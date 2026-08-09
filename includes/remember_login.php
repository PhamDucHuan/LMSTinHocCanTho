<?php

const LMS_REMEMBER_COOKIE = 'lms_google_remember';
const LMS_REMEMBER_DAYS = 30;

function ensureRememberLoginTable(PDO $pdo): void
{
    // Bảng được quản lý bởi database/migrate.php.
}

function rememberCookieOptions(int $expires): array
{
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function clearRememberLoginCookie(): void
{
    setcookie(LMS_REMEMBER_COOKIE, '', rememberCookieOptions(time() - 3600));
    unset($_COOKIE[LMS_REMEMBER_COOKIE]);
}

function parseRememberLoginCookie(?string $cookie = null): ?array
{
    $cookie ??= $_COOKIE[LMS_REMEMBER_COOKIE] ?? '';
    if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', (string) $cookie, $matches)) {
        return null;
    }
    return ['selector' => $matches[1], 'validator' => $matches[2]];
}

function issueRememberLoginToken(PDO $pdo, int $userId): void
{
    ensureRememberLoginTable($pdo);
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $expires = time() + (LMS_REMEMBER_DAYS * 86400);

    $pdo->prepare("DELETE FROM user_remember_tokens WHERE user_id = ? OR expires_at <= NOW()")
        ->execute([$userId]);
    $pdo->prepare(
        "INSERT INTO user_remember_tokens (user_id, selector, token_hash, expires_at)
         VALUES (?, ?, ?, ?)"
    )->execute([
        $userId,
        $selector,
        hash('sha256', $validator),
        date('Y-m-d H:i:s', $expires),
    ]);

    setcookie(LMS_REMEMBER_COOKIE, $selector . ':' . $validator, rememberCookieOptions($expires));
}

function restoreRememberedGoogleLogin(PDO $pdo): bool
{
    if (!empty($_SESSION['user_id'])) return true;
    $token = parseRememberLoginCookie();
    if (!$token) {
        if (isset($_COOKIE[LMS_REMEMBER_COOKIE])) clearRememberLoginCookie();
        return false;
    }

    ensureRememberLoginTable($pdo);
    $stmt = $pdo->prepare(
        "SELECT rt.user_id, rt.token_hash, u.name, u.role, u.avatar_url, u.google_id, u.is_locked, u.is_approved
         FROM user_remember_tokens rt
         INNER JOIN users u ON u.id = rt.user_id
         WHERE rt.selector = ? AND rt.expires_at > NOW()
         LIMIT 1"
    );
    $stmt->execute([$token['selector']]);
    $user = $stmt->fetch();

    if (!$user || !empty($user['is_locked']) || empty($user['is_approved']) || empty($user['google_id'])
        || !hash_equals((string) $user['token_hash'], hash('sha256', $token['validator']))) {
        $pdo->prepare("DELETE FROM user_remember_tokens WHERE selector = ?")->execute([$token['selector']]);
        clearRememberLoginCookie();
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['user_id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_avatar'] = $user['avatar_url'] ?? null;

    // Xoay token sau mỗi lần khôi phục để cookie cũ không thể tái sử dụng.
    $pdo->prepare("DELETE FROM user_remember_tokens WHERE selector = ?")->execute([$token['selector']]);
    issueRememberLoginToken($pdo, (int) $user['user_id']);
    return true;
}

function revokeRememberLogin(PDO $pdo): void
{
    $token = parseRememberLoginCookie();
    if ($token) {
        ensureRememberLoginTable($pdo);
        $pdo->prepare("DELETE FROM user_remember_tokens WHERE selector = ?")->execute([$token['selector']]);
    }
    clearRememberLoginCookie();
}

function redirectToRoleDashboard(string $role): void
{
    if ($role === 'admin') {
        header('Location: admin/dashboard.php');
    } elseif ($role === 'teacher') {
        header('Location: teacher/dashboard.php');
    } else {
        header('Location: student/dashboard.php');
    }
    exit;
}
