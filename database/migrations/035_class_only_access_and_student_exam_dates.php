<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columns = $pdo->query('SHOW COLUMNS FROM learning_class_students')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('exam_date', $columns, true)) {
        $pdo->exec('ALTER TABLE learning_class_students ADD COLUMN exam_date DATE NULL AFTER student_id');
    }

    // Chuyển các học viên chỉ có trong danh sách ghi danh cũ vào một lớp đang
    // hoạt động để không ai mất quyền khi ứng dụng chuyển sang mô hình lớp-only.
    $courses = $pdo->query(
        'SELECT DISTINCT c.id, c.title, c.teacher_id
         FROM courses c
         JOIN course_enrollments ce ON ce.course_id=c.id
         WHERE NOT EXISTS (
             SELECT 1 FROM learning_classes lc
             JOIN learning_class_students lcs ON lcs.learning_class_id=lc.id
             WHERE lc.course_id=c.id AND lc.status=\'active\' AND lcs.student_id=ce.student_id
         )
         ORDER BY c.id'
    )->fetchAll(PDO::FETCH_ASSOC);

    $findClass = $pdo->prepare("SELECT id FROM learning_classes WHERE course_id=? AND status='active' ORDER BY id LIMIT 1");
    $createClass = $pdo->prepare('INSERT INTO learning_classes (class_name, course_id, primary_teacher_id, notes, created_by) VALUES (?, ?, ?, ?, ?)');
    $addTeacher = $pdo->prepare('INSERT IGNORE INTO learning_class_teachers (learning_class_id, teacher_id, added_by) VALUES (?, ?, ?)');
    $copyStudents = $pdo->prepare(
        "INSERT IGNORE INTO learning_class_students (learning_class_id, student_id, exam_date, added_by)
         SELECT ?, ce.student_id, DATE(ce.exam_date), ?
         FROM course_enrollments ce
         WHERE ce.course_id=? AND NOT EXISTS (
             SELECT 1 FROM learning_classes lc
             JOIN learning_class_students existing ON existing.learning_class_id=lc.id
             WHERE lc.course_id=ce.course_id AND lc.status='active' AND existing.student_id=ce.student_id
         )"
    );
    foreach ($courses as $course) {
        $courseId = (int) $course['id'];
        $ownerId = (int) $course['teacher_id'];
        $findClass->execute([$courseId]);
        $classId = (int) $findClass->fetchColumn();
        if ($classId <= 0) {
            $createClass->execute([
                mb_substr('Lớp ' . (string) $course['title'], 0, 191, 'UTF-8'),
                $courseId,
                $ownerId,
                'Được tạo tự động từ danh sách ghi danh trước khi chuyển sang quản lý theo lớp.',
                $ownerId,
            ]);
            $classId = (int) $pdo->lastInsertId();
            $addTeacher->execute([$classId, $ownerId, $ownerId]);
        }
        $copyStudents->execute([$classId, $ownerId, $courseId]);
    }

    // Giữ lại ngày thi đã nhập trước đây cho mọi học viên trùng khóa học.
    $pdo->exec(
        'UPDATE learning_class_students lcs
         JOIN learning_classes lc ON lc.id=lcs.learning_class_id
         JOIN course_enrollments ce ON ce.course_id=lc.course_id AND ce.student_id=lcs.student_id
         SET lcs.exam_date=DATE(ce.exam_date)
         WHERE ce.exam_date IS NOT NULL AND lcs.exam_date IS NULL'
    );

    $indexes = $pdo->query('SHOW INDEX FROM learning_class_students')->fetchAll(PDO::FETCH_ASSOC);
    if (!in_array('idx_learning_class_students_exam', array_column($indexes, 'Key_name'), true)) {
        $pdo->exec('ALTER TABLE learning_class_students ADD INDEX idx_learning_class_students_exam (exam_date, learning_class_id)');
    }

    // Dữ liệu người tạo/người thêm chỉ phục vụ nhật ký. Nếu tài khoản cũ đã bị xóa,
    // giữ bản ghi lớp và thành viên, đồng thời đưa khóa tham chiếu tùy chọn về NULL.
    $pdo->exec('UPDATE learning_classes lc LEFT JOIN users u ON u.id=lc.primary_teacher_id SET lc.primary_teacher_id=NULL WHERE lc.primary_teacher_id IS NOT NULL AND u.id IS NULL');
    $pdo->exec('UPDATE learning_classes lc LEFT JOIN users u ON u.id=lc.created_by SET lc.created_by=NULL WHERE lc.created_by IS NOT NULL AND u.id IS NULL');
    $pdo->exec('UPDATE learning_class_teachers lct LEFT JOIN users u ON u.id=lct.added_by SET lct.added_by=NULL WHERE lct.added_by IS NOT NULL AND u.id IS NULL');
    $pdo->exec('UPDATE learning_class_students lcs LEFT JOIN users u ON u.id=lcs.added_by SET lcs.added_by=NULL WHERE lcs.added_by IS NOT NULL AND u.id IS NULL');

    // Một số cơ sở dữ liệu cũ dùng INT có dấu cho users/courses, trong khi các bảng
    // lớp mới được tạo bằng INT UNSIGNED. MySQL yêu cầu hai phía khóa ngoại trùng kiểu.
    $matchReferencedType = static function (string $table, string $column, string $referencedTable, string $referencedColumn, bool $nullable) use ($pdo): void {
        $typeStmt = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1');
        $typeStmt->execute([$referencedTable, $referencedColumn]);
        $referencedType = strtolower((string) $typeStmt->fetchColumn());
        $typeStmt->execute([$table, $column]);
        $currentType = strtolower((string) $typeStmt->fetchColumn());
        if ($referencedType === '' || !preg_match('/^(tinyint|smallint|mediumint|int|bigint)(\(\d+\))?( unsigned)?$/', $referencedType)) {
            throw new RuntimeException("Không thể xác định kiểu khóa ngoại {$referencedTable}.{$referencedColumn}.");
        }
        if ($currentType !== $referencedType) {
            $pdo->exec(sprintf(
                'ALTER TABLE `%s` MODIFY `%s` %s %s',
                $table,
                $column,
                strtoupper($referencedType),
                $nullable ? 'NULL' : 'NOT NULL'
            ));
        }
    };
    $matchReferencedType('learning_classes', 'course_id', 'courses', 'id', false);
    $matchReferencedType('learning_classes', 'primary_teacher_id', 'users', 'id', true);
    $matchReferencedType('learning_classes', 'created_by', 'users', 'id', true);
    $matchReferencedType('learning_class_teachers', 'teacher_id', 'users', 'id', false);
    $matchReferencedType('learning_class_teachers', 'added_by', 'users', 'id', true);
    $matchReferencedType('learning_class_students', 'student_id', 'users', 'id', false);
    $matchReferencedType('learning_class_students', 'added_by', 'users', 'id', true);

    $addForeignKey = static function (string $table, string $name, string $sql) use ($pdo): void {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=? LIMIT 1');
        $stmt->execute([$table, $name]);
        if (!$stmt->fetchColumn()) $pdo->exec($sql);
    };
    $addForeignKey('learning_classes', 'fk_learning_classes_course', 'ALTER TABLE learning_classes ADD CONSTRAINT fk_learning_classes_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE');
    $addForeignKey('learning_classes', 'fk_learning_classes_primary_teacher', 'ALTER TABLE learning_classes ADD CONSTRAINT fk_learning_classes_primary_teacher FOREIGN KEY (primary_teacher_id) REFERENCES users(id) ON DELETE SET NULL');
    $addForeignKey('learning_classes', 'fk_learning_classes_creator', 'ALTER TABLE learning_classes ADD CONSTRAINT fk_learning_classes_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL');
    $addForeignKey('learning_class_teachers', 'fk_learning_class_teachers_class', 'ALTER TABLE learning_class_teachers ADD CONSTRAINT fk_learning_class_teachers_class FOREIGN KEY (learning_class_id) REFERENCES learning_classes(id) ON DELETE CASCADE');
    $addForeignKey('learning_class_teachers', 'fk_learning_class_teachers_teacher', 'ALTER TABLE learning_class_teachers ADD CONSTRAINT fk_learning_class_teachers_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE');
    $addForeignKey('learning_class_teachers', 'fk_learning_class_teachers_adder', 'ALTER TABLE learning_class_teachers ADD CONSTRAINT fk_learning_class_teachers_adder FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL');
    $addForeignKey('learning_class_students', 'fk_learning_class_students_class', 'ALTER TABLE learning_class_students ADD CONSTRAINT fk_learning_class_students_class FOREIGN KEY (learning_class_id) REFERENCES learning_classes(id) ON DELETE CASCADE');
    $addForeignKey('learning_class_students', 'fk_learning_class_students_student', 'ALTER TABLE learning_class_students ADD CONSTRAINT fk_learning_class_students_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE');
    $addForeignKey('learning_class_students', 'fk_learning_class_students_adder', 'ALTER TABLE learning_class_students ADD CONSTRAINT fk_learning_class_students_adder FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL');
};
