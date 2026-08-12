<?php
declare(strict_types=1);
require_once '../includes/security.php';
secureSessionStart();
requireRole(['teacher','admin']);
require_once '../config/database.php';
$quizId=(int)($_GET['id']??0);$isAdmin=($_SESSION['user_role']??'')==='admin';
$stmt=$pdo->prepare('SELECT q.*,c.title course_title FROM quizzes q JOIN courses c ON c.id=q.course_id WHERE q.id=?'.($isAdmin?'':' AND q.teacher_id=?'));
$stmt->execute($isAdmin?[$quizId]:[$quizId,(int)$_SESSION['user_id']]);$quiz=$stmt->fetch();
if(!$quiz){http_response_code(404);exit('Không tìm thấy đề thi.');}
$stmt=$pdo->prepare('SELECT qq.*,qs.title section_title FROM quiz_questions qq JOIN quiz_sections qs ON qs.id=qq.section_id WHERE qs.quiz_id=? ORDER BY qs.sort_order,qs.id,qq.sort_order,qq.id');$stmt->execute([$quizId]);$questions=$stmt->fetchAll();
$page_title='Xem trước: '.$quiz['title'];require_once '../includes/header.php';
?>
<style>.preview-head{display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap}.preview-question{padding:18px;margin-top:14px;border:1px solid var(--border-color);border-radius:13px;background:var(--input-bg)}.preview-options{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:12px}.preview-option{padding:11px 13px;border:1px solid var(--border-color);border-radius:9px}.preview-option.correct{border-color:var(--success);color:var(--success);background:rgba(16,185,129,.08)}@media(max-width:650px){.preview-options{grid-template-columns:1fr}}</style>
<div class="preview-head"><div><a href="quizzes.php?course_id=<?php echo (int)$quiz['course_id'];?>&quiz_id=<?php echo $quizId;?>" style="color:var(--primary)"><i class='bx bx-arrow-back'></i> Quay lại quản lý</a><h1><?php echo htmlspecialchars($quiz['title']);?></h1><p style="color:var(--text-muted)"><?php echo htmlspecialchars($quiz['course_title']);?> · <?php echo count($questions);?> câu · Điểm đạt <?php echo htmlspecialchars((string)($quiz['passing_score']??5));?>/10</p></div><span class="btn <?php echo $quiz['is_published']?'btn-primary':'btn-outline';?>"><?php echo $quiz['is_published']?'Đang mở':'Bản nháp';?></span></div>
<section class="box"><h2><i class='bx bx-show'></i> Nội dung đề</h2><?php foreach($questions as $index=>$question):?><article class="preview-question"><strong>Câu <?php echo $index+1;?>. <?php echo nl2br(htmlspecialchars($question['question_text']));?></strong><div class="preview-options"><?php foreach(['A','B','C','D'] as $letter):?><div class="preview-option <?php echo $question['correct_option']===$letter?'correct':'';?>"><strong><?php echo $letter;?>.</strong> <?php echo htmlspecialchars($question['option_'.strtolower($letter)]);?></div><?php endforeach;?></div></article><?php endforeach;?><?php if(!$questions):?><p style="color:var(--text-muted)">Đề chưa có câu hỏi.</p><?php endif;?></section>
<?php require_once '../includes/footer.php';?>
