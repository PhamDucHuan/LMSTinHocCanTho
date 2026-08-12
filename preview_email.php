<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/email_templates.php';

$type = $_GET['type'] ?? 'exam';

if ($type === 'exam') {
    echo get_exam_reminder_email_html(
        "Nguyễn Văn A", 
        "Lập trình PHP Cơ bản", 
        "15/08/2026", 
        3, 
        "http://localhost/LMSTinHocCanTho/index.php"
    );
} elseif ($type === 'deadline') {
    echo get_deadline_reminder_email_html(
        "Trần Thị B", 
        "Bài tập thực hành buổi 5", 
        "23:59 12/08/2026", 
        "http://localhost/LMSTinHocCanTho/index.php"
    );
} elseif ($type === 'engagement') {
    echo get_course_engagement_reminder_email_html(
        "Lê Văn C", 
        "Cơ sở dữ liệu nâng cao", 
        "http://localhost/LMSTinHocCanTho/index.php"
    );
} else {
    echo "<h1>Mẫu Email không hợp lệ</h1>";
}
?>
