<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/env.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

$stats=['reminders'=>0,'retried_jobs'=>0,'temp_files_removed'=>0];

$stmt=$pdo->query("SELECT a.id,a.title,a.due_date,ce.student_id FROM assignments a JOIN course_enrollments ce ON ce.course_id=a.course_id WHERE a.due_date BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 24 HOUR) AND NOT EXISTS (SELECT 1 FROM submissions s WHERE s.assignment_id=a.id AND s.student_id=ce.student_id) AND NOT EXISTS (SELECT 1 FROM notifications n WHERE n.user_id=ce.student_id AND n.type='assignment_due_reminder' AND JSON_UNQUOTE(JSON_EXTRACT(n.data_json,'$.assignment_id'))=CAST(a.id AS CHAR))");
foreach($stmt->fetchAll() as $row){createNotification($pdo,(int)$row['student_id'],'assignment_due_reminder','Bài tập sắp hết hạn','“'.$row['title'].'” sẽ hết hạn lúc '.date('d/m/Y H:i',strtotime($row['due_date'])).'.','../student/assignment.php?id='.(int)$row['id'],['assignment_id'=>(int)$row['id']]);$stats['reminders']++;}

$maxAttempts=max(1,(int)envValue('AI_GRADE_MAX_ATTEMPTS','3'));
$retry=$pdo->prepare("UPDATE grading_jobs SET status='queued',available_at=NOW(),locked_at=NULL,worker_token=NULL,started_at=NULL,error_message=NULL WHERE status='failed' AND attempts<?");
$retry->execute([$maxAttempts]);$stats['retried_jobs']=$retry->rowCount();

$tempRoot=realpath(dirname(__DIR__).'/uploads/temp_ai');$cutoff=time()-86400;
if($tempRoot){foreach(new DirectoryIterator($tempRoot) as $file){if($file->isFile()&&!$file->isLink()&&$file->getMTime()<$cutoff&&@unlink($file->getPathname()))$stats['temp_files_removed']++;}}
echo json_encode(['success'=>true,'ran_at'=>date(DATE_ATOM),'stats'=>$stats],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
