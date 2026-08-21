<?php
declare(strict_types=1);

require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';

global $pdo;
$viewerRole = (string) ($_SESSION['user_role'] ?? '');
if (!in_array($viewerRole, ['admin', 'administrative_staff'], true)) {
    header('Location: ../index.php');
    exit;
}

$selectedDate = (string) ($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) $selectedDate = date('Y-m-d');
try {
    $anchorDay = new DateTimeImmutable($selectedDate);
} catch (Throwable) {
    $anchorDay = new DateTimeImmutable('today');
}
$weekStart = $anchorDay->modify('-' . ((int) $anchorDay->format('N') - 1) . ' days');
$weekEnd = $weekStart->modify('+6 days');
$days = [];
for ($day = $weekStart; $day <= $weekEnd; $day = $day->modify('+1 day')) $days[] = $day;

$teachers = $pdo->query(
    "SELECT u.id, u.name, u.email, u.role,
            COUNT(DISTINCT tc.id) AS class_count,
            COUNT(DISTINCT CASE WHEN tc.status='active' THEN tc.id END) AS active_class_count
     FROM users u
     LEFT JOIN teaching_classes tc ON tc.teacher_id=u.id
     WHERE u.role IN ('teacher','administrative_staff','admin')
       AND u.is_approved=1 AND COALESCE(u.is_locked,0)=0
     GROUP BY u.id, u.name, u.email, u.role
     ORDER BY FIELD(u.role,'teacher','administrative_staff','admin'), u.name"
)->fetchAll(PDO::FETCH_ASSOC);

$teacherIds = array_map('intval', array_column($teachers, 'id'));
$teacherId = isset($_GET['teacher_id'])
    ? (int) $_GET['teacher_id']
    : (int) ($_SESSION['viewing_teacher_id'] ?? 0);
if (!in_array($teacherId, $teacherIds, true)) {
    $teacherId = $teacherIds[0] ?? 0;
}
if ($teacherId > 0) {
    $_SESSION['viewing_teacher_id'] = $teacherId;
} else {
    unset($_SESSION['viewing_teacher_id']);
}
$selectedTeacher = null;
foreach ($teachers as $teacher) {
    if ((int) $teacher['id'] === $teacherId) {
        $selectedTeacher = $teacher;
        break;
    }
}

$classes = [];
$slots = [];
$totalSessions = 0;
if ($teacherId > 0) {
    $classStmt = $pdo->prepare(
        "SELECT tc.id, tc.class_name, tc.notes, tc.status, tc.time_shift, c.title AS course_title,
                GROUP_CONCAT(tcs.student_name ORDER BY tcs.student_name SEPARATOR ', ') AS students,
                COUNT(DISTINCT tcs.id) AS student_count
         FROM teaching_classes tc
         LEFT JOIN courses c ON c.id=tc.course_id
         LEFT JOIN teaching_class_students tcs ON tcs.teaching_class_id=tc.id
         WHERE tc.status='active' AND (tc.teacher_id=? OR EXISTS (
             SELECT 1 FROM teaching_schedule_slots substitute_slot
             WHERE substitute_slot.teaching_class_id=tc.id AND substitute_slot.substitute_teacher_id=?
         ))
         GROUP BY tc.id, tc.class_name, tc.notes, tc.status, tc.time_shift, c.title, tc.sort_order
         ORDER BY FIELD(tc.time_shift, 'morning', 'afternoon', 'evening'), tc.sort_order, tc.id"
    );
    $classStmt->execute([$teacherId, $teacherId]);
    $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);

    $slotStmt = $pdo->prepare(
        'SELECT ts.id, ts.teaching_class_id, ts.teaching_date, ts.start_time, ts.end_time, ts.substitute_teacher_id, replacement.name AS substitute_teacher_name
         FROM teaching_schedule_slots ts
         JOIN teaching_classes tc ON tc.id=ts.teaching_class_id
         LEFT JOIN users replacement ON replacement.id=ts.substitute_teacher_id
         WHERE tc.status=\'active\' AND (tc.teacher_id=? OR ts.substitute_teacher_id=?) AND ts.teaching_date BETWEEN ? AND ?
         ORDER BY ts.start_time, ts.id'
    );
    $slotStmt->execute([$teacherId, $teacherId, $weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d')]);
    foreach ($slotStmt as $slot) {
        $slots[(int) $slot['teaching_class_id']][(string) $slot['teaching_date']][] = $slot;
        $totalSessions++;
    }
}

$previousWeek = $weekStart->modify('-7 days')->format('Y-m-d');
$nextWeek = $weekStart->modify('+7 days')->format('Y-m-d');
$todayDate = date('Y-m-d');
$today = date('Y-m-d');

$roleNames = ['teacher' => 'Giáo viên', 'administrative_staff' => 'Nhân viên hành chính', 'admin' => 'Admin'];
$page_title = $selectedTeacher ? 'Lịch dạy - ' . $selectedTeacher['name'] : 'Lịch của giáo viên';
require_once '../includes/header.php';
?>
<style>
.teacher-schedule-page>h1{margin-top:0}.schedule-note{color:var(--text-muted);font-size:13px;line-height:1.55;margin:0}.schedule-card{padding:24px;background:var(--glass-bg);border:1px solid var(--border-color);border-radius:18px}.schedule-card h2{margin:0 0 18px;font-size:22px}
.month-bar{display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap;margin-bottom:18px}.month-heading h2{margin:0}.month-heading .schedule-note{margin-top:6px}.schedule-tools{display:flex;align-items:flex-end;gap:9px;flex-wrap:wrap}.teacher-filter{display:flex;align-items:flex-end;gap:9px;flex-wrap:wrap}.teacher-filter label{display:grid;gap:6px;margin:0;color:var(--text-muted);font-size:12px;font-weight:700}.teacher-filter label:first-child{min-width:270px}.teacher-filter select{box-sizing:border-box;height:58px;min-height:58px;padding:11px 13px;border:1px solid var(--border-color)!important;border-radius:12px;background:var(--input-bg)!important;color:var(--text-main)!important;font-size:14px}.month-control{display:flex;align-items:center;gap:9px;box-sizing:border-box;height:58px;padding:4px 6px 4px 12px;border:1px solid var(--border-color);border-radius:12px;background:rgba(8,20,40,.55)}.month-control input[type="month"]{box-sizing:border-box;width:170px!important;height:48px;min-height:48px;padding:8px 10px;border:1px solid transparent!important;border-radius:8px;background:transparent!important;color:var(--text-main)!important;font:700 14px inherit;cursor:pointer}.month-control .btn{height:48px;min-height:48px}.month-control input[type="month"]:focus{outline:none;border-color:var(--primary)!important;background:rgba(255,255,255,.04)!important}.month-control input[type="month"]::-webkit-calendar-picker-indicator{filter:invert(1);opacity:.9;cursor:pointer}.month-nav{display:flex;align-items:stretch;gap:6px}.month-nav .btn{display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;height:58px;min-width:50px;padding-inline:12px;margin:0}
.month-control input[type="date"]{box-sizing:border-box;width:170px!important;height:48px;min-height:48px;padding:8px 10px;border:1px solid transparent!important;border-radius:8px;background:transparent!important;color:var(--text-main)!important;font:700 14px inherit;cursor:pointer}.month-control input[type="date"]:focus{outline:none;border-color:var(--primary)!important;background:rgba(255,255,255,.04)!important}.month-control input[type="date"]::-webkit-calendar-picker-indicator{filter:invert(1);opacity:.9;cursor:pointer}
.calendar-wrap{width:100%;height:auto;max-height:66vh;overflow-x:auto;overflow-y:auto;scrollbar-gutter:stable;overscroll-behavior:contain;border:1px solid var(--border-color);border-radius:16px;background:var(--glass-bg)}.calendar-wrap.table-responsive .schedule-table{margin-top:0}.schedule-table{border-collapse:separate;border-spacing:0;width:100%;min-width:0;table-layout:fixed;font-size:12px}.schedule-table th{position:sticky;top:0;z-index:4;min-width:0;padding:8px 6px;background:#1f517e;color:#fff;text-align:center;border-right:1px solid rgba(255,255,255,.18);border-bottom:1px solid rgba(255,255,255,.2)}.schedule-table th.weekend{background:#3d3d3d}.schedule-table th.info-head{left:0;z-index:7;width:240px;min-width:240px;box-shadow:5px 0 10px rgba(0,0,0,.16)}.schedule-table td{min-width:0;height:48px;padding:4px;border-right:1px solid var(--border-color);border-bottom:1px solid var(--border-color);vertical-align:top;background:rgba(255,255,255,.012)}.schedule-table td.weekend{background:rgba(0,0,0,.2)}.schedule-table th.today{box-shadow:inset 0 -3px 0 var(--primary)}.schedule-table td.today{background:rgba(var(--primary-rgb),.08)}.schedule-table td.class-info{position:sticky;left:0;z-index:3;width:240px;min-width:240px;max-width:240px;padding:7px 10px;background:var(--sidebar-bg);box-shadow:5px 0 10px rgba(0,0,0,.16)}.class-info strong{display:block;font-size:14px}.class-info small{display:block;margin-top:2px;color:var(--text-muted);font-size:12px;line-height:1.3}.class-note{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.schedule-slot{display:block;width:100%;box-sizing:border-box;padding:4px 6px;margin:1px 0;border-radius:7px;background:#b6e5d0;color:#12352a;font-weight:800;font-size:11px;text-align:center}.empty-view{padding:34px!important;text-align:center;color:var(--text-muted)}
.shift-label{position:sticky;top:52px;z-index:3;padding:8px 14px!important;height:auto!important;font-weight:800;font-size:13px;letter-spacing:.5px;background:var(--shift-color,#2563eb)!important;color:#fff!important;border-bottom:2px solid color-mix(in srgb,var(--shift-color) 80%,#000)!important;text-align:left!important}
.shift-zone.shift-morning td{background:rgba(37,99,235,.04)}.shift-zone.shift-morning td.class-info{background:color-mix(in srgb,var(--sidebar-bg) 96%,#2563eb)}
.shift-zone.shift-afternoon td{background:rgba(234,88,12,.04)}.shift-zone.shift-afternoon td.class-info{background:color-mix(in srgb,var(--sidebar-bg) 96%,#ea580c)}
.shift-zone.shift-evening td{background:rgba(124,58,237,.04)}.shift-zone.shift-evening td.class-info{background:color-mix(in srgb,var(--sidebar-bg) 96%,#7c3aed)}
.shift-empty{padding:18px 14px!important;text-align:center!important;color:var(--text-muted)!important;font-style:italic;height:auto!important;background:rgba(255,255,255,.02)!important}
@media(max-width:900px){.schedule-card{padding:17px}.schedule-tools,.teacher-filter{width:100%}.teacher-filter label:first-child{min-width:100%;width:100%}.teacher-filter select{width:100%}.calendar-wrap{max-height:62vh}.schedule-table{width:940px;min-width:940px}.schedule-table th.info-head,.schedule-table td.class-info{width:210px;min-width:210px;max-width:210px}}
</style>
<main class="teacher-schedule-page">
    <h1><i class='bx bx-calendar-check'></i> Lịch của giáo viên</h1>
    <p class="schedule-note" style="margin:-8px 0 22px">Chọn giáo viên để xem toàn bộ lớp và lịch dạy theo tháng.</p>

    <section class="schedule-card">
        <div class="month-bar">
            <div class="month-heading"><h2><i class='bx bx-table'></i> Lịch dạy tuần <?php echo $weekStart->format('d/m'); ?> – <?php echo $weekEnd->format('d/m/Y'); ?></h2><p class="schedule-note"><?php echo $selectedTeacher ? 'Giáo viên: ' . htmlspecialchars($selectedTeacher['name']) . ' · ' . count($classes) . ' lớp · ' . $totalSessions . ' buổi' : 'Chưa có giáo viên để xem lịch'; ?></p></div>
            <div class="schedule-tools">
                <form method="get" class="teacher-filter" id="teacher-schedule-filter">
                    <label>Giáo viên
                        <select name="teacher_id" aria-label="Chọn giáo viên" id="teacher-schedule-select">
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?php echo (int) $teacher['id']; ?>" <?php echo (int) $teacher['id'] === $teacherId ? 'selected' : ''; ?>><?php echo htmlspecialchars($teacher['name'] . ' — ' . ($roleNames[$teacher['role']] ?? $teacher['role']) . ' · ' . (int) $teacher['active_class_count'] . ' lớp'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="month-control"><input type="date" name="date" aria-label="Chọn ngày để xem tuần" value="<?php echo htmlspecialchars($anchorDay->format('Y-m-d')); ?>"><button class="btn btn-outline"><i class='bx bx-search'></i> Xem tuần</button></div>
                </form>
                <?php if ($teacherId > 0): ?>
                    <a class="btn btn-outline" style="height:58px;display:inline-flex;align-items:center" href="export_weekly_schedule.php?teacher_id=<?php echo $teacherId; ?>&amp;date=<?php echo htmlspecialchars($anchorDay->format('Y-m-d')); ?>"><i class='bx bx-spreadsheet'></i>&nbsp; Xuất Excel</a>
                <?php endif; ?>
                <div class="month-nav">
                    <a class="btn btn-outline" title="Tuần trước" href="?teacher_id=<?php echo $teacherId; ?>&date=<?php echo $previousWeek; ?>"><i class='bx bx-chevron-left'></i></a>
                    <a class="btn btn-outline" title="Tuần hiện tại" href="?teacher_id=<?php echo $teacherId; ?>&date=<?php echo $todayDate; ?>">Hôm nay</a>
                    <a class="btn btn-outline" title="Tuần sau" href="?teacher_id=<?php echo $teacherId; ?>&date=<?php echo $nextWeek; ?>"><i class='bx bx-chevron-right'></i></a>
                </div>
            </div>
        </div>
        <div class="calendar-wrap table-responsive"><table class="schedule-table">
            <thead><tr><th class="info-head">LỚP / HỌC VIÊN</th><?php foreach ($days as $day): $weekend=(int)$day->format('N')>=6; $isToday=$day->format('Y-m-d')===$today; ?><th class="<?php echo trim(($weekend ? 'weekend ' : '') . ($isToday ? 'today' : '')); ?>"><small><?php echo ['T2','T3','T4','T5','T6','T7','CN'][(int)$day->format('N')-1]; ?></small><br><?php echo $day->format('d'); ?></th><?php endforeach; ?></tr></thead>
<?php
$teacherShiftGroups = ['morning' => [], 'afternoon' => [], 'evening' => []];
foreach ($classes as $class) {
    $shift = $class['time_shift'] ?? 'morning';
    if (!isset($teacherShiftGroups[$shift])) $shift = 'morning';
    $teacherShiftGroups[$shift][] = $class;
}
$teacherShiftLabels = ['morning' => '🌅 BUỔI SÁNG', 'afternoon' => '☀️ BUỔI CHIỀU', 'evening' => '🌙 BUỔI TỐI'];
$teacherShiftColors = ['morning' => '#2563eb', 'afternoon' => '#ea580c', 'evening' => '#7c3aed'];
?>
            <tbody>
            <?php foreach ($teacherShiftGroups as $shiftKey => $shiftClasses): ?>
                <tr class="shift-header shift-<?php echo $shiftKey; ?>"><td colspan="<?php echo count($days)+1; ?>" class="shift-label" style="--shift-color:<?php echo $teacherShiftColors[$shiftKey]; ?>"><?php echo $teacherShiftLabels[$shiftKey]; ?></td></tr>
                <?php if (empty($shiftClasses)): ?><tr class="shift-zone shift-<?php echo $shiftKey; ?>"><td colspan="<?php echo count($days)+1; ?>" class="shift-empty">Chưa có lớp nào trong ca này</td></tr><?php endif; ?>
                <?php foreach ($shiftClasses as $class): $className=(string)($class['course_title'] ?: $class['class_name']); ?>
                <tr class="shift-zone shift-<?php echo $shiftKey; ?>"><td class="class-info"><strong><?php echo htmlspecialchars($className); ?></strong><small><?php echo (int)$class['student_count']; ?> học viên</small><small><?php echo htmlspecialchars($class['students'] ?: 'Chưa nhập học viên'); ?></small><?php if (!empty($class['notes'])): ?><small class="class-note" title="<?php echo htmlspecialchars($class['notes'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($class['notes']); ?></small><?php endif; ?></td>
                <?php foreach ($days as $day): $date=$day->format('Y-m-d'); $weekend=(int)$day->format('N')>=6; $isToday=$date===$today; ?><td class="<?php echo trim(($weekend ? 'weekend ' : '') . ($isToday ? 'today' : '')); ?>"><?php foreach ($slots[(int)$class['id']][$date] ?? [] as $slot): ?><span class="schedule-slot"><?php echo substr((string)$slot['start_time'],0,5); ?> – <?php echo substr((string)$slot['end_time'],0,5); ?></span><?php endforeach; ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
            <?php endforeach; ?>
            <?php if (!$classes): ?><tr><td class="empty-view" colspan="<?php echo count($days)+1; ?>">Giáo viên này chưa có lớp đang hoạt động.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>
</main>
<script>
document.getElementById('teacher-schedule-select')?.addEventListener('change', function () {
    document.getElementById('teacher-schedule-filter')?.submit();
});
</script>
<style>
/* Cố định thứ/ngày và thanh ca hiện tại. Thanh ca dùng sticky gốc của bảng nên tự nhường chỗ khi sang ca kế tiếp. */
.calendar-wrap{position:relative;--schedule-header-height:70px}
.calendar-wrap .schedule-table th,
.calendar-wrap .schedule-table th.info-head{position:static}
.calendar-wrap .schedule-table .shift-label{position:sticky;top:var(--schedule-header-height);z-index:12;box-shadow:0 2px 6px rgba(0,0,0,.24)}
.calendar-wrap .schedule-table th{height:var(--schedule-header-height);box-sizing:border-box}
.calendar-wrap .schedule-table td.class-info{z-index:4}
.schedule-sticky-head{position:sticky;top:0;left:0;z-index:20;height:0;overflow:visible;pointer-events:none}
.schedule-sticky-head__content{position:absolute;top:0;left:0;height:var(--schedule-header-height);overflow:hidden;background:#1f517e}
.schedule-sticky-head__content table{border-collapse:separate;border-spacing:0;table-layout:fixed}
.schedule-sticky-head__content th{height:var(--schedule-header-height)!important;box-sizing:border-box;background:#1f517e!important}
.schedule-sticky-head__content th.weekend{background:#3d3d3d!important}
.schedule-sticky-head__content th.today{box-shadow:inset 0 -3px 0 var(--primary)}
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
        };

        wrap.addEventListener('scroll', syncHeader, { passive: true });
        window.addEventListener('resize', syncHeader);
        syncHeader();
    });
})();
</script>
<?php require_once '../includes/footer.php'; ?>
