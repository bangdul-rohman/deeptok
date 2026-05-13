<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$pdo    = getDB();
$shopId = getShopId();

// ── MARGIN AUTH HANDLER ──
function _ensureSettingsTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (`key` VARCHAR(150) PRIMARY KEY, `value` TEXT NOT NULL, `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
}

// ── END MARGIN AUTH ──


// Filter tanggal utama
$filterDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $filterDate = date('Y-m-d');
}

$dateStart = strtotime($filterDate . ' 00:00:00');
$dateEnd   = strtotime($filterDate . ' 23:59:59');
$prevDate  = date('Y-m-d', strtotime($filterDate . ' -1 day'));
$nextDate  = date('Y-m-d', strtotime($filterDate . ' +1 day'));
$isToday   = $filterDate === date('Y-m-d');

// Summary keseluruhan
$stmt = $pdo->prepare("
    SELECT COUNT(*) as total_orders,
        COALESCE(SUM(total_amount), 0) as total_gmv,
        COALESCE(AVG(total_amount), 0) as avg_order_value
    FROM orders WHERE shop_id = :shop_id AND total_amount IS NOT NULL
");
$stmt->execute([':shop_id' => $shopId]);
$summary = $stmt->fetch();

// Summary hari dipilih (exclude CANCELLED, match TikTok GMV definition)
$stmt = $pdo->prepare("
    SELECT COUNT(*) as total_orders,
        COALESCE(SUM(total_amount), 0) as total_gmv,
        COALESCE(AVG(total_amount), 0) as avg_order_value
    FROM orders WHERE shop_id = :shop_id
        AND create_time BETWEEN :start AND :end
        AND status != 'CANCELLED'
");
$stmt->execute([':shop_id' => $shopId, ':start' => $dateStart, ':end' => $dateEnd]);
$daySummary = $stmt->fetch();

// Summary hari ini (selalu hari ini, untuk card GMV per Jam)
$todayStart = strtotime(date('Y-m-d') . ' 00:00:00');
$todayEnd   = strtotime(date('Y-m-d') . ' 23:59:59');
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) as total_gmv, COUNT(*) as total_orders FROM orders WHERE shop_id=:s AND create_time BETWEEN :a AND :b AND status != 'CANCELLED'");
$stmt->execute([':s'=>$shopId,':a'=>$todayStart,':b'=>$todayEnd]);
$todaySummary = $stmt->fetch();

// Status order hari dipilih
$stmt = $pdo->prepare("
    SELECT COALESCE(status, 'UNKNOWN') as status, COUNT(*) as total
    FROM orders WHERE shop_id = :shop_id AND create_time BETWEEN :start AND :end
    GROUP BY status ORDER BY total DESC
");
$stmt->execute([':shop_id' => $shopId, ':start' => $dateStart, ':end' => $dateEnd]);
$orderByStatus = $stmt->fetchAll();

// Cancel rate default
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_orders,
        SUM(CASE WHEN status LIKE '%CANCEL%' THEN 1 ELSE 0 END) as cancel_orders,
        COALESCE(SUM(CASE WHEN status LIKE '%CANCEL%' THEN total_amount ELSE 0 END), 0) as cancel_gmv
    FROM orders
    WHERE shop_id = :shop_id AND create_time BETWEEN :start AND :end
");
$stmt->execute([':shop_id' => $shopId, ':start' => $dateStart, ':end' => $dateEnd]);
$cancelData = $stmt->fetch();

$cancelRate = 0;
if (($cancelData['total_orders'] ?? 0) > 0) {
    $cancelRate = round(($cancelData['cancel_orders'] / $cancelData['total_orders']) * 100, 1);
}

// Semua data harian untuk grafik
$stmt = $pdo->prepare("
    SELECT DATE(FROM_UNIXTIME(create_time)) as order_date,
        COUNT(*) as total_orders,
        COALESCE(SUM(total_amount), 0) as daily_gmv
    FROM orders WHERE shop_id = :shop_id AND create_time IS NOT NULL
    GROUP BY order_date ORDER BY order_date ASC
");
$stmt->execute([':shop_id' => $shopId]);
$allDailyOrders = $stmt->fetchAll();

// Daftar pesanan hari dipilih
$stmt = $pdo->prepare("
    SELECT o.order_id, o.status, o.create_time, o.total_amount, o.currency,
        GROUP_CONCAT(oi.product_name SEPARATOR '|||') as products,
        SUM(oi.quantity) as total_qty
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.order_id AND oi.shop_id = :shop_id
    WHERE o.shop_id = :shop_id2 AND o.create_time BETWEEN :start AND :end
    GROUP BY o.order_id, o.status, o.create_time, o.total_amount, o.currency
    ORDER BY o.create_time DESC
");
$stmt->execute([':shop_id' => $shopId, ':shop_id2' => $shopId, ':start' => $dateStart, ':end' => $dateEnd]);
$filteredOrders = $stmt->fetchAll();

// Produk terlaris
$stmt = $pdo->prepare("
    SELECT oi.product_name,
        SUM(oi.quantity) as total_qty,
        SUM(oi.sale_price * oi.quantity) as total_revenue,
        COUNT(DISTINCT oi.order_id) as order_count
    FROM order_items oi
    JOIN orders o ON o.order_id = oi.order_id AND o.shop_id = :shop_id
    WHERE oi.shop_id = :shop_id2 AND o.create_time BETWEEN :start AND :end
    AND oi.product_name IS NOT NULL
    GROUP BY oi.product_name ORDER BY total_qty DESC LIMIT 10
");
$stmt->execute([':shop_id' => $shopId, ':shop_id2' => $shopId, ':start' => $dateStart, ':end' => $dateEnd]);
$topProducts = $stmt->fetchAll();

// Order belum diproses (AWAITING_SHIPMENT, UNPAID, AWAITING_COLLECTION)
$unprocessedStatuses = ['AWAITING_SHIPMENT','UNPAID','AWAITING_COLLECTION','ON_HOLD'];
$placeholders = implode(',', array_fill(0, count($unprocessedStatuses), '?'));
$stmtUp = $pdo->prepare("
    SELECT COUNT(*) as cnt FROM orders
    WHERE shop_id = ? AND status IN ($placeholders)
");
$stmtUp->execute(array_merge([$shopId], $unprocessedStatuses));
$unprocessedCount = (int)($stmtUp->fetch()['cnt'] ?? 0);

// Sync log terakhir
$stmt = $pdo->prepare("SELECT * FROM sync_logs WHERE shop_id = :shop_id ORDER BY created_at DESC LIMIT 1");
$stmt->execute([':shop_id' => $shopId]);
$lastSync = $stmt->fetch();

// SLA helper
function calcSlaDeadline(int $createTime): int {
    date_default_timezone_set('Asia/Jakarta');
    $orderDt   = new DateTime('@'.$createTime);
    $orderDt->setTimezone(new DateTimeZone('Asia/Jakarta'));
    $orderHour = (int)$orderDt->format('H');
    $orderMin  = (int)$orderDt->format('i');
    $isAfterNoon = ($orderHour > 12 || ($orderHour === 12 && $orderMin > 0));
    $daysToAdd = $isAfterNoon ? 2 : 1;
    $deadline  = clone $orderDt;
    $added = 0;
    while ($added < $daysToAdd) {
        $deadline->modify('+1 day');
        $dow = (int)$deadline->format('N');
        if ($dow < 6) $added++;
    }
    $deadline->setTime(23, 59, 59);
    return $deadline->getTimestamp();
}

function formatRupiah(float $amount): string
{
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function getStatusBadge(string $status): string
{
    if (strpos($status, 'COMPLETED') !== false || strpos($status, 'DELIVERED') !== false) return 'badge-green';
    if (strpos($status, 'CANCEL') !== false) return 'badge-red';
    if (strpos($status, 'AWAITING') !== false || strpos($status, 'PENDING') !== false || strpos($status, 'TRANSIT') !== false || strpos($status, 'UNPAID') !== false) return 'badge-yellow';
    return 'badge-gray';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Deeptok Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
:root {
  --bg:#0a0a0f; --surface:#13131a; --surface2:#1c1c28; --border:#2a2a3a;
  --accent:#7c6aff; --accent2:#ff6a9b; --accent3:#6affb8;
  --text:#e8e8f0; --text-muted:#6b6b8a;
  --success:#6affb8; --warning:#ffb86a; --danger:#ff6a6a;
}
*{margin:0;padding:0;box-sizing:border-box;}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh;}
.header{padding:16px 32px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--surface);position:sticky;top:0;z-index:100;}
.logo{font-family:'Syne',sans-serif;font-weight:800;font-size:22px;background:linear-gradient(135deg,var(--accent),var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
.header-right{display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.last-sync{font-size:11px;color:var(--text-muted);}
.btn{padding:7px 16px;border-radius:8px;font-family:'DM Mono',monospace;font-size:12px;cursor:pointer;text-decoration:none;transition:opacity 0.2s;border:none;display:inline-block;}
.btn-primary{background:var(--accent);color:white;}
.btn-secondary{background:var(--surface2);color:var(--text);border:1px solid var(--border);}
.btn-bulk{background:var(--surface2);color:var(--accent3);border:1px solid var(--accent3);}
.btn-logout{background:var(--surface2);color:var(--danger);border:1px solid var(--danger);}
.btn:hover{opacity:0.8;}
.btn:disabled{opacity:0.4;cursor:not-allowed;}
.container{max-width:1400px;margin:0 auto;padding:24px 32px;}
.section-title{font-family:'Syne',sans-serif;font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:2px;margin-bottom:12px;}
.date-filter{display:flex;align-items:center;gap:12px;margin-bottom:24px;background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:14px 20px;flex-wrap:wrap;}
.date-filter-label{font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;}
.date-nav{display:flex;align-items:center;gap:8px;}
.date-nav-btn{width:32px;height:32px;border-radius:8px;background:var(--surface2);border:1px solid var(--border);color:var(--text);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:14px;text-decoration:none;transition:border-color 0.2s;}
.date-nav-btn:hover{border-color:var(--accent);}
.date-nav-btn.disabled{opacity:0.3;pointer-events:none;}
.date-display{font-family:'Syne',sans-serif;font-size:15px;font-weight:700;min-width:180px;text-align:center;}
.date-input{background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--text);font-family:'DM Mono',monospace;font-size:12px;padding:7px 12px;cursor:pointer;}
.date-input:focus{outline:none;border-color:var(--accent);}
.quick-filters{display:flex;gap:6px;margin-left:auto;flex-wrap:wrap;}
.quick-btn{padding:5px 12px;border-radius:20px;font-size:11px;border:1px solid var(--border);background:transparent;color:var(--text-muted);cursor:pointer;text-decoration:none;transition:all 0.2s;font-family:'DM Mono',monospace;}
.quick-btn:hover,.quick-btn.active{background:var(--accent);border-color:var(--accent);color:white;}
.metrics-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;}
.metric-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:20px 22px;position:relative;overflow:visible;transition:border-color 0.2s,transform 0.2s;}
.metric-card:hover{border-color:var(--accent);transform:translateY(-2px);}
.metric-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;border-radius:14px 14px 0 0;}
.metric-card.purple::before{background:linear-gradient(90deg,var(--accent),transparent);}
.metric-card.pink::before{background:linear-gradient(90deg,var(--accent2),transparent);}
.metric-card.green::before{background:linear-gradient(90deg,var(--accent3),transparent);}
.metric-card.orange::before{background:linear-gradient(90deg,var(--warning),transparent);}
.metric-label{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:10px;}
.metric-value{font-family:'Syne',sans-serif;font-size:28px;font-weight:800;line-height:1;margin-bottom:6px;}
.metric-value.purple{color:var(--accent);}
.metric-value.pink{color:var(--accent2);}
.metric-value.green{color:var(--accent3);}
.metric-value.orange{color:var(--warning);}
.metric-sub{font-size:11px;color:var(--text-muted);}
.grid-2{display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:24px;}
.card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:22px 24px;margin-bottom:24px;}
.card-title{font-family:'Syne',sans-serif;font-size:14px;font-weight:700;margin-bottom:16px;color:var(--text);display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.card-title-meta{font-size:11px;color:var(--text-muted);font-weight:400;font-family:'DM Mono',monospace;}
.card-title-count{margin-left:auto;font-size:12px;color:var(--accent3);font-weight:400;font-family:'DM Mono',monospace;}
.range-picker-bar{display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;}
.range-picker-group{display:flex;align-items:center;gap:8px;}
.range-picker-label{font-size:11px;color:var(--text-muted);white-space:nowrap;}
.range-picker-btn{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:8px;background:var(--surface2);border:1px solid var(--border);color:var(--text);cursor:pointer;font-size:12px;font-family:'DM Mono',monospace;transition:all 0.2s;white-space:nowrap;}
.range-picker-btn:hover{border-color:var(--accent);}
.range-picker-btn.main-active{border-color:var(--accent);color:var(--accent);}
.range-picker-btn.compare-active{border-color:var(--accent2);color:var(--accent2);}
.range-clear-btn{background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:16px;padding:2px 6px;border-radius:4px;transition:color 0.2s;}
.range-clear-btn:hover{color:var(--danger);}
.gmv-summary-bar{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px;}
.gmv-summary-item{background:var(--surface2);border-radius:10px;padding:12px 14px;}
.gmv-summary-label{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;}
.gmv-summary-value{font-family:'Syne',sans-serif;font-size:15px;font-weight:700;}
.gmv-summary-value.main{color:var(--accent);}
.gmv-summary-value.compare{color:var(--accent2);}
.gmv-summary-value.up{color:var(--success);}
.gmv-summary-value.down{color:var(--danger);}
.gmv-summary-value.flat{color:var(--text-muted);}
.cal-dropdown{position:absolute;z-index:400;background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:20px;box-shadow:0 16px 48px rgba(0,0,0,0.5);width:580px;max-width:95vw;display:none;top:calc(100% + 8px);left:0;}
.cal-dropdown.open{display:block;}
.cal-dropdown-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
.cal-tabs{display:flex;gap:4px;background:var(--surface2);border-radius:8px;padding:3px;}
.cal-tab{padding:6px 14px;border-radius:6px;border:none;background:transparent;color:var(--text-muted);font-family:'DM Mono',monospace;font-size:11px;cursor:pointer;transition:all 0.2s;}
.cal-tab.active{background:var(--surface);color:var(--text);box-shadow:0 1px 4px rgba(0,0,0,0.3);}
.cal-close{background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:18px;padding:4px;border-radius:4px;}
.cal-close:hover{color:var(--text);background:var(--surface2);}
.cal-panel{display:none;}
.cal-panel.show{display:block;}
.cal-two-months{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.cal-month-nav{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;}
.cal-month-nav-btn{background:var(--surface2);border:1px solid var(--border);border-radius:6px;color:var(--text);cursor:pointer;padding:3px 8px;font-size:14px;}
.cal-month-nav-btn.hidden{visibility:hidden;}
.cal-month-title{font-family:'Syne',sans-serif;font-size:13px;font-weight:700;text-align:center;}
.cal-weekdays{display:grid;grid-template-columns:repeat(7,1fr);margin-bottom:4px;}
.cal-weekday{text-align:center;font-size:10px;color:var(--text-muted);padding:4px 0;}
.cal-days-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;}
.cal-d{text-align:center;padding:7px 0;border-radius:6px;font-size:12px;cursor:pointer;transition:all 0.15s;}
.cal-d:hover{background:var(--surface2);}
.cal-d.other{color:rgba(107,107,138,0.4);}
.cal-d.today{color:var(--accent);font-weight:700;}
.cal-d.in-range{background:rgba(124,106,255,0.12);border-radius:0;}
.cal-d.range-start{background:var(--accent)!important;color:white;border-radius:6px 0 0 6px;}
.cal-d.range-end{background:var(--accent)!important;color:white;border-radius:0 6px 6px 0;}
.cal-d.range-start.range-end{border-radius:6px;}
.cal-d.disabled{opacity:0.2;cursor:not-allowed;pointer-events:none;}
.cancel-cal-d{text-align:center;padding:7px 0;border-radius:6px;font-size:12px;cursor:pointer;transition:all 0.15s;}
.cancel-cal-d:hover{background:var(--surface2);}
.cancel-cal-d.other{color:rgba(107,107,138,0.4);}
.cancel-cal-d.today{color:var(--warning);font-weight:700;}
.cancel-cal-d.in-range{background:rgba(255,184,106,0.15);border-radius:0;}
.cancel-cal-d.range-start{background:var(--warning)!important;color:#0a0a0f;border-radius:6px 0 0 6px;}
.cancel-cal-d.range-end{background:var(--warning)!important;color:#0a0a0f;border-radius:0 6px 6px 0;}
.cancel-cal-d.range-start.range-end{border-radius:6px;}
.cancel-cal-d.disabled{opacity:0.2;cursor:not-allowed;pointer-events:none;}
.week-nav{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;}
.week-nav-btn{background:var(--surface2);border:1px solid var(--border);border-radius:6px;color:var(--text);cursor:pointer;padding:3px 10px;font-size:14px;}
.week-nav-label{font-family:'Syne',sans-serif;font-size:13px;font-weight:700;}
.week-list{display:flex;flex-direction:column;gap:4px;}
.week-row{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-radius:8px;cursor:pointer;font-size:12px;border:1px solid transparent;transition:all 0.2s;}
.week-row:hover{background:var(--surface2);}
.week-row.selected{background:rgba(124,106,255,0.1);border-color:var(--accent);color:var(--accent);}
.week-row-label{font-size:10px;color:var(--text-muted);}
.month-nav{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
.month-nav-btn{background:var(--surface2);border:1px solid var(--border);border-radius:6px;color:var(--text);cursor:pointer;padding:3px 10px;font-size:14px;}
.month-nav-label{font-family:'Syne',sans-serif;font-size:13px;font-weight:700;}
.month-cells{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;}
.month-cell{padding:12px 8px;border-radius:8px;text-align:center;cursor:pointer;font-size:12px;border:1px solid var(--border);transition:all 0.2s;}
.month-cell:hover{border-color:var(--accent);}
.month-cell.selected{background:rgba(124,106,255,0.15);border-color:var(--accent);color:var(--accent);}
.month-cell.disabled{opacity:0.25;cursor:not-allowed;}
.cal-footer{display:flex;align-items:center;justify-content:space-between;margin-top:16px;padding-top:16px;border-top:1px solid var(--border);}
.cal-footer-info{font-size:11px;color:var(--text-muted);}
.cal-footer-btns{display:flex;gap:8px;}
.cal-btn-cancel{padding:7px 18px;border-radius:7px;background:var(--surface2);border:1px solid var(--border);color:var(--text-muted);font-family:'DM Mono',monospace;font-size:12px;cursor:pointer;}
.cal-btn-apply{padding:7px 18px;border-radius:7px;background:var(--accent);border:none;color:white;font-family:'DM Mono',monospace;font-size:12px;cursor:pointer;}
.cal-btn-apply-orange{padding:7px 18px;border-radius:7px;background:var(--warning);border:none;color:#0a0a0f;font-family:'DM Mono',monospace;font-size:12px;cursor:pointer;font-weight:700;}
.range-picker-anchor{position:relative;display:inline-block;}
.cancel-picker-dropdown{position:absolute;z-index:400;right:0;top:calc(100% + 6px);background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:20px;box-shadow:0 16px 48px rgba(0,0,0,0.5);width:540px;max-width:95vw;display:none;}
.cancel-picker-dropdown.open{display:block;}
.cancel-tabs{display:flex;gap:4px;background:var(--surface2);border-radius:8px;padding:3px;margin-bottom:14px;}
.cancel-tab{flex:1;padding:6px;border-radius:6px;border:none;background:transparent;color:var(--text-muted);font-family:'DM Mono',monospace;font-size:11px;cursor:pointer;transition:all 0.2s;}
.cancel-tab.active{background:var(--surface);color:var(--text);}
.chart-container{position:relative;height:240px;}
.empty-state{display:flex;align-items:center;justify-content:center;height:160px;color:var(--text-muted);font-size:13px;}
.status-list{display:flex;flex-direction:column;gap:10px;}
.status-item{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:var(--surface2);border-radius:8px;font-size:12px;}
.status-badge{padding:3px 10px;border-radius:20px;font-size:10px;font-weight:500;}
.badge-green{background:rgba(106,255,184,0.15);color:var(--success);}
.badge-yellow{background:rgba(255,184,106,0.15);color:var(--warning);}
.badge-red{background:rgba(255,106,106,0.15);color:var(--danger);}
.badge-gray{background:rgba(107,107,138,0.15);color:var(--text-muted);}
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:12px;}
th{text-align:left;padding:10px 14px;font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;border-bottom:1px solid var(--border);font-weight:500;white-space:nowrap;}
td{padding:12px 14px;border-bottom:1px solid rgba(42,42,58,0.5);color:var(--text);vertical-align:top;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:var(--surface2);}
.rank{width:26px;height:26px;border-radius:50%;background:var(--surface2);display:flex;align-items:center;justify-content:center;font-size:11px;color:var(--text-muted);font-family:'Syne',sans-serif;font-weight:700;}
.rank.top{background:rgba(124,106,255,0.2);color:var(--accent);}
.empty-table{text-align:center;padding:40px;color:var(--text-muted);font-size:13px;}
.order-id{font-size:10px;color:var(--text-muted);}
.product-line{font-size:11px;line-height:1.7;}
.time-label{font-size:11px;color:var(--text-muted);white-space:nowrap;}
#sync-toast{position:fixed;bottom:24px;right:24px;background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:14px 20px;font-size:13px;color:var(--text);display:none;z-index:9999;min-width:260px;box-shadow:0 8px 32px rgba(0,0,0,0.4);transition:border-color 0.3s;}
#hourly-modal{position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:500;display:none;align-items:center;justify-content:center;}
@keyframes fadeUp{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}
.metric-card{animation:fadeUp 0.4s ease both;}
.metric-card:nth-child(1){animation-delay:0.05s;}
.metric-card:nth-child(2){animation-delay:0.10s;}
.metric-card:nth-child(3){animation-delay:0.15s;}
.metric-card:nth-child(4){animation-delay:0.20s;}

/* ═══════════════════════════════════════
   COHORT ANALYSIS STYLES
═══════════════════════════════════════ */
.cohort-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:22px 24px;margin-bottom:24px;animation:fadeUp 0.4s ease both;animation-delay:0.25s;}
.cohort-header-row{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;}
.cohort-title{font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px;}
.cohort-controls{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.cohort-date-group{display:flex;align-items:center;gap:8px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:7px 12px;}
.cohort-date-group label{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;white-space:nowrap;}
.cohort-date-group input[type="date"]{background:transparent;border:none;color:var(--text);font-family:'DM Mono',monospace;font-size:12px;outline:none;cursor:pointer;}
.cohort-date-group input[type="date"]::-webkit-calendar-picker-indicator{filter:invert(0.6);cursor:pointer;}
.gran-toggle{display:flex;background:var(--surface2);border:1px solid var(--border);border-radius:8px;overflow:hidden;}
.gran-btn{padding:7px 12px;font-size:11px;font-weight:600;color:var(--text-muted);background:transparent;border:none;cursor:pointer;font-family:'DM Mono',monospace;transition:all 0.2s;}
.gran-btn.active{background:var(--accent);color:#fff;}
.cohort-metric-toggle{display:flex;background:var(--surface2);border:1px solid var(--border);border-radius:8px;overflow:hidden;}
.metric-btn{padding:7px 12px;font-size:11px;font-weight:600;color:var(--text-muted);background:transparent;border:none;cursor:pointer;font-family:'DM Mono',monospace;transition:all 0.2s;white-space:nowrap;}
.metric-btn.active{background:var(--accent3);color:#000;}
.btn-load-cohort{padding:7px 16px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;font-family:'DM Mono',monospace;transition:opacity 0.2s;white-space:nowrap;}
.btn-load-cohort:hover{opacity:0.85;}
.btn-load-cohort:disabled{opacity:0.45;cursor:not-allowed;}
.cohort-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px;}
.cohort-sum-card{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:14px 16px;}
.cohort-sum-card .sum-val{font-family:'Syne',sans-serif;font-size:20px;font-weight:800;color:var(--accent);margin-bottom:4px;}
.cohort-sum-card .sum-lbl{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.8px;}
.cohort-table-wrap{overflow-x:auto;border-radius:10px;border:1px solid var(--border);}
.cohort-table{width:100%;border-collapse:collapse;min-width:600px;font-size:12px;}
.cohort-table th{background:#0d0d1a;color:var(--text-muted);padding:10px 12px;text-align:center;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.8px;white-space:nowrap;border-bottom:1px solid var(--border);}
.cohort-table th:first-child,.cohort-table th:nth-child(2){text-align:left;position:sticky;left:0;z-index:3;background:#0d0d1a;}
.cohort-table th:nth-child(2){left:130px;}
.cohort-table td{padding:8px 10px;border-bottom:1px solid rgba(42,42,58,0.4);text-align:center;white-space:nowrap;}
.cohort-table td:first-child{text-align:left;font-weight:600;color:var(--text);position:sticky;left:0;background:#13131a;z-index:1;min-width:130px;font-size:11px;}
.cohort-table td:nth-child(2){position:sticky;left:130px;background:#13131a;z-index:1;color:var(--accent2);font-weight:700;min-width:65px;}
.cohort-table tr:hover td{background:#16162a!important;}
.cohort-table tr:hover td:first-child,.cohort-table tr:hover td:nth-child(2){background:#16162a!important;}
.heat-cell{border-radius:6px;padding:5px 8px;display:inline-block;min-width:56px;font-weight:600;color:#fff;font-size:11px;cursor:default;transition:transform 0.15s,filter 0.15s;}
.heat-cell:hover{transform:scale(1.08);filter:brightness(1.15);}
.heat-cell.null-cell{color:rgba(107,107,138,0.3);font-weight:400;background:transparent!important;}
.cohort-loading{text-align:center;padding:52px;color:var(--text-muted);font-size:13px;}
.cohort-empty{text-align:center;padding:52px;color:var(--text-muted);font-size:13px;}
.cohort-legend{display:flex;align-items:center;gap:8px;margin-top:12px;font-size:11px;color:var(--text-muted);}
.legend-swatches{display:flex;gap:2px;}
.legend-swatches span{width:20px;height:10px;border-radius:2px;display:inline-block;}
.cohort-tooltip-box{position:fixed;background:#1e1e3a;border:1px solid #3a3a6a;border-radius:10px;padding:12px 16px;font-size:12px;color:var(--text);pointer-events:none;z-index:9998;display:none;box-shadow:0 6px 24px rgba(0,0,0,0.6);min-width:190px;}
.cohort-tooltip-box .tt-title{font-weight:700;margin-bottom:8px;color:var(--accent2);font-size:12px;}
.cohort-tooltip-box .tt-row{display:flex;justify-content:space-between;gap:14px;margin-bottom:3px;}
/* ── DATE PICKER CALENDAR ── */
.dp-cal{background:#1a1a28;border:1px solid #2a2a3a;border-radius:10px;padding:14px;font-family:'DM Mono',monospace;}
.dp-cal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;}
.dp-nav{background:#1c1c28;border:1px solid #2a2a3a;border-radius:6px;color:#e8e8f0;cursor:pointer;padding:3px 10px;font-size:13px;transition:background 0.15s;}
.dp-nav:hover{background:#2a2a3a;}
.dp-months-wrap{display:grid;grid-template-columns:1fr 1fr;gap:18px;}
.dp-month-title{font-family:'Syne',sans-serif;font-size:12px;font-weight:700;color:#e8e8f0;text-align:center;margin-bottom:8px;}
.dp-weekdays{display:grid;grid-template-columns:repeat(7,1fr);margin-bottom:4px;}
.dp-wd{text-align:center;font-size:10px;color:#6b6b8a;padding:2px 0;}
.dp-days{display:grid;grid-template-columns:repeat(7,1fr);gap:1px;}
.dp-d{height:26px;border-radius:3px;background:transparent;border:none;color:#c8c8e0;font-size:11px;cursor:pointer;font-family:'DM Mono',monospace;display:flex;align-items:center;justify-content:center;transition:background 0.1s;position:relative;}
.dp-d:hover:not(.dp-other):not(.dp-dis){background:rgba(0,168,150,0.2);color:#fff;}
.dp-d.dp-other{color:#2a2a3a;pointer-events:none;}
.dp-d.dp-dis{color:#252535;pointer-events:none;}
.dp-d.dp-today{color:#00a896;font-weight:700;}
.dp-d.dp-today::after{content:"•";position:absolute;bottom:0;left:50%;transform:translateX(-50%);font-size:8px;}
.dp-d.dp-sel-end{background:#00a896!important;color:#000!important;border-radius:3px!important;font-weight:700;}
.dp-d.dp-sel-start{background:rgba(0,168,150,0.35)!important;color:#e8e8f0!important;border-radius:3px 0 0 3px;}
.dp-d.dp-in-range{background:rgba(0,168,150,0.18);color:#e8e8f0;border-radius:0;}
.dp-d.dp-cmp-end{background:#ff6a9b!important;color:#fff!important;border-radius:3px!important;font-weight:700;}
.dp-d.dp-cmp-start{background:rgba(255,106,155,0.3)!important;color:#e8e8f0!important;border-radius:3px 0 0 3px;}
.dp-d.dp-cmp-range{background:rgba(255,106,155,0.15);color:#e8e8f0;border-radius:0;}
.dp-footer{display:flex;align-items:center;justify-content:space-between;margin-top:12px;padding-top:10px;border-top:1px solid #2a2a3a;}
.dp-info{font-size:11px;color:#6b6b8a;}
.dp-apply{padding:6px 18px;border-radius:7px;background:#00a896;border:none;color:#000;font-family:'DM Mono',monospace;font-size:11px;cursor:pointer;font-weight:700;opacity:0.4;}
.dp-apply.ready{opacity:1;}
.dp-cancel{padding:6px 12px;border-radius:7px;background:transparent;border:1px solid #2a2a3a;color:#6b6b8a;font-family:'DM Mono',monospace;font-size:11px;cursor:pointer;}
/* month grid */
.mth-grid{display:flex;flex-wrap:wrap;gap:5px;}
.mth-btn{flex:0 0 calc(25% - 4px);padding:7px 4px;border-radius:6px;border:1px solid #2a2a3a;background:transparent;color:#a0a0b8;font-family:'DM Mono',monospace;font-size:11px;cursor:pointer;text-align:center;transition:all 0.15s;}
.mth-btn:hover:not(.mth-dis){border-color:#00a896;color:#e8e8f0;}
.mth-btn.mth-sel-main{background:rgba(0,168,150,0.2);border-color:#00a896;color:#e8e8f0;}
.mth-btn.mth-sel-cmp{background:rgba(255,106,155,0.2);border-color:#ff6a9b;color:#e8e8f0;}
.mth-btn.mth-dis{opacity:0.25;pointer-events:none;}
.ct-tab{padding:5px 11px;border-radius:6px;background:transparent;border:none;color:var(--text-muted);font-family:'DM Mono',monospace;font-size:11px;cursor:pointer;transition:all 0.15s;white-space:nowrap;}
.ct-tab.active{background:var(--accent);color:white;}
.ct-tab:hover:not(.active){color:var(--text);}
.unp-tab,.prod-tab{padding:4px 10px;border-radius:6px;background:transparent;border:none;color:var(--text-muted);font-family:'DM Mono',monospace;font-size:10px;cursor:pointer;transition:all 0.2s;}
.unp-tab.active,.prod-tab.active{background:var(--accent);color:white;}
.unp-tab:hover:not(.active),.prod-tab:hover:not(.active){color:var(--text);}
.sla-ok{color:var(--success);}
.sla-warn{color:var(--warning);}
.sla-crit{color:#ff8c42;}
.sla-late{color:var(--danger);font-weight:700;}
.unproc-table{width:100%;border-collapse:collapse;font-size:12px;}
.unproc-table th{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);padding:8px 10px;border-bottom:1px solid var(--border);text-align:left;white-space:nowrap;}
.unproc-table td{padding:8px 10px;border-bottom:1px solid rgba(42,42,58,0.4);vertical-align:top;}
.unproc-table tr:last-child td{border-bottom:none;}
.unproc-table tr:hover td{background:rgba(124,106,255,0.04);}
.prod-bar-wrap{display:flex;align-items:center;gap:8px;}
.prod-bar{height:6px;border-radius:3px;background:linear-gradient(90deg,var(--accent),var(--accent2));min-width:2px;}
.prod-rank-medal{font-size:14px;}
.unproc-empty{text-align:center;padding:40px 20px;color:var(--text-muted);font-size:12px;}
.cohort-tooltip-box .tt-label{color:var(--text-muted);}
.cohort-tooltip-box .tt-val{font-weight:700;}
</style>
</head>
<body>

<div class="header">
  <div class="logo">⚡ Deeptok</div>
  <div class="header-right">
    <span class="last-sync">Last sync: <?= $lastSync ? date('d M Y H:i', strtotime($lastSync['created_at'])) : '-' ?></span>
    <button onclick="syncData('orders')" class="btn btn-primary" id="btn-sync-orders">↻ Sync Orders</button>
    <button onclick="syncData('products')" class="btn btn-secondary" id="btn-sync-products">↻ Sync Products</button>
    <a href="sync_bulk.php" class="btn btn-bulk">⏳ Bulk Sync</a>

    <a href="cities.php" class="btn btn-secondary">🗺️ Top Kota</a>
    <a href="chat.php" class="btn btn-primary" style="background:linear-gradient(135deg,var(--accent),var(--accent2));">🤖 AI Chat</a>
    <a href="logout.php" class="btn btn-logout">⏻ Logout</a>
  </div>
</div>

<div class="container">

  <!-- Date Filter -->
  <div class="date-filter">
    <span class="date-filter-label">Tanggal</span>
    <div class="date-nav">
      <a href="?date=<?= $prevDate ?>" class="date-nav-btn">‹</a>
      <span class="date-display">
        <?php
          $dayNames   = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
          $monthNames = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
          $ts = strtotime($filterDate);
          echo $dayNames[date('w',$ts)].', '.date('d',$ts).' '.$monthNames[date('n',$ts)-1].' '.date('Y',$ts);
        ?>
      </span>
      <a href="?date=<?= $nextDate ?>" class="date-nav-btn <?= $isToday ? 'disabled' : '' ?>">›</a>
    </div>
    <input type="date" class="date-input" value="<?= $filterDate ?>" max="<?= date('Y-m-d') ?>" onchange="window.location.href='?date='+this.value">
    <div class="quick-filters">
      <a href="?date=<?= date('Y-m-d') ?>" class="quick-btn <?= $filterDate===date('Y-m-d')?'active':'' ?>">Hari ini</a>
      <a href="?date=<?= date('Y-m-d',strtotime('-1 day')) ?>" class="quick-btn <?= $filterDate===date('Y-m-d',strtotime('-1 day'))?'active':'' ?>">Kemarin</a>
      <a href="?date=<?= date('Y-m-d',strtotime('-2 days')) ?>" class="quick-btn <?= $filterDate===date('Y-m-d',strtotime('-2 days'))?'active':'' ?>">2 hari lalu</a>
      <a href="?date=<?= date('Y-m-d',strtotime('-3 days')) ?>" class="quick-btn <?= $filterDate===date('Y-m-d',strtotime('-3 days'))?'active':'' ?>">3 hari lalu</a>
      <a href="?date=<?= date('Y-m-d',strtotime('-7 days')) ?>" class="quick-btn <?= $filterDate===date('Y-m-d',strtotime('-7 days'))?'active':'' ?>">7 hari lalu</a>
    </div>
  </div>

  <!-- Metrics -->
  <div class="section-title">Ringkasan <?= date('d M Y', strtotime($filterDate)) ?></div>
  <div class="metrics-grid">

    <div class="metric-card purple">
      <div class="metric-label">GMV <?= $isToday ? "Hari Ini" : date("d M", strtotime($filterDate)) ?></div>
      <div class="metric-value purple"><?= formatRupiah((float)($daySummary['total_gmv']??0)) ?></div>
      <div class="metric-sub">dari <?= (int)($daySummary['total_orders']??0) ?> order</div>
    </div>

    <div class="metric-card pink">
      <div class="metric-label">Total Order</div>
      <div class="metric-value pink"><?= number_format((int)($daySummary['total_orders']??0)) ?></div>
      <div class="metric-sub">AOV <?= formatRupiah((float)($daySummary['avg_order_value']??0)) ?></div>
    </div>

    <div class="metric-card teal" style="border-color:rgba(0,168,150,0.3);">
      <div class="metric-label">Pengunjung Toko Hari Ini</div>
      <div class="metric-value teal" id="card-visitors">—</div>
      <div class="metric-sub" id="card-customers">— pembeli unik</div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;">
        <div style="font-size:10px;color:var(--text-muted);" id="card-items-sold">— item terjual</div>
        <div style="font-size:9px;color:var(--text-muted);" id="card-perf-update">—</div>
      </div>
    </div>

    <div class="metric-card orange" id="cancel-card" style="overflow:visible;">
      <div class="metric-label">Cancel Rate</div>
      <div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:8px;">
        <div class="metric-value orange" id="cancel-rate-value"><?= $cancelRate ?>%</div>
        <div style="margin-left:auto;position:relative;" id="cancel-anchor">
          <div onclick="toggleCancelPicker(event)" style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:6px;background:var(--surface2);border:1px solid var(--border);color:var(--text-muted);cursor:pointer;font-size:10px;font-family:'DM Mono',monospace;white-space:nowrap;">
            📅 <span id="cancel-picker-label"><?= date('d M Y', strtotime($filterDate)) ?></span>
          </div>
          <div class="cancel-picker-dropdown" id="cancel-picker-dropdown">
            <div class="cancel-tabs">
              <button class="cancel-tab active" onclick="switchCancelTab(event,'day')">Hari</button>
              <button class="cancel-tab" onclick="switchCancelTab(event,'week')">Minggu</button>
              <button class="cancel-tab" onclick="switchCancelTab(event,'month')">Bulan</button>
              <button class="cancel-tab" onclick="switchCancelTab(event,'custom')">Kustom</button>
            </div>
            <div id="cancel-panel-day">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div>
                  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                    <button onclick="cancelCalNav(event,-1)" style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;color:var(--text);cursor:pointer;padding:3px 8px;font-size:13px;">‹</button>
                    <span id="cancel-cal-title-l" style="font-family:'Syne',sans-serif;font-size:12px;font-weight:700;"></span>
                    <button style="visibility:hidden;padding:3px 8px;border:none;background:none;">›</button>
                  </div>
                  <div style="display:grid;grid-template-columns:repeat(7,1fr);margin-bottom:4px;">
                    <?php foreach(['S','S','R','K','J','S','M'] as $dn): ?><div style="text-align:center;font-size:9px;color:var(--text-muted);padding:3px 0;"><?= $dn ?></div><?php endforeach; ?>
                  </div>
                  <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;" id="cancel-days-l"></div>
                </div>
                <div>
                  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                    <button style="visibility:hidden;padding:3px 8px;border:none;background:none;">‹</button>
                    <span id="cancel-cal-title-r" style="font-family:'Syne',sans-serif;font-size:12px;font-weight:700;"></span>
                    <button onclick="cancelCalNav(event,1)" style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;color:var(--text);cursor:pointer;padding:3px 8px;font-size:13px;">›</button>
                  </div>
                  <div style="display:grid;grid-template-columns:repeat(7,1fr);margin-bottom:4px;">
                    <?php foreach(['S','S','R','K','J','S','M'] as $dn): ?><div style="text-align:center;font-size:9px;color:var(--text-muted);padding:3px 0;"><?= $dn ?></div><?php endforeach; ?>
                  </div>
                  <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;" id="cancel-days-r"></div>
                </div>
              </div>
            </div>
            <div id="cancel-panel-week" style="display:none;">
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <button onclick="cancelWeekNav(event,-1)" style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;color:var(--text);cursor:pointer;padding:4px 10px;font-size:14px;">‹</button>
                <span id="cancel-week-label" style="font-family:'Syne',sans-serif;font-size:13px;font-weight:700;"></span>
                <button onclick="cancelWeekNav(event,1)" style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;color:var(--text);cursor:pointer;padding:4px 10px;font-size:14px;">›</button>
              </div>
              <div id="cancel-week-list"></div>
            </div>
            <div id="cancel-panel-month" style="display:none;">
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <button onclick="cancelMonthNav(event,-1)" style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;color:var(--text);cursor:pointer;padding:4px 10px;font-size:14px;">‹</button>
                <span id="cancel-month-label" style="font-family:'Syne',sans-serif;font-size:13px;font-weight:700;"></span>
                <button onclick="cancelMonthNav(event,1)" style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;color:var(--text);cursor:pointer;padding:4px 10px;font-size:14px;">›</button>
              </div>
              <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;" id="cancel-month-cells"></div>
            </div>
            <div id="cancel-panel-custom" style="display:none;">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                  <label style="display:block;font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">Dari</label>
                  <input type="date" id="cancel-custom-from" max="<?= date('Y-m-d') ?>" style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--text);font-family:'DM Mono',monospace;font-size:12px;padding:8px 12px;">
                </div>
                <div>
                  <label style="display:block;font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">Sampai</label>
                  <input type="date" id="cancel-custom-to" max="<?= date('Y-m-d') ?>" style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--text);font-family:'DM Mono',monospace;font-size:12px;padding:8px 12px;">
                </div>
              </div>
            </div>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-top:14px;padding-top:14px;border-top:1px solid var(--border);">
              <span id="cancel-sel-info" style="font-size:11px;color:var(--text-muted);">Pilih tanggal</span>
              <div style="display:flex;gap:8px;">
                <button onclick="toggleCancelPicker(event)" class="cal-btn-cancel">Batal</button>
                <button onclick="applyCancelPicker(event)" class="cal-btn-apply-orange">Terapkan</button>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:4px;">
        <div style="font-size:11px;color:var(--text-muted);"><span id="cancel-count"><?= (int)($cancelData['cancel_orders']??0) ?></span> cancel dari <span id="cancel-total"><?= (int)($cancelData['total_orders']??0) ?></span> order</div>
        <div style="font-size:11px;color:var(--danger);">nilai cancel: <span id="cancel-gmv-val"><?= formatRupiah((float)($cancelData['cancel_gmv']??0)) ?></span></div>
        <div style="font-size:10px;color:var(--text-muted);margin-top:2px;" id="cancel-range-info"><?= date('d M Y', strtotime($filterDate)) ?></div>
      </div>
    </div>

  </div><!-- /metrics-grid -->

  <!-- ══════════════════════════════════
       CHART ADAPTIF + STATUS ORDER
  ══════════════════════════════════ -->
  <div class="grid-2">

    <!-- CHART ADAPTIF: per jam (1 hari) atau per hari (range) -->
    <div class="card" style="margin-bottom:0">
      <!-- Header -->
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
        <div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;" id="chart-title">📈 Tren GMV</div>
        <div style="font-size:10px;color:var(--text-muted);" id="chart-mode-badge">mode: per jam</div>
      </div>

      <!-- 4 TABS -->
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap;">
        <div style="display:flex;gap:2px;background:var(--surface2);border-radius:8px;padding:3px;">
          <button class="ct-tab active" id="ct-tab-day"    onclick="ctSetMode('day')">Hari ini</button>
          <button class="ct-tab"        id="ct-tab-7days"  onclick="ctSetMode('7days')">7 Hari</button>
          <button class="ct-tab"        id="ct-tab-month"  onclick="ctSetMode('month')">Bulan</button>
          <button class="ct-tab"        id="ct-tab-custom" onclick="ctSetMode('custom')">Kustom</button>
        </div>
        <button id="ct-compare-clear" onclick="ctClearCompare()" style="display:none;padding:4px 10px;border-radius:7px;background:rgba(255,106,106,0.1);border:1px solid rgba(255,106,106,0.3);color:var(--danger);font-family:'DM Mono',monospace;font-size:11px;cursor:pointer;">✕ Hapus perbandingan</button>
      </div>

      <!-- PANEL: 7 HARI picker -->
      <div id="ct-panel-7days" style="display:none;margin-bottom:10px;"></div>

      <!-- PANEL: BULAN picker -->
      <div id="ct-panel-month" style="display:none;margin-bottom:10px;"></div>

      <!-- PANEL: CUSTOM inline calendar -->
      <div id="ct-panel-custom" style="display:none;margin-bottom:10px;"></div>

      <!-- SUMMARY BAR -->
      <div id="ct-summary" style="display:none;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:12px;">
        <div style="background:var(--surface2);border-radius:8px;padding:10px 12px;"><div style="font-size:9px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;" id="ct-lbl-main">Utama</div><div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:var(--accent3);" id="ct-val-main">—</div></div>
        <div style="background:var(--surface2);border-radius:8px;padding:10px 12px;"><div style="font-size:9px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;" id="ct-lbl-cmp">Pembanding</div><div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:var(--accent2);" id="ct-val-cmp">—</div></div>
        <div style="background:var(--surface2);border-radius:8px;padding:10px 12px;"><div style="font-size:9px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Selisih</div><div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;" id="ct-val-diff">—</div></div>
        <div style="background:var(--surface2);border-radius:8px;padding:10px 12px;"><div style="font-size:9px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Perubahan</div><div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;" id="ct-val-pct">—</div></div>
      </div>

      <!-- LEGEND -->
      <div style="display:flex;gap:16px;margin-bottom:10px;font-size:11px;color:var(--text-muted);">
        <div style="display:flex;align-items:center;gap:5px;"><span style="width:18px;height:3px;background:var(--accent3);display:inline-block;border-radius:2px;"></span><span id="ct-leg-main">—</span></div>
        <div style="display:flex;align-items:center;gap:5px;display:none;" id="ct-leg-cmp-wrap"><span style="width:18px;height:3px;background:var(--accent2);display:inline-block;border-radius:2px;border-style:dashed;border-width:1px;"></span><span id="ct-leg-cmp">—</span></div>
      </div>

      <div style="position:relative;height:270px;"><canvas id="ct-chart"></canvas></div>
      <div style="display:none;"><span id="h-leg-today"></span><span id="h-leg-prev"></span><span id="hi-diff-gmv"></span><canvas id="hourly-inline-chart"></canvas></div>
      <div style="margin-top:6px;font-size:10px;color:var(--text-muted);text-align:right;" id="ct-status">Memuat...</div>
    </div>


    <!-- STATUS ORDER -->
    <div class="card" style="margin-bottom:0">
      <div class="card-title">Status Order</div>
      <div class="status-list">
        <?php if (!empty($orderByStatus)): ?>
          <?php foreach ($orderByStatus as $s): $label=$s['status']??'UNKNOWN'; $badge=getStatusBadge($label); ?>
          <div class="status-item"><span class="status-badge <?= $badge ?>"><?= htmlspecialchars($label) ?></span><strong><?= (int)$s['total'] ?></strong></div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="empty-state" style="height:auto;padding:30px 0;">Tidak ada order</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

    <!-- ══════════════════════════════════
       ORDER BELUM DIPROSES + SLA
  ══════════════════════════════════ -->
  <div class="card">
    <div class="card-title" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
      <div style="display:flex;align-items:center;gap:10px;">
        📋 Order Belum Diproses
        <span style="background:var(--danger);color:white;font-family:'Syne',sans-serif;font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;" id="unproc-badge"><?= $unprocessedCount ?></span>
      </div>
      <div style="display:flex;align-items:center;gap:8px;">
        <div id="unproc-tabs" style="display:flex;gap:4px;background:var(--surface2);border-radius:8px;padding:3px;">
          <button class="unp-tab active" data-status="ALL" onclick="switchUnprocTab(this,'ALL')">Semua</button>
          <button class="unp-tab" data-status="AWAITING_SHIPMENT" onclick="switchUnprocTab(this,'AWAITING_SHIPMENT')">Siap Kirim</button>
          <button class="unp-tab" data-status="UNPAID" onclick="switchUnprocTab(this,'UNPAID')">Belum Bayar</button>
          <button class="unp-tab" data-status="AWAITING_COLLECTION" onclick="switchUnprocTab(this,'AWAITING_COLLECTION')">Dijemput</button>
        </div>
        <button onclick="loadUnprocessed(1)" style="padding:5px 10px;border-radius:7px;background:var(--surface2);border:1px solid var(--border);color:var(--text-muted);font-size:11px;cursor:pointer;">↻</button>
      </div>
    </div>
    <div id="unproc-table-wrap">
      <div style="text-align:center;padding:30px;color:var(--text-muted);font-size:12px;">Memuat data...</div>
    </div>
    <div id="unproc-pagination" style="display:flex;align-items:center;justify-content:space-between;margin-top:12px;font-size:11px;color:var(--text-muted);"></div>
  </div>

  <!-- ══════════════════════════════════
       PRODUK TERLARIS (dengan filter periode)
  ══════════════════════════════════ -->
  <div class="card">
    <div class="card-title" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
      <span>🏆 Ranking Produk</span>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <div style="display:flex;gap:4px;background:var(--surface2);border-radius:8px;padding:3px;">
          <button class="prod-tab active" onclick="switchProdTab(this,'today','<?= $filterDate ?>','<?= $filterDate ?>')">Hari ini</button>
          <button class="prod-tab" onclick="switchProdTab(this,'week','<?= date('Y-m-d', strtotime('monday this week')) ?>','<?= date('Y-m-d') ?>')">Minggu ini</button>
          <button class="prod-tab" onclick="switchProdTab(this,'month','<?= date('Y-m-01') ?>','<?= date('Y-m-d') ?>')">Bulan ini</button>
          <button class="prod-tab" onclick="openProdCustom()">Kustom</button>
        </div>
        <div id="prod-custom-wrap" style="display:none;gap:6px;align-items:center;">
          <input type="date" id="prod-from" max="<?= date('Y-m-d') ?>" style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:'DM Mono',monospace;font-size:11px;padding:4px 8px;">
          <span style="color:var(--text-muted);">–</span>
          <input type="date" id="prod-to" max="<?= date('Y-m-d') ?>" style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:'DM Mono',monospace;font-size:11px;padding:4px 8px;">
          <button onclick="applyProdCustom()" style="padding:4px 10px;border-radius:6px;background:var(--accent);border:none;color:white;font-size:11px;cursor:pointer;">Terapkan</button>
        </div>
        <span id="prod-period-label" style="font-size:10px;color:var(--text-muted);"></span>
      </div>
    </div>
    <div id="prod-table-wrap">
      <div style="text-align:center;padding:30px;color:var(--text-muted);font-size:12px;">Memuat...</div>
    </div>
  </div>

  <!-- ══════════════════════════════════
       COHORT ANALYSIS SECTION (tetap)
  ══════════════════════════════════ -->
  <!-- ═══════════════════════════════════════
       COHORT ANALYSIS SECTION
  ═══════════════════════════════════════ -->
  <div class="cohort-card">
    <div class="cohort-header-row">
      <div class="cohort-title">📊 Cohort Analysis <span style="font-size:11px;color:var(--text-muted);font-weight:400;font-family:'DM Mono',monospace;">berdasarkan order pertama customer</span></div>
      <div class="cohort-controls">
        <div class="cohort-date-group">
          <label>Dari</label>
          <input type="date" id="cohortDateFrom" max="<?= date('Y-m-d') ?>">
        </div>
        <div class="cohort-date-group">
          <label>Sampai</label>
          <input type="date" id="cohortDateTo" max="<?= date('Y-m-d') ?>">
        </div>
        <div class="gran-toggle">
          <button class="gran-btn" data-gran="daily">Harian</button>
          <button class="gran-btn active" data-gran="weekly">Mingguan</button>
          <button class="gran-btn" data-gran="monthly">Bulanan</button>
        </div>
        <div class="cohort-metric-toggle">
          <button class="metric-btn active" data-metric="retention">Retention %</button>
          <button class="metric-btn" data-metric="gmv">GMV</button>
          <button class="metric-btn" data-metric="aov">AOV</button>
        </div>
        <button class="btn-load-cohort" id="btnLoadCohort" onclick="loadCohort()">🔍 Tampilkan</button>
      </div>
    </div>

    <!-- Summary -->
    <div class="cohort-summary-grid" id="cohortSummary" style="display:none">
      <div class="cohort-sum-card"><div class="sum-val" id="sumTotalCustomers">—</div><div class="sum-lbl">Customer Baru</div></div>
      <div class="cohort-sum-card"><div class="sum-val" id="sumTotalCohorts">—</div><div class="sum-lbl">Jumlah Cohort</div></div>
      <div class="cohort-sum-card"><div class="sum-val" id="sumRetentionP1" style="color:var(--accent3);">—</div><div class="sum-lbl">Avg Retention +1</div></div>
      <div class="cohort-sum-card"><div class="sum-val" id="sumTotalGmv" style="color:var(--accent2);">—</div><div class="sum-lbl">Total GMV Cohort</div></div>
    </div>

    <!-- Table -->
    <div class="cohort-table-wrap" id="cohortTableWrap">
      <div class="cohort-loading">Pilih rentang tanggal dan klik <strong>Tampilkan</strong> untuk memuat data cohort.</div>
    </div>

    <!-- Legend -->
    <div class="cohort-legend" id="cohortLegend" style="display:none">
      <span>Intensitas:</span>
      <div class="legend-swatches" id="legendSwatches"></div>
      <span id="legendLabel">Rendah → Tinggi</span>
    </div>
  </div>

</div><!-- /container -->

<!-- Hourly inline sudah ada di card atas -->

<!-- Cohort Tooltip -->
<div class="cohort-tooltip-box" id="cohortTooltip"></div>

<div id="sync-toast"></div>

<script>
// ── GLOBAL VARS (accessible by all functions) ──
const filterDate = '<?= $filterDate ?>';
const todayStr   = '<?= date("Y-m-d") ?>';
const chartToday = '<?= date("Y-m-d") ?>'; // Chart selalu pakai hari ini, independen dari filter
const allDailyData = <?= json_encode(array_values($allDailyOrders), JSON_UNESCAPED_UNICODE|JSON_HEX_TAG) ?>;
var ctChart = null;
function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ═══════════════════════════════════════
// CHART ENGINE v3 — 4 tabs + inline date picker
// ═══════════════════════════════════════

// State
var ctMode = 'day';
var ctMainFrom = filterDate, ctMainTo = filterDate;
var ctCmpFrom  = null, ctCmpTo  = null;
var ctHourlyCache = {};
var _dpState = {ctx:null, year:new Date().getFullYear(), month:new Date().getMonth(),
  selFrom:null, selTo:null, selecting:null,
  cmpFrom:null, cmpTo:null, cmpSelecting:null, mode:'main'};
var _mthState = {year:new Date().getFullYear(), mainSel:null, cmpSel:null};
var DP_MN = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
var DP_MS = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];

function dpFmt(s){if(!s)return '';var d=new Date(s+'T00:00:00Z');return d.getUTCDate()+' '+DP_MS[d.getUTCMonth()]+' '+d.getUTCFullYear();}
function dpFmtR(f,t){if(!f)return 'Pilih tanggal';return f===t?dpFmt(f):dpFmt(f)+' – '+dpFmt(t);}

// ── Chart render/fetch/draw ──

function fetchHourly(date, cb) {
  if(ctHourlyCache[date]){ cb(ctHourlyCache[date]); return; }
  fetch('get_hourly_gmv.php?date='+date)
    .then(function(r){ return r.json(); })
    .then(function(data){
      ctHourlyCache[date] = {today:data.today, prev:data.prev, summary:data.summary, date:data.date, prev_date:data.prev_date};
      cb(ctHourlyCache[date]);
    }).catch(function(){ cb(null); });
}

function ctRender() {
  var isSingle = (ctMainFrom === ctMainTo);
  var badge = document.getElementById('chart-mode-badge');
  if(badge) badge.textContent = isSingle ? 'mode: per jam' : 'mode: per hari';

  if(isSingle) {
    var titleEl = document.getElementById('chart-title');
    var statusEl = document.getElementById('ct-status');
    if(titleEl) titleEl.textContent = '📈 Tren GMV per Jam — ' + dpFmt(ctMainFrom);
    if(statusEl) statusEl.textContent = 'Memuat data per jam...';

    fetchHourly(ctMainFrom, function(main) {
      if(!main){ if(statusEl) statusEl.textContent='Gagal memuat'; return; }

      // Labels: gabungan jam hari ini + kemarin (kemarin bisa sampai 23)
      var prevData  = (main.prev && main.prev.length) ? main.prev : [];
      var maxHours  = Math.max(main.today.length, prevData.length);
      var labels = [];
      for(var h=0; h<maxHours; h++) labels.push(String(h).padStart(2,'0')+':00');

      // Pad today data to maxHours
      var mainData = [];
      for(var h2=0; h2<maxHours; h2++){
        mainData.push(h2 < main.today.length ? main.today[h2].gmv : null);
      }

      var datasets = [{
        label: main.date, data: mainData,
        borderColor:'#6affb8', backgroundColor:'rgba(106,255,184,0.08)',
        borderWidth:2.5, pointRadius:3, pointHoverRadius:6, pointBackgroundColor:'#6affb8',
        fill:true, tension:0.4, spanGaps:false
      }];
      var legMain = document.getElementById('ct-leg-main');
      if(legMain) legMain.textContent = main.date;

      // Kemarin: grafik full day (main.prev), summary apple-to-apple (main.prev_apple)
      if(main.prev && main.prev.length) {
        var prevChartData = prevData.map(function(d){ return d.gmv; });
        datasets.push({
          label: main.prev_date || main.date, data: prevChartData,
          borderColor:'rgba(255,106,155,0.8)', backgroundColor:'transparent',
          borderWidth:1.5, borderDash:[5,4], pointRadius:2, fill:false, tension:0.4
        });
        var cmpWrap2 = document.getElementById('ct-leg-cmp-wrap');
        var cmpLeg2  = document.getElementById('ct-leg-cmp');
        if(cmpWrap2) cmpWrap2.style.display='flex';
        if(cmpLeg2)  cmpLeg2.textContent = main.prev_date || '';
        // Summary apple to apple
        var appleData   = (main.prev_apple && main.prev_apple.length) ? main.prev_apple : prevData;
        var todayTotal  = main.today.reduce(function(a,b){return a+b.gmv;},0);
        var prevTotal   = appleData.reduce(function(a,b){return a+b.gmv;},0);
        ctUpdateSummary(todayTotal, main.date, prevTotal, main.prev_date||'Kemarin');
      } else {
        var cW=document.getElementById('ct-leg-cmp-wrap');
        var sEl=document.getElementById('ct-summary');
        if(cW) cW.style.display='none';
        if(sEl) sEl.style.display='none';
      }

      ctDrawChart(labels, datasets, true);
      if(statusEl) statusEl.textContent = main.summary ? main.summary.compare_note||'' : '';
 else {
        var cmpWrap2 = document.getElementById('ct-leg-cmp-wrap');
        var sumEl2   = document.getElementById('ct-summary');
        if(cmpWrap2) cmpWrap2.style.display='none';
        if(sumEl2)   sumEl2.style.display='none';
        ctDrawChart(labels, datasets, true);
        if(statusEl) statusEl.textContent='';
      }
    });

  } else {
    // per hari mode
    var titleEl2  = document.getElementById('chart-title');
    var statusEl2 = document.getElementById('ct-status');
    if(titleEl2) titleEl2.textContent = '📈 Tren GMV — ' + dpFmtR(ctMainFrom, ctMainTo);

    var mainRows = getDailyGmv(ctMainFrom, ctMainTo);
    var labels2  = mainRows.map(function(d){ return dpFmt(d.order_date); });
    var mainData2= mainRows.map(function(d){ return parseFloat(d.daily_gmv)||0; });
    var datasets2 = [{
      label: dpFmtR(ctMainFrom,ctMainTo), data: mainData2,
      borderColor:'#6affb8', backgroundColor:'rgba(106,255,184,0.08)',
      borderWidth:2, pointRadius:2, pointHoverRadius:5, pointBackgroundColor:'#6affb8',
      fill:true, tension:0.3
    }];
    var legMain2 = document.getElementById('ct-leg-main');
    if(legMain2) legMain2.textContent = dpFmtR(ctMainFrom,ctMainTo);

    if(ctCmpFrom) {
      var cmpRows2 = getDailyGmv(ctCmpFrom, ctCmpTo);
      var cmpData2 = cmpRows2.map(function(d){ return parseFloat(d.daily_gmv)||0; });
      datasets2.push({
        label: dpFmtR(ctCmpFrom,ctCmpTo), data: cmpData2,
        borderColor:'rgba(255,106,155,0.8)', backgroundColor:'transparent',
        borderWidth:1.5, borderDash:[5,4], pointRadius:2, fill:false, tension:0.3
      });
      var cmpW3 = document.getElementById('ct-leg-cmp-wrap');
      var cmpL3 = document.getElementById('ct-leg-cmp');
      if(cmpW3) cmpW3.style.display='flex';
      if(cmpL3) cmpL3.textContent=dpFmtR(ctCmpFrom,ctCmpTo);
      ctUpdateSummary(
        mainData2.reduce(function(a,b){return a+b;},0), dpFmtR(ctMainFrom,ctMainTo),
        cmpData2.reduce(function(a,b){return a+b;},0), dpFmtR(ctCmpFrom,ctCmpTo)
      );
    } else {
      var cmpW4 = document.getElementById('ct-leg-cmp-wrap');
      var sumEl4 = document.getElementById('ct-summary');
      if(cmpW4) cmpW4.style.display='none';
      if(sumEl4) sumEl4.style.display='none';
    }
    ctDrawChart(labels2, datasets2, false);
    if(statusEl2) statusEl2.textContent='';
  }
}

function ctUpdateSummary(mainGmv, mainLbl, cmpGmv, cmpLbl) {
  var diff = mainGmv - cmpGmv;
  var pct  = cmpGmv > 0 ? ((diff/cmpGmv)*100).toFixed(1) : 0;
  var sumEl = document.getElementById('ct-summary');
  if(sumEl) sumEl.style.display='grid';
  var els = {
    'ct-lbl-main': mainLbl, 'ct-val-main': fmtRpFull(mainGmv),
    'ct-lbl-cmp':  cmpLbl,  'ct-val-cmp':  fmtRpFull(cmpGmv)
  };
  for(var k in els){ var e=document.getElementById(k); if(e)e.textContent=els[k]; }
  var dEl=document.getElementById('ct-val-diff'), pEl=document.getElementById('ct-val-pct');
  if(dEl){ dEl.textContent=(diff>=0?'+':'')+fmtRpFull(diff); dEl.style.color=diff>0?'var(--success)':diff<0?'var(--danger)':'var(--text-muted)'; }
  if(pEl){ pEl.textContent=(pct>0?'▲ +':parseFloat(pct)<0?'▼ ':'')+pct+'%'; pEl.style.color=parseFloat(pct)>0?'var(--success)':parseFloat(pct)<0?'var(--danger)':'var(--text-muted)'; }
}

function ctDrawChart(labels, datasets, isHourly) {
  var canvas = document.getElementById('ct-chart');
  if(!canvas) return;
  if(ctChart) ctChart.destroy();
  ctChart = new Chart(canvas, {
    type:'line', data:{labels:labels, datasets:datasets},
    options:{
      responsive:true, maintainAspectRatio:false,
      interaction:{mode:'index', intersect:false},
      plugins:{
        legend:{display:false},
        tooltip:{backgroundColor:'#1c1c28', borderColor:'#2a2a3a', borderWidth:1,
          titleColor:'#e8e8f0', bodyColor:'#6b6b8a',
          callbacks:{label:function(ctx){ return ' '+ctx.dataset.label+': Rp '+Math.round(ctx.parsed.y).toLocaleString('id-ID'); }}}
      },
      scales:{
        x:{grid:{color:'rgba(42,42,58,0.3)'}, ticks:{color:'#6b6b8a', font:{size:10}, maxTicksLimit:isHourly?12:20}},
        y:{grid:{color:'rgba(42,42,58,0.3)'}, ticks:{color:'#6b6b8a', font:{size:10}, callback:function(v){return fmtRp(v);}}, beginAtZero:true}
      }
    }
  });
}

function getDailyGmv(from, to) {
  var fTs=Date.UTC(+from.slice(0,4),+from.slice(5,7)-1,+from.slice(8,10))/1000;
  var tTs=Date.UTC(+to.slice(0,4),+to.slice(5,7)-1,+to.slice(8,10))/1000+86399;
  return allDailyData.filter(function(d){
    var ts=Date.UTC(+d.order_date.slice(0,4),+d.order_date.slice(5,7)-1,+d.order_date.slice(8,10))/1000;
    return ts>=fTs && ts<=tTs;
  });
}

function fmtRp(v){v=Math.round(v);if(v>=1e9)return'Rp '+(v/1e9).toFixed(1)+'M';if(v>=1e6)return'Rp '+(v/1e6).toFixed(1)+'jt';if(v>=1e3)return'Rp '+(v/1e3).toFixed(0)+'rb';return'Rp '+v;}
function fmtRpFull(v){return 'Rp '+Math.round(v).toLocaleString('id-ID');}

// ── Tab setter ──
window.ctSetMode = function(mode){
  ctMode = mode;
  document.querySelectorAll('.ct-tab').forEach(function(b){b.classList.remove('active');});
  var tab = document.getElementById('ct-tab-'+mode);
  if(tab) tab.classList.add('active');

  // Hide all panels
  ['7days','month','custom'].forEach(function(p){
    var el=document.getElementById('ct-panel-'+p);
    if(el)el.innerHTML='';el&&(el.style.display='none');
  });

  var today = filterDate; // dipakai oleh mode lain (week/month/custom sebagai anchor)

  if(mode==='day'){
    ctMainFrom=chartToday; ctMainTo=chartToday;
    var _yest=(function(){var d=new Date(chartToday);d.setDate(d.getDate()-1);return d.toISOString().slice(0,10);})();
    ctCmpFrom=_yest; ctCmpTo=_yest;
    document.getElementById('ct-compare-clear').style.display='inline-block';
    ctRender();
  } else if(mode==='7days'){
    ctCmpFrom=null; ctCmpTo=null;
    document.getElementById('ct-compare-clear').style.display='none';
    _dpState.year=new Date(today+'T00:00:00Z').getUTCFullYear();
    _dpState.month=new Date(today+'T00:00:00Z').getUTCMonth();
    _dpState.selFrom=null; _dpState.selTo=null; _dpState.selecting=null;
    _dpState.cmpFrom=null; _dpState.cmpTo=null; _dpState.cmpSelecting=null;
    _dpState.mode='main'; _dpState.ctx='7days';
    document.getElementById('ct-panel-7days').style.display='block';
    dpRender7();
  } else if(mode==='month'){
    ctCmpFrom=null; ctCmpTo=null;
    document.getElementById('ct-compare-clear').style.display='none';
    _mthState.year=new Date(today+'T00:00:00Z').getUTCFullYear();
    _mthState.mainSel=null; _mthState.cmpSel=null;
    document.getElementById('ct-panel-month').style.display='block';
    mthRender();
  } else if(mode==='custom'){
    ctCmpFrom=null; ctCmpTo=null;
    document.getElementById('ct-compare-clear').style.display='none';
    _dpState.year=new Date(today+'T00:00:00Z').getUTCFullYear();
    _dpState.month=new Date(today+'T00:00:00Z').getUTCMonth();
    _dpState.selFrom=null; _dpState.selTo=null; _dpState.selecting=null;
    _dpState.cmpFrom=null; _dpState.cmpTo=null; _dpState.cmpSelecting=null;
    _dpState.mode='main'; _dpState.ctx='custom';
    document.getElementById('ct-panel-custom').style.display='block';
    dpRenderCustom();
  }
};

// ═══ 7-HARI PICKER ═══
function dpRender7(){
  var wrap = document.getElementById('ct-panel-7days');
  var st = _dpState;
  var months = [
    {y:st.year,m:st.month},
    {y:st.month===11?st.year+1:st.year, m:st.month===11?0:st.month+1}
  ];
  var html = '<div class="dp-cal">';
  html += '<div class="dp-cal-header">';
  html += '<button class="dp-nav" onclick="dpNav7(-1)">‹</button>';
  html += '<span style="font-size:11px;color:#6b6b8a;">Klik tanggal akhir — 7 hari sebelumnya otomatis dipilih</span>';
  html += '<button class="dp-nav" onclick="dpNav7(1)">›</button>';
  html += '</div>';
  html += '<div class="dp-months-wrap">';
  months.forEach(function(mo){
    html += '<div>';
    html += '<div class="dp-month-title">'+DP_MN[mo.m]+' '+mo.y+'</div>';
    html += '<div class="dp-weekdays">'+['Sen','Sel','Rab','Kam','Jum','Sab','Min'].map(function(d){return '<div class="dp-wd">'+d+'</div>';}).join('')+'</div>';
    html += '<div class="dp-days">';
    html += dp7Days(mo.y,mo.m);
    html += '</div></div>';
  });
  html += '</div>';
  // Footer info
  var mainInfo = st.selTo ? (dpFmt(st.selFrom)+' – '+dpFmt(st.selTo)) : 'Belum dipilih';
  var cmpInfo  = st.cmpTo  ? (dpFmt(st.cmpFrom)+' – '+dpFmt(st.cmpTo))  : 'Belum dipilih';
  html += '<div class="dp-footer">';
  html += '<div><div style="font-size:10px;color:#6b6b8a;margin-bottom:3px;">● UTAMA</div><div class="dp-info" style="color:#00a896;">'+mainInfo+'</div></div>';
  html += '<div><div style="font-size:10px;color:#6b6b8a;margin-bottom:3px;">● PEMBANDING</div><div class="dp-info" style="color:#ff6a9b;">'+cmpInfo+'</div></div>';
  html += '<button class="dp-apply'+(st.selTo?' ready':'"')+'" id="dp7-apply" onclick="dpApply7()">Terapkan</button>';
  html += '</div></div>';
  wrap.innerHTML = html;
}

function dp7Days(yr,mo){
  var st = _dpState;
  var first = new Date(Date.UTC(yr,mo,1));
  var dow = first.getUTCDay(); if(dow===0)dow=7; dow--;
  var dim = new Date(Date.UTC(yr,mo+1,0)).getUTCDate();
  var pvd = new Date(Date.UTC(yr,mo,0)).getUTCDate();
  var html='';
  for(var i=dow-1;i>=0;i--) html+='<div class="dp-d dp-other">'+(pvd-i)+'</div>';
  for(var d=1;d<=dim;d++){
    var ds=yr+'-'+String(mo+1).padStart(2,'0')+'-'+String(d).padStart(2,'0');
    var cls='dp-d';
    if(ds===todayStr) cls+=' dp-today';
    if(ds>todayStr){ cls+=' dp-dis'; html+='<div class="'+cls+'">'+d+'</div>'; continue; }
    // Main range highlight
    if(st.selFrom&&st.selTo){
      if(ds===st.selTo) cls+=' dp-sel-end';
      else if(ds===st.selFrom) cls+=' dp-sel-start';
      else if(ds>st.selFrom&&ds<st.selTo) cls+=' dp-in-range';
    }
    // Cmp range
    if(st.cmpFrom&&st.cmpTo){
      if(ds===st.cmpTo) cls+=' dp-cmp-end';
      else if(ds===st.cmpFrom) cls+=' dp-cmp-start';
      else if(ds>st.cmpFrom&&ds<st.cmpTo) cls+=' dp-cmp-range';
    }
    html+='<div class="'+cls+'" onclick="dp7Click(\'' +ds+ '\')">'+d+'</div>';
  }
  var rem=42-(dow+dim); if(rem>7)rem-=7;
  for(var d2=1;d2<=rem;d2++) html+='<div class="dp-d dp-other">'+d2+'</div>';
  return html;
}

window.dpNav7 = function(dir){
  _dpState.month+=dir;
  if(_dpState.month>11){_dpState.month=0;_dpState.year++;}
  if(_dpState.month<0){_dpState.month=11;_dpState.year--;}
  dpRender7();
};
window.dp7Click = function(ds){
  var st=_dpState;
  if(st.mode==='main'){
    // Click = end date, auto compute 7-day start
    var end=new Date(ds+'T00:00:00Z');
    var start=new Date(end);start.setUTCDate(start.getUTCDate()-6);
    st.selFrom=start.toISOString().slice(0,10);
    st.selTo=ds;
    // Switch to cmp after main picked
    if(!st.cmpTo){ st.mode='cmp'; }
  } else {
    var end2=new Date(ds+'T00:00:00Z');
    var start2=new Date(end2);start2.setUTCDate(start2.getUTCDate()-6);
    st.cmpFrom=start2.toISOString().slice(0,10);
    st.cmpTo=ds;
  }
  dpRender7();
};
window.dpApply7 = function(){
  var st=_dpState;
  if(!st.selFrom||!st.selTo) return;
  ctMainFrom=st.selFrom; ctMainTo=st.selTo;
  if(st.cmpFrom&&st.cmpTo){ ctCmpFrom=st.cmpFrom; ctCmpTo=st.cmpTo; document.getElementById('ct-compare-clear').style.display='inline-block'; }
  else { ctCmpFrom=null; ctCmpTo=null; }
  document.getElementById('ct-panel-7days').style.display='none';
  ctRender();
};

// ═══ BULAN PICKER ═══
function mthRender(){
  var st=_mthState;
  var now=new Date(); var nowYM=now.getFullYear()*12+now.getMonth();
  var html='<div class="dp-cal">';
  html+='<div class="dp-cal-header">';
  html+='<button class="dp-nav" onclick="mthNav(-1)">‹</button>';
  html+='<span style="font-family:Syne,sans-serif;font-weight:700;font-size:13px;color:#e8e8f0;">'+st.year+'</span>';
  html+='<button class="dp-nav" onclick="mthNav(1)">›</button>';
  html+='</div>';
  html+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">';
  // Main col
  html+='<div><div style="font-size:10px;color:#00a896;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">● Bulan Utama</div><div class="mth-grid">';
  for(var m=0;m<12;m++){
    var ym=st.year*12+m, ys=st.year+'-'+String(m+1).padStart(2,'0');
    var cls='mth-btn'+(ym>nowYM?' mth-dis':'')+(st.mainSel===ys?' mth-sel-main':'');
    html+='<div class="'+cls+'" onclick="mthClick(\'main\',\'' +ys+ '\')">'+DP_MS[m]+'</div>';
  }
  html+='</div></div>';
  // Cmp col
  html+='<div><div style="font-size:10px;color:#ff6a9b;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">● Pembanding</div><div class="mth-grid">';
  for(var m2=0;m2<12;m2++){
    var ym2=st.year*12+m2, ys2=st.year+'-'+String(m2+1).padStart(2,'0');
    var cls2='mth-btn'+(ym2>nowYM?' mth-dis':'')+(st.cmpSel===ys2?' mth-sel-cmp':'');
    html+='<div class="'+cls2+'" onclick="mthClick(\'cmp\',\'' +ys2+ '\')">'+DP_MS[m2]+'</div>';
  }
  html+='</div></div>';
  html+='</div>';
  var mainInfo=st.mainSel?DP_MN[parseInt(st.mainSel.slice(5,7))-1]+' '+st.mainSel.slice(0,4):'Belum dipilih';
  var cmpInfo =st.cmpSel ?DP_MN[parseInt(st.cmpSel.slice(5,7)) -1]+' '+st.cmpSel.slice(0,4) :'Belum dipilih';
  html+='<div class="dp-footer">';
  html+='<div><div style="font-size:10px;color:#6b6b8a;margin-bottom:3px;">● Utama</div><div class="dp-info" style="color:#00a896;">'+mainInfo+'</div></div>';
  html+='<div><div style="font-size:10px;color:#6b6b8a;margin-bottom:3px;">● Pembanding</div><div class="dp-info" style="color:#ff6a9b;">'+cmpInfo+'</div></div>';
  html+='<button class="dp-apply'+(st.mainSel?' ready':'"')+'" onclick="mthApply()">Terapkan</button>';
  html+='</div></div>';
  document.getElementById('ct-panel-month').innerHTML=html;
}
window.mthNav=function(dir){_mthState.year+=dir;mthRender();};
window.mthClick=function(who,ym){
  if(who==='main')_mthState.mainSel=ym;else _mthState.cmpSel=ym;
  mthRender();
};
window.mthApply=function(){
  var st=_mthState; if(!st.mainSel) return;
  function mthRange(ym){var d=new Date(ym+'-01T00:00:00Z');var last=new Date(Date.UTC(d.getUTCFullYear(),d.getUTCMonth()+1,0));return {f:ym+'-01',t:last.toISOString().slice(0,10)};}
  var mr=mthRange(st.mainSel); ctMainFrom=mr.f; ctMainTo=mr.t;
  if(st.cmpSel){var cr=mthRange(st.cmpSel);ctCmpFrom=cr.f;ctCmpTo=cr.t;document.getElementById('ct-compare-clear').style.display='inline-block';}
  else{ctCmpFrom=null;ctCmpTo=null;}
  document.getElementById('ct-panel-month').style.display='none';
  ctRender();
};

// ═══ CUSTOM PICKER ═══
function dpRenderCustom(){
  var wrap=document.getElementById('ct-panel-custom');
  var st=_dpState;
  var months=[{y:st.year,m:st.month},{y:st.month===11?st.year+1:st.year,m:st.month===11?0:st.month+1}];
  var html='<div class="dp-cal">';
  // Mode toggle
  html+='<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">';
  html+='<div style="display:flex;gap:3px;background:#0a0a0f;border-radius:7px;padding:3px;">';
  html+='<button onclick="dpCustMode(\'main\')" style="padding:4px 12px;border-radius:5px;background:'+(st.mode==='main'?'#1c1c28':'transparent')+';border:none;color:'+(st.mode==='main'?'#e8e8f0':'#6b6b8a')+';font-family:DM Mono,monospace;font-size:11px;cursor:pointer;">● Periode Utama</button>';
  html+='<button onclick="dpCustMode(\'cmp\')" style="padding:4px 12px;border-radius:5px;background:'+(st.mode==='cmp'?'#1c1c28':'transparent')+';border:none;color:'+(st.mode==='cmp'?'#e8e8f0':'#6b6b8a')+';font-family:DM Mono,monospace;font-size:11px;cursor:pointer;">● Pembanding</button>';
  html+='</div>';
  html+='<span style="font-size:11px;color:#6b6b8a;">'+(st.mode==='main'?'Klik tanggal mulai → klik akhir':'Klik tanggal mulai pembanding → akhir')+'</span>';
  html+='</div>';
  html+='<div class="dp-cal-header" style="margin-bottom:12px;">';
  html+='<button class="dp-nav" onclick="dpNavC(-1)">‹</button><span></span><button class="dp-nav" onclick="dpNavC(1)">›</button>';
  html+='</div>';
  html+='<div class="dp-months-wrap">';
  months.forEach(function(mo){
    html+='<div>';
    html+='<div class="dp-month-title">'+DP_MN[mo.m]+' '+mo.y+'</div>';
    html+='<div class="dp-weekdays">'+['Sen','Sel','Rab','Kam','Jum','Sab','Min'].map(function(d){return '<div class="dp-wd">'+d+'</div>';}).join('')+'</div>';
    html+='<div class="dp-days">'+dpCDays(mo.y,mo.m)+'</div></div>';
  });
  html+='</div>';
  var mInfo=st.selFrom?(st.selTo?dpFmtR(st.selFrom,st.selTo):'Pilih akhir…'):'Belum dipilih';
  var cInfo=st.cmpFrom?(st.cmpTo?dpFmtR(st.cmpFrom,st.cmpTo):'Pilih akhir…'):'Belum dipilih';
  html+='<div class="dp-footer">';
  html+='<div><div style="font-size:10px;color:#6b6b8a;margin-bottom:2px;">● Utama</div><div class="dp-info" style="color:#00a896;">'+mInfo+'</div></div>';
  html+='<div><div style="font-size:10px;color:#6b6b8a;margin-bottom:2px;">● Pembanding</div><div class="dp-info" style="color:#ff6a9b;">'+cInfo+'</div></div>';
  var canApply=!!(st.selFrom&&st.selTo);
  html+='<button class="dp-apply'+(canApply?' ready':'"')+'" onclick="dpApplyC()">Terapkan</button>';
  html+='</div></div>';
  wrap.innerHTML=html;
}
function dpCDays(yr,mo){
  var st=_dpState;
  var first=new Date(Date.UTC(yr,mo,1));
  var dow=first.getUTCDay();if(dow===0)dow=7;dow--;
  var dim=new Date(Date.UTC(yr,mo+1,0)).getUTCDate();
  var pvd=new Date(Date.UTC(yr,mo,0)).getUTCDate();
  var html='';
  for(var i=dow-1;i>=0;i--) html+='<div class="dp-d dp-other">'+(pvd-i)+'</div>';
  for(var d=1;d<=dim;d++){
    var ds=yr+'-'+String(mo+1).padStart(2,'0')+'-'+String(d).padStart(2,'0');
    var cls='dp-d';
    if(ds===todayStr)cls+=' dp-today';
    if(ds>todayStr){cls+=' dp-dis';html+='<div class="'+cls+'">'+d+'</div>';continue;}
    // Main range
    var sf=st.selFrom,st2=st.selTo,sel=st.selecting;
    if(sf&&st2){if(ds===sf)cls+=' dp-sel-start';else if(ds===st2)cls+=' dp-sel-end';else if(ds>sf&&ds<st2)cls+=' dp-in-range';}
    else if(sf&&!st2&&ds===sf)cls+=' dp-sel-end';
    // Cmp
    var cf=st.cmpFrom,ct2=st.cmpTo;
    if(cf&&ct2){if(ds===cf)cls+=' dp-cmp-start';else if(ds===ct2)cls+=' dp-cmp-end';else if(ds>cf&&ds<ct2)cls+=' dp-cmp-range';}
    else if(cf&&!ct2&&ds===cf)cls+=' dp-cmp-end';
    html+='<div class="'+cls+'" onclick="dpClickC(\'' +ds+ '\')">'+d+'</div>';
  }
  var rem=42-(dow+dim);if(rem>7)rem-=7;
  for(var d2=1;d2<=rem;d2++)html+='<div class="dp-d dp-other">'+d2+'</div>';
  return html;
}
window.dpCustMode=function(m){_dpState.mode=m;dpRenderCustom();};
window.dpNavC=function(dir){
  _dpState.month+=dir;
  if(_dpState.month>11){_dpState.month=0;_dpState.year++;}
  if(_dpState.month<0){_dpState.month=11;_dpState.year--;}
  dpRenderCustom();
};
window.dpClickC=function(ds){
  var st=_dpState;
  if(st.mode==='main'){
    if(!st.selFrom||st.selTo){st.selFrom=ds;st.selTo=null;st.selecting=ds;}
    else{var a=st.selecting,b=ds;st.selFrom=a<b?a:b;st.selTo=a<b?b:a;st.selecting=null;}
  } else {
    if(!st.cmpFrom||st.cmpTo){st.cmpFrom=ds;st.cmpTo=null;st.cmpSelecting=ds;}
    else{var a2=st.cmpSelecting,b2=ds;st.cmpFrom=a2<b2?a2:b2;st.cmpTo=a2<b2?b2:a2;st.cmpSelecting=null;}
  }
  dpRenderCustom();
};
window.dpApplyC=function(){
  var st=_dpState;
  if(!st.selFrom||!st.selTo) return;
  ctMainFrom=st.selFrom; ctMainTo=st.selTo;
  if(st.cmpFrom&&st.cmpTo){ctCmpFrom=st.cmpFrom;ctCmpTo=st.cmpTo;document.getElementById('ct-compare-clear').style.display='inline-block';}
  else{ctCmpFrom=null;ctCmpTo=null;}
  document.getElementById('ct-panel-custom').style.display='none';
  ctRender();
};

window.ctClearCompare=function(){
  ctCmpFrom=null;ctCmpTo=null;
  document.getElementById('ct-compare-clear').style.display='none';
  document.getElementById('ct-leg-cmp-wrap').style.display='none';
  document.getElementById('ct-summary').style.display='none';
  ctRender();
};

// Init chart on load
window.addEventListener('DOMContentLoaded',function(){
  if(typeof ctSetMode==='function') ctSetMode('day');
  // Fetch visitor data
  fetch('get_shop_performance.php?date='+filterDate)
    .then(function(r){return r.json();})
    .then(function(d){
      if(d.error) return;
      var ov = d.overall || {};
      // Sum hourly up to current hour for today's partial data
      var nowH = new Date().getHours();
      var vis=0, cust=0, items=0;
      (d.hourly||[]).forEach(function(h){ if(h.hour<=nowH){vis+=h.visitors;cust+=h.customers;items+=h.items_sold;} });
      // Use overall if available, else sum
      if(ov.visitors>0){ vis=ov.visitors; cust=ov.customers; items=ov.items_sold; }
      var el1=document.getElementById('card-visitors');
      var el2=document.getElementById('card-customers');
      var el3=document.getElementById('card-items-sold');
      var el4=document.getElementById('card-perf-update');
      if(el1) el1.textContent=vis.toLocaleString('id-ID');
      if(el2) el2.textContent=cust.toLocaleString('id-ID')+' pembeli unik';
      if(el3) el3.textContent=items.toLocaleString('id-ID')+' item terjual';
      if(el4) el4.textContent='Update: '+new Date().getHours().toString().padStart(2,'0')+':'+new Date().getMinutes().toString().padStart(2,'0');
    }).catch(function(){
      var el=document.getElementById('card-visitors');
      if(el) el.textContent='N/A';
    });
});

// ═══════════════════════════════════════
// COHORT ENGINE
// ═══════════════════════════════════════
(function(){
  const today = new Date();
  const sixMonthsAgo = new Date(today);
  sixMonthsAgo.setMonth(sixMonthsAgo.getMonth() - 6);
  document.getElementById('cohortDateFrom').value = sixMonthsAgo.toISOString().split('T')[0];
  document.getElementById('cohortDateTo').value   = today.toISOString().split('T')[0];

  let currentGran   = 'weekly';
  let currentMetric = 'retention';
  let cohortData    = null;

  document.querySelectorAll('.gran-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      document.querySelectorAll('.gran-btn').forEach(function(b){b.classList.remove('active');});
      this.classList.add('active');
      currentGran = this.dataset.gran;
    });
  });

  document.querySelectorAll('.metric-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      document.querySelectorAll('.metric-btn').forEach(function(b){b.classList.remove('active');});
      this.classList.add('active');
      currentMetric = this.dataset.metric;
      if(cohortData) renderCohortTable(cohortData);
    });
  });

  window.loadCohort = function(){
    const dateFrom = document.getElementById('cohortDateFrom').value;
    const dateTo   = document.getElementById('cohortDateTo').value;
    if(!dateFrom || !dateTo){ alert('Pilih rentang tanggal terlebih dahulu.'); return; }
    if(dateFrom > dateTo){ alert('Tanggal "Dari" tidak boleh lebih besar dari "Sampai".'); return; }

    const btn = document.getElementById('btnLoadCohort');
    btn.disabled = true; btn.textContent = '⏳ Memuat...';
    document.getElementById('cohortTableWrap').innerHTML = '<div class="cohort-loading">🔄 Menganalisis data cohort...</div>';
    document.getElementById('cohortSummary').style.display = 'none';
    document.getElementById('cohortLegend').style.display  = 'none';

    fetch('cohort.php?granularity='+currentGran+'&date_from='+dateFrom+'&date_to='+dateTo)
      .then(function(r){ return r.json(); })
      .then(function(data){
        btn.disabled = false; btn.textContent = '🔍 Tampilkan';
        if(!data.success){
          document.getElementById('cohortTableWrap').innerHTML = '<div class="cohort-empty">❌ Error: '+escHtml(data.error||'Unknown error')+'</div>';
          return;
        }
        if(!data.cohorts || data.cohorts.length === 0){
          document.getElementById('cohortTableWrap').innerHTML = '<div class="cohort-empty">📭 Tidak ada data pada rentang tanggal ini.</div>';
          return;
        }
        cohortData = data;
        renderCohortSummary(data.summary);
        renderCohortTable(data);
        document.getElementById('cohortSummary').style.display = 'grid';
        document.getElementById('cohortLegend').style.display  = 'flex';
      })
      .catch(function(err){
        btn.disabled = false; btn.textContent = '🔍 Tampilkan';
        document.getElementById('cohortTableWrap').innerHTML = '<div class="cohort-empty">❌ Gagal memuat: '+escHtml(err.message)+'</div>';
      });
  };

  function renderCohortSummary(s){
    document.getElementById('sumTotalCustomers').textContent = s.total_customers.toLocaleString('id-ID');
    document.getElementById('sumTotalCohorts').textContent   = s.total_cohorts;
    document.getElementById('sumRetentionP1').textContent    = s.avg_retention_p1 + '%';
    document.getElementById('sumTotalGmv').textContent       = fmtCohortRp(s.total_gmv);
  }

  function renderCohortTable(data){
    const cohorts = data.cohorts;
    const periods = data.periods;

    // Update legend
    const legendLabels = { retention:'Retention %', gmv:'GMV (Rp)', aov:'AOV (Rp)' };
    document.getElementById('legendLabel').textContent = legendLabels[currentMetric] + ' — Rendah → Tinggi';
    renderLegendSwatches();

    // Kumpulkan nilai untuk normalisasi warna
    const allVals = [];
    cohorts.forEach(function(c){
      periods.forEach(function(p){
        const cell = c.periods[p.key];
        if(cell){ const v = getMetricVal(cell, currentMetric); if(v > 0) allVals.push(v); }
      });
    });
    const maxVal = allVals.length ? Math.max.apply(null, allVals) : 1;
    const minVal = allVals.length ? Math.min.apply(null, allVals) : 0;

    let html = '<table class="cohort-table"><thead><tr>';
    html += '<th>Cohort</th><th>N</th>';
    periods.forEach(function(p){ html += '<th>'+escHtml(p.label)+'</th>'; });
    html += '</tr></thead><tbody>';

    cohorts.forEach(function(c){
      html += '<tr>';
      html += '<td>'+escHtml(c.cohort_label)+'</td>';
      html += '<td>'+c.cohort_size+'</td>';
      periods.forEach(function(p){
        const cell = c.periods[p.key];
        if(cell === null || cell === undefined){
          html += '<td><span class="heat-cell null-cell">—</span></td>';
        } else {
          const val     = getMetricVal(cell, currentMetric);
          const dispVal = formatCellVal(val, currentMetric);
          const bg      = getHeatColor(val, minVal, maxVal, currentMetric);
          const ttData  = encodeURIComponent(JSON.stringify({
            cohort: c.cohort_label, period: p.label,
            retention: cell.retention_pct, active: cell.active_cust,
            orders: cell.valid_orders, gmv: cell.gmv, aov: cell.aov
          }));
          html += '<td><span class="heat-cell" style="background:'+bg+'"'
                + ' onmouseenter="showCohortTT(event,\''+ttData+'\')"'
                + ' onmouseleave="hideCohortTT()"'
                + ' onmousemove="moveCohortTT(event)">'+dispVal+'</span></td>';
        }
      });
      html += '</tr>';
    });

    html += '</tbody></table>';
    document.getElementById('cohortTableWrap').innerHTML = html;
  }

  function renderLegendSwatches(){
    const container = document.getElementById('legendSwatches');
    container.innerHTML = '';
    for(let i=0; i<5; i++){
      const ratio = i / 4;
      const el = document.createElement('span');
      el.style.background = getHeatColorRatio(ratio, currentMetric);
      container.appendChild(el);
    }
  }

  function getMetricVal(cell, metric){
    if(metric==='retention') return cell.retention_pct;
    if(metric==='gmv')       return cell.gmv;
    if(metric==='aov')       return cell.aov;
    return 0;
  }

  function formatCellVal(val, metric){
    if(metric==='retention') return val + '%';
    return fmtCohortRp(val);
  }

  function fmtCohortRp(v){
    v = Math.round(v);
    if(v >= 1000000000) return 'Rp '+(v/1e9).toFixed(1)+'M';
    if(v >= 1000000)    return 'Rp '+(v/1e6).toFixed(1)+'jt';
    if(v >= 1000)       return 'Rp '+(v/1000).toFixed(0)+'rb';
    return 'Rp '+v.toLocaleString('id-ID');
  }

  function getHeatColorRatio(ratio, metric){
    if(ratio === 0) return '#111122';
    if(metric === 'retention'){
      // Hijau gelap → hijau terang
      const g = Math.round(55 + ratio * 165);
      const r = Math.round(10 + ratio * 20);
      const b = Math.round(25 + ratio * 75);
      return 'rgb('+r+','+g+','+b+')';
    } else {
      // Biru-ungu gelap → violet terang
      const r = Math.round(30  + ratio * 150);
      const g = Math.round(20  + ratio * 55);
      const b = Math.round(90  + ratio * 115);
      return 'rgb('+r+','+g+','+b+')';
    }
  }

  function getHeatColor(val, min, max, metric){
    if(val === 0) return '#0d0d1e';
    const ratio = (max > min) ? (val - min) / (max - min) : 1;
    return getHeatColorRatio(Math.max(0.1, ratio), metric);
  }

  // escHtml is now global

  // Cohort Tooltip
  window.showCohortTT = function(e, encoded){
    const d  = JSON.parse(decodeURIComponent(encoded));
    const tt = document.getElementById('cohortTooltip');
    tt.innerHTML =
      '<div class="tt-title">📍 '+escHtml(d.cohort)+' → '+escHtml(d.period)+'</div>'
      +'<div class="tt-row"><span class="tt-label">Retention</span><span class="tt-val">'+d.retention+'%</span></div>'
      +'<div class="tt-row"><span class="tt-label">Customer aktif</span><span class="tt-val">'+d.active+' orang</span></div>'
      +'<div class="tt-row"><span class="tt-label">Total order</span><span class="tt-val">'+d.orders+'</span></div>'
      +'<div class="tt-row"><span class="tt-label">GMV</span><span class="tt-val">'+fmtCohortRp(d.gmv)+'</span></div>'
      +'<div class="tt-row"><span class="tt-label">AOV</span><span class="tt-val">'+fmtCohortRp(d.aov)+'</span></div>';
    tt.style.display = 'block';
    moveCohortTT(e);
  };
  window.hideCohortTT = function(){ document.getElementById('cohortTooltip').style.display = 'none'; };
  window.moveCohortTT = function(e){
    const tt = document.getElementById('cohortTooltip');
    const x  = e.clientX + 14, y = e.clientY - 10;
    tt.style.left = Math.min(x, window.innerWidth  - tt.offsetWidth  - 10) + 'px';
    tt.style.top  = Math.min(y, window.innerHeight - tt.offsetHeight - 10) + 'px';
  };

})();


// Cancel rate calendar state (global)
const cancelState = { from:filterDate, to:filterDate, calYear:new Date().getFullYear(), calMonth:new Date().getMonth(), tab:'day', selecting:null, weekRefYear:new Date().getFullYear(), weekRefMonth:new Date().getMonth(), monthYear:new Date().getFullYear(), selWeekFrom:null, selWeekTo:null, selMonth:null };

function getMonDay(d){const x=new Date(d),day=x.getUTCDay()||7;x.setUTCDate(x.getUTCDate()-day+1);return x;}
function getSunDay(d){const m=getMonDay(d),s=new Date(m);s.setUTCDate(s.getUTCDate()+6);return s;}
function getWN(d){const date=new Date(Date.UTC(d.getUTCFullYear(),d.getUTCMonth(),d.getUTCDate()));date.setUTCDate(date.getUTCDate()+4-(date.getUTCDay()||7));const ys=new Date(Date.UTC(date.getUTCFullYear(),0,1));return Math.ceil((((date-ys)/86400000)+1)/7);}

// ═══════════════════════════════════════
// HOURLY INLINE CHART
// ═══════════════════════════════════════
let hourlyInlineChart = null;
function loadHourlyInline(){
  const btn = document.getElementById('btn-refresh-hourly');
  if(btn) btn.textContent = '⏳';
  fetch('get_hourly_gmv.php?date='+filterDate)
    .then(function(r){return r.json();})
    .then(function(data){
      if(btn) btn.textContent = '↻ Refresh';
      const s=data.summary;
      const frf=function(v){return 'Rp '+Math.round(v).toLocaleString('id-ID');};
      document.getElementById('hi-today-gmv').textContent=frf(s.today_gmv);
      document.getElementById('hi-prev-gmv').textContent='Kemarin: '+frf(s.prev_gmv);
      document.getElementById('hi-today-order').textContent=s.today_order+' order';
      document.getElementById('hi-prev-order').textContent='Kemarin: '+s.prev_order+' order';
      const diff=s.diff_gmv,pct=s.diff_pct;
      const dEl=document.getElementById('hi-diff-gmv'),pEl=document.getElementById('hi-diff-pct');
      dEl.textContent=(diff>=0?'+':'')+frf(diff);
      dEl.style.color=diff>0?'var(--success)':diff<0?'var(--danger)':'var(--text-muted)';
      pEl.textContent=(pct>0?'▲ +':pct<0?'▼ ':'')+pct+'%';
      pEl.style.color=pct>0?'var(--success)':pct<0?'var(--danger)':'var(--text-muted)';
      document.getElementById('h-leg-today').textContent=data.date;
      document.getElementById('h-leg-prev').textContent=data.prev_date;
      document.getElementById('hi-last-update').textContent='Update: '+new Date().toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit'});
      const labels=data.today.map(function(d){return String(d.hour).padStart(2,'0')+':00';});
      const canvas=document.getElementById('hourly-inline-chart');
      if(!canvas)return;
      if(hourlyInlineChart)hourlyInlineChart.destroy();
      hourlyInlineChart=new Chart(canvas,{type:'line',data:{labels:labels,datasets:[
        {label:data.date,data:data.today.map(function(d){return d.gmv;}),borderColor:'#6affb8',backgroundColor:'rgba(106,255,184,0.08)',borderWidth:2.5,pointRadius:3,pointHoverRadius:6,pointBackgroundColor:'#6affb8',fill:true,tension:0.4},
        {label:data.prev_date,data:data.prev.map(function(d){return d.gmv;}),borderColor:'rgba(106,255,184,0.35)',backgroundColor:'transparent',borderWidth:1.5,borderDash:[5,4],pointRadius:2,fill:false,tension:0.4}
      ]},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},
        plugins:{legend:{display:false},tooltip:{backgroundColor:'#1c1c28',borderColor:'#2a2a3a',borderWidth:1,titleColor:'#e8e8f0',bodyColor:'#6b6b8a',callbacks:{label:function(ctx){return ' '+ctx.dataset.label+': Rp '+Math.round(ctx.parsed.y).toLocaleString('id-ID');}}}},
        scales:{x:{grid:{color:'rgba(42,42,58,0.3)'},ticks:{color:'#6b6b8a',font:{size:10},maxTicksLimit:12}},y:{grid:{color:'rgba(42,42,58,0.3)'},ticks:{color:'#6b6b8a',font:{size:10},callback:function(v){if(v>=1000000)return 'Rp '+(v/1000000).toFixed(1)+'jt';if(v>=1000)return 'Rp '+(v/1000).toFixed(0)+'rb';return 'Rp '+v;}},beginAtZero:true}}
      }});
    }).catch(function(err){if(btn)btn.textContent='↻ Refresh';document.getElementById('hi-last-update').textContent='Gagal memuat';});
}
// loadHourlyInline removed - card now shows visitors

// ═══════════════════════════════════════
// ORDER BELUM DIPROSES + SLA
// ═══════════════════════════════════════
let unprocPage=1, unprocStatus='ALL', unprocTotal=0;
const SLA_COLORS = {ok:'var(--success)',warn:'var(--warning)',crit:'#ff8c42',late:'var(--danger)'};

function calcSlaClass(slaTs){
  const now=Math.floor(Date.now()/1000);
  const diff=slaTs-now;
  if(diff<0)return 'late';
  if(diff<3*3600)return 'crit';
  if(diff<12*3600)return 'warn';
  return 'ok';
}
function fmtSla(slaTs){
  const now=Math.floor(Date.now()/1000);
  const diff=slaTs-now;
  const cls=calcSlaClass(slaTs);
  const dt=new Date(slaTs*1000);
  const dd=String(dt.getDate()).padStart(2,'0');
  const mo=['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'][dt.getMonth()];
  const hh=String(dt.getHours()).padStart(2,'0');
  const mm=String(dt.getMinutes()).padStart(2,'0');
  let text;
  if(diff<0){const h=Math.floor(Math.abs(diff)/3600);const m=Math.floor((Math.abs(diff)%3600)/60);text='TERLAMBAT '+(h>0?h+'j ':'')+(m>0?m+'m':'');}
  else if(diff<3600){text=Math.floor(diff/60)+'m lagi';}
  else if(diff<86400){text=Math.floor(diff/3600)+'j '+Math.floor((diff%3600)/60)+'m lagi';}
  else{text=dd+' '+mo+' '+hh+':'+mm;}
  const color=SLA_COLORS[cls];
  return '<span style="color:'+color+';font-size:11px;font-weight:'+(cls==='late'?'700':'500')+';">'+text+'</span>';
}

function switchUnprocTab(el,status){
  document.querySelectorAll('.unp-tab').forEach(function(b){b.classList.remove('active');});
  el.classList.add('active');
  unprocStatus=status;
  unprocPage=1;
  loadUnprocessed(1);
}

function loadUnprocessed(page){
  unprocPage=page||1;
  const wrap=document.getElementById('unproc-table-wrap');
  const pag=document.getElementById('unproc-pagination');
  wrap.innerHTML='<div style="text-align:center;padding:30px;color:var(--text-muted);font-size:12px;">⏳ Memuat...</div>';
  pag.innerHTML='';
  const url='get_unprocessed_orders.php?page='+unprocPage+'&status='+unprocStatus;
  fetch(url).then(function(r){return r.json();})
  .then(function(data){
    if(!data.success){wrap.innerHTML='<div class="unproc-empty">❌ '+escHtml(data.error||'Error')+'</div>';return;}
    const orders=data.orders;
    unprocTotal=data.total;
    document.getElementById('unproc-badge').textContent=unprocTotal;
    if(!orders||orders.length===0){wrap.innerHTML='<div class="unproc-empty">✅ Tidak ada order yang perlu diproses</div>';return;}
    let html='<table class="unproc-table"><thead><tr><th>Order ID</th><th>Produk</th><th>Status</th><th>Waktu Order</th><th>SLA Deadline</th><th>Total</th></tr></thead><tbody>';
    orders.forEach(function(o){
      const slaTs=parseInt(o.sla_deadline)||0;
      const slaHtml=slaTs?fmtSla(slaTs):'<span style="color:var(--text-muted);">—</span>';
      const prods=(o.products||'').split('|||').filter(Boolean).slice(0,2);
      const more=(o.products||'').split('|||').filter(Boolean).length-2;
      let prodHtml=prods.map(function(p){return '<div style="font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px;">'+escHtml(p.substring(0,60))+'</div>';}).join('');
      if(more>0)prodHtml+='<div style="font-size:10px;color:var(--text-muted);">+'+more+' lainnya</div>';
      const statusClass=o.status&&o.status.indexOf('CANCEL')>-1?'badge-red':o.status&&(o.status.indexOf('AWAITING')>-1||o.status.indexOf('UNPAID')>-1)?'badge-yellow':'badge-gray';
      const dt=new Date(parseInt(o.create_time)*1000);
      const tstr=String(dt.getDate()).padStart(2,'0')+'/'+String(dt.getMonth()+1).padStart(2,'0')+' '+String(dt.getHours()).padStart(2,'0')+':'+String(dt.getMinutes()).padStart(2,'0');
      html+='<tr>';
      html+='<td class="order-id" style="font-size:11px;white-space:nowrap;">#'+(o.order_id||'').slice(-12)+'</td>';
      html+='<td>'+prodHtml+'</td>';
      html+='<td><span class="status-badge '+statusClass+'" style="font-size:10px;">'+escHtml(o.status||'-')+'</span></td>';
      html+='<td style="font-size:11px;white-space:nowrap;color:var(--text-muted);">'+tstr+'</td>';
      html+='<td>'+slaHtml+'</td>';
      html+='<td style="font-size:11px;white-space:nowrap;color:var(--accent3);">Rp '+Math.round(parseFloat(o.total_amount||0)).toLocaleString('id-ID')+'</td>';
      html+='</tr>';
    });
    html+='</tbody></table>';
    wrap.innerHTML=html;

    // Pagination
    const totalPages=data.total_pages||1;
    if(totalPages>1){
      let pages='<div style="display:flex;align-items:center;gap:4px;">';
      if(unprocPage>1)pages+='<button onclick="loadUnprocessed(1)" class="pg-btn">«</button><button onclick="loadUnprocessed('+(unprocPage-1)+')" class="pg-btn">‹</button>';
      const start=Math.max(1,unprocPage-2),end=Math.min(totalPages,unprocPage+2);
      for(let p=start;p<=end;p++){pages+='<button onclick="loadUnprocessed('+p+')" class="pg-btn'+(p===unprocPage?' pg-active':'')+'">'+p+'</button>';}
      if(unprocPage<totalPages)pages+='<button onclick="loadUnprocessed('+(unprocPage+1)+')" class="pg-btn">›</button><button onclick="loadUnprocessed('+totalPages+')" class="pg-btn">»</button>';
      pages+='</div>';
      pag.innerHTML='<span>'+unprocPage+'-'+Math.min(unprocPage*5,unprocTotal)+' dari '+unprocTotal+'</span>'+pages;
    } else {
      pag.innerHTML='<span>'+orders.length+' dari '+unprocTotal+' order</span>';
    }
  }).catch(function(e){wrap.innerHTML='<div class="unproc-empty">❌ Gagal memuat: '+escHtml(e.message)+'</div>';});
}

// ═══════════════════════════════════════
// RANKING PRODUK DENGAN FILTER PERIODE
// ═══════════════════════════════════════
let prodFrom='<?= $filterDate ?>', prodTo='<?= $filterDate ?>';

function switchProdTab(el,mode,from,to){
  document.querySelectorAll('.prod-tab').forEach(function(b){b.classList.remove('active');});
  el.classList.add('active');
  document.getElementById('prod-custom-wrap').style.display='none';
  prodFrom=from;prodTo=to;
  loadProdRanking(from,to);
}
function openProdCustom(){
  document.querySelectorAll('.prod-tab').forEach(function(b){b.classList.remove('active');});
  document.querySelectorAll('.prod-tab')[3].classList.add('active');
  const cw=document.getElementById('prod-custom-wrap');
  cw.style.display=cw.style.display==='none'?'flex':'none';
}
function applyProdCustom(){
  const f=document.getElementById('prod-from').value;
  const t=document.getElementById('prod-to').value;
  if(!f||!t){return;}
  prodFrom=f;prodTo=t;
  document.getElementById('prod-custom-wrap').style.display='none';
  loadProdRanking(f,t);
}
function loadProdRanking(from,to){
  const wrap=document.getElementById('prod-table-wrap');
  wrap.innerHTML='<div style="text-align:center;padding:30px;color:var(--text-muted);font-size:12px;">⏳ Memuat...</div>';
  const lbl=document.getElementById('prod-period-label');
  if(from===to)lbl.textContent=from;else lbl.textContent=from+' s/d '+to;
  fetch('get_top_products.php?from='+from+'&to='+to)
  .then(function(r){return r.json();})
  .then(function(data){
    if(!data.success||!data.products||data.products.length===0){
      wrap.innerHTML='<div class="unproc-empty">📭 Tidak ada data produk pada periode ini</div>';return;
    }
    const maxQty=data.products[0].total_qty||1;
    const medals=['🥇','🥈','🥉'];
    let html='<table style="width:100%;border-collapse:collapse;font-size:12px;"><thead><tr>';
    html+='<th style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);padding:8px 10px;border-bottom:1px solid var(--border);text-align:left;">#</th>';
    html+='<th style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);padding:8px 10px;border-bottom:1px solid var(--border);text-align:left;">Produk</th>';
    html+='<th style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);padding:8px 10px;border-bottom:1px solid var(--border);text-align:right;">Qty</th>';
    html+='<th style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);padding:8px 10px;border-bottom:1px solid var(--border);text-align:right;">Order</th>';
    html+='<th style="font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);padding:8px 10px;border-bottom:1px solid var(--border);text-align:right;">Revenue</th>';
    html+='</tr></thead><tbody>';
    data.products.forEach(function(p,i){
      const rank=i+1;
      const pct=Math.round((p.total_qty/maxQty)*100);
      const medal=rank<=3?medals[rank-1]:'';
      html+='<tr>';
      html+='<td style="padding:10px 10px;border-bottom:1px solid rgba(42,42,58,0.4);"><span style="font-size:'+(rank<=3?'16':'13')+'px;">'+(medal||'<span style="color:var(--text-muted);font-size:11px;">'+rank+'</span>')+'</span></td>';
      html+='<td style="padding:10px 10px;border-bottom:1px solid rgba(42,42,58,0.4);max-width:300px;">';
      html+='<div style="font-size:12px;margin-bottom:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+escHtml((p.product_name||'-').substring(0,80))+'</div>';
      html+='<div style="display:flex;align-items:center;gap:6px;"><div style="height:5px;border-radius:3px;background:linear-gradient(90deg,var(--accent),var(--accent2));width:'+pct+'%;max-width:200px;min-width:2px;"></div><span style="font-size:10px;color:var(--text-muted);">'+pct+'%</span></div>';
      html+='</td>';
      html+='<td style="padding:10px 10px;border-bottom:1px solid rgba(42,42,58,0.4);text-align:right;font-weight:500;">'+parseInt(p.total_qty).toLocaleString('id-ID')+'</td>';
      html+='<td style="padding:10px 10px;border-bottom:1px solid rgba(42,42,58,0.4);text-align:right;color:var(--text-muted);">'+parseInt(p.order_count).toLocaleString('id-ID')+'</td>';
      html+='<td style="padding:10px 10px;border-bottom:1px solid rgba(42,42,58,0.4);text-align:right;color:var(--accent3);white-space:nowrap;">Rp '+Math.round(p.total_revenue||0).toLocaleString('id-ID')+'</td>';
      html+='</tr>';
    });
    html+='</tbody></table>';
    wrap.innerHTML=html;
  }).catch(function(e){wrap.innerHTML='<div class="unproc-empty">❌ Gagal memuat: '+escHtml(e.message)+'</div>';});
}

// Pagination CSS inline
(function(){
  const style=document.createElement('style');
  style.textContent='.pg-btn{padding:4px 9px;border-radius:6px;background:var(--surface2);border:1px solid var(--border);color:var(--text-muted);font-family:"DM Mono",monospace;font-size:11px;cursor:pointer;}.pg-btn.pg-active{background:var(--accent);border-color:var(--accent);color:white;}.pg-btn:hover:not(.pg-active){border-color:var(--accent);}';
  document.head.appendChild(style);
})();

// Init on load
window.addEventListener('DOMContentLoaded',function(){
  loadUnprocessed(1);
  loadProdRanking('<?= $filterDate ?>','<?= $filterDate ?>');
});
// ===== SYNC =====
function syncData(type){
  const btn=document.getElementById('btn-sync-'+type);const toast=document.getElementById('sync-toast');
  btn.disabled=true;btn.textContent='⏳ Syncing...';
  toast.style.display='block';toast.style.borderColor='var(--accent)';
  toast.innerHTML='⏳ Sedang sync '+type+'...<br><small style="color:var(--text-muted)">Mohon tunggu</small>';
  fetch((type==='orders'?'sync_orders.php':'sync_products.php')+'?date=<?= date("Y-m-d") ?>')
    .then(function(r){return r.text();})
    .then(function(t){
      const m=t.match(/Total tersimpan[:\s]+(\d+)/i);
      toast.style.borderColor='var(--accent3)';
      toast.innerHTML='✅ Sync '+type+' selesai!<br><small style="color:var(--text-muted)">'+(m?m[1]:'?')+' data tersimpan</small>';
      btn.disabled=false;btn.textContent='↻ Sync '+(type==='orders'?'Orders':'Products');
      setTimeout(function(){toast.style.display='none';window.location.reload();},2000);
    }).catch(function(){
      toast.style.borderColor='var(--danger)';toast.innerHTML='❌ Sync gagal!';
      btn.disabled=false;btn.textContent='↻ Sync '+(type==='orders'?'Orders':'Products');
      setTimeout(function(){toast.style.display='none';},4000);
    });
}
</script>

</div>
</body>
</html>