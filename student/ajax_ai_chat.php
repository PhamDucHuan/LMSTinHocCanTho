<?php
require_once '../includes/security.php';
require_once '../includes/authorization.php';
secureSessionStart();
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Bạn cần đăng nhập để sử dụng tính năng này.']);
    exit;
}

verifyCsrfToken();
session_write_close(); // Unlock session before long AI API call

$data = json_decode(file_get_contents('php://input'), true);
$assignment_id = filter_var($data['assignment_id'] ?? null, FILTER_VALIDATE_INT);
$message       = trim((string) ($data['message'] ?? ''));
$history       = is_array($data['history'] ?? null) ? $data['history'] : [];

// ---- Validation ----
if (!$assignment_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Thiếu ID bài tập.']);
    exit;
}
if ($message === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Tin nhắn không được để trống.']);
    exit;
}
if (mb_strlen($message) > 2000) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Tin nhắn quá dài (tối đa 2000 ký tự).']);
    exit;
}

// ---- Rate limiting: 15 messages per minute per user ----
$rateLimitKey   = 'ai_chat_rate_' . $_SESSION['user_id'];
$rateLimitReset = 'ai_chat_reset_' . $_SESSION['user_id'];
$now            = time();

if (!isset($_SESSION[$rateLimitReset]) || $now - $_SESSION[$rateLimitReset] >= 60) {
    $_SESSION[$rateLimitKey]   = 0;
    $_SESSION[$rateLimitReset] = $now;
}
$_SESSION[$rateLimitKey]++;
if ($_SESSION[$rateLimitKey] > 15) {
    $waitSecs = 60 - ($now - $_SESSION[$rateLimitReset]);
    http_response_code(429);
    echo json_encode([
        'status'  => 'error',
        'message' => "Bạn đã gửi quá nhiều tin nhắn. Vui lòng chờ {$waitSecs} giây rồi thử lại."
    ]);
    exit;
}

// ---- Check assignment access ----
try {
    $assignment = authorizationFindAccessibleAssignment(
        $pdo,
        $assignment_id,
        (string) ($_SESSION['user_role'] ?? ''),
        (int)   $_SESSION['user_id']
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Lỗi kiểm tra quyền truy cập.']);
    exit;
}

if (!$assignment) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Bạn không có quyền truy cập bài tập này.']);
    exit;
}

// ---- Build assignment context for AI ----
$context  = "Tên bài tập: " . ($assignment['title'] ?? 'Không có tên') . "\n";
$context .= "Mô tả: "       . (!empty($assignment['description']) ? strip_tags($assignment['description']) : 'Không có mô tả') . "\n";

$moduleSettings = json_decode($assignment['module_settings'] ?? '[]', true);
if (is_array($moduleSettings) && count($moduleSettings) > 0) {
    $moduleNames = array_filter(array_column($moduleSettings, 'module'));
    if ($moduleNames) {
        $context .= "Các phần bài tập: " . implode(', ', $moduleNames) . "\n";
    }
}

// ---- Sanitize history (keep last 6 turns, text only) ----
$safeHistory = [];
foreach (array_slice($history, -6) as $turn) {
    $role    = in_array($turn['role'] ?? '', ['user', 'assistant'], true) ? $turn['role'] : null;
    $content = mb_substr(trim((string) ($turn['content'] ?? '')), 0, 500);
    if ($role && $content !== '') {
        $safeHistory[] = ['role' => $role, 'content' => $content];
    }
}

// ---- Forward to AI service ----
$aiServiceUrl  = rtrim(getenv('AI_SERVICE_URL') ?: 'http://127.0.0.1:8000', '/') . '/chat';
$aiServiceKey  = getenv('AI_SERVICE_KEY') ?: '';

$payload = json_encode([
    'message'            => $message,
    'assignment_context' => $context,
    'history'            => $safeHistory,
], JSON_UNESCAPED_UNICODE);

$ch = curl_init($aiServiceUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 90,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $aiServiceKey,
    ],
    CURLOPT_POSTFIELDS     => $payload,
]);

$rawResponse = curl_exec($ch);
$curlError   = curl_error($ch);
$httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlError) {
    http_response_code(503);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Không thể kết nối đến dịch vụ AI. Hãy đảm bảo AI service đang chạy (start_ai.bat).'
    ]);
    exit;
}

$aiResponse = json_decode($rawResponse, true);
if (!is_array($aiResponse)) {
    http_response_code(502);
    echo json_encode(['status' => 'error', 'message' => 'AI service trả về dữ liệu không hợp lệ.']);
    exit;
}

if (($aiResponse['status'] ?? '') !== 'success') {
    $errMsg = $aiResponse['message'] ?? 'Lỗi không xác định từ AI.';
    http_response_code(max(400, min(599, $httpCode ?: 500)));
    echo json_encode(['status' => 'error', 'message' => $errMsg]);
    exit;
}

echo json_encode([
    'status' => 'success',
    'reply'  => $aiResponse['reply'] ?? '',
]);
