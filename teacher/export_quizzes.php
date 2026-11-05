<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';
secureSessionStart();
require_once __DIR__ . '/../includes/authorization.php';

$actorId = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) ($_SESSION['user_role'] ?? '');
if ($actorId <= 0 || !in_array($role, ['teacher', 'administrative_staff', 'admin'], true)) {
    header('Location: ../index.php');
    exit;
}

$courseId = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);
if (!$courseId || $courseId <= 0) {
    http_response_code(400);
    exit('Khóa học không hợp lệ.');
}

require_once __DIR__ . '/../config/database.php';
$course = authorizationFindManageableCourse($pdo, $courseId, $role, $actorId);
if (!$course) {
    http_response_code(403);
    exit('Bạn không có quyền xuất câu hỏi của khóa học này.');
}

require_once __DIR__ . '/../includes/quiz_export.php';
$temporaryFile = null;
try {
    $workbook = quizExportBuildWorkbook(quizExportLoadQuizzes($pdo, $courseId), dirname(__DIR__) . '/uploads');
    $temporaryFile = tempnam(sys_get_temp_dir(), 'lms_quizzes_');
    if ($temporaryFile === false) throw new RuntimeException('Không thể tạo file Excel tạm.');
    $workbook->save($temporaryFile);

    $filename = 'trac-nghiem-khoa-' . $courseId . '-' . date('Y-m-d') . '.xlsx';
    session_write_close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($temporaryFile));
    header('Cache-Control: private, no-cache, no-store, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    readfile($temporaryFile);
} catch (Throwable $error) {
    error_log('Quiz Excel export failed: ' . $error->getMessage());
    $_SESSION['error'] = 'Không thể xuất Excel. ' . ($error instanceof RuntimeException && !$error instanceof PDOException ? $error->getMessage() : 'Vui lòng thử lại sau.');
    header('Location: quizzes.php?course_id=' . $courseId);
} finally {
    if (is_string($temporaryFile)) @unlink($temporaryFile);
}
exit;
