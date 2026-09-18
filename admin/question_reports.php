<?php
declare(strict_types=1);

require_once '../includes/security.php';
secureSessionStart();
requireRole(['admin']);
require_once '../config/database.php';
/** @var PDO $pdo */
require_once '../includes/audit.php';
require_once '../includes/quiz_answers.php';

$statusOptions = [
    'open' => 'Mới báo cáo',
    'reviewed' => 'Đang xem xét',
    'resolved' => 'Đã xử lý',
    'dismissed' => 'Không cần xử lý',
];
$reasonLabels = [
    'answer_wrong' => 'Đáp án có thể không đúng',
    'content_unclear' => 'Nội dung câu hỏi chưa rõ',
    'typo' => 'Lỗi chính tả hoặc dữ liệu',
    'image_error' => 'Hình ảnh/định dạng bị lỗi',
    'other' => 'Vấn đề khác',
];
$statusFilter = (string) ($_GET['status'] ?? 'open');
if (!array_key_exists($statusFilter, $statusOptions) && $statusFilter !== 'all') $statusFilter = 'open';
$redirect = static function (string $status): never {
    header('Location: question_reports.php?status=' . rawurlencode($status));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $returnStatus = (string) ($_POST['return_status'] ?? 'open');
    if (!array_key_exists($returnStatus, $statusOptions) && $returnStatus !== 'all') $returnStatus = 'open';
    try {
        $action = (string) ($_POST['action'] ?? '');
        $reportId = filter_input(INPUT_POST, 'report_id', FILTER_VALIDATE_INT);
        if (!$reportId) throw new RuntimeException('Báo cáo không hợp lệ.');
        $reportStmt = $pdo->prepare('SELECT id,question_id FROM quiz_question_reports WHERE id=?');
        $reportStmt->execute([$reportId]);
        $report = $reportStmt->fetch();
        if (!$report) throw new RuntimeException('Không tìm thấy báo cáo này.');

        if ($action === 'save_report_status') {
            $status = (string) ($_POST['status'] ?? '');
            $note = trim((string) ($_POST['admin_note'] ?? ''));
            if (!isset($statusOptions[$status])) throw new RuntimeException('Trạng thái không hợp lệ.');
            if (mb_strlen($note, 'UTF-8') > 2000) throw new RuntimeException('Ghi chú quản trị không được dài quá 2.000 ký tự.');
            $completed = in_array($status, ['resolved', 'dismissed'], true);
            $stmt = $pdo->prepare(
                'UPDATE quiz_question_reports SET status=?,admin_note=?,resolved_by=?,resolved_at=? WHERE id=?'
            );
            $stmt->execute([$status, $note ?: null, $completed ? (int) $_SESSION['user_id'] : null, $completed ? date('Y-m-d H:i:s') : null, $reportId]);
            writeAuditLog($pdo, 'quiz_question_report.status_updated', 'quiz_question_report', $reportId, ['status' => $status]);
            $_SESSION['success'] = 'Đã cập nhật trạng thái báo cáo.';
            $redirect($returnStatus);
        }

        if ($action === 'update_reported_question') {
            $questionText = trim((string) ($_POST['question_text'] ?? ''));
            $options = [];
            foreach (['a', 'b', 'c', 'd'] as $letter) $options[$letter] = trim((string) ($_POST['option_' . $letter] ?? ''));
            $correct = quizNormalizeAnswerOptions($_POST['correct_option'] ?? '');
            $explanation = trim((string) ($_POST['explanation'] ?? ''));
            $note = trim((string) ($_POST['admin_note'] ?? ''));
            if ($questionText === '' || in_array('', $options, true) || $correct === '') {
                throw new RuntimeException('Vui lòng nhập đầy đủ nội dung, bốn đáp án và đáp án đúng.');
            }
            if (mb_strlen($note, 'UTF-8') > 2000) throw new RuntimeException('Ghi chú quản trị không được dài quá 2.000 ký tự.');
            $pdo->beginTransaction();
            $questionUpdate = $pdo->prepare(
                'UPDATE quiz_questions SET question_text=?,option_a=?,option_b=?,option_c=?,option_d=?,correct_option=?,explanation=? WHERE id=?'
            );
            $questionUpdate->execute([$questionText, $options['a'], $options['b'], $options['c'], $options['d'], $correct, $explanation ?: null, (int) $report['question_id']]);
            if (!$questionUpdate->rowCount()) {
                $exists = $pdo->prepare('SELECT 1 FROM quiz_questions WHERE id=?');
                $exists->execute([(int) $report['question_id']]);
                if (!$exists->fetchColumn()) throw new RuntimeException('Câu hỏi không còn tồn tại.');
            }
            $resolved = $pdo->prepare(
                "UPDATE quiz_question_reports SET status='resolved',admin_note=?,resolved_by=?,resolved_at=NOW() WHERE id=?"
            );
            $resolved->execute([$note ?: 'Đã chỉnh sửa câu hỏi.', (int) $_SESSION['user_id'], $reportId]);
            $pdo->commit();
            writeAuditLog($pdo, 'quiz_question_report.question_updated', 'quiz_question_report', $reportId, ['question_id' => (int) $report['question_id'], 'correct_option' => $correct]);
            $_SESSION['success'] = 'Đã sửa câu hỏi và hoàn tất báo cáo.';
            $redirect($returnStatus);
        }
        throw new RuntimeException('Thao tác không hợp lệ.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = $error->getMessage();
        $redirect($returnStatus);
    }
}

$counts = array_fill_keys(array_keys($statusOptions), 0);
foreach ($pdo->query('SELECT status,COUNT(*) total FROM quiz_question_reports GROUP BY status') as $count) {
    if (isset($counts[$count['status']])) $counts[$count['status']] = (int) $count['total'];
}
$where = $statusFilter === 'all' ? '' : 'WHERE r.status=?';
$reportQuery =
    "SELECT r.*,qq.question_text,qq.option_a,qq.option_b,qq.option_c,qq.option_d,qq.correct_option,qq.explanation,
            q.title quiz_title,q.id quiz_id,c.title course_title,c.id course_id,qs.title section_title,
            student.name student_name,student.email student_email,resolver.name resolver_name
     FROM quiz_question_reports r
     JOIN quiz_questions qq ON qq.id=r.question_id
     JOIN quiz_sections qs ON qs.id=qq.section_id
     JOIN quizzes q ON q.id=r.quiz_id
     JOIN courses c ON c.id=q.course_id
     JOIN users student ON student.id=r.student_id
     LEFT JOIN users resolver ON resolver.id=r.resolved_by
     {$where}
     ORDER BY FIELD(r.status,'open','reviewed','resolved','dismissed'),r.created_at DESC LIMIT 150";
$reportStmt = $pdo->prepare($reportQuery);
$reportStmt->execute($statusFilter === 'all' ? [] : [$statusFilter]);
$reports = $reportStmt->fetchAll();

$page_title = 'Báo cáo câu hỏi trắc nghiệm';
require_once '../includes/header.php';
?>
<style>
.report-page{display:grid;gap:16px}.report-hero{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;flex-wrap:wrap;padding:22px;border:1px solid var(--border-color);border-radius:16px;background:linear-gradient(135deg,rgba(var(--primary-rgb),.15),var(--glass-bg))}.report-hero h1{margin:0 0 7px}.report-hero p{margin:0;color:var(--text-muted);max-width:700px}.report-counts{display:flex;gap:8px;flex-wrap:wrap}.report-count{padding:9px 11px;border-radius:10px;background:var(--input-bg);border:1px solid var(--border-color);font-weight:800}.report-count.open{color:#fbbf24}.report-filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.report-filters .btn{min-height:40px}.report-card{padding:17px;border:1px solid var(--border-color);border-radius:14px;background:var(--glass-bg)}.report-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap}.report-head h2{margin:0;font-size:18px}.report-meta{display:flex;gap:7px;flex-wrap:wrap;margin-top:8px;color:var(--text-muted);font-size:13px}.report-chip{padding:5px 8px;border-radius:999px;background:var(--input-bg);border:1px solid var(--border-color);font-weight:700}.report-chip.open{color:#fbbf24}.report-chip.reviewed{color:#60a5fa}.report-chip.resolved{color:#4ade80}.report-chip.dismissed{color:var(--text-muted)}.report-detail{margin:13px 0;padding:11px 13px;border-left:3px solid #fbbf24;border-radius:0 9px 9px 0;background:rgba(245,158,11,.07)}.report-detail strong,.report-detail span{display:block}.report-detail span{margin-top:4px;color:var(--text-muted);white-space:pre-wrap}.report-question-preview{margin-top:13px;padding:13px;border:1px solid var(--border-color);border-radius:11px;background:var(--input-bg)}.report-question-preview ol{margin:10px 0 0;padding-left:24px;color:var(--text-muted)}.report-question-preview li.correct{color:var(--success);font-weight:750}.report-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:13px}.report-actions form{margin:0}.report-edit{margin-top:13px;padding:0;border:1px solid var(--border-color);border-radius:11px;background:rgba(255,255,255,.015)}.report-edit summary{padding:11px 13px;cursor:pointer;font-weight:800}.report-edit-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding:13px;border-top:1px solid var(--border-color)}.report-edit-form label{display:grid;gap:6px;font-size:13px;font-weight:750}.report-edit-form input,.report-edit-form textarea,.report-edit-form select{width:100%;padding:9px 10px;border:1px solid var(--border-color);border-radius:8px;background:var(--input-bg);color:var(--text-main);font:inherit}.report-edit-form textarea{min-height:86px;resize:vertical}.report-edit-form .wide{grid-column:1/-1}.report-edit-buttons{grid-column:1/-1;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}.report-status-form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.report-status-form select,.report-status-form input{min-height:38px;padding:7px 9px;border:1px solid var(--border-color);border-radius:8px;background:var(--input-bg);color:var(--text-main);font:inherit}.report-status-form input{min-width:230px}.report-empty{padding:42px;text-align:center;color:var(--text-muted);border:1px dashed var(--border-color);border-radius:13px}@media(max-width:700px){.report-edit-form{grid-template-columns:1fr}.report-status-form input{min-width:0;width:100%}}
</style>
<div class="report-page">
  <section class="report-hero"><div><h1><i class='bx bx-error-circle'></i> Báo cáo câu hỏi trắc nghiệm</h1><p>Học viên phản ánh câu hỏi có lỗi hoặc chưa rõ. Bạn có thể kiểm tra, sửa trực tiếp và lưu trạng thái xử lý tại đây.</p></div><div class="report-counts"><?php foreach($statusOptions as $key=>$label):?><span class="report-count <?php echo $key;?>"><?php echo $counts[$key];?> <?php echo htmlspecialchars($label);?></span><?php endforeach;?></div></section>
  <?php if(isset($_SESSION['success'])):?><div class="alert alert-success"><?php echo htmlspecialchars((string)$_SESSION['success']);unset($_SESSION['success']);?></div><?php endif;?>
  <?php if(isset($_SESSION['error'])):?><div class="alert alert-danger"><?php echo htmlspecialchars((string)$_SESSION['error']);unset($_SESSION['error']);?></div><?php endif;?>
  <section class="box"><form class="report-filters" method="get"><label for="report-status" style="font-weight:750">Trạng thái</label><select id="report-status" name="status"><?php foreach(['open'=>'Mới báo cáo','reviewed'=>'Đang xem xét','resolved'=>'Đã xử lý','dismissed'=>'Không cần xử lý','all'=>'Tất cả'] as $key=>$label):?><option value="<?php echo $key;?>" <?php echo $statusFilter===$key?'selected':'';?>><?php echo $label;?></option><?php endforeach;?></select><button class="btn btn-primary"><i class='bx bx-filter-alt'></i> Lọc</button></form></section>
  <?php foreach($reports as $report):?>
  <article class="report-card">
    <div class="report-head"><div><h2><?php echo htmlspecialchars($report['course_title']);?> · <?php echo htmlspecialchars($report['quiz_title']);?></h2><div class="report-meta"><span><?php echo htmlspecialchars($report['section_title']);?></span><span>Học viên: <strong><?php echo htmlspecialchars($report['student_name']);?></strong> · <?php echo htmlspecialchars($report['student_email']);?></span><span><?php echo date('d/m/Y H:i',strtotime($report['created_at']));?></span></div></div><span class="report-chip <?php echo htmlspecialchars($report['status']);?>"><?php echo htmlspecialchars($statusOptions[$report['status']]??$report['status']);?></span></div>
    <div class="report-detail"><strong><i class='bx bx-message-error'></i> <?php echo htmlspecialchars($reasonLabels[$report['reason']]??$report['reason']);?></strong><span><?php echo htmlspecialchars($report['details']?:'Học viên chưa ghi mô tả thêm.');?></span></div>
    <div class="report-question-preview"><strong>Câu hỏi hiện tại: <?php echo nl2br(htmlspecialchars($report['question_text']));?></strong><ol type="A"><?php foreach(['a'=>'A','b'=>'B','c'=>'C','d'=>'D'] as $key=>$letter):?><li class="<?php echo in_array($letter,quizAnswerOptions($report['correct_option']),true)?'correct':'';?>"><?php echo htmlspecialchars($report['option_'.$key]);?></li><?php endforeach;?></ol><small class="correct-answer-label" style="display:block;margin-top:9px;color:var(--success)"><strong>Đáp án đúng:</strong> <?php echo htmlspecialchars($report['correct_option']);?></small><?php if($report['explanation']):?><small style="display:block;margin-top:9px;color:var(--text-muted)"><strong>Giải thích:</strong> <?php echo htmlspecialchars($report['explanation']);?></small><?php endif;?></div>
    <div class="report-actions"><a class="btn btn-outline" href="../teacher/quizzes.php?course_id=<?php echo (int)$report['course_id'];?>&amp;quiz_id=<?php echo (int)$report['quiz_id'];?>"><i class='bx bx-link-external'></i> Mở đề</a><form method="post" class="report-status-form"><?php echo csrfField();?><input type="hidden" name="action" value="save_report_status"><input type="hidden" name="report_id" value="<?php echo (int)$report['id'];?>"><input type="hidden" name="return_status" value="<?php echo htmlspecialchars($statusFilter);?>"><select name="status"><?php foreach($statusOptions as $key=>$label):?><option value="<?php echo $key;?>" <?php echo $report['status']===$key?'selected':'';?>><?php echo $label;?></option><?php endforeach;?></select><input name="admin_note" value="<?php echo htmlspecialchars($report['admin_note']??'',ENT_QUOTES,'UTF-8');?>" placeholder="Ghi chú xử lý"><button class="btn btn-outline"><i class='bx bx-save'></i> Lưu trạng thái</button></form></div>
    <details class="report-edit"><summary><i class='bx bx-edit'></i> Sửa câu hỏi và hoàn tất báo cáo</summary><form method="post" class="report-edit-form"><?php echo csrfField();?><input type="hidden" name="action" value="update_reported_question"><input type="hidden" name="report_id" value="<?php echo (int)$report['id'];?>"><input type="hidden" name="return_status" value="<?php echo htmlspecialchars($statusFilter);?>"><label class="wide">Nội dung câu hỏi<textarea name="question_text" required><?php echo htmlspecialchars($report['question_text']);?></textarea></label><?php foreach(['a'=>'A','b'=>'B','c'=>'C','d'=>'D'] as $key=>$letter):?><label>Đáp án <?php echo $letter;?><input name="option_<?php echo $key;?>" value="<?php echo htmlspecialchars($report['option_'.$key],ENT_QUOTES,'UTF-8');?>" required></label><?php endforeach;?><label>Đáp án đúng<select name="correct_option"><?php foreach(['A','B','C','D'] as $letter):?><option value="<?php echo $letter;?>" <?php echo $report['correct_option']===$letter?'selected':'';?>><?php echo $letter;?></option><?php endforeach;?></select></label><label class="wide">Giải thích cho học viên<textarea name="explanation"><?php echo htmlspecialchars($report['explanation']??'');?></textarea></label><label class="wide">Ghi chú xử lý<textarea name="admin_note" placeholder="Ví dụ: Đã đổi đáp án đúng từ B sang C."><?php echo htmlspecialchars($report['admin_note']??'');?></textarea></label><div class="report-edit-buttons"><small style="color:var(--text-muted)">Lưu sẽ cập nhật câu hỏi trong đề và chuyển báo cáo sang “Đã xử lý”.</small><button class="btn btn-primary"><i class='bx bx-check-circle'></i> Lưu câu hỏi &amp; hoàn tất</button></div></form></details>
  </article>
  <?php endforeach;?>
  <?php if(!$reports):?><section class="report-empty"><i class='bx bx-check-double' style="font-size:32px;display:block;margin-bottom:8px"></i>Không có báo cáo câu hỏi phù hợp.</section><?php endif;?>
</div>
<script>
document.querySelectorAll('.report-edit-form select[name="correct_option"]').forEach(select => {
    const label = select.closest('.report-card')?.querySelector('.correct-answer-label');
    const current = label ? label.textContent.replace(/^\s*Đáp án đúng:\s*/i, '').trim() : select.value;
    const selected = new Set(current.split(',').map(value => value.trim()).filter(Boolean));
    const group = document.createElement('span');
    group.style.cssText = 'display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:8px 0';
    ['A','B','C','D'].forEach(letter => {
        const optionLabel = document.createElement('label');
        optionLabel.style.cssText = 'display:inline-flex;grid-auto-flow:column;align-items:center;gap:6px;cursor:pointer';
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.name = 'correct_option[]';
        input.value = letter;
        input.checked = selected.has(letter);
        input.style.width = '18px';
        optionLabel.append(input, document.createTextNode(letter));
        group.appendChild(optionLabel);
    });
    select.replaceWith(group);
});
</script>
<?php require_once '../includes/footer.php';?>
