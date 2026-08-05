<?php
declare(strict_types=1);
require_once __DIR__.'/backup_manager.php';

function systemHealthItem(string $name, string $status, string $message, string $detail = ''): array
{
    return compact('name', 'status', 'message', 'detail');
}

function systemHealthValueIsConfigured(string $name): bool
{
    $value = trim((string) envValue($name, ''));
    return $value !== '' && !in_array(strtolower($value), ['replace-me', 'changeme', 'your-key-here'], true)
        && !str_starts_with(strtolower($value), 'replace-with-');
}

function systemHealthSummary(array $checks): array
{
    $summary = ['ok' => 0, 'warning' => 0, 'error' => 0];
    foreach ($checks as $check) {
        $status = $check['status'] ?? 'error';
        if (isset($summary[$status])) $summary[$status]++;
    }
    $summary['overall'] = $summary['error'] > 0 ? 'error' : ($summary['warning'] > 0 ? 'warning' : 'ok');
    return $summary;
}

function systemHealthFormatBytes(int|float $bytes): string
{
    $bytes = max(0, (float) $bytes);
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $unit = 0;
    while ($bytes >= 1024 && $unit < count($units) - 1) { $bytes /= 1024; $unit++; }
    return number_format($bytes, $unit === 0 ? 0 : 2, ',', '.') . ' ' . $units[$unit];
}

function systemHealthDirectoryStats(string $path, int $fileLimit = 50000): array
{
    $stats = ['bytes' => 0, 'files' => 0, 'old_files' => 0, 'truncated' => false];
    if (!is_dir($path) || !is_readable($path)) return $stats;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        $oldBefore = time() - 86400;
        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $stats['bytes'] += max(0, (int) $file->getSize());
            $stats['files']++;
            if ($file->getMTime() < $oldBefore) $stats['old_files']++;
            if ($stats['files'] >= $fileLimit) { $stats['truncated'] = true; break; }
        }
    } catch (Throwable $error) { $stats['truncated'] = true; }
    return $stats;
}

function collectSystemHealth(PDO $pdo, string $projectRoot): array
{
    $checks = [];
    $checks[] = version_compare(PHP_VERSION, '8.2.0', '>=')
        ? systemHealthItem('Phiên bản PHP', 'ok', 'PHP ' . PHP_VERSION, 'Yêu cầu tối thiểu: PHP 8.2')
        : systemHealthItem('Phiên bản PHP', 'error', 'PHP ' . PHP_VERSION . ' chưa được hỗ trợ', 'Hãy nâng cấp lên PHP 8.2 trở lên.');

    $extensions = ['pdo_mysql', 'mbstring', 'curl', 'json', 'openssl', 'fileinfo'];
    $missing = array_values(array_filter($extensions, fn(string $extension): bool => !extension_loaded($extension)));
    $checks[] = !$missing
        ? systemHealthItem('PHP extensions', 'ok', 'Đã có đủ extension bắt buộc', implode(', ', $extensions))
        : systemHealthItem('PHP extensions', 'error', 'Thiếu: ' . implode(', ', $missing), 'Bật extension trong cấu hình PHP của hosting.');

    try {
        $pdo->query('SELECT 1')->fetchColumn();
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $checks[] = systemHealthItem('Cơ sở dữ liệu', 'ok', 'Kết nối thành công', 'Máy chủ: ' . $version);
    } catch (Throwable $error) {
        $checks[] = systemHealthItem('Cơ sở dữ liệu', 'error', 'Không thể thực hiện truy vấn kiểm tra', 'Xem error log máy chủ để biết chi tiết.');
    }

    $tables = ['users', 'courses', 'course_enrollments', 'assignments', 'submissions', 'quizzes', 'grading_jobs'];
    $missingTables = [];
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
            $stmt->execute([$table]);
            if (!$stmt->fetchColumn()) $missingTables[] = $table;
        } catch (Throwable $error) { $missingTables[] = $table; }
    }
    $checks[] = !$missingTables
        ? systemHealthItem('Cấu trúc dữ liệu', 'ok', 'Đã có đủ bảng dữ liệu chính')
        : systemHealthItem('Cấu trúc dữ liệu', 'error', 'Thiếu bảng: ' . implode(', ', $missingTables), 'Chạy các migration còn thiếu.');

    $envPath = $projectRoot . DIRECTORY_SEPARATOR . '.env';
    $checks[] = is_file($envPath) && is_readable($envPath)
        ? systemHealthItem('Tệp môi trường', 'ok', '.env tồn tại và có thể đọc')
        : systemHealthItem('Tệp môi trường', 'error', 'Không tìm thấy hoặc không đọc được .env', 'Tạo .env tại thư mục gốc dự án.');

    try {
        $databaseBackups=listBackups();$latestBackup=$databaseBackups[0]['created_at']??null;
        $backupStatus=$latestBackup&&$latestBackup>=time()-172800?'ok':'warning';
        $backupMessage=$latestBackup?'Bản gần nhất: '.date('d/m/Y H:i',$latestBackup):'Chưa có bản sao lưu database';
        $checks[]=systemHealthItem('Sao lưu dữ liệu',$backupStatus,$backupMessage,'Hệ thống giữ tối đa 5 bản và cảnh báo khi quá 2 ngày chưa sao lưu.');
    } catch(Throwable $error) {
        $checks[]=systemHealthItem('Sao lưu dữ liệu','error','Không thể truy cập thư mục sao lưu',$error->getMessage());
    }

    $environmentGroups = [
        ['Cấu hình ứng dụng', 'error', ['APP_URL', 'APP_KEY', 'DB_HOST', 'DB_NAME', 'DB_USER'], 'Ứng dụng có thể không hoạt động ổn định.'],
        ['Cấu hình chấm AI', 'warning', ['AI_SERVICE_URL', 'AI_SERVICE_KEY', 'GEMINI_API_KEY'], 'Bài chấm AI có thể không hoạt động.'],
        ['Google OAuth', 'warning', ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_REDIRECT_URI'], 'Đăng nhập Google và Google Drive có thể không hoạt động.'],
    ];
    foreach ($environmentGroups as [$name, $failureStatus, $variables, $detail]) {
        $unset = array_values(array_filter($variables, fn(string $variable): bool => !systemHealthValueIsConfigured($variable)));
        $checks[] = !$unset
            ? systemHealthItem($name, 'ok', 'Đã khai báo đầy đủ cấu hình')
            : systemHealthItem($name, $failureStatus, 'Thiếu hoặc chưa thay giá trị: ' . implode(', ', $unset), $detail);
    }

    $autoload = $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (is_file($autoload)) require_once $autoload;
    $composerReady = is_file($autoload) && class_exists('Google_Client') && class_exists('Google_Service_Drive');
    $checks[] = $composerReady
        ? systemHealthItem('Thư viện Composer', 'ok', 'Autoloader và Google API Client hoạt động')
        : systemHealthItem('Thư viện Composer', 'error', 'Thiếu hoặc lỗi thư mục vendor', 'Chạy composer install --no-dev --optimize-autoloader bằng PHP 8.2.');

    foreach (['uploads', 'uploads/temp_ai'] as $relativePath) {
        $path = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $ready = is_dir($path) && is_writable($path);
        $checks[] = $ready
            ? systemHealthItem('Thư mục ' . $relativePath, 'ok', 'Tồn tại và có quyền ghi')
            : systemHealthItem('Thư mục ' . $relativePath, 'error', 'Không tồn tại hoặc không có quyền ghi', 'Tạo thư mục và cấp quyền ghi cho tài khoản chạy PHP.');
    }

    $totalBytes = @disk_total_space($projectRoot);
    $freeBytes = @disk_free_space($projectRoot);
    $usedPercent = $totalBytes && $freeBytes !== false ? round((($totalBytes - $freeBytes) / $totalBytes) * 100, 1) : null;
    $warningPercent = max(1, min(99, (int) envValue('STORAGE_WARNING_PERCENT', '80')));
    $criticalPercent = max($warningPercent + 1, min(100, (int) envValue('STORAGE_CRITICAL_PERCENT', '90')));
    $storageStatus = $usedPercent === null ? 'warning' : ($usedPercent >= $criticalPercent ? 'error' : ($usedPercent >= $warningPercent ? 'warning' : 'ok'));
    $storageMessage = $usedPercent === null
        ? 'Không đọc được dung lượng ổ đĩa'
        : systemHealthFormatBytes((float) $freeBytes) . ' còn trống · đã dùng ' . number_format($usedPercent, 1, ',', '.') . '%';
    $checks[] = systemHealthItem('Dung lượng lưu trữ', $storageStatus, $storageMessage, "Cảnh báo từ {$warningPercent}%, nguy cấp từ {$criticalPercent}%.");
    $uploadStats = systemHealthDirectoryStats($projectRoot . DIRECTORY_SEPARATOR . 'uploads');
    $tempStats = systemHealthDirectoryStats($projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'temp_ai');

    $queue = ['queued' => 0, 'processing' => 0, 'failed' => 0, 'stale' => 0];
    $ai = ['completed_24h' => 0, 'failed_24h' => 0, 'avg_seconds_24h' => 0, 'oldest_queued_minutes' => 0, 'recent_failures' => []];
    try {
        $rows = $pdo->query("SELECT status, COUNT(*) total FROM grading_jobs WHERE status IN ('queued','processing','failed') GROUP BY status")->fetchAll();
        foreach ($rows as $row) $queue[$row['status']] = (int) $row['total'];
        $timeout = max(60, (int) envValue('AI_GRADE_QUEUE_TIMEOUT_SECONDS', '300'));
        $staleSql = "SELECT COUNT(*) FROM grading_jobs WHERE status='processing' AND started_at < DATE_SUB(NOW(), INTERVAL {$timeout} SECOND)";
        $queue['stale'] = (int) $pdo->query($staleSql)->fetchColumn();
        $status = $queue['stale'] > 0 || $queue['failed'] > 0 ? 'warning' : 'ok';
        $checks[] = systemHealthItem('Hàng đợi chấm AI', $status, "{$queue['queued']} chờ · {$queue['processing']} đang chấm · {$queue['failed']} lỗi", $queue['stale'] > 0 ? "Có {$queue['stale']} tác vụ xử lý quá thời gian." : 'Không có tác vụ bị treo.');

        $metrics = $pdo->query("SELECT
            SUM(status='completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) completed_24h,
            SUM(status='failed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) failed_24h,
            COALESCE(ROUND(AVG(CASE WHEN status='completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN TIMESTAMPDIFF(SECOND, started_at, completed_at) END)),0) avg_seconds_24h,
            COALESCE(MAX(CASE WHEN status='queued' THEN TIMESTAMPDIFF(MINUTE, created_at, NOW()) END),0) oldest_queued_minutes
            FROM grading_jobs")->fetch();
        foreach (['completed_24h', 'failed_24h', 'avg_seconds_24h', 'oldest_queued_minutes'] as $key) $ai[$key] = (int) ($metrics[$key] ?? 0);
        $failureStmt = $pdo->query("SELECT gj.id, gj.module_name, gj.attempts, gj.error_message, gj.completed_at, a.title assignment_title, u.name student_name
            FROM grading_jobs gj
            LEFT JOIN assignments a ON a.id=gj.assignment_id
            LEFT JOIN users u ON u.id=gj.student_id
            WHERE gj.status='failed'
            ORDER BY gj.completed_at DESC, gj.id DESC LIMIT 10");
        $ai['recent_failures'] = $failureStmt->fetchAll();
    } catch (Throwable $error) {
        $checks[] = systemHealthItem('Hàng đợi chấm AI', 'error', 'Không đọc được trạng thái hàng đợi');
    }

    return [
        'checks' => $checks,
        'summary' => systemHealthSummary($checks),
        'queue' => $queue,
        'ai' => $ai,
        'storage' => [
            'total_bytes' => (float) ($totalBytes ?: 0), 'free_bytes' => (float) ($freeBytes ?: 0),
            'used_percent' => $usedPercent, 'warning_percent' => $warningPercent,
            'critical_percent' => $criticalPercent, 'uploads' => $uploadStats, 'temp_ai' => $tempStats,
        ],
    ];
}
