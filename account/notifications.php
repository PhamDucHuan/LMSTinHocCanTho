<?php
declare(strict_types=1);

require_once '../includes/security.php';
secureSessionStart();
requireRole(['student', 'teacher', 'admin']);
require_once '../config/database.php';
require_once '../includes/notifications.php';

global $pdo;
if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException('Database connection is not available.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    if (($_POST['action'] ?? '') === 'mark_all_read') {
        $stmt = $pdo->prepare('UPDATE notifications SET read_at = COALESCE(read_at, NOW()) WHERE user_id = ?');
        $stmt->execute([(int) $_SESSION['user_id']]);
    } elseif (($_POST['action'] ?? '') === 'mark_read') {
        $notificationId = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT);
        if ($notificationId) {
            $stmt = $pdo->prepare(
                'UPDATE notifications SET read_at = COALESCE(read_at, NOW()) WHERE id = ? AND user_id = ?'
            );
            $stmt->execute([(int) $notificationId, (int) $_SESSION['user_id']]);
        }
    }
    header('Location: notifications.php');
    exit;
}

$stmt = $pdo->prepare(
    'SELECT id, type, title, message, link, read_at, created_at
     FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100'
);
$stmt->execute([(int) $_SESSION['user_id']]);
$notifications = $stmt->fetchAll();

$unreadStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
$unreadStmt->execute([(int) $_SESSION['user_id']]);
$unreadNotifications = (int) $unreadStmt->fetchColumn();

$page_title = 'Thông báo';
require_once '../includes/header.php';
?>
<div class="box">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:15px;flex-wrap:wrap;margin-bottom:20px;">
        <div>
            <h2 style="margin:0;"><i class='bx bx-bell'></i> Thông báo</h2>
            <p style="color:var(--text-muted);margin:6px 0 0;">Các cập nhật về khóa học, bài làm và điểm số.</p>
        </div>
        <?php if ($unreadNotifications > 0): ?>
            <form method="post">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="mark_all_read">
                <button class="btn btn-outline" type="submit"><i class='bx bx-check-double'></i> Đánh dấu đã đọc</button>
            </form>
        <?php endif; ?>
    </div>

    <div style="display:grid;gap:12px;">
        <?php foreach ($notifications as $notification): ?>
            <article style="padding:16px;border:1px solid var(--border-color);border-radius:12px;background:<?php echo empty($notification['read_at']) ? 'rgba(var(--primary-rgb),.10)' : 'var(--glass-bg)'; ?>;">
                <div style="display:flex;justify-content:space-between;gap:15px;">
                    <div>
                        <strong><?php echo htmlspecialchars($notification['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        <?php if ($notification['message']): ?>
                            <p style="margin:6px 0;color:var(--text-muted);"><?php echo nl2br(htmlspecialchars($notification['message'], ENT_QUOTES, 'UTF-8')); ?></p>
                        <?php endif; ?>
                        <small style="color:var(--text-muted);"><?php echo date('d/m/Y H:i', strtotime($notification['created_at'])); ?></small>
                    </div>
                    <?php if (empty($notification['read_at'])): ?>
                        <form method="post">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="mark_read">
                            <input type="hidden" name="notification_id" value="<?php echo (int) $notification['id']; ?>">
                            <button class="btn btn-outline" type="submit" title="Đánh dấu đã đọc"><i class='bx bx-check'></i></button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php if ($notification['link']): ?>
                    <a class="btn btn-primary" style="margin-top:12px;display:inline-flex;" href="<?php echo htmlspecialchars($notification['link'], ENT_QUOTES, 'UTF-8'); ?>">Xem chi tiết</a>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if (!$notifications): ?>
            <div class="empty-state"><i class='bx bx-bell-off'></i><p>Bạn chưa có thông báo nào.</p></div>
        <?php endif; ?>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>
