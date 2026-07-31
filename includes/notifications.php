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
