<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS learning_classes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            class_name VARCHAR(191) NOT NULL,
            course_id INT UNSIGNED NOT NULL,
            primary_teacher_id INT UNSIGNED NULL,
            notes TEXT NULL,
            status ENUM('active','archived') NOT NULL DEFAULT 'active',
            legacy_teaching_class_id INT(11) NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_learning_class_legacy (legacy_teaching_class_id),
            INDEX idx_learning_classes_course (course_id, status),
            INDEX idx_learning_classes_teacher (primary_teacher_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS learning_class_teachers (
            learning_class_id INT UNSIGNED NOT NULL,
            teacher_id INT UNSIGNED NOT NULL,
            added_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (learning_class_id, teacher_id),
            INDEX idx_learning_class_teachers_teacher (teacher_id, learning_class_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS learning_class_students (
            learning_class_id INT UNSIGNED NOT NULL,
            student_id INT UNSIGNED NOT NULL,
            added_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (learning_class_id, student_id),
            INDEX idx_learning_class_students_student (student_id, learning_class_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Preserve any course/roster data previously entered in the schedule by moving it once.
    $pdo->exec(
        "INSERT IGNORE INTO learning_classes
            (class_name, course_id, primary_teacher_id, notes, status, legacy_teaching_class_id, created_by, created_at, updated_at)
         SELECT tc.class_name, tc.course_id, tc.teacher_id, tc.notes,
                IF(tc.status='completed','archived','active'), tc.id, tc.created_by, tc.created_at, tc.updated_at
         FROM teaching_classes tc
         WHERE tc.course_id IS NOT NULL"
    );
    $pdo->exec(
        "INSERT IGNORE INTO learning_class_teachers (learning_class_id, teacher_id, added_by)
         SELECT lc.id, tc.teacher_id, COALESCE(tc.created_by, tc.teacher_id)
         FROM learning_classes lc
         JOIN teaching_classes tc ON tc.id=lc.legacy_teaching_class_id
         WHERE tc.teacher_id IS NOT NULL"
    );
    $pdo->exec(
        "INSERT IGNORE INTO learning_class_teachers (learning_class_id, teacher_id, added_by)
         SELECT lc.id, tct.teacher_id, tct.added_by
         FROM learning_classes lc
         JOIN teaching_class_teachers tct ON tct.teaching_class_id=lc.legacy_teaching_class_id"
    );
    $pdo->exec(
        "INSERT IGNORE INTO learning_class_students (learning_class_id, student_id, added_by)
         SELECT lc.id, tcs.student_id, lc.created_by
         FROM learning_classes lc
         JOIN teaching_class_students tcs ON tcs.teaching_class_id=lc.legacy_teaching_class_id
         WHERE tcs.student_id IS NOT NULL"
    );

    // Dữ liệu lịch cũ được giữ nguyên để bảo toàn lịch sử; ứng dụng từ đây chỉ
    // cấp quyền học qua ba bảng learning_* độc lập ở trên.
};
