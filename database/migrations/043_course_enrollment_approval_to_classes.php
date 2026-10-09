<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $integerType = static function (string $table, string $column) use ($pdo): string {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1'
        );
        $stmt->execute([$table, $column]);
        $type = strtolower((string) $stmt->fetchColumn());
        if (!preg_match('/^(tinyint|smallint|mediumint|int|bigint)(\(\d+\))?( unsigned)?$/', $type)) {
            throw new RuntimeException("Không thể xác định kiểu khóa {$table}.{$column}.");
        }
        return strtoupper($type);
    };

    $courseIdType = $integerType('courses', 'id');
    $userIdType = $integerType('users', 'id');
    $classIdType = $integerType('learning_classes', 'id');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS course_enrollment_requests (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            course_id {$courseIdType} NOT NULL,
            student_id {$userIdType} NOT NULL,
            learning_class_id {$classIdType} NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME NULL,
            reviewed_by {$userIdType} NULL,
            UNIQUE KEY uq_course_enrollment_request (course_id, student_id),
            INDEX idx_enrollment_requests_status (status),
            INDEX idx_enrollment_requests_course_status (course_id, status, requested_at),
            INDEX idx_enrollment_requests_class (learning_class_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = $pdo->query('SHOW COLUMNS FROM course_enrollment_requests')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('learning_class_id', $columns, true)) {
        $pdo->exec("ALTER TABLE course_enrollment_requests ADD COLUMN learning_class_id {$classIdType} NULL AFTER student_id");
    }

    $indexNames = array_values(array_unique(array_column(
        $pdo->query('SHOW INDEX FROM course_enrollment_requests')->fetchAll(PDO::FETCH_ASSOC),
        'Key_name'
    )));
    if (!in_array('idx_enrollment_requests_course_status', $indexNames, true)) {
        $pdo->exec('ALTER TABLE course_enrollment_requests ADD INDEX idx_enrollment_requests_course_status (course_id, status, requested_at)');
    }
    if (!in_array('idx_enrollment_requests_class', $indexNames, true)) {
        $pdo->exec('ALTER TABLE course_enrollment_requests ADD INDEX idx_enrollment_requests_class (learning_class_id)');
    }

    $addForeignKey = static function (string $name, string $sql) use ($pdo): void {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=\'course_enrollment_requests\' AND CONSTRAINT_NAME=? LIMIT 1'
        );
        $stmt->execute([$name]);
        if (!$stmt->fetchColumn()) $pdo->exec($sql);
    };
    $addForeignKey('fk_enrollment_request_course', 'ALTER TABLE course_enrollment_requests ADD CONSTRAINT fk_enrollment_request_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE');
    $addForeignKey('fk_enrollment_request_student', 'ALTER TABLE course_enrollment_requests ADD CONSTRAINT fk_enrollment_request_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE');
    $addForeignKey('fk_enrollment_request_reviewer', 'ALTER TABLE course_enrollment_requests ADD CONSTRAINT fk_enrollment_request_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL');
    $addForeignKey('fk_enrollment_request_class', 'ALTER TABLE course_enrollment_requests ADD CONSTRAINT fk_enrollment_request_class FOREIGN KEY (learning_class_id) REFERENCES learning_classes(id) ON DELETE SET NULL');

    // Everyone who already belongs to an active class remains fully enrolled.
    // The request row is only history/status; access continues to come from the
    // existing learning_class_students membership.
    $pdo->exec(
        "INSERT INTO course_enrollment_requests
            (course_id, student_id, learning_class_id, status, requested_at, reviewed_at, reviewed_by)
         SELECT members.course_id, members.student_id, members.learning_class_id, 'approved',
                members.joined_at, members.joined_at, members.reviewer_id
         FROM (
             SELECT lc.course_id, lcs.student_id, MIN(lc.id) AS learning_class_id,
                    MIN(lcs.created_at) AS joined_at,
                    COALESCE(MIN(lcs.added_by), MIN(lc.primary_teacher_id), MIN(c.teacher_id)) AS reviewer_id
             FROM learning_classes lc
             JOIN learning_class_students lcs ON lcs.learning_class_id=lc.id
             JOIN courses c ON c.id=lc.course_id
             WHERE lc.status='active'
             GROUP BY lc.course_id, lcs.student_id
         ) members
         ON DUPLICATE KEY UPDATE
            learning_class_id=VALUES(learning_class_id),
            status='approved',
            reviewed_at=COALESCE(reviewed_at, VALUES(reviewed_at)),
            reviewed_by=COALESCE(reviewed_by, VALUES(reviewed_by))"
    );
};

