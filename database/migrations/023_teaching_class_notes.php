<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columns = $pdo->query("SHOW COLUMNS FROM teaching_classes")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('notes', $columns, true)) {
        $pdo->exec('ALTER TABLE teaching_classes ADD COLUMN notes TEXT NULL AFTER class_name');
    }
};
