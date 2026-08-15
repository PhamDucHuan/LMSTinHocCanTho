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
    $returnPage = max(1, (int) ($_POST['return_page'] ?? 1));
    $returnLocation = 'users.php?page=' . $returnPage;

    if ($action === 'approve_account' && $targetUserId > 0) {
        $stmt = $pdo->prepare('SELECT id, name, email, is_approved FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$targetUserId]);
        $target = $stmt->fetch();
        if (!$target) {
            $_SESSION['error'] = 'Không tìm thấy tài khoản cần duyệt.';
        } elseif (!empty($target['is_approved'])) {
            $_SESSION['success'] = 'Tài khoản này đã được duyệt trước đó.';
        } else {
            $approve = $pdo->prepare(
                "UPDATE users
                 SET is_approved = 1, approved_at = NOW(), approved_by = ?, role = 'student'
                 WHERE id = ? AND is_approved = 0"
            );
            $approve->execute([(int) $_SESSION['user_id'], $targetUserId]);
            writeAuditLog($pdo, 'user.approved', 'user', $targetUserId, [
                'name' => $target['name'],
                'email' => $target['email'],
                'role' => 'student',
            ]);
            $_SESSION['success'] = 'Đã duyệt tài khoản. Người dùng được cấp quyền Học viên.';
        }
        header('Location: ' . $returnLocation);
        exit;
    }

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
                        header('Location: ' . $returnLocation);
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
        header('Location: ' . $returnLocation);
        exit;
    }

    $newRole = (string) ($_POST['role'] ?? '');
    if ($action === 'change_role' && $targetUserId > 0 && in_array($newRole, ['student', 'teacher', 'administrative_staff', 'admin'], true)) {
        if ($targetUserId === (int) $_SESSION['user_id']) {
            $_SESSION['error'] = 'Bạn không thể tự thay đổi quyền của mình.';
        } else {
            $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE id = ? AND is_approved = 1');
            $stmt->execute([$newRole, $targetUserId]);
            if ($stmt->rowCount() > 0) {
                writeAuditLog($pdo, 'user.role_changed', 'user', $targetUserId, ['new_role' => $newRole]);
                $_SESSION['success'] = 'Đã cập nhật phân quyền thành công!';
            } else {
                $_SESSION['error'] = 'Bạn phải duyệt tài khoản trước khi thay đổi phân quyền.';
            }
        }
        header('Location: ' . $returnLocation);
        exit;
    }
}

$usersPerPage = 25;
$totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalPages = max(1, (int) ceil($totalUsers / $usersPerPage));
$currentPage = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$offset = ($currentPage - 1) * $usersPerPage;
$users = $pdo->query(
    'SELECT * FROM users ORDER BY created_at DESC, id DESC LIMIT ' . $usersPerPage . ' OFFSET ' . $offset
)->fetchAll();
$pendingCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_approved = 0')->fetchColumn();
$pendingUsers = $pdo->query(
    'SELECT * FROM users WHERE is_approved = 0 ORDER BY created_at, id LIMIT 100'
)->fetchAll();
$pageUrl = static fn(int $page): string => '?page=' . max(1, $page);
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

    <div class="approval-toolbar">
        <div>
            <strong><i class='bx bx-user-check'></i> Phê duyệt tài khoản mới</strong>
            <small>Tài khoản mới chỉ được truy cập LMS sau khi Admin chấp thuận.</small>
        </div>
        <button type="button" class="btn btn-primary" id="open-approval-dialog">
            <i class='bx bx-list-check'></i> Yêu cầu chờ duyệt
            <span class="approval-count"><?php echo $pendingCount; ?></span>
        </button>
    </div>

    <dialog class="approval-dialog" id="approval-dialog">
        <div class="approval-dialog-head">
            <div>
                <h2><i class='bx bx-user-check'></i> Yêu cầu đăng ký</h2>
                <p><?php echo $pendingCount; ?> tài khoản đang chờ Admin duyệt</p>
            </div>
            <button type="button" class="approval-close" id="close-approval-dialog" aria-label="Đóng"><i class='bx bx-x'></i></button>
        </div>
        <div class="approval-list">
            <?php foreach ($pendingUsers as $pendingUser): ?>
                <article class="approval-request">
                    <div class="approval-avatar"><i class='bx bx-user'></i></div>
                    <div class="approval-person">
                        <strong><?php echo htmlspecialchars($pendingUser['name']); ?></strong>
                        <span><?php echo htmlspecialchars($pendingUser['email']); ?></span>
                        <small><i class='bx bx-time-five'></i> Đăng ký <?php echo date('d/m/Y H:i', strtotime($pendingUser['created_at'])); ?> · Quyền mặc định: Học viên</small>
                    </div>
                    <form method="post">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="return_page" value="<?php echo $currentPage; ?>">
                        <input type="hidden" name="action" value="approve_account">
                        <input type="hidden" name="user_id" value="<?php echo (int) $pendingUser['id']; ?>">
                        <button class="btn btn-primary"><i class='bx bx-check'></i> Duyệt</button>
                    </form>
                </article>
            <?php endforeach; ?>
            <?php if (!$pendingUsers): ?>
                <div class="approval-empty"><i class='bx bx-check-circle'></i><strong>Không có yêu cầu đang chờ</strong><span>Tất cả tài khoản hiện tại đã được xử lý.</span></div>
            <?php endif; ?>
        </div>
    </dialog>

    <p>Tổng số thành viên: <?php echo number_format($totalUsers); ?> · Trang <?php echo $currentPage; ?>/<?php echo $totalPages; ?></p>
    <div style="overflow-x:auto;">
    <table>
        <thead><tr><th>Họ tên</th><th>Email</th><th>Ngày tham gia</th><th>Phân quyền</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
        <tbody>
        <?php foreach ($users as $user): $isSelf = (int) $user['id'] === (int) $_SESSION['user_id']; ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($user['name']); ?></strong></td>
                <td><?php echo htmlspecialchars($user['email']); ?></td>
                <td><?php echo date('d/m/Y', strtotime($user['created_at'])); ?></td>
                <?php $roleLabels = ['student' => 'HỌC VIÊN', 'teacher' => 'GIÁO VIÊN', 'administrative_staff' => 'NHÂN VIÊN HÀNH CHÍNH', 'admin' => 'ADMIN']; ?>
                <td><span class="status <?php echo $user['role'] === 'admin' ? 'admin' : (in_array($user['role'], ['teacher', 'administrative_staff'], true) ? 'done' : 'pending'); ?>"><?php echo htmlspecialchars($roleLabels[$user['role']] ?? strtoupper((string) $user['role'])); ?></span></td>
                <td>
                    <?php if (empty($user['is_approved'])): ?>
                        <span class="status pending">CHỜ DUYỆT</span>
                    <?php else: ?>
                        <span class="status <?php echo !empty($user['is_locked']) ? 'pending' : 'done'; ?>"><?php echo !empty($user['is_locked']) ? 'ĐÃ KHÓA' : 'HOẠT ĐỘNG'; ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <form method="post" style="margin:0;display:flex;gap:8px;align-items:center;">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="return_page" value="<?php echo $currentPage; ?>">
                            <input type="hidden" name="action" value="change_role">
                            <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                            <select name="role" style="padding:8px;width:190px;font-size:13px;" <?php echo ($isSelf || empty($user['is_approved'])) ? 'disabled' : ''; ?>>
                                <option value="student" <?php echo $user['role'] === 'student' ? 'selected' : ''; ?>>Student</option>
                                <option value="teacher" <?php echo $user['role'] === 'teacher' ? 'selected' : ''; ?>>Teacher</option>
                                <option value="administrative_staff" <?php echo $user['role'] === 'administrative_staff' ? 'selected' : ''; ?>>Nhân viên hành chính</option>
                                <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            </select>
                            <button class="btn btn-outline" style="padding:8px 12px;font-size:13px;" <?php echo ($isSelf || empty($user['is_approved'])) ? 'disabled' : ''; ?>>Lưu</button>
                        </form>
                        <?php if (empty($user['is_approved'])): ?>
                            <form method="post" style="margin:0;">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="return_page" value="<?php echo $currentPage; ?>">
                                <input type="hidden" name="action" value="approve_account">
                                <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                                <button class="btn btn-primary" style="padding:8px 12px;font-size:13px;"><i class='bx bx-user-check'></i> Duyệt</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" style="margin:0;" onsubmit="return confirm('<?php echo !empty($user['is_locked']) ? 'Mở khóa tài khoản này?' : 'Khóa tài khoản này? Người dùng sẽ bị đăng xuất.'; ?>');">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="return_page" value="<?php echo $currentPage; ?>">
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
    <?php if ($totalPages > 1): ?>
        <nav class="account-pagination" aria-label="Phân trang tài khoản">
            <a class="btn btn-outline<?php echo $currentPage <= 1 ? ' is-disabled' : ''; ?>" href="<?php echo htmlspecialchars($pageUrl($currentPage - 1)); ?>"><i class='bx bx-chevron-left'></i> Trước</a>
            <?php for ($page = max(1, $currentPage - 2); $page <= min($totalPages, $currentPage + 2); $page++): ?>
                <a class="btn <?php echo $page === $currentPage ? 'btn-primary' : 'btn-outline'; ?>" href="<?php echo htmlspecialchars($pageUrl($page)); ?>"><?php echo $page; ?></a>
            <?php endfor; ?>
            <a class="btn btn-outline<?php echo $currentPage >= $totalPages ? ' is-disabled' : ''; ?>" href="<?php echo htmlspecialchars($pageUrl($currentPage + 1)); ?>">Sau <i class='bx bx-chevron-right'></i></a>
        </nav>
    <?php endif; ?>
</div>

<style>
.approval-toolbar{display:flex;align-items:center;justify-content:space-between;gap:18px;margin:0 0 22px;padding:16px 18px;border:1px solid var(--border-color);border-radius:10px;background:rgba(var(--primary-rgb),.06)}
.approval-toolbar>div{display:grid;gap:4px}.approval-toolbar strong{display:flex;align-items:center;gap:8px;font-size:17px}.approval-toolbar strong i{color:var(--primary);font-size:22px}.approval-toolbar small{color:var(--text-muted)}
.approval-count{display:inline-grid;place-items:center;min-width:23px;height:23px;margin-left:3px;padding:0 6px;border-radius:999px;background:#fff;color:var(--primary);font-size:12px;font-weight:800}
.approval-dialog{width:min(680px,calc(100vw - 28px));max-height:min(680px,calc(100vh - 40px));padding:0;border:1px solid var(--border-color);border-radius:12px;background:var(--sidebar-bg);color:var(--text-main);box-shadow:0 24px 70px rgba(0,0,0,.42);overflow:hidden}
.approval-dialog::backdrop{background:rgba(2,6,23,.7)}
.approval-dialog-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:20px 22px;border-bottom:1px solid var(--border-color)}
.approval-dialog-head h2{display:flex;align-items:center;gap:9px;margin:0 0 4px;font-size:21px}.approval-dialog-head h2 i{color:var(--primary)}.approval-dialog-head p{margin:0;color:var(--text-muted);font-size:14px}
.approval-close{display:grid;place-items:center;width:36px;height:36px;border:1px solid var(--border-color);border-radius:8px;background:transparent;color:var(--text-main);font-size:24px;cursor:pointer}.approval-close:hover{color:var(--primary);border-color:var(--primary)}
.approval-list{display:grid;gap:10px;max-height:520px;padding:18px 22px 22px;overflow:auto}
.approval-request{display:grid;grid-template-columns:44px minmax(0,1fr) auto;align-items:center;gap:13px;padding:14px;border:1px solid var(--border-color);border-radius:9px;background:rgba(255,255,255,.025)}
.approval-avatar{display:grid;place-items:center;width:44px;height:44px;border-radius:9px;background:rgba(var(--primary-rgb),.12);color:var(--primary);font-size:24px}.approval-person{display:grid;gap:2px;min-width:0}.approval-person strong,.approval-person span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.approval-person span,.approval-person small{color:var(--text-muted)}.approval-person small{margin-top:3px}
.approval-request form{margin:0}.approval-empty{display:grid;justify-items:center;gap:6px;padding:38px 20px;text-align:center;color:var(--text-muted)}.approval-empty i{color:var(--success);font-size:42px}.approval-empty strong{color:var(--text-main)}
.account-pagination{display:flex;justify-content:center;align-items:center;gap:8px;flex-wrap:wrap;margin-top:20px}.account-pagination .is-disabled{pointer-events:none;opacity:.45}
@media(max-width:650px){.approval-toolbar{align-items:stretch;flex-direction:column}.approval-toolbar .btn{width:100%}.approval-request{grid-template-columns:40px minmax(0,1fr)}.approval-request form{grid-column:1/-1}.approval-request form .btn{width:100%}}
</style>
<script>
(() => {
    const dialog = document.getElementById('approval-dialog');
    const openButton = document.getElementById('open-approval-dialog');
    const closeButton = document.getElementById('close-approval-dialog');
    openButton?.addEventListener('click', () => dialog?.showModal());
    closeButton?.addEventListener('click', () => dialog?.close());
    dialog?.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
    });
})();
</script>

<?php require_once '../includes/footer.php'; ?>
