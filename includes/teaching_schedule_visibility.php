<?php
declare(strict_types=1);

/** Include personal makeups so a completed class remains visible through its last session. */
function teachingScheduleClassDatesJoinSql(): string
{
    return ' LEFT JOIN (
        SELECT teaching_class_id, MIN(teaching_date) AS first_date, MAX(teaching_date) AS last_date
        FROM teaching_schedule_slots
        GROUP BY teaching_class_id
    ) class_dates ON class_dates.teaching_class_id=tc.id ';
}

/** Week/month overlap, independent of when the viewer opens the calendar. */
function teachingScheduleClassVisibilitySql(string $rangeStart, string $rangeEnd, bool $showCompleted = false): array
{
    $start = "COALESCE(CASE
        WHEN class_dates.first_date IS NULL THEN tc.planned_start_date
        WHEN tc.planned_start_date IS NULL OR class_dates.first_date < tc.planned_start_date THEN class_dates.first_date
        ELSE tc.planned_start_date END, DATE(tc.created_at))";
    $sql = '(' . $start . ' <= ?)';
    $params = [$rangeEnd];
    if (!$showCompleted) {
        // Active classes can have more sessions added later. Completed classes end
        // on their last scheduled session; timestamps are only a fallback without slots.
        $sql .= " AND (tc.status='active' OR (tc.status='completed'
            AND COALESCE(class_dates.last_date, DATE(tc.updated_at), DATE(tc.created_at)) >= ?))";
        $params[] = $rangeStart;
    }
    return ['sql' => $sql, 'params' => $params];
}

function teachingScheduleExportWeek(string $date): array
{
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$day || $day->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Ngày xem lịch không hợp lệ.');
    }
    $start = $day->modify('-' . ((int) $day->format('N') - 1) . ' days');
    return [$start->format('Y-m-d'), $start->modify('+6 days')->format('Y-m-d')];
}
