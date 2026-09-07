<?php
require_once '../includes/security.php';
require_once '../includes/authorization.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/drive_helper.php';
/** @var PDO $pdo */

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['teacher', 'administrative_staff', 'admin'], true)) {
    header('Location: ../index.php');
    exit;
}

$course_id = $_GET['id'] ?? 0;

$ownedCourse = authorizationFindManageableCourse($pdo, (int) $course_id, (string) $_SESSION['user_role'], (int) $_SESSION['user_id']);
if (!$ownedCourse) {
    http_response_code(404); exit('Khóa học không tồn tại hoặc bạn không có quyền truy cập.');
}

// Fetch thông tin khóa học
$course = $ownedCourse;

if (!$course) {
    die("Khóa học không tồn tại.");
}

$youtubeVideoId = static function (string $url): ?string {
    $parts = parse_url(trim($url));
    if (!is_array($parts) || empty($parts['host'])) return null;
    $host = strtolower(preg_replace('/^www\./', '', (string) $parts['host']));
    $pathParts = array_values(array_filter(explode('/', trim((string) ($parts['path'] ?? ''), '/'))));
    $videoId = '';
    if ($host === 'youtu.be') {
        $videoId = (string) ($pathParts[0] ?? '');
    } elseif (in_array($host, ['youtube.com', 'm.youtube.com', 'youtube-nocookie.com'], true)) {
        if (($pathParts[0] ?? '') === 'watch') {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $videoId = (string) ($query['v'] ?? '');
        } elseif (in_array((string) ($pathParts[0] ?? ''), ['embed', 'shorts', 'live'], true)) {
            $videoId = (string) ($pathParts[1] ?? '');
        }
    }
    return preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) ? $videoId : null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $action = (string) ($_POST['action'] ?? '');
    $newlyUploadedMaterialDriveId = null;
    try {
        if ($action === 'add_course_material') {
            $title = trim((string) ($_POST['material_title'] ?? ''));
            $type = (string) ($_POST['material_type'] ?? '');
            if ($title === '' || !in_array($type, ['pdf', 'video'], true)) throw new RuntimeException('Vui lòng nhập tên và chọn loại học liệu.');
            $fileDriveId = null;
            $fileName = null;
            $videoId = null;
            if ($type === 'pdf') {
                $file = $_FILES['material_pdf'] ?? [];
                $valid = validateUploadedFile(is_array($file) ? $file : [], ['pdf']);
                $fileName = $valid['original_name'];
                $safeDriveName = preg_replace('/[^\pL\pN._ -]+/u', '_', $fileName) ?: 'giao-trinh.pdf';
                $storedName = date('Ymd_His') . '_' . $safeDriveName;
                try {
                    $fileDriveId = uploadToDrive($valid['tmp_name'], $storedName, ['LMS_Uploads', 'Course_' . (int) $course_id, 'Materials']);
                    if (!is_string($fileDriveId) || $fileDriveId === '' || str_starts_with($fileDriveId, 'local_')) {
                        throw new RuntimeException('Google Drive không trả về mã file hợp lệ.');
                    }
                    $newlyUploadedMaterialDriveId = $fileDriveId;
                } catch (Throwable $uploadError) {
                    error_log('Course material Google Drive upload failed: ' . $uploadError->getMessage());
                    throw new RuntimeException('Không thể tải giáo trình lên Google Drive. Vui lòng kiểm tra kết nối Drive rồi thử lại.');
                }
            } else {
                $videoId = $youtubeVideoId((string) ($_POST['youtube_url'] ?? ''));
                if ($videoId === null) throw new RuntimeException('Liên kết YouTube không hợp lệ. Hãy dùng link video YouTube hoặc link youtu.be.');
            }
            $sortStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM course_materials WHERE course_id=?');
            $sortStmt->execute([$course_id]);
            $sortOrder = (int) $sortStmt->fetchColumn();
            $insert = $pdo->prepare('INSERT INTO course_materials (course_id, title, material_type, file_drive_id, file_name, youtube_video_id, sort_order, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $insert->execute([(int) $course_id, mb_substr($title, 0, 191, 'UTF-8'), $type, $fileDriveId, $fileName, $videoId, $sortOrder, (int) $_SESSION['user_id']]);
            $newlyUploadedMaterialDriveId = null;
            $_SESSION['course_material_notice'] = [
                'level' => 'success',
                'title' => $type === 'pdf' ? 'Đã tải giáo trình lên Google Drive' : 'Đã thêm video hướng dẫn',
                'message' => 'Học viên trong các lớp của khóa học này đã có thể xem “' . $title . '”.',
            ];
        } elseif ($action === 'update_course_material') {
            $materialId = filter_input(INPUT_POST, 'material_id', FILTER_VALIDATE_INT);
            $title = trim((string) ($_POST['material_title'] ?? ''));
            if (!$materialId || $title === '') throw new RuntimeException('Vui lòng nhập tên học liệu.');
            $materialStmt = $pdo->prepare('SELECT id, material_type FROM course_materials WHERE id=? AND course_id=?');
            $materialStmt->execute([$materialId, $course_id]);
            $material = $materialStmt->fetch(PDO::FETCH_ASSOC);
            if (!$material) throw new RuntimeException('Không tìm thấy học liệu cần cập nhật.');
            $title = mb_substr($title, 0, 191, 'UTF-8');
            if ($material['material_type'] === 'video') {
                $videoId = $youtubeVideoId((string) ($_POST['youtube_url'] ?? ''));
                if ($videoId === null) throw new RuntimeException('Liên kết YouTube không hợp lệ. Hãy dùng link video YouTube hoặc link youtu.be.');
                $pdo->prepare('UPDATE course_materials SET title=?, youtube_video_id=? WHERE id=? AND course_id=?')->execute([$title, $videoId, $materialId, $course_id]);
            } else {
                $pdo->prepare('UPDATE course_materials SET title=? WHERE id=? AND course_id=?')->execute([$title, $materialId, $course_id]);
            }
            $_SESSION['course_material_notice'] = ['level' => 'success', 'title' => 'Đã cập nhật học liệu', 'message' => 'Các thay đổi của “' . $title . '” đã được lưu và hiển thị cho học viên.'];
        } elseif ($action === 'delete_course_material') {
            $materialId = filter_input(INPUT_POST, 'material_id', FILTER_VALIDATE_INT);
            $materialStmt = $pdo->prepare('SELECT id, title, material_type, file_drive_id FROM course_materials WHERE id=? AND course_id=?');
            $materialStmt->execute([$materialId, $course_id]);
            $material = $materialStmt->fetch(PDO::FETCH_ASSOC);
            if (!$material) throw new RuntimeException('Không tìm thấy học liệu cần xóa.');
            $pdo->prepare('DELETE FROM course_materials WHERE id=? AND course_id=?')->execute([$materialId, $course_id]);
            if ($material['material_type'] === 'pdf' && !empty($material['file_drive_id'])) deleteFromDrive((string) $material['file_drive_id']);
            $_SESSION['course_material_notice'] = ['level' => 'success', 'title' => 'Đã xóa học liệu', 'message' => '“' . (string) $material['title'] . '” đã được gỡ khỏi khóa học và học viên không còn thấy tài liệu này.'];
        }
    } catch (Throwable $error) {
        if (is_string($newlyUploadedMaterialDriveId) && $newlyUploadedMaterialDriveId !== '') {
            deleteFromDrive($newlyUploadedMaterialDriveId);
        }
        if (in_array($action, ['add_course_material', 'update_course_material', 'delete_course_material'], true)) {
            $_SESSION['course_material_notice'] = ['level' => 'error', 'title' => 'Chưa thể cập nhật học liệu', 'message' => $error->getMessage()];
        } else {
            $_SESSION['error'] = $error->getMessage();
        }
    }
    header('Location: course_detail.php?id=' . (int) $course_id);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT lc.id, lc.class_name, lc.status,
            (SELECT COUNT(*) FROM learning_class_students lcs WHERE lcs.learning_class_id=lc.id) student_count,
            COALESCE((SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ') FROM learning_class_teachers lct JOIN users t ON t.id=lct.teacher_id WHERE lct.learning_class_id=lc.id), primary_teacher.name) teacher_names
     FROM learning_classes lc
     LEFT JOIN users primary_teacher ON primary_teacher.id=lc.primary_teacher_id
     WHERE lc.course_id=?
     ORDER BY FIELD(lc.status, 'active', 'archived'), lc.class_name"
);
$stmt->execute([$course_id]);
$courseClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$materialStmt = $pdo->prepare('SELECT id, title, material_type, file_name, youtube_video_id, created_at FROM course_materials WHERE course_id=? ORDER BY sort_order, id');
$materialStmt->execute([$course_id]);
$courseMaterials = $materialStmt->fetchAll(PDO::FETCH_ASSOC);
$courseMaterialNotice = $_SESSION['course_material_notice'] ?? null;
unset($_SESSION['course_material_notice']);

$stmt = $pdo->prepare("
    SELECT a.id, a.title, a.type, a.due_date, a.created_at, a.priority_order,
           COUNT(s.id) AS submission_count, MAX(s.submitted_at) AS latest_submission_at
    FROM assignments a
    LEFT JOIN submissions s ON s.assignment_id = a.id
    WHERE a.course_id = ?
    GROUP BY a.id, a.title, a.type, a.due_date, a.created_at, a.priority_order
    ORDER BY a.priority_order, a.created_at, a.id
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

<style>
    .course-detail-hero{position:relative;overflow:hidden;display:flex;justify-content:space-between;align-items:flex-end;gap:22px;flex-wrap:wrap;margin-bottom:22px;padding:28px 30px;border:1px solid rgba(125,211,252,.18);border-radius:20px;background:linear-gradient(135deg,rgba(14,116,144,.2),rgba(15,23,42,.58));box-shadow:0 18px 42px rgba(0,0,0,.14)}
    .course-detail-hero::after{content:"";position:absolute;width:260px;height:260px;right:-90px;top:-150px;border-radius:50%;background:radial-gradient(circle,rgba(56,189,248,.22),transparent 68%);pointer-events:none}
    .course-detail-hero>*{position:relative;z-index:1}.course-detail-back{display:inline-flex;align-items:center;gap:7px;color:var(--primary);text-decoration:none;margin-bottom:12px}.course-detail-hero h2{margin:0;font-size:clamp(25px,3vw,34px);max-width:850px}.course-detail-hero p{margin:9px 0 0;color:var(--text-muted)}
    .course-materials-panel{margin-bottom:22px;padding:0;overflow:hidden;border:1px solid rgba(125,211,252,.18);border-radius:20px;background:linear-gradient(145deg,rgba(15,52,82,.88),rgba(8,29,51,.86));box-shadow:0 18px 40px rgba(0,0,0,.13)}
    .course-materials-head{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;padding:22px 24px;border-bottom:1px solid rgba(255,255,255,.09)}.course-materials-head h3{margin:0;font-size:21px}.course-materials-head p{margin:5px 0 0;color:var(--text-muted);font-size:13px}.material-count{display:inline-flex;align-items:center;gap:7px;padding:7px 11px;border-radius:999px;color:#a5e5ff;background:rgba(56,189,248,.11);font-size:13px;font-weight:700}
    .course-material-notice{display:flex;align-items:flex-start;gap:12px;margin:-4px 0 18px;padding:14px 16px;border:1px solid;border-radius:14px;box-shadow:0 12px 25px rgba(0,0,0,.14)}.course-material-notice.success{color:#9af5cc;background:rgba(16,185,129,.12);border-color:rgba(52,211,153,.34)}.course-material-notice.error{color:#ffb2bf;background:rgba(244,63,94,.12);border-color:rgba(251,113,133,.34)}.course-material-notice i{font-size:23px}.course-material-notice strong{display:block;margin-bottom:2px}.course-material-notice p{margin:0;color:var(--text-main);font-size:13px;line-height:1.5}.course-material-notice button{margin-left:auto;padding:0;border:0;background:none;color:inherit;font-size:21px;cursor:pointer}
    .course-materials-workspace{display:grid;grid-template-columns:minmax(280px,360px) minmax(0,1fr);gap:0}.course-material-form-card{padding:24px;border-right:1px solid rgba(255,255,255,.09);background:rgba(2,14,31,.18)}.course-material-form-card h4{margin:0 0 5px;font-size:17px}.course-material-form-card>p{margin:0 0 17px;color:var(--text-muted);font-size:13px;line-height:1.55}
    .course-material-form{display:grid;gap:13px}.course-material-form label{display:grid;gap:7px;color:var(--text-main);font-size:13px;font-weight:700}.course-material-form input,.course-material-form select{width:100%;box-sizing:border-box;min-height:43px;padding:10px 12px;border:1px solid rgba(148,163,184,.26);border-radius:10px;background:rgba(2,15,28,.58);color:var(--text-main);font:inherit}.course-material-form input[type=file]{padding:8px}.course-material-form .btn{margin-top:3px;justify-content:center}
    .course-material-library{padding:24px}.course-material-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(245px,1fr));gap:13px}.course-material-item{display:flex;flex-direction:column;gap:13px;min-width:0;padding:16px;border:1px solid rgba(148,163,184,.18);border-radius:14px;background:rgba(2,15,28,.28)}.course-material-item-top{display:flex;align-items:flex-start;gap:11px;min-width:0}.course-material-icon{display:grid;place-items:center;flex:0 0 42px;width:42px;height:42px;border-radius:12px;font-size:22px}.course-material-icon.pdf{color:#ff9b9b;background:rgba(248,113,113,.14)}.course-material-icon.video{color:#ff8799;background:rgba(255,0,51,.13)}.course-material-item h4{margin:0;font-size:16px;overflow-wrap:anywhere}.course-material-item small{display:block;margin-top:4px;color:var(--text-muted);overflow-wrap:anywhere}.material-kind{display:inline-flex;margin-top:8px;padding:4px 8px;border-radius:999px;background:rgba(148,163,184,.13);color:var(--text-muted);font-size:11px;font-weight:700}.course-material-footer{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:auto;color:var(--text-muted);font-size:12px}.material-actions{display:flex;gap:7px;flex-wrap:wrap}.material-actions .btn{padding:7px 10px;min-height:0}.material-actions .material-delete{color:#ff6683;border-color:rgba(255,102,131,.75)}.material-empty{display:grid;place-items:center;min-height:178px;padding:24px;text-align:center;color:var(--text-muted);border:1px dashed rgba(148,163,184,.25);border-radius:14px}
    .material-edit-dialog{width:min(500px,calc(100% - 28px));padding:0;border:1px solid rgba(148,163,184,.28);border-radius:18px;background:#0c2038;color:var(--text-main);box-shadow:0 30px 80px rgba(0,0,0,.5)}.material-edit-dialog::backdrop{background:rgba(2,8,23,.72)}.material-edit-form{display:grid;gap:15px;padding:24px}.material-edit-form h3{margin:0}.material-edit-form p{margin:0;color:var(--text-muted);font-size:13px;line-height:1.5}.material-edit-form label{display:grid;gap:7px;font-size:13px;font-weight:700}.material-edit-form input{min-height:43px;padding:10px 12px;border:1px solid rgba(148,163,184,.28);border-radius:10px;background:rgba(2,15,28,.65);color:var(--text-main);font:inherit}.material-edit-actions{display:flex;justify-content:flex-end;gap:9px;flex-wrap:wrap;margin-top:3px}
    @media(max-width:850px){.course-materials-workspace{grid-template-columns:1fr}.course-material-form-card{border-right:0;border-bottom:1px solid rgba(255,255,255,.09)}.course-material-form{grid-template-columns:1fr 1fr}.course-material-form .btn{grid-column:1 / -1}}
    @media(max-width:560px){.course-detail-hero{padding:22px}.course-materials-head,.course-material-form-card,.course-material-library{padding:18px}.course-material-form{grid-template-columns:1fr}.course-material-footer{align-items:flex-start;flex-direction:column}}
</style>

<div class="content">
    <div class="course-detail-hero">
        <div>
            <a href="courses.php" class="course-detail-back"><i class='bx bx-arrow-back'></i> Quay lại Khóa học</a>
            <h2><i class='bx bx-book-open'></i> <?php echo htmlspecialchars($course['title']); ?></h2>
            <p>Quản lý học liệu, lớp học, bài tập và trắc nghiệm trong cùng một nơi.</p>
        </div>
        <a href="quizzes.php?course_id=<?php echo (int) $course_id; ?>" class="btn btn-primary"><i class='bx bx-list-check'></i> Quản lý trắc nghiệm</a>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
    <?php endif; ?>
    <?php if (is_array($courseMaterialNotice)): ?>
        <?php $noticeLevel = ($courseMaterialNotice['level'] ?? '') === 'error' ? 'error' : 'success'; ?>
        <div class="course-material-notice <?php echo $noticeLevel; ?>" role="status">
            <i class='bx <?php echo $noticeLevel === 'success' ? 'bx-check-circle' : 'bx-error-circle'; ?>'></i>
            <div><strong><?php echo htmlspecialchars((string) ($courseMaterialNotice['title'] ?? 'Thông báo học liệu')); ?></strong><p><?php echo htmlspecialchars((string) ($courseMaterialNotice['message'] ?? '')); ?></p></div>
            <button type="button" aria-label="Đóng thông báo" data-close-material-notice>&times;</button>
        </div>
    <?php endif; ?>

    <section class="course-materials-panel">
        <header class="course-materials-head">
            <div>
                <h3><i class='bx bx-book-content'></i> Học liệu khóa học</h3>
                <p>Giáo trình PDF và video hướng dẫn được hiển thị trực tiếp cho học viên.</p>
            </div>
            <span class="material-count"><i class='bx bx-collection'></i> <?php echo count($courseMaterials); ?> học liệu</span>
        </header>
        <div class="course-materials-workspace">
            <aside class="course-material-form-card">
                <h4><i class='bx bx-plus-circle'></i> Thêm học liệu</h4>
                <p>Giáo trình PDF tối đa 20 MB sẽ được lưu trực tiếp lên Google Drive. Video dùng liên kết YouTube.</p>
                <form method="post" enctype="multipart/form-data" class="course-material-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="add_course_material">
                    <label>Tên học liệu<input name="material_title" maxlength="191" required placeholder="Ví dụ: Giáo trình buổi 1"></label>
                    <label>Loại học liệu<select name="material_type" id="material-type-new"><option value="pdf">PDF giáo trình</option><option value="video">Video YouTube</option></select></label>
                    <label id="material-pdf-field-new">File PDF<input type="file" name="material_pdf" accept="application/pdf,.pdf" required></label>
                    <label id="material-video-field-new" style="display:none;">Liên kết YouTube<input type="url" name="youtube_url" placeholder="https://www.youtube.com/watch?v=..."></label>
                    <button class="btn btn-primary" type="submit"><i class='bx bx-plus'></i> Thêm học liệu</button>
                </form>
            </aside>
            <div class="course-material-library">
                <?php if ($courseMaterials): ?>
                    <div class="course-material-list">
                    <?php foreach ($courseMaterials as $material): ?>
                        <?php $isPdf = $material['material_type'] === 'pdf'; ?>
                        <article class="course-material-item">
                            <div class="course-material-item-top">
                                <span class="course-material-icon <?php echo $isPdf ? 'pdf' : 'video'; ?>"><i class='bx <?php echo $isPdf ? 'bx-file' : 'bxl-youtube'; ?>'></i></span>
                                <div>
                                    <h4><?php echo htmlspecialchars((string) $material['title']); ?></h4>
                                    <?php if ($isPdf && !empty($material['file_name'])): ?><small><?php echo htmlspecialchars((string) $material['file_name']); ?></small><?php endif; ?>
                                    <span class="material-kind"><?php echo $isPdf ? 'PDF · Google Drive' : 'Video YouTube'; ?></span>
                                </div>
                            </div>
                            <div class="course-material-footer">
                                <span><?php echo date('d/m/Y H:i', strtotime((string) $material['created_at'])); ?></span>
                                <div class="material-actions">
                                    <?php if ($isPdf): ?>
                                        <a class="btn btn-outline" target="_blank" rel="noopener" href="../download.php?kind=course_material&amp;id=<?php echo (int) $material['id']; ?>&amp;preview=1"><i class='bx bx-show'></i> Xem PDF</a>
                                    <?php else: ?>
                                        <a class="btn btn-outline" target="_blank" rel="noopener" href="https://www.youtube.com/watch?v=<?php echo rawurlencode((string) $material['youtube_video_id']); ?>"><i class='bx bx-play'></i> Mở video</a>
                                    <?php endif; ?>
                                    <button class="btn btn-outline" type="button" data-edit-material data-id="<?php echo (int) $material['id']; ?>" data-title="<?php echo htmlspecialchars((string) $material['title'], ENT_QUOTES, 'UTF-8'); ?>" data-type="<?php echo $isPdf ? 'pdf' : 'video'; ?>" data-video="<?php echo htmlspecialchars((string) ($material['youtube_video_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><i class='bx bx-edit'></i> Sửa</button>
                                    <form method="post" data-delete-material data-title="<?php echo htmlspecialchars((string) $material['title'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete_course_material">
                                        <input type="hidden" name="material_id" value="<?php echo (int) $material['id']; ?>">
                                        <button class="btn btn-outline material-delete" type="submit" aria-label="Xóa học liệu"><i class='bx bx-trash'></i></button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="material-empty"><div><i class='bx bx-folder-open' style="font-size:34px;"></i><p>Chưa có học liệu. Hãy thêm giáo trình PDF hoặc video đầu tiên cho khóa học.</p></div></div>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <dialog class="material-edit-dialog" id="material-edit-dialog">
        <form method="post" class="material-edit-form">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_course_material">
            <input type="hidden" name="material_id" id="edit-material-id">
            <h3><i class='bx bx-edit'></i> Sửa học liệu</h3>
            <p id="edit-material-help">Cập nhật tên học liệu và lưu thay đổi.</p>
            <label>Tên học liệu<input name="material_title" id="edit-material-title" maxlength="191" required></label>
            <label id="edit-material-video-field" style="display:none;">Liên kết YouTube<input type="url" name="youtube_url" id="edit-material-video" placeholder="https://www.youtube.com/watch?v=..."></label>
            <div class="material-edit-actions"><button class="btn btn-outline" type="button" id="close-material-edit">Hủy</button><button class="btn btn-primary" type="submit"><i class='bx bx-save'></i> Lưu thay đổi</button></div>
        </form>
    </dialog>
    <script>
    (() => {
        const type = document.getElementById('material-type-new');
        const pdf = document.getElementById('material-pdf-field-new');
        const video = document.getElementById('material-video-field-new');
        const sync = () => {
            const isPdf = type.value === 'pdf';
            pdf.style.display = isPdf ? 'grid' : 'none';
            video.style.display = isPdf ? 'none' : 'grid';
            pdf.querySelector('input').required = isPdf;
            video.querySelector('input').required = !isPdf;
        };
        type.addEventListener('change', sync); sync();

        const dialog = document.getElementById('material-edit-dialog');
        const editId = document.getElementById('edit-material-id');
        const editTitle = document.getElementById('edit-material-title');
        const editVideoField = document.getElementById('edit-material-video-field');
        const editVideo = document.getElementById('edit-material-video');
        const editHelp = document.getElementById('edit-material-help');
        document.querySelectorAll('[data-edit-material]').forEach(button => button.addEventListener('click', () => {
            const isVideo = button.dataset.type === 'video';
            editId.value = button.dataset.id || '';
            editTitle.value = button.dataset.title || '';
            editVideoField.style.display = isVideo ? 'grid' : 'none';
            editVideo.required = isVideo;
            editVideo.value = isVideo && button.dataset.video ? `https://www.youtube.com/watch?v=${button.dataset.video}` : '';
            editHelp.textContent = isVideo
                ? 'Bạn có thể đổi tên hoặc thay liên kết video YouTube.'
                : 'Bạn có thể đổi tên giáo trình. Để thay file PDF, hãy xóa tài liệu cũ và tải file mới lên.';
            dialog.showModal();
        }));
        document.getElementById('close-material-edit').addEventListener('click', () => dialog.close());
        dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
        document.querySelectorAll('[data-delete-material]').forEach(form => form.addEventListener('submit', event => {
            const title = form.dataset.title || 'học liệu này';
            if (!confirm(`Xóa “${title}”? Học viên sẽ không còn xem được học liệu này.`)) event.preventDefault();
        }));
        const notice = document.querySelector('.course-material-notice');
        const closeNotice = document.querySelector('[data-close-material-notice]');
        if (closeNotice) closeNotice.addEventListener('click', () => notice.remove());
        if (notice && notice.classList.contains('success')) setTimeout(() => notice.remove(), 7000);
    })();
    </script>

    <div class="box" style="margin-bottom:20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <h3 style="margin:0;"><i class='bx bx-chalkboard'></i> Lớp sử dụng khóa học này (<?php echo count($courseClasses); ?>)</h3>
            <a class="btn btn-outline" href="../admin/classes.php"><i class='bx bx-group'></i> Mở quản lý lớp</a>
        </div>
        <div style="overflow-x:auto;margin-top:14px;">
            <table class="table"><thead><tr><th>Tên lớp</th><th>Giáo viên</th><th>Học viên</th><th>Trạng thái</th></tr></thead><tbody>
            <?php foreach ($courseClasses as $learningClass): ?><tr><td><strong><?php echo htmlspecialchars($learningClass['class_name']); ?></strong></td><td><?php echo htmlspecialchars($learningClass['teacher_names'] ?: 'Chưa phân công'); ?></td><td><?php echo (int) $learningClass['student_count']; ?></td><td><?php echo $learningClass['status'] === 'active' ? 'Đang học' : 'Đã lưu trữ'; ?></td></tr><?php endforeach; ?>
            <?php if (!$courseClasses): ?><tr><td colspan="4" style="text-align:center;color:var(--text-muted);">Khóa học chưa được phân cho lớp nào.</td></tr><?php endif; ?>
            </tbody></table>
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
