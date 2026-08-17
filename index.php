<?php
require_once __DIR__ . '/includes/security.php';
secureSessionStart();
if (!empty($_SESSION['pending_approval'])) {
    header('Location: pending_approval.php');
    exit;
}
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
    <link rel="icon" type="image/png" href="assets/images/LOGO1.png?v=3">
    <link rel="apple-touch-icon" href="assets/images/LOGO1.png?v=3">
    <!-- Google Fonts -->
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
            <h1 class="sr-only">Tin học Cần Thơ LMS</h1>
            <section class="form-panel" aria-live="polite">
                <div class="form-heading login-heading">
                    <span class="eyebrow">Tài khoản LMS</span>
                    <h2>Đăng nhập</h2>
                    <p>Tiếp tục hành trình học tập của bạn.</p>
                </div>
                <div class="form-heading register-heading">
                    <span class="eyebrow">Bắt đầu ngay</span>
                    <h2>Tạo tài khoản</h2>
                    <p>Đăng ký tài khoản học viên chỉ trong ít phút.</p>
                </div>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger">
                        <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                    </div>
                <?php endif; ?>
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success">
                        <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                    </div>
                <?php endif; ?>

                <div class="forms-stage">
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

                </form>
                </div>
            </section>

            <aside class="welcome-panel">
                <div class="welcome-glow"></div>
                <div class="welcome-content welcome-login">
                    <img src="assets/images/Logomenu.png" class="auth-brand-logo" alt="Tin học Cần Thơ">
                    <span class="welcome-kicker">Chào mừng bạn đến với</span>
                    <h2 class="welcome-panel-title">Tin Học Cần Thơ</h2>
                    <p class="welcome-tagline"><span>Nơi</span><strong>Học Thật - Làm Thật - Chất Lượng Thật</strong></p>
                    <button type="button" class="btn btn-switch" id="show-register">Đăng ký tài khoản <i class='bx bx-right-arrow-alt'></i></button>
                </div>
                <div class="welcome-content welcome-register">
                    <img src="assets/images/Logomenu.png" class="auth-brand-logo" alt="Tin học Cần Thơ">
                    <span class="welcome-kicker">Rất vui gặp lại</span>
                    <h2 class="welcome-panel-title">Đã có tài khoản?</h2>
                    <p class="welcome-description">Đăng nhập để tiếp tục khóa học, hoàn thành bài tập và xem kết quả của bạn.</p>
                    <button type="button" class="btn btn-switch" id="show-login"><i class='bx bx-left-arrow-alt'></i> Quay lại đăng nhập</button>
                </div>
            </aside>
        </div>
    </div>

    <script src="assets/js/main.js"></script>
</body>
</html>
