<?php
require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/drive_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

$id = $_GET['id'] ?? null;
if (!$id) {
    header('Location: dashboard.php');
    exit;
}

// Fetch assignment
$stmt = $pdo->prepare("SELECT * FROM assignments WHERE id = ?");
$stmt->execute([$id]);
$assignment = $stmt->fetch();

if (!$assignment) {
    die("Bài tập không tồn tại.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $title = $_POST['title'];
    $description = $_POST['description'];
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $category = $_POST['category'];
    $type = $_POST['type'];
    $duration_minutes = max(1, min(1440, (int) ($_POST['duration_minutes'] ?? 90)));
    // Thư mục lưu trữ
    $assignment_folder = ['LMS_Uploads', 'Admin_' . $_SESSION['user_id'], 'Bai_Tap_Edit_' . time()];

    $module_settings = [];
    if (isset($_POST['modules']) && is_array($_POST['modules'])) {
        foreach ($_POST['modules'] as $mod) {
            $score = isset($_POST["module_score_$mod"]) ? floatval($_POST["module_score_$mod"]) : 0;
            $criteria = isset($_POST["module_criteria_$mod"]) ? trim($_POST["module_criteria_$mod"]) : "";
            $solution_drive_id = $_POST["module_solution_old_$mod"] ?? null;
            $solution_file_name = $_POST["module_solution_name_old_$mod"] ?? null;
            if (isset($_FILES["module_solution_$mod"]) && $_FILES["module_solution_$mod"]['error'] === 0) {
                $file_tmp = $_FILES["module_solution_$mod"]['tmp_name'];
                $original_name = $_FILES["module_solution_$mod"]['name'];
                $file_name = time() . '_modsol_' . $mod . '_' . $original_name;
                try {
                    $solution_drive_id = uploadToDrive($file_tmp, $file_name, $assignment_folder);
                    $solution_file_name = $original_name;
                } catch (Exception $e) {
                    // Ignore
                }
            }
            
            if ($score > 0) {
                $module_settings[] = [
                    'module' => $mod,
                    'max_score' => $score,
                    'criteria' => $criteria,
                    'solution_drive_id' => $solution_drive_id,
                    'solution_file_name' => $solution_file_name
                ];
            }
        }
    }
    $module_settings_json = !empty($module_settings) ? json_encode($module_settings, JSON_UNESCAPED_UNICODE) : null;
    
    // Update prompt file if uploaded
    $prompt_file_drive_id = $assignment['prompt_file_drive_id'];
    $prompt_file_name = $assignment['prompt_file_name'];
    if (isset($_FILES['prompt_file']) && $_FILES['prompt_file']['error'] === 0) {
        $file_tmp = $_FILES['prompt_file']['tmp_name'];
        $original_name = $_FILES['prompt_file']['name'];
        $file_name = time() . '_prompt_' . $original_name;
        try {
            $prompt_file_drive_id = uploadToDrive($file_tmp, $file_name, $assignment_folder);
            $prompt_file_name = $original_name;
        } catch (Exception $e) {}
    }

    $solution_file_drive_id = null;
    $solution_file_name = null;

    $update = $pdo->prepare("UPDATE assignments SET title = ?, description = ?, due_date = ?, category = ?, type = ?, duration_minutes = ?, module_settings = ?, prompt_file_drive_id = ?, prompt_file_name = ?, solution_file_drive_id = ?, solution_file_name = ? WHERE id = ?");
    $update->execute([$title, $description, $due_date, $category, $type, $duration_minutes, $module_settings_json, $prompt_file_drive_id, $prompt_file_name, $solution_file_drive_id, $solution_file_name, $id]);
    
    $_SESSION['success'] = "Cập nhật bài tập thành công!";
    header('Location: dashboard.php');
    exit;
}

$module_settings = json_decode($assignment['module_settings'] ?? '[]', true);
$selected_modules = [];
$module_scores = [];
$module_criteria = [];
$module_images = [];
$module_solutions = [];
$module_solution_names = [];
if (is_array($module_settings)) {
    foreach ($module_settings as $m) {
        $selected_modules[] = $m['module'];
        $module_scores[$m['module']] = $m['max_score'];
        $module_criteria[$m['module']] = $m['criteria'] ?? '';
        $module_solutions[$m['module']] = $m['solution_drive_id'] ?? null;
        $module_solution_names[$m['module']] = $m['solution_file_name'] ?? null;
    }
}

$page_title = "Chỉnh sửa bài tập";
require_once '../includes/header.php';
?>

        <div class="box" style="max-width: 800px; margin: 0 auto;">
            <form action="" method="POST" enctype="multipart/form-data">
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
                                $criteria_val = isset($module_criteria[$mod]) ? htmlspecialchars($module_criteria[$mod]) : '';
                                $solution_val = isset($module_solutions[$mod]) ? $module_solutions[$mod] : '';
                                $solution_name_val = isset($module_solution_names[$mod]) ? htmlspecialchars($module_solution_names[$mod]) : '';
                                $display_style = $is_checked ? 'block' : 'none';
                                $extra_style = $is_checked ? 'flex' : 'none';
                            ?>
                                <div class="module-item" style="display: flex; flex-direction: column; gap: 10px; background: rgba(255,255,255,0.05); padding: 15px; border-radius: 8px; width: 100%;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <input type="checkbox" name="modules[]" value="<?php echo $mod; ?>" id="mod_<?php echo $mod; ?>" class="mod-checkbox" onchange="toggleScoreInput('<?php echo $mod; ?>')" <?php echo $is_checked; ?>>
                                        <label for="mod_<?php echo $mod; ?>" style="margin: 0; min-width: 100px; font-weight: bold; cursor: pointer;"><?php echo $mod; ?></label>
                                        <input type="number" name="module_score_<?php echo $mod; ?>" id="score_<?php echo $mod; ?>" placeholder="Điểm tối đa" style="width: 150px; padding: 8px; display: <?php echo $display_style; ?>; border-radius: 4px; border: 1px solid rgba(255,255,255,0.1); background: #1e293b; color: #fff;" min="0.5" max="100" step="0.1" value="<?php echo $score_val; ?>">
                                    </div>
                                    <div id="extra_<?php echo $mod; ?>" style="display: <?php echo $extra_style; ?>; flex-direction: column; gap: 10px; margin-left: 25px; margin-top: 10px;">
                                        <textarea name="module_criteria_<?php echo $mod; ?>" rows="3" placeholder="Nhập tiêu chí chấm điểm thủ công (mỗi tiêu chí 1 dòng)..." style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid rgba(255,255,255,0.1); background: rgba(0,0,0,0.2); color: #fff;"><?php echo $criteria_val; ?></textarea>

                                        <div>
                                            <label style="font-size: 13px; color: var(--text-muted);">File Bài Làm Mẫu cho phần này (Tùy chọn):</label>
                                            <?php if ($solution_val): ?>
                                                <div style="margin-bottom: 5px;">
                                                    <span style="font-size: 12px; color: #10b981;"><i class='bx bx-check-shield'></i> Đã có: <?php echo $solution_name_val; ?></span>
                                                    <input type="hidden" name="module_solution_old_<?php echo $mod; ?>" value="<?php echo htmlspecialchars($solution_val); ?>">
                                                    <input type="hidden" name="module_solution_name_old_<?php echo $mod; ?>" value="<?php echo $solution_name_val; ?>">
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="module_solution_<?php echo $mod; ?>" accept=".doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.rar,.7z" style="display: block; font-size: 13px; margin-top: 5px;">
                                        </div>
                                    </div>
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
                    const extraDiv = document.getElementById(`extra_${moduleName}`);
                    if (cb.checked) {
                        scoreInput.style.display = 'block';
                        scoreInput.required = true;
                        extraDiv.style.display = 'flex';
                    } else {
                        scoreInput.style.display = 'none';
                        scoreInput.required = false;
                        scoreInput.value = '';
                        extraDiv.style.display = 'none';
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
                    <small style="color:var(--text-muted);">Mặc định 90 phút, tính riêng từ lúc mỗi học viên bắt đầu.</small>
                </div>

                    <div style="grid-column: span 2;">
                        <label>File Đề Bài (Cập nhật - Tùy chọn)</label>
                        <?php if ($assignment['prompt_file_name']): ?>
                            <p style="font-size: 13px; color: #38bdf8; margin-top: 0;"><i class='bx bxs-file'></i> Đang có: <?php echo htmlspecialchars($assignment['prompt_file_name']); ?></p>
                        <?php endif; ?>
                        <input type="file" name="prompt_file" accept=".doc,.docx,.pdf" style="width: 100%; padding: 10px; background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.1); border-radius: 4px; color: #fff;">
                    </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;"><i class='bx bx-save'></i> Lưu thay đổi</button>
            </form>
        </div>

<?php require_once '../includes/footer.php'; ?>
