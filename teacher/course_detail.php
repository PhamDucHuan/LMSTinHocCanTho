<?php
require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/quiz_schema.php';
require_once '../includes/notifications.php';
require_once '../includes/audit.php';
/** @var PDO $pdo */

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['teacher', 'admin'])) {
    header('Location: ../index.php');
    exit;
}
ensureQuizSchema($pdo);

$course_id = $_GET['id'] ?? 0;

$ownerCheck = $_SESSION['user_role'] === 'admin'
    ? $pdo->prepare("SELECT id, title FROM courses WHERE id = ?")
    : $pdo->prepare("SELECT id, title FROM courses WHERE id = ? AND teacher_id = ?");
$ownerCheck->execute($_SESSION['user_role'] === 'admin' ? [$course_id] : [$course_id, $_SESSION['user_id']]);
$ownedCourse = $ownerCheck->fetch();
if (!$ownedCourse) {
    http_response_code(404); exit('Khóa học không tồn tại hoặc bạn không có quyền truy cập.');
}

// Duyệt hoặc từ chối yêu cầu ghi danh
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['approve_enrollment', 'reject_enrollment'], true)) {
    verifyCsrfToken();
    $requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    $requestStmt = $pdo->prepare("
        SELECT id, student_id
        FROM course_enrollment_requests
        WHERE id = ? AND course_id = ? AND status = 'pending'
    ");
    $requestStmt->execute([$requestId, $course_id]);
    $enrollmentRequest = $requestStmt->fetch();

    if (!$enrollmentRequest) {
        $_SESSION['error'] = 'Yêu cầu ghi danh không còn hợp lệ hoặc đã được xử lý.';
    } elseif ($_POST['action'] === 'approve_enrollment') {
        $pdo->beginTransaction();
        try {
            $enrollStmt = $pdo->prepare("INSERT IGNORE INTO course_enrollments (course_id, student_id) VALUES (?, ?)");
            $enrollStmt->execute([$course_id, $enrollmentRequest['student_id']]);
            $reviewStmt = $pdo->prepare("UPDATE course_enrollment_requests SET status = 'approved', reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
            $reviewStmt->execute([$_SESSION['user_id'], $requestId]);
            createNotification(
                $pdo,
                (int) $enrollmentRequest['student_id'],
                'enrollment_approved',
                'Yêu cầu ghi danh đã được duyệt',
                "Bạn đã được tham gia khóa “{$ownedCourse['title']}”.",
                '../student/course.php?id=' . (int) $course_id,
                ['course_id' => (int) $course_id]
            );
            $pdo->commit();
            writeAuditLog($pdo, 'enrollment.approved', 'course_enrollment_request', (int) $requestId);
            $_SESSION['success'] = 'Đã duyệt yêu cầu và ghi danh học viên vào khóa học.';
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Không thể duyệt yêu cầu ghi danh.';
        }
    } else {
        $reviewStmt = $pdo->prepare("UPDATE course_enrollment_requests SET status = 'rejected', reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
        $reviewStmt->execute([$_SESSION['user_id'], $requestId]);
        createNotification(
            $pdo,
            (int) $enrollmentRequest['student_id'],
            'enrollment_rejected',
            'Yêu cầu ghi danh chưa được duyệt',
            "Yêu cầu tham gia khóa “{$ownedCourse['title']}” đã bị từ chối.",
            '../student/course.php?id=' . (int) $course_id,
            ['course_id' => (int) $course_id]
        );
        writeAuditLog($pdo, 'enrollment.rejected', 'course_enrollment_request', (int) $requestId);
        $_SESSION['success'] = 'Đã từ chối yêu cầu ghi danh.';
    }
    header('Location: course_detail.php?id=' . $course_id);
    exit;
}

// Xử lý thêm học viên
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'enroll_student') {
    verifyCsrfToken();
    $student_id = $_POST['student_id'];
    
    // Kiểm tra đã thêm chưa
    $check = $pdo->prepare("SELECT * FROM course_enrollments WHERE course_id = ? AND student_id = ?");
    $check->execute([$course_id, $student_id]);
    
    if (!$check->fetch()) {
        $stmt = $pdo->prepare("INSERT INTO course_enrollments (course_id, student_id) VALUES (?, ?)");
        $stmt->execute([$course_id, $student_id]);
        $reviewStmt = $pdo->prepare("UPDATE course_enrollment_requests SET status = 'approved', reviewed_at = NOW(), reviewed_by = ? WHERE course_id = ? AND student_id = ?");
        $reviewStmt->execute([$_SESSION['user_id'], $course_id, $student_id]);
        $_SESSION['success'] = "Thêm học viên vào khóa thành công!";
    } else {
        $_SESSION['error'] = "Học viên này đã ở trong khóa học.";
    }
    header("Location: course_detail.php?id=" . $course_id);
    exit;
}

// Xóa học viên
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_student') {
    verifyCsrfToken();
    $student_id = $_POST['student_id'] ?? 0;
    $stmt = $pdo->prepare("DELETE FROM course_enrollments WHERE course_id = ? AND student_id = ?");
    $stmt->execute([$course_id, $student_id]);
    $_SESSION['success'] = "Đã xóa học viên khỏi khóa.";
    header("Location: course_detail.php?id=" . $course_id);
    exit;
}

// Fetch thông tin khóa học
if ($_SESSION['user_role'] === 'admin') {
    $stmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
    $stmt->execute([$course_id]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM courses WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$course_id, $_SESSION['user_id']]);
}
$course = $stmt->fetch();

if (!$course) {
    die("Khóa học không tồn tại.");
}

// Fetch danh sách học viên trong khóa
$stmt = $pdo->prepare("SELECT u.*, ce.enrolled_at FROM course_enrollments ce JOIN users u ON ce.student_id = u.id WHERE ce.course_id = ? ORDER BY ce.enrolled_at DESC");
$stmt->execute([$course_id]);
$enrolled_students = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT er.id, er.requested_at, u.id AS student_id, u.name, u.email
    FROM course_enrollment_requests er
    JOIN users u ON u.id = er.student_id
    WHERE er.course_id = ? AND er.status = 'pending'
    ORDER BY er.requested_at ASC
");
$stmt->execute([$course_id]);
$pending_requests = $stmt->fetchAll();

// Fetch danh sách TẤT CẢ học viên để chọn (chưa có trong khóa)
$stmt = $pdo->prepare("
    SELECT id, name, email FROM users 
    WHERE role = 'student' 
    AND id NOT IN (SELECT student_id FROM course_enrollments WHERE course_id = ?)
");
$stmt->execute([$course_id]);
$available_students = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT a.id, a.title, a.type, a.due_date, a.created_at,
           COUNT(s.id) AS submission_count, MAX(s.submitted_at) AS latest_submission_at
    FROM assignments a
    LEFT JOIN submissions s ON s.assignment_id = a.id
    WHERE a.course_id = ?
    GROUP BY a.id, a.title, a.type, a.due_date, a.created_at
    ORDER BY a.created_at DESC
");
$stmt->execute([$course_id]);
$course_assignments = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT q.id, q.title, q.duration_minutes, q.is_published,
           COUNT(DISTINCT qs.id) AS section_count, COUNT(qq.id) AS question_count,
           COUNT(DISTINCT CASE WHEN qa.submitted_at IS NOT NULL THEN qa.id END) AS attempt_count
    FROM quizzes q
    LEFT JOIN quiz_sections qs ON qs.quiz_id = q.id
    LEFT JOIN quiz_questions qq ON qq.section_id = qs.id
    LEFT JOIN quiz_attempts qa ON qa.quiz_id = q.id
    WHERE q.course_id = ?
    GROUP BY q.id
    ORDER BY q.sort_order, q.id
");
$stmt->execute([$course_id]);
$course_quizzes = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT s.id, s.assignment_id, s.file_drive_id, s.file_name, s.submitted_files,
           s.score, s.submitted_at, a.title AS assignment_title, a.type AS assignment_type,
           u.name AS student_name, u.email AS student_email
    FROM submissions s
    JOIN assignments a ON a.id = s.assignment_id
    JOIN users u ON u.id = s.student_id
    WHERE a.course_id = ?
    ORDER BY s.submitted_at DESC
");
$stmt->execute([$course_id]);
$course_submissions = $stmt->fetchAll();

$submissionDownloadUrl = static function (int $submissionId, ?string $module = null): string {
    $url = '../download.php?kind=submission&id=' . $submissionId;
    return $module !== null ? $url . '&module=' . rawurlencode($module) : $url;
};

$page_title = "Quản lý Khóa học: " . htmlspecialchars($course['title']);
require_once '../includes/header.php';
?>

<div class="content">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div>
            <a href="courses.php" style="color: var(--primary); text-decoration: none; margin-bottom: 10px; display: inline-block;"><i class='bx bx-arrow-back'></i> Quay lại Khóa học</a>
            <h2><i class='bx bx-book-open'></i> <?php echo htmlspecialchars($course['title']); ?></h2>
        </div>
        <a href="quizzes.php?course_id=<?php echo (int) $course_id; ?>" class="btn btn-primary"><i class='bx bx-list-check'></i> Quản lý trắc nghiệm</a>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <div class="box" style="margin-bottom:20px;">
        <h3 style="margin-top:0;"><i class='bx bx-user-check'></i> Yêu cầu ghi danh đang chờ (<?php echo count($pending_requests); ?>)</h3>
        <div style="overflow-x:auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Họ tên</th>
                        <th>Email</th>
                        <th>Ngày yêu cầu</th>
                        <th>Phê duyệt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_requests as $request): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($request['name']); ?></td>
                            <td><?php echo htmlspecialchars($request['email']); ?></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($request['requested_at'])); ?></td>
                            <td>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="action" value="approve_enrollment">
                                        <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                                        <button type="submit" class="btn" style="background:var(--success);"><i class='bx bx-check'></i> Duyệt</button>
                                    </form>
                                    <form method="POST" style="margin:0;" onsubmit="return confirm('Từ chối yêu cầu ghi danh này?');">
                                        <input type="hidden" name="action" value="reject_enrollment">
                                        <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                                        <button type="submit" class="btn" style="background:var(--danger);"><i class='bx bx-x'></i> Từ chối</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$pending_requests): ?>
                        <tr><td colspan="4" style="text-align:center;color:var(--text-muted);">Không có yêu cầu nào đang chờ.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid">
        <div class="box">
            <h3 style="margin-top: 0;">Thêm Học Viên Mới</h3>
            <form action="" method="POST" style="display: flex; gap: 10px;">
                <input type="hidden" name="action" value="enroll_student">
                <select name="student_id" required style="flex: 1; padding: 10px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1); background: var(--bg-dark); color: #fff;">
                    <option value="">-- Chọn học viên --</option>
                    <?php foreach ($available_students as $student): ?>
                        <option value="<?php echo $student['id']; ?>"><?php echo htmlspecialchars($student['name'] . ' (' . $student['email'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary"><i class='bx bx-user-plus'></i> Thêm vào khóa</button>
            </form>
        </div>

        <div class="box">
            <h3 style="margin-top: 0;">Danh Sách Lớp (<?php echo count($enrolled_students); ?>)</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Họ tên</th>
                        <th>Email</th>
                        <th>Ngày ghi danh</th>
                        <th>Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($enrolled_students as $student): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($student['name']); ?></td>
                            <td><?php echo htmlspecialchars($student['email']); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($student['enrolled_at'])); ?></td>
                            <td>
                                <form method="POST" style="margin:0" onsubmit="return confirm('Xóa học viên khỏi khóa?')">
                                    <input type="hidden" name="action" value="remove_student">
                                    <input type="hidden" name="student_id" value="<?php echo (int) $student['id']; ?>">
                                    <button type="submit" class="btn" style="background: var(--danger);"><i class='bx bx-trash'></i> Xóa</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($enrolled_students)): ?>
                        <tr>
                            <td colspan="4" style="text-align: center;">Chưa có học viên nào trong khóa.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="box" style="margin-top:20px;">
        <h3 style="margin-top:0;"><i class='bx bx-task'></i> Bài tập và bài thi trong khóa (<?php echo count($course_assignments); ?>)</h3>
        <div style="overflow-x:auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Tên bài</th>
                        <th>Loại</th>
                        <th>Hạn nộp</th>
                        <th>Số bài đã nộp</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($course_assignments as $courseAssignment): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($courseAssignment['title']); ?></strong></td>
                            <td>
                                <?php if (($courseAssignment['type'] ?? 'assignment') === 'exam'): ?>
                                    <span style="color:#f87171;"><i class='bx bx-timer'></i> Bài thi</span>
                                <?php else: ?>
                                    <span style="color:#38bdf8;"><i class='bx bx-edit'></i> Bài tập</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $courseAssignment['due_date'] ? date('d/m/Y H:i', strtotime($courseAssignment['due_date'])) : 'Không giới hạn'; ?></td>
                            <td>
                                <strong style="color:var(--success);"><?php echo (int) $courseAssignment['submission_count']; ?></strong>
                                <?php if ($courseAssignment['latest_submission_at']): ?>
                                    <small style="display:block;color:var(--text-muted);">Mới nhất: <?php echo date('d/m/Y H:i', strtotime($courseAssignment['latest_submission_at'])); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="submissions.php?id=<?php echo (int) $courseAssignment['id']; ?>" class="btn btn-primary" style="padding:7px 12px;">
                                    <i class='bx bx-show'></i> Xem bài làm
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$course_assignments): ?>
                        <tr><td colspan="5" style="text-align:center;color:var(--text-muted);">Khóa học chưa có bài tập hoặc bài thi.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="box" style="margin-top:20px;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <h3 style="margin:0;"><i class='bx bx-list-check'></i> Trắc nghiệm trong khóa (<?php echo count($course_quizzes); ?>)</h3>
            <a href="quizzes.php?course_id=<?php echo (int) $course_id; ?>" class="btn btn-primary"><i class='bx bx-plus'></i> Tạo / nhập CSV</a>
        </div>
        <div style="overflow-x:auto;">
            <table>
                <thead><tr><th>Tên bài</th><th>Cấu trúc</th><th>Thời gian</th><th>Trạng thái</th><th>Lượt làm</th><th>Thao tác</th></tr></thead>
                <tbody>
                <?php foreach ($course_quizzes as $courseQuiz): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($courseQuiz['title']); ?></strong></td>
                        <td><?php echo (int) $courseQuiz['question_count']; ?> câu hỏi</td>
                        <td><?php echo (int) $courseQuiz['duration_minutes']; ?> phút</td>
                        <td style="color:<?php echo $courseQuiz['is_published'] ? 'var(--success)' : 'var(--text-muted)'; ?>"><?php echo $courseQuiz['is_published'] ? 'Đã mở' : 'Bản nháp'; ?></td>
                        <td><?php echo (int) $courseQuiz['attempt_count']; ?></td>
                        <td><a class="btn btn-outline" href="quizzes.php?course_id=<?php echo (int) $course_id; ?>&quiz_id=<?php echo (int) $courseQuiz['id']; ?>">Quản lý</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$course_quizzes): ?><tr><td colspan="6" style="text-align:center;color:var(--text-muted)">Chưa có bài trắc nghiệm.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="box" style="margin-top:20px;">
        <h3 style="margin-top:0;"><i class='bx bx-file'></i> Bài làm của học viên (<?php echo count($course_submissions); ?>)</h3>
        <div style="overflow-x:auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Học viên</th>
                        <th>Bài tập / bài thi</th>
                        <th>File đã nộp</th>
                        <th>Thời gian nộp</th>
                        <th>Điểm</th>
                        <th>Chi tiết</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($course_submissions as $courseSubmission): ?>
                        <?php $submittedFiles = json_decode($courseSubmission['submitted_files'] ?? '[]', true); ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($courseSubmission['student_name']); ?></strong>
                                <small style="display:block;color:var(--text-muted);"><?php echo htmlspecialchars($courseSubmission['student_email']); ?></small>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($courseSubmission['assignment_title']); ?>
                                <small style="display:block;color:var(--text-muted);"><?php echo ($courseSubmission['assignment_type'] ?? 'assignment') === 'exam' ? 'Bài thi' : 'Bài tập'; ?></small>
                            </td>
                            <td>
                                <div style="display:flex;flex-direction:column;gap:5px;min-width:190px;">
                                    <?php if (is_array($submittedFiles) && $submittedFiles): ?>
                                        <?php foreach ($submittedFiles as $moduleName => $fileData): ?>
                                            <?php if (!empty($fileData['drive_id'])): ?>
                                                <a href="<?php echo htmlspecialchars($submissionDownloadUrl((int) $courseSubmission['id'], (string) $moduleName), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" style="color:var(--primary);text-decoration:none;">
                                                    <i class='bx bx-download'></i> [<?php echo htmlspecialchars((string) $moduleName); ?>]
                                                    <?php echo htmlspecialchars((string) ($fileData['name'] ?? 'Tải file')); ?>
                                                </a>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php elseif (!empty($courseSubmission['file_drive_id'])): ?>
                                        <a href="<?php echo htmlspecialchars($submissionDownloadUrl((int) $courseSubmission['id']), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" style="color:var(--primary);text-decoration:none;">
                                            <i class='bx bx-download'></i> <?php echo htmlspecialchars((string) $courseSubmission['file_name']); ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted);">Không có file</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?php echo $courseSubmission['submitted_at'] ? date('d/m/Y H:i', strtotime($courseSubmission['submitted_at'])) : '—'; ?></td>
                            <td>
                                <?php if ($courseSubmission['score'] !== null): ?>
                                    <strong style="color:var(--success);"><?php echo htmlspecialchars((string) $courseSubmission['score']); ?></strong>
                                <?php else: ?>
                                    <span style="color:#fbbf24;">Chưa chấm</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="submissions.php?id=<?php echo (int) $courseSubmission['assignment_id']; ?>" class="btn btn-outline" style="padding:7px 12px;">
                                    <i class='bx bx-detail'></i> Xem / sửa điểm
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$course_submissions): ?>
                        <tr><td colspan="6" style="text-align:center;color:var(--text-muted);">Chưa có học viên nộp bài trong khóa học này.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
