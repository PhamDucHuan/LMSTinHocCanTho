<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM users') as $column) {
        $columns[(string) $column['Field']] = true;
    }

    // Tài khoản đã tồn tại trước khi có quy trình duyệt vẫn được tiếp tục sử dụng.
    if (!isset($columns['is_approved'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_approved TINYINT(1) NOT NULL DEFAULT 1 AFTER role");
        $pdo->exec("ALTER TABLE users MODIFY is_approved TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!isset($columns['approved_at'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN approved_at DATETIME NULL AFTER is_approved");
        $pdo->exec("UPDATE users SET approved_at = COALESCE(approved_at, created_at) WHERE is_approved = 1");
    }
    if (!isset($columns['approved_by'])) {
        $pdo->exec("ALTER TABLE users ADD COLUMN approved_by BIGINT NULL AFTER approved_at");
    }

    $hasIndex = false;
    foreach ($pdo->query('SHOW INDEX FROM users') as $index) {
        if ((string) $index['Key_name'] === 'idx_users_approval_created') $hasIndex = true;
    }
    if (!$hasIndex) {
        $pdo->exec('ALTER TABLE users ADD INDEX idx_users_approval_created (is_approved, created_at, id)');
    }
};
