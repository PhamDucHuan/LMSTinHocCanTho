<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/quiz_export.php';
require_once __DIR__ . '/../includes/quiz_import.php';

function exportAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function exportXml(ZipArchive $zip, string $path): DOMXPath
{
    $document = new DOMDocument();
    exportAssert($document->loadXML((string) $zip->getFromName($path)), 'Invalid XML: ' . $path);
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    return $xpath;
}

$directory = sys_get_temp_dir() . '/lms_export_test_' . bin2hex(random_bytes(8));
mkdir($directory);
$zip = new ZipArchive();
try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE quizzes (id INTEGER,course_id INTEGER,title TEXT,sort_order INTEGER,is_published INTEGER,question_limit INTEGER)');
    $pdo->exec('CREATE TABLE quiz_sections (id INTEGER,quiz_id INTEGER,title TEXT,sort_order INTEGER)');
    $pdo->exec('CREATE TABLE quiz_questions (id INTEGER,section_id INTEGER,sort_order INTEGER,question_text TEXT,option_a TEXT,option_b TEXT,option_c TEXT,option_d TEXT,correct_option TEXT,points REAL,difficulty TEXT,explanation TEXT,question_image TEXT,option_a_image TEXT)');
    $pdo->exec("INSERT INTO quizzes VALUES (1,5,'Word / Excel',2,1,1),(2,5,'word : excel',1,0,1),(3,6,'Other course',1,1,0),(4,5,'Empty quiz',3,1,0)");
    $pdo->exec("INSERT INTO quiz_sections VALUES (1,1,'Second',2),(2,1,'First',1),(3,2,'Questions',1),(4,3,'Other',1)");
    $pdo->exec("INSERT INTO quiz_questions VALUES
        (1,1,2,'Later','A','B','C','D','A',1,'easy','',NULL,NULL),
        (2,2,1,'=SUM(1,2)','Có dấu tiếng Việt','B','C','D','d, a',2.5,'hard','Giải thích & < >','pixel.png','../outside.png'),
        (3,3,1,'Draft','A','B','C','D','B',1,'medium','',NULL,NULL),
        (4,4,1,'Foreign question','A','B','C','D','C',1,'medium','',NULL,NULL)");
    file_put_contents($directory . '/pixel.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jOuoAAAAASUVORK5CYII='));
    $quizzes = quizExportLoadQuizzes($pdo, 5);
    exportAssert(array_map('intval', array_column($quizzes, 'id')) === [2, 1, 4], 'Course filter, draft inclusion or quiz order failed.');
    exportAssert(array_map('intval', array_column($quizzes[1]['questions'], 'id')) === [2, 1], 'Section/question ordering or full-bank export failed.');
    $workbook = quizExportBuildWorkbook($quizzes, $directory);
    $file = $directory . '/export.xlsx';
    $workbook->save($file);
    exportAssert($zip->open($file) === true, 'Cannot open XLSX.');
    $xml = exportXml($zip, 'xl/workbook.xml');
    $names = [];
    foreach ($xml->query('//s:sheet') as $sheet) $names[] = $sheet->getAttribute('name');
    exportAssert($names === ['word   excel', 'Word   Excel (2)', 'Empty quiz'], 'Unique sanitized sheet names failed.');
    $sheet = exportXml($zip, 'xl/worksheets/sheet2.xml');
    exportAssert($sheet->evaluate('string(//s:pane/@topLeftCell)') === 'A2'
        && $sheet->evaluate('string(//s:pane/@activePane)') === 'bottomLeft', 'Header row freeze pane is invalid.');
    exportAssert($sheet->evaluate('string(//s:c[@r="A2"]/s:is/s:t)') === '=SUM(1,2)', 'Formula-like question text was altered.');
    exportAssert($sheet->query('//s:f')->length === 0, 'Question text became an Excel formula.');
    exportAssert($sheet->evaluate('string(//s:c[@r="F2"]/s:is/s:t)') === 'A,D', 'Multiple correct answers were lost.');
    exportAssert($sheet->query('//s:row[@r="1"]/s:c/s:is/s:t[text()="Giải thích" or text()="Điểm trọng số" or text()="Độ khó"]')->length === 0, 'Excluded metadata columns are still exported.');
    exportAssert(exportXml($zip, 'xl/worksheets/sheet1.xml')->query('//s:row[@r="1"]/s:c')->length === 6, 'Text-only quiz must have six columns.');
    exportAssert($sheet->evaluate('string(//s:c[@r="L2"]/s:is/s:t)') === 'First', 'Section title was lost.');
    exportAssert($sheet->evaluate('string(//s:c[@r="H2"]/s:is/s:t)') === 'Ảnh: uploads/../outside.png', 'Missing/unsafe image reference was lost.');
    exportAssert($zip->locateName('xl/media/image2_1.png') !== false, 'Question image was not embedded.');
    exportAssert($zip->locateName('xl/media/image2_2.png') === false, 'Image outside upload directory was embedded.');
    exportAssert($sheet->query('//s:drawing')->length === 1, 'Drawing relationship was not attached.');
    exportAssert(exportXml($zip, 'xl/worksheets/sheet3.xml')->query('//s:row')->length === 1, 'Empty quiz must retain a sheet with headers.');
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $entry = $zip->getNameIndex($index);
        if (str_ends_with($entry, '.xml') || str_ends_with($entry, '.rels')) exportXml($zip, $entry);
    }
    $zip->close();
    $imported = readQuizImportRows($file, 'xlsx');
    exportAssert(array_slice($imported[1], 0, 6) === ['Draft', 'A', 'B', 'C', 'D', 'B'], 'First six columns are not import-compatible.');

    $namesWorkbook = new SimpleXlsxWorkbook();
    foreach ([str_repeat('Đ', 40), str_repeat('đ', 40), "'History'", "'[]:*?/\\'"] as $name) {
        $namesWorkbook->addSheet($name, [['cells' => [1 => ['value' => 'Test']]]], [20]);
    }
    $namesWorkbook->save($directory . '/names.xlsx');
    $zip->open($directory . '/names.xlsx');
    $seen = [];
    foreach (exportXml($zip, 'xl/workbook.xml')->query('//s:sheet') as $sheet) {
        $name = $sheet->getAttribute('name');
        exportAssert(mb_strlen($name, 'UTF-8') <= 31 && !preg_match('~[\\\\/?:*\[\]]~u', $name), 'Invalid Excel sheet name.');
        exportAssert(!in_array(mb_strtolower($name), $seen, true), 'Case-insensitive duplicate sheet name.');
        exportAssert(strcasecmp($name, 'History') !== 0 && trim($name, "'") === $name, 'Reserved name or boundary apostrophe.');
        $seen[] = mb_strtolower($name);
    }
    $zip->close();
    try {
        quizExportBuildWorkbook([], $directory);
        throw new LogicException('Empty course should be rejected.');
    } catch (RuntimeException $error) {
        exportAssert(str_contains($error->getMessage(), 'chưa có'), 'Unexpected empty-course error.');
    }
    echo "[PASS] Course isolation, full question export, sheet ordering/names, answers, images, XLSX XML and import compatibility\n";
} finally {
    if ($zip->status === ZipArchive::ER_OK) {
        // close() can throw when the archive has already been closed.
        try { $zip->close(); } catch (ValueError $error) { }
    }
    foreach (['export.xlsx', 'names.xlsx', 'pixel.png'] as $filename) {
        if (is_file($directory . '/' . $filename)) unlink($directory . '/' . $filename);
    }
    rmdir($directory);
}
