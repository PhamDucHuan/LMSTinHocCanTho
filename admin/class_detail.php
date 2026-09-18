<?php
declare(strict_types=1);

require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/authorization.php';
require_once '../includes/audit.php';

// database.php may expose the connection using a different conventional name.
if (!isset($pdo)) {
  foreach (['db', 'conn', 'database'] as $connectionName) {
    if (isset($$connectionName) && $$connectionName instanceof PDO) {
      $pdo = $$connectionName;
      break;
    }
  }
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
  throw new RuntimeException('Không thể kết nối cơ sở dữ liệu.');
}

$role = (string) ($_SESSION['user_role'] ?? '');
$actorId = (int) ($_SESSION['user_id'] ?? 0);
if (!in_array($role, ['admin', 'teacher', 'administrative_staff'], true) || $actorId <= 0) {
    header('Location: ../index.php');
    exit;
}
$isAdmin = $role === 'admin';

function classDetailIds(mixed $value, array $allowed): array
{
    $ids = array_values(array_unique(array_map('intval', is_array($value) ? $value : [])));
    $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0 && isset($allowed[$id])));
    sort($ids);
    return $ids;
}

function classDetailExamDates(mixed $value, array $studentIds): array
{
    $allowed = array_fill_keys($studentIds, true);
    $dates = [];
    foreach (is_array($value) ? $value : [] as $studentId => $date) {
        $studentId = (int) $studentId;
        $date = trim((string) $date);
        if (isset($allowed[$studentId]) && ($date === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))) {
            $dates[$studentId] = $date !== '' ? $date : null;
        }
    }
    return $dates;
}

function classDetailCanEdit(array $class, int $actorId, bool $isAdmin): bool
{
    return $isAdmin || (int) ($class['primary_teacher_id'] ?? 0) === $actorId;
}

function classDetailSyncMembers(PDO $pdo, int $classId, int $courseId, ?int $primaryTeacherId, array $teacherIds, array $studentIds, array $examDates, int $actorId): void
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

function classDetailLoad(PDO $pdo, int $classId): ?array
{
    $stmt = $pdo->prepare("SELECT lc.*, c.title AS course_title, u.name AS primary_teacher_name
                           FROM learning_classes lc
                           JOIN courses c ON c.id=lc.course_id
                           LEFT JOIN users u ON u.id=lc.primary_teacher_id
                           WHERE lc.id=? LIMIT 1");
    $stmt->execute([$classId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$classId = (int) ($_POST['class_id'] ?? $_GET['id'] ?? 0);
$class = $classId > 0 ? classDetailLoad($pdo, $classId) : null;
if ($classId > 0 && !$class) {
    $_SESSION['error'] = 'Không tìm thấy lớp học.';
    header('Location: classes.php');
    exit;
}

$canEdit = $class ? classDetailCanEdit($class, $actorId, $isAdmin) : true;
$canView = true;
if ($class && !$canEdit) {
    $viewStmt = $pdo->prepare('SELECT 1 FROM learning_class_teachers WHERE learning_class_id=? AND teacher_id=? LIMIT 1');
    $viewStmt->execute([$classId, $actorId]);
    $canView = (bool) $viewStmt->fetchColumn();
}
if (!$canView) {
    $_SESSION['error'] = 'Bạn không có quyền xem lớp học này.';
    header('Location: classes.php');
    exit;
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
if ($class && !in_array((int) $class['course_id'], array_map('intval', array_column($courses, 'id')), true)) {
    $courses[] = ['id' => (int) $class['course_id'], 'title' => (string) $class['course_title']];
}
$courseMap = array_fill_keys(array_map('intval', array_column($courses, 'id')), true);

$selectedTeachers = [];
$selectedStudents = [];
$examDates = [];
if ($class) {
    $teacherStmt = $pdo->prepare('SELECT teacher_id FROM learning_class_teachers WHERE learning_class_id=?');
    $teacherStmt->execute([$classId]);
    $selectedTeachers = array_map('intval', $teacherStmt->fetchAll(PDO::FETCH_COLUMN));

    $studentStmt = $pdo->prepare("SELECT student_id, DATE_FORMAT(exam_date, '%Y-%m-%d') AS exam_date FROM learning_class_students WHERE learning_class_id=? ORDER BY student_id");
    $studentStmt->execute([$classId]);
    foreach ($studentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $studentId = (int) $row['student_id'];
        $selectedStudents[] = $studentId;
        $examDates[$studentId] = (string) ($row['exam_date'] ?? '');
    }
}

$form = [
    'class_name' => (string) ($class['class_name'] ?? ''),
    'course_id' => (int) ($class['course_id'] ?? 0),
    'primary_teacher_id' => (int) ($class['primary_teacher_id'] ?? ($isAdmin ? 0 : $actorId)),
    'notes' => (string) ($class['notes'] ?? ''),
];
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    try {
        if (!$canEdit) throw new RuntimeException('Giáo viên giám sát chỉ được xem lớp, không được chỉnh sửa.');

        $form['class_name'] = trim((string) ($_POST['class_name'] ?? ''));
        $form['course_id'] = (int) ($_POST['course_id'] ?? 0);
        $form['notes'] = trim((string) ($_POST['notes'] ?? ''));
        $form['primary_teacher_id'] = $isAdmin
            ? (int) ($_POST['primary_teacher_id'] ?? 0)
            : (int) ($class['primary_teacher_id'] ?? $actorId);
        $selectedTeachers = classDetailIds($_POST['teacher_ids'] ?? [], $teacherMap);
        $selectedStudents = classDetailIds($_POST['student_ids'] ?? [], $studentMap);
        $examDates = classDetailExamDates($_POST['exam_dates'] ?? [], $selectedStudents);

        if ($form['class_name'] === '') throw new RuntimeException('Vui lòng nhập tên lớp.');
        $canUseCourse = $form['course_id'] > 0
            && isset($courseMap[$form['course_id']])
            && authorizationUserCanManageCourse($pdo, $form['course_id'], $role, $actorId);
        if (!$canUseCourse && $class && (int) $class['course_id'] === $form['course_id']) $canUseCourse = true;
        if (!$canUseCourse) throw new RuntimeException('Khóa học không hợp lệ hoặc bạn không có quyền quản lý khóa học này.');
        if ($form['primary_teacher_id'] > 0 && !isset($teacherMap[$form['primary_teacher_id']])) {
            throw new RuntimeException('Giáo viên phụ trách chính không hợp lệ.');
        }

        $primaryTeacherId = $form['primary_teacher_id'] > 0 ? $form['primary_teacher_id'] : null;
        $pdo->beginTransaction();
        if ($class) {
            $update = $pdo->prepare('UPDATE learning_classes SET class_name=?, course_id=?, primary_teacher_id=?, notes=? WHERE id=?');
            $update->execute([mb_substr($form['class_name'], 0, 191, 'UTF-8'), $form['course_id'], $primaryTeacherId, $form['notes'] ?: null, $classId]);
            $auditAction = 'learning_class.updated';
            $successMessage = 'Đã cập nhật lớp học.';
        } else {
            $insert = $pdo->prepare('INSERT INTO learning_classes (class_name, course_id, primary_teacher_id, notes, created_by) VALUES (?, ?, ?, ?, ?)');
            $insert->execute([mb_substr($form['class_name'], 0, 191, 'UTF-8'), $form['course_id'], $primaryTeacherId, $form['notes'] ?: null, $actorId]);
            $classId = (int) $pdo->lastInsertId();
            $auditAction = 'learning_class.created';
            $successMessage = 'Đã tạo lớp học mới.';
        }
        classDetailSyncMembers($pdo, $classId, $form['course_id'], $primaryTeacherId, $selectedTeachers, $selectedStudents, $examDates, $actorId);
        $pdo->commit();
        writeAuditLog($pdo, $auditAction, 'learning_class', $classId, ['course_id' => $form['course_id'], 'students' => count($selectedStudents)]);
        $_SESSION['success'] = $successMessage;
        header('Location: class_detail.php?id=' . $classId);
        exit;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errorMessage = $error->getMessage();
    }
}

if (!$class && !$courses) {
    $_SESSION['error'] = 'Bạn cần có quyền quản lý ít nhất một khóa học trước khi tạo lớp.';
    header('Location: classes.php');
    exit;
}

$page_title = $class ? ($canEdit ? 'Quản lý lớp học' : 'Chi tiết lớp học') : 'Tạo lớp học';
require_once '../includes/header.php';
?>
<style>
.class-detail-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;flex-wrap:wrap;margin-bottom:18px}.class-detail-head h1{margin:8px 0 6px}.class-detail-head p{margin:0;color:var(--text-muted)}.back-link{display:inline-flex;align-items:center;gap:6px;color:var(--primary);text-decoration:none}.detail-status{display:inline-flex;align-items:center;gap:7px;padding:8px 12px;border-radius:999px;background:rgba(30,190,130,.13);color:#54dbaa;font-weight:700}.detail-status.archived{background:rgba(255,190,60,.13);color:#ffd166}.class-detail-form{display:grid;gap:16px}.detail-card{padding:20px;border:1px solid var(--border-color);border-radius:17px;background:var(--glass-bg)}.detail-card h2{display:flex;align-items:center;gap:8px;margin:0 0 6px;font-size:20px}.detail-card>p{margin:0 0 16px;color:var(--text-muted)}.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.detail-field{display:grid;gap:7px;font-weight:700}.detail-field input,.detail-field select,.detail-field textarea,.picker-search,.exam-date-row input{box-sizing:border-box;width:100%;padding:11px 13px;border:1px solid var(--border-color);border-radius:10px;background:var(--input-bg,#101c31);color:var(--text-main);font:inherit}.detail-field textarea{min-height:92px;resize:vertical}.detail-field :disabled,.picker-search:disabled{opacity:.72;cursor:not-allowed}.picker-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px}.picker-head strong{font-size:16px}.selected-count{color:var(--primary);font-size:13px;font-weight:700}.picker-list{max-height:320px;overflow:auto;display:grid;gap:6px;padding:8px;border:1px solid var(--border-color);border-radius:12px;background:rgba(0,0,0,.08)}.picker-option{display:grid;grid-template-columns:21px minmax(0,1fr);align-items:start;gap:10px;padding:9px 10px;border-radius:9px;cursor:pointer}.picker-option:hover{background:rgba(99,102,241,.1)}.picker-option[hidden],.picker-empty[hidden],.exam-date-row[hidden]{display:none}.picker-option input{width:18px;height:18px;margin:2px 0 0;accent-color:var(--primary)}.picker-option strong,.picker-option small{display:block;overflow-wrap:anywhere}.picker-option small{margin-top:2px;color:var(--text-muted)}.picker-empty{padding:18px;text-align:center;color:var(--text-muted)}.detail-help{display:block;margin-top:8px;color:var(--text-muted);font-size:12px}.exam-date-list{display:grid;gap:8px}.exam-date-row{display:grid;grid-template-columns:minmax(0,1fr) 180px;align-items:center;gap:12px;padding:10px 12px;border:1px solid var(--border-color);border-radius:10px}.detail-actions{position:sticky;bottom:12px;z-index:4;display:flex;justify-content:flex-end;gap:10px;padding:13px;border:1px solid var(--border-color);border-radius:14px;background:color-mix(in srgb,var(--sidebar-bg) 92%,transparent);backdrop-filter:blur(12px)}.readonly-note{padding:12px 14px;border:1px solid rgba(255,193,7,.35);border-radius:12px;color:#ffd166;background:rgba(255,193,7,.08)}@media(max-width:720px){.detail-grid,.exam-date-row{grid-template-columns:1fr}.detail-card{padding:16px}.detail-actions{position:static}}
</style>

<div class="class-detail-head">
  <div>
    <a class="back-link" href="classes.php"><i class='bx bx-arrow-back'></i> Danh sách lớp học</a>
    <h1><i class='bx bx-group'></i> <?php echo htmlspecialchars($page_title); ?></h1>
    <p><?php echo $class ? htmlspecialchars((string) $form['class_name']) : 'Phân học viên vào khóa học và thêm giáo viên giám sát.'; ?></p>
  </div>
  <?php if ($class): ?><span class="detail-status <?php echo $class['status'] === 'archived' ? 'archived' : ''; ?>"><i class='bx bx-<?php echo $class['status'] === 'archived' ? 'archive' : 'check-circle'; ?>'></i> <?php echo $class['status'] === 'archived' ? 'Đã lưu trữ' : 'Đang hoạt động'; ?></span><?php endif; ?>
</div>

<?php if (!empty($_SESSION['success'])): ?><div class="alert alert-success"><?php echo htmlspecialchars((string) $_SESSION['success']); unset($_SESSION['success']); ?></div><?php endif; ?>
<?php if ($errorMessage !== ''): ?><div class="alert alert-error"><?php echo htmlspecialchars($errorMessage); ?></div><?php endif; ?>
<?php if (!$canEdit): ?><div class="readonly-note"><i class='bx bx-show'></i> Bạn là giáo viên giám sát nên chỉ có quyền xem thông tin lớp.</div><?php endif; ?>

<form method="post" class="class-detail-form" id="class-detail-form">
  <?php echo csrfField(); ?>
  <input type="hidden" name="class_id" value="<?php echo $classId; ?>">

  <section class="detail-card">
    <h2><i class='bx bx-info-circle'></i> Thông tin lớp</h2>
    <p>Lớp học hoạt động độc lập với lịch dạy và dùng để cấp quyền học nội dung khóa học.</p>
    <div class="detail-grid">
      <label class="detail-field">Tên lớp
        <input name="class_name" maxlength="191" required value="<?php echo htmlspecialchars((string) $form['class_name']); ?>" placeholder="Ví dụ: Tin học văn phòng K26" <?php echo $canEdit ? '' : 'disabled'; ?>>
      </label>
      <label class="detail-field">Khóa học
        <select name="course_id" required <?php echo $canEdit ? '' : 'disabled'; ?>>
          <option value="">— Chọn khóa học —</option>
          <?php foreach ($courses as $course): ?><option value="<?php echo (int) $course['id']; ?>" <?php echo (int) $form['course_id'] === (int) $course['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $course['title']); ?></option><?php endforeach; ?>
        </select>
      </label>
    </div>
    <?php if ($isAdmin): ?>
      <label class="detail-field" style="margin-top:14px">Giáo viên phụ trách chính
        <select name="primary_teacher_id" <?php echo $canEdit ? '' : 'disabled'; ?>><option value="">— Chưa phân công —</option><?php foreach ($teachers as $teacher): ?><option value="<?php echo (int) $teacher['id']; ?>" <?php echo (int) $form['primary_teacher_id'] === (int) $teacher['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($teacher['name'] . ' — ' . $teacher['email']); ?></option><?php endforeach; ?></select>
      </label>
    <?php endif; ?>
    <label class="detail-field" style="margin-top:14px">Ghi chú
      <textarea name="notes" placeholder="Thông tin nội bộ về lớp" <?php echo $canEdit ? '' : 'disabled'; ?>><?php echo htmlspecialchars((string) $form['notes']); ?></textarea>
    </label>
  </section>

  <section class="detail-card">
    <h2><i class='bx bx-chalkboard'></i> Giáo viên giám sát</h2>
    <p>Giáo viên được chọn có thể xem lớp và tiến độ; không được sửa lớp hoặc nội dung khóa học.</p>
    <div class="picker-head"><strong>Chọn giáo viên</strong><span class="selected-count" id="teacher-count"></span></div>
    <input class="picker-search" type="search" id="teacher-search" placeholder="Tìm theo tên hoặc email giáo viên" autocomplete="off">
    <div class="picker-list" id="teacher-list" style="margin-top:8px">
      <?php foreach ($teachers as $teacher): $teacherId = (int) $teacher['id']; ?>
        <label class="picker-option" data-search="<?php echo htmlspecialchars($teacher['name'] . ' ' . $teacher['email'], ENT_QUOTES, 'UTF-8'); ?>">
          <input type="checkbox" name="teacher_ids[]" value="<?php echo $teacherId; ?>" <?php echo in_array($teacherId, $selectedTeachers, true) ? 'checked' : ''; ?> <?php echo $canEdit ? '' : 'disabled'; ?>>
          <span><strong><?php echo htmlspecialchars($teacher['name']); ?></strong><small><?php echo htmlspecialchars($teacher['email']); ?></small></span>
        </label>
      <?php endforeach; ?>
      <div class="picker-empty" hidden>Không tìm thấy giáo viên phù hợp.</div>
    </div>
  </section>

  <section class="detail-card">
    <h2><i class='bx bx-user-plus'></i> Học viên của lớp</h2>
    <p>Học viên được chọn sẽ làm bài tập và trắc nghiệm của khóa học khi lớp đang hoạt động.</p>
    <div class="picker-head"><strong>Chọn học viên</strong><span class="selected-count" id="student-count"></span></div>
    <input class="picker-search" type="search" id="student-search" placeholder="Tìm theo tên hoặc email học viên" autocomplete="off">
    <div class="picker-list" id="student-list" style="margin-top:8px">
      <?php foreach ($students as $student): $studentId = (int) $student['id']; ?>
        <label class="picker-option" data-search="<?php echo htmlspecialchars($student['name'] . ' ' . $student['email'], ENT_QUOTES, 'UTF-8'); ?>">
          <input type="checkbox" name="student_ids[]" value="<?php echo $studentId; ?>" data-student-id="<?php echo $studentId; ?>" <?php echo in_array($studentId, $selectedStudents, true) ? 'checked' : ''; ?> <?php echo $canEdit ? '' : 'disabled'; ?>>
          <span><strong><?php echo htmlspecialchars($student['name']); ?></strong><small><?php echo htmlspecialchars($student['email']); ?></small></span>
        </label>
      <?php endforeach; ?>
      <div class="picker-empty" hidden>Không tìm thấy học viên phù hợp.</div>
    </div>
  </section>

  <section class="detail-card">
    <h2><i class='bx bx-calendar-check'></i> Ngày thi riêng của học viên</h2>
    <p>Chỉ học viên đã tích chọn mới xuất hiện ở đây. Ngày thi cũng được dùng trong trang Tiến độ học viên.</p>
    <div class="exam-date-list" id="exam-date-list">
      <?php foreach ($students as $student): $studentId = (int) $student['id']; $isSelected = in_array($studentId, $selectedStudents, true); ?>
        <label class="exam-date-row" data-student-date="<?php echo $studentId; ?>" <?php echo $isSelected ? '' : 'hidden'; ?>>
          <span><strong><?php echo htmlspecialchars($student['name']); ?></strong><small style="display:block;color:var(--text-muted)"><?php echo htmlspecialchars($student['email']); ?></small></span>
          <input type="date" name="exam_dates[<?php echo $studentId; ?>]" value="<?php echo htmlspecialchars((string) ($examDates[$studentId] ?? '')); ?>" <?php echo $isSelected ? '' : 'disabled'; ?> <?php echo $canEdit ? '' : 'disabled'; ?>>
        </label>
      <?php endforeach; ?>
      <div class="picker-empty" id="exam-empty" <?php echo $selectedStudents ? 'hidden' : ''; ?>>Chưa chọn học viên.</div>
    </div>
  </section>

  <div class="detail-actions">
    <a class="btn btn-outline" href="classes.php"><i class='bx bx-arrow-back'></i> <?php echo $canEdit ? 'Hủy' : 'Quay lại'; ?></a>
    <?php if ($canEdit): ?><button class="btn btn-primary" type="submit"><i class='bx bx-save'></i> <?php echo $class ? 'Lưu thay đổi' : 'Tạo lớp học'; ?></button><?php endif; ?>
  </div>
</form>

<script>
(() => {
  const normalize = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').toLowerCase();
  const setupPicker = (listId, searchId, countId) => {
    const list = document.getElementById(listId);
    const search = document.getElementById(searchId);
    const count = document.getElementById(countId);
    const options = [...list.querySelectorAll('.picker-option')];
    const inputs = options.map(option => option.querySelector('input'));
    const update = () => { count.textContent = `Đã chọn ${inputs.filter(input => input.checked).length}`; };
    const filter = () => {
      const keyword = normalize(search.value.trim());
      let visible = 0;
      options.forEach(option => {
        const show = !keyword || normalize(option.dataset.search).includes(keyword);
        option.hidden = !show;
        if (show) visible++;
      });
      list.querySelector('.picker-empty').hidden = visible > 0;
    };
    inputs.forEach(input => input.addEventListener('change', update));
    search.addEventListener('input', filter);
    update();
    return inputs;
  };

  setupPicker('teacher-list', 'teacher-search', 'teacher-count');
  const studentInputs = setupPicker('student-list', 'student-search', 'student-count');
  const examEmpty = document.getElementById('exam-empty');
  const updateExamDates = () => {
    let selected = 0;
    studentInputs.forEach(input => {
      const row = document.querySelector(`[data-student-date="${input.dataset.studentId}"]`);
      const dateInput = row.querySelector('input[type="date"]');
      row.hidden = !input.checked;
      dateInput.disabled = !input.checked || <?php echo $canEdit ? 'false' : 'true'; ?>;
      if (input.checked) selected++;
    });
    examEmpty.hidden = selected > 0;
  };
  studentInputs.forEach(input => input.addEventListener('change', updateExamDates));
  updateExamDates();
})();
</script>
<?php require_once '../includes/footer.php'; ?>
