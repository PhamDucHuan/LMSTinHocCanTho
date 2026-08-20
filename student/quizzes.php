<?php
require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/friendly_urls.php';
/** @var PDO $pdo */
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ../index.php'); exit;
}
ensureFriendlyUrls($pdo);
$courseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);
$courseSlug = trim((string) ($_GET['course'] ?? ''));
$selectedCategory = preg_replace('/\s+/u', ' ', trim((string) ($_GET['category'] ?? ''))) ?: '';
if ($courseSlug !== '') {
    $slugStmt = $pdo->prepare('SELECT id FROM courses WHERE slug=?');
    $slugStmt->execute([$courseSlug]);
    $courseId = (int) $slugStmt->fetchColumn();
}
if (!$courseId) {
    $stmt = $pdo->query("
        SELECT c.id, c.title, c.slug, c.description, COUNT(q.id) AS quiz_count,
               COUNT(DISTINCT COALESCE(NULLIF(TRIM(q.category), ''), 'Chưa phân loại')) AS category_count
        FROM courses c
        JOIN quizzes q ON q.course_id=c.id AND q.is_published=1
        GROUP BY c.id,c.title,c.slug,c.description
        ORDER BY c.created_at DESC,c.id DESC
    ");
    $quizCourses = $stmt->fetchAll();
    $page_title = 'Làm trắc nghiệm';
    require_once '../includes/header.php';
    ?>
    <style>
    .quiz-course-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}.quiz-course-card{display:flex;flex-direction:column;gap:12px}.quiz-course-card .btn{margin-top:auto}
    @media(max-width:1000px){.quiz-course-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:650px){.quiz-course-grid{grid-template-columns:1fr}}
    </style>
    <h1><i class='bx bx-list-check'></i> Làm trắc nghiệm</h1>
    <p style="color:var(--text-muted)">Chọn khóa học trước, sau đó chọn danh mục trắc nghiệm phù hợp. Bạn không cần ghi danh khóa học để làm trắc nghiệm.</p>
    <div class="quiz-course-grid">
        <?php foreach($quizCourses as $quizCourse):?>
            <article class="box quiz-course-card">
                <i class='bx bx-book-open' style="font-size:38px;color:var(--primary)"></i>
                <h2 style="margin:0"><?php echo htmlspecialchars($quizCourse['title']);?></h2>
                <p style="color:var(--text-muted)"><?php echo htmlspecialchars(mb_strimwidth((string)($quizCourse['description']??''),0,140,'…','UTF-8'));?></p>
                <div><i class='bx bx-category-alt'></i> <strong><?php echo (int)$quizCourse['category_count'];?></strong> danh mục · <strong><?php echo (int)$quizCourse['quiz_count'];?></strong> bài trắc nghiệm</div>
                <a href="quizzes.php?course_id=<?php echo (int)$quizCourse['id'];?>" class="btn btn-primary"><i class='bx bx-right-arrow-alt'></i> Chọn khóa học</a>
            </article>
        <?php endforeach;?>
        <?php if(!$quizCourses):?><div class="box empty-state">Hiện chưa có bài trắc nghiệm nào đang mở.</div><?php endif;?>
    </div>
    <?php
    require_once '../includes/footer.php';
    exit;
}
$stmt = $pdo->prepare('SELECT c.title,c.slug FROM courses c WHERE c.id=?');
$stmt->execute([$courseId]);
$course = $stmt->fetch();
if (!$course) { http_response_code(404); exit('Khóa học không tồn tại.'); }
if ($selectedCategory === '') {
    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(TRIM(q.category), ''), 'Chưa phân loại') AS category,
               COUNT(q.id) AS quiz_count
        FROM quizzes q
        WHERE q.course_id=? AND q.is_published=1
        GROUP BY COALESCE(NULLIF(TRIM(q.category), ''), 'Chưa phân loại')
        ORDER BY category
    ");
    $stmt->execute([$courseId]);
    $quizCategories = $stmt->fetchAll();
    $page_title = 'Danh mục trắc nghiệm';
    require_once '../includes/header.php';
    ?>
    <style>
    .quiz-category-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}.quiz-category-card{position:relative;display:flex;flex-direction:column;gap:12px;min-height:210px;overflow:hidden}.quiz-category-card:after{content:"";position:absolute;right:-45px;bottom:-55px;width:150px;height:150px;border-radius:50%;background:color-mix(in srgb,var(--primary) 13%,transparent)}.quiz-category-icon{width:58px;height:58px;border-radius:16px;display:grid;place-items:center;background:color-mix(in srgb,var(--primary) 16%,transparent);color:var(--primary);font-size:32px}.quiz-category-card .btn{position:relative;z-index:1;margin-top:auto}
    @media(max-width:1000px){.quiz-category-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:650px){.quiz-category-grid{grid-template-columns:1fr}}
    </style>
    <a href="quizzes.php" style="color:var(--primary)"><i class='bx bx-arrow-back'></i> Tất cả khóa học</a>
    <h1><i class='bx bx-category-alt'></i> Danh mục trắc nghiệm — <?php echo htmlspecialchars($course['title']);?></h1>
    <p style="color:var(--text-muted)">Chọn danh mục trắc nghiệm bạn muốn làm trong khóa học này.</p>
    <div class="quiz-category-grid">
        <?php foreach($quizCategories as $category):?>
            <article class="box quiz-category-card">
                <div class="quiz-category-icon"><i class='bx bx-category'></i></div>
                <h2 style="margin:0"><?php echo htmlspecialchars($category['category']);?></h2>
                <div style="color:var(--text-muted)"><?php echo (int)$category['quiz_count'];?> bài trắc nghiệm đang mở</div>
                <a href="quizzes.php?course_id=<?php echo (int)$courseId;?>&amp;category=<?php echo rawurlencode($category['category']);?>" class="btn btn-primary"><i class='bx bx-right-arrow-alt'></i> Chọn danh mục</a>
            </article>
        <?php endforeach;?>
        <?php if(!$quizCategories):?><div class="box empty-state">Khóa học này chưa có danh mục trắc nghiệm nào đang mở.</div><?php endif;?>
    </div>
    <?php
    require_once '../includes/footer.php';
    exit;
}
$categorySql = $selectedCategory !== '' ? " AND COALESCE(NULLIF(TRIM(q.category), ''), 'Chưa phân loại')=?" : '';
$stmt = $pdo->prepare("SELECT q.*, COUNT(DISTINCT qs.id) section_count, COUNT(qq.id) question_count,
    (SELECT qa.score FROM quiz_attempts qa WHERE qa.quiz_id=q.id AND qa.student_id=? AND qa.submitted_at IS NOT NULL ORDER BY qa.submitted_at DESC LIMIT 1) latest_score
    FROM quizzes q LEFT JOIN quiz_sections qs ON qs.quiz_id=q.id LEFT JOIN quiz_questions qq ON qq.section_id=qs.id
    WHERE q.course_id=? AND q.is_published=1{$categorySql} GROUP BY q.id ORDER BY q.sort_order,q.id");
$queryParams = [$_SESSION['user_id'], $courseId];
if ($selectedCategory !== '') $queryParams[] = $selectedCategory;
$stmt->execute($queryParams);
$quizzes = $stmt->fetchAll();
$page_title = 'Trắc nghiệm: ' . $course['title'];
require_once '../includes/header.php';
?>
<style>
.student-quiz-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}.student-quiz-card{display:flex;flex-direction:column;gap:12px}.student-quiz-card .btn{margin-top:auto}
@media(max-width:1000px){.student-quiz-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:650px){.student-quiz-grid{grid-template-columns:1fr}}
</style>
<a href="quizzes.php?course_id=<?php echo (int)$courseId;?>" style="color:var(--primary)"><i class='bx bx-arrow-back'></i> Quay lại danh mục</a>
<h1><i class='bx bx-list-check'></i> Trắc nghiệm — <?php echo htmlspecialchars($course['title']);?> · <?php echo htmlspecialchars($selectedCategory);?></h1>
<div class="student-quiz-grid">
<?php foreach($quizzes as $quiz):?>
    <article class="box student-quiz-card">
        <span style="color:var(--primary)"><i class='bx bx-check-square'></i> <?php echo htmlspecialchars((string)($quiz['category'] ?? 'Chưa phân loại'));?></span>
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
