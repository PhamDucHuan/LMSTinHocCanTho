<?php
require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

// Check database connection
if (!isset($pdo) || $pdo === null) {
    die('Database connection failed');
}

// Số liệu tổng quan
$stats = $pdo->query("SELECT
    (SELECT COUNT(*) FROM users WHERE role='student') AS students,
    (SELECT COUNT(*) FROM users WHERE role='teacher') AS teachers,
    (SELECT COUNT(*) FROM courses) AS courses,
    (SELECT COUNT(*) FROM assignments) AS assignments,
    (SELECT COUNT(*) FROM submissions) AS submissions,
    (SELECT COUNT(*) FROM submissions WHERE grading_status='review_required') AS review_required,
    (SELECT COUNT(*) FROM grading_jobs WHERE status IN ('queued','processing')) AS ai_waiting,
    (SELECT COUNT(*) FROM grading_jobs WHERE status='failed') AS ai_failed,
    (SELECT COALESCE(ROUND(AVG(TIMESTAMPDIFF(SECOND,started_at,completed_at))),0)
       FROM grading_jobs WHERE status='completed' AND started_at IS NOT NULL AND completed_at IS NOT NULL) AS ai_avg_seconds,
    (SELECT COUNT(*) FROM submissions WHERE score IS NOT NULL AND score >= 5) AS passed,
    (SELECT COUNT(*) FROM submissions WHERE score IS NOT NULL) AS graded,
    (SELECT COALESCE(ROUND(AVG(score),1),0) FROM submissions WHERE score IS NOT NULL) AS avg_score,
    (SELECT COUNT(DISTINCT student_id) FROM submissions WHERE submitted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS active_this_week,
    (SELECT COALESCE(SUM(CASE WHEN online_status = 1 AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 3 MINUTE) THEN 1 ELSE 0 END), 0) FROM users) AS online_users
")->fetch();

// Biểu đồ bài nộp 30 ngày
$trend = $pdo->query("
    SELECT DATE(submitted_at) AS day, COUNT(*) AS total
    FROM submissions
    WHERE submitted_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY DATE(submitted_at)
    ORDER BY day
")->fetchAll();
$trendLabels = [];
$trendData = [];
$trendMap = [];
foreach ($trend as $t) { $trendMap[$t['day']] = (int)$t['total']; }
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $trendLabels[] = date('d/m', strtotime($d));
    $trendData[] = $trendMap[$d] ?? 0;
}

// Lấy danh sách khóa học có nhiều bài tập nhất
$top_courses = $pdo->query("
    SELECT c.title, COUNT(a.id) as total_assignments 
    FROM courses c 
    LEFT JOIN assignments a ON c.id = a.course_id 
    GROUP BY c.id 
    ORDER BY total_assignments DESC 
    LIMIT 5
")->fetchAll();

$course_labels = array_column($top_courses, 'title');
$course_data   = array_column($top_courses, 'total_assignments');

// Top 5 học viên điểm cao nhất
$top_students = $pdo->query("
    SELECT u.name, u.email, ROUND(AVG(s.score),1) AS avg_score, COUNT(s.id) AS num_submissions
    FROM submissions s JOIN users u ON u.id = s.student_id
    WHERE s.score IS NOT NULL
    GROUP BY s.student_id ORDER BY avg_score DESC LIMIT 5
")->fetchAll();

$recent_login_logs = $pdo->query(
    "SELECT al.action, al.context_json, al.ip_address, al.created_at, u.name, u.email
     FROM audit_logs al
     LEFT JOIN users u ON u.id = al.user_id
     WHERE al.action LIKE 'login%'
     ORDER BY al.id DESC
     LIMIT 8"
)->fetchAll();

// Tỉ lệ đậu
$pass_rate = ($stats['graded'] > 0) ? round($stats['passed'] / $stats['graded'] * 100) : 0;

$page_title = "Thống kê Tổng quan (Admin)";
require_once '../includes/header.php';
?>

<style>
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 18px; margin-bottom: 30px; }
    .stat-card { background: var(--glass-bg); border: 1px solid rgba(255,255,255,0.05); border-radius: 16px; padding: 22px; text-align: center; transition: 0.3s; cursor:default; }
    .stat-card:hover { transform: translateY(-4px); border-color: var(--primary); box-shadow: 0 8px 24px rgba(0,0,0,.25); }
    .stat-icon { font-size: 36px; color: var(--primary); margin-bottom: 12px; }
    .stat-number { font-size: 32px; font-weight: 700; color: var(--text-main); margin: 0 0 4px 0; }
    .stat-label { color: var(--text-muted); font-size: 12px; text-transform: uppercase; letter-spacing: 1px; }
    .stat-card.highlight { border-color: rgba(99,102,241,.35); background: rgba(99,102,241,.08); }
    .charts-grid { display:grid; grid-template-columns:minmax(280px,.7fr) minmax(0,1.8fr); gap:22px; align-items:start; margin-bottom:24px; }
    .chart-container { min-width:0; background:var(--glass-bg); border:1px solid rgba(255,255,255,0.05); border-radius:16px; padding:22px; overflow:hidden; }
    .chart-container h3 { margin:0 0 18px; border-bottom:1px solid rgba(255,255,255,0.08); padding-bottom:12px; font-size:15px; }
    .chart-canvas-wrap { position:relative; width:100%; height:280px; }
    .chart-canvas-wrap canvas { display:block; width:100% !important; height:100% !important; max-width:100%; }
    .bottom-grid { display:grid; grid-template-columns:1fr 1fr; gap:22px; }
    .dash-table { width:100%; border-collapse:collapse; }
    .dash-table th,.dash-table td { padding:10px 12px; border-bottom:1px solid var(--border-color); text-align:left; font-size:13px; }
    .dash-table th { color:var(--text-muted); font-size:11px; text-transform:uppercase; letter-spacing:.06em; }
    .score-badge { font-weight:700; color:var(--primary); }
    .badge-success,.badge-fail{display:inline-block;padding:3px 8px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap}
    .badge-success{background:rgba(16,185,129,.15);color:#6ee7b7}.badge-fail{background:rgba(239,68,68,.15);color:#fca5a5}
    @media (max-width:1100px) { .charts-grid,.bottom-grid { grid-template-columns:1fr; } }
    @media (max-width:650px) { .chart-container { padding:14px; } .chart-canvas-wrap { height:220px; } }
</style>

<div class="stats-grid">
    <div class="stat-card"><i class='bx bx-user stat-icon'></i><h3 class="stat-number"><?php echo number_format($stats['students']); ?></h3><div class="stat-label">Học viên</div></div>
    <div class="stat-card"><i class='bx bx-user-pin stat-icon'></i><h3 class="stat-number"><?php echo number_format($stats['teachers']); ?></h3><div class="stat-label">Giáo viên</div></div>
    <div class="stat-card"><i class='bx bx-book-open stat-icon'></i><h3 class="stat-number"><?php echo number_format($stats['courses']); ?></h3><div class="stat-label">Khóa học</div></div>
    <div class="stat-card"><i class='bx bx-check-square stat-icon'></i><h3 class="stat-number"><?php echo number_format($stats['submissions']); ?></h3><div class="stat-label">Bài nộp</div></div>
    <div class="stat-card highlight"><i class='bx bx-trending-up stat-icon'></i><h3 class="stat-number"><?php echo $pass_rate; ?>%</h3><div class="stat-label">Tỉ lệ đậu (≥5đ)</div></div>
    <div class="stat-card highlight"><i class='bx bx-star stat-icon'></i><h3 class="stat-number"><?php echo $stats['avg_score']; ?></h3><div class="stat-label">Điểm TB toàn hệ</div></div>
    <div class="stat-card highlight"><i class='bx bx-run stat-icon'></i><h3 class="stat-number"><?php echo number_format($stats['active_this_week']); ?></h3><div class="stat-label">Học viên TG tuần này</div></div>
    <div class="stat-card highlight" title="Tự cập nhật mỗi 30 giây; tài khoản có hoạt động trong 3 phút được tính là Bit 1."><i class='bx bx-radio-circle-marked stat-icon'></i><h3 id="online-users-count" class="stat-number"><?php echo number_format($stats['online_users']); ?></h3><div class="stat-label">Tổng đang hoạt động · Bit 1</div><small id="online-users-note" style="display:block;margin-top:7px;color:var(--success);font-weight:700">Cập nhật tự động</small></div>
    <div class="stat-card"><i class='bx bx-user-check stat-icon'></i><h3 class="stat-number"><?php echo $stats['review_required']; ?></h3><div class="stat-label">Cần GV duyệt</div></div>
    <div class="stat-card"><i class='bx bx-loader-circle stat-icon'></i><h3 class="stat-number"><?php echo $stats['ai_waiting']; ?></h3><div class="stat-label">AI đang chờ/chấm</div></div>
    <div class="stat-card"><i class='bx bx-time-five stat-icon'></i><h3 class="stat-number"><?php echo $stats['ai_avg_seconds']; ?>s</h3><div class="stat-label">Thời gian chấm TB</div></div>
</div>

<div class="charts-grid">
    <div class="chart-container">
        <h3>📊 Tỉ lệ Người dùng</h3>
        <div class="chart-canvas-wrap"><canvas id="userChart"></canvas></div>
    </div>
    <div class="chart-container">
        <h3>📈 Bài nộp 30 ngày qua</h3>
        <div class="chart-canvas-wrap"><canvas id="trendChart"></canvas></div>
    </div>
</div>

<div class="bottom-grid">
    <div class="chart-container">
        <h3>📚 Top Khóa học nhiều Bài tập nhất</h3>
        <div class="chart-canvas-wrap" style="height:220px"><canvas id="courseChart"></canvas></div>
    </div>
    <div class="chart-container">
        <h3>🏆 Top học viên điểm cao nhất</h3>
        <table class="dash-table">
            <thead><tr><th>#</th><th>Học viên</th><th>Điểm TB</th><th>Số bài</th></tr></thead>
            <tbody>
            <?php foreach ($top_students as $i => $s): ?>
            <tr>
                <td><?php echo $i + 1; ?></td>
                <td><strong><?php echo htmlspecialchars($s['name']); ?></strong><small style="display:block;color:var(--text-muted)"><?php echo htmlspecialchars($s['email']); ?></small></td>
                <td><span class="score-badge"><?php echo $s['avg_score']; ?></span></td>
                <td><?php echo $s['num_submissions']; ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$top_students): ?><tr><td colspan="4" style="color:var(--text-muted)">Chưa có dữ liệu.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="chart-container">
        <h3 style="display:flex;align-items:center;justify-content:space-between;gap:12px">
            <span><i class='bx bx-log-in-circle'></i> Đăng nhập gần đây</span>
            <a href="login_logs.php" class="btn btn-outline" style="padding:6px 10px;font-size:12px;text-decoration:none">Xem tất cả</a>
        </h3>
        <table class="dash-table">
            <thead><tr><th>Người dùng</th><th>Trạng thái</th><th>Thời gian</th></tr></thead>
            <tbody>
            <?php foreach ($recent_login_logs as $login):
                $context = json_decode((string)($login['context_json'] ?? ''), true) ?: [];
                $email = (string)($login['email'] ?? $context['email'] ?? '');
                $failed = str_contains((string)$login['action'], 'failed');
            ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($login['name'] ?? ($email !== '' ? 'Tài khoản chưa xác định' : '—')); ?></strong><small style="display:block;color:var(--text-muted)"><?php echo htmlspecialchars($email); ?></small></td>
                    <td><span class="<?php echo $failed ? 'badge-fail' : 'badge-success'; ?>"><?php echo $failed ? 'Thất bại' : 'Thành công'; ?></span></td>
                    <td style="white-space:nowrap"><?php echo date('d/m H:i', strtotime((string)$login['created_at'])); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recent_login_logs): ?><tr><td colspan="3" style="color:var(--text-muted)">Chưa có lịch sử đăng nhập.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="../assets/js/native-charts.js?v=<?php echo filemtime('../assets/js/native-charts.js'); ?>"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const s = getComputedStyle(document.documentElement);
    const textColor  = s.getPropertyValue('--text-main').trim()   || '#f8fafc';
    const mutedColor = s.getPropertyValue('--text-muted').trim()  || '#94a3b8';
    const gridColor  = s.getPropertyValue('--border-color').trim()|| 'rgba(255,255,255,.1)';
    const onlineCount = document.getElementById('online-users-count');
    const onlineNote = document.getElementById('online-users-note');
    const refreshOnlineUsers = async () => {
        if (document.visibilityState !== 'visible') return;
        try {
            const response = await fetch('online_presence.php', { cache: 'no-store', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await response.json();
            if (!response.ok || !data.success || !onlineCount) return;
            onlineCount.textContent = Number(data.online_users || 0).toLocaleString('vi-VN');
            if (onlineNote) onlineNote.textContent = `Đã cập nhật · ${new Date().toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' })}`;
        } catch (_) {
            if (onlineNote) onlineNote.textContent = 'Đang chờ cập nhật';
        }
    };
    refreshOnlineUsers();
    setInterval(refreshOnlineUsers, 30000);

    NativeCharts.doughnut(
        document.getElementById('userChart'),
        ['Học viên', 'Giáo viên'],
        [<?php echo $stats['students']; ?>, <?php echo $stats['teachers']; ?>],
        { colors: ['#6366f1', '#f43f5e'], textColor }
    );
    NativeCharts.bar(
        document.getElementById('trendChart'),
        <?php echo json_encode($trendLabels); ?>,
        <?php echo json_encode($trendData); ?>,
        { color: '#6366f1', textColor: mutedColor, gridColor }
    );
    NativeCharts.bar(
        document.getElementById('courseChart'),
        <?php echo json_encode($course_labels); ?>,
        <?php echo json_encode($course_data); ?>,
        { color: '#8b5cf6', textColor: mutedColor, gridColor }
    );
});
</script>

<?php require_once '../includes/footer.php'; ?>
