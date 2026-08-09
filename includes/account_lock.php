<?php
declare(strict_types=1);

function isAccountLocked(PDO $pdo, int $userId): bool
{
    try {
        $stmt = $pdo->prepare('SELECT is_locked FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();
        return $value !== false && (int) $value === 1;
    } catch (PDOException $error) {
        if (stripos($error->getMessage(), 'is_locked') !== false) return false;
        throw $error;
    }
}

function isAccountApproved(PDO $pdo, int $userId): bool
{
    try {
        $stmt = $pdo->prepare('SELECT is_approved FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();
        return $value !== false && (int) $value === 1;
    } catch (PDOException $error) {
        // Giữ hệ thống hoạt động trong lúc migration chưa được chạy.
        if (stripos($error->getMessage(), 'is_approved') !== false) return true;
        throw $error;
    }
}

function terminateLockedAccountSession(PDO $pdo): void
{
    if (empty($_SESSION['user_id'])) return;
    $userId = (int) $_SESSION['user_id'];
    $locked = isAccountLocked($pdo, $userId);
    $approved = isAccountApproved($pdo, $userId);
    if (!$locked && $approved) return;
    try {
        $pdo->prepare('DELETE FROM user_remember_tokens WHERE user_id = ?')->execute([$userId]);
    } catch (Throwable $error) {
        error_log('Cannot revoke locked account tokens: ' . $error->getMessage());
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    setcookie('lms_google_remember', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
    session_destroy();
    session_start();
    $_SESSION['error'] = $locked
        ? 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ quản trị viên.'
        : 'Tài khoản đang chờ Admin duyệt. Bạn chưa thể truy cập hệ thống.';
    $directory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    if (in_array(basename($directory), ['admin', 'teacher', 'student', 'account', 'includes'], true)) $directory = dirname($directory);
    header('Location: ' . rtrim($directory, '/') . '/index.php');
    exit;
}
