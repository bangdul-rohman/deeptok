<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');

$pdo    = getDB();
$shopId = getShopId();
$date   = $_GET['date'] ?? date('Y-m-d');
$start  = strtotime($date . ' 00:00:00');
$end    = strtotime($date . ' 23:59:59');

// GMV per status
$stmt = $pdo->prepare("
    SELECT status, COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as gmv
    FROM orders 
    WHERE shop_id=? AND create_time BETWEEN ? AND ?
    GROUP BY status ORDER BY gmv DESC
");
$stmt->execute([$shopId, $start, $end]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_all = 0;
$total_excl_cancel = 0;
foreach ($rows as $r) {
    $total_all += $r['gmv'];
    if ($r['status'] !== 'CANCELLED') $total_excl_cancel += $r['gmv'];
}

echo json_encode([
    'date'               => $date,
    'total_all_status'   => $total_all,
    'total_excl_cancel'  => $total_excl_cancel,
    'breakdown'          => $rows,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
