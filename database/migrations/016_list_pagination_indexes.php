<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $addIndex = static function (string $table, string $name, array $columns) use ($pdo): void {
        $indexes = [];
        foreach ($pdo->query("SHOW INDEX FROM `{$table}`") as $index) {
            $indexes[(string) $index['Key_name']][(int) $index['Seq_in_index']] = (string) $index['Column_name'];
        }
        if (isset($indexes[$name])) return;
        foreach ($indexes as $indexedColumns) {
            ksort($indexedColumns, SORT_NUMERIC);
            if (array_slice(array_values($indexedColumns), 0, count($columns)) === $columns) return;
        }
        $columnSql = implode(',', array_map(static fn(string $column): string => "`{$column}`", $columns));
        $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$columnSql})");
    };

    $addIndex('users', 'idx_users_created_id', ['created_at', 'id']);
    $addIndex('users', 'idx_users_approval_created', ['is_approved', 'created_at', 'id']);
    $addIndex('assignments', 'idx_assignments_priority_created', ['priority_order', 'created_at', 'id']);
};
