<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Ambil kredensial margin untuk shop tertentu ──
function getMarginCredentials(PDO $pdo, int $shopId): array {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
            `key` VARCHAR(150) PRIMARY KEY,
            `value` TEXT NOT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        $stmtU = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1");
        $stmtU->execute(["margin_username__{$shopId}"]);
        $rowU  = $stmtU->fetch(PDO::FETCH_ASSOC);

        $stmtP = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1");
        $stmtP->execute(["margin_password__{$shopId}"]);
        $rowP  = $stmtP->fetch(PDO::FETCH_ASSOC);

        return [
            'username' => $rowU ? trim($rowU['value']) : '',
            'password' => $rowP ? $rowP['value'] : '',
        ];
    } catch (Exception $e) {
        error_log('[MarginAuth] DB error: ' . $e->getMessage());
        return ['username' => '', 'password' => ''];
    }
}

// ── Logout ──
if ($action === 'logout') {
    unset($_SESSION['margin_logged_in'], $_SESSION['margin_login_time']);
    header('Location: /dashboard.php');
    exit;
}

// ── Login ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $redirect = $_POST['redirect'] ?? '/margin.php';

    // Ambil shop_id: prioritas dari POST (dikirim form), fallback ke session
    $shopId = (int)($_POST['shop_id'] ?? $_SESSION['shop_id'] ?? 0);

    if ($shopId === 0) {
        error_log('[MarginAuth] shop_id tidak ditemukan. POST=' . json_encode($_POST) . ' SESSION keys=' . implode(',', array_keys($_SESSION)));
        header('Location: /margin.php?error=2');
        exit;
    }

    $pdo   = getDB();
    $creds = getMarginCredentials($pdo, $shopId);

    $usernameOk = ($creds['username'] !== '' && $username === $creds['username']);
    $passwordOk = ($creds['password'] !== '') && password_verify($password, $creds['password']);

    if ($usernameOk && $passwordOk) {
        $_SESSION['margin_logged_in']  = true;
        $_SESSION['margin_login_time'] = time();
        // Simpan shop_id ke session margin agar konsisten
        $_SESSION['margin_shop_id']    = $shopId;
        header('Location: ' . $redirect);
        exit;
    } else {
        error_log('[MarginAuth] Login gagal. shopId=' . $shopId
            . ' inputUser=' . $username
            . ' credUser=' . $creds['username']
            . ' usernameOk=' . ($usernameOk ? 'y' : 'n')
            . ' credHasPw=' . ($creds['password'] !== '' ? 'y' : 'n')
            . ' passwordOk=' . ($passwordOk ? 'y' : 'n'));
        header('Location: /margin.php?error=1');
        exit;
    }
}

header('Location: /margin.php');
exit;
