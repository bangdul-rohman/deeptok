<?php
declare(strict_types=1);

require_once __DIR__ . '/session_config.php';

require_once __DIR__ . '/db.php';

$deeptokLoggedIn = !empty($_SESSION['deeptok_logged_in'])
    && !empty($_SESSION['user_id'])
    && !empty($_SESSION['shop_id']);

if (!$deeptokLoggedIn) {
    header('Location: /login.php?redirect=' . urlencode('/margin.php'));
    exit;
}

$shopId = (int)$_SESSION['shop_id'];

$marginTimeout = 2 * 3600;
if (isset($_SESSION['margin_login_time']) && (time() - $_SESSION['margin_login_time']) > $marginTimeout) {
    unset($_SESSION['margin_logged_in'], $_SESSION['margin_login_time'], $_SESSION['margin_shop_id']);
}

if (!empty($_SESSION['margin_logged_in']) && isset($_SESSION['margin_shop_id'])) {
    if ((int)$_SESSION['margin_shop_id'] !== $shopId) {
        unset($_SESSION['margin_logged_in'], $_SESSION['margin_login_time'], $_SESSION['margin_shop_id']);
    }
}

$marginLoggedIn = !empty($_SESSION['margin_logged_in']);
$errorCode      = (int)($_GET['error'] ?? 0);

$pdo = getDB();

// Cek password margin
$marginPasswordSet = false;
try {
    $r = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=? LIMIT 1");
    $r->execute(["margin_password__{$shopId}"]);
    $row = $r->fetch();
    $marginPasswordSet = !empty($row['value']);
} catch (Exception $e) {}

// Handle AJAX: save ads cost
if ($marginLoggedIn && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    if ($_POST['ajax_action'] === 'save_ads') {
        $year   = (int)($_POST['year'] ?? date('Y'));
        $month  = (int)($_POST['month'] ?? date('n'));
        $amount = (float)($_POST['amount'] ?? 0);
        $notes  = trim($_POST['notes'] ?? '');
        try {
            $pdo->prepare("INSERT INTO ads_cost (shop_id, period_year, period_month, amount, currency, notes)
                VALUES (?, ?, ?, ?, 'IDR', ?)
                ON DUPLICATE KEY UPDATE amount=VALUES(amount), notes=VALUES(notes), updated_at=NOW()")
                ->execute([$shopId, $year, $month, $amount, $notes]);
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }
    exit;
}

// Ambil data untuk dashboard
$selYear  = (int)($_GET['year']  ?? date('Y'));
$selMonth = (int)($_GET['month'] ?? date('n'));

if ($marginLoggedIn) {
    // Settlement per bulan
    $stmtSettle = $pdo->prepare("
        SELECT
            settlement_date,
            SUM(settlement_amount) as total
        FROM settlements
        WHERE shop_id = ? AND YEAR(settlement_date) = ? AND MONTH(settlement_date) = ?
        GROUP BY settlement_date
        ORDER BY settlement_date ASC
    ");
    $stmtSettle->execute([$shopId, $selYear, $selMonth]);
    $settlementDaily = $stmtSettle->fetchAll(PDO::FETCH_ASSOC);

    $totalSettlement = array_sum(array_column($settlementDaily, 'total'));

    // Total HPP bulan ini (hpp_per_unit × qty dari order_items)
    $stmtHpp = $pdo->prepare("
        SELECT COALESCE(SUM(h.hpp_per_unit * oi.quantity), 0) as total_hpp
        FROM order_items oi
        JOIN hpp_sku h ON h.seller_sku = oi.seller_sku AND h.shop_id = oi.shop_id
        JOIN orders o ON o.order_id = oi.order_id AND o.shop_id = oi.shop_id
        WHERE oi.shop_id = ?
          AND YEAR(FROM_UNIXTIME(o.create_time)) = ?
          AND MONTH(FROM_UNIXTIME(o.create_time)) = ?
          AND o.status NOT IN ('CANCELLED', 'UNPAID')
    ");
    $stmtHpp->execute([$shopId, $selYear, $selMonth]);
    $totalHpp = (float)($stmtHpp->fetchColumn() ?? 0);

    // Ads cost bulan ini
    $stmtAds = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM ads_cost WHERE shop_id=? AND period_year=? AND period_month=?");
    $stmtAds->execute([$shopId, $selYear, $selMonth]);
    $totalAds = (float)($stmtAds->fetchColumn() ?? 0);

    // Net profit
    $totalCost   = $totalHpp + $totalAds;
    $netProfit   = $totalSettlement - $totalCost;
    $marginPct   = $totalSettlement > 0 ? round($netProfit / $totalSettlement * 100, 1) : 0;

    // Settlement per bulan untuk chart (12 bulan terakhir)
    $stmtChart = $pdo->prepare("
        SELECT YEAR(settlement_date) as yr, MONTH(settlement_date) as mo,
               SUM(settlement_amount) as total
        FROM settlements
        WHERE shop_id = ?
          AND settlement_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
        GROUP BY yr, mo
        ORDER BY yr ASC, mo ASC
    ");
    $stmtChart->execute([$shopId]);
    $chartData = $stmtChart->fetchAll(PDO::FETCH_ASSOC);

    // Detail settlement bulan ini
    $stmtDetail = $pdo->prepare("
        SELECT settlement_date, statement_id, order_id, settlement_amount
        FROM settlements
        WHERE shop_id = ? AND YEAR(settlement_date) = ? AND MONTH(settlement_date) = ?
        ORDER BY settlement_date DESC
        LIMIT 100
    ");
    $stmtDetail->execute([$shopId, $selYear, $selMonth]);
    $settlementDetail = $stmtDetail->fetchAll(PDO::FETCH_ASSOC);

    // Ads cost history
    $stmtAdsHistory = $pdo->prepare("
        SELECT period_year, period_month, amount, notes, updated_at
        FROM ads_cost WHERE shop_id = ?
        ORDER BY period_year DESC, period_month DESC
        LIMIT 24
    ");
    $stmtAdsHistory->execute([$shopId]);
    $adsHistory = $stmtAdsHistory->fetchAll(PDO::FETCH_ASSOC);

    // Available months/years
    $stmtMonths = $pdo->prepare("
        SELECT DISTINCT YEAR(settlement_date) as yr, MONTH(settlement_date) as mo
        FROM settlements WHERE shop_id = ?
        ORDER BY yr DESC, mo DESC
        LIMIT 24
    ");
    $stmtMonths->execute([$shopId]);
    $availMonths = $stmtMonths->fetchAll(PDO::FETCH_ASSOC);
}

function fmtRp(float $n): string {
    return 'Rp ' . number_format($n, 0, ',', '.');
}
$months_id = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agt','Sep','Okt','Nov','Des'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Margin Dashboard — Deeptok</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<style>
:root {
  --bg:#0a0a0f; --surface:#13131a; --surface2:#1c1c28; --border:#2a2a3a;
  --accent:#7c6aff; --accent2:#ff6a9b; --accent3:#6affb8;
  --text:#e8e8f0; --text-muted:#6b6b8a;
  --success:#6affb8; --warning:#ffb86a; --danger:#ff6a6a;
}
*{margin:0;padding:0;box-sizing:border-box;}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh;}
.header{padding:14px 28px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--surface);position:sticky;top:0;z-index:100;}
.logo{font-family:'Syne',sans-serif;font-weight:800;font-size:20px;background:linear-gradient(135deg,var(--accent),var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
.btn{padding:7px 14px;border-radius:8px;font-family:'DM Mono',monospace;font-size:11px;cursor:pointer;text-decoration:none;transition:opacity 0.2s;border:none;display:inline-flex;align-items:center;gap:6px;color:var(--text);}
.btn:hover{opacity:0.8;}
.btn-secondary{background:var(--surface2);border:1px solid var(--border);}
.btn-danger{background:var(--surface2);color:var(--danger);border:1px solid rgba(255,106,106,0.3);}
.btn-primary{background:var(--accent);color:white;border:none;}
.btn-success{background:rgba(106,255,184,0.15);color:var(--success);border:1px solid rgba(106,255,184,0.3);}

/* Gate */
.gate-wrap{display:flex;align-items:center;justify-content:center;min-height:calc(100vh - 57px);padding:24px;}
.gate-card{background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:44px 40px;width:100%;max-width:400px;position:relative;overflow:hidden;}
.gate-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--accent),var(--accent2),var(--accent3));border-radius:18px 18px 0 0;}
.gate-icon{font-size:40px;margin-bottom:14px;display:block;text-align:center;}
.gate-title{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;text-align:center;margin-bottom:4px;}
.gate-sub{font-size:11px;color:var(--text-muted);text-align:center;margin-bottom:28px;}
.field-wrap{margin-bottom:14px;}
.field-label{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:6px;display:block;}
.field-input{width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:9px;color:var(--text);font-family:'DM Mono',monospace;font-size:13px;padding:11px 14px;transition:border-color 0.2s;}
.field-input:focus{outline:none;border-color:var(--accent);}
.btn-login{width:100%;padding:13px;border-radius:9px;background:linear-gradient(135deg,var(--accent),var(--accent2));border:none;color:white;font-family:'Syne',sans-serif;font-size:14px;font-weight:700;cursor:pointer;margin-top:8px;transition:opacity 0.2s;position:relative;z-index:10;}
.btn-login:hover{opacity:0.88;}
.error-msg{background:rgba(255,106,106,0.1);border:1px solid rgba(255,106,106,0.3);border-radius:8px;padding:10px 14px;font-size:12px;color:var(--danger);margin-bottom:18px;}
.warn-msg{background:rgba(255,184,106,0.1);border:1px solid rgba(255,184,106,0.3);border-radius:8px;padding:10px 14px;font-size:12px;color:var(--warning);margin-bottom:18px;}
.back-link{display:block;text-align:center;margin-top:18px;font-size:11px;color:var(--text-muted);text-decoration:none;}
.back-link:hover{color:var(--text);}

/* Dashboard */
.container{max-width:1400px;margin:0 auto;padding:24px 28px;}
.top-bar{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;}
.page-title{font-family:'Syne',sans-serif;font-size:20px;font-weight:800;}
.page-sub{font-size:11px;color:var(--text-muted);margin-top:2px;}
.period-select{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.period-select select{background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--text);font-family:'DM Mono',monospace;font-size:12px;padding:7px 12px;cursor:pointer;}
.period-select select:focus{outline:none;border-color:var(--accent);}

/* Cards */
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:24px;}
.card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:20px 22px;position:relative;overflow:hidden;}
.card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;}
.card.settlement::before{background:linear-gradient(90deg,var(--accent),var(--accent2));}
.card.hpp::before{background:linear-gradient(90deg,var(--warning),#ff8c6a);}
.card.ads::before{background:linear-gradient(90deg,var(--accent2),#ff8c6a);}
.card.profit::before{background:linear-gradient(90deg,var(--accent3),var(--accent));}
.card.margin-pct::before{background:linear-gradient(90deg,var(--accent3),#6ae0ff);}
.card-label{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1.2px;margin-bottom:10px;}
.card-value{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;line-height:1.1;}
.card-value.positive{color:var(--success);}
.card-value.negative{color:var(--danger);}
.card-value.neutral{color:var(--accent);}
.card-sub{font-size:10px;color:var(--text-muted);margin-top:6px;}

/* Tabs */
.tabs{display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:24px;}
.tab{padding:10px 18px;font-size:12px;color:var(--text-muted);cursor:pointer;border-bottom:2px solid transparent;transition:all 0.2s;background:none;border-top:none;border-left:none;border-right:none;font-family:'DM Mono',monospace;}
.tab:hover{color:var(--text);}
.tab.active{color:var(--accent);border-bottom-color:var(--accent);}
.tab-content{display:none;}
.tab-content.active{display:block;}

/* Chart */
.chart-wrap{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:24px;}
.chart-title{font-size:12px;color:var(--text-muted);margin-bottom:16px;text-transform:uppercase;letter-spacing:1px;}

/* Table */
.table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:24px;}
.table-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.table-head-title{font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;}
table{width:100%;border-collapse:collapse;}
th{padding:12px 16px;font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;text-align:left;border-bottom:1px solid var(--border);background:var(--surface2);}
td{padding:12px 16px;font-size:12px;border-bottom:1px solid rgba(42,42,58,0.5);}
tr:last-child td{border-bottom:none;}
tr:hover td{background:rgba(124,106,255,0.04);}
.badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:10px;}
.badge-success{background:rgba(106,255,184,0.12);color:var(--success);}
.badge-warning{background:rgba(255,184,106,0.12);color:var(--warning);}
.badge-danger{background:rgba(255,106,106,0.12);color:var(--danger);}

/* Form Ads */
.form-card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:24px;margin-bottom:24px;}
.form-title{font-family:'Syne',sans-serif;font-size:15px;font-weight:700;margin-bottom:20px;}
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;align-items:end;}
.form-group label{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1.2px;display:block;margin-bottom:6px;}
.form-group input, .form-group select{width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--text);font-family:'DM Mono',monospace;font-size:12px;padding:10px 12px;}
.form-group input:focus, .form-group select:focus{outline:none;border-color:var(--accent);}
.toast{position:fixed;bottom:24px;right:24px;background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:12px 18px;font-size:12px;z-index:999;transform:translateY(80px);opacity:0;transition:all 0.3s;}
.toast.show{transform:translateY(0);opacity:1;}
.toast.success{border-color:rgba(106,255,184,0.4);color:var(--success);}
.toast.error{border-color:rgba(255,106,106,0.4);color:var(--danger);}
.empty-state{text-align:center;padding:48px;color:var(--text-muted);font-size:12px;}
</style>
</head>
<body>

<div class="header">
  <div class="logo">⚡ Deeptok</div>
  <div style="display:flex;align-items:center;gap:8px;">
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
    <?php if ($marginLoggedIn): ?>
    <a href="margin_auth.php?action=logout" class="btn btn-danger">⏻ Keluar Margin</a>
    <?php endif; ?>
  </div>
</div>

<?php if (!$marginLoggedIn): ?>
<!-- LOGIN GATE -->
<div class="gate-wrap">
  <div class="gate-card">
    <span class="gate-icon">🔐</span>
    <div class="gate-title">Margin Dashboard</div>
    <div class="gate-sub">Akses terbatas — masukkan kredensial margin</div>

    <?php if ($errorCode === 1): ?>
    <div class="error-msg">❌ Username atau password salah.</div>
    <?php elseif ($errorCode === 2): ?>
    <div class="error-msg">❌ Sesi tidak valid. Silakan <a href="/login.php" style="color:var(--danger);">login ulang</a>.</div>
    <?php endif; ?>

    <?php if (!$marginPasswordSet): ?>
    <div class="warn-msg">⚠️ Kredensial margin belum diset. Minta admin atur di Admin Panel → Atur Kredensial.</div>
    <?php endif; ?>

    <form method="POST" action="margin_auth.php">
      <input type="hidden" name="action" value="login">
      <input type="hidden" name="redirect" value="/margin.php">
      <input type="hidden" name="shop_id" value="<?= $shopId ?>">
      <div class="field-wrap">
        <label class="field-label">Username</label>
        <input class="field-input" type="text" name="username" placeholder="Username margin" required>
      </div>
      <div class="field-wrap">
        <label class="field-label">Password</label>
        <input class="field-input" type="password" name="password" placeholder="Password margin" required>
      </div>
      <button type="submit" class="btn-login">🔑 Masuk ke Margin Dashboard</button>
    </form>
    <a href="dashboard.php" class="back-link">← Kembali ke Dashboard Utama</a>
  </div>
</div>

<?php else: ?>
<!-- DASHBOARD -->
<div class="container">
  <div class="top-bar">
    <div>
      <div class="page-title">💰 Margin Dashboard</div>
      <div class="page-sub">Analisis profitabilitas & settlement</div>
    </div>
    <div class="period-select">
      <span style="font-size:11px;color:var(--text-muted);">Periode:</span>
      <select id="selMonth" onchange="changePeriod()">
        <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>" <?= $m == $selMonth ? 'selected' : '' ?>><?= $months_id[$m] ?></option>
        <?php endfor; ?>
      </select>
      <select id="selYear" onchange="changePeriod()">
        <?php for ($y = date('Y'); $y >= 2024; $y--): ?>
        <option value="<?= $y ?>" <?= $y == $selYear ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </div>
  </div>

  <!-- CARDS -->
  <div class="cards">
    <div class="card settlement">
      <div class="card-label">💵 Total Settlement</div>
      <div class="card-value neutral"><?= fmtRp($totalSettlement) ?></div>
      <div class="card-sub">Uang masuk <?= $months_id[$selMonth] ?> <?= $selYear ?></div>
    </div>
    <div class="card hpp">
      <div class="card-label">📦 Total HPP</div>
      <div class="card-value" style="color:var(--warning)"><?= fmtRp($totalHpp) ?></div>
      <div class="card-sub">Harga pokok produk terjual</div>
    </div>
    <div class="card ads">
      <div class="card-label">📢 Biaya Ads</div>
      <div class="card-value" style="color:var(--accent2)"><?= fmtRp($totalAds) ?></div>
      <div class="card-sub">Budget iklan bulan ini</div>
    </div>
    <div class="card profit">
      <div class="card-label">📈 Net Profit</div>
      <div class="card-value <?= $netProfit >= 0 ? 'positive' : 'negative' ?>"><?= fmtRp($netProfit) ?></div>
      <div class="card-sub">Settlement − HPP − Ads</div>
    </div>
    <div class="card margin-pct">
      <div class="card-label">🎯 Margin</div>
      <div class="card-value <?= $marginPct >= 0 ? 'positive' : 'negative' ?>"><?= $marginPct ?>%</div>
      <div class="card-sub">Net Profit / Settlement</div>
    </div>
  </div>

  <!-- TABS -->
  <div class="tabs">
    <button class="tab active" onclick="switchTab('overview')">📊 Overview</button>
    <button class="tab" onclick="switchTab('settlement')">💳 Detail Settlement</button>
    <button class="tab" onclick="switchTab('ads')">📢 Biaya Ads</button>
  </div>

  <!-- TAB: OVERVIEW -->
  <div class="tab-content active" id="tab-overview">
    <div class="chart-wrap">
      <div class="chart-title">Tren Settlement 12 Bulan Terakhir</div>
      <canvas id="chartSettlement" height="80"></canvas>
    </div>

    <div class="chart-wrap">
      <div class="chart-title">Settlement Harian — <?= $months_id[$selMonth] ?> <?= $selYear ?></div>
      <canvas id="chartDaily" height="80"></canvas>
    </div>

    <!-- Summary biaya -->
    <div class="table-wrap">
      <div class="table-head"><div class="table-head-title">Ringkasan Biaya <?= $months_id[$selMonth] ?> <?= $selYear ?></div></div>
      <table>
        <thead><tr><th>Item</th><th>Jumlah</th><th>% dari Settlement</th></tr></thead>
        <tbody>
          <tr>
            <td>💵 Total Settlement</td>
            <td><?= fmtRp($totalSettlement) ?></td>
            <td><span class="badge badge-success">100%</span></td>
          </tr>
          <tr>
            <td>📦 HPP Produk</td>
            <td><?= fmtRp($totalHpp) ?></td>
            <td><span class="badge badge-warning"><?= $totalSettlement > 0 ? round($totalHpp/$totalSettlement*100,1) : 0 ?>%</span></td>
          </tr>
          <tr>
            <td>📢 Biaya Ads</td>
            <td><?= fmtRp($totalAds) ?></td>
            <td><span class="badge badge-warning"><?= $totalSettlement > 0 ? round($totalAds/$totalSettlement*100,1) : 0 ?>%</span></td>
          </tr>
          <tr style="font-weight:bold;">
            <td>🎯 Net Profit</td>
            <td style="color:<?= $netProfit >= 0 ? 'var(--success)' : 'var(--danger)' ?>"><?= fmtRp($netProfit) ?></td>
            <td><span class="badge <?= $marginPct >= 0 ? 'badge-success' : 'badge-danger' ?>"><?= $marginPct ?>%</span></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- TAB: SETTLEMENT -->
  <div class="tab-content" id="tab-settlement">
    <div class="table-wrap">
      <div class="table-head">
        <div class="table-head-title">Detail Settlement <?= $months_id[$selMonth] ?> <?= $selYear ?></div>
        <span style="font-size:11px;color:var(--text-muted)"><?= count($settlementDetail) ?> transaksi</span>
      </div>
      <?php if (empty($settlementDetail)): ?>
      <div class="empty-state">Tidak ada data settlement untuk periode ini</div>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Tanggal</th>
            <th>Statement ID</th>
            <th>Order ID</th>
            <th>Jumlah</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($settlementDetail as $s): ?>
          <tr>
            <td><?= date('d M Y', strtotime($s['settlement_date'])) ?></td>
            <td style="font-size:10px;color:var(--text-muted)"><?= htmlspecialchars(substr($s['statement_id'] ?? '-', 0, 30)) ?>...</td>
            <td style="font-size:10px"><?= htmlspecialchars(substr($s['order_id'] ?? '-', 0, 25)) ?>...</td>
            <td style="color:var(--success)"><?= fmtRp((float)$s['settlement_amount']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <!-- Rekapitulasi harian -->
    <div class="table-wrap">
      <div class="table-head"><div class="table-head-title">Rekapitulasi Harian</div></div>
      <?php if (empty($settlementDaily)): ?>
      <div class="empty-state">Tidak ada data</div>
      <?php else: ?>
      <table>
        <thead><tr><th>Tanggal</th><th>Total Settlement</th></tr></thead>
        <tbody>
          <?php foreach (array_reverse($settlementDaily) as $d): ?>
          <tr>
            <td><?= date('d M Y', strtotime($d['settlement_date'])) ?></td>
            <td style="color:var(--success)"><?= fmtRp((float)$d['total']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- TAB: ADS -->
  <div class="tab-content" id="tab-ads">
    <div class="form-card">
      <div class="form-title">➕ Input Biaya Ads</div>
      <div class="form-grid">
        <div class="form-group">
          <label>Bulan</label>
          <select id="adsMonth">
            <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $m == $selMonth ? 'selected' : '' ?>><?= $months_id[$m] ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Tahun</label>
          <select id="adsYear">
            <?php for ($y = date('Y'); $y >= 2024; $y--): ?>
            <option value="<?= $y ?>" <?= $y == $selYear ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Total Biaya Ads (Rp)</label>
          <input type="number" id="adsAmount" placeholder="contoh: 5000000" min="0">
        </div>
        <div class="form-group">
          <label>Catatan</label>
          <input type="text" id="adsNotes" placeholder="opsional">
        </div>
        <div class="form-group">
          <label>&nbsp;</label>
          <button class="btn btn-primary" onclick="saveAds()" style="width:100%;padding:10px;justify-content:center;">💾 Simpan</button>
        </div>
      </div>
    </div>

    <div class="table-wrap">
      <div class="table-head"><div class="table-head-title">Riwayat Biaya Ads</div></div>
      <?php if (empty($adsHistory)): ?>
      <div class="empty-state">Belum ada data biaya ads</div>
      <?php else: ?>
      <table>
        <thead><tr><th>Periode</th><th>Total Ads</th><th>Catatan</th><th>Diupdate</th></tr></thead>
        <tbody>
          <?php foreach ($adsHistory as $a): ?>
          <tr>
            <td><?= $months_id[(int)$a['period_month']] ?> <?= $a['period_year'] ?></td>
            <td style="color:var(--accent2)"><?= fmtRp((float)$a['amount']) ?></td>
            <td style="color:var(--text-muted)"><?= htmlspecialchars($a['notes'] ?? '-') ?></td>
            <td style="font-size:10px;color:var(--text-muted)"><?= date('d M Y', strtotime($a['updated_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
// Tab switch
function switchTab(name) {
  document.querySelectorAll('.tab').forEach((t,i) => {
    const names = ['overview','settlement','ads'];
    t.classList.toggle('active', names[i] === name);
  });
  document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
}

// Period change
function changePeriod() {
  const m = document.getElementById('selMonth').value;
  const y = document.getElementById('selYear').value;
  window.location.href = '?month=' + m + '&year=' + y;
}

// Toast
function showToast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast ' + type + ' show';
  setTimeout(() => t.classList.remove('show'), 3000);
}

// Save ads
function saveAds() {
  const month  = document.getElementById('adsMonth').value;
  const year   = document.getElementById('adsYear').value;
  const amount = document.getElementById('adsAmount').value;
  const notes  = document.getElementById('adsNotes').value;
  if (!amount || parseFloat(amount) < 0) { showToast('Masukkan jumlah biaya ads', 'error'); return; }
  fetch('margin.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams({ajax_action:'save_ads', month, year, amount, notes})
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) {
      showToast('✅ Biaya ads tersimpan!');
      setTimeout(() => location.reload(), 1200);
    } else {
      showToast('❌ Gagal: ' + (d.msg || 'Error'), 'error');
    }
  });
}

// Charts
<?php
$chartLabels = [];
$chartValues = [];
foreach ($chartData as $c) {
    $chartLabels[] = $months_id[(int)$c['mo']] . ' ' . $c['yr'];
    $chartValues[] = (float)$c['total'];
}

$dailyLabels = [];
$dailyValues = [];
foreach ($settlementDaily as $d) {
    $dailyLabels[] = date('d M', strtotime($d['settlement_date']));
    $dailyValues[] = (float)$d['total'];
}
?>
const chartLabels = <?= json_encode($chartLabels) ?>;
const chartValues = <?= json_encode($chartValues) ?>;
const dailyLabels = <?= json_encode($dailyLabels) ?>;
const dailyValues = <?= json_encode($dailyValues) ?>;

const chartOpts = {
  responsive: true,
  plugins: { legend: { display: false } },
  scales: {
    x: { grid: { color: 'rgba(42,42,58,0.8)' }, ticks: { color: '#6b6b8a', font: { size: 10 } } },
    y: { grid: { color: 'rgba(42,42,58,0.8)' }, ticks: { color: '#6b6b8a', font: { size: 10 },
      callback: v => 'Rp ' + (v/1e6).toFixed(1) + 'jt' } }
  }
};

new Chart(document.getElementById('chartSettlement'), {
  type: 'bar',
  data: {
    labels: chartLabels,
    datasets: [{ data: chartValues, backgroundColor: 'rgba(124,106,255,0.6)', borderColor: '#7c6aff', borderWidth: 1, borderRadius: 4 }]
  },
  options: chartOpts
});

new Chart(document.getElementById('chartDaily'), {
  type: 'line',
  data: {
    labels: dailyLabels,
    datasets: [{ data: dailyValues, borderColor: '#6affb8', backgroundColor: 'rgba(106,255,184,0.1)', tension: 0.4, fill: true, pointBackgroundColor: '#6affb8', pointRadius: 3 }]
  },
  options: chartOpts
});
</script>
<?php endif; ?>
</body>
</html>