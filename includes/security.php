<?php
require_once __DIR__ . '/../config/env.php';

function secureSessionStart(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        session_start();
    }
    if (!empty($_SESSION['user_id'])) {
        require_once __DIR__ . '/../config/database.php';
        require_once __DIR__ . '/account_lock.php';
        terminateLockedAccountSession($GLOBALS['pdo']);
        touchUserOnlinePresence($GLOBALS['pdo'], (int) $_SESSION['user_id']);
    }
}

/** A one-bit online flag (0=offline, 1=online) plus a heartbeat timestamp. */
function touchUserOnlinePresence(PDO $pdo, int $userId): void
{
    if ($userId <= 0) return;
    $now = time();
    if (($GLOBALS['_lms_presence_touched_at'] ?? 0) + 45 > $now) return;
    $GLOBALS['_lms_presence_touched_at'] = $now;
    try {
        $statement = $pdo->prepare(
            'UPDATE users SET online_status = 1, last_seen_at = NOW()
             WHERE id = ? AND (online_status = 0 OR last_seen_at IS NULL OR last_seen_at < DATE_SUB(NOW(), INTERVAL 45 SECOND))'
        );
        $statement->execute([$userId]);
    } catch (Throwable $error) {
        error_log('Cannot update user online presence: ' . $error->getMessage());
    }
}

function markUserOffline(PDO $pdo, int $userId): void
{
    if ($userId <= 0) return;
    try {
        $pdo->prepare('UPDATE users SET online_status = 0 WHERE id = ?')->execute([$userId]);
    } catch (Throwable $error) {
        error_log('Cannot mark user offline: ' . $error->getMessage());
    }
}

function csrfToken(): string
{
    secureSessionStart();
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function verifyCsrfToken(?string $token = null): void
{
    secureSessionStart();
    $token ??= $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('Yêu cầu đã hết hạn hoặc không hợp lệ. Vui lòng tải lại trang.');
    }
}

function requireRole(array $roles): void
{
    secureSessionStart();
    if (empty($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', $roles, true)) {
        header('Location: ../index.php'); exit;
    }
}

function validateUploadedFile(array $file, array $extensions, int $maxBytes = 20971520): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) throw new RuntimeException('File tải lên không hợp lệ.');
    if (($file['size'] ?? 0) <= 0 || $file['size'] > $maxBytes) throw new RuntimeException('Dung lượng file không hợp lệ (tối đa ' . round($maxBytes / 1048576) . ' MB).');
    $original = basename(str_replace('\\', '/', (string) $file['name']));
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($extension, $extensions, true)) throw new RuntimeException('Định dạng file không được hỗ trợ.');
    $mime = class_exists(finfo::class)
        ? (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name'])
        : null;
    $mimeByExtension = [
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/x-zip-compressed', 'application/vnd.ms-office', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel', 'application/zip', 'application/x-zip-compressed', 'application/vnd.ms-office', 'application/octet-stream'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/vnd.ms-powerpoint', 'application/zip', 'application/x-zip-compressed', 'application/vnd.ms-office', 'application/octet-stream'],
        'pdf' => ['application/pdf'],
        'zip' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        'rar' => ['application/vnd.rar', 'application/x-rar', 'application/x-rar-compressed', 'application/octet-stream'],
        '7z' => ['application/x-7z-compressed', 'application/octet-stream'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'bmp' => ['image/bmp', 'image/x-ms-bmp'],
    ];
    if ($mime && isset($mimeByExtension[$extension]) && !in_array($mime, $mimeByExtension[$extension], true)) {
        // MIME của các file Office Open XML thường bị XAMPP/Windows nhận dạng khác nhau.
        // Kiểm tra cấu trúc gói Office thật thay vì chỉ tin MIME hoặc phần mở rộng.
        $officeEntryByExtension = [
            'docx' => 'word/document.xml',
            'xlsx' => 'xl/workbook.xml',
            'pptx' => 'ppt/presentation.xml',
        ];
        $isValidOfficePackage = false;
        if (isset($officeEntryByExtension[$extension]) && class_exists(ZipArchive::class)) {
            $archive = new ZipArchive();
            if ($archive->open($file['tmp_name']) === true) {
                $isValidOfficePackage = $archive->locateName('[Content_Types].xml') !== false
                    && $archive->locateName($officeEntryByExtension[$extension]) !== false;
                $archive->close();
            }
        }
        if (!$isValidOfficePackage) {
            throw new RuntimeException("Nội dung file không khớp với phần mở rộng .{$extension} (nhận dạng: {$mime}).");
        }
    }
    return [
        'original_name' => $original,
        'extension' => $extension,
        'tmp_name' => $file['tmp_name'],
        'mime' => $mime,
        'size' => (int) $file['size'],
    ];
}

function aiServiceUrl(string $path): string
{
    return rtrim(envValue('AI_SERVICE_URL', 'http://127.0.0.1:8000'), '/') . '/' . ltrim($path, '/');
}

function aiServiceHeaders(): array
{
    $headers = ['Content-Type: application/json'];
    $key = envValue('AI_SERVICE_KEY', '');
    if ($key !== '') $headers[] = 'X-API-Key: ' . $key;
    return $headers;
}
