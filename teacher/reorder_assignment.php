<?php
require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['teacher', 'administrative_staff', 'admin'], true)) {
    header('Location: ../index.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Phương thức không được hỗ trợ.');
}
verifyCsrfToken();

$assignmentId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$direction = (string) ($_POST['direction'] ?? '');
$returnTo = (string) ($_POST['return_to'] ?? 'teacher');
$returnPage = max(1, (int) ($_POST['return_page'] ?? 1));
$redirect = $returnTo === 'admin' && $_SESSION['user_role'] === 'admin'
    ? '../admin/assignments.php?page=' . $returnPage
    : 'assignments.php';

if (!$assignmentId || !in_array($direction, ['up', 'down'], true)) {
    $_SESSION['error'] = 'Yêu cầu sắp xếp không hợp lệ.';
    header('Location: ' . $redirect);
    exit;
}

$lookup = $_SESSION['user_role'] === 'admin'
    ? $pdo->prepare('SELECT id, course_id, type FROM assignments WHERE id = ?')
    : $pdo->prepare('SELECT id, course_id, type FROM assignments WHERE id = ? AND teacher_id = ?');
$lookup->execute($_SESSION['user_role'] === 'admin' ? [$assignmentId] : [$assignmentId, $_SESSION['user_id']]);
$assignment = $lookup->fetch();
if (!$assignment) {
    $_SESSION['error'] = 'Không tìm thấy bài tập hoặc bạn không có quyền sắp xếp.';
    header('Location: ' . $redirect);
    exit;
}

$courseCondition = $assignment['course_id'] === null ? 'course_id IS NULL' : 'course_id = ?';
$ownerCondition = $_SESSION['user_role'] === 'admin' ? '' : ' AND teacher_id = ?';
$params = [];
if ($assignment['course_id'] !== null) $params[] = (int) $assignment['course_id'];
$params[] = (string) $assignment['type'];
if ($_SESSION['user_role'] !== 'admin') $params[] = (int) $_SESSION['user_id'];

try {
    $pdo->beginTransaction();
    $list = $pdo->prepare("SELECT id FROM assignments WHERE {$courseCondition} AND type = ?{$ownerCondition} ORDER BY priority_order, created_at, id FOR UPDATE");
    $list->execute($params);
    $ids = array_map('intval', $list->fetchAll(PDO::FETCH_COLUMN));
    $currentIndex = array_search((int) $assignmentId, $ids, true);
    $targetIndex = $currentIndex === false ? false : $currentIndex + ($direction === 'up' ? -1 : 1);
    if ($currentIndex !== false && isset($ids[$targetIndex])) {
        [$ids[$currentIndex], $ids[$targetIndex]] = [$ids[$targetIndex], $ids[$currentIndex]];
    }
    $update = $pdo->prepare('UPDATE assignments SET priority_order = ? WHERE id = ?');
    foreach ($ids as $index => $id) $update->execute([$index + 1, $id]);
    $pdo->commit();
    $_SESSION['success'] = 'Đã cập nhật thứ tự ưu tiên.';
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['error'] = 'Không thể cập nhật thứ tự ưu tiên.';
}

header('Location: ' . $redirect);
exit;
