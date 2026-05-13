<?php
declare(strict_types=1);

// ── Session harus dimulai sebelum apapun ──
if (session_status() === PHP_SESSION_NONE) {
    // Pastikan session config optimal sebelum start
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helper.php';

$code     = $_GET['code']  ?? '';
$state    = $_GET['state'] ?? '';
$error    = $_GET['error'] ?? '';

// ── Validasi state (CSRF protection) ──
// Catatan: Beberapa versi TikTok API tidak selalu mengembalikan ?state= di callback.
// Jika state tidak ada di URL tapi ada code & session aktif → lanjutkan.
// Jika state ada di URL → validasi ketat.
$sessionState = $_SESSION['oauth_state'] ?? '';

if (!empty($state) && !empty($sessionState) && $state !== $sessionState) {
    // State ada di kedua sisi tapi tidak cocok → CSRF atau tab ganda
    die('❌ OAuth state tidak cocok. Kemungkinan kamu membuka dua tab sekaligus. Silakan <a href="/connect.php">Coba lagi</a>.');
}

// Jika state tidak dikirim TikTok, minimal harus ada code
if (empty($state) && empty($code)) {
    die('❌ Authorization code tidak ditemukan. <a href="/connect.php">Coba lagi</a>');
}

// ── Validasi session shop_id ──
$shopId = (int)($_SESSION['oauth_shop_id'] ?? $_SESSION['shop_id'] ?? 0);
if (!$shopId) {
    die('❌ Session tidak valid. <a href="/login.php">Login ulang</a>');
}

// ── TikTok mengembalikan error ──
if ($error) {
    header('Location: /connect.php?err=' . urlencode($error));
    exit;
}

if (!$code) {
    die('❌ Authorization code tidak ditemukan. <a href="/connect.php">Coba lagi</a>');
}

// ── Tukar code dengan access token ──
$tokenUrl  = 'https://auth.tiktok-shops.com/api/v2/token/get';
$params    = [
    'app_key'    => TTS_APP_KEY,
    'app_secret' => TTS_APP_SECRET,
    'auth_code'  => $code,
    'grant_type' => 'authorized_code',
];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $tokenUrl . '?' . http_build_query($params),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$response = curl_exec($ch);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    die('❌ Curl error: ' . htmlspecialchars($curlErr) . ' <a href="/connect.php">Coba lagi</a>');
}

$data = json_decode($response, true);

if (empty($data['data']['access_token'])) {
    $msg = $data['message'] ?? $data['msg'] ?? 'Unknown error';
    die('❌ Gagal mendapatkan token: ' . htmlspecialchars($msg) . '<br><pre>' . htmlspecialchars($response) . '</pre><a href="/connect.php">Coba lagi</a>');
}

$accessToken  = $data['data']['access_token'];
$refreshToken = $data['data']['refresh_token']       ?? null;
$expireIn     = $data['data']['access_token_expire_in'] ?? 0;
$expireAt     = $expireIn ? time() + (int)$expireIn : null;

// ── Ambil info shop dari TikTok ──
$tiktokShopId   = null;
$shopCipher     = null;
$shopRegion     = 'ID';
$shopTiktokName = null;

// Coba ambil shop info — retry 2x kalau gagal
for ($attempt = 1; $attempt <= 2; $attempt++) {
    try {
        $path      = '/authorization/202309/shops';
        $timestamp = time();
        $params2   = ['app_key' => TTS_APP_KEY, 'timestamp' => $timestamp];
        ksort($params2);
        $input = '';
        foreach ($params2 as $k => $v) {
            if ($k === 'sign') continue;
            $input .= $k . $v;
        }
        $input = TTS_APP_SECRET . $path . $input . TTS_APP_SECRET;
        $params2['sign'] = bin2hex(hash_hmac('sha256', $input, TTS_APP_SECRET, true));

        $url2 = TTS_API_HOST . $path . '?' . http_build_query($params2);
        $ch2  = curl_init($url2);
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'x-tts-access-token: ' . $accessToken,
            ],
        ]);
        $shopRaw  = curl_exec($ch2);
        curl_close($ch2);

        $shopResponse = json_decode($shopRaw, true);
        if (!empty($shopResponse['data']['shops'])) {
            $firstShop      = $shopResponse['data']['shops'][0];
            $tiktokShopId   = $firstShop['id']     ?? null;
            $shopCipher     = $firstShop['cipher']  ?? null;
            $shopRegion     = $firstShop['region']  ?? 'ID';
            $shopTiktokName = $firstShop['name']    ?? null;
            break; // Berhasil, keluar dari loop
        }
    } catch (Exception $e) {
        // Retry
    }
    sleep(1);
}

// ── Simpan token ke tabel shops ──
$pdo = getDB();

try {
    $stmt = $pdo->prepare("
        UPDATE shops
        SET access_token   = :token,
            tiktok_shop_id = :shop_id,
            tiktok_cipher  = :cipher,
            tiktok_region  = :region,
            token_expire   = :expire,
            connected_at   = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':token'   => $accessToken,
        ':shop_id' => $tiktokShopId,
        ':cipher'  => $shopCipher,
        ':region'  => $shopRegion,
        ':expire'  => $expireAt,
        ':id'      => $shopId,
    ]);

    // Jika TikTok mengembalikan nama toko, update juga nama di DB
    if ($shopTiktokName) {
        $pdo->prepare("UPDATE shops SET name = :name WHERE id = :id AND name = 'Toko Pertama'")
            ->execute([':name' => $shopTiktokName, ':id' => $shopId]);
    }

    // Update session
    $_SESSION['shop_connected']  = true;
    $_SESSION['tiktok_shop_id']  = $tiktokShopId;
    $_SESSION['shop_name']       = $shopTiktokName ?? $_SESSION['shop_name'];

    // Bersihkan oauth state
    unset($_SESSION['oauth_state'], $_SESSION['oauth_shop_id']);

    // Kalau cipher berhasil diambil → langsung ke dashboard
    // Kalau cipher masih NULL → ke fetch_shop_info.php dulu
    if ($shopCipher) {
        header('Location: /dashboard.php?connected=1');
    } else {
        header('Location: /fetch_shop_info.php?auto=1');
    }
    exit;

} catch (Exception $e) {
    die('❌ Gagal menyimpan token ke database: ' . htmlspecialchars($e->getMessage()) . ' <a href="/connect.php">Coba lagi</a>');
}
