<?php
require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/audit.php';
/** @var PDO $pdo Kết nối cơ sở dữ liệu được khởi tạo trong config/database.php. */

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $target_user_id = $_POST['user_id'] ?? null;
    $new_role = $_POST['role'] ?? null;
    
    if ($target_user_id && $new_role && in_array($new_role, ['student', 'teacher', 'admin'])) {
            $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->execute([$new_role, $target_user_id]);
            writeAuditLog($pdo, 'user.role_changed', 'user', (int) $target_user_id, ['new_role' => $new_role]);
        $_SESSION['success'] = "Đã cập nhật phân quyền thành công!";
        header('Location: users.php');
        exit;
    }
}

$stmt = $pdo->prepare("SELECT * FROM users ORDER BY created_at DESC");
$stmt->execute();
$users = $stmt->fetchAll();

$page_title = "Quản lý Tài khoản";
require_once '../includes/header.php';
?>

<div class="box">
    <?php if(isset($_SESSION['success'])): ?>
        <div style="background: rgba(16, 185, 129, 0.2); color: #6ee7b7; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>

    <p>Tổng số thành viên: <?php echo count($users); ?></p>
    
    <table>
        <thead>
            <tr>
                <th>Họ tên</th>
                <th>Email</th>
                <th>Ngày tham gia</th>
                <th>Phân quyền</th>
                <th>Thao tác</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($u['name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                    <td><?php echo date('d/m/Y', strtotime($u['created_at'])); ?></td>
                    <td>
                        <span class="status <?php echo $u['role'] === 'admin' ? 'admin' : ($u['role'] === 'teacher' ? 'done' : 'pending'); ?>">
                            <?php echo strtoupper($u['role']); ?>
                        </span>
                    </td>
                    <td>
                        <form method="POST" style="margin: 0; display: flex; gap: 10px; align-items: center;">
                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                            <select name="role" style="padding: 6px; width: 120px; font-size: 13px;" <?php echo ($u['id'] == $_SESSION['user_id']) ? 'disabled' : ''; ?>>
                                <option value="student" <?php echo $u['role'] === 'student' ? 'selected' : ''; ?>>Student</option>
                                <option value="teacher" <?php echo $u['role'] === 'teacher' ? 'selected' : ''; ?>>Teacher</option>
                                <option value="admin" <?php echo $u['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            </select>
                            <button type="submit" class="btn btn-outline" style="padding: 6px 12px; font-size: 13px;" <?php echo ($u['id'] == $_SESSION['user_id']) ? 'disabled' : ''; ?>>Lưu</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once '../includes/footer.php'; ?>
