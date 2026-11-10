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
$pdo->exec('CREATE TABLE teaching_schedule_slots (id INTEGER PRIMARY KEY, teaching_class_id INTEGER, attendance_status TEXT, makeup_student_id INTEGER, substitute_teacher_id INTEGER, teaching_date TEXT, start_time TEXT, end_time TEXT, is_makeup INTEGER DEFAULT 0)');
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
$pdo->exec("INSERT INTO teaching_schedule_slots (id, teaching_class_id, attendance_status, makeup_student_id, substitute_teacher_id) VALUES
    (1,1,'present',NULL,NULL), (2,1,'present',NULL,NULL),
    (3,1,'absent',NULL,NULL), (4,1,'pending',NULL,NULL),
    (5,1,'present',101,NULL), (6,1,'present',102,NULL),
    (7,1,'absent',101,NULL), (8,1,'pending',101,NULL),
    (9,2,'present',NULL,10), (10,2,'absent',NULL,NULL),
    (11,2,'pending',NULL,NULL), (12,3,'present',NULL,NULL)");
$slotDates = [1 => '2026-11-03', 2 => '2026-11-03', 3 => '2026-11-04', 4 => '2026-11-12',
    5 => '2026-11-05', 6 => '2026-11-06', 7 => '2026-11-07', 8 => '2026-11-13',
    9 => '2026-11-03', 10 => '2026-11-04', 11 => '2026-11-12', 12 => '2026-10-01'];
$setSlotDate = $pdo->prepare('UPDATE teaching_schedule_slots SET teaching_date=?, start_time=?, end_time=?, is_makeup=? WHERE id=?');
foreach ($slotDates as $id => $date) {
    $setSlotDate->execute([$date, $id === 1 ? '14:00:00' : '08:00:00', $id === 1 ? '17:00:00' : '11:00:00', $id === 1 ? 1 : 0, $id]);
}
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
$sessions = teachingProgressExportSessions($pdo, $all);
progressAssert(teachingProgressExportSessions($pdo, []) === [], 'Empty class list must return no dated sessions.');
progressAssert(!isset($sessions[3]) && !isset($sessions[6]), 'Dated sessions leaked outside selected classes or fabricated slots.');
$anSessions = array_values(array_filter($sessions[1], static fn(array $session): bool => (int) $session['student_id'] === 101));
$binhSessions = array_values(array_filter($sessions[1], static fn(array $session): bool => (int) $session['student_id'] === 102));
progressAssert(array_map('intval', array_column($anSessions, 'slot_id')) === [1,5], 'Absent/pending sessions leaked or personal attended makeup was lost for An.');
progressAssert(array_map('intval', array_column($binhSessions, 'slot_id')) === [2,1,6], 'Attended session order or personal makeup isolation failed for Bình.');
foreach ($students as $classId => $classStudents) {
    foreach ($classStudents as $student) {
        $attended = array_filter($sessions[$classId] ?? [], static fn(array $session): bool => (int) $session['student_id'] === (int) $student['id']);
        progressAssert(count($attended) === (int) $student['completed'], 'Dated attended rows must match the learned session count.');
    }
}
$pdo->beginTransaction();
$pdo->exec("UPDATE teaching_schedule_slots SET attendance_status='pending' WHERE id=9");
progressAssert(!isset(teachingProgressExportSessions($pdo, $all)[2]), 'Class with only absent/pending sessions must have no detail rows.');
$pdo->rollBack();
progressAssert($sessions[2][0]['substitute_teacher_name'] === 'Giáo viên A', 'Dated session substitute teacher was lost.');

$path = tempnam(sys_get_temp_dir(), 'lms_progress_test_');
$zip = new ZipArchive();
try {
    teachingProgressExportWorkbook($all, $students, $sessions)->save($path);
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
    progressAssert($summary->evaluate('string(//s:c[@r="A4"]/s:is/s:t)') === 'Lớp'
        && $summary->evaluate('string(//s:c[@r="B4"]/s:is/s:t)') === 'Học viên', 'Summary identity columns are missing.');
    progressAssert($summary->evaluate('string(//s:c[@r="C5"]/s:is/s:t)') === 'B1'
        && $summary->evaluate('string(//s:c[@r="E5"]/s:is/s:t)') === 'B3', 'Session headers must run horizontally.');
    progressAssert($summary->evaluate('string(//s:c[@r="F6"]/s:is/s:t)') === '2/3'
        && $summary->evaluate('string(//s:c[@r="F7"]/s:is/s:t)') === '3/3', 'Horizontal totals are incorrect.');
    progressAssert($summary->evaluate('string(//s:c[@r="B8"]/s:is/s:t)') === '=SUM(1,2)' && $summary->query('//s:f')->length === 0, 'Student name became an Excel formula.');
    progressAssert($summary->evaluate('string(//s:c[@r="F8"]/s:is/s:t)') === '1/2', 'Fallback total incorrect.');
    progressAssert($summary->evaluate('string(//s:c[@r="B9"]/s:is/s:t)') === 'Chưa có học viên', 'Empty class was omitted.');
    progressAssert($summary->evaluate('string(//s:c[@r="F10"]/s:is/s:t)') === '0', 'Zero-total student should show zero without division.');
    progressAssert($summary->evaluate('string(//s:pane/@topLeftCell)') === 'C6', 'Frozen headers/identity columns incorrect.');
    $detail = progressXml($zip, 'xl/worksheets/sheet2.xml');
    progressAssert($detail->query('//s:row[number(@r)>=6]')->length === 2, 'Each student must have exactly one row.');
    progressAssert($detail->evaluate('string(//s:c[@r="A6"]/s:is/s:t)') === 'An'
        && $detail->evaluate('string(//s:c[@r="A7"]/s:is/s:t)') === 'Bình', 'Student names must be in the first column.');
    progressAssert($detail->evaluate('string(//s:c[@r="B6"]/s:is/s:t)') === '03/11/2026'
        && $detail->evaluate('string(//s:c[@r="C6"]/s:is/s:t)') === '05/11/2026', 'Attended dates and personal makeup must run horizontally.');
    progressAssert($detail->evaluate('string(//s:c[@r="D6"]/s:is/s:t)') === '-'
        && $detail->evaluate('string(//s:c[@r="E6"]/s:is/s:t)') === '2/3', 'Unlearned session placeholder or total is incorrect.');
    progressAssert($detail->evaluate('string(//s:c[@r="B7"]/s:is/s:t)') === '03/11/2026'
        && $detail->evaluate('string(//s:c[@r="C7"]/s:is/s:t)') === '03/11/2026'
        && $detail->evaluate('string(//s:c[@r="D7"]/s:is/s:t)') === '06/11/2026', 'Two attended shifts on the same date must occupy separate cells.');
    progressAssert($detail->query('//s:mergeCell[@ref="A4:A5" or @ref="B4:D4" or @ref="E4:E5"]')->length === 3, 'Two-row grouped headers do not match the reference.');
    progressAssert($detail->evaluate('string(//s:pane/@topLeftCell)') === 'B6', 'Class student name/header must remain frozen.');
    progressAssert($detail->evaluate('string(//s:c[@r="B6"]/@s)') === '16', 'Attended date should use the blue highlight style.');
    progressAssert($detail->query('//s:t[text()="Vắng" or text()="Chưa điểm danh"]')->length === 0, 'Absent/pending attendance leaked into Excel detail.');
    progressAssert($detail->query('//s:t[text()="04/11/2026" or text()="07/11/2026" or text()="12/11/2026" or text()="13/11/2026"]')->length === 0, 'Absent/pending dates leaked into Excel detail.');
    $noSessions = progressXml($zip, 'xl/worksheets/sheet5.xml');
    progressAssert($noSessions->evaluate('string(//s:c[@r="B6"]/s:is/s:t)') === '-'
        && $noSessions->evaluate('string(//s:c[@r="C6"]/s:is/s:t)') === '0', 'No-attended-session student must have no date and zero learned sessions.');
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = $zip->getNameIndex($index);
        if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) progressXml($zip, $name);
    }
    $zip->close();
    // Single-class and selected-class workbooks must not contain another class.
    foreach ([['mode' => 'single', 'class_id' => '1'], ['mode' => 'selected', 'class_ids' => ['1','6']]] as $request) {
        $classes = teachingProgressExportClasses($pdo, 99, 'admin', $request);
        teachingProgressExportWorkbook($classes, teachingProgressExportStudents($pdo, $classes), teachingProgressExportSessions($pdo, $classes))->save($path);
        $zip->open($path);
        $sheet = progressXml($zip, 'xl/worksheets/sheet1.xml');
        progressAssert($sheet->query('//s:t[text()="=SUM(1,2)"]')->length === 0, 'Unselected student leaked into workbook.');
        progressAssert(progressXml($zip, 'xl/workbook.xml')->query('//s:sheet')->length === count($classes) + 1, 'Wrong selected workbook sheet count.');
        progressAssert(progressXml($zip, 'xl/worksheets/sheet2.xml')->query('//s:t[text()="01/10/2026"]')->length === 0, 'Dates from unselected classes leaked.');
        $zip->close();
    }
    // Match the reference's 17 columns and keep column letters/merges correct past Z.
    foreach ([17 => ['last_session' => 'R', 'total' => 'S'], 30 => ['last_session' => 'AE', 'total' => 'AF']] as $total => $columns) {
        $layoutClass = $all[0];
        $layoutClass['total_sessions'] = $total;
        teachingProgressExportWorkbook([$layoutClass], $students, $sessions)->save($path);
        $zip->open($path);
        $matrix = progressXml($zip, 'xl/worksheets/sheet2.xml');
        progressAssert($matrix->evaluate('string(//s:c[@r="' . $columns['last_session'] . '5"]/s:is/s:t)') === 'B' . $total, 'Configured session columns were truncated.');
        progressAssert($matrix->evaluate('string(//s:c[@r="' . $columns['total'] . '6"]/s:is/s:t)') === '2/' . $total, 'Wide matrix total is incorrect.');
        progressAssert($matrix->query('//s:mergeCell[@ref="B4:' . $columns['last_session'] . '4"]')->length === 1, 'Wide matrix group merge is incorrect.');
        $zip->close();
    }
    $layoutClass = $all[0];
    $layoutClass['total_sessions'] = 1;
    teachingProgressExportWorkbook([$layoutClass], $students, $sessions)->save($path);
    $zip->open($path);
    $overflow = progressXml($zip, 'xl/worksheets/sheet2.xml');
    progressAssert($overflow->evaluate('string(//s:c[@r="D7"]/s:is/s:t)') === '06/11/2026'
        && $overflow->evaluate('string(//s:c[@r="E7"]/s:is/s:t)') === '3/1', 'Learned dates beyond configured total must not be lost.');
    $zip->close();
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
    echo "[PASS] All/single/selected exports; authorization; horizontal B1/B2 dates; attended sessions and makeups; same-day shifts; 17/30-column layouts; totals; XLSX XML and form rendering.\n";
} finally {
    try { $zip->close(); } catch (ValueError $error) { }
    if ($path !== false) unlink($path);
}
