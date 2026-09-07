<?php
declare(strict_types=1);

/**
 * Script cron để nhắc nhở học viên tự động.
 * Khuyến nghị cài đặt crontab chạy mỗi giờ một lần:
 * 0 * * * * php /path/to/LMSTinHocCanTho/cron_reminders.php
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/email_templates.php';
global $pdo;

// Hàm hỗ trợ log ra màn hình/console
function log_cron(string $message): void {
    echo "[" . date('Y-m-d H:i:s') . "] " . $message . "\n";
}

// Hàm giả lập gửi email (hoặc gửi thật nếu server cấu hình sẵn sendmail/postfix)
// Hàm này đã bị loại bỏ, thay bằng sendSystemEmail trong notifications.php

log_cron("Bắt đầu chạy Cron Nhắc nhở Tự động...");

// ==========================================
// 1. Nhắc nhở Bài tập sắp tới hạn (Deadline)
// ==========================================
// Tìm các bài tập có deadline trong vòng 24h tới
$assignmentStmt = $pdo->query("
    SELECT id, title, course_id, due_date 
    FROM assignments 
    WHERE due_date IS NOT NULL 
      AND due_date > NOW() 
      AND due_date <= DATE_ADD(NOW(), INTERVAL 24 HOUR)
");
$upcomingAssignments = $assignmentStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($upcomingAssignments as $assignment) {
    // Tìm các học viên trong khóa học này (hoặc toàn bộ nếu course_id null) mà chưa nộp bài
    $studentSql = "
        SELECT u.id, u.name, u.email 
        FROM users u 
    ";
    $params = [];
    
    if ($assignment['course_id']) {
        $studentSql .= "
            WHERE u.role = 'student' AND EXISTS (
                SELECT 1 FROM learning_classes lc
                JOIN learning_class_students lcs ON lcs.learning_class_id=lc.id
                WHERE lc.course_id=? AND lc.status='active' AND lcs.student_id=u.id
            )
        ";
        $params[] = $assignment['course_id'];
    } else {
        $studentSql .= " WHERE u.role = 'student' ";
    }
    
    // Đảm bảo học viên CHƯA nộp bài
    $studentSql .= "
        AND NOT EXISTS (
            SELECT 1 FROM submissions s WHERE s.assignment_id = ? AND s.student_id = u.id
        )
    ";
    $params[] = $assignment['id'];
    
    $studentStmt = $pdo->prepare($studentSql);
    $studentStmt->execute($params);
    $studentsToRemind = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($studentsToRemind as $student) {
        // Kiểm tra xem đã gửi nhắc nhở này chưa
        $checkLog = $pdo->prepare("SELECT 1 FROM reminder_logs WHERE user_id = ? AND type = 'assignment_deadline' AND reference_id = ?");
        $checkLog->execute([$student['id'], $assignment['id']]);
        if (!$checkLog->fetch()) {
            // Chưa gửi -> Tiến hành gửi
            $title = "⏳ Hạn chót sắp đến!";
            $message = "Bài tập '{$assignment['title']}' sắp hết hạn vào lúc " . date('H:i d/m', strtotime($assignment['due_date'])) . ". Vui lòng nộp bài sớm!";
            
            // Gửi Notification trên web
            createNotification($pdo, (int)$student['id'], 'reminder', $title, $message, '#');
            
            // Gửi Email
            $dueDateStr = date('H:i d/m/Y', strtotime((string)$assignment['due_date']));
            $emailBody = get_deadline_reminder_email_html($student['name'], $assignment['title'], $dueDateStr, '#');
            sendSystemEmail($student['email'], $title, $emailBody);
            
            // Ghi Log
            $pdo->prepare("INSERT INTO reminder_logs (user_id, type, reference_id) VALUES (?, 'assignment_deadline', ?)")
                ->execute([$student['id'], $assignment['id']]);
                
            log_cron("Đã nhắc nhở học viên {$student['name']} về deadline bài tập {$assignment['id']}");
        }
    }
}

// ==========================================
// 2. Nhắc nhở Ngày thi sắp tới
// ==========================================
// Tìm từng học viên trong lớp có ngày thi trong vòng 3 ngày tới.
$examStmt = $pdo->query("
    SELECT lc.course_id, lcs.student_id, MIN(lcs.exam_date) exam_date, u.name, u.email, c.title as course_title, c.teacher_id
    FROM learning_classes lc
    JOIN learning_class_students lcs ON lcs.learning_class_id=lc.id
    JOIN users u ON u.id=lcs.student_id
    JOIN courses c ON c.id=lc.course_id
    WHERE lc.status='active' AND lcs.exam_date IS NOT NULL
      AND lcs.exam_date >= CURDATE()
      AND lcs.exam_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
    GROUP BY lc.course_id, lcs.student_id, u.name, u.email, c.title, c.teacher_id
");
$upcomingExams = $examStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($upcomingExams as $exam) {
    // Kiểm tra xem đã báo cho giáo viên về học viên này trong khóa học này chưa
    $reminderType = 'exam_teacher_course_' . (int) $exam['course_id'];
    $checkLog = $pdo->prepare("
        SELECT 1 FROM reminder_logs 
        WHERE user_id = ? AND type = ? AND reference_id = ?
        AND sent_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
    ");
    $checkLog->execute([$exam['teacher_id'], $reminderType, $exam['student_id']]);
    
    if (!$checkLog->fetch()) {
        $daysLeft = ceil((strtotime($exam['exam_date']) - time()) / 86400);
        $title = "🎓 Cần nhắc nhở ôn thi!";
        $message = "Học viên {$exam['name']} sắp thi môn '{$exam['course_title']}' trong {$daysLeft} ngày nữa. Vui lòng bấm nhắc nhở.";
        
        // Gửi Notification cho Giáo viên
        createNotification($pdo, (int)$exam['teacher_id'], 'reminder', $title, $message, '../teacher/student_progress.php?course_id=' . $exam['course_id']);
        
        // Ghi Log (đã báo giáo viên)
        $pdo->prepare('INSERT INTO reminder_logs (user_id, type, reference_id) VALUES (?, ?, ?)')
            ->execute([$exam['teacher_id'], $reminderType, $exam['student_id']]);
            
        log_cron("Đã báo giáo viên ({$exam['teacher_id']}) về kỳ thi của học viên {$exam['name']} môn {$exam['course_title']}");
    }
}

log_cron("Cron hoàn tất.");
?>
