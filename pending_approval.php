<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';
secureSessionStart();

if (isset($_GET['back'])) {
    unset($_SESSION['pending_approval']);
    header('Location: index.php');
    exit;
}

if (!empty($_SESSION['user_id'])) {
    require_once __DIR__ . '/includes/remember_login.php';
    redirectToRoleDashboard((string) ($_SESSION['user_role'] ?? 'student'));
}

$pending = $_SESSION['pending_approval'] ?? [];
$name = trim((string) ($pending['name'] ?? ''));
$email = trim((string) ($pending['email'] ?? ''));
$approvalCheckUnavailable = false;

// Mỗi lần tải lại trang, kiểm tra xem Admin đã duyệt tài khoản hay chưa.
// Nếu đã duyệt thì khôi phục phiên đăng nhập ngay, không bắt người dùng nhập lại mật khẩu.
$pendingUserId = (int) ($pending['user_id'] ?? 0);
if ($pendingUserId > 0 || $email !== '') {
    try {
        require_once __DIR__ . '/config/database.php';
        require_once __DIR__ . '/includes/account_lock.php';

        // SELECT * giúp trang chờ duyệt vẫn chạy nếu hosting chưa có một cột
        // phụ như avatar_url hoặc is_locked.
        $lookup = $pendingUserId > 0
            ? $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1')
            : $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $lookup->execute([$pendingUserId > 0 ? $pendingUserId : $email]);
        $user = $lookup->fetch(PDO::FETCH_ASSOC);

        if ($user && (int) ($user['is_approved'] ?? 0) === 1 && !isAccountLocked($pdo, (int) $user['id'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['user_name'] = (string) ($user['name'] ?? '');
            $_SESSION['user_role'] = (string) ($user['role'] ?? 'student');
            $_SESSION['user_avatar'] = $user['avatar_url'] ?? null;
            unset($_SESSION['pending_approval']);

            if ($_SESSION['user_role'] === 'admin') {
                header('Location: admin/dashboard.php');
            } elseif (in_array($_SESSION['user_role'], ['teacher', 'administrative_staff'], true)) {
                header('Location: teacher/dashboard.php');
            } else {
                header('Location: student/dashboard.php');
            }
            exit;
        }
    } catch (Throwable $error) {
        // Không để lỗi cột/migration tạm thời biến trang chờ duyệt thành HTTP 500.
        error_log('Pending approval status check failed: ' . $error->getMessage());
        $approvalCheckUnavailable = true;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đang chờ phê duyệt | LMS</title>
    <link rel="icon" type="image/png" href="assets/images/LOGO1.png?v=3">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <style>
        :root{color-scheme:dark;--panel:#17243a;--line:rgba(148,163,184,.24);--muted:#aebed3;--primary:#ff3d61;--success:#22c58b}
        *{box-sizing:border-box}body{min-height:100vh;margin:0;display:grid;place-items:center;padding:24px;font-family:Arial,sans-serif;color:#f8fafc;background:radial-gradient(circle at 15% 15%,#273b88 0,transparent 35%),radial-gradient(circle at 85% 85%,#4b266d 0,transparent 33%),#0d1729}
        .card{width:min(560px,100%);padding:42px;border:1px solid var(--line);border-radius:24px;text-align:center;background:rgba(23,36,58,.94);box-shadow:0 28px 85px rgba(0,0,0,.38)}
        .icon{display:grid;place-items:center;width:78px;height:78px;margin:0 auto 20px;border-radius:50%;background:rgba(245,158,11,.16);color:#fbbf24;font-size:42px}.brand{max-width:180px;max-height:65px;object-fit:contain;margin-bottom:22px}.card h1{margin:0 0 12px;font-size:30px}.card p{margin:0 auto;color:var(--muted);font-size:16px;line-height:1.7;max-width:460px}.account{display:grid;gap:4px;margin:24px 0;padding:15px 18px;border:1px solid var(--line);border-radius:14px;background:rgba(15,23,42,.45);text-align:left}.account strong{font-size:16px}.account span{color:var(--muted);font-size:14px}.notice{display:flex;gap:10px;align-items:flex-start;margin:0 0 25px;padding:13px 15px;border-radius:12px;color:#b9f8dc;background:rgba(34,197,139,.1);text-align:left;font-size:14px;line-height:1.55}.notice i{font-size:20px;color:var(--success)}.button{display:inline-flex;align-items:center;gap:8px;padding:13px 21px;border-radius:10px;background:var(--primary);color:#fff;text-decoration:none;font-weight:700}.button:hover{filter:brightness(1.08)}
    </style>
</head>
<body>
    <main class="card">
        <img class="brand" src="assets/images/Logomenu.png" alt="LMS">
        <div class="icon"><i class='bx bx-time-five'></i></div>
        <h1>Tài khoản đang chờ duyệt</h1>
        <p>Yêu cầu đăng ký của bạn đã được gửi đến quản trị viên. Bạn sẽ có quyền <strong>Học viên</strong> ngay sau khi được phê duyệt.</p>
        <?php if ($name !== '' || $email !== ''): ?>
            <div class="account">
                <?php if ($name !== ''): ?><strong><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></strong><?php endif; ?>
                <?php if ($email !== ''): ?><span><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="notice"><i class='bx bx-refresh'></i><span><?php echo $approvalCheckUnavailable ? 'Hệ thống đang thử kết nối lại để kiểm tra phê duyệt. Bạn có thể tải lại trang sau ít phút.' : 'Sau khi Admin duyệt, bạn chỉ cần tải lại trang này. Hệ thống sẽ tự đăng nhập cho bạn.'; ?></span></div>
        <a class="button" href="pending_approval.php?back=1"><i class='bx bx-left-arrow-alt'></i> Quay lại đăng nhập</a>
    </main>
</body>
</html>
