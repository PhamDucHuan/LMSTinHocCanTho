<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columns = $pdo->query('SHOW COLUMNS FROM teaching_classes')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('planned_second_start_time', $columns, true)) {
        $pdo->exec('ALTER TABLE teaching_classes ADD COLUMN planned_second_start_time TIME NULL AFTER planned_end_time');
    }
    if (!in_array('planned_second_end_time', $columns, true)) {
        $pdo->exec('ALTER TABLE teaching_classes ADD COLUMN planned_second_end_time TIME NULL AFTER planned_second_start_time');
    }
};
