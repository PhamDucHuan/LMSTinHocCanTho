<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$tempDirectory = realpath(__DIR__ . '/../uploads/temp_ai');
$uploadsDirectory = realpath(__DIR__ . '/../uploads');
$expectedDirectory = $uploadsDirectory
    ? $uploadsDirectory . DIRECTORY_SEPARATOR . 'temp_ai'
    : null;
$deletedFiles = 0;
if ($tempDirectory && $expectedDirectory && $tempDirectory === $expectedDirectory) {
    $cutoff = time() - 86400;
    foreach (new DirectoryIterator($tempDirectory) as $file) {
        if (!$file->isFile() || $file->isDot() || $file->getFilename() === '.gitkeep') continue;
        if ($file->getMTime() < $cutoff && @unlink($file->getPathname())) $deletedFiles++;
    }
}

$deletedTokens = $pdo->exec('DELETE FROM user_remember_tokens WHERE expires_at <= NOW()');
$deletedNotifications = $pdo->exec(
    'DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL 180 DAY)'
);
$deletedJobs = $pdo->exec(
    "DELETE FROM grading_jobs
     WHERE status IN ('completed','failed','cancelled')
       AND created_at < DATE_SUB(NOW(), INTERVAL 180 DAY)"
);
$pdo->exec(
    "UPDATE grading_jobs
     SET status='queued', worker_token=NULL, locked_at=NULL,
         available_at=NOW(), error_message='Worker bị gián đoạn; hệ thống tự khôi phục.'
     WHERE status='processing' AND locked_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
);

echo json_encode([
    'deleted_temp_files' => $deletedFiles,
    'deleted_expired_tokens' => $deletedTokens,
    'deleted_old_notifications' => $deletedNotifications,
    'deleted_old_jobs' => $deletedJobs,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
