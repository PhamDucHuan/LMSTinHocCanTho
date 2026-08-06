<?php
declare(strict_types=1);
require_once '../includes/security.php';
secureSessionStart();
requireRole(['teacher','admin']);
require_once '../config/database.php';
require_once '../includes/question_bank.php';
require_once '../includes/quiz_schema.php';
require_once '../includes/quiz_import.php';
require_once '../includes/friendly_urls.php';
require_once '../includes/audit.php';
ensureQuizSchema($pdo);
ensureQuestionBankSchema($pdo);

$actorId = (int) $_SESSION['user_id'];
$isAdmin = ($_SESSION['user_role'] ?? '') === 'admin';
$ownerId = $isAdmin ? max(0, (int) ($_REQUEST['teacher_id'] ?? 0)) : $actorId;
$ownerCondition = $ownerId > 0 ? 'qb.teacher_id=?' : '1=1';
$ownerParams = $ownerId > 0 ? [$ownerId] : [];

if (($_GET['action'] ?? '') === 'export') {
    $stmt = $pdo->prepare("SELECT qt.name topic,qb.difficulty,qb.question_text,qb.option_a,qb.option_b,qb.option_c,qb.option_d,qb.correct_option FROM question_bank qb LEFT JOIN question_topics qt ON qt.id=qb.topic_id WHERE {$ownerCondition} ORDER BY qt.name,qb.difficulty,qb.id");
    $stmt->execute($ownerParams);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ngan-hang-cau-hoi-' . date('Ymd-His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    fputcsv($output, ['Chủ đề','Mức độ','Câu hỏi','Đáp án A','Đáp án B','Đáp án C','Đáp án D','Đáp án đúng']);
    foreach ($stmt->fetchAll() as $row) fputcsv($output, array_values($row));
    fclose($output);
    exit;
}

$redirect = static function (): never {
    $questionId = max(0, (int) ($_POST['question_id'] ?? 0));
    header('Location: question_bank.php' . ($questionId > 0 ? '#question-' . $questionId : ''));
    exit;
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'create_topic') {
            $topicName = trim((string) ($_POST['topic_name'] ?? ''));
            $topicOwner = $isAdmin ? max(1, (int) ($_POST['teacher_id'] ?? 0)) : $actorId;
            if ($topicName === '') throw new RuntimeException('Vui lòng nhập tên chủ đề.');
            $stmt = $pdo->prepare('INSERT INTO question_topics (teacher_id,name) VALUES (?,?)');
            try { $stmt->execute([$topicOwner, $topicName]); }
            catch (PDOException $error) { throw new RuntimeException('Chủ đề này đã tồn tại.'); }
            writeAuditLog($pdo, 'question_topic.created', 'question_topic', (int) $pdo->lastInsertId(), ['name' => $topicName]);
            $_SESSION['success'] = 'Đã tạo chủ đề “' . $topicName . '”.';
        } elseif ($action === 'delete_topic') {
            $topicId = (int) ($_POST['topic_id'] ?? 0);
            $ownerSql = $isAdmin ? 'SELECT id,name FROM question_topics WHERE id=?' : 'SELECT id,name FROM question_topics WHERE id=? AND teacher_id=?';
            $topicStmt = $pdo->prepare($ownerSql);
            $topicStmt->execute($isAdmin ? [$topicId] : [$topicId,$actorId]);
            $topicRow = $topicStmt->fetch();
            if (!$topicRow) throw new RuntimeException('Chủ đề không tồn tại hoặc bạn không có quyền xóa.');
            $usedStmt = $pdo->prepare('SELECT COUNT(*) FROM question_bank WHERE topic_id=?');
            $usedStmt->execute([$topicId]);
            if ((int) $usedStmt->fetchColumn() > 0) throw new RuntimeException('Không thể xóa chủ đề đang có câu hỏi. Hãy chuyển hoặc xóa các câu hỏi trước.');
            $pdo->prepare('DELETE FROM question_topics WHERE id=?')->execute([$topicId]);
            writeAuditLog($pdo, 'question_topic.deleted', 'question_topic', $topicId, ['name' => $topicRow['name']]);
            $_SESSION['success'] = 'Đã xóa chủ đề.';
        } elseif ($action === 'save_question') {
            $question = [];
            foreach (['question_text','option_a','option_b','option_c','option_d'] as $field) $question[$field] = trim((string) ($_POST[$field] ?? ''));
            if (in_array('', $question, true)) throw new RuntimeException('Vui lòng nhập đầy đủ câu hỏi và bốn đáp án.');
            $correct = strtoupper(trim((string) ($_POST['correct_option'] ?? '')));
            if (!in_array($correct, ['A','B','C','D'], true)) throw new RuntimeException('Đáp án đúng không hợp lệ.');
            $topic = trim((string) ($_POST['topic'] ?? 'Chưa phân loại'));
            $difficulty = in_array($_POST['difficulty'] ?? '', ['easy','medium','hard'], true) ? $_POST['difficulty'] : 'medium';
            $questionOwner = $isAdmin ? max(1, (int) ($_POST['teacher_id'] ?? $actorId)) : $actorId;
            $pdo->prepare('INSERT INTO question_topics (teacher_id,name) VALUES (?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)')->execute([$questionOwner,$topic]);
            $topicId = (int) $pdo->lastInsertId();
            $fingerprint = questionFingerprint($question);
            $duplicate = $pdo->prepare('SELECT id FROM question_bank WHERE teacher_id=? AND fingerprint=? LIMIT 1');
            $duplicate->execute([$questionOwner,$fingerprint]);
            if ($duplicate->fetchColumn()) throw new RuntimeException('Câu hỏi này trùng hoặc gần như trùng với câu đã có trong ngân hàng.');
            $stmt=$pdo->prepare('INSERT INTO question_bank (teacher_id,topic_id,difficulty,question_text,option_a,option_b,option_c,option_d,correct_option,fingerprint) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$questionOwner,$topicId,$difficulty,$question['question_text'],$question['option_a'],$question['option_b'],$question['option_c'],$question['option_d'],$correct,$fingerprint]);
            writeAuditLog($pdo,'question_bank.created','question_bank',(int)$pdo->lastInsertId(),['topic'=>$topic,'difficulty'=>$difficulty]);
            $_SESSION['success']='Đã thêm câu hỏi vào ngân hàng.';
        } elseif ($action === 'update_question') {
            $id = (int) ($_POST['question_id'] ?? 0);
            $questionSql = $isAdmin
                ? 'SELECT * FROM question_bank WHERE id=?'
                : 'SELECT * FROM question_bank WHERE id=? AND teacher_id=?';
            $questionStmt = $pdo->prepare($questionSql);
            $questionStmt->execute($isAdmin ? [$id] : [$id, $actorId]);
            $existingQuestion = $questionStmt->fetch();
            if (!$existingQuestion) throw new RuntimeException('Câu hỏi không tồn tại hoặc bạn không có quyền sửa.');

            $question = [];
            foreach (['question_text','option_a','option_b','option_c','option_d'] as $field) {
                $question[$field] = trim((string) ($_POST[$field] ?? ''));
            }
            if (in_array('', $question, true)) throw new RuntimeException('Vui lòng nhập đầy đủ câu hỏi và bốn đáp án.');
            $correct = strtoupper(trim((string) ($_POST['correct_option'] ?? '')));
            if (!in_array($correct, ['A','B','C','D'], true)) throw new RuntimeException('Đáp án đúng không hợp lệ.');
            $difficulty = in_array($_POST['difficulty'] ?? '', ['easy','medium','hard'], true) ? $_POST['difficulty'] : 'medium';
            $topicId = (int) ($_POST['topic_id'] ?? 0);
            $topicStmt = $pdo->prepare('SELECT id FROM question_topics WHERE id=? AND teacher_id=?');
            $topicStmt->execute([$topicId, (int) $existingQuestion['teacher_id']]);
            if (!$topicStmt->fetchColumn()) throw new RuntimeException('Chủ đề được chọn không hợp lệ.');

            $fingerprint = questionFingerprint($question);
            $duplicate = $pdo->prepare('SELECT id FROM question_bank WHERE teacher_id=? AND fingerprint=? AND id<>? LIMIT 1');
            $duplicate->execute([(int) $existingQuestion['teacher_id'], $fingerprint, $id]);
            $duplicateId = (int) $duplicate->fetchColumn();
            if ($duplicateId > 0) throw new RuntimeException("Không thể lưu vì nội dung sau khi sửa trùng hoàn toàn với câu #{$duplicateId}. Câu đúng đã có sẵn; bạn có thể xóa bản đang bị lỗi này.");

            $pdo->beginTransaction();
            $versionStmt = $pdo->prepare('SELECT COALESCE(MAX(version_number),0)+1 FROM question_bank_versions WHERE question_id=?');
            $versionStmt->execute([$id]);
            $pdo->prepare('INSERT INTO question_bank_versions (question_id,version_number,changed_by,snapshot_json) VALUES (?,?,?,?)')->execute([
                $id,
                (int) $versionStmt->fetchColumn(),
                $actorId,
                json_encode($existingQuestion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            $stmt = $pdo->prepare('UPDATE question_bank SET topic_id=?,difficulty=?,question_text=?,option_a=?,option_b=?,option_c=?,option_d=?,correct_option=?,fingerprint=? WHERE id=?');
            $stmt->execute([$topicId,$difficulty,$question['question_text'],$question['option_a'],$question['option_b'],$question['option_c'],$question['option_d'],$correct,$fingerprint,$id]);
            $syncStmt = $pdo->prepare('UPDATE quiz_questions SET question_text=?,option_a=?,option_b=?,option_c=?,option_d=?,correct_option=?,difficulty=? WHERE source_question_id=?');
            $syncStmt->execute([$question['question_text'],$question['option_a'],$question['option_b'],$question['option_c'],$question['option_d'],$correct,$difficulty,$id]);
            $pdo->commit();
            writeAuditLog($pdo, 'question_bank.updated', 'question_bank', $id, [
                'before' => ['topic_id'=>$existingQuestion['topic_id'],'difficulty'=>$existingQuestion['difficulty'],'correct_option'=>$existingQuestion['correct_option']],
                'after' => ['topic_id'=>$topicId,'difficulty'=>$difficulty,'correct_option'=>$correct],
            ]);
            $_SESSION['success'] = 'Đã cập nhật câu hỏi và đồng bộ ' . $syncStmt->rowCount() . ' câu trong các đề đã tạo.';
        } elseif ($action === 'restore_question_version') {
            $id = (int) ($_POST['question_id'] ?? 0);
            $versionId = (int) ($_POST['version_id'] ?? 0);
            $ownerClause = $isAdmin ? '' : ' AND qb.teacher_id=' . $actorId;
            $stmt = $pdo->prepare('SELECT qbv.*,qb.teacher_id FROM question_bank_versions qbv JOIN question_bank qb ON qb.id=qbv.question_id WHERE qbv.id=? AND qb.id=?' . $ownerClause);
            $stmt->execute([$versionId,$id]);
            $version = $stmt->fetch();
            $snapshot = $version ? json_decode((string)$version['snapshot_json'], true) : null;
            if (!$version || !is_array($snapshot)) throw new RuntimeException('Phiên bản câu hỏi không hợp lệ.');
            $fields = ['topic_id','difficulty','question_text','option_a','option_b','option_c','option_d','correct_option','fingerprint'];
            foreach ($fields as $field) if (!array_key_exists($field,$snapshot)) throw new RuntimeException('Dữ liệu phiên bản không đầy đủ.');
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE question_bank SET topic_id=?,difficulty=?,question_text=?,option_a=?,option_b=?,option_c=?,option_d=?,correct_option=?,fingerprint=? WHERE id=?')->execute([$snapshot['topic_id'],$snapshot['difficulty'],$snapshot['question_text'],$snapshot['option_a'],$snapshot['option_b'],$snapshot['option_c'],$snapshot['option_d'],$snapshot['correct_option'],$snapshot['fingerprint'],$id]);
            $pdo->prepare('UPDATE quiz_questions SET question_text=?,option_a=?,option_b=?,option_c=?,option_d=?,correct_option=?,difficulty=? WHERE source_question_id=?')->execute([$snapshot['question_text'],$snapshot['option_a'],$snapshot['option_b'],$snapshot['option_c'],$snapshot['option_d'],$snapshot['correct_option'],$snapshot['difficulty'],$id]);
            $pdo->commit();
            writeAuditLog($pdo,'question_bank.version_restored','question_bank',$id,['version_id'=>$versionId]);
            $_SESSION['success']='Đã khôi phục phiên bản câu hỏi và đồng bộ các đề liên quan.';
        } elseif ($action === 'delete_question') {
            $id=(int)($_POST['question_id']??0);
            $sql=$isAdmin?'DELETE FROM question_bank WHERE id=?':'DELETE FROM question_bank WHERE id=? AND teacher_id=?';
            $pdo->prepare($sql)->execute($isAdmin?[$id]:[$id,$actorId]);
            writeAuditLog($pdo,'question_bank.deleted','question_bank',$id);
            $_SESSION['success']='Đã xóa câu hỏi.';
        } elseif ($action === 'import') {
            $selectedTopicId=(int)($_POST['import_topic_id']??0);
            $topicSql=$isAdmin?'SELECT id,name,teacher_id FROM question_topics WHERE id=?':'SELECT id,name,teacher_id FROM question_topics WHERE id=? AND teacher_id=?';
            $topicStmt=$pdo->prepare($topicSql);$topicStmt->execute($isAdmin?[$selectedTopicId]:[$selectedTopicId,$actorId]);$selectedTopic=$topicStmt->fetch();
            if(!$selectedTopic) throw new RuntimeException('Vui lòng chọn một chủ đề hợp lệ trước khi nhập file.');
            $questionOwner=(int)$selectedTopic['teacher_id'];
            $importDifficulty=(string)($_POST['import_difficulty']??'medium');
            if(!in_array($importDifficulty,['easy','medium','hard','from_file'],true))$importDifficulty='medium';
            $files=$_FILES['question_files']??null;
            if(!$files||!is_array($files['name']??null)||count(array_filter($files['name']))===0) throw new RuntimeException('Vui lòng chọn ít nhất một file CSV hoặc XLSX.');
            $added=0;$duplicates=0;$fileCount=0;
            $pdo->beginTransaction();
            foreach($files['name'] as $fileIndex=>$fileName){
                if(($files['error'][$fileIndex]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('Không thể tải file “'.basename((string)$fileName).'”.');
                $extension=strtolower(pathinfo((string)$fileName,PATHINFO_EXTENSION));
                if(!in_array($extension,['csv','xlsx'],true)) throw new RuntimeException('File “'.basename((string)$fileName).'” không phải CSV hoặc XLSX.');
                $rows=readQuizImportRows($files['tmp_name'][$fileIndex],$extension);$fileCount++;
                $firstHeader=mb_strtolower(trim((string)($rows[0][0]??'')),'UTF-8');
                $format=str_contains($firstHeader,'chủ đề')||str_contains($firstHeader,'chu de')||$firstHeader==='topic'?'eight':(str_contains($firstHeader,'mức độ')||str_contains($firstHeader,'muc do')||$firstHeader==='difficulty'?'seven':(str_contains($firstHeader,'câu hỏi')||str_contains($firstHeader,'cau hoi')||$firstHeader==='question'?'six':'auto'));
                $hasHeader=$format!=='auto';
                foreach($rows as $index=>$row){ if($index===0&&$hasHeader)continue; unset($row['__images']);$values=array_map(fn($v)=>trim((string)$v),array_values($row));
                    $rowFormat=$format;
                    if($rowFormat==='auto'){$rowFormat=in_array(strtoupper($values[7]??''),['A','B','C','D'],true)?'eight':(in_array(strtoupper($values[6]??''),['A','B','C','D'],true)?'seven':'six');}
                    if($rowFormat==='eight'){[$difficulty,$text,$a,$b,$c,$d,$correct]=array_slice($values,1,7);}
                    elseif($rowFormat==='seven'){[$difficulty,$text,$a,$b,$c,$d,$correct]=array_slice($values,0,7);}
                    else{$difficulty='medium';[$text,$a,$b,$c,$d,$correct]=array_slice($values,0,6);}
                    $difficultyMap=['dễ'=>'easy','de'=>'easy','easy'=>'easy','khó'=>'hard','kho'=>'hard','hard'=>'hard'];
                    $difficulty=$difficultyMap[mb_strtolower($difficulty,'UTF-8')]??'medium'; $correct=strtoupper($correct);
                    if($importDifficulty!=='from_file')$difficulty=$importDifficulty;
                    if($text===''||!in_array($correct,['A','B','C','D'],true))continue;
                    $question=['question_text'=>$text,'option_a'=>$a,'option_b'=>$b,'option_c'=>$c,'option_d'=>$d]; $fingerprint=questionFingerprint($question);
                    $check=$pdo->prepare('SELECT 1 FROM question_bank WHERE teacher_id=? AND fingerprint=?');$check->execute([$questionOwner,$fingerprint]);if($check->fetchColumn()){$duplicates++;continue;}
                    $pdo->prepare('INSERT INTO question_bank (teacher_id,topic_id,difficulty,question_text,option_a,option_b,option_c,option_d,correct_option,fingerprint) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$questionOwner,$selectedTopicId,$difficulty,$text,$a,$b,$c,$d,$correct,$fingerprint]);$added++;
                }
            }
            if ($added === 0 && $duplicates === 0) throw new RuntimeException('Không tìm thấy câu hỏi hợp lệ. Hệ thống hỗ trợ mẫu 6 cột (Câu hỏi, A, B, C, D, Đáp án đúng), 7 cột có Mức độ hoặc 8 cột cũ có Chủ đề.');
            $pdo->commit(); writeAuditLog($pdo,'question_bank.imported','question_bank',null,['topic_id'=>$selectedTopicId,'difficulty'=>$importDifficulty,'files'=>$fileCount,'added'=>$added,'duplicates'=>$duplicates]);
            $difficultyNotice=$importDifficulty==='from_file'?'theo mức độ trong file':questionDifficultyLabel($importDifficulty);
            $_SESSION['success']="Đã đọc {$fileCount} file và nhập {$added} câu mức {$difficultyNotice} vào chủ đề “{$selectedTopic['name']}”; bỏ qua {$duplicates} câu trùng.";
        } elseif ($action === 'generate_quiz') {
            $courseId=(int)($_POST['course_id']??0);$count=max(1,min(200,(int)($_POST['question_count']??50)));$topicId=(int)($_POST['topic_id']??0);$difficulty=(string)($_POST['difficulty_filter']??'all');$title=trim((string)($_POST['quiz_title']??''));
            $difficultyCounts=['easy'=>max(0,(int)($_POST['easy_count']??0)),'medium'=>max(0,(int)($_POST['medium_count']??0)),'hard'=>max(0,(int)($_POST['hard_count']??0))];
            $distributionTotal=array_sum($difficultyCounts);if($distributionTotal>200)throw new RuntimeException('Tổng số câu theo mức độ không được vượt quá 200.');
            $courseSql=$isAdmin?'SELECT * FROM courses WHERE id=?':'SELECT * FROM courses WHERE id=? AND teacher_id=?';$courseStmt=$pdo->prepare($courseSql);$courseStmt->execute($isAdmin?[$courseId]:[$courseId,$actorId]);$course=$courseStmt->fetch();if(!$course)throw new RuntimeException('Khóa học không hợp lệ.');
            $bankOwner = $isAdmin ? max(1, (int) ($_POST['bank_teacher_id'] ?? $actorId)) : (int) $course['teacher_id'];
            $baseConditions=['teacher_id=?'];$baseParams=[$bankOwner];if($topicId){$baseConditions[]='topic_id=?';$baseParams[]=$topicId;}$selected=[];
            if($distributionTotal>0){foreach($difficultyCounts as $level=>$levelCount){if($levelCount===0)continue;$levelRows=selectQuestionBankForCourse($pdo,$courseId,[...$baseConditions,'difficulty=?'],[...$baseParams,$level],$levelCount);if(count($levelRows)<$levelCount)throw new RuntimeException('Không đủ câu mức '.questionDifficultyLabel($level).'. Hiện có '.count($levelRows).' câu.');$selected=array_merge($selected,$levelRows);}$count=$distributionTotal;shuffle($selected);}else{$conditions=$baseConditions;$params=$baseParams;if(in_array($difficulty,['easy','medium','hard'],true)){$conditions[]='difficulty=?';$params[]=$difficulty;}$selected=selectQuestionBankForCourse($pdo,$courseId,$conditions,$params,$count);if(count($selected)<$count)throw new RuntimeException('Ngân hàng không đủ câu hỏi phù hợp. Hiện có '.count($selected).' câu.');}
            $pdo->beginTransaction();$title=$title?:'Đề ngẫu nhiên '.date('d/m/Y H:i');$quizOrderStmt=$pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM quizzes WHERE course_id=?');$quizOrderStmt->execute([$courseId]);$quizSortOrder=(int)$quizOrderStmt->fetchColumn();$pdo->prepare('INSERT INTO quizzes (course_id,teacher_id,title,slug,description,duration_minutes,shuffle_questions,shuffle_options,is_published,sort_order) VALUES (?,?,?,?,?,40,1,1,1,?)')->execute([$courseId,(int)$course['teacher_id'],$title,uniqueFriendlySlug($pdo,'quizzes',$title),'Tạo ngẫu nhiên từ ngân hàng câu hỏi',$quizSortOrder]);$quizId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO quiz_sections (quiz_id,title,sort_order) VALUES (?,'Câu hỏi ngẫu nhiên',1)")->execute([$quizId]);$sectionId=(int)$pdo->lastInsertId();
            $reusedQuestionCount=0;$insert=$pdo->prepare('INSERT INTO quiz_questions (section_id,source_question_id,question_text,option_a,option_b,option_c,option_d,correct_option,sort_order) VALUES (?,?,?,?,?,?,?,?,?)');$usage=$pdo->prepare('UPDATE question_bank SET usage_count=usage_count+1 WHERE id=?');foreach($selected as $i=>$q){if((int)($q['course_usage_count']??0)>0)$reusedQuestionCount++;$insert->execute([$sectionId,$q['id'],$q['question_text'],$q['option_a'],$q['option_b'],$q['option_c'],$q['option_d'],$q['correct_option'],$i+1]);$usage->execute([$q['id']]);}$pdo->commit();writeAuditLog($pdo,'quiz.generated_from_bank','quiz',$quizId,['question_count'=>$count,'reused_in_course'=>$reusedQuestionCount,'new_to_course'=>$count-$reusedQuestionCount,'shuffle_questions'=>true,'shuffle_options'=>true,'is_published'=>true]);$_SESSION['success']='Đã tạo và mở đề: '.($count-$reusedQuestionCount).' câu chưa từng có trong đề khác của khóa học'.($reusedQuestionCount>0?', '.$reusedQuestionCount.' câu ít trùng nhất được dùng bổ sung':'').'.';
        }
    } catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();$_SESSION['error']=$error->getMessage();$failedQuestionId=(int)($_POST['question_id']??0);if(($_POST['action']??'')==='update_question'&&$failedQuestionId>0)$_SESSION['question_errors'][$failedQuestionId]=$error->getMessage();}
    $redirect();
}

$topicsSql='SELECT qt.id,qt.teacher_id,qt.name,qt.created_at,u.name teacher_name,COUNT(qb.id) question_count FROM question_topics qt LEFT JOIN users u ON u.id=qt.teacher_id LEFT JOIN question_bank qb ON qb.topic_id=qt.id';
if(!($isAdmin&&$ownerId===0))$topicsSql.=' WHERE qt.teacher_id=?';
$topicsSql.=' GROUP BY qt.id,qt.teacher_id,qt.name,qt.created_at,u.name ORDER BY qt.name';
$topicsStmt=$pdo->prepare($topicsSql);$topicsStmt->execute($isAdmin&&$ownerId===0?[]:[$ownerId?:$actorId]);$topics=$topicsStmt->fetchAll();
$search=trim((string)($_GET['q']??''));$topicFilter=(int)($_GET['topic_id']??0);$difficultyFilter=(string)($_GET['difficulty']??'');$conditions=[$ownerCondition];$params=$ownerParams;if($search!==''){$conditions[]='qb.question_text LIKE ?';$params[]='%'.$search.'%';}if($topicFilter){$conditions[]='qb.topic_id=?';$params[]=$topicFilter;}if(in_array($difficultyFilter,['easy','medium','hard'],true)){$conditions[]='qb.difficulty=?';$params[]=$difficultyFilter;}
$whereSql=implode(' AND ',$conditions);$countStmt=$pdo->prepare('SELECT COUNT(*) FROM question_bank qb WHERE '.$whereSql);$countStmt->execute($params);$totalQuestions=(int)$countStmt->fetchColumn();$perPage=50;$totalPages=max(1,(int)ceil($totalQuestions/$perPage));$currentPage=max(1,min($totalPages,(int)($_GET['page']??1)));$offset=($currentPage-1)*$perPage;
$stmt=$pdo->prepare('SELECT qb.*,qt.name topic_name,u.name teacher_name FROM question_bank qb LEFT JOIN question_topics qt ON qt.id=qb.topic_id LEFT JOIN users u ON u.id=qb.teacher_id WHERE '.$whereSql.' ORDER BY qb.updated_at DESC LIMIT '.$perPage.' OFFSET '.$offset);$stmt->execute($params);$questions=$stmt->fetchAll();$questionErrors=is_array($_SESSION['question_errors']??null)?$_SESSION['question_errors']:[];unset($_SESSION['question_errors']);
$questionVersions=[];
if($questions){$questionIds=array_map('intval',array_column($questions,'id'));$versionStmt=$pdo->query('SELECT id,question_id,version_number,created_at FROM question_bank_versions WHERE question_id IN ('.implode(',',$questionIds).') ORDER BY question_id,version_number DESC');foreach($versionStmt->fetchAll() as $version)$questionVersions[(int)$version['question_id']][]=$version;}
$pageUrl=static function(int $page):string{$query=$_GET;unset($query['action']);$query['page']=$page;return '?'.http_build_query($query);};
$coursesStmt=$isAdmin?$pdo->query('SELECT id,title FROM courses ORDER BY title'):$pdo->prepare('SELECT id,title FROM courses WHERE teacher_id=? ORDER BY title');if(!$isAdmin)$coursesStmt->execute([$actorId]);$courses=$coursesStmt->fetchAll();$teachers=$isAdmin?$pdo->query("SELECT id,name,role FROM users WHERE role IN ('teacher','admin') ORDER BY FIELD(role,'admin','teacher'),name")->fetchAll():[];
$page_title='Ngân hàng câu hỏi';require_once '../includes/header.php';
?>
<style>
.bank-grid{display:grid;grid-template-columns:380px 1fr;gap:20px}.bank-panel{background:var(--glass-bg);border:1px solid var(--border-color);border-radius:16px;padding:20px}.bank-panel h2{margin-top:0}.bank-form{display:grid;gap:14px}.bank-form input,.bank-form select,.bank-form textarea,.bank-actions input,.bank-actions select{width:100%;min-height:54px;padding:13px 16px;border:1px solid var(--border-color);border-radius:12px;background:var(--input-bg);color:var(--text-main);font:inherit;line-height:1.35;outline:none;transition:border-color .2s ease,box-shadow .2s ease,background .2s ease}.bank-form textarea{min-height:110px;resize:vertical}.bank-form input::placeholder,.bank-form textarea::placeholder,.bank-actions input::placeholder{color:var(--text-muted);opacity:.9}.bank-form input:hover,.bank-form select:hover,.bank-form textarea:hover,.bank-actions input:hover,.bank-actions select:hover{border-color:rgba(var(--primary-rgb),.5)}.bank-form input:focus,.bank-form select:focus,.bank-form textarea:focus,.bank-actions input:focus,.bank-actions select:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(var(--primary-rgb),.16);background:rgba(15,23,42,.72)}.bank-form select,.bank-actions select{cursor:pointer;color-scheme:dark}.bank-form input[type=file]{padding:8px 10px;cursor:pointer;color:var(--text-muted)}.bank-form input[type=file]::file-selector-button{height:36px;margin-right:12px;padding:0 15px;border:0;border-radius:9px;background:rgba(var(--primary-rgb),.16);color:var(--primary);font:inherit;font-weight:700;cursor:pointer;transition:.2s}.bank-form input[type=file]::file-selector-button:hover{background:var(--primary);color:#fff}.bank-form .btn{min-height:48px;justify-content:center}.bank-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.bank-actions>input{flex:1 1 260px}.bank-actions>select{flex:0 1 210px}.bank-actions>.btn{min-height:50px}.question-card{padding:16px;border:1px solid var(--border-color);border-radius:14px;margin-bottom:12px;background:var(--input-bg)}.question-meta{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px}.question-meta span{font-size:12px;padding:4px 9px;border-radius:999px;background:rgba(var(--primary-rgb),.15);color:var(--primary)}@media(max-width:1000px){.bank-grid{grid-template-columns:1fr}}@media(max-width:600px){.bank-panel{padding:16px}.bank-actions>input,.bank-actions>select,.bank-actions>.btn{flex:1 1 100%;width:100%}}
.question-edit{margin-top:14px;border-top:1px solid var(--border-color);padding-top:12px}.question-edit summary{display:inline-flex;align-items:center;gap:7px;padding:9px 13px;border:1px solid var(--primary);border-radius:9px;color:var(--primary);font-weight:700;cursor:pointer;list-style:none}.question-edit summary::-webkit-details-marker{display:none}.question-edit[open] summary{margin-bottom:14px;background:rgba(var(--primary-rgb),.12)}.question-edit-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.question-edit-grid .wide{grid-column:1/-1}.question-edit .bank-form textarea{min-height:90px}.question-edit-buttons{position:sticky;bottom:10px;z-index:4;display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:12px;border:1px solid rgba(var(--primary-rgb),.28);border-radius:12px;background:var(--sidebar-bg);box-shadow:0 10px 30px rgba(0,0,0,.25)}.question-edit-buttons .btn{width:auto;min-height:44px}.question-save-note{color:var(--text-muted);font-size:13px}.question-update-form.is-dirty .question-save-note{color:#fbbf24}.question-update-form.is-saving .question-save-note{color:var(--primary)}@media(max-width:650px){.question-edit-grid{grid-template-columns:1fr}.question-edit-grid .wide{grid-column:auto}.question-edit-buttons .btn{width:100%}}
.topic-manager{display:grid;grid-template-columns:minmax(280px,420px) 1fr;gap:24px;align-items:start}.topic-create{padding:18px;border-radius:14px;background:var(--input-bg);border:1px solid var(--border-color)}.topic-create-title{display:flex;align-items:center;gap:8px;margin:0 0 14px;font-size:15px;color:var(--text-muted)}.topic-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}.topic-card{min-width:0;display:flex;align-items:center;gap:12px;padding:14px;border:1px solid var(--border-color);border-radius:14px;background:var(--input-bg);transition:.2s}.topic-card:hover{transform:translateY(-2px);border-color:rgba(var(--primary-rgb),.55);box-shadow:0 8px 24px rgba(0,0,0,.12)}.topic-card-icon{width:42px;height:42px;flex:0 0 42px;display:grid;place-items:center;border-radius:12px;background:rgba(var(--primary-rgb),.15);color:var(--primary);font-size:22px}.topic-card-info{min-width:0;flex:1}.topic-card-name{display:block;color:var(--text-main);font-weight:700;font-size:16px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.topic-card-meta{display:flex;gap:7px;flex-wrap:wrap;margin-top:5px;color:var(--text-muted);font-size:12px}.topic-card-delete{width:34px;height:34px;flex:0 0 34px;border:1px solid rgba(239,68,68,.25);border-radius:9px;background:rgba(239,68,68,.08);color:var(--danger);cursor:pointer;display:grid;place-items:center}.topic-card-delete:hover{background:var(--danger);color:#fff}.topic-card-delete:disabled{opacity:.35;cursor:not-allowed}.topic-empty{grid-column:1/-1;padding:24px;text-align:center;border:1px dashed var(--border-color);border-radius:14px;color:var(--text-muted)}@media(max-width:900px){.topic-manager{grid-template-columns:1fr}}@media(max-width:600px){.topic-list{grid-template-columns:1fr}}
.question-inline-error{margin:14px 0 4px;padding:12px 14px;border:1px solid rgba(239,68,68,.38);border-radius:11px;background:rgba(239,68,68,.1);color:#fca5a5;display:flex;gap:9px;align-items:flex-start}.question-inline-error i{font-size:20px;flex:0 0 auto}
</style>
<h1><i class='bx bx-library'></i> Ngân hàng câu hỏi</h1>
<?php if(isset($_SESSION['success'])):?><div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success']);unset($_SESSION['success']);?></div><?php endif;?><?php if(isset($_SESSION['error'])):?><div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['error']);unset($_SESSION['error']);?></div><?php endif;?>
<section class="bank-panel" style="margin-bottom:20px">
    <h2><i class='bx bx-purchase-tag'></i> Quản lý chủ đề</h2>
    <div class="topic-manager">
        <div class="topic-create">
            <div class="topic-create-title"><i class='bx bx-plus-circle'></i> Tạo chủ đề mới</div>
            <form method="post" class="bank-form"><?php echo csrfField();?><input type="hidden" name="action" value="create_topic"><?php if($isAdmin):?><select name="teacher_id" required><option value="">Chọn người phụ trách</option><?php foreach($teachers as $teacher):?><option value="<?php echo $teacher['id'];?>"><?php echo htmlspecialchars($teacher['name']);?></option><?php endforeach;?></select><?php endif;?><input name="topic_name" placeholder="Nhập tên chủ đề mới" maxlength="191" required><button class="btn btn-primary"><i class='bx bx-plus'></i> Tạo chủ đề</button></form>
        </div>
        <div class="topic-list">
            <?php foreach($topics as $topic): ?>
                <article class="topic-card" title="<?php echo htmlspecialchars($topic['name'],ENT_QUOTES,'UTF-8'); ?>">
                    <div class="topic-card-icon"><i class='bx bx-purchase-tag-alt'></i></div>
                    <div class="topic-card-info"><span class="topic-card-name"><?php echo htmlspecialchars($topic['name']); ?></span><div class="topic-card-meta"><span><i class='bx bx-help-circle'></i> <?php echo number_format((int)$topic['question_count']); ?> câu</span><?php if($isAdmin): ?><span><i class='bx bx-user'></i> <?php echo htmlspecialchars($topic['teacher_name']??'Chưa rõ'); ?></span><?php endif; ?></div></div>
                    <form method="post" onsubmit="return confirm('Xóa chủ đề này?')"><?php echo csrfField();?><input type="hidden" name="action" value="delete_topic"><input type="hidden" name="topic_id" value="<?php echo (int)$topic['id'];?>"><button type="submit" class="topic-card-delete" title="<?php echo (int)$topic['question_count']>0?'Không thể xóa chủ đề đang có câu hỏi':'Xóa chủ đề'; ?>" <?php echo (int)$topic['question_count']>0?'disabled':''; ?>><i class='bx bx-trash'></i></button></form>
                </article>
            <?php endforeach; ?>
            <?php if(!$topics): ?><div class="topic-empty"><i class='bx bx-purchase-tag' style="font-size:30px;display:block;margin-bottom:8px"></i>Chưa có chủ đề. Hãy tạo chủ đề đầu tiên.</div><?php endif; ?>
        </div>
    </div>
</section>
<div class="bank-grid"><aside><section class="bank-panel"><h2>Thêm câu hỏi</h2><form method="post" class="bank-form"><?php echo csrfField();?><input type="hidden" name="action" value="save_question"><?php if($isAdmin):?><select name="teacher_id" required><option value="">Chọn giảng viên</option><?php foreach($teachers as $teacher):?><option value="<?php echo $teacher['id'];?>"><?php echo htmlspecialchars($teacher['name']);?></option><?php endforeach;?></select><?php endif;?><input name="topic" list="question-topic-list" placeholder="Chọn hoặc nhập chủ đề" required><datalist id="question-topic-list"><?php foreach($topics as $topic):?><option value="<?php echo htmlspecialchars($topic['name'],ENT_QUOTES,'UTF-8');?>"><?php endforeach;?></datalist><select name="difficulty"><option value="easy">Dễ</option><option value="medium" selected>Trung bình</option><option value="hard">Khó</option></select><textarea name="question_text" rows="3" placeholder="Nội dung câu hỏi" required></textarea><?php foreach(['a','b','c','d'] as $letter):?><input name="option_<?php echo $letter;?>" placeholder="Đáp án <?php echo strtoupper($letter);?>" required><?php endforeach;?><select name="correct_option"><option value="A">Đúng: A</option><option value="B">Đúng: B</option><option value="C">Đúng: C</option><option value="D">Đúng: D</option></select><button class="btn btn-primary"><i class='bx bx-plus'></i> Lưu câu hỏi</button></form></section>
<section class="bank-panel" style="margin-top:16px"><h2>Nhập / xuất Excel</h2><form method="post" enctype="multipart/form-data" class="bank-form"><?php echo csrfField();?><input type="hidden" name="action" value="import"><select name="import_topic_id" required><option value="">Chọn chủ đề cần nhập vào</option><?php foreach($topics as $topic):?><option value="<?php echo $topic['id'];?>"><?php echo htmlspecialchars($topic['name']);?></option><?php endforeach;?></select><select name="import_difficulty" required><option value="easy">Mức độ: Dễ</option><option value="medium" selected>Mức độ: Trung bình</option><option value="hard">Mức độ: Khó</option><option value="from_file">Lấy mức độ trong file</option></select><input type="file" name="question_files[]" accept=".csv,.xlsx" multiple required><small style="color:var(--text-muted)">Dùng đúng mẫu 6 cột như file 007_Windows_01.xlsx: Câu hỏi, Đáp án A, Đáp án B, Đáp án C, Đáp án D, Đáp án đúng. Có thể chọn nhiều file cùng lúc.</small><button class="btn btn-outline">Nhập các file vào chủ đề đã chọn</button></form><a class="btn btn-outline" style="margin-top:10px" href="?action=export"><i class='bx bx-download'></i> Xuất cho Excel</a></section>
<section class="bank-panel" style="margin-top:16px"><h2>Tạo đề ngẫu nhiên</h2><form method="post" class="bank-form"><?php echo csrfField();?><input type="hidden" name="action" value="generate_quiz"><input name="quiz_title" placeholder="Tên đề"><select name="course_id" required><option value="">Chọn khóa học</option><?php foreach($courses as $course):?><option value="<?php echo $course['id'];?>"><?php echo htmlspecialchars($course['title']);?></option><?php endforeach;?></select><select name="topic_id"><option value="0">Mọi chủ đề</option><?php foreach($topics as $topic):?><option value="<?php echo $topic['id'];?>"><?php echo htmlspecialchars($topic['name']);?></option><?php endforeach;?></select><select name="difficulty_filter"><option value="all">Mọi mức độ</option><option value="easy">Dễ</option><option value="medium">Trung bình</option><option value="hard">Khó</option></select><div><label for="question-count" style="display:block;margin-bottom:8px;font-weight:700">Số câu hỏi trong đề</label><input id="question-count" type="number" name="question_count" value="50" min="1" max="200" required><small style="display:block;margin-top:7px;color:var(--text-muted);line-height:1.45">Ưu tiên câu chưa xuất hiện trong đề khác của cùng khóa học; nếu chưa đủ, hệ thống lấy các câu ít trùng nhất. Tối đa 200 câu và đề được mở ngay cho học viên.</small></div><button class="btn btn-primary"><i class='bx bx-shuffle'></i> Tạo và mở đề</button></form></section></aside>
<main class="bank-panel"><form method="get" class="bank-actions" style="margin-bottom:18px"><input name="q" value="<?php echo htmlspecialchars($search);?>" placeholder="Tìm nội dung câu hỏi" style="flex:1;min-width:220px"><select name="topic_id"><option value="0">Mọi chủ đề</option><?php foreach($topics as $topic):?><option value="<?php echo $topic['id'];?>" <?php echo $topicFilter==$topic['id']?'selected':'';?>><?php echo htmlspecialchars($topic['name']);?></option><?php endforeach;?></select><select name="difficulty"><option value="">Mọi mức độ</option><option value="easy" <?php echo $difficultyFilter==='easy'?'selected':'';?>>Dễ</option><option value="medium" <?php echo $difficultyFilter==='medium'?'selected':'';?>>Trung bình</option><option value="hard" <?php echo $difficultyFilter==='hard'?'selected':'';?>>Khó</option></select><button class="btn btn-outline">Lọc</button></form><h2><?php echo number_format($totalQuestions);?> câu hỏi <small style="font-size:14px;color:var(--text-muted);font-weight:500">· Trang <?php echo $currentPage;?>/<?php echo $totalPages;?></small></h2><?php foreach($questions as $q):?><article class="question-card" id="question-<?php echo (int)$q['id'];?>"><div class="question-meta"><span><?php echo htmlspecialchars($q['topic_name']??'Chưa phân loại');?></span><span><?php echo questionDifficultyLabel($q['difficulty']);?></span><span>Đã dùng <?php echo (int)$q['usage_count'];?> lần</span><?php if($isAdmin):?><span><?php echo htmlspecialchars($q['teacher_name']??'');?></span><?php endif;?></div><strong><?php echo nl2br(htmlspecialchars($q['question_text']));?></strong><ol type="A" style="color:var(--text-muted)"><li><?php echo htmlspecialchars($q['option_a']);?></li><li><?php echo htmlspecialchars($q['option_b']);?></li><li><?php echo htmlspecialchars($q['option_c']);?></li><li><?php echo htmlspecialchars($q['option_d']);?></li></ol><div class="bank-actions"><span style="color:var(--success)">Đúng: <?php echo $q['correct_option'];?></span><form method="post" onsubmit="return confirm('Xóa câu hỏi này?')" style="margin-left:auto"><?php echo csrfField();?><input type="hidden" name="action" value="delete_question"><input type="hidden" name="question_id" value="<?php echo $q['id'];?>"><button class="btn btn-outline" style="color:var(--danger)"><i class='bx bx-trash'></i> Xóa</button></form></div><details class="question-edit"><summary><i class='bx bx-edit'></i> Sửa câu hỏi</summary><form method="post" class="bank-form question-update-form"><?php echo csrfField();?><input type="hidden" name="action" value="update_question"><input type="hidden" name="question_id" value="<?php echo (int)$q['id'];?>"><div class="question-edit-grid"><div class="wide"><label>Chủ đề</label><select name="topic_id" required><?php foreach($topics as $topic):?><?php if((int)$topic['teacher_id']===(int)$q['teacher_id']):?><option value="<?php echo (int)$topic['id'];?>" <?php echo (int)$q['topic_id']===(int)$topic['id']?'selected':'';?>><?php echo htmlspecialchars($topic['name']);?></option><?php endif;?><?php endforeach;?></select></div><div class="wide"><label>Nội dung câu hỏi</label><textarea name="question_text" required><?php echo htmlspecialchars($q['question_text']);?></textarea></div><?php foreach(['a','b','c','d'] as $letter):?><div><label>Đáp án <?php echo strtoupper($letter);?></label><input name="option_<?php echo $letter;?>" value="<?php echo htmlspecialchars($q['option_'.$letter],ENT_QUOTES,'UTF-8');?>" required></div><?php endforeach;?><div><label>Đáp án đúng</label><select name="correct_option"><?php foreach(['A','B','C','D'] as $letter):?><option value="<?php echo $letter;?>" <?php echo $q['correct_option']===$letter?'selected':'';?>><?php echo $letter;?></option><?php endforeach;?></select></div><div><label>Mức độ</label><select name="difficulty"><option value="easy" <?php echo $q['difficulty']==='easy'?'selected':'';?>>Dễ</option><option value="medium" <?php echo $q['difficulty']==='medium'?'selected':'';?>>Trung bình</option><option value="hard" <?php echo $q['difficulty']==='hard'?'selected':'';?>>Khó</option></select></div></div><div class="question-edit-buttons"><button type="submit" class="btn btn-primary"><i class='bx bx-save'></i> Lưu thay đổi</button><span class="question-save-note">Hãy bấm “Lưu thay đổi” trước khi tải lại trang.</span></div></form></details></article><?php endforeach;?><?php if(!$questions):?><p style="color:var(--text-muted)">Chưa có câu hỏi phù hợp.</p><?php endif;?><?php if($totalPages>1):?><nav class="bank-actions" style="justify-content:center;margin-top:20px"><a class="btn btn-outline" href="<?php echo htmlspecialchars($pageUrl(max(1,$currentPage-1)));?>" <?php echo $currentPage<=1?'style="pointer-events:none;opacity:.45"':'';?>><i class='bx bx-chevron-left'></i> Trước</a><?php for($page=max(1,$currentPage-2);$page<=min($totalPages,$currentPage+2);$page++):?><a class="btn <?php echo $page===$currentPage?'btn-primary':'btn-outline';?>" href="<?php echo htmlspecialchars($pageUrl($page));?>"><?php echo $page;?></a><?php endfor;?><a class="btn btn-outline" href="<?php echo htmlspecialchars($pageUrl(min($totalPages,$currentPage+1)));?>" <?php echo $currentPage>=$totalPages?'style="pointer-events:none;opacity:.45"':'';?>>Sau <i class='bx bx-chevron-right'></i></a></nav><?php endif;?></main></div>
<script>
(() => {
    const questionErrors = <?php echo json_encode($questionErrors,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
    Object.entries(questionErrors).forEach(([questionId, message]) => {
        const card = document.getElementById(`question-${questionId}`);
        const editor = card?.querySelector('.question-edit');
        if (!card || !editor) return;
        const alert = document.createElement('div');
        alert.className = 'question-inline-error';
        alert.innerHTML = '<i class="bx bx-error-circle"></i><span></span>';
        alert.querySelector('span').textContent = message;
        card.insertBefore(alert, editor);
        editor.open = true;
    });

    let hasUnsavedQuestion = false;
    document.querySelectorAll('.question-update-form').forEach(form => {
        const note = form.querySelector('.question-save-note');
        form.addEventListener('input', () => {
            form.classList.add('is-dirty');
            hasUnsavedQuestion = true;
            if (note) note.textContent = 'Bạn có thay đổi chưa được lưu.';
        });
        form.addEventListener('change', () => {
            form.classList.add('is-dirty');
            hasUnsavedQuestion = true;
            if (note) note.textContent = 'Bạn có thay đổi chưa được lưu.';
        });
        form.addEventListener('submit', () => {
            hasUnsavedQuestion = false;
            form.classList.remove('is-dirty');
            form.classList.add('is-saving');
            if (note) note.textContent = 'Đang lưu vào cơ sở dữ liệu...';
        });
    });
    window.addEventListener('beforeunload', event => {
        if (!hasUnsavedQuestion) return;
        event.preventDefault();
        event.returnValue = '';
    });

    const countInput = document.getElementById('question-count');
    if (countInput) {
        const distribution = document.createElement('div');
        distribution.className = 'question-edit-grid';
        distribution.innerHTML = '<div><label>Số câu dễ</label><input type="number" name="easy_count" value="0" min="0" max="200"></div><div><label>Số câu trung bình</label><input type="number" name="medium_count" value="0" min="0" max="200"></div><div><label>Số câu khó</label><input type="number" name="hard_count" value="0" min="0" max="200"></div><small style="align-self:center;color:var(--text-muted)">Nếu nhập các ô này, hệ thống sẽ dùng tổng theo mức độ và bỏ qua số câu chung.</small>';
        countInput.closest('div').after(distribution);
    }

    const versions = <?php echo json_encode($questionVersions,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);?>;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    Object.entries(versions).forEach(([questionId, history]) => {
        const details = document.querySelector(`#question-${questionId} .question-edit`);
        if (!details || !history.length) return;
        const box = document.createElement('div');
        box.style.cssText = 'margin-top:16px;padding-top:14px;border-top:1px solid var(--border-color)';
        box.innerHTML = '<strong>Lịch sử phiên bản</strong><div class="bank-actions" style="margin-top:10px"></div>';
        history.slice(0,5).forEach(version => {
            const form = document.createElement('form');
            form.method = 'post';
            form.innerHTML = `<input type="hidden" name="csrf_token" value="${csrf}"><input type="hidden" name="action" value="restore_question_version"><input type="hidden" name="question_id" value="${questionId}"><input type="hidden" name="version_id" value="${version.id}"><button class="btn btn-outline"><i class='bx bx-history'></i> Bản ${version.version_number} · ${new Date(version.created_at.replace(' ','T')).toLocaleString('vi-VN')}</button>`;
            form.addEventListener('submit', event => { if (!confirm('Khôi phục phiên bản này? Các đề liên quan cũng sẽ được cập nhật.')) event.preventDefault(); });
            box.querySelector('.bank-actions').appendChild(form);
        });
        details.appendChild(box);
    });
})();
</script>
<?php require_once '../includes/footer.php';?>
