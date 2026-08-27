<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columns = $pdo->query('SHOW COLUMNS FROM teaching_schedule_slots')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('attendance_status', $columns, true)) {
        $pdo->exec("ALTER TABLE teaching_schedule_slots ADD COLUMN attendance_status ENUM('pending','present','absent') NOT NULL DEFAULT 'pending' AFTER is_makeup");
    }
};
