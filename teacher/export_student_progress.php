<?php
declare(strict_types=1);

require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/simple_xlsx.php';

$actorId = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) ($_SESSION['user_role'] ?? '');
if ($actorId <= 0 || !in_array($role, ['teacher', 'administrative_staff', 'admin'], true)) {
    header('Location: ../index.php');
    exit;
}

$classId = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT) ?: (int) ($_GET['class_id'] ?? 0);
if (!$classId) {
    http_response_code(400);
    exit('Vui lòng chọn một lớp học trước khi xuất Excel.');
}

$isAdmin = $role === 'admin';
$classSql = "SELECT lc.id, lc.class_name, lc.course_id, c.title AS course_title
             FROM learning_classes lc
             JOIN courses c ON c.id=lc.course_id
             WHERE lc.id=? AND lc.status='active'";
$classParams = [(int) $classId];
if (!$isAdmin) {
    $classSql .= " AND ((c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))
                       OR lc.primary_teacher_id=?
                       OR EXISTS (SELECT 1 FROM learning_class_teachers lct WHERE lct.learning_class_id=lc.id AND lct.teacher_id=?))";
    array_push($classParams, $actorId, $actorId, $actorId, $actorId);
}
$classStmt = $pdo->prepare($classSql . ' LIMIT 1');
$classStmt->execute($classParams);
$class = $classStmt->fetch(PDO::FETCH_ASSOC);
if (!$class) {
    http_response_code(403);
    exit('Bạn không có quyền xuất kết quả của lớp này hoặc lớp không còn hoạt động.');
}

$progressStmt = $pdo->prepare(
    "SELECT
        u.id AS student_id,
        u.name AS student_name,
        u.email AS student_email,
        lcs.exam_date,
        (SELECT COUNT(*) FROM assignments a WHERE a.course_id=lc.course_id) AS assignment_total,
        (SELECT COUNT(DISTINCT s.assignment_id)
         FROM submissions s
         JOIN assignments a_done ON a_done.id=s.assignment_id
         WHERE a_done.course_id=lc.course_id AND s.student_id=u.id) AS assignment_completed,
        (SELECT AVG(s_avg.score)
         FROM submissions s_avg
         JOIN assignments a_avg ON a_avg.id=s_avg.assignment_id
         WHERE a_avg.course_id=lc.course_id AND s_avg.student_id=u.id AND s_avg.score IS NOT NULL) AS assignment_average,
        (SELECT COUNT(*) FROM quizzes q WHERE q.course_id=lc.course_id AND q.is_published=1) AS quiz_total,
        (SELECT COUNT(DISTINCT qa.quiz_id)
         FROM quiz_attempts qa
         JOIN quizzes q_done ON q_done.id=qa.quiz_id
         WHERE q_done.course_id=lc.course_id AND q_done.is_published=1
           AND qa.student_id=u.id AND qa.submitted_at IS NOT NULL) AS quiz_completed,
        NULL AS quiz_average
     FROM learning_class_students lcs
     JOIN learning_classes lc ON lc.id=lcs.learning_class_id
     JOIN users u ON u.id=lcs.student_id
     WHERE lc.id=?
     ORDER BY u.name, u.id"
);
$progressStmt->execute([(int) $class['id']]);
$students = $progressStmt->fetchAll(PDO::FETCH_ASSOC);

$quizAverageStmt = $pdo->prepare(
    "SELECT best.student_id, AVG(best.best_score) AS quiz_average
     FROM (
         SELECT qa.student_id, qa.quiz_id, MAX(qa.score) AS best_score
         FROM quiz_attempts qa
         JOIN quizzes q ON q.id=qa.quiz_id
         JOIN learning_class_students lcs ON lcs.student_id=qa.student_id
         WHERE lcs.learning_class_id=? AND q.course_id=? AND q.is_published=1
           AND qa.submitted_at IS NOT NULL AND qa.score IS NOT NULL
         GROUP BY qa.student_id, qa.quiz_id
     ) best
     GROUP BY best.student_id"
);
$quizAverageStmt->execute([(int) $class['id'], (int) $class['course_id']]);
$quizAverages = [];
foreach ($quizAverageStmt->fetchAll(PDO::FETCH_ASSOC) as $quizAverage) {
    $quizAverages[(int) $quizAverage['student_id']] = (float) $quizAverage['quiz_average'];
}

$rows = [];
$rows[] = ['height' => 30, 'cells' => [1 => ['value' => 'BÁO CÁO TIẾN ĐỘ HỌC VIÊN', 'style' => 1]]];
$rows[] = ['cells' => [1 => ['value' => 'Lớp: ' . $class['class_name'] . '  |  Khóa học: ' . $class['course_title'], 'style' => 2]]];
$rows[] = ['cells' => [1 => ['value' => 'Xuất lúc: ' . date('d/m/Y H:i') . '  |  Tổng học viên: ' . count($students), 'style' => 2]]];
$rows[] = ['cells' => []];
$headers = ['STT', 'Học viên', 'Email', 'Ngày thi', 'BT đã làm', 'Tổng BT', 'Tiến độ BT', 'Điểm TB BT', 'TN đã làm', 'Tổng TN', 'Tiến độ TN', 'Điểm TB TN'];
$headerCells = [];
foreach ($headers as $index => $header) $headerCells[$index + 1] = ['value' => $header, 'style' => 4];
$rows[] = ['height' => 32, 'cells' => $headerCells];

foreach ($students as $index => $student) {
    $assignmentTotal = (int) $student['assignment_total'];
    $assignmentCompleted = (int) $student['assignment_completed'];
    $quizTotal = (int) $student['quiz_total'];
    $quizCompleted = (int) $student['quiz_completed'];
    $assignmentPercent = $assignmentTotal > 0 ? round($assignmentCompleted / $assignmentTotal * 100, 1) : 0;
    $quizPercent = $quizTotal > 0 ? round($quizCompleted / $quizTotal * 100, 1) : 0;
    $quizAverage = $quizAverages[(int) $student['student_id']] ?? null;
    $rows[] = ['cells' => [
        1 => ['value' => $index + 1, 'style' => 6, 'type' => 'number'],
        2 => ['value' => $student['student_name'], 'style' => 5],
        3 => ['value' => $student['student_email'], 'style' => 5],
        4 => ['value' => $student['exam_date'] ? date('d/m/Y', strtotime((string) $student['exam_date'])) : 'Chưa đặt', 'style' => 6],
        5 => ['value' => $assignmentCompleted, 'style' => 6, 'type' => 'number'],
        6 => ['value' => $assignmentTotal, 'style' => 6, 'type' => 'number'],
        7 => ['value' => $assignmentPercent . '%', 'style' => 6],
        8 => ['value' => $student['assignment_average'] !== null ? number_format((float) $student['assignment_average'], 2) : 'Chưa có điểm', 'style' => 6],
        9 => ['value' => $quizCompleted, 'style' => 6, 'type' => 'number'],
        10 => ['value' => $quizTotal, 'style' => 6, 'type' => 'number'],
        11 => ['value' => $quizPercent . '%', 'style' => 6],
        12 => ['value' => $quizAverage !== null ? number_format($quizAverage, 2) : 'Chưa có điểm', 'style' => 6],
    ]];
}

$workbook = new SimpleXlsxWorkbook();
$workbook->addSheet(
    'Tiến độ lớp',
    $rows,
    [7, 28, 32, 14, 12, 10, 14, 14, 12, 10, 14, 14],
    ['A1:L1', 'A2:L2', 'A3:L3'],
    ['freeze_rows' => 5]
);

$temporaryFile = tempnam(sys_get_temp_dir(), 'lms_progress_');
if ($temporaryFile === false) {
    http_response_code(500);
    exit('Không thể tạo file Excel tạm.');
}

try {
    $workbook->save($temporaryFile);
    $fileLabel = preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $class['class_name']) ?: 'lop') ?: 'lop';
    $filename = 'tien-do-' . trim(strtolower($fileLabel), '-') . '-' . date('Y-m-d') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($temporaryFile));
    header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
    readfile($temporaryFile);
} catch (Throwable $error) {
    http_response_code(500);
    echo 'Không thể xuất file Excel: ' . htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8');
} finally {
    @unlink($temporaryFile);
}
exit;
