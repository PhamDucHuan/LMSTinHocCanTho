<?php
declare(strict_types=1);

require_once '../includes/security.php';
secureSessionStart();
requireRole(['admin']);
require_once '../config/database.php';
require_once '../includes/tabular_import.php';
require_once '../includes/google_forms.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $payload = json_decode((string) file_get_contents('php://input'), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) throw new RuntimeException('Yêu cầu không hợp lệ.');
        verifyCsrfToken(is_string($payload['csrf_token'] ?? null) ? $payload['csrf_token'] : '');
        $jsonAction = (string) ($payload['action'] ?? '');
        if ($jsonAction === 'rename_preset') {
            $presetId = (int) ($payload['preset_id'] ?? 0);
            $presetTitle = trim(is_string($payload['form_title'] ?? null) ? $payload['form_title'] : '');
            if ($presetId < 1 || $presetTitle === '') throw new RuntimeException('Vui lòng nhập tên dễ nhớ cho link Form.');
            if (mb_strlen($presetTitle, 'UTF-8') > 191) throw new RuntimeException('Tên link không được dài quá 191 ký tự.');
            $rename = $pdo->prepare('UPDATE google_form_presets SET form_title=? WHERE id=? AND created_by=?');
            $rename->execute([$presetTitle, $presetId, (int) $_SESSION['user_id']]);
            if (!$rename->rowCount()) {
                $exists = $pdo->prepare('SELECT 1 FROM google_form_presets WHERE id=? AND created_by=?');
                $exists->execute([$presetId, (int) $_SESSION['user_id']]);
                if (!$exists->fetchColumn()) throw new RuntimeException('Không tìm thấy link Form cần đổi tên.');
            }
            echo json_encode(['ok' => true, 'message' => 'Đã đổi tên link Google Form.', 'title' => $presetTitle], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($jsonAction !== 'submit_row') throw new RuntimeException('Yêu cầu gửi không hợp lệ.');
        $formSubmitUrl = trim(is_string($payload['form_url'] ?? null) ? $payload['form_url'] : '');
        $rawAnswers = $payload['answers'] ?? null;
        if ($formSubmitUrl === '' || !is_array($rawAnswers)) throw new RuntimeException('Thiếu link Form hoặc dữ liệu cần gửi.');
        $answers = [];
        foreach ($rawAnswers as $entry => $values) {
            if (!is_string($entry) || !is_array($values)) throw new RuntimeException('Dữ liệu câu trả lời không hợp lệ.');
            $answers[$entry] = array_map(static fn($value): string => is_scalar($value) ? (string) $value : '', $values);
        }
        $submitResult = submitPublicGoogleForm($formSubmitUrl, $answers);
        echo json_encode(['ok' => true, 'message' => 'Google Forms đã nhận dữ liệu.', 'status' => $submitResult['status']], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$result = null;
$error = null;
$formUrl = trim((string) ($_POST['form_url'] ?? ''));
$adminId = (int) $_SESSION['user_id'];

if ($formUrl === '' && isset($_GET['preset'])) {
    $presetStatement = $pdo->prepare('SELECT form_url FROM google_form_presets WHERE id=? AND created_by=?');
    $presetStatement->execute([(int) $_GET['preset'], $adminId]);
    $formUrl = trim((string) $presetStatement->fetchColumn());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $action = (string) ($_POST['action'] ?? 'analyze');
    if ($action === 'delete_preset') {
        $delete = $pdo->prepare('DELETE FROM google_form_presets WHERE id=? AND created_by=?');
        $delete->execute([(int) ($_POST['preset_id'] ?? 0), $adminId]);
        $_SESSION['success'] = $delete->rowCount() ? 'Đã xóa link Google Form đã lưu.' : 'Không tìm thấy link cần xóa.';
        header('Location: google_form_fill.php');
        exit;
    }
    try {
        if ($formUrl === '') throw new RuntimeException('Vui lòng nhập link Google Form.');
        $upload = validateUploadedFile($_FILES['excel_file'] ?? [], ['xlsx', 'csv'], 5 * 1024 * 1024);
        $sheet = readTabularImport($upload['tmp_name'], $upload['extension']);
        $fetched = fetchPublicGoogleForm($formUrl);
        $form = parsePublicGoogleForm($fetched['html'], $fetched['url']);
        $savePreset = $pdo->prepare(
             'INSERT INTO google_form_presets (created_by, form_title, form_url, form_url_hash, last_used_at)
              VALUES (?, ?, ?, ?, NOW())
              ON DUPLICATE KEY UPDATE form_url=VALUES(form_url), last_used_at=NOW()'
        );
        $savePreset->execute([$adminId, mb_substr($form['title'], 0, 191, 'UTF-8'), $form['url'], hash('sha256', $form['url'])]);
        $formUrl = $form['url'];
        $result = ['form' => $form, 'sheet' => $sheet, 'filename' => $upload['original_name'], 'file_hash' => hash_file('sha256', $upload['tmp_name'])];
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$savedStatement = $pdo->prepare('SELECT id, form_title, form_url, last_used_at FROM google_form_presets WHERE created_by=? ORDER BY last_used_at DESC, id DESC LIMIT 30');
$savedStatement->execute([$adminId]);
$savedForms = $savedStatement->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Điền Google Form từ Excel';
require_once '../includes/header.php';
?>
<style>
.gf-page{display:grid;gap:18px}.gf-hero{position:relative;overflow:hidden;padding:25px;background:linear-gradient(135deg,rgba(var(--primary-rgb),.16),var(--glass-bg));border:1px solid var(--border-color);border-radius:18px}.gf-hero:after{content:"";position:absolute;width:180px;height:180px;border-radius:50%;right:-60px;top:-95px;background:rgba(var(--primary-rgb),.14)}.gf-hero h1{margin:0 0 8px;display:flex;align-items:center;gap:10px}.gf-hero p{margin:0;color:var(--text-muted);max-width:780px;line-height:1.6}.gf-steps{display:flex;gap:9px;flex-wrap:wrap;margin-top:17px}.gf-step{padding:7px 11px;border-radius:999px;background:var(--input-bg);border:1px solid var(--border-color);font-size:13px;font-weight:700}.gf-card{padding:22px;border:1px solid var(--border-color);border-radius:17px;background:var(--glass-bg)}.gf-upload{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(240px,.65fr) auto;gap:13px;align-items:end}.gf-field{display:grid;gap:7px}.gf-field label{font-weight:750}.gf-field input,.mapping-select,.row-select{width:100%;height:48px;padding:0 13px;border:1px solid var(--border-color);border-radius:11px;background:var(--input-bg);color:var(--text-main);font:inherit;outline:none}.gf-field input:focus,.mapping-select:focus,.row-select:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(var(--primary-rgb),.14)}.gf-file{padding:10px!important}.gf-help{font-size:12px;color:var(--text-muted)}.gf-summary{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}.gf-summary h2{margin:0 0 6px}.gf-summary p{margin:0;color:var(--text-muted)}.gf-badges{display:flex;gap:8px;flex-wrap:wrap}.gf-badge{padding:8px 11px;border:1px solid var(--border-color);border-radius:10px;background:var(--input-bg);font-weight:700}.mapping-list{display:grid;gap:10px;margin-top:17px}.mapping-row{display:grid;grid-template-columns:minmax(220px,1fr) 35px minmax(220px,1fr);align-items:center;gap:12px;padding:12px;border:1px solid var(--border-color);border-radius:12px;background:rgba(255,255,255,.015)}.mapping-question strong,.mapping-question small{display:block}.mapping-question small{margin-top:4px;color:var(--text-muted)}.required-mark{color:var(--danger)}.mapping-arrow{text-align:center;color:var(--primary);font-size:23px}.option-hint{margin-top:6px;font-size:12px;color:var(--text-muted);overflow-wrap:anywhere}.preview-tools{display:grid;grid-template-columns:auto minmax(200px,320px) auto auto 1fr auto;gap:9px;align-items:center;margin:17px 0}.preview-progress{justify-self:end;color:var(--text-muted);font-weight:700}.preview-table{width:100%;border-collapse:collapse}.preview-table th,.preview-table td{padding:11px 12px;border-bottom:1px solid var(--border-color);text-align:left;vertical-align:top}.preview-table th{color:var(--text-muted);font-size:13px}.preview-value{white-space:pre-wrap;overflow-wrap:anywhere}.preview-empty{color:var(--danger);font-weight:700}.preview-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:17px}.sent-box{display:flex;gap:9px;align-items:center;color:var(--text-muted)}.sent-box input{width:19px;height:19px;accent-color:var(--success)}.gf-warning{padding:12px 14px;border:1px solid rgba(245,158,11,.35);border-radius:11px;background:rgba(245,158,11,.09);color:#fbbf24;margin-top:13px}.gf-note{display:flex;gap:10px;align-items:flex-start;padding:13px;border-radius:12px;background:rgba(var(--primary-rgb),.08);color:var(--text-muted)}.gf-note i{font-size:22px;color:var(--primary)}@media(max-width:920px){.gf-upload{grid-template-columns:1fr}.mapping-row{grid-template-columns:1fr}.mapping-arrow{transform:rotate(90deg)}.preview-tools{grid-template-columns:1fr 1fr}.preview-progress{justify-self:start}.preview-table{min-width:650px}.preview-scroll{overflow:auto}}@media(max-width:560px){.preview-tools{grid-template-columns:1fr}.gf-card,.gf-hero{padding:17px}.preview-actions .btn{width:100%}}
.preview-tools{grid-template-columns:auto minmax(210px,320px) auto auto minmax(190px,1fr)}.preview-tools .btn{height:48px;white-space:nowrap}.progress-block{display:grid;gap:7px;min-width:190px}.progress-copy{display:flex;justify-content:space-between;gap:12px;color:var(--text-muted);font-size:13px;font-weight:700}.progress-track{height:8px;border-radius:999px;overflow:hidden;background:rgba(148,163,184,.18)}.progress-track span{display:block;width:0;height:100%;border-radius:inherit;background:linear-gradient(90deg,#22c55e,#34d399);transition:width .25s ease}.send-panel{position:sticky;bottom:12px;z-index:12;margin-top:18px;padding:16px;border:1px solid rgba(var(--primary-rgb),.35);border-radius:16px;background:color-mix(in srgb,var(--sidebar-bg) 94%,transparent);box-shadow:0 16px 45px rgba(0,0,0,.28);backdrop-filter:blur(15px)}.send-panel.is-sent{border-color:rgba(34,197,94,.42)}.current-row-status{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:13px}.row-identity{display:flex;align-items:center;gap:11px}.row-number{width:38px;height:38px;display:grid;place-items:center;border-radius:11px;background:rgba(var(--primary-rgb),.14);color:var(--primary);font-weight:900}.row-identity strong,.row-identity small{display:block}.row-identity small{margin-top:2px;color:var(--text-muted)}.status-badge{display:inline-flex;align-items:center;gap:7px;padding:7px 10px;border-radius:999px;background:rgba(245,158,11,.12);color:#fbbf24;font-size:13px;font-weight:800}.status-badge.sent{background:rgba(34,197,94,.13);color:#4ade80}.send-buttons{display:grid;grid-template-columns:minmax(0,1fr) 42px minmax(0,1fr);align-items:stretch;gap:11px}.send-action{min-height:66px;border:0;border-radius:13px;padding:11px 17px;color:#fff;font:800 15px inherit;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:11px;text-align:left;transition:transform .18s ease,filter .18s ease,opacity .18s ease}.send-action i{font-size:25px}.send-action span small{display:block;margin-top:3px;font-size:12px;font-weight:500;opacity:.82}.send-action:hover:not(:disabled){transform:translateY(-2px);filter:brightness(1.06)}.send-action:disabled{cursor:not-allowed;opacity:.5}.open-action{background:linear-gradient(135deg,var(--primary),#7c3aed);box-shadow:0 10px 24px rgba(var(--primary-rgb),.23)}.confirm-action{background:linear-gradient(135deg,#16a34a,#059669);box-shadow:0 10px 24px rgba(5,150,105,.2)}.send-arrow{display:grid;place-items:center;color:var(--text-muted);font-size:27px}.send-footer{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:12px;color:var(--text-muted);font-size:13px}.undo-sent{border:0;background:transparent;color:var(--text-muted);font:700 13px inherit;cursor:pointer;text-decoration:underline;text-underline-offset:3px}.undo-sent[hidden]{display:none}.send-complete{color:#4ade80;font-weight:750}@media(max-width:920px){.preview-tools{grid-template-columns:auto minmax(180px,1fr) auto}.progress-block{grid-column:1/-1}.send-buttons{grid-template-columns:1fr}.send-arrow{transform:rotate(90deg);height:25px}}@media(max-width:560px){.preview-tools{grid-template-columns:1fr}.preview-tools .btn{width:100%}.send-panel{bottom:7px;padding:13px}.current-row-status{align-items:flex-start}.send-footer{align-items:flex-start;flex-direction:column}.send-action{width:100%}}
.saved-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:11px;margin-top:15px}.saved-form{display:grid;gap:11px;padding:14px;border:1px solid var(--border-color);border-radius:13px;background:var(--input-bg)}.saved-form-head{display:grid;grid-template-columns:38px minmax(0,1fr);gap:10px;align-items:center}.saved-form-icon{width:38px;height:38px;display:grid;place-items:center;border-radius:10px;background:rgba(103,58,183,.15);color:#a78bfa;font-size:22px}.saved-form strong,.saved-form small{display:block}.saved-form strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.saved-form small{margin-top:3px;color:var(--text-muted)}.saved-form-actions{display:flex;gap:7px;flex-wrap:wrap}.saved-form-actions form{margin:0}.saved-form-actions .btn{min-height:38px;padding:8px 11px;font-size:13px}.sent-history{display:grid;gap:9px;margin-top:15px}.sent-history-row{display:grid;grid-template-columns:44px minmax(0,1fr) auto;gap:12px;align-items:center;padding:12px;border:1px solid rgba(34,197,94,.25);border-radius:12px;background:rgba(34,197,94,.055)}.sent-history-number{width:42px;height:42px;display:grid;place-items:center;border-radius:11px;background:rgba(34,197,94,.14);color:#4ade80;font-weight:900}.sent-history-content strong,.sent-history-content small{display:block}.sent-history-content small{margin-top:4px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.sent-history-actions{display:flex;gap:7px}.sent-empty{padding:24px;text-align:center;border:1px dashed var(--border-color);border-radius:12px;color:var(--text-muted)}@media(max-width:650px){.sent-history-row{grid-template-columns:42px minmax(0,1fr)}.sent-history-actions{grid-column:1/-1}.sent-history-actions .btn{flex:1}}
.preview-adjusted{color:#4ade80;font-weight:750}.preview-original{display:block;margin-top:4px;color:var(--text-muted);font-size:12px;font-weight:400}.preview-choice-error{color:#fbbf24;font-weight:750}
.mapping-source{display:grid;gap:8px}.mapping-row-note{color:var(--text-muted);font-size:11px}.gf-page select option{background:#0b2942;color:#f8fafc}.gf-page select{color-scheme:dark}html[data-theme="light"] .gf-page select{color-scheme:light}html[data-theme="light"] .gf-page select option{background:#fff;color:#0f172a}
.send-footer-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap}.open-normal-tab{min-height:36px;display:inline-flex;align-items:center;gap:7px;padding:7px 11px;border:1px solid var(--border-color);border-radius:9px;background:var(--input-bg);color:var(--text-main);font:700 13px inherit;cursor:pointer}.open-normal-tab:hover:not(:disabled){border-color:var(--primary);color:var(--primary)}.open-normal-tab:disabled{cursor:not-allowed;opacity:.5}@media(max-width:560px){.send-footer-actions{width:100%;justify-content:space-between}.open-normal-tab{flex:1;justify-content:center}}
.preview-source strong,.preview-source small{display:block}.preview-source small{margin-top:4px;color:var(--text-muted);font-size:12px}.preview-source.is-edited strong{color:#4ade80}.preview-editor{display:grid;gap:8px;min-width:240px}.preview-edit-control{width:100%;min-height:42px;padding:8px 11px;border:1px solid var(--border-color);border-radius:9px;background:var(--input-bg);color:var(--text-main);font:inherit;outline:none}.preview-edit-control:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(var(--primary-rgb),.12)}textarea.preview-edit-control{min-height:78px;resize:vertical}.preview-choice-grid{display:flex;gap:7px 13px;flex-wrap:wrap}.preview-choice-item{display:inline-flex;align-items:center;gap:7px;padding:7px 9px;border:1px solid var(--border-color);border-radius:9px;background:var(--input-bg);cursor:pointer}.preview-choice-item input{width:17px;height:17px;accent-color:var(--primary)}.preview-editor-footer{display:flex;align-items:center;justify-content:space-between;gap:9px;flex-wrap:wrap}.preview-editor-note{color:var(--text-muted);font-size:11px}.preview-reset{border:0;background:transparent;color:var(--primary);font:700 12px inherit;cursor:pointer;text-decoration:underline;text-underline-offset:3px}.preview-table td:last-child{min-width:290px}@media(max-width:700px){.preview-editor{min-width:210px}}
.send-arrow span{font-size:11px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.manual-confirm{border-color:rgba(34,197,94,.35);color:#4ade80}.send-action.is-loading i{animation:gf-spin .8s linear infinite}@keyframes gf-spin{to{transform:rotate(360deg)}}
.saved-form-rename{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:7px;align-items:center;padding-top:10px;border-top:1px solid var(--border-color)}.saved-form-rename[hidden]{display:none}.saved-form-rename input{width:100%;height:40px;padding:0 11px;border:1px solid var(--border-color);border-radius:9px;background:var(--sidebar-bg);color:var(--text-main);font:inherit;outline:none}.saved-form-rename input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(var(--primary-rgb),.12)}.saved-form-rename .btn{min-height:40px;padding:8px 11px}.rename-preset-toggle.is-open{border-color:var(--primary);color:var(--primary)}@media(max-width:520px){.saved-form-rename{grid-template-columns:1fr 1fr}.saved-form-rename input{grid-column:1/-1}}
.mapping-list{gap:6px;margin-top:12px}.mapping-row{grid-template-columns:minmax(220px,1fr) 24px minmax(220px,1fr);gap:8px;padding:9px 12px}.mapping-row .mapping-select{height:44px}.mapping-question small{margin-top:2px}.option-hint{margin-top:4px}.mapping-row-note{margin-top:-1px}@media(max-width:920px){.mapping-row{grid-template-columns:1fr}.mapping-arrow{transform:rotate(90deg)}}
.preview-tools{margin:12px 0}.preview-table th,.preview-table td{padding:7px 12px}.preview-editor{gap:4px}.preview-edit-control{min-height:38px;height:38px;padding:6px 10px}.preview-source small{margin-top:2px}.preview-editor-footer{gap:5px}.preview-editor-note{font-size:10px;line-height:1.25}textarea.preview-edit-control{height:auto;min-height:58px}.preview-choice-grid{gap:5px 9px}.preview-choice-item{padding:5px 8px}.send-panel{margin-top:12px;padding:13px}.current-row-status{margin-bottom:10px}.send-buttons{gap:8px}.send-action{min-height:58px;padding:9px 15px}.send-footer{margin-top:8px}
.send-panel .row-navigation{margin:12px 0 0;padding-top:12px;border-top:1px solid var(--border-color)}
</style>
<div class="gf-page">
  <section class="gf-hero">
    <h1><i class='bx bxl-google'></i> Điền Google Form từ Excel</h1>
    <p>Mỗi hàng Excel tương ứng một lượt điền. Bạn có thể kiểm tra, chỉnh dữ liệu và gửi thẳng lên Google Forms ngay trong LMS.</p>
    <div class="gf-steps"><span class="gf-step">1. Nhập link và Excel</span><span class="gf-step">2. Ghép các trường</span><span class="gf-step">3. Kiểm tra từng hàng</span><span class="gf-step">4. Gửi ngay trên LMS</span></div>
  </section>

  <?php if ($error): ?><div class="alert alert-danger"><strong>Chưa thể đọc dữ liệu:</strong> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
  <?php if (isset($_SESSION['success'])): ?><div class="alert alert-success"><?php echo htmlspecialchars((string) $_SESSION['success']); unset($_SESSION['success']); ?></div><?php endif; ?>

  <?php if ($savedForms): ?>
  <section class="gf-card">
    <div class="gf-summary"><div><h2><i class='bx bx-bookmark'></i> Biểu mẫu đã lưu</h2><p>Chọn dùng lại link rồi tải lên file Excel mới.</p></div><span class="gf-badge"><?php echo count($savedForms); ?> link</span></div>
    <div class="saved-grid">
      <?php foreach ($savedForms as $savedForm): ?>
      <article class="saved-form" data-preset-id="<?php echo (int) $savedForm['id']; ?>">
        <div class="saved-form-head"><span class="saved-form-icon"><i class='bx bxl-google'></i></span><div><strong class="saved-form-title" title="<?php echo htmlspecialchars($savedForm['form_title']); ?>"><?php echo htmlspecialchars($savedForm['form_title']); ?></strong><small>Dùng gần nhất <?php echo date('d/m/Y H:i', strtotime($savedForm['last_used_at'])); ?></small></div></div>
        <div class="saved-form-actions"><a class="btn btn-primary" href="?preset=<?php echo (int) $savedForm['id']; ?>"><i class='bx bx-import'></i> Dùng link này</a><a class="btn btn-outline" href="<?php echo htmlspecialchars($savedForm['form_url']); ?>" target="_blank" rel="noopener"><i class='bx bx-link-external'></i> Mở</a><button class="btn btn-outline rename-preset-toggle" type="button"><i class='bx bx-edit-alt'></i> Đổi tên</button><form method="post" onsubmit="return confirm('Xóa link Google Form đã lưu này?')"><?php echo csrfField(); ?><input type="hidden" name="action" value="delete_preset"><input type="hidden" name="preset_id" value="<?php echo (int) $savedForm['id']; ?>"><button class="btn btn-outline" type="submit" title="Xóa link"><i class='bx bx-trash'></i></button></form></div>
        <div class="saved-form-rename" hidden><input class="rename-preset-input" type="text" maxlength="191" value="<?php echo htmlspecialchars($savedForm['form_title']); ?>" aria-label="Tên dễ nhớ cho Google Form"><button class="btn btn-primary rename-preset-save" type="button"><i class='bx bx-save'></i> Lưu tên</button><button class="btn btn-outline rename-preset-cancel" type="button">Hủy</button></div>
      </article>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="gf-card">
    <form method="post" enctype="multipart/form-data" class="gf-upload">
      <?php echo csrfField(); ?>
      <input type="hidden" name="action" value="analyze">
      <div class="gf-field"><label for="form-url">Link Google Form</label><input id="form-url" type="url" name="form_url" value="<?php echo htmlspecialchars($formUrl); ?>" placeholder="https://forms.gle/... hoặc https://docs.google.com/forms/..." required><span class="gf-help">Form cần công khai và không bắt buộc tải tệp. Link hợp lệ sẽ tự động được lưu lại.</span></div>
      <div class="gf-field"><label for="excel-file">File Excel</label><input class="gf-file" id="excel-file" type="file" name="excel_file" accept=".xlsx,.csv" required><span class="gf-help">Hàng đầu là tên cột; tối đa 5 MB và 2.000 hàng.</span></div>
      <button class="btn btn-primary" type="submit"><i class='bx bx-analyse'></i> Đọc dữ liệu</button>
    </form>
  </section>

  <?php if ($result): ?>
  <section class="gf-card">
    <div class="gf-summary"><div><h2><?php echo htmlspecialchars($result['form']['title']); ?></h2><p><?php echo htmlspecialchars($result['filename']); ?></p></div><div class="gf-badges"><span class="gf-badge"><?php echo count($result['form']['fields']); ?> trường Form</span><span class="gf-badge"><?php echo count($result['sheet']['headers']); ?> cột Excel</span><span class="gf-badge"><?php echo count($result['sheet']['rows']); ?> hàng dữ liệu</span></div></div>
    <?php if ($result['form']['skipped']): ?><div class="gf-warning"><strong>Không thể điền sẵn:</strong> <?php echo htmlspecialchars(implode(', ', $result['form']['skipped'])); ?></div><?php endif; ?>
    <div class="mapping-list" id="mapping-list">
      <?php foreach ($result['form']['fields'] as $index => $field): ?>
      <div class="mapping-row">
        <div class="mapping-question"><strong><?php echo htmlspecialchars($field['label']); ?><?php if ($field['required']): ?> <span class="required-mark">*</span><?php endif; ?></strong><small><?php echo htmlspecialchars($field['type_label']); ?></small><?php if ($field['options']): ?><div class="option-hint">Giá trị hợp lệ: <?php echo htmlspecialchars(implode(' · ', array_slice($field['options'], 0, 8))); ?></div><?php endif; ?></div>
        <div class="mapping-arrow"><i class='bx bx-left-arrow-alt'></i></div>
        <div class="mapping-source"><select class="mapping-select" data-field-index="<?php echo $index; ?>"><option value="">— Không lấy từ Excel —</option><?php foreach ($result['sheet']['headers'] as $column => $header): ?><option value="<?php echo $column; ?>"><?php echo htmlspecialchars($header); ?></option><?php endforeach; ?></select><?php if ($field['options']): ?><small class="mapping-row-note">Mỗi hàng dùng dữ liệu riêng từ Excel; bạn có thể chỉnh lựa chọn ở bước kiểm tra.</small><?php endif; ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="gf-card">
    <div class="gf-summary"><div><h2>Kiểm tra từng hàng</h2><p>Giá trị trống hoặc không hợp lệ sẽ được cảnh báo trước khi gửi.</p></div></div>
    <div class="preview-scroll"><table class="preview-table"><thead><tr><th>Trường Google Form</th><th>Nguồn dữ liệu</th><th>Giá trị sẽ điền — có thể chỉnh sửa</th></tr></thead><tbody id="preview-body"></tbody></table></div>
    <div class="gf-note" style="margin-top:15px"><i class='bx bx-info-circle'></i><span>Bạn có thể sửa trực tiếp từng giá trị bên trên. Dữ liệu đã sửa được lưu riêng cho từng hàng Excel và sẽ được dùng khi mở hoặc gửi Google Form.</span></div>
    <div class="send-panel" id="send-panel">
      <div class="current-row-status"><div class="row-identity"><span class="row-number" id="current-row-number"></span><span><strong id="current-row-title"></strong><small id="current-row-subtitle"></small></span></div><span class="status-badge" id="current-row-badge"><i class='bx bx-time-five'></i><span>Chưa gửi</span></span></div>
      <div class="send-buttons">
        <button class="send-action open-action" id="open-prefilled" type="button"><i class='bx bx-columns'></i><span><strong>1. Mở chế độ chia đôi</strong><small>Google Form mở ở nửa phải màn hình</small></span></button>
        <div class="send-arrow"><span>hoặc</span></div>
        <button class="send-action confirm-action" id="send-direct" type="button"><i class='bx bx-send'></i><span><strong>Gửi trực tiếp trên web</strong><small>Gửi hàng này lên Google Forms ngay</small></span></button>
      </div>
      <div class="send-footer"><span id="send-hint" aria-live="polite">Bạn có thể gửi ngay hoặc mở Form để kiểm tra trước.</span><div class="send-footer-actions"><button class="open-normal-tab" id="open-normal-tab" type="button"><i class='bx bx-link-external'></i> Mở thẻ thường</button><button class="open-normal-tab manual-confirm" id="confirm-sent" type="button" hidden><i class='bx bx-check'></i> Tôi đã gửi thủ công</button><button class="undo-sent" id="undo-sent" type="button" hidden>Đánh dấu lại là chưa gửi</button></div></div>
      <div class="preview-tools row-navigation"><button class="btn btn-outline" id="previous-row" type="button"><i class='bx bx-chevron-left'></i> Hàng trước</button><select id="row-select" class="row-select" aria-label="Chọn hàng Excel"></select><button class="btn btn-outline" id="next-row" type="button">Hàng sau <i class='bx bx-chevron-right'></i></button><button class="btn btn-outline" id="first-pending" type="button"><i class='bx bx-skip-next'></i> Tới hàng chưa gửi</button><div class="progress-block"><div class="progress-copy"><span id="preview-progress"></span><span id="remaining-progress"></span></div><div class="progress-track"><span id="progress-bar"></span></div></div></div>
    </div>
  </section>
  <section class="gf-card" id="sent-history-card">
    <div class="gf-summary"><div><h2><i class='bx bx-check-double'></i> Các hàng đã gửi</h2><p>Danh sách được lưu trên trình duyệt theo đúng file Excel hiện tại.</p></div><div class="gf-badges"><span class="gf-badge" id="sent-list-count">0 hàng</span><button class="btn btn-outline" id="clear-sent" type="button"><i class='bx bx-reset'></i> Xóa trạng thái</button></div></div>
    <div class="sent-history" id="sent-history"></div>
    <div class="sent-empty" id="sent-empty"><i class='bx bx-inbox' style="font-size:28px;display:block;margin-bottom:7px"></i>Chưa có hàng nào được đánh dấu đã gửi.</div>
  </section>
  <script>
  (() => {
    const payload = <?php echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const fields = payload.form.fields;
    const headers = payload.sheet.headers;
    const rows = payload.sheet.rows;
    const selects = Array.from(document.querySelectorAll('.mapping-select'));
    const rowSelect = document.getElementById('row-select');
    const body = document.getElementById('preview-body');
    const sendPanel = document.getElementById('send-panel');
    const openButton = document.getElementById('open-prefilled');
    const normalTabButton = document.getElementById('open-normal-tab');
    const directButton = document.getElementById('send-direct');
    const confirmButton = document.getElementById('confirm-sent');
    const undoButton = document.getElementById('undo-sent');
    const csrfToken = <?php echo json_encode(csrfToken()); ?>;
    const storageKey = 'lms-google-form-progress:' + simpleHash(payload.form.url + '|' + payload.file_hash);
    const mappingKey = storageKey + ':mapping';
    const overridesKey = storageKey + ':overrides';
    let sent = readStoredJson(storageKey);
    let overrides = readStoredJson(overridesKey);
    const openedRows = {};
    let current = 0;
    let isSubmitting = false;

    function normalise(value) {
      return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
    }
    function canonicalChoice(value) {
      return normalise(value)
        .replace(/\bung dung cong nghe thong tin\b/g, 'ud cntt')
        .replace(/\bung dung cntt\b/g, 'ud cntt')
        .replace(/\bcong nghe thong tin\b/g, 'cntt')
        .replace(/\bquan ly hanh chinh\b/g, 'qlhc')
        .replace(/\btrat tu xa hoi\b/g, 'ttxh')
        .replace(/\btrat tu do thi\b/g, 'ttdt')
        .replace(/\bcsqlhc\b/g, 'canh sat qlhc')
        .replace(/\s+/g, ' ').trim();
    }
    function textMatchScore(left, right) {
      left = normalise(left); right = normalise(right);
      if (!left || !right) return 0;
      if (left === right) return 100;
      if (left.length > 3 && right.length > 3 && (left.includes(right) || right.includes(left))) return 88;
      const leftTokens = [...new Set(left.split(' ').filter(token => token.length > 1))];
      const rightTokens = [...new Set(right.split(' ').filter(token => token.length > 1))];
      const common = leftTokens.filter(token => rightTokens.includes(token)).length;
      if (common < 2) return 0;
      const coverage = common / Math.min(leftTokens.length, rightTokens.length);
      const union = new Set([...leftTokens, ...rightTokens]).size;
      return Math.round(55 * coverage + 35 * common / union);
    }
    function matchSingleOption(value, options) {
      const wanted = canonicalChoice(value);
      const exact = options.find(option => canonicalChoice(option) === wanted);
      if (exact !== undefined) return { value: exact, matched: true, changed: exact !== value };
      const scored = options.map(option => ({ option, score: textMatchScore(wanted, canonicalChoice(option)) })).sort((left, right) => right.score - left.score);
      if (scored[0] && scored[0].score >= 65 && (!scored[1] || scored[0].score - scored[1].score >= 6)) {
        return { value: scored[0].option, matched: true, changed: scored[0].option !== value };
      }
      return { value, matched: false, changed: false };
    }
    function prepareFieldValue(field, rawValue) {
      const rawValues = field.multiple
        ? String(rawValue).split(/[;|]/).map(value => value.trim()).filter(Boolean)
        : [String(rawValue).trim()].filter(Boolean);
      if (!field.options.length) return { values: rawValues, display: rawValues.join('; '), matched: true, changed: false };
      const matches = rawValues.map(value => matchSingleOption(value, field.options));
      return {
        values: matches.map(match => match.value),
        display: matches.map(match => match.value).join('; '),
        matched: matches.length > 0 && matches.every(match => match.matched),
        changed: matches.some(match => match.changed),
      };
    }
    function sourceForField(fieldIndex, row) {
      const column = selects[fieldIndex].value === '' ? -1 : Number(selects[fieldIndex].value);
      return { value: column >= 0 ? String(row[column] ?? '') : '', label: column >= 0 ? headers[column] : 'Không điền' };
    }
    function hasOverride(rowIndex, fieldIndex) {
      const rowValues = overrides[String(rowIndex)];
      return Boolean(rowValues && Object.prototype.hasOwnProperty.call(rowValues, String(fieldIndex)));
    }
    function effectiveValueForField(fieldIndex, rowIndex = current) {
      const row = rows[rowIndex];
      const original = sourceForField(fieldIndex, row);
      if (!hasOverride(rowIndex, fieldIndex)) return { ...original, overridden: false, original };
      return {
        value: String(overrides[String(rowIndex)][String(fieldIndex)] ?? ''),
        label: 'Đã chỉnh trên web',
        overridden: true,
        original,
      };
    }
    function saveOverrides() {
      localStorage.setItem(overridesKey, JSON.stringify(overrides));
    }
    function setFieldOverride(fieldIndex, value) {
      const rowKey = String(current);
      if (!overrides[rowKey] || typeof overrides[rowKey] !== 'object') overrides[rowKey] = {};
      overrides[rowKey][String(fieldIndex)] = String(value ?? '');
      delete sent[current];
      localStorage.setItem(storageKey, JSON.stringify(sent));
      openedRows[current] = false;
      saveOverrides();
      render();
    }
    function resetFieldOverride(fieldIndex) {
      const rowKey = String(current);
      if (!overrides[rowKey]) return;
      delete overrides[rowKey][String(fieldIndex)];
      if (!Object.keys(overrides[rowKey]).length) delete overrides[rowKey];
      delete sent[current];
      localStorage.setItem(storageKey, JSON.stringify(sent));
      openedRows[current] = false;
      saveOverrides();
      render();
    }
    function dateInputValue(value) {
      const text = String(value || '').trim();
      const dmy = text.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})/);
      if (dmy) return `${dmy[3]}-${dmy[2].padStart(2, '0')}-${dmy[1].padStart(2, '0')}`;
      const ymd = text.match(/^(\d{4})-(\d{2})-(\d{2})/);
      return ymd ? ymd[0] : '';
    }
    function createFieldEditor(field, fieldIndex, fieldValue) {
      const wrapper = document.createElement('div');
      wrapper.className = 'preview-editor';
      const prepared = prepareFieldValue(field, fieldValue.value);
      if (field.options.length && field.multiple) {
        const choices = document.createElement('div'); choices.className = 'preview-choice-grid';
        const selectedValues = new Set(prepared.matched ? prepared.values : String(fieldValue.value).split(/[;|]/).map(value => value.trim()));
        field.options.forEach(option => {
          const label = document.createElement('label'); label.className = 'preview-choice-item';
          const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.value = option; checkbox.checked = selectedValues.has(option);
          const text = document.createElement('span'); text.textContent = option;
          checkbox.addEventListener('change', () => {
            const values = Array.from(choices.querySelectorAll('input:checked')).map(input => input.value);
            setFieldOverride(fieldIndex, values.join('; '));
          });
          label.append(checkbox, text); choices.appendChild(label);
        });
        wrapper.appendChild(choices);
      } else if (field.options.length) {
        const select = document.createElement('select'); select.className = 'preview-edit-control';
        const blank = document.createElement('option'); blank.value = ''; blank.textContent = '— Không điền —'; select.appendChild(blank);
        field.options.forEach(option => {
          const item = document.createElement('option'); item.value = option; item.textContent = option; select.appendChild(item);
        });
        if (fieldValue.value && !prepared.matched) {
          const unmatched = document.createElement('option'); unmatched.value = fieldValue.value; unmatched.textContent = `⚠ Chưa khớp: ${fieldValue.value}`; select.appendChild(unmatched);
          select.value = fieldValue.value;
        } else select.value = prepared.values[0] || '';
        select.addEventListener('change', () => setFieldOverride(fieldIndex, select.value));
        wrapper.appendChild(select);
      } else {
        const control = document.createElement(field.type === 1 ? 'textarea' : 'input');
        control.className = 'preview-edit-control';
        if (control instanceof HTMLInputElement) {
          control.type = field.type === 9 ? 'date' : (field.type === 10 ? 'time' : 'text');
          control.value = field.type === 9 ? dateInputValue(fieldValue.value) : fieldValue.value;
        } else control.value = fieldValue.value;
        control.placeholder = field.required ? 'Nhập dữ liệu bắt buộc' : 'Để trống nếu không cần điền';
        control.addEventListener('change', () => setFieldOverride(fieldIndex, control.value));
        wrapper.appendChild(control);
      }
      const footer = document.createElement('div'); footer.className = 'preview-editor-footer';
      const note = document.createElement('small'); note.className = 'preview-editor-note';
      if (!fieldValue.value && field.required) note.textContent = 'Thiếu dữ liệu bắt buộc';
      else if (fieldValue.value && !prepared.matched) note.textContent = 'Giá trị chưa khớp lựa chọn của Google Form';
      else if (prepared.changed) note.textContent = `Đã tự khớp từ: ${fieldValue.value}`;
      else note.textContent = fieldValue.overridden ? 'Đang dùng giá trị đã chỉnh trên web' : 'Đang dùng dữ liệu đã ánh xạ';
      footer.appendChild(note);
      if (fieldValue.overridden) {
        const reset = document.createElement('button'); reset.type = 'button'; reset.className = 'preview-reset'; reset.textContent = 'Dùng lại dữ liệu gốc';
        reset.addEventListener('click', () => resetFieldOverride(fieldIndex)); footer.appendChild(reset);
      }
      wrapper.appendChild(footer);
      return wrapper;
    }
    function simpleHash(value) {
      let hash = 2166136261;
      for (let index = 0; index < value.length; index++) { hash ^= value.charCodeAt(index); hash = Math.imul(hash, 16777619); }
      return (hash >>> 0).toString(36);
    }
    function readStoredJson(key) {
      try {
        const value = JSON.parse(localStorage.getItem(key) || '{}');
        return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
      } catch (_) {
        return {};
      }
    }
    function initialiseMapping() {
      const saved = readStoredJson(mappingKey);
      selects.forEach((select, fieldIndex) => {
        const savedValue = saved[fieldIndex];
        const savedColumn = savedValue && typeof savedValue === 'object' ? savedValue.column : savedValue;
        if (savedColumn !== undefined && headers[savedColumn] !== undefined) select.value = String(savedColumn);
        else {
          const label = fields[fieldIndex].label.split(/\s+—\s+/)[0];
          let best = -1;
          let bestScore = 0;
          headers.forEach((header, column) => {
            const score = textMatchScore(label, header);
            if (score > bestScore) { bestScore = score; best = column; }
          });
          if (best >= 0 && bestScore >= 65) select.value = String(best);
        }
      });
      saveMapping();
    }
    function saveMapping() {
      const mapping = {};
      selects.forEach((select, index) => {
        if (select.value !== '') mapping[index] = { column: Number(select.value) };
      });
      localStorage.setItem(mappingKey, JSON.stringify(mapping));
    }
    function renderRowOptions() {
      rowSelect.innerHTML = rows.map((_, index) => `<option value="${index}">Hàng Excel ${index + 2}${sent[index] ? ' ✓ Đã gửi' : ''}</option>`).join('');
      rowSelect.value = String(current);
    }
    function render() {
      const row = rows[current];
      body.innerHTML = '';
      selects.forEach((select, index) => {
        const fieldValue = effectiveValueForField(index);
        const tr = document.createElement('tr');
        const label = document.createElement('td'); label.textContent = fields[index].label;
        const source = document.createElement('td'); source.className = 'preview-source';
        const sourceTitle = document.createElement('strong'); sourceTitle.textContent = fieldValue.label;
        source.appendChild(sourceTitle);
        if (fieldValue.overridden) {
          source.classList.add('is-edited');
          const sourceDetail = document.createElement('small'); sourceDetail.textContent = `Nguồn ban đầu: ${fieldValue.original.label}`; source.appendChild(sourceDetail);
        }
        const content = document.createElement('td'); content.appendChild(createFieldEditor(fields[index], index, fieldValue));
        tr.append(label, source, content); body.appendChild(tr);
      });
      const sentCount = Object.values(sent).filter(Boolean).length;
      const isSent = Boolean(sent[current]);
      const wasOpened = Boolean(openedRows[current]);
      const mappedCount = fields.filter((_, index) => selects[index].value !== '').length;
      const editedCount = fields.filter((_, index) => hasOverride(current, index)).length;
      const filledCount = fields.filter((_, index) => effectiveValueForField(index).value.trim() !== '').length;
      document.getElementById('preview-progress').textContent = `${sentCount}/${rows.length} đã gửi`;
      document.getElementById('remaining-progress').textContent = `${rows.length - sentCount} còn lại`;
      document.getElementById('progress-bar').style.width = `${Math.round(sentCount * 100 / rows.length)}%`;
      document.getElementById('current-row-number').textContent = String(current + 2);
      document.getElementById('current-row-title').textContent = `Hàng Excel ${current + 2}`;
      document.getElementById('current-row-subtitle').textContent = `${mappedCount} trường được ghép${editedCount ? ` · ${editedCount} trường đã chỉnh` : ''}`;
      const badge = document.getElementById('current-row-badge');
      badge.classList.toggle('sent', isSent);
      badge.innerHTML = isSent ? "<i class='bx bx-check-circle'></i><span>Đã gửi</span>" : "<i class='bx bx-time-five'></i><span>Chưa gửi</span>";
      sendPanel.classList.toggle('is-sent', isSent);
      openButton.disabled = filledCount === 0 || isSubmitting;
      normalTabButton.disabled = filledCount === 0 || isSubmitting;
      openButton.querySelector('strong').textContent = wasOpened ? '1. Mở lại chế độ chia đôi' : '1. Mở chế độ chia đôi';
      directButton.disabled = isSent || filledCount === 0 || isSubmitting;
      directButton.classList.toggle('is-loading', isSubmitting);
      directButton.querySelector('i').className = isSubmitting ? 'bx bx-loader-alt' : (isSent ? 'bx bx-check-circle' : 'bx bx-send');
      directButton.querySelector('strong').textContent = isSubmitting ? 'Đang gửi lên Google Forms…' : (isSent ? 'Đã gửi lên Google Forms' : 'Gửi trực tiếp trên web');
      directButton.querySelector('small').textContent = isSubmitting ? 'Vui lòng giữ nguyên trang trong giây lát' : (isSent ? 'Hàng này đã hoàn tất' : 'Gửi hàng này lên Google Forms ngay');
      confirmButton.hidden = isSent || !wasOpened;
      confirmButton.disabled = isSubmitting;
      undoButton.hidden = !isSent;
      undoButton.textContent = sent[current]?.method === 'direct' ? 'Cho phép gửi lại' : 'Đánh dấu lại là chưa gửi';
      undoButton.title = sent[current]?.method === 'direct' ? 'Thao tác này không xóa câu trả lời đã có trên Google Forms' : '';
      document.getElementById('send-hint').innerHTML = sentCount === rows.length
        ? '<span class="send-complete"><i class="bx bx-party"></i> Đã hoàn thành tất cả các hàng.</span>'
        : (isSubmitting ? '<strong>Đang kết nối Google Forms và gửi dữ liệu…</strong>' : (isSent ? '<span class="send-complete"><i class="bx bx-check-circle"></i> Google Forms đã nhận dữ liệu hàng này.</span>' : (filledCount ? (wasOpened ? '<strong>Form đã mở:</strong> nếu đã bấm Gửi bên Google, hãy chọn “Tôi đã gửi thủ công”.' : 'Bạn có thể gửi ngay hoặc mở Form để kiểm tra trước.') : '<span class="preview-empty">Hãy nhập hoặc ánh xạ ít nhất một trường trước khi gửi.</span>')));
      document.getElementById('previous-row').disabled = current === 0 || isSubmitting;
      document.getElementById('next-row').disabled = current === rows.length - 1 || isSubmitting;
      document.getElementById('first-pending').disabled = sentCount === rows.length || isSubmitting;
      rowSelect.disabled = isSubmitting;
      renderRowOptions();
      renderSentHistory();
    }
    function renderSentHistory() {
      const list = document.getElementById('sent-history');
      const empty = document.getElementById('sent-empty');
      const indexes = Object.keys(sent).map(Number).filter(index => sent[index] && rows[index]).sort((left, right) => left - right);
      list.replaceChildren();
      empty.hidden = indexes.length > 0;
      document.getElementById('sent-list-count').textContent = `${indexes.length} hàng`;
      document.getElementById('clear-sent').disabled = indexes.length === 0;
      indexes.forEach(index => {
        const item = document.createElement('article'); item.className = 'sent-history-row';
        const number = document.createElement('span'); number.className = 'sent-history-number'; number.textContent = String(index + 2);
        const content = document.createElement('div'); content.className = 'sent-history-content';
        const sentInfo = sent[index] && typeof sent[index] === 'object' ? sent[index] : {};
        const sentMethod = sentInfo.method === 'direct' ? 'Gửi trực tiếp' : (sentInfo.method === 'manual' ? 'Gửi thủ công' : 'Đã gửi');
        const title = document.createElement('strong'); title.textContent = `Hàng Excel ${index + 2} · ${sentMethod}`;
        const summary = document.createElement('small');
        summary.textContent = rows[index].map((value, column) => value ? `${headers[column]}: ${value}` : '').filter(Boolean).slice(0, 3).join(' · ') || 'Hàng không có dữ liệu hiển thị';
        content.append(title, summary);
        const actions = document.createElement('div'); actions.className = 'sent-history-actions';
        const view = document.createElement('button'); view.type = 'button'; view.className = 'btn btn-outline'; view.innerHTML = "<i class='bx bx-show'></i> Xem lại";
        view.addEventListener('click', () => { go(index); sendPanel.scrollIntoView({ behavior: 'smooth', block: 'center' }); });
        const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-outline'; remove.innerHTML = "<i class='bx bx-undo'></i> Bỏ đánh dấu";
        remove.addEventListener('click', () => {
          if (sent[index]?.method === 'direct' && !confirm('Câu trả lời đã gửi vẫn còn trên Google Forms. Chỉ cho phép gửi lại hàng này trên LMS?')) return;
          delete sent[index]; localStorage.setItem(storageKey, JSON.stringify(sent)); render();
        });
        actions.append(view, remove); item.append(number, content, actions); list.appendChild(item);
      });
    }
    function preparedValuesForField(fieldIndex) {
      const rawValue = effectiveValueForField(fieldIndex).value.trim();
      if (!rawValue) return [];
      return prepareFieldValue(fields[fieldIndex], rawValue).values.map(value => {
        if (fields[fieldIndex].type !== 9) return value;
        const date = value.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})/);
        return date ? `${date[3]}-${date[2].padStart(2, '0')}-${date[1].padStart(2, '0')}` : value;
      }).filter(Boolean);
    }
    function answersForCurrentRow() {
      const answers = {};
      fields.forEach((field, index) => {
        const values = preparedValuesForField(index);
        if (values.length) answers[field.entry] = field.multiple ? values : [values[0]];
      });
      return answers;
    }
    function prefilledUrl() {
      const url = new URL(payload.form.url);
      url.searchParams.set('usp', 'pp_url');
      selects.forEach((select, index) => {
        const values = preparedValuesForField(index);
        if (fields[index].multiple) values.forEach(value => url.searchParams.append(fields[index].entry, value));
        else if (values[0]) url.searchParams.set(fields[index].entry, values[0]);
      });
      return url.toString();
    }
    function go(index) { current = Math.max(0, Math.min(rows.length - 1, index)); render(); }
    function invalidateOpenedRows() {
      Object.keys(openedRows).forEach(rowIndex => delete openedRows[rowIndex]);
    }
    selects.forEach((select, index) => select.addEventListener('change', () => {
      invalidateOpenedRows(); saveMapping(); render();
    }));
    rowSelect.addEventListener('change', () => go(Number(rowSelect.value)));
    document.getElementById('previous-row').addEventListener('click', () => go(current - 1));
    document.getElementById('next-row').addEventListener('click', () => go(current + 1));
    document.getElementById('first-pending').addEventListener('click', () => { const index = rows.findIndex((_, row) => !sent[row]); go(index < 0 ? 0 : index); });
    document.getElementById('clear-sent').addEventListener('click', () => {
      if (!confirm('Xóa toàn bộ trạng thái đã gửi của file Excel này?')) return;
      sent = {}; localStorage.removeItem(storageKey); render();
    });
    confirmButton.addEventListener('click', () => {
      sent[current] = { method: 'manual', submitted_at: new Date().toISOString() };
      localStorage.setItem(storageKey, JSON.stringify(sent));
      if (current < rows.length - 1) go(current + 1); else render();
    });
    undoButton.addEventListener('click', () => {
      if (sent[current]?.method === 'direct' && !confirm('Câu trả lời đã gửi vẫn còn trên Google Forms. Bạn có chắc muốn cho phép gửi lại hàng này?')) return;
      delete sent[current];
      localStorage.setItem(storageKey, JSON.stringify(sent));
      render();
    });
    function canOpenCurrentForm() {
      const missing = fields.filter((field, index) => field.required && !effectiveValueForField(index).value.trim());
      const unmatched = fields.filter((field, index) => {
        const value = effectiveValueForField(index).value.trim();
        return value && !prepareFieldValue(field, value).matched;
      });
      const warnings = [];
      if (missing.length) warnings.push(`thiếu ${missing.length} trường bắt buộc`);
      if (unmatched.length) warnings.push(`${unmatched.length} giá trị chưa khớp lựa chọn Google`);
      return !warnings.length || confirm(`Hàng này ${warnings.join(' và ')}. Bạn sẽ cần chọn thủ công trên Form. Vẫn mở?`);
    }
    function directSubmissionProblem() {
      const missing = fields.filter((field, index) => field.required && !effectiveValueForField(index).value.trim());
      if (missing.length) {
        const labels = missing.slice(0, 4).map(field => `• ${field.label}`).join('\n');
        return `Chưa thể gửi vì còn thiếu ${missing.length} trường bắt buộc:\n${labels}`;
      }
      const unmatched = fields.filter((field, index) => {
        const value = effectiveValueForField(index).value.trim();
        return value && !prepareFieldValue(field, value).matched;
      });
      if (unmatched.length) {
        const labels = unmatched.slice(0, 4).map(field => `• ${field.label}`).join('\n');
        return `Chưa thể gửi vì ${unmatched.length} giá trị chưa khớp lựa chọn của Google Form:\n${labels}`;
      }
      return '';
    }
    directButton.addEventListener('click', async () => {
      const problem = directSubmissionProblem();
      if (problem) { alert(problem); return; }
      const rowIndex = current;
      if (!confirm(`Gửi dữ liệu của hàng Excel ${rowIndex + 2} lên Google Forms ngay bây giờ?\n\nCâu trả lời đã gửi sẽ không thể thu hồi từ LMS.`)) return;
      isSubmitting = true;
      render();
      const controller = new AbortController();
      const timeout = window.setTimeout(() => controller.abort(), 25000);
      try {
        const response = await fetch(window.location.pathname, {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({
            action: 'submit_row',
            csrf_token: csrfToken,
            form_url: payload.form.url,
            answers: answersForCurrentRow(),
          }),
          signal: controller.signal,
        });
        const data = await response.json().catch(() => null);
        if (!response.ok || !data?.ok) throw new Error(data?.message || 'Máy chủ không trả về kết quả hợp lệ.');
        sent[rowIndex] = { method: 'direct', submitted_at: new Date().toISOString() };
        localStorage.setItem(storageKey, JSON.stringify(sent));
        isSubmitting = false;
        render();
        document.getElementById('send-hint').innerHTML = '<span class="send-complete"><i class="bx bx-check-circle"></i> Đã gửi thành công và nhận phản hồi từ Google Forms.</span>';
      } catch (error) {
        isSubmitting = false;
        render();
        const message = error?.name === 'AbortError' ? 'Google Forms phản hồi quá lâu. Hàng này chưa được đánh dấu đã gửi; vui lòng thử lại hoặc mở Form để kiểm tra.' : (error?.message || 'Không thể gửi dữ liệu lên Google Forms.');
        const errorStatus = document.createElement('span'); errorStatus.className = 'preview-empty';
        const errorIcon = document.createElement('i'); errorIcon.className = 'bx bx-error-circle';
        errorStatus.append(errorIcon, document.createTextNode(' ' + message));
        document.getElementById('send-hint').replaceChildren(errorStatus);
      } finally {
        window.clearTimeout(timeout);
      }
    });
    function markCurrentRowOpened() {
      openedRows[current] = true;
      render();
    }
    openButton.addEventListener('click', () => {
      if (!canOpenCurrentForm()) return;
      const availableWidth = window.screen.availWidth || window.outerWidth || 1280;
      const availableHeight = window.screen.availHeight || window.outerHeight || 800;
      const availableLeft = Number.isFinite(window.screen.availLeft) ? window.screen.availLeft : Math.max(0, window.screenX || 0);
      const availableTop = Number.isFinite(window.screen.availTop) ? window.screen.availTop : 0;
      const popupWidth = Math.min(availableWidth, Math.max(480, Math.floor(availableWidth / 2)));
      const popupLeft = availableLeft + availableWidth - popupWidth;
      const features = `popup=yes,noopener,noreferrer,width=${popupWidth},height=${availableHeight},left=${popupLeft},top=${availableTop},resizable=yes,scrollbars=yes`;
      window.open(prefilledUrl(), '_blank', features);
      markCurrentRowOpened();
    });
    normalTabButton.addEventListener('click', () => {
      if (!canOpenCurrentForm()) return;
      window.open(prefilledUrl(), '_blank', 'noopener,noreferrer');
      markCurrentRowOpened();
    });
    initialiseMapping(); render();
  })();
  </script>
  <?php endif; ?>
  <script>
  (() => {
    const renameCsrfToken = <?php echo json_encode(csrfToken()); ?>;
    document.querySelectorAll('.saved-form').forEach(card => {
      const toggle = card.querySelector('.rename-preset-toggle');
      const panel = card.querySelector('.saved-form-rename');
      const input = card.querySelector('.rename-preset-input');
      const save = card.querySelector('.rename-preset-save');
      const cancel = card.querySelector('.rename-preset-cancel');
      const title = card.querySelector('.saved-form-title');
      if (!toggle || !panel || !input || !save || !cancel || !title) return;
      function closeEditor() {
        panel.hidden = true;
        toggle.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
        input.value = title.textContent.trim();
      }
      function openEditor() {
        panel.hidden = false;
        toggle.classList.add('is-open');
        toggle.setAttribute('aria-expanded', 'true');
        input.focus(); input.select();
      }
      toggle.setAttribute('aria-expanded', 'false');
      toggle.addEventListener('click', () => panel.hidden ? openEditor() : closeEditor());
      cancel.addEventListener('click', closeEditor);
      input.addEventListener('keydown', event => {
        if (event.key === 'Enter') { event.preventDefault(); save.click(); }
        if (event.key === 'Escape') { event.preventDefault(); closeEditor(); }
      });
      save.addEventListener('click', async () => {
        const nextTitle = input.value.trim();
        if (!nextTitle) { input.setCustomValidity('Vui lòng nhập tên dễ nhớ.'); input.reportValidity(); return; }
        input.setCustomValidity('');
        const originalButton = save.innerHTML;
        input.disabled = save.disabled = cancel.disabled = toggle.disabled = true;
        save.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Đang lưu";
        try {
          const response = await fetch(window.location.pathname, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ action: 'rename_preset', csrf_token: renameCsrfToken, preset_id: Number(card.dataset.presetId), form_title: nextTitle }),
          });
          const data = await response.json().catch(() => null);
          if (!response.ok || !data?.ok) throw new Error(data?.message || 'Không thể đổi tên link Form.');
          title.textContent = data.title; title.title = data.title; input.value = data.title;
          closeEditor();
        } catch (error) {
          alert(error?.message || 'Không thể đổi tên link Form.');
        } finally {
          input.disabled = save.disabled = cancel.disabled = toggle.disabled = false;
          save.innerHTML = originalButton;
        }
      });
    });
  })();
  </script>
</div>
<?php require_once '../includes/footer.php'; ?>
