<?php
declare(strict_types=1);
require_once '../includes/security.php';
secureSessionStart();
requireRole(['admin']);
require_once '../config/database.php';
require_once '../includes/settings.php';

global $pdo;

// Handle POST save
$saved = false;
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $all = getAllSettings($pdo);
    foreach ($all as $group => $rows) {
        foreach ($rows as $row) {
            $key = $row['key'];
            if ($row['type'] === 'bool') {
                $val = isset($_POST[$key]) ? '1' : '0';
            } else {
                $val = trim((string)($_POST[$key] ?? ''));
                if ($row['type'] === 'number' && $val !== '' && !is_numeric($val)) {
                    $errors[] = 'Trường "' . $row['label'] . '" phải là số.';
                    continue;
                }
            }
            updateSetting($pdo, $key, $val);
        }
    }
    if (empty($errors)) {
        $saved = true;
    }
}

$settings = getAllSettings($pdo);
$page_title = 'Cấu hình hệ thống';
require_once '../includes/header.php';
?>
<style>
.settings-tabs{display:flex;gap:6px;margin-bottom:22px;border-bottom:1px solid var(--border-color);padding-bottom:0}
.settings-tab{padding:10px 20px;border:none;background:none;color:var(--text-muted);font:inherit;font-size:14px;font-weight:600;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;transition:color .15s,border-color .15s}
.settings-tab.active{color:var(--primary);border-color:var(--primary)}
.settings-panel{display:none}.settings-panel.active{display:block}
.settings-group{display:grid;gap:18px}
.setting-row{display:grid;grid-template-columns:1fr 1fr;gap:14px 24px;align-items:start}
.setting-field{display:flex;flex-direction:column;gap:6px}
.setting-label{font-size:13px;font-weight:600;color:var(--text-main)}
.setting-desc{font-size:12px;color:var(--text-muted)}
.setting-input{padding:10px 14px;border:1px solid rgba(255,255,255,.12);border-radius:10px;background:rgba(0,0,0,.2);color:var(--text-main);font:inherit;font-size:14px;outline:none;transition:border-color .15s}
.setting-input:focus{border-color:var(--primary)}
.setting-toggle{display:flex;align-items:center;gap:10px;padding:12px 0}
.toggle-switch{position:relative;width:44px;height:24px;flex-shrink:0}
.toggle-switch input{opacity:0;width:0;height:0}
.toggle-slider{position:absolute;inset:0;background:rgba(255,255,255,.15);border-radius:12px;cursor:pointer;transition:.2s}
.toggle-slider::after{content:'';position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:.2s}
.toggle-switch input:checked+.toggle-slider{background:var(--primary)}
.toggle-switch input:checked+.toggle-slider::after{transform:translateX(20px)}
.settings-save-bar{position:sticky;bottom:0;z-index:10;background:var(--bg-dark);padding:14px 0;border-top:1px solid var(--border-color);margin-top:24px;display:flex;align-items:center;gap:14px}
@media(max-width:700px){.setting-row{grid-template-columns:1fr}}
</style>

<h1><i class='bx bx-cog'></i> Cấu hình hệ thống</h1>

<?php if ($saved): ?><div class="alert alert-success" style="background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);border-radius:10px;padding:12px 16px;color:#6ee7b7;margin-bottom:18px"><i class='bx bx-check-circle'></i> Đã lưu cấu hình thành công.</div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="alert" style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);border-radius:10px;padding:12px 16px;color:#fca5a5;margin-bottom:10px"><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?>

<form method="post">
    <?php echo csrfField(); ?>
    <div class="settings-tabs">
        <button type="button" class="settings-tab active" data-tab="general">⚙️ Chung</button>
        <button type="button" class="settings-tab" data-tab="security">🔒 Bảo mật</button>
        <button type="button" class="settings-tab" data-tab="features">✨ Tính năng</button>
    </div>

    <?php
    $groupLabels = ['general' => 'Chung', 'security' => 'Bảo mật', 'features' => 'Tính năng'];
    $tabMap = ['general' => 'general', 'security' => 'security', 'features' => 'features'];
    foreach ($tabMap as $tab => $groupKey):
        $rows = $settings[$groupKey] ?? [];
    ?>
    <div class="settings-panel <?php echo $tab === 'general' ? 'active' : ''; ?>" id="panel-<?php echo $tab; ?>">
        <div class="box">
            <div class="settings-group">
                <?php $fields = []; foreach ($rows as $r) $fields[] = $r; ?>
                <?php $i = 0; while ($i < count($fields)):
                    $r = $fields[$i];
                    if ($r['type'] === 'bool'):
                ?>
                    <div class="setting-toggle">
                        <label class="toggle-switch">
                            <input type="checkbox" name="<?php echo htmlspecialchars($r['key']); ?>" value="1" <?php echo ($r['value'] ?? '0') === '1' ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <div>
                            <div class="setting-label"><?php echo htmlspecialchars($r['label']); ?></div>
                        </div>
                    </div>
                <?php $i++; else: ?>
                    <div class="setting-row">
                        <div class="setting-field">
                            <label class="setting-label" for="s_<?php echo htmlspecialchars($r['key']); ?>"><?php echo htmlspecialchars($r['label']); ?></label>
                            <?php if ($r['type'] === 'textarea'): ?>
                                <textarea class="setting-input" id="s_<?php echo htmlspecialchars($r['key']); ?>" name="<?php echo htmlspecialchars($r['key']); ?>" rows="3"><?php echo htmlspecialchars($r['value'] ?? ''); ?></textarea>
                            <?php else: ?>
                                <input class="setting-input" id="s_<?php echo htmlspecialchars($r['key']); ?>" type="<?php echo $r['type'] === 'number' ? 'number' : 'text'; ?>" name="<?php echo htmlspecialchars($r['key']); ?>" value="<?php echo htmlspecialchars($r['value'] ?? ''); ?>">
                            <?php endif; ?>
                        </div>
                        <?php $i++; if ($i < count($fields) && $fields[$i]['type'] !== 'bool' && $fields[$i]['type'] !== 'textarea'): $r2 = $fields[$i]; ?>
                        <div class="setting-field">
                            <label class="setting-label" for="s_<?php echo htmlspecialchars($r2['key']); ?>"><?php echo htmlspecialchars($r2['label']); ?></label>
                            <input class="setting-input" id="s_<?php echo htmlspecialchars($r2['key']); ?>" type="<?php echo $r2['type'] === 'number' ? 'number' : 'text'; ?>" name="<?php echo htmlspecialchars($r2['key']); ?>" value="<?php echo htmlspecialchars($r2['value'] ?? ''); ?>">
                        </div>
                        <?php $i++; else: ?><div></div><?php endif; ?>
                    </div>
                <?php endif; endwhile; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="settings-save-bar">
        <button type="submit" class="btn btn-primary"><i class='bx bx-save'></i> Lưu cấu hình</button>
        <span style="color:var(--text-muted);font-size:13px">Thay đổi có hiệu lực ngay sau khi lưu.</span>
    </div>
</form>

<script>
document.querySelectorAll('.settings-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.settings-tab,.settings-panel').forEach(el => el.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('panel-' + tab.dataset.tab)?.classList.add('active');
    });
});
</script>
<?php require_once '../includes/footer.php'; ?>