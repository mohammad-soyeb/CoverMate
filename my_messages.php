<?php
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = mysqli_connect("localhost", "root", "", "lost_found");

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");

function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$current_email = $_SESSION['user'];

/* =========================
   CURRENT USER
========================= */
$user_stmt = mysqli_prepare(
    $conn,
    "SELECT id, name, email, role
     FROM users
     WHERE email = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($user_stmt, "s", $current_email);
mysqli_stmt_execute($user_stmt);
$user_result = mysqli_stmt_get_result($user_stmt);
$user = mysqli_fetch_assoc($user_result);

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

/* =========================
   CHATS AVAILABLE FOR:
   pending + approved claims
   only owner or claimer
========================= */
$stmt = mysqli_prepare(
    $conn,
    "SELECT
        c.id AS claim_id,
        c.status AS claim_status,
        c.user_email AS claimer_email,
        c.created_at AS claim_created_at,
        c.approved_at,
        i.id AS item_id,
        i.title,
        i.description,
        i.type,
        i.status AS item_status,
        i.user_email AS owner_email,
        owner.name AS owner_name,
        claimer.name AS claimer_name,
        (
            SELECT m.message
            FROM messages m
            WHERE m.claim_id = c.id
            ORDER BY m.id DESC
            LIMIT 1
        ) AS last_message,
        (
            SELECT m.created_at
            FROM messages m
            WHERE m.claim_id = c.id
            ORDER BY m.id DESC
            LIMIT 1
        ) AS last_message_time
     FROM claims c
     INNER JOIN items i ON c.item_id = i.id
     LEFT JOIN users owner ON owner.email = i.user_email
     LEFT JOIN users claimer ON claimer.email = c.user_email
     WHERE c.status IN ('pending', 'approved')
       AND (i.user_email = ? OR c.user_email = ?)
     ORDER BY c.id DESC"
);
mysqli_stmt_bind_param($stmt, "ss", $current_email, $current_email);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$total = mysqli_num_rows($result);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Messages - Lost & Found</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #eef2ff, #f8fbff);
            color: #1f2937;
        }
        .wrapper {
            min-height: 100vh;
            padding: 30px 15px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .hero {
            background: linear-gradient(135deg, #4f46e5, #2563eb);
            color: white;
            border-radius: 18px;
            padding: 28px;
            box-shadow: 0 14px 35px rgba(37, 99, 235, 0.20);
            margin-bottom: 22px;
        }
        .hero h1 {
            margin: 0 0 8px;
            font-size: 30px;
        }
        .hero p {
            margin: 0;
            opacity: 0.95;
        }
        .top-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }
        .count-box {
            font-weight: 700;
            color: #374151;
        }
        .btn {
            border: none;
            padding: 12px 18px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-light {
            background: #eef2ff;
            color: #1d4ed8;
        }
        .btn-light:hover {
            background: #dfe7ff;
        }
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(330px, 1fr));
            gap: 18px;
        }
        .chat-card {
            background: white;
            border-radius: 18px;
            padding: 22px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
        }
        .chat-card h3 {
            margin: 0 0 10px;
            font-size: 22px;
            color: #111827;
        }
        .meta-line {
            margin-bottom: 8px;
            color: #4b5563;
            font-size: 14px;
            line-height: 1.5;
        }
        .badge {
            display: inline-block;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 10px;
        }
        .lost { background: #f1e9ff; color: #6941c6; }
        .found { background: #e7fff6; color: #027a48; }
        .pending { background: #fff4db; color: #9a6700; }
        .approved { background: #e7f5ff; color: #0b69a3; }
        .preview {
            margin-top: 12px;
            padding: 12px 14px;
            border-radius: 12px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            color: #374151;
            min-height: 72px;
            line-height: 1.5;
        }
        .chat-link {
            display: inline-block;
            margin-top: 16px;
            text-decoration: none;
            font-weight: 700;
            color: #2563eb;
        }
        .chat-link:hover {
            text-decoration: underline;
        }
        .empty {
            background: white;
            border-radius: 18px;
            padding: 35px;
            text-align: center;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
            color: #6b7280;
            font-weight: 600;
        }
    </style>
    <link rel="stylesheet" href="ui-enhancements.css">
    <script src="ui-enhancements.js" defer></script>
</head>
<body>

<div class="wrapper">
    <div class="container">

        <div class="hero">
            <h1>My Messages</h1>
            <p>Once a claim is submitted, the poster and claimant can chat before final admin approval.</p>
        </div>

        <div class="top-actions">
            <div class="count-box">
                Total Available Chats: <?php echo $total; ?>
            </div>
            <a href="dashboard.php" class="btn btn-light">⬅ Back to Dashboard</a>
        </div>

        <?php if ($total > 0): ?>
            <div class="cards">
                <?php while ($row = mysqli_fetch_assoc($result)): ?>
                    <div class="chat-card">
                        <h3><?php echo e($row['title']); ?></h3>

                        <span class="badge <?php echo e($row['type']); ?>">
                            <?php echo ucfirst(e($row['type'])); ?>
                        </span>

                        <span class="badge <?php echo e($row['claim_status']); ?>">
                            <?php echo ucfirst(e($row['claim_status'])); ?>
                        </span>

                        <div class="meta-line">
                            <strong>Claim ID:</strong> #<?php echo e($row['claim_id']); ?>
                        </div>

                        <div class="meta-line">
                            <strong>Owner:</strong>
                            <?php echo e($row['owner_name'] ?: $row['owner_email']); ?>
                        </div>

                        <div class="meta-line">
                            <strong>Claimant:</strong>
                            <?php echo e($row['claimer_name'] ?: $row['claimer_email']); ?>
                        </div>

                        <div class="meta-line">
                            <strong>Claim Submitted At:</strong>
                            <?php echo e($row['claim_created_at'] ?: 'N/A'); ?>
                        </div>

                        <div class="preview">
                            <?php if (!empty($row['last_message'])): ?>
                                <strong>Last Message:</strong><br>
                                <?php echo e($row['last_message']); ?><br><br>
                                <small>Sent at: <?php echo e($row['last_message_time']); ?></small>
                            <?php else: ?>
                                No messages yet. Start the conversation.
                            <?php endif; ?>
                        </div>

                        <a class="chat-link" href="messages.php?claim_id=<?php echo (int)$row['claim_id']; ?>">
                            Open Chat →
                        </a>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="empty">
                No chats available yet.
            </div>
        <?php endif; ?>

    </div>
</div>

</body>
</html>
