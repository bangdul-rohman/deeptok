<?php
declare(strict_types=1);

/**
 * session_config.php — dipanggil PERTAMA sebelum require apapun
 */

if (session_status() !== PHP_SESSION_NONE) {
    return;
}

// Matikan output buffering dulu, lalu nyalakan lagi
// agar whitespace/BOM dari file lain tidak memicu "headers already sent"
if (ob_get_level() === 0) {
    ob_start();
}

// Custom session dir
$sessionDir = __DIR__ . '/sessions';
if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0700, true);
}
if (is_dir($sessionDir) && is_writable($sessionDir)) {
    session_save_path($sessionDir);
}

session_set_cookie_params([
    'lifetime' => 86400,
    'path'     => '/',
    'domain'   => '',
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_name('deeptok_sess');
ini_set('session.gc_maxlifetime', '86400');

session_start();
