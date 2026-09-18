<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    foreach (['quiz_questions', 'question_bank'] as $table) {
        $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
        if (!$exists) continue;
        $column = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'correct_option'")->fetch(PDO::FETCH_ASSOC);
        if (!$column) continue;
        $pdo->exec("ALTER TABLE `{$table}` MODIFY correct_option VARCHAR(15) NOT NULL");
    }
};
