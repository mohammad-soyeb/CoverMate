<?php
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = mysqli_connect("localhost","root","","lost_found");

if(!$conn){
    die("Connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");

if(!isset($_SESSION['user'])){
    header("Location: login.php");
    exit();
}

$email = $_SESSION['user'];

function e($str){
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

// Get current user
$stmt = mysqli_prepare($conn, "SELECT id, name, email, role FROM users WHERE email = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "s", $email);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);

if(!$user){
    session_destroy();
    header("Location: login.php");
    exit();
}

// My reported items count
$stmt1 = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM items WHERE user_email = ?");
mysqli_stmt_bind_param($stmt1, "s", $email);
mysqli_stmt_execute($stmt1);
$res1 = mysqli_stmt_get_result($stmt1);
$my_items_count = mysqli_fetch_assoc($res1)['total'] ?? 0;

// My claims count
$stmt2 = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM claims WHERE user_email = ?");
mysqli_stmt_bind_param($stmt2, "s", $email);
mysqli_stmt_execute($stmt2);
$res2 = mysqli_stmt_get_result($stmt2);
$my_claims_count = mysqli_fetch_assoc($res2)['total'] ?? 0;

// My pending reports
$stmt3 = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM items WHERE user_email = ? AND status = 'pending'");
mysqli_stmt_bind_param($stmt3, "s", $email);
mysqli_stmt_execute($stmt3);
$res3 = mysqli_stmt_get_result($stmt3);
$pending_reports = mysqli_fetch_assoc($res3)['total'] ?? 0;

// My approved claims
$stmt4 = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM claims WHERE user_email = ? AND status = 'approved'");
mysqli_stmt_bind_param($stmt4, "s", $email);
mysqli_stmt_execute($stmt4);
$res4 = mysqli_stmt_get_result($stmt4);
$approved_claims = mysqli_fetch_assoc($res4)['total'] ?? 0;

// My messageable chats count (approved claims where user is owner or claimant)
$stmt5 = mysqli_prepare(
    $conn,
    "SELECT COUNT(*) AS total
     FROM claims c
     INNER JOIN items i ON c.item_id = i.id
     WHERE c.status = 'approved'
       AND (c.user_email = ? OR i.user_email = ?)"
);
mysqli_stmt_bind_param($stmt5, "ss", $email, $email);
mysqli_stmt_execute($stmt5);
$res5 = mysqli_stmt_get_result($stmt5);
$my_messages_count = mysqli_fetch_assoc($res5)['total'] ?? 0;

// Recent reported items
$stmt6 = mysqli_prepare($conn, "SELECT title, type, status, created_at FROM items WHERE user_email = ? ORDER BY id DESC LIMIT 5");
mysqli_stmt_bind_param($stmt6, "s", $email);
mysqli_stmt_execute($stmt6);
$recent_items = mysqli_stmt_get_result($stmt6);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Dashboard - Lost & Found</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: #f4f7fb;
            color: #1f2937;
        }

        .layout {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background: linear-gradient(180deg, #1e293b, #0f172a);
            color: white;
            padding: 24px 14px;
            box-shadow: 4px 0 18px rgba(0,0,0,0.08);
        }

        .brand {
            font-size: 24px;
            font-weight: 700;
            text-align: center;
            margin-bottom: 30px;
        }

        .nav-links a {
            display: block;
            color: white;
            text-decoration: none;
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 10px;
            font-weight: 600;
            transition: 0.2s ease;
        }

        .nav-links a:hover {
            background: rgba(255,255,255,0.12);
            transform: translateX(3px);
        }

        .main {
            flex: 1;
            padding: 28px;
        }

        .hero {
            background: linear-gradient(135deg, #4f46e5, #2563eb);
            color: white;
            border-radius: 20px;
            padding: 28px;
            box-shadow: 0 14px 35px rgba(37, 99, 235, 0.20);
            margin-bottom: 24px;
        }

        .hero h1 {
            margin: 0 0 10px;
            font-size: 32px;
        }

        .hero p {
            margin: 0;
            font-size: 16px;
            opacity: 0.95;
        }

        .top-grid {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }

        .card {
            background: white;
            border-radius: 18px;
            padding: 22px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.07);
        }

        .card h2 {
            margin-top: 0;
            margin-bottom: 16px;
            font-size: 22px;
            color: #111827;
        }

        .info-row {
            margin-bottom: 10px;
            font-size: 16px;
        }

        .label {
            font-weight: 700;
            color: #374151;
        }

        .quick-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .quick-actions a {
            text-decoration: none;
            padding: 12px 16px;
            border-radius: 10px;
            font-weight: 700;
            transition: 0.2s ease;
            display: inline-block;
        }

        .btn-primary {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(37, 99, 235, 0.20);
        }

        .btn-light {
            background: #eef2ff;
            color: #1d4ed8;
        }

        .btn-light:hover {
            background: #dfe7ff;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            border-radius: 18px;
            padding: 20px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.07);
        }

        .stat-title {
            font-size: 15px;
            color: #6b7280;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .stat-value {
            font-size: 30px;
            font-weight: 800;
            color: #111827;
        }

        .section-title {
            margin: 0 0 16px;
            font-size: 24px;
            color: #111827;
        }

        .recent-table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
        }

        th, td {
            text-align: left;
            padding: 13px 12px;
            border-bottom: 1px solid #edf0f5;
        }

        th {
            background: #f8faff;
            color: #374151;
            font-size: 14px;
        }

        .badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
        }

        .pending { background: #fff4db; color: #9a6700; }
        .approved { background: #e7f5ff; color: #0b69a3; }
        .rejected { background: #ffe7e7; color: #b42318; }
        .returned { background: #e8fff0; color: #117a37; }
        .lost { background: #f1e9ff; color: #6941c6; }
        .found { background: #e7fff6; color: #027a48; }

        .empty {
            text-align: center;
            padding: 20px;
            color: #6b7280;
            font-weight: 600;
        }

        .small-text {
            color: #6b7280;
            font-size: 14px;
        }

        @media (max-width: 960px) {
            .layout { flex-direction: column; }
            .sidebar { width: 100%; }
            .top-grid { grid-template-columns: 1fr; }
            .main { padding: 18px; }
            .hero h1 { font-size: 26px; }
        }
    </style>
    <link rel="stylesheet" href="ui-enhancements.css">
    <script src="ui-enhancements.js" defer></script>
</head>
<body>

<div class="layout">
    <aside class="sidebar">
        <div class="brand">Lost & Found</div>

        <div class="nav-links">
            <a href="dashboard.php">🏠 Dashboard</a>
            <a href="profile.php">👤 My Profile</a>
            <a href="report.php">📦 Report Item</a>
            <a href="search.php">🔍 Search Items</a>
            <a href="my_messages.php">💬 My Messages</a>
            <a href="my_feedback.php">📝 Feedback</a>

            <?php if($user['role'] === 'admin'){ ?>
                <a href="admin.php">🛠 Admin Panel</a>
            <?php } ?>

            <a href="logout.php">🚪 Logout</a>
        </div>
    </aside>

    <main class="main">
        <div class="hero">
            <h1>Welcome, <?php echo e($user['name']); ?> 👋</h1>
            <p>Manage your lost and found activities from one place. Report items, search posts, track claims, and chat after approval.</p>
        </div>

        <div class="top-grid">
            <div class="card">
                <h2>My Information</h2>
                <div class="info-row"><span class="label">Name:</span> <?php echo e($user['name']); ?></div>
                <div class="info-row"><span class="label">Email:</span> <?php echo e($user['email']); ?></div>
                <div class="info-row"><span class="label">Role:</span> <?php echo e($user['role']); ?></div>
            </div>

            <div class="card">
                <h2>Quick Actions</h2>
                <div class="quick-actions">
                    <a href="profile.php" class="btn-light">👤 View Profile</a>
                    <a href="report.php" class="btn-primary">➕ Report Item</a>
                    <a href="search.php" class="btn-light">🔍 Search Items</a>
                    <a href="my_messages.php" class="btn-light">💬 My Messages</a>
                    <a href="my_feedback.php" class="btn-light">📝 Feedback</a>

                    <?php if($user['role'] === 'admin'){ ?>
                        <a href="admin.php" class="btn-primary">🛠 Admin Panel</a>
                    <?php } ?>
                </div>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-title">My Reported Items</div>
                <div class="stat-value"><?php echo e($my_items_count); ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-title">My Claims</div>
                <div class="stat-value"><?php echo e($my_claims_count); ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-title">Pending Reports</div>
                <div class="stat-value"><?php echo e($pending_reports); ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-title">Approved Claims</div>
                <div class="stat-value"><?php echo e($approved_claims); ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-title">My Chats</div>
                <div class="stat-value"><?php echo e($my_messages_count); ?></div>
            </div>
        </div>

        <div class="card">
            <h2 class="section-title">Recent Reported Items</h2>
            <div class="recent-table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Reported At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(mysqli_num_rows($recent_items) > 0): ?>
                            <?php while($item = mysqli_fetch_assoc($recent_items)): ?>
                                <tr>
                                    <td><strong><?php echo e($item['title']); ?></strong></td>
                                    <td>
                                        <span class="badge <?php echo e($item['type']); ?>">
                                            <?php echo ucfirst(e($item['type'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo e($item['status']); ?>">
                                            <?php echo ucfirst(e($item['status'])); ?>
                                        </span>
                                    </td>
                                    <td class="small-text"><?php echo e($item['created_at']); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="empty">You have not reported any items yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

</body>
</html>
