<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS course_materials (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            course_id INT NOT NULL,
            title VARCHAR(191) NOT NULL,
            material_type ENUM('pdf','video') NOT NULL,
            file_drive_id VARCHAR(191) NULL,
            file_name VARCHAR(255) NULL,
            youtube_video_id VARCHAR(32) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_course_materials_course (course_id, sort_order, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $matchReferencedType = static function (string $column, string $referencedTable, bool $nullable) use ($pdo): void {
        $stmt = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1');
        $stmt->execute([$referencedTable, 'id']);
        $referencedType = strtolower((string) $stmt->fetchColumn());
        $stmt->execute(['course_materials', $column]);
        $currentType = strtolower((string) $stmt->fetchColumn());
        if ($referencedType === '' || !preg_match('/^(tinyint|smallint|mediumint|int|bigint)(\(\d+\))?( unsigned)?$/', $referencedType)) {
            throw new RuntimeException("Không thể xác định kiểu khóa ngoại {$referencedTable}.id.");
        }
        if ($currentType !== $referencedType) {
            $pdo->exec(sprintf('ALTER TABLE `course_materials` MODIFY `%s` %s %s', $column, strtoupper($referencedType), $nullable ? 'NULL' : 'NOT NULL'));
        }
    };
    $matchReferencedType('course_id', 'courses', false);
    $matchReferencedType('created_by', 'users', true);

    $addForeignKey = static function (string $name, string $sql) use ($pdo): void {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='course_materials' AND CONSTRAINT_NAME=? LIMIT 1");
        $stmt->execute([$name]);
        if (!$stmt->fetchColumn()) $pdo->exec($sql);
    };
    $addForeignKey('fk_course_materials_course', 'ALTER TABLE course_materials ADD CONSTRAINT fk_course_materials_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE');
    $addForeignKey('fk_course_materials_creator', 'ALTER TABLE course_materials ADD CONSTRAINT fk_course_materials_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL');
};
