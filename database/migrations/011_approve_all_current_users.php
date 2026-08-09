<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM users') as $column) {
        $columns[(string) $column['Field']] = true;
    }
    if (!isset($columns['is_approved'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_approved TINYINT(1) NOT NULL DEFAULT 0 AFTER role");
    }
    if (!isset($columns['approved_at'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN approved_at DATETIME NULL AFTER is_approved");
    }
    if (!isset($columns['approved_by'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN approved_by BIGINT NULL AFTER approved_at");
    }
    $pdo->exec(
        "UPDATE users
         SET is_approved = 1,
             approved_at = COALESCE(approved_at, NOW())
         WHERE is_approved = 0"
    );
};
