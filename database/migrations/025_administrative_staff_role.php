<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $column = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
    $type = strtolower((string) ($column['Type'] ?? ''));
    if (str_starts_with($type, 'enum(') && !str_contains($type, "'administrative_staff'")) {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('student','teacher','administrative_staff','admin') NOT NULL DEFAULT 'student'");
    }
};
