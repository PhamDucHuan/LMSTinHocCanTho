<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $tableExists = static function (string $table) use ($pdo): bool {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    };

    $addIndex = static function (string $table, string $name, array $columns) use ($pdo, $tableExists): void {
        if (!$tableExists($table)) return;
        $existing = [];
        foreach ($pdo->query("SHOW INDEX FROM `{$table}`") as $index) {
            $existing[(string) $index['Key_name']][(int) $index['Seq_in_index']] = (string) $index['Column_name'];
        }
        foreach ($existing as $indexedColumns) {
            ksort($indexedColumns, SORT_NUMERIC);
            $indexedColumns = array_values($indexedColumns);
            if (array_slice($indexedColumns, 0, count($columns)) === $columns) return;
        }
        $columnSql = implode(',', array_map(static fn(string $column): string => "`{$column}`", $columns));
        $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$columnSql})");
    };

    // Dashboard và danh sách theo người quản lý.
    $addIndex('users', 'idx_users_role_id', ['role', 'id']);
    $addIndex('courses', 'idx_courses_teacher_created', ['teacher_id', 'created_at', 'id']);
    $addIndex('assignments', 'idx_assignments_teacher_created', ['teacher_id', 'created_at', 'id']);

    // Bài nộp: tra cứu theo học viên, bài tập và trạng thái duyệt.
    $addIndex('submissions', 'idx_submissions_student_assignment', ['student_id', 'assignment_id']);
    $addIndex('submissions', 'idx_submissions_assignment_status_date', ['assignment_id', 'grading_status', 'submitted_at']);
    $addIndex('course_enrollments', 'idx_enrollments_student_date_course', ['student_id', 'enrolled_at', 'course_id']);

    // Đếm đề đã mở theo khóa học và phân trang dữ liệu quản trị.
    $addIndex('quizzes', 'idx_quizzes_course_published', ['course_id', 'is_published', 'id']);
    $addIndex('question_bank', 'idx_question_bank_owner_updated', ['teacher_id', 'updated_at', 'id']);
    $addIndex('audit_logs', 'idx_audit_created_id', ['created_at', 'id']);
};
