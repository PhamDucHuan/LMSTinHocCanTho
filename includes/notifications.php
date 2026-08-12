<?php
declare(strict_types=1);

function createNotification(
    PDO $pdo,
    int $userId,
    string $type,
    string $title,
    string $message = '',
    ?string $link = null,
    array $data = []
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO notifications (user_id, type, title, message, link, data_json)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        mb_substr($type, 0, 50),
        mb_substr($title, 0, 255),
        $message,
        $link,
        $data ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ]);
    return (int) $pdo->lastInsertId();
}

function unreadNotificationCount(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Gửi email hệ thống (hỗ trợ SMTP qua PHPMailer nếu được cấu hình trong .env).
 */
function sendSystemEmail(string $to, string $subject, string $htmlBody): bool
{
    // Lấy cấu hình SMTP từ .env
    $host = getenv('MAIL_HOST');
    $port = getenv('MAIL_PORT');
    $username = getenv('MAIL_USERNAME');
    $password = getenv('MAIL_PASSWORD');
    $encryption = getenv('MAIL_ENCRYPTION') ?: 'tls';
    $fromAddress = getenv('MAIL_FROM_ADDRESS') ?: 'no-reply@lmstinhoccantho.com';
    $fromName = getenv('MAIL_FROM_NAME') ?: 'LMS Admin';

    // Nếu đã có cấu hình SMTP (Username & Password) thì dùng PHPMailer
    if (!empty($host) && !empty($username) && !empty($password)) {
        require_once __DIR__ . '/../vendor/autoload.php';
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $username;
            $mail->Password   = $password;
            $mail->SMTPSecure = strtolower($encryption) === 'ssl' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int)$port;
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom($fromAddress, $fromName);
            $mail->addAddress($to);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            
            // Xóa HTML tags để làm AltBody
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $htmlBody));

            $mail->send();
            return true;
        } catch (\Exception $e) {
            error_log("PHPMailer Error: {$mail->ErrorInfo}");
            return false;
        }
    }

    // Fallback: Nếu không cấu hình SMTP, dùng hàm mail() mặc định
    $headers = "From: {$fromName} <{$fromAddress}>\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    return @mail($to, $subject, $htmlBody, $headers);
}
