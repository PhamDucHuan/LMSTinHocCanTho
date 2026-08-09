<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM users') as $column) {
        $columns[(string) $column['Field']] = true;
    }
    if (!isset($columns['is_approved'])) return;

    $stmt = $pdo->prepare(
        "UPDATE users
         SET is_approved = 1,
             approved_at = COALESCE(approved_at, created_at)
         WHERE created_at <= COALESCE(
             (SELECT applied_at FROM schema_migrations WHERE version = ? LIMIT 1),
             created_at
         )"
    );
    $stmt->execute(['009_user_approval']);
};
