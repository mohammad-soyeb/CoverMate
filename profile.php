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

function redirect_to($url) {
    header("Location: " . $url);
    exit();
}

if (!isset($_SESSION['user'])) {
    redirect_to("login.php");
}

$current_email = $_SESSION['user'];
$success = "";
$error = "";

/* =========================
   GET CURRENT USER
========================= */
$stmt = mysqli_prepare($conn, "SELECT id, name, email, password, role FROM users WHERE email = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "s", $current_email);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);

if (!$user) {
    session_destroy();
    redirect_to("login.php");
}

/* =========================
   UPDATE PROFILE
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['name'] ?? '');
    $new_email = trim($_POST['email'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');

    if ($name === '' || $new_email === '') {
        $error = "Name and email are required.";

    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";

    } elseif ($new_password !== '' && strlen($new_password) < 8) {
        $error = "Password must be at least 8 characters long.";

    } elseif ($new_password !== '' && !preg_match('/[A-Z]/', $new_password)) {
        $error = "Password must contain at least 1 uppercase letter.";

    } elseif ($new_password !== '' && !preg_match('/[a-z]/', $new_password)) {
        $error = "Password must contain at least 1 lowercase letter.";

    } elseif ($new_password !== '' && !preg_match('/[0-9]/', $new_password)) {
        $error = "Password must contain at least 1 number.";

    } elseif ($new_password !== '' && !preg_match('/[^a-zA-Z0-9]/', $new_password)) {
        $error = "Password must contain at least 1 special character.";

    } else {
        try {
            mysqli_begin_transaction($conn);

            if ($new_email !== $user['email']) {
                $check = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? AND id != ?");
                mysqli_stmt_bind_param($check, "si", $new_email, $user['id']);
                mysqli_stmt_execute($check);
                $check_result = mysqli_stmt_get_result($check);

                if (mysqli_num_rows($check_result) > 0) {
                    throw new Exception("This email is already in use.");
                }
            }

            if ($new_password !== '') {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                $update = mysqli_prepare($conn, "UPDATE users SET name = ?, email = ?, password = ? WHERE id = ?");
                mysqli_stmt_bind_param($update, "sssi", $name, $new_email, $hashed_password, $user['id']);
                mysqli_stmt_execute($update);
            } else {
                $update = mysqli_prepare($conn, "UPDATE users SET name = ?, email = ? WHERE id = ?");
                mysqli_stmt_bind_param($update, "ssi", $name, $new_email, $user['id']);
                mysqli_stmt_execute($update);
            }

            if ($new_email !== $user['email']) {
                $old_email = $user['email'];

                $update_items = mysqli_prepare($conn, "UPDATE items SET user_email = ? WHERE user_email = ?");
                mysqli_stmt_bind_param($update_items, "ss", $new_email, $old_email);
                mysqli_stmt_execute($update_items);

                $update_claims = mysqli_prepare($conn, "UPDATE claims SET user_email = ? WHERE user_email = ?");
                mysqli_stmt_bind_param($update_claims, "ss", $new_email, $old_email);
                mysqli_stmt_execute($update_claims);

                $check_feedback_table = mysqli_query($conn, "SHOW TABLES LIKE 'feedbacks'");
                if ($check_feedback_table && mysqli_num_rows($check_feedback_table) > 0) {
                    $update_feedbacks = mysqli_prepare($conn, "UPDATE feedbacks SET user_email = ? WHERE user_email = ?");
                    mysqli_stmt_bind_param($update_feedbacks, "ss", $new_email, $old_email);
                    mysqli_stmt_execute($update_feedbacks);
                }

                $_SESSION['user'] = $new_email;
                $current_email = $new_email;
            }

            mysqli_commit($conn);
            $success = "Profile updated successfully.";

            $stmt = mysqli_prepare($conn, "SELECT id, name, email, password, role FROM users WHERE email = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt, "s", $current_email);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $user = mysqli_fetch_assoc($result);

        } catch (Exception $ex) {
            mysqli_rollback($conn);
            $error = $ex->getMessage();
        }
    }
}

/* =========================
   CHECK FEEDBACK TABLE EXISTS
========================= */
$feedback_table_exists = false;
$check_feedback_table = mysqli_query($conn, "SHOW TABLES LIKE 'feedbacks'");
if ($check_feedback_table && mysqli_num_rows($check_feedback_table) > 0) {
    $feedback_table_exists = true;
}

/* =========================
   MY REPORTED ITEMS
========================= */
$my_items_stmt = mysqli_prepare(
    $conn,
    "SELECT id, title, description, image, type, status, created_at, approved_at, rejected_at, returned_at
     FROM items
     WHERE user_email = ?
     ORDER BY id DESC"
);
mysqli_stmt_bind_param($my_items_stmt, "s", $current_email);
mysqli_stmt_execute($my_items_stmt);
$my_items = mysqli_stmt_get_result($my_items_stmt);

/* =========================
   MY CLAIMS
========================= */
if ($feedback_table_exists) {
    $my_claims_stmt = mysqli_prepare(
        $conn,
        "SELECT c.id, c.status AS claim_status, c.created_at, c.approved_at, c.rejected_at,
                i.title, i.description, i.image, i.type, i.status AS item_status,
                f.id AS feedback_id, f.item_received, f.system_helpful
         FROM claims c
         LEFT JOIN items i ON c.item_id = i.id
         LEFT JOIN feedbacks f ON f.claim_id = c.id
         WHERE c.user_email = ?
         ORDER BY c.id DESC"
    );
} else {
    $my_claims_stmt = mysqli_prepare(
        $conn,
        "SELECT c.id, c.status AS claim_status, c.created_at, c.approved_at, c.rejected_at,
                i.title, i.description, i.image, i.type, i.status AS item_status,
                NULL AS feedback_id, NULL AS item_received, NULL AS system_helpful
         FROM claims c
         LEFT JOIN items i ON c.item_id = i.id
         WHERE c.user_email = ?
         ORDER BY c.id DESC"
    );
}
mysqli_stmt_bind_param($my_claims_stmt, "s", $current_email);
mysqli_stmt_execute($my_claims_stmt);
$my_claims = mysqli_stmt_get_result($my_claims_stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Profile - Lost & Found</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: #f4f7fb;
            color: #222;
        }

        .container {
            width: 95%;
            max-width: 1450px;
            margin: 25px auto;
        }

        .header {
            background: linear-gradient(135deg, #4f46e5, #2563eb);
            color: white;
            padding: 24px;
            border-radius: 16px;
            margin-bottom: 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
        }

        .header h1 {
            margin: 0 0 8px;
        }

        .nav-links {
            margin-top: 14px;
        }

        .nav-links a {
            display: inline-block;
            text-decoration: none;
            color: white;
            background: rgba(255,255,255,0.15);
            padding: 10px 14px;
            border-radius: 8px;
            margin-right: 10px;
            margin-top: 8px;
            font-weight: 600;
        }

        .nav-links a:hover {
            background: rgba(255,255,255,0.25);
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1.4fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .card {
            background: #fff;
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 10px 22px rgba(0,0,0,0.06);
        }

        .card h2 {
            margin-top: 0;
            margin-bottom: 18px;
            font-size: 24px;
        }

        .info-box p {
            margin: 10px 0;
            font-size: 16px;
        }

        .label {
            font-weight: 700;
            color: #444;
        }

        .message {
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 15px;
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

        input {
            width: 100%;
            padding: 12px 14px;
            border-radius: 8px;
            border: 1px solid #d3d8e2;
            margin-bottom: 14px;
            font-size: 15px;
        }

        .hint {
            margin-top: -6px;
            margin-bottom: 14px;
            font-size: 13px;
            color: #667085;
            line-height: 1.5;
        }

        button {
            background: #2563eb;
            color: white;
            border: none;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
        }

        button:hover {
            background: #1d4ed8;
        }

        .section {
            background: white;
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 10px 22px rgba(0,0,0,0.06);
            margin-bottom: 20px;
        }

        .section h2 {
            margin-top: 0;
            margin-bottom: 18px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1350px;
        }

        th, td {
            padding: 12px;
            border-bottom: 1px solid #edf0f5;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #f8faff;
            color: #444;
        }

        .thumb {
            width: 90px;
            height: 70px;
            object-fit: cover;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            background: #f8fafc;
        }

        .no-thumb {
            width: 90px;
            height: 70px;
            border-radius: 10px;
            border: 1px dashed #d1d5db;
            background: #f8fafc;
            color: #6b7280;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
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
            padding: 18px;
            color: #667085;
            font-weight: 600;
        }

        .small {
            font-size: 13px;
            color: #667085;
        }

        .feedback-link {
            text-decoration: none;
            font-weight: 700;
            color: #2563eb;
        }

        .feedback-link:hover {
            text-decoration: underline;
        }

        @media (max-width: 900px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <link rel="stylesheet" href="ui-enhancements.css">
    <script src="ui-enhancements.js" defer></script>
</head>
<body>

<div class="container">

    <div class="header">
        <h1>User Profile</h1>
        <p>Welcome, <?= e($user['name']); ?></p>

        <div class="nav-links">
            <a href="dashboard.php">🏠 Dashboard</a>
            <a href="report.php">➕ Report Item</a>
            <a href="search.php">🔍 Search Items</a>
            <a href="logout.php">🚪 Logout</a>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <h2>My Information</h2>

            <div class="info-box">
                <p><span class="label">Name:</span> <?= e($user['name']); ?></p>
                <p><span class="label">Email:</span> <?= e($user['email']); ?></p>
                <p><span class="label">Role:</span> <?= e($user['role']); ?></p>
            </div>
        </div>

        <div class="card">
            <h2>Edit Profile</h2>

            <?php if ($success): ?>
                <div class="message success"><?= e($success); ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="message error"><?= e($error); ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="text" name="name" placeholder="Enter your name" value="<?= e($user['name']); ?>" required>
                <input type="email" name="email" placeholder="Enter your email" value="<?= e($user['email']); ?>" required>
                <input type="password" name="new_password" placeholder="Enter new password (optional)">
                <div class="hint">
                    If you want to change password, it must be at least 8 characters and include:
                    1 uppercase letter, 1 lowercase letter, 1 number, and 1 special character.
                </div>
                <button type="submit" name="update_profile">Update Profile</button>
            </form>
        </div>
    </div>

    <div class="section">
        <h2>📦 My Reported Items</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Image</th>
                        <th>Title</th>
                        <th>Description</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Reported At</th>
                        <th>Approved At</th>
                        <th>Rejected At</th>
                        <th>Returned At</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($my_items) > 0): ?>
                    <?php while ($item = mysqli_fetch_assoc($my_items)): ?>
                        <tr>
                            <td>#<?= e($item['id']); ?></td>
                            <td>
                                <?php if (!empty($item['image']) && file_exists(__DIR__ . '/uploads/' . $item['image'])): ?>
                                    <img class="thumb" src="uploads/<?= e($item['image']); ?>" alt="Item Image">
                                <?php else: ?>
                                    <div class="no-thumb">No Image</div>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= e($item['title']); ?></strong></td>
                            <td><?= e($item['description']); ?></td>
                            <td>
                                <span class="badge <?= e($item['type']); ?>">
                                    <?= ucfirst(e($item['type'])); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= e($item['status']); ?>">
                                    <?= ucfirst(e($item['status'])); ?>
                                </span>
                            </td>
                            <td><?= e($item['created_at']); ?></td>
                            <td><?= e($item['approved_at'] ?: 'N/A'); ?></td>
                            <td><?= e($item['rejected_at'] ?: 'N/A'); ?></td>
                            <td><?= e($item['returned_at'] ?: 'N/A'); ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="10" class="empty">You have not reported any items yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="section">
        <h2>📥 My Claims</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Claim ID</th>
                        <th>Image</th>
                        <th>Item Title</th>
                        <th>Description</th>
                        <th>Type</th>
                        <th>Item Status</th>
                        <th>Claim Status</th>
                        <th>Claimed At</th>
                        <th>Approved At</th>
                        <th>Rejected At</th>
                        <th>Feedback</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($my_claims) > 0): ?>
                    <?php while ($claim = mysqli_fetch_assoc($my_claims)): ?>
                        <tr>
                            <td>#<?= e($claim['id']); ?></td>
                            <td>
                                <?php if (!empty($claim['image']) && file_exists(__DIR__ . '/uploads/' . $claim['image'])): ?>
                                    <img class="thumb" src="uploads/<?= e($claim['image']); ?>" alt="Item Image">
                                <?php else: ?>
                                    <div class="no-thumb">No Image</div>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= e($claim['title'] ?? 'Deleted Item'); ?></strong></td>
                            <td><?= e($claim['description'] ?? 'N/A'); ?></td>
                            <td>
                                <?php if (!empty($claim['type'])): ?>
                                    <span class="badge <?= e($claim['type']); ?>">
                                        <?= ucfirst(e($claim['type'])); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="small">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($claim['item_status'])): ?>
                                    <span class="badge <?= e($claim['item_status']); ?>">
                                        <?= ucfirst(e($claim['item_status'])); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="small">Deleted</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= e($claim['claim_status']); ?>">
                                    <?= ucfirst(e($claim['claim_status'])); ?>
                                </span>
                            </td>
                            <td><?= e($claim['created_at']); ?></td>
                            <td><?= e($claim['approved_at'] ?: 'N/A'); ?></td>
                            <td><?= e($claim['rejected_at'] ?: 'N/A'); ?></td>
                            <td>
                                <?php if ($feedback_table_exists): ?>
                                    <?php if (($claim['claim_status'] ?? '') === 'approved' && ($claim['item_status'] ?? '') === 'returned'): ?>
                                        <?php if (!empty($claim['feedback_id'])): ?>
                                            <span class="badge approved">Feedback Given</span><br><br>
                                            <a class="feedback-link" href="feedback.php?claim_id=<?= e($claim['id']); ?>">Edit Feedback</a>
                                        <?php else: ?>
                                            <a class="feedback-link" href="feedback.php?claim_id=<?= e($claim['id']); ?>">Give Feedback</a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="small">Not available yet</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="small">Feedback feature not added</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="11" class="empty">You have not made any claims yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
</body>
</html>
