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

function mask_email($email) {
    $email = (string)$email;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $email;
    }

    [$name, $domain] = explode('@', $email, 2);

    if (strlen($name) <= 2) {
        $masked_name = substr($name, 0, 1) . str_repeat('*', max(strlen($name) - 1, 1));
    } else {
        $masked_name = substr($name, 0, 2) . str_repeat('*', max(strlen($name) - 2, 1));
    }

    return $masked_name . '@' . $domain;
}

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$current_email = $_SESSION['user'];

/* =========================
   CHECK FEEDBACK TABLE EXISTS
========================= */
$feedback_table_exists = false;
$check_feedback_table = mysqli_query($conn, "SHOW TABLES LIKE 'feedbacks'");
if ($check_feedback_table && mysqli_num_rows($check_feedback_table) > 0) {
    $feedback_table_exists = true;
}

/* =========================
   CURRENT USER INFO
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
   MY FEEDBACK-ELIGIBLE CLAIMS
========================= */
$my_feedback_result = false;
$my_feedback_total = 0;

if ($feedback_table_exists) {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT c.id AS claim_id,
                c.status AS claim_status,
                c.created_at AS claim_date,
                c.approved_at AS claim_approved_at,
                i.title,
                i.description,
                i.type,
                i.status AS item_status,
                i.returned_at,
                f.id AS feedback_id,
                f.item_received,
                f.system_helpful,
                f.created_at AS feedback_date
         FROM claims c
         LEFT JOIN items i ON c.item_id = i.id
         LEFT JOIN feedbacks f ON f.claim_id = c.id
         WHERE c.user_email = ?
           AND c.status = 'approved'
           AND i.status = 'returned'
         ORDER BY c.id DESC"
    );
    mysqli_stmt_bind_param($stmt, "s", $current_email);
    mysqli_stmt_execute($stmt);
    $my_feedback_result = mysqli_stmt_get_result($stmt);
    $my_feedback_total = mysqli_num_rows($my_feedback_result);
}

/* =========================
   ALL SUBMITTED FEEDBACKS
========================= */
$all_feedback_result = false;
$all_feedback_total = 0;

if ($feedback_table_exists) {
    $all_stmt = mysqli_prepare(
        $conn,
        "SELECT f.id,
                f.user_email,
                f.item_received,
                f.system_helpful,
                f.comments,
                f.created_at,
                c.id AS claim_id,
                i.title AS item_title,
                i.type AS item_type,
                u.name AS user_name
         FROM feedbacks f
         LEFT JOIN claims c ON f.claim_id = c.id
         LEFT JOIN items i ON c.item_id = i.id
         LEFT JOIN users u ON u.email = f.user_email
         ORDER BY f.id DESC"
    );
    mysqli_stmt_execute($all_stmt);
    $all_feedback_result = mysqli_stmt_get_result($all_stmt);
    $all_feedback_total = mysqli_num_rows($all_feedback_result);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Feedback - Lost & Found</title>
    <style>
        * {
            box-sizing: border-box;
        }

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
            max-width: 1300px;
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

        .section {
            background: white;
            border-radius: 18px;
            padding: 22px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
            margin-bottom: 24px;
        }

        .section h2 {
            margin-top: 0;
            margin-bottom: 16px;
            font-size: 24px;
            color: #111827;
        }

        .section p.subtitle {
            margin-top: -6px;
            margin-bottom: 18px;
            color: #6b7280;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        th, td {
            padding: 13px 12px;
            border-bottom: 1px solid #edf0f5;
            text-align: left;
            vertical-align: top;
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

        .approved { background: #e7f5ff; color: #0b69a3; }
        .returned { background: #e8fff0; color: #117a37; }
        .lost { background: #f1e9ff; color: #6941c6; }
        .found { background: #e7fff6; color: #027a48; }
        .yes { background: #e8fff0; color: #117a37; }
        .no { background: #fff1f1; color: #b42318; }
        .given { background: #e7f5ff; color: #0b69a3; }
        .not-given { background: #fff4db; color: #9a6700; }

        .action-link {
            text-decoration: none;
            font-weight: 700;
            color: #2563eb;
        }

        .action-link:hover {
            text-decoration: underline;
        }

        .empty {
            text-align: center;
            padding: 28px;
            color: #6b7280;
            font-weight: 600;
        }

        .feedback-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(310px, 1fr));
            gap: 18px;
        }

        .feedback-card {
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 18px;
            background: #ffffff;
        }

        .feedback-card h3 {
            margin: 0 0 8px;
            font-size: 20px;
            color: #111827;
        }

        .meta-line {
            margin-bottom: 8px;
            color: #4b5563;
            font-size: 14px;
        }

        .badge-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 12px 0;
        }

        .comment-box {
            margin-top: 12px;
            padding: 12px 14px;
            border-radius: 12px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            color: #374151;
            min-height: 70px;
            line-height: 1.5;
        }

        .small-muted {
            color: #6b7280;
            font-size: 13px;
        }

        .warning-box {
            background: #fff8db;
            color: #9a6700;
            border: 1px solid #f3df95;
            padding: 14px 16px;
            border-radius: 12px;
            font-weight: 600;
        }

        @media (max-width: 900px) {
            .hero h1 {
                font-size: 26px;
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
            <h1>Feedback Center</h1>
            <p>Submit feedback for completed claims and view all submitted feedback from users.</p>
        </div>

        <div class="top-actions">
            <div class="count-box">
                Logged in as: <?php echo e($user['name']); ?> (<?php echo e($user['email']); ?>)
            </div>
            <a href="dashboard.php" class="btn btn-light">⬅ Back to Dashboard</a>
        </div>

        <?php if (!$feedback_table_exists): ?>
            <div class="section">
                <div class="warning-box">
                    Feedback table not found. Please create the <strong>feedbacks</strong> table first.
                </div>
            </div>
        <?php else: ?>

        <div class="section">
            <h2>My Feedback Actions</h2>
            <p class="subtitle">These are your approved and returned claims where you can give or update feedback.</p>

            <div class="count-box" style="margin-bottom: 15px;">
                Total Feedback-Eligible Claims: <?php echo $my_feedback_total; ?>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Claim ID</th>
                            <th>Item Title</th>
                            <th>Description</th>
                            <th>Type</th>
                            <th>Item Status</th>
                            <th>Claim Status</th>
                            <th>Claim Approved At</th>
                            <th>Returned At</th>
                            <th>Feedback Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($my_feedback_total > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($my_feedback_result)): ?>
                            <tr>
                                <td>#<?php echo e($row['claim_id']); ?></td>
                                <td><strong><?php echo e($row['title'] ?? 'Deleted Item'); ?></strong></td>
                                <td><?php echo e($row['description'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php if (!empty($row['type'])): ?>
                                        <span class="badge <?php echo e($row['type']); ?>">
                                            <?php echo ucfirst(e($row['type'])); ?>
                                        </span>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge returned">
                                        <?php echo ucfirst(e($row['item_status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge approved">
                                        <?php echo ucfirst(e($row['claim_status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo e($row['claim_approved_at'] ?: 'N/A'); ?></td>
                                <td><?php echo e($row['returned_at'] ?: 'N/A'); ?></td>
                                <td>
                                    <?php if (!empty($row['feedback_id'])): ?>
                                        <span class="badge given">Given</span>
                                    <?php else: ?>
                                        <span class="badge not-given">Not Given</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a class="action-link" href="feedback.php?claim_id=<?php echo (int)$row['claim_id']; ?>">
                                        <?php echo !empty($row['feedback_id']) ? 'Edit Feedback' : 'Give Feedback'; ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" class="empty">No completed claims available for feedback yet.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="section">
            <h2>All Submitted Feedback</h2>
            <p class="subtitle">Everyone’s submitted feedback is shown below.</p>

            <div class="count-box" style="margin-bottom: 18px;">
                Total Submitted Feedback: <?php echo $all_feedback_total; ?>
            </div>

            <?php if ($all_feedback_total > 0): ?>
                <div class="feedback-grid">
                    <?php while ($fb = mysqli_fetch_assoc($all_feedback_result)): ?>
                        <div class="feedback-card">
                            <h3><?php echo e($fb['item_title'] ?? 'Deleted Item'); ?></h3>

                            <div class="meta-line">
                                <strong>User:</strong>
                                <?php echo e($fb['user_name'] ?: 'Unknown User'); ?>
                                (<?php echo e(mask_email($fb['user_email'])); ?>)
                            </div>

                            <div class="meta-line">
                                <strong>Claim ID:</strong> #<?php echo e($fb['claim_id'] ?? 'N/A'); ?>
                            </div>

                            <div class="meta-line">
                                <strong>Item Type:</strong>
                                <?php echo e($fb['item_type'] ? ucfirst($fb['item_type']) : 'N/A'); ?>
                            </div>

                            <div class="meta-line">
                                <strong>Submitted At:</strong>
                                <?php echo e($fb['created_at']); ?>
                            </div>

                            <div class="badge-row">
                                <span class="badge <?php echo e($fb['item_received']); ?>">
                                    Item Received: <?php echo ucfirst(e($fb['item_received'])); ?>
                                </span>

                                <span class="badge <?php echo e($fb['system_helpful']); ?>">
                                    Helpful: <?php echo ucfirst(e($fb['system_helpful'])); ?>
                                </span>
                            </div>

                            <div class="comment-box">
                                <?php echo $fb['comments'] !== '' ? e($fb['comments']) : 'No additional comment provided.'; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty">No feedback submitted yet.</div>
            <?php endif; ?>
        </div>

        <?php endif; ?>

    </div>
</div>

</body>
</html>
