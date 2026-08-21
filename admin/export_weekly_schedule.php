<?php
declare(strict_types=1);

require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';

global $pdo;

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) ($_SESSION['user_role'] ?? '');
if ($userId <= 0 || !in_array($role, ['admin', 'teacher', 'administrative_staff'], true)) {
    header('Location: ../index.php');
    exit;
}

$selectedDate = (string) ($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}
try {
    $anchorDay = new DateTimeImmutable($selectedDate);
} catch (Throwable) {
    $anchorDay = new DateTimeImmutable('today');
}
$weekStart = $anchorDay->modify('-' . ((int) $anchorDay->format('N') - 1) . ' days');
$weekEnd = $weekStart->modify('+6 days');

$requestedTeacherId = filter_input(INPUT_GET, 'teacher_id', FILTER_VALIDATE_INT);
$scope = (string) ($_GET['scope'] ?? '');
$canViewOtherTeachers = in_array($role, ['admin', 'administrative_staff'], true);
$teacherId = null;
if ($canViewOtherTeachers && $requestedTeacherId) {
    $teacherId = (int) $requestedTeacherId;
} elseif ($role !== 'admin' || $scope === 'mine') {
    $teacherId = $userId;
}

$teacherName = 'Tất cả giáo viên';
if ($teacherId !== null) {
    $teacherStmt = $pdo->prepare("SELECT name FROM users WHERE id=? AND role IN ('teacher','administrative_staff','admin') AND is_approved=1 AND COALESCE(is_locked,0)=0 LIMIT 1");
    $teacherStmt->execute([$teacherId]);
    $teacherNameResult = $teacherStmt->fetchColumn();
    if ($teacherNameResult === false) {
        http_response_code(404);
        exit('Không tìm thấy giáo viên.');
    }
    $teacherName = (string) $teacherNameResult;
}

$sql =
    "SELECT ts.id, ts.teaching_date, ts.start_time, ts.end_time,
            tc.id AS class_id, tc.class_name, tc.time_shift, tc.sort_order,
            COALESCE(c.title, tc.class_name) AS display_name,
            owner.name AS teacher_name, replacement.name AS substitute_teacher_name,
            COUNT(DISTINCT tcs.id) AS student_count,
            GROUP_CONCAT(DISTINCT tcs.student_name ORDER BY tcs.student_name SEPARATOR ', ') AS student_names
     FROM teaching_schedule_slots ts
     JOIN teaching_classes tc ON tc.id=ts.teaching_class_id
     LEFT JOIN courses c ON c.id=tc.course_id
     LEFT JOIN users owner ON owner.id=tc.teacher_id
     LEFT JOIN users replacement ON replacement.id=ts.substitute_teacher_id
     LEFT JOIN teaching_class_students tcs ON tcs.teaching_class_id=tc.id
     WHERE tc.status='active' AND ts.teaching_date BETWEEN ? AND ?";
$params = [$weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d')];
if ($teacherId !== null) {
    $sql .= ' AND (tc.teacher_id=? OR ts.substitute_teacher_id=?)';
    $params[] = $teacherId;
    $params[] = $teacherId;
}
$sql .=
    " GROUP BY ts.id, ts.teaching_date, ts.start_time, ts.end_time,
               tc.id, tc.class_name, tc.time_shift, tc.sort_order, c.title,
               owner.name, replacement.name
      ORDER BY ts.teaching_date, ts.start_time,
               FIELD(tc.time_shift, 'morning', 'afternoon', 'evening'),
               tc.sort_order, tc.id, ts.id";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$classSql =
    "SELECT tc.id, tc.class_name, tc.time_shift, tc.sort_order,
            COALESCE(c.title, tc.class_name) AS display_name,
            owner.name AS teacher_name,
            COUNT(DISTINCT tcs.id) AS student_count,
            GROUP_CONCAT(DISTINCT tcs.student_name ORDER BY tcs.student_name SEPARATOR ', ') AS student_names
     FROM teaching_classes tc
     LEFT JOIN courses c ON c.id=tc.course_id
     LEFT JOIN users owner ON owner.id=tc.teacher_id
     LEFT JOIN teaching_class_students tcs ON tcs.teaching_class_id=tc.id
     WHERE tc.status='active'";
$classParams = [];
if ($teacherId !== null) {
    $classSql .= ' AND (tc.teacher_id=? OR EXISTS (
        SELECT 1 FROM teaching_schedule_slots substitute_slot
        WHERE substitute_slot.teaching_class_id=tc.id AND substitute_slot.substitute_teacher_id=?
    ))';
    $classParams[] = $teacherId;
    $classParams[] = $teacherId;
}
$classSql .=
    " GROUP BY tc.id, tc.class_name, tc.time_shift, tc.sort_order, c.title, owner.name
      ORDER BY FIELD(tc.time_shift, 'morning', 'afternoon', 'evening'), tc.sort_order, tc.id";
$classStmt = $pdo->prepare($classSql);
$classStmt->execute($classParams);
$matrixClasses = $classStmt->fetchAll(PDO::FETCH_ASSOC);

$shiftLabels = [
    'morning' => 'Ca sáng',
    'afternoon' => 'Ca chiều',
    'evening' => 'Ca tối',
];
$matrixShiftTitles = [
    'morning' => 'BUỔI SÁNG',
    'afternoon' => 'BUỔI CHIỀU',
    'evening' => 'BUỔI TỐI',
];
$weekdayLabels = ['Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'Chủ nhật'];
$summaries = [];
foreach ($shiftLabels as $key => $label) {
    $summaries[$key] = [
        'label' => $label,
        'sessions' => 0,
        'classes' => [],
        'students' => 0,
    ];
}
foreach ($rows as &$row) {
    $shift = (string) ($row['time_shift'] ?? '');
    if (!isset($summaries[$shift])) {
        $hour = (int) substr((string) $row['start_time'], 0, 2);
        $shift = $hour < 12 ? 'morning' : ($hour < 18 ? 'afternoon' : 'evening');
    }
    $row['_shift'] = $shift;
    $summaries[$shift]['sessions']++;
    $summaries[$shift]['classes'][(int) $row['class_id']] = true;
    $summaries[$shift]['students'] += (int) $row['student_count'];
}
unset($row);

$matrixSlots = [];
foreach ($rows as $row) {
    $matrixSlots[(int) $row['class_id']][(string) $row['teaching_date']][] =
        substr((string) $row['start_time'], 0, 5) . ' - ' . substr((string) $row['end_time'], 0, 5);
}
$matrixShiftGroups = ['morning' => [], 'afternoon' => [], 'evening' => []];
foreach ($matrixClasses as $class) {
    $shift = (string) ($class['time_shift'] ?? 'morning');
    if (!isset($matrixShiftGroups[$shift])) {
        $shift = 'morning';
    }
    $matrixShiftGroups[$shift][] = $class;
}
$weekDays = [];
for ($day = $weekStart; $day <= $weekEnd; $day = $day->modify('+1 day')) {
    $weekDays[] = $day;
}

require_once __DIR__ . '/../includes/simple_xlsx.php';

$cell = static fn (mixed $value, int $style = 0, string $type = 'string'): array => [
    'value' => $value,
    'style' => $style,
    'type' => $type,
];

$summaryRows = [];
$summaryMerges = ['A1:J1', 'A2:J2', 'A4:J4', 'A10:J10'];
$summaryRows[] = ['height' => 30, 'cells' => [1 => $cell(
    'TỔNG HỢP LỊCH DẠY TUẦN ' . $weekStart->format('d/m/Y') . ' - ' . $weekEnd->format('d/m/Y'),
    1
)]];
$summaryRows[] = ['cells' => [1 => $cell(
    'Phạm vi: ' . $teacherName . ' · Xuất lúc ' . date('d/m/Y H:i'),
    2
)]];
$summaryRows[] = ['cells' => []];
$summaryRows[] = ['height' => 22, 'cells' => [1 => $cell('TỔNG HỢP THEO CA HỌC', 3)]];
$summaryRows[] = ['height' => 26, 'cells' => [
    1 => $cell('Ca học', 4),
    2 => $cell('Số buổi', 4),
    3 => $cell('Số lớp', 4),
    4 => $cell('Tổng lượt học viên', 4),
]];
foreach ($summaries as $summary) {
    $summaryRows[] = ['cells' => [
        1 => $cell($summary['label'], 5),
        2 => $cell($summary['sessions'], 6, 'number'),
        3 => $cell(count($summary['classes']), 6, 'number'),
        4 => $cell($summary['students'], 6, 'number'),
    ]];
}
$summaryRows[] = ['cells' => []];
$summaryRows[] = ['height' => 22, 'cells' => [1 => $cell('CHI TIẾT TỪNG BUỔI HỌC', 3)]];
$summaryRows[] = ['height' => 30, 'cells' => []];
foreach (['STT', 'Ngày', 'Thứ', 'Ca học', 'Giờ học', 'Lớp', 'Giáo viên phụ trách', 'Giáo viên dạy thay', 'Số học viên', 'Danh sách học viên'] as $column => $heading) {
    $summaryRows[10]['cells'][$column + 1] = $cell($heading, 4);
}

if ($rows === []) {
    $emptyRowNumber = count($summaryRows) + 1;
    $summaryRows[] = ['cells' => [1 => $cell('Không có buổi học trong tuần này.', 5)]];
    $summaryMerges[] = 'A' . $emptyRowNumber . ':J' . $emptyRowNumber;
} else {
    $previousDetailDate = '';
    foreach ($rows as $index => $row) {
        $date = new DateTimeImmutable((string) $row['teaching_date']);
        $weekday = $weekdayLabels[(int) $date->format('N') - 1];
        $time = substr((string) $row['start_time'], 0, 5)
            . ' - ' . substr((string) $row['end_time'], 0, 5);

        if ($previousDetailDate !== (string) $row['teaching_date']) {
            $previousDetailDate = (string) $row['teaching_date'];
            $groupRowNumber = count($summaryRows) + 1;
            $summaryRows[] = ['height' => 22, 'cells' => [
                1 => $cell('NGÀY ' . $date->format('d/m/Y') . ' · ' . $weekday, 7),
            ]];
            $summaryMerges[] = 'A' . $groupRowNumber . ':J' . $groupRowNumber;
        }

        $summaryRows[] = ['height' => 34, 'cells' => [
            1 => $cell($index + 1, 6, 'number'),
            2 => $cell($date->format('d/m/Y'), 5),
            3 => $cell($weekday, 5),
            4 => $cell($shiftLabels[$row['_shift']] ?? 'Ca học', 5),
            5 => $cell($time, 5),
            6 => $cell($row['display_name'], 5),
            7 => $cell($row['teacher_name'] ?: 'Chưa phân công', 5),
            8 => $cell($row['substitute_teacher_name'] ?: '', 5),
            9 => $cell((int) $row['student_count'], 6, 'number'),
            10 => $cell($row['student_names'] ?: '', 5),
        ]];
    }
}

$matrixRows = [];
$matrixMerges = ['A1:H1', 'A2:H2'];
$matrixRows[] = ['height' => 30, 'cells' => [1 => $cell(
    'BẢNG LỊCH DẠY TUẦN ' . $weekStart->format('d/m/Y') . ' - ' . $weekEnd->format('d/m/Y'),
    1
)]];
$matrixRows[] = ['cells' => [1 => $cell('Phạm vi: ' . $teacherName, 2)]];
$matrixRows[] = ['height' => 34, 'cells' => [1 => $cell('LỚP / HỌC VIÊN', 8)]];
foreach ($weekDays as $index => $day) {
    $isWeekend = (int) $day->format('N') >= 6;
    $matrixRows[2]['cells'][$index + 2] = $cell(
        $weekdayLabels[(int) $day->format('N') - 1] . "\n" . $day->format('d/m'),
        $isWeekend ? 9 : 8
    );
}

$shiftStyleIds = ['morning' => 13, 'afternoon' => 14, 'evening' => 15];
foreach ($matrixShiftGroups as $shiftKey => $classesInShift) {
    $shiftRowNumber = count($matrixRows) + 1;
    $matrixRows[] = ['height' => 24, 'cells' => [
        1 => $cell($matrixShiftTitles[$shiftKey], $shiftStyleIds[$shiftKey]),
    ]];
    $matrixMerges[] = 'A' . $shiftRowNumber . ':H' . $shiftRowNumber;

    if ($classesInShift === []) {
        $emptyRowNumber = count($matrixRows) + 1;
        $matrixRows[] = ['height' => 24, 'cells' => [1 => $cell('Chưa có lớp trong ca này.', 12)]];
        $matrixMerges[] = 'A' . $emptyRowNumber . ':H' . $emptyRowNumber;
        continue;
    }

    foreach ($classesInShift as $class) {
        $classDescription = (string) $class['display_name']
            . "\n" . ($shiftLabels[$shiftKey] ?? 'Ca học')
            . ' · ' . (string) ($class['teacher_name'] ?: 'Chưa phân công')
            . ' · ' . (int) $class['student_count'] . ' học viên'
            . ((string) ($class['student_names'] ?? '') !== '' ? "\n" . (string) $class['student_names'] : '');
        $rowCells = [1 => $cell($classDescription, 10)];
        foreach ($weekDays as $index => $day) {
            $cellTimes = $matrixSlots[(int) $class['id']][$day->format('Y-m-d')] ?? [];
            $rowCells[$index + 2] = $cell(implode("\n", $cellTimes), $cellTimes ? 11 : 12);
        }
        $matrixRows[] = ['height' => 58, 'cells' => $rowCells];
    }
}

$workbook = new SimpleXlsxWorkbook();
$workbook->addSheet(
    'Tổng hợp',
    $summaryRows,
    [6, 14, 12, 13, 14, 32, 22, 22, 13, 42],
    $summaryMerges,
    ['freeze_rows' => 11]
);
$workbook->addSheet(
    'Bảng lịch tuần',
    $matrixRows,
    [42, 18, 18, 18, 18, 18, 18, 18],
    $matrixMerges,
    ['freeze_rows' => 3, 'freeze_columns' => 1]
);

$temporaryFile = tempnam(sys_get_temp_dir(), 'lms_schedule_');
if ($temporaryFile === false) {
    http_response_code(500);
    exit('Không thể tạo file Excel tạm.');
}

try {
    $workbook->save($temporaryFile);
    $filename = 'lich-day-tuan-' . $weekStart->format('Y-m-d') . '-den-' . $weekEnd->format('Y-m-d') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($temporaryFile));
    header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
    readfile($temporaryFile);
} catch (Throwable $exception) {
    http_response_code(500);
    echo 'Không thể xuất file XLSX: ' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
} finally {
    @unlink($temporaryFile);
}
exit;

function excelXmlText(mixed $value): string
{
    $text = (string) $value;
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? '';
    return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function excelCell(mixed $value, string $type = 'String', string $style = ''): string
{
    $styleAttribute = $style !== '' ? ' ss:StyleID="' . excelXmlText($style) . '"' : '';
    return '<Cell' . $styleAttribute . '><Data ss:Type="' . $type . '">' . excelXmlText($value) . '</Data></Cell>';
}

$filename = 'lich-day-tuan-' . $weekStart->format('Y-m-d') . '-den-' . $weekEnd->format('Y-m-d') . '.xml';
header('Content-Type: application/xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<?php echo '<?mso-application progid="Excel.Sheet"?>'; ?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="10"/></Style>
  <Style ss:ID="Title"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="16" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1F517E" ss:Pattern="Solid"/></Style>
  <Style ss:ID="Subtitle"><Font ss:FontName="Arial" ss:Size="10" ss:Italic="1" ss:Color="#475569"/></Style>
  <Style ss:ID="Section"><Font ss:FontName="Arial" ss:Size="12" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#334155" ss:Pattern="Solid"/></Style>
  <Style ss:ID="Header"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/><Font ss:FontName="Arial" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#2563EB" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>
  <Style ss:ID="Body"><Alignment ss:Vertical="Top" ss:WrapText="1"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/></Borders></Style>
  <Style ss:ID="Number"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/></Borders></Style>
  <Style ss:ID="DateGroup"><Alignment ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="10" ss:Bold="1" ss:Color="#0F5132"/><Interior ss:Color="#D1FAE5" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#6EE7B7"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#6EE7B7"/></Borders></Style>
  <Style ss:ID="MatrixHeader"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/><Font ss:FontName="Arial" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#245986" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FFFFFF"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#5B83A7"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#5B83A7"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#5B83A7"/></Borders></Style>
  <Style ss:ID="MatrixWeekend"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/><Font ss:FontName="Arial" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#3D3D3D" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FFFFFF"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#666666"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#666666"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#666666"/></Borders></Style>
  <Style ss:ID="MatrixClass"><Alignment ss:Vertical="Top" ss:WrapText="1"/><Font ss:FontName="Arial" ss:Size="10" ss:Bold="1" ss:Color="#0F172A"/><Interior ss:Color="#E8F1F8" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#8BA9C1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#8BA9C1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#8BA9C1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#8BA9C1"/></Borders></Style>
  <Style ss:ID="MatrixCell"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1" ss:Color="#12352A"/><Interior ss:Color="#EAF7F1" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#8BA9C1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#8BA9C1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#8BA9C1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#8BA9C1"/></Borders></Style>
  <Style ss:ID="MatrixEmpty"><Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E1"/></Borders></Style>
  <Style ss:ID="ShiftMorning"><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#2563EB" ss:Pattern="Solid"/></Style>
  <Style ss:ID="ShiftAfternoon"><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#EA580C" ss:Pattern="Solid"/></Style>
  <Style ss:ID="ShiftEvening"><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#7C3AED" ss:Pattern="Solid"/></Style>
 </Styles>
 <Worksheet ss:Name="Tổng hợp">
  <Table>
   <Column ss:Width="36"/><Column ss:Width="76"/><Column ss:Width="66"/><Column ss:Width="68"/><Column ss:Width="82"/><Column ss:Width="170"/><Column ss:Width="115"/><Column ss:Width="115"/><Column ss:Width="74"/><Column ss:Width="240"/>
   <Row ss:Height="30"><Cell ss:MergeAcross="9" ss:StyleID="Title"><Data ss:Type="String">TỔNG HỢP LỊCH DẠY TUẦN <?php echo excelXmlText($weekStart->format('d/m/Y') . ' - ' . $weekEnd->format('d/m/Y')); ?></Data></Cell></Row>
   <Row><Cell ss:MergeAcross="9" ss:StyleID="Subtitle"><Data ss:Type="String">Phạm vi: <?php echo excelXmlText($teacherName); ?> · Xuất lúc <?php echo excelXmlText(date('d/m/Y H:i')); ?></Data></Cell></Row>
   <Row/>
   <Row><Cell ss:MergeAcross="9" ss:StyleID="Section"><Data ss:Type="String">TỔNG HỢP THEO CA HỌC</Data></Cell></Row>
   <Row><?php echo excelCell('Ca học', 'String', 'Header') . excelCell('Số buổi', 'String', 'Header') . excelCell('Số lớp', 'String', 'Header') . excelCell('Tổng lượt học viên', 'String', 'Header'); ?><Cell ss:MergeAcross="5"/></Row>
<?php foreach ($summaries as $summary): ?>
   <Row><?php echo excelCell($summary['label'], 'String', 'Body') . excelCell($summary['sessions'], 'Number', 'Number') . excelCell(count($summary['classes']), 'Number', 'Number') . excelCell($summary['students'], 'Number', 'Number'); ?></Row>
<?php endforeach; ?>
   <Row/>
   <Row><Cell ss:MergeAcross="9" ss:StyleID="Section"><Data ss:Type="String">CHI TIẾT TỪNG BUỔI HỌC</Data></Cell></Row>
   <Row><?php foreach (['STT','Ngày','Thứ','Ca học','Giờ học','Lớp','Giáo viên phụ trách','Giáo viên dạy thay','Số học viên','Danh sách học viên'] as $heading) echo excelCell($heading, 'String', 'Header'); ?></Row>
<?php if (!$rows): ?>
   <Row><Cell ss:MergeAcross="9" ss:StyleID="Body"><Data ss:Type="String">Không có buổi học trong tuần này.</Data></Cell></Row>
<?php else:
$previousDetailDate = '';
foreach ($rows as $index => $row):
    $date = new DateTimeImmutable((string) $row['teaching_date']);
    $weekday = $weekdayLabels[(int) $date->format('N') - 1];
    $time = substr((string) $row['start_time'], 0, 5)
        . ' - ' . substr((string) $row['end_time'], 0, 5);
    if ($previousDetailDate !== (string) $row['teaching_date']):
        $previousDetailDate = (string) $row['teaching_date'];
?>
   <Row ss:Height="22"><Cell ss:MergeAcross="9" ss:StyleID="DateGroup"><Data ss:Type="String"><?php echo excelXmlText('NGÀY ' . $date->format('d/m/Y') . ' · ' . $weekday); ?></Data></Cell></Row>
<?php endif; ?>
   <Row><?php
    echo excelCell($index + 1, 'Number', 'Number');
    echo excelCell($date->format('d/m/Y'), 'String', 'Body');
    echo excelCell($weekday, 'String', 'Body');
    echo excelCell($shiftLabels[$row['_shift']] ?? 'Ca học', 'String', 'Body');
    echo excelCell($time, 'String', 'Body');
    echo excelCell($row['display_name'], 'String', 'Body');
    echo excelCell($row['teacher_name'] ?: 'Chưa phân công', 'String', 'Body');
    echo excelCell($row['substitute_teacher_name'] ?: '', 'String', 'Body');
    echo excelCell((int) $row['student_count'], 'Number', 'Number');
    echo excelCell($row['student_names'] ?: '', 'String', 'Body');
   ?></Row>
<?php endforeach; endif; ?>
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><FreezePanes/><FrozenNoSplit/><SplitHorizontal>10</SplitHorizontal><TopRowBottomPane>10</TopRowBottomPane><ActivePane>2</ActivePane><Selected/></WorksheetOptions>
 </Worksheet>
 <Worksheet ss:Name="Bảng lịch tuần">
  <Table>
   <Column ss:Width="230"/><?php foreach ($weekDays as $_): ?><Column ss:Width="105"/><?php endforeach; ?>
   <Row ss:Height="30"><Cell ss:MergeAcross="7" ss:StyleID="Title"><Data ss:Type="String">BẢNG LỊCH DẠY TUẦN <?php echo excelXmlText($weekStart->format('d/m/Y') . ' - ' . $weekEnd->format('d/m/Y')); ?></Data></Cell></Row>
   <Row><Cell ss:MergeAcross="7" ss:StyleID="Subtitle"><Data ss:Type="String">Phạm vi: <?php echo excelXmlText($teacherName); ?></Data></Cell></Row>
   <Row ss:Height="34">
    <?php echo excelCell('LỚP / HỌC VIÊN', 'String', 'MatrixHeader'); ?>
<?php foreach ($weekDays as $day): $isWeekend = (int) $day->format('N') >= 6; ?>
    <?php echo excelCell($weekdayLabels[(int) $day->format('N') - 1] . "\n" . $day->format('d/m'), 'String', $isWeekend ? 'MatrixWeekend' : 'MatrixHeader'); ?>
<?php endforeach; ?>
   </Row>
<?php
$shiftStyleIds = ['morning' => 'ShiftMorning', 'afternoon' => 'ShiftAfternoon', 'evening' => 'ShiftEvening'];
foreach ($matrixShiftGroups as $shiftKey => $classesInShift):
?>
   <Row ss:Height="24"><Cell ss:MergeAcross="7" ss:StyleID="<?php echo $shiftStyleIds[$shiftKey]; ?>"><Data ss:Type="String"><?php echo excelXmlText($matrixShiftTitles[$shiftKey]); ?></Data></Cell></Row>
<?php if (!$classesInShift): ?>
   <Row><Cell ss:MergeAcross="7" ss:StyleID="MatrixEmpty"><Data ss:Type="String">Chưa có lớp trong ca này.</Data></Cell></Row>
<?php else: foreach ($classesInShift as $class):
    $classShiftLabel = $shiftLabels[$shiftKey] ?? 'Ca học';
    $classDescription = (string) $class['display_name']
        . "\n" . $classShiftLabel . ' · ' . ((string) ($class['teacher_name'] ?: 'Chưa phân công')) . ' · ' . (int) $class['student_count'] . ' học viên'
        . ((string) ($class['student_names'] ?? '') !== '' ? "\n" . (string) $class['student_names'] : '');
?>
   <Row ss:Height="58">
    <?php echo excelCell($classDescription, 'String', 'MatrixClass'); ?>
<?php foreach ($weekDays as $day):
    $cellTimes = $matrixSlots[(int) $class['id']][$day->format('Y-m-d')] ?? [];
?>
    <?php echo excelCell(implode("\n", $cellTimes), 'String', $cellTimes ? 'MatrixCell' : 'MatrixEmpty'); ?>
<?php endforeach; ?>
   </Row>
<?php endforeach; endif; endforeach; ?>
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><FreezePanes/><FrozenNoSplit/><SplitHorizontal>3</SplitHorizontal><TopRowBottomPane>3</TopRowBottomPane><SplitVertical>1</SplitVertical><LeftColumnRightPane>1</LeftColumnRightPane><ActivePane>0</ActivePane></WorksheetOptions>
 </Worksheet>
</Workbook>
