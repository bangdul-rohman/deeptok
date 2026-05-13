<?php
// test_margin.php - HAPUS setelah selesai debug
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: text/plain; charset=utf-8');
echo "=== TEST FILE BERJALAN ===\n\n";
echo "URL diminta  : " . $_SERVER['REQUEST_URI'] . "\n";
echo "Session keys : " . (empty($_SESSION) ? '(kosong)' : implode(', ', array_keys($_SESSION))) . "\n";
echo "shop_id      : " . ($_SESSION['shop_id'] ?? 'tidak ada') . "\n";
echo "deeptok_login: " . ($_SESSION['deeptok_logged_in'] ?? 'tidak ada') . "\n";
echo "\nJika file ini tampil berarti PHP berjalan normal.\n";
echo "Jika margin.php redirect ke dashboard, masalah ada di dalam margin.php itu sendiri.\n";
