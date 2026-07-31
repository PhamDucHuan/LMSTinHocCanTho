<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(191) PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$applied = array_fill_keys(
    $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN),
    true
);
$files = glob(__DIR__ . '/migrations/*.php') ?: [];
sort($files, SORT_NATURAL);

foreach ($files as $file) {
    $version = basename($file, '.php');
    if (isset($applied[$version])) {
        echo "[skip] {$version}\n";
        continue;
    }
    $migration = require $file;
    if (!is_callable($migration)) {
        throw new RuntimeException("Migration không hợp lệ: {$version}");
    }
    try {
        $migration($pdo);
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
        $stmt->execute([$version]);
        echo "[done] {$version}\n";
    } catch (Throwable $error) {
        throw $error;
    }
}

echo "Migration hoàn tất.\n";
