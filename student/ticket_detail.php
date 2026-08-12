<?php
declare(strict_types=1);
require_once '../includes/security.php';
secureSessionStart();
requireRole(['student']);
require_once '../config/database.php';
global $pdo;

$ticketId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$studentId = (int)$_SESSION['user_id'];

if (!$ticketId) {
    header('Location: tickets.php');
    exit;
}

// Verify ownership
$stmt = $pdo->prepare("SELECT * FROM support_tickets WHERE id = ? AND student_id = ?");
$stmt->execute([$ticketId, $studentId]);
$ticket = $stmt->fetch();
if (!$ticket) {
    header('Location: tickets.php');
    exit;
}

// Handle reply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reply') {
    verifyCsrfToken();
    if ($ticket['status'] === 'closed') {
        $_SESSION['error'] = 'Yêu cầu này đã đóng, không thể trả lời thêm.';
    } else {
        $msg = trim((string)$_POST['message']);
        if ($msg !== '') {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO ticket_replies (ticket_id, user_id, message) VALUES (?, ?, ?)')
                ->execute([$ticketId, $studentId, $msg]);
            
            // Re-open ticket if it was answered
            $pdo->prepare("UPDATE support_tickets SET status = 'open' WHERE id = ?")
                ->execute([$ticketId]);
            
            // Notify admin
            $adminStmt = $pdo->query('SELECT id FROM users WHERE role="admin"');
            $admins = $adminStmt->fetchAll(PDO::FETCH_COLUMN);
            require_once '../includes/notifications.php';
            foreach ($admins as $adminId) {
                createNotification($pdo, (int)$adminId, 'system', 'Phản hồi Ticket mới',
                    "Học viên {$_SESSION['user_name']} vừa phản hồi trong Ticket #{$ticketId}.",
                    "../admin/ticket_detail.php?id=$ticketId");
            }
            $pdo->commit();
            header("Location: ticket_detail.php?id=$ticketId");
            exit;
        }
    }
}

// Fetch replies
$replyStmt = $pdo->prepare("SELECT r.*, u.name, u.role, u.avatar_url FROM ticket_replies r JOIN users u ON u.id = r.user_id WHERE r.ticket_id = ? ORDER BY r.created_at ASC");
$replyStmt->execute([$ticketId]);
$replies = $replyStmt->fetchAll();

$stLabel = match($ticket['status']) { 'open'=>'Chờ xử lý', 'answered'=>'Đã trả lời', 'closed'=>'Đã đóng', default=>'' };
$catLabel = match($ticket['category']) { 'tech'=>'Lỗi kỹ thuật', 'account'=>'Tài khoản', 'course'=>'Bài học', default=>'Khác' };

$page_title = 'Chi tiết Yêu cầu #' . $ticket['id'];
require_once '../includes/header.php';
?>
<style>
.chat-box { background: var(--glass-bg); border: 1px solid rgba(255,255,255,0.05); border-radius: 16px; display: flex; flex-direction: column; overflow: hidden; margin-top: 20px; }
.chat-header { padding: 20px 24px; border-bottom: 1px solid var(--border-color); background: rgba(0,0,0,0.1); }
.chat-body { padding: 24px; display: flex; flex-direction: column; gap: 20px; max-height: 60vh; overflow-y: auto; }
.msg-wrap { display: flex; gap: 12px; max-width: 85%; }
.msg-wrap.me { align-self: flex-end; flex-direction: row-reverse; }
.avatar { width: 36px; height: 36px; border-radius: 50%; background: var(--primary); color: white; display: grid; place-items: center; font-weight: 700; flex-shrink: 0; }
.msg-bubble { padding: 14px 18px; border-radius: 16px; background: rgba(255,255,255,0.05); color: var(--text-main); font-size: 14px; line-height: 1.5; position: relative; }
.msg-wrap.me .msg-bubble { background: var(--primary); color: #fff; }
.msg-meta { font-size: 11px; color: var(--text-muted); margin-top: 6px; }
.msg-wrap.me .msg-meta { text-align: right; }
.chat-footer { padding: 20px 24px; border-top: 1px solid var(--border-color); background: rgba(0,0,0,0.1); }
.form-control { width: 100%; padding: 14px; background: rgba(0,0,0,0.2); border: 1px solid var(--border-color); border-radius: 12px; color: var(--text-main); font-family: inherit; font-size: 14px; resize: vertical; margin-bottom: 12px; }
.form-control:focus { outline: none; border-color: var(--primary); }
</style>

<div>
    <a href="tickets.php" class="btn btn-outline" style="margin-bottom:20px"><i class='bx bx-arrow-back'></i> Quay lại</a>
    <h1><?php echo htmlspecialchars($ticket['subject']); ?></h1>
    <div style="color:var(--text-muted); margin-bottom:24px; font-size:14px;">
        Mã số: <strong>#<?php echo $ticket['id']; ?></strong> &middot; 
        Danh mục: <strong><?php echo $catLabel; ?></strong> &middot; 
        Trạng thái: <strong><?php echo $stLabel; ?></strong>
    </div>
</div>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert" style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);border-radius:10px;padding:12px 16px;color:#fca5a5;margin-bottom:18px"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>

<div class="chat-box">
    <div class="chat-body" id="chatBody">
        <?php foreach ($replies as $r): 
            $isMe = $r['user_id'] === $studentId;
            $sender = $isMe ? 'Bạn' : ($r['role'] === 'admin' ? 'Admin' : 'Giáo viên');
            $letter = mb_substr($r['name'], 0, 1);
        ?>
        <div class="msg-wrap <?php echo $isMe ? 'me' : ''; ?>">
            <div class="avatar"><?php echo htmlspecialchars($letter); ?></div>
            <div>
                <div class="msg-bubble"><?php echo nl2br(htmlspecialchars($r['message'])); ?></div>
                <div class="msg-meta"><?php echo $sender; ?> &middot; <?php echo date('H:i d/m/Y', strtotime($r['created_at'])); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <?php if ($ticket['status'] !== 'closed'): ?>
    <div class="chat-footer">
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="reply">
            <textarea name="message" class="form-control" rows="3" required placeholder="Nhập phản hồi của bạn..."></textarea>
            <div style="text-align:right">
                <button type="submit" class="btn btn-primary"><i class='bx bx-send'></i> Gửi tin nhắn</button>
            </div>
        </form>
    </div>
    <?php else: ?>
    <div class="chat-footer" style="text-align:center; color:var(--text-muted);">
        <i class='bx bx-lock'></i> Yêu cầu này đã được đóng. Bạn không thể trả lời thêm.
    </div>
    <?php endif; ?>
</div>

<script>
// Auto scroll to bottom
const cb = document.getElementById('chatBody');
cb.scrollTop = cb.scrollHeight;
</script>

<?php require_once '../includes/footer.php'; ?>