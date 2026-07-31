<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $keyType = static function (string $table, string $column = 'id') use ($pdo): string {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
        $type = (string) ($stmt->fetch()['Type'] ?? 'int');
        if (!preg_match('/^(?:tinyint|smallint|mediumint|int|bigint)(?:\(\d+\))?(?: unsigned)?/i', $type, $match)) {
            throw new RuntimeException("Không xác định được kiểu khóa {$table}.{$column}");
        }
        return strtoupper(preg_replace('/\(\d+\)/', '', $match[0]));
    };
    $submissionKeyType = $keyType('submissions');
    $assignmentKeyType = $keyType('assignments');
    $userKeyType = $keyType('users');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS grading_jobs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            submission_id {$submissionKeyType} NOT NULL,
            assignment_id {$assignmentKeyType} NOT NULL,
            student_id {$userKeyType} NOT NULL,
            module_name VARCHAR(100) NOT NULL,
            status ENUM('queued','processing','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
            payload JSON NOT NULL,
            result_json JSON NULL,
            error_message TEXT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            locked_at DATETIME NULL,
            worker_token CHAR(36) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            INDEX idx_grading_jobs_claim (status, available_at, id),
            INDEX idx_grading_jobs_submission (submission_id, module_name, created_at),
            CONSTRAINT fk_grading_jobs_submission FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
            CONSTRAINT fk_grading_jobs_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
            CONSTRAINT fk_grading_jobs_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS grading_reviews (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            grading_job_id BIGINT UNSIGNED NULL,
            submission_id {$submissionKeyType} NOT NULL,
            module_name VARCHAR(100) NOT NULL,
            ai_score DECIMAL(6,2) NULL,
            final_score DECIMAL(6,2) NOT NULL,
            ai_feedback JSON NULL,
            reviewer_feedback TEXT NULL,
            reviewed_by {$userKeyType} NOT NULL,
            reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_grading_reviews_submission (submission_id, module_name, reviewed_at),
            CONSTRAINT fk_grading_reviews_job FOREIGN KEY (grading_job_id) REFERENCES grading_jobs(id) ON DELETE SET NULL,
            CONSTRAINT fk_grading_reviews_submission FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
            CONSTRAINT fk_grading_reviews_user FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id {$userKeyType} NOT NULL,
            type VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NULL,
            link VARCHAR(500) NULL,
            data_json JSON NULL,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notifications_user (user_id, read_at, created_at),
            CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS audit_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id {$userKeyType} NULL,
            action VARCHAR(100) NOT NULL,
            entity_type VARCHAR(80) NULL,
            entity_id VARCHAR(100) NULL,
            context_json JSON NULL,
            ip_address VARCHAR(45) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_entity (entity_type, entity_id, created_at),
            INDEX idx_audit_user (user_id, created_at),
            CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM submissions') as $column) {
        $columns[(string) $column['Field']] = true;
    }
    if (!isset($columns['grading_status'])) {
        $pdo->exec(
            "ALTER TABLE submissions
             ADD COLUMN grading_status ENUM('not_graded','queued','processing','ai_graded','review_required','reviewed','failed')
             NOT NULL DEFAULT 'not_graded' AFTER is_outstanding"
        );
    }
    if (!isset($columns['grading_updated_at'])) {
        $pdo->exec('ALTER TABLE submissions ADD COLUMN grading_updated_at DATETIME NULL AFTER grading_status');
    }
};
