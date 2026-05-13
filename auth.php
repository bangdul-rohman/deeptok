<?php
declare(strict_types=1);

require_once __DIR__ . '/session_config.php';

// ── Auto logout setelah 8 jam tidak aktif (dari versi lama) ──
$timeout = 8 * 3600;
if (isset($_SESSION['deeptok_login_time']) && (time() - $_SESSION['deeptok_login_time']) > $timeout) {
    session_destroy();
    header('Location: /login.php?timeout=1');
    exit;
}

// ── Cek apakah sudah login ──
if (empty($_SESSION['deeptok_logged_in']) || empty($_SESSION['user_id']) || empty($_SESSION['shop_id'])) {
    $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
    $redirect   = $currentUrl ? '?redirect=' . urlencode($currentUrl) : '';
    header('Location: /login.php' . $redirect);
    exit;
}

// ── Update waktu aktif ──
$_SESSION['deeptok_login_time'] = time();

// ── Helper functions ──

function getShopId(): int {
    return (int)($_SESSION['shop_id'] ?? 0);
}

function getUserId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function getShopName(): string {
    return $_SESSION['shop_name'] ?? 'Toko';
}

function getUsername(): string {
    return $_SESSION['username'] ?? 'admin';
}

function getDisplayName(): string {
    return $_SESSION['display_name'] ?? getUsername();
}

function isShopConnected(): bool {
    return !empty($_SESSION['shop_connected']);
}

function refreshShopSession(PDO $pdo): void {
    $shopId = getShopId();
    $stmt   = $pdo->prepare("SELECT * FROM shops WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $shopId]);
    $shop = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($shop) {
        $_SESSION['shop_name']      = $shop['name'];
        $_SESSION['shop_connected'] = !empty($shop['access_token']);
        $_SESSION['tiktok_shop_id'] = $shop['tiktok_shop_id'];
    }
}