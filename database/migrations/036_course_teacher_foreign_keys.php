<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $orphanCount = (int) $pdo->query(
        'SELECT COUNT(*)
         FROM course_teachers ct
         LEFT JOIN courses c ON c.id=ct.course_id
         LEFT JOIN users u ON u.id=ct.teacher_id
         WHERE c.id IS NULL OR u.id IS NULL'
    )->fetchColumn();
    if ($orphanCount > 0) {
        throw new RuntimeException("Có {$orphanCount} quyền chia sẻ khóa học không hợp lệ; cần xử lý trước khi thêm khóa ngoại.");
    }

    $pdo->exec('UPDATE course_teachers ct LEFT JOIN users u ON u.id=ct.added_by SET ct.added_by=NULL WHERE ct.added_by IS NOT NULL AND u.id IS NULL');

    $matchReferencedType = static function (string $column, string $referencedTable, bool $nullable) use ($pdo): void {
        $stmt = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1');
        $stmt->execute([$referencedTable, 'id']);
        $referencedType = strtolower((string) $stmt->fetchColumn());
        $stmt->execute(['course_teachers', $column]);
        $currentType = strtolower((string) $stmt->fetchColumn());
        if ($referencedType === '' || !preg_match('/^(tinyint|smallint|mediumint|int|bigint)(\(\d+\))?( unsigned)?$/', $referencedType)) {
            throw new RuntimeException("Không thể xác định kiểu khóa ngoại {$referencedTable}.id.");
        }
        if ($currentType !== $referencedType) {
            $pdo->exec(sprintf(
                'ALTER TABLE `course_teachers` MODIFY `%s` %s %s',
                $column,
                strtoupper($referencedType),
                $nullable ? 'NULL' : 'NOT NULL'
            ));
        }
    };
    $matchReferencedType('course_id', 'courses', false);
    $matchReferencedType('teacher_id', 'users', false);
    $matchReferencedType('added_by', 'users', true);

    $addForeignKey = static function (string $name, string $sql) use ($pdo): void {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='course_teachers' AND CONSTRAINT_NAME=? LIMIT 1");
        $stmt->execute([$name]);
        if (!$stmt->fetchColumn()) $pdo->exec($sql);
    };
    $addForeignKey('fk_course_teachers_course', 'ALTER TABLE course_teachers ADD CONSTRAINT fk_course_teachers_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE');
    $addForeignKey('fk_course_teachers_teacher', 'ALTER TABLE course_teachers ADD CONSTRAINT fk_course_teachers_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE');
    $addForeignKey('fk_course_teachers_adder', 'ALTER TABLE course_teachers ADD CONSTRAINT fk_course_teachers_adder FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL');
};
