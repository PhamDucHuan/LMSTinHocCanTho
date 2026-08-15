<?php
require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/drive_helper.php';
require_once '../includes/notifications.php';
require_once '../includes/audit.php';
require_once '../includes/friendly_urls.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['teacher', 'administrative_staff', 'admin'], true)) {
    header('Location: ../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $category = trim($_POST['category']);
    $type = $_POST['type'];
    $duration_minutes = max(1, min(1440, (int) ($_POST['duration_minutes'] ?? 90)));
    $course_id = $_POST['course_id'];
    $courseCheck = $_SESSION['user_role'] === 'admin'
        ? $pdo->prepare("SELECT id FROM courses WHERE id = ?")
        : $pdo->prepare("SELECT id FROM courses WHERE id = ? AND teacher_id = ?");
    $courseCheck->execute($_SESSION['user_role'] === 'admin' ? [$course_id] : [$course_id, $_SESSION['user_id']]);
    if (!$courseCheck->fetch()) {
        $error = 'Khóa học không hợp lệ.';
    }
    
    // Thư mục lưu trữ theo cấu trúc: LMS_Uploads / Giao_Vien_<ID> / Bai_Tap_<Timestamp>
    $assignment_folder = ['LMS_Uploads', 'Teacher_' . $_SESSION['user_id'], 'Bai_Tap_' . time()];
    
    $module_settings = [];
    if (isset($_POST['modules']) && is_array($_POST['modules'])) {
        foreach (array_intersect($_POST['modules'], ['Windows', 'Word', 'Excel', 'PowerPoint']) as $mod) {
            $score = isset($_POST["module_score_$mod"]) ? floatval($_POST["module_score_$mod"]) : 0;
            $criteria = isset($_POST["module_criteria_$mod"]) ? trim($_POST["module_criteria_$mod"]) : "";
            
            $solution_drive_id = null;
            $solution_file_name = null;
            if (isset($_FILES["module_solution_$mod"]) && $_FILES["module_solution_$mod"]['error'] === 0) {
                try {
                    $valid = validateUploadedFile($_FILES["module_solution_$mod"], ['doc','docx','xls','xlsx','ppt','pptx','zip','rar','7z']);
                    $file_tmp = $valid['tmp_name'];
                    $original_name = $valid['original_name'];
                    $file_name = bin2hex(random_bytes(12)) . '.' . $valid['extension'];
                    $solution_drive_id = uploadToDrive($file_tmp, $file_name, $assignment_folder);
                    $solution_file_name = $original_name;
                } catch (Exception $e) {
                    $error = "Lỗi upload bài mẫu cho phần $mod: " . $e->getMessage();
                }
            }
            if ($score > 0 && !$solution_drive_id) {
                $error = "Vui lòng tải file bài làm mẫu/đáp án cho phần $mod để AI có dữ liệu đối chiếu.";
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
    
    $prompt_file_drive_id = null;
    $prompt_file_name = null;
    if (isset($_FILES['prompt_file']) && $_FILES['prompt_file']['error'] === 0) {
        try {
            $valid = validateUploadedFile($_FILES['prompt_file'], ['doc','docx','pdf']);
            $file_tmp = $valid['tmp_name'];
            $original_name = $valid['original_name'];
            $file_name = bin2hex(random_bytes(12)) . '.' . $valid['extension'];
            $prompt_file_drive_id = uploadToDrive($file_tmp, $file_name, $assignment_folder);
            $prompt_file_name = $original_name;
        } catch (Exception $e) {
            $error = 'Lỗi upload Đề bài: ' . $e->getMessage();
        }
    }

    $solution_file_drive_id = null;
    $solution_file_name = null;

    $attachments = [];
    if (isset($_FILES['resource_files']) && !isset($error)) {
        $file_count = count($_FILES['resource_files']['name']);
        for ($i = 0; $i < $file_count; $i++) {
            if ($_FILES['resource_files']['error'][$i] === 0) {
                try {
                    $singleFile = [
                        'name' => $_FILES['resource_files']['name'][$i], 'tmp_name' => $_FILES['resource_files']['tmp_name'][$i],
                        'error' => $_FILES['resource_files']['error'][$i], 'size' => $_FILES['resource_files']['size'][$i]
                    ];
                    $valid = validateUploadedFile($singleFile, ['doc','docx','xls','xlsx','ppt','pptx','pdf','zip','rar','7z','jpg','jpeg','png','gif','webp','bmp']);
                    $file_tmp = $valid['tmp_name'];
                    $original_name = $valid['original_name'];
                    $file_name = bin2hex(random_bytes(12)) . '.' . $valid['extension'];
                    $drive_id = uploadToDrive($file_tmp, $file_name, $assignment_folder);
                    $attachments[] = [
                        'name' => $original_name,
                        'drive_id' => $drive_id
                    ];
                } catch (Exception $e) {
                    $error = 'Lỗi upload File dữ liệu: ' . $e->getMessage();
                    break;
                }
            }
        }
    }
    $attachments_json = !empty($attachments) ? json_encode($attachments, JSON_UNESCAPED_UNICODE) : null;
    
    if (!isset($error)) {
        $priorityStmt = $pdo->prepare('SELECT COALESCE(MAX(priority_order), 0) + 1 FROM assignments WHERE course_id = ? AND type = ?');
        $priorityStmt->execute([(int) $course_id, $type]);
        $priorityOrder = (int) $priorityStmt->fetchColumn();
        $stmt = $pdo->prepare("INSERT INTO assignments (teacher_id, title, slug, description, prompt_file_drive_id, prompt_file_name, solution_file_drive_id, solution_file_name, due_date, category, type, duration_minutes, attachments, course_id, module_settings, priority_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$_SESSION['user_id'], $title, uniqueFriendlySlug($pdo, 'assignments', $title), $description, $prompt_file_drive_id, $prompt_file_name, $solution_file_drive_id, $solution_file_name, $due_date, $category, $type, $duration_minutes, $attachments_json, $course_id, $module_settings_json, $priorityOrder])) {
            $assignmentId = (int) $pdo->lastInsertId();
            $studentStmt = $pdo->prepare('SELECT student_id FROM course_enrollments WHERE course_id = ?');
            $studentStmt->execute([(int) $course_id]);
            foreach ($studentStmt->fetchAll(PDO::FETCH_COLUMN) as $enrolledStudentId) {
                createNotification(
                    $pdo,
                    (int) $enrolledStudentId,
                    $type === 'exam' ? 'exam_created' : 'assignment_created',
                    $type === 'exam' ? 'Có bài thi mới' : 'Có bài tập mới',
                    "“{$title}” đã được giao" . ($due_date ? ', hạn nộp ' . date('d/m/Y H:i', strtotime($due_date)) : '') . '.',
                    '../student/assignment.php?id=' . $assignmentId,
                    ['assignment_id' => $assignmentId, 'course_id' => (int) $course_id]
                );
            }
            writeAuditLog($pdo, 'assignment.created', 'assignment', $assignmentId, [
                'course_id' => (int) $course_id,
                'type' => $type,
            ]);
            $_SESSION['success'] = 'Giao bài tập thành công!';
            header('Location: ' . ($_SESSION['user_role'] === 'admin' ? '../admin/assignments.php' : 'assignments.php'));
            exit;
        } else {
            $error = 'Có lỗi xảy ra. Vui lòng thử lại.';
        }
    }
}

// Fetch courses for dropdown
if ($_SESSION['user_role'] === 'admin') {
    $stmt = $pdo->prepare("SELECT * FROM courses ORDER BY created_at DESC");
    $stmt->execute();
} else {
    $stmt = $pdo->prepare("SELECT * FROM courses WHERE teacher_id = ? ORDER BY created_at DESC");
    $stmt->execute([$_SESSION['user_id']]);
}
$courses = $stmt->fetchAll();

$page_title = "Tạo Bài Tập Mới";
require_once '../includes/header.php';
?>
        <div class="box" style="max-width: 800px; margin: 0 auto;">
            <?php if(isset($error)): ?>
                <div style="background: rgba(239, 68, 68, 0.2); color: #fca5a5; padding: 10px; border-radius: 8px; margin-bottom: 20px;">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <form action="" method="POST" enctype="multipart/form-data">
                <?php echo csrfField(); ?>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label>Phần đã học (Chuyên đề)</label>
                        <input type="text" name="category" required placeholder="VD: Khóa Cơ Bản, Chuyên đề 1...">
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 0;">
                        <label>Loại bài</label>
                        <select name="type" id="assignment_type" required onchange="toggleModuleSelection()" style="width: 100%; padding: 12px 15px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1); background: rgba(15, 23, 42, 0.6); color: #fff; font-family: inherit; outline: none;">
                            <option value="assignment">Bài tập thường (Chọn 1 Module)</option>
                            <option value="exam">Bài thi thử (Chọn nhiều Module)</option>
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
                            ?>
                                <div class="module-item" style="display: flex; flex-direction: column; gap: 10px; background: rgba(255,255,255,0.05); padding: 15px; border-radius: 8px; width: 100%;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <input type="checkbox" name="modules[]" value="<?php echo $mod; ?>" id="mod_<?php echo $mod; ?>" class="mod-checkbox" onchange="toggleScoreInput('<?php echo $mod; ?>')">
                                        <label for="mod_<?php echo $mod; ?>" style="margin: 0; min-width: 100px; font-weight: bold; cursor: pointer;"><?php echo $mod; ?></label>
                                        <input type="number" name="module_score_<?php echo $mod; ?>" id="score_<?php echo $mod; ?>" placeholder="Điểm tối đa" style="width: 150px; padding: 8px; display: none; border-radius: 4px; border: 1px solid rgba(255,255,255,0.1); background: #1e293b; color: #fff;" min="0.5" max="100" step="0.1">
                                    </div>
                                    <div id="extra_<?php echo $mod; ?>" style="display: none; flex-direction: column; gap: 10px; margin-left: 25px; margin-top: 10px;">
                                        <textarea name="module_criteria_<?php echo $mod; ?>" rows="3" placeholder="Nhập tiêu chí chấm điểm thủ công (mỗi tiêu chí 1 dòng)..." style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid rgba(255,255,255,0.1); background: rgba(0,0,0,0.2); color: #fff;"></textarea>

                                        <div>
                                            <label style="font-size: 13px; color: var(--text-muted);">File Bài Làm Mẫu/Đáp Án để AI đối chiếu *:</label>
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
                    const solutionInput = document.querySelector(`[name="module_solution_${moduleName}"]`);
                    if (cb.checked) {
                        scoreInput.style.display = 'block';
                        scoreInput.required = true;
                        solutionInput.required = true;
                        extraDiv.style.display = 'flex';
                    } else {
                        scoreInput.style.display = 'none';
                        scoreInput.required = false;
                        scoreInput.value = '';
                        solutionInput.required = false;
                        solutionInput.value = '';
                        extraDiv.style.display = 'none';
                    }
                }
                </script>

                <div class="grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label>Tên bài tập</label>
                        <input type="text" name="title" required placeholder="VD: Bài tập Word cơ bản 01">
                    </div>
                    
                    <div class="form-group">
                        <label>Khóa học *</label>
                        <select name="course_id" required style="width: 100%; padding: 12px 15px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1); background: rgba(15, 23, 42, 0.6); color: #fff; font-family: inherit; outline: none;">
                            <option value="">-- Chọn Khóa học --</option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Mô tả chi tiết yêu cầu</label>
                    <textarea name="description" rows="5" placeholder="Nhập các yêu cầu cho bài tập này..."></textarea>
                </div>
                
                <div class="form-group">
                    <label>Hạn nộp bài (Để trống nếu không giới hạn thời gian)</label>
                    <input type="datetime-local" name="due_date">
                </div>

                <div class="form-group">
                    <label>Thời gian làm bài thi (phút)</label>
                    <input type="number" name="duration_minutes" value="90" min="1" max="1440" required>
                    <small style="color:var(--text-muted);">Mặc định 90 phút. Đồng hồ bắt đầu khi học viên bấm “Bắt đầu làm bài”.</small>
                </div>
                
                <style>
                    .assignment-upload-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;margin-bottom:20px;align-items:start}
                    .assignment-upload-field{display:grid;grid-template-rows:auto auto;align-content:start;min-width:0}
                    .assignment-upload-field>label{display:flex;align-items:flex-end;min-height:28px;margin-bottom:8px}
                    .assignment-upload-field>.file-upload{display:flex;flex-direction:column;align-items:center;justify-content:center;width:100%;height:210px;padding:24px!important;box-sizing:border-box;overflow:auto}
                    .assignment-upload-field>.file-upload p{margin:12px 0 5px}
                    .assignment-upload-field>.file-upload>div{width:100%;max-height:58px;overflow:auto;box-sizing:border-box}
                    @media(max-width:700px){.assignment-upload-grid{grid-template-columns:1fr}.assignment-upload-field>.file-upload{height:190px}}
                </style>
                <div class="assignment-upload-grid">
                    <!-- ĐỀ BÀI -->
                    <div class="assignment-upload-field">
                        <label>File Đề Bài (PDF, Word) *</label>
                        <div class="file-upload" onclick="document.getElementById('prompt_file').click()" style="border: 2px dashed rgba(56, 189, 248, 0.4); padding: 30px; text-align: center; border-radius: 8px; cursor: pointer; transition: 0.3s; background: rgba(56, 189, 248, 0.05);">
                            <i class='bx bx-cloud-upload' style="font-size: 40px; color: #38bdf8;"></i>
                            <p style="font-size: 14px;">Click để tải Đề Bài lên</p>
                            <div id="prompt-file-name-display" style="color: #38bdf8; font-weight: 600; text-align: center; padding: 5px;"></div>
                        </div>
                        <input type="file" id="prompt_file" name="prompt_file" required style="display: none;" accept=".doc,.docx,.pdf" onchange="updatePromptFile(this)">
                        
                        <div id="ai-extract-container" style="display: none; margin-top: 10px; text-align: center;">
                            <button type="button" class="btn" style="background: rgba(99, 102, 241, 0.1); color: var(--primary); border: 1px solid var(--primary); width: 100%;" onclick="extractRequirements()">
                                <i class='bx bx-bot'></i> Quét AI lấy yêu cầu
                            </button>
                            <div id="extract-loading" style="display: none; color: var(--text-muted); font-size: 13px; margin-top: 5px;">
                                <i class='bx bx-loader-alt bx-spin'></i> Đang dùng AI để đọc...
                            </div>
                        </div>
                    </div>
                    


                    <!-- DỮ LIỆU -->
                    <div class="assignment-upload-field">
                        <label>Các File Thực Hành (Tùy chọn)</label>
                        <div class="file-upload" onclick="document.getElementById('resource_files').click()" style="border: 2px dashed rgba(255,255,255,0.2); padding: 30px; text-align: center; border-radius: 8px; cursor: pointer; transition: 0.3s; background: rgba(255,255,255,0.02);">
                            <i class='bx bx-copy-alt' style="font-size: 40px; color: var(--text-muted);"></i>
                            <p style="font-size: 14px; color: var(--text-muted);">Tải các file đính kèm cho HS</p>
                            <div id="resource-files-name-display" style="color: #e2e8f0; font-weight: 500; text-align: left; padding: 5px; font-size: 13px;"></div>
                        </div>
                        <input type="file" id="resource_files" name="resource_files[]" multiple style="display: none;" accept=".doc,.docx,.xls,.xlsx,.ppt,.pptx,.pdf,.zip,.rar,.7z,.jpg,.jpeg,.png,.gif,.webp,.bmp,image/*" onchange="updateResourceFiles(this)">
                    </div>
                </div>
                
                <div id="local-preview-container" style="display: none; margin-bottom: 20px;">
                    <label>Xem trước file Đề Bài</label>
                    <iframe id="local-preview-iframe" style="width: 100%; height: 500px; border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; background: #fff;" src=""></iframe>
                </div>
                
                <script>
                    function updatePromptFile(input) {
                        const display = document.getElementById('prompt-file-name-display');
                        const extractContainer = document.getElementById('ai-extract-container');
                        const previewContainer = document.getElementById('local-preview-container');
                        const previewIframe = document.getElementById('local-preview-iframe');
                        
                        display.innerHTML = '';
                        if (input.files.length > 0) {
                            let file = input.files[0];
                            display.innerHTML = `<i class='bx bxs-file'></i> ${file.name}`;
                            extractContainer.style.display = 'block';
                            
                            if (file.type === 'application/pdf') {
                                previewContainer.style.display = 'block';
                                previewIframe.src = URL.createObjectURL(file);
                            } else {
                                previewContainer.style.display = 'none';
                                previewIframe.src = '';
                            }
                        } else {
                            extractContainer.style.display = 'none';
                            previewContainer.style.display = 'none';
                            previewIframe.src = '';
                        }
                    }
                    
                    function updateResourceFiles(input) {
                        const display = document.getElementById('resource-files-name-display');
                        display.innerHTML = '';
                        if (input.files.length > 0) {
                            display.innerHTML = '<ul style="margin:0; padding-left:20px;">';
                            for (let i = 0; i < input.files.length; i++) {
                                display.innerHTML += `<li>${input.files[i].name}</li>`;
                            }
                            display.innerHTML += '</ul>';
                        }
                    }


                    function extractRequirements() {
                        const input = document.getElementById('prompt_file');
                        if (input.files.length === 0) return;
                        
                        const file = input.files[0];
                        const formData = new FormData();
                        formData.append('prompt_file', file);
                        
                        document.getElementById('extract-loading').style.display = 'block';
                        
                        fetch('ajax_extract_requirements.php', {
                            method: 'POST',
                            headers: {'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content},
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            document.getElementById('extract-loading').style.display = 'none';
                            if (data.status === 'success') {
                                document.querySelector('textarea[name="description"]').value = data.requirements;
                            } else {
                                alert("Lỗi: " + data.message);
                            }
                        })
                        .catch(err => {
                            document.getElementById('extract-loading').style.display = 'none';
                            alert("Đã xảy ra lỗi khi gọi AI.");
                        });
                    }
                </script>
                
                <div style="text-align: right;">
                    <a href="dashboard.php" class="btn btn-outline" style="margin-right: 10px;">Hủy</a>
                    <button type="submit" class="btn btn-primary"><i class='bx bx-check'></i> Tạo bài tập</button>
                </div>
            </form>
        </div>

<?php require_once '../includes/footer.php'; ?>
