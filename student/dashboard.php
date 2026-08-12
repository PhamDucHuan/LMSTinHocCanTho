<?php
require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/friendly_urls.php';
require_once '../includes/notifications.php';
/** @var PDO $pdo */
ensureFriendlyUrls($pdo);
/** @var PDO $pdo Kết nối cơ sở dữ liệu được khởi tạo trong config/database.php. */

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['student', 'admin', 'teacher'])) {
    header('Location: ../index.php');
    exit;
}

$student_id = $_SESSION['user_id'];
$is_staff = in_array($_SESSION['user_role'], ['admin', 'teacher']);
$shortCourseDescription = static function (?string $description, int $limit = 140): string {
    $description = trim((string) $description);
    if ($description === '') return 'Chưa có mô tả cho khóa học này.';
    return mb_strlen($description) > $limit
        ? mb_substr($description, 0, $limit) . '...'
        : $description;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_enrollment' && !$is_staff) {
    verifyCsrfToken();
    $requestedCourseId = filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT);
    if (!$requestedCourseId) {
        $_SESSION['error'] = 'Khóa học không hợp lệ.';
    } else {
        $courseCheck = $pdo->prepare("
            SELECT c.id, c.title, c.teacher_id
            FROM courses c
            WHERE c.id = ?
              AND NOT EXISTS (
                  SELECT 1 FROM course_enrollments ce
                  WHERE ce.course_id = c.id AND ce.student_id = ?
              )
        ");
        $courseCheck->execute([$requestedCourseId, $student_id]);
        $requestedCourse = $courseCheck->fetch();
        if (!$requestedCourse) {
            $_SESSION['error'] = 'Khóa học không tồn tại hoặc bạn đã được ghi danh.';
        } else {
            $requestStmt = $pdo->prepare("
                INSERT INTO course_enrollment_requests
                    (course_id, student_id, status, requested_at, reviewed_at, reviewed_by)
                VALUES (?, ?, 'pending', NOW(), NULL, NULL)
                ON DUPLICATE KEY UPDATE
                    status = 'pending',
                    requested_at = NOW(),
                    reviewed_at = NULL,
                    reviewed_by = NULL
            ");
            $requestStmt->execute([$requestedCourseId, $student_id]);
            $recipientStmt = $pdo->prepare(
                "SELECT id FROM users WHERE id = ? OR role = 'admin'"
            );
            $recipientStmt->execute([(int) $requestedCourse['teacher_id']]);
            foreach (array_unique(array_map('intval', $recipientStmt->fetchAll(PDO::FETCH_COLUMN))) as $recipientId) {
                createNotification(
                    $pdo,
                    $recipientId,
                    'enrollment_requested',
                    'Có yêu cầu ghi danh mới',
                    ($_SESSION['user_name'] ?? 'Một học viên') . " muốn tham gia khóa “{$requestedCourse['title']}”.",
                    '../teacher/course_detail.php?id=' . (int) $requestedCourseId,
                    ['course_id' => (int) $requestedCourseId, 'student_id' => (int) $student_id]
                );
            }
            $_SESSION['success'] = 'Đã gửi yêu cầu ghi danh. Vui lòng chờ giáo viên hoặc admin duyệt.';
        }
    }
    header('Location: dashboard.php');
    exit;
}

// --- THỐNG KÊ ---
$statsStmt = $pdo->prepare("SELECT
    (SELECT COUNT(*) FROM course_enrollments WHERE student_id=?) AS courses,
    (SELECT COUNT(*) FROM submissions WHERE student_id=?) AS completed,
    (SELECT AVG(score) FROM submissions WHERE student_id=? AND score IS NOT NULL) AS avg_score");
$statsStmt->execute([$student_id, $student_id, $student_id]);
$stats = $statsStmt->fetch();
if ($stats['avg_score'] !== null) {
    $stats['avg_score'] = round($stats['avg_score'], 1);
} else {
    $stats['avg_score'] = 0;
}

$total_assigned = $pdo->prepare("
    SELECT COUNT(a.id) 
    FROM assignments a
    WHERE a.course_id IN (SELECT course_id FROM course_enrollments WHERE student_id = ?) 
       OR a.course_id IS NULL
");
$total_assigned->execute([$student_id]);
$stats['pending'] = max(0, $total_assigned->fetchColumn() - $stats['completed']);

$scores_by_course = $pdo->prepare("
    SELECT c.title, AVG(s.score) as avg_score
    FROM submissions s
    JOIN assignments a ON s.assignment_id = a.id
    JOIN courses c ON a.course_id = c.id
    WHERE s.student_id = ? AND s.score IS NOT NULL
    GROUP BY c.id
");
$scores_by_course->execute([$student_id]);
$course_scores = $scores_by_course->fetchAll();

$radar_labels = [];
$radar_data = [];
foreach ($course_scores as $row) {
    $radar_labels[] = mb_strlen($row['title']) > 15 ? mb_substr($row['title'], 0, 15) . '...' : $row['title'];
    $radar_data[] = round($row['avg_score'], 1);
}

// --- DANH SÁCH BÀI TẬP ---
$query = "
    SELECT a.*, s.score, s.submitted_at, c.title as course_title 
    FROM assignments a
    LEFT JOIN courses c ON a.course_id = c.id
    LEFT JOIN submissions s ON a.id = s.assignment_id AND s.student_id = :submission_uid
";
$queryParams = ['submission_uid' => $student_id];
if (!$is_staff) {
    $query .= " WHERE a.course_id IN (SELECT course_id FROM course_enrollments WHERE student_id = :enrollment_uid) OR a.course_id IS NULL";
    $queryParams['enrollment_uid'] = $student_id;
}
$query .= " ORDER BY a.priority_order, a.created_at, a.id";

$stmt = $pdo->prepare($query);
$stmt->execute($queryParams);
$assignments = $stmt->fetchAll();

if ($is_staff) {
    $courseListStmt = $pdo->query("
        SELECT c.id, c.title, c.slug, c.description, NULL as exam_date,
               COALESCE(ac.assignment_count,0) AS assignment_count,
               COALESCE(qc.quiz_count,0) AS quiz_count
        FROM courses c
        LEFT JOIN (SELECT course_id,COUNT(*) assignment_count FROM assignments WHERE course_id IS NOT NULL GROUP BY course_id) ac ON ac.course_id=c.id
        LEFT JOIN (SELECT course_id,COUNT(*) quiz_count FROM quizzes WHERE is_published=1 GROUP BY course_id) qc ON qc.course_id=c.id
        ORDER BY c.created_at DESC
    ");
} else {
    $courseListStmt = $pdo->prepare("
        SELECT c.id, c.title, c.slug, c.description, ce.exam_date,
               COALESCE(ac.assignment_count,0) AS assignment_count,
               COALESCE(qc.quiz_count,0) AS quiz_count
        FROM courses c
        JOIN course_enrollments ce ON ce.course_id = c.id AND ce.student_id = ?
        LEFT JOIN (SELECT course_id,COUNT(*) assignment_count FROM assignments WHERE course_id IS NOT NULL GROUP BY course_id) ac ON ac.course_id=c.id
        LEFT JOIN (SELECT course_id,COUNT(*) quiz_count FROM quizzes WHERE is_published=1 GROUP BY course_id) qc ON qc.course_id=c.id
        ORDER BY ce.enrolled_at DESC
    ");
    $courseListStmt->execute([$student_id]);
}
$dashboardCourses = $courseListStmt->fetchAll();
$generalAssignmentCount = (int) $pdo->query("SELECT COUNT(*) FROM assignments WHERE course_id IS NULL")->fetchColumn();

$availableCourses = [];
if (!$is_staff) {
    $availableCourseStmt = $pdo->prepare("
        SELECT c.id, c.title, c.slug, c.description, u.name AS teacher_name, er.status AS request_status
        FROM courses c
        JOIN users u ON u.id = c.teacher_id
        LEFT JOIN course_enrollment_requests er
            ON er.course_id = c.id AND er.student_id = ?
        WHERE NOT EXISTS (
            SELECT 1 FROM course_enrollments ce
            WHERE ce.course_id = c.id AND ce.student_id = ?
        )
        ORDER BY c.created_at DESC
    ");
    $availableCourseStmt->execute([$student_id, $student_id]);
    $availableCourses = $availableCourseStmt->fetchAll();
}

$page_title = "Tổng quan Học tập";
require_once '../includes/header.php';
?>

<style>
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 40px; }
    .stat-card { background: var(--glass-bg); border: 1px solid rgba(255,255,255,0.05); border-radius: 16px; padding: 25px; text-align: center; transition: 0.3s; }
    .stat-card:hover { transform: translateY(-5px); border-color: var(--primary); }
    .stat-icon { font-size: 40px; color: var(--primary); margin-bottom: 15px; }
    .stat-number { font-size: 36px; font-weight: 700; color: var(--text-main); margin: 0 0 5px 0; }
    .stat-label { color: var(--text-muted); font-size: 14px; text-transform: uppercase; letter-spacing: 1px; }
    
    .charts-grid { display:grid; grid-template-columns:1fr; max-width:440px; margin:35px auto 0; }
    .chart-container { background:var(--glass-bg); border:1px solid rgba(255,255,255,0.05); border-radius:16px; padding:20px; text-align:center; }
    .chart-container h3 { margin-top: 0; margin-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 10px; text-align: left; }
    .chart-container canvas { width:100% !important; height:280px !important; }
    .dashboard-course-grid { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:20px; margin-top:20px; }
    .dashboard-course-card { display:flex; flex-direction:column; min-width:0; min-height:210px; padding:24px; border-radius:16px; background:var(--glass-bg); border:1px solid rgba(255,255,255,.08); transition:.25s; }
    .dashboard-course-card:hover { transform:translateY(-4px); border-color:var(--primary); }
    .dashboard-course-card > i { font-size:36px; color:var(--primary); }
    .dashboard-course-card h3 { margin:13px 0 8px; overflow-wrap:anywhere; }
    .dashboard-course-card p { flex:1; margin:0 0 14px; color:var(--text-muted); }
    .dashboard-course-count { margin-bottom:15px; color:#7dd3fc; font-size:13px; }
    .course-section-title h2 { margin:0 !important; }
    @media (max-width:1000px) { .dashboard-course-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
    @media (max-width:650px) { .dashboard-course-grid { grid-template-columns:1fr; } }
</style>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card">
        <i class='bx bx-book-reader stat-icon' style="color: #6366f1;"></i>
        <h3 class="stat-number"><?php echo $stats['courses']; ?></h3>
        <div class="stat-label">Khóa học tham gia</div>
    </div>
    <div class="stat-card">
        <i class='bx bx-check-circle stat-icon' style="color: #10b981;"></i>
        <h3 class="stat-number"><?php echo $stats['completed']; ?></h3>
        <div class="stat-label">Bài tập đã hoàn thành</div>
    </div>
    <div class="stat-card">
        <i class='bx bx-time-five stat-icon' style="color: #f59e0b;"></i>
        <h3 class="stat-number"><?php echo $stats['pending']; ?></h3>
        <div class="stat-label">Bài tập chưa nộp</div>
    </div>
    <div class="stat-card">
        <i class='bx bxs-star stat-icon' style="color: #f43f5e;"></i>
        <h3 class="stat-number"><?php echo $stats['avg_score']; ?></h3>
        <div class="stat-label">Điểm trung bình</div>
    </div>
</div>

<script src="../assets/js/native-charts.js?v=<?php echo filemtime('../assets/js/native-charts.js'); ?>"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    <?php if (count($radar_labels) > 0): ?>
    NativeCharts.radar(
        document.getElementById('scoreChart'),
        <?php echo json_encode($radar_labels); ?>,
        <?php echo json_encode($radar_data); ?>,
        { max: 10, color: '#6366f1', fill: 'rgba(99,102,241,.2)', textColor: '#f8fafc' }
    );
    <?php endif; ?>
});
</script>

<div class="course-section-title" style="margin-top:30px;padding:0 0 12px;border-bottom:1px solid rgba(255,255,255,.12);">
    <h2><i class='bx bx-book-open' style="color:var(--primary);"></i> Khóa học đã đăng ký</h2>
</div>

<div class="dashboard-course-grid">
    <?php foreach ($dashboardCourses as $course): ?>
        <div class="dashboard-course-card">
            <i class='bx bx-book-bookmark'></i>
            <h3><?php echo htmlspecialchars($course['title']); ?></h3>
            <p><?php echo htmlspecialchars($shortCourseDescription($course['description'] ?? '')); ?></p>
            <div class="dashboard-course-count"><i class='bx bx-task'></i> <?php echo (int) $course['assignment_count']; ?> bài tập / bài thi</div>
            <?php if (!$is_staff): ?><div class="dashboard-course-count"><i class='bx bx-list-check'></i> <?php echo (int) $course['quiz_count']; ?> bài trắc nghiệm</div><?php endif; ?>
            
            <?php 
            if (!$is_staff && !empty($course['exam_date'])) {
                $daysLeft = ceil((strtotime((string)$course['exam_date']) - time()) / 86400);
                $examDateStr = date('d/m/Y', strtotime((string)$course['exam_date']));
                if ($daysLeft > 0) {
                    echo "<div style='background:rgba(244,63,94,0.1); border:1px solid rgba(244,63,94,0.3); border-radius:8px; padding:8px 12px; margin-bottom:15px; color:#fb7185; font-size:13px;'><strong><i class='bx bx-calendar-event'></i> Ngày thi: $examDateStr</strong><br>Còn $daysLeft ngày để ôn tập!</div>";
                } elseif ($daysLeft == 0) {
                    echo "<div style='background:rgba(244,63,94,0.2); border:1px solid rgba(244,63,94,0.5); border-radius:8px; padding:8px 12px; margin-bottom:15px; color:#fb7185; font-size:13px;'><strong><i class='bx bx-alarm-exclamation'></i> HÔM NAY LÀ NGÀY THI!</strong><br>Chúc bạn làm bài tốt!</div>";
                } else {
                    echo "<div style='background:rgba(16,185,129,0.1); border-radius:8px; padding:8px 12px; margin-bottom:15px; color:#6ee7b7; font-size:13px;'><strong><i class='bx bx-calendar-check'></i> Đã thi: $examDateStr</strong></div>";
                }
            } 
            ?>
            <div style="display:flex;gap:9px;flex-wrap:wrap;margin-top:auto;">
                <a href="<?php echo htmlspecialchars(friendlyUrl('assignments.php','course',$course['slug'])); ?>" class="btn btn-primary" style="flex:1;">Xem bài tập</a>
                <?php if (!$is_staff): ?><a href="<?php echo htmlspecialchars(friendlyUrl('quizzes.php','course',$course['slug'])); ?>" class="btn btn-outline" style="flex:1;"><i class='bx bx-list-check'></i> Trắc nghiệm</a><?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($generalAssignmentCount > 0): ?>
        <div class="dashboard-course-card">
            <i class='bx bx-folder-open'></i>
            <h3>Bài tập chung</h3>
            <p>Các bài tập không thuộc một khóa học cụ thể.</p>
            <div class="dashboard-course-count"><i class='bx bx-task'></i> <?php echo $generalAssignmentCount; ?> bài tập / bài thi</div>
            <a href="assignments.php?course_id=0" class="btn btn-primary">Xem bài tập</a>
        </div>
    <?php endif; ?>
</div>

<?php if (!$dashboardCourses && $generalAssignmentCount === 0): ?>
    <div class="box" style="margin-top:20px;text-align:center;color:var(--text-muted);">
        Bạn chưa được ghi danh vào khóa học nào.
    </div>
<?php endif; ?>

<?php if (!$is_staff): ?>
    <div class="course-section-title" style="margin-top:42px;padding:0 0 12px;border-bottom:1px solid rgba(255,255,255,.12);">
        <h2><i class='bx bx-grid-alt' style="color:var(--primary);"></i> Khóa học có thể đăng ký</h2>
    </div>

    <?php if ($availableCourses): ?>
        <div class="dashboard-course-grid" style="margin-bottom:35px;">
            <?php foreach ($availableCourses as $course): ?>
                <div class="dashboard-course-card">
                    <i class='bx bx-book-add'></i>
                    <h3><?php echo htmlspecialchars($course['title']); ?></h3>
                    <div style="color:#7dd3fc;font-size:13px;margin-bottom:8px;"><i class='bx bx-user'></i> <?php echo htmlspecialchars($course['teacher_name']); ?></div>
                    <p><?php echo htmlspecialchars($shortCourseDescription($course['description'] ?? '')); ?></p>
                    <?php if (($course['request_status'] ?? '') === 'pending'): ?>
                        <div style="color:#fbbf24;font-size:13px;margin-bottom:10px;"><i class='bx bx-time-five'></i> Yêu cầu đang chờ duyệt</div>
                    <?php endif; ?>
                    <a href="<?php echo htmlspecialchars(friendlyUrl('course.php','course',$course['slug'])); ?>" class="btn btn-primary" style="text-align:center;">
                        <i class='bx bx-show'></i> Xem thông tin khóa học
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="box" style="margin-top:20px;text-align:center;color:var(--text-muted);">
            Hiện không có khóa học mới để đăng ký.
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="charts-grid">
    <div class="chart-container">
        <h3>Điểm trung bình theo Khóa học</h3>
        <?php if (count($radar_labels) > 0): ?>
            <canvas id="scoreChart"></canvas>
        <?php else: ?>
            <div class="empty-state" style="padding:24px 10px;color:var(--text-muted);">Bạn chưa có điểm số nào để thống kê.</div>
        <?php endif; ?>
    </div>
</div>

<?php if (false): ?>
<?php
// Phân loại mảng assignments
$exams = [];
$courses_group = [];
foreach ($assignments as $assignment) {
    if (($assignment['type'] ?? 'assignment') === 'exam') {
        $exams[] = $assignment;
    } else {
        $c_title = $assignment['course_title'] ? $assignment['course_title'] : 'Khóa học chung / Chưa phân loại';
        if (!isset($courses_group[$c_title])) $courses_group[$c_title] = [];
        $courses_group[$c_title][] = $assignment;
    }
}
?>

<?php if (count($courses_group) > 0): ?>
    <?php foreach ($courses_group as $c_title => $course_assignments): ?>
        <div style="display: flex; align-items: center; gap: 10px; margin: 40px 0 20px 0; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 10px;">
            <i class='bx bx-book-open' style="font-size: 24px; color: var(--primary);"></i>
            <h2 style="margin: 0;"><?php echo htmlspecialchars($c_title); ?></h2>
        </div>
        
        <div class="card-grid">
            <?php foreach ($course_assignments as $assignment): ?>
                <div class="card">
                    <div style="display: flex; gap: 8px; margin-bottom: 10px;">
                        <?php if ($assignment['category']): ?>
                            <span style="background: rgba(14, 165, 233, 0.2); color: #38bdf8; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">
                                <i class='bx bx-folder'></i> <?php echo htmlspecialchars($assignment['category']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <h3><?php echo htmlspecialchars($assignment['title']); ?></h3>
                    
                    <?php if ($assignment['submitted_at']): 
                        $module_settings = json_decode($assignment['module_settings'] ?? '[]', true);
                        $total_max = 0;
                        if (is_array($module_settings)) {
                            foreach ($module_settings as $m) $total_max += $m['max_score'];
                        }
                        if ($total_max == 0) $total_max = 10;
                    ?>
                        <div class="status done"><i class='bx bx-check-circle'></i> Đã nộp bài</div>
                        <p><strong>Điểm AI chấm: </strong> <span style="color: var(--success); font-size: 18px; font-weight: bold;"><?php echo $assignment['score'] ?? 'Đang chấm...'; ?></span> / <?php echo $total_max; ?></p>
                        <a href="<?php echo htmlspecialchars(friendlyUrl('assignment.php','assignment',$assignment['slug'])); ?>" class="btn btn-outline" style="margin-bottom: 10px;">Xem kết quả & Nhận xét</a>
                        <a href="outstanding_submissions.php?id=<?php echo $assignment['id']; ?>" class="btn" style="background: rgba(245, 158, 11, 0.1); color: var(--warning); border: 1px solid var(--warning);">
                            <i class='bx bxs-star'></i> Xem các bài tiêu biểu
                        </a>
                    <?php else: ?>
                        <div class="status pending"><i class='bx bx-time'></i> Chưa nộp</div>
                        <p><i class='bx bx-calendar'></i> Hạn nộp: <?php echo $assignment['due_date'] ? date('d/m/Y H:i', strtotime($assignment['due_date'])) : 'Không thời hạn'; ?></p>
                        <a href="<?php echo htmlspecialchars(friendlyUrl('assignment.php','assignment',$assignment['slug'])); ?>" class="btn">Vào làm bài</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (count($exams) > 0): ?>
    <div id="exams-section" style="display: flex; align-items: center; gap: 10px; margin: 50px 0 20px 0; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 10px;">
        <i class='bx bx-timer' style="font-size: 28px; color: var(--danger);"></i>
        <h2 style="margin: 0; color: var(--danger);">Bài Thi Thử</h2>
    </div>
    <div class="card-grid">
        <?php foreach ($exams as $assignment): ?>
            <div class="card" style="border-color: rgba(239, 68, 68, 0.3);">
                <h3><?php echo htmlspecialchars($assignment['title']); ?></h3>
                
                <?php if ($assignment['submitted_at']): 
                        $module_settings = json_decode($assignment['module_settings'] ?? '[]', true);
                        $total_max = 0;
                        if (is_array($module_settings)) {
                            foreach ($module_settings as $m) $total_max += $m['max_score'];
                        }
                        if ($total_max == 0) $total_max = 10;
                ?>
                    <div class="status done"><i class='bx bx-check-circle'></i> Đã thi xong</div>
                    <p><strong>Điểm AI chấm: </strong> <span style="color: var(--success); font-size: 18px; font-weight: bold;"><?php echo $assignment['score'] ?? 'Đang chấm...'; ?></span> / <?php echo $total_max; ?></p>
                    <a href="<?php echo htmlspecialchars(friendlyUrl('assignment.php','assignment',$assignment['slug'])); ?>" class="btn btn-outline" style="margin-bottom: 10px;">Xem kết quả</a>
                <?php else: ?>
                    <div class="status pending" style="background: rgba(239, 68, 68, 0.2); color: var(--danger);"><i class='bx bx-time'></i> Chưa làm bài</div>
                    <p><i class='bx bx-calendar'></i> Hạn thi: <?php echo $assignment['due_date'] ? date('d/m/Y H:i', strtotime($assignment['due_date'])) : 'Không thời hạn'; ?></p>
                    <a href="<?php echo htmlspecialchars(friendlyUrl('assignment.php','assignment',$assignment['slug'])); ?>" class="btn" style="background: var(--danger);">Bắt đầu thi</a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (count($assignments) == 0): ?>
    <div style="text-align: center; padding: 50px; color: var(--text-muted);">
        <i class='bx bx-folder-open' style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
        <p>Bạn chưa có bài tập hay bài thi nào.</p>
    </div>
<?php endif; ?>

<?php endif; ?>
<?php require_once '../includes/footer.php'; ?>
