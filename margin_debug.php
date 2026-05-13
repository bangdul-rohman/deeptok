<?php
/**
 * margin_debug.php — FILE SEMENTARA UNTUK DEBUG
 * HAPUS file ini setelah masalah login terselesaikan!
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/auth.php'; // ← load session Deeptok
require_once __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

$shopId = (int)($_SESSION['shop_id'] ?? 0);

echo "=== MARGIN DEBUG ===\n\n";
echo "shop_id dari session : {$shopId}\n";
echo "margin_logged_in     : " . ($_SESSION['margin_logged_in'] ?? 'tidak ada') . "\n";
echo "Session keys         : " . implode(', ', array_keys($_SESSION)) . "\n\n";

if ($shopId === 0) {
    echo "⚠️ shop_id KOSONG — session Deeptok mungkin belum aktif.\n";
    echo "Pastikan Anda sudah login ke Deeptok dulu sebelum membuka halaman ini.\n";
    exit;
}

try {
    $pdo = getDB();

    // Cek tabel settings ada
    $tables = $pdo->query("SHOW TABLES LIKE 'settings'")->fetchAll();
    echo "Tabel settings ada   : " . (count($tables) > 0 ? 'YA' : 'TIDAK') . "\n\n";

    if (count($tables) > 0) {
        // Ambil semua row margin untuk shop ini
        $ku = "margin_username__{$shopId}";
        $kp = "margin_password__{$shopId}";

        $r = $pdo->prepare("SELECT `key`, `value`, `updated_at` FROM settings WHERE `key` IN (?,?)");
        $r->execute([$ku, $kp]);
        $rows = $r->fetchAll(PDO::FETCH_ASSOC);

        echo "Key yang dicari:\n";
        echo "  - {$ku}\n";
        echo "  - {$kp}\n\n";

        if (empty($rows)) {
            echo "❌ Tidak ada data margin untuk shop_id={$shopId} di tabel settings.\n";
            echo "Pastikan sudah klik 'Atur Kredensial' di Admin Panel untuk toko ini.\n\n";

            // Tampilkan semua key margin yang ada
            $all = $pdo->query("SELECT `key`,`updated_at` FROM settings WHERE `key` LIKE 'margin_%'")->fetchAll(PDO::FETCH_ASSOC);
            if ($all) {
                echo "Key margin yang tersimpan di DB:\n";
                foreach ($all as $a) {
                    echo "  - {$a['key']} (updated: {$a['updated_at']})\n";
                }
            } else {
                echo "Tidak ada data margin sama sekali di tabel settings.\n";
            }
        } else {
            foreach ($rows as $row) {
                if (strpos($row['key'], 'username') !== false) {
                    echo "✅ Username ditemukan : '{$row['value']}'\n";
                    echo "   Updated at        : {$row['updated_at']}\n";
                } elseif (strpos($row['key'], 'password') !== false) {
                    echo "✅ Password hash ada  : " . substr($row['value'], 0, 20) . "...\n";
                    echo "   Updated at        : {$row['updated_at']}\n";

                    // Tes password jika dikirim via GET untuk debugging
                    if (isset($_GET['test_pass'])) {
                        $testPass = $_GET['test_pass'];
                        $ok = password_verify($testPass, $row['value']);
                        echo "\n🔑 Test password_verify('{$testPass}') : " . ($ok ? "✅ COCOK" : "❌ TIDAK COCOK") . "\n";
                    }
                }
            }
        }
    }

} catch (Exception $e) {
    echo "❌ DB Error: " . $e->getMessage() . "\n";
}

echo "\n=== END DEBUG ===\n";
echo "\nCatatan: Tambahkan ?test_pass=passwordAnda di URL untuk test verifikasi password.\n";
echo "HAPUS file ini setelah selesai debug!\n";
