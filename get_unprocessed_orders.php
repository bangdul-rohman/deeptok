<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

$pdo    = getDB();
$shopId = getShopId();
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 5;
$offset  = ($page - 1) * $perPage;
$statusFilter = $_GET['status'] ?? 'ALL';

$allStatuses = ['AWAITING_SHIPMENT','UNPAID','AWAITING_COLLECTION','ON_HOLD'];

if ($statusFilter === 'ALL') {
    $statuses = $allStatuses;
} else {
    $statuses = in_array($statusFilter, $allStatuses) ? [$statusFilter] : $allStatuses;
}

// SLA calculation (WIB)
function calcSlaDeadlineTs(int $createTime): int {
    $tz = new DateTimeZone('Asia/Jakarta');
    $dt = new DateTime('@'.$createTime);
    $dt->setTimezone($tz);
    $hour = (int)$dt->format('H');
    $min  = (int)$dt->format('i');
    $isAfterNoon = ($hour > 12 || ($hour === 12 && $min > 0));
    $daysAdd = $isAfterNoon ? 2 : 1;
    $deadline = clone $dt;
    $added = 0;
    while ($added < $daysAdd) {
        $deadline->modify('+1 day');
        $dow = (int)$deadline->format('N'); // 1=Mon..7=Sun
        if ($dow < 6) $added++;
    }
    $deadline->setTime(23, 59, 59);
    return $deadline->getTimestamp();
}

try {
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));

    // Count
    $stmtC = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE shop_id=? AND status IN ($placeholders)");
    $stmtC->execute(array_merge([$shopId], $statuses));
    $total = (int)$stmtC->fetchColumn();

    // Orders
    $stmtO = $pdo->prepare("
        SELECT o.order_id, o.status, o.create_time, o.total_amount,
            GROUP_CONCAT(oi.product_name SEPARATOR '|||') as products,
            SUM(oi.quantity) as item_count
        FROM orders o
        LEFT JOIN order_items oi ON oi.order_id=o.order_id AND oi.shop_id=?
        WHERE o.shop_id=? AND o.status IN ($placeholders)
        GROUP BY o.order_id, o.status, o.create_time, o.total_amount
        ORDER BY o.create_time ASC
        LIMIT ? OFFSET ?
    ");
    $stmtO->execute(array_merge([$shopId, $shopId], $statuses, [$perPage, $offset]));
    $orders = $stmtO->fetchAll(PDO::FETCH_ASSOC);

    foreach ($orders as &$o) {
        $o['create_time']   = (int)$o['create_time'];
        $o['total_amount']  = (float)$o['total_amount'];
        $o['item_count']    = (int)$o['item_count'];
        $o['sla_deadline']  = calcSlaDeadlineTs($o['create_time']);
    }

    echo json_encode([
        'success'     => true,
        'orders'      => $orders,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => $total > 0 ? (int)ceil($total / $perPage) : 1,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
