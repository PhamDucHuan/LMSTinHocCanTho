<?php
declare(strict_types=1);

/**
 * Get a system setting value with optional default.
 * Uses a request-scoped static cache to avoid repeated DB queries.
 */
function getSetting(PDO $pdo, string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $rows = $pdo->query("SELECT `key`, `value` FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
            $cache = is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            error_log('getSetting failed: ' . $e->getMessage());
        }
    }
    return (string) ($cache[$key] ?? $default);
}

/**
 * Update a system setting value.
 */
function updateSetting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare("UPDATE system_settings SET `value` = ? WHERE `key` = ?")->execute([$value, $key]);
}

/**
 * Get all settings grouped by group_name.
 */
function getAllSettings(PDO $pdo): array
{
    try {
        $rows = $pdo->query("SELECT `key`,`value`,`label`,`type`,`group_name` FROM system_settings ORDER BY `group_name`,`key`")->fetchAll();
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['group_name']][] = $row;
        }
        return $grouped;
    } catch (Throwable $e) {
        error_log('getAllSettings failed: ' . $e->getMessage());
        return [];
    }
}
