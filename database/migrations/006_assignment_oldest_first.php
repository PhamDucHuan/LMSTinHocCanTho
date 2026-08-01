<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $rows = $pdo->query('SELECT id, course_id, type FROM assignments ORDER BY course_id, type, created_at, id')->fetchAll();
    $orders = [];
    $update = $pdo->prepare('UPDATE assignments SET priority_order = ? WHERE id = ?');
    foreach ($rows as $row) {
        $key = ($row['course_id'] === null ? 'null' : (string) $row['course_id']) . ':' . (string) $row['type'];
        $orders[$key] = ($orders[$key] ?? 0) + 1;
        $update->execute([$orders[$key], $row['id']]);
    }
};
