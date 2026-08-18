<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columns = $pdo->query('SHOW COLUMNS FROM teaching_shift_presets')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('major_shift', $columns, true)) {
        $pdo->exec("ALTER TABLE teaching_shift_presets ADD COLUMN major_shift ENUM('morning','afternoon','evening') NOT NULL DEFAULT 'morning' AFTER end_time");
    }
};
