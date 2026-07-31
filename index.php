<?php
require_once __DIR__ . '/includes/security.php';
secureSessionStart();
if (empty($_SESSION['user_id']) && isset($_COOKIE['lms_google_remember'])) {
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/includes/remember_login.php';
    if (restoreRememberedGoogleLogin($pdo)) {
        redirectToRoleDashboard((string) $_SESSION['user_role']);
    }
}
if (!empty($_SESSION['user_id'])) {
    require_once __DIR__ . '/includes/remember_login.php';
    redirectToRoleDashboard((string) $_SESSION['user_role']);
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng Nhập | LMS Tin Học</title>
    <link rel="icon" type="image/png" sizes="512x512" href="assets/images/3.png?v=2">
    <link rel="apple-touch-icon" href="assets/images/3.png?v=2">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- Boxicons for icons -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
</head>
<body>
    <div class="background-animation">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
    </div>

    <div class="container">
        <div class="auth-box">
            <div class="auth-header">
                <img src="assets/images/logo.png" class="auth-brand-logo" alt="Tin học Cần Thơ">
                <h1 class="sr-only">Tin học Cần Thơ LMS</h1>
                <p>Nền tảng quản lý học tập và chấm điểm tự động</p>
            </div>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger" style="background: rgba(239, 68, 68, 0.2); color: #fca5a5; padding: 10px; border-radius: 8px; margin-bottom: 20px; text-align: center; border: 1px solid rgba(239, 68, 68, 0.3);">
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success" style="background: rgba(16, 185, 129, 0.2); color: #6ee7b7; padding: 10px; border-radius: 8px; margin-bottom: 20px; text-align: center; border: 1px solid rgba(16, 185, 129, 0.3);">
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form id="login-form" action="includes/auth.php" method="POST" class="auth-form active-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="login">
                
                <div class="input-group">
                    <i class='bx bx-envelope'></i>
                    <input type="email" name="email" required placeholder="Email của bạn">
                </div>
                
                <div class="input-group">
                    <i class='bx bx-lock-alt'></i>
                    <input type="password" name="password" required placeholder="Mật khẩu">
                </div>

                <div class="form-options">
                    <label>
                        <input type="checkbox" name="remember"> Ghi nhớ đăng nhập
                    </label>
                    <a href="#" class="forgot-password">Quên mật khẩu?</a>
                </div>

                <button type="submit" class="btn btn-primary">Đăng Nhập <i class='bx bx-right-arrow-alt'></i></button>
                
                <div class="divider">
                    <span>HOẶC</span>
                </div>

                <a href="includes/google_auth.php" class="btn btn-google" style="text-decoration: none;">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/c/c1/Google_%22G%22_logo.svg" alt="Google">
                    Đăng nhập bằng Google
                </a>

                <p class="switch-form">Chưa có tài khoản? <a href="#" id="show-register">Đăng ký ngay</a></p>
            </form>

            <!-- Register Form -->
            <form id="register-form" action="includes/auth.php" method="POST" class="auth-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="register">
                
                <div class="input-group">
                    <i class='bx bx-user'></i>
                    <input type="text" name="name" required placeholder="Họ và tên">
                </div>

                <div class="input-group">
                    <i class='bx bx-envelope'></i>
                    <input type="email" name="email" required placeholder="Email của bạn">
                </div>
                
                <div class="input-group">
                    <i class='bx bx-lock-alt'></i>
                    <input type="password" name="password" required placeholder="Mật khẩu">
                </div>

                <p class="form-note">Tài khoản mới được tạo với vai trò Học viên. Admin có thể cấp quyền Giảng viên sau.</p>

                <button type="submit" class="btn btn-primary">Tạo Tài Khoản <i class='bx bx-user-plus'></i></button>

                <p class="switch-form">Đã có tài khoản? <a href="#" id="show-login">Đăng nhập</a></p>
            </form>
        </div>
    </div>

    <script src="assets/js/main.js"></script>
</body>
</html>
