<?php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');
date_default_timezone_set('Asia/Jakarta');
try {
    $pdo    = getDB();
    $shopId = getShopId();
} catch (Throwable $e) {
    echo json_encode(['error' => 'DB: ' . $e->getMessage()]); exit;
}
$nowHour     = (int)date('G');
$today       = date('Y-m-d');
$date        = isset($_GET['date']) ? preg_replace('/[^0-9\-]/', '', $_GET['date']) : $today;
$isToday     = ($date === $today);
$currentHour = $isToday ? $nowHour : 23;
$prevDate    = date('Y-m-d', strtotime($date . ' -1 day'));
function getHourlyData(PDO $pdo, string $date, int $shopId, int $maxHour): array {
    $tsStart = strtotime($date . ' 00:00:00');
    $tsEnd   = strtotime($date . ' ' . str_pad((string)$maxHour, 2, '0', STR_PAD_LEFT) . ':59:59');
    $stmt = $pdo->prepare("SELECT HOUR(FROM_UNIXTIME(create_time)) AS hour, COALESCE(SUM(total_amount), 0) AS gmv, COUNT(*) AS orders FROM orders WHERE shop_id = :sid AND create_time BETWEEN :s AND :e AND total_amount IS NOT NULL AND status NOT IN ('CANCELLED','UNPAID') GROUP BY hour ORDER BY hour ASC");
    $stmt->execute([':sid' => $shopId, ':s' => $tsStart, ':e' => $tsEnd]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result = [];
    for ($i = 0; $i <= $maxHour; $i++) {
        $result[$i] = ['hour' => $i, 'gmv' => 0.0, 'orders' => 0];
    }
    foreach ($rows as $r) {
        $h = (int)$r['hour'];
        if ($h >= 0 && $h <= $maxHour) {
            $result[$h] = ['hour' => $h, 'gmv' => (float)$r['gmv'], 'orders' => (int)$r['orders']];
        }
    }
    return array_values($result);
}
try {
    $today_data = getHourlyData($pdo, $date, $shopId, $currentHour);
    $prevFull   = getHourlyData($pdo, $prevDate, $shopId, 23);
    $prevApple  = getHourlyData($pdo, $prevDate, $shopId, $currentHour);
    $todayGmv  = (float)array_sum(array_column($today_data, 'gmv'));
    $prevGmvAp = (float)array_sum(array_column($prevApple, 'gmv'));
    $diffGmv   = $todayGmv - $prevGmvAp;
    $diffPct   = $prevGmvAp > 0 ? round($diffGmv / $prevGmvAp * 100, 1) : 0;
    echo json_encode(['date' => $date, 'prev_date' => $prevDate, 'current_hour' => $currentHour, 'server_time' => date('Y-m-d H:i:s'), 'today' => $today_data, 'prev' => $prevFull, 'prev_apple' => $prevApple, 'summary' => ['today_gmv' => $todayGmv, 'prev_gmv' => $prevGmvAp, 'diff_gmv' => $diffGmv, 'diff_pct' => $diffPct, 'compare_note' => '00:00-' . str_pad((string)$currentHour, 2, '0', STR_PAD_LEFT) . ':59 WIB vs kemarin jam yg sama']], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}