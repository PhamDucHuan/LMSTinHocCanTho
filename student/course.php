<?php
require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/friendly_urls.php';
require_once '../includes/authorization.php';
/** @var PDO $pdo */

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ../index.php');
    exit;
}
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

if (!authorizationStudentIsEnrolled($pdo, $studentId, (int) $courseId)) {
    http_response_code(403);
    exit('Bạn chưa được phân vào lớp học của khóa này.');
}

$stmt = $pdo->prepare("
    SELECT c.*, COALESCE((SELECT GROUP_CONCAT(teacher.name ORDER BY teacher.name SEPARATOR ', ') FROM course_teachers ct JOIN users teacher ON teacher.id=ct.teacher_id WHERE ct.course_id=c.id), u.name) AS teacher_name,
           (SELECT COUNT(*) FROM assignments a WHERE a.course_id = c.id AND a.type = 'assignment') AS assignment_count,
           (SELECT COUNT(*) FROM assignments a WHERE a.course_id = c.id AND a.type = 'exam') AS exam_count,
           (SELECT COUNT(*) FROM quizzes q WHERE q.course_id = c.id AND q.is_published = 1) AS quiz_count
    FROM courses c
    JOIN users u ON u.id = c.teacher_id
    WHERE c.id = ?
");
$stmt->execute([$courseId]);
$course = $stmt->fetch();
if (!$course) {
    http_response_code(404);
    exit('Khóa học không tồn tại.');
}

$materialStmt = $pdo->prepare('SELECT id, title, material_type, file_name, youtube_video_id FROM course_materials WHERE course_id=? ORDER BY sort_order, id');
$materialStmt->execute([$courseId]);
$courseMaterials = $materialStmt->fetchAll(PDO::FETCH_ASSOC);
$pdfMaterials = array_values(array_filter($courseMaterials, static fn(array $material): bool => $material['material_type'] === 'pdf'));
$videoMaterials = array_values(array_filter($courseMaterials, static fn(array $material): bool => $material['material_type'] === 'video'));

$page_title = $course['title'];
require_once '../includes/header.php';
?>

<style>
    .course-detail-heading { display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap;margin-bottom:24px;padding-bottom:18px;border-bottom:1px solid rgba(255,255,255,.12); }
    .course-detail-actions { min-width:220px;display:flex;justify-content:flex-end; }
    .course-detail-layout { display:grid;grid-template-columns:minmax(0,2fr) minmax(260px,1fr);gap:22px; }
    .course-description { white-space:normal;line-height:1.8;color:rgba(255,255,255,.84);overflow-wrap:anywhere; }
    .course-info-row { display:flex;align-items:center;gap:10px;padding:12px 0;border-bottom:1px solid rgba(255,255,255,.08); }
    .course-materials { margin-bottom:22px; }
    .course-material-group + .course-material-group { margin-top:28px;padding-top:24px;border-top:1px solid rgba(255,255,255,.1); }
    .course-material-group-title { display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px; }
    .course-material-group-title h3 { margin:0;font-size:18px; }
    .course-material-group-title span { color:var(--text-muted);font-size:13px; }
    .course-material-grid { display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px; }
    .course-material-card { border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:16px;background:rgba(10,28,51,.38); }
    .course-material-card h3 { margin:0 0 8px;font-size:17px;overflow-wrap:anywhere; }
    .course-material-card p { margin:0 0 13px;color:var(--text-muted);font-size:13px;overflow-wrap:anywhere; }
    .course-material-pdf { height:560px;overflow:hidden;border:1px solid rgba(255,255,255,.12);border-radius:10px;background:#fff;margin:12px 0; }
    .course-material-pdf iframe { display:block;width:100%;height:100%;border:0; }
    .course-material-video { position:relative;aspect-ratio:16/9;overflow:hidden;border-radius:10px;background:#050b16;margin-top:12px; }
    .course-material-video iframe { position:absolute;inset:0;width:100%;height:100% !important;border:0; }
    @media(max-width:800px) { .course-detail-layout{grid-template-columns:1fr}.course-detail-actions{width:100%;justify-content:stretch}.course-detail-actions>*{width:100%}.course-material-pdf{height:420px} }
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
        <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;">
        <?php if ((int) $course['quiz_count'] > 0): ?>
            <a href="<?php echo htmlspecialchars(friendlyUrl('quizzes.php','course',$course['slug'])); ?>" class="btn btn-outline"><i class='bx bx-list-check'></i> Làm trắc nghiệm</a>
        <?php endif; ?>
        <a href="<?php echo htmlspecialchars(friendlyUrl('assignments.php','course',$course['slug'])); ?>" class="btn btn-primary"><i class='bx bx-book-open'></i> Xem bài tập / bài thi</a>
        </div>
    </div>
</div>

<?php if ($courseMaterials): ?>
<section class="box course-materials">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
        <h2 style="margin:0;"><i class='bx bx-book-content'></i> Học liệu</h2>
        <span style="color:var(--text-muted);font-size:13px;"><?php echo count($courseMaterials); ?> tài liệu / video</span>
    </div>
    <?php if ($pdfMaterials): ?>
    <div class="course-material-group">
        <div class="course-material-group-title"><h3><i class='bx bx-file' style="color:#f87171;"></i> Giáo trình PDF</h3><span><?php echo count($pdfMaterials); ?> tài liệu</span></div>
        <div class="course-material-grid">
        <?php foreach ($pdfMaterials as $material): ?>
            <article class="course-material-card">
                <h3><i class='bx bx-file' style="color:#f87171;"></i> <?php echo htmlspecialchars((string) $material['title']); ?></h3>
                <p><?php echo htmlspecialchars((string) ($material['file_name'] ?: 'Giáo trình PDF')); ?></p>
                <div class="course-material-pdf"><iframe src="../download.php?kind=course_material&amp;id=<?php echo (int) $material['id']; ?>&amp;preview=1" title="<?php echo htmlspecialchars((string) $material['title'], ENT_QUOTES, 'UTF-8'); ?>" loading="lazy"></iframe></div>
                <div style="display:flex;gap:9px;flex-wrap:wrap;"><a class="btn btn-outline" target="_blank" rel="noopener" href="../download.php?kind=course_material&amp;id=<?php echo (int) $material['id']; ?>&amp;preview=1"><i class='bx bx-expand'></i> Mở toàn màn hình</a><a class="btn btn-outline" href="../download.php?kind=course_material&amp;id=<?php echo (int) $material['id']; ?>"><i class='bx bx-download'></i> Tải về</a></div>
            </article>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($videoMaterials): ?>
    <div class="course-material-group">
        <div class="course-material-group-title"><h3><i class='bx bxl-youtube' style="color:#ff0033;"></i> Video hướng dẫn</h3><span><?php echo count($videoMaterials); ?> video</span></div>
        <div class="course-material-grid">
        <?php foreach ($videoMaterials as $material): ?>
            <article class="course-material-card">
                <h3><i class='bx bxl-youtube' style="color:#ff0033;"></i> <?php echo htmlspecialchars((string) $material['title']); ?></h3>
                <p>Video hướng dẫn từ YouTube</p>
                <div class="course-material-video"><iframe src="https://www.youtube-nocookie.com/embed/<?php echo rawurlencode((string) $material['youtube_video_id']); ?>?rel=0" title="<?php echo htmlspecialchars((string) $material['title'], ENT_QUOTES, 'UTF-8'); ?>" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>
            </article>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

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
