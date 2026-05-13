<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');

$pdo    = getDB();
$shopId = getShopId();

// Ambil 10 order terbaru + terlama untuk lihat format create_time
$rows = $pdo->prepare("SELECT order_id, create_time, 
    FROM_UNIXTIME(create_time) as as_utc,
    FROM_UNIXTIME(create_time + 25200) as plus7,
    FROM_UNIXTIME(create_time - 25200) as minus7
    FROM orders WHERE shop_id=? ORDER BY create_time DESC LIMIT 5");
$rows->execute([$shopId]);
$latest = $rows->fetchAll(PDO::FETCH_ASSOC);

// Count per hour UTC vs WIB for today
$today = date('Y-m-d'); // server UTC date
$byHourUtc = $pdo->prepare("SELECT HOUR(FROM_UNIXTIME(create_time)) as h, COUNT(*) as cnt 
    FROM orders WHERE shop_id=? AND DATE(FROM_UNIXTIME(create_time))=? 
    GROUP BY h ORDER BY h");
$byHourUtc->execute([$shopId, $today]);
$utcHours = $byHourUtc->fetchAll(PDO::FETCH_ASSOC);

$byHourWib = $pdo->prepare("SELECT HOUR(FROM_UNIXTIME(create_time+25200)) as h, COUNT(*) as cnt 
    FROM orders WHERE shop_id=? AND DATE(FROM_UNIXTIME(create_time+25200))='2026-05-13' 
    GROUP BY h ORDER BY h");
$byHourWib->execute([$shopId]);
$wibHours = $byHourWib->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'server_date_utc' => $today,
    'latest_5'  => $latest,
    'hours_by_utc_date_today' => $utcHours,
    'hours_by_wib_2026-05-13' => $wibHours,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
