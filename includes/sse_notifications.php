<?php
declare(strict_types=1);

// Giữ đường dẫn cũ nhưng trả về một phản hồi JSON ngắn. Cách này không còn
// giữ PHP worker và kết nối CSDL trong 60 giây cho mỗi tab trình duyệt.
require_once __DIR__ . '/security.php';
secureSessionStart();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$userId = (int) $_SESSION['user_id'];
$afterId = max(0, (int) ($_GET['after_id'] ?? 0));
session_write_close();

try {
    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL'
    );
    $countStmt->execute([$userId]);
    $unread = (int) $countStmt->fetchColumn();

    if ($afterId > 0) {
        $stmt = $pdo->prepare(
            'SELECT id, type, title, message, link, created_at
             FROM notifications
             WHERE user_id = ? AND read_at IS NULL AND id > ?
             ORDER BY id ASC LIMIT 10'
        );
        $stmt->execute([$userId, $afterId]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, type, title, message, link, created_at
             FROM notifications
             WHERE user_id = ? AND read_at IS NULL
             ORDER BY id DESC LIMIT 10'
        );
        $stmt->execute([$userId]);
    }

    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $latestId = $afterId;
    foreach ($notifications as $notification) {
        $latestId = max($latestId, (int) $notification['id']);
    }

    echo json_encode([
        'ok' => true,
        'unread' => $unread,
        'latest_id' => $latestId,
        'notifications' => $notifications,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('Cannot poll notifications: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'temporarily_unavailable']);
}
