<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM quizzes') as $column) {
        $columns[(string) $column['Field']] = true;
    }

    if (!isset($columns['category'])) {
        $pdo->exec("ALTER TABLE quizzes ADD COLUMN category VARCHAR(100) NOT NULL DEFAULT 'Chưa phân loại' AFTER description");
    }

    $indexes = [];
    foreach ($pdo->query('SHOW INDEX FROM quizzes') as $index) {
        $indexes[(string) $index['Key_name']] = true;
    }
    if (!isset($indexes['idx_quizzes_category_published'])) {
        $pdo->exec('ALTER TABLE quizzes ADD INDEX idx_quizzes_category_published (category, is_published)');
    }
};
