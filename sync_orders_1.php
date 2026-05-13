<?php

declare(strict_types=1);

set_time_limit(600);
ignore_user_abort(true);
ini_set('memory_limit', '128M');
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/db.php';

// ── Tentukan shop_id ──
// Mode web: dari session (via auth)
// Mode cron: dari parameter ?shop_id=X
$isCron = defined('CRON_MODE') && CRON_MODE;

if (!$isCron) {
    require_once __DIR__ . '/auth.php';
}

$shopId = 0;
if ($isCron && isset($_GET['shop_id'])) {
    $shopId = (int)$_GET['shop_id'];
} elseif (!$isCron) {
    $shopId = getShopId();
}

if ($shopId === 0) {
    echo json_encode(['error' => 'shop_id tidak valid']);
    exit;
}

$pdo         = getDB();
try {
    $accessToken = requireAccessToken($shopId);
    $shopCipher  = requireShopCipher($shopId);
} catch (Throwable $e) {
    echo "ERROR TOKEN: " . $e->getMessage() . "\n";
    exit;
}

// Sync 1 hari saja per request
$targetDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
    $targetDate = date('Y-m-d');
}

// create_time di DB = WIB unix timestamp, query langsung tanpa offset
$batches = [
    ['ge' => strtotime($targetDate . ' 00:00:00'), 'lt' => strtotime($targetDate . ' 23:59:59'), 'label' => 'Full day WIB'],
];

$totalSynced = 0;
echo "Sync orders: {$targetDate} | shop_id: {$shopId}\n";
ob_implicit_flush(true);
if (function_exists('ob_end_flush')) @ob_end_flush();

function orderRequest(
    string $accessToken,
    string $shopCipher,
    array $body,
    string $nextPageToken = ''
): array {
    $path      = '/order/202309/orders/search';
    $timestamp = time();

    $queryParams = [
        'app_key'     => TTS_APP_KEY,
        'timestamp'   => $timestamp,
        'shop_cipher' => $shopCipher,
        'page_size'   => 20,
        'sort_order'  => 'DESC',
    ];

    if ($nextPageToken !== '') {
        $queryParams['page_token'] = $nextPageToken;
    }

    $bodyJson = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ksort($queryParams);

    $input = '';
    foreach ($queryParams as $key => $value) {
        if ($key === 'sign') continue;
        $input .= $key . $value;
    }

    $input = TTS_APP_SECRET . $path . $input . $bodyJson . TTS_APP_SECRET;
    $sign  = bin2hex(hash_hmac('sha256', $input, TTS_APP_SECRET, true));
    $queryParams['sign'] = $sign;

    $url = TTS_API_HOST . $path . '?' . http_build_query($queryParams);

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'x-tts-access-token: ' . $accessToken,
        'Expect:',
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyJson);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) return ['error' => $curlError];
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : ['error' => 'Invalid JSON'];
}

foreach ($batches as $batch) {
    $page          = 1;
    $nextPageToken = '';
    echo "\nBatch {$batch['label']} | UTC: ".date('d/m H:i',$batch['ge'])." s/d ".date('d/m H:i',$batch['lt'])."\n";

do {
    echo "Halaman {$page}...\n";

    $body     = ['create_time_ge' => $batch['ge'], 'create_time_lt' => $batch['lt']];
    $response = orderRequest($accessToken, $shopCipher, $body, $nextPageToken);

    if (!is_array($response) || ($response['code'] ?? -1) !== 0) {
        $msg = $response['message'] ?? ($response['error'] ?? 'Error');
        echo "ERROR: {$msg}\n";
        $pdo->prepare("INSERT INTO sync_logs (shop_id, type, status, message, records) VALUES (?, 'orders', 'failed', ?, 0)")
            ->execute([$shopId, $msg]);
        break;
    }

    $orders        = $response['data']['orders']         ?? [];
    $nextPageToken = $response['data']['next_page_token'] ?? '';
    $totalCount    = $response['data']['total_count']     ?? 0;

    if ($page === 1) echo "Total order di tanggal ini: {$totalCount}\n\n";
    if (empty($orders)) { echo "Tidak ada order.\n"; break; }

    foreach ($orders as $order) {
        $orderId = $order['id'] ?? '';
        if (empty($orderId)) continue;

        $currency    = $order['line_items'][0]['currency'] ?? 'IDR';
        $totalAmount = null;

        if (!empty($order['payment']['total_amount'])) {
            $totalAmount = $order['payment']['total_amount'];
        } elseif (!empty($order['total_amount']) && !is_array($order['total_amount'])) {
            $totalAmount = $order['total_amount'];
        } elseif (!empty($order['line_items'])) {
            $totalAmount = array_sum(array_column($order['line_items'], 'sale_price'));
        }

        // ── Simpan order dengan shop_id ──
        $stmt = $pdo->prepare("
            INSERT INTO orders (
                shop_id, order_id, status, create_time, update_time,
                cancel_reason, currency, total_amount,
                payment_method, fulfillment_type, raw_data, synced_at
            ) VALUES (
                :shop_id, :order_id, :status, :create_time, :update_time,
                :cancel_reason, :currency, :total_amount,
                :payment_method, :fulfillment_type, :raw_data, NOW()
            )
            ON DUPLICATE KEY UPDATE
                status          = VALUES(status),
                update_time     = VALUES(update_time),
                cancel_reason   = VALUES(cancel_reason),
                total_amount    = VALUES(total_amount),
                currency        = VALUES(currency),
                raw_data        = VALUES(raw_data),
                synced_at       = NOW()
        ");

        $stmt->execute([
            ':shop_id'          => $shopId,
            ':order_id'         => $orderId,
            ':status'           => $order['status']           ?? null,
            ':create_time'      => $order['create_time']      ?? null,
            ':update_time'      => $order['update_time']      ?? null,
            ':cancel_reason'    => $order['cancel_reason']    ?? null,
            ':currency'         => $currency,
            ':total_amount'     => $totalAmount,
            ':payment_method'   => $order['payment_method_name'] ?? null,
            ':fulfillment_type' => $order['fulfillment_type'] ?? null,
            ':raw_data'         => json_encode($order, JSON_UNESCAPED_UNICODE),
        ]);

        // ── Simpan order items dengan shop_id ──
        $pdo->prepare("DELETE FROM order_items WHERE order_id = ? AND shop_id = ?")
            ->execute([$orderId, $shopId]);

        foreach ($order['line_items'] ?? [] as $item) {
            $pdo->prepare("
                INSERT INTO order_items (
                    shop_id, order_id, product_id, product_name,
                    sku_id, sku_name, seller_sku,
                    quantity, sale_price, currency
                ) VALUES (
                    :shop_id, :order_id, :product_id, :product_name,
                    :sku_id, :sku_name, :seller_sku,
                    :quantity, :sale_price, :currency
                )
            ")->execute([
                ':shop_id'      => $shopId,
                ':order_id'     => $orderId,
                ':product_id'   => $item['product_id']   ?? null,
                ':product_name' => $item['product_name'] ?? null,
                ':sku_id'       => $item['sku_id']       ?? null,
                ':sku_name'     => $item['sku_name']     ?? null,
                ':seller_sku'   => $item['seller_sku']   ?? null,
                ':quantity'     => 1,
                ':sale_price'   => $item['sale_price']   ?? null,
                ':currency'     => $item['currency']     ?? $currency,
            ]);
        }

        $totalSynced++;
    }

    echo "Halaman {$page}: " . count($orders) . " order. Total: {$totalSynced}\n";
    $page++;

} while ($nextPageToken !== '');
} // end foreach batches

$pdo->prepare("INSERT INTO sync_logs (shop_id, type, status, message, records) VALUES (?, 'orders', 'success', ?, ?)")
    ->execute([$shopId, "Sync {$targetDate} selesai", $totalSynced]);

echo "\n============================\n";
echo "Selesai! Total tersimpan: {$totalSynced}\n";
echo "============================\n";
