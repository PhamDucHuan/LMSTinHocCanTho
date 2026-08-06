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
        PDO::ATTR_TIMEOUT            => max(1, min(10, (int) envValue('DB_CONNECT_TIMEOUT', '3'))),
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ];
    $connection = new PDO($dsn, $user, $pass, $options);
    $connection->exec("SET time_zone = '+07:00'");
    return $connection;
}

function createDatabaseConnectionWithRetry(int $attempts = 3): PDO
{
    $attempts = max(1, min(5, $attempts));
    $lastError = null;
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        try {
            return createDatabaseConnection();
        } catch (PDOException $error) {
            $lastError = $error;
            if ($attempt < $attempts) {
                // Hàng chờ ngắn phía máy chủ: 250ms, 750ms, 1.5s, 2.5s.
                $delays = [250000, 750000, 1500000, 2500000];
                usleep($delays[$attempt - 1] ?? 2500000);
            }
        }
    }
    throw $lastError ?? new PDOException('Database connection unavailable');
}

function respondDatabaseUnavailable(): never
{
    if (PHP_SAPI === 'cli') {
        throw new RuntimeException('Không thể kết nối cơ sở dữ liệu.');
    }

    http_response_code(503);
    header('Retry-After: 5');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $isProbe = ($_SERVER['HTTP_X_LMS_CONNECTION_PROBE'] ?? '') === '1';
    $acceptsJson = str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
    $isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    if ($isProbe || $acceptsJson || $isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'status' => 'waiting', 'retry_after' => 5], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Đang chờ kết nối | LMS</title>
    <style>
        :root{color-scheme:dark;--bg:#080b1e;--panel:#141a35;--line:rgba(148,163,184,.18);--text:#f8fafc;--muted:#a5b4cf;--primary:#6366f1;--accent:#22d3ee;--danger:#fb7185}
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;font-family:system-ui,-apple-system,"Segoe UI",Arial,sans-serif;color:var(--text);background:radial-gradient(circle at 18% 18%,rgba(99,102,241,.25),transparent 32%),radial-gradient(circle at 85% 80%,rgba(34,211,238,.12),transparent 30%),var(--bg);overflow:hidden}
        body:before{content:"";position:fixed;inset:0;pointer-events:none;background-image:radial-gradient(circle,rgba(255,255,255,.7) 1px,transparent 1.5px);background-size:95px 95px;opacity:.12}
        .card{position:relative;width:min(620px,100%);padding:42px;border:1px solid var(--line);border-radius:26px;background:linear-gradient(145deg,rgba(25,32,66,.96),rgba(12,17,39,.96));box-shadow:0 30px 90px rgba(0,0,0,.45);text-align:center;overflow:hidden}
        .card:before{content:"";position:absolute;width:260px;height:260px;border-radius:50%;background:rgba(99,102,241,.16);right:-120px;top:-140px}
        .database{position:relative;width:92px;height:92px;margin:0 auto 22px;display:grid;place-items:center;border-radius:25px;color:var(--accent);background:rgba(34,211,238,.08);border:1px solid rgba(34,211,238,.24)}
        .database svg{width:50px;height:50px}.pulse{position:absolute;inset:-8px;border:1px solid rgba(34,211,238,.35);border-radius:30px;animation:pulse 2s ease-out infinite}
        @keyframes pulse{to{transform:scale(1.28);opacity:0}}
        h1{position:relative;margin:0 0 12px;font-size:clamp(27px,5vw,38px);letter-spacing:-.03em}p{margin:0 auto;color:var(--muted);font-size:16px;line-height:1.65;max-width:480px}
        .queue{margin:26px 0 20px;padding:18px;border:1px solid var(--line);border-radius:16px;background:rgba(8,11,30,.48);text-align:left}.queue-head,.queue-row{display:flex;align-items:center;justify-content:space-between;gap:16px}.queue-head{margin-bottom:14px;font-weight:700}.queue-row{font-size:14px;color:var(--muted)}
        .status{display:inline-flex;align-items:center;gap:8px;color:#fbbf24}.dot{width:9px;height:9px;border-radius:50%;background:#fbbf24;box-shadow:0 0 16px #fbbf24;animation:blink 1.1s infinite}@keyframes blink{50%{opacity:.35}}
        .progress{height:7px;margin:15px 0 13px;border-radius:99px;background:rgba(148,163,184,.13);overflow:hidden}.progress span{display:block;height:100%;width:35%;border-radius:inherit;background:linear-gradient(90deg,var(--primary),var(--accent));animation:queue 1.5s ease-in-out infinite}@keyframes queue{from{transform:translateX(-120%)}to{transform:translateX(360%)}}
        .actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}.button{appearance:none;border:0;border-radius:12px;padding:13px 20px;font:700 15px inherit;cursor:pointer;color:white;background:linear-gradient(135deg,var(--primary),#818cf8);box-shadow:0 10px 25px rgba(99,102,241,.25)}.button.secondary{background:transparent;border:1px solid var(--line);box-shadow:none;color:var(--muted)}
        .note{margin-top:18px;font-size:13px;color:#71809d}.attempt{color:var(--text);font-weight:700}@media(max-width:560px){.card{padding:30px 20px;border-radius:20px}.queue{padding:15px}.queue-row{align-items:flex-start;flex-direction:column;gap:5px}}
    </style>
</head>
<body>
<main class="card">
    <div class="database"><span class="pulse"></span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><ellipse cx="12" cy="5" rx="7" ry="3"/><path d="M5 5v6c0 1.7 3.1 3 7 3s7-1.3 7-3V5M5 11v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"/></svg></div>
    <h1>Đang chờ kết nối dữ liệu</h1>
    <p>Máy chủ cơ sở dữ liệu đang bận hoặc tạm thời mất kết nối. Yêu cầu của bạn đã được đưa vào hàng chờ và hệ thống sẽ tự thử lại.</p>
    <section class="queue">
        <div class="queue-head"><span class="status"><span class="dot"></span> Đang chờ kết nối lại</span><span id="countdown">5 giây</span></div>
        <div class="progress"><span></span></div>
        <div class="queue-row"><span>Trang đang chờ</span><span class="attempt" id="request-path"></span></div>
        <div class="queue-row" style="margin-top:7px"><span>Số lần thử lại</span><span class="attempt" id="attempt">1</span></div>
    </section>
    <div class="actions"><button class="button" id="retry" type="button">Thử kết nối ngay</button><button class="button secondary" type="button" onclick="history.back()">Quay lại</button></div>
    <div class="note">Trang sẽ tự khôi phục ngay khi kết nối hoạt động trở lại.</div>
</main>
<script>
(() => {
    const path = location.pathname + location.search;
    const key = 'lms-db-retry:' + location.pathname;
    let attempt = Number(sessionStorage.getItem(key) || 0) + 1;
    let delay = Math.min(30, 5 + Math.max(0, attempt - 1) * 3);
    let remaining = delay;
    let timer;
    const countdown = document.getElementById('countdown');
    document.getElementById('attempt').textContent = attempt;
    document.getElementById('request-path').textContent = path;
    sessionStorage.setItem(key, String(attempt));

    const connect = async () => {
        clearInterval(timer);
        countdown.textContent = 'Đang kiểm tra…';
        try {
            const response = await fetch(location.href, { cache: 'no-store', headers: { 'X-LMS-Connection-Probe': '1' } });
            if (response.ok) {
                sessionStorage.removeItem(key);
                location.reload();
                return;
            }
        } catch (_) {}
        attempt += 1;
        sessionStorage.setItem(key, String(attempt));
        document.getElementById('attempt').textContent = attempt;
        delay = Math.min(30, 5 + (attempt - 1) * 3);
        start(delay);
    };
    const start = seconds => {
        remaining = seconds;
        countdown.textContent = remaining + ' giây';
        timer = setInterval(() => {
            remaining -= 1;
            countdown.textContent = Math.max(0, remaining) + ' giây';
            if (remaining <= 0) connect();
        }, 1000);
    };
    document.getElementById('retry').addEventListener('click', connect);
    start(delay);
})();
</script>
</body>
</html>
HTML;
    exit;
}

try {
    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) {
        $GLOBALS['pdo'] = createDatabaseConnectionWithRetry((int) envValue('DB_CONNECT_RETRIES', '3'));
    }
    $pdo = $GLOBALS['pdo'];
} catch (\PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    respondDatabaseUnavailable();
}
?>
