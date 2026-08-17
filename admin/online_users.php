<?php
declare(strict_types=1);

require_once '../includes/security.php';
secureSessionStart();
requireRole(['admin']);

function onlineUserInitial(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '?';
    }

    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        return mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
    }

    return strtoupper(substr($name, 0, 1));
}

require_once __DIR__ . '/../config/database.php';
$onlineUsers = $pdo->query(
    "SELECT id, name, email, role, last_seen_at
     FROM users
     WHERE online_status = 1 AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 3 MINUTE)
     ORDER BY last_seen_at DESC, id DESC
     LIMIT 100"
)->fetchAll(PDO::FETCH_ASSOC);
$page_title = 'Người đang hoạt động';
require_once '../includes/header.php';
?>
<style>
.online-page{max-width:1180px;margin:0 auto}.online-summary{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:20px 22px;margin-bottom:20px;border:1px solid rgba(16,185,129,.36);border-radius:18px;background:rgba(16,185,129,.08)}.online-summary h2{margin:0;font-size:22px}.online-summary p{margin:6px 0 0;color:var(--text-muted)}.online-total{display:grid;place-items:center;min-width:92px;height:76px;border-radius:14px;background:rgba(16,185,129,.14);color:#55e4ad;font-size:29px;font-weight:800}.online-card{overflow:hidden;border:1px solid var(--border-color);border-radius:18px;background:var(--glass-bg)}.online-table{width:100%;border-collapse:collapse}.online-table th,.online-table td{padding:14px 16px;border-bottom:1px solid var(--border-color);text-align:left}.online-table th{color:var(--text-muted);font-size:12px;text-transform:uppercase;letter-spacing:.05em}.online-table tr:last-child td{border-bottom:0}.online-user{display:flex;align-items:center;gap:11px}.online-avatar{display:grid;place-items:center;flex:0 0 36px;width:36px;height:36px;border-radius:50%;background:rgba(99,102,241,.18);color:#b9c3ff;font-weight:800}.online-dot{display:inline-flex;align-items:center;gap:7px;color:#55e4ad;font-weight:700;font-size:13px}.online-dot::before{content:'';width:8px;height:8px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.14)}.online-empty{padding:48px 20px;text-align:center;color:var(--text-muted)}@media(max-width:650px){.online-summary{align-items:flex-start}.online-table th:nth-child(3),.online-table td:nth-child(3){display:none}.online-table th,.online-table td{padding:12px 10px}}
</style>
<div class="online-page">
 <div class="page-header"><h1><i class='bx bx-radio-circle-marked'></i> Người đang hoạt động</h1><p>Theo dõi tài khoản có hoạt động trong 3 phút gần nhất.</p></div>
 <section class="online-summary"><div><h2><i class='bx bx-wifi'></i> Đang trực tuyến</h2><p id="online-updated">Cập nhật tự động mỗi 30 giây.</p></div><div class="online-total" id="online-total"><?php echo count($onlineUsers); ?></div></section>
 <section class="online-card"><table class="online-table"><thead><tr><th>Người dùng</th><th>Vai trò</th><th>Email</th><th>Trạng thái</th><th>Hoạt động gần nhất</th></tr></thead><tbody id="online-list">
 <?php foreach ($onlineUsers as $user): $initial = onlineUserInitial((string) $user['name']); ?>
 <tr><td><div class="online-user"><span class="online-avatar"><?php echo htmlspecialchars($initial); ?></span><strong><?php echo htmlspecialchars((string) $user['name']); ?></strong></div></td><td><?php echo htmlspecialchars(['admin'=>'Quản trị viên','teacher'=>'Giáo viên','administrative_staff'=>'Nhân viên hành chính','student'=>'Học viên'][$user['role']] ?? (string) $user['role']); ?></td><td><?php echo htmlspecialchars((string) $user['email']); ?></td><td><span class="online-dot">Online</span></td><td><?php echo htmlspecialchars(date('d/m/Y H:i:s', strtotime((string) $user['last_seen_at']))); ?></td></tr>
 <?php endforeach; ?>
 <?php if (!$onlineUsers): ?><tr><td colspan="5" class="online-empty">Chưa có người dùng nào đang hoạt động.</td></tr><?php endif; ?>
 </tbody></table></section>
</div>
<script>
(() => { const list=document.getElementById('online-list'),total=document.getElementById('online-total'),updated=document.getElementById('online-updated'); const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c])); const roles={admin:'Quản trị viên',teacher:'Giáo viên',administrative_staff:'Nhân viên hành chính',student:'Học viên'}; const render=users=>{list.innerHTML=users.length?users.map(u=>`<tr><td><div class="online-user"><span class="online-avatar">${esc((u.name||'?').trim().charAt(0).toUpperCase())}</span><strong>${esc(u.name)}</strong></div></td><td>${esc(roles[u.role]||u.role)}</td><td>${esc(u.email)}</td><td><span class="online-dot">Online</span></td><td>${esc(u.last_seen_at)}</td></tr>`).join(''):'<tr><td colspan="5" class="online-empty">Chưa có người dùng nào đang hoạt động.</td></tr>';}; const refresh=async()=>{if(document.visibilityState!=='visible')return;try{const res=await fetch('online_presence.php',{cache:'no-store'}),data=await res.json();if(!res.ok||!data.success)return;total.textContent=data.online_users||0;render(data.users||[]);updated.textContent=`Đã cập nhật · ${new Date().toLocaleTimeString('vi-VN',{hour:'2-digit',minute:'2-digit',second:'2-digit'})}`;}catch(_){updated.textContent='Không thể cập nhật ngay lúc này.';}};setInterval(refresh,30000);document.addEventListener('visibilitychange',refresh);})();
</script>
<?php require_once '../includes/footer.php'; ?>
