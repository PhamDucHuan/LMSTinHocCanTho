<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columns = $pdo->query("SHOW COLUMNS FROM teaching_classes")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('course_id', $columns, true)) {
        // Không dùng khóa ngoại ở đây vì một số CSDL cũ có kiểu id courses khác nhau.
        // Liên kết vẫn được kiểm tra khi tạo lớp để tránh lưu khóa học không tồn tại.
        $pdo->exec('ALTER TABLE teaching_classes ADD COLUMN course_id INT NULL AFTER class_name');
    }
    if (!in_array('status', $columns, true)) {
        $pdo->exec("ALTER TABLE teaching_classes ADD COLUMN status ENUM('active','completed') NOT NULL DEFAULT 'active' AFTER created_by");
    }

    $indexes = $pdo->query('SHOW INDEX FROM teaching_classes')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($indexes as $index) {
        if (($index['Key_name'] ?? '') === 'idx_teaching_classes_course') {
            return;
        }
    }
    $pdo->exec('ALTER TABLE teaching_classes ADD INDEX idx_teaching_classes_course (course_id)');
};
