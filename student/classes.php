<?php
declare(strict_types=1);

require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/friendly_urls.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'student') {
    header('Location: ../index.php');
    exit;
}

$studentId = (int) $_SESSION['user_id'];
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

$page_title = 'Lớp học của tôi';
require_once '../includes/header.php';
?>
<style>
.student-class-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:24px;flex-wrap:wrap}.student-class-head h1{margin:0 0 7px}.student-class-head p{margin:0;color:var(--text-muted)}.student-class-count{display:inline-flex;align-items:center;gap:7px;padding:9px 13px;border-radius:999px;background:rgba(var(--primary-rgb),.12);color:var(--primary);font-weight:700}.student-class-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}.student-class-card{display:flex;flex-direction:column;min-height:260px;padding:22px;border:1px solid var(--border-color);border-radius:17px;background:var(--glass-bg);transition:.22s}.student-class-card:hover{transform:translateY(-4px);border-color:var(--primary)}.student-class-icon{display:grid;place-items:center;width:48px;height:48px;border-radius:14px;background:rgba(var(--primary-rgb),.13);color:var(--primary);font-size:28px}.student-class-card h2{margin:15px 0 6px;font-size:21px}.student-class-course{color:#7dd3fc;font-weight:700;margin-bottom:14px}.student-class-info{display:grid;gap:9px;margin-bottom:16px;color:var(--text-muted);font-size:14px}.student-class-info div{display:grid;grid-template-columns:20px 1fr;gap:7px}.student-class-info i{color:var(--primary);font-size:18px}.student-class-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:auto}.student-class-actions .btn{flex:1;text-align:center;white-space:nowrap}.student-class-empty{text-align:center;padding:55px 22px;color:var(--text-muted)}.student-class-empty i{display:block;font-size:58px;color:var(--primary);opacity:.65;margin-bottom:10px}@media(max-width:1050px){.student-class-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:680px){.student-class-grid{grid-template-columns:1fr}}
</style>

<div class="student-class-head">
  <div><h1><i class='bx bx-chalkboard'></i> Lớp học của tôi</h1><p>Các lớp mà giáo viên đã phân cho bạn. Nội dung học tập được lấy từ khóa học của từng lớp.</p></div>
  <span class="student-class-count"><i class='bx bx-group'></i> <?php echo count($classes); ?> lớp đang học</span>
</div>

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
<div class="box student-class-empty"><i class='bx bx-chalkboard'></i><h2>Bạn chưa được xếp vào lớp nào</h2><p>Vui lòng liên hệ giáo viên hoặc quản trị viên để được thêm vào lớp học.</p></div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
