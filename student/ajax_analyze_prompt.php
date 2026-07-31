<?php
require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/drive_helper.php';

header('Content-Type: application/json');
set_time_limit(480); // Cho phép một lần thử lại khi Gemini phản hồi chậm

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}
verifyCsrfToken();

$data = json_decode(file_get_contents('php://input'), true);
$assignment_id = $data['assignment_id'] ?? null;

if (!$assignment_id) {
    echo json_encode(['status' => 'error', 'message' => 'Missing assignment_id']);
    exit;
}

try {
    if (($_SESSION['user_role'] ?? '') === 'admin') {
        $stmt = $pdo->prepare("SELECT a.* FROM assignments a WHERE a.id = ?");
        $stmt->execute([$assignment_id]);
    } elseif (($_SESSION['user_role'] ?? '') === 'teacher') {
        $stmt = $pdo->prepare("SELECT a.* FROM assignments a WHERE a.id = ? AND a.teacher_id = ?");
        $stmt->execute([$assignment_id, $_SESSION['user_id']]);
    } else {
        $stmt = $pdo->prepare("SELECT a.* FROM assignments a WHERE a.id = ? AND (a.course_id IS NULL OR EXISTS (SELECT 1 FROM course_enrollments ce WHERE ce.course_id = a.course_id AND ce.student_id = ?))");
        $stmt->execute([$assignment_id, $_SESSION['user_id']]);
    }
    $assignment = $stmt->fetch();
    
    if (!$assignment) {
        echo json_encode(['status' => 'error', 'message' => 'Assignment not found']);
        exit;
    }
    
    // Nếu đã phân tích rồi thì trả về luôn
    if (!empty($assignment['ai_analysis'])) {
        echo json_encode([
            'status' => 'success',
            'analysis' => json_decode($assignment['ai_analysis'], true)
        ]);
        exit;
    }
    
    // Nếu chưa có file đề
    if (empty($assignment['prompt_file_drive_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'Không có file đề bài để phân tích']);
        exit;
    }
    
    // Chuẩn bị danh sách modules
    $module_settings = json_decode($assignment['module_settings'] ?? '[]', true);
    $modules = [];
    if (is_array($module_settings)) {
        foreach ($module_settings as $m) {
            $modules[] = $m['module'];
        }
    }
    
    if (empty($modules)) {
        echo json_encode(['status' => 'error', 'message' => 'Bài tập chưa có phần nào được thiết lập']);
        exit;
    }
    
    // Tải file đề bài về
    $temp_dir = __DIR__ . '/../uploads/temp_ai/';
    if (!is_dir($temp_dir)) mkdir($temp_dir, 0777, true);
    
    $prompt_ext = strtolower(pathinfo($assignment['prompt_file_name'] ?? '', PATHINFO_EXTENSION)) ?: 'txt';
    $temp_prompt = $temp_dir . 'prompt_' . bin2hex(random_bytes(12)) . '.' . $prompt_ext;
    
    if (!downloadFromDrive($assignment['prompt_file_drive_id'], $temp_prompt)) {
        throw new RuntimeException('Không thể chuẩn bị file đề bài để AI phân tích.');
    }
    
    // Gửi yêu cầu sang FastAPI
    $ai_request_data = [
        'prompt_local_path' => $temp_prompt,
        'modules' => $modules
    ];
    
    $ch = curl_init(aiServiceUrl('/analyze_prompt'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 420); // Hai lượt Gemini tối đa 180 giây/lượt cộng thời gian chờ giữa các lượt
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, aiServiceHeaders());
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($ai_request_data));
    $ai_response_json = curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    @unlink($temp_prompt);
    
    if ($curl_error) {
        throw new Exception("Lỗi kết nối AI Server: " . $curl_error);
    }
    
    $ai_response = json_decode($ai_response_json, true);
    if (isset($ai_response['status']) && $ai_response['status'] === 'success') {
        $analysis = $ai_response['analysis'];
        
        // Lưu vào database
        $pdo = createDatabaseConnection();
        $update = $pdo->prepare("UPDATE assignments SET ai_analysis = ? WHERE id = ?");
        $update->execute([json_encode($analysis, JSON_UNESCAPED_UNICODE), $assignment_id]);
        
        echo json_encode([
            'status' => 'success',
            'analysis' => $analysis
        ]);
    } else {
        $error_msg = $ai_response['message'] ?? ($ai_response['detail'] ?? 'Unknown error');
        if ($error_msg === 'Not Found') {
            $error_msg = "Không tìm thấy tính năng phân tích. Hãy khởi động lại dịch vụ Node.js bằng file ai_service/start_ai.bat.";
        }
        throw new Exception($error_msg);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>
