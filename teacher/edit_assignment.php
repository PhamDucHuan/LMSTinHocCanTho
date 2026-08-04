<?php
require_once '../includes/security.php';
require_once '../includes/authorization.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/audit.php';
require_once '../includes/audit.php';

/** @var PDO $pdo */
if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('Không thể kết nối đến cơ sở dữ liệu.');
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['teacher', 'admin'])) {
    header('Location: ../index.php');
    exit;
}

$id = $_GET['id'] ?? null;
if (!$id) {
    header('Location: ' . ($_SESSION['user_role'] === 'admin' ? '../admin/assignments.php' : 'assignments.php'));
    exit;
}

$assignment = authorizationFindManageableAssignment($pdo, (int) $id, (string) $_SESSION['user_role'], (int) $_SESSION['user_id']);

if (!$assignment) {
    die("Bài tập không tồn tại hoặc bạn không có quyền sửa.");
}

$existingModuleSettings = json_decode($assignment['module_settings'] ?? '[]', true);
if (!is_array($existingModuleSettings)) $existingModuleSettings = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $title = $_POST['title'];
    $description = $_POST['description'];
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $category = $_POST['category'];
    $type = $_POST['type'];
    $duration_minutes = max(1, min(1440, (int) ($_POST['duration_minutes'] ?? 90)));
    
    $module_settings = [];
    if (isset($_POST['modules']) && is_array($_POST['modules'])) {
        foreach ($_POST['modules'] as $mod) {
            $score = isset($_POST["module_score_$mod"]) ? floatval($_POST["module_score_$mod"]) : 0;
            $existingModule = null;
            foreach ($existingModuleSettings as $setting) {
                if (($setting['module'] ?? '') === $mod) {
                    $existingModule = $setting;
                    break;
                }
            }
            if ($score > 0) {
                $module_settings[] = [
                    'module' => $mod,
                    'max_score' => $score,
                    'criteria' => $existingModule['criteria'] ?? '',
                    'solution_drive_id' => $existingModule['solution_drive_id'] ?? null,
                    'solution_file_name' => $existingModule['solution_file_name'] ?? null,
                    'rubric' => $existingModule['rubric'] ?? null
                ];
            }
        }
    }
    $module_settings_json = !empty($module_settings) ? json_encode($module_settings, JSON_UNESCAPED_UNICODE) : null;
    
    $update = $pdo->prepare("UPDATE assignments SET title = ?, description = ?, due_date = ?, category = ?, type = ?, duration_minutes = ?, module_settings = ? WHERE id = ?");
    $update->execute([$title, $description, $due_date, $category, $type, $duration_minutes, $module_settings_json, $id]);
    writeAuditLog($pdo, 'assignment.updated', 'assignment', (int) $id, [
        'before' => ['title' => $assignment['title'], 'due_date' => $assignment['due_date'], 'type' => $assignment['type']],
        'after' => ['title' => $title, 'due_date' => $due_date, 'type' => $type],
    ]);
    writeAuditLog($pdo, 'assignment.updated', 'assignment', (int) $id, [
        'before' => ['title' => $assignment['title'], 'due_date' => $assignment['due_date'], 'type' => $assignment['type']],
        'after' => ['title' => $title, 'due_date' => $due_date, 'type' => $type],
    ]);
    
    $_SESSION['success'] = "Cập nhật bài tập thành công!";
    header('Location: ' . ($_SESSION['user_role'] === 'admin' ? '../admin/assignments.php' : 'assignments.php'));
    exit;
}

$module_settings = json_decode($assignment['module_settings'] ?? '[]', true);
$selected_modules = [];
$module_scores = [];
if (is_array($module_settings)) {
    foreach ($module_settings as $m) {
        $selected_modules[] = $m['module'];
        $module_scores[$m['module']] = $m['max_score'];
    }
}

$page_title = "Chỉnh sửa bài tập";
require_once '../includes/header.php';
?>

        <div class="box" style="max-width: 800px; margin: 0 auto;">
            <form action="" method="POST">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label>Phần đã học (Chuyên đề)</label>
                        <input type="text" name="category" required value="<?php echo htmlspecialchars($assignment['category'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 0;">
                        <label>Loại bài</label>
                        <select name="type" id="assignment_type" required onchange="toggleModuleSelection()" style="width: 100%; padding: 12px 15px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1); background: rgba(15, 23, 42, 0.6); color: #fff; font-family: inherit; outline: none;">
                            <option value="assignment" <?php echo ($assignment['type'] ?? '') === 'assignment' ? 'selected' : ''; ?>>Bài tập thường (Chọn 1 Module)</option>
                            <option value="exam" <?php echo ($assignment['type'] ?? '') === 'exam' ? 'selected' : ''; ?>>Bài thi thử (Chọn nhiều Module)</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label>Cấu hình Module & Điểm Tối Đa *</label>
                    <div style="background: rgba(0,0,0,0.2); padding: 20px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1);">
                        <p style="font-size: 13px; color: var(--text-muted); margin-top: 0; margin-bottom: 15px;" id="module_hint">Bạn chỉ được phép chọn 1 module cho bài tập thường.</p>
                        
                        <div id="modules_container" style="display: flex; gap: 20px; flex-wrap: wrap;">
                            <?php 
                            $available_modules = ['Windows', 'Word', 'Excel', 'PowerPoint'];
                            foreach ($available_modules as $mod): 
                                $is_checked = in_array($mod, $selected_modules) ? 'checked' : '';
                                $score_val = isset($module_scores[$mod]) ? $module_scores[$mod] : '';
                                $display_style = $is_checked ? 'block' : 'none';
                            ?>
                                <div class="module-item" style="display: flex; align-items: center; gap: 10px; background: rgba(255,255,255,0.05); padding: 10px 15px; border-radius: 8px; min-width: 250px;">
                                    <input type="checkbox" name="modules[]" value="<?php echo $mod; ?>" id="mod_<?php echo $mod; ?>" class="mod-checkbox" onchange="toggleScoreInput('<?php echo $mod; ?>')" <?php echo $is_checked; ?>>
                                    <label for="mod_<?php echo $mod; ?>" style="margin: 0; min-width: 80px; font-weight: normal; cursor: pointer;"><?php echo $mod; ?></label>
                                    <input type="number" name="module_score_<?php echo $mod; ?>" id="score_<?php echo $mod; ?>" placeholder="Điểm tối đa" style="width: 110px; padding: 8px; display: <?php echo $display_style; ?>; border-radius: 4px; border: 1px solid rgba(255,255,255,0.1); background: #1e293b; color: #fff;" min="0.5" max="100" step="0.1" value="<?php echo $score_val; ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <script>
                function toggleModuleSelection() {
                    const type = document.getElementById('assignment_type').value;
                    const hint = document.getElementById('module_hint');
                    const checkboxes = document.querySelectorAll('.mod-checkbox');
                    
                    if (type === 'assignment') {
                        hint.innerText = "Bạn chỉ được phép chọn 1 module cho bài tập thường.";
                        let checkedCount = 0;
                        checkboxes.forEach(cb => {
                            if (cb.checked) {
                                checkedCount++;
                                if (checkedCount > 1) {
                                    cb.checked = false;
                                    toggleScoreInput(cb.value);
                                }
                            }
                        });
                    } else {
                        hint.innerText = "Bạn có thể chọn kết hợp nhiều module cho bài thi thử.";
                    }
                }

                document.querySelectorAll('.mod-checkbox').forEach(cb => {
                    cb.addEventListener('change', function() {
                        const type = document.getElementById('assignment_type').value;
                        if (type === 'assignment' && this.checked) {
                            // uncheck others
                            document.querySelectorAll('.mod-checkbox').forEach(other => {
                                if (other !== this) {
                                    other.checked = false;
                                    toggleScoreInput(other.value);
                                }
                            });
                        }
                        toggleScoreInput(this.value);
                    });
                });

                function toggleScoreInput(moduleName) {
                    const cb = document.getElementById(`mod_${moduleName}`);
                    const scoreInput = document.getElementById(`score_${moduleName}`);
                    if (cb.checked) {
                        scoreInput.style.display = 'block';
                        scoreInput.required = true;
                    } else {
                        scoreInput.style.display = 'none';
                        scoreInput.required = false;
                        scoreInput.value = '';
                    }
                }
                
                // Initialize hint on load
                window.addEventListener('DOMContentLoaded', toggleModuleSelection);
                </script>

                <div class="form-group">
                    <label>Tiêu đề bài tập</label>
                    <input type="text" name="title" required value="<?php echo htmlspecialchars($assignment['title']); ?>">
                </div>
                
                <div class="form-group">
                    <label>Mô tả chi tiết</label>
                    <textarea name="description" required rows="5"><?php echo htmlspecialchars($assignment['description']); ?></textarea>
                </div>
                
                <div class="form-group">
                    <label>Hạn nộp (Để trống nếu không giới hạn thời gian)</label>
                    <input type="datetime-local" name="due_date" value="<?php echo $assignment['due_date'] ? date('Y-m-d\TH:i', strtotime($assignment['due_date'])) : ''; ?>">
                </div>

                <div class="form-group">
                    <label>Thời gian làm bài thi (phút)</label>
                    <input type="number" name="duration_minutes" value="<?php echo (int) ($assignment['duration_minutes'] ?? 90); ?>" min="1" max="1440" required>
                    <small style="color:var(--text-muted);">Mỗi lượt thi bắt đầu đếm riêng khi học viên bấm bắt đầu.</small>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;"><i class='bx bx-save'></i> Lưu thay đổi</button>
            </form>
        </div>

<?php require_once '../includes/footer.php'; ?>
