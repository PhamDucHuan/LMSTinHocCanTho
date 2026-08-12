<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/remember_login.php';
secureSessionStart();
require_once '../config/database.php';
require_once __DIR__ . '/account_lock.php';
require_once __DIR__ . '/login_history.php';

// Tệp xử lý đăng nhập bằng Google
// Tính năng này đang được thiết lập mô phỏng. Bắt buộc phải có Client ID từ Google Cloud.
// Sau khi cài đặt composer require google/apiclient, bỏ comment đoạn dưới đây

$client = require_once '../config/google_config.php';

if (isset($_GET['code'])) {
    if (empty($_GET['state']) || !hash_equals($_SESSION['google_oauth_state'] ?? '', (string) $_GET['state'])) {
        unset($_SESSION['google_oauth_state']);
        http_response_code(400); exit('Phiên đăng nhập Google không hợp lệ.');
    }
    unset($_SESSION['google_oauth_state']);
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    if (!empty($token['error']) || empty($token['access_token'])) {
        throw new RuntimeException('Không thể xác thực tài khoản Google.');
    }
    $client->setAccessToken($token['access_token']);
    
    // get profile info
    $google_oauth = new Google_Service_Oauth2($client);
    $google_account_info = $google_oauth->userinfo->get();
    
    $email =  $google_account_info->email;
    $name =  $google_account_info->name;
    $google_id = $google_account_info->id;
    $avatar_url = filter_var((string) ($google_account_info->picture ?? ''), FILTER_VALIDATE_URL) ?: null;
    if ($avatar_url && strtolower((string) parse_url($avatar_url, PHP_URL_SCHEME)) !== 'https') {
        $avatar_url = null;
    }
    
    // Check if user exists
    $stmt = $pdo->prepare("SELECT * FROM users WHERE google_id = ? OR email = ?");
    $stmt->execute([$google_id, $email]);
    $user = $stmt->fetch();
    
    if ($user && isAccountLocked($pdo, (int) $user['id'])) {
        recordLoginHistory($pdo, (int) $user['id'], 'login_google_failed_locked', 'google', $email, ['reason' => 'account_locked']);
        $_SESSION['error'] = 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ quản trị viên.';
        header('Location: ../index.php');
        exit;
    }


    if ($user && !isAccountApproved($pdo, (int) $user['id'])) {
        recordLoginHistory($pdo, (int) $user['id'], 'login_google_failed_pending', 'google', $email, ['reason' => 'awaiting_admin_approval']);
        $_SESSION['error'] = 'Tài khoản Google đang chờ Admin duyệt. Bạn chưa thể đăng nhập.';
        header('Location: ../index.php');
        exit;
    }

    if($user) {
        // Liên kết Google và làm mới avatar vì người dùng có thể đổi ảnh Google.
        $update = $pdo->prepare("UPDATE users SET google_id = COALESCE(NULLIF(google_id, ''), ?), avatar_url = ? WHERE id = ?");
        $update->execute([$google_id, $avatar_url, $user['id']]);
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_avatar'] = $avatar_url;
        recordLoginHistory($pdo, (int) $user['id'], 'login_google_success', 'google', $email);
    } else {
        // Đăng ký mới với mặc định là học viên
        $insert = $pdo->prepare("INSERT INTO users (name, email, google_id, avatar_url, role, is_approved) VALUES (?, ?, ?, ?, 'student', 0)");
        $insert->execute([$name, $email, $google_id, $avatar_url]);

        $_SESSION['success'] = 'Đã ghi nhận tài khoản Google. Vui lòng chờ Admin duyệt trước khi đăng nhập.';
        header('Location: ../index.php');
        exit;
    }
    
    session_regenerate_id(true);
    // Google login is remembered for 30 days on this browser.
    issueRememberLoginToken($pdo, (int) $_SESSION['user_id']);
    if ($_SESSION['user_role'] === 'teacher') {
        header('Location: ../teacher/dashboard.php');
    } elseif ($_SESSION['user_role'] === 'admin') {
        header('Location: ../admin/dashboard.php');
    } else {
        header('Location: ../student/dashboard.php');
    }
    exit;
} else {
    // Redirect to google login
    $_SESSION['google_oauth_state'] = bin2hex(random_bytes(32));
    $client->setState($_SESSION['google_oauth_state']);
    $authUrl = $client->createAuthUrl();
    header('Location: ' . filter_var($authUrl, FILTER_SANITIZE_URL));
    exit;
}
?>
