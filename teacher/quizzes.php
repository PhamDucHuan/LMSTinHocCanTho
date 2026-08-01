<?php
require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/quiz_schema.php';
require_once '../includes/quiz_import.php';
require_once '../includes/friendly_urls.php';
require_once '../includes/notifications.php';
require_once '../includes/audit.php';
/** @var PDO $pdo */

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['teacher', 'admin'], true)) {
    header('Location: ../index.php');
    exit;
}

if (!isset($pdo)) {
    http_response_code(500);
    exit('Database connection error.');
}
ensureQuizSchema($pdo);

$courseId = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT)
        ?: filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT))
    : filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);
$quizId = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (filter_input(INPUT_POST, 'quiz_id', FILTER_VALIDATE_INT)
        ?: filter_input(INPUT_GET, 'quiz_id', FILTER_VALIDATE_INT))
    : filter_input(INPUT_GET, 'quiz_id', FILTER_VALIDATE_INT);
if (!$courseId) {
    http_response_code(400);
    exit('Khóa học không hợp lệ.');
}

$courseStmt = $_SESSION['user_role'] === 'admin'
    ? $pdo->prepare('SELECT * FROM courses WHERE id = ?')
    : $pdo->prepare('SELECT * FROM courses WHERE id = ? AND teacher_id = ?');
$courseStmt->execute($_SESSION['user_role'] === 'admin' ? [$courseId] : [$courseId, $_SESSION['user_id']]);
$course = $courseStmt->fetch();
if (!$course) {
    http_response_code(403);
    exit('Bạn không có quyền quản lý khóa học này.');
}

$redirect = static function (int $courseId, ?int $quizId = null): never {
    $url = 'quizzes.php?course_id=' . $courseId;
    if ($quizId) $url .= '&quiz_id=' . $quizId;
    header('Location: ' . $url);
    exit;
};
$quizImageDirectory = dirname(__DIR__) . '/uploads/quiz_images';
$saveQuizImage = static function (string $binary, string $extension) use ($quizImageDirectory): string {
    if (!is_dir($quizImageDirectory) && !mkdir($quizImageDirectory, 0755, true) && !is_dir($quizImageDirectory)) {
        throw new RuntimeException('Không thể tạo thư mục lưu ảnh câu hỏi.');
    }
    $extension = $extension === 'jpeg' ? 'jpg' : $extension;
    $name = bin2hex(random_bytes(16)) . '.' . $extension;
    if (file_put_contents($quizImageDirectory . '/' . $name, $binary) === false) {
        throw new RuntimeException('Không thể lưu ảnh câu hỏi.');
    }
    return 'quiz_images/' . $name;
};
$saveUploadedQuizImage = static function (array $file) use ($saveQuizImage): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 3 * 1024 * 1024) {
        throw new RuntimeException('Ảnh phải có dung lượng không quá 3 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp'];
    if (!isset($extensions[$mime])) throw new RuntimeException('Ảnh chỉ được dùng PNG, JPG, GIF hoặc WEBP.');
    return $saveQuizImage(file_get_contents($file['tmp_name']), $extensions[$mime]);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_quiz') {
            $title = trim((string) ($_POST['title'] ?? ''));
            if ($title === '') throw new RuntimeException('Vui lòng nhập tên bài trắc nghiệm.');
            $duration = max(1, min(600, (int) ($_POST['duration_minutes'] ?? 40)));
            $orderStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM quizzes WHERE course_id=?');
            $orderStmt->execute([$courseId]);
            $stmt = $pdo->prepare(
                'INSERT INTO quizzes
                 (course_id, teacher_id, title, slug, description, duration_minutes, max_attempts,
                  question_limit, shuffle_questions, shuffle_options, is_published, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
            );
            $stmt->execute([
                $courseId,
                $_SESSION['user_id'],
                $title,
                uniqueFriendlySlug($pdo, 'quizzes', $title),
                trim((string) ($_POST['description'] ?? '')),
                $duration,
                max(0, min(100, (int) ($_POST['max_attempts'] ?? 0))),
                max(0, min(1000, (int) ($_POST['question_limit'] ?? 0))),
                isset($_POST['shuffle_questions']) ? 1 : 0,
                isset($_POST['shuffle_options']) ? 1 : 0,
                (int) $orderStmt->fetchColumn(),
            ]);
            $newQuizId = (int) $pdo->lastInsertId();
            $studentStmt = $pdo->prepare('SELECT student_id FROM course_enrollments WHERE course_id=?');
            $studentStmt->execute([$courseId]);
            foreach ($studentStmt->fetchAll(PDO::FETCH_COLUMN) as $enrolledStudentId) {
                createNotification(
                    $pdo,
                    (int) $enrolledStudentId,
                    'quiz_created',
                    'Có bài trắc nghiệm mới',
                    "“{$title}” đã được mở trong khóa “{$course['title']}”.",
                    '../student/quiz.php?id=' . $newQuizId,
                    ['quiz_id' => $newQuizId, 'course_id' => $courseId]
                );
            }
            writeAuditLog($pdo, 'quiz.created', 'quiz', $newQuizId, ['course_id' => $courseId]);
            $_SESSION['success'] = 'Đã tạo và mở bài trắc nghiệm cho học viên. Hãy nhập file câu hỏi CSV hoặc Excel.';
            $redirect($courseId, $newQuizId);
        }

        $quizCheck = $pdo->prepare('SELECT id FROM quizzes WHERE id = ? AND course_id = ?');
        $quizCheck->execute([$quizId, $courseId]);
        if (!$quizCheck->fetchColumn()) throw new RuntimeException('Bài trắc nghiệm không hợp lệ.');

        if ($action === 'move_quiz') {
            $direction = ($_POST['direction'] ?? '') === 'up' ? -1 : 1;
            $orderStmt = $pdo->prepare('SELECT id FROM quizzes WHERE course_id=? ORDER BY sort_order,id');
            $orderStmt->execute([$courseId]);
            $ids = array_map('intval', $orderStmt->fetchAll(PDO::FETCH_COLUMN));
            $currentIndex = array_search((int) $quizId, $ids, true);
            $targetIndex = $currentIndex === false ? -1 : $currentIndex + $direction;
            if ($currentIndex !== false && isset($ids[$targetIndex])) {
                [$ids[$currentIndex], $ids[$targetIndex]] = [$ids[$targetIndex], $ids[$currentIndex]];
                $pdo->beginTransaction();
                $updateOrder = $pdo->prepare('UPDATE quizzes SET sort_order=? WHERE id=? AND course_id=?');
                foreach ($ids as $index => $id) $updateOrder->execute([$index + 1, $id, $courseId]);
                $pdo->commit();
                $_SESSION['success'] = 'Đã cập nhật thứ tự bài trắc nghiệm.';
            }
        } elseif ($action === 'update_quiz') {
            $title = trim((string) ($_POST['title'] ?? ''));
            if ($title === '') throw new RuntimeException('Tên bài trắc nghiệm không được để trống.');
            $availableFrom = trim((string) ($_POST['available_from'] ?? ''));
            $availableUntil = trim((string) ($_POST['available_until'] ?? ''));
            $stmt = $pdo->prepare(
                'UPDATE quizzes
                 SET title=?, description=?, duration_minutes=?, max_attempts=?, question_limit=?,
                     shuffle_questions=?, shuffle_options=?, available_from=?, available_until=?, is_published=?
                 WHERE id=?'
            );
            $stmt->execute([
                $title,
                trim((string) ($_POST['description'] ?? '')),
                max(1, min(600, (int) ($_POST['duration_minutes'] ?? 40))),
                max(0, min(100, (int) ($_POST['max_attempts'] ?? 0))),
                max(0, min(1000, (int) ($_POST['question_limit'] ?? 0))),
                isset($_POST['shuffle_questions']) ? 1 : 0,
                isset($_POST['shuffle_options']) ? 1 : 0,
                $availableFrom !== '' ? date('Y-m-d H:i:s', strtotime($availableFrom)) : null,
                $availableUntil !== '' ? date('Y-m-d H:i:s', strtotime($availableUntil)) : null,
                isset($_POST['is_published']) ? 1 : 0,
                $quizId,
            ]);
            $_SESSION['success'] = 'Đã cập nhật bài trắc nghiệm.';
        } elseif ($action === 'delete_quiz') {
            $pdo->prepare('DELETE FROM quizzes WHERE id=? AND course_id=?')->execute([$quizId, $courseId]);
            $_SESSION['success'] = 'Đã xóa bài trắc nghiệm.';
            $redirect($courseId);
        } elseif ($action === 'import_section') {
            if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Vui lòng chọn file CSV hợp lệ.');
            }
            $file = $_FILES['csv_file'];
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (($file['size'] ?? 0) > 5 * 1024 * 1024 || !in_array($extension, ['csv', 'xlsx'], true)) {
                throw new RuntimeException('Chỉ chấp nhận file .csv hoặc .xlsx tối đa 5 MB.');
            }
            $rows = [];
            $sourceRows = readQuizImportRows($file['tmp_name'], $extension);
            foreach ($sourceRows as $index => $row) {
                $line = $index + 1;
                if ($line === 1) continue;
                $images = $row['__images'] ?? [];
                unset($row['__images']);
                if (count($row) === 1 && trim((string) $row[0]) === '') continue;
                if (count($row) < 6) throw new RuntimeException("Dòng {$line} không đủ 6 cột.");
                $row = array_map(static fn($value) => trim((string) $value), array_slice($row, 0, 6));
                foreach (range(0, 4) as $contentColumn) {
                    if ($row[$contentColumn] === '' && empty($images[$contentColumn])) {
                        throw new RuntimeException("Dòng {$line}, cột " . chr(65 + $contentColumn) . ' phải có chữ hoặc hình ảnh.');
                    }
                }
                $row[5] = strtoupper($row[5]);
                if (!in_array($row[5], ['A', 'B', 'C', 'D'], true)) throw new RuntimeException("Dòng {$line} có đáp án đúng không hợp lệ.");
                $rows[] = ['values' => $row, 'images' => $images];
            }
            if (!$rows) throw new RuntimeException('File không có câu hỏi.');

            $pdo->beginTransaction();
            $sectionStmt = $pdo->prepare('SELECT id FROM quiz_sections WHERE quiz_id=? ORDER BY sort_order,id LIMIT 1');
            $sectionStmt->execute([$quizId]);
            $sectionId = (int) $sectionStmt->fetchColumn();
            if (!$sectionId) {
                $pdo->prepare("INSERT INTO quiz_sections (quiz_id,title,sort_order) VALUES (?,'Danh sách câu hỏi',1)")
                    ->execute([$quizId]);
                $sectionId = (int) $pdo->lastInsertId();
            }
            $sortStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM quiz_questions WHERE section_id=?');
            $sortStmt->execute([$sectionId]);
            $startOrder = (int) $sortStmt->fetchColumn();
            $insert = $pdo->prepare('INSERT INTO quiz_questions (section_id,question_text,option_a,option_b,option_c,option_d,correct_option,sort_order,question_image,option_a_image,option_b_image,option_c_image,option_d_image) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($rows as $index => $importRow) {
                $row = $importRow['values'];
                $imagePaths = array_fill(0, 5, null);
                foreach ($imagePaths as $column => $_) {
                    $image = $importRow['images'][$column][0] ?? null;
                    if ($image) $imagePaths[$column] = $saveQuizImage($image['data'], $image['extension']);
                }
                $insert->execute([$sectionId, $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $startOrder + $index + 1, ...$imagePaths]);
            }
            $pdo->commit();
            $_SESSION['success'] = 'Đã thêm ' . count($rows) . ' câu hỏi vào bài trắc nghiệm.';
        } elseif ($action === 'update_question') {
            $questionId = (int) ($_POST['question_id'] ?? 0);
            $values = [
                trim((string) ($_POST['question_text'] ?? '')),
                trim((string) ($_POST['option_a'] ?? '')),
                trim((string) ($_POST['option_b'] ?? '')),
                trim((string) ($_POST['option_c'] ?? '')),
                trim((string) ($_POST['option_d'] ?? '')),
            ];
            $correct = strtoupper(trim((string) ($_POST['correct_option'] ?? '')));
            if (!in_array($correct, ['A','B','C','D'], true)) throw new RuntimeException('Đáp án đúng phải là A, B, C hoặc D.');
            $points = max(0.1, min(100, (float) ($_POST['points'] ?? 1)));
            $difficulty = in_array($_POST['difficulty'] ?? '', ['easy','medium','hard'], true)
                ? $_POST['difficulty']
                : 'medium';
            $explanation = trim((string) ($_POST['explanation'] ?? ''));
            $imageFields = ['question_image','option_a_image','option_b_image','option_c_image','option_d_image'];
            $currentStmt = $pdo->prepare('SELECT q.question_image,q.option_a_image,q.option_b_image,q.option_c_image,q.option_d_image FROM quiz_questions q JOIN quiz_sections s ON s.id=q.section_id WHERE q.id=? AND s.quiz_id=?');
            $currentStmt->execute([$questionId,$quizId]);
            $images = $currentStmt->fetch();
            if (!$images) throw new RuntimeException('Không tìm thấy câu hỏi.');
            foreach ($imageFields as $imageField) {
                if (isset($_POST['remove_'.$imageField])) $images[$imageField] = null;
                $uploaded = $saveUploadedQuizImage($_FILES[$imageField] ?? []);
                if ($uploaded) $images[$imageField] = $uploaded;
            }
            foreach ($values as $contentIndex => $textValue) {
                if ($textValue === '' && empty($images[$imageFields[$contentIndex]])) {
                    throw new RuntimeException(($contentIndex === 0 ? 'Câu hỏi' : 'Đáp án ' . chr(64 + $contentIndex)) . ' phải có chữ hoặc hình ảnh.');
                }
            }
            $stmt = $pdo->prepare('UPDATE quiz_questions q JOIN quiz_sections s ON s.id=q.section_id SET q.question_text=?,q.option_a=?,q.option_b=?,q.option_c=?,q.option_d=?,q.correct_option=?,q.points=?,q.difficulty=?,q.explanation=?,q.question_image=?,q.option_a_image=?,q.option_b_image=?,q.option_c_image=?,q.option_d_image=? WHERE q.id=? AND s.quiz_id=?');
            $stmt->execute([...$values, $correct, $points, $difficulty, $explanation, ...array_values($images), $questionId, $quizId]);
            $_SESSION['success'] = 'Đã cập nhật câu hỏi.';
        } elseif ($action === 'delete_question') {
            $stmt = $pdo->prepare('DELETE q FROM quiz_questions q JOIN quiz_sections s ON s.id=q.section_id WHERE q.id=? AND s.quiz_id=?');
            $stmt->execute([(int) ($_POST['question_id'] ?? 0), $quizId]);
            $_SESSION['success'] = 'Đã xóa câu hỏi.';
        }
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = $error->getMessage();
    }
    $redirect($courseId, $quizId ?: null);
}

$quizListStmt = $pdo->prepare('SELECT q.*, COUNT(DISTINCT s.id) section_count, COUNT(qq.id) question_count FROM quizzes q LEFT JOIN quiz_sections s ON s.quiz_id=q.id LEFT JOIN quiz_questions qq ON qq.section_id=s.id WHERE q.course_id=? GROUP BY q.id ORDER BY q.sort_order,q.id');
$quizListStmt->execute([$courseId]);
$quizzes = $quizListStmt->fetchAll();
$quiz = null;
$sections = [];
$attempts = [];
if ($quizId) {
    $stmt = $pdo->prepare('SELECT * FROM quizzes WHERE id=? AND course_id=?');
    $stmt->execute([$quizId, $courseId]);
    $quiz = $stmt->fetch();
    if ($quiz) {
        $stmt = $pdo->prepare('SELECT s.*, COUNT(q.id) question_count FROM quiz_sections s LEFT JOIN quiz_questions q ON q.section_id=s.id WHERE s.quiz_id=? GROUP BY s.id ORDER BY s.sort_order,s.id');
        $stmt->execute([$quizId]);
        $sections = $stmt->fetchAll();
        $questionStmt = $pdo->prepare('SELECT * FROM quiz_questions WHERE section_id=? ORDER BY sort_order,id');
        foreach ($sections as &$section) {
            $questionStmt->execute([$section['id']]);
            $section['questions'] = $questionStmt->fetchAll();
        }
        unset($section);
        $stmt = $pdo->prepare('SELECT qa.*,u.name student_name,u.email student_email FROM quiz_attempts qa JOIN users u ON u.id=qa.student_id WHERE qa.quiz_id=? AND qa.submitted_at IS NOT NULL ORDER BY qa.submitted_at DESC');
        $stmt->execute([$quizId]);
        $attempts = $stmt->fetchAll();
    }
}

$page_title = 'Quản lý trắc nghiệm: ' . $course['title'];
require_once '../includes/header.php';
?>
<style>
.quiz-layout{display:grid;grid-template-columns:minmax(250px,320px) minmax(0,1fr);gap:22px;align-items:start;color-scheme:dark}
.quiz-list{display:flex;flex-direction:column;gap:10px}.quiz-list-item{padding:12px;border:1px solid var(--border-color);border-radius:10px}.quiz-list-item.active{border-color:var(--primary);background:rgba(var(--primary-rgb),.12)}.quiz-list-link{display:block;color:var(--text-main);text-decoration:none;margin-bottom:9px}.quiz-list-actions{display:flex;gap:6px;flex-wrap:wrap}.quiz-list-actions form{margin:0}.quiz-list-actions .btn{min-height:34px;padding:6px 9px}.quiz-meta{font-size:13px;color:var(--text-muted)}
.question-editor{padding:16px;border:1px solid var(--border-color);border-radius:12px;margin-top:12px}.answer-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.question-image{display:block;max-width:220px;max-height:150px;object-fit:contain;margin:8px 0;padding:5px;border-radius:8px;background:#fff}.image-tools{padding:9px;border:1px dashed var(--border-color);border-radius:8px;margin-top:8px}.image-tools label{font-size:13px;color:var(--text-muted)}
.section-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.inline-actions{display:flex;gap:8px;flex-wrap:wrap}
.quiz-layout .form-group{display:grid;gap:8px;margin-bottom:18px}
.quiz-layout .form-group>label{color:var(--text-main);font-size:14px;font-weight:600}
.quiz-layout .form-group input:not([type="checkbox"]),.quiz-layout .form-group textarea,.quiz-layout .form-group select,.quiz-layout .inline-actions select{width:100%;min-height:46px;padding:11px 14px;border:1px solid rgba(148,163,184,.22);border-radius:11px;background:rgba(15,23,42,.72);color:var(--text-main);font:inherit;outline:none;box-shadow:inset 0 1px 0 rgba(255,255,255,.025);transition:border-color .22s ease,box-shadow .22s ease,background .22s ease,transform .22s ease}
.quiz-layout .form-group textarea{min-height:96px;line-height:1.55;resize:vertical}
.quiz-layout .form-group input::placeholder,.quiz-layout .form-group textarea::placeholder{color:#64748b}
.quiz-layout .form-group input:hover,.quiz-layout .form-group textarea:hover,.quiz-layout .form-group select:hover,.quiz-layout .inline-actions select:hover{border-color:rgba(129,140,248,.48);background:rgba(15,23,42,.9)}
.quiz-layout .form-group input:focus,.quiz-layout .form-group textarea:focus,.quiz-layout .form-group select:focus,.quiz-layout .inline-actions select:focus{border-color:var(--primary);background:rgba(15,23,42,.96);box-shadow:0 0 0 4px rgba(var(--primary-rgb),.14),0 8px 24px rgba(0,0,0,.16)}
.quiz-layout input[type="file"]{padding:8px!important;cursor:pointer}
.quiz-layout input[type="file"]::file-selector-button{margin-right:12px;padding:8px 12px;border:0;border-radius:8px;background:rgba(var(--primary-rgb),.18);color:#a5b4fc;font-weight:700;cursor:pointer}
.quiz-toggle{display:flex!important;align-items:center!important;gap:11px!important;margin:0 0 12px!important;color:var(--text-main);font-size:14px;font-weight:600;cursor:pointer}
.quiz-toggle input[type="checkbox"]{position:relative;flex:0 0 44px;width:44px;height:24px;margin:0;appearance:none;-webkit-appearance:none;border:1px solid rgba(148,163,184,.3);border-radius:999px;background:rgba(15,23,42,.8);cursor:pointer;transition:.25s ease}
.quiz-toggle input[type="checkbox"]::before{content:"";position:absolute;width:16px;height:16px;left:3px;top:3px;border-radius:50%;background:#cbd5e1;box-shadow:0 2px 5px rgba(0,0,0,.35);transition:transform .25s cubic-bezier(.22,1,.36,1),background .25s ease}
.quiz-toggle input[type="checkbox"]:checked{border-color:var(--primary);background:var(--primary);box-shadow:0 0 0 3px rgba(var(--primary-rgb),.12)}
.quiz-toggle input[type="checkbox"]:checked::before{transform:translateX(20px);background:#fff}
.quiz-toggle-grid{grid-column:1/-1;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:4px;padding:14px;border:1px solid var(--border-color);border-radius:12px;background:rgba(15,23,42,.35)}
.quiz-toggle-grid .quiz-toggle{min-height:34px;margin:0!important}
@media(max-width:850px){.quiz-layout{grid-template-columns:1fr}.answer-grid{grid-template-columns:1fr}.quiz-toggle-grid{grid-template-columns:1fr}}
</style>
<a href="course_detail.php?id=<?php echo $courseId; ?>" style="color:var(--primary)"><i class='bx bx-arrow-back'></i> Quay lại khóa học</a>
<h1><i class='bx bx-list-check'></i> Trắc nghiệm — <?php echo htmlspecialchars($course['title']); ?></h1>
<?php if(isset($_SESSION['success'])):?><div class="box" style="padding:14px;margin-bottom:14px;color:var(--success)"><?php echo htmlspecialchars($_SESSION['success']);unset($_SESSION['success']);?></div><?php endif;?>
<?php if(isset($_SESSION['error'])):?><div class="box" style="padding:14px;margin-bottom:14px;color:var(--danger)"><?php echo htmlspecialchars($_SESSION['error']);unset($_SESSION['error']);?></div><?php endif;?>
<div class="quiz-layout">
    <aside class="box">
        <h3 style="margin-top:0">Tạo bài trắc nghiệm mới</h3>
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="create_quiz"><input type="hidden" name="course_id" value="<?php echo $courseId;?>">
            <div class="form-group"><label>Tên bài</label><input name="title" required></div>
            <div class="form-group"><label>Mô tả</label><textarea name="description"></textarea></div>
            <div class="form-group"><label>Thời gian (phút)</label><input type="number" name="duration_minutes" value="40" min="1" max="600"></div>
            <div class="form-group"><label>Số lượt làm tối đa (0 = không giới hạn)</label><input type="number" name="max_attempts" value="0" min="0" max="100"></div>
            <div class="form-group"><label>Số câu lấy cho mỗi lượt (0 = tất cả)</label><input type="number" name="question_limit" value="0" min="0" max="1000"></div>
            <label class="quiz-toggle"><input type="checkbox" name="shuffle_questions"> <span>Đảo thứ tự câu hỏi</span></label>
            <label class="quiz-toggle" style="margin-bottom:18px!important"><input type="checkbox" name="shuffle_options"> <span>Đảo thứ tự đáp án</span></label>
            <button class="btn btn-primary"><i class='bx bx-plus'></i> Tạo trắc nghiệm</button>
        </form>
        <hr style="border-color:var(--border-color);margin:24px 0">
        <h3>Các bài trắc nghiệm đã tạo</h3>
        <div class="quiz-list">
            <?php foreach($quizzes as $listIndex=>$item):?>
                <div class="quiz-list-item <?php echo (int)$quizId===(int)$item['id']?'active':'';?>">
                    <a class="quiz-list-link" href="?course_id=<?php echo $courseId;?>&quiz_id=<?php echo (int)$item['id'];?>">
                        <strong><?php echo $listIndex+1;?>. <?php echo htmlspecialchars($item['title']);?></strong>
                        <div class="quiz-meta"><?php echo (int)$item['question_count'];?> câu · <?php echo $item['is_published']?'Đã mở':'Bản nháp';?></div>
                    </a>
                    <div class="quiz-list-actions">
                        <a class="btn btn-outline" href="?course_id=<?php echo $courseId;?>&quiz_id=<?php echo (int)$item['id'];?>" title="Sửa"><i class='bx bx-edit'></i></a>
                        <form method="post"><?php echo csrfField(); ?><input type="hidden" name="action" value="move_quiz"><input type="hidden" name="direction" value="up"><input type="hidden" name="course_id" value="<?php echo $courseId;?>"><input type="hidden" name="quiz_id" value="<?php echo (int)$item['id'];?>"><button class="btn btn-outline" title="Đưa lên" <?php echo $listIndex===0?'disabled':'';?>><i class='bx bx-up-arrow-alt'></i></button></form>
                        <form method="post"><?php echo csrfField(); ?><input type="hidden" name="action" value="move_quiz"><input type="hidden" name="direction" value="down"><input type="hidden" name="course_id" value="<?php echo $courseId;?>"><input type="hidden" name="quiz_id" value="<?php echo (int)$item['id'];?>"><button class="btn btn-outline" title="Đưa xuống" <?php echo $listIndex===count($quizzes)-1?'disabled':'';?>><i class='bx bx-down-arrow-alt'></i></button></form>
                        <form method="post" onsubmit="return confirm('Xóa bài trắc nghiệm này và toàn bộ kết quả?')"><?php echo csrfField(); ?><input type="hidden" name="action" value="delete_quiz"><input type="hidden" name="course_id" value="<?php echo $courseId;?>"><input type="hidden" name="quiz_id" value="<?php echo (int)$item['id'];?>"><button class="btn" style="background:var(--danger);color:white" title="Xóa"><i class='bx bx-trash'></i></button></form>
                    </div>
                </div>
            <?php endforeach;?>
            <?php if(!$quizzes):?><p class="quiz-meta">Chưa có bài trắc nghiệm.</p><?php endif;?>
        </div>
    </aside>
    <main>
    <?php if($quiz):?>
        <section class="box" style="margin-bottom:18px">
            <form method="post">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_quiz"><input type="hidden" name="course_id" value="<?php echo $courseId;?>"><input type="hidden" name="quiz_id" value="<?php echo $quizId;?>">
                <div class="form-group"><label>Tên bài trắc nghiệm</label><input name="title" value="<?php echo htmlspecialchars($quiz['title']);?>" required></div>
                <div class="form-group"><label>Mô tả</label><textarea name="description"><?php echo htmlspecialchars($quiz['description']??'');?></textarea></div>
                <div class="answer-grid">
                    <div class="form-group"><label>Thời gian (phút)</label><input type="number" name="duration_minutes" value="<?php echo (int)$quiz['duration_minutes'];?>" min="1" max="600"></div>
                    <div class="form-group"><label>Số lượt làm tối đa (0 = không giới hạn)</label><input type="number" name="max_attempts" value="<?php echo (int)($quiz['max_attempts']??0);?>" min="0" max="100"></div>
                    <div class="form-group"><label>Số câu mỗi lượt (0 = tất cả)</label><input type="number" name="question_limit" value="<?php echo (int)($quiz['question_limit']??0);?>" min="0" max="1000"></div>
                    <div class="form-group"><label>Mở từ</label><input type="datetime-local" name="available_from" value="<?php echo !empty($quiz['available_from'])?date('Y-m-d\\TH:i',strtotime($quiz['available_from'])):'';?>"></div>
                    <div class="form-group"><label>Đóng lúc</label><input type="datetime-local" name="available_until" value="<?php echo !empty($quiz['available_until'])?date('Y-m-d\\TH:i',strtotime($quiz['available_until'])):'';?>"></div>
                    <div class="quiz-toggle-grid">
                        <label class="quiz-toggle"><input type="checkbox" name="shuffle_questions" <?php echo !empty($quiz['shuffle_questions'])?'checked':'';?>> <span>Đảo câu hỏi</span></label>
                        <label class="quiz-toggle"><input type="checkbox" name="shuffle_options" <?php echo !empty($quiz['shuffle_options'])?'checked':'';?>> <span>Đảo đáp án</span></label>
                        <label class="quiz-toggle"><input type="checkbox" name="is_published" <?php echo $quiz['is_published']?'checked':'';?>> <span>Mở cho học viên làm</span></label>
                    </div>
                </div>
                <div class="inline-actions"><button class="btn btn-primary">Lưu thông tin</button></div>
            </form>
        </section>
        <section class="box" style="margin-bottom:18px;border-color:rgba(56,189,248,.3)">
            <h3 style="margin-top:0"><i class='bx bx-upload'></i> Nhập câu hỏi bằng file CSV hoặc Excel</h3>
            <p class="quiz-meta">Hỗ trợ .csv và .xlsx. File gồm: Câu hỏi, Đáp án A, B, C, D, Đáp án đúng (A/B/C/D). Có thể nhập thêm nhiều file vào cùng bài trắc nghiệm.</p>
            <p><a href="../assets/templates/template_questions.csv" download style="color:var(--primary)"><i class='bx bx-download'></i> Tải file CSV mẫu</a></p>
            <form method="post" enctype="multipart/form-data">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="import_section"><input type="hidden" name="course_id" value="<?php echo $courseId;?>"><input type="hidden" name="quiz_id" value="<?php echo $quizId;?>">
                <div class="form-group"><label>File CSV / Excel</label><input type="file" name="csv_file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></div>
                <button class="btn btn-primary"><i class='bx bx-import'></i> Nhập câu hỏi</button>
            </form>
        </section>
        <?php foreach($sections as $number=>$section):?>
        <section class="box" style="margin-bottom:18px">
            <div class="section-head"><h3 style="margin:0"><i class='bx bx-help-circle'></i> Danh sách câu hỏi</h3><span class="quiz-meta"><?php echo (int)$section['question_count'];?> câu hỏi</span></div>
            <?php foreach($section['questions'] as $index=>$question):?>
            <form method="post" enctype="multipart/form-data" class="question-editor">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_question"><input type="hidden" name="course_id" value="<?php echo $courseId;?>"><input type="hidden" name="quiz_id" value="<?php echo $quizId;?>"><input type="hidden" name="question_id" value="<?php echo (int)$question['id'];?>">
                <div class="form-group"><label>Câu <?php echo $index+1;?></label><textarea name="question_text" placeholder="Có thể để trống nếu câu hỏi là hình ảnh"><?php echo htmlspecialchars($question['question_text']);?></textarea>
                    <div class="image-tools">
                        <?php if(!empty($question['question_image'])):?><img class="question-image" src="../uploads/<?php echo htmlspecialchars($question['question_image']);?>" alt="Ảnh câu hỏi"><label><input type="checkbox" name="remove_question_image"> Xóa ảnh hiện tại</label><?php endif;?>
                        <label>Ảnh câu hỏi mới</label><input type="file" name="question_image" accept="image/png,image/jpeg,image/gif,image/webp">
                    </div>
                </div>
                <div class="answer-grid">
                    <?php foreach(['a','b','c','d'] as $letter):$imageField='option_'.$letter.'_image';?><div class="form-group"><label>Đáp án <?php echo strtoupper($letter);?></label><input name="option_<?php echo $letter;?>" value="<?php echo htmlspecialchars($question['option_'.$letter]);?>" placeholder="Có thể để trống nếu đáp án là hình ảnh">
                        <div class="image-tools">
                            <?php if(!empty($question[$imageField])):?><img class="question-image" src="../uploads/<?php echo htmlspecialchars($question[$imageField]);?>" alt="Ảnh đáp án <?php echo strtoupper($letter);?>"><label><input type="checkbox" name="remove_<?php echo $imageField;?>"> Xóa ảnh hiện tại</label><?php endif;?>
                            <label>Ảnh đáp án <?php echo strtoupper($letter);?> mới</label><input type="file" name="<?php echo $imageField;?>" accept="image/png,image/jpeg,image/gif,image/webp">
                        </div>
                    </div><?php endforeach;?>
                </div>
                <div class="answer-grid">
                    <div class="form-group"><label>Điểm trọng số</label><input type="number" name="points" min="0.1" max="100" step="0.1" value="<?php echo htmlspecialchars((string)($question['points']??1));?>"></div>
                    <div class="form-group"><label>Độ khó</label><select name="difficulty"><?php foreach(['easy'=>'Dễ','medium'=>'Trung bình','hard'=>'Khó'] as $value=>$label):?><option value="<?php echo $value;?>" <?php echo ($question['difficulty']??'medium')===$value?'selected':'';?>><?php echo $label;?></option><?php endforeach;?></select></div>
                </div>
                <div class="form-group"><label>Giải thích đáp án</label><textarea name="explanation"><?php echo htmlspecialchars($question['explanation']??'');?></textarea></div>
                <div class="inline-actions"><select name="correct_option" style="width:auto"><?php foreach(['A','B','C','D'] as $letter):?><option <?php echo $question['correct_option']===$letter?'selected':'';?>><?php echo $letter;?></option><?php endforeach;?></select><button class="btn btn-primary">Lưu câu hỏi</button><button class="btn" name="action" value="delete_question" style="background:var(--danger);color:white" onclick="return confirm('Xóa câu hỏi này?')">Xóa</button></div>
            </form>
            <?php endforeach;?>
        </section>
        <?php endforeach;?>
        <section class="box" style="margin-bottom:18px">
            <h3 style="margin-top:0"><i class='bx bx-bar-chart-alt-2'></i> Kết quả học viên (<?php echo count($attempts);?> lượt)</h3>
            <div style="overflow-x:auto"><table>
                <thead><tr><th>Học viên</th><th>Số câu đúng</th><th>Điểm</th><th>Thời gian nộp</th></tr></thead>
                <tbody>
                <?php foreach($attempts as $attempt):?><tr><td><strong><?php echo htmlspecialchars($attempt['student_name']);?></strong><small style="display:block;color:var(--text-muted)"><?php echo htmlspecialchars($attempt['student_email']);?></small></td><td><?php echo (int)$attempt['correct_count'];?>/<?php echo (int)$attempt['total_questions'];?></td><td><strong style="color:var(--success)"><?php echo htmlspecialchars($attempt['score']);?>/10</strong></td><td><?php echo date('d/m/Y H:i',strtotime($attempt['submitted_at']));?></td></tr><?php endforeach;?>
                <?php if(!$attempts):?><tr><td colspan="4" style="text-align:center;color:var(--text-muted)">Chưa có học viên hoàn thành bài này.</td></tr><?php endif;?>
                </tbody>
            </table></div>
        </section>
    <?php else:?><div class="box empty-state">Chọn một bài trắc nghiệm bên trái hoặc tạo bài mới.</div><?php endif;?>
    </main>
</div>
<?php require_once '../includes/footer.php'; ?>
