<?php
require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/quiz_schema.php';
require_once '../includes/friendly_urls.php';
/** @var PDO $pdo */
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ../index.php'); exit;
}
ensureQuizSchema($pdo);
ensureFriendlyUrls($pdo);
$courseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);
$courseSlug = trim((string) ($_GET['course'] ?? ''));
if ($courseSlug !== '') {
    $slugStmt = $pdo->prepare('SELECT id FROM courses WHERE slug=?');
    $slugStmt->execute([$courseSlug]);
    $courseId = (int) $slugStmt->fetchColumn();
}
if (!$courseId) {
    $stmt = $pdo->prepare("
        SELECT c.id, c.title, c.slug, c.description, COUNT(q.id) AS quiz_count
        FROM courses c
        JOIN course_enrollments ce ON ce.course_id=c.id AND ce.student_id=?
        LEFT JOIN quizzes q ON q.course_id=c.id AND q.is_published=1
        GROUP BY c.id,c.title,c.description
        ORDER BY ce.enrolled_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $quizCourses = $stmt->fetchAll();
    $page_title = 'Làm trắc nghiệm';
    require_once '../includes/header.php';
    ?>
    <style>
    .quiz-course-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}.quiz-course-card{display:flex;flex-direction:column;gap:12px}.quiz-course-card .btn{margin-top:auto}
    @media(max-width:1000px){.quiz-course-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:650px){.quiz-course-grid{grid-template-columns:1fr}}
    </style>
    <h1><i class='bx bx-list-check'></i> Làm trắc nghiệm</h1>
    <p style="color:var(--text-muted)">Chọn khóa học để xem các bài trắc nghiệm đang mở.</p>
    <div class="quiz-course-grid">
        <?php foreach($quizCourses as $quizCourse):?>
            <article class="box quiz-course-card">
                <i class='bx bx-book-open' style="font-size:38px;color:var(--primary)"></i>
                <h2 style="margin:0"><?php echo htmlspecialchars($quizCourse['title']);?></h2>
                <p style="color:var(--text-muted)"><?php echo htmlspecialchars(mb_strimwidth((string)($quizCourse['description']??''),0,140,'…','UTF-8'));?></p>
                <div><i class='bx bx-list-check'></i> <strong><?php echo (int)$quizCourse['quiz_count'];?></strong> bài trắc nghiệm đang mở</div>
                <a href="<?php echo htmlspecialchars(friendlyUrl('quizzes.php','course',$quizCourse['slug']));?>" class="btn btn-primary"><i class='bx bx-right-arrow-alt'></i> Xem trắc nghiệm</a>
            </article>
        <?php endforeach;?>
        <?php if(!$quizCourses):?><div class="box empty-state">Bạn chưa tham gia khóa học nào.</div><?php endif;?>
    </div>
    <?php
    require_once '../includes/footer.php';
    exit;
}
$stmt = $pdo->prepare('SELECT c.title,c.slug FROM courses c JOIN course_enrollments ce ON ce.course_id=c.id AND ce.student_id=? WHERE c.id=?');
$stmt->execute([$_SESSION['user_id'], $courseId]);
$course = $stmt->fetch();
if (!$course) { http_response_code(403); exit('Bạn chưa được ghi danh vào khóa học này.'); }
$stmt = $pdo->prepare("SELECT q.*, COUNT(DISTINCT qs.id) section_count, COUNT(qq.id) question_count,
    (SELECT qa.score FROM quiz_attempts qa WHERE qa.quiz_id=q.id AND qa.student_id=? AND qa.submitted_at IS NOT NULL ORDER BY qa.submitted_at DESC LIMIT 1) latest_score
    FROM quizzes q LEFT JOIN quiz_sections qs ON qs.quiz_id=q.id LEFT JOIN quiz_questions qq ON qq.section_id=qs.id
    WHERE q.course_id=? AND q.is_published=1 GROUP BY q.id ORDER BY q.sort_order,q.id");
$stmt->execute([$_SESSION['user_id'], $courseId]);
$quizzes = $stmt->fetchAll();
$page_title = 'Trắc nghiệm: ' . $course['title'];
require_once '../includes/header.php';
?>
<style>
.student-quiz-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}.student-quiz-card{display:flex;flex-direction:column;gap:12px}.student-quiz-card .btn{margin-top:auto}
@media(max-width:1000px){.student-quiz-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:650px){.student-quiz-grid{grid-template-columns:1fr}}
</style>
<a href="<?php echo htmlspecialchars(friendlyUrl('course.php','course',$course['slug']));?>" style="color:var(--primary)"><i class='bx bx-arrow-back'></i> Quay lại khóa học</a>
<h1><i class='bx bx-list-check'></i> Trắc nghiệm — <?php echo htmlspecialchars($course['title']);?></h1>
<div class="student-quiz-grid">
<?php foreach($quizzes as $quiz):?>
    <article class="box student-quiz-card">
        <span style="color:var(--primary)"><i class='bx bx-check-square'></i> Bài trắc nghiệm</span>
        <h2 style="margin:0"><?php echo htmlspecialchars($quiz['title']);?></h2>
        <p style="color:var(--text-muted)"><?php echo nl2br(htmlspecialchars($quiz['description']??''));?></p>
        <div><?php echo (int)$quiz['question_count'];?> câu hỏi</div>
        <div><i class='bx bx-time'></i> <?php echo (int)$quiz['duration_minutes'];?> phút</div>
        <?php if($quiz['latest_score']!==null):?><div style="color:var(--success)">Điểm gần nhất: <strong><?php echo htmlspecialchars($quiz['latest_score']);?>/10</strong></div><?php endif;?>
        <a href="<?php echo htmlspecialchars(friendlyUrl('quiz.php','quiz',$quiz['slug']));?>" class="btn btn-primary"><i class='bx bx-play'></i> <?php echo $quiz['latest_score']!==null?'Làm lại':'Bắt đầu làm';?></a>
    </article>
<?php endforeach;?>
<?php if(!$quizzes):?><div class="box empty-state">Khóa học chưa có bài trắc nghiệm nào được mở.</div><?php endif;?>
</div>
<?php require_once '../includes/footer.php';?>
