<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/friendly_urls.php';

return static function (PDO $pdo): void {
    foreach (['courses', 'assignments', 'quizzes'] as $table) {
        $columns = [];
        foreach ($pdo->query("SHOW COLUMNS FROM `{$table}`") as $column) {
            $columns[(string) $column['Field']] = true;
        }
        if (!isset($columns['slug'])) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN slug VARCHAR(191) NULL");
        }
        $indexes = [];
        foreach ($pdo->query("SHOW INDEX FROM `{$table}`") as $index) {
            $indexes[(string) $index['Key_name']] = true;
        }
        if (!isset($indexes["uq_{$table}_slug"])) {
            $pdo->exec("ALTER TABLE `{$table}` ADD UNIQUE KEY uq_{$table}_slug (slug)");
        }
        $rows = $pdo->query("SELECT id,title FROM `{$table}` WHERE slug IS NULL OR slug='' ORDER BY id")->fetchAll();
        $update = $pdo->prepare("UPDATE `{$table}` SET slug=? WHERE id=?");
        foreach ($rows as $row) {
            $update->execute([uniqueFriendlySlug($pdo, $table, (string) $row['title']), $row['id']]);
        }
    }

    $userKey = $pdo->query("SHOW COLUMNS FROM users LIKE 'id'")->fetch();
    $userType = strtoupper(preg_replace('/\(\d+\)/', '', (string) ($userKey['Type'] ?? 'INT')));
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_remember_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id {$userType} NOT NULL,
            selector CHAR(24) NOT NULL UNIQUE,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_remember_user (user_id),
            INDEX idx_remember_expires (expires_at),
            CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
