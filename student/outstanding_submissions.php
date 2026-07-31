<?php
require_once '../includes/security.php';
secureSessionStart();
require_once '../config/database.php';

// Ensure we have a PDO instance. Some projects may name the connection $db or $conn.
if (!isset($pdo)) {
    if (isset($db) && $db instanceof PDO) {
        $pdo = $db;
    } elseif (isset($conn) && $conn instanceof PDO) {
        $pdo = $conn;
    } else {
        die('Không thể kết nối đến cơ sở dữ liệu. Vui lòng kiểm tra cấu hình.');
    }
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['student', 'admin', 'teacher'])) {
    header('Location: ../index.php');
    exit;
}

$assignment_id = $_GET['id'] ?? null;
if (!$assignment_id) {
    header('Location: dashboard.php');
    exit;
}

// Fetch assignment info
$stmt = $pdo->prepare("SELECT * FROM assignments WHERE id = ?");
$stmt->execute([$assignment_id]);
$assignment = $stmt->fetch();

if (!$assignment) {
    die("Bài tập không tồn tại.");
}

// Lấy danh sách bài tiêu biểu
$stmt = $pdo->prepare("
    SELECT s.*, u.name 
    FROM submissions s
    JOIN users u ON s.student_id = u.id
    WHERE s.assignment_id = ? AND s.is_outstanding = 1
    ORDER BY s.score DESC, s.submitted_at DESC
");
$stmt->execute([$assignment_id]);
$outstanding_submissions = $stmt->fetchAll();

$page_title = "Bài làm tiêu biểu";
require_once '../includes/header.php';
?>
    <style>
        .header-title { display: flex; align-items: center; gap: 10px; color: var(--warning); margin-bottom: 30px; }
        .score-badge { position: absolute; top: -15px; right: 20px; background: var(--success); color: #fff; font-size: 24px; font-weight: bold; width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; border-radius: 50%; border: 4px solid var(--bg-dark); }
        .student-name { font-size: 20px; font-weight: 600; color: #fff; margin-bottom: 5px; display: flex; align-items: center; gap: 8px; }
        .feedback { background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; line-height: 1.5; color: rgba(255,255,255,0.9); border-left: 4px solid var(--success); }
        .btn-download { display: inline-flex; align-items: center; gap: 8px; background: transparent; border: 1px solid var(--primary); color: var(--primary); padding: 8px 15px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 500; transition: 0.3s; }
        .btn-download:hover { background: rgba(99, 102, 241, 0.1); }
    </style>

        <div class="header-title">
            <i class='bx bxs-star' style="font-size: 40px;"></i>
            <h1 style="margin: 0;">Bài làm tiêu biểu</h1>
        </div>
        
        <p style="color: var(--text-muted); margin-bottom: 30px;">Bài tập: <strong><?php echo htmlspecialchars($assignment['title']); ?></strong></p>

        <div class="card-grid">
            <?php if (count($outstanding_submissions) > 0): ?>
                <?php foreach ($outstanding_submissions as $sub): 
                    $feedback = json_decode($sub['ai_feedback'], true);
                    $comment = $feedback['comment'] ?? 'Không có nhận xét chi tiết.';
                ?>
                    <div class="card" style="border-color: rgba(245, 158, 11, 0.3);">
                        <div class="score-badge"><?php echo $sub['score']; ?></div>
                        
                        <div class="student-name">
                            <i class='bx bxs-user-circle' style="color: var(--primary); font-size: 24px;"></i> 
                            <?php echo htmlspecialchars($sub['name']); ?>
                        </div>
                        <div class="meta">Nộp lúc: <?php echo date('d/m/Y H:i', strtotime($sub['submitted_at'])); ?></div>
                        
                        <div class="feedback">
                            <strong>Nhận xét:</strong><br>
                            <?php echo htmlspecialchars($comment); ?>
                        </div>
                        
                        <!-- Trong thực tế có thể mở ra URL để tải file, ở đây ta chỉ mô phỏng tên file -->
                        <div class="btn-download" style="cursor: pointer;" onclick="alert('Tính năng tải file (<?php echo htmlspecialchars($sub['file_name']); ?>) đang trong quá trình thử nghiệm.')">
                            <i class='bx bx-download'></i> Xem cách làm
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class='bx bx-star' style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                    <p>Hiện chưa có bài làm tiêu biểu nào được giảng viên chọn.</p>
                </div>
            <?php endif; ?>
        </div>

<?php require_once '../includes/footer.php'; ?>
