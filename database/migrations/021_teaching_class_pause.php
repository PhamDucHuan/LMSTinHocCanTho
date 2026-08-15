<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columns = $pdo->query("SHOW COLUMNS FROM teaching_classes LIKE 'status'")->fetchAll(PDO::FETCH_ASSOC);
    if ($columns) {
        $pdo->exec("ALTER TABLE teaching_classes MODIFY status ENUM('active','paused','completed') NOT NULL DEFAULT 'active'");
    }
};
