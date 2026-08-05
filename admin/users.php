<?php
require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/audit.php';
require_once '../includes/account_lock.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $targetUserId = (int) ($_POST['user_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? 'change_role');

    if ($action === 'toggle_lock' && $targetUserId > 0) {
        if ($targetUserId === (int) $_SESSION['user_id']) {
            $_SESSION['error'] = 'Bạn không thể tự khóa tài khoản đang đăng nhập.';
        } else {
            $stmt = $pdo->prepare('SELECT id, name, role, is_locked FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$targetUserId]);
            $target = $stmt->fetch();
            if (!$target) {
                $_SESSION['error'] = 'Không tìm thấy tài khoản.';
            } else {
                $willLock = (int) $target['is_locked'] !== 1;
                if ($willLock && $target['role'] === 'admin') {
                    $activeAdmins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_locked = 0")->fetchColumn();
                    if ($activeAdmins <= 1) {
                        $_SESSION['error'] = 'Không thể khóa quản trị viên hoạt động cuối cùng.';
                        header('Location: users.php');
                        exit;
                    }
                }

                $update = $pdo->prepare('UPDATE users SET is_locked = ?, locked_at = ?, locked_by = ? WHERE id = ?');
                $update->execute([
                    $willLock ? 1 : 0,
                    $willLock ? date('Y-m-d H:i:s') : null,
                    $willLock ? (int) $_SESSION['user_id'] : null,
                    $targetUserId,
                ]);
                if ($willLock) {
                    $pdo->prepare('DELETE FROM user_remember_tokens WHERE user_id = ?')->execute([$targetUserId]);
                }
                writeAuditLog($pdo, $willLock ? 'user.locked' : 'user.unlocked', 'user', $targetUserId, ['name' => $target['name']]);
                $_SESSION['success'] = $willLock ? 'Đã khóa tài khoản thành công.' : 'Đã mở khóa tài khoản thành công.';
            }
        }
        header('Location: users.php');
        exit;
    }

    $newRole = (string) ($_POST['role'] ?? '');
    if ($action === 'change_role' && $targetUserId > 0 && in_array($newRole, ['student', 'teacher', 'admin'], true)) {
        if ($targetUserId === (int) $_SESSION['user_id']) {
            $_SESSION['error'] = 'Bạn không thể tự thay đổi quyền của mình.';
        } else {
            $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
            $stmt->execute([$newRole, $targetUserId]);
            writeAuditLog($pdo, 'user.role_changed', 'user', $targetUserId, ['new_role' => $newRole]);
            $_SESSION['success'] = 'Đã cập nhật phân quyền thành công!';
        }
        header('Location: users.php');
        exit;
    }
}

$users = $pdo->query('SELECT * FROM users ORDER BY created_at DESC')->fetchAll();
$page_title = 'Quản lý Tài khoản';
require_once '../includes/header.php';
?>

<div class="box">
    <?php if (isset($_SESSION['success'])): ?>
        <div style="background:rgba(16,185,129,.2);color:#6ee7b7;padding:15px;border-radius:8px;margin-bottom:20px;">
            <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div style="background:rgba(239,68,68,.16);color:#fca5a5;padding:15px;border-radius:8px;margin-bottom:20px;">
            <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <p>Tổng số thành viên: <?php echo count($users); ?></p>
    <div style="overflow-x:auto;">
    <table>
        <thead><tr><th>Họ tên</th><th>Email</th><th>Ngày tham gia</th><th>Phân quyền</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
        <tbody>
        <?php foreach ($users as $user): $isSelf = (int) $user['id'] === (int) $_SESSION['user_id']; ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($user['name']); ?></strong></td>
                <td><?php echo htmlspecialchars($user['email']); ?></td>
                <td><?php echo date('d/m/Y', strtotime($user['created_at'])); ?></td>
                <td><span class="status <?php echo $user['role'] === 'admin' ? 'admin' : ($user['role'] === 'teacher' ? 'done' : 'pending'); ?>"><?php echo strtoupper($user['role']); ?></span></td>
                <td><span class="status <?php echo !empty($user['is_locked']) ? 'pending' : 'done'; ?>"><?php echo !empty($user['is_locked']) ? 'ĐÃ KHÓA' : 'HOẠT ĐỘNG'; ?></span></td>
                <td>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <form method="post" style="margin:0;display:flex;gap:8px;align-items:center;">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="change_role">
                            <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                            <select name="role" style="padding:8px;width:120px;font-size:13px;" <?php echo $isSelf ? 'disabled' : ''; ?>>
                                <option value="student" <?php echo $user['role'] === 'student' ? 'selected' : ''; ?>>Student</option>
                                <option value="teacher" <?php echo $user['role'] === 'teacher' ? 'selected' : ''; ?>>Teacher</option>
                                <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            </select>
                            <button class="btn btn-outline" style="padding:8px 12px;font-size:13px;" <?php echo $isSelf ? 'disabled' : ''; ?>>Lưu</button>
                        </form>
                        <form method="post" style="margin:0;" onsubmit="return confirm('<?php echo !empty($user['is_locked']) ? 'Mở khóa tài khoản này?' : 'Khóa tài khoản này? Người dùng sẽ bị đăng xuất.'; ?>');">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="toggle_lock">
                            <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                            <button class="btn btn-outline" style="padding:8px 12px;font-size:13px;" <?php echo $isSelf ? 'disabled title="Không thể tự khóa"' : ''; ?>>
                                <i class="bx <?php echo !empty($user['is_locked']) ? 'bx-lock-open-alt' : 'bx-lock-alt'; ?>"></i>
                                <?php echo !empty($user['is_locked']) ? 'Mở khóa' : 'Khóa'; ?>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
