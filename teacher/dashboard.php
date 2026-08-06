<?php
require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['teacher', 'admin'])) {
    header('Location: ../index.php');
    exit;
}

$teacher_id = $_SESSION['user_id'];

// Một truy vấn thay cho bốn lượt đếm riêng.
$statsStmt = $pdo->prepare("SELECT
    (SELECT COUNT(*) FROM courses WHERE teacher_id=?) AS courses,
    COUNT(DISTINCT a.id) AS assignments,
    COUNT(s.id) AS submissions,
    COALESCE(SUM(s.grading_status='review_required'),0) AS review_required
    FROM assignments a
    LEFT JOIN submissions s ON s.assignment_id=a.id
    WHERE a.teacher_id=?");
$statsStmt->execute([$teacher_id, $teacher_id]);
$stats = $statsStmt->fetch();

// Lấy số lượng bài nộp trên từng bài tập gần đây (tối đa 5 bài tập)
$recent_assignments = $pdo->prepare("
    SELECT a.title, COUNT(s.id) AS sub_count
    FROM assignments a
    LEFT JOIN submissions s ON s.assignment_id = a.id
    WHERE a.teacher_id = ?
    GROUP BY a.id, a.title, a.created_at
    ORDER BY a.created_at DESC
    LIMIT 5
");
$recent_assignments->execute([$teacher_id]);
$assignment_data = $recent_assignments->fetchAll();

$assign_labels = [];
$sub_data = [];
foreach ($assignment_data as $row) {
    // Rút gọn tên bài tập nếu quá dài
    $short_title = mb_strlen($row['title']) > 20 ? mb_substr($row['title'], 0, 20) . '...' : $row['title'];
    $assign_labels[] = $short_title;
    $sub_data[] = $row['sub_count'];
}

$page_title = "Thống kê Tổng quan (Giáo viên)";
require_once '../includes/header.php';
?>

<style>
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 40px; }
    .stat-card { background: var(--glass-bg); border: 1px solid rgba(255,255,255,0.05); border-radius: 16px; padding: 25px; text-align: center; transition: 0.3s; }
    .stat-card:hover { transform: translateY(-5px); border-color: var(--primary); }
    .stat-icon { font-size: 40px; color: var(--primary); margin-bottom: 15px; }
    .stat-number { font-size: 36px; font-weight: 700; color: var(--text-main); margin: 0 0 5px 0; }
    .stat-label { color: var(--text-muted); font-size: 14px; text-transform: uppercase; letter-spacing: 1px; }
    
    .charts-grid { display: grid; grid-template-columns: 1fr; gap: 30px; }
    .chart-container { background: var(--glass-bg); border: 1px solid rgba(255,255,255,0.05); border-radius: 16px; padding: 25px; }
    .chart-container h3 { margin-top: 0; margin-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 10px; }
</style>

<div class="stats-grid">
    <div class="stat-card">
        <i class='bx bx-book-open stat-icon'></i>
        <h3 class="stat-number"><?php echo $stats['courses']; ?></h3>
        <div class="stat-label">Khóa học phụ trách</div>
    </div>
    <div class="stat-card">
        <i class='bx bx-book-content stat-icon'></i>
        <h3 class="stat-number"><?php echo $stats['assignments']; ?></h3>
        <div class="stat-label">Bài tập đã giao</div>
    </div>
    <div class="stat-card">
        <i class='bx bx-check-square stat-icon'></i>
        <h3 class="stat-number"><?php echo $stats['submissions']; ?></h3>
        <div class="stat-label">Bài nộp đã nhận</div>
    </div>
    <div class="stat-card">
        <i class='bx bx-user-check stat-icon'></i>
        <h3 class="stat-number"><?php echo $stats['review_required']; ?></h3>
        <div class="stat-label">Bài cần kiểm tra</div>
    </div>
</div>

<div class="charts-grid">
    <div class="chart-container">
        <h3>Tiến độ nộp bài (5 bài tập gần nhất)</h3>
        <?php if (count($assign_labels) > 0): ?>
            <canvas id="submissionChart" style="max-height: 400px;"></canvas>
        <?php else: ?>
            <div class="empty-state">Chưa có dữ liệu bài tập nào.</div>
        <?php endif; ?>
    </div>
</div>

<script src="../assets/js/native-charts.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    <?php if (count($assign_labels) > 0): ?>
    NativeCharts.bar(
        document.getElementById('submissionChart'),
        <?php echo json_encode($assign_labels); ?>,
        <?php echo json_encode($sub_data); ?>,
        { color: '#10b981', textColor: '#94a3b8', gridColor: 'rgba(255,255,255,.1)', height: 360 }
    );
    <?php endif; ?>
});
</script>

<?php require_once '../includes/footer.php'; ?>
