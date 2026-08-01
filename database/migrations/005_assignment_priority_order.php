<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM assignments') as $column) {
        $columns[(string) $column['Field']] = true;
    }
    if (!isset($columns['priority_order'])) {
        $pdo->exec('ALTER TABLE assignments ADD COLUMN priority_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER ai_analysis');
    }

    $indexes = [];
    foreach ($pdo->query('SHOW INDEX FROM assignments') as $index) {
        $indexes[(string) $index['Key_name']] = true;
    }
    if (!isset($indexes['idx_assignments_priority'])) {
        $pdo->exec('ALTER TABLE assignments ADD INDEX idx_assignments_priority (course_id, type, priority_order)');
    }

    $rows = $pdo->query('SELECT id, course_id, type FROM assignments ORDER BY course_id, type, created_at, id')->fetchAll();
    $orders = [];
    $update = $pdo->prepare('UPDATE assignments SET priority_order = ? WHERE id = ?');
    foreach ($rows as $row) {
        $key = ($row['course_id'] === null ? 'null' : (string) $row['course_id']) . ':' . (string) $row['type'];
        $orders[$key] = ($orders[$key] ?? 0) + 1;
        $update->execute([$orders[$key], $row['id']]);
    }
};
