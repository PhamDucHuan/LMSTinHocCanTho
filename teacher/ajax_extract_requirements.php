<?php
require_once '../includes/security.php';
secureSessionStart();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['teacher', 'admin'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    if (!isset($_FILES['prompt_file']) || $_FILES['prompt_file']['error'] !== 0) {
        echo json_encode(['status' => 'error', 'message' => 'Không tìm thấy file hoặc lỗi upload']);
        exit;
    }

    try {
        $valid = validateUploadedFile($_FILES['prompt_file'], ['doc', 'docx', 'pdf']);
        $tempDir = __DIR__ . '/../uploads/temp_ai';
        if (!is_dir($tempDir)) mkdir($tempDir, 0700, true);
        $tempPath = $tempDir . '/extract_' . bin2hex(random_bytes(12)) . '.' . $valid['extension'];
        if (!move_uploaded_file($valid['tmp_name'], $tempPath)) throw new RuntimeException('Không thể chuẩn bị file tạm.');

        $ch = curl_init(aiServiceUrl('/analyze_prompt'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => aiServiceHeaders(),
            CURLOPT_POSTFIELDS => json_encode(['prompt_local_path' => $tempPath, 'modules' => ['Windows', 'Word', 'Excel', 'PowerPoint']]),
            CURLOPT_TIMEOUT => 120,
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        @unlink($tempPath);

        $result = $response ? json_decode($response, true) : null;
        if ($httpCode !== 200 || ($result['status'] ?? '') !== 'success') {
            throw new RuntimeException($result['message'] ?? $curlError ?: 'Dịch vụ AI không phản hồi hợp lệ.');
        }
        $parts = [];
        foreach (($result['analysis'] ?? []) as $module => $requirements) {
            if (trim((string) $requirements) !== '') $parts[] = $module . ":\n" . $requirements;
        }
        echo json_encode(['status' => 'success', 'requirements' => implode("\n\n", $parts)], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        if (isset($tempPath) && is_file($tempPath)) @unlink($tempPath);
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
}
