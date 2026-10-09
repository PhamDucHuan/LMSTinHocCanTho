<?php
declare(strict_types=1);

/** Return a database/browser time value as a stable 24-hour HH:mm string. */
function scheduleTime24(mixed $value): string
{
    $time = trim((string) $value);
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $matches)) {
        return '';
    }

    $hour = (int) $matches[1];
    $minute = (int) $matches[2];
    if ($hour > 23 || $minute > 59) {
        return '';
    }

    return sprintf('%02d:%02d', $hour, $minute);
}

function scheduleTimeRange24(mixed $start, mixed $end, string $separator = ' – '): string
{
    $startTime = scheduleTime24($start);
    $endTime = scheduleTime24($end);
    if ($startTime === '' || $endTime === '') {
        return '';
    }

    return $startTime . $separator . $endTime;
}

/** Classify a clock time using the same boundaries throughout every schedule view. */
function scheduleMajorShift(mixed $time, string $fallback = 'morning'): string
{
    $time24 = scheduleTime24($time);
    if ($time24 === '') {
        return in_array($fallback, ['morning', 'afternoon', 'evening'], true) ? $fallback : 'morning';
    }
    if ($time24 >= '17:30') {
        return 'evening';
    }
    if ($time24 >= '12:00') {
        return 'afternoon';
    }
    return 'morning';
}
