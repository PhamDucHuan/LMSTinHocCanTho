<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $indexes = $pdo->query('SHOW INDEX FROM teaching_schedule_slots')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($indexes as $index) {
        if (($index['Key_name'] ?? '') === 'idx_teaching_schedule_chronological') {
            return;
        }
    }

    $pdo->exec(
        'ALTER TABLE teaching_schedule_slots
         ADD INDEX idx_teaching_schedule_chronological
         (teaching_date, start_time, end_time, teaching_class_id, id)'
    );
};
