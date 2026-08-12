<?php
declare(strict_types=1);
require_once '../includes/security.php';
secureSessionStart();
requireRole(['student']);
require_once '../config/database.php';
global $pdo;

$studentId = (int)$_SESSION['user_id'];

// Create new ticket
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    verifyCsrfToken();
    $subject = trim((string)$_POST['subject']);
    $category = trim((string)$_POST['category']);
    $message = trim((string)$_POST['message']);

    if ($subject === '' || $message === '') {
        $_SESSION['error'] = 'Vui lòng nhập đầy đủ Tiêu đề và Nội dung.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO support_tickets (student_id, subject, category, status) VALUES (?, ?, ?, "open")');
            $stmt->execute([$studentId, $subject, $category]);
            $ticketId = (int)$pdo->lastInsertId();

            $replyStmt = $pdo->prepare('INSERT INTO ticket_replies (ticket_id, user_id, message) VALUES (?, ?, ?)');
            $replyStmt->execute([$ticketId, $studentId, $message]);

            // Gửi thông báo cho toàn bộ Admin
            $adminStmt = $pdo->query('SELECT id FROM users WHERE role="admin"');
            $admins = $adminStmt->fetchAll(PDO::FETCH_COLUMN);
            require_once '../includes/notifications.php';
            foreach ($admins as $adminId) {
                createNotification($pdo, (int)$adminId, 'system', 'Ticket mới từ Học viên',
                    "Học viên {$_SESSION['user_name']} vừa gửi một yêu cầu hỗ trợ mới.",
                    "../admin/ticket_detail.php?id=$ticketId");
            }
            $pdo->commit();
            $_SESSION['success'] = 'Gửi yêu cầu hỗ trợ thành công. Ban quản trị sẽ phản hồi sớm nhất có thể.';
            header('Location: tickets.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Lỗi hệ thống: ' . $e->getMessage();
        }
    }
}

$statusFilter = $_GET['status'] ?? '';
$where = "student_id = $studentId";
if ($statusFilter === 'open') { $where .= " AND status != 'closed'"; }
elseif ($statusFilter === 'closed') { $where .= " AND status = 'closed'"; }

$stmt = $pdo->query("SELECT * FROM support_tickets WHERE $where ORDER BY updated_at DESC");
$tickets = $stmt->fetchAll();

$page_title = 'Hỗ trợ kỹ thuật & Hỏi đáp';
require_once '../includes/header.php';
?>
<style>
.ticket-list { margin-top: 24px; display: grid; gap: 16px; }
.ticket-card { background: var(--glass-bg); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 20px; transition: 0.3s; display: flex; align-items: center; justify-content: space-between; gap: 20px; cursor: pointer; text-decoration: none; color: inherit; }
.ticket-card:hover { transform: translateY(-3px); border-color: var(--primary); box-shadow: 0 8px 24px rgba(0,0,0,0.2); }
.t-info { flex: 1; min-width: 0; }
.t-title { font-size: 16px; font-weight: 700; color: var(--text-main); margin: 0 0 8px 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.t-meta { font-size: 13px; color: var(--text-muted); display: flex; gap: 16px; align-items: center; }
.badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
.badge.b-open { background: rgba(99,102,241,0.15); color: #818cf8; }
.badge.b-answered { background: rgba(16,185,129,0.15); color: #6ee7b7; }
.badge.b-closed { background: rgba(148,163,184,0.15); color: #94a3b8; }
.cat { background: rgba(255,255,255,0.1); padding: 2px 8px; border-radius: 4px; }
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 1000; display: none; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
.modal-box { background: var(--bg-dark); border: 1px solid var(--border-color); border-radius: 16px; width: 100%; max-width: 500px; padding: 24px; box-shadow: 0 20px 40px rgba(0,0,0,0.4); }
.modal-overlay.active { display: flex; }
.modal-title { font-size: 18px; font-weight: 700; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
.form-group { margin-bottom: 16px; }
.form-label { display: block; margin-bottom: 8px; font-size: 13px; font-weight: 600; color: var(--text-muted); }
.form-control { width: 100%; padding: 12px 16px; background: rgba(0,0,0,0.2); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-main); font-family: inherit; font-size: 14px; }
.form-control:focus { outline: none; border-color: var(--primary); }
</style>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
    <h1><i class='bx bx-support'></i> Hỗ trợ & Hỏi đáp</h1>
    <button class="btn btn-primary" onclick="document.getElementById('newTicketModal').classList.add('active')">
        <i class='bx bx-plus'></i> Tạo yêu cầu mới
    </button>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success" style="background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);border-radius:10px;padding:12px 16px;color:#6ee7b7;margin-bottom:18px"><i class='bx bx-check-circle'></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert" style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);border-radius:10px;padding:12px 16px;color:#fca5a5;margin-bottom:18px"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>

<div class="tabs" style="display:flex; gap:12px; margin-bottom:20px;">
    <a href="tickets.php" class="btn <?php echo $statusFilter === '' ? 'btn-primary' : 'btn-outline'; ?>">Tất cả</a>
    <a href="?status=open" class="btn <?php echo $statusFilter === 'open' ? 'btn-primary' : 'btn-outline'; ?>">Đang mở</a>
    <a href="?status=closed" class="btn <?php echo $statusFilter === 'closed' ? 'btn-primary' : 'btn-outline'; ?>">Đã đóng</a>
</div>

<div class="ticket-list">
    <?php foreach ($tickets as $t): 
        $st = match($t['status']) { 'open'=>'b-open', 'answered'=>'b-answered', 'closed'=>'b-closed', default=>'' };
        $stLabel = match($t['status']) { 'open'=>'Chờ xử lý', 'answered'=>'Đã trả lời', 'closed'=>'Đã đóng', default=>'' };
        $catLabel = match($t['category']) { 'tech'=>'Lỗi kỹ thuật', 'account'=>'Tài khoản', 'course'=>'Bài học', default=>'Khác' };
    ?>
    <a href="ticket_detail.php?id=<?php echo $t['id']; ?>" class="ticket-card">
        <div class="t-info">
            <h3 class="t-title"><?php echo htmlspecialchars($t['subject']); ?></h3>
            <div class="t-meta">
                <span class="cat"><?php echo $catLabel; ?></span>
                <span>Cập nhật: <?php echo date('H:i d/m/Y', strtotime($t['updated_at'])); ?></span>
            </div>
        </div>
        <div>
            <span class="badge <?php echo $st; ?>"><?php echo $stLabel; ?></span>
        </div>
    </a>
    <?php endforeach; ?>
    <?php if (!$tickets): ?>
        <div style="text-align:center; padding:40px; color:var(--text-muted); background:var(--glass-bg); border-radius:12px;">Bạn chưa có yêu cầu hỗ trợ nào.</div>
    <?php endif; ?>
</div>

<div class="modal-overlay" id="newTicketModal">
    <div class="modal-box">
        <div class="modal-title">
            <span>Tạo yêu cầu mới</span>
            <button onclick="document.getElementById('newTicketModal').classList.remove('active')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:24px">&times;</button>
        </div>
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label class="form-label">Chủ đề</label>
                <select name="category" class="form-control" required>
                    <option value="tech">Lỗi hệ thống / Kỹ thuật</option>
                    <option value="account">Vấn đề tài khoản</option>
                    <option value="course">Thắc mắc bài giảng / Điểm</option>
                    <option value="other">Khác</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Tiêu đề</label>
                <input type="text" name="subject" class="form-control" required placeholder="Tóm tắt vấn đề của bạn...">
            </div>
            <div class="form-group">
                <label class="form-label">Chi tiết</label>
                <textarea name="message" class="form-control" rows="5" required placeholder="Mô tả chi tiết vấn đề bạn đang gặp phải..."></textarea>
            </div>
            <div style="text-align:right">
                <button type="submit" class="btn btn-primary"><i class='bx bx-send'></i> Gửi yêu cầu</button>
            </div>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>