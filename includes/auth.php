<?php
require_once __DIR__ . '/security.php';
secureSessionStart();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/account_lock.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/login_history.php';
require_once __DIR__ . '/remember_login.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'register') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = 'student';

        $isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
        $registrationError = static function (string $message, ?string $field = null, int $status = 422) use ($isAjax): void {
            if ($isAjax) {
                http_response_code($status);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => $message, 'field' => $field], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $_SESSION['error'] = $message;
            header('Location: ../index.php');
            exit;
        };

        if ($name === '') $registrationError('Vui lòng nhập họ và tên.', 'name');
        if ($email === '') $registrationError('Vui lòng nhập địa chỉ email.', 'email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $registrationError('Địa chỉ email không đúng định dạng.', 'email');
        if ($password === '') $registrationError('Vui lòng nhập mật khẩu.', 'password');
        if (strlen($password) < 8) $registrationError('Mật khẩu phải có ít nhất 8 ký tự.', 'password');

        // Check if email exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $registrationError('Email này đã được sử dụng.', 'email', 409);
        }

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, is_approved) VALUES (?, ?, ?, ?, 0)");
            $registered = $stmt->execute([$name, $email, $hashed_password, $role]);
        } catch (PDOException $error) {
            error_log('Registration failed: ' . $error->getMessage());
            $registrationError(
                $error->getCode() === '23000' ? 'Email này đã được sử dụng.' : 'Đăng ký thất bại. Vui lòng thử lại sau.',
                $error->getCode() === '23000' ? 'email' : null,
                $error->getCode() === '23000' ? 409 : 500
            );
        }

        if ($registered) {
            $_SESSION['pending_approval'] = [
                'user_id' => (int) $pdo->lastInsertId(),
                'name' => $name,
                'email' => $email,
            ];
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'redirect' => 'pending_approval.php'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            header('Location: ../pending_approval.php');
            exit;
        } else {
            $registrationError('Đăng ký thất bại. Vui lòng thử lại sau.', null, 500);
        }
    } elseif ($action === 'login') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $_SESSION['error'] = 'Vui lòng nhập email và mật khẩu.';
            header('Location: ../index.php');
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && isAccountLocked($pdo, (int) $user['id'])) {
            recordLoginHistory($pdo, (int) $user['id'], 'login_failed_locked', 'password', $email, ['reason' => 'account_locked']);
            $_SESSION['error'] = 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ quản trị viên.';
            header('Location: ../index.php');
            exit;
        }

        if ($user && password_verify($password, $user['password_hash'])) {
            if (!isAccountApproved($pdo, (int) $user['id'])) {
                recordLoginHistory($pdo, (int) $user['id'], 'login_failed_pending', 'password', $email, ['reason' => 'awaiting_admin_approval']);
                $_SESSION['pending_approval'] = [
                    'user_id' => (int) $user['id'],
                    'name' => (string) $user['name'],
                    'email' => (string) $user['email'],
                ];
                header('Location: ../pending_approval.php');
                exit;
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_avatar'] = $user['avatar_url'] ?? null;
            if (!empty($_POST['remember'])) {
                issueRememberLoginToken($pdo, (int) $user['id']);
            } else {
                // Chỉ bỏ ghi nhớ trên trình duyệt hiện tại; các thiết bị khác vẫn đăng nhập.
                revokeRememberLogin($pdo);
            }
            recordLoginHistory($pdo, (int) $user['id'], 'login_success', 'password', $email);

            if ($user['role'] === 'admin') {
                header('Location: ../admin/dashboard.php');
            } elseif (in_array($user['role'], ['teacher', 'administrative_staff'], true)) {
                header('Location: ../teacher/dashboard.php');
            } else {
                header('Location: ../student/dashboard.php');
            }
            exit;
        } else {
            recordLoginHistory($pdo, $user ? (int) $user['id'] : null, 'login_failed', 'password', $email, ['reason' => 'invalid_credentials']);
            $_SESSION['error'] = 'Email hoặc mật khẩu không đúng.';
            header('Location: ../index.php');
            exit;
        }
    }
}
?>
