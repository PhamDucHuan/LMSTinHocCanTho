<?php
require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/drive_helper.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['teacher', 'admin'])) {
    header('Location: ../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    verifyCsrfToken();
    $id = $_POST['id'];
    
    if ($_SESSION['user_role'] === 'admin') {
        $stmt = $pdo->prepare("SELECT * FROM assignments WHERE id = ?");
        $stmt->execute([$id]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM assignments WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$id, $_SESSION['user_id']]);
    }
    $assignment = $stmt->fetch();
    
    if ($assignment) {
        // 1. Xóa file đề bài
        if (!empty($assignment['prompt_file_drive_id'])) {
            deleteFromDrive($assignment['prompt_file_drive_id']);
        }
        
        // 2. Xóa các file dữ liệu (attachments)
        if (!empty($assignment['attachments'])) {
            $attachments = json_decode($assignment['attachments'], true);
            if (is_array($attachments)) {
                foreach ($attachments as $att) {
                    if (!empty($att['drive_id'])) {
                        deleteFromDrive($att['drive_id']);
                    }
                }
            }
        }
        
        // 3. Xóa bài làm của học viên
        $sub_stmt = $pdo->prepare("SELECT file_drive_id, submitted_files FROM submissions WHERE assignment_id = ?");
        $sub_stmt->execute([$id]);
        $submissions = $sub_stmt->fetchAll();
        
        foreach ($submissions as $sub) {
            if (!empty($sub['file_drive_id'])) {
                deleteFromDrive($sub['file_drive_id']);
            }
            if (!empty($sub['submitted_files'])) {
                $submitted_files = json_decode($sub['submitted_files'], true);
                if (is_array($submitted_files)) {
                    foreach ($submitted_files as $moduleName => $fileData) {
                        if (!empty($fileData['drive_id'])) {
                            deleteFromDrive($fileData['drive_id']);
                        }
                    }
                }
            }
        }
        
        // 4. Xóa record trong DB
        $pdo->prepare("DELETE FROM submissions WHERE assignment_id = ?")->execute([$id]);
        
        if ($_SESSION['user_role'] === 'admin') {
            $stmt = $pdo->prepare("DELETE FROM assignments WHERE id = ?");
            $stmt->execute([$id]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM assignments WHERE id = ? AND teacher_id = ?");
            $stmt->execute([$id, $_SESSION['user_id']]);
        }
        
        $_SESSION['success'] = "Đã xóa bài tập và toàn bộ dữ liệu trên Drive thành công!";
    } else {
        $_SESSION['error'] = "Không tìm thấy bài tập hoặc không có quyền xóa.";
    }
}

if (isset($_SERVER['HTTP_REFERER'])) {
    header("Location: " . $_SERVER['HTTP_REFERER']);
} else {
    header('Location: dashboard.php');
}
exit;
?>
