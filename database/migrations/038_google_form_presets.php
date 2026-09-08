<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS google_form_presets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            created_by INT NOT NULL,
            form_title VARCHAR(191) NOT NULL,
            form_url VARCHAR(1000) NOT NULL,
            form_url_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_google_form_preset_owner_url (created_by, form_url_hash),
            INDEX idx_google_form_presets_recent (created_by, last_used_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $typeStatement = $pdo->prepare(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='id' LIMIT 1"
    );
    $typeStatement->execute(['users']);
    $userIdType = strtolower((string) $typeStatement->fetchColumn());
    if (!preg_match('/^(tinyint|smallint|mediumint|int|bigint)(\(\d+\))?( unsigned)?$/', $userIdType)) {
        throw new RuntimeException('Không thể xác định kiểu dữ liệu users.id.');
    }
    $columnType = $pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='google_form_presets' AND COLUMN_NAME='created_by' LIMIT 1"
    )->fetchColumn();
    if (strtolower((string) $columnType) !== $userIdType) {
        $pdo->exec('ALTER TABLE google_form_presets MODIFY created_by ' . strtoupper($userIdType) . ' NOT NULL');
    }

    $foreignKey = $pdo->query(
        "SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='google_form_presets'
           AND CONSTRAINT_NAME='fk_google_form_presets_creator' LIMIT 1"
    )->fetchColumn();
    if (!$foreignKey) {
        $pdo->exec('ALTER TABLE google_form_presets ADD CONSTRAINT fk_google_form_presets_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE');
    }
};
