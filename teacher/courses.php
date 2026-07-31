<?php
require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/friendly_urls.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['teacher', 'admin'])) {
    header('Location: ../index.php');
    exit;
}

// Xử lý tạo khóa học mới
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_course') {
    verifyCsrfToken();
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $teacher_id = $_SESSION['user_id'];
    
    if (!empty($title)) {
        $stmt = $pdo->prepare("INSERT INTO courses (title, slug, description, teacher_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([$title, uniqueFriendlySlug($pdo, 'courses', $title), $description, $teacher_id]);
        $_SESSION['success'] = "Tạo khóa học thành công!";
        header("Location: courses.php");
        exit;
    } else {
        $error = "Vui lòng nhập tên khóa học.";
    }
}

// Cập nhật tên và mô tả khóa học
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_course_details') {
    verifyCsrfToken();
    $course_id = filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (!$course_id || $title === '') {
        $_SESSION['error'] = 'Tên khóa học không được để trống.';
    } else {
        if ($_SESSION['user_role'] === 'admin') {
            $stmt = $pdo->prepare("UPDATE courses SET title = ?, description = ? WHERE id = ?");
            $stmt->execute([$title, $description, $course_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE courses SET title = ?, description = ? WHERE id = ? AND teacher_id = ?");
            $stmt->execute([$title, $description, $course_id, $_SESSION['user_id']]);
        }
        $_SESSION[$stmt->rowCount() > 0 ? 'success' : 'error'] = $stmt->rowCount() > 0
            ? 'Đã cập nhật thông tin khóa học.'
            : 'Không thể cập nhật hoặc thông tin không có thay đổi.';
    }
    header('Location: courses.php');
    exit;
}

// Xử lý xóa khóa học
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_course') {
    verifyCsrfToken();
    $course_id = $_POST['course_id'];
    
    // Check permission
    if ($_SESSION['user_role'] !== 'admin') {
        $check = $pdo->prepare("SELECT id FROM courses WHERE id = ? AND teacher_id = ?");
        $check->execute([$course_id, $_SESSION['user_id']]);
        if (!$check->fetch()) {
            die("Không có quyền xóa khóa học này.");
        }
    }
    
    $stmt = $pdo->prepare("DELETE FROM courses WHERE id = ?");
    $stmt->execute([$course_id]);
    $_SESSION['success'] = "Đã xóa khóa học thành công.";
    header("Location: courses.php");
    exit;
}

// Fetch danh sách khóa học
if ($_SESSION['user_role'] === 'admin') {
    $stmt = $pdo->prepare("SELECT c.*, u.name as teacher_name, (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = c.id) AS student_count, (SELECT COUNT(*) FROM course_enrollment_requests er WHERE er.course_id = c.id AND er.status = 'pending') AS pending_request_count FROM courses c JOIN users u ON c.teacher_id = u.id ORDER BY c.created_at DESC");
    $stmt->execute();
} else {
    $stmt = $pdo->prepare("SELECT c.*, u.name as teacher_name, (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = c.id) AS student_count, (SELECT COUNT(*) FROM course_enrollment_requests er WHERE er.course_id = c.id AND er.status = 'pending') AS pending_request_count FROM courses c JOIN users u ON c.teacher_id = u.id WHERE c.teacher_id = ? ORDER BY c.created_at DESC");
    $stmt->execute([$_SESSION['user_id']]);
}
$courses = $stmt->fetchAll();

$page_title = "Quản lý Khóa học";
require_once '../includes/header.php';
?>

<style>
    .course-management-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:20px; margin-top:20px; }
    .course-management-card {
        position:relative;
        display:flex;
        flex-direction:column;
        min-width:0;
        min-height:310px;
        padding:24px;
        border-radius:16px;
        background:var(--glass-bg);
        border:1px solid rgba(255,255,255,.08);
        transition:transform .25s,border-color .25s,box-shadow .25s;
    }
    .course-management-card:hover { transform:translateY(-4px); border-color:var(--primary); box-shadow:0 16px 34px rgba(0,0,0,.16); }
    .course-management-icon { font-size:36px; color:var(--primary); margin-bottom:12px; }
    .course-management-title { margin:0 0 10px; padding-right:72px; color:var(--text-main); font-size:20px; line-height:1.3; overflow-wrap:anywhere; }
    .course-management-meta { display:flex; flex-direction:column; gap:7px; margin-bottom:13px; color:var(--text-muted); font-size:13px; }
    .course-management-meta .student-count { color:#7dd3fc; }
    .course-management-description {
        flex:1;
        margin:0 0 14px;
        color:var(--text-muted);
        font-size:14px;
        line-height:1.55;
        overflow-wrap:anywhere;
    }
    .course-request-slot { min-height:34px; display:flex; align-items:center; margin-top:4px; }
    .course-management-actions { margin-top:auto; padding-top:14px; }
    .course-management-actions .btn { width:100%; min-height:45px; text-align:center; justify-content:center; }
    @media (max-width:1000px) { .course-management-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    @media (max-width:650px) {
        .course-management-grid { grid-template-columns:1fr; }
        .course-management-card { min-height:0; }
    }
</style>

<div class="content">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2><i class='bx bx-book-open'></i> Quản lý Khóa học</h2>
        <button class="btn btn-primary" onclick="document.getElementById('createCourseModal').style.display='block'">
            <i class='bx bx-plus'></i> Tạo Khóa Học Mới
        </button>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <div class="course-management-grid">
        <?php foreach ($courses as $course): ?>
            <div class="course-management-card">
                <button type="button" class="edit-course-description" data-course-id="<?php echo (int) $course['id']; ?>" data-course-title="<?php echo htmlspecialchars($course['title'], ENT_QUOTES, 'UTF-8'); ?>" data-course-description="<?php echo htmlspecialchars($course['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" title="Sửa tên và mô tả" style="position:absolute;top:15px;right:55px;background:rgba(56,189,248,.1);border:1px solid rgba(56,189,248,.25);color:#38bdf8;width:32px;height:32px;border-radius:6px;display:flex;align-items:center;justify-content:center;cursor:pointer;">
                    <i class='bx bx-edit'></i>
                </button>
                <form method="POST" action="" style="position: absolute; top: 15px; right: 15px; margin: 0; z-index: 10;" onsubmit="return confirm('Bạn có chắc chắn muốn xóa khóa học này? Lưu ý: Mọi bài tập và bài nộp trong khóa này sẽ bị xóa toàn bộ!');">
                    <input type="hidden" name="action" value="delete_course">
                    <input type="hidden" name="course_id" value="<?php echo $course['id']; ?>">
                    <button type="submit" style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); color: var(--danger); width: 32px; height: 32px; border-radius: 6px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='var(--danger)'; this.style.color='#fff';" onmouseout="this.style.background='rgba(239, 68, 68, 0.1)'; this.style.color='var(--danger)';"><i class='bx bx-trash'></i></button>
                </form>
                <i class='bx bx-book-bookmark course-management-icon'></i>
                <h3 class="course-management-title"><?php echo htmlspecialchars($course['title']); ?></h3>
                <div class="course-management-meta">
                    <span><i class='bx bx-user'></i> Giảng viên: <?php echo htmlspecialchars($course['teacher_name']); ?></span>
                    <span class="student-count"><i class='bx bx-group'></i> Số học viên: <strong><?php echo (int) $course['student_count']; ?></strong></span>
                </div>
                <p class="course-management-description">
                    <?php
                    $description = trim($course['description'] ?? '');
                    echo $description === ''
                        ? '<span style="color:var(--text-muted);">Chưa có mô tả</span>'
                        : htmlspecialchars(mb_strlen($description) > 140 ? mb_substr($description, 0, 140) . '...' : $description);
                    ?>
                </p>
                <div class="course-request-slot">
                    <?php if ((int) $course['pending_request_count'] > 0): ?>
                        <div style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:20px;background:rgba(245,158,11,.16);color:#fbbf24;font-size:13px;font-weight:700;">
                            <i class='bx bx-time-five'></i> <?php echo (int) $course['pending_request_count']; ?> yêu cầu chờ duyệt
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="course-management-actions">
                    <a href="course_detail.php?id=<?php echo $course['id']; ?>" class="btn btn-primary">
                        <i class='bx bx-cog'></i> Quản lý khóa học
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($courses)): ?>
            <div class="box" style="grid-column: 1 / -1; text-align: center; padding: 40px;">
                <i class='bx bx-book-open' style="font-size: 48px; color: var(--text-muted); margin-bottom: 10px;"></i>
                <p>Chưa có khóa học nào.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Tạo Khóa học -->
<div id="createCourseModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 1000; align-items: center; justify-content: center;">
    <div class="box" style="width: 100%; max-width: 500px; margin: 10vh auto; position: relative;">
        <span onclick="document.getElementById('createCourseModal').style.display='none'" style="position: absolute; right: 20px; top: 20px; cursor: pointer; font-size: 24px;">&times;</span>
        <h3 style="margin-top: 0;">Tạo Khóa Học Mới</h3>
        <form action="" method="POST">
            <input type="hidden" name="action" value="create_course">
            <div class="form-group">
                <label>Tên Khóa Học *</label>
                <input type="text" name="title" required placeholder="Ví dụ: Lập trình Web K10">
            </div>
            <div class="form-group">
                <label>Mô tả (Tùy chọn)</label>
                <textarea name="description" rows="4"></textarea>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%;"><i class='bx bx-save'></i> Tạo khóa học</button>
        </form>
    </div>
</div>

<div id="editDescriptionModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:1000;align-items:center;justify-content:center;padding:20px;">
    <div class="box" style="width:100%;max-width:560px;position:relative;">
        <button type="button" id="closeDescriptionModal" aria-label="Đóng" style="position:absolute;right:18px;top:16px;border:0;background:transparent;color:#fff;font-size:28px;cursor:pointer;">&times;</button>
        <h3 style="margin-top:0;padding-right:35px;">Sửa thông tin khóa học</h3>
        <form method="POST">
            <input type="hidden" name="action" value="update_course_details">
            <input type="hidden" name="course_id" id="editDescriptionCourseId">
            <div class="form-group">
                <label for="editCourseTitle">Tên khóa học *</label>
                <input type="text" name="title" id="editCourseTitle" maxlength="255" required>
            </div>
            <div class="form-group">
                <label for="editCourseDescription">Mô tả khóa học</label>
                <textarea name="description" id="editCourseDescription" rows="7" maxlength="5000" placeholder="Nhập nội dung mô tả khóa học..."></textarea>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;"><i class='bx bx-save'></i> Lưu thay đổi</button>
        </form>
    </div>
</div>

<script>
    (() => {
        const modal = document.getElementById('editDescriptionModal');
        const courseIdInput = document.getElementById('editDescriptionCourseId');
        const descriptionInput = document.getElementById('editCourseDescription');
        const courseTitleInput = document.getElementById('editCourseTitle');
        const closeModal = () => modal.style.display = 'none';

        document.querySelectorAll('.edit-course-description').forEach(button => {
            button.addEventListener('click', () => {
                courseIdInput.value = button.dataset.courseId;
                courseTitleInput.value = button.dataset.courseTitle;
                descriptionInput.value = button.dataset.courseDescription;
                modal.style.display = 'flex';
                courseTitleInput.focus();
                courseTitleInput.select();
            });
        });
        document.getElementById('closeDescriptionModal').addEventListener('click', closeModal);
        modal.addEventListener('click', event => {
            if (event.target === modal) closeModal();
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') closeModal();
        });
    })();
</script>

<?php require_once '../includes/footer.php'; ?>
