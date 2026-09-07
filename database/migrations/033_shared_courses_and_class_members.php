<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS course_teachers (
            course_id INT UNSIGNED NOT NULL,
            teacher_id INT UNSIGNED NOT NULL,
            added_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (course_id, teacher_id),
            INDEX idx_course_teachers_teacher (teacher_id, course_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec('INSERT IGNORE INTO course_teachers (course_id, teacher_id, added_by) SELECT id, teacher_id, teacher_id FROM courses');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS teaching_class_teachers (
            teaching_class_id INT(11) NOT NULL,
            teacher_id INT UNSIGNED NOT NULL,
            added_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (teaching_class_id, teacher_id),
            INDEX idx_teaching_class_teachers_teacher (teacher_id, teaching_class_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec('INSERT IGNORE INTO teaching_class_teachers (teaching_class_id, teacher_id, added_by) SELECT id, teacher_id, created_by FROM teaching_classes WHERE teacher_id IS NOT NULL');

    $studentColumns = $pdo->query('SHOW COLUMNS FROM teaching_class_students')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('student_id', $studentColumns, true)) {
        $pdo->exec('ALTER TABLE teaching_class_students ADD COLUMN student_id INT UNSIGNED NULL AFTER student_name');
    }

    $indexes = $pdo->query('SHOW INDEX FROM teaching_class_students')->fetchAll(PDO::FETCH_ASSOC);
    $indexNames = array_values(array_unique(array_column($indexes, 'Key_name')));
    if (!in_array('idx_teaching_class_student_name', $indexNames, true)) {
        $pdo->exec('ALTER TABLE teaching_class_students ADD INDEX idx_teaching_class_student_name (teaching_class_id, student_name)');
        $indexNames[] = 'idx_teaching_class_student_name';
    }
    if (in_array('uq_teaching_class_student', $indexNames, true)) {
        // Add the replacement first because the old composite index may currently support the class foreign key.
        $pdo->exec('ALTER TABLE teaching_class_students DROP INDEX uq_teaching_class_student');
        $indexNames = array_values(array_diff($indexNames, ['uq_teaching_class_student']));
    }
    if (!in_array('idx_teaching_class_students_user', $indexNames, true)) {
        $pdo->exec('ALTER TABLE teaching_class_students ADD INDEX idx_teaching_class_students_user (student_id, teaching_class_id)');
    }
    if (!in_array('uq_teaching_class_student_user', $indexNames, true)) {
        $pdo->exec('ALTER TABLE teaching_class_students ADD UNIQUE KEY uq_teaching_class_student_user (teaching_class_id, student_id)');
    }

    // Preserve old free-text rosters and link an account only when its name identifies one student uniquely.
    $pdo->exec(
        "UPDATE teaching_class_students tcs
         JOIN (
             SELECT name, MIN(id) AS student_id
             FROM users
             WHERE role='student'
             GROUP BY name
             HAVING COUNT(*)=1
         ) matched ON matched.name=tcs.student_name
         SET tcs.student_id=matched.student_id
         WHERE tcs.student_id IS NULL"
    );
};
