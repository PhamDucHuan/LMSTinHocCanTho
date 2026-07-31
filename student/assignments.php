<?php
require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/friendly_urls.php';
/** @var PDO $pdo */
ensureFriendlyUrls($pdo);

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['student', 'admin', 'teacher'], true)) {
    header('Location: ../index.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$isStaff = in_array($_SESSION['user_role'], ['admin', 'teacher'], true);
$selectedCourseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);
$selectedCourseId = $selectedCourseId === false ? null : $selectedCourseId;
$selectedCourseSlug = trim((string) ($_GET['course'] ?? ''));

if ($isStaff) {
    $courseStmt = $pdo->query("SELECT c.id, c.title, c.slug, c.description, COUNT(a.id) assignment_count FROM courses c LEFT JOIN assignments a ON a.course_id = c.id GROUP BY c.id, c.title, c.slug, c.description ORDER BY c.created_at DESC");
} else {
    $courseStmt = $pdo->prepare("SELECT c.id, c.title, c.slug, c.description, COUNT(a.id) assignment_count FROM courses c JOIN course_enrollments ce ON ce.course_id = c.id AND ce.student_id = ? LEFT JOIN assignments a ON a.course_id = c.id GROUP BY c.id, c.title, c.slug, c.description ORDER BY ce.enrolled_at DESC");
    $courseStmt->execute([$userId]);
}
$courses = $courseStmt->fetchAll();
if ($selectedCourseSlug !== '') {
    $selectedCourseId = null;
    foreach ($courses as $course) {
        if (hash_equals((string) $course['slug'], $selectedCourseSlug)) {
            $selectedCourseId = (int) $course['id'];
            break;
        }
    }
}
$generalAssignmentCount = (int) $pdo->query("SELECT COUNT(*) FROM assignments WHERE course_id IS NULL")->fetchColumn();

$selectedCourse = null;
$assignments = [];
if ($selectedCourseId !== null) {
    if ($selectedCourseId === 0) {
        $selectedCourse = ['id' => 0, 'title' => 'Bài tập chung', 'description' => 'Các bài tập không thuộc một khóa học cụ thể.'];
    } else {
        foreach ($courses as $course) {
            if ((int) $course['id'] === $selectedCourseId) {
                $selectedCourse = $course;
                break;
            }
        }
    }
    if (!$selectedCourse) {
        http_response_code(403);
        exit('Bạn không có quyền truy cập khóa học này.');
    }

    $where = $selectedCourseId === 0 ? 'a.course_id IS NULL' : 'a.course_id = ?';
    $stmt = $pdo->prepare("SELECT a.*, s.score, s.submitted_at FROM assignments a LEFT JOIN submissions s ON s.assignment_id = a.id AND s.student_id = ? WHERE {$where} ORDER BY a.created_at DESC");
    $params = [$userId];
    if ($selectedCourseId !== 0) $params[] = $selectedCourseId;
    $stmt->execute($params);
    $assignments = $stmt->fetchAll();
}

$page_title = $selectedCourse ? $selectedCourse['title'] : 'Chọn khóa học';
require_once '../includes/header.php';
?>

<style>
    .course-heading{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px;flex-wrap:wrap}
    .course-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:20px}
    .course-card{display:flex;flex-direction:column;min-height:210px;padding:24px;border-radius:16px;background:var(--glass-bg);border:1px solid rgba(255,255,255,.08);transition:.25s}
    .course-card:hover{transform:translateY(-4px);border-color:var(--primary)}
    .course-card>i{font-size:38px;color:var(--primary)}
    .course-card h3{margin:14px 0 8px}.course-card p{color:var(--text-muted);flex:1}
    .course-count{display:inline-flex;align-items:center;gap:6px;color:#7dd3fc;font-size:13px;margin-bottom:16px}
    .assignment-type{display:inline-flex;padding:4px 9px;border-radius:20px;font-size:12px;font-weight:700;margin-bottom:10px}
    .assignment-type.exam{color:#fca5a5;background:rgba(239,68,68,.16)}
    .assignment-type.normal{color:#7dd3fc;background:rgba(14,165,233,.16)}
    @media(max-width:1000px){.course-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:650px){.course-grid{grid-template-columns:1fr}}
</style>

<?php if ($selectedCourse === null): ?>
    <div class="course-heading"><div>
        <h2 style="margin:0 0 8px"><i class='bx bx-book-open'></i> Chọn khóa học để làm bài</h2>
        <p style="margin:0;color:var(--text-muted)">Bài tập và bài thi sẽ hiện sau khi bạn chọn một khóa học.</p>
    </div></div>
    <div class="course-grid">
        <?php foreach ($courses as $course): ?>
            <div class="course-card">
                <i class='bx bx-book-bookmark'></i>
                <h3><?php echo htmlspecialchars($course['title']); ?></h3>
                <p>
                    <?php
                    $courseDescription = trim((string) ($course['description'] ?? ''));
                    echo htmlspecialchars($courseDescription === ''
                        ? 'Chưa có mô tả cho khóa học này.'
                        : (mb_strlen($courseDescription) > 140 ? mb_substr($courseDescription, 0, 140) . '...' : $courseDescription));
                    ?>
                </p>
                <div class="course-count"><i class='bx bx-task'></i> <?php echo (int) $course['assignment_count']; ?> bài tập / bài thi</div>
                <a class="btn btn-primary" href="<?php echo htmlspecialchars(friendlyUrl('assignments.php','course',$course['slug'])); ?>">Chọn khóa học</a>
            </div>
        <?php endforeach; ?>
        <?php if ($generalAssignmentCount > 0): ?>
            <div class="course-card">
                <i class='bx bx-folder-open'></i><h3>Bài tập chung</h3>
                <p>Các bài tập không thuộc một khóa học cụ thể.</p>
                <div class="course-count"><i class='bx bx-task'></i> <?php echo $generalAssignmentCount; ?> bài tập / bài thi</div>
                <a class="btn btn-primary" href="?course_id=0">Xem bài tập chung</a>
            </div>
        <?php endif; ?>
    </div>
    <?php if (!$courses && $generalAssignmentCount === 0): ?>
        <div class="box" style="text-align:center;padding:45px;color:var(--text-muted)"><i class='bx bx-folder-open' style="font-size:48px"></i><p>Bạn chưa được ghi danh vào khóa học nào.</p></div>
    <?php endif; ?>
<?php else: ?>
    <div class="course-heading">
        <div>
            <a href="assignments.php" style="color:var(--primary)"><i class='bx bx-arrow-back'></i> Chọn khóa học khác</a>
            <h2 style="margin:12px 0 4px"><?php echo htmlspecialchars($selectedCourse['title']); ?></h2>
        </div>
        <?php if ((int) $selectedCourse['id'] > 0): ?>
            <a href="<?php echo htmlspecialchars(friendlyUrl('course.php','course',$selectedCourse['slug'])); ?>" class="btn btn-outline"><i class='bx bx-detail'></i> Xem mô tả khóa học</a>
        <?php endif; ?>
    </div>

    <?php
    $assignmentGroups = [
        [
            'title' => 'Bài tập',
            'icon' => 'bx-edit',
            'color' => 'var(--primary)',
            'items' => array_values(array_filter($assignments, fn($item) => ($item['type'] ?? 'assignment') !== 'exam')),
        ],
        [
            'title' => 'Bài thi',
            'icon' => 'bx-timer',
            'color' => 'var(--danger)',
            'items' => array_values(array_filter($assignments, fn($item) => ($item['type'] ?? 'assignment') === 'exam')),
        ],
    ];
    ?>

    <?php foreach ($assignmentGroups as $group): ?>
        <div style="display:flex;align-items:center;gap:10px;margin:34px 0 18px;padding-bottom:10px;border-bottom:1px solid rgba(255,255,255,.1);">
            <i class='bx <?php echo $group['icon']; ?>' style="font-size:26px;color:<?php echo $group['color']; ?>;"></i>
            <h2 style="margin:0;color:<?php echo $group['color']; ?>;"><?php echo $group['title']; ?> (<?php echo count($group['items']); ?>)</h2>
        </div>
        <?php if ($group['items']): ?>
            <div class="card-grid">
                <?php foreach ($group['items'] as $assignment): ?>
                    <?php
                    $isExam = ($assignment['type'] ?? 'assignment') === 'exam';
                    $moduleSettings = json_decode($assignment['module_settings'] ?? '[]', true);
                    $totalMax = 0;
                    if (is_array($moduleSettings)) foreach ($moduleSettings as $module) $totalMax += (float) ($module['max_score'] ?? 0);
                    if ($totalMax <= 0) $totalMax = 10;
                    ?>
                    <div class="card" <?php echo $isExam ? 'style="border-color:rgba(239,68,68,.3)"' : ''; ?>>
                        <span class="assignment-type <?php echo $isExam ? 'exam' : 'normal'; ?>"><i class='bx <?php echo $isExam ? 'bx-timer' : 'bx-edit'; ?>'></i>&nbsp; <?php echo $isExam ? 'Bài thi' : 'Bài tập'; ?></span>
                        <h3><?php echo htmlspecialchars($assignment['title']); ?></h3>
                        <?php if ($assignment['submitted_at']): ?>
                            <div class="status done"><i class='bx bx-check-circle'></i> Đã hoàn thành</div>
                            <p><strong>Điểm:</strong> <span style="color:var(--success);font-size:18px;font-weight:bold"><?php echo $assignment['score'] ?? 'Đang chấm...'; ?></span> / <?php echo $totalMax; ?></p>
                            <a href="<?php echo htmlspecialchars(friendlyUrl('assignment.php','assignment',$assignment['slug'])); ?>" class="btn btn-outline">Xem kết quả & nhận xét</a>
                        <?php else: ?>
                            <div class="status pending"><i class='bx bx-time'></i> Chưa hoàn thành</div>
                            <p><i class='bx bx-calendar'></i> <?php echo $isExam ? 'Hạn thi' : 'Hạn nộp'; ?>: <?php echo $assignment['due_date'] ? date('d/m/Y H:i', strtotime($assignment['due_date'])) : 'Không thời hạn'; ?></p>
                            <a href="<?php echo htmlspecialchars(friendlyUrl('assignment.php','assignment',$assignment['slug'])); ?>" class="btn" <?php echo $isExam ? 'style="background:var(--danger)"' : ''; ?>><?php echo $isExam ? 'Vào bài thi' : 'Vào làm bài'; ?></a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="box" style="padding:22px;text-align:center;color:var(--text-muted);">Chưa có <?php echo mb_strtolower($group['title']); ?> nào trong khóa học.</div>
        <?php endif; ?>
    <?php endforeach; ?>

<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
