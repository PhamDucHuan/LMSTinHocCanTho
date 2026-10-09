<?php
declare(strict_types=1);

require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';
/** @var PDO $pdo */
require_once '../includes/friendly_urls.php';
require_once '../includes/course_enrollment.php';
require_once '../includes/notifications.php';
require_once '../includes/audit.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'student') {
    header('Location: ../index.php');
    exit;
}

$studentId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_enrollment') {
    verifyCsrfToken();
    $courseId = (int) ($_POST['course_id'] ?? 0);
    try {
        $requestedCourse = requestCourseEnrollment($pdo, $studentId, $courseId);
        foreach (courseEnrollmentReviewerIds($pdo, $courseId) as $reviewerId) {
            try {
                createNotification(
                    $pdo,
                    $reviewerId,
                    'enrollment_requested',
                    'Có yêu cầu vào lớp mới',
                    ($_SESSION['user_name'] ?? 'Một học viên') . ' muốn tham gia khóa “' . $requestedCourse['title'] . '”.',
                    '../teacher/course_detail.php?id=' . $courseId . '#enrollment-requests',
                    ['course_id' => $courseId, 'student_id' => $studentId, 'request_id' => (int) $requestedCourse['request_id']]
                );
            } catch (Throwable $notificationError) {
                error_log('Cannot notify course enrollment reviewer: ' . $notificationError->getMessage());
            }
        }
        writeAuditLog($pdo, 'enrollment.requested', 'course_enrollment_request', (int) $requestedCourse['request_id'], [
            'course_id' => $courseId,
        ]);
        $_SESSION['success'] = 'Đã gửi yêu cầu đăng ký. Bạn sẽ vào lớp sau khi giáo viên duyệt.';
    } catch (Throwable $error) {
        $_SESSION['error'] = $error->getMessage();
    }
    header('Location: classes.php');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT lc.id, lc.class_name, lc.notes, lc.created_at, lcs.exam_date,
            c.id AS course_id, c.title AS course_title, c.slug AS course_slug, c.description AS course_description,
            COALESCE(
                (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ')
                 FROM learning_class_teachers lct JOIN users t ON t.id=lct.teacher_id
                 WHERE lct.learning_class_id=lc.id),
                primary_teacher.name
            ) AS teacher_names,
            (SELECT COUNT(*) FROM assignments a WHERE a.course_id=c.id) AS assignment_count,
            (SELECT COUNT(*) FROM quizzes q WHERE q.course_id=c.id AND q.is_published=1) AS quiz_count
     FROM learning_classes lc
     JOIN learning_class_students lcs ON lcs.learning_class_id=lc.id AND lcs.student_id=?
     JOIN courses c ON c.id=lc.course_id
     LEFT JOIN users primary_teacher ON primary_teacher.id=lc.primary_teacher_id
     WHERE lc.status='active'
     ORDER BY lc.updated_at DESC, lc.class_name"
);
$stmt->execute([$studentId]);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$availableStmt = $pdo->prepare(
    "SELECT c.id, c.title, c.slug, c.description,
            COALESCE(
                (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ')
                 FROM course_teachers ct JOIN users t ON t.id=ct.teacher_id
                 WHERE ct.course_id=c.id),
                owner.name
            ) AS teacher_names,
            er.status AS request_status,
            (SELECT COUNT(*) FROM learning_classes lc WHERE lc.course_id=c.id AND lc.status='active') AS active_class_count,
            (SELECT COUNT(*) FROM course_materials cm WHERE cm.course_id=c.id) AS material_count,
            (SELECT COUNT(*) FROM assignments a WHERE a.course_id=c.id) AS assignment_count,
            (SELECT COUNT(*) FROM quizzes q WHERE q.course_id=c.id AND q.is_published=1) AS quiz_count
     FROM courses c
     JOIN users owner ON owner.id=c.teacher_id
     LEFT JOIN course_enrollment_requests er ON er.course_id=c.id AND er.student_id=?
     WHERE NOT EXISTS (
         SELECT 1 FROM learning_classes enrolled_class
         JOIN learning_class_students enrolled_student ON enrolled_student.learning_class_id=enrolled_class.id
         WHERE enrolled_class.course_id=c.id AND enrolled_class.status='active' AND enrolled_student.student_id=?
     )
     ORDER BY (er.status='pending') DESC, c.created_at DESC, c.id DESC"
);
$availableStmt->execute([$studentId, $studentId]);
$availableCourses = $availableStmt->fetchAll(PDO::FETCH_ASSOC);

$shortDescription = static function (?string $description, int $limit = 150): string {
    $description = trim((string) $description);
    if ($description === '') return 'Khóa học chưa có mô tả.';
    return mb_strlen($description, 'UTF-8') > $limit
        ? mb_substr($description, 0, $limit, 'UTF-8') . '…'
        : $description;
};

$page_title = 'Lớp học của tôi';
require_once '../includes/header.php';
?>
<style>
.student-class-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:24px;flex-wrap:wrap}.student-class-head h1{margin:0 0 7px}.student-class-head p{margin:0;color:var(--text-muted)}.student-class-count{display:inline-flex;align-items:center;gap:7px;padding:9px 13px;border-radius:999px;background:rgba(var(--primary-rgb),.12);color:var(--primary);font-weight:700}.student-class-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}.student-class-card{display:flex;flex-direction:column;min-height:260px;padding:22px;border:1px solid var(--border-color);border-radius:17px;background:var(--glass-bg);transition:.22s}.student-class-card:hover{transform:translateY(-4px);border-color:var(--primary)}.student-class-icon{display:grid;place-items:center;width:48px;height:48px;border-radius:14px;background:rgba(var(--primary-rgb),.13);color:var(--primary);font-size:28px}.student-class-card h2{margin:15px 0 6px;font-size:21px}.student-class-course{color:#7dd3fc;font-weight:700;margin-bottom:14px}.student-class-info{display:grid;gap:9px;margin-bottom:16px;color:var(--text-muted);font-size:14px}.student-class-info div{display:grid;grid-template-columns:20px 1fr;gap:7px}.student-class-info i{color:var(--primary);font-size:18px}.student-class-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:auto}.student-class-actions .btn{flex:1;text-align:center;white-space:nowrap}.student-class-empty{text-align:center;padding:55px 22px;color:var(--text-muted)}.student-class-empty i{display:block;font-size:58px;color:var(--primary);opacity:.65;margin-bottom:10px}.enrollment-section{margin-top:34px}.enrollment-section-head{display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap;margin-bottom:16px;padding-bottom:13px;border-bottom:1px solid var(--border-color)}.enrollment-section-head h2{margin:0 0 6px}.enrollment-section-head p{margin:0;color:var(--text-muted)}.available-course-card p{color:var(--text-muted);line-height:1.55}.enrollment-status{display:inline-flex;align-items:center;gap:6px;margin:0 0 12px;padding:6px 9px;border-radius:999px;font-size:12px;font-weight:700}.enrollment-status.pending{color:#fbbf24;background:rgba(245,158,11,.14)}.enrollment-status.closed{color:var(--text-muted);background:rgba(148,163,184,.12)}.course-summary{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:15px;color:var(--text-muted);font-size:13px}.course-summary span{display:inline-flex;align-items:center;gap:5px}.available-course-card form{margin-top:auto}.available-course-card form .btn{width:100%;justify-content:center}.available-course-card .btn[disabled]{cursor:not-allowed;opacity:.68}@media(max-width:1050px){.student-class-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:680px){.student-class-grid{grid-template-columns:1fr}}
</style>

<div class="student-class-head">
  <div><h1><i class='bx bx-chalkboard'></i> Lớp học &amp; đăng ký</h1><p>Xem các lớp đang học hoặc gửi yêu cầu tham gia khóa học mới.</p></div>
  <span class="student-class-count"><i class='bx bx-group'></i> <?php echo count($classes); ?> lớp đang học</span>
</div>

<?php if (isset($_SESSION['success'])): ?><div class="alert alert-success"><?php echo htmlspecialchars((string) $_SESSION['success']); unset($_SESSION['success']); ?></div><?php endif; ?>
<?php if (isset($_SESSION['error'])): ?><div class="alert alert-danger"><?php echo htmlspecialchars((string) $_SESSION['error']); unset($_SESSION['error']); ?></div><?php endif; ?>

<?php if ($classes): ?>
<div class="student-class-grid">
  <?php foreach ($classes as $class): ?>
    <article class="student-class-card">
      <div class="student-class-icon"><i class='bx bx-chalkboard'></i></div>
      <h2><?php echo htmlspecialchars($class['class_name']); ?></h2>
      <div class="student-class-course"><i class='bx bx-book-open'></i> <?php echo htmlspecialchars($class['course_title']); ?></div>
      <div class="student-class-info">
        <div><i class='bx bx-user-voice'></i><span><?php echo htmlspecialchars($class['teacher_names'] ?: 'Chưa phân công giáo viên'); ?></span></div>
        <div><i class='bx bx-task'></i><span><?php echo (int) $class['assignment_count']; ?> bài tập / bài thi</span></div>
        <div><i class='bx bx-list-check'></i><span><?php echo (int) $class['quiz_count']; ?> bài trắc nghiệm</span></div>
        <?php if (!empty($class['exam_date'])): ?><div><i class='bx bx-calendar-check'></i><span>Ngày thi của bạn: <strong><?php echo date('d/m/Y', strtotime((string) $class['exam_date'])); ?></strong></span></div><?php endif; ?>
        <?php if (trim((string) $class['notes']) !== ''): ?><div><i class='bx bx-note'></i><span><?php echo nl2br(htmlspecialchars((string) $class['notes'])); ?></span></div><?php endif; ?>
      </div>
      <div class="student-class-actions">
        <a class="btn btn-primary" href="<?php echo htmlspecialchars(friendlyUrl('assignments.php', 'course', $class['course_slug'])); ?>"><i class='bx bx-task'></i> Bài tập</a>
        <a class="btn btn-outline" href="<?php echo htmlspecialchars(friendlyUrl('quizzes.php', 'course', $class['course_slug'])); ?>"><i class='bx bx-list-check'></i> Trắc nghiệm</a>
      </div>
    </article>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="box student-class-empty"><i class='bx bx-chalkboard'></i><h2>Bạn chưa vào lớp nào</h2><p>Chọn một khóa học bên dưới và gửi yêu cầu để giáo viên duyệt.</p></div>
<?php endif; ?>

<section class="enrollment-section">
  <header class="enrollment-section-head">
    <div><h2><i class='bx bx-book-add'></i> Khóa học có thể đăng ký</h2><p>Sau khi gửi yêu cầu, giáo viên sẽ chọn lớp phù hợp và phê duyệt cho bạn.</p></div>
    <span class="student-class-count"><i class='bx bx-book-open'></i> <?php echo count($availableCourses); ?> khóa học</span>
  </header>
  <?php if ($availableCourses): ?>
  <div class="student-class-grid">
    <?php foreach ($availableCourses as $course): ?>
      <?php $requestStatus = (string) ($course['request_status'] ?? ''); $hasActiveClass = (int) $course['active_class_count'] > 0; ?>
      <article class="student-class-card available-course-card">
        <div class="student-class-icon"><i class='bx bx-book-add'></i></div>
        <h2><?php echo htmlspecialchars((string) $course['title']); ?></h2>
        <div class="student-class-course"><i class='bx bx-user-voice'></i> <?php echo htmlspecialchars((string) ($course['teacher_names'] ?: 'Chưa phân công giáo viên')); ?></div>
        <p><?php echo htmlspecialchars($shortDescription($course['description'] ?? null)); ?></p>
        <div class="course-summary">
          <span><i class='bx bx-chalkboard'></i> <?php echo (int) $course['active_class_count']; ?> lớp mở</span>
          <span><i class='bx bx-book-content'></i> <?php echo (int) $course['material_count']; ?> học liệu</span>
          <span><i class='bx bx-task'></i> <?php echo (int) $course['assignment_count']; ?> bài</span>
          <span><i class='bx bx-list-check'></i> <?php echo (int) $course['quiz_count']; ?> trắc nghiệm</span>
        </div>
        <?php if ($requestStatus === 'pending'): ?>
          <span class="enrollment-status pending"><i class='bx bx-time-five'></i> Đang chờ giáo viên duyệt</span>
        <?php elseif (!$hasActiveClass): ?>
          <span class="enrollment-status closed"><i class='bx bx-lock-alt'></i> Chưa có lớp đang mở</span>
        <?php endif; ?>
        <form method="post">
          <?php echo csrfField(); ?>
          <input type="hidden" name="action" value="request_enrollment">
          <input type="hidden" name="course_id" value="<?php echo (int) $course['id']; ?>">
          <button type="submit" class="btn btn-primary" <?php echo $requestStatus === 'pending' || !$hasActiveClass ? 'disabled' : ''; ?>>
            <i class='bx <?php echo $requestStatus === 'pending' ? 'bx-time-five' : 'bx-user-plus'; ?>'></i>
            <?php echo $requestStatus === 'pending' ? 'Đang chờ duyệt' : (in_array($requestStatus, ['rejected', 'approved'], true) ? 'Gửi yêu cầu lại' : 'Đăng ký khóa học'); ?>
          </button>
        </form>
      </article>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
    <div class="box student-class-empty"><i class='bx bx-check-circle'></i><h2>Không có khóa học mới</h2><p>Bạn đã tham gia tất cả khóa học hiện có.</p></div>
  <?php endif; ?>
</section>

<?php require_once '../includes/footer.php'; ?>
