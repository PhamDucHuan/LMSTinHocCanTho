<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM users') as $column) {
        $columns[(string) $column['Field']] = true;
    }
    if (!isset($columns['online_status'])) {
        $pdo->exec('ALTER TABLE users ADD COLUMN online_status TINYINT(1) NOT NULL DEFAULT 0 AFTER is_approved');
    }
    if (!isset($columns['last_seen_at'])) {
        $pdo->exec('ALTER TABLE users ADD COLUMN last_seen_at DATETIME NULL AFTER online_status');
    }

    foreach ($pdo->query('SHOW INDEX FROM users') as $index) {
        if (($index['Key_name'] ?? '') === 'idx_users_online_presence') return;
    }
    $pdo->exec('ALTER TABLE users ADD INDEX idx_users_online_presence (online_status, last_seen_at)');
};
