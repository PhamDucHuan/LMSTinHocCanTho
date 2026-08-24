<?php
declare(strict_types=1);

require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';
require_once '../includes/audit.php';

if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'teacher', 'administrative_staff'], true)) {
    header('Location: ../index.php');
    exit;
}
global $pdo;
$isAdmin = ($_SESSION['user_role'] ?? '') === 'admin';
$showOwnSchedule = $isAdmin && ((string) ($_REQUEST['scope'] ?? '')) === 'mine';
$canManageAllSchedules = $isAdmin && !$showOwnSchedule;

function canManageTeachingClass(PDO $pdo, int $classId, int $userId, bool $isAdmin): bool
{
    if ($isAdmin) return true;
    $statement = $pdo->prepare('SELECT 1 FROM teaching_classes WHERE id=? AND teacher_id=? LIMIT 1');
    $statement->execute([$classId, $userId]);
    return (bool) $statement->fetchColumn();
}

function plannedWeekdays(mixed $value): array
{
    $values = is_array($value) ? $value : explode(',', (string) $value);
    $days = array_values(array_unique(array_filter(array_map('intval', $values), static fn(int $day): bool => $day >= 1 && $day <= 7)));
    sort($days);
    return $days;
}

function teachingClassStudentNames(string $value): array
{
    // Chỉ tách theo xuống dòng thực tế. Không dùng \R vì một số ký tự Unicode
    // trong tên tiếng Việt có thể bị trình soạn thảo/IME chuẩn hóa không như mong muốn.
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $lines = preg_split('/\n/u', $value) ?: [];
    $names = array_map(static function (string $name): string {
        return preg_replace('/[\t ]+/u', ' ', trim($name)) ?? '';
    }, $lines);
    return array_values(array_unique(array_filter($names, static fn(string $name): bool => $name !== '')));
}

function appendPlannedSlots(PDO $pdo, int $classId, int $userId, ?string $notBeforeDate = null): void
{
    $configStmt = $pdo->prepare('SELECT total_sessions, planned_weekdays, planned_start_date, planned_start_time, planned_end_time FROM teaching_classes WHERE id=?');
    $configStmt->execute([$classId]);
    $config = $configStmt->fetch(PDO::FETCH_ASSOC);
    $days = plannedWeekdays($config['planned_weekdays'] ?? '');
    $total = (int) ($config['total_sessions'] ?? 0);
    if ($total <= 0 || !$days || empty($config['planned_start_date']) || empty($config['planned_start_time']) || empty($config['planned_end_time'])) return;
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM teaching_schedule_slots WHERE teaching_class_id=?');
    $countStmt->execute([$classId]);
    $remaining = $total - (int) $countStmt->fetchColumn();
    if ($remaining <= 0) return;
    $lastStmt = $pdo->prepare('SELECT MAX(teaching_date) FROM teaching_schedule_slots WHERE teaching_class_id=?');
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
            $insert->execute([$classId, $cursor->format('Y-m-d'), $config['planned_start_time'], $config['planned_end_time'], $userId]);
            $remaining--;
        }
    }
}

function rebalancePlannedSchedule(PDO $pdo, int $classId, int $userId, bool $addedMakeup = false, ?string $notBeforeDate = null): void
{
    $configStmt = $pdo->prepare('SELECT total_sessions FROM teaching_classes WHERE id=?');
    $configStmt->execute([$classId]);
    $total = (int) $configStmt->fetchColumn();
    if ($total <= 0) return;
    if ($addedMakeup) {
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM teaching_schedule_slots WHERE teaching_class_id=?');
        $countStmt->execute([$classId]);
        if ((int) $countStmt->fetchColumn() > $total) {
            $lastPlanned = $pdo->prepare('SELECT id FROM teaching_schedule_slots WHERE teaching_class_id=? AND is_makeup=0 ORDER BY teaching_date DESC, start_time DESC, id DESC LIMIT 1');
            $lastPlanned->execute([$classId]);
            $slotId = (int) $lastPlanned->fetchColumn();
            if ($slotId > 0) $pdo->prepare('DELETE FROM teaching_schedule_slots WHERE id=?')->execute([$slotId]);
        }
    }
    appendPlannedSlots($pdo, $classId, $userId, $notBeforeDate);
}

function scheduleResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $payload = json_decode((string) file_get_contents('php://input'), true) ?: [];
    try {
        verifyCsrfToken((string) ($payload['csrf_token'] ?? ''));
        $action = (string) ($payload['action'] ?? '');
        if ($action === 'create_shift_preset') {
            $name = trim((string) ($payload['name'] ?? ''));
            $start = (string) ($payload['start_time'] ?? '');
            $end = (string) ($payload['end_time'] ?? '');
            $majorShift = (string) ($payload['major_shift'] ?? '');
            if ($name === '' || mb_strlen($name) > 80 || !in_array($majorShift, ['morning', 'afternoon', 'evening'], true) || !preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || $start >= $end) {
                throw new RuntimeException('Tên ca hoặc khung giờ không hợp lệ.');
            }
            $existingPreset = $pdo->prepare('SELECT 1 FROM teaching_shift_presets WHERE name=? LIMIT 1');
            $existingPreset->execute([$name]);
            if ($existingPreset->fetchColumn()) throw new RuntimeException('Tên ca này đã tồn tại. Hãy dùng tên khác hoặc chọn ca có sẵn.');
            $stmt = $pdo->prepare('INSERT INTO teaching_shift_presets (name, start_time, end_time, major_shift, created_by) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$name, $start, $end, $majorShift, (int) $_SESSION['user_id']]);
            $presetId = (int) $pdo->lastInsertId();
            writeAuditLog($pdo, 'teaching_schedule.shift_preset_created', 'teaching_shift_preset', $presetId, ['name' => $name, 'start_time' => $start, 'end_time' => $end]);
            scheduleResponse(['ok' => true, 'preset' => ['id' => $presetId, 'name' => $name, 'start_time' => $start, 'end_time' => $end, 'major_shift' => $majorShift]]);
        }
        if ($action === 'reorder_classes') {
            $classIds = array_values(array_unique(array_filter(array_map('intval', (array) ($payload['class_ids'] ?? [])))));
            if (!$classIds) throw new RuntimeException('Danh sách lớp không hợp lệ.');
            foreach ($classIds as $classId) {
                if (!canManageTeachingClass($pdo, $classId, (int) $_SESSION['user_id'], $canManageAllSchedules)) {
                    throw new RuntimeException('Bạn không có quyền sắp xếp một hoặc nhiều lớp.');
                }
            }
            $pdo->beginTransaction();
            try {
                $update = $pdo->prepare('UPDATE teaching_classes SET sort_order=? WHERE id=?');
                foreach ($classIds as $position => $classId) $update->execute([($position + 1) * 10, $classId]);
                $pdo->commit();
                writeAuditLog($pdo, 'teaching_schedule.classes_reordered', 'teaching_class', null, ['class_ids' => $classIds]);
                scheduleResponse(['ok' => true]);
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $error;
            }
        }
        $classId = (int) ($payload['class_id'] ?? 0);
        if ($classId <= 0) throw new RuntimeException('Lớp học không hợp lệ.');
        if (!canManageTeachingClass($pdo, $classId, (int) $_SESSION['user_id'], $canManageAllSchedules)) throw new RuntimeException('Bạn không có quyền sửa lịch của lớp này.');

        if ($action === 'get_class') {
            $stmt = $pdo->prepare('SELECT tc.id, tc.class_name, tc.notes, tc.total_sessions, tc.planned_weekdays, tc.planned_start_date, tc.planned_start_time, tc.planned_end_time, tc.time_shift, tc.course_id, tc.teacher_id, tc.status, GROUP_CONCAT(tcs.student_name ORDER BY tcs.student_name SEPARATOR "\\n") AS students FROM teaching_classes tc LEFT JOIN teaching_class_students tcs ON tcs.teaching_class_id=tc.id WHERE tc.id=? GROUP BY tc.id');
            $stmt->execute([$classId]);
            $class = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$class) throw new RuntimeException('Không tìm thấy lớp học.');
            scheduleResponse(['ok' => true, 'class' => $class]);
        }

        if ($action === 'save_slot') {
            $date = (string) ($payload['date'] ?? '');
            $start = (string) ($payload['start_time'] ?? '');
            $end = (string) ($payload['end_time'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || $start >= $end) {
                throw new RuntimeException('Ngày hoặc khung giờ không hợp lệ.');
            }
            $slotId = (int) ($payload['slot_id'] ?? 0);
            if ($slotId > 0) {
                $slotLookup = $pdo->prepare('SELECT 1 FROM teaching_schedule_slots WHERE id=? AND teaching_class_id=? LIMIT 1');
                $slotLookup->execute([$slotId, $classId]);
                if (!$slotLookup->fetchColumn()) throw new RuntimeException('Không tìm thấy buổi dạy cần sửa.');
                $classConfig = $pdo->prepare('SELECT planned_weekdays FROM teaching_classes WHERE id=?');
                $classConfig->execute([$classId]);
                $isMakeup = in_array((int) (new DateTimeImmutable($date))->format('N'), plannedWeekdays((string) $classConfig->fetchColumn()), true) ? 0 : 1;
                $substituteTeacherId = (int) ($payload['substitute_teacher_id'] ?? 0) ?: null;
                $stmt = $pdo->prepare('UPDATE teaching_schedule_slots SET teaching_date=?, start_time=?, end_time=?, is_makeup=?, substitute_teacher_id=? WHERE id=? AND teaching_class_id=?');
                $stmt->execute([$date, $start, $end, $isMakeup, $substituteTeacherId, $slotId, $classId]);
            } else {
                $classConfig = $pdo->prepare('SELECT planned_weekdays FROM teaching_classes WHERE id=?');
                $classConfig->execute([$classId]);
                $isMakeup = in_array((int) (new DateTimeImmutable($date))->format('N'), plannedWeekdays((string) $classConfig->fetchColumn()), true) ? 0 : 1;
                $substituteTeacherId = (int) ($payload['substitute_teacher_id'] ?? 0) ?: null;
                $stmt = $pdo->prepare('INSERT INTO teaching_schedule_slots (teaching_class_id, teaching_date, start_time, end_time, is_makeup, substitute_teacher_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$classId, $date, $start, $end, $isMakeup, $substituteTeacherId, (int) $_SESSION['user_id']]);
                $slotId = (int) $pdo->lastInsertId();
                rebalancePlannedSchedule($pdo, $classId, (int) $_SESSION['user_id'], $isMakeup === 1);
            }
            writeAuditLog($pdo, 'teaching_schedule.slot_saved', 'teaching_schedule_slot', $slotId, ['class_id' => $classId, 'date' => $date]);
            scheduleResponse(['ok' => true, 'slot' => [
                'id' => $slotId,
                'class_id' => $classId,
                'date' => $date,
                'start_time' => $start,
                'end_time' => $end,
                'substitute_teacher_id' => $substituteTeacherId,
            ]]);
        }
        if ($action === 'delete_slot') {
            $slotId = (int) ($payload['slot_id'] ?? 0);
            if ($slotId <= 0) throw new RuntimeException('Buổi dạy không hợp lệ.');

            $pdo->beginTransaction();
            try {
                $slotLookup = $pdo->prepare('SELECT teaching_date FROM teaching_schedule_slots WHERE id=? AND teaching_class_id=? FOR UPDATE');
                $slotLookup->execute([$slotId, $classId]);
                $deletedDate = $slotLookup->fetchColumn();
                if ($deletedDate === false) throw new RuntimeException('Không tìm thấy buổi dạy hoặc buổi đã được xóa.');

                $stmt = $pdo->prepare('DELETE FROM teaching_schedule_slots WHERE id=? AND teaching_class_id=?');
                $stmt->execute([$slotId, $classId]);
                if ($stmt->rowCount() !== 1) throw new RuntimeException('Không thể xóa buổi dạy. Vui lòng thử lại.');

                rebalancePlannedSchedule($pdo, $classId, (int) $_SESSION['user_id'], false, (string) $deletedDate);
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $error;
            }
            writeAuditLog($pdo, 'teaching_schedule.slot_deleted', 'teaching_schedule_slot', $slotId, ['class_id' => $classId]);
            scheduleResponse(['ok' => true, 'message' => 'Đã xóa buổi. Nếu lớp có lịch tự tạo, một buổi bù sẽ được xếp sau buổi cuối.']);
        }
        throw new RuntimeException('Thao tác không hợp lệ.');
    } catch (Throwable $error) {
        scheduleResponse(['ok' => false, 'message' => $error->getMessage()], 422);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create_class') {
        $className = trim((string) ($_POST['class_name'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $totalSessions = max(0, min(500, (int) ($_POST['total_sessions'] ?? 0)));
        $plannedDays = plannedWeekdays($_POST['planned_weekdays'] ?? []);
        $plannedStartDate = (string) ($_POST['planned_start_date'] ?? '');
        $plannedStartTime = (string) ($_POST['planned_start_time'] ?? '');
        $plannedEndTime = (string) ($_POST['planned_end_time'] ?? '');
        $timeShift = (string) ($_POST['time_shift'] ?? 'morning');
        if (!in_array($timeShift, ['morning', 'afternoon', 'evening'], true)) $timeShift = 'morning';
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $teacherId = $canManageAllSchedules ? ((int) ($_POST['teacher_id'] ?? 0) ?: null) : (int) $_SESSION['user_id'];
        $names = teachingClassStudentNames((string) ($_POST['student_names'] ?? ''));
        if ($courseId > 0) {
            $courseStmt = $pdo->prepare('SELECT title FROM courses WHERE id=? LIMIT 1');
            $courseStmt->execute([$courseId]);
            $courseTitle = $courseStmt->fetchColumn();
            if ($courseTitle === false) {
                $_SESSION['error'] = 'Khóa học được chọn không còn tồn tại.';
                header('Location: teaching_schedule.php?month=' . rawurlencode((string) ($_POST['month'] ?? date('Y-m'))) . ($showOwnSchedule ? '&scope=mine' : ''));
                exit;
            }
            $className = (string) $courseTitle;
        }
        if ($className === '') {
            $_SESSION['error'] = 'Vui lòng nhập tên lớp.';
        } elseif ($totalSessions > 0 && (!$plannedDays || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $plannedStartDate) || !preg_match('/^\d{2}:\d{2}$/', $plannedStartTime) || !preg_match('/^\d{2}:\d{2}$/', $plannedEndTime) || $plannedStartTime >= $plannedEndTime)) {
            $_SESSION['error'] = 'Vui lòng nhập đủ số buổi, thứ học, ngày bắt đầu và giờ học dự kiến.';
        } else {
            $pdo->beginTransaction();
            try {
                $nextOrder = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM teaching_classes')->fetchColumn();
                $stmt = $pdo->prepare('INSERT INTO teaching_classes (class_name, notes, total_sessions, planned_weekdays, planned_start_date, planned_start_time, planned_end_time, time_shift, course_id, teacher_id, created_by, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$className, $notes ?: null, $totalSessions ?: null, $plannedDays ? implode(',', $plannedDays) : null, $totalSessions ? $plannedStartDate : null, $totalSessions ? $plannedStartTime : null, $totalSessions ? $plannedEndTime : null, $timeShift, $courseId ?: null, $teacherId, (int) $_SESSION['user_id'], $nextOrder]);
                $classId = (int) $pdo->lastInsertId();
                if ($names) {
                    $studentStmt = $pdo->prepare('INSERT IGNORE INTO teaching_class_students (teaching_class_id, student_name) VALUES (?, ?)');
                    foreach ($names as $name) $studentStmt->execute([$classId, mb_substr($name, 0, 191, 'UTF-8')]);
                }
                appendPlannedSlots($pdo, $classId, (int) $_SESSION['user_id']);
                $pdo->commit();
                writeAuditLog($pdo, 'teaching_schedule.class_created', 'teaching_class', $classId, ['class_name' => $className, 'student_count' => count($names)]);
                $_SESSION['success'] = 'Đã tạo lớp và xếp ' . count($names) . ' học viên.';
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = 'Không thể tạo lớp: ' . $error->getMessage();
            }
        }
    } elseif ($action === 'delete_class') {
        $classId = (int) ($_POST['class_id'] ?? 0);
        if ($classId <= 0 || !canManageTeachingClass($pdo, $classId, (int) $_SESSION['user_id'], $canManageAllSchedules)) {
            $_SESSION['error'] = 'Bạn không có quyền xóa lớp này.';
        } else {
            $classStmt = $pdo->prepare('SELECT class_name FROM teaching_classes WHERE id=?');
            $classStmt->execute([$classId]);
            $className = (string) ($classStmt->fetchColumn() ?: 'Lớp #' . $classId);
            $pdo->prepare('DELETE FROM teaching_classes WHERE id=?')->execute([$classId]);
            writeAuditLog($pdo, 'teaching_schedule.class_deleted', 'teaching_class', $classId, ['class_name' => $className]);
            $_SESSION['success'] = 'Đã xóa lớp “' . $className . '” và toàn bộ lịch dạy của lớp.';
        }
    } elseif ($action === 'complete_class') {
        $classId = (int) ($_POST['class_id'] ?? 0);
        if ($classId <= 0 || !canManageTeachingClass($pdo, $classId, (int) $_SESSION['user_id'], $canManageAllSchedules)) {
            $_SESSION['error'] = 'Bạn không có quyền kết thúc lớp này.';
        } else {
            $pdo->prepare("UPDATE teaching_classes SET status='completed' WHERE id=?")->execute([$classId]);
            writeAuditLog($pdo, 'teaching_schedule.class_completed', 'teaching_class', $classId);
            $_SESSION['success'] = 'Đã kết thúc lớp. Lớp sẽ được ẩn khỏi lịch mặc định.';
        }
    } elseif ($action === 'pause_class' || $action === 'resume_class') {
        $classId = (int) ($_POST['class_id'] ?? 0);
        if ($classId <= 0 || !canManageTeachingClass($pdo, $classId, (int) $_SESSION['user_id'], $canManageAllSchedules)) {
            $_SESSION['error'] = 'Bạn không có quyền thay đổi trạng thái lớp này.';
        } else {
            $newStatus = $action === 'pause_class' ? 'paused' : 'active';
            $pdo->prepare('UPDATE teaching_classes SET status=? WHERE id=?')->execute([$newStatus, $classId]);
            writeAuditLog($pdo, 'teaching_schedule.class_' . $newStatus, 'teaching_class', $classId);
            $_SESSION['success'] = $newStatus === 'paused' ? 'Đã tạm dừng lớp. Bạn có thể mở lại từ nút “Lớp tạm dừng”.' : 'Đã mở lại lớp.';
        }
    } elseif ($action === 'move_class') {
        $classId = (int) ($_POST['class_id'] ?? 0);
        $direction = (string) ($_POST['direction'] ?? '');
        if (!in_array($direction, ['up', 'down'], true) || $classId <= 0 || !canManageTeachingClass($pdo, $classId, (int) $_SESSION['user_id'], $canManageAllSchedules)) {
            $_SESSION['error'] = 'Không thể thay đổi thứ tự lớp.';
        } else {
            $currentStmt = $pdo->prepare('SELECT id, sort_order, status FROM teaching_classes WHERE id=?');
            $currentStmt->execute([$classId]);
            $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
            if ($current) {
                $operator = $direction === 'up' ? '<' : '>';
                $orderBy = $direction === 'up' ? 'DESC' : 'ASC';
                $scope = $canManageAllSchedules ? '' : ' AND teacher_id=' . (int) $_SESSION['user_id'];
                $neighborStmt = $pdo->prepare("SELECT id, sort_order FROM teaching_classes WHERE status=? AND (sort_order {$operator} ? OR (sort_order=? AND id " . ($direction === 'up' ? '<' : '>') . " ?)) {$scope} ORDER BY sort_order {$orderBy}, id {$orderBy} LIMIT 1");
                $neighborStmt->execute([$current['status'], (int) $current['sort_order'], (int) $current['sort_order'], $classId]);
                $neighbor = $neighborStmt->fetch(PDO::FETCH_ASSOC);
                if ($neighbor) {
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare('UPDATE teaching_classes SET sort_order=? WHERE id=?')->execute([(int) $neighbor['sort_order'], $classId]);
                        $pdo->prepare('UPDATE teaching_classes SET sort_order=? WHERE id=?')->execute([(int) $current['sort_order'], (int) $neighbor['id']]);
                        $pdo->commit();
                        writeAuditLog($pdo, 'teaching_schedule.class_reordered', 'teaching_class', $classId, ['direction' => $direction]);
                    } catch (Throwable $error) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $_SESSION['error'] = 'Không thể đổi thứ tự lớp: ' . $error->getMessage();
                    }
                }
            }
        }
    } elseif ($action === 'update_class') {
        $classId = (int) ($_POST['class_id'] ?? 0);
        $className = trim((string) ($_POST['class_name'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $timeShift = (string) ($_POST['time_shift'] ?? 'morning');
        if (!in_array($timeShift, ['morning', 'afternoon', 'evening'], true)) $timeShift = 'morning';
        $updateSchedule = (string) ($_POST['update_schedule'] ?? '') === '1';
        $totalSessions = max(0, min(500, (int) ($_POST['total_sessions'] ?? 0)));
        $plannedDays = plannedWeekdays($_POST['planned_weekdays'] ?? []);
        $scheduleApplyDate = (string) ($_POST['schedule_apply_date'] ?? '');
        $plannedStartTime = (string) ($_POST['planned_start_time'] ?? '');
        $plannedEndTime = (string) ($_POST['planned_end_time'] ?? '');
        $teacherId = $canManageAllSchedules ? ((int) ($_POST['teacher_id'] ?? 0) ?: null) : (int) $_SESSION['user_id'];
        $names = teachingClassStudentNames((string) ($_POST['student_names'] ?? ''));
        if ($classId <= 0 || !canManageTeachingClass($pdo, $classId, (int) $_SESSION['user_id'], $canManageAllSchedules)) {
            $_SESSION['error'] = 'Bạn không có quyền sửa lớp này.';
        } else {
            if ($courseId > 0) {
                $courseStmt = $pdo->prepare('SELECT title FROM courses WHERE id=? LIMIT 1');
                $courseStmt->execute([$courseId]);
                $courseTitle = $courseStmt->fetchColumn();
                if ($courseTitle === false) {
                    $_SESSION['error'] = 'Khóa học được chọn không còn tồn tại.';
                    header('Location: teaching_schedule.php?month=' . rawurlencode((string) ($_POST['month'] ?? date('Y-m'))) . ($showOwnSchedule ? '&scope=mine' : ''));
                    exit;
                }
                $className = (string) $courseTitle;
            }
            if ($className === '') {
                $_SESSION['error'] = 'Vui lòng nhập tên lớp.';
            } elseif ($updateSchedule && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduleApplyDate) || $scheduleApplyDate < date('Y-m-d') || ($totalSessions > 0 && (!$plannedDays || !preg_match('/^\d{2}:\d{2}$/', $plannedStartTime) || !preg_match('/^\d{2}:\d{2}$/', $plannedEndTime) || $plannedStartTime >= $plannedEndTime)))) {
                $_SESSION['error'] = 'Lịch mới cần có số buổi, thứ học, ngày áp dụng (từ hôm nay) và khung giờ hợp lệ.';
            } else {
                $pdo->beginTransaction();
                try {
                    if ($updateSchedule) {
                        $pdo->prepare('DELETE FROM teaching_schedule_slots WHERE teaching_class_id=? AND teaching_date>=?')->execute([$classId, $scheduleApplyDate]);
                        $pdo->prepare('UPDATE teaching_classes SET class_name=?, notes=?, course_id=?, teacher_id=?, time_shift=?, total_sessions=?, planned_weekdays=?, planned_start_date=?, planned_start_time=?, planned_end_time=? WHERE id=?')->execute([
                            $className, $notes ?: null, $courseId ?: null, $teacherId, $timeShift,
                            $totalSessions ?: null, $plannedDays ? implode(',', $plannedDays) : null,
                            $totalSessions ? $scheduleApplyDate : null, $totalSessions ? $plannedStartTime : null, $totalSessions ? $plannedEndTime : null,
                            $classId,
                        ]);
                        if ($totalSessions > 0) appendPlannedSlots($pdo, $classId, (int) $_SESSION['user_id']);
                    } else {
                        $pdo->prepare('UPDATE teaching_classes SET class_name=?, notes=?, course_id=?, teacher_id=?, time_shift=? WHERE id=?')->execute([$className, $notes ?: null, $courseId ?: null, $teacherId, $timeShift, $classId]);
                    }
                    $pdo->prepare('DELETE FROM teaching_class_students WHERE teaching_class_id=?')->execute([$classId]);
                    if ($names) {
                        $studentStmt = $pdo->prepare('INSERT IGNORE INTO teaching_class_students (teaching_class_id, student_name) VALUES (?, ?)');
                foreach ($names as $name) $studentStmt->execute([$classId, mb_substr($name, 0, 191, 'UTF-8')]);
                    }
                    $pdo->commit();
                    writeAuditLog($pdo, 'teaching_schedule.class_updated', 'teaching_class', $classId, ['class_name' => $className, 'student_count' => count($names), 'schedule_updated' => $updateSchedule, 'schedule_apply_date' => $updateSchedule ? $scheduleApplyDate : null]);
                    $_SESSION['success'] = $updateSchedule ? 'Đã cập nhật lớp và áp dụng lịch mới từ ' . date('d/m/Y', strtotime($scheduleApplyDate)) . '. Các buổi trước ngày này được giữ nguyên.' : 'Đã cập nhật lớp.';
                } catch (Throwable $error) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $_SESSION['error'] = 'Không thể cập nhật lớp: ' . $error->getMessage();
                }
            }
        }
    }
    header('Location: teaching_schedule.php?month=' . rawurlencode((string) ($_POST['month'] ?? date('Y-m'))) . ($showOwnSchedule ? '&scope=mine' : ''));
    exit;
}

$selectedDate = (string) ($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) $selectedDate = date('Y-m-d');
try {
    $selectedDay = new DateTimeImmutable($selectedDate);
} catch (Throwable) {
    $selectedDay = new DateTimeImmutable('today');
    $selectedDate = $selectedDay->format('Y-m-d');
}
$firstDay = $selectedDay->modify('-' . ((int) $selectedDay->format('N') - 1) . ' days');
$lastDay = $firstDay->modify('+6 days');
$month = $selectedDay->format('Y-m');
$previousWeek = $firstDay->modify('-7 days')->format('Y-m-d');
$nextWeek = $firstDay->modify('+7 days')->format('Y-m-d');
$todayDate = date('Y-m-d');
$days = [];
for ($day = $firstDay; $day <= $lastDay; $day = $day->modify('+1 day')) $days[] = $day;
$showCompleted = ((string) ($_GET['show_completed'] ?? '')) === '1';

$substituteTeachers = $pdo->query("SELECT id, name FROM users WHERE role IN ('teacher','administrative_staff','admin') AND is_approved=1 AND COALESCE(is_locked,0)=0 ORDER BY name")->fetchAll();
$teachers = $canManageAllSchedules ? $substituteTeachers : [];
$courses = $pdo->query('SELECT id, title FROM courses ORDER BY title, id')->fetchAll();
$classSql =
    "SELECT tc.id, tc.class_name, tc.notes, tc.course_id, tc.status, tc.sort_order, tc.time_shift, c.title AS course_title, tc.teacher_id, u.name AS teacher_name,
            GROUP_CONCAT(tcs.student_name ORDER BY tcs.student_name SEPARATOR ', ') AS students,
            COUNT(DISTINCT tcs.id) AS student_count
     FROM teaching_classes tc
     LEFT JOIN users u ON u.id=tc.teacher_id
     LEFT JOIN courses c ON c.id=tc.course_id
     LEFT JOIN teaching_class_students tcs ON tcs.teaching_class_id=tc.id";
if (!$canManageAllSchedules) $classSql .= ' WHERE (tc.teacher_id = ' . (int) $_SESSION['user_id'] . ' OR EXISTS (SELECT 1 FROM teaching_schedule_slots own_ts WHERE own_ts.teaching_class_id=tc.id AND own_ts.substitute_teacher_id=' . (int) $_SESSION['user_id'] . '))';
else $classSql .= ' WHERE 1=1';
if (!$showCompleted) $classSql .= " AND tc.status='active'";
$classSql .= ' GROUP BY tc.id';
$classSql .= " ORDER BY FIELD(tc.time_shift, 'morning', 'afternoon', 'evening'), tc.sort_order ASC, tc.id ASC";
$classes = $pdo->query($classSql)->fetchAll();
$shiftGroups = ['morning' => [], 'afternoon' => [], 'evening' => []];
foreach ($classes as $class) {
    $shift = $class['time_shift'] ?? 'morning';
    if (!isset($shiftGroups[$shift])) $shift = 'morning';
    $shiftGroups[$shift][] = $class;
}
$shiftLabels = ['morning' => '🌅 BUỔI SÁNG', 'afternoon' => '☀️ BUỔI CHIỀU', 'evening' => '🌙 BUỔI TỐI'];
$shiftColors = ['morning' => '#2563eb', 'afternoon' => '#ea580c', 'evening' => '#7c3aed'];
$pausedSql = "SELECT tc.id, tc.class_name, c.title AS course_title, u.name AS teacher_name FROM teaching_classes tc LEFT JOIN courses c ON c.id=tc.course_id LEFT JOIN users u ON u.id=tc.teacher_id WHERE tc.status='paused'";
if (!$canManageAllSchedules) $pausedSql .= ' AND tc.teacher_id=' . (int) $_SESSION['user_id'];
$pausedSql .= ' ORDER BY tc.updated_at DESC, tc.id DESC';
$pausedClasses = $pdo->query($pausedSql)->fetchAll();
$slotStmt = $pdo->prepare('SELECT id, teaching_class_id, teaching_date, start_time, end_time, substitute_teacher_id FROM teaching_schedule_slots WHERE teaching_date BETWEEN ? AND ? ORDER BY start_time, id');
$slotStmt->execute([$firstDay->format('Y-m-d'), $lastDay->format('Y-m-d')]);
$slots = [];
foreach ($slotStmt as $slot) $slots[(int) $slot['teaching_class_id']][$slot['teaching_date']][] = $slot;
$customShiftStmt = $pdo->query("SELECT id, name, start_time, end_time, major_shift FROM teaching_shift_presets ORDER BY FIELD(major_shift, 'morning', 'afternoon', 'evening'), name, id");
$customShiftPresets = $customShiftStmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = $showOwnSchedule ? 'Lịch dạy của tôi' : 'Xếp lớp & Lịch dạy';
require_once '../includes/header.php';
?>
<style>
.schedule-layout{display:grid;grid-template-columns:minmax(290px,360px) minmax(0,1fr);gap:22px;align-items:start}.schedule-card{padding:24px;background:var(--glass-bg);border:1px solid var(--border-color);border-radius:18px}.schedule-card h2{margin:0 0 18px;font-size:22px}.schedule-form{display:grid;gap:14px}.schedule-form label{display:grid;gap:7px;font-weight:700}.schedule-form textarea{min-height:150px;resize:vertical}.schedule-note{color:var(--text-muted);font-size:13px;line-height:1.55;margin:0}.calendar-wrap{overflow:auto;border:1px solid var(--border-color);border-radius:16px;background:var(--glass-bg);max-height:calc(100vh - 215px)}.schedule-table{border-collapse:separate;border-spacing:0;width:max-content;min-width:100%;font-size:13px}.schedule-table th{position:sticky;top:0;z-index:4;background:#1f517e;color:#fff;text-align:center;padding:12px 8px;border-right:1px solid rgba(255,255,255,.18);border-bottom:1px solid rgba(255,255,255,.2)}.schedule-table th.weekend{background:#3d3d3d}.schedule-table th.info-head{left:0;z-index:6}.schedule-table td{border-right:1px solid var(--border-color);border-bottom:1px solid var(--border-color);padding:6px;min-width:118px;height:68px;background:rgba(255,255,255,.012)}.schedule-table td.info-cell{position:sticky;left:0;z-index:3;min-width:260px;max-width:260px;background:var(--sidebar-bg);padding:10px 12px}.class-head{display:flex;align-items:start;gap:8px;justify-content:space-between}.class-title{font-weight:800;font-size:14px}.class-actions{display:flex;gap:4px}.class-actions form{margin:0}.class-edit,.class-complete,.class-delete{padding:4px 7px;border-radius:7px;background:transparent;cursor:pointer;line-height:1}.class-edit{border:1px solid #57b7ff;color:#85ccff}.class-complete{border:1px solid #17bd86;color:#42d6a5}.class-delete{border:1px solid #ef476f;color:#ff7895}.class-edit:hover{background:rgba(87,183,255,.16)}.class-complete:hover{background:rgba(23,189,134,.16)}.class-delete:hover{background:rgba(239,71,111,.16)}.completed-badge{display:block;width:max-content;margin-top:3px;color:#ffd166;font-size:10px}.class-meta{margin-top:4px;color:var(--text-muted);font-size:12px;line-height:1.45}.schedule-table td.weekend{background:rgba(0,0,0,.2)}.schedule-cell{cursor:pointer;transition:.18s}.schedule-cell:hover{background:rgba(var(--primary-rgb),.12)!important;box-shadow:inset 0 0 0 1px var(--primary)}.slot{display:block;width:100%;padding:6px 8px;border:0;border-radius:7px;background:#b6e5d0;color:#12352a;font:700 12px inherit;cursor:pointer;margin:2px 0}.slot:hover{filter:brightness(1.05)}.empty-cell{color:var(--text-muted);font-size:19px;opacity:0}.schedule-cell:hover .empty-cell{opacity:.65}.month-bar{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:18px}.month-control{display:flex;align-items:center;gap:9px}.month-control input{width:150px}.schedule-dialog{width:min(440px,calc(100vw - 28px));padding:0;border:1px solid var(--border-color);border-radius:17px;color:var(--text-main);background:var(--sidebar-bg);box-shadow:0 24px 70px rgba(0,0,0,.5)}.schedule-dialog::backdrop{background:rgba(2,6,23,.7)}.schedule-dialog form{display:grid;gap:15px;padding:22px}.dialog-title{margin:0;font-size:21px}.time-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.time-grid label{display:grid;gap:7px;font-weight:700}.dialog-actions{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap}.delete-slot{margin-right:auto}@media(max-width:900px){.schedule-layout{grid-template-columns:1fr}.calendar-wrap{max-height:none}}@media(max-width:600px){.schedule-card{padding:17px}.schedule-table td{min-width:104px}.schedule-table td.info-cell{min-width:210px;max-width:210px}.time-grid{grid-template-columns:1fr}}
</style>
<h1><i class='bx bx-calendar-event'></i> <?php echo $showOwnSchedule ? 'Lịch dạy của tôi' : 'Xếp lớp & lịch dạy'; ?></h1>
<p style="color:var(--text-muted);margin:-8px 0 22px"><?php echo $showOwnSchedule ? 'Quản lý các lớp do bạn phụ trách; nhấn trực tiếp vào ô lịch để thêm, sửa hoặc xóa buổi dạy.' : 'Tạo lớp, nhập tên học viên và nhấn trực tiếp vào ô lịch để thêm, sửa hoặc xóa buổi dạy.'; ?></p>
<?php if (!empty($_SESSION['success'])): ?><div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div><?php endif; ?>
<?php if (!empty($_SESSION['error'])): ?><div class="alert alert-error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div><?php endif; ?>
<div class="schedule-layout">
  <section class="schedule-card"><div class="month-bar"><div><h2 style="margin:0"><i class='bx bx-table'></i> Lịch dạy tháng <?php echo $firstDay->format('m/Y'); ?></h2><p class="schedule-note" style="margin-top:6px">Mỗi buổi trong cùng một dòng lớp có thể dùng giờ khác nhau. Nhấn giờ để sửa; nhấn “+” để thêm ca trong ô.</p></div><form class="month-control" method="get"><input type="month" name="month" value="<?php echo htmlspecialchars($month); ?>"><button class="btn btn-outline">Xem lịch</button></form></div><div class="calendar-wrap"><table class="schedule-table"><thead><tr><th class="info-head">LỚP / HỌC VIÊN</th><?php foreach ($days as $day): $weekend=(int)$day->format('N')>=6; $isToday=$day->format('Y-m-d')===$todayDate; ?><th class="<?php echo trim(($weekend ? 'weekend ' : '') . ($isToday ? 'today' : '')); ?>"><small><?php echo ['T2','T3','T4','T5','T6','T7','CN'][(int)$day->format('N')-1]; ?></small><br><?php echo $day->format('d'); ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($shiftGroups as $shiftKey => $shiftClasses): ?><tr class="shift-header shift-<?php echo $shiftKey; ?>"><td colspan="<?php echo count($days)+1; ?>" class="shift-label" style="--shift-color:<?php echo $shiftColors[$shiftKey]; ?>"><?php echo $shiftLabels[$shiftKey]; ?></td></tr><?php if (empty($shiftClasses)): ?><tr class="shift-zone shift-<?php echo $shiftKey; ?>"><td colspan="<?php echo count($days)+1; ?>" class="shift-empty">Chưa có lớp nào trong ca này</td></tr><?php endif; ?><?php foreach ($shiftClasses as $class): $displayName = (string) ($class['course_title'] ?: $class['class_name']); ?><tr class="shift-zone shift-<?php echo $shiftKey; ?>"><td class="info-cell"><div class="class-head"><div class="class-title"><?php echo htmlspecialchars($displayName); ?></div></div><div class="class-meta"><?php echo htmlspecialchars($class['teacher_name'] ?: 'Chưa phân công giáo viên'); ?> · <?php echo (int)$class['student_count']; ?> học viên</div><div class="class-meta"><?php echo htmlspecialchars($class['students'] ?: 'Chưa nhập học viên'); ?></div></td><?php foreach ($days as $day): $date=$day->format('Y-m-d'); $cellSlots=$slots[(int)$class['id']][$date]??[]; $weekend=(int)$day->format('N')>=6; ?><td class="schedule-cell <?php echo $weekend ? 'weekend' : ''; ?>" data-class-id="<?php echo (int)$class['id']; ?>" data-class-name="<?php echo htmlspecialchars($displayName, ENT_QUOTES); ?>" data-date="<?php echo $date; ?>"><?php foreach ($cellSlots as $slot): ?><button type="button" class="slot" data-slot-id="<?php echo (int)$slot['id']; ?>" data-start="<?php echo substr($slot['start_time'],0,5); ?>" data-end="<?php echo substr($slot['end_time'],0,5); ?>"><?php echo substr($slot['start_time'],0,5); ?> – <?php echo substr($slot['end_time'],0,5); ?></button><?php endforeach; ?><button type="button" class="add-slot" aria-label="Thêm ca dạy trong ô" title="Thêm ca dạy trong ô">+</button></td><?php endforeach; ?></tr><?php endforeach; ?><?php endforeach; ?><?php if (!$classes): ?><tr><td colspan="<?php echo count($days)+1; ?>" style="padding:34px;text-align:center;color:var(--text-muted)">Chưa có lớp nào. Hãy tạo lớp đầu tiên bằng nút "Tạo lớp mới".</td></tr><?php endif; ?></tbody></table></div></section>
</div>
<dialog class="schedule-dialog" id="class-dialog"><form method="post" id="class-form"><?php echo csrfField(); ?><input type="hidden" name="action" value="update_class"><input type="hidden" name="class_id" id="edit-class-id"><input type="hidden" name="month" value="<?php echo htmlspecialchars($month); ?>"><input type="hidden" name="scope" value="<?php echo $showOwnSchedule ? 'mine' : 'all'; ?>"><h2 class="dialog-title">Sửa lớp</h2><label>Khóa học trong hệ thống<select name="course_id" id="edit-course-id"><option value="">— Lớp riêng —</option><?php foreach ($courses as $course): ?><option value="<?php echo (int) $course['id']; ?>" data-title="<?php echo htmlspecialchars($course['title'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($course['title']); ?></option><?php endforeach; ?></select></label><label>Tên lớp<input name="class_name" id="edit-class-name" required maxlength="191"></label><?php if ($canManageAllSchedules): ?><label>Giáo viên phụ trách<select name="teacher_id" id="edit-teacher-id"><option value="">Chưa phân công</option><?php foreach ($teachers as $teacher): ?><option value="<?php echo (int) $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['name']); ?></option><?php endforeach; ?></select></label><?php endif; ?><label>Ca học<select name="time_shift" id="edit-time-shift"><option value="morning">🌅 Ca sáng (8h – 11h)</option><option value="afternoon">☀️ Ca chiều (14h – 17h)</option><option value="evening">🌙 Ca tối (18h – 21h)</option></select></label><label>Học viên trong lớp<textarea name="student_names" id="edit-student-names"></textarea></label><div class="dialog-actions"><button class="btn btn-outline" type="button" id="close-class-dialog">Hủy</button><button class="btn btn-primary" type="submit"><i class='bx bx-save'></i> Lưu lớp</button></div></form></dialog>
<dialog class="schedule-dialog" id="schedule-dialog"><form id="slot-form"><h2 class="dialog-title" id="slot-title">Thêm buổi dạy</h2><p class="schedule-note" id="slot-subtitle"></p><input type="hidden" id="slot-class-id"><input type="hidden" id="slot-date"><input type="hidden" id="slot-id"><label>Chọn nhanh khung giờ<select id="slot-time-preset"><option value="custom">Giờ tùy chỉnh</option><option value="08:00|09:30">08:00 – 09:30</option><option value="08:00|11:00">08:00 – 11:00</option><option value="14:00|17:00">14:00 – 17:00</option><option value="15:00|16:30">15:00 – 16:30</option><option value="18:00|21:00">18:00 – 21:00</option></select></label><div class="time-grid"><label>Giờ bắt đầu<input id="slot-start" type="time" required></label><label>Giờ kết thúc<input id="slot-end" type="time" required></label></div><p class="schedule-note">Thời gian này chỉ áp dụng cho buổi đang chọn; lớp vẫn nằm nguyên trên cùng một dòng.</p><div class="dialog-actions"><button class="btn btn-outline delete-slot" id="delete-slot" type="button" hidden><i class='bx bx-trash'></i> Xóa buổi</button><button class="btn btn-outline" type="button" id="close-dialog">Hủy</button><button class="btn btn-primary" type="submit"><i class='bx bx-save'></i> Lưu thời gian</button></div></form></dialog>
<style>#class-dialog{width:min(620px,calc(100vw - 28px))}#class-dialog label{display:grid;gap:7px;font-weight:700}#class-dialog input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]),#class-dialog select,#class-dialog textarea{width:100%;box-sizing:border-box;background:var(--input-bg,#101c31)!important;color:var(--text-main)!important;border:1px solid var(--border-color)!important;border-radius:10px!important;font:inherit}#class-dialog input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]),#class-dialog select{min-height:48px;padding:11px 14px}#class-dialog textarea{min-height:78px;padding:11px 14px;resize:vertical}#class-dialog input:focus,#class-dialog select:focus,#class-dialog textarea:focus{outline:none;border-color:var(--primary)!important;box-shadow:0 0 0 3px rgba(var(--primary-rgb),.16)}</style>
<style>.class-pause{padding:4px 7px;border:1px solid #f5b642;border-radius:7px;background:transparent;color:#ffd166;cursor:pointer;line-height:1}.class-pause:hover{background:rgba(245,182,66,.16)}.paused-list{display:grid;gap:9px;min-width:340px}.paused-item{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px;border:1px solid var(--border-color);border-radius:10px}.paused-item small{display:block;color:var(--text-muted);margin-top:3px}</style>
<dialog class="schedule-dialog" id="paused-dialog"><form method="dialog"><h2 class="dialog-title">Lớp tạm dừng</h2><p class="schedule-note">Chọn mở lại để đưa lớp về lịch dạy đang hoạt động.</p><div class="paused-list"><?php foreach ($pausedClasses as $pausedClass): $pausedName = (string) ($pausedClass['course_title'] ?: $pausedClass['class_name']); ?><div class="paused-item"><div><strong><?php echo htmlspecialchars($pausedName); ?></strong><small><?php echo htmlspecialchars($pausedClass['teacher_name'] ?: 'Chưa phân công giáo viên'); ?></small></div><form method="post"><?php echo csrfField(); ?><input type="hidden" name="action" value="resume_class"><input type="hidden" name="class_id" value="<?php echo (int) $pausedClass['id']; ?>"><input type="hidden" name="month" value="<?php echo htmlspecialchars($month); ?>"><button class="btn btn-primary" type="submit">Mở lại</button></form></div><?php endforeach; ?><?php if (!$pausedClasses): ?><p class="schedule-note">Không có lớp nào đang tạm dừng.</p><?php endif; ?></div><div class="dialog-actions"><button class="btn btn-outline" value="cancel">Đóng</button></div></form></dialog>
<style>.schedule-table tbody tr.dragging{opacity:.45}.schedule-table tbody tr.drag-target td{box-shadow:inset 0 3px 0 #57b7ff}.class-drag{padding:4px 6px;border:1px dashed #6ea9d6;border-radius:7px;background:transparent;color:#9fd1fb;cursor:grab;line-height:1}.class-drag:active{cursor:grabbing}.class-order-status{display:inline-flex;align-items:center;gap:6px;margin-left:auto;color:var(--text-muted);font-size:12px}.class-order-status.saving{color:#ffd166}.class-order-status.saved{color:#42d6a5}.class-order-status.error{color:#ff7895}</style>
<style>.schedule-layout{display:block}.schedule-layout>.schedule-card{display:block;width:100%;box-sizing:border-box}.create-class-dialog{width:min(620px,calc(100vw - 28px))}</style>
<dialog class="schedule-dialog create-class-dialog" id="create-class-dialog"><form class="schedule-form" method="post"><?php echo csrfField(); ?><input type="hidden" name="action" value="create_class"><input type="hidden" name="month" value="<?php echo htmlspecialchars($month); ?>"><h2 class="dialog-title">Tạo lớp mới</h2><label>Tên lớp<input required name="class_name" maxlength="191" placeholder="Ví dụ: TH.2603.06"></label><?php if ($isAdmin): ?><label>Giáo viên phụ trách<select name="teacher_id"><option value="">Chưa phân công</option><?php foreach ($teachers as $teacher): ?><option value="<?php echo (int) $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['name']); ?></option><?php endforeach; ?></select></label><?php endif; ?><fieldset class="shift-fieldset"><legend>Ca học</legend><div class="shift-options"><label class="shift-option shift-option-morning"><input type="radio" name="time_shift" value="morning" checked><span class="shift-icon">🌅</span><div><strong>Ca sáng</strong><small>8h – 11h</small></div></label><label class="shift-option shift-option-afternoon"><input type="radio" name="time_shift" value="afternoon"><span class="shift-icon">☀️</span><div><strong>Ca chiều</strong><small>14h – 17h</small></div></label><label class="shift-option shift-option-evening"><input type="radio" name="time_shift" value="evening"><span class="shift-icon">🌙</span><div><strong>Ca tối</strong><small>18h – 21h</small></div></label></div></fieldset><label>Học viên trong lớp<textarea name="student_names" placeholder="Mỗi dòng một học viên"></textarea></label><label>Ghi chú lớp<textarea name="notes" placeholder="Ví dụ: Học tối thứ 2, 4, 6 · Phòng T357 · Khai giảng 20/08"></textarea></label><div class="dialog-actions"><button type="button" class="btn btn-outline" id="close-create-class">Hủy</button><button class="btn btn-primary"><i class='bx bx-save'></i> Tạo lớp</button></div></form></dialog>
<style>.create-class-dialog input:not([type="checkbox"]):not([type="radio"]),.create-class-dialog select,.create-class-dialog textarea,.edit-schedule-fields input:not([type="checkbox"]):not([type="radio"]){box-sizing:border-box;background:var(--input-bg,#101c31)!important;color:var(--text-main)!important;border:1px solid var(--border-color)!important;border-radius:10px!important}.create-class-dialog input:not([type="checkbox"]):not([type="radio"]),.create-class-dialog select,.edit-schedule-fields input:not([type="checkbox"]):not([type="radio"]){min-height:48px;padding:11px 14px}.create-class-dialog input[name="total_sessions"]{max-width:180px}.create-class-dialog fieldset,.edit-schedule-fields{display:grid;gap:10px;margin:0;border:1px solid var(--border-color);border-radius:12px;padding:14px}.create-class-dialog legend,.edit-schedule-fields legend{padding:0 5px;font-weight:800}.create-class-dialog textarea[name="student_names"],.create-class-dialog textarea[name="notes"]{min-height:75px!important;height:75px}.weekday-label{font-weight:700}.weekday-options{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.weekday-options .weekday-option{display:flex!important;align-items:center;gap:8px;margin:0!important;padding:8px 10px;border:1px solid var(--border-color);border-radius:9px;font-weight:600!important;cursor:pointer}.weekday-options .weekday-option input{width:auto!important;margin:0!important;accent-color:var(--primary)}.weekday-options .weekday-option span{white-space:nowrap}.lesson-shift-select{grid-column:span 2}.lesson-shift-select small{margin-top:4px;color:var(--text-muted);font-weight:400;line-height:1.35}.edit-schedule-toggle{display:flex!important;align-items:center;gap:8px;font-weight:700!important;cursor:pointer}.edit-schedule-inputs{display:grid;gap:12px;opacity:.55}.edit-schedule-fields.is-enabled .edit-schedule-inputs{opacity:1}@media(max-width:520px){.weekday-options{grid-template-columns:repeat(2,minmax(0,1fr))}.lesson-shift-select{grid-column:auto}}
.shift-fieldset{border:1px solid var(--border-color)!important;border-radius:14px!important;padding:16px!important}.shift-options{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.shift-option{display:flex!important;align-items:center;gap:10px;padding:12px 14px!important;border:2px solid var(--border-color);border-radius:12px;cursor:pointer;transition:all .2s;margin:0!important;font-weight:400!important}.shift-option:hover{border-color:rgba(var(--primary-rgb),.5);background:rgba(var(--primary-rgb),.04)}.shift-option input[type="radio"]{width:auto!important;margin:0!important;accent-color:var(--primary)}.shift-option input[type="radio"]:checked ~ *{opacity:1}.shift-option:has(input:checked){border-color:var(--primary);background:rgba(var(--primary-rgb),.08);box-shadow:0 0 0 1px rgba(var(--primary-rgb),.2)}.shift-icon{font-size:22px;line-height:1}.shift-option div{display:grid;gap:2px}.shift-option strong{font-size:13px;line-height:1.2}.shift-option small{color:var(--text-muted);font-size:11px}
.shift-label{position:sticky;top:52px;z-index:3;padding:8px 14px!important;height:auto!important;font-weight:800;font-size:13px;letter-spacing:.5px;background:var(--shift-color,#2563eb)!important;color:#fff!important;border-bottom:2px solid color-mix(in srgb,var(--shift-color) 80%,#000)!important;text-align:left!important}
.shift-zone.shift-morning td{background:rgba(37,99,235,.04)}.shift-zone.shift-morning td.info-cell{background:color-mix(in srgb,var(--sidebar-bg) 96%,#2563eb)}
.shift-zone.shift-afternoon td{background:rgba(234,88,12,.04)}.shift-zone.shift-afternoon td.info-cell{background:color-mix(in srgb,var(--sidebar-bg) 96%,#ea580c)}
.shift-zone.shift-evening td{background:rgba(124,58,237,.04)}.shift-zone.shift-evening td.info-cell{background:color-mix(in srgb,var(--sidebar-bg) 96%,#7c3aed)}
.shift-empty{padding:18px 14px!important;text-align:center!important;color:var(--text-muted)!important;font-style:italic;height:auto!important;background:rgba(255,255,255,.02)!important}
@media(max-width:520px){.shift-options{grid-template-columns:1fr}}</style>
<style>.schedule-page-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:18px 0 -4px}.create-class-dialog .custom-shift-form{display:none}.shift-manager-dialog{width:min(680px,calc(100vw - 28px))}.shift-manager-dialog form{gap:16px}.shift-preset-list{display:grid;grid-template-columns:repeat(3,1fr);gap:9px}.shift-preset-item{display:grid;gap:4px;padding:11px;border:1px solid var(--border-color);border-radius:10px;background:rgba(255,255,255,.025)}.shift-preset-item span{color:var(--text-muted);font-size:13px}.shift-preset-item.is-custom{border-color:rgba(var(--primary-rgb),.55)}.custom-shift-form{display:grid;grid-template-columns:1.3fr 1fr 1fr auto;gap:10px;align-items:end;padding:13px;margin-top:4px;border:1px dashed rgba(var(--primary-rgb),.65);border-radius:12px;background:rgba(var(--primary-rgb),.045)}.custom-shift-form strong{grid-column:1/-1}.custom-shift-form label{display:grid;gap:6px;font-size:12px;font-weight:700}.custom-shift-form .btn{min-height:48px;white-space:nowrap}@media(max-width:640px){.shift-preset-list{grid-template-columns:1fr}.custom-shift-form{grid-template-columns:1fr 1fr}.custom-shift-form label:first-of-type{grid-column:1/-1}}@media(max-width:520px){.custom-shift-form{grid-template-columns:1fr}.custom-shift-form label:first-of-type{grid-column:auto}}</style>
<style>.month-control{padding:5px 6px 5px 12px;border:1px solid var(--border-color);border-radius:12px;background:rgba(8,20,40,.55)}.month-control input[type="month"]{width:170px!important;min-height:42px;padding:8px 10px;border:1px solid transparent!important;border-radius:8px;background:transparent!important;color:var(--text-main)!important;font:700 14px inherit;cursor:pointer}.month-control input[type="month"]:focus{outline:none;border-color:var(--primary)!important;background:rgba(255,255,255,.04)!important}.month-control input[type="month"]::-webkit-calendar-picker-indicator{filter:invert(1);opacity:.9;cursor:pointer}</style>
<style>.schedule-table{width:100%;min-width:0;table-layout:fixed;font-size:12px}.schedule-table th{padding:8px 6px}.schedule-table th.info-head,.schedule-table td.info-cell{width:240px;min-width:240px;max-width:240px}.schedule-table td{min-width:0;height:48px;padding:4px}.schedule-table td.info-cell{padding:7px 10px}.class-meta{margin-top:2px;line-height:1.3;word-break:normal;overflow-wrap:normal}.class-meta.class-students,.info-cell>.class-meta:last-of-type{white-space:pre-line}.slot{padding:4px 6px;margin:1px 0;font-size:11px}.add-slot{display:block;width:100%;min-height:20px;margin-top:2px;padding:0;border:1px dashed transparent;border-radius:6px;background:transparent;color:var(--text-muted);font:700 15px/1 inherit;cursor:pointer;opacity:.35}.schedule-cell:hover .add-slot,.add-slot:focus-visible{border-color:rgba(var(--primary-rgb),.6);color:var(--primary);opacity:1}@media(max-width:900px){.schedule-table{width:940px;min-width:940px}.schedule-table th.info-head,.schedule-table td.info-cell{width:210px;min-width:210px;max-width:210px}}</style>
<script>
(() => {
  const control = document.querySelector('.month-control');
  const selectedDate = <?php echo json_encode($selectedDate); ?>;
  const weekStart = <?php echo json_encode($firstDay->format('d/m')); ?>;
  const weekEnd = <?php echo json_encode($lastDay->format('d/m/Y')); ?>;
  if (!control) return;
  const dateInput = control.querySelector('input[name="month"]');
  if (dateInput) {
    dateInput.type = 'date';
    dateInput.name = 'date';
    dateInput.value = selectedDate;
  }
  const title = control.closest('.month-bar')?.querySelector('h2');
  if (title) title.innerHTML = "<i class='bx bx-table'></i> Lịch dạy tuần " + weekStart + ' – ' + weekEnd;
  const submit = control.querySelector('button');
  if (submit) submit.textContent = 'Xem tuần';
  const scope = <?php echo json_encode($showOwnSchedule || !$isAdmin ? 'mine' : 'all'); ?>;
  if (scope && !control.querySelector('input[name="scope"]')) control.insertAdjacentHTML('afterbegin', '<input type="hidden" name="scope" value="' + scope + '">');
  const nav = document.createElement('div');
  nav.className = 'week-nav';
  const withScope = (date) => '?date=' + date + (scope ? '&scope=' + scope : '');
  nav.innerHTML = '<a class="btn btn-outline" href="' + withScope(<?php echo json_encode($previousWeek); ?>) + '" title="Tuần trước">‹</a>' +
    '<a class="btn btn-outline" href="' + withScope(<?php echo json_encode($todayDate); ?>) + '">Hôm nay</a>' +
    '<a class="btn btn-outline" href="' + withScope(<?php echo json_encode($nextWeek); ?>) + '" title="Tuần sau">›</a>';
  control.after(nav);
  const exportButton = document.createElement('a');
  exportButton.className = 'btn btn-outline weekly-export';
  exportButton.href = 'export_weekly_schedule.php?date=' + encodeURIComponent(selectedDate) + (scope ? '&scope=' + encodeURIComponent(scope) : '');
  exportButton.innerHTML = "<i class='bx bx-spreadsheet'></i> Xuất Excel";
  nav.after(exportButton);
})();
</script>
<style>.month-control input[type="date"]{width:170px!important;min-height:42px;padding:8px 10px;border:1px solid transparent!important;border-radius:8px;background:transparent!important;color:var(--text-main)!important;font:700 14px inherit;cursor:pointer}.month-control input[type="date"]::-webkit-calendar-picker-indicator{filter:invert(1);opacity:.9;cursor:pointer}.week-nav{display:flex;align-items:center;gap:8px}.week-nav .btn,.weekly-export{min-height:42px;padding:8px 13px}.weekly-export{display:inline-flex;align-items:center;gap:6px}</style>
<script>
(() => {
  const dialog = document.getElementById('schedule-dialog');
  const token = <?php echo json_encode(csrfToken()); ?>;
  const request = async (body) => {
    const response = await fetch(location.href, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({...body, csrf_token: token})});
    const data = await response.json();
    if (!data.ok) throw new Error(data.message || 'Không thể lưu dữ liệu.');
    return data;
  };
  const openSlotDialog = (cell, slot = null) => {
    document.getElementById('slot-class-id').value = cell.dataset.classId;
    document.getElementById('slot-date').value = cell.dataset.date;
    document.getElementById('slot-id').value = slot?.dataset.slotId || '';
    document.getElementById('slot-start').value = slot?.dataset.start || '08:00';
    document.getElementById('slot-end').value = slot?.dataset.end || '09:30';
    document.getElementById('slot-title').textContent = slot ? 'Chỉnh sửa buổi dạy' : 'Thêm ca dạy';
    document.getElementById('slot-subtitle').textContent = cell.dataset.className + ' · ' + new Date(cell.dataset.date + 'T00:00:00').toLocaleDateString('vi-VN');
    document.getElementById('delete-slot').hidden = !slot;
    document.getElementById('slot-form').dispatchEvent(new CustomEvent('slotdialogopen', {detail: {slot}}));
    dialog.showModal();
  };
  document.querySelectorAll('.schedule-cell').forEach((cell) => cell.addEventListener('click', (event) => {
    const slot = event.target.closest('.slot');
    if (!slot && !event.target.closest('.add-slot') && event.target !== cell) return;
    openSlotDialog(cell, slot);
  }));
  document.getElementById('close-dialog').addEventListener('click', () => dialog.close());
  document.getElementById('delete-slot').addEventListener('click', async () => {
    if (!confirm('Xóa buổi dạy này?')) return;
    const slotId = +document.getElementById('slot-id').value;
    const classId = +document.getElementById('slot-class-id').value;
    const deleteButton = document.getElementById('delete-slot');
    deleteButton.disabled = true;
    try {
      await request({action: 'delete_slot', class_id: classId, slot_id: slotId});
      document.querySelector('.slot[data-slot-id="' + slotId + '"]')?.remove();
      dialog.close();
    } catch (error) {
      alert(error.message);
    } finally {
      deleteButton.disabled = false;
    }
  });
})();
</script>
<script>
// Keep the new-slot defaults in sync with the class section.
document.addEventListener('click', (event) => {
  const cell = event.target.closest('.schedule-cell');
  if (!cell || event.target.closest('.slot')) return;
  const row = cell.closest('.shift-zone');
  const defaults = row?.classList.contains('shift-evening')
    ? ['18:00', '21:00']
    : row?.classList.contains('shift-afternoon')
      ? ['14:00', '17:00']
      : ['08:00', '11:00'];
  document.getElementById('slot-start').value = defaults[0];
  document.getElementById('slot-end').value = defaults[1];
});
</script>
<script>
(() => {
  const token = <?php echo json_encode(csrfToken()); ?>;
  const createClassDialog = document.getElementById('create-class-dialog');
  const createClassButton = document.createElement('button');
  createClassButton.type = 'button'; createClassButton.className = 'btn btn-primary';
  createClassButton.innerHTML = "<i class='bx bx-plus-circle'></i> Tạo lớp mới";
  createClassButton.addEventListener('click', () => createClassDialog.showModal());
  const scheduleActions = document.createElement('div');
  scheduleActions.className = 'schedule-page-actions';
  scheduleActions.append(createClassButton);
  document.querySelector('h1')?.after(scheduleActions);
  document.getElementById('close-create-class')?.addEventListener('click', () => createClassDialog.close());
  const createForm = createClassDialog.querySelector('form');
  createForm.insertAdjacentHTML('afterbegin', '<input type="hidden" name="scope" value="<?php echo $showOwnSchedule ? 'mine' : 'all'; ?>">');
  const plannedSchedule = document.createElement('fieldset');
  plannedSchedule.innerHTML = `<legend>Lịch học dự kiến <small>(tự tạo lịch)</small></legend><label>Số buổi<input type="number" name="total_sessions" min="0" max="500" value="0"><small>Để 0 nếu chưa muốn tự tạo lịch.</small></label><div class="weekday-label">Thứ học</div><div class="weekday-options"><label class="weekday-option"><input type="checkbox" name="planned_weekdays[]" value="1" checked><span>Thứ 2</span></label><label class="weekday-option"><input type="checkbox" name="planned_weekdays[]" value="2"><span>Thứ 3</span></label><label class="weekday-option"><input type="checkbox" name="planned_weekdays[]" value="3" checked><span>Thứ 4</span></label><label class="weekday-option"><input type="checkbox" name="planned_weekdays[]" value="4"><span>Thứ 5</span></label><label class="weekday-option"><input type="checkbox" name="planned_weekdays[]" value="5" checked><span>Thứ 6</span></label><label class="weekday-option"><input type="checkbox" name="planned_weekdays[]" value="6"><span>Thứ 7</span></label><label class="weekday-option"><input type="checkbox" name="planned_weekdays[]" value="7"><span>Chủ nhật</span></label></div><div class="time-grid"><label>Ngày bắt đầu<input type="date" name="planned_start_date" value="<?php echo date('Y-m-d'); ?>"></label><label class="lesson-shift-select">Chọn nhanh ca dạy<select name="planned_shift"><option value="morning">🌅 Ca sáng · 08:00 – 11:00</option><option value="afternoon">☀️ Ca chiều · 14:00 – 17:00</option><option value="evening">🌙 Ca tối · 18:00 – 21:00</option><option value="custom">✏️ Giờ tùy chỉnh</option></select><small>Chọn ca để điền nhanh giờ; vẫn có thể sửa trực tiếp bên dưới.</small></label><label>Giờ bắt đầu<input type="time" name="planned_start_time" value="08:00"></label><label>Giờ kết thúc<input type="time" name="planned_end_time" value="11:00"></label></div><div class="custom-shift-form"><strong><i class='bx bx-plus-circle'></i> Tạo ca mới</strong><label>Tên ca<input type="text" maxlength="80" id="new-shift-name" placeholder="Ví dụ: Ca trưa"></label><label>Bắt đầu<input type="time" id="new-shift-start" value="12:00"></label><label>Kết thúc<input type="time" id="new-shift-end" value="13:30"></label><button type="button" class="btn btn-outline" id="save-shift-preset">Lưu ca</button></div>`;
  createForm.querySelector('.dialog-actions').before(plannedSchedule);
  // Không chọn sẵn ngày nào: người tạo lớp tự chọn đúng lịch học thực tế.
  plannedSchedule.querySelectorAll('input[name="planned_weekdays[]"]').forEach((input) => { input.checked = false; });

  // Auto-fill thời gian khi chọn ca học
  const shiftDefaults = {morning: {start: '08:00', end: '11:00'}, afternoon: {start: '14:00', end: '17:00'}, evening: {start: '18:00', end: '21:00'}};
  const shiftRadios = createForm.querySelectorAll('input[name="time_shift"]');
  const plannedShiftSelect = plannedSchedule.querySelector('select[name="planned_shift"]');
  const customShiftPresets = <?php echo json_encode($customShiftPresets, JSON_UNESCAPED_UNICODE); ?>;
  const plannedStartInput = plannedSchedule.querySelector('input[name="planned_start_time"]');
  const plannedEndInput = plannedSchedule.querySelector('input[name="planned_end_time"]');
  const applyShiftDefaults = (shift) => {
    const defaults = shiftDefaults[shift];
    if (!defaults || !plannedStartInput || !plannedEndInput) return;
    plannedStartInput.value = defaults.start;
    plannedEndInput.value = defaults.end;
  };
  const addPresetOption = (preset, select = true) => {
    if (!plannedShiftSelect) return;
    const option = document.createElement('option');
    option.value = 'preset:' + preset.id;
    option.textContent = '🕘 ' + preset.name + ' · ' + preset.start_time.slice(0, 5) + ' – ' + preset.end_time.slice(0, 5);
    option.dataset.start = preset.start_time.slice(0, 5);
    option.dataset.end = preset.end_time.slice(0, 5);
    option.dataset.majorShift = preset.major_shift || 'morning';
    plannedShiftSelect.insertBefore(option, plannedShiftSelect.querySelector('option[value="custom"]'));
    if (select) plannedShiftSelect.value = option.value;
  };
  customShiftPresets.forEach((preset) => addPresetOption(preset, false));
  const shiftDialog = document.createElement('dialog');
  shiftDialog.className = 'schedule-dialog shift-manager-dialog';
  shiftDialog.innerHTML = `<form method="dialog"><h2 class="dialog-title"><i class='bx bx-time-five'></i> Ca học dùng chung</h2><p class="schedule-note">Buổi sáng, chiều và tối là các vùng lớn của lịch. Ca tạo mới được dùng chung cho tất cả giáo viên và cần thuộc một buổi để được xếp đúng vị trí.</p><div class="shift-preset-list" id="shift-preset-list"></div><div class="custom-shift-form"><strong><i class='bx bx-plus-circle'></i> Tạo ca dùng chung</strong><label>Thuộc buổi<select id="modal-new-shift-major"><option value="morning">🌅 Buổi sáng</option><option value="afternoon">☀️ Buổi chiều</option><option value="evening">🌙 Buổi tối</option></select></label><label>Tên ca<input type="text" maxlength="80" id="modal-new-shift-name" placeholder="Ví dụ: Ca trưa"></label><label>Bắt đầu<input type="time" id="modal-new-shift-start" value="12:00"></label><label>Kết thúc<input type="time" id="modal-new-shift-end" value="13:30"></label><button type="button" class="btn btn-primary" id="modal-save-shift">Lưu ca</button></div><div class="dialog-actions"><button class="btn btn-outline" value="cancel">Đóng</button></div></form>`;
  document.body.append(shiftDialog);
  const shiftPresetList = shiftDialog.querySelector('#shift-preset-list');
  const renderPreset = (name, start, end, custom = false) => {
    const item = document.createElement('div');
    item.className = 'shift-preset-item' + (custom ? ' is-custom' : '');
    const title = document.createElement('strong'); title.textContent = name;
    const time = document.createElement('span'); time.textContent = start.slice(0, 5) + ' – ' + end.slice(0, 5);
    item.append(title, time);
    shiftPresetList.append(item);
  };
  renderPreset('🌅 Ca sáng', '08:00', '11:00');
  renderPreset('☀️ Ca chiều', '14:00', '17:00');
  renderPreset('🌙 Ca tối', '18:00', '21:00');
  customShiftPresets.forEach((preset) => renderPreset('🕘 ' + preset.name, preset.start_time, preset.end_time, true));
  const shiftManagerButton = document.createElement('button');
  shiftManagerButton.type = 'button'; shiftManagerButton.className = 'btn btn-outline';
  shiftManagerButton.innerHTML = "<i class='bx bx-time-five'></i> Ca học";
  shiftManagerButton.addEventListener('click', () => shiftDialog.showModal());
  scheduleActions.append(shiftManagerButton);
  shiftDialog.querySelector('#modal-save-shift').addEventListener('click', async () => {
    const name = shiftDialog.querySelector('#modal-new-shift-name').value.trim();
    const start = shiftDialog.querySelector('#modal-new-shift-start').value;
    const end = shiftDialog.querySelector('#modal-new-shift-end').value;
    const majorShift = shiftDialog.querySelector('#modal-new-shift-major').value;
    if (!name || !start || !end || start >= end) return alert('Hãy nhập tên ca và giờ bắt đầu/kết thúc hợp lệ.');
    try {
      const response = await jsonRequest({action: 'create_shift_preset', name, start_time: start, end_time: end, major_shift: majorShift});
      addPresetOption(response.preset);
      renderPreset('🕘 ' + response.preset.name, response.preset.start_time, response.preset.end_time, true);
      shiftDialog.querySelector('#modal-new-shift-name').value = '';
      alert('Đã lưu ca “' + name + '”.');
    } catch (error) { alert(error.message); }
  });
  shiftRadios.forEach((radio) => {
    radio.addEventListener('change', () => {
      if (!radio.checked) return;
      if (plannedShiftSelect) plannedShiftSelect.value = radio.value;
      applyShiftDefaults(radio.value);
    });
  });
  plannedShiftSelect?.addEventListener('change', () => {
    if (plannedShiftSelect.value === 'custom') return;
    if (plannedShiftSelect.value.startsWith('preset:')) {
      const option = plannedShiftSelect.selectedOptions[0];
      plannedStartInput.value = option.dataset.start;
      plannedEndInput.value = option.dataset.end;
      const classShift = createForm.querySelector('input[name="time_shift"][value="' + option.dataset.majorShift + '"]');
      if (classShift) classShift.checked = true;
      return;
    }
    const classShift = createForm.querySelector('input[name="time_shift"][value="' + plannedShiftSelect.value + '"]');
    if (classShift) classShift.checked = true;
    applyShiftDefaults(plannedShiftSelect.value);
  });
  [plannedStartInput, plannedEndInput].forEach((input) => input?.addEventListener('change', () => {
    if (plannedShiftSelect) plannedShiftSelect.value = 'custom';
  }));
  document.getElementById('save-shift-preset')?.addEventListener('click', async () => {
    const name = document.getElementById('new-shift-name').value.trim();
    const start = document.getElementById('new-shift-start').value;
    const end = document.getElementById('new-shift-end').value;
    if (!name || !start || !end || start >= end) return alert('Hãy nhập tên ca và giờ bắt đầu/kết thúc hợp lệ.');
    try {
      const preset = await jsonRequest({action: 'create_shift_preset', name, start_time: start, end_time: end});
      addPresetOption(preset.preset);
      plannedStartInput.value = start;
      plannedEndInput.value = end;
      document.getElementById('new-shift-name').value = '';
      alert('Đã lưu ca “' + name + '”.');
    } catch (error) { alert(error.message); }
  });

  const bindCourseName = (selectId, inputId) => {
    const select = document.getElementById(selectId), input = document.getElementById(inputId);
    if (!select || !input) return;
    select.addEventListener('change', () => {
      const option = select.options[select.selectedIndex];
      if (select.value) input.value = option.dataset.title || option.text;
    });
  };
  bindCourseName('course-id', 'class-name');
  bindCourseName('edit-course-id', 'edit-class-name');

  const classDialog = document.getElementById('class-dialog');
  const editForm = document.getElementById('class-form');
  const noteLabel = document.createElement('label');
  noteLabel.innerHTML = 'Ghi chú lớp<textarea name="notes" id="edit-class-notes" placeholder="Ghi chú lịch học, phòng học hoặc thông tin cần lưu"></textarea>';
  editForm.querySelector('.dialog-actions').before(noteLabel);
  const editSchedule = document.createElement('fieldset');
  editSchedule.className = 'edit-schedule-fields';
  editSchedule.innerHTML = `<legend>Lịch học</legend>
    <label class="edit-schedule-toggle"><input type="checkbox" name="update_schedule" value="1" id="edit-update-schedule"> Thay đổi lịch từ ngày áp dụng</label>
    <div class="edit-schedule-inputs">
      <p class="schedule-note">Các buổi trước ngày áp dụng được giữ nguyên. Các buổi từ ngày đó trở đi sẽ theo lịch mới.</p>
      <div class="time-grid"><label>Ngày áp dụng<input type="date" name="schedule_apply_date" id="edit-schedule-apply-date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>"></label><label>Tổng số buổi<input type="number" name="total_sessions" id="edit-total-sessions" min="0" max="500"></label></div>
      <div class="weekday-label">Thứ học</div>
      <div class="weekday-options"><label class="weekday-option"><input type="checkbox" name="planned_weekdays[]" value="1"><span>Thứ 2</span></label><label class="weekday-option"><input type="checkbox" name="planned_weekdays[]" value="2"><span>Thứ 3</span></label><label class="weekday-option"><input type="checkbox" name="planned_weekdays[]" value="3"><span>Thứ 4</span></label><label class="weekday-option"><input type="checkbox" name="planned_weekdays[]" value="4"><span>Thứ 5</span></label><label class="weekday-option"><input type="checkbox" name="planned_weekdays[]" value="5"><span>Thứ 6</span></label><label class="weekday-option"><input type="checkbox" name="planned_weekdays[]" value="6"><span>Thứ 7</span></label><label class="weekday-option"><input type="checkbox" name="planned_weekdays[]" value="7"><span>Chủ nhật</span></label></div>
      <div class="time-grid"><label>Giờ bắt đầu<input type="time" name="planned_start_time" id="edit-planned-start-time"></label><label>Giờ kết thúc<input type="time" name="planned_end_time" id="edit-planned-end-time"></label></div>
    </div>`;
  noteLabel.after(editSchedule);
  const updateScheduleToggle = document.getElementById('edit-update-schedule');
  const editScheduleInputs = editSchedule.querySelector('.edit-schedule-inputs');
  const setScheduleInputsEnabled = (enabled) => {
    editScheduleInputs.querySelectorAll('input').forEach((input) => { input.disabled = !enabled; });
    editSchedule.classList.toggle('is-enabled', enabled);
  };
  updateScheduleToggle.addEventListener('change', () => setScheduleInputsEnabled(updateScheduleToggle.checked));
  setScheduleInputsEnabled(false);
  const jsonRequest = async (body) => {
    const response = await fetch(location.href, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({...body, csrf_token: token})});
    const data = await response.json();
    if (!data.ok) throw new Error(data.message || 'Không thể lấy dữ liệu lớp.');
    return data;
  };
  document.querySelectorAll('.info-cell').forEach((cell) => {
    const row = cell.closest('tr');
    const classId = row?.querySelector('.schedule-cell')?.dataset.classId;
    const head = cell.querySelector('.class-head');
    if (!classId || !head) return;
    head.querySelector('form')?.remove();
    const actions = document.createElement('div');
    actions.className = 'class-actions';
    actions.innerHTML = `<button type="button" class="class-edit" title="Sửa lớp"><i class='bx bx-edit'></i></button><form method="post" onsubmit="return confirm('Kết thúc lớp này? Lớp sẽ được ẩn khỏi lịch mặc định.');"><input type="hidden" name="csrf_token" value="${token}"><input type="hidden" name="action" value="complete_class"><input type="hidden" name="class_id" value="${classId}"><input type="hidden" name="month" value="<?php echo htmlspecialchars($month, ENT_QUOTES); ?>"><button type="submit" class="class-complete" title="Kết thúc lớp"><i class='bx bx-check'></i></button></form><form method="post" onsubmit="return confirm('Xóa lớp này và toàn bộ lịch dạy của lớp?');"><input type="hidden" name="csrf_token" value="${token}"><input type="hidden" name="action" value="delete_class"><input type="hidden" name="class_id" value="${classId}"><input type="hidden" name="month" value="<?php echo htmlspecialchars($month, ENT_QUOTES); ?>"><button type="submit" class="class-delete" title="Xóa lớp"><i class='bx bx-trash'></i></button></form>`;
    const pauseForm = document.createElement('form');
    pauseForm.method = 'post';
    pauseForm.innerHTML = `<input type="hidden" name="csrf_token" value="${token}"><input type="hidden" name="action" value="pause_class"><input type="hidden" name="class_id" value="${classId}"><input type="hidden" name="month" value="<?php echo htmlspecialchars($month, ENT_QUOTES); ?>"><button type="submit" class="class-pause" title="Tạm dừng lớp"><i class='bx bx-pause'></i></button>`;
    pauseForm.addEventListener('submit', (event) => { if (!confirm('Tạm dừng lớp này? Lớp sẽ được ẩn khỏi lịch mặc định.')) event.preventDefault(); });
    actions.querySelector('.class-edit').after(pauseForm);
    const dragHandle = document.createElement('button');
    dragHandle.type = 'button'; dragHandle.className = 'class-drag'; dragHandle.title = 'Kéo để đổi thứ tự lớp';
    dragHandle.innerHTML = "<i class='bx bx-grid-vertical'></i>";
    actions.prepend(dragHandle);
    const moveForm = (direction, icon, label) => {
      const form = document.createElement('form'); form.method = 'post';
      form.innerHTML = `<input type="hidden" name="csrf_token" value="${token}"><input type="hidden" name="action" value="move_class"><input type="hidden" name="class_id" value="${classId}"><input type="hidden" name="direction" value="${direction}"><input type="hidden" name="month" value="<?php echo htmlspecialchars($month, ENT_QUOTES); ?>"><button type="submit" class="class-edit" title="${label}"><i class='bx ${icon}'></i></button>`;
      return form;
    };
    actions.prepend(moveForm('down', 'bx-down-arrow-alt', 'Đưa lớp xuống'));
    actions.prepend(moveForm('up', 'bx-up-arrow-alt', 'Đưa lớp lên'));
    actions.querySelectorAll('form').forEach((form) => form.remove());
    head.append(actions);
    actions.querySelector('.class-edit').addEventListener('click', async () => {
      try {
        const data = await jsonRequest({action: 'get_class', class_id: +classId});
        const classData = data.class;
        document.getElementById('edit-class-id').value = classData.id;
        document.getElementById('edit-course-id').value = classData.course_id || '';
        document.getElementById('edit-class-name').value = classData.class_name || '';
        document.getElementById('edit-class-notes').value = classData.notes || '';
        const teacher = document.getElementById('edit-teacher-id'); if (teacher) teacher.value = classData.teacher_id || '';
        const editShift = document.getElementById('edit-time-shift'); if (editShift) editShift.value = classData.time_shift || 'morning';
        document.getElementById('edit-student-names').value = classData.students || '';
        updateScheduleToggle.checked = false;
        setScheduleInputsEnabled(false);
        document.getElementById('edit-total-sessions').value = classData.total_sessions || 0;
        document.getElementById('edit-schedule-apply-date').value = <?php echo json_encode(date('Y-m-d')); ?>;
        document.getElementById('edit-planned-start-time').value = String(classData.planned_start_time || '08:00').slice(0, 5);
        document.getElementById('edit-planned-end-time').value = String(classData.planned_end_time || '11:00').slice(0, 5);
        const plannedDays = String(classData.planned_weekdays || '').split(',');
        editSchedule.querySelectorAll('input[name="planned_weekdays[]"]').forEach((input) => { input.checked = plannedDays.includes(input.value); });
        const formActions = document.querySelector('#class-form .dialog-actions');
        let statusActions = document.getElementById('class-status-actions');
        if (!statusActions) {
          statusActions = document.createElement('div'); statusActions.id = 'class-status-actions'; statusActions.className = 'dialog-actions';
          formActions.before(statusActions);
        }
        const submitClassAction = (action, message) => {
          if (!confirm(message)) return;
          document.querySelector('#class-form input[name="action"]').value = action;
          document.getElementById('class-form').submit();
        };
        statusActions.innerHTML = `<button type="button" class="btn btn-outline" id="pause-edit-class"><i class='bx bx-pause'></i> Tạm dừng lớp</button><button type="button" class="btn btn-outline" id="complete-edit-class"><i class='bx bx-check'></i> Kết thúc lớp</button><button type="button" class="btn btn-outline" id="delete-edit-class"><i class='bx bx-trash'></i> Xóa lớp</button>`;
        document.getElementById('pause-edit-class').addEventListener('click', () => submitClassAction('pause_class', 'Tạm dừng lớp này? Lớp sẽ được ẩn khỏi lịch mặc định.'));
        document.getElementById('complete-edit-class').addEventListener('click', () => submitClassAction('complete_class', 'Kết thúc lớp này? Lớp sẽ được ẩn khỏi lịch mặc định.'));
        document.getElementById('delete-edit-class').addEventListener('click', () => submitClassAction('delete_class', 'Xóa lớp này và toàn bộ lịch dạy của lớp?'));
        classDialog.showModal();
      } catch (error) { alert(error.message); }
    });
  });
  document.getElementById('close-class-dialog')?.addEventListener('click', () => classDialog.close());
  const pausedDialog = document.getElementById('paused-dialog');
  pausedDialog?.querySelectorAll('form[method="post"]').forEach((form) => {
    form.insertAdjacentHTML('afterbegin', '<input type="hidden" name="scope" value="<?php echo $showOwnSchedule ? 'mine' : 'all'; ?>">');
  });
  const pausedButton = document.createElement('button');
  pausedButton.type = 'button'; pausedButton.className = 'btn btn-outline';
  pausedButton.innerHTML = `<i class='bx bx-pause-circle'></i> Lớp tạm dừng (<?php echo count($pausedClasses); ?>)`;
  pausedButton.addEventListener('click', () => pausedDialog.showModal());
  document.querySelector('.month-bar')?.append(pausedButton);

  const tableBody = document.querySelector('.schedule-table tbody');
  if (tableBody) {
    let draggedRow = null;
    let orderBeforeDrag = '';
    let savingOrder = false;
    const classRows = () => [...tableBody.querySelectorAll('tr')].filter((row) => row.querySelector('.schedule-cell[data-class-id]'));
    const currentClassIds = () => classRows().map((item) => +item.querySelector('.schedule-cell').dataset.classId);
    const orderStatus = document.createElement('span');
    orderStatus.className = 'class-order-status';
    orderStatus.innerHTML = "<i class='bx bx-move-vertical'></i> Kéo lớp để đổi thứ tự";
    document.querySelector('.month-bar')?.append(orderStatus);
    const persistClassOrder = async () => {
      const classIds = currentClassIds();
      if (!classIds.length || classIds.join(',') === orderBeforeDrag || savingOrder) return;
      savingOrder = true;
      orderStatus.className = 'class-order-status saving';
      orderStatus.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Đang lưu thứ tự...";
      try {
        await jsonRequest({action: 'reorder_classes', class_ids: classIds});
        orderBeforeDrag = classIds.join(',');
        orderStatus.className = 'class-order-status saved';
        orderStatus.innerHTML = "<i class='bx bx-check'></i> Đã lưu thứ tự";
        window.setTimeout(() => {
          orderStatus.className = 'class-order-status';
          orderStatus.innerHTML = "<i class='bx bx-move-vertical'></i> Kéo lớp để đổi thứ tự";
        }, 1800);
      } catch (error) {
        orderStatus.className = 'class-order-status error';
        orderStatus.innerHTML = "<i class='bx bx-error-circle'></i> Không thể lưu thứ tự";
        alert(error.message);
        window.setTimeout(() => location.reload(), 500);
      } finally {
        savingOrder = false;
      }
    };
    classRows().forEach((row) => {
      row.draggable = true;
      row.querySelector('.class-drag')?.setAttribute('draggable', 'true');
      row.addEventListener('dragstart', (event) => {
        draggedRow = row;
        orderBeforeDrag = currentClassIds().join(',');
        row.classList.add('dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', row.querySelector('.schedule-cell').dataset.classId || '');
      });
      row.addEventListener('dragend', async () => {
        row.classList.remove('dragging');
        tableBody.querySelectorAll('.drag-target').forEach((item) => item.classList.remove('drag-target'));
        draggedRow = null;
        await persistClassOrder();
      });
      row.addEventListener('dragover', (event) => {
        if (!draggedRow || draggedRow === row) return;
        event.preventDefault();
        const after = event.clientY > row.getBoundingClientRect().top + row.offsetHeight / 2;
        tableBody.insertBefore(draggedRow, after ? row.nextSibling : row);
        row.classList.add('drag-target');
      });
      row.addEventListener('drop', async (event) => {
        event.preventDefault();
        tableBody.querySelectorAll('.drag-target').forEach((item) => item.classList.remove('drag-target'));
      });
    });
  }
})();
</script>
<script>
(() => {
  const form = document.getElementById('slot-form');
  const grid = form?.querySelector('.time-grid');
  if (!form || !grid) return;
  const presetSelect = document.getElementById('slot-time-preset');
  const startInput = document.getElementById('slot-start');
  const endInput = document.getElementById('slot-end');
  const customPresets = <?php echo json_encode($customShiftPresets, JSON_UNESCAPED_UNICODE); ?>;
  customPresets.forEach((preset) => {
    const start = String(preset.start_time).slice(0, 5);
    const end = String(preset.end_time).slice(0, 5);
    const option = document.createElement('option');
    option.value = start + '|' + end;
    option.textContent = preset.name + ' · ' + start + ' – ' + end;
    presetSelect.insertBefore(option, presetSelect.options[1] || null);
  });
  const syncPreset = () => {
    const value = startInput.value + '|' + endInput.value;
    presetSelect.value = [...presetSelect.options].some((option) => option.value === value) ? value : 'custom';
  };
  presetSelect.addEventListener('change', () => {
    if (presetSelect.value === 'custom') return;
    const [start, end] = presetSelect.value.split('|');
    startInput.value = start;
    endInput.value = end;
  });
  startInput.addEventListener('input', syncPreset);
  endInput.addEventListener('input', syncPreset);
  form.addEventListener('slotdialogopen', () => window.setTimeout(syncPreset));
  const options = <?php echo json_encode($substituteTeachers, JSON_UNESCAPED_UNICODE); ?>;
  const label = document.createElement('label');
  label.innerHTML = 'Giáo viên dạy thay <select id="slot-substitute-teacher"><option value="">Giáo viên phụ trách lớp</option></select><small>Chỉ áp dụng cho buổi này; lịch sẽ hiện ở tài khoản giáo viên dạy thay.</small>';
  const select = label.querySelector('select');
  options.forEach((teacher) => {
    const option = document.createElement('option');
    option.value = teacher.id; option.textContent = teacher.name; select.append(option);
  });
  grid.after(label);
  const slotSubstitutes = <?php $slotSubstituteMap = []; foreach ($slots as $classSlots) foreach ($classSlots as $dateSlots) foreach ($dateSlots as $savedSlot) $slotSubstituteMap[(int) $savedSlot['id']] = (int) ($savedSlot['substitute_teacher_id'] ?? 0); echo json_encode($slotSubstituteMap); ?>;
  document.querySelectorAll('.schedule-cell').forEach((cell) => cell.addEventListener('click', (event) => {
    const slot = event.target.closest('.slot');
    select.value = slot ? String(slotSubstitutes[slot.dataset.slotId] || '') : '';
  }));
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submitButton = form.querySelector('button[type="submit"]');
    const originalButtonHtml = submitButton.innerHTML;
    submitButton.disabled = true;
    submitButton.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Đang lưu...";
    try {
      const response = await fetch(location.href, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({
        action: 'save_slot', csrf_token: <?php echo json_encode(csrfToken()); ?>,
        class_id: +document.getElementById('slot-class-id').value,
        slot_id: +document.getElementById('slot-id').value,
        date: document.getElementById('slot-date').value,
        start_time: document.getElementById('slot-start').value,
        end_time: document.getElementById('slot-end').value,
        substitute_teacher_id: +select.value
      })});
      const result = await response.json();
      if (!result.ok) throw new Error(result.message || 'Không thể lưu ca dạy.');
      const savedSlot = result.slot;
      const cell = document.querySelector('.schedule-cell[data-class-id="' + savedSlot.class_id + '"][data-date="' + savedSlot.date + '"]');
      if (!cell) throw new Error('Đã lưu ca dạy nhưng không tìm thấy ô lịch để cập nhật.');

      let slotButton = cell.querySelector('.slot[data-slot-id="' + savedSlot.id + '"]');
      if (!slotButton) {
        slotButton = document.createElement('button');
        slotButton.type = 'button';
        slotButton.className = 'slot';
      }
      slotButton.dataset.slotId = savedSlot.id;
      slotButton.dataset.start = savedSlot.start_time.slice(0, 5);
      slotButton.dataset.end = savedSlot.end_time.slice(0, 5);
      slotButton.textContent = slotButton.dataset.start + ' – ' + slotButton.dataset.end;
      slotSubstitutes[savedSlot.id] = +(savedSlot.substitute_teacher_id || 0);

      if (!slotButton.isConnected) cell.insertBefore(slotButton, cell.querySelector('.add-slot'));
      const addButton = cell.querySelector('.add-slot');
      [...cell.querySelectorAll('.slot')]
        .sort((first, second) => first.dataset.start.localeCompare(second.dataset.start))
        .forEach((button) => cell.insertBefore(button, addButton));
      document.getElementById('slot-id').value = savedSlot.id;
      document.getElementById('schedule-dialog').close();
    } catch (error) {
      alert(error.message);
    } finally {
      submitButton.disabled = false;
      submitButton.innerHTML = originalButtonHtml;
    }
  });
})();
</script>
<style>
/* Dùng cùng cơ chế cố định tiêu đề và ca học của teacher_schedules.php. */
.calendar-wrap{position:relative;--schedule-header-height:70px}
.calendar-wrap .schedule-table th,
.calendar-wrap .schedule-table th.info-head{position:static}
.calendar-wrap .schedule-table .shift-label{position:sticky;top:var(--schedule-header-height);z-index:12;box-shadow:0 2px 6px rgba(0,0,0,.24)}
.calendar-wrap .schedule-table th{height:var(--schedule-header-height);box-sizing:border-box}
.calendar-wrap .schedule-table td.info-cell{z-index:4}
.schedule-sticky-head{position:sticky;top:0;left:0;z-index:20;height:0;overflow:visible;pointer-events:none}
.schedule-sticky-head__content{position:absolute;top:0;left:0;height:var(--schedule-header-height);overflow:hidden;background:#1f517e}
.schedule-sticky-head__content table{margin:0!important;border-collapse:separate;border-spacing:0;table-layout:fixed}
.schedule-sticky-head__content th{height:var(--schedule-header-height)!important;box-sizing:border-box;background:#1f517e!important}
.schedule-sticky-head__content th.weekend{background:#3d3d3d!important}
.calendar-wrap .schedule-table th.today,.schedule-sticky-head__content th.today{box-shadow:inset 0 -4px 0 #ff3b6b!important}
.schedule-sticky-shift{position:absolute;top:0;left:0;z-index:19;height:0;overflow:visible;pointer-events:none}
.schedule-sticky-shift__content{position:absolute;top:0;left:0;display:none;box-sizing:border-box;padding:8px 14px;color:#fff;font-weight:800;font-size:13px;letter-spacing:.5px;white-space:nowrap;overflow:hidden;box-shadow:0 2px 6px rgba(0,0,0,.24);will-change:transform}
</style>
<script>
(() => {
    document.querySelectorAll('.calendar-wrap').forEach((wrap) => {
        const table = wrap.querySelector('.schedule-table');
        if (!table || !table.tHead || wrap.querySelector('.schedule-sticky-head')) return;

        const sticky = document.createElement('div');
        sticky.className = 'schedule-sticky-head';
        const content = document.createElement('div');
        content.className = 'schedule-sticky-head__content';
        const cloneTable = table.cloneNode(false);
        const colgroup = document.createElement('colgroup');
        cloneTable.appendChild(colgroup);
        cloneTable.appendChild(table.tHead.cloneNode(true));
        content.appendChild(cloneTable);
        sticky.appendChild(content);
        wrap.prepend(sticky);

        const shiftSticky = document.createElement('div');
        shiftSticky.className = 'schedule-sticky-shift';
        const shiftContent = document.createElement('div');
        shiftContent.className = 'schedule-sticky-shift__content';
        shiftSticky.appendChild(shiftContent);
        wrap.prepend(shiftSticky);
        const shiftLabels = Array.from(table.querySelectorAll('tbody .shift-label'));
        let activeShift = null;

        const syncHeader = () => {
            const cells = Array.from(table.tHead.rows[0].cells);
            colgroup.replaceChildren(...cells.map((cell) => {
                const col = document.createElement('col');
                col.style.width = `${cell.getBoundingClientRect().width}px`;
                return col;
            }));
            const width = table.getBoundingClientRect().width;
            cloneTable.style.width = `${width}px`;
            content.style.width = `${width}px`;
            content.style.transform = `translateX(${-wrap.scrollLeft}px)`;

            const headerHeight = Math.max(70, Math.ceil(table.tHead.getBoundingClientRect().height));
            wrap.style.setProperty('--schedule-header-height', `${headerHeight}px`);
            content.style.height = `${headerHeight}px`;

            const stickyLine = wrap.scrollTop + headerHeight + 1;
            let currentShift = null;
            shiftLabels.forEach((label) => {
                if (label.parentElement.offsetTop <= stickyLine) currentShift = label;
            });

            if (!currentShift) {
                if (activeShift) activeShift.style.visibility = '';
                shiftContent.style.display = 'none';
                activeShift = null;
                return;
            }

            if (currentShift !== activeShift) {
                if (activeShift) activeShift.style.visibility = '';
                shiftContent.textContent = currentShift.textContent.trim();
                shiftContent.style.background = getComputedStyle(currentShift).backgroundColor;
                currentShift.style.visibility = 'hidden';
                activeShift = currentShift;
            }
            shiftContent.style.display = 'block';
            shiftContent.style.width = `${width}px`;
            shiftContent.style.height = `${Math.ceil(currentShift.getBoundingClientRect().height)}px`;
            shiftContent.style.transform = `translate3d(${-wrap.scrollLeft}px, ${wrap.scrollTop + headerHeight}px, 0)`;
        };

        wrap.addEventListener('scroll', syncHeader, { passive: true });
        window.addEventListener('resize', syncHeader);
        syncHeader();
    });
})();
</script>
<?php require_once '../includes/footer.php'; ?>
