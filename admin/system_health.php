<?php
declare(strict_types=1);
require_once '../includes/security.php';
secureSessionStart();
requireRole(['admin']);
require_once '../config/database.php';
if (!isset($pdo)) {
    throw new RuntimeException('Database connection not initialized.');
}
/** @var \PDO $pdo */
require_once '../includes/system_health.php';

$health = collectSystemHealth($pdo, dirname(__DIR__));
$summary = $health['summary'];
$storage = $health['storage'];
$ai = $health['ai'];
$autoRefresh = ($_GET['auto'] ?? '') === '1';
$labels = ['ok' => 'Hệ thống hoạt động tốt', 'warning' => 'Hệ thống cần chú ý', 'error' => 'Hệ thống có lỗi'];
$page_title = 'Tình trạng hệ thống';
require_once '../includes/header.php';
?>
<?php if ($autoRefresh): ?><meta http-equiv="refresh" content="60;url=system_health.php?auto=1"><?php endif; ?>
<style>
.health-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:22px}.health-head h1{margin:0 0 7px}.health-refresh{white-space:nowrap}.health-overview{display:grid;grid-template-columns:2fr repeat(3,1fr);gap:16px;margin-bottom:22px}.health-card,.health-check{background:var(--glass-bg);border:1px solid var(--border-color);border-radius:16px}.health-card{padding:20px}.health-card strong{display:block;font-size:28px;margin-top:8px}.health-overall{display:flex;align-items:center;gap:16px}.health-overall-icon{width:54px;height:54px;border-radius:50%;display:grid;place-items:center;font-size:29px;flex:0 0 auto}.health-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.health-check{padding:18px;display:flex;gap:14px;min-width:0}.health-check-icon{width:38px;height:38px;border-radius:12px;display:grid;place-items:center;font-size:21px;flex:0 0 auto}.health-check h3{font-size:17px;margin:0 0 6px}.health-check p{margin:0;color:var(--text-muted);line-height:1.5;overflow-wrap:anywhere}.health-check small{display:block;margin-top:7px;color:var(--text-muted);line-height:1.45}.health-ok .health-check-icon,.health-overall.health-ok .health-overall-icon{background:rgba(16,185,129,.16);color:var(--success)}.health-warning .health-check-icon,.health-overall.health-warning .health-overall-icon{background:rgba(245,158,11,.16);color:var(--warning)}.health-error .health-check-icon,.health-overall.health-error .health-overall-icon{background:rgba(239,68,68,.16);color:var(--danger)}.health-ok{border-color:rgba(16,185,129,.22)}.health-warning{border-color:rgba(245,158,11,.26)}.health-error{border-color:rgba(239,68,68,.28)}.health-time{margin-top:18px;text-align:right;color:var(--text-muted);font-size:13px}@media(max-width:900px){.health-overview{grid-template-columns:repeat(3,1fr)}.health-overall{grid-column:1/-1}.health-list{grid-template-columns:1fr}}@media(max-width:600px){.health-head{flex-direction:column}.health-overview{grid-template-columns:1fr}.health-overall{grid-column:auto}.health-refresh{width:100%}}
</style>
<style>
.monitor-grid{display:grid;grid-template-columns:1fr 1.4fr;gap:18px;margin:0 0 22px}.monitor-panel{background:var(--glass-bg);border:1px solid var(--border-color);border-radius:16px;padding:20px;min-width:0}.monitor-panel h2{font-size:20px;margin:0 0 18px}.storage-bar{height:13px;background:var(--input-bg);border-radius:999px;overflow:hidden;margin:12px 0 8px}.storage-bar span{display:block;height:100%;border-radius:inherit;background:var(--success)}.storage-bar.warning span{background:var(--warning)}.storage-bar.error span{background:var(--danger)}.monitor-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.monitor-stat{background:var(--input-bg);border:1px solid var(--border-color);border-radius:12px;padding:13px}.monitor-stat strong{display:block;font-size:22px;margin-bottom:4px}.monitor-stat small{color:var(--text-muted)}.storage-detail{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px}.failure-panel{margin-bottom:22px}.health-table-wrap{overflow:auto}.health-table{width:100%;border-collapse:collapse;min-width:760px}.health-table th,.health-table td{text-align:left;padding:12px;border-bottom:1px solid var(--border-color);vertical-align:top}.health-table th{color:var(--text-muted);font-size:13px}.health-table td small{display:block;color:var(--text-muted);margin-top:4px}.error-text{color:var(--danger);max-width:430px;overflow-wrap:anywhere}@media(max-width:1050px){.monitor-grid{grid-template-columns:1fr}.monitor-stats{grid-template-columns:repeat(2,1fr)}}@media(max-width:600px){.storage-detail,.monitor-stats{grid-template-columns:1fr}}
</style>

<div class="health-head">
    <div><h1><i class='bx bx-pulse'></i> Tình trạng hệ thống</h1><p style="margin:0;color:var(--text-muted)">Kiểm tra nhanh các thành phần cần thiết để LMS vận hành ổn định.</p></div>
    <div style="display:flex;gap:10px;flex-wrap:wrap"><a class="btn btn-outline health-refresh" href="system_health.php?auto=<?php echo $autoRefresh?'0':'1'; ?>"><i class='bx bx-timer'></i> <?php echo $autoRefresh?'Tắt tự làm mới':'Tự làm mới 60 giây'; ?></a><a class="btn btn-primary health-refresh" href="system_health.php<?php echo $autoRefresh?'?auto=1':''; ?>"><i class='bx bx-refresh'></i> Kiểm tra lại</a></div>
</div>
<section class="health-overview">
    <div class="health-card health-overall health-<?php echo $summary['overall']; ?>">
        <span class="health-overall-icon"><i class='bx <?php echo $summary['overall']==='ok'?'bx-check':($summary['overall']==='warning'?'bx-error':'bx-x'); ?>'></i></span>
        <div><span style="color:var(--text-muted)">Trạng thái tổng thể</span><strong><?php echo $labels[$summary['overall']]; ?></strong></div>
    </div>
    <div class="health-card"><span style="color:var(--text-muted)">Hoạt động</span><strong style="color:var(--success)"><?php echo $summary['ok']; ?></strong></div>
    <div class="health-card"><span style="color:var(--text-muted)">Cảnh báo</span><strong style="color:var(--warning)"><?php echo $summary['warning']; ?></strong></div>
    <div class="health-card"><span style="color:var(--text-muted)">Lỗi</span><strong style="color:var(--danger)"><?php echo $summary['error']; ?></strong></div>
</section>
<section class="monitor-grid">
    <div class="monitor-panel">
        <h2><i class='bx bx-hdd'></i> Dung lượng lưu trữ</h2>
        <?php $storageClass = $storage['used_percent'] === null ? 'warning' : ($storage['used_percent'] >= $storage['critical_percent'] ? 'error' : ($storage['used_percent'] >= $storage['warning_percent'] ? 'warning' : 'ok')); ?>
        <div style="display:flex;justify-content:space-between;gap:10px"><strong><?php echo $storage['used_percent'] === null ? 'Không xác định' : number_format($storage['used_percent'],1,',','.') . '% đã dùng'; ?></strong><span><?php echo systemHealthFormatBytes($storage['free_bytes']); ?> trống</span></div>
        <div class="storage-bar <?php echo $storageClass; ?>"><span style="width:<?php echo min(100,(float)($storage['used_percent']??0)); ?>%"></span></div>
        <small style="color:var(--text-muted)">Tổng dung lượng hệ thống nhìn thấy: <?php echo systemHealthFormatBytes($storage['total_bytes']); ?></small>
        <div class="storage-detail">
            <div class="monitor-stat"><strong><?php echo systemHealthFormatBytes($storage['uploads']['bytes']); ?></strong><small><?php echo number_format($storage['uploads']['files']); ?> file trong uploads<?php echo $storage['uploads']['truncated']?' (ước tính)':''; ?></small></div>
            <div class="monitor-stat"><strong><?php echo systemHealthFormatBytes($storage['temp_ai']['bytes']); ?></strong><small><?php echo number_format($storage['temp_ai']['old_files']); ?> file tạm cũ hơn 24 giờ</small></div>
        </div>
    </div>
    <div class="monitor-panel">
        <h2><i class='bx bx-bot'></i> Giám sát chấm AI</h2>
        <div class="monitor-stats">
            <div class="monitor-stat"><strong style="color:var(--warning)"><?php echo $health['queue']['queued']; ?></strong><small>Đang chờ</small></div>
            <div class="monitor-stat"><strong><?php echo $health['queue']['processing']; ?></strong><small>Đang xử lý</small></div>
            <div class="monitor-stat"><strong style="color:var(--success)"><?php echo $ai['completed_24h']; ?></strong><small>Hoàn tất 24 giờ</small></div>
            <div class="monitor-stat"><strong style="color:var(--danger)"><?php echo $ai['failed_24h']; ?></strong><small>Lỗi 24 giờ</small></div>
            <div class="monitor-stat"><strong><?php echo $ai['avg_seconds_24h']; ?>s</strong><small>Thời gian trung bình</small></div>
            <div class="monitor-stat"><strong><?php echo $ai['oldest_queued_minutes']; ?> phút</strong><small>Chờ lâu nhất</small></div>
            <div class="monitor-stat"><strong style="color:<?php echo $health['queue']['stale']?'var(--danger)':'var(--success)'; ?>"><?php echo $health['queue']['stale']; ?></strong><small>Tác vụ bị treo</small></div>
            <div class="monitor-stat"><strong><?php echo (int) envValue('AI_MAX_GRADE_QUEUE_SIZE','50'); ?></strong><small>Sức chứa hàng đợi</small></div>
        </div>
    </div>
</section>

<section class="monitor-panel failure-panel">
    <h2><i class='bx bx-error-circle'></i> Lỗi chấm AI gần nhất</h2>
    <?php if ($ai['recent_failures']): ?><div class="health-table-wrap"><table class="health-table"><thead><tr><th>Mã</th><th>Bài tập / học viên</th><th>Phần bài</th><th>Số lần thử</th><th>Thời gian</th><th>Lỗi</th></tr></thead><tbody>
    <?php foreach ($ai['recent_failures'] as $failure): ?><tr><td>#<?php echo (int)$failure['id']; ?></td><td><strong><?php echo htmlspecialchars((string)($failure['assignment_title']??'Đã xóa')); ?></strong><small><?php echo htmlspecialchars((string)($failure['student_name']??'Không xác định')); ?></small></td><td><?php echo htmlspecialchars((string)$failure['module_name']); ?></td><td><?php echo (int)$failure['attempts']; ?></td><td><?php echo $failure['completed_at']?date('d/m/Y H:i',strtotime($failure['completed_at'])):'—'; ?></td><td class="error-text"><?php echo htmlspecialchars(mb_strimwidth((string)($failure['error_message']??'Không có thông tin'),0,220,'…','UTF-8')); ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php else: ?><p style="margin:0;color:var(--text-muted)"><i class='bx bx-check-circle' style="color:var(--success)"></i> Chưa có tác vụ chấm AI thất bại.</p><?php endif; ?>
</section>

<section class="health-list">
<?php foreach ($health['checks'] as $check): ?>
    <article class="health-check health-<?php echo $check['status']; ?>">
        <span class="health-check-icon"><i class='bx <?php echo $check['status']==='ok'?'bx-check':($check['status']==='warning'?'bx-error':'bx-x'); ?>'></i></span>
        <div><h3><?php echo htmlspecialchars($check['name']); ?></h3><p><?php echo htmlspecialchars($check['message']); ?></p><?php if ($check['detail'] !== ''): ?><small><?php echo htmlspecialchars($check['detail']); ?></small><?php endif; ?></div>
    </article>
<?php endforeach; ?>
</section>
<div class="health-time">Kiểm tra lúc <?php echo date('d/m/Y H:i:s'); ?> · Chỉ Admin nhìn thấy trang này</div>
<?php require_once '../includes/footer.php'; ?>
