<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS teaching_classes (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            class_name VARCHAR(191) NOT NULL,
            teacher_id INT(11) NULL,
            created_by INT(11) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_teaching_classes_teacher (teacher_id, class_name),
            CONSTRAINT fk_teaching_classes_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_teaching_classes_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS teaching_class_students (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            teaching_class_id INT(11) NOT NULL,
            student_name VARCHAR(191) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_teaching_class_student (teaching_class_id, student_name),
            CONSTRAINT fk_teaching_class_students_class FOREIGN KEY (teaching_class_id) REFERENCES teaching_classes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS teaching_schedule_slots (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            teaching_class_id INT(11) NOT NULL,
            teaching_date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            created_by INT(11) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_teaching_schedule_calendar (teaching_date, teaching_class_id),
            CONSTRAINT fk_teaching_schedule_class FOREIGN KEY (teaching_class_id) REFERENCES teaching_classes(id) ON DELETE CASCADE,
            CONSTRAINT fk_teaching_schedule_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
