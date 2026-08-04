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

// Lấy thống kê tổng quan
$stats = [
    'students' => $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn(),
    'teachers' => $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'teacher'")->fetchColumn(),
    'courses' => $pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn(),
    'assignments' => $pdo->query("SELECT COUNT(*) FROM assignments")->fetchColumn(),
    'submissions' => $pdo->query("SELECT COUNT(*) FROM submissions")->fetchColumn(),
    'ai_waiting' => $pdo->query("SELECT COUNT(*) FROM grading_jobs WHERE status IN ('queued','processing')")->fetchColumn(),
    'review_required' => $pdo->query("SELECT COUNT(*) FROM submissions WHERE grading_status = 'review_required'")->fetchColumn(),
    'ai_failed' => $pdo->query("SELECT COUNT(*) FROM grading_jobs WHERE status = 'failed'")->fetchColumn(),
    'ai_avg_seconds' => $pdo->query("SELECT COALESCE(ROUND(AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at))),0) FROM grading_jobs WHERE status='completed' AND started_at IS NOT NULL")->fetchColumn()
];

// Lấy danh sách khóa học có nhiều bài tập nhất
$top_courses = $pdo->query("
    SELECT c.title, COUNT(a.id) as total_assignments 
    FROM courses c 
    LEFT JOIN assignments a ON c.id = a.course_id 
    GROUP BY c.id 
    ORDER BY total_assignments DESC 
    LIMIT 5
")->fetchAll();

$course_labels = [];
$course_data = [];
foreach ($top_courses as $c) {
    $course_labels[] = $c['title'];
    $course_data[] = $c['total_assignments'];
}

$page_title = "Thống kê Tổng quan (Admin)";
require_once '../includes/header.php';
?>

<style>
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 40px; }
    .stat-card { background: var(--glass-bg); border: 1px solid rgba(255,255,255,0.05); border-radius: 16px; padding: 25px; text-align: center; transition: 0.3s; }
    .stat-card:hover { transform: translateY(-5px); border-color: var(--primary); }
    .stat-icon { font-size: 40px; color: var(--primary); margin-bottom: 15px; }
    .stat-number { font-size: 36px; font-weight: 700; color: var(--text-main); margin: 0 0 5px 0; }
    .stat-label { color: var(--text-muted); font-size: 14px; text-transform: uppercase; letter-spacing: 1px; }
    
    .charts-grid { display:grid; grid-template-columns:minmax(300px, .8fr) minmax(0, 1.7fr); gap:24px; align-items:start; }
    .chart-container { min-width:0; background:var(--glass-bg); border:1px solid rgba(255,255,255,0.05); border-radius:16px; padding:22px; overflow:hidden; }
    .chart-container h3 { margin-top: 0; margin-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 10px; }
    .chart-canvas-wrap { position:relative; width:100%; height:300px; }
    .chart-canvas-wrap canvas { display:block; width:100% !important; height:100% !important; max-width:100%; }
    @media (max-width:1100px) {
        .charts-grid { grid-template-columns:1fr; }
        .chart-container:first-child { max-width:520px; width:100%; margin:0 auto; }
    }
    @media (max-width:650px) {
        .chart-container { padding:16px; }
        .chart-canvas-wrap { height:250px; }
    }
</style>

<div class="stats-grid">
    <div class="stat-card">
        <i class='bx bx-user stat-icon'></i>
        <h3 class="stat-number"><?php echo $stats['students']; ?></h3>
        <div class="stat-label">Học sinh</div>
    </div>
    <div class="stat-card">
        <i class='bx bx-user-pin stat-icon'></i>
        <h3 class="stat-number"><?php echo $stats['teachers']; ?></h3>
        <div class="stat-label">Giáo viên</div>
    </div>
    <div class="stat-card">
        <i class='bx bx-book-open stat-icon'></i>
        <h3 class="stat-number"><?php echo $stats['courses']; ?></h3>
        <div class="stat-label">Khóa học</div>
    </div>
    <div class="stat-card">
        <i class='bx bx-book-content stat-icon'></i>
        <h3 class="stat-number"><?php echo $stats['assignments']; ?></h3>
        <div class="stat-label">Bài tập</div>
    </div>
    <div class="stat-card">
        <i class='bx bx-check-square stat-icon'></i>
        <h3 class="stat-number"><?php echo $stats['submissions']; ?></h3>
        <div class="stat-label">Bài nộp</div>
    </div>
    <div class="stat-card">
        <i class='bx bx-loader-circle stat-icon'></i>
        <h3 class="stat-number"><?php echo $stats['ai_waiting']; ?></h3>
        <div class="stat-label">AI đang chờ/chấm</div>
    </div>
    <div class="stat-card">
        <i class='bx bx-user-check stat-icon'></i>
        <h3 class="stat-number"><?php echo $stats['review_required']; ?></h3>
        <div class="stat-label">Cần giáo viên duyệt</div>
    </div>
    <div class="stat-card">
        <i class='bx bx-time-five stat-icon'></i>
        <h3 class="stat-number"><?php echo $stats['ai_avg_seconds']; ?>s</h3>
        <div class="stat-label">Thời gian chấm TB</div>
    </div>
    <div class="stat-card">
        <i class='bx bx-error-circle stat-icon'></i>
        <h3 class="stat-number"><?php echo $stats['ai_failed']; ?></h3>
        <div class="stat-label">Lượt chấm lỗi</div>
    </div>
</div>

<div class="charts-grid">
    <div class="chart-container">
        <h3>Tỉ lệ Người dùng</h3>
        <div class="chart-canvas-wrap"><canvas id="userChart"></canvas></div>
    </div>
    <div class="chart-container">
        <h3>Top Khóa học nhiều Bài tập nhất</h3>
        <div class="chart-canvas-wrap"><canvas id="courseChart"></canvas></div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const chartStyles = getComputedStyle(document.documentElement);
    const chartTextColor = chartStyles.getPropertyValue('--text-main').trim() || '#f8fafc';
    const chartMutedColor = chartStyles.getPropertyValue('--text-muted').trim() || '#94a3b8';
    const chartGridColor = chartStyles.getPropertyValue('--border-color').trim() || 'rgba(255,255,255,.1)';

    // Biểu đồ người dùng (Pie)
    const ctxUser = document.getElementById('userChart').getContext('2d');
    new Chart(ctxUser, {
        type: 'doughnut',
        data: {
            labels: ['Học sinh', 'Giáo viên'],
            datasets: [{
                data: [<?php echo $stats['students']; ?>, <?php echo $stats['teachers']; ?>],
                backgroundColor: ['#6366f1', '#f43f5e'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: 8 },
            plugins: {
                legend: { position: 'bottom', labels: { color: chartTextColor, boxWidth: 14, padding: 16 } }
            }
        }
    });

    // Biểu đồ khóa học (Bar)
    const ctxCourse = document.getElementById('courseChart').getContext('2d');
    new Chart(ctxCourse, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($course_labels); ?>,
            datasets: [{
                label: 'Số lượng bài tập',
                data: <?php echo json_encode($course_data); ?>,
                backgroundColor: 'rgba(99, 102, 241, 0.8)',
                borderColor: '#6366f1',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: 4 },
            scales: {
                y: { beginAtZero: true, grid: { color: chartGridColor }, ticks: { color: chartMutedColor, stepSize: 1 } },
                x: { grid: { display: false }, ticks: { color: chartMutedColor } }
            },
            plugins: {
                legend: { display: false }
            }
        }
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
