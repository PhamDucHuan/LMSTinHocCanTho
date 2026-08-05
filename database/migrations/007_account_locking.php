<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columns = [];
    foreach ($pdo->query("SHOW COLUMNS FROM users") as $column) {
        $columns[(string) $column['Field']] = true;
    }
    if (!isset($columns['is_locked'])) $pdo->exec("ALTER TABLE users ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER role");
    if (!isset($columns['locked_at'])) $pdo->exec("ALTER TABLE users ADD COLUMN locked_at DATETIME NULL AFTER is_locked");
    if (!isset($columns['locked_by'])) $pdo->exec("ALTER TABLE users ADD COLUMN locked_by BIGINT NULL AFTER locked_at");
};
