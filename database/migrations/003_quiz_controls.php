<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $quizColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM quizzes') as $column) {
        $quizColumns[(string) $column['Field']] = true;
    }
    $quizAdditions = [
        'max_attempts' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER duration_minutes',
        'question_limit' => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER max_attempts',
        'shuffle_questions' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER question_limit',
        'shuffle_options' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER shuffle_questions',
        'available_from' => 'DATETIME NULL AFTER shuffle_options',
        'available_until' => 'DATETIME NULL AFTER available_from',
    ];
    foreach ($quizAdditions as $name => $definition) {
        if (!isset($quizColumns[$name])) $pdo->exec("ALTER TABLE quizzes ADD COLUMN `{$name}` {$definition}");
    }

    $questionColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM quiz_questions') as $column) {
        $questionColumns[(string) $column['Field']] = true;
    }
    $questionAdditions = [
        'points' => 'DECIMAL(6,2) NOT NULL DEFAULT 1 AFTER correct_option',
        'difficulty' => "ENUM('easy','medium','hard') NOT NULL DEFAULT 'medium' AFTER points",
        'explanation' => 'TEXT NULL AFTER difficulty',
    ];
    foreach ($questionAdditions as $name => $definition) {
        if (!isset($questionColumns[$name])) $pdo->exec("ALTER TABLE quiz_questions ADD COLUMN `{$name}` {$definition}");
    }

    $attemptColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM quiz_attempts') as $column) {
        $attemptColumns[(string) $column['Field']] = true;
    }
    $attemptAdditions = [
        'question_order' => 'JSON NULL AFTER answers',
        'option_order' => 'JSON NULL AFTER question_order',
        'last_saved_at' => 'DATETIME NULL AFTER paused_seconds',
    ];
    foreach ($attemptAdditions as $name => $definition) {
        if (!isset($attemptColumns[$name])) $pdo->exec("ALTER TABLE quiz_attempts ADD COLUMN `{$name}` {$definition}");
    }
};
