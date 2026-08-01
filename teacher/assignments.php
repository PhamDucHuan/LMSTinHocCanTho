<?php
require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['teacher', 'admin'])) {
    header('Location: ../index.php');
    exit;
}

// Fetch assignments
if ($_SESSION['user_role'] === 'admin') {
    $stmt = $pdo->prepare("SELECT a.*, c.title as course_title FROM assignments a LEFT JOIN courses c ON a.course_id = c.id ORDER BY a.priority_order, a.created_at, a.id");
    $stmt->execute();
} else {
    $stmt = $pdo->prepare("SELECT a.*, c.title as course_title FROM assignments a LEFT JOIN courses c ON a.course_id = c.id WHERE a.teacher_id = ? ORDER BY a.priority_order, a.created_at, a.id");
    $stmt->execute([$_SESSION['user_id']]);
}
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

$page_title = "Danh sách Bài tập";
require_once '../includes/header.php';
?>

        <div class="header-action" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="margin: 0;">Quản lý Bài tập</h2>
            <a href="create_assignment.php" class="btn btn-primary"><i class='bx bx-plus'></i> Giao bài tập mới</a>
        </div>

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
                <div class="box" style="position: relative;">
                    <form method="POST" action="delete_assignment.php" style="position: absolute; top: 15px; right: 15px; margin: 0; z-index: 10;" onsubmit="return confirm('Bạn có chắc chắn muốn xóa bài tập này? Toàn bộ bài nộp của học viên sẽ bị xóa!');">
                        <input type="hidden" name="id" value="<?php echo $assignment['id']; ?>">
                        <button type="submit" style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); color: var(--danger); width: 32px; height: 32px; border-radius: 6px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='var(--danger)'; this.style.color='#fff';" onmouseout="this.style.background='rgba(239, 68, 68, 0.1)'; this.style.color='var(--danger)';"><i class='bx bx-trash'></i></button>
                    </form>
                    <div class="priority-controls" aria-label="Sắp xếp ưu tiên">
                        <span>#<?php echo $assignmentIndex + 1; ?></span>
                        <form method="POST" action="reorder_assignment.php"><input type="hidden" name="id" value="<?php echo $assignment['id']; ?>"><input type="hidden" name="direction" value="up"><button type="submit" title="Đưa lên ưu tiên cao hơn" <?php echo $assignmentIndex === 0 ? 'disabled' : ''; ?>><i class='bx bx-up-arrow-alt'></i></button></form>
                        <form method="POST" action="reorder_assignment.php"><input type="hidden" name="id" value="<?php echo $assignment['id']; ?>"><input type="hidden" name="direction" value="down"><button type="submit" title="Đưa xuống ưu tiên thấp hơn" <?php echo $assignmentIndex === count($assigns) - 1 ? 'disabled' : ''; ?>><i class='bx bx-down-arrow-alt'></i></button></form>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; padding-right: 40px;">
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <?php if ($assignment['category']): ?>
                                <span class="badge" style="background: rgba(14, 165, 233, 0.2); color: #38bdf8; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600;"><i class='bx bx-folder'></i> <?php echo htmlspecialchars($assignment['category']); ?></span>
                            <?php endif; ?>
                            
                            <?php if ($assignment['type'] === 'mock_exam'): ?>
                                <span class="badge" style="background: rgba(239, 68, 68, 0.2); color: #f87171; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600;"><i class='bx bx-timer'></i> Thi thử</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <h3><?php echo htmlspecialchars($assignment['title']); ?></h3>
                    <p><?php echo htmlspecialchars(substr($assignment['description'], 0, 100)) . '...'; ?></p>
                    <div class="meta" style="margin-bottom: 15px; font-size: 14px; color: var(--text-muted);">
                        <i class='bx bx-calendar'></i> Hạn nộp: <?php echo $assignment['due_date'] ? date('d/m/Y H:i', strtotime($assignment['due_date'])) : 'Không thời hạn'; ?>
                    </div>
                    <a href="submissions.php?id=<?php echo $assignment['id']; ?>" class="btn btn-primary" style="width: 100%; justify-content: center; margin-bottom: 10px; box-sizing: border-box;">
                        Xem bài nộp
                    </a>
                    <a href="edit_assignment.php?id=<?php echo $assignment['id']; ?>" class="btn btn-outline" style="width: 100%; justify-content: center; box-sizing: border-box;">
                        Chỉnh sửa
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
    
    <?php if (empty($assignments)): ?>
        <div class="box" style="text-align: center; padding: 40px;">
            <i class='bx bx-folder-open' style="font-size: 48px; color: var(--text-muted); margin-bottom: 10px;"></i>
            <p>Bạn chưa giao bài tập nào.</p>
        </div>
    <?php endif; ?>

    <style>
        .assignment-type-section{margin-bottom:34px;padding:22px;border:1px solid var(--border-color);border-radius:16px;background:rgba(var(--primary-rgb),.025)}
        .assignment-type-title{display:flex;align-items:center;gap:10px;margin:0 0 22px;color:var(--section-color);font-size:27px}
        .assignment-type-title span{display:inline-grid;place-items:center;min-width:28px;height:28px;padding:0 7px;border-radius:999px;background:color-mix(in srgb,var(--section-color) 16%,transparent);font-size:13px}
        .assignment-type-section .card-grid{align-items:stretch}
        .assignment-type-section .card-grid>.box{display:flex;flex-direction:column;height:100%;box-sizing:border-box}
        .assignment-type-section .card-grid>.box>a:first-of-type{margin-top:auto}
        .priority-controls{display:flex;align-items:center;gap:5px;margin:0 42px 12px 0;color:var(--text-muted);font-size:12px}.priority-controls form{margin:0}.priority-controls button{display:grid;place-items:center;width:28px;height:28px;padding:0;border:1px solid var(--border-color);border-radius:6px;background:rgba(255,255,255,.04);color:var(--text-color);cursor:pointer}.priority-controls button:hover:not(:disabled){border-color:var(--primary);color:var(--primary)}.priority-controls button:disabled{opacity:.3;cursor:not-allowed}
        @media(max-width:650px){.assignment-type-section{padding:14px}.assignment-type-title{font-size:23px}}
    </style>

<?php require_once '../includes/footer.php'; ?>
