<?php
require_once __DIR__ . '/../includes/security.php';
secureSessionStart();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $name = trim($_POST['name']);
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($name)) {
        $error = "Họ và tên không được để trống.";
    } else {
        $update_query = "UPDATE users SET name = ?";
        $params = [$name];

        if (!empty($new_password)) {
            $current_password = $_POST['current_password'] ?? '';
            if (empty($user['password_hash']) || !password_verify($current_password, $user['password_hash'])) {
                $error = "Mật khẩu hiện tại không đúng.";
            } elseif (strlen($new_password) < 8) {
                $error = "Mật khẩu mới phải có ít nhất 8 ký tự.";
            } elseif ($new_password !== $confirm_password) {
                $error = "Mật khẩu xác nhận không khớp.";
            } else {
                $update_query .= ", password_hash = ?";
                $params[] = password_hash($new_password, PASSWORD_DEFAULT);
            }
        }

        $update_query .= " WHERE id = ?";
        $params[] = $user_id;

        if (!isset($error)) {
            $stmt = $pdo->prepare($update_query);
            if ($stmt->execute($params)) {
                $_SESSION['user_name'] = $name; // Cập nhật session name để navbar tự động hiện tên mới
                $success = "Cập nhật thông tin thành công!";
                // Refresh data
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
            } else {
                $error = "Có lỗi xảy ra, vui lòng thử lại.";
            }
        }
    }
}

$page_title = "Hồ sơ cá nhân";
require_once '../includes/header.php';
?>

<div class="box" style="max-width: 600px; margin: 0 auto; padding: 40px;">
    <div style="text-align: center; margin-bottom: 30px;">
        <i class='bx bxs-user-circle' style="font-size: 80px; color: var(--primary);"></i>
        <h2 style="margin: 10px 0 5px 0;"><?php echo htmlspecialchars($user['name']); ?></h2>
        <p style="color: var(--text-muted); margin: 0; text-transform: capitalize;"><?php echo htmlspecialchars($user['role']); ?></p>
    </div>

    <?php if (isset($error)): ?>
        <div style="background: rgba(239, 68, 68, 0.2); color: #fca5a5; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; border: 1px solid rgba(239, 68, 68, 0.3);">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>
    <?php if (isset($success)): ?>
        <div style="background: rgba(16, 185, 129, 0.2); color: #6ee7b7; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; border: 1px solid rgba(16, 185, 129, 0.3);">
            <?php echo $success; ?>
        </div>
    <?php endif; ?>

    <form action="" method="POST">
        <div class="form-group">
            <label><i class='bx bx-user'></i> Họ và tên</label>
            <input type="text" name="name" required value="<?php echo htmlspecialchars($user['name']); ?>">
        </div>
        
        <div class="form-group">
            <label><i class='bx bx-envelope'></i> Email (Không thể thay đổi)</label>
            <input type="text" disabled value="<?php echo htmlspecialchars($user['email']); ?>" style="opacity: 0.6; cursor: not-allowed;">
        </div>

        <hr style="border: none; border-top: 1px solid rgba(255,255,255,0.05); margin: 25px 0;">
        <h4 style="margin-top: 0; margin-bottom: 20px; color: var(--text-muted);">Đổi mật khẩu</h4>

        <div class="form-group">
            <label><i class='bx bx-key'></i> Mật khẩu hiện tại</label>
            <input type="password" name="current_password" autocomplete="current-password">
        </div>

        <div class="form-group">
            <label><i class='bx bx-lock-alt'></i> Mật khẩu mới</label>
            <input type="password" name="new_password" placeholder="Nhập mật khẩu mới (Để trống nếu không đổi)" style="width: 100%; padding: 12px; background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.1); color: #fff; border-radius: 8px; font-family: inherit; box-sizing: border-box;">
        </div>

        <div class="form-group">
            <label><i class='bx bx-check-shield'></i> Xác nhận mật khẩu mới</label>
            <input type="password" name="confirm_password" placeholder="Nhập lại mật khẩu mới" style="width: 100%; padding: 12px; background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.1); color: #fff; border-radius: 8px; font-family: inherit; box-sizing: border-box;">
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px; padding: 15px; font-size: 16px;">
            <i class='bx bx-save'></i> Lưu Thay Đổi
        </button>
    </form>
</div>

<?php require_once '../includes/footer.php'; ?>
