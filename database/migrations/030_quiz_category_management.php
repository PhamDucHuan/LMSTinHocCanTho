<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS quiz_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_quiz_categories_name (name),
            KEY idx_quiz_categories_order (sort_order, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $insert = $pdo->prepare(
        'INSERT IGNORE INTO quiz_categories (name, sort_order)
         SELECT category, 0
         FROM quizzes
         WHERE category IS NOT NULL AND TRIM(category) <> ?
         GROUP BY category'
    );
    $insert->execute(['']);

    $seed = $pdo->prepare('INSERT IGNORE INTO quiz_categories (name, sort_order) VALUES (?, ?)');
    foreach (['ĐHCT', 'ĐHTV'] as $index => $name) {
        $seed->execute([$name, $index + 1]);
    }
};
