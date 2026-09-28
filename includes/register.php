<?php
declare(strict_types=1);

/**
 * Endpoint dành riêng cho đăng ký tài khoản.
 *
 * Tách khỏi auth.php để việc tạo tài khoản không bị ảnh hưởng bởi các tiện ích
 * chỉ dùng sau đăng nhập (lịch sử đăng nhập, ghi nhớ thiết bị, nhật ký...).
 * Mọi phản hồi AJAX đều là JSON, kể cả khi hosting có lỗi CSDL.
 */
require_once __DIR__ . '/security.php';
secureSessionStart();
require_once __DIR__ . '/../config/database.php';

$isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
    || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');

$respond = static function (bool $ok, string $message, int $status = 200, ?string $field = null, array $extra = []) use ($isAjax): never {
    if ($isAjax) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        // array unpacking with string keys only works on PHP 8.1+. The LMS can
        // still run on PHP 8.0, so use array_merge to avoid throwing after the
        // account has already been inserted.
        echo json_encode(array_filter(array_merge([
            'ok' => $ok,
            'message' => $message,
            'field' => $field,
        ], $extra), static fn ($value) => $value !== null), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $_SESSION[$ok ? 'success' : 'error'] = $message;
    header('Location: ../' . ($ok ? 'pending_approval.php' : 'index.php'));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(false, 'Yêu cầu đăng ký không hợp lệ.', 405);
}

// verifyCsrfToken trả JSON riêng cho AJAX nếu phiên đã hết hạn.
verifyCsrfToken();

try {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($name === '') $respond(false, 'Vui lòng nhập họ và tên.', 422, 'name');
    if (mb_strlen($name) > 191) $respond(false, 'Họ và tên không được quá 191 ký tự.', 422, 'name');
    if ($email === '') $respond(false, 'Vui lòng nhập địa chỉ email.', 422, 'email');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $respond(false, 'Địa chỉ email không đúng định dạng.', 422, 'email');
    if (mb_strlen($email) > 191) $respond(false, 'Địa chỉ email không được quá 191 ký tự.', 422, 'email');
    if ($password === '') $respond(false, 'Vui lòng nhập mật khẩu.', 422, 'password');
    if (strlen($password) < 8) $respond(false, 'Mật khẩu phải có ít nhất 8 ký tự.', 422, 'password');

    $exists = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $exists->execute([$email]);
    if ($exists->fetchColumn() !== false) {
        $respond(false, 'Email này đã được sử dụng.', 409, 'email');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    if ($passwordHash === false) {
        throw new RuntimeException('Cannot create password hash.');
    }

    try {
        $statement = $pdo->prepare(
            "INSERT INTO users (name, email, password_hash, role, is_approved) VALUES (?, ?, ?, 'student', 0)"
        );
        $statement->execute([$name, $email, $passwordHash]);
    } catch (PDOException $error) {
        // Hosting cũ chưa chạy migration duyệt tài khoản vẫn có thể tạo học viên.
        // Với schema đó, account_lock.php đã coi tài khoản cũ là được duyệt.
        $missingApprovalColumn = (string) ($error->errorInfo[1] ?? '') === '1054'
            || str_contains(strtolower($error->getMessage()), 'is_approved');
        if (!$missingApprovalColumn) throw $error;

        error_log('Registration compatibility mode: users.is_approved is missing.');
        $statement = $pdo->prepare(
            "INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'student')"
        );
        $statement->execute([$name, $email, $passwordHash]);
    }

    $_SESSION['pending_approval'] = [
        'user_id' => (int) $pdo->lastInsertId(),
        'name' => $name,
        'email' => $email,
    ];
    $respond(true, 'Tài khoản đã được tạo và đang chờ quản trị viên duyệt.', 200, null, [
        // Resolved by JavaScript against index.php, not against this endpoint.
        'redirect' => 'pending_approval.php',
    ]);
} catch (Throwable $error) {
    error_log('Registration endpoint failed: ' . $error->getMessage());
    $temporaryConnectionError = $error instanceof PDOException
        && function_exists('isRetryableDatabaseConnectionError')
        && isRetryableDatabaseConnectionError($error);

    $respond(
        false,
        $temporaryConnectionError
            ? 'Máy chủ dữ liệu đang tạm thời bận. Chưa có tài khoản nào được tạo. Vui lòng thử lại sau vài giây.'
            : 'Hệ thống chưa thể tạo tài khoản. Vui lòng thử lại; nếu lỗi vẫn tiếp diễn, hãy gửi mã lỗi trong nhật ký hosting cho quản trị viên.',
        $temporaryConnectionError ? 503 : 500
    );
}
