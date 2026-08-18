<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columns = $pdo->query("SHOW COLUMNS FROM teaching_classes")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('time_shift', $columns, true)) {
        $pdo->exec("ALTER TABLE teaching_classes ADD COLUMN time_shift ENUM('morning','afternoon','evening') NOT NULL DEFAULT 'morning' AFTER planned_end_time");
    }
    // Auto-detect shift for existing classes based on planned_start_time
    $pdo->exec("UPDATE teaching_classes SET time_shift='afternoon' WHERE planned_start_time IS NOT NULL AND planned_start_time >= '12:00:00' AND planned_start_time < '17:30:00' AND time_shift='morning'");
    $pdo->exec("UPDATE teaching_classes SET time_shift='evening' WHERE planned_start_time IS NOT NULL AND planned_start_time >= '17:30:00' AND time_shift='morning'");
    // Classes created manually may have no planned time. Classify those from
    // their actual lessons so legacy data is grouped correctly too.
    $pdo->exec("UPDATE teaching_classes tc
        JOIN (
            SELECT teaching_class_id, MIN(start_time) AS first_start_time
            FROM teaching_schedule_slots
            GROUP BY teaching_class_id
        ) schedule_time ON schedule_time.teaching_class_id=tc.id
        SET tc.time_shift=CASE
            WHEN schedule_time.first_start_time >= '17:30:00' THEN 'evening'
            WHEN schedule_time.first_start_time >= '12:00:00' THEN 'afternoon'
            ELSE 'morning'
        END
        WHERE tc.planned_start_time IS NULL");
};
