<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/teaching_progress_export.php';

function visibilityAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE teaching_classes (id INTEGER PRIMARY KEY, class_name TEXT, teacher_id INTEGER, status TEXT, total_sessions INTEGER, sort_order INTEGER, planned_start_date TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE teaching_schedule_slots (id INTEGER PRIMARY KEY, teaching_class_id INTEGER, teaching_date TEXT, attendance_status TEXT, makeup_student_id INTEGER, substitute_teacher_id INTEGER)');
$pdo->exec("INSERT INTO users VALUES (10,'Giáo viên A'),(20,'Giáo viên B')");
$pdo->exec("INSERT INTO teaching_classes VALUES
    (1,'Đang hoạt động',10,'active',10,1,'2026-11-01','2026-10-01 08:00:00','2026-11-10 08:00:00'),
    (2,'Đã kết thúc',10,'completed',10,2,'2026-09-01','2026-08-01 08:00:00','2026-11-10 08:00:00'),
    (3,'Chưa đến ngày học',10,'active',10,3,'2026-12-01','2026-10-01 08:00:00','2026-11-10 08:00:00'),
    (4,'Chưa có lịch',10,'active',0,4,NULL,'2026-11-10 08:00:00','2026-11-10 08:00:00'),
    (5,'Tạm dừng',10,'paused',10,5,'2026-09-01','2026-08-01 08:00:00','2026-10-01 08:00:00'),
    (6,'Sửa lịch từ tháng sau',10,'completed',10,6,'2026-12-01','2026-10-01 08:00:00','2026-12-10 08:00:00'),
    (7,'Học bù cá nhân cuối khóa',20,'completed',10,7,'2026-10-01','2026-09-01 08:00:00','2026-11-20 08:00:00'),
    (8,'Kết thúc không có lịch',10,'completed',0,8,NULL,'2026-09-01 08:00:00','2026-09-30 08:00:00'),
    (9,'Chưa tới buổi đầu',10,'active',0,9,NULL,'2026-09-01 08:00:00','2026-10-01 08:00:00'),
    (10,'Bắt đầu chủ nhật',10,'active',10,10,'2026-11-08','2026-09-01 08:00:00','2026-10-01 08:00:00'),
    (11,'Bắt đầu thứ hai sau',10,'active',10,11,'2026-11-09','2026-09-01 08:00:00','2026-10-01 08:00:00')");
$pdo->exec("INSERT INTO teaching_schedule_slots VALUES
    (1,1,'2026-11-03','present',NULL,NULL),(2,1,'2026-11-30','pending',NULL,NULL),
    (3,2,'2026-09-02','present',NULL,NULL),(4,2,'2026-10-18','present',NULL,NULL),
    (5,3,'2026-12-02','pending',NULL,NULL),
    (6,6,'2026-10-05','present',NULL,NULL),(7,6,'2026-10-25','present',NULL,NULL),
    (8,7,'2026-10-01','present',NULL,NULL),(9,7,'2026-11-15','present',701,10),
    (10,9,'2026-12-02','pending',NULL,NULL),
    (11,10,'2026-11-10','pending',NULL,NULL),(12,11,'2026-11-10','pending',NULL,NULL)");

$visible = static function (string $start, string $end, bool $showCompleted = false) use ($pdo): array {
    $condition = teachingScheduleClassVisibilitySql($start, $end, $showCompleted);
    $statement = $pdo->prepare('SELECT tc.id FROM teaching_classes tc ' . teachingScheduleClassDatesJoinSql() . ' WHERE ' . $condition['sql'] . ' ORDER BY tc.id');
    $statement->execute($condition['params']);
    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
};
visibilityAssert($visible('2026-09-07', '2026-09-13') === [2,8], 'Completed classes must reappear during their active period, without future classes.');
visibilityAssert($visible('2026-10-12', '2026-10-18') === [2,6,7], 'Last session on Sunday must remain visible; moving the plan forward must not erase history.');
visibilityAssert($visible('2026-10-19', '2026-10-25') === [6,7], 'Completed class must disappear the week after its last session.');
visibilityAssert($visible('2026-11-02', '2026-11-08') === [1,7,10], 'Future start/creation dates must be hidden and Sunday start included.');
visibilityAssert($visible('2026-11-09', '2026-11-15') === [1,4,7,10,11], 'Current week should include historical class through its final personal makeup.');
visibilityAssert($visible('2026-11-16', '2026-11-22') === [1,4,10,11], 'Final makeup must not extend a completed class into subsequent weeks.');
visibilityAssert($visible('2026-12-01', '2026-12-31') === [1,3,4,9,10,11], 'Future classes should become visible when navigating to their start period.');
visibilityAssert($visible('2026-07-01', '2026-07-31', true) === [], 'Show-completed option must not reveal classes before their start.');
visibilityAssert(in_array(5, $visible('2026-11-02', '2026-11-08', true), true), 'Explicit inactive-class view should remain available.');
visibilityAssert(teachingScheduleExportWeek('2026-11-08') === ['2026-11-02','2026-11-08'], 'Sunday week boundary is incorrect.');
visibilityAssert(teachingScheduleExportWeek('2026-11-09') === ['2026-11-09','2026-11-15'], 'Monday week boundary is incorrect.');
foreach (['2026-02-30', 'not-a-date', '2026-11-08 OR 1=1'] as $date) {
    try {
        teachingScheduleExportWeek($date);
        throw new RuntimeException('Invalid date was accepted.');
    } catch (InvalidArgumentException $error) { }
}

// Progress exports must use the same historical list and preserve authorization.
$ids = static fn(array $classes): array => array_map('intval', array_column($classes, 'id'));
visibilityAssert($ids(teachingProgressExportClasses($pdo, 99, 'admin', ['date' => '2026-09-09'])) === [2,8], 'Export all must match the historical calendar.');
visibilityAssert($ids(teachingProgressExportClasses($pdo, 10, 'teacher', ['date' => '2026-11-10'])) === [1,4,7,10,11], 'Historical substitute scope was lost.');
visibilityAssert($ids(teachingProgressExportClasses($pdo, 99, 'admin', ['date' => '2026-10-18', 'mode' => 'selected', 'class_ids' => [2,6]])) === [2,6], 'Selected completed classes must be exportable during their active period.');
visibilityAssert($ids(teachingProgressExportClasses($pdo, 20, 'teacher', ['date' => '2026-10-18', 'mode' => 'single', 'class_id' => 7])) === [7], 'Single historical class export failed.');
try {
    teachingProgressExportClasses($pdo, 20, 'teacher', ['date' => '2026-10-18', 'mode' => 'single', 'class_id' => 2]);
    throw new RuntimeException('Historical class leaked outside authorized scope.');
} catch (DomainException $error) { }
try {
    teachingProgressExportClasses($pdo, 99, 'admin', ['date' => '2026-10-18', 'mode' => 'single', 'class_id' => 3]);
    throw new RuntimeException('Future class was exported from historical calendar.');
} catch (DomainException $error) { }

echo "[PASS] Historical completed classes; future start/creation dates; week boundaries; personal makeups; paused classes; calendar/export parity and authorization.\n";
