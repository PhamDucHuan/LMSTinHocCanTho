<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM grading_jobs') as $column) {
        $columns[(string) $column['Field']] = true;
    }
    if (!isset($columns['result_applied_at'])) {
        $pdo->exec('ALTER TABLE grading_jobs ADD COLUMN result_applied_at DATETIME NULL AFTER completed_at');
    }
};
