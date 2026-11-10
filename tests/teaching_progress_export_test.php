<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/teaching_progress_export.php';

function progressAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function progressReject(callable $action, string $exception): void
{
    try {
        $action();
    } catch (Throwable $error) {
        progressAssert($error instanceof $exception, 'Unexpected rejection: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $exception);
}

function progressXml(ZipArchive $zip, string $name): DOMXPath
{
    $document = new DOMDocument();
    progressAssert($document->loadXML((string) $zip->getFromName($name)), 'Invalid XML: ' . $name);
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    return $xpath;
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE teaching_classes (id INTEGER PRIMARY KEY, class_name TEXT, teacher_id INTEGER, status TEXT, total_sessions INTEGER, sort_order INTEGER)');
$pdo->exec('CREATE TABLE teaching_class_students (id INTEGER PRIMARY KEY, teaching_class_id INTEGER, student_name TEXT)');
$pdo->exec('CREATE TABLE teaching_schedule_slots (id INTEGER PRIMARY KEY, teaching_class_id INTEGER, attendance_status TEXT, makeup_student_id INTEGER, substitute_teacher_id INTEGER)');
$pdo->exec('CREATE TABLE teaching_schedule_student_attendance (slot_id INTEGER, student_id INTEGER, attendance_status TEXT, PRIMARY KEY (slot_id, student_id))');
$pdo->exec("INSERT INTO users VALUES (10,'Giáo viên A'),(20,'Giáo viên B')");
$pdo->exec("INSERT INTO teaching_classes VALUES
    (1,'Tin học / Cơ bản',10,'active',3,1),
    (2,'Tin học : Cơ bản',20,'active',0,2),
    (3,'Lớp đã kết thúc',10,'completed',4,3),
    (4,'Lớp tạm dừng',20,'paused',5,4),
    (5,'Lớp chưa có học viên',NULL,'active',0,5),
    (6,'Lớp chưa có lịch',10,'active',0,6)");
$pdo->exec("INSERT INTO teaching_class_students VALUES
    (101,1,'An'),(102,1,'Bình'),(201,2,'=SUM(1,2)'),(301,3,'Chi'),(601,6,'Dũng')");
$pdo->exec("INSERT INTO teaching_schedule_slots VALUES
    (1,1,'present',NULL,NULL), (2,1,'present',NULL,NULL),
    (3,1,'absent',NULL,NULL), (4,1,'pending',NULL,NULL),
    (5,1,'present',101,NULL), (6,1,'present',102,NULL),
    (7,1,'absent',101,NULL), (8,1,'pending',101,NULL),
    (9,2,'present',NULL,10), (10,2,'absent',NULL,NULL),
    (11,2,'pending',NULL,NULL), (12,3,'present',NULL,NULL)");
$pdo->exec("INSERT INTO teaching_schedule_student_attendance VALUES
    (2,101,'absent'),(3,101,'present'),(4,101,'present')");

$classIds = static fn(array $classes): array => array_map('intval', array_column($classes, 'id'));
$all = teachingProgressExportClasses($pdo, 99, 'admin', ['mode' => 'all']);
progressAssert($classIds($all) === [1,2,5,6], 'Admin all must match active class list.');
progressAssert($classIds(teachingProgressExportClasses($pdo, 99, 'admin', ['show_completed' => '1'])) === [1,2,3,4,5,6], 'Completed/paused filter was lost.');
progressAssert($classIds(teachingProgressExportClasses($pdo, 10, 'teacher', [])) === [1,2,6], 'Owner/substitute scope failed.');
progressAssert($classIds(teachingProgressExportClasses($pdo, 10, 'admin', ['scope' => 'mine'])) === [1,2,6], 'Admin personal schedule scope failed.');
progressAssert($classIds(teachingProgressExportClasses($pdo, 10, 'administrative_staff', [])) === [1,2,6], 'Staff personal scope failed.');
progressAssert($classIds(teachingProgressExportClasses($pdo, 10, 'administrative_staff', ['teacher_id' => '20'])) === [2], 'Staff teacher-view scope failed.');
progressAssert($classIds(teachingProgressExportClasses($pdo, 99, 'admin', ['mode' => 'single', 'class_id' => '1'])) === [1], 'Single export failed.');
progressAssert($classIds(teachingProgressExportClasses($pdo, 10, 'teacher', ['mode' => 'selected', 'class_ids' => ['2','1','1']])) === [1,2], 'Selected/deduplicated export failed.');
foreach ([['mode' => 'selected'], ['mode' => 'selected', 'class_ids' => []], ['mode' => 'selected', 'class_ids' => '1'],
          ['mode' => 'single'], ['mode' => 'single', 'class_id' => '-1'], ['mode' => 'single', 'class_id' => '1 OR 1=1'],
          ['mode' => []], ['mode' => 'unknown'], ['teacher_id' => 'abc']] as $request) {
    progressReject(fn() => teachingProgressExportClasses($pdo, 99, 'admin', $request), InvalidArgumentException::class);
}
progressReject(fn() => teachingProgressExportClasses($pdo, 10, 'teacher', ['teacher_id' => '20']), DomainException::class);
progressReject(fn() => teachingProgressExportClasses($pdo, 20, 'teacher', ['mode' => 'selected', 'class_ids' => ['1','2']]), DomainException::class);
progressReject(fn() => teachingProgressExportClasses($pdo, 99, 'admin', ['mode' => 'single', 'class_id' => '999']), DomainException::class);
progressReject(fn() => teachingProgressExportClasses($pdo, 99, 'admin', ['mode' => 'single', 'class_id' => '3']), DomainException::class);
progressReject(fn() => teachingProgressExportClasses($pdo, 99, 'student', []), DomainException::class);
progressReject(fn() => teachingProgressExportClasses($pdo, 0, 'admin', []), DomainException::class);
progressReject(fn() => teachingProgressExportClasses($pdo, 999, 'teacher', []), InvalidArgumentException::class);

$students = teachingProgressExportStudents($pdo, $all);
progressAssert((int) $students[1][0]['completed'] === 2 && (int) $students[1][0]['absent'] === 3, 'Individual absences/class absence/makeup rules failed for An.');
progressAssert((int) $students[1][1]['completed'] === 3 && (int) $students[1][1]['absent'] === 1, 'Default attendance or makeup isolation failed for Bình.');
progressAssert((int) $students[1][0]['makeup_completed'] === 1 && (int) $students[1][1]['makeup_completed'] === 1, 'Personal makeup double counted.');
progressAssert((int) $students[6][0]['completed'] === 0 && (int) $students[6][0]['absent'] === 0, 'Student without slots must have zero counts.');
progressAssert(!isset($students[3]), 'Students from unselected classes leaked.');
progressAssert((int) $all[1]['regular_total'] === 2, 'Unconfigured totals must exclude class absences and personal makeups.');
progressAssert(teachingProgressExportStudents($pdo, []) === [], 'Empty class list must return no students.');

$path = tempnam(sys_get_temp_dir(), 'lms_progress_test_');
$zip = new ZipArchive();
try {
    teachingProgressExportWorkbook($all, $students)->save($path);
    progressAssert($zip->open($path) === true, 'Cannot open XLSX.');
    $workbook = progressXml($zip, 'xl/workbook.xml');
    progressAssert($workbook->query('//s:sheet')->length === 5, 'Expected summary and one worksheet per selected class.');
    $names = [];
    foreach ($workbook->query('//s:sheet') as $sheet) {
        if (!($sheet instanceof DOMElement)) throw new RuntimeException('Invalid worksheet element.');
        $name = $sheet->getAttribute('name');
        progressAssert(!in_array(mb_strtolower($name), $names, true), 'Duplicate sanitized sheet names.');
        $names[] = mb_strtolower($name);
    }
    $summary = progressXml($zip, 'xl/worksheets/sheet1.xml');
    progressAssert($summary->evaluate('string(//s:c[@r="G6"]/s:v)') === '2', 'Learned sessions must be numeric.');
    progressAssert($summary->evaluate('string(//s:c[@r="H6"]/s:v)') === '1', 'Remaining sessions incorrect.');
    progressAssert($summary->evaluate('string(//s:c[@r="K6"]/s:is/s:t)') === '66.7%', 'Completion percent incorrect.');
    progressAssert($summary->evaluate('string(//s:c[@r="L7"]/s:is/s:t)') === 'Hoàn thành', 'Completed student status incorrect.');
    progressAssert($summary->evaluate('string(//s:c[@r="E8"]/s:is/s:t)') === '=SUM(1,2)' && $summary->query('//s:f')->length === 0, 'Student name became an Excel formula.');
    progressAssert($summary->evaluate('string(//s:c[@r="K8"]/s:is/s:t)') === '50%', 'Fallback total incorrect.');
    progressAssert($summary->evaluate('string(//s:c[@r="E9"]/s:is/s:t)') === 'Chưa có học viên', 'Empty class was omitted.');
    progressAssert($summary->evaluate('string(//s:c[@r="K10"]/s:is/s:t)') === '' && $summary->evaluate('string(//s:c[@r="L10"]/s:is/s:t)') === 'Chưa có lịch', 'Zero-total progress is misleading.');
    progressAssert($summary->evaluate('string(//s:pane/@topLeftCell)') === 'F6', 'Frozen headers/identity columns incorrect.');
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = $zip->getNameIndex($index);
        if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) progressXml($zip, $name);
    }
    $zip->close();
    // Single-class and selected-class workbooks must not contain another class.
    foreach ([['mode' => 'single', 'class_id' => '1'], ['mode' => 'selected', 'class_ids' => ['1','6']]] as $request) {
        $classes = teachingProgressExportClasses($pdo, 99, 'admin', $request);
        teachingProgressExportWorkbook($classes, teachingProgressExportStudents($pdo, $classes))->save($path);
        $zip->open($path);
        $sheet = progressXml($zip, 'xl/worksheets/sheet1.xml');
        progressAssert($sheet->query('//s:t[text()="=SUM(1,2)"]')->length === 0, 'Unselected student leaked into workbook.');
        progressAssert(progressXml($zip, 'xl/workbook.xml')->query('//s:sheet')->length === count($classes) + 1, 'Wrong selected workbook sheet count.');
        $zip->close();
    }
    // Render the shared form without a teacher_name column (teacher view).
    $classes = [['id' => 1, 'class_name' => '<Lớp & A>']];
    $selectedTeacher = ['name' => 'Giáo viên B'];
    $progressExportContext = ['teacher_id' => 20];
    set_error_handler(static function (int $severity, string $message): never { throw new RuntimeException($message); });
    ob_start();
    try {
        require __DIR__ . '/../includes/teaching_progress_export_form.php';
        $html = ob_get_contents();
    } finally {
        ob_end_clean();
        restore_error_handler();
    }
    progressAssert(str_contains($html, '&lt;Lớp &amp; A&gt;') && str_contains($html, 'Giáo viên B'), 'Form escaping/teacher fallback failed.');
    echo "[PASS] All/single/selected exports; role and substitute isolation; individual/class absence and makeup counts; empty classes; XLSX XML, cells and form rendering.\n";
} finally {
    try { $zip->close(); } catch (ValueError $error) { }
    if ($path !== false) unlink($path);
}
