<?php
declare(strict_types=1);
require_once __DIR__ . '/security.php';
secureSessionStart();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false], JSON_UNESCAPED_UNICODE);
    exit;
}
echo json_encode(['success' => true, 'status_bit' => 1], JSON_UNESCAPED_UNICODE);
