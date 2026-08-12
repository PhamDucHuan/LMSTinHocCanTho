<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $indexes = [];
    foreach ($pdo->query('SHOW INDEX FROM audit_logs') as $index) {
        $indexes[(string) $index['Key_name']] = true;
    }
    if (!isset($indexes['idx_audit_login_created'])) {
        $pdo->exec('ALTER TABLE audit_logs ADD INDEX idx_audit_login_created (action, created_at, id)');
    }
};
