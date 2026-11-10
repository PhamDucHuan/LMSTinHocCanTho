<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';
secureSessionStart();

$actorId = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) ($_SESSION['user_role'] ?? '');
if ($actorId <= 0 || !in_array($role, ['admin', 'teacher', 'administrative_staff'], true)) {
    header('Location: ../index.php');
    exit;
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/teaching_progress_export.php';

$temporaryFile = false;
try {
    $classes = teachingProgressExportClasses($pdo, $actorId, $role, $_GET);
    $students = teachingProgressExportStudents($pdo, $classes);
    $workbook = teachingProgressExportWorkbook($classes, $students);
    $temporaryFile = tempnam(sys_get_temp_dir(), 'lms_teaching_progress_');
    if ($temporaryFile === false) throw new RuntimeException('Cannot create temporary XLSX.');
    $workbook->save($temporaryFile);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="tien-do-lich-day-' . date('Y-m-d') . '.xlsx"');
    header('Content-Length: ' . filesize($temporaryFile));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    readfile($temporaryFile);
} catch (InvalidArgumentException | DomainException $error) {
    http_response_code($error instanceof DomainException ? 403 : 400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $error->getMessage();
} catch (Throwable $error) {
    error_log('Teaching progress export failed: ' . $error->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Không thể xuất Excel tiến độ học tập. Vui lòng thử lại sau.';
} finally {
    if ($temporaryFile !== false) @unlink($temporaryFile);
}
exit;
