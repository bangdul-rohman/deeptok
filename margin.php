<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

// ── Cek session Deeptok TANPA auth.php (hindari redirect loop) ──
$deeptokLoggedIn = !empty($_SESSION['deeptok_logged_in'])
    && !empty($_SESSION['user_id'])
    && !empty($_SESSION['shop_id']);

if (!$deeptokLoggedIn) {
    header('Location: /login.php?redirect=' . urlencode('/margin.php'));
    exit;
}

$shopId = (int)$_SESSION['shop_id'];

// ── Cek timeout sesi margin (2 jam) ──
$marginTimeout = 2 * 3600;
if (isset($_SESSION['margin_login_time']) && (time() - $_SESSION['margin_login_time']) > $marginTimeout) {
    unset($_SESSION['margin_logged_in'], $_SESSION['margin_login_time'], $_SESSION['margin_shop_id']);
}

// Validasi: sesi margin harus untuk shop yang sama
if (!empty($_SESSION['margin_logged_in']) && isset($_SESSION['margin_shop_id'])) {
    if ((int)$_SESSION['margin_shop_id'] !== $shopId) {
        unset($_SESSION['margin_logged_in'], $_SESSION['margin_login_time'], $_SESSION['margin_shop_id']);
    }
}

$marginLoggedIn = !empty($_SESSION['margin_logged_in']);
$errorCode      = (int)($_GET['error'] ?? 0);

// Cek apakah password margin sudah diset di DB untuk toko ini
$marginPasswordSet = false;
try {
    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
        `key` VARCHAR(150) PRIMARY KEY,
        `value` TEXT NOT NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $r = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=? LIMIT 1");
    $r->execute(["margin_password__{$shopId}"]);
    $row = $r->fetch();
    $marginPasswordSet = !empty($row['value']);
} catch (Exception $e) {
    $marginPasswordSet = false;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Margin Dashboard — Deeptok</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#0a0a0f; --surface:#13131a; --surface2:#1c1c28; --border:#2a2a3a;
  --accent:#7c6aff; --accent2:#ff6a9b; --accent3:#6affb8;
  --text:#e8e8f0; --text-muted:#6b6b8a;
  --success:#6affb8; --warning:#ffb86a; --danger:#ff6a6a;
}
*{margin:0;padding:0;box-sizing:border-box;}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh;}
.header{padding:16px 32px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--surface);position:sticky;top:0;z-index:100;}
.logo{font-family:'Syne',sans-serif;font-weight:800;font-size:22px;background:linear-gradient(135deg,var(--accent),var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
.header-right{display:flex;align-items:center;gap:12px;}
.btn{padding:7px 16px;border-radius:8px;font-family:'DM Mono',monospace;font-size:12px;cursor:pointer;text-decoration:none;transition:opacity 0.2s;border:none;display:inline-flex;align-items:center;gap:6px;}
.btn:hover{opacity:0.8;}
.btn-secondary{background:var(--surface2);color:var(--text);border:1px solid var(--border);}
.btn-danger{background:var(--surface2);color:var(--danger);border:1px solid var(--danger);}
.btn-primary{background:var(--accent);color:white;}
.gate-wrap{display:flex;align-items:center;justify-content:center;min-height:calc(100vh - 65px);padding:24px;}
.gate-card{background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:44px 40px;width:100%;max-width:400px;position:relative;overflow:hidden;}
.gate-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--accent),var(--accent2),var(--accent3));border-radius:18px 18px 0 0;}
.gate-icon{font-size:42px;margin-bottom:16px;display:block;text-align:center;}
.gate-title{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;text-align:center;margin-bottom:4px;}
.gate-sub{font-size:11px;color:var(--text-muted);text-align:center;margin-bottom:28px;letter-spacing:0.5px;}
.field-wrap{margin-bottom:16px;}
.field-label{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:7px;display:block;}
.field-input{width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:9px;color:var(--text);font-family:'DM Mono',monospace;font-size:13px;padding:12px 14px;transition:border-color 0.2s;}
.field-input:focus{outline:none;border-color:var(--accent);}
.field-input::placeholder{color:var(--text-muted);}
.btn-login{width:100%;padding:13px;border-radius:9px;background:linear-gradient(135deg,var(--accent),var(--accent2));border:none;color:white;font-family:'Syne',sans-serif;font-size:14px;font-weight:700;cursor:pointer;margin-top:8px;transition:opacity 0.2s;}
.btn-login:hover{opacity:0.88;}
.error-msg{background:rgba(255,106,106,0.1);border:1px solid rgba(255,106,106,0.3);border-radius:8px;padding:11px 14px;font-size:12px;color:var(--danger);margin-bottom:20px;display:flex;align-items:center;gap:8px;}
.warn-msg{background:rgba(255,184,106,0.1);border:1px solid rgba(255,184,106,0.3);border-radius:8px;padding:11px 14px;font-size:12px;color:var(--warning);margin-bottom:20px;}
.back-link{display:block;text-align:center;margin-top:20px;font-size:11px;color:var(--text-muted);text-decoration:none;}
.back-link:hover{color:var(--text);}
.container{max-width:1400px;margin:0 auto;padding:28px 32px;}
.page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:12px;}
.page-title{font-family:'Syne',sans-serif;font-size:20px;font-weight:800;}
.page-sub{font-size:11px;color:var(--text-muted);margin-top:3px;}
.placeholder-card{background:var(--surface);border:1px dashed var(--border);border-radius:14px;padding:60px 40px;text-align:center;}
.placeholder-icon{font-size:52px;margin-bottom:16px;}
.placeholder-title{font-family:'Syne',sans-serif;font-size:18px;font-weight:700;margin-bottom:8px;}
.placeholder-sub{font-size:12px;color:var(--text-muted);line-height:1.7;max-width:400px;margin:0 auto;}
.info-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(124,106,255,0.12);border:1px solid rgba(124,106,255,0.25);border-radius:20px;padding:4px 12px;font-size:10px;color:var(--accent);letter-spacing:0.5px;margin-top:16px;}
</style>
</head>
<body>

<div class="header">
  <div class="logo">⚡ Deeptok</div>
  <div class="header-right">
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
    <?php if ($marginLoggedIn): ?>
    <a href="margin_auth.php?action=logout" class="btn btn-danger">🔒 Keluar Margin</a>
    <?php endif; ?>
  </div>
</div>

<?php if (!$marginLoggedIn): ?>
<div class="gate-wrap">
  <div class="gate-card">
    <span class="gate-icon">📊</span>
    <div class="gate-title">Margin Dashboard</div>
    <div class="gate-sub">Akses terbatas — masukkan kredensial margin</div>

    <?php if ($errorCode === 1): ?>
    <div class="error-msg">⚠️ Username atau password salah. Coba lagi.</div>
    <?php elseif ($errorCode === 2): ?>
    <div class="error-msg">⚠️ Sesi tidak valid. Silakan <a href="/login.php" style="color:var(--danger);">login ulang</a>.</div>
    <?php endif; ?>

    <?php if (!$marginPasswordSet): ?>
    <div class="warn-msg">🔧 Kredensial margin belum diset. Minta admin atur di <strong>Admin Panel → Atur Kredensial</strong> pada toko ini.</div>
    <?php endif; ?>

    <form method="POST" action="margin_auth.php">
      <input type="hidden" name="action" value="login">
      <input type="hidden" name="redirect" value="/margin.php">
      <input type="hidden" name="shop_id" value="<?= $shopId ?>">

      <div class="field-wrap">
        <label class="field-label" for="username">Username</label>
        <input class="field-input" type="text" id="username" name="username"
          placeholder="Masukkan username" autocomplete="username" required>
      </div>

      <div class="field-wrap">
        <label class="field-label" for="password">Password</label>
        <input class="field-input" type="password" id="password" name="password"
          placeholder="Masukkan password" autocomplete="current-password" required>
      </div>

      <button type="submit" class="btn-login">🔓 Masuk ke Margin Dashboard</button>
    </form>

    <a href="dashboard.php" class="back-link">← Kembali ke Dashboard Utama</a>
  </div>
</div>

<?php else: ?>
<div class="container">
  <div class="page-header">
    <div>
      <div class="page-title">📊 Margin Dashboard</div>
      <div class="page-sub">Analisis margin produk & profitabilitas toko</div>
    </div>
    <a href="margin_auth.php?action=logout" class="btn btn-danger">🔒 Keluar Margin</a>
  </div>

  <div class="placeholder-card">
    <div class="placeholder-icon">🚧</div>
    <div class="placeholder-title">Konten Margin Segera Hadir</div>
    <div class="placeholder-sub">
      Halaman ini sudah terlindungi. Anda berhasil login ke Margin Dashboard.<br>
      Tambahkan konten margin, HPP, dan analisis profitabilitas di sini.
    </div>
    <div class="info-badge">✅ Login berhasil — sesi aktif 2 jam</div>
  </div>
</div>
<?php endif; ?>

</body>
</html>
