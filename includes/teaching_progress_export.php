<?php
declare(strict_types=1);

require_once __DIR__ . '/simple_xlsx.php';
require_once __DIR__ . '/schedule_time.php';
require_once __DIR__ . '/teaching_schedule_visibility.php';

/** Validate the selection before querying, so an empty selection never exports all classes. */
function teachingProgressExportSelection(array $request): array
{
    $mode = $request['mode'] ?? 'all';
    if (!is_string($mode) || !in_array($mode, ['all', 'single', 'selected'], true)) {
        throw new InvalidArgumentException('Phạm vi xuất Excel không hợp lệ.');
    }
    if ($mode === 'all') return [];
    $values = $mode === 'single' ? [$request['class_id'] ?? null] : ($request['class_ids'] ?? []);
    if (!is_array($values) || !$values) {
        throw new InvalidArgumentException('Vui lòng chọn ít nhất một lớp để xuất Excel.');
    }
    $ids = [];
    foreach ($values as $value) {
        if (!is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new InvalidArgumentException('Lớp được chọn không hợp lệ.');
        }
        $ids[] = (int) $value;
    }
    return array_values(array_unique($ids));
}

/** Use the same owner/substitute scope as the teaching schedule screens. */
function teachingProgressExportClasses(PDO $pdo, int $actorId, string $role, array $request): array
{
    if ($actorId <= 0 || !in_array($role, ['admin', 'teacher', 'administrative_staff'], true)) {
        throw new DomainException('Bạn không có quyền xuất tiến độ lịch dạy.');
    }
    $ids = teachingProgressExportSelection($request);
    $teacherId = $role === 'admin' && ($request['scope'] ?? '') !== 'mine' ? null : $actorId;
    if (isset($request['teacher_id'])) {
        $requested = filter_var($request['teacher_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($requested === false) throw new InvalidArgumentException('Giáo viên không hợp lệ.');
        if ($role === 'teacher' && $requested !== $actorId) {
            throw new DomainException('Bạn không có quyền xuất lịch của giáo viên này.');
        }
        $teacherId = $requested;
    }
    $sql = "SELECT tc.id, tc.class_name, tc.status, tc.total_sessions, u.name AS teacher_name,
                   COALESCE(s.regular_total, 0) AS regular_total
            FROM teaching_classes tc
            LEFT JOIN users u ON u.id=tc.teacher_id
            LEFT JOIN (
                SELECT teaching_class_id, COUNT(*) AS regular_total
                FROM teaching_schedule_slots
                WHERE makeup_student_id IS NULL AND attendance_status<>'absent'
                GROUP BY teaching_class_id
            ) s ON s.teaching_class_id=tc.id
            " . (isset($request['date']) ? teachingScheduleClassDatesJoinSql() : '') . "
            WHERE 1=1";
    $params = [];
    if (isset($request['date'])) {
        if (!is_string($request['date'])) throw new InvalidArgumentException('Ngày xem lịch không hợp lệ.');
        [$rangeStart, $rangeEnd] = teachingScheduleExportWeek($request['date']);
        $visibility = teachingScheduleClassVisibilitySql($rangeStart, $rangeEnd, ($request['show_completed'] ?? '') === '1');
        $sql .= ' AND ' . $visibility['sql'];
        $params = $visibility['params'];
    } elseif (($request['show_completed'] ?? '') !== '1') {
        $sql .= " AND tc.status='active'";
    }
    if ($teacherId !== null) {
        $sql .= ' AND (tc.teacher_id=? OR EXISTS (SELECT 1 FROM teaching_schedule_slots own_slot
                  WHERE own_slot.teaching_class_id=tc.id AND own_slot.substitute_teacher_id=?))';
        array_push($params, $teacherId, $teacherId);
    }
    if ($ids) {
        $sql .= ' AND tc.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        array_push($params, ...$ids);
    }
    $statement = $pdo->prepare($sql . ' ORDER BY tc.sort_order, tc.id');
    $statement->execute($params);
    $classes = $statement->fetchAll(PDO::FETCH_ASSOC);
    if ($ids && count($classes) !== count($ids)) {
        throw new DomainException('Có lớp không còn trong danh sách hoặc bạn không có quyền xuất. Vui lòng tải lại lịch và chọn lại lớp.');
    }
    if (!$classes) throw new InvalidArgumentException('Chưa có lớp nào trong phạm vi đã chọn để xuất Excel.');
    return $classes;
}

function teachingProgressExportStudents(PDO $pdo, array $classes): array
{
    if (!$classes) return [];
    $ids = array_map('intval', array_column($classes, 'id'));
    // A personal makeup belongs only to its target student. A regular session counts
    // as learned only when the class is present and that student is not absent.
    $sql = "SELECT student.id, student.teaching_class_id, student.student_name,
                SUM(CASE WHEN ts.attendance_status='present'
                    AND (ts.makeup_student_id=student.id OR COALESCE(sa.attendance_status,'present')='present')
                    THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN ts.attendance_status='absent'
                    OR (ts.makeup_student_id IS NULL AND sa.attendance_status='absent')
                    THEN 1 ELSE 0 END) AS absent,
                SUM(CASE WHEN ts.makeup_student_id=student.id AND ts.attendance_status='present'
                    THEN 1 ELSE 0 END) AS makeup_completed
            FROM teaching_class_students student
            LEFT JOIN teaching_schedule_slots ts ON ts.teaching_class_id=student.teaching_class_id
                AND (ts.makeup_student_id IS NULL OR ts.makeup_student_id=student.id)
            LEFT JOIN teaching_schedule_student_attendance sa ON sa.slot_id=ts.id AND sa.student_id=student.id
            WHERE student.teaching_class_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
            GROUP BY student.id, student.teaching_class_id, student.student_name
            ORDER BY student.student_name, student.id";
    $statement = $pdo->prepare($sql);
    $statement->execute($ids);
    $students = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $student) {
        $students[(int) $student['teaching_class_id']][] = $student;
    }
    return $students;
}

/** Load confirmed attended sessions only for the already authorized class list. */
function teachingProgressExportSessions(PDO $pdo, array $classes): array
{
    if (!$classes) return [];
    $ids = array_map('intval', array_column($classes, 'id'));
    $sql = "SELECT student.id AS student_id, student.teaching_class_id, student.student_name,
                   ts.id AS slot_id, ts.teaching_date, ts.start_time, ts.end_time,
                   ts.attendance_status, ts.is_makeup, ts.makeup_student_id,
                   sa.attendance_status AS student_attendance_status,
                   replacement.name AS substitute_teacher_name
            FROM teaching_class_students student
            JOIN teaching_schedule_slots ts ON ts.teaching_class_id=student.teaching_class_id
                AND (ts.makeup_student_id IS NULL OR ts.makeup_student_id=student.id)
            LEFT JOIN teaching_schedule_student_attendance sa ON sa.slot_id=ts.id AND sa.student_id=student.id
            LEFT JOIN users replacement ON replacement.id=ts.substitute_teacher_id
            WHERE student.teaching_class_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
                AND ts.attendance_status='present'
                AND (ts.makeup_student_id=student.id OR COALESCE(sa.attendance_status,'present')='present')
            ORDER BY student.student_name, student.id, ts.teaching_date, ts.start_time, ts.end_time, ts.id";
    $statement = $pdo->prepare($sql);
    $statement->execute($ids);
    $sessions = [];
    foreach ($statement as $session) $sessions[(int) $session['teaching_class_id']][] = $session;
    return $sessions;
}

function teachingProgressExportWorkbook(array $classes, array $students, array $sessions): SimpleXlsxWorkbook
{
    $workbook = new SimpleXlsxWorkbook();
    $exportedAt = date('d/m/Y H:i');
    $byStudent = [];
    foreach ($sessions as $classId => $classSessions) {
        foreach ($classSessions as $session) $byStudent[$classId][(int) $session['student_id']][] = $session;
    }
    $columnName = static function (int $column): string {
        $name = '';
        while ($column > 0) {
            $column--;
            $name = chr(65 + $column % 26) . $name;
            $column = intdiv($column, 26);
        }
        return $name;
    };
    $addMatrix = static function (string $name, array $sheetClasses, bool $includeClass) use ($workbook, $students, $byStudent, $exportedAt, $columnName): void {
        $identityColumns = $includeClass ? 2 : 1;
        $sessionColumns = 1;
        foreach ($sheetClasses as $class) {
            $total = (int) $class['total_sessions'] > 0 ? (int) $class['total_sessions'] : (int) $class['regular_total'];
            $sessionColumns = max($sessionColumns, $total);
            foreach ($byStudent[(int) $class['id']] ?? [] as $studentSessions) $sessionColumns = max($sessionColumns, count($studentSessions));
        }
        $lastColumn = $identityColumns + $sessionColumns + 1;
        $lastName = $columnName($lastColumn);
        $firstSessionName = $columnName($identityColumns + 1);
        $lastSessionName = $columnName($lastColumn - 1);
        $subtitle = $includeClass ? 'Số lớp: ' . count($sheetClasses)
            : 'Lớp: ' . $sheetClasses[0]['class_name'] . ' | Giáo viên: ' . ($sheetClasses[0]['teacher_name'] ?: 'Chưa phân công');
        $rows = [
            ['height' => 30, 'cells' => [1 => ['value' => 'TIẾN ĐỘ HỌC TẬP', 'style' => 1]]],
            ['height' => 36, 'cells' => [1 => ['value' => $subtitle . ' | Xuất lúc: ' . $exportedAt, 'style' => 19]]],
            ['height' => 44, 'cells' => [1 => ['value' => 'B1, B2…: các buổi Có học theo ngày/giờ, gồm học bù; ngày dd/mm/yyyy. Mỗi ca là một buổi. Tổng = đã học / tổng buổi.', 'style' => 19]]],
        ];
        $topHeader = [];
        $bottomHeader = [];
        $merges = ['A1:' . $lastName . '1', 'A2:' . $lastName . '2', 'A3:' . $lastName . '3'];
        for ($column = 1; $column <= $lastColumn; $column++) {
            $topHeader[$column] = ['value' => '', 'style' => 4];
            $bottomHeader[$column] = ['value' => '', 'style' => 4];
        }
        if ($includeClass) $topHeader[1]['value'] = 'Lớp';
        $topHeader[$identityColumns]['value'] = 'Học viên';
        for ($column = 1; $column <= $identityColumns; $column++) {
            $merges[] = $columnName($column) . '4:' . $columnName($column) . '5';
        }
        $topHeader[$identityColumns + 1]['value'] = 'Danh sách buổi học (Ngày có mặt)';
        if ($sessionColumns > 1) $merges[] = $firstSessionName . '4:' . $lastSessionName . '4';
        $topHeader[$lastColumn]['value'] = 'Tổng';
        $merges[] = $lastName . '4:' . $lastName . '5';
        for ($session = 1; $session <= $sessionColumns; $session++) {
            $bottomHeader[$identityColumns + $session]['value'] = 'B' . $session;
        }
        $rows[] = ['height' => 28, 'cells' => $topHeader];
        $rows[] = ['height' => 26, 'cells' => $bottomHeader];
        foreach ($sheetClasses as $class) {
            $classStudents = $students[(int) $class['id']] ?? [];
            $total = (int) $class['total_sessions'] > 0 ? (int) $class['total_sessions'] : (int) $class['regular_total'];
            foreach ($classStudents ?: [null] as $student) {
                $cells = [];
                if ($includeClass) $cells[1] = ['value' => $class['class_name'], 'style' => 18];
                $cells[$identityColumns] = ['value' => $student['student_name'] ?? 'Chưa có học viên', 'style' => 18];
                $studentSessions = $student ? ($byStudent[(int) $class['id']][(int) $student['id']] ?? []) : [];
                for ($session = 0; $session < $sessionColumns; $session++) {
                    $date = isset($studentSessions[$session]) ? new DateTimeImmutable((string) $studentSessions[$session]['teaching_date']) : null;
                    $cells[$identityColumns + $session + 1] = ['value' => $date ? $date->format('d/m/Y') : '-', 'style' => $date ? 16 : 12];
                }
                $completed = count($studentSessions);
                $cells[$lastColumn] = ['value' => !$student ? '-' : ($total > 0 ? $completed . '/' . $total : (string) $completed), 'style' => 17];
                $rows[] = ['height' => 32, 'cells' => $cells];
            }
        }
        $widths = $includeClass ? [28, 30] : [30];
        $widths = [...$widths, ...array_fill(0, $sessionColumns, 13), 12];
        $workbook->addSheet($name, $rows, $widths, $merges, ['freeze_rows' => 5, 'freeze_columns' => $identityColumns]);
    };
    $addMatrix('Tổng hợp', $classes, true);
    foreach ($classes as $class) {
        $addMatrix((string) $class['class_name'], [$class], false);
    }
    return $workbook;
}
