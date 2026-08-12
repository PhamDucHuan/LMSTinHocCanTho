<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    // 1. Add exam_date to course_enrollments
    $cols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM course_enrollments') as $col) {
        $cols[$col['Field']] = true;
    }
    if (!isset($cols['exam_date'])) {
        $pdo->exec("ALTER TABLE course_enrollments ADD COLUMN exam_date DATETIME NULL");
    }

    // 2. Create reminder_logs table to prevent duplicate spam
    $pdo->exec("CREATE TABLE IF NOT EXISTS reminder_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type VARCHAR(50) NOT NULL,
        reference_id INT NOT NULL,
        sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_reminder_logs (user_id, type, reference_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};