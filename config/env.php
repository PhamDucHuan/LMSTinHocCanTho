<?php

function loadEnvironmentFile(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if ($name === '') {
            continue;
        }

        if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }
        // File .env của chính dự án là nguồn cấu hình nhất quán cho cả Apache,
        // CLI và tác vụ nền. Ghi đè biến cũ mà tiến trình XAMPP có thể đã giữ.
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

function envValue(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    return $value === false ? $default : $value;
}

function appUrl(string $path = ''): string
{
    $base = rtrim((string) envValue('APP_URL', 'http://localhost/LMS'), '/');
    return $base . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

loadEnvironmentFile(dirname(__DIR__) . '/.env');

$applicationTimezone = envValue('APP_TIMEZONE', 'Asia/Ho_Chi_Minh');
if (!in_array($applicationTimezone, timezone_identifiers_list(), true)) {
    $applicationTimezone = 'Asia/Ho_Chi_Minh';
}
date_default_timezone_set($applicationTimezone);
