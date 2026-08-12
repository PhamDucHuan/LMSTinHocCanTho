<?php
declare(strict_types=1);
require_once '../includes/security.php';
secureSessionStart();
requireRole(['admin']);
require_once '../config/database.php';
global $pdo;

$status = trim((string)($_GET['status'] ?? ''));
$from   = trim((string)($_GET['from'] ?? ''));
$to     = trim((string)($_GET['to'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));

$conditions = ["al.action LIKE 'login%'"];
$params = [];
if ($status === 'success') { $conditions[] = "al.action NOT LIKE '%failed%'"; }
elseif ($status === 'failed') { $conditions[] = "al.action LIKE '%failed%'"; }
if ($from !== '') { $conditions[] = 'al.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to   !== '') { $conditions[] = 'al.created_at <= ?'; $params[] = $to   . ' 23:59:59'; }
if ($search !== '') {
    $conditions[] = '(u.email LIKE ? OR u.name LIKE ? OR al.ip_address LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}

$where = implode(' AND ', $conditions);
$perPage = 100;
$cnt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id WHERE $where");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page   = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT al.id, al.user_id, al.action, al.context_json, al.ip_address, al.user_agent, al.created_at, u.name AS user_name, u.email FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id WHERE $where ORDER BY al.id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$pageUrl = static fn(int $p): string => '?' . http_build_query(array_merge($_GET, ['page' => $p]));
$page_title = 'Nhật ký đăng nhập';
require_once '../includes/header.php';
?>
<style>
.login-filter { display:grid; grid-template-columns:2fr 1fr 1fr 1fr auto; gap:12px; margin-bottom:18px; align-items:stretch; }
.login-filter input,
.login-filter select {
    width:100%; min-width:0; height:50px; padding:0 14px;
    border:1px solid rgba(148,163,184,.24); border-radius:10px;
    background:rgba(15,23,42,.76); color:var(--text-main); font:inherit;
    outline:none; color-scheme:dark; transition:border-color .2s;
}
.login-filter input:focus, .login-filter select:focus { border-color:var(--primary); }
.login-filter .btn { height:50px; padding:0 22px; white-space:nowrap; border-radius:10px; }
.login-table { width:100%; border-collapse:collapse; min-width:900px; }
.login-table th, .login-table td { padding:11px 12px; border-bottom:1px solid var(--border-color); text-align:left; vertical-align:top; font-size:13px; }
.login-table th { color:var(--text-muted); font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
.badge-success { padding:3px 9px; border-radius:20px; background:rgba(16,185,129,.15); color:#6ee7b7; font-size:11px; font-weight:700; }
.badge-fail    { padding:3px 9px; border-radius:20px; background:rgba(239,68,68,.15);  color:#fca5a5; font-size:11px; font-weight:700; }
.ua-text { max-width:300px; font-size:11px; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.log-pages { display:flex; align-items:center; justify-content:center; gap:8px; flex-wrap:wrap; margin-top:18px; }
.is-disabled { pointer-events:none; opacity:.42; }
@media(max-width:1000px) { .login-filter { grid-template-columns:1fr 1fr; } .login-filter .btn { width:100%; } }
@media(max-width:560px)  { .login-filter { grid-template-columns:1fr; } }
</style>

<h1><i class='bx bx-log-in-circle'></i> Nhật ký đăng nhập</h1>

<div class="box">
    <form class="login-filter" method="get">
        <input name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Tìm email, tên, địa chỉ IP...">
        <select name="status">
            <option value="">Tất cả trạng thái</option>
            <option value="success" <?php echo $status === 'success' ? 'selected' : ''; ?>>Thành công</option>
            <option value="failed"  <?php echo $status === 'failed'  ? 'selected' : ''; ?>>Thất bại</option>
        </select>
        <input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>">
        <input type="date" name="to"   value="<?php echo htmlspecialchars($to); ?>">
        <button class="btn btn-primary">Lọc</button>
    </form>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;color:var(--text-muted);font-size:13px">
        <span><?php echo number_format($total); ?> bản ghi &middot; Trang <?php echo $page; ?>/<?php echo $totalPages; ?></span>
    </div>

    <div style="overflow:auto">
        <table class="login-table">
            <thead><tr>
                <th>Thời gian</th>
                <th>Người dùng</th>
                <th>Loại đăng nhập</th>
                <th>Trạng thái</th>
                <th>IP</th>
                <th>Thiết bị / Trình duyệt</th>
            </tr></thead>
            <tbody>
            <?php foreach ($logs as $log):
                $isFailed = str_contains((string)$log['action'], 'failed');
                $context = json_decode((string)($log['context_json'] ?? ''), true) ?: [];
                $logEmail = (string)($log['email'] ?? $context['email'] ?? '');
                $type = match(true) {
                    str_contains((string)$log['action'], 'google')   => '🔵 Google',
                    str_contains((string)$log['action'], 'remember') => '🔁 Remember me',
                    default                                           => '📧 Email/Mật khẩu',
                };
                $ua = (string)($log['user_agent'] ?? '');
                $device = '—';
                if ($ua) {
                    if      (preg_match('/Mobile|Android|iPhone/i', $ua)) $device = '📱 Di động';
                    elseif  (preg_match('/Windows/i', $ua))               $device = '🖥️ Windows';
                    elseif  (preg_match('/Mac/i', $ua))                   $device = '🍎 Mac';
                    elseif  (preg_match('/Linux/i', $ua))                 $device = '🐧 Linux';
                    else                                                   $device = '💻 Khác';
                    if      (preg_match('/Edg\//i', $ua))                 $device .= ' · Edge';
                    elseif  (preg_match('/Chrome\//i', $ua))              $device .= ' · Chrome';
                    elseif  (preg_match('/Firefox\//i', $ua))             $device .= ' · Firefox';
                    elseif  (preg_match('/Safari\//i', $ua))              $device .= ' · Safari';
                }
            ?>
            <tr>
                <td><?php echo date('d/m/Y H:i:s', strtotime($log['created_at'])); ?></td>
                <td>
                    <strong><?php echo htmlspecialchars($log['user_name'] ?? ($logEmail !== '' ? 'Tài khoản chưa xác định' : '—')); ?></strong>
                    <small style="display:block;color:var(--text-muted)"><?php echo htmlspecialchars($logEmail); ?></small>
                </td>
                <td><?php echo htmlspecialchars($type); ?></td>
                <td>
                    <?php if ($isFailed): ?>
                        <span class="badge-fail">Thất bại</span>
                    <?php else: ?>
                        <span class="badge-success">Thành công</span>
                    <?php endif; ?>
                </td>
                <td style="font-family:monospace;font-size:12px"><?php echo htmlspecialchars($log['ip_address'] ?? '—'); ?></td>
                <td>
                    <div class="ua-text" title="<?php echo htmlspecialchars($ua); ?>">
                        <?php echo htmlspecialchars($device); ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$logs): ?>
                <tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:24px">Không có dữ liệu phù hợp.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <nav class="log-pages">
        <a class="btn btn-outline <?php echo $page <= 1 ? 'is-disabled' : ''; ?>"
           href="<?php echo htmlspecialchars($pageUrl(max(1, $page - 1))); ?>">
            <i class='bx bx-chevron-left'></i>
        </a>
        <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
        <a class="btn <?php echo $p === $page ? 'btn-primary' : 'btn-outline'; ?>"
           href="<?php echo htmlspecialchars($pageUrl($p)); ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
        <a class="btn btn-outline <?php echo $page >= $totalPages ? 'is-disabled' : ''; ?>"
           href="<?php echo htmlspecialchars($pageUrl(min($totalPages, $page + 1))); ?>">
            <i class='bx bx-chevron-right'></i>
        </a>
    </nav>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
