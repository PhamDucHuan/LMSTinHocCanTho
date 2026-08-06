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

/**
 * Chọn câu theo phân tầng động để giảm trùng trong cùng khóa học.
 * Câu chưa dùng trong khóa học có chi phí 0 và luôn được ưu tiên. Khi không đủ,
 * thuật toán lần lượt dùng các tầng có số lần xuất hiện thấp nhất. Việc xáo trộn
 * chỉ diễn ra trong cùng một tầng nên không phá vỡ mức ưu tiên.
 */
function selectQuestionBankForCourse(
    PDO $pdo,
    int $courseId,
    array $conditions,
    array $params,
    int $count
): array {
    if ($count <= 0) return [];

    $sql = 'SELECT qb.*, COALESCE(course_usage.use_count, 0) AS course_usage_count
        FROM question_bank qb
        LEFT JOIN (
            SELECT qq.source_question_id, COUNT(DISTINCT q.id) AS use_count
            FROM quiz_questions qq
            INNER JOIN quiz_sections qs ON qs.id = qq.section_id
            INNER JOIN quizzes q ON q.id = qs.quiz_id
            WHERE q.course_id = ? AND qq.source_question_id IS NOT NULL
            GROUP BY qq.source_question_id
        ) course_usage ON course_usage.source_question_id = qb.id
        WHERE ' . implode(' AND ', $conditions);
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$courseId], $params));
    $candidates = $stmt->fetchAll();

    if (count($candidates) < $count) return $candidates;

    // Gom theo [số lần dùng trong khóa học][tổng lượt dùng] rồi cấp phát động.
    $tiers = [];
    foreach ($candidates as $candidate) {
        $courseUses = max(0, (int) ($candidate['course_usage_count'] ?? 0));
        $globalUses = max(0, (int) ($candidate['usage_count'] ?? 0));
        $tiers[$courseUses][$globalUses][] = $candidate;
    }
    ksort($tiers, SORT_NUMERIC);

    $selected = [];
    foreach ($tiers as &$usageTiers) {
        ksort($usageTiers, SORT_NUMERIC);
        foreach ($usageTiers as &$questions) {
            shuffle($questions);
            $needed = $count - count($selected);
            if ($needed <= 0) break 2;
            $selected = array_merge($selected, array_slice($questions, 0, $needed));
        }
        unset($questions);
    }
    unset($usageTiers);

    // Không để thứ tự ưu tiên làm lộ thứ tự câu trong đề.
    shuffle($selected);
    return $selected;
}
