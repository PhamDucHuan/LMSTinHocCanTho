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
if (!$id || !in_array($kind, ['submission', 'prompt', 'attachment'], true)) {
    http_response_code(400);
    exit('Yêu cầu tải file không hợp lệ.');
}

$role = (string) $_SESSION['user_role'];
$userId = (int) $_SESSION['user_id'];
$driveId = '';
$fileName = 'download.bin';

if ($kind === 'submission') {
    $stmt = $pdo->prepare(
        'SELECT s.*, a.teacher_id
         FROM submissions s JOIN assignments a ON a.id=s.assignment_id
         WHERE s.id=? LIMIT 1'
    );
    $stmt->execute([$id]);
    $submission = $stmt->fetch();
    $allowed = $submission && authorizationCanDownloadSubmission($role, $userId, (int) $submission['teacher_id'], (int) $submission['student_id']);
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
} else {
    $assignment = authorizationFindAccessibleAssignment($pdo, (int) $id, $role, $userId);
    if (!$assignment) {
        http_response_code(404);
        exit('Không tìm thấy bài tập.');
    }
    $allowed = $role === 'admin'
        || (in_array($role, ['teacher', 'administrative_staff'], true) && (int) $assignment['teacher_id'] === $userId);
    if ($role === 'student') {
        if ($assignment['course_id'] === null) {
            $allowed = true;
        } else {
            $access = $pdo->prepare(
                'SELECT 1 FROM course_enrollments WHERE course_id=? AND student_id=? LIMIT 1'
            );
            $access->execute([(int) $assignment['course_id'], $userId]);
            $allowed = (bool) $access->fetchColumn();
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
