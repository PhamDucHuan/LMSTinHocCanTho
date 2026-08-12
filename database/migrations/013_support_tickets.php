<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    // 1. Create support_tickets table
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_tickets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        subject VARCHAR(255) NOT NULL,
        category VARCHAR(100) NOT NULL DEFAULT 'general',
        status ENUM('open', 'answered', 'closed') NOT NULL DEFAULT 'open',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 2. Create ticket_replies table
    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_replies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT NOT NULL,
        user_id INT NOT NULL,
        message TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 3. Add Indexes for Query Performance optimization (for dashboard queries)
    $addIndex = static function (string $table, string $name, array $columns) use ($pdo): void {
        $existing = [];
        foreach ($pdo->query("SHOW INDEX FROM `$table`") as $index) {
            $existing[(string) $index['Key_name']][(int) $index['Seq_in_index']] = (string) $index['Column_name'];
        }
        foreach ($existing as $indexedColumns) {
            ksort($indexedColumns, SORT_NUMERIC);
            $indexedColumns = array_values($indexedColumns);
            if (array_slice($indexedColumns, 0, count($columns)) === $columns) return;
        }
        $columnSql = implode(',', array_map(static fn(string $col) => "`$col`", $columns));
        $pdo->exec("ALTER TABLE `$table` ADD INDEX `$name` ($columnSql)");
    };

    // Submissions indexes for faster aggregation
    $addIndex('submissions', 'idx_submissions_submitted_at', ['submitted_at']);
    $addIndex('submissions', 'idx_submissions_score', ['score']);
    
    // Grading jobs index for wait times
    $addIndex('grading_jobs', 'idx_grading_jobs_status_dates', ['status', 'started_at', 'completed_at']);
    
    // Tickets index
    $addIndex('support_tickets', 'idx_tickets_student', ['student_id', 'status']);
};