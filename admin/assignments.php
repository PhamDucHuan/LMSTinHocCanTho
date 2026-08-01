<?php
require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

// Fetch all assignments with course info
$stmt = $pdo->prepare("SELECT a.*, c.title as course_title, u.name as teacher_name FROM assignments a LEFT JOIN courses c ON a.course_id = c.id JOIN users u ON a.teacher_id = u.id ORDER BY a.priority_order, a.created_at, a.id");
$stmt->execute();
$assignments = $stmt->fetchAll();

$grouped_assignments = ['assignment' => [], 'exam' => []];
foreach ($assignments as $a) {
    $c_title = $a['course_title'] ? $a['course_title'] : 'Chưa phân loại khóa học';
    $typeKey = in_array((string) ($a['type'] ?? ''), ['exam', 'mock_exam'], true) ? 'exam' : 'assignment';
    $grouped_assignments[$typeKey][$c_title][] = $a;
}
$typeSections = [
    'assignment' => ['title' => 'Bài tập', 'icon' => 'bx-edit', 'color' => '#38bdf8'],
    'exam' => ['title' => 'Bài thi', 'icon' => 'bx-timer', 'color' => '#fb7185'],
];

$page_title = "Quản lý Bài tập";
require_once '../includes/header.php';
?>

        <?php if(isset($_SESSION['success'])): ?>
            <div style="background: rgba(16, 185, 129, 0.2); color: #6ee7b7; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>
        <?php if(isset($_SESSION['error'])): ?>
            <div style="background:rgba(239,68,68,.16);color:#fca5a5;padding:15px;border-radius:8px;margin-bottom:20px;"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

    <?php foreach ($typeSections as $typeKey => $typeSection): ?>
        <?php if (empty($grouped_assignments[$typeKey])) continue; ?>
        <section class="assignment-type-section">
            <h2 class="assignment-type-title" style="--section-color:<?php echo $typeSection['color']; ?>;">
                <i class='bx <?php echo $typeSection['icon']; ?>'></i> <?php echo $typeSection['title']; ?>
                <span><?php echo array_sum(array_map('count', $grouped_assignments[$typeKey])); ?></span>
            </h2>
    <?php foreach ($grouped_assignments[$typeKey] as $course_title => $assigns): ?>
        <h3 style="margin-top: 30px; margin-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 10px;">
            <i class='bx bx-book-bookmark' style="color: var(--primary);"></i> <?php echo htmlspecialchars($course_title); ?>
        </h3>
        <div class="card-grid">
            <?php foreach ($assigns as $assignmentIndex => $assignment): ?>
                <div class="card" style="position: relative;">
                    <form method="POST" action="../teacher/delete_assignment.php" style="position: absolute; top: 15px; right: 15px; margin: 0; z-index: 10;" onsubmit="return confirm('Bạn có chắc chắn muốn xóa bài tập này? Toàn bộ bài nộp của học viên sẽ bị xóa!');">
                        <input type="hidden" name="id" value="<?php echo $assignment['id']; ?>">
                        <button type="submit" style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); color: var(--danger); width: 32px; height: 32px; border-radius: 6px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='var(--danger)'; this.style.color='#fff';" onmouseout="this.style.background='rgba(239, 68, 68, 0.1)'; this.style.color='var(--danger)';"><i class='bx bx-trash'></i></button>
                    </form>
                    <div class="priority-controls" aria-label="Sắp xếp ưu tiên">
                        <span>#<?php echo $assignmentIndex + 1; ?></span>
                        <form method="POST" action="../teacher/reorder_assignment.php"><input type="hidden" name="id" value="<?php echo $assignment['id']; ?>"><input type="hidden" name="direction" value="up"><input type="hidden" name="return_to" value="admin"><button type="submit" title="Đưa lên ưu tiên cao hơn" <?php echo $assignmentIndex === 0 ? 'disabled' : ''; ?>><i class='bx bx-up-arrow-alt'></i></button></form>
                        <form method="POST" action="../teacher/reorder_assignment.php"><input type="hidden" name="id" value="<?php echo $assignment['id']; ?>"><input type="hidden" name="direction" value="down"><input type="hidden" name="return_to" value="admin"><button type="submit" title="Đưa xuống ưu tiên thấp hơn" <?php echo $assignmentIndex === count($assigns) - 1 ? 'disabled' : ''; ?>><i class='bx bx-down-arrow-alt'></i></button></form>
                    </div>
                    <div class="teacher-badge" style="margin-right: 40px; display: inline-block; padding: 4px 8px; border-radius: 4px; background: rgba(255,255,255,0.1); font-size: 12px; margin-bottom: 10px;"><i class='bx bxs-user-badge'></i> GV: <?php echo htmlspecialchars($assignment['teacher_name']); ?></div>
                    <h3><?php echo htmlspecialchars($assignment['title']); ?></h3>
                    <p><?php echo htmlspecialchars(substr($assignment['description'], 0, 100)) . '...'; ?></p>
                    <div class="meta" style="margin-bottom: 15px; font-size: 14px; color: var(--text-muted);">
                        <i class='bx bx-calendar'></i> Hạn nộp: <?php echo $assignment['due_date'] ? date('d/m/Y H:i', strtotime($assignment['due_date'])) : 'Không thời hạn'; ?>
                    </div>
                    <a href="edit_assignment.php?id=<?php echo $assignment['id']; ?>" class="btn btn-primary" style="width: 100%; justify-content: center; box-sizing: border-box;">
                        <i class='bx bx-edit'></i> Chỉnh sửa
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
    
    <?php if (empty($assignments)): ?>
        <div class="empty-state">
            <i class='bx bx-folder-open' style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
            <p>Hệ thống chưa có bài tập nào.</p>
        </div>
    <?php endif; ?>

    <style>
        .assignment-type-section{margin-bottom:34px;padding:22px;border:1px solid var(--border-color);border-radius:16px;background:rgba(var(--primary-rgb),.025)}
        .assignment-type-title{display:flex;align-items:center;gap:10px;margin:0 0 22px;color:var(--section-color);font-size:27px}
        .assignment-type-title span{display:inline-grid;place-items:center;min-width:28px;height:28px;padding:0 7px;border-radius:999px;background:color-mix(in srgb,var(--section-color) 16%,transparent);font-size:13px}
        .assignment-type-section .card-grid{align-items:stretch}
        .assignment-type-section .card-grid>.card{display:flex;flex-direction:column;height:100%;box-sizing:border-box}
        .assignment-type-section .card-grid>.card>.btn{margin-top:auto}
        .priority-controls{display:flex;align-items:center;gap:5px;margin:0 42px 12px 0;color:var(--text-muted);font-size:12px}.priority-controls form{margin:0}.priority-controls button{display:grid;place-items:center;width:28px;height:28px;padding:0;border:1px solid var(--border-color);border-radius:6px;background:rgba(255,255,255,.04);color:var(--text-color);cursor:pointer}.priority-controls button:hover:not(:disabled){border-color:var(--primary);color:var(--primary)}.priority-controls button:disabled{opacity:.3;cursor:not-allowed}
        @media(max-width:650px){.assignment-type-section{padding:14px}.assignment-type-title{font-size:23px}}
    </style>

<?php require_once '../includes/footer.php'; ?>
