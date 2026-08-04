<?php
declare(strict_types=1);

function ensureQuestionBankSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS question_topics (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL,
        name VARCHAR(191) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_question_topic_teacher (teacher_id,name),
        INDEX idx_question_topic_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS question_bank (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL,
        topic_id INT UNSIGNED NULL,
        difficulty ENUM('easy','medium','hard') NOT NULL DEFAULT 'medium',
        question_text TEXT NOT NULL,
        option_a TEXT NOT NULL, option_b TEXT NOT NULL, option_c TEXT NOT NULL, option_d TEXT NOT NULL,
        correct_option ENUM('A','B','C','D') NOT NULL,
        fingerprint CHAR(64) NOT NULL,
        usage_count INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_question_teacher_fingerprint (teacher_id,fingerprint),
        INDEX idx_question_bank_filter (teacher_id,topic_id,difficulty),
        CONSTRAINT fk_question_bank_topic FOREIGN KEY (topic_id) REFERENCES question_topics(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS question_bank_versions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        question_id BIGINT UNSIGNED NOT NULL,
        version_number INT UNSIGNED NOT NULL,
        changed_by INT NOT NULL,
        snapshot_json JSON NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_question_version (question_id, version_number),
        INDEX idx_question_versions_question (question_id, created_at),
        CONSTRAINT fk_question_versions_question FOREIGN KEY (question_id) REFERENCES question_bank(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM quiz_questions') as $column) $columns[$column['Field']] = true;
    if (!isset($columns['source_question_id'])) {
        $pdo->exec('ALTER TABLE quiz_questions ADD COLUMN source_question_id BIGINT UNSIGNED NULL AFTER section_id, ADD INDEX idx_quiz_question_source (source_question_id)');
    }
}

function questionFingerprint(array $question): string
{
    $parts = [];
    foreach (['question_text','option_a','option_b','option_c','option_d'] as $key) {
        $value = mb_strtolower(trim((string) ($question[$key] ?? '')), 'UTF-8');
        $parts[] = preg_replace('/\s+/u', ' ', $value);
    }
    sort($parts, SORT_STRING);
    return hash('sha256', implode('|', $parts));
}

function questionDifficultyLabel(string $difficulty): string
{
    return ['easy' => 'Dễ', 'medium' => 'Trung bình', 'hard' => 'Khó'][$difficulty] ?? 'Trung bình';
}
