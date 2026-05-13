<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helper.php';

header('Content-Type: application/json');

$accessToken = requireAccessToken(getShopId());
$shopCipher  = requireShopCipher(getShopId());
$date        = isset($_GET['date']) ? preg_replace('/[^0-9\-]/', '', $_GET['date']) : date('Y-m-d');

// Path: date ada di URL, bukan query param
$path = '/analytics/202510/shop/performance/' . $date . '/performance_per_hour';

$params = [
    'app_key'     => TTS_APP_KEY,
    'timestamp'   => time(),
    'shop_cipher' => $shopCipher,
    'currency'    => 'LOCAL',
];
$params['sign'] = buildSign($path, $params, TTS_APP_SECRET);

$url = TTS_API_HOST . $path . '?' . http_build_query($params);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-tts-access-token: ' . $accessToken,
    ],
    CURLOPT_TIMEOUT => 10,
]);
$resp     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$decoded = json_decode($resp, true);

if (($decoded['code'] ?? -1) === 0 && isset($decoded['data']['performance'])) {
    $perf    = $decoded['data']['performance'];
    $overall = $perf['overall'] ?? [];
    $intervals = $perf['intervals'] ?? [];

    // Normalize intervals ke array per jam (index 0-23)
    $hourly = [];
    for ($i = 0; $i < 24; $i++) {
        $hourly[$i] = ['hour' => $i, 'visitors' => 0, 'customers' => 0, 'items_sold' => 0, 'gmv' => 0.0];
    }
    foreach ($intervals as $slot) {
        $h = (int)($slot['index'] ?? -1);
        if ($h >= 0 && $h < 24) {
            $hourly[$h] = [
                'hour'       => $h,
                'visitors'   => (int)($slot['visitors']   ?? 0),
                'customers'  => (int)($slot['customers']  ?? 0),
                'items_sold' => (int)($slot['items_sold'] ?? 0),
                'gmv'        => (float)($slot['gmv']['amount'] ?? 0),
            ];
        }
    }

    echo json_encode([
        'date'    => $date,
        'overall' => [
            'visitors'   => (int)($overall['visitors']   ?? 0),
            'customers'  => (int)($overall['customers']  ?? 0),
            'items_sold' => (int)($overall['items_sold'] ?? 0),
            'gmv'        => (float)($overall['gmv']['amount'] ?? 0),
        ],
        'hourly'  => array_values($hourly),
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'error'     => true,
        'code'      => $decoded['code']    ?? null,
        'message'   => $decoded['message'] ?? null,
        'http_code' => $httpCode,
        'path'      => $path,
    ]);
}
