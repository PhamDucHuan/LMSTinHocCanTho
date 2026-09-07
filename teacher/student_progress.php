<?php
require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/authorization.php';
/** @var PDO $pdo */

if (empty($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['teacher', 'administrative_staff', 'admin'], true)) {
    header('Location: ../index.php');
    exit;
}

$courseFilter = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);
$classFilter = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
$actorId = (int) $_SESSION['user_id'];
$actorRole = (string) $_SESSION['user_role'];
$isAdmin = $actorRole === 'admin';
$returnParameters = [];
if ($courseFilter) $returnParameters['course_id'] = (int) $courseFilter;
if ($classFilter) $returnParameters['class_id'] = (int) $classFilter;
$returnUrl = 'student_progress.php' . ($returnParameters ? '?' . http_build_query($returnParameters) : '');

if ($isAdmin) {
    $manageableCourseIds = array_fill_keys(array_map('intval', $pdo->query('SELECT id FROM courses')->fetchAll(PDO::FETCH_COLUMN)), true);
} else {
    $manageableStmt = $pdo->prepare('SELECT c.id FROM courses c WHERE c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)');
    $manageableStmt->execute([$actorId, $actorId]);
    $manageableCourseIds = array_fill_keys(array_map('intval', $manageableStmt->fetchAll(PDO::FETCH_COLUMN)), true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') verifyCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_exam_date') {
    $cId = filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT);
    $sId = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
    $examDate = trim((string)($_POST['exam_date'] ?? ''));
    if ($examDate === '') $examDate = null;

    if ($cId && $sId) {
        $dateIsValid = $examDate === null;
        if ($examDate !== null) {
            $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $examDate);
            $dateIsValid = $parsedDate !== false && $parsedDate->format('Y-m-d') === $examDate;
        }

        if (!$dateIsValid) {
            $_SESSION['error'] = "Ngày thi không hợp lệ.";
        } else {
            $enrollmentSql = "SELECT 1
                              FROM learning_classes lc
                              JOIN learning_class_students lcs ON lcs.learning_class_id=lc.id
                              JOIN courses c ON c.id=lc.course_id
                              WHERE lc.course_id=? AND lcs.student_id=? AND lc.status='active'";
            $enrollmentParams = [$cId, $sId];
            if (!$isAdmin) {
                $enrollmentSql .= " AND (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))";
                $enrollmentParams[] = $actorId;
                $enrollmentParams[] = $actorId;
            }

            $enrollmentStmt = $pdo->prepare($enrollmentSql);
            $enrollmentStmt->execute($enrollmentParams);
            if ($enrollmentStmt->fetchColumn()) {
                $stmt = $pdo->prepare("UPDATE learning_class_students lcs JOIN learning_classes lc ON lc.id=lcs.learning_class_id SET lcs.exam_date=? WHERE lc.course_id=? AND lcs.student_id=? AND lc.status='active'");
                $stmt->execute([$examDate, $cId, $sId]);
                $_SESSION['success'] = "Cập nhật ngày thi thành công!";
            } else {
                $_SESSION['error'] = "Không tìm thấy học viên trong khóa học này hoặc bạn không có quyền cập nhật.";
            }
        }
        header('Location: ' . $returnUrl);
        exit;
    }

    $_SESSION['error'] = "Thông tin học viên hoặc khóa học không hợp lệ.";
    header('Location: ' . $returnUrl);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_exam_reminder') {
    require_once '../includes/notifications.php';
    require_once '../includes/email_templates.php';
    $cId = filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT);
    $sId = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
    
    if ($cId && $sId) {
        if (!authorizationUserCanManageCourse($pdo, (int) $cId, $actorRole, $actorId)) {
            $_SESSION['error'] = 'Bạn không có quyền gửi nhắc nhở cho khóa học này.';
            header('Location: ' . $returnUrl);
            exit;
        }
        // Lấy thông tin học viên và ngày thi
        $stmt = $pdo->prepare("
            SELECT u.name, u.email, MAX(lcs.exam_date) exam_date, c.title as course_title
            FROM learning_classes lc
            JOIN learning_class_students lcs ON lcs.learning_class_id=lc.id
            JOIN users u ON u.id=lcs.student_id
            JOIN courses c ON c.id=lc.course_id
            WHERE lc.course_id=? AND lcs.student_id=? AND lc.status='active'
            GROUP BY u.id, u.name, u.email, c.id, c.title
        ");
        $stmt->execute([$cId, $sId]);
        $info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($info && $info['exam_date']) {
            $daysLeft = ceil((strtotime((string)$info['exam_date']) - time()) / 86400);
            $title = "🎓 Kỳ thi đang đến gần!";
            $message = "Chỉ còn {$daysLeft} ngày nữa là đến kỳ thi môn '{$info['course_title']}'. Hãy tranh thủ ôn bài và luyện tập nhé!";
            
            // Gửi Notification trên web
            createNotification($pdo, (int)$sId, 'reminder', $title, $message, '#');
            
            // Gửi Email
            $baseUrl = "http://" . $_SERVER['HTTP_HOST'] . "/LMSTinHocCanTho/";
            $examDateStr = date('d/m/Y', strtotime((string)$info['exam_date']));
            $emailBody = get_exam_reminder_email_html($info['name'], $info['course_title'], $examDateStr, $daysLeft, $baseUrl . 'index.php');
            sendSystemEmail($info['email'], $title, $emailBody);
            
            // Ghi Log (đã báo học viên)
            $pdo->prepare("INSERT INTO reminder_logs (user_id, type, reference_id) VALUES (?, 'exam_reminder_student', ?)")
                ->execute([$sId, $cId]);
                
            $_SESSION['success'] = "Đã gửi thông báo nhắc nhở cho học viên {$info['name']}.";
        }
        header('Location: ' . $returnUrl);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_send_exam_reminder') {
    require_once '../includes/notifications.php';
    require_once '../includes/email_templates.php';
    
    // Validate selected student-course pairs
    $reminders = $_POST['reminders'] ?? [];
    if (!empty($reminders) && is_array($reminders)) {
        $count = 0;
        foreach ($reminders as $rem) {
            $parts = explode('-', (string) $rem, 2);
            if (count($parts) !== 2) continue;
            [$sId, $cId] = $parts;
            $sId = (int)$sId;
            $cId = (int)$cId;
            
            // Check authorization implicitly by checking if teacher owns course (if teacher)
            $authCheck = "SELECT u.name, u.email, MAX(lcs.exam_date) exam_date, c.title as course_title
                          FROM learning_classes lc
                          JOIN learning_class_students lcs ON lcs.learning_class_id=lc.id
                          JOIN users u ON u.id=lcs.student_id
                          JOIN courses c ON c.id=lc.course_id
                          WHERE lc.course_id=? AND lcs.student_id=? AND lc.status='active'";
            $params = [$cId, $sId];
            if (!$isAdmin) {
                $authCheck .= " AND (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))";
                $params[] = $actorId;
                $params[] = $actorId;
            }
            
            $authCheck .= ' GROUP BY u.id, u.name, u.email, c.id, c.title';
            $stmt = $pdo->prepare($authCheck);
            $stmt->execute($params);
            $info = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($info && $info['exam_date']) {
                $daysLeft = ceil((strtotime((string)$info['exam_date']) - time()) / 86400);
                if ($daysLeft >= 0) {
                    $title = "🎓 Kỳ thi đang đến gần!";
                    $message = "Chỉ còn {$daysLeft} ngày nữa là đến kỳ thi môn '{$info['course_title']}'. Hãy tranh thủ ôn bài và luyện tập nhé!";
                    
                    // Web Notification
                    createNotification($pdo, $sId, 'reminder', $title, $message, '#');
                    
                    // Email
                    $baseUrl = "http://" . $_SERVER['HTTP_HOST'] . "/LMSTinHocCanTho/";
                    $examDateStr = date('d/m/Y', strtotime((string)$info['exam_date']));
                    $emailBody = get_exam_reminder_email_html($info['name'], $info['course_title'], $examDateStr, $daysLeft, $baseUrl . 'index.php');
                    sendSystemEmail($info['email'], $title, $emailBody);
                    
                    // Log
                    $pdo->prepare("INSERT INTO reminder_logs (user_id, type, reference_id) VALUES (?, 'exam_reminder_student', ?)")
                        ->execute([$sId, $cId]);
                    $count++;
                }
            }
        }
        $_SESSION['success'] = "Đã gửi thông báo nhắc nhở cho $count học viên.";
    } else {
        $_SESSION['error'] = "Vui lòng chọn ít nhất 1 học viên để gửi nhắc nhở.";
    }
    header('Location: ' . $returnUrl);
    exit;
}

$conditions = [];
$parameters = [];
if (!$isAdmin) {
    $conditions[] = '((c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)) OR lc.primary_teacher_id=? OR EXISTS (SELECT 1 FROM learning_class_teachers viewer_lct WHERE viewer_lct.learning_class_id=lc.id AND viewer_lct.teacher_id=?))';
    array_push($parameters, $actorId, $actorId, $actorId, $actorId);
}
if ($courseFilter) {
    $conditions[] = 'c.id = ?';
    $parameters[] = (int) $courseFilter;
}
if ($classFilter) {
    $conditions[] = 'lc.id = ?';
    $parameters[] = (int) $classFilter;
}
$whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$courseSql = !$isAdmin
    ? "SELECT DISTINCT c.id, c.title FROM courses c
       WHERE c.teacher_id=?
          OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?)
          OR EXISTS (
              SELECT 1 FROM learning_classes access_lc
              WHERE access_lc.course_id=c.id AND access_lc.status='active'
                AND (access_lc.primary_teacher_id=? OR EXISTS (SELECT 1 FROM learning_class_teachers access_lct WHERE access_lct.learning_class_id=access_lc.id AND access_lct.teacher_id=?))
          )
       ORDER BY c.title"
    : 'SELECT id, title FROM courses ORDER BY title';
$courseStmt = $pdo->prepare($courseSql);
$courseStmt->execute(!$isAdmin ? [$actorId, $actorId, $actorId, $actorId] : []);
$availableCourses = $courseStmt->fetchAll();

$classSql = "SELECT DISTINCT lc.id, lc.class_name, lc.course_id, c.title course_title
             FROM learning_classes lc
             JOIN courses c ON c.id=lc.course_id
             WHERE lc.status='active'";
$classParams = [];
if (!$isAdmin) {
    $classSql .= " AND ((c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))
                       OR lc.primary_teacher_id=?
                       OR EXISTS (SELECT 1 FROM learning_class_teachers viewer_lct WHERE viewer_lct.learning_class_id=lc.id AND viewer_lct.teacher_id=?))";
    array_push($classParams, $actorId, $actorId, $actorId, $actorId);
}
if ($courseFilter) {
    $classSql .= ' AND c.id=?';
    $classParams[] = (int) $courseFilter;
}
$classSql .= ' ORDER BY c.title, lc.class_name, lc.id';
$classStmt = $pdo->prepare($classSql);
$classStmt->execute($classParams);
$availableClasses = $classStmt->fetchAll(PDO::FETCH_ASSOC);
$selectedClassName = '';
foreach ($availableClasses as $availableClass) {
    if ((int) $availableClass['id'] === (int) $classFilter) {
        $selectedClassName = (string) $availableClass['class_name'];
        break;
    }
}

// Fetch upcoming exams (<= 7 days) for the modal
$upcomingSql = "
    SELECT lc.course_id, lcs.student_id, MIN(lcs.exam_date) exam_date, u.name as student_name, c.title as course_title
    FROM learning_classes lc
    JOIN learning_class_students lcs ON lcs.learning_class_id=lc.id
    JOIN users u ON u.id=lcs.student_id
    JOIN courses c ON c.id=lc.course_id
    WHERE lc.status='active' AND lcs.exam_date IS NOT NULL
      AND lcs.exam_date >= CURDATE()
      AND lcs.exam_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
";
$upcomingParams = [];
if (!$isAdmin) {
    $upcomingSql .= " AND (c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))";
    $upcomingParams[] = $actorId;
    $upcomingParams[] = $actorId;
}
if ($courseFilter) {
    $upcomingSql .= " AND c.id = ?";
    $upcomingParams[] = (int) $courseFilter;
}
if ($classFilter) {
    $upcomingSql .= ' AND lc.id = ?';
    $upcomingParams[] = (int) $classFilter;
}
$upcomingSql .= " GROUP BY lc.course_id, lcs.student_id, u.name, c.title ORDER BY exam_date ASC";
$stmtUp = $pdo->prepare($upcomingSql);
$stmtUp->execute($upcomingParams);
$upcomingExamsList = $stmtUp->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare(
    "SELECT
        c.id course_id,
        c.title course_title,
        u.id student_id,
        u.name student_name,
        u.email student_email,
        MAX(lcs.exam_date) exam_date,
        (SELECT COUNT(*) FROM assignments a WHERE a.course_id=c.id) assignment_total,
        (SELECT COUNT(DISTINCT s.assignment_id)
         FROM submissions s
         JOIN assignments a_done ON a_done.id=s.assignment_id
         WHERE a_done.course_id=c.id AND s.student_id=u.id) assignment_completed,
        (SELECT AVG(s_avg.score)
         FROM submissions s_avg
         JOIN assignments a_avg ON a_avg.id=s_avg.assignment_id
         WHERE a_avg.course_id=c.id AND s_avg.student_id=u.id AND s_avg.score IS NOT NULL) assignment_average,
        (SELECT COUNT(*) FROM quizzes q WHERE q.course_id=c.id AND q.is_published=1) quiz_total,
        (SELECT COUNT(DISTINCT qa.quiz_id)
         FROM quiz_attempts qa
         JOIN quizzes q_done ON q_done.id=qa.quiz_id
         WHERE q_done.course_id=c.id AND q_done.is_published=1
           AND qa.student_id=u.id AND qa.submitted_at IS NOT NULL) quiz_completed,
        NULL quiz_average
     FROM learning_classes lc
     JOIN learning_class_students lcs ON lcs.learning_class_id=lc.id
     JOIN courses c ON c.id=lc.course_id
     JOIN users u ON u.id=lcs.student_id
     $whereSql
       " . ($whereSql ? " AND" : " WHERE") . " lc.status='active'
     GROUP BY c.id, c.title, u.id, u.name, u.email
     ORDER BY c.title, u.name, u.id"
);
$stmt->execute($parameters);
$progressRows = $stmt->fetchAll();

$quizBestConditions = [
    'q.is_published=1',
    'qa.submitted_at IS NOT NULL',
    'qa.score IS NOT NULL',
];
$quizBestParameters = [];
if (!$isAdmin) {
    $quizBestConditions[] = "EXISTS (
        SELECT 1
        FROM learning_classes access_lc
        JOIN learning_class_students access_lcs ON access_lcs.learning_class_id=access_lc.id
        WHERE access_lc.course_id=q.course_id
          AND access_lcs.student_id=qa.student_id
          AND access_lc.status='active'
          AND ((c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?))
               OR access_lc.primary_teacher_id=?
               OR EXISTS (SELECT 1 FROM learning_class_teachers access_lct WHERE access_lct.learning_class_id=access_lc.id AND access_lct.teacher_id=?))
    )";
    array_push($quizBestParameters, $actorId, $actorId, $actorId, $actorId);
}
if ($courseFilter) {
    $quizBestConditions[] = 'c.id=?';
    $quizBestParameters[] = (int) $courseFilter;
}
if ($classFilter) {
    $quizBestConditions[] = "EXISTS (
        SELECT 1
        FROM learning_classes filter_lc
        JOIN learning_class_students filter_lcs ON filter_lcs.learning_class_id=filter_lc.id
        WHERE filter_lc.id=?
          AND filter_lc.status='active'
          AND filter_lc.course_id=q.course_id
          AND filter_lcs.student_id=qa.student_id
    )";
    $quizBestParameters[] = (int) $classFilter;
}
$quizBestStmt = $pdo->prepare(
    "SELECT best.course_id, best.student_id, AVG(best.best_score) quiz_average
     FROM (
         SELECT q.course_id, qa.student_id, qa.quiz_id, MAX(qa.score) best_score
         FROM quiz_attempts qa
         JOIN quizzes q ON q.id=qa.quiz_id
         JOIN courses c ON c.id=q.course_id
         WHERE " . implode(' AND ', $quizBestConditions) . "
         GROUP BY q.course_id, qa.student_id, qa.quiz_id
     ) best
     GROUP BY best.course_id, best.student_id"
);
$quizBestStmt->execute($quizBestParameters);
$quizBestAverages = [];
foreach ($quizBestStmt->fetchAll() as $quizAverageRow) {
    $quizBestAverages[(int) $quizAverageRow['course_id']][(int) $quizAverageRow['student_id']]
        = (float) $quizAverageRow['quiz_average'];
}

$courses = [];
foreach ($progressRows as $row) {
    $courseId = (int) $row['course_id'];
    if (!isset($courses[$courseId])) {
        $courses[$courseId] = [
            'title' => (string) $row['course_title'],
            'can_manage' => isset($manageableCourseIds[$courseId]),
            'students' => [],
        ];
    }
    $assignmentTotal = (int) $row['assignment_total'];
    $assignmentCompleted = (int) $row['assignment_completed'];
    $quizTotal = (int) $row['quiz_total'];
    $quizCompleted = (int) $row['quiz_completed'];
    $row['assignment_percent'] = $assignmentTotal > 0
        ? min(100, round($assignmentCompleted / $assignmentTotal * 100, 1))
        : 0;
    $row['quiz_percent'] = $quizTotal > 0
        ? min(100, round($quizCompleted / $quizTotal * 100, 1))
        : 0;
    $row['quiz_average'] = $quizBestAverages[$courseId][(int) $row['student_id']] ?? null;
    $courses[$courseId]['students'][] = $row;
}

$page_title = 'Tiến độ Học viên';
require_once '../includes/header.php';
?>
<style>
    .progress-toolbar{display:grid;gap:18px;margin-bottom:22px}
    .progress-toolbar-actions{display:flex;align-items:end;gap:12px;flex-wrap:wrap}
    .progress-filter{display:grid;grid-template-columns:minmax(220px,1fr) minmax(300px,2fr) auto;align-items:end;gap:10px;flex:1 1 760px}
    .progress-filter label{display:grid;gap:6px;margin-bottom:0;color:var(--text-muted);font-size:13px}
    .progress-filter select{min-width:0;width:100%}
    .progress-filter select,.progress-filter .btn{height:40px;min-height:40px;box-sizing:border-box}
    .progress-filter .btn{align-self:end;justify-content:center;padding:0 20px;white-space:nowrap;transform:none}
    .progress-filter .is-disabled{opacity:.45;pointer-events:none;cursor:default}
    .course-progress{margin-bottom:22px}
    .course-progress-heading{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px}
    .course-progress-heading h2{margin:0}
    .progress-cell{min-width:180px}
    .progress-cell-head{display:flex;justify-content:space-between;gap:8px;margin-bottom:7px;font-size:13px}
    .progress-track{height:9px;border-radius:999px;background:rgba(148,163,184,.18);overflow:hidden}
    .progress-fill{height:100%;border-radius:inherit;background:linear-gradient(90deg,var(--primary),#38bdf8)}
    .progress-fill.quiz{background:linear-gradient(90deg,#10b981,#34d399)}
    .average-score{font-size:18px;font-weight:800;color:var(--success);white-space:nowrap}
    .no-score{color:var(--text-muted)}
    @media(max-width:700px){
        .progress-toolbar-actions,.progress-filter{width:100%}
        .progress-filter{grid-template-columns:1fr}
        .progress-filter label,.progress-filter select,.progress-filter .btn{width:100%}
        .progress-filter .btn{transform:none}
    }
</style>

<div class="box">
    <div class="progress-toolbar">
        <div>
            <h2 style="margin:0 0 7px"><i class='bx bx-line-chart'></i> Tiến độ Học viên</h2>
            <p style="margin:0;color:var(--text-muted)">Theo dõi mức độ hoàn thành và điểm trung bình của từng học viên.</p>
        </div>
        <div class="progress-toolbar-actions">
            <a href="../preview_email.php?type=exam" target="_blank" class="btn btn-outline" style="height: 40px; border-color:var(--primary); color:var(--primary);">
                <i class='bx bx-search-alt'></i> Xem trước mẫu Email
            </a>
            <?php if (count($upcomingExamsList) > 0): ?>
            <button type="button" class="btn btn-primary" onclick="document.getElementById('bulkReminderModal').style.display='flex'" style="height: 40px; background:var(--success); border-color:var(--success);">
                <i class='bx bx-mail-send'></i> Gửi nhắc nhở hàng loạt (<?php echo count($upcomingExamsList); ?>)
            </button>
            <?php endif; ?>
            <form method="GET" class="progress-filter">
                <label>
                    Khóa học
                    <select name="course_id" onchange="this.form.elements.class_id.value='';this.form.submit()">
                        <option value="">Tất cả khóa học</option>
                        <?php foreach ($availableCourses as $filterCourse): ?>
                            <option value="<?php echo (int) $filterCourse['id']; ?>" <?php echo (int) $courseFilter === (int) $filterCourse['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string) $filterCourse['title'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Lớp học
                    <select name="class_id" onchange="this.form.submit()">
                        <option value="">Tất cả lớp học</option>
                        <?php foreach ($availableClasses as $filterClass): ?>
                            <option value="<?php echo (int) $filterClass['id']; ?>" <?php echo (int) $classFilter === (int) $filterClass['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string) $filterClass['course_title'] . ' — ' . (string) $filterClass['class_name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <a class="btn btn-outline <?php echo $courseFilter || $classFilter ? '' : 'is-disabled'; ?>" href="student_progress.php" aria-disabled="<?php echo $courseFilter || $classFilter ? 'false' : 'true'; ?>"><i class='bx bx-reset'></i> Xóa lọc</a>
            </form>
        </div>
    </div>
</div>

<?php foreach ($courses as $courseId => $course): ?>
    <section class="box course-progress">
        <div class="course-progress-heading">
            <h2><i class='bx bx-book-open'></i> <?php echo htmlspecialchars($course['title'] . ($selectedClassName !== '' ? ' — ' . $selectedClassName : ''), ENT_QUOTES, 'UTF-8'); ?></h2>
            <span style="color:var(--text-muted)"><?php echo count($course['students']); ?> học viên<?php echo $course['can_manage'] ? '' : ' · Chỉ xem'; ?></span>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Học viên</th>
                        <th>Ngày thi</th>
                        <th>Tiến độ bài tập</th>
                        <th>Điểm TB bài tập</th>
                        <th>Tiến độ trắc nghiệm</th>
                        <th>Điểm TB trắc nghiệm</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($course['students'] as $student): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars((string) $student['student_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <small style="display:block;color:var(--text-muted)"><?php echo htmlspecialchars((string) $student['student_email'], ENT_QUOTES, 'UTF-8'); ?></small>
                            </td>
                            <td>
                                <?php if ($course['can_manage']): ?>
                                <form method="post" style="display:flex; gap:6px; align-items:center; margin-bottom: 6px;">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="set_exam_date">
                                    <input type="hidden" name="course_id" value="<?php echo $courseId; ?>">
                                    <input type="hidden" name="student_id" value="<?php echo $student['student_id']; ?>">
                                    <input type="date" name="exam_date" value="<?php echo $student['exam_date'] ? date('Y-m-d', strtotime((string)$student['exam_date'])) : ''; ?>" 
                                           style="padding:6px; border:1px solid var(--border-color); border-radius:6px; background:rgba(0,0,0,0.2); color:var(--text-main); font-size:12px;">
                                    <button type="submit" class="btn btn-primary" title="Lưu ngày thi" style="padding:4px 8px; font-size:12px; min-height:0;"><i class='bx bx-check'></i></button>
                                </form>
                                <?php if ($student['exam_date'] && strtotime((string)$student['exam_date']) > time()): ?>
                                <form method="post" style="display:flex; gap:6px; align-items:center;">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="send_exam_reminder">
                                    <input type="hidden" name="course_id" value="<?php echo $courseId; ?>">
                                    <input type="hidden" name="student_id" value="<?php echo $student['student_id']; ?>">
                                    <button type="submit" class="btn btn-outline" style="padding:4px 8px; font-size:11px; min-height:0; width: 100%; border-color: rgba(99,102,241,0.5); color: #818cf8;">
                                        <i class='bx bx-envelope'></i> Gửi nhắc nhở
                                    </button>
                                </form>
                                <?php endif; ?>
                                <?php else: ?>
                                    <span><?php echo $student['exam_date'] ? date('d/m/Y', strtotime((string) $student['exam_date'])) : 'Chưa đặt'; ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="progress-cell">
                                    <div class="progress-cell-head">
                                        <span><?php echo (int) $student['assignment_completed']; ?>/<?php echo (int) $student['assignment_total']; ?> bài</span>
                                        <strong><?php echo $student['assignment_percent']; ?>%</strong>
                                    </div>
                                    <div class="progress-track"><div class="progress-fill" style="width:<?php echo $student['assignment_percent']; ?>%"></div></div>
                                </div>
                            </td>
                            <td>
                                <?php if ($student['assignment_average'] !== null): ?>
                                    <span class="average-score"><?php echo number_format((float) $student['assignment_average'], 2); ?></span><span style="color:var(--text-muted)">/10</span>
                                <?php else: ?><span class="no-score">Chưa có điểm</span><?php endif; ?>
                            </td>
                            <td>
                                <div class="progress-cell">
                                    <div class="progress-cell-head">
                                        <span><?php echo (int) $student['quiz_completed']; ?>/<?php echo (int) $student['quiz_total']; ?> bài</span>
                                        <strong><?php echo $student['quiz_percent']; ?>%</strong>
                                    </div>
                                    <div class="progress-track"><div class="progress-fill quiz" style="width:<?php echo $student['quiz_percent']; ?>%"></div></div>
                                </div>
                            </td>
                            <td>
                                <?php if ($student['quiz_average'] !== null): ?>
                                    <span class="average-score"><?php echo number_format((float) $student['quiz_average'], 2); ?></span><span style="color:var(--text-muted)">/10</span>
                                <?php else: ?><span class="no-score">Chưa có điểm</span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>

<?php if (!$courses): ?>
    <div class="box" style="text-align:center;padding:40px 20px;color:var(--text-muted)">
        <i class='bx bx-folder-open' style="font-size:48px;opacity:0.5;margin-bottom:10px"></i>
        <p>Chưa có học viên nào trong khóa học này.</p>
    </div>
<?php endif; ?>

<!-- Bulk Reminder Modal -->
<div id="bulkReminderModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center; backdrop-filter:blur(4px);">
    <div style="background:var(--panel-bg); border:1px solid var(--border-color); border-radius:12px; width:100%; max-width:600px; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 15px 35px rgba(0,0,0,0.5);">
        <div style="padding:20px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0;"><i class='bx bx-mail-send'></i> Gửi nhắc nhở hàng loạt</h3>
            <button type="button" onclick="document.getElementById('bulkReminderModal').style.display='none'" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:24px;">&times;</button>
        </div>
        <form method="POST" style="display:flex; flex-direction:column; overflow:hidden;">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="bulk_send_exam_reminder">
            <div style="padding:20px; overflow-y:auto; flex:1;">
                <p style="margin-top:0; color:var(--text-muted);">Danh sách học viên sắp thi trong 7 ngày tới (<?php echo count($upcomingExamsList); ?> học viên):</p>
                <div style="display:flex; flex-direction:column; gap:8px;">
                    <?php foreach ($upcomingExamsList as $up): ?>
                        <label style="display:flex; align-items:center; gap:12px; padding:12px; background:rgba(0,0,0,0.15); border:1px solid var(--border-color); border-radius:8px; cursor:pointer;">
                            <input type="checkbox" name="reminders[]" value="<?php echo $up['student_id'] . '-' . $up['course_id']; ?>" checked style="width:18px; height:18px; accent-color:var(--primary);">
                            <div>
                                <strong style="display:block;"><?php echo htmlspecialchars($up['student_name']); ?></strong>
                                <small style="color:var(--text-muted);"><?php echo htmlspecialchars($up['course_title']); ?> - Ngày thi: <?php echo date('d/m/Y', strtotime($up['exam_date'])); ?></small>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="padding:20px; border-top:1px solid var(--border-color); text-align:right;">
                <button type="button" onclick="document.getElementById('bulkReminderModal').style.display='none'" class="btn btn-outline">Hủy</button>
                <button type="submit" class="btn btn-primary" style="margin-left:10px;"><i class='bx bx-send'></i> Gửi tất cả</button>
            </div>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
