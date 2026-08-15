<?php
require_once '../includes/security.php';
require_once '../includes/authorization.php';
secureSessionStart();
// Tăng thời gian thực thi tối đa lên 5 phút (300 giây) để tránh lỗi Fatal Error khi đợi AI
set_time_limit(600);
require_once '../config/database.php';
require_once '../includes/drive_helper.php';
require_once '../includes/friendly_urls.php';
require_once '../includes/grading_queue.php';
/** @var PDO $pdo */
ensureFriendlyUrls($pdo);

$isAjaxRequest = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
if ($isAjaxRequest) {
    ini_set('display_errors', '0');
    set_exception_handler(static function (Throwable $error): void {
        error_log('Assignment AJAX error: ' . $error);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'message' => $error instanceof AiGradingUnavailableException
                ? $error->getMessage()
                : 'Máy chủ gặp lỗi khi lưu dữ liệu. Vui lòng thử lại.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    });
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['student', 'teacher', 'administrative_staff', 'admin'], true)) {
    header('Location: ../index.php');
    exit;
}
$previewMode = in_array($_SESSION['user_role'], ['teacher', 'administrative_staff'], true);
$adminTestMode = $_SESSION['user_role'] === 'admin';

function allowedExtensionsForModule(string $moduleName): array
{
    return match (strtolower(trim($moduleName))) {
        'word' => ['doc', 'docx'],
        'excel' => ['xls', 'xlsx'],
        'powerpoint', 'power point' => ['ppt', 'pptx'],
        'windows' => ['zip', 'rar', '7z'],
        default => ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'pdf'],
    };
}

function acceptAttributeForModule(string $moduleName): string
{
    return implode(',', array_map(
        static fn(string $extension): string => '.' . $extension,
        allowedExtensionsForModule($moduleName)
    ));
}

function assertArchiveContainsNoBlockedFiles(string $filePath, string $extension): void
{
    if ($extension !== 'zip' || !class_exists(ZipArchive::class)) return;
    $archive = new ZipArchive();
    if ($archive->open($filePath) !== true) {
        throw new RuntimeException('File ZIP bị hỏng hoặc không đúng định dạng.');
    }
    $blockedExtensions = ['exe', 'bat', 'cmd', 'ps1', 'php', 'js'];
    try {
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $entryName = str_replace('\\', '/', (string) $archive->getNameIndex($index));
            if ($entryName === '' || str_ends_with($entryName, '/')) continue;
            $baseName = rtrim(basename($entryName), ". \t\n\r\0\x0B");
            $entryExtension = strtolower(pathinfo($baseName, PATHINFO_EXTENSION));
            if (in_array($entryExtension, $blockedExtensions, true)) {
                throw new RuntimeException("File nén chứa tệp bị chặn: {$entryName}");
            }
        }
    } finally {
        $archive->close();
    }
}

$assignmentSlug = trim((string) ($_GET['assignment'] ?? ''));
$assignment_id = $_GET['id'] ?? null;
if ($assignmentSlug !== '') {
    $slugStmt = $pdo->prepare('SELECT id FROM assignments WHERE slug=?');
    $slugStmt->execute([$assignmentSlug]);
    $assignment_id = $slugStmt->fetchColumn() ?: null;
}
if (!$assignment_id) {
    header('Location: dashboard.php');
    exit;
}

$assignment = authorizationFindAccessibleAssignment($pdo, (int) $assignment_id, (string) $_SESSION['user_role'], (int) $_SESSION['user_id']);

if (!$assignment) {
    die("Bài tập không tồn tại.");
}

if ($isAjaxRequest && ($_GET['action'] ?? '') === 'grading_job_status') {
    $jobId = filter_input(INPUT_GET, 'job_id', FILTER_VALIDATE_INT);
    if (!$jobId) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Mã yêu cầu chấm không hợp lệ.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $job = gradingJobForStudent($pdo, (int) $jobId, (int) $_SESSION['user_id']);
    if (!$job) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy yêu cầu chấm.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $response = [
        'success' => true,
        'action' => 'grading_status',
        'job_id' => (int) $job['id'],
        'module' => $job['module_name'],
        'status' => $job['status'],
        'attempts' => (int) $job['attempts'],
        'created_at' => $job['created_at'],
        'started_at' => $job['started_at'],
    ];
    if ($job['status'] === 'completed') {
        $result = applyCompletedGradingJob($pdo, $job);
        $response = array_merge($response, [
            'action' => 'graded',
            'score' => $result['score'],
            'total_score' => $result['total_score'],
            'max_score' => $result['max_score'],
            'feedback' => $result['feedback'],
            'review_required' => $result['review_required'],
            'message' => $result['review_required']
                ? 'AI đã chấm xong. Bài này cần giáo viên kiểm tra lại.'
                : 'AI đã chấm bài xong.',
        ]);
    } elseif (in_array($job['status'], ['failed', 'cancelled'], true)) {
        $response = array_merge($response, [
            'success' => false,
            'message' => $job['status'] === 'cancelled'
                ? 'Yêu cầu chấm đã được hủy vì file bài làm đã thay đổi.'
                : 'Không thể chấm bài: ' . ($job['error_message'] ?: 'Lỗi không xác định.'),
        ]);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}
$assignmentUrl = friendlyUrl('assignment.php', 'assignment', (string) $assignment['slug']);

$module_settings = json_decode($assignment['module_settings'] ?? '[]', true);
$storedAiAnalysis = json_decode($assignment['ai_analysis'] ?? '[]', true);
if (!is_array($storedAiAnalysis)) $storedAiAnalysis = [];
$total_max = 0;
if (is_array($module_settings)) {
    foreach ($module_settings as $m) {
        $total_max += $m['max_score'];
    }
}
if ($total_max == 0) $total_max = 10;

$isExam = ($assignment['type'] ?? 'assignment') === 'exam';
$durationMinutes = max(1, (int) ($assignment['duration_minutes'] ?? 90));
$examAttempt = null;
$remainingSeconds = null;
$examPaused = false;

if ($isExam && !$previewMode) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_exam') {
        verifyCsrfToken();
        if (!empty($assignment['due_date']) && time() > strtotime($assignment['due_date'])) {
            $_SESSION['error'] = 'Bài thi đã quá hạn và không thể bắt đầu.';
        } else {
            $stmt = $pdo->prepare("INSERT IGNORE INTO assignment_attempts (assignment_id, student_id, started_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL {$durationMinutes} MINUTE))");
            $stmt->execute([$assignment_id, $_SESSION['user_id']]);
        }
        header('Location: ' . $assignmentUrl);
        exit;
    }

    $stmt = $pdo->prepare("SELECT started_at, expires_at, paused_at, GREATEST(0, TIMESTAMPDIFF(SECOND, COALESCE(paused_at, NOW()), expires_at)) AS remaining_seconds FROM assignment_attempts WHERE assignment_id = ? AND student_id = ?");
    $stmt->execute([$assignment_id, $_SESSION['user_id']]);
    $examAttempt = $stmt->fetch();
    if ($examAttempt) {
        $remainingSeconds = (int) $examAttempt['remaining_seconds'];
        $examPaused = !empty($examAttempt['paused_at']);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restart_exam') {
        verifyCsrfToken();
        if (!$examAttempt || $remainingSeconds > 0) {
            $_SESSION['error'] = 'Bài thi chưa hết thời gian nên chưa thể làm lại.';
        } elseif (!empty($assignment['due_date']) && time() > strtotime($assignment['due_date'])) {
            $_SESSION['error'] = 'Bài thi đã quá hạn và không thể làm lại.';
        } else {
            $stmt = $pdo->prepare("UPDATE assignment_attempts SET started_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL {$durationMinutes} MINUTE), paused_at = NULL WHERE assignment_id = ? AND student_id = ?");
            $stmt->execute([$assignment_id, $_SESSION['user_id']]);
            $_SESSION['success'] = "Đã bắt đầu lượt làm lại. Bạn có {$durationMinutes} phút.";
        }
        header('Location: ' . $assignmentUrl);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_exam_pause') {
        verifyCsrfToken();
        $isAjaxPause = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
        if ($examAttempt && $remainingSeconds > 0) {
            if ($examPaused) {
                $stmt = $pdo->prepare("UPDATE assignment_attempts SET expires_at = DATE_ADD(expires_at, INTERVAL TIMESTAMPDIFF(SECOND, paused_at, NOW()) SECOND), paused_at = NULL WHERE assignment_id = ? AND student_id = ? AND paused_at IS NOT NULL");
            } else {
                $stmt = $pdo->prepare("UPDATE assignment_attempts SET paused_at = NOW() WHERE assignment_id = ? AND student_id = ? AND paused_at IS NULL AND expires_at > NOW()");
            }
            $stmt->execute([$assignment_id, $_SESSION['user_id']]);
        }
        if ($isAjaxPause) {
            $stmt = $pdo->prepare("SELECT paused_at, GREATEST(0, TIMESTAMPDIFF(SECOND, COALESCE(paused_at, NOW()), expires_at)) AS remaining_seconds FROM assignment_attempts WHERE assignment_id = ? AND student_id = ?");
            $stmt->execute([$assignment_id, $_SESSION['user_id']]);
            $updatedAttempt = $stmt->fetch();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => (bool) $updatedAttempt,
                'paused' => !empty($updatedAttempt['paused_at']),
                'remaining_seconds' => max(0, (int) ($updatedAttempt['remaining_seconds'] ?? 0)),
            ]);
            exit;
        }
        header('Location: ' . $assignmentUrl);
        exit;
    }
}

$examCanWork = !$isExam || $previewMode || ($examAttempt && $remainingSeconds > 0 && !$examPaused);

// POST request xử lý
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($previewMode) {
        http_response_code(403); exit('Chế độ xem trước không cho phép nộp hoặc thay đổi bài làm.');
    }
    verifyCsrfToken();
    $action = $_POST['action'];
    $archivePassword = (string) ($_POST['archive_password'] ?? '');
    if (strlen($archivePassword) > 256) {
        http_response_code(422);
        exit('Mật khẩu file nén không được dài quá 256 ký tự.');
    }
    if ($isExam && !$examCanWork) {
        $_SESSION['error'] = $examPaused ? 'Bài thi đang tạm dừng. Hãy bấm tiếp tục trước khi thao tác.' : ($examAttempt ? 'Thời gian làm bài đã hết.' : 'Bạn cần bấm bắt đầu trước khi làm bài thi.');
        header('Location: ' . $assignmentUrl); exit;
    }
    $modName = $_POST['module_name'] ?? '';
    $configuredModules = array_column(is_array($module_settings) ? $module_settings : [], 'module');
    if (!in_array($modName, $configuredModules, true)) {
        http_response_code(400); exit('Module không hợp lệ.');
    }
    if (in_array($action, ['submit_module', 'upload_module_only'], true) && !empty($assignment['due_date']) && time() > strtotime($assignment['due_date'])) {
        $_SESSION['error'] = 'Bài tập đã quá hạn nộp.';
        header('Location: ' . $assignmentUrl); exit;
    }
    
    $stmt = $pdo->prepare("SELECT * FROM submissions WHERE assignment_id = ? AND student_id = ?");
    $stmt->execute([$assignment_id, $_SESSION['user_id']]);
    $submission = $stmt->fetch();
    
    $sub_files = $submission ? json_decode($submission['submitted_files'] ?? '[]', true) : [];
    $sub_scores = $submission ? json_decode($submission['module_scores'] ?? '[]', true) : [];
    $sub_feedback = $submission ? json_decode($submission['ai_feedback'] ?? '[]', true) : [];

    // CHỈ TẢI FILE LÊN, CHƯA GỌI AI
    if ($action === 'upload_module_only' && $modName) {
        if (isset($_FILES['sub_file_'.$modName]) && $_FILES['sub_file_'.$modName]['error'] === 0) {
            $allowedExtensions = allowedExtensionsForModule($modName);
            try {
                $validated = validateUploadedFile($_FILES['sub_file_'.$modName], $allowedExtensions);
                if (strtolower($modName) === 'windows') {
                    assertArchiveContainsNoBlockedFiles($validated['tmp_name'], $validated['extension']);
                }
                $originalName = $validated['original_name'];
                $storedName = bin2hex(random_bytes(12)) . '.' . $validated['extension'];
                $studentFolder = ['LMS_Uploads', 'Student_' . $_SESSION['user_id'], 'Nop_Bai_' . $assignment_id];
                $driveId = uploadToDrive($validated['tmp_name'], $storedName, $studentFolder);

                if (!empty($sub_files[$modName]['drive_id'])) {
                    deleteFromDrive($sub_files[$modName]['drive_id']);
                }
                if ($submission) cancelActiveGradingJobs($pdo, (int) $submission['id'], $modName);
                $sub_files[$modName] = ['name' => $originalName, 'drive_id' => $driveId];
                unset($sub_scores[$modName], $sub_feedback[$modName]);
                $totalEarned = array_sum($sub_scores);

                if (!$submission) {
                    $stmt = $pdo->prepare("INSERT INTO submissions (assignment_id, student_id, file_drive_id, file_name, submitted_files, module_scores, score, ai_feedback) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $assignment_id,
                        $_SESSION['user_id'],
                        $driveId,
                        $originalName,
                        json_encode($sub_files, JSON_UNESCAPED_UNICODE),
                        json_encode($sub_scores, JSON_UNESCAPED_UNICODE),
                        $totalEarned,
                        json_encode($sub_feedback, JSON_UNESCAPED_UNICODE)
                    ]);
                    $submissionId = (int) $pdo->lastInsertId();
                } else {
                    $stmt = $pdo->prepare("UPDATE submissions SET submitted_files = ?, module_scores = ?, score = ?, ai_feedback = ? WHERE id = ?");
                    $stmt->execute([
                        json_encode($sub_files, JSON_UNESCAPED_UNICODE),
                        json_encode($sub_scores, JSON_UNESCAPED_UNICODE),
                        $totalEarned,
                        json_encode($sub_feedback, JSON_UNESCAPED_UNICODE),
                        $submission['id']
                    ]);
                    $submissionId = (int) $submission['id'];
                }
                $_SESSION['success'] = "Bạn đã tải lên file: $originalName. Bấm “Chấm bài bằng AI” khi bạn đã sẵn sàng.";
                if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
                    $downloadUrl = '../download.php?kind=submission&id=' . $submissionId
                        . '&module=' . rawurlencode($modName);
                    unset($_SESSION['success']);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'success' => true,
                        'module' => $modName,
                        'file_name' => $originalName,
                        'download_url' => $downloadUrl,
                        'message' => "Bạn đã tải lên file: $originalName"
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            } catch (Exception $e) {
                if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => 'Lỗi tải file: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $_SESSION['error'] = 'Lỗi tải file: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Vui lòng chọn file hợp lệ.';
        }
        header('Location: ' . $assignmentUrl);
        exit;
    }
    
    // NỘP BÀI TỪNG PHẦN
    if ($action === 'submit_module' && $modName) {
        if (isset($_FILES['sub_file_'.$modName]) && $_FILES['sub_file_'.$modName]['error'] === 0) {
            $allowedExtensions = allowedExtensionsForModule($modName);
            $validated = validateUploadedFile($_FILES['sub_file_'.$modName], $allowedExtensions);
            $file_tmp = $validated['tmp_name'];
            $original_name = $validated['original_name'];
            $file_name = bin2hex(random_bytes(12)) . '.' . $validated['extension'];
            
            try {
                $student_folder = ['LMS_Uploads', 'Student_' . $_SESSION['user_id'], 'Nop_Bai_' . $assignment_id];
                $drive_id = uploadToDrive($file_tmp, $file_name, $student_folder);
                $sub_files[$modName] = ['name' => $original_name, 'drive_id' => $drive_id];
                
                $max_score = 10;
                foreach ($module_settings as $m) {
                    if ($m['module'] === $modName) $max_score = $m['max_score'];
                }
                
                // GỌI AI THẬT
                $reference_drive_id = $assignment['prompt_file_drive_id'];
                $reference_file_name = $assignment['prompt_file_name'] ?? 'prompt.txt';
                $reference_kind = 'prompt_template';
                foreach ($module_settings as $m) {
                    if ($m['module'] === $modName && !empty($m['solution_drive_id'])) {
                        $reference_drive_id = $m['solution_drive_id'];
                        $reference_file_name = $m['solution_file_name'] ?? $reference_file_name;
                        $reference_kind = 'solution';
                        break;
                    }
                }
                $prompt_ext = strtolower(pathinfo($reference_file_name, PATHINFO_EXTENSION)) ?: 'txt';
                $sub_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION)) ?: 'txt';
                $temp_prompt = __DIR__ . '/../uploads/temp_ai/prompt_' . bin2hex(random_bytes(12)) . '.' . $prompt_ext;
                
                downloadFromDrive($reference_drive_id, $temp_prompt);
                $temp_sub = __DIR__ . '/../uploads/temp_ai/sub_' . bin2hex(random_bytes(12)) . '.' . $sub_ext;
                downloadFromDrive($drive_id, $temp_sub);
                $ai_analysis = json_decode($assignment['ai_analysis'] ?? '[]', true);
                
                // Find manual criteria for this module
                $manual_criteria = '';
                $module_rubric = null;
                if (is_array($module_settings)) {
                    foreach ($module_settings as $m) {
                        if ($m['module'] === $modName) {
                            $manual_criteria = $m['criteria'] ?? '';
                            $module_rubric = is_array($m['rubric'] ?? null) ? $m['rubric'] : null;
                            break;
                        }
                    }
                }
                
                $ai_criteria = $manual_criteria ? $manual_criteria : (isset($ai_analysis[$modName]) ? $ai_analysis[$modName] : "");

                $ai_request_data = [
                    'module_name' => $modName,
                    'max_score' => $max_score,
                    'prompt_local_path' => $temp_prompt,
                    'submission_local_path' => $temp_sub,
                    'reference_kind' => $reference_kind,
                    'ai_criteria' => $ai_criteria,
                    'rubric' => $module_rubric,
                    'rubric_id' => $module_rubric['rubric_id'] ?? null,
                    'archive_password' => $archivePassword
                ];
                
                unset($sub_scores[$modName], $sub_feedback[$modName]);
                $total_earned = array_sum($sub_scores);
                if (!$submission) {
                    $stmt = $pdo->prepare("INSERT INTO submissions (assignment_id, student_id, file_drive_id, file_name, submitted_files, module_scores, score, ai_feedback) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $assignment_id, 
                        $_SESSION['user_id'], 
                        $drive_id, 
                        $original_name, 
                        json_encode($sub_files, JSON_UNESCAPED_UNICODE),
                        json_encode($sub_scores, JSON_UNESCAPED_UNICODE),
                        $total_earned,
                        json_encode($sub_feedback, JSON_UNESCAPED_UNICODE)
                    ]);
                    $submissionId = (int) $pdo->lastInsertId();
                } else {
                    $stmt = $pdo->prepare("UPDATE submissions SET submitted_files = ?, module_scores = ?, score = ?, ai_feedback = ? WHERE id = ?");
                    $stmt->execute([
                        json_encode($sub_files, JSON_UNESCAPED_UNICODE),
                        json_encode($sub_scores, JSON_UNESCAPED_UNICODE),
                        $total_earned,
                        json_encode($sub_feedback, JSON_UNESCAPED_UNICODE),
                        $submission['id']
                    ]);
                    $submissionId = (int) $submission['id'];
                }

                $jobId = enqueueGradingJob(
                    $pdo,
                    $submissionId,
                    (int) $assignment_id,
                    (int) $_SESSION['user_id'],
                    $modName,
                    $ai_request_data
                );
                $_SESSION['success'] = "Bạn đã nộp file $original_name và đưa vào hàng đợi chấm AI.";
                if ($isAjaxRequest) {
                    unset($_SESSION['success']);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'success' => true,
                        'action' => 'queued',
                        'job_id' => $jobId,
                        'module' => $modName,
                        'max_score' => $max_score,
                        'message' => "Đã đưa phần $modName vào hàng đợi chấm AI.",
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            } catch (Exception $e) {
                $_SESSION['error'] = "Lỗi upload: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Vui lòng chọn file hợp lệ.";
        }
        header('Location: ' . $assignmentUrl);
        exit;
    }
    
    // XÓA PHẦN NỘP
    if ($action === 'delete_module' && $modName && $submission) {
        if (isset($sub_files[$modName])) {
            cancelActiveGradingJobs($pdo, (int) $submission['id'], $modName);
            deleteFromDrive($sub_files[$modName]['drive_id']);
            unset($sub_files[$modName]);
            unset($sub_scores[$modName]);
            unset($sub_feedback[$modName]);
            
            $total_earned = array_sum($sub_scores);
            
            if (empty($sub_files)) {
                $stmt = $pdo->prepare("DELETE FROM submissions WHERE id = ?");
                $stmt->execute([$submission['id']]);
            } else {
                $stmt = $pdo->prepare("UPDATE submissions SET submitted_files = ?, module_scores = ?, score = ?, ai_feedback = ? WHERE id = ?");
                $stmt->execute([
                    json_encode($sub_files, JSON_UNESCAPED_UNICODE),
                    json_encode($sub_scores, JSON_UNESCAPED_UNICODE),
                    $total_earned,
                    json_encode($sub_feedback, JSON_UNESCAPED_UNICODE),
                    $submission['id']
                ]);
            }
            $_SESSION['success'] = "Đã xóa phần bài làm $modName.";
            if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
                unset($_SESSION['success']);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'action' => 'deleted',
                    'module' => $modName,
                    'message' => "Đã xóa file phần $modName."
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        header('Location: ' . $assignmentUrl);
        exit;
    }
    
    // CHẤM LẠI PHẦN NỘP
    if ($action === 'regrade_module' && $modName && $submission) {
        if (isset($sub_files[$modName])) {
            $max_score = 10;
            foreach ($module_settings as $m) {
                if ($m['module'] === $modName) $max_score = $m['max_score'];
            }
            
            $reference_drive_id = $assignment['prompt_file_drive_id'];
            $reference_file_name = $assignment['prompt_file_name'] ?? 'prompt.txt';
            $reference_kind = 'prompt_template';
            foreach ($module_settings as $m) {
                if ($m['module'] === $modName && !empty($m['solution_drive_id'])) {
                    $reference_drive_id = $m['solution_drive_id'];
                    $reference_file_name = $m['solution_file_name'] ?? $reference_file_name;
                    $reference_kind = 'solution';
                    break;
                }
            }
            
            $drive_id = $sub_files[$modName]['drive_id'];
            $prompt_ext = strtolower(pathinfo($reference_file_name, PATHINFO_EXTENSION)) ?: 'txt';
            $sub_ext = strtolower(pathinfo($sub_files[$modName]['name'] ?? '', PATHINFO_EXTENSION)) ?: 'txt';
            $temp_prompt = __DIR__ . '/../uploads/temp_ai/prompt_' . bin2hex(random_bytes(12)) . '.' . $prompt_ext;
            downloadFromDrive($reference_drive_id, $temp_prompt);
            $temp_sub = __DIR__ . '/../uploads/temp_ai/sub_' . bin2hex(random_bytes(12)) . '.' . $sub_ext;
            downloadFromDrive($drive_id, $temp_sub);

            $ai_analysis = json_decode($assignment['ai_analysis'] ?? '[]', true);
            $manual_criteria = '';
            $module_rubric = null;
            foreach ($module_settings as $m) {
                if ($m['module'] === $modName) {
                    $manual_criteria = $m['criteria'] ?? '';
                    $module_rubric = is_array($m['rubric'] ?? null) ? $m['rubric'] : null;
                    break;
                }
            }
            $ai_criteria = $manual_criteria ?: ($ai_analysis[$modName] ?? '');
            
            $ai_request_data = [
                'module_name' => $modName,
                'max_score' => $max_score,
                'prompt_local_path' => $temp_prompt,
                'submission_local_path' => $temp_sub,
                'reference_kind' => $reference_kind,
                'ai_criteria' => $ai_criteria,
                'rubric' => $module_rubric,
                'rubric_id' => $module_rubric['rubric_id'] ?? null,
                'archive_password' => $archivePassword
            ];
            
            $jobId = enqueueGradingJob(
                $pdo,
                (int) $submission['id'],
                (int) $assignment_id,
                (int) $_SESSION['user_id'],
                $modName,
                $ai_request_data
            );

            $_SESSION['success'] = "Đã đưa phần $modName vào hàng đợi chấm AI.";
            if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
                unset($_SESSION['success']);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'action' => 'queued',
                    'job_id' => $jobId,
                    'module' => $modName,
                    'max_score' => $max_score,
                    'message' => "Đã đưa phần $modName vào hàng đợi chấm AI."
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        header('Location: ' . $assignmentUrl);
        exit;
    }
}

// Nếu học viên đóng trang trong lúc AI chấm, áp dụng kết quả ngay khi quay lại.
$completedJobStmt = $pdo->prepare(
    "SELECT id, submission_id, assignment_id, student_id, module_name, status,
            result_json, error_message, attempts, result_applied_at
     FROM grading_jobs
     WHERE assignment_id=? AND student_id=? AND status='completed' AND result_applied_at IS NULL
     ORDER BY id"
);
$completedJobStmt->execute([(int) $assignment_id, (int) $_SESSION['user_id']]);
foreach ($completedJobStmt->fetchAll() as $completedJob) {
    try {
        applyCompletedGradingJob($pdo, $completedJob);
    } catch (Throwable $error) {
        error_log('Cannot apply completed grading job ' . $completedJob['id'] . ': ' . $error->getMessage());
    }
}

// Fetch submission again to render page
$stmt = $pdo->prepare("SELECT * FROM submissions WHERE assignment_id = ? AND student_id = ?");
$stmt->execute([$assignment_id, $_SESSION['user_id']]);
$submission = $stmt->fetch();

$sub_files = $submission ? json_decode($submission['submitted_files'] ?? '[]', true) : [];
$sub_scores = $submission ? json_decode($submission['module_scores'] ?? '[]', true) : [];
$sub_feedback = $submission ? json_decode($submission['ai_feedback'] ?? '[]', true) : [];
$activeGradingStmt = $pdo->prepare(
    "SELECT gj.id, gj.module_name
     FROM grading_jobs gj
     JOIN (
         SELECT module_name, MAX(id) latest_id
         FROM grading_jobs
         WHERE assignment_id=? AND student_id=? AND status IN ('queued','processing')
         GROUP BY module_name
     ) latest ON latest.latest_id=gj.id"
);
$activeGradingStmt->execute([(int) $assignment_id, (int) $_SESSION['user_id']]);
$activeGradingJobs = $activeGradingStmt->fetchAll();

$page_title = "Chi tiết bài tập";
require_once '../includes/header.php';
?>

    <style>
        .page-content { display: grid; grid-template-columns: minmax(0, 1fr); gap: 24px; padding: 20px; width: 100%; max-width: none; }
        .page-content > .box { width: 100%; min-width: 0; grid-column: 1 / -1; }
        .box h2 { margin-top: 0; color: var(--primary); }
        .file-download { display: flex; align-items: center; gap: 10px; background: rgba(255,255,255,0.05); padding: 15px; border-radius: 8px; text-decoration: none; color: #fff; transition: 0.3s; margin-top: 20px; border: 1px solid rgba(255,255,255,0.1); }
        .file-download:hover { background: rgba(99, 102, 241, 0.1); border-color: var(--primary); }
        .file-upload { border: 2px dashed rgba(255,255,255,0.2); padding: 20px; text-align: center; border-radius: 8px; cursor: pointer; transition: 0.3s; }
        .file-upload:hover { border-color: var(--primary); background: rgba(99, 102, 241, 0.05); }
        .file-upload.drag-over { border-color:#38bdf8; background:rgba(56,189,248,.12); transform:scale(1.01); }
        .file-upload.uploading { pointer-events:none; border-color:var(--success); background:rgba(16,185,129,.1); }
        .ai-feedback { background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.2); padding: 20px; border-radius: 8px; margin-top: 20px; }
        .ai-feedback h3 { color: var(--success); margin-top: 0; display: flex; align-items: center; gap: 8px; }
        
        .module-breakdown { background: rgba(0,0,0,0.2); border-radius: 8px; padding: 15px; margin-top: 15px; border: 1px solid rgba(255,255,255,0.05); }
        .module-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed rgba(255,255,255,0.1); font-size: 14px; }
        .module-row:last-child { border-bottom: none; }
        .mod-name { font-weight: 500; color: #e2e8f0; }
        .mod-score { color: var(--success); font-weight: bold; }
        .mod-max { color: var(--text-muted); font-size: 13px; }
        
        .module-card {
            --module-color:var(--primary);
            --module-rgb:var(--primary-rgb);
            background:linear-gradient(135deg,rgba(var(--module-rgb),.09),rgba(255,255,255,.035) 42%);
            border:1px solid rgba(var(--module-rgb),.28);
            border-left:4px solid var(--module-color);
            border-radius:10px;
            padding:20px;
            margin-bottom:20px;
            box-shadow:0 10px 28px rgba(var(--module-rgb),.05);
        }
        .module-card[data-module="Word" i] { --module-color:#2b7cd3; --module-rgb:43,124,211; }
        .module-card[data-module="Excel" i] { --module-color:#21a366; --module-rgb:33,163,102; }
        .module-card[data-module="PowerPoint" i],
        .module-card[data-module="Power Point" i] { --module-color:#e65a2f; --module-rgb:230,90,47; }
        .module-card[data-module="Windows" i] { --module-color:#ef4444; --module-rgb:239,68,68; }
        .module-card[data-module="Internet" i] { --module-color:#38bdf8; --module-rgb:56,189,248; }
        .module-card[data-module*="Drive" i] { --module-color:#f9ab00; --module-rgb:249,171,0; }
        .module-card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .module-card-header h3 { margin:0; color:var(--module-color); font-size:18px; }
        .module-card-header h3 i { font-size:22px; }
        .module-card-header .badge { background:rgba(var(--module-rgb),.16); color:var(--module-color); border:1px solid rgba(var(--module-rgb),.24); padding:5px 10px; border-radius:20px; font-size:12px; font-weight:600; }
        .module-card-header .badge.done { background: rgba(16, 185, 129, 0.2); color: #10b981; }
        .module-card .module-criteria { background:rgba(var(--module-rgb),.075) !important; border-color:rgba(var(--module-rgb),.25) !important; }
        .module-card .module-criteria h4 { color:var(--module-color) !important; }
        .module-card .file-upload { border-color:rgba(var(--module-rgb),.42); background:rgba(var(--module-rgb),.045); }
        .module-card .file-upload:hover,
        .module-card .file-upload.drag-over { border-color:var(--module-color); background:rgba(var(--module-rgb),.12); }
        .module-card .file-upload > i { color:var(--module-color) !important; }
        .module-card .file-upload [id^="name_display_"] { color:var(--module-color) !important; }
        .module-card > .module-upload-form > .btn-primary { background:var(--module-color); }
        .archive-password-field{display:block;margin:0 0 9px;text-align:left;color:var(--text-muted);font-size:12px}
        .archive-password-field span{display:block;margin-bottom:5px}
        .archive-password-field input{width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid var(--border-color);border-radius:7px;background:rgba(255,255,255,.05);color:var(--text-main)}
        .archive-password-field input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(var(--primary-rgb),.12)}
        .submission-actions{align-items:stretch}
        .submission-actions>form{display:flex;flex-direction:column;min-width:0}
        .submission-actions>form>button[type="submit"]{margin-top:auto}
        .ai-grading-progress{width:220px;max-width:48vw;text-align:left;color:var(--text-main);font-size:12px;line-height:1.25}
        .ai-grading-progress__top{display:flex;align-items:center;gap:6px;font-weight:700;white-space:nowrap}
        .ai-grading-progress__top i{color:var(--primary)}
        .ai-grading-progress__track{height:6px;overflow:hidden;margin-top:6px;border-radius:999px;background:rgba(148,163,184,.2);border:1px solid rgba(148,163,184,.18)}
        .ai-grading-progress__bar{height:100%;border-radius:inherit;background:linear-gradient(90deg,var(--primary),#22d3ee);box-shadow:0 0 12px rgba(var(--primary-rgb),.6);transition:width .7s ease}
        .ai-grading-progress__hint{display:block;margin-top:4px;color:var(--text-muted);font-size:11px}
        @media(max-width:650px){
            .submission-actions{flex-direction:column}
            .submission-actions>form{width:100%}
            .ai-grading-progress{width:100%;max-width:none}
        }
        .exam-timer { position: sticky; top: 14px; z-index: 50; display:flex; justify-content:center; align-items:center; gap:12px; padding:14px 20px; border-radius:12px; background:rgba(127,29,29,.96); border:1px solid rgba(248,113,113,.55); box-shadow:0 10px 30px rgba(0,0,0,.28); font-weight:700; }
        .exam-timer-value { font-variant-numeric: tabular-nums; font-size:24px; color:#fff; letter-spacing:1px; }
        .exam-timer.is-warning { animation:timerWarningPulse 1.8s ease-in-out infinite; }
        .exam-timer.is-critical { animation:timerCriticalPulse .8s ease-in-out infinite; border-color:#fecaca; }
        @keyframes timerWarningPulse { 50% { box-shadow:0 10px 34px rgba(239,68,68,.42); } }
        @keyframes timerCriticalPulse { 50% { transform:scale(1.012); box-shadow:0 10px 40px rgba(239,68,68,.68); } }
        @media (prefers-reduced-motion:reduce) {
            .exam-timer.is-warning,.exam-timer.is-critical { animation:none; }
        }
        .lms-dialog-overlay { position:fixed; inset:0; z-index:2000; display:grid; place-items:center; padding:20px; background:rgba(2,6,23,.58); backdrop-filter:blur(3px); }
        .lms-dialog-overlay[hidden] { display:none !important; }
        .lms-dialog { width:min(390px,100%); padding:24px; border-radius:16px; background:var(--sidebar-bg); color:var(--text-main); border:1px solid var(--border-color); box-shadow:0 24px 70px rgba(0,0,0,.4); text-align:center; animation:lmsDialogIn .18s ease-out; }
        .lms-dialog-icon { width:52px; height:52px; margin:0 auto 14px; border-radius:50%; display:grid; place-items:center; font-size:30px; background:rgba(16,185,129,.14); color:var(--success); }
        .lms-dialog-icon.error { background:rgba(239,68,68,.14); color:var(--danger); }
        .lms-dialog h3 { margin:0 0 8px; font-size:19px; }
        .lms-dialog-message { margin:0; color:var(--text-muted); line-height:1.55; white-space:pre-line; overflow-wrap:anywhere; }
        .lms-dialog-ai-warning { margin:10px 0 0; padding-top:10px; border-top:1px solid var(--border-color); color:#f59e0b; font-size:13px; line-height:1.45; }
        .lms-dialog-actions { display:flex; justify-content:center; gap:10px; margin-top:20px; }
        .lms-dialog-ok { min-width:110px; justify-content:center; margin-top:20px; }
        .lms-dialog-actions .lms-dialog-ok { margin-top:0; }
        .lms-dialog-cancel { min-width:100px; justify-content:center; background:rgba(148,163,184,.14); color:var(--text-main); border:1px solid var(--border-color); }
        @keyframes lmsDialogIn { from { opacity:0; transform:translateY(8px) scale(.97); } to { opacity:1; transform:none; } }
        @media (max-width:650px) {
            .page-content { padding:14px 10px 24px; gap:14px; }
            .module-card { padding:15px 13px; margin-bottom:14px; }
            .module-card-header {
                align-items:flex-start;
                flex-direction:column;
                gap:9px;
            }
            .module-card-header .badge { align-self:flex-start; }
            .file-download { padding:12px; overflow-wrap:anywhere; }
            .file-download > div { min-width:0; }
            .file-upload { padding:18px 10px; }
            .ai-feedback { padding:15px; }
            .module-row { align-items:flex-start; gap:12px; }
            .exam-timer {
                top:6px;
                padding:11px 12px;
                gap:8px;
                flex-wrap:wrap;
                text-align:center;
            }
            .exam-timer-value { font-size:20px; }
            .exam-timer form,
            .exam-timer .btn { width:100%; }
            .lms-dialog-actions { flex-direction:column; }
            .lms-dialog-actions .btn { width:100%; }
        }
    </style>

        <?php if ($isExam && !$previewMode && !$examAttempt): ?>
            <div class="box" style="padding:14px 18px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;border-color:rgba(245,158,11,.35);">
                <div style="display:flex;align-items:center;gap:10px;color:#fbbf24;">
                    <i class='bx bx-timer' style="font-size:28px;"></i>
                    <span>Thời gian làm bài: <strong><?php echo $durationMinutes; ?> phút</strong>. Đồng hồ chưa bắt đầu.</span>
                </div>
                <?php if(isset($_SESSION['error'])): ?>
                    <div style="color:#fca5a5;"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
                <?php endif; ?>
                <form method="POST" style="margin:0;" onsubmit="return confirm('Bắt đầu làm bài ngay bây giờ? Đồng hồ sẽ bắt đầu đếm.');">
                    <input type="hidden" name="action" value="start_exam">
                    <button type="submit" class="btn btn-primary" style="padding:9px 16px;"><i class='bx bx-play-circle'></i> Bắt đầu làm bài</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($isExam && !$previewMode && $examAttempt): ?>
            <div class="exam-timer" id="exam-timer" data-seconds="<?php echo max(0, (int) $remainingSeconds); ?>" data-paused="<?php echo $examPaused ? '1' : '0'; ?>">
                <i class='bx bx-time-five' style="font-size:25px;"></i>
                <span><?php echo $examPaused ? 'Đang tạm dừng:' : ($remainingSeconds > 0 ? 'Thời gian còn lại:' : 'Đã hết thời gian'); ?></span>
                <span class="exam-timer-value" id="exam-timer-value">--:--:--</span>
                <?php if ($remainingSeconds > 0): ?>
                    <form method="POST" id="exam-pause-form" style="margin:0;">
                        <input type="hidden" name="action" value="toggle_exam_pause">
                        <button type="submit" id="exam-pause-button" class="btn" style="padding:7px 12px;background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.3);">
                            <i class='bx <?php echo $examPaused ? 'bx-play' : 'bx-pause'; ?>'></i> <?php echo $examPaused ? 'Tiếp tục' : 'Tạm dừng'; ?>
                        </button>
                    </form>
                <?php elseif (empty($assignment['due_date']) || time() <= strtotime($assignment['due_date'])): ?>
                    <form method="POST" style="margin:0;" onsubmit="return confirm('Bắt đầu làm lại bài thi với <?php echo $durationMinutes; ?> phút? Các file đã nộp cũ vẫn được giữ cho đến khi bạn xóa hoặc nộp lại.');">
                        <input type="hidden" name="action" value="restart_exam">
                        <button type="submit" class="btn" style="padding:7px 12px;background:rgba(16,185,129,.18);color:#6ee7b7;border:1px solid rgba(16,185,129,.4);">
                            <i class='bx bx-refresh'></i> Làm lại bài thi
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Phần nội dung đề bài -->
        <div class="box">
            <div style="display: flex; gap: 8px; margin-bottom: 10px;">
                <?php if ($assignment['category']): ?>
                    <span style="background: rgba(14, 165, 233, 0.2); color: #38bdf8; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600;">
                        <i class='bx bx-folder'></i> <?php echo htmlspecialchars($assignment['category']); ?>
                    </span>
                <?php endif; ?>
                <?php if ($assignment['type'] === 'exam'): ?>
                    <span style="background: rgba(239, 68, 68, 0.2); color: #f87171; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600;">
                        <i class='bx bx-timer'></i> Bài thi thử
                    </span>
                <?php endif; ?>
            </div>
            
            <h2><?php echo htmlspecialchars($assignment['title']); ?></h2>
            <div style="display: flex; gap: 20px; color: var(--text-muted); font-size: 14px; margin-bottom: 20px; flex-wrap: wrap;">
                <div><i class='bx bx-calendar'></i> Hạn nộp: <?php echo $assignment['due_date'] ? date('d/m/Y H:i', strtotime($assignment['due_date'])) : 'Không giới hạn'; ?></div>
                <?php if ($isExam): ?><div style="color:#fbbf24;"><i class='bx bx-time'></i> Thời gian làm bài: <strong><?php echo $durationMinutes; ?> phút</strong></div><?php endif; ?>
                <div style="color: #cbd5e1;"><i class='bx bx-target-lock'></i> Tổng điểm tối đa: <strong><?php echo $total_max; ?></strong></div>
                <div style="color: var(--success); font-weight: 600;"><i class='bx bx-check-shield'></i> Điểm đạt được: <span id="assignment-earned-score"><?php echo htmlspecialchars((string) ($submission['score'] ?? 0)); ?></span> / <?php echo $total_max; ?></div>
            </div>
            
            <div style="line-height: 1.6;">
                <?php echo nl2br(htmlspecialchars($assignment['description'])); ?>
            </div>
            
            <?php 
            $attachments = !empty($assignment['attachments']) ? json_decode($assignment['attachments'], true) : [];
            ?>
            
            <?php if (!empty($assignment['prompt_file_name'])): ?>
                <div style="margin-top: 30px;">
                    <h3 style="color: var(--primary); font-size: 16px; margin-bottom: 15px;"><i class='bx bx-book-open'></i> Đề Bài</h3>
                    <?php $prompt_link = '../download.php?kind=prompt&id=' . (int) $assignment_id; ?>
                    
                    <a href="<?php echo $prompt_link; ?>" target="_blank" class="file-download" style="margin-top: 0; padding: 12px 15px; background: rgba(56, 189, 248, 0.1); border-color: rgba(56, 189, 248, 0.3);">
                        <i class='bx bxs-file-pdf' style="font-size: 24px; color: #38bdf8;"></i>
                        <div>
                            <div style="font-size: 14px; font-weight: 600; color: #fff;"><?php echo htmlspecialchars($assignment['prompt_file_name']); ?></div>
                        </div>
                        <i class='bx bx-download' style="margin-left: auto; font-size: 20px; color: #38bdf8;"></i>
                    </a>
                    
                    <?php if (strpos($assignment['prompt_file_drive_id'], 'local_') !== 0): ?>
                    <div style="margin-top: 15px; border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; overflow: hidden; background: #fff;">
                        <iframe src="https://drive.google.com/file/d/<?php echo $assignment['prompt_file_drive_id']; ?>/preview" width="100%" height="600" allow="autoplay" style="border:none;"></iframe>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (count($attachments) > 0): ?>
                <div style="margin-top: 30px;">
                    <h3 style="color: #10b981; font-size: 16px; margin-bottom: 15px;"><i class='bx bx-paperclip'></i> File Dữ liệu / Bài mẫu</h3>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <?php foreach ($attachments as $attachmentIndex => $att): ?>
                            <?php $dl_link = '../download.php?kind=attachment&id=' . (int) $assignment_id . '&index=' . (int) $attachmentIndex; ?>
                            <a href="<?php echo $dl_link; ?>" target="_blank" class="file-download" style="margin-top: 0; padding: 12px 15px;">
                                <i class='bx bx-file' style="font-size: 24px; color: #10b981;"></i>
                                <div>
                                    <div style="font-size: 14px; font-weight: 500; color: #fff;"><?php echo htmlspecialchars($att['name']); ?></div>
                                </div>
                                <i class='bx bx-download' style="margin-left: auto; font-size: 20px; color: #10b981;"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tiêu chí cũ được giữ ẩn để tương thích dữ liệu; giao diện hiển thị theo từng module bên dưới. -->
        <div class="box" style="display:none;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h2 style="margin: 0; color: var(--primary);"><i class='bx bx-list-check'></i> Tiêu Chí & Yêu Cầu Cụ Thể</h2>
                <?php
                $has_manual_criteria = false;
                $manual_criteria_html = '';
                if (is_array($module_settings)) {
                    foreach ($module_settings as $m) {
                        if (!empty($m['criteria'])) {
                            $has_manual_criteria = true;
                            $mod_name = $m['module'];
                            $manual_criteria_html .= "<h3 style='color: #fff; margin-top: 15px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 5px;'>Phần: {$mod_name}</h3>";
                            
                            $lines = explode("\\n", $m['criteria']);
                            $manual_criteria_html .= "<ul style='padding-left: 20px; line-height: 1.6; color: #e2e8f0; font-size: 14px;'>";
                            foreach ($lines as $line) {
                                $line = trim($line);
                                if ($line) {
                                    $manual_criteria_html .= "<li style='margin-bottom: 5px;'>" . htmlspecialchars($line) . "</li>";
                                }
                            }
                            $manual_criteria_html .= "</ul>";
                        }
                    }
                }
                
                if (!$has_manual_criteria):
                ?>
                <button type="button" class="btn btn-primary" id="btn-analyze-prompt" onclick="analyzePrompt()">
                    <i class='bx bx-search-alt'></i> AI Phân tích đề bài
                </button>
                <?php endif; ?>
            </div>
            
            <?php if ($has_manual_criteria): ?>
                <p style="font-size: 14px; color: var(--text-muted); margin-bottom: 20px;">Dưới đây là các tiêu chí chấm điểm và ảnh mẫu do giáo viên thiết lập. AI sẽ dựa vào các tiêu chí này để chấm bài của bạn.</p>
                <div style="background: rgba(0,0,0,0.2); border-radius: 8px; padding: 20px; border: 1px solid rgba(255,255,255,0.1);">
                    <?php echo $manual_criteria_html; ?>
                </div>
            <?php else: ?>
                <p style="font-size: 14px; color: var(--text-muted); margin-bottom: 20px;">AI sẽ tự động đọc file đề bài và bóc tách các tiêu chí chấm điểm chi tiết cho từng phần. Bạn nên bám sát vào các tiêu chí này để đạt điểm cao.</p>
                
                <div id="ai-analysis-result" style="display: none; background: rgba(0,0,0,0.2); border-radius: 8px; padding: 20px; border: 1px solid rgba(255,255,255,0.1);">
                    <!-- Dữ liệu phân tích sẽ hiển thị ở đây -->
                </div>
                <div id="ai-analysis-loading" style="display: none; text-align: center; padding: 30px; color: var(--text-muted);">
                    <i class='bx bx-loader-alt bx-spin' style="font-size: 32px; color: var(--primary); margin-bottom: 10px;"></i>
                    <p>AI đang đọc và bóc tách yêu cầu từ file đề bài... Quá trình này có thể mất tới 1 phút.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Phần Nộp bài Từng Module -->
        <div class="box" style="grid-column: 1 / -1;">
            <?php if(isset($_SESSION['success'])): ?>
                <div style="background: rgba(16, 185, 129, 0.2); color: #6ee7b7; padding: 10px; border-radius: 8px; margin-bottom: 20px; text-align: center;">
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            <?php if(isset($_SESSION['error'])): ?>
                <div style="background: rgba(239, 68, 68, 0.2); color: #f87171; padding: 10px; border-radius: 8px; margin-bottom: 20px; text-align: center;">
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <h2 style="margin-bottom: 20px;"><i class='bx <?php echo $previewMode ? 'bx-show' : 'bx-cloud-upload'; ?>'></i> <?php echo $previewMode ? 'Xem trước bài tập' : 'Nộp Bài Làm'; ?></h2>
            <?php if ($previewMode): ?>
                <div style="background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.3);padding:12px;border-radius:8px;margin-bottom:20px;color:#93c5fd;">
                    Bạn đang xem bằng tài khoản <?php echo htmlspecialchars($_SESSION['user_role']); ?>. Chế độ này không tạo bài nộp và không gọi chấm điểm.
                </div>
            <?php else: ?>
                <?php if ($adminTestMode): ?>
                    <div style="background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);padding:12px;border-radius:8px;margin-bottom:20px;color:#fbbf24;">
                        Chế độ kiểm thử Admin: bài nộp và điểm sẽ được lưu dưới tài khoản admin hiện tại.
                    </div>
                <?php endif; ?>
                <p style="font-size: 14px; color: var(--text-muted); margin-bottom: 25px;">Bạn có thể nộp và chấm điểm riêng biệt từng phần.</p>
            <?php endif; ?>

            <?php if (is_array($module_settings) && count($module_settings) > 0): ?>
                <?php foreach ($module_settings as $m): 
                    $modName = $m['module'];
                    $modMax = $m['max_score'];
                    $moduleIcon = match (strtolower(trim((string) $modName))) {
                        'word' => 'bx-file',
                        'excel' => 'bx-table',
                        'powerpoint', 'power point' => 'bx-slideshow',
                        'windows' => 'bxl-windows',
                        'internet' => 'bx-globe',
                        default => 'bx-task',
                    };
                    $isSubmitted = isset($sub_files[$modName]);
                    $moduleCriteria = trim((string) ($m['criteria'] ?? ''));
                    if ($moduleCriteria === '' && isset($storedAiAnalysis[$modName])) {
                        $moduleCriteria = trim((string) $storedAiAnalysis[$modName]);
                    }
                    $moduleCriteria = str_replace('\\n', "\n", $moduleCriteria);
                ?>
                    <div class="module-card" data-module="<?php echo htmlspecialchars($modName); ?>">
                        <div class="module-card-header">
                            <h3><i class='bx <?php echo htmlspecialchars($moduleIcon, ENT_QUOTES, 'UTF-8'); ?>'></i> Phần: <?php echo htmlspecialchars($modName); ?></h3>
                            <?php if ($isSubmitted): ?>
                                <span class="badge done">Đã Nộp</span>
                            <?php else: ?>
                                <span class="badge">Chưa Nộp (Tối đa: <?php echo $modMax; ?> đ)</span>
                            <?php endif; ?>
                        </div>

                        <div class="module-criteria" data-module="<?php echo htmlspecialchars($modName, ENT_QUOTES, 'UTF-8'); ?>" style="background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.22);border-radius:10px;padding:16px;margin-bottom:18px;">
                            <h4 style="margin:0 0 10px;color:#c7d2fe;display:flex;align-items:center;gap:7px;"><i class='bx bx-list-check'></i> Tiêu chí & yêu cầu phần <?php echo htmlspecialchars($modName); ?></h4>
                            <div class="module-criteria-content" style="color:#e2e8f0;font-size:14px;line-height:1.65;">
                                <?php if ($moduleCriteria !== ''): ?>
                                    <ul style="margin:0;padding-left:22px;">
                                        <?php foreach (preg_split('/\r\n|\r|\n/', $moduleCriteria) as $criterionLine): ?>
                                            <?php $criterionLine = ltrim(trim($criterionLine), '-*• '); if ($criterionLine === '') continue; ?>
                                            <li style="margin-bottom:6px;"><?php echo htmlspecialchars($criterionLine); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="criteria-empty" style="margin:0 0 10px;color:var(--text-muted);">Chưa có tiêu chí riêng cho phần này.</p>
                                    <button type="button" class="btn btn-outline analyze-trigger" onclick="analyzePrompt()" style="padding:7px 12px;font-size:13px;"><i class='bx bx-search-alt'></i> AI phân tích đề bài</button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($isSubmitted): 
                            $fData = $sub_files[$modName];
                            $fileDriveId = (string) ($fData['drive_id'] ?? '');
                            $submittedExtension = strtolower(pathinfo((string) ($fData['name'] ?? ''), PATHINFO_EXTENSION));
                            $isArchiveSubmission = in_array($submittedExtension, ['zip', 'rar', '7z'], true);
                            $isGraded = array_key_exists($modName, $sub_scores);
                            $fScore = $sub_scores[$modName] ?? 0;
                            $fFeedback = $sub_feedback[$modName] ?? [];
                            $fileDownloadUrl = '../download.php?kind=submission&id=' . (int) $submission['id']
                                . '&module=' . rawurlencode($modName);
                        ?>
                            <!-- Trạng thái ĐÃ NỘP -->
                            <div class="submitted-file-panel" style="background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.2); padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <div style="font-weight: bold; font-size: 14px; color: #fff; min-width:0;">
                                        <i class='bx bxs-file'></i>
                                        <span style="overflow-wrap:anywhere;"><?php echo htmlspecialchars($fData['name']); ?></span>
                                    </div>
                                    <div class="module-score-display" data-score="<?php echo $isGraded ? htmlspecialchars((string) $fScore, ENT_QUOTES, 'UTF-8') : ''; ?>" style="font-size:<?php echo $isGraded ? '24px' : '14px'; ?>;font-weight:bold;color:var(--success);">
                                        <?php if ($isGraded): ?>
                                            <?php echo $fScore; ?> <span style="font-size:14px;color:var(--text-muted);">/ <?php echo $modMax; ?></span>
                                        <?php else: ?>
                                            <i class='bx bx-time-five'></i> Đã tải lên, chưa chấm
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if ($fileDriveId !== ''): ?>
                                    <a href="<?php echo htmlspecialchars($fileDownloadUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline" style="display:inline-flex;padding:7px 12px;margin-bottom:12px;font-size:13px;text-decoration:none;">
                                        <i class='bx bx-download'></i> Tải lại file đã nộp
                                    </a>
                                <?php endif; ?>
                                
                                <div class="module-feedback-display">
                                <?php if (!empty($fFeedback['comment'])): ?>
                                    <div style="font-size: 13px; color: rgba(255,255,255,0.8); margin-bottom: 10px;">
                                        <strong>Nhận xét:</strong> <?php echo htmlspecialchars($fFeedback['comment']); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($fFeedback['errors'])): ?>
                                    <div style="font-size: 13px; color: #f87171;">
                                        <strong>Lỗi trừ điểm:</strong>
                                        <ul style="margin: 5px 0 0 20px; padding: 0;">
                                            <?php foreach ($fFeedback['errors'] as $err): ?>
                                                <li><?php echo htmlspecialchars($err); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                                </div>
                            </div>
                            
                            <?php if ($examCanWork): ?>
                            <div class="submission-actions" style="display: flex; gap: 10px;">
                                <form method="POST" style="flex: 1;" data-confirm-message="<?php echo htmlspecialchars($isGraded ? 'Bạn có muốn hệ thống chấm lại phần này không?' : 'Bắt đầu chấm file này bằng AI?', ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="regrade_module">
                                    <input type="hidden" name="module_name" value="<?php echo htmlspecialchars($modName); ?>">
                                    <?php if ($isArchiveSubmission): ?>
                                        <label class="archive-password-field">
                                            <span><i class='bx bx-lock-alt'></i> Mật khẩu file nén (nếu có)</span>
                                            <input type="password" name="archive_password" maxlength="256" autocomplete="off" placeholder="Nhập mật khẩu ZIP/RAR/7Z">
                                        </label>
                                    <?php endif; ?>
                                    <button type="submit" class="btn" style="width: 100%; padding: 8px; font-size: 13px; background: rgba(56, 189, 248, 0.1); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3);"><i class='bx <?php echo $isGraded ? 'bx-refresh' : 'bx-bot'; ?>'></i> <?php echo $isGraded ? 'Chấm lại AI' : 'Chấm bài bằng AI'; ?></button>
                                </form>
                                <form method="POST" style="flex: 1;" data-confirm-message="Toàn bộ kết quả phần này sẽ bị xóa. Bạn có chắc chắn muốn nộp lại từ đầu?">
                                    <input type="hidden" name="action" value="delete_module">
                                    <input type="hidden" name="module_name" value="<?php echo htmlspecialchars($modName); ?>">
                                    <button type="submit" class="btn" style="width: 100%; padding: 8px; font-size: 13px; background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3);"><i class='bx bx-reset'></i> Xóa & Nộp lại</button>
                                </form>
                            </div>
                            <?php elseif ($isExam): ?>
                                <div style="padding:12px;border-radius:8px;background:rgba(239,68,68,.1);color:#fca5a5;"><i class='bx bx-lock-alt'></i> <?php echo $examPaused ? 'Bài thi đang tạm dừng. Bấm “Tiếp tục” trên đồng hồ để thao tác.' : ($examAttempt ? 'Đã hết giờ, kết quả hiện tại được giữ nguyên.' : 'Bấm “Bắt đầu làm bài” phía trên để mở quyền nộp bài.'); ?></div>
                            <?php endif; ?>

                        <?php elseif ($previewMode): ?>
                            <div style="padding:15px;border:1px dashed rgba(255,255,255,.2);border-radius:8px;color:var(--text-muted);">
                                Học viên sẽ tải bài làm cho phần này. Điểm tối đa: <?php echo htmlspecialchars((string) $modMax); ?> điểm.
                            </div>
                        <?php elseif ($isExam && (!$examAttempt || $remainingSeconds <= 0)): ?>
                            <div style="padding:15px;border:1px solid rgba(239,68,68,.3);background:rgba(239,68,68,.1);border-radius:8px;color:#fca5a5;">
                                <i class='bx bx-lock-alt'></i> <?php echo $examPaused ? 'Bài thi đang tạm dừng. Bấm “Tiếp tục” để nộp bài.' : ($examAttempt ? 'Thời gian làm bài đã hết.' : 'Bạn cần bấm “Bắt đầu làm bài” phía trên trước khi nộp file.'); ?>
                            </div>
                        <?php else: ?>
                            <!-- Trạng thái CHƯA NỘP -->
                            <form method="POST" enctype="multipart/form-data" class="module-upload-form">
                                <input type="hidden" name="action" value="upload_module_only">
                                <input type="hidden" name="module_name" value="<?php echo htmlspecialchars($modName); ?>">
                                
                                <div class="file-upload" data-file-input="sub_file_<?php echo htmlspecialchars($modName); ?>" onclick="document.getElementById('sub_file_<?php echo $modName; ?>').click()">
                                    <i class='bx bx-cloud-upload' style="font-size: 32px; color: var(--primary);"></i>
                                    <p style="font-size: 13px; margin: 10px 0;">Kéo file vào đây hoặc click để chọn file</p>
                                    <p id="name_display_<?php echo $modName; ?>" style="color: var(--primary); font-weight: 600; font-size: 13px; margin:0;"></p>
                                </div>
                                <input type="file" id="sub_file_<?php echo $modName; ?>" name="sub_file_<?php echo $modName; ?>" required style="display: none;" accept="<?php echo htmlspecialchars(acceptAttributeForModule($modName), ENT_QUOTES, 'UTF-8'); ?>" onchange="document.getElementById('name_display_<?php echo $modName; ?>').innerText = this.files[0].name">
                                
                                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 15px;"><i class='bx bx-cloud-upload'></i> Tải file lên</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <!-- Không cấu hình module thì không hỗ trợ bài tập này (vì đây là hệ thống học tin học đa phần) -->
                <div style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); padding: 15px; border-radius: 8px; text-align: center; color: var(--warning);">
                    Bài tập này chưa được cấu hình Module để chấm điểm. Vui lòng liên hệ giáo viên.
                </div>
            <?php endif; ?>

            <!-- Bảng Điểm Tổng Hợp -->
            <div style="margin-top: 30px; border-top: 1px dashed rgba(255,255,255,0.2); padding-top: 20px;">
                <h3 style="text-align: center; color: #fff; margin-bottom: 20px;">Tổng Kết</h3>
                <div style="font-size: 48px; font-weight: bold; color: var(--success); text-align: center;">
                    <span id="assignment-summary-score"><?php echo array_sum($sub_scores); ?></span> <span style="font-size: 20px; color: var(--text-muted);">/ <?php echo $total_max; ?></span>
                </div>
            </div>
        </div>

        <div class="lms-dialog-overlay" id="lms-dialog-overlay" hidden>
            <div class="lms-dialog" role="alertdialog" aria-modal="true" aria-labelledby="lms-dialog-title" aria-describedby="lms-dialog-message">
                <div class="lms-dialog-icon" id="lms-dialog-icon"><i class='bx bx-check'></i></div>
                <h3 id="lms-dialog-title">Thông báo</h3>
                <p class="lms-dialog-message" id="lms-dialog-message"></p>
                <p class="lms-dialog-ai-warning" id="lms-dialog-ai-warning" hidden>
                    <i class='bx bx-error-circle'></i>
                    AI có thể mắc sai sót, hãy kiểm tra lại kết quả.
                </p>
                <div class="lms-dialog-actions">
                    <button type="button" class="btn btn-primary lms-dialog-ok" id="lms-dialog-ok">Đồng ý</button>
                    <button type="button" class="btn lms-dialog-cancel" id="lms-dialog-cancel" hidden>Hủy</button>
                </div>
            </div>
        </div>

        <script>
            const existingAnalysis = <?php echo !empty($storedAiAnalysis) ? json_encode($storedAiAnalysis, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : 'null'; ?>;
            const activeGradingJobs = <?php echo json_encode($activeGradingJobs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            let lmsConfirmResolver = null;
            const showLmsDialog = (message, type = 'auto', showAiWarning = false) => {
                const overlay = document.getElementById('lms-dialog-overlay');
                const icon = document.getElementById('lms-dialog-icon');
                const title = document.getElementById('lms-dialog-title');
                const messageElement = document.getElementById('lms-dialog-message');
                const aiWarning = document.getElementById('lms-dialog-ai-warning');
                const okButton = document.getElementById('lms-dialog-ok');
                const cancelButton = document.getElementById('lms-dialog-cancel');
                if (!overlay || !icon || !title || !messageElement || !okButton) return;
                if (lmsConfirmResolver) {
                    lmsConfirmResolver(false);
                    lmsConfirmResolver = null;
                }
                const isError = type === 'error' || (type === 'auto' && /lỗi|không thể|thất bại/i.test(String(message)));
                icon.classList.toggle('error', isError);
                icon.innerHTML = isError ? "<i class='bx bx-x'></i>" : "<i class='bx bx-check'></i>";
                title.textContent = isError ? 'Có lỗi xảy ra' : 'Thông báo';
                messageElement.textContent = String(message || '');
                if (aiWarning) aiWarning.hidden = !showAiWarning;
                if (cancelButton) cancelButton.hidden = true;
                overlay.hidden = false;
                requestAnimationFrame(() => okButton.focus());
            };
            const closeLmsDialog = (confirmed = false) => {
                const overlay = document.getElementById('lms-dialog-overlay');
                if (overlay) overlay.hidden = true;
                if (lmsConfirmResolver) {
                    const resolver = lmsConfirmResolver;
                    lmsConfirmResolver = null;
                    resolver(confirmed);
                }
            };
            const showLmsConfirm = message => new Promise(resolve => {
                const overlay = document.getElementById('lms-dialog-overlay');
                const icon = document.getElementById('lms-dialog-icon');
                const title = document.getElementById('lms-dialog-title');
                const messageElement = document.getElementById('lms-dialog-message');
                const aiWarning = document.getElementById('lms-dialog-ai-warning');
                const okButton = document.getElementById('lms-dialog-ok');
                const cancelButton = document.getElementById('lms-dialog-cancel');
                if (!overlay || !icon || !title || !messageElement || !okButton || !cancelButton) {
                    resolve(false);
                    return;
                }
                lmsConfirmResolver = resolve;
                icon.classList.remove('error');
                icon.innerHTML = "<i class='bx bx-help-circle'></i>";
                title.textContent = 'Xác nhận thao tác';
                messageElement.textContent = String(message || '');
                aiWarning.hidden = true;
                cancelButton.hidden = false;
                overlay.hidden = false;
                requestAnimationFrame(() => okButton.focus());
            });
            document.getElementById('lms-dialog-ok')?.addEventListener('click', () => closeLmsDialog(true));
            document.getElementById('lms-dialog-cancel')?.addEventListener('click', () => closeLmsDialog(false));
            document.getElementById('lms-dialog-overlay')?.addEventListener('click', event => {
                if (event.target === event.currentTarget) closeLmsDialog(false);
            });
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape') closeLmsDialog(false);
            });
            const readAjaxJson = async response => {
                const responseText = await response.text();
                try {
                    return JSON.parse(responseText);
                } catch (error) {
                    console.error('Phản hồi AJAX không hợp lệ:', responseText);
                    throw new Error('Máy chủ trả về dữ liệu không hợp lệ. Vui lòng thử lại.');
                }
            };

            const formatScore = score => {
                const rounded = Math.round((Number(score) + Number.EPSILON) * 100) / 100;
                return Number.isInteger(rounded) ? String(rounded) : String(rounded).replace(/0+$/, '').replace(/\.$/, '');
            };
            const updateAssignmentTotalScores = () => {
                const total = [...document.querySelectorAll('.module-score-display')]
                    .reduce((sum, element) => {
                        const score = Number(element.dataset.score);
                        return element.dataset.score !== '' && Number.isFinite(score) ? sum + score : sum;
                    }, 0);
                const formattedTotal = formatScore(total);
                const earnedScore = document.getElementById('assignment-earned-score');
                const summaryScore = document.getElementById('assignment-summary-score');
                if (earnedScore) earnedScore.textContent = formattedTotal;
                if (summaryScore) summaryScore.textContent = formattedTotal;
            };
            const applyGradingResultToCard = (card, result, button = null) => {
                if (!card || result.action !== 'graded') return;
                const scoreDisplay = card.querySelector('.module-score-display');
                if (scoreDisplay) {
                    scoreDisplay.style.fontSize = '24px';
                    scoreDisplay.dataset.score = String(result.score);
                    scoreDisplay.textContent = `${formatScore(result.score)} / ${formatScore(result.max_score)}`;
                }
                const feedbackDisplay = card.querySelector('.module-feedback-display');
                if (feedbackDisplay) renderFeedback(feedbackDisplay, result.feedback);
                if (button) {
                    button.disabled = false;
                    button.innerHTML = "<i class='bx bx-refresh'></i> Chấm lại AI";
                }
                updateAssignmentTotalScores();
            };

            const setupUploadForm = form => {
                const dropZone = form.querySelector('.file-upload');
                const fileInput = form.querySelector('input[type="file"]');
                const fileNameDisplay = form.querySelector('[id^="name_display_"]');
                const submitButton = form.querySelector('button[type="submit"]');
                if (!dropZone || !fileInput) return;

                const renderUploadedFile = result => {
                    const status = document.createElement('div');
                    status.className = 'submitted-file-panel';
                    status.style.cssText = 'background:rgba(16,185,129,.05);border:1px solid rgba(16,185,129,.25);padding:15px;border-radius:8px;';

                    const notice = document.createElement('div');
                    notice.style.cssText = 'color:var(--success);font-weight:700;margin-bottom:12px;';
                    notice.innerHTML = "<i class='bx bx-check-circle'></i> ";
                    notice.append(document.createTextNode(result.message));

                    const fileLine = document.createElement('div');
                    fileLine.style.cssText = 'display:flex;justify-content:space-between;gap:12px;color:#fff;font-weight:700;overflow-wrap:anywhere;margin-bottom:12px;';
                    fileLine.innerHTML = "<i class='bx bxs-file'></i> ";
                    fileLine.append(document.createTextNode(result.file_name));
                    const scoreDisplay = document.createElement('span');
                    scoreDisplay.className = 'module-score-display';
                    scoreDisplay.dataset.score = '';
                    scoreDisplay.style.cssText = 'margin-left:auto;color:var(--success);white-space:nowrap;';
                    scoreDisplay.innerHTML = "<i class='bx bx-time-five'></i> Đã tải lên, chưa chấm";
                    fileLine.append(scoreDisplay);

                    const downloadLink = document.createElement('a');
                    downloadLink.href = result.download_url;
                    downloadLink.target = '_blank';
                    downloadLink.rel = 'noopener noreferrer';
                    downloadLink.className = 'btn btn-outline';
                    downloadLink.style.cssText = 'display:inline-flex;padding:7px 12px;margin-bottom:14px;font-size:13px;text-decoration:none;';
                    downloadLink.innerHTML = "<i class='bx bx-download'></i> Tải lại file đã nộp";

                    const actions = document.createElement('div');
                    actions.className = 'submission-actions';
                    actions.style.cssText = 'display:flex;gap:10px;';
                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
                    const createActionForm = (action, label, icon, style, confirmMessage) => {
                        const actionForm = document.createElement('form');
                        actionForm.method = 'POST';
                        actionForm.style.flex = '1';
                        [['csrf_token', csrfToken], ['action', action], ['module_name', result.module]].forEach(([name, value]) => {
                            const input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = name;
                            input.value = value;
                            actionForm.appendChild(input);
                        });
                        actionForm.dataset.confirmMessage = confirmMessage;
                        if (action === 'regrade_module' && /\.(zip|rar|7z)$/i.test(String(result.file_name || ''))) {
                            const passwordLabel = document.createElement('label');
                            passwordLabel.className = 'archive-password-field';
                            const passwordText = document.createElement('span');
                            passwordText.innerHTML = "<i class='bx bx-lock-alt'></i> Mật khẩu file nén (nếu có)";
                            const passwordInput = document.createElement('input');
                            passwordInput.type = 'password';
                            passwordInput.name = 'archive_password';
                            passwordInput.maxLength = 256;
                            passwordInput.autocomplete = 'off';
                            passwordInput.placeholder = 'Nhập mật khẩu ZIP/RAR/7Z';
                            passwordLabel.append(passwordText, passwordInput);
                            actionForm.appendChild(passwordLabel);
                        }
                        const button = document.createElement('button');
                        button.type = 'submit';
                        button.className = 'btn';
                        button.style.cssText = `width:100%;padding:8px;font-size:13px;${style}`;
                        button.innerHTML = `<i class='bx ${icon}'></i> ${label}`;
                        actionForm.appendChild(button);
                        return actionForm;
                    };
                    actions.append(
                        createActionForm('regrade_module', 'Chấm bài bằng AI', 'bx-bot', 'background:rgba(56,189,248,.1);color:#38bdf8;border:1px solid rgba(56,189,248,.3);', 'Bắt đầu chấm file này bằng AI?'),
                        createActionForm('delete_module', 'Xóa & nộp lại', 'bx-reset', 'background:rgba(239,68,68,.1);color:#ef4444;border:1px solid rgba(239,68,68,.3);', 'Bạn có chắc chắn muốn xóa file vừa tải lên?')
                    );
                    const feedbackDisplay = document.createElement('div');
                    feedbackDisplay.className = 'module-feedback-display';
                    status.append(notice, fileLine, downloadLink, feedbackDisplay, actions);
                    form.replaceWith(status);
                    updateAssignmentTotalScores();
                };

                const uploadFile = async () => {
                    if (!fileInput.files.length) return;
                    if (!isAcceptedFile(fileInput.files[0])) {
                        showLmsDialog(`File “${fileInput.files[0].name}” không đúng định dạng cho phần này.`, 'error');
                        fileInput.value = '';
                        if (fileNameDisplay) fileNameDisplay.textContent = '';
                        return;
                    }
                    dropZone.classList.add('uploading');
                    dropZone.querySelector('p')?.replaceChildren(document.createTextNode('Đang tải file lên...'));
                    if (submitButton) {
                        submitButton.disabled = true;
                        submitButton.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Đang tải lên...";
                    }
                    try {
                        const response = await fetch(window.location.href, {
                            method: 'POST',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                            body: new FormData(form)
                        });
                        const result = await readAjaxJson(response);
                        if (!response.ok || !result.success) throw new Error(result.message || 'Không thể tải file lên.');
                        renderUploadedFile(result);
                    } catch (error) {
                        showLmsDialog(error.message || 'Không thể kết nối máy chủ.', 'error');
                        dropZone.classList.remove('uploading');
                        dropZone.querySelector('p')?.replaceChildren(document.createTextNode('Kéo file vào đây hoặc click để chọn file'));
                        if (submitButton) {
                            submitButton.disabled = false;
                            submitButton.innerHTML = "<i class='bx bx-cloud-upload'></i> Tải file lên";
                        }
                    }
                };

                form.addEventListener('submit', event => {
                    event.preventDefault();
                    uploadFile();
                });

                const isAcceptedFile = file => {
                    const acceptedExtensions = fileInput.accept
                        .split(',')
                        .map(value => value.trim().toLowerCase())
                        .filter(Boolean);
                    if (!acceptedExtensions.length) return true;
                    const fileName = file.name.toLowerCase();
                    return acceptedExtensions.some(extension => fileName.endsWith(extension));
                };

                ['dragenter', 'dragover'].forEach(eventName => {
                    dropZone.addEventListener(eventName, event => {
                        event.preventDefault();
                        event.stopPropagation();
                        dropZone.classList.add('drag-over');
                    });
                });

                ['dragleave', 'drop'].forEach(eventName => {
                    dropZone.addEventListener(eventName, event => {
                        event.preventDefault();
                        event.stopPropagation();
                        dropZone.classList.remove('drag-over');
                    });
                });

                dropZone.addEventListener('drop', event => {
                    const file = event.dataTransfer.files[0];
                    if (!file) return;
                    if (!isAcceptedFile(file)) {
                        showLmsDialog(`File “${file.name}” không đúng định dạng cho phần này.`, 'error');
                        return;
                    }

                    const transfer = new DataTransfer();
                    transfer.items.add(file);
                    fileInput.files = transfer.files;
                    if (fileNameDisplay) fileNameDisplay.textContent = file.name;
                    form.requestSubmit();
                });
            };
            document.querySelectorAll('.module-upload-form').forEach(setupUploadForm);

            const acceptedFilesForModule = moduleName => {
                switch (String(moduleName || '').trim().toLowerCase()) {
                    case 'word': return '.doc,.docx';
                    case 'excel': return '.xls,.xlsx';
                    case 'powerpoint':
                    case 'power point': return '.ppt,.pptx';
                    case 'windows': return '.zip,.rar,.7z';
                    default: return '.doc,.docx,.xls,.xlsx,.ppt,.pptx,.pdf';
                }
            };

            const createUploadForm = moduleName => {
                const form = document.createElement('form');
                form.method = 'POST';
                form.enctype = 'multipart/form-data';
                form.className = 'module-upload-form';

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'upload_module_only';
                const moduleInput = document.createElement('input');
                moduleInput.type = 'hidden';
                moduleInput.name = 'module_name';
                moduleInput.value = moduleName;
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = document.querySelector('meta[name="csrf-token"]')?.content || '';

                const fileInput = document.createElement('input');
                fileInput.type = 'file';
                fileInput.name = `sub_file_${moduleName}`;
                fileInput.required = true;
                fileInput.style.display = 'none';
                fileInput.accept = acceptedFilesForModule(moduleName);

                const dropZone = document.createElement('div');
                dropZone.className = 'file-upload';
                dropZone.innerHTML = "<i class='bx bx-cloud-upload' style='font-size:32px;color:var(--primary)'></i><p style='font-size:13px;margin:10px 0'>Kéo file vào đây hoặc click để chọn file</p><p style='color:var(--primary);font-weight:600;font-size:13px;margin:0'></p>";
                dropZone.lastElementChild.id = `name_display_${moduleName}`;
                dropZone.addEventListener('click', () => fileInput.click());
                fileInput.addEventListener('change', () => {
                    dropZone.lastElementChild.textContent = fileInput.files[0]?.name || '';
                });

                const button = document.createElement('button');
                button.type = 'submit';
                button.className = 'btn btn-primary';
                button.style.cssText = 'width:100%;margin-top:15px;';
                button.innerHTML = "<i class='bx bx-cloud-upload'></i> Tải file lên";
                form.append(csrfInput, actionInput, moduleInput, dropZone, fileInput, button);
                setupUploadForm(form);
                return form;
            };

            const renderFeedback = (container, feedback) => {
                container.replaceChildren();
                if (feedback?.comment) {
                    const comment = document.createElement('div');
                    comment.style.cssText = 'font-size:13px;color:rgba(255,255,255,.8);margin:10px 0;';
                    const strong = document.createElement('strong');
                    strong.textContent = 'Nhận xét: ';
                    comment.append(strong, document.createTextNode(feedback.comment));
                    container.append(comment);
                }
                if (Array.isArray(feedback?.errors) && feedback.errors.length) {
                    const errors = document.createElement('div');
                    errors.style.cssText = 'font-size:13px;color:#f87171;';
                    const title = document.createElement('strong');
                    title.textContent = 'Lỗi trừ điểm:';
                    const list = document.createElement('ul');
                    feedback.errors.forEach(error => {
                        const item = document.createElement('li');
                        item.textContent = error;
                        list.append(item);
                    });
                    errors.append(title, list);
                    container.append(errors);
                }
            };

            const renderGradingProgress = (display, status, elapsedMs) => {
                if (!display) return;
                const processing = status === 'processing';
                const seconds = Math.max(0, Math.floor(elapsedMs / 1000));
                let percent = 10;
                let label = 'Đang xếp hàng chấm';
                let hint = 'Worker AI sẽ nhận bài trong giây lát';
                if (processing && seconds < 15) {
                    percent = 28; label = 'Đang phân tích file'; hint = 'Đọc cấu trúc, công thức và dữ liệu';
                } else if (processing && seconds < 45) {
                    percent = 52; label = 'Đang đối chiếu file mẫu'; hint = 'So khớp bài làm với đáp án/file chuẩn';
                } else if (processing && seconds < 90) {
                    percent = 76; label = 'AI đang chấm tiêu chí'; hint = 'Đánh giá chức năng và tạo nhận xét';
                } else if (processing) {
                    percent = Math.min(94, 82 + Math.floor((seconds - 90) / 20));
                    label = 'Đang hoàn tất kết quả'; hint = 'Bài lớn có thể cần thêm ít phút';
                }
                display.dataset.score = '';
                display.style.fontSize = 'inherit';
                display.innerHTML = `<div class="ai-grading-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${percent}">
                    <div class="ai-grading-progress__top"><i class='bx ${processing ? 'bx-loader-alt bx-spin' : 'bx-time-five'}'></i><span>${label} · ${percent}%</span></div>
                    <div class="ai-grading-progress__track"><div class="ai-grading-progress__bar" style="width:${percent}%"></div></div>
                    <span class="ai-grading-progress__hint">${hint}</span>
                </div>`;
            };

            const waitForGradingJob = async (jobId, button, scoreDisplay = null) => {
                const startedAt = Date.now();
                renderGradingProgress(scoreDisplay, 'queued', 0);
                // The job continues in the background; do not trap learners in a long modal.
                while (Date.now() - startedAt < 3 * 60 * 1000) {
                    await new Promise(resolve => setTimeout(resolve, 1500));
                    const statusUrl = new URL(window.location.href);
                    statusUrl.searchParams.set('action', 'grading_job_status');
                    statusUrl.searchParams.set('job_id', String(jobId));
                    const response = await fetch(statusUrl, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        cache: 'no-store'
                    });
                    const status = await readAjaxJson(response);
                    if (status.status === 'completed' || status.action === 'graded') return status;
                    if (status.status === 'failed' || !status.success) {
                        throw new Error(status.message || 'AI không thể hoàn thành yêu cầu chấm.');
                    }
                    renderGradingProgress(scoreDisplay, status.status, Date.now() - startedAt);
                    if (button) {
                        button.innerHTML = status.status === 'processing'
                            ? "<i class='bx bx-loader-alt bx-spin'></i> AI đang chấm..."
                            : "<i class='bx bx-time-five'></i> Đang chờ chấm...";
                    }
                }
                throw new Error('AI vẫn đang xử lý nền sau 3 phút. Bạn có thể tải lại trang sau ít phút để xem kết quả; không cần nộp lại bài.');
            };

            document.addEventListener('submit', async event => {
                const actionForm = event.target;
                if (!(actionForm instanceof HTMLFormElement)) return;
                const action = actionForm.querySelector('input[name="action"]')?.value;
                if (!['regrade_module', 'delete_module'].includes(action) || event.defaultPrevented) return;
                event.preventDefault();
                const confirmMessage = actionForm.dataset.confirmMessage;
                if (confirmMessage && !(await showLmsConfirm(confirmMessage))) return;

                const button = actionForm.querySelector('button[type="submit"]');
                const originalButtonHtml = button?.innerHTML || '';
                if (button) {
                    button.disabled = true;
                    button.innerHTML = action === 'regrade_module'
                        ? "<i class='bx bx-loader-alt bx-spin'></i> Đang chấm..."
                        : "<i class='bx bx-loader-alt bx-spin'></i> Đang xóa...";
                }
                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: new FormData(actionForm)
                    });
                    let result = await readAjaxJson(response);
                    if (!response.ok || !result.success) throw new Error(result.message || 'Không thể thực hiện thao tác.');
                    const card = actionForm.closest('.module-card');
                    if (!card) return;

                    if (result.action === 'queued') {
                        const scoreDisplay = card.querySelector('.module-score-display');
                        if (scoreDisplay) {
                            scoreDisplay.dataset.score = '';
                            scoreDisplay.style.fontSize = '14px';
                            scoreDisplay.innerHTML = "<i class='bx bx-time-five'></i> Đang chờ AI chấm";
                        }
                        result = await waitForGradingJob(result.job_id, button, scoreDisplay);
                    }

                    if (result.action === 'graded') {
                        applyGradingResultToCard(card, result, button);
                        showLmsDialog(result.message, 'success', true);
                        window.lmsCelebrate?.();
                    } else if (result.action === 'deleted') {
                        card.querySelector('.submitted-file-panel')?.remove();
                        card.querySelector('.submission-actions')?.remove();
                        card.append(createUploadForm(result.module));
                        updateAssignmentTotalScores();
                    }
                } catch (error) {
                    showLmsDialog(error.message || 'Không thể kết nối máy chủ.', 'error');
                    if (button) button.innerHTML = originalButtonHtml;
                } finally {
                    if (button && button.isConnected) button.disabled = false;
                }
            });

            activeGradingJobs.forEach(async job => {
                const card = [...document.querySelectorAll('.module-card')]
                    .find(candidate => candidate.dataset.module === job.module_name);
                if (!card) return;
                const regradeForm = card.querySelector('input[name="action"][value="regrade_module"]')?.form;
                const button = regradeForm?.querySelector('button[type="submit"]') || null;
                const scoreDisplay = card.querySelector('.module-score-display');
                if (scoreDisplay) {
                    scoreDisplay.dataset.score = '';
                    scoreDisplay.style.fontSize = '14px';
                    renderGradingProgress(scoreDisplay, 'processing', 0);
                }
                if (button) {
                    button.disabled = true;
                    button.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> AI đang chấm...";
                }
                try {
                    const result = await waitForGradingJob(job.id, button, scoreDisplay);
                    applyGradingResultToCard(card, result, button);
                    showLmsDialog(result.message, 'success', true);
                    window.lmsCelebrate?.();
                } catch (error) {
                    if (button) {
                        button.disabled = false;
                        button.innerHTML = "<i class='bx bx-refresh'></i> Chấm lại AI";
                    }
                    showLmsDialog(error.message || 'Không thể cập nhật kết quả chấm AI.', 'error');
                }
            });

            const examTimer = document.getElementById('exam-timer');
            if (examTimer) {
                let secondsLeft = Math.max(0, Number(examTimer.dataset.seconds || 0));
                let timerPaused = examTimer.dataset.paused === '1';
                let countdown = null;
                const timerValue = document.getElementById('exam-timer-value');
                const timerLabel = examTimer.querySelector('span:not(.exam-timer-value)');
                const pauseForm = document.getElementById('exam-pause-form');
                const pauseButton = document.getElementById('exam-pause-button');
                const setWorkLocked = locked => {
                    document.querySelectorAll('form').forEach(form => {
                        if (form === pauseForm) return;
                        form.querySelectorAll('button[type="submit"], input[type="file"]').forEach(control => {
                            control.disabled = locked;
                        });
                    });
                };
                const renderTimer = () => {
                    const hours = Math.floor(secondsLeft / 3600);
                    const minutes = Math.floor((secondsLeft % 3600) / 60);
                    const seconds = secondsLeft % 60;
                    timerValue.textContent = [hours, minutes, seconds].map(value => String(value).padStart(2, '0')).join(':');
                    examTimer.classList.toggle('is-warning', !timerPaused && secondsLeft > 60 && secondsLeft <= 300);
                    examTimer.classList.toggle('is-critical', !timerPaused && secondsLeft <= 60);
                    if (timerPaused) {
                        examTimer.style.background = 'rgba(146,64,14,.97)';
                    } else if (secondsLeft <= 300) {
                        examTimer.style.background = 'rgba(185,28,28,.97)';
                    } else {
                        examTimer.style.background = 'rgba(127,29,29,.96)';
                    }
                };
                const startCountdown = () => {
                    if (countdown || secondsLeft <= 0 || timerPaused) return;
                    countdown = setInterval(() => {
                        secondsLeft = Math.max(0, secondsLeft - 1);
                        renderTimer();
                        if (secondsLeft === 0) {
                            clearInterval(countdown);
                            countdown = null;
                            document.querySelectorAll('form button[type="submit"]').forEach(button => button.disabled = true);
                            setTimeout(() => window.location.reload(), 1000);
                        }
                    }, 1000);
                };
                renderTimer();
                setWorkLocked(timerPaused);
                startCountdown();

                if (pauseForm) {
                    pauseForm.addEventListener('submit', async event => {
                        event.preventDefault();
                        pauseButton.disabled = true;
                        try {
                            const response = await fetch(window.location.href, {
                                method: 'POST',
                                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                                body: new FormData(pauseForm)
                            });
                            const result = await readAjaxJson(response);
                            if (!response.ok || !result.success) throw new Error('Không thể thay đổi trạng thái bài thi.');
                            timerPaused = Boolean(result.paused);
                            secondsLeft = Math.max(0, Number(result.remaining_seconds || 0));
                            examTimer.dataset.paused = timerPaused ? '1' : '0';
                            if (timerPaused && countdown) {
                                clearInterval(countdown);
                                countdown = null;
                            }
                            timerLabel.textContent = timerPaused ? 'Đang tạm dừng:' : 'Thời gian còn lại:';
                            pauseButton.innerHTML = timerPaused
                                ? "<i class='bx bx-play'></i> Tiếp tục"
                                : "<i class='bx bx-pause'></i> Tạm dừng";
                            setWorkLocked(timerPaused);
                            renderTimer();
                            startCountdown();
                        } catch (error) {
                            showLmsDialog(error.message || 'Không thể kết nối máy chủ.', 'error');
                        } finally {
                            pauseButton.disabled = false;
                        }
                    });
                }
            }

            function renderAnalysis(data) {
                for (const [module, reqs] of Object.entries(data)) {
                    const card = Array.from(document.querySelectorAll('.module-criteria')).find(element => element.dataset.module === module);
                    if (!card) continue;
                    const container = card.querySelector('.module-criteria-content');
                    const list = document.createElement('ul');
                    list.style.margin = '0';
                    list.style.paddingLeft = '22px';
                    for (let line of String(reqs ?? '').split(/\r?\n|\\n/)) {
                        line = line.trim();
                        if (line.startsWith('-')) line = line.substring(1).trim();
                        if (line.startsWith('*')) line = line.substring(1).trim();
                        if (line) {
                            const item = document.createElement('li');
                            item.style.marginBottom = '6px';
                            item.textContent = line;
                            list.appendChild(item);
                        }
                    }
                    container.replaceChildren(list);
                }
            }

            function setAnalyzeBusy(isBusy) {
                document.querySelectorAll('.analyze-trigger').forEach(button => {
                    button.disabled = isBusy;
                    button.innerHTML = isBusy
                        ? "<i class='bx bx-loader-alt bx-spin'></i> Đang phân tích..."
                        : "<i class='bx bx-search-alt'></i> AI phân tích đề bài";
                });
            }
            
            if (existingAnalysis) {
                renderAnalysis(existingAnalysis);
            }
            
            function analyzePrompt() {
                setAnalyzeBusy(true);
                
                fetch('ajax_analyze_prompt.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({
                        assignment_id: <?php echo $assignment_id; ?>
                    })
                })
                .then(response => response.json())
                .then(data => {
                    setAnalyzeBusy(false);
                    if (data.status === 'success') {
                        renderAnalysis(data.analysis);
                    } else {
                        showLmsDialog('Lỗi: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    setAnalyzeBusy(false);
                    showLmsDialog('Lỗi kết nối khi gọi AI: ' + error, 'error');
                });
            }
        </script>

<?php require_once '../includes/footer.php'; ?>

<?php if (!$previewMode && !$adminTestMode): ?>
<!-- ============================================================
     AI CHAT WIDGET — Trợ lý AI học tập
     ============================================================ -->
<style>
/* ---- Floating Button ---- */
#ai-chat-fab {
    position: fixed;
    bottom: 28px;
    right: 28px;
    z-index: 1200;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0 20px 0 16px;
    height: 52px;
    border: none;
    border-radius: 26px;
    background: linear-gradient(135deg, var(--primary), #8b5cf6);
    color: #fff;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 6px 24px rgba(99,102,241,.45);
    transition: transform .2s ease, box-shadow .2s ease, opacity .2s ease;
}
#ai-chat-fab:hover { transform: translateY(-3px); box-shadow: 0 10px 32px rgba(99,102,241,.6); }
#ai-chat-fab .fab-icon { font-size: 22px; line-height: 1; }
#ai-chat-fab .fab-label { letter-spacing: .01em; }
#ai-chat-fab .fab-badge {
    position: absolute;
    top: -4px; right: -4px;
    width: 10px; height: 10px;
    border-radius: 50%;
    background: #10b981;
    border: 2px solid var(--bg-dark, #0f172a);
    display: none;
}
#ai-chat-fab.has-reply .fab-badge { display: block; }

/* ---- Panel ---- */
#ai-chat-panel {
    position: fixed;
    bottom: 92px;
    right: 28px;
    z-index: 1200;
    width: min(420px, calc(100vw - 32px));
    max-height: min(580px, calc(100vh - 120px));
    display: flex;
    flex-direction: column;
    background: var(--glass-bg, rgba(30,41,59,.98));
    backdrop-filter: blur(18px);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 18px;
    box-shadow: 0 24px 64px rgba(0,0,0,.4);
    overflow: hidden;
    transform-origin: bottom right;
    transition: transform .28s cubic-bezier(.34,1.56,.64,1), opacity .22s ease;
}
#ai-chat-panel[hidden] { display: none !important; }
#ai-chat-panel.chat-enter { animation: chatEnter .28s cubic-bezier(.34,1.56,.64,1) both; }
@keyframes chatEnter {
    from { transform: scale(.8) translateY(16px); opacity: 0; }
    to   { transform: scale(1) translateY(0);     opacity: 1; }
}

/* ---- Header ---- */
#ai-chat-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px 16px 12px;
    background: linear-gradient(135deg, rgba(99,102,241,.25), rgba(139,92,246,.18));
    border-bottom: 1px solid rgba(255,255,255,.08);
    flex-shrink: 0;
}
.chat-header-avatar {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--primary), #8b5cf6);
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}
.chat-header-info { flex: 1; min-width: 0; }
.chat-header-name { font-size: 14px; font-weight: 700; color: var(--text-main, #f8fafc); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.chat-header-status { font-size: 11px; color: #10b981; display: flex; align-items: center; gap: 4px; }
.chat-header-status::before { content: ''; display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: #10b981; }
#ai-chat-close {
    background: none; border: none; color: var(--text-muted, #94a3b8);
    font-size: 20px; cursor: pointer; padding: 4px; border-radius: 6px;
    line-height: 1; transition: color .15s, background .15s;
}
#ai-chat-close:hover { color: var(--text-main); background: rgba(255,255,255,.07); }

/* ---- Messages ---- */
#ai-chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px 14px 8px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,.12) transparent;
}
.chat-bubble {
    max-width: 88%;
    padding: 10px 14px;
    border-radius: 14px;
    font-size: 13.5px;
    line-height: 1.55;
    word-break: break-word;
    animation: bubbleIn .2s ease both;
}
@keyframes bubbleIn { from { opacity:0; transform: translateY(6px); } to { opacity:1; transform:none; } }
.chat-bubble.user {
    align-self: flex-end;
    background: linear-gradient(135deg, var(--primary), #8b5cf6);
    color: #fff;
    border-bottom-right-radius: 4px;
}
.chat-bubble.assistant {
    align-self: flex-start;
    background: rgba(255,255,255,.07);
    color: var(--text-main, #f8fafc);
    border: 1px solid rgba(255,255,255,.08);
    border-bottom-left-radius: 4px;
}
.chat-bubble.system-msg {
    align-self: center;
    background: rgba(99,102,241,.12);
    color: var(--text-muted, #94a3b8);
    font-size: 12px;
    border-radius: 10px;
    text-align: center;
    max-width: 95%;
}
.chat-bubble.error-msg {
    align-self: center;
    background: rgba(239,68,68,.12);
    color: #fca5a5;
    font-size: 12px;
    border: 1px solid rgba(239,68,68,.25);
    border-radius: 10px;
    text-align: center;
    max-width: 95%;
}
/* Typing indicator */
.typing-indicator {
    display: flex; align-items: center; gap: 4px;
    padding: 10px 14px;
    background: rgba(255,255,255,.07);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 14px;
    border-bottom-left-radius: 4px;
    align-self: flex-start;
}
.typing-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: var(--text-muted, #94a3b8);
    animation: typingBounce 1.2s infinite ease-in-out;
}
.typing-dot:nth-child(2) { animation-delay: .2s; }
.typing-dot:nth-child(3) { animation-delay: .4s; }
@keyframes typingBounce { 0%,60%,100% { transform: translateY(0); } 30% { transform: translateY(-6px); } }

/* ---- Input area ---- */
#ai-chat-form {
    padding: 10px 12px 12px;
    border-top: 1px solid rgba(255,255,255,.08);
    display: flex;
    gap: 8px;
    flex-shrink: 0;
    background: rgba(0,0,0,.15);
}
#ai-chat-input {
    flex: 1;
    padding: 10px 14px;
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 22px;
    background: rgba(255,255,255,.06);
    color: var(--text-main, #f8fafc);
    font-size: 13.5px;
    outline: none;
    resize: none;
    min-height: 42px;
    max-height: 120px;
    line-height: 1.5;
    transition: border-color .15s;
    font-family: inherit;
}
#ai-chat-input:focus { border-color: var(--primary); }
#ai-chat-input::placeholder { color: var(--text-muted, #94a3b8); }
#ai-chat-send {
    width: 42px; height: 42px;
    border: none; border-radius: 50%;
    background: linear-gradient(135deg, var(--primary), #8b5cf6);
    color: #fff;
    font-size: 18px;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    transition: transform .15s, opacity .15s;
    align-self: flex-end;
}
#ai-chat-send:hover { transform: scale(1.08); }
#ai-chat-send:disabled { opacity: .45; cursor: not-allowed; transform: none; }
.chat-char-count {
    font-size: 10px;
    color: var(--text-muted);
    text-align: right;
    padding: 0 14px 2px;
    flex-basis: 100%;
}
</style>

<!-- FAB Button -->
<button id="ai-chat-fab" aria-label="Mở trợ lý AI" aria-expanded="false">
    <span class="fab-icon">🤖</span>
    <span class="fab-label">Hỏi AI</span>
    <span class="fab-badge"></span>
</button>

<!-- Chat Panel -->
<div id="ai-chat-panel" hidden role="dialog" aria-label="Trợ lý AI học tập" aria-modal="false">
    <div id="ai-chat-header">
        <div class="chat-header-avatar">🤖</div>
        <div class="chat-header-info">
            <div class="chat-header-name">Trợ lý AI – <?php echo htmlspecialchars($assignment['title'] ?? 'Bài tập'); ?></div>
            <div class="chat-header-status">Sẵn sàng hỗ trợ</div>
        </div>
        <button id="ai-chat-close" aria-label="Đóng chat">✕</button>
    </div>
    <div id="ai-chat-messages" role="log" aria-live="polite" aria-label="Lịch sử hội thoại"></div>
    <form id="ai-chat-form" autocomplete="off">
        <textarea id="ai-chat-input" rows="1"
            placeholder="Hỏi về đề bài, cách làm…"
            maxlength="2000"
            aria-label="Nhập câu hỏi"></textarea>
        <button type="submit" id="ai-chat-send" aria-label="Gửi">
            <i class="bx bx-send"></i>
        </button>
    </form>
</div>

<script>
(function () {
    'use strict';

    const ASSIGNMENT_ID  = <?php echo (int) $assignment_id; ?>;
    const CSRF_TOKEN     = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const CHAT_ENDPOINT  = '../student/ajax_ai_chat.php';

    const fab      = document.getElementById('ai-chat-fab');
    const panel    = document.getElementById('ai-chat-panel');
    const closeBtn = document.getElementById('ai-chat-close');
    const messages = document.getElementById('ai-chat-messages');
    const form     = document.getElementById('ai-chat-form');
    const input    = document.getElementById('ai-chat-input');
    const sendBtn  = document.getElementById('ai-chat-send');

    let chatHistory = [];  // { role: 'user'|'assistant', content: string }[]
    let isWaiting   = false;
    let hasOpened   = false;

    // ---- Toggle panel ----
    function openPanel() {
        panel.hidden = false;
        panel.classList.add('chat-enter');
        panel.addEventListener('animationend', () => panel.classList.remove('chat-enter'), { once: true });
        fab.setAttribute('aria-expanded', 'true');
        fab.classList.remove('has-reply');
        input.focus();
        if (!hasOpened) {
            hasOpened = true;
            appendBubble('system-msg',
                '👋 Xin chào! Tôi là Trợ lý AI của LMS Tin học Cần Thơ.\n' +
                'Tôi có thể giúp bạn hiểu đề bài và gợi ý hướng làm — nhưng sẽ không làm bài hộ bạn 😊'
            );
        }
        scrollToBottom();
    }

    function closePanel() {
        panel.hidden = true;
        fab.setAttribute('aria-expanded', 'false');
    }

    fab.addEventListener('click', () => panel.hidden ? openPanel() : closePanel());
    closeBtn.addEventListener('click', closePanel);

    // ---- Render helpers ----
    function renderMarkdown(text) {
        return text
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.+?)\*/g, '<em>$1</em>')
            .replace(/\n/g, '<br>');
    }

    function appendBubble(type, text) {
        const div = document.createElement('div');
        div.className = 'chat-bubble ' + type;
        div.innerHTML = renderMarkdown(text);
        messages.appendChild(div);
        scrollToBottom();
        return div;
    }

    function showTyping() {
        const el = document.createElement('div');
        el.className = 'typing-indicator';
        el.id = 'typing-indicator';
        el.innerHTML = '<div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div>';
        messages.appendChild(el);
        scrollToBottom();
    }

    function hideTyping() {
        document.getElementById('typing-indicator')?.remove();
    }

    function scrollToBottom() {
        requestAnimationFrame(() => { messages.scrollTop = messages.scrollHeight; });
    }

    // ---- Auto-resize textarea ----
    input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    });

    // ---- Send on Enter (Shift+Enter = newline) ----
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            form.requestSubmit();
        }
    });

    // ---- Submit ----
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const text = input.value.trim();
        if (!text || isWaiting) return;

        isWaiting = true;
        sendBtn.disabled = true;
        input.value = '';
        input.style.height = '';

        appendBubble('user', text);
        chatHistory.push({ role: 'user', content: text });
        showTyping();

        try {
            const res = await fetch(CHAT_ENDPOINT, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': CSRF_TOKEN,
                },
                body: JSON.stringify({
                    assignment_id: ASSIGNMENT_ID,
                    message: text,
                    history: chatHistory.slice(-6),
                }),
            });

            const data = await res.json().catch(() => ({ status: 'error', message: 'Phản hồi không hợp lệ.' }));

            hideTyping();

            if (data.status === 'success' && data.reply) {
                const reply = String(data.reply);
                chatHistory.push({ role: 'assistant', content: reply });
                appendBubble('assistant', reply);
                // Pulse FAB badge when panel is closed
                if (panel.hidden) {
                    fab.classList.add('has-reply');
                }
            } else {
                const msg = data.message || 'Đã xảy ra lỗi. Vui lòng thử lại.';
                appendBubble('error-msg', '⚠️ ' + msg);
            }
        } catch (err) {
            hideTyping();
            appendBubble('error-msg', '⚠️ Không thể kết nối đến AI. Hãy kiểm tra kết nối mạng.');
        } finally {
            isWaiting = false;
            sendBtn.disabled = false;
            input.focus();
        }
    });
})();
</script>
<?php endif; ?>
