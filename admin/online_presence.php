<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
secureSessionStart();
requireRole(['admin']);
require_once __DIR__ . '/../config/database.php';

$stmt = $pdo->query(
    "SELECT COALESCE(SUM(CASE WHEN online_status = 1 AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 3 MINUTE) THEN 1 ELSE 0 END), 0) AS online_users
     FROM users"
);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'success' => true,
    'online_users' => (int) $stmt->fetchColumn(),
    'status_bit' => 1,
    'active_window_minutes' => 3,
], JSON_UNESCAPED_UNICODE);
