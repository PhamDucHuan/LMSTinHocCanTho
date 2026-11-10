<?php
declare(strict_types=1);

require_once __DIR__ . '/simple_xlsx.php';

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
            WHERE 1=1";
    $params = [];
    if (($request['show_completed'] ?? '') !== '1') $sql .= " AND tc.status='active'";
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

function teachingProgressExportWorkbook(array $classes, array $students): SimpleXlsxWorkbook
{
    $workbook = new SimpleXlsxWorkbook();
    $headers = ['STT', 'Lớp', 'Giáo viên', 'Trạng thái lớp', 'Học viên', 'Tổng buổi', 'Đã học', 'Còn lại', 'Buổi vắng', 'Buổi bù đã học', 'Tiến độ', 'Trạng thái học tập'];
    $widths = [7, 28, 25, 20, 28, 12, 12, 12, 12, 16, 13, 24];
    $statusLabels = ['active' => 'Đang học', 'paused' => 'Tạm dừng', 'completed' => 'Đã kết thúc'];
    $exportedAt = date('d/m/Y H:i');
    $makeRows = static function (array $sheetClasses) use ($headers, $students, $statusLabels, $exportedAt): array {
        $studentCount = 0;
        foreach ($sheetClasses as $class) $studentCount += count($students[(int) $class['id']] ?? []);
        $rows = [
            ['height' => 30, 'cells' => [1 => ['value' => 'TIẾN ĐỘ HỌC TẬP HỌC VIÊN — LỊCH DẠY', 'style' => 1]]],
            ['cells' => [1 => ['value' => 'Xuất lúc: ' . $exportedAt . ' | Số lớp: ' . count($sheetClasses) . ' | Số học viên theo lớp: ' . $studentCount, 'style' => 2]]],
            ['height' => 32, 'cells' => [1 => ['value' => 'Tính toàn bộ lịch; mỗi ca là một buổi. Đã học gồm buổi bù cá nhân; buổi vắng không tính hoàn thành.', 'style' => 2]]],
            ['height' => 32, 'cells' => [1 => ['value' => 'Nếu chưa đặt tổng buổi, dùng số ca chung không vắng. Tiến độ tối đa 100%; lớp chưa có lịch để trống tiến độ.', 'style' => 2]]],
        ];
        $cells = [];
        foreach ($headers as $index => $header) $cells[$index + 1] = ['value' => $header, 'style' => 4];
        $rows[] = ['height' => 32, 'cells' => $cells];
        $ordinal = 0;
        foreach ($sheetClasses as $class) {
            $classStudents = $students[(int) $class['id']] ?? [];
            $total = (int) $class['total_sessions'] > 0 ? (int) $class['total_sessions'] : (int) $class['regular_total'];
            foreach ($classStudents ?: [null] as $student) {
                $completed = (int) ($student['completed'] ?? 0);
                $values = [++$ordinal, $class['class_name'], $class['teacher_name'] ?: 'Chưa phân công', $statusLabels[$class['status']] ?? $class['status'],
                    $student['student_name'] ?? 'Chưa có học viên', $total,
                    $student ? $completed : '', $student ? max(0, $total - $completed) : '',
                    $student ? (int) $student['absent'] : '', $student ? (int) $student['makeup_completed'] : '',
                    $student && $total > 0 ? round(min(100, $completed / $total * 100), 1) . '%' : '',
                    !$student ? '' : ($total <= 0 ? 'Chưa có lịch' : ($completed >= $total ? 'Hoàn thành' : ($completed > 0 ? 'Đang học' : 'Chưa học')))];
                $cells = [];
                foreach ($values as $index => $value) {
                    $cells[$index + 1] = ['value' => $value, 'style' => $index >= 5 ? 6 : 5];
                    if (is_int($value)) $cells[$index + 1]['type'] = 'number';
                }
                $rows[] = ['cells' => $cells];
            }
        }
        return $rows;
    };
    $merges = ['A1:L1', 'A2:L2', 'A3:L3', 'A4:L4'];
    $options = ['freeze_rows' => 5, 'freeze_columns' => 5];
    $workbook->addSheet('Tổng hợp', $makeRows($classes), $widths, $merges, $options);
    foreach ($classes as $class) {
        $workbook->addSheet((string) $class['class_name'], $makeRows([$class]), $widths, $merges, $options);
    }
    return $workbook;
}
