<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $numericColumnType = static function (string $table, string $column) use ($pdo): string {
        $statement = $pdo->prepare(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1'
        );
        $statement->execute([$table, $column]);
        $type = strtolower((string) $statement->fetchColumn());
        if (!preg_match('/^(tinyint|smallint|mediumint|int|bigint)(\(\d+\))?( unsigned)?$/', $type)) {
            throw new RuntimeException("Không thể xác định kiểu khóa {$table}.{$column}.");
        }
        return strtoupper($type);
    };

    $questionKey = $numericColumnType('quiz_questions', 'id');
    $quizKey = $numericColumnType('quizzes', 'id');
    $attemptKey = $numericColumnType('quiz_attempts', 'id');
    $userKey = $numericColumnType('users', 'id');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS quiz_question_reports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            question_id {$questionKey} NOT NULL,
            quiz_id {$quizKey} NOT NULL,
            attempt_id {$attemptKey} NOT NULL,
            student_id {$userKey} NOT NULL,
            reason VARCHAR(60) NOT NULL,
            details TEXT NULL,
            question_snapshot JSON NULL,
            status ENUM('open','reviewed','resolved','dismissed') NOT NULL DEFAULT 'open',
            admin_note TEXT NULL,
            resolved_by {$userKey} NULL,
            resolved_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_quiz_question_report_attempt (attempt_id, question_id),
            KEY idx_quiz_question_reports_status (status, created_at),
            KEY idx_quiz_question_reports_question (question_id, created_at),
            CONSTRAINT fk_quiz_question_reports_question FOREIGN KEY (question_id) REFERENCES quiz_questions(id) ON DELETE CASCADE,
            CONSTRAINT fk_quiz_question_reports_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
            CONSTRAINT fk_quiz_question_reports_attempt FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE,
            CONSTRAINT fk_quiz_question_reports_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_quiz_question_reports_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
