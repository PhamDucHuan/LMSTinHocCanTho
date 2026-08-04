<?php
declare(strict_types=1);

function ensureQuizSchema(PDO $pdo): void
{
    $columnType = static function (string $table, string $column) use ($pdo): string {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
        $definition = strtolower((string) ($stmt->fetch()['Type'] ?? 'int'));
        return str_contains($definition, 'unsigned') ? 'INT UNSIGNED' : 'INT';
    };
    $courseKeyType = $columnType('courses', 'id');
    $userKeyType = $columnType('users', 'id');

    $statements = [
        "CREATE TABLE IF NOT EXISTS quizzes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            course_id {$courseKeyType} NOT NULL,
            teacher_id {$userKeyType} NOT NULL,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(191) NULL UNIQUE,
            description TEXT NULL,
            duration_minutes INT UNSIGNED NOT NULL DEFAULT 40,
            is_published TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_quizzes_course (course_id),
            CONSTRAINT fk_quizzes_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
            CONSTRAINT fk_quizzes_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS quiz_sections (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            quiz_id INT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_quiz_sections_quiz (quiz_id),
            CONSTRAINT fk_quiz_sections_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS quiz_questions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            section_id INT UNSIGNED NOT NULL,
            question_text TEXT NOT NULL,
            option_a TEXT NOT NULL,
            option_b TEXT NOT NULL,
            option_c TEXT NOT NULL,
            option_d TEXT NOT NULL,
            question_image VARCHAR(500) NULL,
            option_a_image VARCHAR(500) NULL,
            option_b_image VARCHAR(500) NULL,
            option_c_image VARCHAR(500) NULL,
            option_d_image VARCHAR(500) NULL,
            correct_option ENUM('A','B','C','D') NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_quiz_questions_section (section_id),
            CONSTRAINT fk_quiz_questions_section FOREIGN KEY (section_id) REFERENCES quiz_sections(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS quiz_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            quiz_id INT UNSIGNED NOT NULL,
            student_id {$userKeyType} NOT NULL,
            answers JSON NULL,
            correct_count INT UNSIGNED NOT NULL DEFAULT 0,
            total_questions INT UNSIGNED NOT NULL DEFAULT 0,
            score DECIMAL(5,2) NOT NULL DEFAULT 0,
            started_at DATETIME NOT NULL,
            paused_at DATETIME NULL,
            paused_seconds INT UNSIGNED NOT NULL DEFAULT 0,
            submitted_at DATETIME NULL,
            INDEX idx_quiz_attempt_student (student_id, quiz_id),
            CONSTRAINT fk_quiz_attempt_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
            CONSTRAINT fk_quiz_attempt_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS quiz_attempt_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            attempt_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            event_data JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_quiz_attempt_events_attempt (attempt_id, created_at),
            CONSTRAINT fk_quiz_attempt_events_attempt FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }

    $questionColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM quiz_questions') as $column) {
        $questionColumns[$column['Field']] = true;
    }
    foreach (['question_image', 'option_a_image', 'option_b_image', 'option_c_image', 'option_d_image'] as $imageColumn) {
        if (!isset($questionColumns[$imageColumn])) {
            $pdo->exec("ALTER TABLE quiz_questions ADD COLUMN `{$imageColumn}` VARCHAR(500) NULL AFTER option_d");
        }
    }
    $quizColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM quizzes') as $column) {
        $quizColumns[$column['Field']] = true;
    }
    if (!isset($quizColumns['sort_order'])) {
        $pdo->exec('ALTER TABLE quizzes ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_published');
    }
    $quizAdditions = [
        'passing_score' => 'DECIMAL(5,2) NOT NULL DEFAULT 5.00 AFTER duration_minutes',
        'require_fullscreen' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_published',
        'limit_device' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER require_fullscreen',
    ];
    foreach ($quizAdditions as $name => $definition) {
        if (!isset($quizColumns[$name])) $pdo->exec("ALTER TABLE quizzes ADD COLUMN `{$name}` {$definition}");
    }
    // Kiểu cột và các nâng cấp tiếp theo được quản lý bởi database/migrate.php.

    $attemptColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM quiz_attempts') as $column) {
        $attemptColumns[$column['Field']] = true;
    }
    if (!isset($attemptColumns['paused_at'])) {
        $pdo->exec('ALTER TABLE quiz_attempts ADD COLUMN paused_at DATETIME NULL AFTER started_at');
    }
    if (!isset($attemptColumns['paused_seconds'])) {
        $pdo->exec('ALTER TABLE quiz_attempts ADD COLUMN paused_seconds INT UNSIGNED NOT NULL DEFAULT 0 AFTER paused_at');
    }
    $attemptAdditions = [
        'device_hash' => 'CHAR(64) NULL AFTER student_id',
        'tab_switch_count' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER paused_seconds',
        'fullscreen_exit_count' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER tab_switch_count',
        'offline_count' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER fullscreen_exit_count',
    ];
    foreach ($attemptAdditions as $name => $definition) {
        if (!isset($attemptColumns[$name])) $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN `{$name}` {$definition}");
    }
}
