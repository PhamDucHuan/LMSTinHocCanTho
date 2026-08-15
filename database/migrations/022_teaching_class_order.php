<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columns = $pdo->query("SHOW COLUMNS FROM teaching_classes")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('sort_order', $columns, true)) {
        $pdo->exec('ALTER TABLE teaching_classes ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER status');
        $pdo->exec('UPDATE teaching_classes SET sort_order=id WHERE sort_order=0');
    }
    $indexes = $pdo->query('SHOW INDEX FROM teaching_classes')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($indexes as $index) {
        if (($index['Key_name'] ?? '') === 'idx_teaching_classes_order') return;
    }
    $pdo->exec('ALTER TABLE teaching_classes ADD INDEX idx_teaching_classes_order (status, sort_order, id)');
};
