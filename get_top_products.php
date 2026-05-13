<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

$pdo    = getDB();
$shopId = getShopId();

$from = $_GET['from'] ?? date('Y-m-d');
$to   = $_GET['to']   ?? date('Y-m-d');
$limit = min((int)($_GET['limit'] ?? 20), 50);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');
if ($from > $to) [$from, $to] = [$to, $from];

$tsStart = strtotime($from . ' 00:00:00');
$tsEnd   = strtotime($to   . ' 23:59:59');

try {
    $stmt = $pdo->prepare("
        SELECT
            oi.product_name,
            SUM(oi.quantity) as total_qty,
            SUM(oi.sale_price * oi.quantity) as total_revenue,
            COUNT(DISTINCT oi.order_id) as order_count
        FROM order_items oi
        JOIN orders o ON o.order_id = oi.order_id AND o.shop_id = :sid
        WHERE oi.shop_id = :sid2
          AND o.create_time BETWEEN :ts AND :te
          AND o.status NOT LIKE '%CANCEL%'
          AND oi.product_name IS NOT NULL
          AND oi.product_name != ''
        GROUP BY oi.product_name
        ORDER BY total_qty DESC
        LIMIT :lim
    ");
    $stmt->bindValue(':sid',  $shopId, PDO::PARAM_INT);
    $stmt->bindValue(':sid2', $shopId, PDO::PARAM_INT);
    $stmt->bindValue(':ts',   $tsStart, PDO::PARAM_INT);
    $stmt->bindValue(':te',   $tsEnd,   PDO::PARAM_INT);
    $stmt->bindValue(':lim',  $limit,   PDO::PARAM_INT);
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($products as &$p) {
        $p['total_qty']     = (int)$p['total_qty'];
        $p['order_count']   = (int)$p['order_count'];
        $p['total_revenue'] = (float)$p['total_revenue'];
    }

    echo json_encode([
        'success'  => true,
        'products' => $products,
        'from'     => $from,
        'to'       => $to,
        'count'    => count($products),
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
