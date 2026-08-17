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

/**
 * Đọc trạng thái truy cập của tài khoản bằng một truy vấn duy nhất.
 * Kết quả chỉ được lưu trong vòng đời request hiện tại để các lần gọi
 * secureSessionStart(), requireRole() và csrfToken() không hỏi lại CSDL.
 *
 * @return array{locked: bool, approved: bool}
 */
function accountAccessStatus(PDO $pdo, int $userId): array
{
    static $requestCache = [];

    if (isset($requestCache[$userId])) {
        return $requestCache[$userId];
    }

    try {
        $stmt = $pdo->prepare('SELECT is_locked, is_approved FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $requestCache[$userId] = [
            // Tài khoản không còn tồn tại cũng không được tiếp tục dùng phiên cũ.
            'locked' => $row === false || (int) ($row['is_locked'] ?? 0) === 1,
            'approved' => $row !== false && (int) ($row['is_approved'] ?? 1) === 1,
        ];
    } catch (PDOException $error) {
        // Tương thích trong thời gian migration cột phân quyền chưa được chạy.
        $message = $error->getMessage();
        if (stripos($message, 'is_locked') !== false || stripos($message, 'is_approved') !== false) {
            return $requestCache[$userId] = [
                'locked' => isAccountLocked($pdo, $userId),
                'approved' => isAccountApproved($pdo, $userId),
            ];
        }
        throw $error;
    }
}

function terminateLockedAccountSession(PDO $pdo): void
{
    if (empty($_SESSION['user_id'])) return;
    $userId = (int) $_SESSION['user_id'];
    $status = accountAccessStatus($pdo, $userId);
    $locked = $status['locked'];
    $approved = $status['approved'];
    if (!$locked && $approved) return;
    $pendingName = (string) ($_SESSION['user_name'] ?? '');
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
    if ($locked) {
        $_SESSION['error'] = 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ quản trị viên.';
    } else {
        $_SESSION['pending_approval'] = ['name' => $pendingName];
    }
    $directory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    if (in_array(basename($directory), ['admin', 'teacher', 'student', 'account', 'includes'], true)) $directory = dirname($directory);
    header('Location: ' . rtrim($directory, '/') . ($locked ? '/index.php' : '/pending_approval.php'));
    exit;
}
