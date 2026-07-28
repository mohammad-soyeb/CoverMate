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
$msg = "";
$msg_type = "";

$claim_id = isset($_GET['claim_id']) ? (int)$_GET['claim_id'] : 0;

if ($claim_id <= 0) {
    die("Invalid claim ID.");
}

/* =========================
   GET CURRENT USER
========================= */
$current_user_stmt = mysqli_prepare(
    $conn,
    "SELECT id, name, email, role
     FROM users
     WHERE email = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($current_user_stmt, "s", $current_email);
mysqli_stmt_execute($current_user_stmt);
$current_user_result = mysqli_stmt_get_result($current_user_stmt);
$current_user = mysqli_fetch_assoc($current_user_result);

if (!$current_user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$current_user_name = $current_user['name'] ?? $current_email;
$is_admin = (($current_user['role'] ?? '') === 'admin');

/* =========================
   ACCESS:
   pending or approved claim
   owner / claimer / admin
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
        i.user_email AS owner_email,
        owner.name AS owner_name,
        claimer.name AS claimer_name
     FROM claims c
     INNER JOIN items i ON c.item_id = i.id
     LEFT JOIN users owner ON owner.email = i.user_email
     LEFT JOIN users claimer ON claimer.email = c.user_email
     WHERE c.id = ?
       AND c.status IN ('pending', 'approved')
       AND (i.user_email = ? OR c.user_email = ? OR ? = 1)
     LIMIT 1"
);
$admin_flag = $is_admin ? 1 : 0;
mysqli_stmt_bind_param($stmt, "issi", $claim_id, $current_email, $current_email, $admin_flag);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$chat = mysqli_fetch_assoc($result);

if (!$chat) {
    die("Access denied.");
}

/* =========================
   OTHER USER NAME
========================= */
if ($current_email === $chat['owner_email']) {
    $other_name = $chat['claimer_name'] ?: $chat['claimer_email'];
} elseif ($current_email === $chat['claimer_email']) {
    $other_name = $chat['owner_name'] ?: $chat['owner_email'];
} else {
    $other_name = "Owner and Claimant";
}

/* =========================
   SEND MESSAGE
   Admin can also send if needed
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $message = trim($_POST['message'] ?? '');

    if ($message === '') {
        $msg = "❌ Message cannot be empty.";
        $msg_type = "error";
    } else {
        $insert = mysqli_prepare(
            $conn,
            "INSERT INTO messages (claim_id, sender_email, message)
             VALUES (?, ?, ?)"
        );
        mysqli_stmt_bind_param($insert, "iss", $claim_id, $current_email, $message);

        if (mysqli_stmt_execute($insert)) {
            header("Location: messages.php?claim_id=" . $claim_id);
            exit();
        } else {
            $msg = "❌ Failed to send message.";
            $msg_type = "error";
        }
    }
}

/* =========================
   LOAD MESSAGES
========================= */
$msg_stmt = mysqli_prepare(
    $conn,
    "SELECT m.id, m.sender_email, m.message, m.created_at, u.name AS sender_name, u.role AS sender_role
     FROM messages m
     LEFT JOIN users u ON u.email = m.sender_email
     WHERE m.claim_id = ?
     ORDER BY m.id ASC"
);
mysqli_stmt_bind_param($msg_stmt, "i", $claim_id);
mysqli_stmt_execute($msg_stmt);
$messages_result = mysqli_stmt_get_result($msg_stmt);
$total_messages = mysqli_num_rows($messages_result);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat - Lost & Found</title>
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
            padding: 24px 15px;
        }
        .container {
            max-width: 980px;
            margin: 0 auto;
        }
        .hero {
            background: linear-gradient(135deg, #4f46e5, #2563eb);
            color: white;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 14px 35px rgba(37, 99, 235, 0.20);
            margin-bottom: 20px;
        }
        .hero h1 {
            margin: 0 0 8px;
            font-size: 28px;
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
        .chat-card {
            background: white;
            border-radius: 18px;
            padding: 20px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
            margin-bottom: 18px;
        }
        .chat-info {
            margin-bottom: 16px;
            color: #4b5563;
            line-height: 1.7;
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
        .msg {
            margin-bottom: 14px;
            padding: 12px 14px;
            border-radius: 10px;
            font-weight: 600;
        }
        .success {
            background: #e8fff0;
            color: #117a37;
            border: 1px solid #b6f0ca;
        }
        .error {
            background: #fff1f1;
            color: #b42318;
            border: 1px solid #ffcdcd;
        }
        .messages-box {
            background: white;
            border-radius: 18px;
            padding: 22px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
            min-height: 420px;
            margin-bottom: 18px;
        }
        .message-row {
            display: flex;
            width: 100%;
            margin-bottom: 16px;
        }
        .message-row.mine {
            justify-content: flex-end;
        }
        .message-row.other {
            justify-content: flex-start;
        }
        .message-block {
            max-width: 72%;
            display: flex;
            flex-direction: column;
        }
        .message-row.mine .message-block {
            align-items: flex-end;
        }
        .message-row.other .message-block {
            align-items: flex-start;
        }
        .sender-name {
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 6px;
            color: #4b5563;
        }
        .bubble {
            padding: 12px 14px;
            border-radius: 16px;
            line-height: 1.6;
            word-wrap: break-word;
            white-space: pre-wrap;
        }
        .message-row.mine .bubble {
            background: #2563eb;
            color: white;
            border-bottom-right-radius: 4px;
        }
        .message-row.other .bubble {
            background: #f3f4f6;
            color: #111827;
            border-bottom-left-radius: 4px;
        }
        .time {
            margin-top: 6px;
            font-size: 12px;
            color: #6b7280;
        }
        .empty {
            text-align: center;
            padding: 40px 20px;
            color: #6b7280;
            font-weight: 600;
        }
        .send-card {
            background: white;
            border-radius: 18px;
            padding: 20px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
        }
        textarea {
            width: 100%;
            min-height: 110px;
            padding: 14px;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            font-size: 15px;
            resize: vertical;
            outline: none;
            margin-bottom: 14px;
        }
        textarea:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
        }
        .send-btn {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: white;
        }
        @media (max-width: 768px) {
            .message-block {
                max-width: 88%;
            }
        }
    </style>
    <link rel="stylesheet" href="ui-enhancements.css">
    <script src="ui-enhancements.js" defer></script>
</head>
<body>

<div class="wrapper">
    <div class="container">

        <div class="hero">
            <h1>Chat</h1>
            <p>
                <?php if ($is_admin): ?>
                    Admin view of claim conversation.
                <?php else: ?>
                    Talk with <?php echo e($other_name); ?> before final admin approval.
                <?php endif; ?>
            </p>
        </div>

        <div class="top-actions">
            <a href="<?php echo $is_admin ? 'admin.php' : 'my_messages.php'; ?>" class="btn btn-light">⬅ Back</a>
            <div><strong>Total Messages:</strong> <?php echo $total_messages; ?></div>
        </div>

        <div class="chat-card">
            <div class="chat-info">
                <strong>Item:</strong> <?php echo e($chat['title']); ?><br>
                <strong>Type:</strong> <?php echo ucfirst(e($chat['type'])); ?><br>
                <strong>Claim Status:</strong>
                <span class="badge <?php echo e($chat['claim_status']); ?>">
                    <?php echo ucfirst(e($chat['claim_status'])); ?>
                </span><br><br>
                <strong>Owner:</strong> <?php echo e($chat['owner_name'] ?: $chat['owner_email']); ?><br>
                <strong>Claimant:</strong> <?php echo e($chat['claimer_name'] ?: $chat['claimer_email']); ?><br>
                <strong>Claim Submitted At:</strong> <?php echo e($chat['claim_created_at'] ?: 'N/A'); ?>
            </div>

            <?php if ($msg): ?>
                <div class="msg <?php echo e($msg_type); ?>">
                    <?php echo e($msg); ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="messages-box">
            <?php if ($total_messages > 0): ?>
                <?php while ($m = mysqli_fetch_assoc($messages_result)): ?>
                    <?php
                        $isMine = ($m['sender_email'] === $current_email);
                        $role_suffix = (($m['sender_role'] ?? '') === 'admin') ? ' (Admin)' : '';
                        $sender_label = ($m['sender_name'] ?: $m['sender_email']) . $role_suffix;
                    ?>
                    <div class="message-row <?php echo $isMine ? 'mine' : 'other'; ?>">
                        <div class="message-block">
                            <div class="sender-name"><?php echo e($sender_label); ?></div>
                            <div class="bubble"><?php echo nl2br(e($m['message'])); ?></div>
                            <div class="time"><?php echo e($m['created_at']); ?></div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty">No messages yet.</div>
            <?php endif; ?>
        </div>

        <div class="send-card">
            <form method="POST">
                <textarea name="message" placeholder="Write your message here..." required></textarea>
                <button type="submit" name="send_message" class="btn send-btn">Send Message</button>
            </form>
        </div>

    </div>
</div>

</body>
</html>
