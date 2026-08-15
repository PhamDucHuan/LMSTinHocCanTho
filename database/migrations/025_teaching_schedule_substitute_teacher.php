<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columns = $pdo->query('SHOW COLUMNS FROM teaching_schedule_slots')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('substitute_teacher_id', $columns, true)) {
        $pdo->exec('ALTER TABLE teaching_schedule_slots ADD COLUMN substitute_teacher_id INT(11) NULL AFTER is_makeup');
        $pdo->exec('ALTER TABLE teaching_schedule_slots ADD INDEX idx_teaching_schedule_substitute (substitute_teacher_id, teaching_date)');
        $pdo->exec('ALTER TABLE teaching_schedule_slots ADD CONSTRAINT fk_teaching_schedule_substitute_teacher FOREIGN KEY (substitute_teacher_id) REFERENCES users(id) ON DELETE SET NULL');
    }
};
