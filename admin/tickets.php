<?php
declare(strict_types=1);
require_once '../includes/security.php';
secureSessionStart();
requireRole(['admin', 'teacher']);
require_once '../config/database.php';
global $pdo;

$statusFilter = $_GET['status'] ?? '';
$where = "1=1";
$params = [];
if ($statusFilter === 'open') { $where .= " AND t.status != 'closed'"; }
elseif ($statusFilter === 'closed') { $where .= " AND t.status = 'closed'"; }

$search = trim((string)($_GET['q'] ?? ''));
if ($search !== '') {
    $where .= " AND (u.name LIKE ? OR t.subject LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%";
}

$stmt = $pdo->prepare("SELECT t.*, u.name as student_name, u.email as student_email 
                       FROM support_tickets t 
                       JOIN users u ON u.id = t.student_id 
                       WHERE $where 
                       ORDER BY CASE t.status WHEN 'open' THEN 1 WHEN 'answered' THEN 2 ELSE 3 END, t.updated_at DESC");
$stmt->execute($params);
$tickets = $stmt->fetchAll();

$page_title = 'Quản lý Yêu cầu hỗ trợ';
require_once '../includes/header.php';
?>
<style>
.ticket-list { margin-top: 24px; display: grid; gap: 16px; }
.ticket-card { background: var(--glass-bg); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 20px; transition: 0.3s; display: flex; align-items: center; justify-content: space-between; gap: 20px; cursor: pointer; text-decoration: none; color: inherit; }
.ticket-card:hover { transform: translateY(-3px); border-color: var(--primary); box-shadow: 0 8px 24px rgba(0,0,0,0.2); }
.t-info { flex: 1; min-width: 0; }
.t-title { font-size: 16px; font-weight: 700; color: var(--text-main); margin: 0 0 8px 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.t-meta { font-size: 13px; color: var(--text-muted); display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
.badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
.badge.b-open { background: rgba(245,158,11,0.15); color: #fcd34d; }
.badge.b-answered { background: rgba(16,185,129,0.15); color: #6ee7b7; }
.badge.b-closed { background: rgba(148,163,184,0.15); color: #94a3b8; }
.cat { background: rgba(255,255,255,0.1); padding: 2px 8px; border-radius: 4px; }
.filter-bar { display:flex; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
.filter-bar input { padding:10px 14px; background:rgba(0,0,0,0.2); border:1px solid var(--border-color); border-radius:8px; color:white; min-width:250px; }
.filter-bar input:focus { outline:none; border-color:var(--primary); }
</style>

<div>
    <h1><i class='bx bx-support'></i> Quản lý Yêu cầu hỗ trợ (Tickets)</h1>
</div>

<form class="filter-bar" method="get">
    <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Tìm tên học viên, tiêu đề...">
    <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
    <button class="btn btn-primary">Tìm kiếm</button>
</form>

<div class="tabs" style="display:flex; gap:12px; margin-bottom:20px;">
    <a href="tickets.php?q=<?php echo urlencode($search); ?>" class="btn <?php echo $statusFilter === '' ? 'btn-primary' : 'btn-outline'; ?>">Tất cả</a>
    <a href="?status=open&q=<?php echo urlencode($search); ?>" class="btn <?php echo $statusFilter === 'open' ? 'btn-primary' : 'btn-outline'; ?>">Đang mở</a>
    <a href="?status=closed&q=<?php echo urlencode($search); ?>" class="btn <?php echo $statusFilter === 'closed' ? 'btn-primary' : 'btn-outline'; ?>">Đã đóng</a>
</div>

<div class="ticket-list">
    <?php foreach ($tickets as $t): 
        $st = match($t['status']) { 'open'=>'b-open', 'answered'=>'b-answered', 'closed'=>'b-closed', default=>'' };
        $stLabel = match($t['status']) { 'open'=>'Cần trả lời', 'answered'=>'Đã trả lời', 'closed'=>'Đã đóng', default=>'' };
        $catLabel = match($t['category']) { 'tech'=>'Lỗi kỹ thuật', 'account'=>'Tài khoản', 'course'=>'Bài học', default=>'Khác' };
    ?>
    <a href="ticket_detail.php?id=<?php echo $t['id']; ?>" class="ticket-card">
        <div class="t-info">
            <h3 class="t-title">#<?php echo $t['id']; ?> - <?php echo htmlspecialchars($t['subject']); ?></h3>
            <div class="t-meta">
                <span style="color:var(--primary); font-weight:600;"><i class='bx bx-user'></i> <?php echo htmlspecialchars($t['student_name']); ?></span>
                <span class="cat"><?php echo $catLabel; ?></span>
                <span><i class='bx bx-time'></i> <?php echo date('H:i d/m/Y', strtotime($t['updated_at'])); ?></span>
            </div>
        </div>
        <div>
            <span class="badge <?php echo $st; ?>"><?php echo $stLabel; ?></span>
        </div>
    </a>
    <?php endforeach; ?>
    <?php if (!$tickets): ?>
        <div style="text-align:center; padding:40px; color:var(--text-muted); background:var(--glass-bg); border-radius:12px;">Không tìm thấy yêu cầu hỗ trợ nào.</div>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>