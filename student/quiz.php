<?php
require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/friendly_urls.php';
/** @var PDO $pdo */
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: ../index.php'); exit;
}
ensureFriendlyUrls($pdo);
$quizSlug=trim((string)($_GET['quiz']??''));
$quizId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'quiz_id', FILTER_VALIDATE_INT);
if($quizSlug!==''){$slugStmt=$pdo->prepare('SELECT id FROM quizzes WHERE slug=?');$slugStmt->execute([$quizSlug]);$quizId=(int)$slugStmt->fetchColumn();}
$studentId = (int) $_SESSION['user_id'];
$deviceHash = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
$stmt = $pdo->prepare('SELECT q.*,c.title course_title,c.slug course_slug FROM quizzes q JOIN courses c ON c.id=q.course_id WHERE q.id=? AND q.is_published=1');
$stmt->execute([$quizId]);
$quiz = $stmt->fetch();
if(!$quiz){http_response_code(404);exit('Bài trắc nghiệm không tồn tại hoặc chưa được mở.');}
if (!empty($quiz['available_from']) && strtotime($quiz['available_from']) > time()) {
    http_response_code(403);
    exit('Bài trắc nghiệm chưa đến thời gian mở.');
}
if (!empty($quiz['available_until']) && strtotime($quiz['available_until']) < time()) {
    http_response_code(403);
    exit('Bài trắc nghiệm đã đóng.');
}

$questionStmt=$pdo->prepare('SELECT q.*,s.title section_title,s.sort_order section_order FROM quiz_questions q JOIN quiz_sections s ON s.id=q.section_id WHERE s.quiz_id=? ORDER BY s.sort_order,s.id,q.sort_order,q.id');
$questionStmt->execute([$quizId]);
$questions=$questionStmt->fetchAll();
if(!$questions){exit('Bài trắc nghiệm chưa có câu hỏi.');}

$attemptId=filter_input(INPUT_GET,'attempt',FILTER_VALIDATE_INT)?:filter_input(INPUT_POST,'attempt_id',FILTER_VALIDATE_INT);
$attempt=null;
if($attemptId){
    $stmt=$pdo->prepare('SELECT * FROM quiz_attempts WHERE id=? AND quiz_id=? AND student_id=?');
    $stmt->execute([$attemptId,$quizId,$studentId]);$attempt=$stmt->fetch();
}
$attemptCountStmt = $pdo->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id=? AND student_id=? AND submitted_at IS NOT NULL');
$attemptCountStmt->execute([$quizId,$studentId]);
$completedAttempts = (int) $attemptCountStmt->fetchColumn();
if(!$attempt){
    if ((int)($quiz['max_attempts']??0) > 0 && $completedAttempts >= (int)$quiz['max_attempts']) {
        http_response_code(403);
        exit('Bạn đã sử dụng hết số lượt làm của bài trắc nghiệm này.');
    }
    $questionOrder = array_map(static fn(array $question): int => (int)$question['id'], $questions);
    if (!empty($quiz['shuffle_questions'])) shuffle($questionOrder);
    $questionLimit = (int)($quiz['question_limit']??0);
    if ($questionLimit > 0) $questionOrder = array_slice($questionOrder, 0, $questionLimit);
    $optionOrder = [];
    foreach ($questionOrder as $questionId) {
        $letters = ['A','B','C','D'];
        if (!empty($quiz['shuffle_options'])) shuffle($letters);
        $optionOrder[(string)$questionId] = $letters;
    }
    $pdo->prepare('INSERT INTO quiz_attempts (quiz_id,student_id,device_hash,question_order,option_order,started_at) VALUES (?,?,?,?,?,NOW())')
        ->execute([
            $quizId,
            $studentId,
            $deviceHash,
            json_encode($questionOrder),
            json_encode($optionOrder),
        ]);
    $attemptId=(int)$pdo->lastInsertId();
    header('Location: '.friendlyUrl('quiz.php','quiz',(string)$quiz['slug']).'&attempt='.$attemptId);exit;
}

if (!empty($quiz['limit_device'])) {
    if (!empty($attempt['device_hash']) && !hash_equals((string)$attempt['device_hash'], $deviceHash)) {
        http_response_code(403);
        exit('Lượt làm bài này đã được mở trên một thiết bị hoặc trình duyệt khác.');
    }
    if (empty($attempt['device_hash'])) {
        $pdo->prepare('UPDATE quiz_attempts SET device_hash=? WHERE id=? AND student_id=?')->execute([$deviceHash,$attemptId,$studentId]);
        $attempt['device_hash']=$deviceHash;
    }
}

$storedQuestionOrder=json_decode($attempt['question_order']??'[]',true)?:[];
$storedOptionOrder=json_decode($attempt['option_order']??'{}',true)?:[];
if($storedQuestionOrder){
    $questionsById=[];
    foreach($questions as $question)$questionsById[(int)$question['id']]=$question;
    $orderedQuestions=[];
    foreach($storedQuestionOrder as $questionId){
        if(isset($questionsById[(int)$questionId]))$orderedQuestions[]=$questionsById[(int)$questionId];
    }
    $questions=$orderedQuestions;
}

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='toggle_pause' && !$attempt['submitted_at']){
    verifyCsrfToken();
    header('Content-Type: application/json; charset=utf-8');
    if($attempt['paused_at']){
        $stmt=$pdo->prepare('UPDATE quiz_attempts SET paused_seconds=paused_seconds+GREATEST(0,TIMESTAMPDIFF(SECOND,paused_at,NOW())),paused_at=NULL WHERE id=? AND student_id=? AND submitted_at IS NULL');
        $stmt->execute([$attemptId,$studentId]);
        echo json_encode(['success'=>true,'paused'=>false],JSON_UNESCAPED_UNICODE);
    }else{
        $stmt=$pdo->prepare('UPDATE quiz_attempts SET paused_at=NOW() WHERE id=? AND student_id=? AND submitted_at IS NULL');
        $stmt->execute([$attemptId,$studentId]);
        echo json_encode(['success'=>true,'paused'=>true],JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='autosave' && !$attempt['submitted_at']){
    verifyCsrfToken();
    $answers=[];
    foreach($questions as $question){
        $answer=strtoupper((string)($_POST['answer'][$question['id']]??''));
        if(in_array($answer,['A','B','C','D'],true))$answers[(string)$question['id']]=$answer;
    }
    $stmt=$pdo->prepare('UPDATE quiz_attempts SET answers=?,last_saved_at=NOW() WHERE id=? AND student_id=? AND submitted_at IS NULL');
    $stmt->execute([json_encode($answers,JSON_UNESCAPED_UNICODE),$attemptId,$studentId]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>true,'saved_at'=>date('H:i:s')],JSON_UNESCAPED_UNICODE);
    exit;
}

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='log_event' && !$attempt['submitted_at']){
    verifyCsrfToken();
    $eventType=(string)($_POST['event_type']??'');
    $allowed=['tab_hidden','fullscreen_exit','offline','online'];
    if(!in_array($eventType,$allowed,true)){http_response_code(422);exit;}
    $pdo->prepare('INSERT INTO quiz_attempt_events (attempt_id,event_type,event_data) VALUES (?,?,?)')->execute([$attemptId,$eventType,json_encode(['at'=>date(DATE_ATOM)],JSON_UNESCAPED_UNICODE)]);
    $column=['tab_hidden'=>'tab_switch_count','fullscreen_exit'=>'fullscreen_exit_count','offline'=>'offline_count'][$eventType]??null;
    if($column)$pdo->prepare("UPDATE quiz_attempts SET {$column}={$column}+1 WHERE id=? AND student_id=?")->execute([$attemptId,$studentId]);
    header('Content-Type: application/json; charset=utf-8');echo json_encode(['success'=>true]);exit;
}

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')!=='toggle_pause' && !$attempt['submitted_at']){
    verifyCsrfToken();
    $answers=[];$correct=0;$earnedPoints=0.0;$totalPoints=0.0;
    foreach($questions as $question){
        $answer=strtoupper((string)($_POST['answer'][$question['id']]??''));
        if(in_array($answer,['A','B','C','D'],true))$answers[(string)$question['id']]=$answer;
        $points=max(0.1,(float)($question['points']??1));
        $totalPoints+=$points;
        if($answer===$question['correct_option']){$correct++;$earnedPoints+=$points;}
    }
    $total=count($questions);$score=$totalPoints>0?round($earnedPoints/$totalPoints*10,2):0;
    $stmt=$pdo->prepare('UPDATE quiz_attempts SET answers=?,correct_count=?,total_questions=?,score=?,submitted_at=NOW() WHERE id=? AND submitted_at IS NULL');
    $stmt->execute([json_encode($answers,JSON_UNESCAPED_UNICODE),$correct,$total,$score,$attemptId]);
    header('Location: '.friendlyUrl('quiz.php','quiz',(string)$quiz['slug']).'&attempt='.$attemptId);exit;
}
$stmt=$pdo->prepare('SELECT * FROM quiz_attempts WHERE id=?');$stmt->execute([$attemptId]);$attempt=$stmt->fetch();
$savedAnswers=json_decode($attempt['answers']??'{}',true)?:[];
$timerReference=$attempt['paused_at']?strtotime($attempt['paused_at']):time();
$elapsedSeconds=max(0,$timerReference-strtotime($attempt['started_at']));
$remaining=max(0,(int)$quiz['duration_minutes']*60+(int)($attempt['paused_seconds']??0)-$elapsedSeconds);
$isPaused=!empty($attempt['paused_at']);
$page_title=$quiz['title'];
require_once '../includes/header.php';
?>
<style>
.quiz-top{position:sticky;top:8px;z-index:40;display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap;margin-bottom:18px}.quiz-clock{font-size:22px;font-weight:700;color:#fbbf24}.quiz-timer-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.quiz-section{margin-bottom:20px}.quiz-question{padding:20px;border:1px solid var(--border-color);border-radius:12px;margin-top:12px}.quiz-options{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px}
.quiz-option{display:flex;align-items:flex-start;gap:12px;min-height:54px;padding:14px;border:1px solid var(--border-color);border-radius:9px;cursor:pointer}.quiz-option input[type="radio"]{width:22px;height:22px;min-width:22px;margin:1px 0 0;accent-color:var(--primary);cursor:pointer}.quiz-option:hover{border-color:var(--primary)}.quiz-option:has(input:checked){border-color:var(--primary);background:rgba(var(--primary-rgb),.1)}.quiz-option.correct{border-color:var(--success);background:rgba(16,185,129,.1)}.quiz-option.wrong{border-color:var(--danger);background:rgba(239,68,68,.1)}
.quiz-question-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:14px}.quiz-question-heading>strong{min-width:0}.quiz-answer-status{flex:0 0 auto;width:34px;height:34px;border-radius:50%;display:grid;place-items:center;font-size:24px;font-weight:800}.quiz-answer-status.correct{color:#fff;background:var(--success);box-shadow:0 0 0 5px rgba(16,185,129,.12)}.quiz-answer-status.wrong{color:#fff;background:var(--danger);box-shadow:0 0 0 5px rgba(239,68,68,.12)}
.quiz-question-image{display:block;max-width:min(100%,520px);max-height:320px;object-fit:contain;margin:14px 0;padding:6px;border-radius:9px;background:#fff}.quiz-option-content{min-width:0}.quiz-option-image{display:block;max-width:180px;max-height:120px;object-fit:contain;margin-top:8px;padding:4px;border-radius:6px;background:#fff}
.quiz-confirm-overlay{position:fixed;inset:0;z-index:2100;display:grid;place-items:center;padding:18px;background:rgba(2,6,23,.62);backdrop-filter:blur(3px)}.quiz-confirm-overlay[hidden]{display:none}.quiz-confirm-dialog{width:min(390px,100%);padding:24px;border:1px solid var(--border-color);border-radius:16px;background:var(--sidebar-bg);box-shadow:0 25px 70px rgba(0,0,0,.42);text-align:center}.quiz-confirm-icon{width:54px;height:54px;margin:0 auto 13px;border-radius:50%;display:grid;place-items:center;background:rgba(245,158,11,.15);color:#fbbf24;font-size:30px}.quiz-confirm-actions{display:flex;justify-content:center;gap:10px;margin-top:20px}
@media(max-width:650px){.quiz-options{grid-template-columns:1fr}.quiz-top .btn{width:100%}}
</style>
<a href="quizzes.php?course_id=<?php echo (int)$quiz['course_id'];?>&amp;category=<?php echo rawurlencode((string)($quiz['category'] ?? 'Chưa phân loại'));?>" style="color:var(--primary)"><i class='bx bx-arrow-back'></i> Danh sách trắc nghiệm</a>
<h1><?php echo htmlspecialchars($quiz['title']);?></h1>
<?php if(!$attempt['submitted_at']):?><div id="quiz-integrity-status" class="box" style="padding:10px 14px;margin-bottom:14px;color:var(--text-muted);font-size:13px"><i class='bx bx-cloud-upload'></i> Đáp án được tự động lưu. Hệ thống đang ghi nhận trạng thái làm bài.</div><?php endif;?>
<?php if($attempt['submitted_at']):?>
<div class="box" style="text-align:center;margin-bottom:20px"><h2>Kết quả</h2><div style="font-size:46px;color:var(--success);font-weight:700"><?php echo htmlspecialchars($attempt['score']);?>/10</div><p>Đúng <?php echo (int)$attempt['correct_count'];?>/<?php echo (int)$attempt['total_questions'];?> câu</p><?php if((int)($quiz['max_attempts']??0)===0||$completedAttempts<(int)$quiz['max_attempts']):?><a class="btn btn-primary" href="<?php echo htmlspecialchars(friendlyUrl('quiz.php','quiz',$quiz['slug']));?>">Làm lại</a><?php else:?><p style="color:var(--text-muted)">Bạn đã dùng hết <?php echo (int)$quiz['max_attempts'];?> lượt làm.</p><?php endif;?></div>
<?php endif;?>
<form method="post" id="quiz-form">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8');?>"><input type="hidden" name="quiz_id" value="<?php echo $quizId;?>"><input type="hidden" name="attempt_id" value="<?php echo $attemptId;?>">
<?php if(!$attempt['submitted_at']):?><div class="box quiz-top"><div><strong><?php echo htmlspecialchars($quiz['course_title']);?></strong><div style="color:var(--text-muted)"><?php echo count($questions);?> câu hỏi</div></div><div class="quiz-timer-actions"><div class="quiz-clock"><i class='bx bx-time'></i> <span id="quiz-time"><?php echo gmdate('H:i:s',$remaining);?></span></div><button type="button" class="btn btn-outline" id="quiz-pause"><i class='bx <?php echo $isPaused?'bx-play':'bx-pause';?>'></i> <span><?php echo $isPaused?'Tiếp tục':'Tạm dừng';?></span></button></div><button type="button" class="btn btn-primary" id="quiz-submit-open">Nộp bài</button></div><?php endif;?>
<section class="box quiz-section"><h2><i class='bx bx-help-circle'></i> Câu hỏi</h2>
<?php $number=0;foreach($questions as $question):$number++;$questionChosen=$savedAnswers[(string)$question['id']]??'';$questionIsCorrect=$questionChosen===$question['correct_option'];?>
<div class="quiz-question">
<div class="quiz-question-heading">
<strong>Câu <?php echo $number;?>. <?php echo htmlspecialchars($question['question_text']);?></strong>
<?php if($attempt['submitted_at']):?>
<span class="quiz-answer-status <?php echo $questionIsCorrect?'correct':'wrong';?>" title="<?php echo $questionIsCorrect?'Trả lời đúng':'Trả lời sai';?>" aria-label="<?php echo $questionIsCorrect?'Trả lời đúng':'Trả lời sai';?>">
    <i class='bx <?php echo $questionIsCorrect?'bx-check':'bx-x';?>'></i>
</span>
<?php endif;?>
</div>
<?php if(!empty($question['question_image'])):?><img class="quiz-question-image" src="../uploads/<?php echo htmlspecialchars($question['question_image']);?>" alt="Hình minh họa câu <?php echo $number;?>"><?php endif;?>
<div class="quiz-options">
<?php $displayOptionOrder=$storedOptionOrder[(string)$question['id']]??['A','B','C','D'];foreach($displayOptionOrder as $letter):$chosen=$questionChosen;$class='';if($attempt['submitted_at']){$class=$letter===$question['correct_option']?'correct':($letter===$chosen?'wrong':'');}?>
<?php $optionImage=$question['option_'.strtolower($letter).'_image']??null;?>
<label class="quiz-option <?php echo $class;?>"><input type="radio" name="answer[<?php echo (int)$question['id'];?>]" value="<?php echo $letter;?>" <?php echo $chosen===$letter?'checked':'';?> <?php echo $attempt['submitted_at']?'disabled':'';?>><span class="quiz-option-content"><strong><?php echo $letter;?>.</strong> <?php echo htmlspecialchars($question['option_'.strtolower($letter)]);?><?php if($optionImage):?><img class="quiz-option-image" src="../uploads/<?php echo htmlspecialchars($optionImage);?>" alt="Hình đáp án <?php echo $letter;?>"><?php endif;?></span></label>
<?php endforeach;?></div>
<?php if($attempt['submitted_at']&&!empty($question['explanation'])):?><p style="margin:12px 0 0;color:var(--text-muted)"><strong>Giải thích:</strong> <?php echo htmlspecialchars($question['explanation']);?></p><?php endif;?>
</div>
<?php endforeach;?></section>
</form>
<?php if(!$attempt['submitted_at']):?>
<div class="quiz-confirm-overlay" id="quiz-confirm-overlay" hidden>
    <div class="quiz-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="quiz-confirm-title">
        <div class="quiz-confirm-icon"><i class='bx bx-help-circle'></i></div>
        <h3 id="quiz-confirm-title" style="margin:0 0 8px">Nộp bài trắc nghiệm?</h3>
        <p style="margin:0;color:var(--text-muted);line-height:1.55">Bạn hãy kiểm tra lại các câu trả lời trước khi nộp. Kết quả sẽ được chấm ngay sau khi xác nhận.</p>
        <div class="quiz-confirm-actions">
            <button type="button" class="btn btn-outline" id="quiz-submit-cancel">Tiếp tục làm</button>
            <button type="button" class="btn btn-primary" id="quiz-submit-confirm"><i class='bx bx-check'></i> Nộp bài</button>
        </div>
    </div>
</div>
<?php endif;?>
<?php if(!$attempt['submitted_at']):?><script>
let quizSeconds=<?php echo $remaining;?>,quizPaused=<?php echo $isPaused?'true':'false';?>;const quizTime=document.getElementById('quiz-time'),quizForm=document.getElementById('quiz-form'),pauseButton=document.getElementById('quiz-pause'),confirmOverlay=document.getElementById('quiz-confirm-overlay');
let quizSaveTimer=null,quizSubmitting=false;
const integrityStatus=document.getElementById('quiz-integrity-status');
function setIntegrityStatus(text,color='var(--text-muted)'){if(integrityStatus){integrityStatus.lastChild.textContent=' '+text;integrityStatus.style.color=color;}}
async function saveQuizAnswers(){if(quizSubmitting)return;const data=new FormData(quizForm);data.set('action','autosave');setIntegrityStatus('Đang lưu đáp án...');try{const response=await fetch(location.href,{method:'POST',body:data,headers:{'X-Requested-With':'XMLHttpRequest'}});if(!response.ok)throw new Error();setIntegrityStatus('Đã lưu đáp án lúc '+new Date().toLocaleTimeString('vi-VN'),'var(--success)');}catch(error){setIntegrityStatus('Mất kết nối, đáp án sẽ được lưu lại khi có mạng.','var(--danger)');}}
async function logQuizEvent(eventType){const data=new FormData();data.set('csrf_token',quizForm.querySelector('[name="csrf_token"]').value);data.set('action','log_event');data.set('quiz_id','<?php echo $quizId;?>');data.set('attempt_id','<?php echo $attemptId;?>');data.set('event_type',eventType);try{await fetch(location.href,{method:'POST',body:data,headers:{'X-Requested-With':'XMLHttpRequest'},keepalive:true});}catch(error){localStorage.setItem('quiz_pending_event_<?php echo $attemptId;?>',eventType);}}
quizForm.addEventListener('change',()=>{clearTimeout(quizSaveTimer);quizSaveTimer=setTimeout(saveQuizAnswers,700);});
setInterval(saveQuizAnswers,15000);
document.addEventListener('visibilitychange',()=>{if(document.hidden)logQuizEvent('tab_hidden');});
window.addEventListener('offline',()=>{logQuizEvent('offline');setIntegrityStatus('Đã mất kết nối Internet. Đáp án vẫn được giữ trên màn hình.','var(--danger)');});
window.addEventListener('online',()=>{logQuizEvent('online');localStorage.removeItem('quiz_pending_event_<?php echo $attemptId;?>');saveQuizAnswers();});
let quizFullscreenEntered=false;
document.addEventListener('fullscreenchange',()=>{if(document.fullscreenElement)quizFullscreenEntered=true;else if(quizFullscreenEntered)logQuizEvent('fullscreen_exit');});
<?php if(!empty($quiz['require_fullscreen'])):?>
const fullscreenButton=document.createElement('button');fullscreenButton.type='button';fullscreenButton.className='btn btn-outline';fullscreenButton.innerHTML="<i class='bx bx-fullscreen'></i> Toàn màn hình";fullscreenButton.addEventListener('click',()=>document.documentElement.requestFullscreen?.());document.querySelector('.quiz-timer-actions')?.appendChild(fullscreenButton);
<?php endif;?>
function drawQuizTime(){const h=String(Math.floor(quizSeconds/3600)).padStart(2,'0'),m=String(Math.floor(quizSeconds%3600/60)).padStart(2,'0'),s=String(quizSeconds%60).padStart(2,'0');quizTime.textContent=`${h}:${m}:${s}`;if(!quizPaused){if(quizSeconds<=0){quizSubmitting=true;quizForm.submit();return;}quizSeconds--;}}
pauseButton?.addEventListener('click',async()=>{pauseButton.disabled=true;const data=new FormData();data.set('csrf_token',quizForm.querySelector('[name="csrf_token"]').value);data.set('action','toggle_pause');data.set('quiz_id','<?php echo $quizId;?>');data.set('attempt_id','<?php echo $attemptId;?>');try{const response=await fetch(location.href,{method:'POST',body:data,headers:{'X-Requested-With':'XMLHttpRequest'}});const result=await response.json();if(!response.ok||!result.success)throw new Error('Không thể cập nhật');quizPaused=result.paused;pauseButton.querySelector('i').className=quizPaused?'bx bx-play':'bx bx-pause';pauseButton.querySelector('span').textContent=quizPaused?'Tiếp tục':'Tạm dừng';}catch(error){alert('Không thể tạm dừng lúc này.');}finally{pauseButton.disabled=false;}});
document.getElementById('quiz-submit-open')?.addEventListener('click',()=>{confirmOverlay.hidden=false;document.getElementById('quiz-submit-confirm')?.focus();});
document.getElementById('quiz-submit-cancel')?.addEventListener('click',()=>{confirmOverlay.hidden=true;});
document.getElementById('quiz-submit-confirm')?.addEventListener('click',()=>{quizSubmitting=true;confirmOverlay.hidden=true;quizForm.submit();});
confirmOverlay?.addEventListener('click',event=>{if(event.target===confirmOverlay)confirmOverlay.hidden=true;});
document.addEventListener('keydown',event=>{if(event.key==='Escape'&&confirmOverlay&&!confirmOverlay.hidden)confirmOverlay.hidden=true;});
drawQuizTime();setInterval(drawQuizTime,1000);
</script><?php endif;?>
<?php require_once '../includes/footer.php';?>
