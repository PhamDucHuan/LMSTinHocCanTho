<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS teaching_shift_presets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            major_shift ENUM('morning','afternoon','evening') NOT NULL DEFAULT 'morning',
            created_by INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_teaching_shift_preset_owner_name (created_by, name),
            KEY idx_teaching_shift_preset_owner (created_by),
            CONSTRAINT chk_teaching_shift_preset_time CHECK (start_time < end_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
