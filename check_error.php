<?php
/**
 * check_error.php — Cek PHP error log terbaru
 * HAPUS setelah selesai debug!
 */
// Aktifkan error reporting sementara
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<pre style='font:12px monospace;padding:20px;background:#111;color:#6affb8;'>";
echo "=== PHP VERSION ===\n";
echo PHP_VERSION . "\n\n";

echo "=== RECENT ERROR LOG (last 50 lines) ===\n";
// Coba beberapa lokasi log umum
$logPaths = [
    ini_get('error_log'),
    '/var/log/php_errors.log',
    '/var/log/apache2/error.log',
    '/var/log/nginx/error.log',
    __DIR__ . '/php_errors.log',
    sys_get_temp_dir() . '/php_errors.log',
];

$found = false;
foreach ($logPaths as $path) {
    if ($path && file_exists($path) && is_readable($path)) {
        echo "Log: $path\n";
        $lines = file($path);
        $last = array_slice($lines, -50);
        // Filter hanya yang berhubungan dengan deeptok/dashboard
        foreach ($last as $line) {
            if (stripos($line, 'dashboard') !== false || 
                stripos($line, 'deeptok') !== false ||
                stripos($line, 'Fatal') !== false ||
                stripos($line, 'Parse error') !== false) {
                echo htmlspecialchars($line);
            }
        }
        $found = true;
        break;
    }
}
if (!$found) echo "Log file tidak ditemukan di lokasi umum.\n";

echo "\n=== SESSION STATUS ===\n";
session_start();
echo "Session ID: " . session_id() . "\n";
echo "Session data keys: " . implode(', ', array_keys($_SESSION)) . "\n";
echo "Logged in: " . (!empty($_SESSION['deeptok_logged_in']) ? 'YES' : 'NO') . "\n";
echo "Shop ID: " . ($_SESSION['shop_id'] ?? 'NOT SET') . "\n";

echo "\n=== FILE EXISTS CHECK ===\n";
$files = ['dashboard.php', 'auth.php', 'db.php', 'config.php', 'get_top_products.php', 'cohort.php'];
foreach ($files as $f) {
    echo $f . ': ' . (file_exists(__DIR__.'/'.$f) ? '✓ exists ('.filesize(__DIR__.'/'.$f).' bytes)' : '✗ MISSING') . "\n";
}

echo "</pre>";
