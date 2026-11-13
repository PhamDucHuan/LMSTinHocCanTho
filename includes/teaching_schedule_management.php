<?php
declare(strict_types=1);

require_once __DIR__ . '/schedule_time.php';

function plannedWeekdays(mixed $value): array
{
    $values = is_array($value) ? $value : explode(',', (string) $value);
    $days = array_values(array_unique(array_filter(array_map('intval', $values), static fn(int $day): bool => $day >= 1 && $day <= 7)));
    sort($days);
    return $days;
}

function plannedDailyTimeRanges(array $config): array
{
    $ranges = [];
    $start = scheduleTime24($config['planned_start_time'] ?? '');
    $end = scheduleTime24($config['planned_end_time'] ?? '');
    if ($start !== '' && $end !== '' && $start < $end) {
        $ranges[] = [$start, $end];
    }
    $secondStart = scheduleTime24($config['planned_second_start_time'] ?? '');
    $secondEnd = scheduleTime24($config['planned_second_end_time'] ?? '');
    if ($secondStart !== '' && $secondEnd !== ''
        && $secondStart < $secondEnd && (!$ranges || $secondStart >= $ranges[0][1])) {
        $ranges[] = [$secondStart, $secondEnd];
    }
    return $ranges;
}

function appendPlannedSlots(PDO $pdo, int $classId, int $userId, ?string $notBeforeDate = null): void
{
    $configStmt = $pdo->prepare('SELECT total_sessions, planned_weekdays, planned_start_date, planned_start_time, planned_end_time, planned_second_start_time, planned_second_end_time FROM teaching_classes WHERE id=?');
    $configStmt->execute([$classId]);
    $config = $configStmt->fetch(PDO::FETCH_ASSOC);
    $days = plannedWeekdays($config['planned_weekdays'] ?? '');
    $total = (int) ($config['total_sessions'] ?? 0);
    $dailyRanges = plannedDailyTimeRanges($config ?: []);
    if ($total <= 0 || !$days || empty($config['planned_start_date']) || !$dailyRanges) return;
    // Buổi vắng được giữ lại làm lịch sử, nhưng không tính vào số buổi thực học
    // đã cấu hình. Vì vậy mỗi lần có buổi vắng, lịch sẽ được nối thêm ở phía sau.
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM teaching_schedule_slots WHERE teaching_class_id=? AND makeup_student_id IS NULL AND attendance_status<>'absent'");
    $countStmt->execute([$classId]);
    $remaining = $total - (int) $countStmt->fetchColumn();
    if ($remaining <= 0) return;
    $lastStmt = $pdo->prepare('SELECT MAX(teaching_date) FROM teaching_schedule_slots WHERE teaching_class_id=? AND makeup_student_id IS NULL');
    $lastStmt->execute([$classId]);
    $lastDate = $lastStmt->fetchColumn();
    // Khi vừa xóa buổi cuối, MAX() sẽ lùi về buổi trước đó. Lấy thêm ngày vừa
    // xóa làm mốc để buổi bù được tạo ở phía sau, không tự xuất hiện lại đúng ô đó.
    $anchorDate = $lastDate ?: $config['planned_start_date'];
    if ((string) $config['planned_start_date'] > $anchorDate) $anchorDate = (string) $config['planned_start_date'];
    if ($notBeforeDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $notBeforeDate) && $notBeforeDate > $anchorDate) {
        $anchorDate = $notBeforeDate;
    }
    $cursor = new DateTimeImmutable($lastDate && $anchorDate === $lastDate ? $anchorDate . ' +1 day' : $anchorDate);
    $insert = $pdo->prepare('INSERT INTO teaching_schedule_slots (teaching_class_id, teaching_date, start_time, end_time, is_makeup, created_by) VALUES (?, ?, ?, ?, 0, ?)');
    for ($guard = 0; $remaining > 0 && $guard < 3660; $guard++, $cursor = $cursor->modify('+1 day')) {
        if (in_array((int) $cursor->format('N'), $days, true)) {
            foreach ($dailyRanges as [$rangeStart, $rangeEnd]) {
                if ($remaining <= 0) break;
                $insert->execute([$classId, $cursor->format('Y-m-d'), $rangeStart, $rangeEnd, $userId]);
                $remaining--;
            }
        }
    }
}

function rebalancePlannedSchedule(PDO $pdo, int $classId, int $userId, bool $trimExcess = false, ?string $notBeforeDate = null, ?int $protectedSlotId = null): void
{
    $configStmt = $pdo->prepare('SELECT total_sessions FROM teaching_classes WHERE id=?');
    $configStmt->execute([$classId]);
    $total = (int) $configStmt->fetchColumn();
    if ($total <= 0) return;
    if ($trimExcess) {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM teaching_schedule_slots WHERE teaching_class_id=? AND makeup_student_id IS NULL AND attendance_status<>'absent'");
        $deleteStmt = $pdo->prepare('DELETE FROM teaching_schedule_slots WHERE id=? AND teaching_class_id=?');
        for ($guard = 0; $guard < 500; $guard++) {
            $countStmt->execute([$classId]);
            if ((int) $countStmt->fetchColumn() <= $total) break;

            // Chỉ gỡ một buổi chưa diễn ra ở cuối lịch. Không bao giờ xóa buổi
            // đã xác nhận Có học hoặc buổi Vắng đang được giữ làm lịch sử.
            $params = [$classId];
            $excludeSql = '';
            if ($protectedSlotId !== null && $protectedSlotId > 0) {
                $excludeSql = ' AND id<>?';
                $params[] = $protectedSlotId;
            }
            $lastPending = $pdo->prepare("SELECT id FROM teaching_schedule_slots WHERE teaching_class_id=? AND makeup_student_id IS NULL AND attendance_status='pending' AND teaching_date>=CURDATE(){$excludeSql} ORDER BY teaching_date DESC, start_time DESC, end_time DESC, id DESC LIMIT 1");
            $lastPending->execute($params);
            $slotId = (int) $lastPending->fetchColumn();
            if ($slotId <= 0) break;
            $deleteStmt->execute([$slotId, $classId]);
        }
    }
    appendPlannedSlots($pdo, $classId, $userId, $notBeforeDate);
}

/** Delete related schedule data in one transaction; learning classes stay independent. */
function teachingScheduleDeleteClass(PDO $pdo, int $classId, int $actorId, bool $canManageAll): string
{
    if ($classId <= 0 || $actorId <= 0) throw new DomainException('Bạn không có quyền xóa nhóm lịch này.');
    $pdo->beginTransaction();
    try {
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $lookup = $pdo->prepare('SELECT class_name, teacher_id FROM teaching_classes WHERE id=?' . $lock);
        $lookup->execute([$classId]);
        $class = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$class) throw new RuntimeException('Nhóm lịch không còn tồn tại hoặc đã được xóa.');
        if (!$canManageAll && (int) $class['teacher_id'] !== $actorId) {
            throw new DomainException('Bạn không có quyền xóa nhóm lịch này.');
        }
        $pdo->prepare('DELETE FROM teaching_schedule_student_attendance
            WHERE slot_id IN (SELECT id FROM teaching_schedule_slots WHERE teaching_class_id=?)
               OR student_id IN (SELECT id FROM teaching_class_students WHERE teaching_class_id=?)')->execute([$classId, $classId]);
        $pdo->prepare('DELETE FROM teaching_schedule_slots WHERE teaching_class_id=?')->execute([$classId]);
        $pdo->prepare('DELETE FROM teaching_class_teachers WHERE teaching_class_id=?')->execute([$classId]);
        $pdo->prepare('DELETE FROM teaching_class_students WHERE teaching_class_id=?')->execute([$classId]);
        $delete = $pdo->prepare('DELETE FROM teaching_classes WHERE id=?');
        $delete->execute([$classId]);
        if ($delete->rowCount() !== 1) throw new RuntimeException('Không thể xóa nhóm lịch. Vui lòng thử lại.');
        $pdo->commit();
        return (string) $class['class_name'];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** The replacement for a deleted slot must start strictly after the deleted date. */
function teachingScheduleDeleteSlot(PDO $pdo, int $classId, int $slotId, int $actorId, bool $canManageAll): array
{
    if ($classId <= 0 || $slotId <= 0 || $actorId <= 0) throw new DomainException('Buổi dạy không hợp lệ.');
    $pdo->beginTransaction();
    try {
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $classLookup = $pdo->prepare('SELECT teacher_id, total_sessions FROM teaching_classes WHERE id=?' . $lock);
        $classLookup->execute([$classId]);
        $class = $classLookup->fetch(PDO::FETCH_ASSOC);
        if (!$class || (!$canManageAll && (int) $class['teacher_id'] !== $actorId)) {
            throw new DomainException('Bạn không có quyền xóa buổi dạy của lớp này.');
        }
        $lookup = $pdo->prepare('SELECT teaching_date, makeup_student_id FROM teaching_schedule_slots WHERE id=? AND teaching_class_id=?' . $lock);
        $lookup->execute([$slotId, $classId]);
        $slot = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$slot) throw new RuntimeException('Không tìm thấy buổi dạy hoặc buổi đã được xóa.');
        $lastIdLookup = $pdo->prepare('SELECT COALESCE(MAX(id),0) FROM teaching_schedule_slots WHERE teaching_class_id=?');
        $lastIdLookup->execute([$classId]);
        $lastId = (int) $lastIdLookup->fetchColumn();
        $pdo->prepare('DELETE FROM teaching_schedule_student_attendance WHERE slot_id=?')->execute([$slotId]);
        $delete = $pdo->prepare('DELETE FROM teaching_schedule_slots WHERE id=? AND teaching_class_id=?');
        $delete->execute([$slotId, $classId]);
        if ($delete->rowCount() !== 1) throw new RuntimeException('Không thể xóa buổi dạy. Vui lòng thử lại.');
        // Personal makeups do not reduce the regular class schedule.
        if (empty($slot['makeup_student_id'])) {
            $notBefore = (new DateTimeImmutable((string) $slot['teaching_date']))->modify('+1 day')->format('Y-m-d');
            rebalancePlannedSchedule($pdo, $classId, $actorId, false, $notBefore);
        }
        $addedLookup = $pdo->prepare('SELECT id, teaching_date, start_time, end_time, attendance_status, makeup_student_id
            FROM teaching_schedule_slots WHERE teaching_class_id=? AND id>? ORDER BY teaching_date, start_time, end_time, id');
        $addedLookup->execute([$classId, $lastId]);
        $addedSlots = [];
        foreach ($addedLookup->fetchAll(PDO::FETCH_ASSOC) as $added) {
            $addedSlots[] = [
                'id' => (int) $added['id'], 'class_id' => $classId, 'date' => $added['teaching_date'],
                'start_time' => scheduleTime24($added['start_time']), 'end_time' => scheduleTime24($added['end_time']),
                'attendance_status' => $added['attendance_status'], 'makeup_student_id' => $added['makeup_student_id'],
                'substitute_teacher_id' => null,
            ];
        }
        $progressLookup = $pdo->prepare("SELECT
            SUM(CASE WHEN makeup_student_id IS NULL THEN 1 ELSE 0 END) AS scheduled,
            SUM(CASE WHEN makeup_student_id IS NULL AND attendance_status='present' THEN 1 ELSE 0 END) AS learned
            FROM teaching_schedule_slots WHERE teaching_class_id=?");
        $progressLookup->execute([$classId]);
        $progress = $progressLookup->fetch(PDO::FETCH_ASSOC);
        $pdo->commit();
        return [
            'class_id' => $classId, 'deleted_slot_ids' => [$slotId], 'added_slots' => $addedSlots,
            'class_progress' => [
                'learned' => (int) $progress['learned'],
                'total' => (int) $class['total_sessions'] > 0 ? (int) $class['total_sessions'] : (int) $progress['scheduled'],
            ],
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

