<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/authorization.php';
secureSessionStart();
if (empty($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['student', 'teacher', 'administrative_staff', 'admin'], true)) {
    header('Location: index.php');
    exit;
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/drive_helper.php';

$kind = (string) ($_GET['kind'] ?? '');
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$module = trim((string) ($_GET['module'] ?? ''));
$previewRequested = (string) ($_GET['preview'] ?? '') === '1';
if (!$id || !in_array($kind, ['submission', 'prompt', 'attachment', 'course_material'], true)) {
    http_response_code(400);
    exit('Yêu cầu tải file không hợp lệ.');
}

$renderMaterialPreviewGuide = static function (): void {
    http_response_code(502);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    echo <<<'HTML'
<!doctype html>
<html lang="vi"><head><meta charset="utf-8"><title>Chưa thể mở tài liệu</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;font:16px/1.55 Arial,sans-serif;background:#f8fafc;color:#172033}.guide{max-width:530px;margin:24px;padding:28px;border:1px solid #dbe5f0;border-radius:16px;background:#fff;box-shadow:0 12px 32px rgba(15,23,42,.1)}h1{margin:0 0 10px;font-size:21px}p{margin:0 0 16px;color:#536176}ol{margin:0;padding-left:24px;color:#334155}button{margin-top:20px;padding:10px 16px;border:0;border-radius:9px;background:#2563eb;color:#fff;font:inherit;font-weight:700;cursor:pointer}
</style></head><body><main class="guide"><h1>Tài liệu đang tạm thời chưa mở được</h1><p>Đừng lo, bạn có thể làm theo các bước sau:</p><ol><li>Nhấn <strong>Tải lại tài liệu</strong> bên dưới.</li><li>Nếu vẫn chưa xem được, dùng nút <strong>Mở toàn màn hình</strong> hoặc <strong>Tải về</strong> ở dưới khung tài liệu.</li><li>Nếu lỗi tiếp diễn, hãy báo cho giáo viên để kiểm tra lại file.</li></ol><button type="button" onclick="window.location.reload()">Tải lại tài liệu</button></main></body></html>
HTML;
    exit;
};

$role = (string) $_SESSION['user_role'];
$userId = (int) $_SESSION['user_id'];
$driveId = '';
$fileName = 'download.bin';

if ($kind === 'submission') {
    $stmt = $pdo->prepare(
        'SELECT s.*, a.teacher_id, a.course_id
         FROM submissions s JOIN assignments a ON a.id=s.assignment_id
         WHERE s.id=? LIMIT 1'
    );
    $stmt->execute([$id]);
    $submission = $stmt->fetch();
    $allowed = $submission && authorizationCanDownloadSubmission($role, $userId, (int) $submission['teacher_id'], (int) $submission['student_id']);
    if (!$allowed && $submission && in_array($role, ['teacher', 'administrative_staff'], true) && $submission['course_id'] !== null) {
        $allowed = authorizationUserCanManageCourse($pdo, (int) $submission['course_id'], $role, $userId);
    }
    if (!$allowed) {
        http_response_code(403);
        exit('Bạn không có quyền tải file này.');
    }
    $files = json_decode((string) ($submission['submitted_files'] ?? '{}'), true) ?: [];
    if ($module !== '' && isset($files[$module])) {
        $driveId = (string) ($files[$module]['drive_id'] ?? '');
        $fileName = (string) ($files[$module]['name'] ?? $fileName);
    } else {
        $driveId = (string) $submission['file_drive_id'];
        $fileName = (string) $submission['file_name'];
    }
} elseif ($kind === 'course_material') {
    $stmt = $pdo->prepare(
        'SELECT cm.id, cm.course_id, cm.file_drive_id, cm.file_name
         FROM course_materials cm
         WHERE cm.id=? AND cm.material_type=\'pdf\' LIMIT 1'
    );
    $stmt->execute([$id]);
    $material = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$material) {
        http_response_code(404);
        exit('Không tìm thấy học liệu PDF.');
    }
    $allowed = $role === 'admin'
        || (in_array($role, ['teacher', 'administrative_staff'], true)
            && authorizationUserCanManageCourse($pdo, (int) $material['course_id'], $role, $userId))
        || ($role === 'student' && authorizationStudentIsEnrolled($pdo, $userId, (int) $material['course_id']));
    if (!$allowed) {
        http_response_code(403);
        exit('Bạn không có quyền tải học liệu này.');
    }
    $driveId = (string) ($material['file_drive_id'] ?? '');
    $fileName = (string) ($material['file_name'] ?? $fileName);
} else {
    $assignment = authorizationFindAccessibleAssignment($pdo, (int) $id, $role, $userId);
    if (!$assignment) {
        http_response_code(404);
        exit('Không tìm thấy bài tập.');
    }
    $allowed = $role === 'admin';
    if (in_array($role, ['teacher', 'administrative_staff'], true)) {
        $allowed = $assignment['course_id'] !== null
            ? authorizationUserCanManageCourse($pdo, (int) $assignment['course_id'], $role, $userId)
            : (int) $assignment['teacher_id'] === $userId;
    }
    if ($role === 'student') {
        if ($assignment['course_id'] === null) {
            $allowed = true;
        } else {
            $allowed = authorizationStudentIsEnrolled($pdo, $userId, (int) $assignment['course_id']);
        }
    }
    if (!$allowed) {
        http_response_code(403);
        exit('Bạn không có quyền tải file này.');
    }
    if ($kind === 'prompt') {
        $driveId = (string) ($assignment['prompt_file_drive_id'] ?? '');
        $fileName = (string) ($assignment['prompt_file_name'] ?? $fileName);
    } else {
        $attachments = json_decode((string) ($assignment['attachments'] ?? '[]'), true) ?: [];
        $attachmentIndex = filter_input(INPUT_GET, 'index', FILTER_VALIDATE_INT);
        if ($attachmentIndex === false || !isset($attachments[$attachmentIndex])) {
            http_response_code(404);
            exit('Không tìm thấy file đính kèm.');
        }
        $driveId = (string) ($attachments[$attachmentIndex]['drive_id'] ?? '');
        $fileName = (string) ($attachments[$attachmentIndex]['name'] ?? $fileName);
    }
}

if ($driveId === '') {
    http_response_code(404);
    exit('File không còn tồn tại.');
}

$temporaryPath = null;
if (str_starts_with($driveId, 'local_')) {
    $uploadsRoot = realpath(__DIR__ . '/uploads');
    $candidate = realpath(__DIR__ . '/uploads/' . ltrim(substr($driveId, 6), '/\\'));
    if (!$uploadsRoot || !$candidate || !str_starts_with($candidate, $uploadsRoot . DIRECTORY_SEPARATOR) || !is_file($candidate)) {
        http_response_code(404);
        exit('File không còn tồn tại.');
    }
    $sourcePath = $candidate;
} else {
    $temporaryPath = tempnam(sys_get_temp_dir(), 'lms_download_');
    if (!$temporaryPath || !downloadFromDrive($driveId, $temporaryPath)) {
        if ($temporaryPath) @unlink($temporaryPath);
        if ($kind === 'course_material' && $previewRequested) {
            $renderMaterialPreviewGuide();
        }
        http_response_code(502);
        exit('Không thể tải file từ kho lưu trữ.');
    }
    $sourcePath = $temporaryPath;
}

$safeName = basename(str_replace('\\', '/', $fileName)) ?: 'download.bin';
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($sourcePath) ?: 'application/octet-stream';
$previewMimeTypes = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'text/plain',
];
$canPreviewInline = $previewRequested && in_array($mime, $previewMimeTypes, true);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($sourcePath));
header(
    'Content-Disposition: ' . ($canPreviewInline ? 'inline' : 'attachment')
    . "; filename=\"download\"; filename*=UTF-8''" . rawurlencode($safeName)
);
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\' data:; style-src \'unsafe-inline\'; sandbox');
header('Cache-Control: private, no-store, max-age=0');
readfile($sourcePath);
if ($temporaryPath) @unlink($temporaryPath);
