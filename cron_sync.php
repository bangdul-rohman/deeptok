<?php

declare(strict_types=1);

// ── Timezone wajib WIB agar date() konsisten ──
date_default_timezone_set('Asia/Jakarta');

define('CRON_MODE', true);

require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/db.php';

// ── Setup log ──
$logDir  = __DIR__ . '/logs';
$logFile = $logDir . '/cron_log.txt';

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$log   = [];
$log[] = '[' . date('Y-m-d H:i:s') . '] ══════════════════════════════';
$log[] = '[' . date('Y-m-d H:i:s') . '] ══ Cron sync dimulai ══';
$log[] = '[' . date('Y-m-d H:i:s') . '] Timezone: ' . date_default_timezone_get();

// ── Tanggal yang akan di-sync: kemarin + hari ini ──
$dates = [
    date('Y-m-d', strtotime('-1 day')), // kemarin — pastikan data akhir hari masuk
    date('Y-m-d'),                       // hari ini  — data yang sudah masuk sejak tengah malam
];
$log[] = '[' . date('Y-m-d H:i:s') . '] Tanggal yang akan di-sync: ' . implode(', ', $dates);

// ── Ambil semua toko yang sudah connected ──
$shops = getAllConnectedShops();

if (empty($shops)) {
    $log[] = '[' . date('Y-m-d H:i:s') . '] Tidak ada toko yang connected. Cron selesai.';
    file_put_contents($logFile, implode(PHP_EOL, $log) . PHP_EOL, FILE_APPEND);
    echo implode("\n", $log) . "\n";
    exit;
}

$log[] = '[' . date('Y-m-d H:i:s') . '] Ditemukan ' . count($shops) . ' toko connected.';

// ── Helper: HTTP request via cURL (lebih andal dari file_get_contents) ──
function fetchUrl(string $url): string|false
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp  = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return false;
        }
        return $resp;
    }

    // Fallback ke file_get_contents jika cURL tidak ada
    return @file_get_contents($url);
}

// ── Tracking ringkasan ──
$totalOrderSynced = 0;
$totalErrors      = 0;

// ── Loop setiap toko × setiap tanggal ──
foreach ($shops as $shop) {
    $shopId   = $shop['id'];
    $shopName = $shop['name'];

    $log[] = '[' . date('Y-m-d H:i:s') . "] ── Toko: {$shopName} (shop_id={$shopId}) ──";

    foreach ($dates as $targetDate) {
        $url  = 'http://api.enasverse.com/sync_orders.php'
              . '?date=' . $targetDate
              . '&shop_id=' . $shopId;

        $log[] = '[' . date('Y-m-d H:i:s') . "]    Sync tanggal: {$targetDate}";

        $resp = fetchUrl($url);

        if ($resp !== false) {
            preg_match('/Total tersimpan[:\s]+(\d+)/i', $resp, $match);
            $total           = isset($match[1]) ? (int)$match[1] : 0;
            $totalOrderSynced += $total;

            // Deteksi error dari response body
            if (stripos($resp, 'error') !== false && $total === 0) {
                $log[] = '[' . date('Y-m-d H:i:s') . "]    ⚠️  {$shopName} [{$targetDate}]: Response mengandung error — {$resp}";
                $totalErrors++;
            } else {
                $log[] = '[' . date('Y-m-d H:i:s') . "]    ✅ {$shopName} [{$targetDate}]: {$total} order tersimpan";
            }
        } else {
            $log[]  = '[' . date('Y-m-d H:i:s') . "]    ❌ {$shopName} [{$targetDate}]: Gagal fetch URL";
            $totalErrors++;
        }

        // Jeda 500ms antar request agar server tidak overload
        usleep(500_000);
    }
}

// ── Ringkasan akhir ──
$log[] = '[' . date('Y-m-d H:i:s') . '] ══════════════════════════════';
$log[] = '[' . date('Y-m-d H:i:s') . '] RINGKASAN:';
$log[] = '[' . date('Y-m-d H:i:s') . ']   Total toko   : ' . count($shops);
$log[] = '[' . date('Y-m-d H:i:s') . ']   Total order  : ' . $totalOrderSynced;
$log[] = '[' . date('Y-m-d H:i:s') . ']   Total error  : ' . $totalErrors;
$log[] = '[' . date('Y-m-d H:i:s') . '] ══ Cron selesai ══';
$log[] = '[' . date('Y-m-d H:i:s') . '] ══════════════════════════════';

file_put_contents($logFile, implode(PHP_EOL, $log) . PHP_EOL, FILE_APPEND);
echo implode("\n", $log) . "\n";
