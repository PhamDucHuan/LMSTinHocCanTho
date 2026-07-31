<?php
require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/quiz_schema.php';
require_once '../includes/friendly_urls.php';
/** @var PDO $pdo */

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ../index.php');
    exit;
}
ensureQuizSchema($pdo);
ensureFriendlyUrls($pdo);

$studentId = (int) $_SESSION['user_id'];
$courseSlug = trim((string) ($_GET['course'] ?? ''));
$courseId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($courseSlug !== '') {
    $slugStmt = $pdo->prepare('SELECT id FROM courses WHERE slug=?');
    $slugStmt->execute([$courseSlug]);
    $courseId = (int) $slugStmt->fetchColumn();
}
if (!$courseId) {
    http_response_code(400);
    exit('Khóa học không hợp lệ.');
}

$courseExistsStmt = $pdo->prepare("SELECT 1 FROM courses WHERE id = ?");
$courseExistsStmt->execute([$courseId]);
if (!$courseExistsStmt->fetchColumn()) {
    http_response_code(404);
    exit('Khóa học không tồn tại.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_enrollment') {
    verifyCsrfToken();
    $enrollmentCheck = $pdo->prepare("SELECT 1 FROM course_enrollments WHERE course_id = ? AND student_id = ?");
    $enrollmentCheck->execute([$courseId, $studentId]);
    if ($enrollmentCheck->fetchColumn()) {
        $_SESSION['error'] = 'Bạn đã tham gia khóa học này.';
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
        $requestStmt->execute([$courseId, $studentId]);
        $_SESSION['success'] = 'Đã gửi yêu cầu ghi danh. Vui lòng chờ giáo viên hoặc admin duyệt.';
    }
    $slugStmt = $pdo->prepare('SELECT slug FROM courses WHERE id=?');
    $slugStmt->execute([$courseId]);
    header('Location: ' . friendlyUrl('course.php','course',(string)$slugStmt->fetchColumn()));
    exit;
}

$stmt = $pdo->prepare("
    SELECT c.*, u.name AS teacher_name,
           (SELECT COUNT(*) FROM assignments a WHERE a.course_id = c.id AND a.type = 'assignment') AS assignment_count,
           (SELECT COUNT(*) FROM assignments a WHERE a.course_id = c.id AND a.type = 'exam') AS exam_count,
           (SELECT COUNT(*) FROM quizzes q WHERE q.course_id = c.id AND q.is_published = 1) AS quiz_count,
           EXISTS(
               SELECT 1 FROM course_enrollments ce
               WHERE ce.course_id = c.id AND ce.student_id = ?
           ) AS is_enrolled,
           (
               SELECT er.status FROM course_enrollment_requests er
               WHERE er.course_id = c.id AND er.student_id = ?
               LIMIT 1
           ) AS request_status
    FROM courses c
    JOIN users u ON u.id = c.teacher_id
    WHERE c.id = ?
");
$stmt->execute([$studentId, $studentId, $courseId]);
$course = $stmt->fetch();
if (!$course) {
    http_response_code(404);
    exit('Khóa học không tồn tại.');
}

$page_title = $course['title'];
require_once '../includes/header.php';
?>

<style>
    .course-detail-heading { display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap;margin-bottom:24px;padding-bottom:18px;border-bottom:1px solid rgba(255,255,255,.12); }
    .course-detail-actions { min-width:220px;display:flex;justify-content:flex-end; }
    .course-detail-layout { display:grid;grid-template-columns:minmax(0,2fr) minmax(260px,1fr);gap:22px; }
    .course-description { white-space:normal;line-height:1.8;color:rgba(255,255,255,.84);overflow-wrap:anywhere; }
    .course-info-row { display:flex;align-items:center;gap:10px;padding:12px 0;border-bottom:1px solid rgba(255,255,255,.08); }
    @media(max-width:800px) { .course-detail-layout{grid-template-columns:1fr}.course-detail-actions{width:100%;justify-content:stretch}.course-detail-actions>*{width:100%} }
</style>

<a href="dashboard.php" style="display:inline-block;margin-bottom:18px;color:var(--primary);"><i class='bx bx-arrow-back'></i> Quay lại tổng quan</a>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
<?php endif; ?>

<div class="course-detail-heading">
    <div>
        <div style="color:#7dd3fc;margin-bottom:8px;"><i class='bx bx-user'></i> Giảng viên: <?php echo htmlspecialchars($course['teacher_name']); ?></div>
        <h1 style="margin:0;"><?php echo htmlspecialchars($course['title']); ?></h1>
    </div>
    <div class="course-detail-actions">
        <?php if ((int) $course['is_enrolled'] === 1): ?>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <a href="<?php echo htmlspecialchars(friendlyUrl('assignments.php','course',$course['slug'])); ?>" class="btn btn-primary"><i class='bx bx-book-open'></i> Xem bài tập</a>
                <a href="<?php echo htmlspecialchars(friendlyUrl('quizzes.php','course',$course['slug'])); ?>" class="btn btn-outline"><i class='bx bx-list-check'></i> Làm trắc nghiệm</a>
            </div>
        <?php elseif (($course['request_status'] ?? '') === 'pending'): ?>
            <button type="button" class="btn" disabled style="background:rgba(245,158,11,.16);color:#fbbf24;border:1px solid rgba(245,158,11,.3);"><i class='bx bx-time-five'></i> Đang chờ duyệt</button>
        <?php else: ?>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="request_enrollment">
                <button type="submit" class="btn btn-primary"><i class='bx bx-user-plus'></i> <?php echo ($course['request_status'] ?? '') === 'rejected' ? 'Gửi yêu cầu lại' : 'Đăng ký tham gia'; ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="course-detail-layout">
    <section class="box">
        <h2 style="margin-top:0;"><i class='bx bx-detail'></i> Thông tin khóa học</h2>
        <div class="course-description">
            <?php echo trim((string) $course['description']) !== ''
                ? nl2br(htmlspecialchars($course['description']))
                : '<span style="color:var(--text-muted);">Khóa học này chưa có mô tả.</span>'; ?>
        </div>
    </section>
    <aside class="box">
        <h3 style="margin-top:0;">Tổng quan</h3>
        <div class="course-info-row">
            <i class='bx bx-task' style="color:var(--primary);font-size:22px;"></i>
            <span>Bài tập: <strong><?php echo (int) $course['assignment_count']; ?></strong></span>
        </div>
        <div class="course-info-row">
            <i class='bx bx-timer' style="color:#f87171;font-size:22px;"></i>
            <span>Bài thi: <strong><?php echo (int) $course['exam_count']; ?></strong></span>
        </div>
        <div class="course-info-row">
            <i class='bx bx-list-check' style="color:#a78bfa;font-size:22px;"></i>
            <span>Trắc nghiệm: <strong><?php echo (int) $course['quiz_count']; ?></strong></span>
        </div>
    </aside>
</div>

<?php require_once '../includes/footer.php'; ?>
