<?php
declare(strict_types=1);
require_once '../includes/security.php';
secureSessionStart();
requireRole(['admin', 'teacher', 'administrative_staff']);
require_once '../config/database.php';
global $pdo;

$ticketId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['user_role'];

if (!$ticketId) {
    header('Location: tickets.php');
    exit;
}

$stmt = $pdo->prepare("SELECT t.*, u.name as student_name, u.email as student_email FROM support_tickets t JOIN users u ON u.id = t.student_id WHERE t.id = ?");
$stmt->execute([$ticketId]);
$ticket = $stmt->fetch();
if (!$ticket) {
    header('Location: tickets.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'close') {
        $pdo->prepare("UPDATE support_tickets SET status = 'closed' WHERE id = ?")->execute([$ticketId]);
        header("Location: ticket_detail.php?id=$ticketId");
        exit;
    } elseif ($action === 'reply' && $ticket['status'] !== 'closed') {
        $msg = trim((string)$_POST['message']);
        if ($msg !== '') {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO ticket_replies (ticket_id, user_id, message) VALUES (?, ?, ?)')
                ->execute([$ticketId, $userId, $msg]);
            
            $pdo->prepare("UPDATE support_tickets SET status = 'answered' WHERE id = ?")
                ->execute([$ticketId]);
            
            require_once '../includes/notifications.php';
            $sender = $userRole === 'admin' ? 'Admin' : 'Giáo viên';
            createNotification($pdo, (int)$ticket['student_id'], 'system', 'Phản hồi Ticket mới',
                "{$sender} vừa trả lời yêu cầu hỗ trợ #{$ticketId} của bạn.",
                "../student/ticket_detail.php?id=$ticketId");
            
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

$stLabel = match($ticket['status']) { 'open'=>'Đang mở', 'answered'=>'Đã trả lời', 'closed'=>'Đã đóng', default=>'' };
$catLabel = match($ticket['category']) { 'tech'=>'Lỗi kỹ thuật', 'account'=>'Tài khoản', 'course'=>'Bài học', default=>'Khác' };

$page_title = 'Chi tiết Yêu cầu #' . $ticket['id'];
require_once '../includes/header.php';
?>
<style>
.chat-box { background: var(--glass-bg); border: 1px solid rgba(255,255,255,0.05); border-radius: 16px; display: flex; flex-direction: column; overflow: hidden; margin-top: 20px; }
.chat-header { padding: 20px 24px; border-bottom: 1px solid var(--border-color); background: rgba(0,0,0,0.1); display:flex; justify-content:space-between; align-items:center; }
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
    <div style="color:var(--text-muted); margin-bottom:24px; font-size:14px; display:flex; gap:16px; align-items:center;">
        <span>Mã số: <strong>#<?php echo $ticket['id']; ?></strong></span>
        <span>Học viên: <strong style="color:var(--primary)"><?php echo htmlspecialchars($ticket['student_name']); ?></strong> (<?php echo htmlspecialchars($ticket['student_email']); ?>)</span>
        <span>Danh mục: <strong><?php echo $catLabel; ?></strong></span>
        <span>Trạng thái: <strong><?php echo $stLabel; ?></strong></span>
    </div>
</div>

<div class="chat-box">
    <div class="chat-header">
        <h3 style="margin:0; font-size:16px;">Nội dung trao đổi</h3>
        <?php if ($ticket['status'] !== 'closed'): ?>
        <form method="post" style="margin:0" onsubmit="return confirm('Bạn có chắc chắn muốn đóng yêu cầu này?')">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="close">
            <button type="submit" class="btn btn-outline" style="color:#ef4444; border-color:#ef4444;"><i class='bx bx-lock'></i> Đóng Ticket</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="chat-body" id="chatBody">
        <?php foreach ($replies as $r): 
            $isMe = $r['user_id'] === $userId;
            $sender = $isMe ? 'Bạn' : ($r['role'] === 'student' ? 'Học viên' : ($r['role'] === 'admin' ? 'Admin khác' : 'Giáo viên khác'));
            $letter = mb_substr($r['name'], 0, 1);
        ?>
        <div class="msg-wrap <?php echo $isMe ? 'me' : ''; ?>">
            <div class="avatar"><?php echo htmlspecialchars($letter); ?></div>
            <div>
                <div class="msg-bubble"><?php echo nl2br(htmlspecialchars($r['message'])); ?></div>
                <div class="msg-meta"><?php echo $sender; ?> (<?php echo htmlspecialchars($r['name']); ?>) &middot; <?php echo date('H:i d/m/Y', strtotime($r['created_at'])); ?></div>
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
                <button type="submit" class="btn btn-primary"><i class='bx bx-send'></i> Gửi phản hồi</button>
            </div>
        </form>
    </div>
    <?php else: ?>
    <div class="chat-footer" style="text-align:center; color:var(--text-muted);">
        <i class='bx bx-lock'></i> Yêu cầu này đã được đóng.
    </div>
    <?php endif; ?>
</div>

<script>
// Auto scroll to bottom
const cb = document.getElementById('chatBody');
cb.scrollTop = cb.scrollHeight;
</script>

<?php require_once '../includes/footer.php'; ?>
