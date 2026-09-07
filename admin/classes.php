<?php
declare(strict_types=1);

require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/authorization.php';
require_once '../includes/audit.php';

$role = (string) ($_SESSION['user_role'] ?? '');
$actorId = (int) ($_SESSION['user_id'] ?? 0);
if (!in_array($role, ['admin', 'teacher', 'administrative_staff'], true) || $actorId <= 0) {
    header('Location: ../index.php');
    exit;
}
$isAdmin = $role === 'admin';

function learningClassIds(mixed $value, array $allowed): array
{
    $ids = array_values(array_unique(array_map('intval', is_array($value) ? $value : [])));
    $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0 && isset($allowed[$id])));
    sort($ids);
    return $ids;
}

function canManageLearningClass(PDO $pdo, int $classId, int $actorId, bool $isAdmin): bool
{
    if ($isAdmin) return true;
    $stmt = $pdo->prepare('SELECT 1 FROM learning_classes WHERE id=? AND primary_teacher_id=? LIMIT 1');
    $stmt->execute([$classId, $actorId]);
    return (bool) $stmt->fetchColumn();
}

function learningClassExamDates(mixed $value, array $studentIds): array
{
    $allowed = array_fill_keys($studentIds, true);
    $dates = [];
    foreach (is_array($value) ? $value : [] as $studentId => $date) {
        $studentId = (int) $studentId;
        $date = trim((string) $date);
        if (isset($allowed[$studentId]) && ($date === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))) $dates[$studentId] = $date ?: null;
    }
    return $dates;
}

function syncLearningClassMembers(PDO $pdo, int $classId, int $courseId, ?int $primaryTeacherId, array $teacherIds, array $studentIds, array $examDates, int $actorId): void
{
    if ($primaryTeacherId) $teacherIds[] = $primaryTeacherId;
    $teacherIds = array_values(array_unique(array_filter(array_map('intval', $teacherIds))));
    $pdo->prepare('DELETE FROM learning_class_teachers WHERE learning_class_id=?')->execute([$classId]);
    $teacherInsert = $pdo->prepare('INSERT INTO learning_class_teachers (learning_class_id, teacher_id, added_by) VALUES (?, ?, ?)');
    foreach ($teacherIds as $teacherId) $teacherInsert->execute([$classId, $teacherId, $actorId]);

    $selectedStudents = array_fill_keys($studentIds, true);
    $existingStmt = $pdo->prepare('SELECT student_id FROM learning_class_students WHERE learning_class_id=?');
    $existingStmt->execute([$classId]);
    $deleteStudent = $pdo->prepare('DELETE FROM learning_class_students WHERE learning_class_id=? AND student_id=?');
    foreach ($existingStmt->fetchAll(PDO::FETCH_COLUMN) as $existingStudentId) {
        if (!isset($selectedStudents[(int) $existingStudentId])) $deleteStudent->execute([$classId, (int) $existingStudentId]);
    }
    $studentInsert = $pdo->prepare('INSERT IGNORE INTO learning_class_students (learning_class_id, student_id, exam_date, added_by) VALUES (?, ?, ?, ?)');
    $studentExamUpdate = $pdo->prepare('UPDATE learning_class_students lcs JOIN learning_classes lc ON lc.id=lcs.learning_class_id SET lcs.exam_date=? WHERE lc.course_id=? AND lcs.student_id=?');
    foreach ($studentIds as $studentId) {
        $examDate = $examDates[$studentId] ?? null;
        $studentInsert->execute([$classId, $studentId, $examDate, $actorId]);
        if (array_key_exists($studentId, $examDates)) $studentExamUpdate->execute([$examDate, $courseId, $studentId]);
    }
}

$teachers = $pdo->query("SELECT id, name, email FROM users WHERE role IN ('teacher','administrative_staff','admin') AND is_approved=1 AND COALESCE(is_locked,0)=0 ORDER BY name, id")->fetchAll(PDO::FETCH_ASSOC);
$students = $pdo->query("SELECT id, name, email FROM users WHERE role='student' AND is_approved=1 AND COALESCE(is_locked,0)=0 ORDER BY name, id")->fetchAll(PDO::FETCH_ASSOC);
$teacherMap = array_fill_keys(array_map('intval', array_column($teachers, 'id')), true);
$studentMap = array_fill_keys(array_map('intval', array_column($students, 'id')), true);
if ($isAdmin) {
    $courses = $pdo->query('SELECT id, title FROM courses ORDER BY title, id')->fetchAll(PDO::FETCH_ASSOC);
} else {
    $courseStmt = $pdo->prepare('SELECT DISTINCT c.id, c.title FROM courses c WHERE c.teacher_id=? OR EXISTS (SELECT 1 FROM course_teachers ct WHERE ct.course_id=c.id AND ct.teacher_id=?) ORDER BY c.title, c.id');
    $courseStmt->execute([$actorId, $actorId]);
    $courses = $courseStmt->fetchAll(PDO::FETCH_ASSOC);
}
$courseMap = array_fill_keys(array_map('intval', array_column($courses, 'id')), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $action = (string) ($_POST['action'] ?? '');
    $classId = (int) ($_POST['class_id'] ?? 0);
    try {
        if ($action === 'create' || $action === 'update') {
            $className = trim((string) ($_POST['class_name'] ?? ''));
            $courseId = (int) ($_POST['course_id'] ?? 0);
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $teacherIds = learningClassIds($_POST['teacher_ids'] ?? [], $teacherMap);
            $studentIds = learningClassIds($_POST['student_ids'] ?? [], $studentMap);
            $examDates = learningClassExamDates($_POST['exam_dates'] ?? [], $studentIds);
            if ($className === '') throw new RuntimeException('Vui lòng nhập tên lớp.');
            $canUseCourse = $courseId > 0 && isset($courseMap[$courseId]) && authorizationUserCanManageCourse($pdo, $courseId, $role, $actorId);
            if (!$canUseCourse && $action === 'update' && $classId > 0 && canManageLearningClass($pdo, $classId, $actorId, $isAdmin)) {
                $sameCourseStmt = $pdo->prepare('SELECT 1 FROM learning_classes WHERE id=? AND course_id=? LIMIT 1');
                $sameCourseStmt->execute([$classId, $courseId]);
                $canUseCourse = (bool) $sameCourseStmt->fetchColumn();
            }
            if (!$canUseCourse) {
                throw new RuntimeException('Khóa học không hợp lệ hoặc bạn không có quyền quản lý khóa học này.');
            }

            if ($action === 'create') {
                $primaryTeacherId = $isAdmin ? ((int) ($_POST['primary_teacher_id'] ?? 0) ?: null) : $actorId;
                if ($primaryTeacherId !== null && !isset($teacherMap[$primaryTeacherId])) throw new RuntimeException('Giáo viên phụ trách không hợp lệ.');
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('INSERT INTO learning_classes (class_name, course_id, primary_teacher_id, notes, created_by) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([mb_substr($className, 0, 191, 'UTF-8'), $courseId, $primaryTeacherId, $notes ?: null, $actorId]);
                $classId = (int) $pdo->lastInsertId();
                syncLearningClassMembers($pdo, $classId, $courseId, $primaryTeacherId, $teacherIds, $studentIds, $examDates, $actorId);
                $pdo->commit();
                writeAuditLog($pdo, 'learning_class.created', 'learning_class', $classId, ['course_id' => $courseId, 'students' => count($studentIds)]);
                $_SESSION['success'] = 'Đã tạo lớp học mới.';
            } else {
                if ($classId <= 0 || !canManageLearningClass($pdo, $classId, $actorId, $isAdmin)) throw new RuntimeException('Bạn không có quyền sửa lớp này.');
                $currentStmt = $pdo->prepare('SELECT primary_teacher_id FROM learning_classes WHERE id=?');
                $currentStmt->execute([$classId]);
                $currentPrimary = $currentStmt->fetchColumn();
                if ($currentPrimary === false) throw new RuntimeException('Không tìm thấy lớp học.');
                $primaryTeacherId = $isAdmin ? ((int) ($_POST['primary_teacher_id'] ?? 0) ?: null) : (((int) $currentPrimary) ?: null);
                if ($primaryTeacherId !== null && !isset($teacherMap[$primaryTeacherId])) throw new RuntimeException('Giáo viên phụ trách không hợp lệ.');
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('UPDATE learning_classes SET class_name=?, course_id=?, primary_teacher_id=?, notes=? WHERE id=?');
                $stmt->execute([mb_substr($className, 0, 191, 'UTF-8'), $courseId, $primaryTeacherId, $notes ?: null, $classId]);
                syncLearningClassMembers($pdo, $classId, $courseId, $primaryTeacherId, $teacherIds, $studentIds, $examDates, $actorId);
                $pdo->commit();
                writeAuditLog($pdo, 'learning_class.updated', 'learning_class', $classId, ['course_id' => $courseId, 'students' => count($studentIds)]);
                $_SESSION['success'] = 'Đã cập nhật lớp học.';
            }
        } elseif ($action === 'archive' || $action === 'resume') {
            if ($classId <= 0 || !canManageLearningClass($pdo, $classId, $actorId, $isAdmin)) throw new RuntimeException('Bạn không có quyền thay đổi lớp này.');
            $status = $action === 'archive' ? 'archived' : 'active';
            $pdo->prepare('UPDATE learning_classes SET status=? WHERE id=?')->execute([$status, $classId]);
            writeAuditLog($pdo, 'learning_class.' . $status, 'learning_class', $classId);
            $_SESSION['success'] = $status === 'active' ? 'Đã mở lại lớp học.' : 'Đã lưu trữ lớp học. Học viên không còn được cấp quyền qua lớp này.';
        } elseif ($action === 'delete') {
            if ($classId <= 0 || !canManageLearningClass($pdo, $classId, $actorId, $isAdmin)) throw new RuntimeException('Bạn không có quyền xóa lớp này.');
            $ownerStmt = $pdo->prepare('SELECT primary_teacher_id FROM learning_classes WHERE id=?');
            $ownerStmt->execute([$classId]);
            $primaryId = (int) $ownerStmt->fetchColumn();
            if (!$isAdmin && $primaryId !== $actorId) throw new RuntimeException('Chỉ giáo viên phụ trách chính hoặc quản trị viên được xóa lớp.');
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM learning_class_teachers WHERE learning_class_id=?')->execute([$classId]);
            $pdo->prepare('DELETE FROM learning_class_students WHERE learning_class_id=?')->execute([$classId]);
            $pdo->prepare('DELETE FROM learning_classes WHERE id=?')->execute([$classId]);
            $pdo->commit();
            writeAuditLog($pdo, 'learning_class.deleted', 'learning_class', $classId);
            $_SESSION['success'] = 'Đã xóa lớp học.';
        }
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = $error->getMessage();
    }
    header('Location: classes.php');
    exit;
}

$classSql = "SELECT lc.id, lc.class_name, lc.course_id, lc.primary_teacher_id, lc.notes, lc.status, lc.created_at,
                    c.title AS course_title, primary_teacher.name AS primary_teacher_name,
                    (SELECT GROUP_CONCAT(lct.teacher_id ORDER BY lct.teacher_id) FROM learning_class_teachers lct WHERE lct.learning_class_id=lc.id) AS teacher_ids,
                    (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ') FROM learning_class_teachers lct JOIN users t ON t.id=lct.teacher_id WHERE lct.learning_class_id=lc.id) AS teacher_names,
                    (SELECT GROUP_CONCAT(lcs.student_id ORDER BY lcs.student_id) FROM learning_class_students lcs WHERE lcs.learning_class_id=lc.id) AS student_ids,
                    (SELECT GROUP_CONCAT(CONCAT(lcs.student_id, '=', COALESCE(DATE_FORMAT(lcs.exam_date, '%Y-%m-%d'), '')) ORDER BY lcs.student_id SEPARATOR '|') FROM learning_class_students lcs WHERE lcs.learning_class_id=lc.id) AS student_exam_dates,
                    (SELECT COUNT(*) FROM learning_class_students lcs WHERE lcs.learning_class_id=lc.id) AS student_count
             FROM learning_classes lc
             JOIN courses c ON c.id=lc.course_id
             LEFT JOIN users primary_teacher ON primary_teacher.id=lc.primary_teacher_id";
if (!$isAdmin) $classSql .= ' WHERE lc.primary_teacher_id=' . $actorId . ' OR EXISTS (SELECT 1 FROM learning_class_teachers access_teacher WHERE access_teacher.learning_class_id=lc.id AND access_teacher.teacher_id=' . $actorId . ')';
$classSql .= " ORDER BY FIELD(lc.status, 'active', 'archived'), lc.updated_at DESC, lc.id DESC";
$classes = $pdo->query($classSql)->fetchAll(PDO::FETCH_ASSOC);
$activeCount = count(array_filter($classes, static fn(array $class): bool => $class['status'] === 'active'));
$studentTotal = array_sum(array_map(static fn(array $class): int => (int) $class['student_count'], array_filter($classes, static fn(array $class): bool => $class['status'] === 'active')));

$page_title = 'Quản lý lớp học';
require_once '../includes/header.php';
?>
<style>
.class-page-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;flex-wrap:wrap}.class-page-head h1{margin-bottom:7px}.class-page-head p{margin:0;color:var(--text-muted)}.class-stats{display:flex;gap:10px;margin:20px 0;flex-wrap:wrap}.class-stat{padding:12px 16px;border:1px solid var(--border-color);border-radius:13px;background:var(--glass-bg)}.class-stat strong{font-size:21px;margin-right:6px}.learning-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px}.learning-card{padding:18px;border:1px solid var(--border-color);border-radius:16px;background:var(--glass-bg);display:grid;gap:13px}.learning-card.archived{opacity:.7}.learning-card-head{display:flex;justify-content:space-between;gap:12px}.learning-card h2{font-size:19px;margin:0 0 5px}.course-chip,.status-chip{display:inline-flex;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:700}.course-chip{color:#9bd4ff;background:rgba(58,150,230,.14)}.status-chip{height:max-content;color:#54dbaa;background:rgba(30,190,130,.13)}.archived .status-chip{color:#ffd166;background:rgba(255,190,60,.13)}.class-row{display:grid;grid-template-columns:25px 1fr;gap:8px;color:var(--text-muted);font-size:14px}.class-row i{font-size:19px;color:var(--primary)}.class-card-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:auto}.class-card-actions form{margin:0}.class-modal{width:min(720px,calc(100vw - 26px));border:1px solid var(--border-color);border-radius:17px;padding:0;background:var(--sidebar-bg);color:var(--text-main)}.class-modal::backdrop{background:rgba(2,6,23,.72)}.class-modal form{display:grid;gap:14px;padding:22px}.class-modal h2{margin:0}.class-modal label{display:grid;gap:7px;font-weight:700}.class-modal input,.class-modal select,.class-modal textarea{box-sizing:border-box;width:100%;padding:11px 13px;border:1px solid var(--border-color);border-radius:10px;background:var(--input-bg,#101c31);color:var(--text-main);font:inherit}.class-modal select[multiple]{min-height:145px;padding:7px}.class-modal textarea{min-height:85px;resize:vertical}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:13px}.class-help{color:var(--text-muted);font-size:12px;font-weight:400}.exam-date-list{display:grid;gap:8px}.exam-date-row{display:grid;grid-template-columns:minmax(0,1fr) 170px;align-items:center;gap:10px;padding:9px 11px;border:1px solid var(--border-color);border-radius:10px}.exam-date-row span{overflow-wrap:anywhere}.modal-actions{display:flex;justify-content:flex-end;gap:9px}@media(max-width:640px){.form-grid,.exam-date-row{grid-template-columns:1fr}.learning-grid{grid-template-columns:1fr}}
.student-picker-field{display:grid;gap:7px}.student-picker-title{font-weight:700}.student-picker-head{display:flex;align-items:center;justify-content:space-between;gap:10px}.student-selected-count{color:var(--primary);font-size:12px;font-weight:700}.student-checklist{max-height:230px;overflow:auto;display:grid;gap:6px;padding:8px;border:1px solid var(--border-color);border-radius:10px;background:rgba(0,0,0,.08)}.class-modal .student-check-option{display:grid;grid-template-columns:20px minmax(0,1fr);align-items:start;gap:10px;padding:9px 10px;border-radius:8px;font-weight:400;cursor:pointer}.class-modal .student-check-option[hidden],.student-empty[hidden]{display:none}.class-modal .student-check-option:hover{background:rgba(99,102,241,.1)}.class-modal .student-check-option input{width:18px;height:18px;margin:2px 0 0;padding:0;accent-color:var(--primary)}.student-check-option strong,.student-check-option small{display:block;overflow-wrap:anywhere}.student-check-option small{margin-top:2px;color:var(--text-muted)}.student-empty{padding:16px;text-align:center;color:var(--text-muted);font-size:13px}
</style>

<div class="class-page-head">
  <div><h1><i class='bx bx-group'></i> Quản lý lớp học</h1><p>Lớp học quyết định khóa học, giáo viên giám sát và quyền làm bài của học viên. Trang này độc lập với lịch dạy.</p></div>
  <button type="button" class="btn btn-primary" id="new-class" <?php echo $courses ? '' : 'disabled'; ?>><i class='bx bx-plus'></i> Tạo lớp</button>
</div>
<?php if (!$courses): ?><div class="alert alert-error">Bạn cần có quyền quản lý ít nhất một khóa học trước khi tạo lớp.</div><?php endif; ?>
<?php if (!empty($_SESSION['success'])): ?><div class="alert alert-success"><?php echo htmlspecialchars((string) $_SESSION['success']); unset($_SESSION['success']); ?></div><?php endif; ?>
<?php if (!empty($_SESSION['error'])): ?><div class="alert alert-error"><?php echo htmlspecialchars((string) $_SESSION['error']); unset($_SESSION['error']); ?></div><?php endif; ?>
<div class="class-stats"><div class="class-stat"><strong><?php echo $activeCount; ?></strong> lớp đang hoạt động</div><div class="class-stat"><strong><?php echo $studentTotal; ?></strong> lượt học viên</div></div>

<div class="learning-grid">
<?php foreach ($classes as $class): ?>
  <?php $canEditClass = $isAdmin || (int) $class['primary_teacher_id'] === $actorId; ?>
  <article class="learning-card <?php echo $class['status'] === 'archived' ? 'archived' : ''; ?>">
    <div class="learning-card-head"><div><h2><?php echo htmlspecialchars($class['class_name']); ?></h2><span class="course-chip"><i class='bx bx-book-open'></i>&nbsp; <?php echo htmlspecialchars($class['course_title']); ?></span></div><span class="status-chip"><?php echo $class['status'] === 'active' ? 'Đang học' : 'Đã lưu trữ'; ?></span></div>
    <div class="class-row"><i class='bx bx-chalkboard'></i><div><strong>Giáo viên:</strong> <?php echo htmlspecialchars($class['teacher_names'] ?: ($class['primary_teacher_name'] ?: 'Chưa phân công')); ?></div></div>
    <div class="class-row"><i class='bx bx-user'></i><div><strong><?php echo (int) $class['student_count']; ?> học viên</strong></div></div>
    <?php if (!empty($class['notes'])): ?><div class="class-row"><i class='bx bx-note'></i><div><?php echo nl2br(htmlspecialchars($class['notes'])); ?></div></div><?php endif; ?>
    <div class="class-card-actions">
      <?php if ($canEditClass): ?>
        <button type="button" class="btn btn-outline edit-class" data-class='<?php echo htmlspecialchars(json_encode($class, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES); ?>'><i class='bx bx-edit'></i> Sửa</button>
        <form method="post"><?php echo csrfField(); ?><input type="hidden" name="class_id" value="<?php echo (int) $class['id']; ?>"><input type="hidden" name="action" value="<?php echo $class['status'] === 'active' ? 'archive' : 'resume'; ?>"><button class="btn btn-outline"><i class='bx <?php echo $class['status'] === 'active' ? 'bx-archive' : 'bx-refresh'; ?>'></i> <?php echo $class['status'] === 'active' ? 'Lưu trữ' : 'Mở lại'; ?></button></form>
        <form method="post" onsubmit="return confirm('Xóa lớp này? Học viên sẽ mất quyền được cấp qua lớp.');"><?php echo csrfField(); ?><input type="hidden" name="class_id" value="<?php echo (int) $class['id']; ?>"><input type="hidden" name="action" value="delete"><button class="btn btn-outline"><i class='bx bx-trash'></i> Xóa</button></form>
      <?php else: ?><span class="status-chip"><i class='bx bx-show'></i>&nbsp; Chỉ xem</span><?php endif; ?>
    </div>
  </article>
<?php endforeach; ?>
<?php if (!$classes): ?><div class="learning-card"><h2>Chưa có lớp học</h2><p style="color:var(--text-muted);margin:0">Nhấn “Tạo lớp” để phân học viên vào một khóa học.</p></div><?php endif; ?>
</div>

<dialog class="class-modal" id="class-modal">
  <form method="post" id="learning-class-form">
    <?php echo csrfField(); ?><input type="hidden" name="action" id="class-action" value="create"><input type="hidden" name="class_id" id="class-id">
    <h2 id="class-modal-title">Tạo lớp học</h2>
    <div class="form-grid"><label>Tên lớp<input name="class_name" id="class-name" maxlength="191" required placeholder="Ví dụ: Tin học văn phòng K26"></label><label>Khóa học<select name="course_id" id="class-course" required><option value="">— Chọn khóa học —</option><?php foreach ($courses as $course): ?><option value="<?php echo (int) $course['id']; ?>"><?php echo htmlspecialchars($course['title']); ?></option><?php endforeach; ?></select></label></div>
    <?php if ($isAdmin): ?><label>Giáo viên phụ trách chính<select name="primary_teacher_id" id="class-primary"><option value="">— Chưa phân công —</option><?php foreach ($teachers as $teacher): ?><option value="<?php echo (int) $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['name'] . ' — ' . $teacher['email']); ?></option><?php endforeach; ?></select></label><?php endif; ?>
    <div class="student-picker-field">
      <div class="student-picker-head"><span class="student-picker-title">Giáo viên giám sát (chỉ xem)</span><span class="student-selected-count" id="teacher-selected-count">Đã chọn 0</span></div>
      <div class="student-checklist teacher-checklist" id="class-teachers">
        <?php foreach ($teachers as $teacher): ?>
          <label class="student-check-option">
            <input type="checkbox" name="teacher_ids[]" value="<?php echo (int) $teacher['id']; ?>">
            <span><strong><?php echo htmlspecialchars($teacher['name']); ?></strong><small><?php echo htmlspecialchars($teacher['email']); ?></small></span>
          </label>
        <?php endforeach; ?>
      </div>
      <span class="class-help">Giáo viên giám sát chỉ xem lớp và tiến độ; không được sửa lớp hoặc nội dung khóa học.</span>
    </div>
    <div class="student-picker-field">
      <div class="student-picker-head"><span class="student-picker-title">Học viên của lớp</span><span class="student-selected-count" id="student-selected-count">Đã chọn 0</span></div>
      <input type="search" id="student-search" placeholder="Tìm theo tên hoặc email học viên" autocomplete="off">
      <div class="student-checklist" id="class-students">
        <?php foreach ($students as $student): ?>
          <label class="student-check-option" data-search="<?php echo htmlspecialchars($student['name'] . ' ' . $student['email'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="checkbox" name="student_ids[]" value="<?php echo (int) $student['id']; ?>" data-student-name="<?php echo htmlspecialchars($student['name'] . ' — ' . $student['email'], ENT_QUOTES, 'UTF-8'); ?>">
            <span><strong><?php echo htmlspecialchars($student['name']); ?></strong><small><?php echo htmlspecialchars($student['email']); ?></small></span>
          </label>
        <?php endforeach; ?>
        <div class="student-empty" id="student-search-empty" hidden>Không tìm thấy học viên phù hợp.</div>
      </div>
      <span class="class-help">Tích chọn học viên để thêm vào lớp. Học viên trong lớp được làm bài tập và trắc nghiệm của khóa học khi lớp đang hoạt động.</span>
    </div>
    <label>Ngày thi riêng từng học viên<span class="class-help">Chỉ các học viên đã chọn ở trên mới xuất hiện.</span><div class="exam-date-list" id="exam-date-list"></div></label>
    <label>Ghi chú<textarea name="notes" id="class-notes" placeholder="Thông tin nội bộ về lớp"></textarea></label>
    <div class="modal-actions"><button type="button" class="btn btn-outline" id="close-class">Hủy</button><button class="btn btn-primary"><i class='bx bx-save'></i> Lưu lớp</button></div>
  </form>
</dialog>
<script>
(() => {
  const modal = document.getElementById('class-modal');
  const teacherList = document.getElementById('class-teachers');
  const teacherSelectedCount = document.getElementById('teacher-selected-count');
  const teacherCheckboxes = [...teacherList.querySelectorAll('input[name="teacher_ids[]"]')];
  const studentList = document.getElementById('class-students');
  const studentSearch = document.getElementById('student-search');
  const studentSearchEmpty = document.getElementById('student-search-empty');
  const studentSelectedCount = document.getElementById('student-selected-count');
  const studentCheckboxes = [...studentList.querySelectorAll('input[name="student_ids[]"]')];
  const examDateList = document.getElementById('exam-date-list');
  let examDates = {};
  const setCheckedValues = (checkboxes, values) => {
    const selected = new Set(String(values || '').split(',').filter(Boolean));
    checkboxes.forEach(checkbox => { checkbox.checked = selected.has(checkbox.value); });
  };
  const setSelectedStudents = values => setCheckedValues(studentCheckboxes, values);
  const updateTeacherSelectedCount = () => { teacherSelectedCount.textContent = `Đã chọn ${teacherCheckboxes.filter(checkbox => checkbox.checked).length}`; };
  const selectedStudents = () => studentCheckboxes.filter(checkbox => checkbox.checked);
  const normalizeSearch = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').toLowerCase();
  const filterStudents = () => {
    const keyword = normalizeSearch(studentSearch.value.trim());
    let visibleCount = 0;
    studentList.querySelectorAll('.student-check-option').forEach(option => {
      const visible = keyword === '' || normalizeSearch(option.dataset.search).includes(keyword);
      option.hidden = !visible;
      if (visible) visibleCount++;
    });
    studentSearchEmpty.hidden = visibleCount > 0;
  };
  const rememberExamDates = () => examDateList.querySelectorAll('input[data-student-id]').forEach(input => { examDates[input.dataset.studentId] = input.value; });
  const renderExamDates = () => {
    rememberExamDates();
    examDateList.replaceChildren();
    const selected = selectedStudents();
    studentSelectedCount.textContent = `Đã chọn ${selected.length}`;
    selected.forEach(checkbox => {
      const row = document.createElement('div'); row.className = 'exam-date-row';
      const name = document.createElement('span'); name.textContent = checkbox.dataset.studentName;
      const input = document.createElement('input'); input.type = 'date'; input.name = `exam_dates[${checkbox.value}]`; input.dataset.studentId = checkbox.value; input.value = examDates[checkbox.value] || '';
      row.append(name, input); examDateList.append(row);
    });
    if (!selected.length) {
      const help = document.createElement('span'); help.className = 'class-help'; help.textContent = 'Chưa chọn học viên.'; examDateList.append(help);
    }
  };
  const openCreate = () => {
    document.getElementById('learning-class-form').reset();
    studentSearch.value = '';
    examDates = {};
    document.getElementById('class-action').value = 'create'; document.getElementById('class-id').value = '';
    document.getElementById('class-modal-title').textContent = 'Tạo lớp học';
    <?php if (!$isAdmin): ?>setCheckedValues(teacherCheckboxes, '<?php echo $actorId; ?>');<?php endif; ?>
    filterStudents();
    updateTeacherSelectedCount();
    renderExamDates();
    modal.showModal();
  };
  document.getElementById('new-class')?.addEventListener('click', openCreate);
  document.getElementById('close-class').addEventListener('click', () => modal.close());
  document.querySelectorAll('.edit-class').forEach(button => button.addEventListener('click', () => {
    const item = JSON.parse(button.dataset.class);
    document.getElementById('class-action').value = 'update'; document.getElementById('class-id').value = item.id;
    document.getElementById('class-modal-title').textContent = 'Sửa lớp học';
    document.getElementById('class-name').value = item.class_name || ''; document.getElementById('class-course').value = item.course_id || '';
    document.getElementById('class-notes').value = item.notes || '';
    const primary = document.getElementById('class-primary'); if (primary) primary.value = item.primary_teacher_id || '';
    setCheckedValues(teacherCheckboxes, item.teacher_ids); setSelectedStudents(item.student_ids);
    studentSearch.value = '';
    examDates = {};
    String(item.student_exam_dates || '').split('|').filter(Boolean).forEach(pair => { const splitAt = pair.indexOf('='); if (splitAt >= 0) examDates[pair.slice(0, splitAt)] = pair.slice(splitAt + 1); });
    filterStudents();
    updateTeacherSelectedCount();
    renderExamDates();
    modal.showModal();
  }));
  studentCheckboxes.forEach(checkbox => checkbox.addEventListener('change', renderExamDates));
  teacherCheckboxes.forEach(checkbox => checkbox.addEventListener('change', updateTeacherSelectedCount));
  studentSearch.addEventListener('input', filterStudents);
})();
</script>
<?php require_once '../includes/footer.php'; ?>
