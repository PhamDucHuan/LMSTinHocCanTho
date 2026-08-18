<?php
require_once '../includes/security.php';
secureSessionStart();
requireRole(['student']);
require_once '../config/database.php';

if (!isset($pdo)) {
    die('Database connection failed');
}

$studentId = (int) $_SESSION['user_id'];

$submissionStmt = $pdo->prepare(
    "SELECT s.id, s.score, s.submitted_at, a.id assignment_id, a.title, a.type, a.module_settings,
            c.id course_id, COALESCE(c.title, 'Bài tập chung') course_title
     FROM submissions s
     JOIN assignments a ON a.id = s.assignment_id
     LEFT JOIN courses c ON c.id = a.course_id
     WHERE s.student_id = ?
     ORDER BY s.submitted_at DESC"
);
$submissionStmt->execute([$studentId]);
$submissions = $submissionStmt->fetchAll();

$assignmentScores = [];
foreach ($submissions as $submission) {
    $maximum = 0.0;
    $settings = json_decode((string) ($submission['module_settings'] ?? '[]'), true);
    if (is_array($settings)) {
        foreach ($settings as $module) $maximum += (float) ($module['max_score'] ?? 0);
    }
    if ($maximum <= 0) $maximum = 10;
    $normalized = $submission['score'] === null ? null : min(10, max(0, (float) $submission['score'] / $maximum * 10));
    $assignmentScores[(int) $submission['id']] = ['normalized' => $normalized, 'maximum' => $maximum];
}

$quizStmt = $pdo->prepare(
    "SELECT qa.id, qa.score, qa.correct_count, qa.total_questions, qa.submitted_at,
            q.id quiz_id, q.title, c.id course_id, c.title course_title
     FROM quiz_attempts qa
     JOIN quizzes q ON q.id = qa.quiz_id
     JOIN courses c ON c.id = q.course_id
     WHERE qa.student_id = ? AND qa.submitted_at IS NOT NULL
     ORDER BY qa.submitted_at DESC"
);
$quizStmt->execute([$studentId]);
$quizAttempts = $quizStmt->fetchAll();

$bestQuizById = [];
foreach ($quizAttempts as $attempt) {
    $quizId = (int) $attempt['quiz_id'];
    if (!isset($bestQuizById[$quizId]) || (float) $attempt['score'] > (float) $bestQuizById[$quizId]['score']) {
        $bestQuizById[$quizId] = $attempt;
    }
}

$courseStmt = $pdo->prepare(
    "SELECT c.id, c.title,
            (SELECT COUNT(*) FROM assignments a WHERE a.course_id = c.id) assignment_total,
            (SELECT COUNT(DISTINCT s.assignment_id) FROM submissions s JOIN assignments a2 ON a2.id=s.assignment_id WHERE s.student_id=? AND a2.course_id=c.id) assignment_done,
            (SELECT COUNT(*) FROM quizzes q WHERE q.course_id=c.id AND q.is_published=1) quiz_total,
            (SELECT COUNT(DISTINCT qa.quiz_id) FROM quiz_attempts qa JOIN quizzes q2 ON q2.id=qa.quiz_id WHERE qa.student_id=? AND qa.submitted_at IS NOT NULL AND q2.course_id=c.id) quiz_done
     FROM course_enrollments ce
     JOIN courses c ON c.id=ce.course_id
     WHERE ce.student_id=?
     ORDER BY ce.enrolled_at DESC"
);
$courseStmt->execute([$studentId, $studentId, $studentId]);
$courses = $courseStmt->fetchAll();

$gradedAssignments = array_values(array_filter($assignmentScores, static fn(array $score): bool => $score['normalized'] !== null));
$assignmentAverage = $gradedAssignments
    ? array_sum(array_column($gradedAssignments, 'normalized')) / count($gradedAssignments)
    : null;
$quizAverage = $bestQuizById
    ? array_sum(array_map(static fn(array $attempt): float => (float) $attempt['score'], $bestQuizById)) / count($bestQuizById)
    : null;
$allScores = array_merge(
    array_map(static fn(array $score): float => (float) $score['normalized'], $gradedAssignments),
    array_map(static fn(array $attempt): float => (float) $attempt['score'], $bestQuizById)
);
$overallAverage = $allScores ? array_sum($allScores) / count($allScores) : null;
$totalRequired = array_sum(array_map(static fn(array $course): int => (int) $course['assignment_total'] + (int) $course['quiz_total'], $courses));
$totalDone = array_sum(array_map(static fn(array $course): int => (int) $course['assignment_done'] + (int) $course['quiz_done'], $courses));
$completionRate = $totalRequired > 0 ? min(100, round($totalDone / $totalRequired * 100)) : 0;
$excellentCount = count(array_filter($allScores, static fn(float $score): bool => $score >= 9));
$perfectCount = count(array_filter($allScores, static fn(float $score): bool => $score >= 9.99));
$assignmentDone = count($submissions);
$quizDone = count($bestQuizById);
$bestAssignmentScore = $gradedAssignments ? max(array_column($gradedAssignments, 'normalized')) : null;
$bestQuizScore = $bestQuizById ? max(array_map(static fn(array $attempt): float => (float) $attempt['score'], $bestQuizById)) : null;
$activityDays = [];
foreach (array_merge(array_column($submissions, 'submitted_at'), array_column($quizAttempts, 'submitted_at')) as $activityDate) {
    if ($activityDate) $activityDays[date('Y-m-d', strtotime((string) $activityDate))] = true;
}
$activeDayCount = count($activityDays);
$completedCourseCount = count(array_filter($courses, static function (array $course): bool {
    $required = (int) $course['assignment_total'] + (int) $course['quiz_total'];
    $done = (int) $course['assignment_done'] + (int) $course['quiz_done'];
    return $required > 0 && $done >= $required;
}));

$badges = [
    ['icon' => 'bx-rocket', 'name' => 'Khởi đầu tốt', 'description' => 'Hoàn thành bài đầu tiên', 'earned' => $totalDone >= 1, 'progress' => min(100, $totalDone * 100)],
    ['icon' => 'bx-task', 'name' => 'Chăm chỉ', 'description' => 'Hoàn thành 5 bài', 'earned' => $totalDone >= 5, 'progress' => min(100, $totalDone / 5 * 100)],
    ['icon' => 'bx-star', 'name' => 'Điểm số xuất sắc', 'description' => 'Đạt ít nhất một điểm từ 9', 'earned' => $excellentCount >= 1, 'progress' => min(100, $excellentCount * 100)],
    ['icon' => 'bx-crown', 'name' => 'Bậc thầy kiến thức', 'description' => 'Đạt điểm trung bình từ 9', 'earned' => $overallAverage !== null && $overallAverage >= 9, 'progress' => min(100, ($overallAverage ?? 0) / 9 * 100)],
    ['icon' => 'bx-check-shield', 'name' => 'Chinh phục khóa học', 'description' => 'Hoàn thành 100% nội dung', 'earned' => $totalRequired > 0 && $completionRate === 100, 'progress' => $completionRate],
    ['icon' => 'bx-edit-alt', 'name' => 'Giỏi thực hành', 'description' => 'Hoàn thành 5 bài tập', 'earned' => $assignmentDone >= 5, 'progress' => min(100, $assignmentDone / 5 * 100)],
    ['icon' => 'bx-brain', 'name' => 'Chuyên gia trắc nghiệm', 'description' => 'Hoàn thành 5 đề trắc nghiệm', 'earned' => $quizDone >= 5, 'progress' => min(100, $quizDone / 5 * 100)],
    ['icon' => 'bx-bullseye', 'name' => 'Điểm tuyệt đối', 'description' => 'Đạt ít nhất một điểm 10', 'earned' => $perfectCount >= 1, 'progress' => min(100, $perfectCount * 100)],
    ['icon' => 'bx-calendar-check', 'name' => 'Học tập đều đặn', 'description' => 'Có hoạt động trong 5 ngày khác nhau', 'earned' => $activeDayCount >= 5, 'progress' => min(100, $activeDayCount / 5 * 100)],
    ['icon' => 'bx-map-alt', 'name' => 'Nhà khám phá', 'description' => 'Tham gia ít nhất 3 khóa học', 'earned' => count($courses) >= 3, 'progress' => min(100, count($courses) / 3 * 100)],
    ['icon' => 'bx-trophy', 'name' => 'Về đích', 'description' => 'Hoàn thành trọn vẹn một khóa học', 'earned' => $completedCourseCount >= 1, 'progress' => min(100, $completedCourseCount * 100)],
];
$earnedBadgeCount = count(array_filter($badges, static fn(array $badge): bool => $badge['earned']));

$activities = [];
foreach (array_slice($submissions, 0, 12) as $submission) {
    $score = $assignmentScores[(int) $submission['id']]['normalized'];
    $activities[] = ['kind' => 'Bài tập', 'icon' => 'bx-edit', 'title' => $submission['title'], 'course' => $submission['course_title'], 'score' => $score, 'date' => $submission['submitted_at']];
}
foreach (array_slice($quizAttempts, 0, 12) as $attempt) {
    $activities[] = ['kind' => 'Trắc nghiệm', 'icon' => 'bx-list-check', 'title' => $attempt['title'], 'course' => $attempt['course_title'], 'score' => (float) $attempt['score'], 'date' => $attempt['submitted_at']];
}
usort($activities, static fn(array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']));
$activities = array_slice($activities, 0, 10);

$page_title = 'Thành tích của tôi';
require_once '../includes/header.php';
?>
<style>
.achievement-hero{display:flex;justify-content:space-between;gap:24px;align-items:center;padding:28px;background:linear-gradient(135deg,rgba(var(--primary-rgb),.22),var(--glass-bg));border:1px solid rgba(var(--primary-rgb),.35);border-radius:20px;margin-bottom:24px}.achievement-hero h1{margin:0 0 8px;font-size:32px}.achievement-hero p{margin:0;color:var(--text-muted)}.hero-medal{width:88px;height:88px;flex:0 0 88px;border-radius:50%;display:grid;place-items:center;background:rgba(var(--primary-rgb),.18);color:var(--primary);font-size:48px;border:1px solid rgba(var(--primary-rgb),.35)}
.achievement-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:26px}.achievement-stat,.achievement-panel{background:var(--glass-bg);border:1px solid var(--border-color);border-radius:16px}.achievement-stat{padding:20px}.achievement-stat i{font-size:27px;color:var(--primary)}.achievement-stat strong{display:block;font-size:29px;margin:8px 0 2px}.achievement-stat span{color:var(--text-muted);font-size:13px}
.achievement-layout{display:grid;grid-template-columns:1.15fr .85fr;gap:22px}.achievement-panel{padding:22px;margin-bottom:22px}.achievement-panel h2{margin:0 0 18px;display:flex;align-items:center;gap:9px}.badge-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.achievement-badge{padding:17px;border-radius:14px;background:rgba(15,23,42,.28);border:1px solid var(--border-color);opacity:.58}.achievement-badge.earned{opacity:1;border-color:rgba(245,158,11,.4);background:rgba(245,158,11,.08)}.badge-title{display:flex;align-items:center;gap:11px}.badge-title i{font-size:30px;color:#f59e0b}.badge-title strong{display:block}.badge-title small{color:var(--text-muted)}.progress-track{height:6px;margin-top:13px;border-radius:999px;background:rgba(148,163,184,.16);overflow:hidden}.progress-track span{display:block;height:100%;background:var(--primary);border-radius:inherit}
.course-progress{padding:15px 0;border-bottom:1px solid var(--border-color)}.course-progress:last-child{border-bottom:0}.course-head{display:flex;justify-content:space-between;gap:12px;margin-bottom:9px}.course-head small{color:var(--text-muted)}.activity-row{display:grid;grid-template-columns:40px 1fr auto;gap:12px;align-items:center;padding:13px 0;border-bottom:1px solid var(--border-color)}.activity-row:last-child{border-bottom:0}.activity-icon{width:40px;height:40px;border-radius:11px;display:grid;place-items:center;background:rgba(var(--primary-rgb),.13);color:var(--primary);font-size:20px}.activity-copy strong,.activity-copy small{display:block}.activity-copy small{color:var(--text-muted);margin-top:3px}.activity-score{font-size:18px;font-weight:700;color:var(--success);white-space:nowrap}.empty-achievement{text-align:center;color:var(--text-muted);padding:25px 10px}
@media(max-width:1000px){.achievement-stats{grid-template-columns:repeat(2,1fr)}.achievement-layout{grid-template-columns:1fr}}@media(max-width:620px){.achievement-hero{align-items:flex-start}.hero-medal{width:64px;height:64px;flex-basis:64px;font-size:34px}.achievement-stats,.badge-grid{grid-template-columns:1fr}.achievement-hero h1{font-size:25px}}
</style>

<section class="achievement-hero">
    <div><h1>Thành tích của <?php echo htmlspecialchars((string) $_SESSION['user_name']); ?></h1><p>Theo dõi tiến bộ, điểm số và những cột mốc bạn đã chinh phục.</p></div>
    <div class="hero-medal"><i class='bx bx-medal'></i></div>
</section>

<div class="achievement-stats">
    <article class="achievement-stat"><i class='bx bx-check-circle'></i><strong><?php echo $totalDone; ?></strong><span>Nội dung đã hoàn thành</span></article>
    <article class="achievement-stat"><i class='bx bx-trending-up'></i><strong><?php echo $overallAverage === null ? '—' : number_format($overallAverage, 1); ?></strong><span>Điểm trung bình / 10</span></article>
    <article class="achievement-stat"><i class='bx bx-award'></i><strong><?php echo $earnedBadgeCount; ?>/<?php echo count($badges); ?></strong><span>Huy hiệu đã đạt</span></article>
    <article class="achievement-stat"><i class='bx bx-bar-chart-alt-2'></i><strong><?php echo $completionRate; ?>%</strong><span>Tiến độ tổng thể</span></article>
</div>

<div class="achievement-layout">
    <div>
        <section class="achievement-panel"><h2><i class='bx bx-award'></i> Huy hiệu</h2><div class="badge-grid">
            <?php foreach ($badges as $badge): ?>
                <article class="achievement-badge <?php echo $badge['earned'] ? 'earned' : ''; ?>">
                    <div class="badge-title"><i class='bx <?php echo $badge['icon']; ?>'></i><div><strong><?php echo htmlspecialchars($badge['name']); ?></strong><small><?php echo htmlspecialchars($badge['description']); ?></small></div></div>
                    <div class="progress-track"><span style="width:<?php echo round((float) $badge['progress']); ?>%"></span></div>
                </article>
            <?php endforeach; ?>
        </div></section>
        <section class="achievement-panel"><h2><i class='bx bx-history'></i> Hoạt động gần đây</h2>
            <?php if (!$activities): ?><div class="empty-achievement">Bạn chưa có hoạt động học tập nào.</div><?php endif; ?>
            <?php foreach ($activities as $activity): ?><div class="activity-row"><div class="activity-icon"><i class='bx <?php echo $activity['icon']; ?>'></i></div><div class="activity-copy"><strong><?php echo htmlspecialchars($activity['title']); ?></strong><small><?php echo htmlspecialchars($activity['kind'] . ' · ' . $activity['course']); ?> · <?php echo date('d/m/Y H:i', strtotime($activity['date'])); ?></small></div><div class="activity-score"><?php echo $activity['score'] === null ? 'Đang chấm' : number_format((float) $activity['score'], 1) . '/10'; ?></div></div><?php endforeach; ?>
        </section>
    </div>
    <div>
        <section class="achievement-panel"><h2><i class='bx bx-book-open'></i> Tiến độ khóa học</h2>
            <?php if (!$courses): ?><div class="empty-achievement">Bạn chưa tham gia khóa học nào.</div><?php endif; ?>
            <?php foreach ($courses as $course): $required=(int)$course['assignment_total']+(int)$course['quiz_total'];$done=(int)$course['assignment_done']+(int)$course['quiz_done'];$percent=$required>0?min(100,round($done/$required*100)):0; ?>
                <div class="course-progress"><div class="course-head"><div><strong><?php echo htmlspecialchars($course['title']); ?></strong><br><small><?php echo $done; ?>/<?php echo $required; ?> nội dung</small></div><strong><?php echo $percent; ?>%</strong></div><div class="progress-track"><span style="width:<?php echo $percent; ?>%"></span></div></div>
            <?php endforeach; ?>
        </section>
        <section class="achievement-panel"><h2><i class='bx bx-stats'></i> Tổng hợp điểm</h2>
            <div class="course-progress"><div class="course-head"><span>Bài tập</span><strong><?php echo $assignmentAverage === null ? '—' : number_format($assignmentAverage,1).'/10'; ?></strong></div></div>
            <div class="course-progress"><div class="course-head"><span>Trắc nghiệm</span><strong><?php echo $quizAverage === null ? '—' : number_format($quizAverage,1).'/10'; ?></strong></div></div>
            <div class="course-progress"><div class="course-head"><span>Số điểm xuất sắc</span><strong><?php echo $excellentCount; ?></strong></div></div>
        </section>
        <section class="achievement-panel"><h2><i class='bx bx-trophy'></i> Kỷ lục cá nhân</h2>
            <div class="course-progress"><div class="course-head"><span>Điểm bài tập cao nhất</span><strong><?php echo $bestAssignmentScore === null ? '—' : number_format($bestAssignmentScore, 1).'/10'; ?></strong></div></div>
            <div class="course-progress"><div class="course-head"><span>Điểm trắc nghiệm cao nhất</span><strong><?php echo $bestQuizScore === null ? '—' : number_format($bestQuizScore, 1).'/10'; ?></strong></div></div>
            <div class="course-progress"><div class="course-head"><span>Tổng lượt làm trắc nghiệm</span><strong><?php echo count($quizAttempts); ?></strong></div></div>
            <div class="course-progress"><div class="course-head"><span>Số ngày có hoạt động</span><strong><?php echo $activeDayCount; ?> ngày</strong></div></div>
            <div class="course-progress"><div class="course-head"><span>Khóa học đã hoàn thành</span><strong><?php echo $completedCourseCount; ?></strong></div></div>
        </section>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
