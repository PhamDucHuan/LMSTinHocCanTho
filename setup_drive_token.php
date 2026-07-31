<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/env.php';

session_start();

$clientID = envValue('GOOGLE_CLIENT_ID', '');
$clientSecret = envValue('GOOGLE_CLIENT_SECRET', '');
$redirectUri = appUrl('setup_drive_token.php');
$tokenPath = envValue('GOOGLE_DRIVE_TOKEN_PATH', '');
if ($clientID === '' || $clientSecret === '' || $tokenPath === '') die('Thiếu cấu hình Google Drive trong .env');

$client = new Google_Client();
$client->setClientId($clientID);
$client->setClientSecret($clientSecret);
$client->setRedirectUri($redirectUri);
$client->addScope(Google_Service_Drive::DRIVE);
$client->setAccessType('offline');
$client->setPrompt('consent');

if (isset($_GET['code'])) {
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    
    if (isset($token['error'])) {
        die("Error fetching token: " . $token['error']);
    }
    
    if (!is_dir(dirname($tokenPath))) die('Thư mục lưu token chưa tồn tại.');
    file_put_contents($tokenPath, json_encode($token), LOCK_EX);
    
    echo "<h1>Thiết lập thành công!</h1>";
    echo "<p>Đã lưu token vào vị trí bảo mật đã cấu hình.</p>";
    if (!isset($token['refresh_token'])) {
        echo "<p style='color:red;'>CẢNH BÁO: Không có refresh_token trả về. Bạn có thể cần thu hồi quyền (revoke) của ứng dụng trên tài khoản Google và chạy lại file này.</p>";
    }
    echo "<a href='index.php'>Quay lại trang chủ</a>";
    exit;
} else {
    $authUrl = $client->createAuthUrl();
    echo "<h1>Thiết lập Google Drive Token</h1>";
    echo "<p>Bấm vào nút dưới đây để đăng nhập bằng tài khoản Google (tài khoản cố định để lưu file) và cấp quyền.</p>";
    echo "<a href='" . filter_var($authUrl, FILTER_SANITIZE_URL) . "' style='padding: 10px 20px; background: #4f46e5; color: white; text-decoration: none; border-radius: 5px;'>Xác thực với Google</a>";
}
?>
