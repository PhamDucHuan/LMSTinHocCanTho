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
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'pdf' => ['application/pdf'],
        'zip' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        'rar' => ['application/vnd.rar', 'application/x-rar', 'application/x-rar-compressed', 'application/octet-stream'],
        '7z' => ['application/x-7z-compressed', 'application/octet-stream'],
    ];
    if ($mime && isset($mimeByExtension[$extension]) && !in_array($mime, $mimeByExtension[$extension], true)) {
        throw new RuntimeException("Nội dung file không khớp với phần mở rộng .{$extension}.");
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
