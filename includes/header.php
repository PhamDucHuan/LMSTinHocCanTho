<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/notifications.php';
secureSessionStart();
$role = $_SESSION['user_role'] ?? '';
$roleLabel = [
    'admin' => 'Admin',
    'teacher' => 'Giáo viên',
    'administrative_staff' => 'Nhân viên hành chính',
    'student' => 'Học viên',
][$role] ?? $role;
$user_name = $_SESSION['user_name'] ?? 'User';
$user_avatar = filter_var($_SESSION['user_avatar'] ?? '', FILTER_VALIDATE_URL) ?: null;
$page_title = $page_title ?? 'LMS Dashboard';
$unreadNotifications = isset($unreadNotifications) ? max(0, (int) $unreadNotifications) : null;
if ($unreadNotifications === null && isset($pdo) && $pdo instanceof PDO && !empty($_SESSION['user_id'])) {
    try {
        $unreadNotifications = unreadNotificationCount($pdo, (int) $_SESSION['user_id']);
    } catch (Throwable $error) {
        error_log('Cannot count notifications: ' . $error->getMessage());
    }
}
$unreadNotifications ??= 0;
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="icon" type="image/png" href="../assets/images/LOGO1.png?v=3">
    <link rel="apple-touch-icon" href="../assets/images/LOGO1.png?v=3">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        :root {
            --primary: #6366f1;
            --primary-rgb: 99, 102, 241;
            <?php if ($role === 'admin'): ?>
                --primary: #f43f5e;
                --primary-rgb: 244, 63, 94;
            <?php endif; ?>
            --bg-dark: #0f172a;
            --sidebar-bg: #1e293b;
            --glass-bg: rgba(30, 41, 59, 0.7);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --border-color: rgba(255,255,255,0.08);
            --input-bg: rgba(0,0,0,0.2);
            --navbar-bg: rgba(15, 23, 42, 0.9);
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
        }
        html[data-theme="light"] {
            --bg-dark: #f1f5f9;
            --sidebar-bg: #ffffff;
            --glass-bg: rgba(255,255,255,0.88);
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border-color: rgba(15,23,42,0.12);
            --input-bg: rgba(241,245,249,0.95);
            --navbar-bg: rgba(255,255,255,0.92);
        }
        html[data-theme="ocean"] {
            --bg-dark:#071a2b;
            --sidebar-bg:#0b2942;
            --glass-bg:rgba(13,54,87,.78);
            --text-main:#ecfeff;
            --text-muted:#8fc7dc;
            --border-color:rgba(125,211,252,.16);
            --input-bg:rgba(3,25,43,.65);
            --navbar-bg:rgba(7,26,43,.92);
        }
        html[data-theme="forest"] {
            --bg-dark:#071c16;
            --sidebar-bg:#102d23;
            --glass-bg:rgba(20,65,49,.76);
            --text-main:#f0fdf4;
            --text-muted:#9bc9ae;
            --border-color:rgba(110,231,183,.16);
            --input-bg:rgba(5,35,25,.68);
            --navbar-bg:rgba(7,28,22,.92);
        }
        html[data-theme="violet"] {
            --bg-dark:#170f2e;
            --sidebar-bg:#281a46;
            --glass-bg:rgba(54,34,91,.76);
            --text-main:#faf5ff;
            --text-muted:#c4b5d9;
            --border-color:rgba(216,180,254,.16);
            --input-bg:rgba(28,17,53,.7);
            --navbar-bg:rgba(23,15,46,.92);
        }
        html[data-theme="sunset"] {
            --bg-dark:#2b1415;
            --sidebar-bg:#462221;
            --glass-bg:rgba(91,43,38,.74);
            --text-main:#fff7ed;
            --text-muted:#dfb1a1;
            --border-color:rgba(253,186,116,.17);
            --input-bg:rgba(51,22,20,.72);
            --navbar-bg:rgba(43,20,21,.92);
        }
        html[data-theme="universe"] {
            --bg-dark:#070617;
            --sidebar-bg:#11102a;
            --glass-bg:rgba(31,27,72,.76);
            --text-main:#f5f3ff;
            --text-muted:#aaa4cf;
            --border-color:rgba(167,139,250,.2);
            --input-bg:rgba(11,9,35,.72);
            --navbar-bg:rgba(7,6,23,.9);
        }
        html[data-theme="universe"] body {
            background-color:var(--bg-dark);
            background-image:
                radial-gradient(circle at 9% 36%, #fff 0 2px, rgba(147,197,253,.75) 2.5px, rgba(96,165,250,.2) 4px, transparent 7px),
                radial-gradient(circle at 26% 14%, #fff 0 1.8px, rgba(216,180,254,.8) 2.4px, rgba(168,85,247,.18) 4px, transparent 7px),
                radial-gradient(circle at 43% 79%, #fff 0 2.4px, rgba(186,230,253,.72) 3px, rgba(56,189,248,.16) 5px, transparent 8px),
                radial-gradient(circle at 67% 29%, #fff 0 2px, rgba(233,213,255,.82) 2.7px, rgba(192,132,252,.18) 4.5px, transparent 7px),
                radial-gradient(circle at 84% 67%, #fff 0 2.6px, rgba(191,219,254,.76) 3.2px, rgba(59,130,246,.17) 5px, transparent 8px),
                radial-gradient(circle at 94% 18%, #fff 0 1.8px, rgba(216,180,254,.72) 2.5px, rgba(168,85,247,.16) 4px, transparent 7px),
                radial-gradient(circle at 12% 18%, rgba(59,130,246,.22) 0, transparent 25%),
                radial-gradient(circle at 82% 15%, rgba(168,85,247,.2) 0, transparent 24%),
                radial-gradient(circle at 68% 82%, rgba(236,72,153,.13) 0, transparent 25%);
            background-attachment:fixed;
        }
        html[data-theme="universe"] body::before,
        html[data-theme="universe"] body::after {
            content:"";
            position:fixed;
            left:0;
            top:0;
            border-radius:50%;
            pointer-events:none;
            z-index:0;
        }
        html[data-theme="universe"] body::before {
            width:2px;
            height:2px;
            background:#fff;
            box-shadow:
                4vw 9vh rgba(255,255,255,.85), 13vw 27vh rgba(191,219,254,.75),
                22vw 6vh rgba(255,255,255,.55), 31vw 43vh rgba(221,214,254,.9),
                39vw 17vh rgba(255,255,255,.65), 47vw 71vh rgba(186,230,253,.75),
                55vw 34vh rgba(255,255,255,.95), 63vw 12vh rgba(233,213,255,.68),
                72vw 56vh rgba(255,255,255,.58), 81vw 25vh rgba(219,234,254,.9),
                91vw 8vh rgba(255,255,255,.7), 96vw 68vh rgba(243,232,255,.8),
                8vw 78vh rgba(255,255,255,.62), 18vw 61vh rgba(186,230,253,.88),
                27vw 91vh rgba(255,255,255,.72), 36vw 82vh rgba(233,213,255,.55),
                58vw 93vh rgba(255,255,255,.82), 69vw 76vh rgba(219,234,254,.64),
                77vw 88vh rgba(255,255,255,.94), 88vw 47vh rgba(233,213,255,.7);
            animation:universeTwinkleA 5.8s ease-in-out infinite alternate;
        }
        html[data-theme="universe"] body::after {
            width:1px;
            height:1px;
            background:rgba(255,255,255,.8);
            box-shadow:
                2vw 48vh rgba(255,255,255,.5), 10vw 15vh rgba(216,180,254,.8),
                16vw 91vh rgba(255,255,255,.65), 24vw 37vh rgba(125,211,252,.72),
                29vw 13vh rgba(255,255,255,.46), 34vw 66vh rgba(233,213,255,.82),
                42vw 29vh rgba(255,255,255,.7), 49vw 89vh rgba(147,197,253,.6),
                53vw 8vh rgba(255,255,255,.76), 60vw 52vh rgba(216,180,254,.65),
                66vw 31vh rgba(255,255,255,.48), 74vw 4vh rgba(186,230,253,.84),
                79vw 72vh rgba(255,255,255,.7), 85vw 94vh rgba(233,213,255,.54),
                90vw 34vh rgba(255,255,255,.86), 98vw 19vh rgba(147,197,253,.62),
                6vw 67vh rgba(255,255,255,.78), 20vw 73vh rgba(216,180,254,.5),
                44vw 58vh rgba(255,255,255,.88), 70vw 97vh rgba(186,230,253,.7);
            animation:universeTwinkleB 8.3s ease-in-out infinite alternate;
        }
        html[data-theme="universe"] .main-content { position:relative; z-index:1; }
        @keyframes universeTwinkleA {
            0% { opacity:.38; transform:translate3d(0,0,0) scale(.9); }
            45% { opacity:.9; }
            100% { opacity:.56; transform:translate3d(3px,-5px,0) scale(1.08); }
        }
        @keyframes universeTwinkleB {
            0% { opacity:.72; transform:translate3d(0,0,0); }
            55% { opacity:.3; }
            100% { opacity:.92; transform:translate3d(-4px,3px,0); }
        }

        /* Đại dương: tia sáng dưới nước, gợn sóng và bong bóng nổi. */
        html[data-theme="ocean"] body {
            background-color:var(--bg-dark);
            background-image:
                radial-gradient(ellipse at 14% -8%, rgba(125,211,252,.28) 0, transparent 34%),
                radial-gradient(ellipse at 82% 6%, rgba(34,211,238,.15) 0, transparent 28%),
                repeating-radial-gradient(ellipse at 50% -18%, transparent 0 52px, rgba(125,211,252,.035) 55px 58px, transparent 62px 92px),
                linear-gradient(180deg, rgba(14,116,144,.13), rgba(3,25,43,.1) 42%, rgba(2,15,28,.32));
            background-attachment:fixed;
        }
        html[data-theme="ocean"] body::before,
        html[data-theme="ocean"] body::after {
            content:"";
            position:fixed;
            pointer-events:none;
            z-index:0;
        }
        html[data-theme="ocean"] body::before {
            left:7vw;
            bottom:-18px;
            width:8px;
            height:8px;
            border:1px solid rgba(186,230,253,.7);
            border-radius:50%;
            background:rgba(125,211,252,.08);
            box-shadow:
                8vw -19vh 0 2px rgba(186,230,253,.32),
                19vw -7vh 0 -1px rgba(224,242,254,.68),
                27vw -46vh 0 3px rgba(125,211,252,.26),
                38vw -24vh 0 1px rgba(186,230,253,.42),
                48vw -68vh 0 -1px rgba(224,242,254,.72),
                58vw -35vh 0 3px rgba(125,211,252,.28),
                69vw -12vh 0 1px rgba(186,230,253,.5),
                76vw -57vh 0 4px rgba(125,211,252,.2),
                84vw -29vh 0 -1px rgba(224,242,254,.7),
                91vw -76vh 0 2px rgba(186,230,253,.34),
                13vw -81vh 0 1px rgba(125,211,252,.4),
                43vw -91vh 0 2px rgba(224,242,254,.3);
            animation:oceanBubbles 12s ease-in-out infinite;
        }
        html[data-theme="ocean"] body::after {
            inset:0;
            opacity:.52;
            background:
                linear-gradient(112deg, transparent 8%, rgba(186,230,253,.055) 17%, transparent 27%),
                linear-gradient(72deg, transparent 58%, rgba(103,232,249,.045) 67%, transparent 76%),
                radial-gradient(ellipse at 18% 102%, rgba(14,116,144,.3) 0 8%, transparent 8.5%),
                radial-gradient(ellipse at 72% 106%, rgba(8,80,105,.42) 0 12%, transparent 12.5%);
            animation:oceanLightDrift 9s ease-in-out infinite alternate;
        }
        html[data-theme="ocean"] .main-content,
        html[data-theme="forest"] .main-content { position:relative; z-index:1; }
        @keyframes oceanBubbles {
            0% { opacity:.25; transform:translate3d(0,12vh,0) scale(.88); }
            45% { opacity:.82; }
            100% { opacity:.18; transform:translate3d(2vw,-18vh,0) scale(1.08); }
        }
        @keyframes oceanLightDrift {
            from { transform:translate3d(-1.5vw,0,0) skewX(-1deg); opacity:.38; }
            to { transform:translate3d(1.5vw,1vh,0) skewX(1deg); opacity:.65; }
        }

        /* Rừng xanh: tia nắng, tán lá, lá bay và đom đóm so le. */
        html[data-theme="forest"] body {
            background-color:var(--bg-dark);
            background-image:
                radial-gradient(ellipse at 16% -4%, rgba(253,224,71,.16) 0, transparent 28%),
                radial-gradient(ellipse at 90% 8%, rgba(52,211,153,.12) 0, transparent 29%),
                radial-gradient(ellipse at 4% 22%, rgba(20,83,45,.38) 0 10%, transparent 10.5%),
                radial-gradient(ellipse at 96% 38%, rgba(21,128,61,.25) 0 13%, transparent 13.5%),
                linear-gradient(180deg, rgba(22,101,52,.1), rgba(3,25,18,.24));
            background-attachment:fixed;
        }
        html[data-theme="forest"] body::before,
        html[data-theme="forest"] body::after {
            content:"";
            position:fixed;
            pointer-events:none;
            z-index:0;
        }
        html[data-theme="forest"] body::before {
            left:5vw;
            top:11vh;
            width:3px;
            height:3px;
            border-radius:50%;
            background:#fef08a;
            box-shadow:
                7vw 18vh 0 1px rgba(254,240,138,.72),
                16vw 54vh 0 0 rgba(190,242,100,.82),
                25vw 8vh 0 1px rgba(254,249,195,.62),
                34vw 72vh 0 1px rgba(253,224,71,.72),
                43vw 31vh 0 0 rgba(217,249,157,.88),
                51vw 84vh 0 1px rgba(254,240,138,.58),
                61vw 16vh 0 1px rgba(190,242,100,.76),
                69vw 59vh 0 0 rgba(254,249,195,.9),
                78vw 29vh 0 1px rgba(253,224,71,.62),
                87vw 76vh 0 1px rgba(217,249,157,.72),
                93vw 12vh 0 0 rgba(254,240,138,.88),
                12vw 88vh 0 1px rgba(190,242,100,.56);
            filter:drop-shadow(0 0 4px rgba(253,224,71,.8));
            animation:forestFireflies 6.7s ease-in-out infinite alternate;
        }
        html[data-theme="forest"] body::after {
            right:8vw;
            top:18vh;
            width:19px;
            height:10px;
            border-radius:100% 0 100% 0;
            background:rgba(74,222,128,.2);
            box-shadow:
                -72vw 8vh rgba(34,197,94,.16),
                -55vw 39vh rgba(134,239,172,.15),
                -31vw 6vh rgba(74,222,128,.18),
                -15vw 53vh rgba(22,163,74,.2),
                4vw 28vh rgba(134,239,172,.13),
                -83vw 64vh rgba(74,222,128,.16),
                -42vw 72vh rgba(34,197,94,.18);
            animation:forestLeaves 10s ease-in-out infinite alternate;
        }
        @keyframes forestFireflies {
            0% { opacity:.24; transform:translate3d(0,0,0); }
            38% { opacity:.94; }
            67% { opacity:.42; }
            100% { opacity:.8; transform:translate3d(7px,-10px,0); }
        }
        @keyframes forestLeaves {
            from { opacity:.42; transform:translate3d(0,0,0) rotate(-18deg); }
            to { opacity:.78; transform:translate3d(-18px,22px,0) rotate(14deg); }
        }

        /* Tím đêm: ánh trăng, mây tím và những đốm sáng dịu. */
        html[data-theme="violet"] body {
            background-color:var(--bg-dark);
            background-image:
                radial-gradient(circle at 84% 13%, rgba(250,245,255,.72) 0 2.6%, rgba(216,180,254,.23) 3.2%, transparent 8%),
                radial-gradient(ellipse at 78% 14%, rgba(192,132,252,.13) 0, transparent 23%),
                radial-gradient(ellipse at 10% 78%, rgba(126,34,206,.18) 0, transparent 30%),
                linear-gradient(155deg, rgba(88,28,135,.1), rgba(23,15,46,.18) 48%, rgba(46,16,101,.2));
            background-attachment:fixed;
        }
        html[data-theme="violet"] body::before,
        html[data-theme="violet"] body::after {
            content:"";
            position:fixed;
            pointer-events:none;
            z-index:0;
        }
        html[data-theme="violet"] body::before {
            left:6vw;
            top:12vh;
            width:2px;
            height:2px;
            border-radius:50%;
            background:rgba(250,245,255,.9);
            box-shadow:
                8vw 17vh 0 1px rgba(233,213,255,.7),
                18vw 4vh rgba(250,245,255,.76),
                27vw 42vh 0 1px rgba(216,180,254,.68),
                37vw 13vh rgba(250,245,255,.88),
                46vw 69vh 0 1px rgba(192,132,252,.62),
                55vw 28vh rgba(243,232,255,.74),
                64vw 81vh 0 1px rgba(216,180,254,.72),
                73vw 47vh rgba(250,245,255,.86),
                84vw 72vh 0 1px rgba(192,132,252,.58),
                91vw 25vh rgba(243,232,255,.78),
                13vw 83vh rgba(250,245,255,.64),
                32vw 91vh 0 1px rgba(216,180,254,.65);
            filter:drop-shadow(0 0 3px rgba(216,180,254,.72));
            animation:violetNightGlow 7.4s ease-in-out infinite alternate;
        }
        html[data-theme="violet"] body::after {
            left:-14vw;
            top:19vh;
            width:46vw;
            height:10vh;
            border-radius:50%;
            opacity:.34;
            background:
                radial-gradient(ellipse at 24% 65%, rgba(168,85,247,.42) 0 19%, transparent 20%),
                radial-gradient(ellipse at 51% 45%, rgba(126,34,206,.46) 0 24%, transparent 25%),
                radial-gradient(ellipse at 79% 68%, rgba(192,132,252,.3) 0 18%, transparent 19%);
            box-shadow:62vw 43vh 55px rgba(126,34,206,.14);
            filter:blur(8px);
            animation:violetCloudDrift 14s ease-in-out infinite alternate;
        }
        html[data-theme="violet"] .main-content,
        html[data-theme="sunset"] .main-content { position:relative; z-index:1; }
        @keyframes violetNightGlow {
            0% { opacity:.32; transform:translate3d(0,0,0) scale(.94); }
            48% { opacity:.9; }
            100% { opacity:.55; transform:translate3d(5px,-4px,0) scale(1.06); }
        }
        @keyframes violetCloudDrift {
            from { transform:translate3d(-2vw,0,0) scale(1); opacity:.25; }
            to { transform:translate3d(9vw,2vh,0) scale(1.08); opacity:.46; }
        }

        /* Hoàng hôn: mặt trời lặn, tầng mây ấm và đàn chim xa. */
        html[data-theme="sunset"] body {
            background-color:var(--bg-dark);
            background-image:
                radial-gradient(circle at 78% 30%, rgba(254,240,138,.82) 0 3.2%, rgba(251,146,60,.28) 4%, transparent 12%),
                linear-gradient(180deg, rgba(124,58,237,.09) 0%, rgba(194,65,12,.13) 42%, rgba(127,29,29,.18) 72%, rgba(43,20,21,.3) 100%),
                radial-gradient(ellipse at 8% 92%, rgba(127,29,29,.28) 0, transparent 34%);
            background-attachment:fixed;
        }
        html[data-theme="sunset"] body::before,
        html[data-theme="sunset"] body::after {
            content:"";
            position:fixed;
            pointer-events:none;
            z-index:0;
        }
        html[data-theme="sunset"] body::before {
            left:-10vw;
            top:21vh;
            width:48vw;
            height:12vh;
            border-radius:50%;
            opacity:.5;
            background:
                radial-gradient(ellipse at 22% 65%, rgba(251,146,60,.34) 0 20%, transparent 21%),
                radial-gradient(ellipse at 48% 42%, rgba(244,63,94,.25) 0 25%, transparent 26%),
                radial-gradient(ellipse at 78% 68%, rgba(253,186,116,.3) 0 19%, transparent 20%);
            box-shadow:69vw 34vh 60px rgba(194,65,12,.16);
            filter:blur(7px);
            animation:sunsetCloudDrift 13s ease-in-out infinite alternate;
        }
        html[data-theme="sunset"] body::after {
            right:13vw;
            top:18vh;
            width:17px;
            height:8px;
            border-top:2px solid rgba(67,20,7,.72);
            border-radius:50% 50% 0 0;
            transform:rotate(-8deg);
            box-shadow:
                -7vw 5vh 0 -1px rgba(67,20,7,.62),
                -16vw -3vh 0 1px rgba(69,10,10,.5),
                8vw 9vh 0 -2px rgba(67,20,7,.68),
                16vw 2vh 0 -1px rgba(69,10,10,.46),
                -27vw 12vh 0 -2px rgba(67,20,7,.58);
            animation:sunsetBirds 8s ease-in-out infinite alternate;
        }
        @keyframes sunsetCloudDrift {
            from { transform:translate3d(-3vw,0,0); opacity:.34; }
            to { transform:translate3d(8vw,1.5vh,0); opacity:.58; }
        }
        @keyframes sunsetBirds {
            from { transform:translate3d(0,0,0) rotate(-8deg); opacity:.42; }
            to { transform:translate3d(-4vw,-2vh,0) rotate(-3deg); opacity:.76; }
        }
        @media (prefers-reduced-motion:reduce) {
            html[data-theme="universe"] body::before,
            html[data-theme="universe"] body::after,
            html[data-theme="ocean"] body::before,
            html[data-theme="ocean"] body::after,
            html[data-theme="forest"] body::before,
            html[data-theme="forest"] body::after,
            html[data-theme="violet"] body::before,
            html[data-theme="violet"] body::after,
            html[data-theme="sunset"] body::before,
            html[data-theme="sunset"] body::after { animation:none; }
        }
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Arial, sans-serif; background: var(--bg-dark); color: var(--text-main); margin: 0; display: flex; min-height: 100vh; overflow-x: hidden; transition: background .25s, color .25s; }
        
        /* Sidebar Styles */
        .sidebar {
            width: 260px;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
            z-index: 100;
            transition: 0.3s;
        }
        .sidebar.collapsed { transform: translateX(-100%); }
        .sidebar-header {
            padding: 20px 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .sidebar-header h2 { margin: 0; color: var(--primary); font-size: 20px; display: flex; align-items: center; gap: 8px; }
        .sidebar-brand {
            width:182px;
            height:48px;
            padding:4px 7px;
            display:flex;
            align-items:center;
            border-radius:9px;
            background:#fff;
            box-shadow:0 5px 16px rgba(0,0,0,.16);
            overflow:hidden;
        }
        .sidebar-brand img { display:block; width:100%; height:100%; object-fit:contain; }
        
        .sidebar-menu { flex: 1; padding: 20px 0; overflow-y: auto; }
        .menu-item {
            display: flex; align-items: center; gap: 12px; padding: 12px 24px;
            color: var(--text-muted); text-decoration: none; font-weight: 500;
            transition: 0.3s;
        }
        .menu-item i { font-size: 20px; }
        .menu-item:hover {
            background: rgba(255,255,255,0.05); color: var(--primary);
        }
        .menu-item.active {
            background: rgba(var(--primary-rgb),0.12);
            color: var(--primary);
            border-left: 3px solid var(--primary);
            padding-left: 21px;
        }
        .sidebar-group { border-bottom:1px solid var(--border-color); }
        .sidebar-group:first-child { border-top:1px solid var(--border-color); }
        .sidebar-group-toggle {
            width:100%; border:0; background:transparent; color:var(--text-muted); cursor:pointer;
            padding:12px 18px 9px; display:flex; align-items:center; gap:9px;
            font:600 12px/1.2 system-ui,-apple-system,"Segoe UI",Arial,sans-serif; letter-spacing:.08em; text-transform:uppercase;
            transition:background .2s,color .2s;
        }
        .sidebar-group-toggle:hover { background:rgba(255,255,255,.035); color:var(--text-main); }
        .sidebar-group-toggle > i:first-child { color:var(--primary); font-size:17px; }
        .sidebar-group-toggle .group-chevron { margin-left:auto; font-size:18px; transition:transform .25s ease; }
        .sidebar-group.collapsed .group-chevron { transform:rotate(-90deg); }
        .sidebar-group-items { display:grid; grid-template-rows:1fr; opacity:1; transition:grid-template-rows .28s ease,opacity .2s ease; }
        .sidebar-group-items-inner { min-height:0; overflow:hidden; padding-bottom:7px; transition:padding .28s ease; }
        .sidebar-group.collapsed .sidebar-group-items { grid-template-rows:0fr; opacity:0; }
        .sidebar-group.collapsed .sidebar-group-items-inner { padding-bottom:0; }
        .sidebar-group .menu-item { padding:10px 20px 10px 29px; font-size:14px; }
        .sidebar-group .menu-item.active { padding-left:26px; }
        
        .sidebar-footer { padding: 20px; border-top: 1px solid rgba(255,255,255,0.05); }
        .user-menu-container { position: relative; }
        .user-menu-container .user-info { display: flex; align-items: center; gap: 10px; padding: 10px; border-radius: 8px; transition: 0.3s; border: 1px solid transparent; cursor: pointer; }
        .user-menu-container:hover .user-info { background: rgba(255,255,255,0.05); border-color: rgba(255,255,255,0.1); }
        .user-menu-container .user-info i { font-size: 32px; color: var(--text-muted); }
        .user-avatar { width:38px; height:38px; border-radius:50%; object-fit:cover; flex:0 0 38px; border:2px solid rgba(var(--primary-rgb),.6); background:var(--glass-bg); }
        .user-avatar-fallback { width:38px; height:38px; flex:0 0 38px; display:grid; place-items:center; }
        .user-avatar-fallback[hidden] { display:none !important; }
        .user-avatar-fallback i { font-size:34px !important; }
        .user-menu-container .user-details { line-height: 1.2; }
        .user-menu-container .user-name { font-weight: 600; font-size: 14px; }
        .user-menu-container .user-role { font-size: 12px; color: var(--text-muted); text-transform: capitalize; }
        
        .user-dropdown {
            position: absolute;
            bottom: 100%;
            left: 0;
            width: 100%;
            background: var(--sidebar-bg);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 8px;
            padding: 8px;
            margin-bottom: 5px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(10px);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 -4px 15px rgba(0,0,0,0.2);
            z-index: 101;
        }
        .user-menu-container:hover .user-dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        .user-dropdown a {
            display: flex; align-items: center; gap: 8px; padding: 10px; color: var(--text-main); text-decoration: none; border-radius: 6px; transition: 0.2s; font-size: 14px; font-weight: 500;
        }
        .user-dropdown a:hover { background: rgba(255,255,255,0.05); }
        .user-dropdown a.text-danger { color: #fca5a5; }
        .user-dropdown a.text-danger:hover { background: rgba(239, 68, 68, 0.1); color: var(--danger); }

        /* Main Content Styles */
        .main-content { margin-left: 260px; flex: 1; display: flex; flex-direction: column; width: calc(100% - 260px); transition: 0.3s; }
        .main-content.expanded { margin-left: 0; width: 100%; }
        
        #sidebar-toggle-open { display: none; }
        .main-content.expanded #sidebar-toggle-open { display: flex; }
        
        .top-navbar { padding: 20px 40px; background: var(--navbar-bg); border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; position:relative; }
        .top-navbar h2 { margin: 0; font-size: 20px; font-weight: 500; }
        .theme-toggle { width:42px; height:42px; border:1px solid var(--border-color); border-radius:10px; background:var(--glass-bg); color:var(--text-main); cursor:pointer; font-size:21px; display:grid; place-items:center; }
        .theme-toggle:hover { color:var(--primary); border-color:var(--primary); }
        .theme-panel { position:absolute; right:40px; top:70px; width:min(330px,calc(100vw - 30px)); padding:18px; border-radius:14px; background:var(--sidebar-bg); border:1px solid var(--border-color); box-shadow:0 18px 45px rgba(0,0,0,.28); z-index:300; }
        .theme-panel[hidden] { display:none; }
        .theme-panel-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:15px; }
        .theme-panel-header strong { font-size:16px; }
        .theme-close { border:0; background:transparent; color:var(--text-muted); cursor:pointer; font-size:22px; }
        .theme-label { display:block; color:var(--text-muted); font-size:13px; margin:14px 0 8px; }
        .theme-modes { display:grid; grid-template-columns:repeat(2,1fr); gap:8px; }
        .theme-mode { padding:9px 6px; border:1px solid var(--border-color); border-radius:8px; background:var(--glass-bg); color:var(--text-main); cursor:pointer; font-family:inherit; }
        .theme-mode[data-theme-mode="dark"] { background:#0f172a; color:#f8fafc; border-color:#334155; }
        .theme-mode[data-theme-mode="light"] { background:#ffffff; color:#0f172a; border-color:#cbd5e1; }
        .theme-mode[data-theme-mode="system"] { background:linear-gradient(135deg,#0f172a 0 50%,#ffffff 50% 100%); color:#6366f1; border-color:#94a3b8; text-shadow:0 1px 1px rgba(255,255,255,.8); }
        .theme-mode[data-theme-mode="ocean"] { background:linear-gradient(135deg,#071a2b,#0e7490); color:#ecfeff; border-color:#38bdf8; }
        .theme-mode[data-theme-mode="forest"] { background:linear-gradient(135deg,#071c16,#15803d); color:#f0fdf4; border-color:#34d399; }
        .theme-mode[data-theme-mode="violet"] { background:linear-gradient(135deg,#170f2e,#7e22ce); color:#faf5ff; border-color:#c084fc; }
        .theme-mode[data-theme-mode="sunset"] { background:linear-gradient(135deg,#2b1415,#c2410c); color:#fff7ed; border-color:#fb923c; }
        .theme-mode[data-theme-mode="universe"] { background:radial-gradient(circle at 70% 25%,#a855f7 0,transparent 22%),linear-gradient(135deg,#070617,#312e81); color:#f5f3ff; border-color:#8b5cf6; }
        .theme-mode.active { border-color:var(--primary); box-shadow:0 0 0 3px rgba(var(--primary-rgb),.18); transform:translateY(-1px); }
        .theme-colors { display:flex; align-items:center; flex-wrap:wrap; gap:10px; }
        .theme-swatch { width:30px; height:30px; border-radius:50%; border:2px solid transparent; cursor:pointer; box-shadow:0 0 0 1px var(--border-color); }
        .theme-swatch.active { border-color:var(--text-main); box-shadow:0 0 0 2px var(--primary); }
        .theme-custom-color { width:34px; height:34px; padding:2px; border:1px solid var(--border-color); border-radius:8px; background:transparent; cursor:pointer; }
        .theme-reset { width:100%; margin-top:16px; padding:9px; border:1px solid var(--border-color); border-radius:8px; background:transparent; color:var(--text-muted); cursor:pointer; font-family:inherit; }
        .theme-reset:hover { color:var(--primary); border-color:var(--primary); }
        html[data-theme="light"] input[type="text"],
        html[data-theme="light"] input[type="number"],
        html[data-theme="light"] input[type="datetime-local"],
        html[data-theme="light"] input[type="email"],
        html[data-theme="light"] input[type="password"],
        html[data-theme="light"] textarea,
        html[data-theme="light"] select { background:var(--input-bg) !important; color:var(--text-main) !important; border-color:var(--border-color) !important; }
        html[data-theme="light"] select option { background:#fff; color:#0f172a; }
        html[data-theme="light"] .card,
        html[data-theme="light"] .box,
        html[data-theme="light"] .stat-card,
        html[data-theme="light"] .chart-container { border-color:var(--border-color) !important; box-shadow:0 8px 25px rgba(15,23,42,.06); }
        @media (max-width:650px) {
            .top-navbar { padding:16px 18px; }
            .theme-panel { right:15px; top:64px; }
        }
        
        .page-content { padding: 40px; width: 100%; max-width: none; }
        
        /* Common Components */
        .card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
        .card { background: var(--glass-bg); padding: 20px; border-radius: 16px; border: 1px solid rgba(255,255,255,0.05); transition: 0.3s; }
        .card:hover { transform: translateY(-5px); border-color: rgba(var(--primary-rgb), 0.65); box-shadow:0 15px 35px rgba(var(--primary-rgb),.13),0 0 22px rgba(var(--primary-rgb),.08); }
        :where(.stat-card,.dashboard-course-card,.course-management-card):hover {
            border-color:rgba(var(--primary-rgb),.65) !important;
            box-shadow:0 15px 35px rgba(var(--primary-rgb),.13),0 0 22px rgba(var(--primary-rgb),.08);
        }
        
        .btn { position:relative; overflow:hidden; isolation:isolate; padding:10px 20px; border-radius:8px; border:none; font-weight:600; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:8px; transition:all .3s; }
        .lms-ripple { position:absolute; z-index:0; border-radius:50%; pointer-events:none; background:rgba(255,255,255,.3); transform:translate(-50%,-50%) scale(0); animation:lmsRipple .58s ease-out forwards; }
        @keyframes lmsRipple { to { transform:translate(-50%,-50%) scale(1); opacity:0; } }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { opacity: 0.9; transform: translateY(-2px); }
        .btn-outline { background: transparent; border: 1px solid var(--primary); color: var(--primary); }
        .btn-outline:hover { background: rgba(99, 102, 241, 0.1); }
        
        .box { background: var(--glass-bg); padding: 30px; border-radius: 16px; border: 1px solid rgba(255,255,255,0.05); }
        .lms-dialog-overlay:not([hidden]) .lms-dialog,
        dialog[open],
        .theme-panel:not([hidden]) { animation:lmsPopIn .2s cubic-bezier(.2,.8,.2,1); }
        @keyframes lmsPopIn {
            from { opacity:0; transform:translateY(8px) scale(.96); }
            to { opacity:1; transform:translateY(0) scale(1); }
        }
        .lms-confetti-piece { position:fixed; z-index:5000; top:48%; left:50%; width:8px; height:13px; border-radius:2px; pointer-events:none; animation:lmsConfetti 3s cubic-bezier(.15,.68,.25,1) forwards; }
        @keyframes lmsConfetti {
            0% { opacity:1; transform:translate(-50%,-50%) rotate(0deg); }
            100% { opacity:0; transform:translate(var(--confetti-x),var(--confetti-y)) rotate(var(--confetti-r)); }
        }
        @media (prefers-reduced-motion:reduce) {
            .lms-ripple,.lms-confetti-piece,
            .lms-dialog-overlay:not([hidden]) .lms-dialog,
            dialog[open],.theme-panel:not([hidden]) { animation:none !important; }
        }
        .empty-state { text-align: center; padding: 50px; color: var(--text-muted); grid-column: 1 / -1; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.05); }
        th { color: var(--text-muted); font-weight: 500; }
        
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; }
        input[type="text"], input[type="datetime-local"], textarea, select {
            width: 100%; padding: 12px; background: var(--input-bg); border: 1px solid var(--border-color); color: var(--text-main); border-radius: 8px; font-family: inherit; box-sizing: border-box;
        }
        
        .status { padding: 5px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; margin-bottom: 15px; }
        .status.done { background: rgba(16, 185, 129, 0.2); color: var(--success); }
        .status.pending { background: rgba(245, 158, 11, 0.2); color: var(--warning); }
        .status.admin { background: rgba(244, 63, 94, 0.2); color: var(--primary); }

        /* Responsive foundation shared by admin, teacher and student screens. */
        html, body { width:100%; max-width:100%; }
        img, video, svg, iframe, canvas { max-width:100%; }
        .main-content, .page-content, .box, .card { min-width:0; }
        .table-responsive {
            width:100%;
            overflow-x:auto;
            -webkit-overflow-scrolling:touch;
            scrollbar-width:thin;
        }
        .table-responsive table { min-width:680px; margin-top:20px; }
        .sidebar-backdrop {
            display:none;
            position:fixed;
            inset:0;
            z-index:90;
            border:0;
            background:rgba(2,6,23,.62);
            backdrop-filter:blur(2px);
            cursor:pointer;
        }

        @media (max-width:1200px) {
            .page-content { padding:28px; }
            .card-grid { grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); }
        }

        @media (max-width:900px) {
            body { display:block; }
            .sidebar {
                width:min(280px,86vw);
                box-shadow:18px 0 45px rgba(0,0,0,.3);
            }
            .main-content,
            .main-content.expanded {
                width:100%;
                min-height:100vh;
                margin-left:0;
            }
            #sidebar-toggle-open,
            .main-content.expanded #sidebar-toggle-open { display:flex; }
            body.sidebar-mobile-open .sidebar-backdrop { display:block; }
            .top-navbar { padding:16px 22px; }
            .theme-panel { right:18px; }
            .page-content { padding:24px 20px; }
            .stats-grid { grid-template-columns:repeat(2,minmax(0,1fr)) !important; }
            .charts-grid { grid-template-columns:1fr !important; }
            .chart-container { min-width:0; }
        }

        @media (max-width:650px) {
            .top-navbar {
                min-height:64px;
                padding:12px 14px;
                gap:10px;
            }
            .top-navbar > div:first-child { min-width:0; gap:8px !important; }
            .top-navbar h2 {
                overflow:hidden;
                font-size:17px;
                line-height:1.25;
                text-overflow:ellipsis;
                white-space:nowrap;
            }
            .theme-toggle { width:38px; height:38px; flex:0 0 38px; }
            .theme-panel {
                position:fixed;
                top:72px;
                right:12px;
                left:12px;
                width:auto;
                max-height:calc(100vh - 84px);
                overflow-y:auto;
            }
            .page-content { padding:18px 14px 28px; }
            .box, .card { padding:18px; border-radius:13px; }
            .card-grid,
            .stats-grid,
            .charts-grid,
            .dashboard-course-grid,
            .course-grid,
            .course-management-grid {
                grid-template-columns:minmax(0,1fr) !important;
                gap:14px !important;
            }
            .page-content [style*="grid-template-columns"] {
                grid-template-columns:minmax(0,1fr) !important;
            }
            .page-content [style*="display: flex"],
            .page-content [style*="display:flex"] {
                flex-wrap:wrap;
            }
            .btn {
                max-width:100%;
                min-height:42px;
                justify-content:center;
                white-space:normal;
                text-align:center;
            }
            input, select, textarea, button { max-width:100%; }
            input[type="text"], input[type="number"], input[type="email"],
            input[type="password"], input[type="datetime-local"], textarea, select {
                min-height:44px;
                font-size:16px;
            }
            .empty-state { padding:32px 12px; }
            .table-responsive {
                margin-inline:-4px;
                padding-bottom:5px;
            }
            .table-responsive table { min-width:620px; }
            th, td { padding:12px 10px; }
            dialog,
            .lms-dialog,
            .modal-content {
                width:calc(100vw - 24px) !important;
                max-width:calc(100vw - 24px) !important;
                max-height:calc(100vh - 32px);
                overflow-y:auto;
                padding:20px !important;
            }
            iframe { height:min(58vh,420px) !important; }
        }

        @media (max-width:420px) {
            .sidebar-brand { width:172px; height:44px; }
            .page-content { padding-inline:11px; }
            .box, .card { padding:15px; }
            .theme-modes { grid-template-columns:1fr 1fr; }
            .stats-grid { grid-template-columns:1fr !important; }
            .table-responsive table { min-width:560px; }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/sidebar-modern.css?v=1">
    <script>
        (function () {
            try {
                const saved = JSON.parse(localStorage.getItem('lms_theme') || '{}');
                const supported = ['dark','light','system','ocean','forest','violet','sunset','universe'];
                const mode = supported.includes(saved.mode) ? saved.mode : 'dark';
                const resolved = mode === 'system'
                    ? (matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark')
                    : mode;
                document.documentElement.dataset.theme = resolved;
                document.documentElement.dataset.themeMode = mode;
                if (/^#[0-9a-f]{6}$/i.test(saved.primary || '')) {
                    const hex = saved.primary;
                    document.documentElement.style.setProperty('--primary', hex);
                    document.documentElement.style.setProperty('--primary-rgb',
                        `${parseInt(hex.slice(1,3),16)}, ${parseInt(hex.slice(3,5),16)}, ${parseInt(hex.slice(5,7),16)}`);
                }
            } catch (_) {}
        })();
    </script>
</head>
<body data-default-primary="<?php echo $role === 'admin' ? '#f43f5e' : '#6366f1'; ?>">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <a href="<?php echo $role === 'admin' ? '../admin/dashboard.php' : (in_array($role, ['teacher', 'administrative_staff'], true) ? '../teacher/dashboard.php' : '../student/dashboard.php'); ?>" class="sidebar-brand" aria-label="Tin học Cần Thơ - Trang tổng quan">
                <img src="../assets/images/Logo2.png" alt="Tin học Cần Thơ">
            </a>
            <button id="sidebar-toggle-close" style="background: transparent; border: none; color: var(--text-muted); font-size: 28px; cursor: pointer; padding: 0; display: flex; align-items: center; justify-content: center; transition: 0.3s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='var(--text-muted)'">
                <i class='bx bx-x'></i>
            </button>
        </div>
        
        <div class="sidebar-menu">
            <?php
            $parts = explode('/', str_replace('\\', '/', $_SERVER['PHP_SELF']));
            $current_page = $parts[count($parts)-2] . '/' . $parts[count($parts)-1];
            $isMenuActive = static function (array $item) use ($current_page): bool {
                if (!in_array($current_page, $item['match'], true)) return false;
                if ($current_page !== 'admin/teaching_schedule.php') return true;
                $url = (string) ($item['url'] ?? '');
                $ownSchedule = ((string) ($_GET['scope'] ?? '')) === 'mine';
                if (str_contains($url, 'scope=mine')) return $ownSchedule;
                if (str_contains($url, 'scope=all')) return !$ownSchedule;
                return true;
            };
            
            if ($role === 'admin') {
                $menuGroups = [
                    ['id' => 'overview', 'label' => 'Tổng quan', 'icon' => 'bx-home-alt', 'items' => [
                        ['url' => '../admin/dashboard.php', 'icon' => 'bx-shield', 'label' => 'Bảng điều khiển', 'match' => ['admin/dashboard.php']],
                    ]],
                    ['id' => 'training', 'label' => 'Quản lý đào tạo', 'icon' => 'bx-book-open', 'items' => [
                        ['url' => '../teacher/courses.php', 'icon' => 'bx-book-open', 'label' => 'Quản lý Khóa học', 'match' => ['teacher/courses.php', 'teacher/course_detail.php', 'teacher/quizzes.php']],
                        ['url' => '../admin/assignments.php', 'icon' => 'bx-book-content', 'label' => 'Quản lý Bài tập', 'match' => ['admin/assignments.php', 'admin/edit_assignment.php']],
                        ['url' => '../teacher/create_assignment.php', 'icon' => 'bx-plus-circle', 'label' => 'Giao Bài Mới', 'match' => ['teacher/create_assignment.php']],
                        ['url' => '../teacher/submissions.php', 'icon' => 'bx-file-find', 'label' => 'Bài làm Học viên', 'match' => ['teacher/submissions.php']],
                        ['url' => '../teacher/student_progress.php', 'icon' => 'bx-line-chart', 'label' => 'Tiến độ Học viên', 'match' => ['teacher/student_progress.php']],
                        ['url' => '../teacher/question_bank.php', 'icon' => 'bx-library', 'label' => 'Ngân hàng câu hỏi', 'match' => ['teacher/question_bank.php']],
                    ]],
                    ['id' => 'teaching-schedule', 'label' => 'Lịch dạy', 'icon' => 'bx-calendar-event', 'items' => [
                        ['url' => '../admin/teaching_schedule.php?scope=all', 'icon' => 'bx-calendar-event', 'label' => 'Xếp lớp & Lịch dạy', 'match' => ['admin/teaching_schedule.php']],
                        ['url' => '../admin/teaching_schedule.php?scope=mine', 'icon' => 'bx-calendar-check', 'label' => 'Lịch dạy của tôi', 'match' => ['admin/teaching_schedule.php']],
                        ['url' => '../admin/teacher_schedules.php', 'icon' => 'bx-group', 'label' => 'Lịch của giáo viên', 'match' => ['admin/teacher_schedules.php']],
                    ]],
                    ['id' => 'monitoring', 'label' => 'Theo dõi & AI', 'icon' => 'bx-line-chart', 'items' => [
                        ['url' => '../admin/ai_grading.php', 'icon' => 'bx-bot', 'label' => 'Giám sát chấm AI', 'match' => ['admin/ai_grading.php']],
                        ['url' => '../admin/audit_logs.php', 'icon' => 'bx-history', 'label' => 'Nhật ký hoạt động', 'match' => ['admin/audit_logs.php']],
                        ['url' => '../admin/login_logs.php', 'icon' => 'bx-log-in-circle', 'label' => 'Nhật ký đăng nhập', 'match' => ['admin/login_logs.php']],
                        ['url' => '../admin/online_users.php', 'icon' => 'bx-radio-circle-marked', 'label' => 'Người đang online', 'match' => ['admin/online_users.php']],
                        ['url' => '../admin/tickets.php', 'icon' => 'bx-support', 'label' => 'Quản lý Hỗ trợ', 'match' => ['admin/tickets.php', 'admin/ticket_detail.php']],
                    ]],
                    ['id' => 'system', 'label' => 'Quản trị hệ thống', 'icon' => 'bx-cog', 'items' => [
                        ['url' => '../admin/users.php', 'icon' => 'bx-group', 'label' => 'Quản lý Tài khoản', 'match' => ['admin/users.php']],
                        ['url' => '../admin/settings.php', 'icon' => 'bx-slider-alt', 'label' => 'Cấu hình hệ thống', 'match' => ['admin/settings.php']],
                        ['url' => '../admin/system_health.php', 'icon' => 'bx-pulse', 'label' => 'Tình trạng hệ thống', 'match' => ['admin/system_health.php']],
                        ['url' => '../admin/backups.php', 'icon' => 'bx-data', 'label' => 'Sao lưu dữ liệu', 'match' => ['admin/backups.php']],
                        ['url' => '../student/dashboard.php', 'icon' => 'bx-book-reader', 'label' => 'Giao diện Học viên', 'match' => ['student/dashboard.php', 'student/assignment.php', 'student/outstanding_submissions.php']],
                    ]],
                ];
            } elseif (in_array($role, ['teacher', 'administrative_staff'], true)) {
                $menuGroups = [
                    ['id' => 'teacher-overview', 'label' => 'Tổng quan', 'icon' => 'bx-home-alt', 'items' => [
                        ['url' => '../teacher/dashboard.php', 'icon' => 'bx-bar-chart-alt-2', 'label' => 'Bảng điều khiển', 'match' => ['teacher/dashboard.php']],
                    ]],
                    ['id' => 'teacher-training', 'label' => 'Quản lý đào tạo', 'icon' => 'bx-book-open', 'items' => [
                        ['url' => '../teacher/courses.php', 'icon' => 'bx-book-open', 'label' => 'Quản lý Khóa học', 'match' => ['teacher/courses.php', 'teacher/course_detail.php', 'teacher/quizzes.php']],
                        ['url' => '../teacher/assignments.php', 'icon' => 'bx-book-content', 'label' => 'Danh sách Bài tập', 'match' => ['teacher/assignments.php', 'teacher/edit_assignment.php']],
                        ['url' => '../teacher/create_assignment.php', 'icon' => 'bx-plus-circle', 'label' => 'Giao Bài Mới', 'match' => ['teacher/create_assignment.php']],
                        ['url' => '../teacher/question_bank.php', 'icon' => 'bx-library', 'label' => 'Ngân hàng câu hỏi', 'match' => ['teacher/question_bank.php']],
                    ]],
                    ['id' => 'teacher-schedule', 'label' => 'Lịch dạy', 'icon' => 'bx-calendar-event', 'items' => [
                        ['url' => '../admin/teaching_schedule.php', 'icon' => 'bx-calendar-check', 'label' => 'Lịch dạy của tôi', 'match' => ['admin/teaching_schedule.php']],
                        ...($role === 'administrative_staff' ? [[
                            'url' => '../admin/teacher_schedules.php', 'icon' => 'bx-group', 'label' => 'Lịch của giáo viên', 'match' => ['admin/teacher_schedules.php'],
                        ]] : []),
                    ]],
                    ['id' => 'teacher-students', 'label' => 'Theo dõi học viên', 'icon' => 'bx-group', 'items' => [
                        ['url' => '../teacher/submissions.php', 'icon' => 'bx-file-find', 'label' => 'Bài làm Học viên', 'match' => ['teacher/submissions.php']],
                        ['url' => '../teacher/student_progress.php', 'icon' => 'bx-line-chart', 'label' => 'Tiến độ Học viên', 'match' => ['teacher/student_progress.php']],
                        ['url' => '../teacher/export_grades.php', 'icon' => 'bx-export', 'label' => 'Xuất bảng điểm', 'match' => ['teacher/export_grades.php']],
                        ['url' => '../admin/tickets.php', 'icon' => 'bx-support', 'label' => 'Quản lý Hỗ trợ', 'match' => ['admin/tickets.php', 'admin/ticket_detail.php']],
                    ]],
                    ['id' => 'teacher-preview', 'label' => 'Xem giao diện', 'icon' => 'bx-show', 'items' => [
                        ['url' => '../student/dashboard.php', 'icon' => 'bx-book-reader', 'label' => 'Giao diện Học viên', 'match' => ['student/dashboard.php', 'student/course.php', 'student/assignment.php', 'student/outstanding_submissions.php', 'student/assignments.php', 'student/quizzes.php', 'student/quiz.php']],
                    ]],
                ];
            } else { // student
                $adminEmail = '';
                if (isset($pdo) && function_exists('getSetting')) {
                    $adminEmail = getSetting($pdo, 'admin_email');
                } elseif (isset($pdo) && file_exists(__DIR__ . '/settings.php')) {
                    require_once __DIR__ . '/settings.php';
                    $adminEmail = getSetting($pdo, 'admin_email');
                }

                $menuGroups = [
                    ['id' => 'student-overview', 'label' => 'Tổng quan', 'icon' => 'bx-home-alt', 'items' => [
                        ['url' => '../student/dashboard.php', 'icon' => 'bx-home-alt', 'label' => 'Tổng quan Học tập', 'match' => ['student/dashboard.php']],
                    ]],
                    ['id' => 'student-learning', 'label' => 'Học tập & làm bài', 'icon' => 'bx-book-open', 'items' => [
                        ['url' => '../student/assignments.php', 'icon' => 'bx-book-open', 'label' => 'Bài tập theo Khóa học', 'match' => ['student/assignments.php', 'student/course.php', 'student/assignment.php', 'student/outstanding_submissions.php']],
                        ['url' => '../student/quizzes.php', 'icon' => 'bx-list-check', 'label' => 'Làm trắc nghiệm', 'match' => ['student/quizzes.php', 'student/quiz.php']],
                    ]],
                    ['id' => 'student-results', 'label' => 'Kết quả cá nhân', 'icon' => 'bx-trophy', 'items' => [
                        ['url' => '../student/achievements.php', 'icon' => 'bx-medal', 'label' => 'Thành tích của tôi', 'match' => ['student/achievements.php']],
                    ]],
                    ['id' => 'student-support', 'label' => 'Trợ giúp', 'icon' => 'bx-help-circle', 'items' => [
                        ['url' => '../student/tickets.php', 'icon' => 'bx-support', 'label' => 'Trung tâm Hỗ trợ', 'match' => ['student/tickets.php', 'student/ticket_detail.php']],
                    ]],
                ];
            }
            
            foreach ($menuGroups as $group) {
                $groupActive = false;
                foreach ($group['items'] as $item) {
                    if ($isMenuActive($item)) { $groupActive = true; break; }
                }
                echo '<section class="sidebar-group' . ($groupActive ? ' has-active' : ' collapsed') . '" data-sidebar-group="' . htmlspecialchars($group['id']) . '">';
                echo '<button type="button" class="sidebar-group-toggle" aria-expanded="' . ($groupActive ? 'true' : 'false') . '"><i class="bx ' . htmlspecialchars($group['icon']) . '"></i><span>' . htmlspecialchars($group['label']) . '</span><i class="bx bx-chevron-down group-chevron"></i></button>';
                echo '<div class="sidebar-group-items"><div class="sidebar-group-items-inner">';
                foreach ($group['items'] as $menu) {
                    $isActive = $isMenuActive($menu) ? 'active' : '';
                    echo '<a href="' . htmlspecialchars($menu['url']) . '" class="menu-item ' . $isActive . '"><i class="bx ' . htmlspecialchars($menu['icon']) . '"></i> ' . htmlspecialchars($menu['label']) . '</a>';
                }
                echo '</div></div></section>';
            }
            ?>
        </div>
        
        <div class="sidebar-footer">
            <div class="user-menu-container">
                <div class="user-info">
                    <?php if ($user_avatar): ?>
                        <img
                            src="<?php echo htmlspecialchars($user_avatar, ENT_QUOTES, 'UTF-8'); ?>"
                            alt="Ảnh đại diện của <?php echo htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8'); ?>"
                            class="user-avatar"
                            referrerpolicy="no-referrer"
                            onerror="this.hidden=true;this.nextElementSibling.hidden=false"
                        >
                        <span class="user-avatar-fallback" hidden><i class='bx bxs-user-circle'></i></span>
                    <?php else: ?>
                        <span class="user-avatar-fallback"><i class='bx bxs-user-circle'></i></span>
                    <?php endif; ?>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
                        <div class="user-role"><?php echo htmlspecialchars($roleLabel); ?></div>
                    </div>
                </div>
                <div class="user-dropdown">
                    <a href="../account/profile.php"><i class='bx bx-edit'></i> Hồ sơ & mật khẩu</a>
                    <a href="../includes/logout.php" class="text-danger"><i class='bx bx-log-out'></i> Đăng xuất</a>
                </div>
            </div>
        </div>
    </aside>
    <script>
        // Chạy đồng bộ ngay sau khi sidebar được parse để cập nhật trạng thái từ localStorage.
        // Điều này giúp ngăn chặn hoàn toàn hiện tượng FOUC (giật/nhảy menu khi tải trang).
        (function() {
            document.querySelectorAll('.sidebar-group').forEach(group => {
                const key = 'lms_sidebar_group_' + group.getAttribute('data-sidebar-group');
                const saved = localStorage.getItem(key);
                if (saved !== null) {
                    const isCollapsed = saved === 'collapsed';
                    group.classList.toggle('collapsed', isCollapsed);
                    const toggle = group.querySelector('.sidebar-group-toggle');
                    if (toggle) toggle.setAttribute('aria-expanded', String(!isCollapsed));
                }
            });
        })();

        // Giữ nguyên vị trí cuộn của menu khi chuyển sang trang khác.
        // Dùng sessionStorage để mỗi tab trình duyệt có vị trí riêng và tự xóa khi đóng tab.
        (function () {
            const storageKey = 'lms_sidebar_menu_scroll_top';
            const sidebarMenu = document.querySelector('.sidebar-menu');
            if (!sidebarMenu) return;

            const savedPosition = Number(sessionStorage.getItem(storageKey));
            const restorePosition = function () {
                if (Number.isFinite(savedPosition) && savedPosition > 0) {
                    sidebarMenu.scrollTop = savedPosition;
                }
            };

            // Khôi phục ngay và lặp lại sau một khung hình để không bị ảnh hưởng
            // bởi animation/mở nhóm menu khi trang vừa tải xong.
            restorePosition();
            requestAnimationFrame(restorePosition);
            window.addEventListener('load', restorePosition, { once: true });

            const savePosition = function () {
                sessionStorage.setItem(storageKey, String(sidebarMenu.scrollTop));
            };

            sidebarMenu.addEventListener('scroll', savePosition, { passive: true });
            window.addEventListener('pagehide', savePosition);
            document.querySelectorAll('.sidebar-menu a').forEach(function (link) {
                link.addEventListener('click', savePosition);
            });
        })();
    </script>
    <!-- Main Content -->
    <main class="main-content" id="main-content">
        <div class="top-navbar">
            <div style="display: flex; align-items: center; gap: 15px;">
                <button id="sidebar-toggle-open" style="background: transparent; border: none; color: var(--text-main); font-size: 24px; cursor: pointer; align-items: center; justify-content: center; padding: 5px; border-radius: 4px; transition: 0.3s;" onmouseover="this.style.background='rgba(255,255,255,0.1)'" onmouseout="this.style.background='transparent'">
                    <i class='bx bx-menu'></i>
                </button>
                <h2><?php echo htmlspecialchars($page_title); ?></h2>
            </div>
            <div style="display:flex;align-items:center;gap:10px;">
                <a href="../account/notifications.php" class="theme-toggle" aria-label="Thông báo" style="position:relative;text-decoration:none;">
                    <i class='bx bx-bell'></i>
                    <span data-notif-badge style="position:absolute;right:-5px;top:-6px;min-width:18px;height:18px;padding:0 4px;border-radius:999px;background:var(--danger);color:#fff;font-size:11px;display:<?php echo $unreadNotifications > 0 ? 'grid' : 'none'; ?>;place-items:center;">
                        <?php echo $unreadNotifications > 99 ? '99+' : $unreadNotifications; ?>
                    </span>
                </a>
                <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Tùy chỉnh giao diện" aria-expanded="false">
                    <i class='bx bx-palette'></i>
                </button>
            </div>
            <section class="theme-panel" id="theme-panel" hidden aria-label="Tùy chỉnh giao diện">
                <div class="theme-panel-header">
                    <strong><i class='bx bx-palette'></i> Chủ đề giao diện</strong>
                    <button type="button" class="theme-close" id="theme-close" aria-label="Đóng"><i class='bx bx-x'></i></button>
                </div>
                <span class="theme-label">Chế độ hiển thị</span>
                <div class="theme-modes">
                    <button type="button" class="theme-mode" data-theme-mode="dark"><i class='bx bx-moon'></i> Tối</button>
                    <button type="button" class="theme-mode" data-theme-mode="light"><i class='bx bx-sun'></i> Sáng</button>
                    <button type="button" class="theme-mode" data-theme-mode="ocean"><i class='bx bxs-droplet'></i> Đại dương</button>
                    <button type="button" class="theme-mode" data-theme-mode="forest"><i class='bx bx-leaf'></i> Rừng xanh</button>
                    <button type="button" class="theme-mode" data-theme-mode="violet"><i class='bx bx-star'></i> Tím đêm</button>
                    <button type="button" class="theme-mode" data-theme-mode="sunset"><i class='bx bx-sun'></i> Hoàng hôn</button>
                    <button type="button" class="theme-mode" data-theme-mode="universe"><i class='bx bx-planet'></i> Vũ trụ</button>
                    <button type="button" class="theme-mode" data-theme-mode="system"><i class='bx bx-desktop'></i> Hệ thống</button>
                </div>
                <span class="theme-label">Màu chủ đạo</span>
                <div class="theme-colors" id="theme-colors">
                    <button type="button" class="theme-swatch" data-theme-color="#6366f1" style="background:#6366f1" aria-label="Tím"></button>
                    <button type="button" class="theme-swatch" data-theme-color="#f43f5e" style="background:#f43f5e" aria-label="Hồng đỏ"></button>
                    <button type="button" class="theme-swatch" data-theme-color="#0ea5e9" style="background:#0ea5e9" aria-label="Xanh dương"></button>
                    <button type="button" class="theme-swatch" data-theme-color="#10b981" style="background:#10b981" aria-label="Xanh lá"></button>
                    <button type="button" class="theme-swatch" data-theme-color="#f59e0b" style="background:#f59e0b" aria-label="Cam"></button>
                    <input type="color" class="theme-custom-color" id="theme-custom-color" aria-label="Chọn màu khác">
                </div>
                <button type="button" class="theme-reset" id="theme-reset"><i class='bx bx-reset'></i> Khôi phục mặc định</button>
            </section>
        </div>
        <div class="page-content">
