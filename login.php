<?php
declare(strict_types=1);

require_once __DIR__ . '/session_config.php';

require_once __DIR__ . '/db.php';

$error   = '';
$timeout = isset($_GET['timeout']);
$redirect = $_GET['redirect'] ?? '';

// Kalau sudah login, langsung redirect
if (!empty($_SESSION['deeptok_logged_in']) && !empty($_SESSION['shop_id'])) {
    header('Location: /dashboard.php');
    exit;
}

// ── Proses POST login ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } else {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("
                SELECT u.*, s.name AS shop_name, s.tiktok_shop_id, s.access_token,
                       s.tiktok_cipher, s.tiktok_region
                FROM users u
                JOIN shops s ON s.id = u.shop_id
                WHERE u.username = :username
                LIMIT 1
            ");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($password, $user['password'])) {
                $error = 'Username atau password salah.';
            } else {
                // Set semua session
                $_SESSION['deeptok_logged_in']  = true;
                $_SESSION['user_id']            = (int)$user['id'];
                $_SESSION['shop_id']            = (int)$user['shop_id'];
                $_SESSION['username']           = $user['username'];
                $_SESSION['display_name']       = $user['display_name'] ?: $user['username'];
                $_SESSION['shop_name']          = $user['shop_name'];
                $_SESSION['shop_connected']     = !empty($user['access_token']);
                $_SESSION['tiktok_shop_id']     = $user['tiktok_shop_id'];
                $_SESSION['deeptok_login_time'] = time();

                // Update last_login
                $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = :id")
                    ->execute([':id' => $user['id']]);

                // Redirect
                $dest = ($redirect && strpos($redirect, '/') === 0) ? $redirect : '/dashboard.php';

                // Kalau belum connect TikTok, arahkan ke halaman connect
                if (empty($user['access_token'])) {
                    $dest = '/connect.php';
                }

                header('Location: ' . $dest);
                exit;
            }
        } catch (Exception $e) {
            $error = 'Terjadi kesalahan sistem. Silakan coba lagi.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — Deeptok</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#0a0a0f;--surface:#13131a;--surface2:#1c1c28;--border:#2a2a3a;
  --accent:#7c6aff;--accent2:#ff6a9b;--accent3:#6affb8;
  --text:#e8e8f0;--text-muted:#6b6b8a;--danger:#ff6a6a;--warning:#ffb86a;
}
*{margin:0;padding:0;box-sizing:border-box;}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}

/* Background grid */
body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(124,106,255,0.03) 1px,transparent 1px),linear-gradient(90deg,rgba(124,106,255,0.03) 1px,transparent 1px);background-size:40px 40px;pointer-events:none;}

.login-wrap{width:100%;max-width:420px;animation:fadeUp 0.5s ease both;}
@keyframes fadeUp{from{opacity:0;transform:translateY(16px);}to{opacity:1;transform:translateY(0);}}

.brand{text-align:center;margin-bottom:32px;}
.logo{font-family:'Syne',sans-serif;font-weight:800;font-size:32px;background:linear-gradient(135deg,var(--accent),var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:6px;}
.brand-sub{font-size:11px;color:var(--text-muted);letter-spacing:2px;text-transform:uppercase;}

.card{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:32px;}

.alert{border-radius:8px;padding:12px 16px;font-size:12px;line-height:1.6;margin-bottom:20px;display:flex;align-items:flex-start;gap:10px;}
.alert-error{background:rgba(255,106,106,0.08);border:1px solid rgba(255,106,106,0.25);color:var(--danger);}
.alert-warning{background:rgba(255,184,106,0.08);border:1px solid rgba(255,184,106,0.25);color:var(--warning);}

.form-group{margin-bottom:18px;}
.form-label{display:block;font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:8px;}
.form-input{width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--text);font-family:'DM Mono',monospace;font-size:13px;padding:11px 14px;transition:border-color 0.2s,box-shadow 0.2s;outline:none;}
.form-input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(124,106,255,0.1);}
.form-input::placeholder{color:var(--text-muted);}

.input-wrap{position:relative;}
.input-wrap .form-input{padding-right:44px;}
.toggle-pw{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:16px;padding:4px;line-height:1;transition:color 0.2s;}
.toggle-pw:hover{color:var(--text);}

.btn-login{width:100%;padding:13px;border-radius:8px;background:linear-gradient(135deg,var(--accent),var(--accent2));border:none;color:white;font-family:'Syne',sans-serif;font-size:14px;font-weight:700;cursor:pointer;transition:opacity 0.2s,transform 0.1s;margin-top:4px;letter-spacing:0.5px;}
.btn-login:hover{opacity:0.9;}
.btn-login:active{transform:scale(0.99);}
.btn-login:disabled{opacity:0.5;cursor:not-allowed;}

.footer-note{text-align:center;margin-top:20px;font-size:11px;color:var(--text-muted);}
.footer-note a{color:var(--accent);text-decoration:none;}

/* Shimmer di card top */
.card::before{content:'';display:block;height:2px;border-radius:16px 16px 0 0;background:linear-gradient(90deg,var(--accent),var(--accent2),var(--accent3));margin:-32px -32px 32px -32px;border-radius:16px 16px 0 0;}
</style>
</head>
<body>

<div class="login-wrap">
  <div class="brand">
    <div class="logo">⚡ Deeptok</div>
    <div class="brand-sub">TikTok Shop Analytics</div>
  </div>

  <div class="card">

    <?php if ($timeout): ?>
    <div class="alert alert-warning">
      ⏰ Sesi kamu sudah berakhir karena tidak aktif. Silakan login kembali.
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-error">
      ❌ <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="" id="loginForm">
      <?php if ($redirect): ?>
      <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
      <?php endif; ?>

      <div class="form-group">
        <label class="form-label" for="username">Username</label>
        <input
          type="text"
          id="username"
          name="username"
          class="form-input"
          placeholder="Masukkan username"
          value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
          autocomplete="username"
          autofocus
          required
        >
      </div>

      <div class="form-group">
        <label class="form-label" for="password">Password</label>
        <div class="input-wrap">
          <input
            type="password"
            id="password"
            name="password"
            class="form-input"
            placeholder="Masukkan password"
            autocomplete="current-password"
            required
          >
          <button type="button" class="toggle-pw" onclick="togglePassword()" title="Tampilkan/sembunyikan password">
            <span id="pw-icon">👁</span>
          </button>
        </div>
      </div>

      <button type="submit" class="btn-login" id="btnLogin">Masuk →</button>
    </form>

    <div class="footer-note">
      Butuh bantuan? Hubungi administrator toko kamu.
    </div>
  </div>
</div>

<script>
function togglePassword() {
  const input = document.getElementById('password');
  const icon  = document.getElementById('pw-icon');
  if (input.type === 'password') {
    input.type = 'text';
    icon.textContent = '🙈';
  } else {
    input.type = 'password';
    icon.textContent = '👁';
  }
}

document.getElementById('loginForm').addEventListener('submit', function() {
  const btn = document.getElementById('btnLogin');
  btn.disabled    = true;
  btn.textContent = 'Memproses...';
});
</script>
</body>
</html>