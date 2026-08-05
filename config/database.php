<?php
require_once __DIR__ . '/env.php';

function createDatabaseConnection(): PDO
{
    $host = envValue('DB_HOST', '127.0.0.1');
    $db = envValue('DB_NAME', 'lms_db');
    $user = envValue('DB_USER', 'lms_user');
    $pass = envValue('DB_PASS', '');
    $port = (int) envValue('DB_PORT', '3306');
    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ];
    $connection = new PDO($dsn, $user, $pass, $options);
    $connection->exec("SET time_zone = '+07:00'");
    return $connection;
}

try {
    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) {
        $GLOBALS['pdo'] = createDatabaseConnection();
    }
    $pdo = $GLOBALS['pdo'];
} catch (\PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    throw new \RuntimeException('Không thể kết nối cơ sở dữ liệu.');
}
?>
