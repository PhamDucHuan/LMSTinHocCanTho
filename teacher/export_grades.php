<?php
declare(strict_types=1);
require_once '../includes/security.php';
require_once '../includes/authorization.php';
secureSessionStart();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['teacher','admin'], true)) {
    header('Location: ../index.php'); exit;
}

$format      = $_GET['format'] ?? 'csv'; // csv | pdf
$assignmentId = filter_input(INPUT_GET, 'assignment_id', FILTER_VALIDATE_INT);
$courseId     = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT);

// Validate at least one filter
if (!$assignmentId && !$courseId) {
    die('Vui lòng chọn bài tập hoặc khóa học để xuất báo cáo.');
}

// Build query
$conditions = [];
$params = [];
if ($_SESSION['user_role'] === 'teacher') {
    $conditions[] = 'c.teacher_id = ?';
    $params[] = (int)$_SESSION['user_id'];
}
if ($assignmentId) { $conditions[] = 'a.id = ?'; $params[] = $assignmentId; }
if ($courseId)     { $conditions[] = 'c.id = ?'; $params[] = $courseId; }

$where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';
$rows = $pdo->prepare("
    SELECT u.name, u.email, c.title AS course_title, a.title AS assignment_title,
           s.score, s.submitted_at, s.grading_status, s.module_scores
    FROM submissions s
    JOIN users u ON u.id = s.student_id
    JOIN assignments a ON a.id = s.assignment_id
    JOIN courses c ON c.id = a.course_id
    $where
    ORDER BY c.title, a.title, u.name
");
$rows->execute($params);
$data = $rows->fetchAll();

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="bang_diem_' . date('Ymd_His') . '.csv"');
    // BOM for Excel UTF-8
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['STT','Họ tên','Email','Khóa học','Bài tập','Điểm','Trạng thái','Ngày nộp']);
    foreach ($data as $i => $r) {
        $status = match($r['grading_status']) {
            'ai_graded'      => 'AI đã chấm',
            'review_required'=> 'Cần GV duyệt',
            'reviewed'       => 'GV đã duyệt',
            'not_graded'     => 'Chưa chấm',
            default => $r['grading_status'],
        };
        fputcsv($out, [
            $i + 1,
            $r['name'],
            $r['email'],
            $r['course_title'],
            $r['assignment_title'],
            $r['score'] !== null ? number_format((float)$r['score'], 1) : '—',
            $status,
            $r['submitted_at'] ? date('d/m/Y H:i', strtotime($r['submitted_at'])) : '—',
        ]);
    }
    fclose($out);
    exit;
}

// PDF (print-optimized HTML)
$filterLabel = '';
if ($assignmentId) {
    $stmt = $pdo->prepare('SELECT a.title, c.title AS ct FROM assignments a JOIN courses c ON c.id=a.course_id WHERE a.id=?');
    $stmt->execute([$assignmentId]);
    $aInfo = $stmt->fetch();
    $filterLabel = ($aInfo['ct'] ?? '') . ' › ' . ($aInfo['title'] ?? '');
} elseif ($courseId) {
    $stmt = $pdo->prepare('SELECT title FROM courses WHERE id=?');
    $stmt->execute([$courseId]);
    $filterLabel = $stmt->fetchColumn() ?: '';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>Bảng điểm – <?php echo htmlspecialchars($filterLabel); ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; color: #1e293b; background: #fff; }
.report-header { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #6366f1; padding-bottom: 12px; margin-bottom: 18px; }
.report-title { font-size: 18px; font-weight: 700; color: #6366f1; }
.report-meta { font-size: 11px; color: #64748b; }
table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
th { background: #6366f1; color: #fff; padding: 9px 10px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; }
td { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
tr:nth-child(even) td { background: #f8fafc; }
.score-pass { font-weight: 700; color: #059669; }
.score-fail { font-weight: 700; color: #dc2626; }
.score-na { color: #94a3b8; }
.summary { display: flex; gap: 24px; margin-bottom: 18px; }
.summary-card { flex: 1; background: #f1f5f9; border-radius: 8px; padding: 12px 16px; }
.summary-card .val { font-size: 24px; font-weight: 700; color: #6366f1; }
.summary-card .lbl { font-size: 11px; color: #64748b; text-transform: uppercase; margin-top: 2px; }
.no-print { text-align: center; margin-bottom: 20px; }
.print-btn { background: #6366f1; color: #fff; border: none; padding: 10px 24px; border-radius: 8px; font-size: 14px; cursor: pointer; }
@media print {
    .no-print { display: none; }
    @page { margin: 15mm; }
}
</style>
</head>
<body>
<div class="no-print">
    <button class="print-btn" onclick="window.print()">🖨️ In / Lưu PDF</button>
    <button onclick="window.close()" style="margin-left:10px;background:#e2e8f0;border:none;padding:10px 20px;border-radius:8px;cursor:pointer">✕ Đóng</button>
</div>
<div class="report-header">
    <div>
        <div class="report-title">📊 BẢNG ĐIỂM</div>
        <div class="report-meta"><?php echo htmlspecialchars($filterLabel); ?></div>
    </div>
    <div class="report-meta" style="text-align:right">
        Xuất lúc: <?php echo date('H:i d/m/Y'); ?><br>
        Tổng số bản ghi: <?php echo count($data); ?>
    </div>
</div>

<?php
$graded  = array_filter($data, fn($r) => $r['score'] !== null);
$passed  = array_filter($graded, fn($r) => (float)$r['score'] >= 5);
$avgScore = count($graded) ? round(array_sum(array_column(array_values($graded),'score')) / count($graded), 1) : 0;
$passRate = count($graded) ? round(count($passed) / count($graded) * 100) : 0;
?>
<div class="summary">
    <div class="summary-card"><div class="val"><?php echo count($data); ?></div><div class="lbl">Bài nộp</div></div>
    <div class="summary-card"><div class="val"><?php echo count($graded); ?></div><div class="lbl">Đã chấm</div></div>
    <div class="summary-card"><div class="val"><?php echo $avgScore; ?></div><div class="lbl">Điểm trung bình</div></div>
    <div class="summary-card"><div class="val"><?php echo $passRate; ?>%</div><div class="lbl">Tỉ lệ đậu (≥5đ)</div></div>
</div>

<table>
<thead><tr><th>#</th><th>Họ tên</th><th>Email</th><th>Khóa học</th><th>Bài tập</th><th>Điểm</th><th>Trạng thái</th><th>Ngày nộp</th></tr></thead>
<tbody>
<?php foreach ($data as $i => $r):
    $score = $r['score'];
    $scoreClass = $score === null ? 'score-na' : ((float)$score >= 5 ? 'score-pass' : 'score-fail');
    $scoreText  = $score !== null ? number_format((float)$score, 1) : '—';
    $status = match($r['grading_status']) {
        'ai_graded' => 'AI chấm', 'review_required' => 'Cần GV duyệt',
        'reviewed' => 'GV duyệt', default => 'Chưa chấm',
    };
?>
<tr>
    <td><?php echo $i+1; ?></td>
    <td><?php echo htmlspecialchars($r['name']); ?></td>
    <td style="color:#64748b"><?php echo htmlspecialchars($r['email']); ?></td>
    <td><?php echo htmlspecialchars($r['course_title']); ?></td>
    <td><?php echo htmlspecialchars($r['assignment_title']); ?></td>
    <td class="<?php echo $scoreClass; ?>"><?php echo $scoreText; ?></td>
    <td><?php echo htmlspecialchars($status); ?></td>
    <td><?php echo $r['submitted_at'] ? date('d/m/Y H:i', strtotime($r['submitted_at'])) : '—'; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</body>
</html>
