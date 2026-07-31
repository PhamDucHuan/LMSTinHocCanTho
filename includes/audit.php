<?php
declare(strict_types=1);

function writeAuditLog(
    PDO $pdo,
    string $action,
    ?string $entityType = null,
    int|string|null $entityId = null,
    array $context = []
): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, context_json, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            mb_substr($action, 0, 100),
            $entityType !== null ? mb_substr($entityType, 0, 80) : null,
            $entityId !== null ? mb_substr((string) $entityId, 0, 100) : null,
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);
    } catch (Throwable $error) {
        error_log('Audit log failed: ' . $error->getMessage());
    }
}
