<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columns = $pdo->query('SHOW COLUMNS FROM teaching_schedule_slots')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('makeup_student_id', $columns, true)) {
        $pdo->exec('ALTER TABLE teaching_schedule_slots ADD COLUMN makeup_student_id INT(11) NULL AFTER attendance_status, ADD INDEX idx_teaching_schedule_makeup_student (makeup_student_id, teaching_date), ADD CONSTRAINT fk_teaching_schedule_makeup_student FOREIGN KEY (makeup_student_id) REFERENCES teaching_class_students(id) ON DELETE CASCADE');
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS teaching_schedule_student_attendance (
            slot_id INT(11) NOT NULL,
            student_id INT(11) NOT NULL,
            attendance_status ENUM('present','absent') NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (slot_id, student_id),
            INDEX idx_student_attendance_student (student_id, attendance_status),
            CONSTRAINT fk_student_attendance_slot FOREIGN KEY (slot_id) REFERENCES teaching_schedule_slots(id) ON DELETE CASCADE,
            CONSTRAINT fk_student_attendance_student FOREIGN KEY (student_id) REFERENCES teaching_class_students(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
