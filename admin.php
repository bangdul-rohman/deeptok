<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$pdo     = getDB();
$success = '';
$error   = '';

// ── Proses POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Tambah toko + user baru ──
    if ($action === 'add_shop') {
        $shopName    = trim($_POST['shop_name']    ?? '');
        $username    = trim($_POST['username']      ?? '');
        $password    = $_POST['password']           ?? '';
        $displayName = trim($_POST['display_name']  ?? '');

        if (!$shopName || !$username || !$password) {
            $error = 'Nama toko, username, dan password wajib diisi.';
        } elseif (strlen($password) < 6) {
            $error = 'Password minimal 6 karakter.';
        } else {
            try {
                // Cek username sudah ada
                $check = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
                $check->execute([':u' => $username]);
                if ($check->fetch()) {
                    $error = "Username \"$username\" sudah digunakan.";
                } else {
                    $pdo->beginTransaction();

                    // Buat toko
                    $stmt = $pdo->prepare("INSERT INTO shops (name) VALUES (:name)");
                    $stmt->execute([':name' => $shopName]);
                    $newShopId = (int)$pdo->lastInsertId();

                    // Buat user
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $stmt   = $pdo->prepare("
                        INSERT INTO users (shop_id, username, password, display_name)
                        VALUES (:shop_id, :username, :password, :display_name)
                    ");
                    $stmt->execute([
                        ':shop_id'      => $newShopId,
                        ':username'     => $username,
                        ':password'     => $hashed,
                        ':display_name' => $displayName ?: $shopName,
                    ]);

                    $pdo->commit();
                    $success = "Toko \"$shopName\" dan user \"$username\" berhasil dibuat!";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Gagal membuat toko: ' . $e->getMessage();
            }
        }
    }

    // ── Reset password ──
    elseif ($action === 'reset_password') {
        $userId      = (int)($_POST['user_id']       ?? 0);
        $newPassword = $_POST['new_password']         ?? '';

        if (!$userId || !$newPassword) {
            $error = 'User ID dan password baru wajib diisi.';
        } elseif (strlen($newPassword) < 6) {
            $error = 'Password minimal 6 karakter.';
        } else {
            $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt   = $pdo->prepare("UPDATE users SET password = :pw WHERE id = :id");
            $stmt->execute([':pw' => $hashed, ':id' => $userId]);
            $success = 'Password berhasil direset.';
        }
    }

    // ── Hapus toko ──
    elseif ($action === 'delete_shop') {
        $shopId = (int)($_POST['shop_id'] ?? 0);
        if (!$shopId) {
            $error = 'Shop ID tidak valid.';
        } elseif ($shopId === getShopId()) {
            $error = 'Tidak bisa menghapus toko yang sedang aktif.';
        } else {
            try {
                $pdo->beginTransaction();
                // Hapus data toko (CASCADE akan hapus users)
                foreach (['orders', 'order_items', 'products', 'daily_metrics', 'sync_logs'] as $tbl) {
                    $pdo->prepare("DELETE FROM `$tbl` WHERE shop_id = ?")->execute([$shopId]);
                }
                $pdo->prepare("DELETE FROM shops WHERE id = ?")->execute([$shopId]);
                $pdo->commit();
                $success = 'Toko berhasil dihapus beserta seluruh datanya.';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Gagal menghapus toko: ' . $e->getMessage();
            }
        }
    }

    // ── Update nama toko ──
    elseif ($action === 'rename_shop') {
        $shopId   = (int)($_POST['shop_id']   ?? 0);
        $newName  = trim($_POST['new_name']    ?? '');
        if (!$shopId || !$newName) {
            $error = 'Nama toko tidak boleh kosong.';
        } else {
            $pdo->prepare("UPDATE shops SET name = :name WHERE id = :id")
                ->execute([':name' => $newName, ':id' => $shopId]);
            $success = 'Nama toko berhasil diupdate.';
        }
    }

    // ── Update kredensial Margin per toko ──
    elseif ($action === 'update_margin_credentials') {
        $targetShopId = (int)($_POST['shop_id'] ?? 0);
        $marginUser   = trim($_POST['margin_username'] ?? '');
        $marginPass   = $_POST['margin_password'] ?? '';
        $marginConf   = $_POST['margin_password_confirm'] ?? '';

        if (!$targetShopId) {
            $error = 'Shop ID tidak valid.';
        } elseif (!$marginUser) {
            $error = 'Username margin tidak boleh kosong.';
        } elseif ($marginPass !== '' && strlen($marginPass) < 6) {
            $error = 'Password margin minimal 6 karakter.';
        } elseif ($marginPass !== '' && $marginPass !== $marginConf) {
            $error = 'Konfirmasi password margin tidak cocok.';
        } else {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
                    `key` VARCHAR(150) PRIMARY KEY,
                    `value` TEXT NOT NULL,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )");
                $keyUser = "margin_username__{$targetShopId}";
                $keyPass = "margin_password__{$targetShopId}";

                $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (:k,:v)
                    ON DUPLICATE KEY UPDATE `value`=:v2")
                    ->execute([':k' => $keyUser, ':v' => $marginUser, ':v2' => $marginUser]);

                if ($marginPass !== '') {
                    $hashed = password_hash($marginPass, PASSWORD_DEFAULT);
                    $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (:k,:v)
                        ON DUPLICATE KEY UPDATE `value`=:v2")
                        ->execute([':k' => $keyPass, ':v' => $hashed, ':v2' => $hashed]);
                }

                // Ambil nama toko untuk pesan sukses
                $sn = $pdo->prepare("SELECT name FROM shops WHERE id=?");
                $sn->execute([$targetShopId]);
                $snRow = $sn->fetch();
                $success = 'Kredensial Margin untuk toko "' . htmlspecialchars($snRow['name'] ?? '') . '" berhasil disimpan.';
            } catch (Exception $e) {
                $error = 'Gagal update kredensial margin: ' . $e->getMessage();
            }
        }
    }

    // ── Ganti password sendiri ──
    elseif ($action === 'change_own_password') {
        $oldPassword = $_POST['old_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confPassword = $_POST['confirm_password'] ?? '';

        if (!$oldPassword || !$newPassword || !$confPassword) {
            $error = 'Semua field wajib diisi.';
        } elseif ($newPassword !== $confPassword) {
            $error = 'Password baru dan konfirmasi tidak cocok.';
        } elseif (strlen($newPassword) < 6) {
            $error = 'Password minimal 6 karakter.';
        } else {
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = :id");
            $stmt->execute([':id' => getUserId()]);
            $user = $stmt->fetch();
            if (!$user || !password_verify($oldPassword, $user['password'])) {
                $error = 'Password lama tidak sesuai.';
            } else {
                $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password = :pw WHERE id = :id")
                    ->execute([':pw' => $hashed, ':id' => getUserId()]);
                $success = 'Password berhasil diubah.';
            }
        }
    }
}

// ── Ambil semua data toko + user ──
$shops = $pdo->query("
    SELECT s.*, u.id AS user_id, u.username, u.display_name, u.last_login
    FROM shops s
    LEFT JOIN users u ON u.shop_id = s.id
    ORDER BY s.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Hitung ringkasan per toko
$shopStats = [];
foreach ($shops as $s) {
    $sid = $s['id'];
    if (!isset($shopStats[$sid])) {
        $stat = $pdo->prepare("
            SELECT
                COUNT(*) as total_orders,
                COALESCE(SUM(total_amount),0) as total_gmv
            FROM orders WHERE shop_id = ?
        ");
        $stat->execute([$sid]);
        $shopStats[$sid] = $stat->fetch(PDO::FETCH_ASSOC);
    }
}
// ── Ambil semua kredensial Margin per toko ──
$marginCredsAll = [];
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
        `key` VARCHAR(150) PRIMARY KEY,
        `value` TEXT NOT NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $rows = $pdo->query("SELECT `key`,`value` FROM settings WHERE `key` LIKE 'margin_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($rows as $k => $v) {
        if (preg_match('/^margin_(username|password)__(\d+)$/', $k, $m)) {
            $marginCredsAll[(int)$m[2]][$m[1]] = $v;
        }
    }
} catch (Exception $e) { /* ignore */ }
?>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin — Deeptok</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#0a0a0f;--surface:#13131a;--surface2:#1c1c28;--border:#2a2a3a;--accent:#7c6aff;--accent2:#ff6a9b;--accent3:#6affb8;--text:#e8e8f0;--text-muted:#6b6b8a;--success:#6affb8;--warning:#ffb86a;--danger:#ff6a6a;}
*{margin:0;padding:0;box-sizing:border-box;}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh;}
.header{padding:16px 32px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--surface);position:sticky;top:0;z-index:100;}
.logo{font-family:'Syne',sans-serif;font-weight:800;font-size:20px;background:linear-gradient(135deg,var(--accent),var(--accent2));-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
.header-right{display:flex;align-items:center;gap:10px;}
.btn{padding:7px 16px;border-radius:8px;font-family:'DM Mono',monospace;font-size:12px;cursor:pointer;text-decoration:none;transition:opacity 0.2s;border:none;display:inline-block;}
.btn-primary{background:var(--accent);color:white;}
.btn-secondary{background:var(--surface2);color:var(--text);border:1px solid var(--border);}
.btn-danger{background:rgba(255,106,106,0.15);color:var(--danger);border:1px solid rgba(255,106,106,0.3);}
.btn-sm{padding:5px 12px;font-size:11px;}
.btn:hover{opacity:0.8;}
.container{max-width:1200px;margin:0 auto;padding:24px 32px;}
.page-title{font-family:'Syne',sans-serif;font-size:20px;font-weight:800;margin-bottom:4px;}
.page-sub{font-size:12px;color:var(--text-muted);margin-bottom:28px;}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;}
.card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:22px 24px;margin-bottom:20px;}
.card-title{font-family:'Syne',sans-serif;font-size:14px;font-weight:700;margin-bottom:18px;color:var(--text);display:flex;align-items:center;gap:8px;}
.form-group{margin-bottom:14px;}
.form-label{display:block;font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;}
.form-input{width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--text);font-family:'DM Mono',monospace;font-size:12px;padding:9px 12px;outline:none;transition:border-color 0.2s;}
.form-input:focus{border-color:var(--accent);}
.form-input::placeholder{color:var(--text-muted);}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.alert{border-radius:8px;padding:12px 16px;font-size:12px;margin-bottom:20px;}
.alert-success{background:rgba(106,255,184,0.08);border:1px solid rgba(106,255,184,0.3);color:var(--success);}
.alert-error{background:rgba(255,106,106,0.08);border:1px solid rgba(255,106,106,0.3);color:var(--danger);}
.shop-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:18px 20px;margin-bottom:14px;transition:border-color 0.2s;}
.shop-card.active-shop{border-color:var(--accent);}
.shop-card:hover{border-color:var(--border);border-color:#3a3a5a;}
.shop-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;gap:10px;flex-wrap:wrap;}
.shop-name{font-family:'Syne',sans-serif;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;}
.shop-badge{font-size:10px;padding:3px 10px;border-radius:20px;font-weight:400;font-family:'DM Mono',monospace;}
.badge-connected{background:rgba(106,255,184,0.12);color:var(--success);}
.badge-disconnected{background:rgba(107,107,138,0.12);color:var(--text-muted);}
.badge-active{background:rgba(124,106,255,0.15);color:var(--accent);}
.shop-actions{display:flex;gap:8px;flex-wrap:wrap;}
.shop-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:14px;}
.shop-meta-item{background:var(--surface2);border-radius:8px;padding:10px 12px;}
.shop-meta-val{font-family:'Syne',sans-serif;font-size:16px;font-weight:700;color:var(--accent3);margin-bottom:3px;}
.shop-meta-lbl{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.8px;}
.user-row{display:flex;align-items:center;justify-content:space-between;background:var(--surface2);border-radius:8px;padding:10px 14px;font-size:12px;flex-wrap:wrap;gap:8px;}
.user-info{display:flex;align-items:center;gap:10px;}
.user-avatar{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-size:12px;font-weight:700;color:white;}
.user-name{font-weight:500;}
.user-sub{font-size:10px;color:var(--text-muted);margin-top:2px;}
.divider{height:1px;background:var(--border);margin:16px 0;}
.section-label{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;}

/* Modal */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:500;display:none;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:28px;width:440px;max-width:95vw;box-shadow:0 24px 64px rgba(0,0,0,0.5);animation:fadeUp 0.2s ease;}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:translateY(0);}}
.modal-title{font-family:'Syne',sans-serif;font-size:15px;font-weight:700;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;}
.modal-close{background:none;border:none;color:var(--text-muted);font-size:18px;cursor:pointer;padding:2px 6px;border-radius:4px;}
.modal-close:hover{color:var(--text);}
.modal-footer{display:flex;gap:8px;justify-content:flex-end;margin-top:18px;padding-top:18px;border-top:1px solid var(--border);}
</style>
</head>
<body>

<div class="header">
  <div class="logo">⚡ Deeptok <span style="font-size:12px;color:var(--text-muted);font-family:'DM Mono',monospace;font-weight:400;">/ Admin</span></div>
  <div class="header-right">
    <span style="font-size:11px;color:var(--text-muted);">Login sebagai: <strong style="color:var(--text)"><?= htmlspecialchars(getDisplayName()) ?></strong></span>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
    <a href="logout.php" class="btn btn-secondary" style="color:var(--danger);">⏻ Logout</a>
  </div>
</div>

<div class="container">
  <div class="page-title">⚙️ Admin Panel</div>
  <div class="page-sub">Kelola toko dan akun pengguna Deeptok</div>

  <?php if ($success): ?>
  <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="grid-2">

    <!-- Form Tambah Toko Baru -->
    <div class="card">
      <div class="card-title">🏪 Tambah Toko Baru</div>
      <form method="POST">
        <input type="hidden" name="action" value="add_shop">
        <div class="form-group">
          <label class="form-label">Nama Toko</label>
          <input type="text" name="shop_name" class="form-input" placeholder="cth: Toko Herbal Sehat" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Username</label>
            <input type="text" name="username" class="form-input" placeholder="cth: tokosehat" required>
          </div>
          <div class="form-group">
            <label class="form-label">Display Name</label>
            <input type="text" name="display_name" class="form-input" placeholder="opsional">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-input" placeholder="min. 6 karakter" required>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;padding:10px;">+ Buat Toko & Akun</button>
      </form>
    </div>

    <!-- Ganti Password Sendiri -->
    <div class="card">
      <div class="card-title">🔐 Ganti Password Saya</div>
      <form method="POST">
        <input type="hidden" name="action" value="change_own_password">
        <div class="form-group">
          <label class="form-label">Password Lama</label>
          <input type="password" name="old_password" class="form-input" placeholder="Password saat ini" required>
        </div>
        <div class="form-group">
          <label class="form-label">Password Baru</label>
          <input type="password" name="new_password" class="form-input" placeholder="min. 6 karakter" required>
        </div>
        <div class="form-group">
          <label class="form-label">Konfirmasi Password Baru</label>
          <input type="password" name="confirm_password" class="form-input" placeholder="Ulangi password baru" required>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;padding:10px;background:linear-gradient(135deg,var(--accent3),#2ab04e);color:#000;">💾 Simpan Password</button>
      </form>
    </div>

  </div>

  <!-- Daftar Semua Toko -->
  <div class="card">
    <div class="card-title">🏬 Semua Toko (<?= count(array_unique(array_column($shops, 'id'))) ?> toko)</div>

    <?php
    $rendered = [];
    foreach ($shops as $s):
      if (in_array($s['id'], $rendered)) continue;
      $rendered[] = $s['id'];
      $stat        = $shopStats[$s['id']] ?? ['total_orders'=>0,'total_gmv'=>0];
      $isActive    = $s['id'] === getShopId();
      $isConnected = !empty($s['access_token']);
    ?>
    <div class="shop-card <?= $isActive ? 'active-shop' : '' ?>">
      <div class="shop-header">
        <div class="shop-name">
          🏪 <?= htmlspecialchars($s['name']) ?>
          <?php if ($isActive): ?>
            <span class="shop-badge badge-active">Toko Anda</span>
          <?php endif; ?>
          <span class="shop-badge <?= $isConnected ? 'badge-connected' : 'badge-disconnected' ?>">
            <?= $isConnected ? '✅ TikTok Connected' : '⚠️ Belum Connect' ?>
          </span>
        </div>
        <div class="shop-actions">
          <button class="btn btn-secondary btn-sm" onclick="openRenameModal(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['name'])) ?>')">✏️ Rename</button>
          <?php if (!$isActive): ?>
          <button class="btn btn-danger btn-sm" onclick="openDeleteModal(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['name'])) ?>')">🗑 Hapus</button>
          <?php endif; ?>
        </div>
      </div>

      <div class="shop-meta">
        <div class="shop-meta-item">
          <div class="shop-meta-val"><?= number_format((int)$stat['total_orders']) ?></div>
          <div class="shop-meta-lbl">Total Order</div>
        </div>
        <div class="shop-meta-item">
          <div class="shop-meta-val">Rp <?= number_format((float)$stat['total_gmv']/1000000, 1) ?>jt</div>
          <div class="shop-meta-lbl">Total GMV</div>
        </div>
        <div class="shop-meta-item">
          <div class="shop-meta-val"><?= $s['tiktok_shop_id'] ? substr($s['tiktok_shop_id'],-8) : '—' ?></div>
          <div class="shop-meta-lbl">TikTok Shop ID</div>
        </div>
        <div class="shop-meta-item">
          <div class="shop-meta-val"><?= $s['connected_at'] ? date('d M Y', strtotime($s['connected_at'])) : '—' ?></div>
          <div class="shop-meta-lbl">Connect At</div>
        </div>
      </div>

      <div class="divider"></div>
      <div class="section-label">Akun Pengguna</div>

      <?php
      // Ambil semua user untuk toko ini
      $usersForShop = array_filter($shops, fn($u) => $u['id'] === $s['id'] && $u['user_id']);
      foreach ($usersForShop as $u):
        $initial = strtoupper(substr($u['display_name'] ?: $u['username'], 0, 1));
      ?>
      <div class="user-row">
        <div class="user-info">
          <div class="user-avatar"><?= $initial ?></div>
          <div>
            <div class="user-name"><?= htmlspecialchars($u['display_name'] ?: $u['username']) ?></div>
            <div class="user-sub">@<?= htmlspecialchars($u['username']) ?> · Last login: <?= $u['last_login'] ? date('d M Y H:i', strtotime($u['last_login'])) : 'Belum pernah' ?></div>
          </div>
        </div>
        <button class="btn btn-secondary btn-sm" onclick="openResetModal(<?= $u['user_id'] ?>, '<?= htmlspecialchars(addslashes($u['username'])) ?>')">🔑 Reset Password</button>
      </div>
      <?php endforeach; ?>

      <?php
        // Info kredensial margin untuk toko ini
        $mc = $marginCredsAll[$s['id']] ?? [];
        $mcUser = $mc['username'] ?? '';
        $mcHasPw = !empty($mc['password']);
      ?>
      <div class="divider"></div>
      <div class="section-label">Margin Dashboard</div>
      <div class="user-row" style="align-items:center;gap:12px;">
        <div style="display:flex;align-items:center;gap:10px;flex:1;flex-wrap:wrap;">
          <div style="font-size:20px;">📊</div>
          <div>
            <div style="font-size:12px;font-weight:500;">
              <?php if ($mcUser): ?>
                Username: <span style="color:var(--accent3);"><?= htmlspecialchars($mcUser) ?></span>
              <?php else: ?>
                <span style="color:var(--text-muted);">Belum dikonfigurasi</span>
              <?php endif; ?>
            </div>
            <div style="font-size:10px;margin-top:2px;color:<?= $mcHasPw ? 'var(--success)' : 'var(--warning)' ?>;">
              <?= $mcHasPw ? '✅ Password sudah diset' : '⚠️ Belum ada password' ?>
            </div>
          </div>
        </div>
        <button class="btn btn-sm" style="background:rgba(255,184,106,0.12);color:var(--warning);border:1px solid rgba(255,184,106,0.3);"
          onclick="openMarginModal(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['name'])) ?>', '<?= htmlspecialchars(addslashes($mcUser)) ?>')">
          🔐 Atur Kredensial
        </button>
      </div>

    </div>
    <?php endforeach; ?>
  </div>

</div>

<!-- Modal Margin Credentials -->
<div class="modal-overlay" id="modalMargin">
  <div class="modal">
    <div class="modal-title" style="color:var(--warning);">
      📊 Kredensial Margin Dashboard
      <button class="modal-close" onclick="closeModal('modalMargin')">✕</button>
    </div>
    <div style="font-size:11px;color:var(--text-muted);margin-bottom:18px;background:var(--surface2);border-radius:8px;padding:10px 12px;line-height:1.6;">
      Toko: <strong id="marginShopName" style="color:var(--text);"></strong>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="update_margin_credentials">
      <input type="hidden" name="shop_id" id="marginShopId">
      <div class="form-group">
        <label class="form-label">Username Margin</label>
        <input type="text" name="margin_username" id="marginUsername" class="form-input" placeholder="cth: margin_admin" required>
      </div>
      <div class="form-group">
        <label class="form-label">Password Baru <span style="color:var(--text-muted);font-size:9px;">(kosongkan jika tidak ganti)</span></label>
        <input type="password" name="margin_password" class="form-input" placeholder="min. 6 karakter">
      </div>
      <div class="form-group">
        <label class="form-label">Konfirmasi Password</label>
        <input type="password" name="margin_password_confirm" class="form-input" placeholder="Ulangi password baru">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modalMargin')">Batal</button>
        <button type="submit" class="btn btn-primary" style="background:linear-gradient(135deg,var(--warning),#e07800);color:#000;">💾 Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Reset Password -->
<div class="modal-overlay" id="modalReset">
  <div class="modal">
    <div class="modal-title">
      🔑 Reset Password
      <button class="modal-close" onclick="closeModal('modalReset')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="user_id" id="resetUserId">
      <div class="form-group">
        <label class="form-label">Username</label>
        <input type="text" id="resetUsername" class="form-input" disabled style="opacity:0.6;">
      </div>
      <div class="form-group">
        <label class="form-label">Password Baru</label>
        <input type="password" name="new_password" class="form-input" placeholder="min. 6 karakter" required>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modalReset')">Batal</button>
        <button type="submit" class="btn btn-primary">Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Rename Shop -->
<div class="modal-overlay" id="modalRename">
  <div class="modal">
    <div class="modal-title">
      ✏️ Rename Toko
      <button class="modal-close" onclick="closeModal('modalRename')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="rename_shop">
      <input type="hidden" name="shop_id" id="renameShopId">
      <div class="form-group">
        <label class="form-label">Nama Toko Baru</label>
        <input type="text" name="new_name" id="renameShopName" class="form-input" required>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modalRename')">Batal</button>
        <button type="submit" class="btn btn-primary">Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Hapus Toko -->
<div class="modal-overlay" id="modalDelete">
  <div class="modal">
    <div class="modal-title" style="color:var(--danger);">
      🗑 Hapus Toko
      <button class="modal-close" onclick="closeModal('modalDelete')">✕</button>
    </div>
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px;line-height:1.6;">
      Kamu akan menghapus toko <strong id="deleteShopName" style="color:var(--text);"></strong>.<br>
      <span style="color:var(--danger);">⚠️ Semua data order, produk, dan metrik toko ini akan ikut terhapus dan tidak bisa dikembalikan.</span>
    </p>
    <form method="POST">
      <input type="hidden" name="action" value="delete_shop">
      <input type="hidden" name="shop_id" id="deleteShopId">
      <div class="form-group">
        <label class="form-label">Ketik nama toko untuk konfirmasi</label>
        <input type="text" id="deleteConfirmInput" class="form-input" placeholder="Ketik nama toko...">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modalDelete')">Batal</button>
        <button type="submit" class="btn btn-danger" id="btnConfirmDelete" disabled>Hapus Permanen</button>
      </div>
    </form>
  </div>
</div>

<script>
let _deleteShopName = '';

function openMarginModal(shopId, shopName, currentUsername) {
  document.getElementById('marginShopId').value   = shopId;
  document.getElementById('marginShopName').textContent = shopName;
  document.getElementById('marginUsername').value = currentUsername || '';
  openModal('modalMargin');
}

function openResetModal(userId, username) {
  document.getElementById('resetUserId').value  = userId;
  document.getElementById('resetUsername').value = username;
  openModal('modalReset');
}

function openRenameModal(shopId, shopName) {
  document.getElementById('renameShopId').value   = shopId;
  document.getElementById('renameShopName').value = shopName;
  openModal('modalRename');
}

function openDeleteModal(shopId, shopName) {
  _deleteShopName = shopName;
  document.getElementById('deleteShopId').value    = shopId;
  document.getElementById('deleteShopName').textContent = shopName;
  document.getElementById('deleteConfirmInput').value   = '';
  document.getElementById('btnConfirmDelete').disabled  = true;
  openModal('modalDelete');
}

document.getElementById('deleteConfirmInput').addEventListener('input', function() {
  document.getElementById('btnConfirmDelete').disabled = this.value.trim() !== _deleteShopName;
});

function openModal(id) {
  document.getElementById(id).classList.add('open');
}
function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}

// Close modal on overlay click
document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
  overlay.addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
  });
});
</script>
</body>
</html>