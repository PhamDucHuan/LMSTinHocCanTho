<?php
require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';
/** @var PDO $pdo */

if (empty($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['teacher', 'admin'], true)) {
    header('Location: ../index.php');
    exit;
}

$courseFilter = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);
$conditions = [];
$parameters = [];
if ($_SESSION['user_role'] === 'teacher') {
    $conditions[] = 'c.teacher_id = ?';
    $parameters[] = (int) $_SESSION['user_id'];
}
if ($courseFilter) {
    $conditions[] = 'c.id = ?';
    $parameters[] = (int) $courseFilter;
}
$whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$courseSql = $_SESSION['user_role'] === 'teacher'
    ? 'SELECT id, title FROM courses WHERE teacher_id=? ORDER BY title'
    : 'SELECT id, title FROM courses ORDER BY title';
$courseStmt = $pdo->prepare($courseSql);
$courseStmt->execute($_SESSION['user_role'] === 'teacher' ? [(int) $_SESSION['user_id']] : []);
$availableCourses = $courseStmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT
        c.id course_id,
        c.title course_title,
        u.id student_id,
        u.name student_name,
        u.email student_email,
        (SELECT COUNT(*) FROM assignments a WHERE a.course_id=c.id) assignment_total,
        (SELECT COUNT(DISTINCT s.assignment_id)
         FROM submissions s
         JOIN assignments a_done ON a_done.id=s.assignment_id
         WHERE a_done.course_id=c.id AND s.student_id=u.id) assignment_completed,
        (SELECT AVG(s_avg.score)
         FROM submissions s_avg
         JOIN assignments a_avg ON a_avg.id=s_avg.assignment_id
         WHERE a_avg.course_id=c.id AND s_avg.student_id=u.id AND s_avg.score IS NOT NULL) assignment_average,
        (SELECT COUNT(*) FROM quizzes q WHERE q.course_id=c.id AND q.is_published=1) quiz_total,
        (SELECT COUNT(DISTINCT qa.quiz_id)
         FROM quiz_attempts qa
         JOIN quizzes q_done ON q_done.id=qa.quiz_id
         WHERE q_done.course_id=c.id AND q_done.is_published=1
           AND qa.student_id=u.id AND qa.submitted_at IS NOT NULL) quiz_completed,
        NULL quiz_average
     FROM course_enrollments ce
     JOIN courses c ON c.id=ce.course_id
     JOIN users u ON u.id=ce.student_id
     $whereSql
     ORDER BY c.title, u.name, u.id"
);
$stmt->execute($parameters);
$progressRows = $stmt->fetchAll();

$quizBestConditions = [
    'q.is_published=1',
    'qa.submitted_at IS NOT NULL',
    'qa.score IS NOT NULL',
];
$quizBestParameters = [];
if ($_SESSION['user_role'] === 'teacher') {
    $quizBestConditions[] = 'c.teacher_id=?';
    $quizBestParameters[] = (int) $_SESSION['user_id'];
}
if ($courseFilter) {
    $quizBestConditions[] = 'c.id=?';
    $quizBestParameters[] = (int) $courseFilter;
}
$quizBestStmt = $pdo->prepare(
    "SELECT best.course_id, best.student_id, AVG(best.best_score) quiz_average
     FROM (
         SELECT q.course_id, qa.student_id, qa.quiz_id, MAX(qa.score) best_score
         FROM quiz_attempts qa
         JOIN quizzes q ON q.id=qa.quiz_id
         JOIN courses c ON c.id=q.course_id
         WHERE " . implode(' AND ', $quizBestConditions) . "
         GROUP BY q.course_id, qa.student_id, qa.quiz_id
     ) best
     GROUP BY best.course_id, best.student_id"
);
$quizBestStmt->execute($quizBestParameters);
$quizBestAverages = [];
foreach ($quizBestStmt->fetchAll() as $quizAverageRow) {
    $quizBestAverages[(int) $quizAverageRow['course_id']][(int) $quizAverageRow['student_id']]
        = (float) $quizAverageRow['quiz_average'];
}

$courses = [];
foreach ($progressRows as $row) {
    $courseId = (int) $row['course_id'];
    if (!isset($courses[$courseId])) {
        $courses[$courseId] = [
            'title' => (string) $row['course_title'],
            'students' => [],
        ];
    }
    $assignmentTotal = (int) $row['assignment_total'];
    $assignmentCompleted = (int) $row['assignment_completed'];
    $quizTotal = (int) $row['quiz_total'];
    $quizCompleted = (int) $row['quiz_completed'];
    $row['assignment_percent'] = $assignmentTotal > 0
        ? min(100, round($assignmentCompleted / $assignmentTotal * 100, 1))
        : 0;
    $row['quiz_percent'] = $quizTotal > 0
        ? min(100, round($quizCompleted / $quizTotal * 100, 1))
        : 0;
    $row['quiz_average'] = $quizBestAverages[$courseId][(int) $row['student_id']] ?? null;
    $courses[$courseId]['students'][] = $row;
}

$page_title = 'Tiến độ Học viên';
require_once '../includes/header.php';
?>
<style>
    .progress-toolbar{display:flex;align-items:end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:22px}
    .progress-filter{display:flex;align-items:end;gap:10px;flex-wrap:wrap}
    .progress-filter label{display:grid;gap:6px;margin-bottom:0;color:var(--text-muted);font-size:13px}
    .progress-filter select{min-width:240px}
    .progress-filter select,.progress-filter .btn{height:40px;min-height:40px;box-sizing:border-box}
    .progress-filter .btn{align-self:end;justify-content:center;padding:0 20px;white-space:nowrap;transform:none}
    .course-progress{margin-bottom:22px}
    .course-progress-heading{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px}
    .course-progress-heading h2{margin:0}
    .progress-cell{min-width:180px}
    .progress-cell-head{display:flex;justify-content:space-between;gap:8px;margin-bottom:7px;font-size:13px}
    .progress-track{height:9px;border-radius:999px;background:rgba(148,163,184,.18);overflow:hidden}
    .progress-fill{height:100%;border-radius:inherit;background:linear-gradient(90deg,var(--primary),#38bdf8)}
    .progress-fill.quiz{background:linear-gradient(90deg,#10b981,#34d399)}
    .average-score{font-size:18px;font-weight:800;color:var(--success);white-space:nowrap}
    .no-score{color:var(--text-muted)}
    @media(max-width:700px){
        .progress-filter,.progress-filter label,.progress-filter select,.progress-filter .btn{width:100%}
        .progress-filter .btn{transform:none}
    }
</style>

<div class="box">
    <div class="progress-toolbar">
        <div>
            <h2 style="margin:0 0 7px"><i class='bx bx-line-chart'></i> Tiến độ Học viên</h2>
            <p style="margin:0;color:var(--text-muted)">Theo dõi mức độ hoàn thành và điểm trung bình của từng học viên.</p>
        </div>
        <form method="GET" class="progress-filter">
            <label>
                Khóa học
                <select name="course_id" onchange="this.form.submit()">
                    <option value="">Tất cả khóa học</option>
                    <?php foreach ($availableCourses as $filterCourse): ?>
                        <option value="<?php echo (int) $filterCourse['id']; ?>" <?php echo (int) $courseFilter === (int) $filterCourse['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $filterCourse['title'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($courseFilter): ?>
                <a class="btn btn-outline" href="student_progress.php"><i class='bx bx-reset'></i> Xóa lọc</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php foreach ($courses as $course): ?>
    <section class="box course-progress">
        <div class="course-progress-heading">
            <h2><i class='bx bx-book-open'></i> <?php echo htmlspecialchars($course['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <span style="color:var(--text-muted)"><?php echo count($course['students']); ?> học viên</span>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Học viên</th>
                        <th>Tiến độ bài tập</th>
                        <th>Điểm TB bài tập</th>
                        <th>Tiến độ trắc nghiệm</th>
                        <th>Điểm TB trắc nghiệm</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($course['students'] as $student): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars((string) $student['student_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <small style="display:block;color:var(--text-muted)"><?php echo htmlspecialchars((string) $student['student_email'], ENT_QUOTES, 'UTF-8'); ?></small>
                            </td>
                            <td>
                                <div class="progress-cell">
                                    <div class="progress-cell-head">
                                        <span><?php echo (int) $student['assignment_completed']; ?>/<?php echo (int) $student['assignment_total']; ?> bài</span>
                                        <strong><?php echo $student['assignment_percent']; ?>%</strong>
                                    </div>
                                    <div class="progress-track"><div class="progress-fill" style="width:<?php echo $student['assignment_percent']; ?>%"></div></div>
                                </div>
                            </td>
                            <td>
                                <?php if ($student['assignment_average'] !== null): ?>
                                    <span class="average-score"><?php echo number_format((float) $student['assignment_average'], 2); ?></span><span style="color:var(--text-muted)">/10</span>
                                <?php else: ?><span class="no-score">Chưa có điểm</span><?php endif; ?>
                            </td>
                            <td>
                                <div class="progress-cell">
                                    <div class="progress-cell-head">
                                        <span><?php echo (int) $student['quiz_completed']; ?>/<?php echo (int) $student['quiz_total']; ?> bài</span>
                                        <strong><?php echo $student['quiz_percent']; ?>%</strong>
                                    </div>
                                    <div class="progress-track"><div class="progress-fill quiz" style="width:<?php echo $student['quiz_percent']; ?>%"></div></div>
                                </div>
                            </td>
                            <td>
                                <?php if ($student['quiz_average'] !== null): ?>
                                    <span class="average-score"><?php echo number_format((float) $student['quiz_average'], 2); ?></span><span style="color:var(--text-muted)">/10</span>
                                <?php else: ?><span class="no-score">Chưa có điểm</span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>

<?php if (!$courses): ?>
    <div class="box empty-state">
        <i class='bx bx-user-x' style="font-size:48px;color:var(--text-muted)"></i>
        <h3>Chưa có dữ liệu học viên</h3>
        <p style="color:var(--text-muted)">Khóa học được chọn chưa có học viên đã ghi danh.</p>
    </div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
