<?php
declare(strict_types=1);

/** Record every login outcome without storing passwords or OAuth tokens. */
function recordLoginHistory(PDO $pdo, ?int $userId, string $action, string $method, ?string $email = null, array $extra = []): void
{
    try {
        $context = array_filter([
            'email' => $email !== '' ? mb_substr((string) $email, 0, 255) : null,
            'method' => $method,
            ...$extra,
        ], static fn($value): bool => $value !== null && $value !== '');
        $statement = $pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, context_json, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $userId,
            mb_substr($action, 0, 100),
            'user',
            json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
    } catch (Throwable $error) {
        error_log('Login history write failed: ' . $error->getMessage());
    }
}
