<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/quiz_schema.php';
require_once __DIR__ . '/../../includes/question_bank.php';

return static function (PDO $pdo): void {
    // Các kiểm tra DDL trước đây chạy trong mỗi lượt mở trang. Từ migration này,
    // chúng chỉ chạy một lần khi triển khai hoặc nâng cấp hệ thống.
    ensureQuizSchema($pdo);
    ensureQuestionBankSchema($pdo);
};
