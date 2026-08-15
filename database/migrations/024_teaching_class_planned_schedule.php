<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $classColumns = $pdo->query('SHOW COLUMNS FROM teaching_classes')->fetchAll(PDO::FETCH_COLUMN);
    $classChanges = [
        'total_sessions' => 'ADD COLUMN total_sessions INT NULL AFTER notes',
        'planned_weekdays' => 'ADD COLUMN planned_weekdays VARCHAR(32) NULL AFTER total_sessions',
        'planned_start_date' => 'ADD COLUMN planned_start_date DATE NULL AFTER planned_weekdays',
        'planned_start_time' => 'ADD COLUMN planned_start_time TIME NULL AFTER planned_start_date',
        'planned_end_time' => 'ADD COLUMN planned_end_time TIME NULL AFTER planned_start_time',
    ];
    foreach ($classChanges as $column => $sql) {
        if (!in_array($column, $classColumns, true)) $pdo->exec('ALTER TABLE teaching_classes ' . $sql);
    }
    $slotColumns = $pdo->query('SHOW COLUMNS FROM teaching_schedule_slots')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('is_makeup', $slotColumns, true)) {
        $pdo->exec('ALTER TABLE teaching_schedule_slots ADD COLUMN is_makeup TINYINT(1) NOT NULL DEFAULT 0 AFTER end_time');
    }
};
