<?php
/** @var array $classes Classes visible in the current schedule. */
/** @var array $progressExportContext Scope filters supplied by the schedule screen. */
$progressExportContext = $progressExportContext ?? [];
?>
<style>
.teaching-progress-export{margin:0 0 18px;padding:16px;border:1px solid var(--border-color);border-radius:12px;background:var(--glass-bg)}
.teaching-progress-export summary{cursor:pointer;font-weight:800;color:var(--text-main)}
.teaching-progress-export form{display:grid;gap:12px;margin-top:14px}
.teaching-progress-export .export-controls{display:flex;align-items:end;flex-wrap:wrap;gap:12px}
.teaching-progress-export .export-field{display:grid;gap:6px;min-width:200px;margin:0;font-size:13px}
.teaching-progress-export select{width:100%;min-height:42px}
.teaching-progress-export .export-class-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:8px;max-height:240px;overflow:auto;margin-top:10px;padding:2px}
.teaching-progress-export .export-check{display:flex;align-items:center;gap:8px;margin:0;padding:8px;border:1px solid var(--border-color);border-radius:8px;cursor:pointer;font-size:13px}
.teaching-progress-export input[type=checkbox]{width:17px;height:17px;min-height:0;flex-shrink:0;margin:0;accent-color:var(--primary)}
.teaching-progress-export .export-check span{overflow-wrap:anywhere}
.teaching-progress-export .export-check small{display:block;color:var(--text-muted)}
.teaching-progress-export [hidden]{display:none!important}
.teaching-progress-export .export-note,.teaching-progress-export .export-count{margin:0;color:var(--text-muted);font-size:13px;line-height:1.5}
.teaching-progress-export .export-submit{min-height:42px;display:inline-flex;align-items:center;gap:6px}
.teaching-progress-export .export-submit:disabled{opacity:.45;cursor:not-allowed}
@media(max-width:600px){.teaching-progress-export .export-field,.teaching-progress-export .export-submit{width:100%}.teaching-progress-export .export-class-list{grid-template-columns:1fr}}
</style>
<details class="teaching-progress-export" id="teaching-progress-export">
    <summary><i class='bx bx-spreadsheet'></i> Xuất Excel tiến độ học tập</summary>
    <form method="get" action="export_teaching_progress.php">
        <?php foreach ($progressExportContext as $key => $value): ?>
            <input type="hidden" name="<?php echo htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); ?>">
        <?php endforeach; ?>
        <p class="export-note">File có trang tổng hợp và trang riêng từng lớp. Mỗi học viên một dòng, ngày Có học xếp ngang theo B1, B2… (gồm học bù), cuối dòng là tổng số buổi đã học / tổng buổi.</p>
        <div class="export-controls">
            <label class="export-field">Phạm vi xuất
                <select name="mode">
                    <option value="all">Tất cả lớp trong danh sách (<?php echo count($classes); ?>)</option>
                    <option value="single">Một lớp</option>
                    <option value="selected">Các lớp đã chọn</option>
                </select>
            </label>
            <label class="export-field" data-export-single hidden>Chọn lớp
                <select name="class_id" disabled>
                    <option value="">-- Chọn một lớp --</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo (int) $class['id']; ?>"><?php echo htmlspecialchars($class['class_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="btn btn-outline export-submit" <?php echo !$classes ? 'disabled' : ''; ?>><i class='bx bx-download'></i> Tải Excel tiến độ</button>
        </div>
        <div data-export-selected hidden>
            <label class="export-check"><input type="checkbox" data-export-select-all> Chọn tất cả lớp trong danh sách</label>
            <div class="export-class-list">
                <?php foreach ($classes as $class): ?>
                    <label class="export-check">
                        <input type="checkbox" name="class_ids[]" value="<?php echo (int) $class['id']; ?>" disabled>
                        <span><?php echo htmlspecialchars($class['class_name'], ENT_QUOTES, 'UTF-8'); ?><small><?php echo htmlspecialchars(($class['teacher_name'] ?? $selectedTeacher['name'] ?? '') ?: 'Chưa phân công giáo viên', ENT_QUOTES, 'UTF-8'); ?></small></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="export-count" aria-live="polite">Đã chọn 0 lớp.</p>
        </div>
        <?php if (!$classes): ?><p class="export-note">Chưa có lớp để xuất trong danh sách hiện tại.</p><?php endif; ?>
    </form>
</details>
<script>
(() => {
    const panel = document.getElementById('teaching-progress-export');
    const form = panel.querySelector('form');
    const mode = form.elements.mode;
    const single = form.querySelector('[data-export-single]');
    const selected = form.querySelector('[data-export-selected]');
    const classSelect = form.elements.class_id;
    const checkboxes = [...form.querySelectorAll('input[name="class_ids[]"]')];
    const selectAll = form.querySelector('[data-export-select-all]');
    const submit = form.querySelector('button[type=submit]');
    const hasClasses = classSelect.options.length > 1;
    const update = () => {
        const isSingle = mode.value === 'single';
        const isSelected = mode.value === 'selected';
        single.hidden = !isSingle;
        classSelect.disabled = !isSingle;
        classSelect.required = isSingle;
        selected.hidden = !isSelected;
        checkboxes.forEach(checkbox => { checkbox.disabled = !isSelected; });
        const count = checkboxes.filter(checkbox => checkbox.checked).length;
        selectAll.checked = checkboxes.length > 0 && count === checkboxes.length;
        selectAll.indeterminate = count > 0 && count < checkboxes.length;
        form.querySelector('.export-count').textContent = 'Đã chọn ' + count + ' lớp.';
        submit.disabled = !hasClasses || (isSingle && !classSelect.value) || (isSelected && count === 0);
    };
    selectAll.addEventListener('change', () => {
        checkboxes.forEach(checkbox => { checkbox.checked = selectAll.checked; });
        update();
    });
    form.addEventListener('change', update);
    update();
})();
</script>
