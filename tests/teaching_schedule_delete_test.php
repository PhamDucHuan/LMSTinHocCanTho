<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/teaching_schedule_management.php';

function deleteAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function deleteReject(callable $action, string $exception): void
{
    try { $action(); }
    catch (Throwable $error) {
        deleteAssert($error instanceof $exception, 'Unexpected error: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected failure: ' . $exception);
}

function deleteFixture(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys=ON');
    // RESTRICT verifies explicit dependency cleanup, even without cascade support.
    $pdo->exec('CREATE TABLE teaching_classes (id INTEGER PRIMARY KEY, class_name TEXT, teacher_id INTEGER, total_sessions INTEGER,
        planned_weekdays TEXT, planned_start_date TEXT, planned_start_time TEXT, planned_end_time TEXT,
        planned_second_start_time TEXT, planned_second_end_time TEXT)');
    $pdo->exec('CREATE TABLE teaching_class_students (id INTEGER PRIMARY KEY, teaching_class_id INTEGER REFERENCES teaching_classes(id), student_name TEXT)');
    $pdo->exec('CREATE TABLE teaching_class_teachers (teaching_class_id INTEGER REFERENCES teaching_classes(id), teacher_id INTEGER)');
    $pdo->exec("CREATE TABLE teaching_schedule_slots (id INTEGER PRIMARY KEY AUTOINCREMENT,
        teaching_class_id INTEGER REFERENCES teaching_classes(id), teaching_date TEXT, start_time TEXT, end_time TEXT,
        attendance_status TEXT NOT NULL DEFAULT 'pending', makeup_student_id INTEGER REFERENCES teaching_class_students(id),
        is_makeup INTEGER NOT NULL DEFAULT 0, created_by INTEGER)");
    $pdo->exec('CREATE TABLE teaching_schedule_student_attendance (slot_id INTEGER REFERENCES teaching_schedule_slots(id),
        student_id INTEGER REFERENCES teaching_class_students(id), attendance_status TEXT, PRIMARY KEY(slot_id,student_id))');
    $pdo->exec('CREATE TABLE learning_classes (id INTEGER PRIMARY KEY, legacy_teaching_class_id INTEGER, class_name TEXT)');
    $pdo->exec("INSERT INTO teaching_classes VALUES
        (1,'Nhóm A',10,0,NULL,NULL,NULL,NULL,NULL,NULL),
        (2,'Nhóm B',20,0,NULL,NULL,NULL,NULL,NULL,NULL),
        (3,'Lịch tự tạo',10,1,'1,2,3,4,5,6,7','2026-11-09','08:00:00','11:00:00',NULL,NULL)");
    $pdo->exec("INSERT INTO teaching_class_students VALUES (101,1,'An'),(102,1,'Bình'),(201,2,'Chi'),(301,3,'Dũng')");
    $pdo->exec('INSERT INTO teaching_class_teachers VALUES (1,10),(1,20),(2,20)');
    $pdo->exec("INSERT INTO teaching_schedule_slots (id,teaching_class_id,teaching_date,start_time,end_time,attendance_status,makeup_student_id) VALUES
        (1,1,'2026-11-09','08:00:00','11:00:00','present',NULL),
        (2,1,'2026-11-10','08:00:00','11:00:00','present',101),
        (3,1,'2026-11-11','08:00:00','11:00:00','pending',NULL),
        (4,2,'2026-11-12','08:00:00','11:00:00','present',NULL),
        (10,3,'2026-11-09','08:00:00','11:00:00','present',NULL)");
    $pdo->exec("INSERT INTO teaching_schedule_student_attendance VALUES (1,101,'present'),(1,102,'absent'),(2,101,'present'),(4,201,'present'),(10,301,'present')");
    $pdo->exec("INSERT INTO learning_classes VALUES (91,1,'Lớp học độc lập')");
    return $pdo;
}

$pdo = deleteFixture();
deleteAssert(teachingScheduleDeleteClass($pdo, 1, 10, false) === 'Nhóm A', 'Owner class deletion failed.');
foreach (['teaching_classes' => 'id=1', 'teaching_class_students' => 'teaching_class_id=1',
          'teaching_class_teachers' => 'teaching_class_id=1', 'teaching_schedule_slots' => 'teaching_class_id=1',
          'teaching_schedule_student_attendance' => 'student_id IN (101,102)'] as $table => $condition) {
    deleteAssert((int) $pdo->query('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $condition)->fetchColumn() === 0, 'Schedule relation was not deleted: ' . $table);
}
deleteAssert((int) $pdo->query('SELECT COUNT(*) FROM teaching_schedule_slots WHERE id=4')->fetchColumn() === 1, 'Another class slot was deleted.');
deleteAssert((int) $pdo->query('SELECT COUNT(*) FROM teaching_schedule_student_attendance WHERE student_id=201')->fetchColumn() === 1, 'Another class attendance was deleted.');
deleteAssert((int) $pdo->query('SELECT COUNT(*) FROM learning_classes WHERE id=91')->fetchColumn() === 1, 'Independent learning class was deleted.');
deleteReject(fn() => teachingScheduleDeleteClass($pdo, 1, 10, false), RuntimeException::class);
deleteReject(fn() => teachingScheduleDeleteClass($pdo, 2, 10, false), DomainException::class);
deleteReject(fn() => teachingScheduleDeleteClass($pdo, 2, 0, true), DomainException::class);
deleteAssert(! $pdo->inTransaction(), 'Failed deletion left a transaction open.');
teachingScheduleDeleteClass($pdo, 2, 99, true);
deleteAssert((int) $pdo->query('SELECT COUNT(*) FROM teaching_classes WHERE id=2')->fetchColumn() === 0, 'Admin could not delete another owner class.');

$pdo = deleteFixture();
$pdo->exec("CREATE TRIGGER reject_class_delete BEFORE DELETE ON teaching_classes WHEN OLD.id=1 BEGIN SELECT RAISE(ABORT,'Simulated database failure'); END");
deleteReject(fn() => teachingScheduleDeleteClass($pdo, 1, 10, false), PDOException::class);
deleteAssert((int) $pdo->query('SELECT COUNT(*) FROM teaching_schedule_slots WHERE teaching_class_id=1')->fetchColumn() === 3, 'Class deletion failure did not restore slots.');
deleteAssert((int) $pdo->query('SELECT COUNT(*) FROM teaching_schedule_student_attendance WHERE student_id IN (101,102)')->fetchColumn() === 3, 'Class deletion failure did not restore attendance.');
deleteAssert((int) $pdo->query('SELECT COUNT(*) FROM teaching_class_teachers WHERE teaching_class_id=1')->fetchColumn() === 2, 'Class deletion failure did not restore teachers.');
deleteAssert(! $pdo->inTransaction(), 'Class deletion rollback left transaction open.');

$pdo = deleteFixture();
$manualResult = teachingScheduleDeleteSlot($pdo, 1, 1, 10, false);
deleteAssert($manualResult['class_id'] === 1 && $manualResult['deleted_slot_ids'] === [1], 'Delete response must identify the removed slot and class.');
deleteAssert($manualResult['added_slots'] === [] && $manualResult['class_progress'] === ['learned' => 0, 'total' => 1], 'Manual delete response must return current progress without replacement slots.');
deleteAssert((int) $pdo->query('SELECT COUNT(*) FROM teaching_schedule_slots WHERE id=1')->fetchColumn() === 0, 'Manual slot was not deleted.');
deleteAssert((int) $pdo->query('SELECT COUNT(*) FROM teaching_schedule_student_attendance WHERE slot_id=1')->fetchColumn() === 0, 'Deleted slot attendance remains.');
deleteAssert((int) $pdo->query('SELECT COUNT(*) FROM teaching_schedule_slots WHERE teaching_class_id=1')->fetchColumn() === 2, 'Manual deletion affected other sessions.');
deleteReject(fn() => teachingScheduleDeleteSlot($pdo, 1, 4, 10, false), RuntimeException::class);
deleteReject(fn() => teachingScheduleDeleteSlot($pdo, 2, 4, 10, false), DomainException::class);
$lastManualResult = teachingScheduleDeleteSlot($pdo, 2, 4, 99, true);
deleteAssert($lastManualResult['class_progress'] === ['learned' => 0, 'total' => 0], 'Deleting the only manual session must return zero progress.');
deleteAssert((int) $pdo->query('SELECT COUNT(*) FROM teaching_schedule_slots WHERE id=4')->fetchColumn() === 0, 'Admin slot deletion failed.');

$pdo = deleteFixture();
$automaticResult = teachingScheduleDeleteSlot($pdo, 3, 10, 10, false);
$replacement = $pdo->query('SELECT * FROM teaching_schedule_slots WHERE teaching_class_id=3')->fetchAll(PDO::FETCH_ASSOC);
deleteAssert(count($replacement) === 1 && $replacement[0]['teaching_date'] === '2026-11-10', 'Deleting the only automatic session recreated it on its original date.');
deleteAssert((int) $replacement[0]['id'] !== 10 && $replacement[0]['attendance_status'] === 'pending', 'Replacement must be a new unconfirmed session.');
deleteAssert(count($automaticResult['added_slots']) === 1
    && $automaticResult['added_slots'][0]['id'] === (int) $replacement[0]['id']
    && $automaticResult['added_slots'][0]['class_id'] === 3
    && $automaticResult['added_slots'][0]['date'] === '2026-11-10'
    && $automaticResult['added_slots'][0]['start_time'] === '08:00'
    && $automaticResult['added_slots'][0]['end_time'] === '11:00'
    && $automaticResult['added_slots'][0]['attendance_status'] === 'pending', 'Response must include the complete new slot for updating the calendar without reload.');
deleteAssert($automaticResult['class_progress'] === ['learned' => 0, 'total' => 1], 'Automatic replacement progress must match the database.');
deleteAssert((int) $pdo->query('SELECT total_sessions FROM teaching_classes WHERE id=3')->fetchColumn() === 1, 'Deletion changed configured session total.');
deleteAssert((int) $pdo->query('SELECT COUNT(*) FROM teaching_schedule_student_attendance WHERE slot_id=10')->fetchColumn() === 0, 'Deleted automatic session retained attendance.');

$pdo = deleteFixture();
$pdo->exec("UPDATE teaching_classes SET total_sessions=2,planned_second_start_time='14:00:00',planned_second_end_time='17:00:00' WHERE id=3");
$pdo->exec("INSERT INTO teaching_schedule_slots (id,teaching_class_id,teaching_date,start_time,end_time) VALUES (11,3,'2026-11-09','14:00:00','17:00:00')");
teachingScheduleDeleteSlot($pdo, 3, 10, 10, false);
$twoShiftResult = teachingScheduleDeleteSlot($pdo, 3, 11, 10, false);
deleteAssert($twoShiftResult['class_progress'] === ['learned' => 0, 'total' => 2], 'Two-shift deletion must return refreshed class totals.');
deleteAssert((int) $pdo->query("SELECT COUNT(*) FROM teaching_schedule_slots WHERE teaching_class_id=3 AND teaching_date='2026-11-09'")->fetchColumn() === 0, 'Deleted morning/afternoon sessions were recreated on the same day.');
deleteAssert((int) $pdo->query('SELECT COUNT(*) FROM teaching_schedule_slots WHERE teaching_class_id=3')->fetchColumn() === 2, 'Two-shift replacement changed planned total.');

$pdo = deleteFixture();
$pdo->exec('UPDATE teaching_classes SET total_sessions=3 WHERE id=3');
$pdo->exec("INSERT INTO teaching_schedule_slots (id,teaching_class_id,teaching_date,start_time,end_time,makeup_student_id) VALUES (11,3,'2026-11-10','08:00:00','11:00:00',301)");
$makeupResult = teachingScheduleDeleteSlot($pdo, 3, 11, 10, false);
deleteAssert($makeupResult['added_slots'] === [] && $makeupResult['class_progress'] === ['learned' => 1, 'total' => 3], 'Deleting a personal makeup must not change regular learned progress.');
deleteAssert((int) $pdo->query('SELECT COUNT(*) FROM teaching_schedule_slots WHERE teaching_class_id=3')->fetchColumn() === 1, 'Deleting a personal makeup unexpectedly regenerated regular sessions.');

$pdo = deleteFixture();
$pdo->exec("CREATE TRIGGER reject_replacement BEFORE INSERT ON teaching_schedule_slots WHEN NEW.teaching_class_id=3 BEGIN SELECT RAISE(ABORT,'Simulated replacement failure'); END");
deleteReject(fn() => teachingScheduleDeleteSlot($pdo, 3, 10, 10, false), PDOException::class);
deleteAssert((int) $pdo->query('SELECT COUNT(*) FROM teaching_schedule_slots WHERE id=10')->fetchColumn() === 1, 'Failed replacement did not restore original session.');
deleteAssert((int) $pdo->query('SELECT COUNT(*) FROM teaching_schedule_student_attendance WHERE slot_id=10')->fetchColumn() === 1, 'Failed replacement did not restore attendance.');
deleteAssert(! $pdo->inTransaction(), 'Slot failure left transaction open.');

echo "[PASS] Class/slot deletion, child cleanup, ownership and admin scope, independent learning classes, rollback, single-session and two-shift automatic schedules, personal makeups.\n";
