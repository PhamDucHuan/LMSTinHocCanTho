<?php
require_once '../includes/security.php';
require_once '../includes/authorization.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/audit.php';
require_once '../includes/notifications.php';
require_once '../includes/grading_queue.php';
/** @var PDO $pdo */

// Ensure $pdo variable exists to avoid undefined variable errors
if (!isset($pdo)) $pdo = null;

// Support multiple possible database connection variable names from config
if ($pdo === null) {
    if (isset($conn)) {
        $pdo = $conn;
    } elseif (isset($db)) {
        $pdo = $db;
    } elseif (isset($link)) {
        $pdo = $link;
    }
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['teacher', 'admin'])) {
    header('Location: ../index.php');
    exit;
}

$assignment_id = $_GET['id'] ?? null;
if (!$assignment_id) {
    if ($pdo === null) {
        die("Database connection error");
    }
    $courseFilter = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);
    $studentFilter = filter_input(INPUT_GET, 'student_id', FILTER_VALIDATE_INT);

    $filterCourseStmt = $_SESSION['user_role'] === 'admin'
        ? $pdo->query('SELECT id, title FROM courses ORDER BY title')
        : $pdo->prepare('SELECT id, title FROM courses WHERE teacher_id=? ORDER BY title');
    if ($_SESSION['user_role'] !== 'admin') {
        $filterCourseStmt->execute([(int) $_SESSION['user_id']]);
    }
    $filterCourses = $filterCourseStmt->fetchAll();

    $filterStudentConditions = [];
    $filterStudentParameters = [];
    if ($_SESSION['user_role'] === 'teacher') {
        $filterStudentConditions[] = 'c.teacher_id=?';
        $filterStudentParameters[] = (int) $_SESSION['user_id'];
    }
    if ($courseFilter) {
        $filterStudentConditions[] = 'c.id=?';
        $filterStudentParameters[] = (int) $courseFilter;
    }
    $filterStudentWhere = $filterStudentConditions
        ? 'WHERE ' . implode(' AND ', $filterStudentConditions)
        : '';
    $filterStudentStmt = $pdo->prepare(
        "SELECT DISTINCT u.id, u.name, u.email
         FROM course_enrollments ce
         JOIN courses c ON c.id=ce.course_id
         JOIN users u ON u.id=ce.student_id
         $filterStudentWhere
         ORDER BY u.name, u.id"
    );
    $filterStudentStmt->execute($filterStudentParameters);
    $filterStudents = $filterStudentStmt->fetchAll();

    if ($_SESSION['user_role'] === 'admin') {
        $overviewStmt = $pdo->query(
            "SELECT a.id, a.title, a.type, a.course_id, c.title course_name,
                    COUNT(s.id) submission_count,
                    SUM(CASE WHEN s.grading_status='review_required' THEN 1 ELSE 0 END) review_count,
                    GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR '||') submitter_names,
                    GROUP_CONCAT(DISTINCT u.id ORDER BY u.id SEPARATOR ',') submitter_ids,
                    GROUP_CONCAT(DISTINCT CASE WHEN s.grading_status='review_required' THEN u.id END ORDER BY u.id SEPARATOR ',') review_submitter_ids
             FROM assignments a
             LEFT JOIN courses c ON c.id=a.course_id
             LEFT JOIN submissions s ON s.assignment_id=a.id
             LEFT JOIN users u ON u.id=s.student_id
             GROUP BY a.id, a.title, a.type, a.course_id, c.title
             ORDER BY MAX(s.submitted_at) DESC, a.id DESC"
        );
    } else {
        $overviewStmt = $pdo->prepare(
            "SELECT a.id, a.title, a.type, a.course_id, c.title course_name,
                    COUNT(s.id) submission_count,
                    SUM(CASE WHEN s.grading_status='review_required' THEN 1 ELSE 0 END) review_count,
                    GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR '||') submitter_names,
                    GROUP_CONCAT(DISTINCT u.id ORDER BY u.id SEPARATOR ',') submitter_ids,
                    GROUP_CONCAT(DISTINCT CASE WHEN s.grading_status='review_required' THEN u.id END ORDER BY u.id SEPARATOR ',') review_submitter_ids
             FROM assignments a
             LEFT JOIN courses c ON c.id=a.course_id
             LEFT JOIN submissions s ON s.assignment_id=a.id
             LEFT JOIN users u ON u.id=s.student_id
             WHERE a.teacher_id=?
             GROUP BY a.id, a.title, a.type, a.course_id, c.title
             ORDER BY MAX(s.submitted_at) DESC, a.id DESC"
        );
        $overviewStmt->execute([(int) $_SESSION['user_id']]);
    }
    $overviewAssignments = $overviewStmt->fetchAll();
    $overviewAssignments = array_values(array_filter(
        $overviewAssignments,
        static function (array $overviewAssignment) use ($courseFilter, $studentFilter): bool {
            if ($courseFilter && (int) $overviewAssignment['course_id'] !== (int) $courseFilter) {
                return false;
            }
            if ($studentFilter) {
                $submitterIds = array_map(
                    'intval',
                    array_filter(explode(',', (string) ($overviewAssignment['submitter_ids'] ?? '')))
                );
                if (!in_array((int) $studentFilter, $submitterIds, true)) {
                    return false;
                }
            }
            return true;
        }
    ));
    if ($studentFilter) {
        foreach ($overviewAssignments as &$overviewAssignment) {
            $reviewSubmitterIds = array_map(
                'intval',
                array_filter(explode(',', (string) ($overviewAssignment['review_submitter_ids'] ?? '')))
            );
            $overviewAssignment['submission_count'] = 1;
            $overviewAssignment['review_count'] = in_array((int) $studentFilter, $reviewSubmitterIds, true) ? 1 : 0;
            foreach ($filterStudents as $filterStudent) {
                if ((int) $filterStudent['id'] === (int) $studentFilter) {
                    $overviewAssignment['submitter_names'] = (string) $filterStudent['name'];
                    break;
                }
            }
        }
        unset($overviewAssignment);
    }
    $assignmentsByCourse = [];
    foreach ($overviewAssignments as $overviewAssignment) {
        $courseKey = $overviewAssignment['course_id'] === null
            ? 'uncategorized'
            : 'course_' . (int) $overviewAssignment['course_id'];
        if (!isset($assignmentsByCourse[$courseKey])) {
            $assignmentsByCourse[$courseKey] = [
                'title' => (string) ($overviewAssignment['course_name'] ?: 'Bài không thuộc khóa học'),
                'assignments' => [],
                'submission_count' => 0,
            ];
        }
        $assignmentsByCourse[$courseKey]['assignments'][] = $overviewAssignment;
        $assignmentsByCourse[$courseKey]['submission_count'] += (int) $overviewAssignment['submission_count'];
    }
    $page_title = 'Bài làm học viên';
    require_once '../includes/header.php';
    ?>
    <div class="box">
        <div class="submission-overview-head">
            <div>
                <h2 style="margin:0 0 7px;"><i class='bx bx-file-find'></i> Bài làm học viên</h2>
                <p style="margin:0;color:var(--text-muted);">Chọn bài tập hoặc bài thi để xem file học viên đã nộp và kiểm tra lại điểm.</p>
            </div>
            <form method="GET" class="submission-filter">
                <label>
                    Khóa học
                    <select name="course_id" onchange="this.form.submit()">
                        <option value="">Tất cả khóa học</option>
                        <?php foreach ($filterCourses as $filterCourse): ?>
                            <option value="<?php echo (int) $filterCourse['id']; ?>" <?php echo (int) $courseFilter === (int) $filterCourse['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string) $filterCourse['title'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Học viên
                    <select name="student_id" onchange="this.form.submit()">
                        <option value="">Tất cả học viên</option>
                        <?php foreach ($filterStudents as $filterStudent): ?>
                            <option value="<?php echo (int) $filterStudent['id']; ?>" <?php echo (int) $studentFilter === (int) $filterStudent['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string) $filterStudent['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <a href="submissions.php"
                   class="btn btn-outline submission-filter-reset<?php echo ($courseFilter || $studentFilter) ? '' : ' is-hidden'; ?>"
                   <?php echo ($courseFilter || $studentFilter) ? '' : 'aria-hidden="true" tabindex="-1"'; ?>>
                    <i class='bx bx-reset'></i> Xóa lọc
                </a>
            </form>
        </div>
        <div class="submission-course-list">
            <?php foreach ($assignmentsByCourse as $courseGroup): ?>
                <details class="submission-course" open>
                    <summary>
                        <span>
                            <i class='bx bx-book-open'></i>
                            <strong><?php echo htmlspecialchars($courseGroup['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        </span>
                        <small><?php echo count($courseGroup['assignments']); ?> bài · <?php echo (int) $courseGroup['submission_count']; ?> bài nộp</small>
                    </summary>
                    <div class="submission-type-groups">
                    <?php
                    $submissionTypeGroups = [
                        'assignment' => [
                            'title' => 'Bài tập',
                            'icon' => 'bx-edit',
                            'items' => array_values(array_filter($courseGroup['assignments'], static fn(array $item): bool => ($item['type'] ?? 'assignment') !== 'exam')),
                        ],
                        'exam' => [
                            'title' => 'Bài thi',
                            'icon' => 'bx-timer',
                            'items' => array_values(array_filter($courseGroup['assignments'], static fn(array $item): bool => ($item['type'] ?? 'assignment') === 'exam')),
                        ],
                    ];
                    ?>
                    <?php foreach ($submissionTypeGroups as $submissionTypeKey => $submissionTypeGroup): ?>
                        <?php if (!$submissionTypeGroup['items']) continue; ?>
                        <?php $typeSubmissionCount = array_sum(array_map(static fn(array $item): int => (int) $item['submission_count'], $submissionTypeGroup['items'])); ?>
                        <section class="submission-type-group <?php echo $submissionTypeKey; ?>">
                            <div class="submission-type-heading">
                                <span><i class='bx <?php echo $submissionTypeGroup['icon']; ?>'></i> <?php echo $submissionTypeGroup['title']; ?></span>
                                <small><?php echo count($submissionTypeGroup['items']); ?> bài · <?php echo $typeSubmissionCount; ?> bài nộp</small>
                            </div>
                            <div class="submission-assignment-grid">
                        <?php foreach ($submissionTypeGroup['items'] as $overviewAssignment): ?>
                            <article class="submission-assignment-card">
                                <div>
                                    <span class="submission-type <?php echo ($overviewAssignment['type'] ?? '') === 'exam' ? 'exam' : ''; ?>">
                                        <?php echo ($overviewAssignment['type'] ?? '') === 'exam' ? 'Bài thi' : 'Bài tập'; ?>
                                    </span>
                                    <h3><?php echo htmlspecialchars((string) $overviewAssignment['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                </div>
                                <div class="submission-counts">
                                    <span><i class='bx bx-file'></i> <?php echo (int) $overviewAssignment['submission_count']; ?> bài nộp</span>
                                    <?php if ((int) $overviewAssignment['review_count'] > 0): ?>
                                        <span class="review-count"><i class='bx bx-error-circle'></i> <?php echo (int) $overviewAssignment['review_count']; ?> cần kiểm tra</span>
                                    <?php endif; ?>
                                </div>
                                <?php
                                $submitterNames = !empty($overviewAssignment['submitter_names'])
                                    ? array_values(array_filter(explode('||', (string) $overviewAssignment['submitter_names'])))
                                    : [];
                                ?>
                                <div class="submission-students">
                                    <strong><i class='bx bx-user-check'></i> Học viên đã làm</strong>
                                    <?php if ($submitterNames): ?>
                                        <div class="submission-student-chips">
                                            <?php foreach ($submitterNames as $submitterName): ?>
                                                <span><?php echo htmlspecialchars($submitterName, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <small>Chưa có học viên nộp bài.</small>
                                    <?php endif; ?>
                                </div>
                                <a class="btn btn-primary" href="submissions.php?id=<?php echo (int) $overviewAssignment['id']; ?>">
                                    <i class='bx bx-show'></i> Xem và chấm bài
                                </a>
                            </article>
                        <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
            <?php if (!$assignmentsByCourse): ?><div class="empty-state">Chưa có bài tập hoặc bài thi.</div><?php endif; ?>
        </div>
    </div>
    <style>
        .submission-course-list{display:grid;gap:18px;margin-top:22px}
        .submission-overview-head{display:grid;grid-template-columns:minmax(280px,1fr) minmax(440px,560px);align-items:end;gap:18px}
        .submission-filter{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) auto;align-items:end;gap:10px;width:100%;justify-self:end}
        .submission-filter label{display:grid;gap:6px;margin-bottom:0;color:var(--text-muted);font-size:13px}
        .submission-filter select{width:100%;min-width:0}
        .submission-filter select,.submission-filter .btn{height:40px;min-height:40px;box-sizing:border-box}
        .submission-filter .btn{align-self:end;justify-content:center;padding:0 20px;white-space:nowrap;transform:none}
        .submission-filter-reset.is-hidden{visibility:hidden;pointer-events:none}
        .submission-course{border:1px solid var(--border-color);border-radius:14px;background:rgba(var(--primary-rgb),.025);overflow:hidden}
        .submission-course>summary{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:18px 20px;cursor:pointer;list-style:none;background:rgba(var(--primary-rgb),.06)}
        .submission-course>summary::-webkit-details-marker{display:none}
        .submission-course>summary span{display:flex;align-items:center;gap:10px;font-size:18px;color:var(--text-main)}
        .submission-course>summary i{color:var(--primary);font-size:24px}
        .submission-course>summary small{color:var(--text-muted)}
        .submission-type-groups{display:grid;gap:22px;padding:18px}
        .submission-type-group{display:grid;gap:13px}
        .submission-type-group+.submission-type-group{padding-top:20px;border-top:1px solid var(--border-color)}
        .submission-type-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:0 3px}
        .submission-type-heading>span{display:flex;align-items:center;gap:8px;color:#38bdf8;font-size:17px;font-weight:700}
        .submission-type-heading i{font-size:21px}
        .submission-type-heading small{color:var(--text-muted)}
        .submission-type-group.exam .submission-type-heading>span{color:#fb7185}
        .submission-assignment-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(255px,1fr));gap:14px}
        .submission-assignment-card{display:flex;flex-direction:column;gap:13px;padding:17px;border:1px solid var(--border-color);border-radius:12px;background:var(--sidebar-bg)}
        .submission-assignment-card>div:first-child{min-height:122px}
        .submission-assignment-card h3{margin:8px 0 0;overflow-wrap:anywhere}
        .submission-type{display:inline-flex;padding:4px 9px;border-radius:999px;background:rgba(14,165,233,.14);color:#38bdf8;font-size:12px;font-weight:700}
        .submission-type.exam{background:rgba(244,63,94,.14);color:#fb7185}
        .submission-counts{display:flex;gap:12px;flex-wrap:wrap;color:var(--text-muted);font-size:14px}
        .submission-counts .review-count{color:var(--warning)}
        .submission-students{display:grid;gap:8px;padding-top:11px;border-top:1px solid var(--border-color);font-size:13px}
        .submission-students>strong{display:flex;align-items:center;gap:6px}
        .submission-students>small{color:var(--text-muted)}
        .submission-student-chips{display:flex;gap:6px;flex-wrap:wrap;max-height:82px;overflow-y:auto;padding-right:3px}
        .submission-student-chips span{display:inline-flex;align-items:center;padding:4px 8px;border-radius:999px;background:rgba(var(--primary-rgb),.12);color:var(--text-main);border:1px solid rgba(var(--primary-rgb),.2)}
        .submission-assignment-card .btn{margin-top:auto;justify-content:center}
        @media(max-width:900px){.submission-overview-head{grid-template-columns:1fr}.submission-filter{justify-self:stretch}}
        @media(max-width:650px){.submission-course>summary{align-items:flex-start;flex-direction:column}.submission-type-groups{padding:12px}.submission-type-heading{align-items:flex-start;flex-direction:column}.submission-assignment-grid{grid-template-columns:1fr}.submission-assignment-card>div:first-child{min-height:0}.submission-filter{grid-template-columns:1fr}.submission-filter,.submission-filter label,.submission-filter select,.submission-filter .btn{width:100%}.submission-filter .btn{transform:none}}
    </style>
    <?php
    require_once '../includes/footer.php';
    exit;
}

if ($pdo === null) {
    die("Database connection error");
}

$assignment = authorizationFindManageableAssignment($pdo, (int) $assignment_id, (string) $_SESSION['user_role'], (int) $_SESSION['user_id']);

if (!$assignment) {
    die("Bài tập không tồn tại hoặc bạn không có quyền xem.");
}

$completedJobs = $pdo->prepare(
    "SELECT gj.* FROM grading_jobs gj
     WHERE gj.assignment_id=? AND gj.status='completed' AND gj.result_applied_at IS NULL
     ORDER BY gj.id"
);
$completedJobs->execute([(int) $assignment_id]);
foreach ($completedJobs->fetchAll() as $completedJob) {
    try {
        applyCompletedGradingJob($pdo, $completedJob);
    } catch (Throwable $error) {
        error_log('Cannot apply grading job in teacher view: ' . $error->getMessage());
    }
}

$module_settings = json_decode($assignment['module_settings'] ?? '[]', true);
$total_max = 0;
if (is_array($module_settings)) {
    foreach ($module_settings as $m) $total_max += $m['max_score'];
}
if ($total_max == 0) $total_max = 10;

// Xử lý POST (Đánh dấu tiêu biểu hoặc Chấm lại)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $action = $_POST['action'] ?? '';
    $sub_id = $_POST['submission_id'] ?? null;
    
    if ($sub_id) {
        if ($action === 'toggle_outstanding') {
            $stmt = $pdo->prepare("UPDATE submissions SET is_outstanding = NOT is_outstanding WHERE id = ? AND assignment_id = ?");
            $stmt->execute([$sub_id, $assignment_id]);
            $_SESSION['success'] = "Đã cập nhật trạng thái tiêu biểu.";
        } elseif ($action === 'regrade') {
            $score = max(0, min($total_max, (float) ($_POST['score'] ?? 0)));
            $comment = trim((string) ($_POST['comment'] ?? ''));
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'SELECT student_id, score, ai_feedback FROM submissions
                 WHERE id = ? AND assignment_id = ? FOR UPDATE'
            );
            $stmt->execute([$sub_id, $assignment_id]);
            $currentSubmission = $stmt->fetch();
            if (!$currentSubmission) {
                $pdo->rollBack();
                throw new RuntimeException('Không tìm thấy bài nộp.');
            }
            $feedbackData = json_decode((string) ($currentSubmission['ai_feedback'] ?? '{}'), true);
            if (!is_array($feedbackData)) $feedbackData = [];
            $feedbackData['_teacher_review'] = [
                'comment' => $comment,
                'reviewed_by' => (int) $_SESSION['user_id'],
                'reviewed_at' => date(DATE_ATOM),
                'previous_score' => $currentSubmission['score'] !== null
                    ? (float) $currentSubmission['score']
                    : null,
                'final_score' => $score,
            ];
            $stmt = $pdo->prepare(
                "UPDATE submissions
                 SET score = ?, ai_feedback = ?, grading_status = 'reviewed', grading_updated_at = NOW()
                 WHERE id = ? AND assignment_id = ?"
            );
            $stmt->execute([
                $score,
                json_encode($feedbackData, JSON_UNESCAPED_UNICODE),
                $sub_id,
                $assignment_id,
            ]);
            $stmt = $pdo->prepare(
                'INSERT INTO grading_reviews
                 (submission_id, module_name, ai_score, final_score, ai_feedback, reviewer_feedback, reviewed_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $sub_id,
                '_overall',
                $currentSubmission['score'],
                $score,
                $currentSubmission['ai_feedback'],
                $comment,
                (int) $_SESSION['user_id'],
            ]);
            createNotification(
                $pdo,
                (int) $currentSubmission['student_id'],
                'grade_reviewed',
                'Giáo viên đã xác nhận điểm',
                "Bài “{$assignment['title']}” đã được xác nhận {$score}/{$total_max} điểm.",
                '../student/assignment.php?id=' . (int) $assignment_id,
                ['assignment_id' => (int) $assignment_id, 'submission_id' => (int) $sub_id]
            );
            $pdo->commit();
            writeAuditLog($pdo, 'submission.grade_reviewed', 'submission', (int) $sub_id, [
                'previous_score' => $currentSubmission['score'],
                'final_score' => $score,
            ]);
            $_SESSION['success'] = "Đã sửa điểm thành công.";
        }
        header("Location: submissions.php?id=$assignment_id");
        exit;
    }
}

$stmt = $pdo->prepare("
    SELECT s.*, u.name, u.email 
    FROM submissions s
    JOIN users u ON s.student_id = u.id
    WHERE s.assignment_id = ?
    ORDER BY s.submitted_at DESC
");
$stmt->execute([$assignment_id]);
$submissions = $stmt->fetchAll();

$historyStmt = $pdo->prepare(
    'SELECT gr.*, u.name reviewer_name, s.student_id, student.name student_name
     FROM grading_reviews gr
     JOIN submissions s ON s.id = gr.submission_id
     JOIN users u ON u.id = gr.reviewed_by
     JOIN users student ON student.id = s.student_id
     WHERE s.assignment_id = ?
     ORDER BY gr.reviewed_at DESC
     LIMIT 100'
);
$historyStmt->execute([$assignment_id]);
$reviewHistory = $historyStmt->fetchAll();

$page_title = "Danh sách nộp bài - " . htmlspecialchars($assignment['title']);
require_once '../includes/header.php';
?>
        <div class="box">
            <a href="<?php echo $_SESSION['user_role'] === 'admin' ? '../admin/assignments.php' : 'assignments.php'; ?>" class="btn btn-outline" style="margin-bottom:18px;">
                <i class='bx bx-arrow-back'></i> Quay lại danh sách bài tập
            </a>
            <?php if(isset($_SESSION['success'])): ?>
                <div style="background: rgba(16, 185, 129, 0.2); color: #6ee7b7; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <p>Tổng số bài nộp: <?php echo count($submissions); ?></p>
            
            <table>
                <thead>
                    <tr>
                        <th>Học viên</th>
                        <th>Thời gian nộp</th>
                        <th>File bài làm</th>
                        <th>Điểm</th>
                        <th>Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($submissions) > 0): ?>
                        <?php foreach ($submissions as $sub): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($sub['name']); ?></strong><br>
                                    <small style="color: var(--text-muted);"><?php echo htmlspecialchars($sub['email']); ?></small>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($sub['submitted_at'])); ?></td>
                                <td>
                                    <?php 
                                    $sub_files = json_decode($sub['submitted_files'] ?? '[]', true);
                                    if (!empty($sub_files)): 
                                    ?>
                                        <div style="display: flex; flex-direction: column; gap: 5px;">
                                            <?php foreach ($sub_files as $mod => $fData): ?>
                                                <?php $dl_link = '../download.php?kind=submission&id=' . (int) $sub['id'] . '&module=' . rawurlencode((string) $mod); ?>
                                                <?php
                                                $submittedName = (string) ($fData['name'] ?? '');
                                                $previewExtension = strtolower(pathinfo($submittedName, PATHINFO_EXTENSION));
                                                $canPreview = in_array($previewExtension, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'txt'], true);
                                                ?>
                                                <div class="submission-file-actions">
                                                    <span class="submission-file-name">[<?php echo htmlspecialchars($mod); ?>] <?php echo htmlspecialchars($submittedName); ?></span>
                                                    <?php if ($canPreview): ?>
                                                        <button type="button" class="btn-sm btn-preview"
                                                            onclick='openSubmissionPreview(<?php echo json_encode($dl_link . '&preview=1'); ?>, <?php echo json_encode($submittedName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                                            <i class='bx bx-show'></i> Xem nhanh
                                                        </button>
                                                    <?php endif; ?>
                                                    <a href="<?php echo $dl_link; ?>" class="btn-sm btn-download">
                                                        <i class='bx bx-download'></i> Tải xuống
                                                    </a>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <?php $dl_link = '../download.php?kind=submission&id=' . (int) $sub['id']; ?>
                                        <?php
                                        $submittedName = (string) ($sub['file_name'] ?? '');
                                        $previewExtension = strtolower(pathinfo($submittedName, PATHINFO_EXTENSION));
                                        $canPreview = in_array($previewExtension, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'txt'], true);
                                        ?>
                                        <div class="submission-file-actions">
                                            <span class="submission-file-name"><?php echo htmlspecialchars($submittedName); ?></span>
                                            <?php if ($canPreview): ?>
                                                <button type="button" class="btn-sm btn-preview"
                                                    onclick='openSubmissionPreview(<?php echo json_encode($dl_link . '&preview=1'); ?>, <?php echo json_encode($submittedName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                                    <i class='bx bx-show'></i> Xem nhanh
                                                </button>
                                            <?php endif; ?>
                                            <a href="<?php echo $dl_link; ?>" class="btn-sm btn-download">
                                                <i class='bx bx-download'></i> Tải xuống
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                    <?php
                                    $feedbackModules = json_decode((string) ($sub['ai_feedback'] ?? '{}'), true);
                                    if (!is_array($feedbackModules)) $feedbackModules = [];
                                    unset($feedbackModules['_teacher_review']);
                                    ?>
                                    <?php if ($feedbackModules): ?>
                                        <details style="margin-top:10px;max-width:420px;">
                                            <summary style="cursor:pointer;color:var(--primary);font-size:13px;">Xem nhận xét AI</summary>
                                            <?php foreach ($feedbackModules as $feedbackModule => $moduleFeedback): ?>
                                                <div style="padding:8px 0;border-bottom:1px solid var(--border-color);font-size:13px;">
                                                    <strong><?php echo htmlspecialchars((string) $feedbackModule, ENT_QUOTES, 'UTF-8'); ?>:</strong>
                                                    <?php echo htmlspecialchars((string) ($moduleFeedback['comment'] ?? 'Không có nhận xét.'), ENT_QUOTES, 'UTF-8'); ?>
                                                    <?php if (!empty($moduleFeedback['errors']) && is_array($moduleFeedback['errors'])): ?>
                                                        <ul style="margin:5px 0 0;color:var(--danger);">
                                                            <?php foreach ($moduleFeedback['errors'] as $feedbackError): ?>
                                                                <li><?php echo htmlspecialchars((string) $feedbackError, ENT_QUOTES, 'UTF-8'); ?></li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </details>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($sub['score'] !== null): ?>
                                        <span class="score"><?php echo $sub['score']; ?></span>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">Đang chấm...</span>
                                    <?php endif; ?>
                                    <?php
                                    $gradingLabels = [
                                        'queued' => 'Đang chờ AI',
                                        'processing' => 'AI đang chấm',
                                        'ai_graded' => 'AI đã chấm',
                                        'review_required' => 'Cần kiểm tra',
                                        'reviewed' => 'Đã xác nhận',
                                        'failed' => 'Chấm lỗi',
                                    ];
                                    $gradingStatus = (string) ($sub['grading_status'] ?? '');
                                    ?>
                                    <?php if (isset($gradingLabels[$gradingStatus])): ?>
                                        <small style="display:block;margin-top:5px;color:var(--text-muted);">
                                            <?php echo htmlspecialchars($gradingLabels[$gradingStatus], ENT_QUOTES, 'UTF-8'); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td style="display: flex; gap: 8px;">
                                    <form method="POST" style="margin: 0;">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="toggle_outstanding">
                                        <input type="hidden" name="submission_id" value="<?php echo $sub['id']; ?>">
                                        <button class="btn-sm btn-outstanding <?php echo $sub['is_outstanding'] ? 'active' : ''; ?>">
                                            <i class='bx <?php echo $sub['is_outstanding'] ? 'bxs-star' : 'bx-star'; ?>'></i> Tiêu biểu
                                        </button>
                                    </form>
                                    
                                    <button class="btn-sm btn-edit" onclick='openRegradeModal(<?php
                                        echo json_encode((int) $sub['id']) . ','
                                            . json_encode((string) $sub['name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ','
                                            . json_encode($sub['score'] ?? '');
                                    ?>)'>
                                        <i class='bx bx-edit-alt'></i> Sửa điểm
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" style="text-align: center; color: var(--text-muted);">Chưa có ai nộp bài</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($reviewHistory): ?>
            <div class="box" style="margin-top:20px;">
                <h3><i class='bx bx-history'></i> Lịch sử xác nhận điểm</h3>
                <div class="table-responsive">
                    <table>
                        <thead><tr><th>Học viên</th><th>Điểm AI/cũ</th><th>Điểm cuối</th><th>Người duyệt</th><th>Thời gian</th><th>Ghi chú</th></tr></thead>
                        <tbody>
                        <?php foreach ($reviewHistory as $review): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($review['student_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo $review['ai_score'] === null ? '—' : htmlspecialchars((string) $review['ai_score']); ?></td>
                                <td><strong><?php echo htmlspecialchars((string) $review['final_score']); ?></strong></td>
                                <td><?php echo htmlspecialchars($review['reviewer_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($review['reviewed_at'])); ?></td>
                                <td><?php echo htmlspecialchars((string) ($review['reviewer_feedback'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    <!-- Regrade Modal -->
    <style>
        dialog { background: var(--bg-dark); color: #fff; border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; padding: 30px; max-width: 500px; width: 100%; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        dialog::backdrop { background: rgba(0,0,0,0.7); }
        dialog input, dialog textarea { width: 100%; padding: 10px; margin-top: 5px; margin-bottom: 15px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #fff; border-radius: 6px; box-sizing: border-box; }
        dialog button { padding: 10px 15px; border-radius: 6px; border: none; cursor: pointer; font-weight: bold; }
        .btn-save { background: var(--primary); color: #fff; width: 100%; margin-bottom: 10px; }
        .btn-close { background: rgba(255,255,255,0.1); color: #fff; width: 100%; }
        
        .btn-sm { padding: 5px 10px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.2); background: transparent; color: #fff; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 4px; font-size: 13px; }
        .btn-sm:hover { background: rgba(255,255,255,0.1); }
        
        .btn-outstanding { border-color: var(--warning); color: var(--warning); }
        .btn-outstanding:hover { background: rgba(245, 158, 11, 0.1); }
        .btn-outstanding.active { background: var(--warning); color: #fff; }
        
        .btn-edit { border-color: var(--primary); color: var(--primary); }
        .btn-edit:hover { background: rgba(99, 102, 241, 0.1); }
        .submission-file-actions { display:flex; align-items:center; flex-wrap:wrap; gap:6px; padding:5px 0; }
        .submission-file-name { flex:1 1 180px; min-width:0; overflow-wrap:anywhere; font-size:13px; }
        .btn-preview { border-color:var(--primary); color:var(--primary); }
        .btn-download { border-color:var(--success); color:var(--success); text-decoration:none; }
        #submissionPreviewModal { max-width:min(1100px,94vw); width:94vw; padding:18px; }
        .submission-preview-head { display:flex; justify-content:space-between; align-items:center; gap:15px; margin-bottom:12px; }
        .submission-preview-frame { display:block; width:100%; height:min(72vh,760px); border:1px solid var(--border-color); border-radius:10px; background:#fff; }
        @media (max-width:768px) {
            #submissionPreviewModal { width:96vw; padding:12px; }
            .submission-preview-frame { height:68vh; }
        }
    </style>

    <dialog id="submissionPreviewModal">
        <div class="submission-preview-head">
            <h3 id="submissionPreviewTitle" style="margin:0;overflow-wrap:anywhere;">Xem bài làm</h3>
            <button type="button" class="btn-sm btn-close-preview" onclick="closeSubmissionPreview()" aria-label="Đóng">
                <i class='bx bx-x'></i> Đóng
            </button>
        </div>
        <iframe id="submissionPreviewFrame" class="submission-preview-frame" src="about:blank" title="Xem trước bài làm"></iframe>
    </dialog>

    <dialog id="regradeModal">
        <h3 style="margin-top:0;">Chấm điểm lại</h3>
        <p style="color: var(--text-muted); font-size: 14px;">Học viên: <strong id="modalStudentName" style="color:#fff;"></strong></p>
        
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="regrade">
            <input type="hidden" name="submission_id" id="modalSubmissionId" value="">
            
            <label style="font-weight: 500; font-size: 14px;">Điểm số mới</label>
            <input type="number" step="0.1" min="0" max="<?php echo $total_max; ?>" name="score" id="modalScore" required>
            
            <label style="font-weight: 500; font-size: 14px;">Lời phê / Nhận xét (Tùy chọn)</label>
            <textarea name="comment" rows="4"></textarea>
            
            <button type="submit" class="btn-save"><i class='bx bx-check'></i> Lưu điểm</button>
            <button type="button" class="btn-close" onclick="document.getElementById('regradeModal').close()">Hủy bỏ</button>
        </form>
    </dialog>

    <script>
        function openRegradeModal(subId, studentName, currentScore) {
            document.getElementById('modalSubmissionId').value = subId;
            document.getElementById('modalStudentName').textContent = studentName;
            document.getElementById('modalScore').value = currentScore;
            document.getElementById('regradeModal').showModal();
        }

        function openSubmissionPreview(url, fileName) {
            const modal = document.getElementById('submissionPreviewModal');
            document.getElementById('submissionPreviewTitle').textContent = 'Xem bài làm: ' + fileName;
            document.getElementById('submissionPreviewFrame').src = url;
            modal.showModal();
        }

        function closeSubmissionPreview() {
            document.getElementById('submissionPreviewFrame').src = 'about:blank';
            document.getElementById('submissionPreviewModal').close();
        }

        document.getElementById('submissionPreviewModal').addEventListener('close', function () {
            document.getElementById('submissionPreviewFrame').src = 'about:blank';
        });
    </script>

<?php require_once '../includes/footer.php'; ?>
