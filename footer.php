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

$stmt = mysqli_prepare(
    $conn,
    "SELECT c.id, c.user_email, c.status AS claim_status,
            i.title, i.status AS item_status
     FROM claims c
     INNER JOIN items i ON c.item_id = i.id
     WHERE c.id = ? AND c.user_email = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, "is", $claim_id, $current_email);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$claim = mysqli_fetch_assoc($result);

if (!$claim) {
    die("Claim not found or access denied.");
}

if ($claim['claim_status'] !== 'approved' || $claim['item_status'] !== 'returned') {
    die("Feedback can only be given after claim approval and item return.");
}

$existing = null;
$fb_stmt = mysqli_prepare(
    $conn,
    "SELECT * FROM feedbacks WHERE claim_id = ? LIMIT 1"
);
mysqli_stmt_bind_param($fb_stmt, "i", $claim_id);
mysqli_stmt_execute($fb_stmt);
$fb_result = mysqli_stmt_get_result($fb_stmt);
$existing = mysqli_fetch_assoc($fb_result);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_feedback'])) {
    $item_received = $_POST['item_received'] ?? '';
    $system_helpful = $_POST['system_helpful'] ?? '';
    $comments = trim($_POST['comments'] ?? '');

    if (!in_array($item_received, ['yes', 'no'], true) || !in_array($system_helpful, ['yes', 'no'], true)) {
        $msg = "❌ Please select valid feedback options.";
        $msg_type = "error";
    } else {
        if ($existing) {
            $update = mysqli_prepare(
                $conn,
                "UPDATE feedbacks
                 SET item_received = ?, system_helpful = ?, comments = ?
                 WHERE claim_id = ?"
            );
            mysqli_stmt_bind_param($update, "sssi", $item_received, $system_helpful, $comments, $claim_id);
            mysqli_stmt_execute($update);

            $msg = "✅ Feedback updated successfully.";
            $msg_type = "success";
        } else {
            $insert = mysqli_prepare(
                $conn,
                "INSERT INTO feedbacks (claim_id, user_email, item_received, system_helpful, comments)
                 VALUES (?, ?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param($insert, "issss", $claim_id, $current_email, $item_received, $system_helpful, $comments);
            mysqli_stmt_execute($insert);

            $msg = "✅ Feedback submitted successfully.";
            $msg_type = "success";
        }

        $fb_stmt = mysqli_prepare($conn, "SELECT * FROM feedbacks WHERE claim_id = ? LIMIT 1");
        mysqli_stmt_bind_param($fb_stmt, "i", $claim_id);
        mysqli_stmt_execute($fb_stmt);
        $fb_result = mysqli_stmt_get_result($fb_stmt);
        $existing = mysqli_fetch_assoc($fb_result);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claim Feedback - Lost & Found</title>
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
            max-width: 760px;
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

        .card {
            background: white;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
        }

        .card h2 {
            margin-top: 0;
            margin-bottom: 18px;
        }

        .info-box {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 18px;
        }

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

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }

        select, textarea {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            font-size: 15px;
            outline: none;
        }

        select:focus, textarea:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
        }

        textarea {
            min-height: 120px;
            resize: vertical;
        }

        .button-row {
            display: flex;
            gap: 12px;
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

        .btn-primary {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: white;
        }

        .btn-secondary {
            background: #eef2ff;
            color: #1d4ed8;
        }

        .btn-secondary:hover {
            background: #dfe7ff;
        }
    </style>
    <link rel="stylesheet" href="ui-enhancements.css">
    <script src="ui-enhancements.js" defer></script>
</head>
<body>

<div class="wrapper">
    <div class="container">

        <div class="hero">
            <h1>Claim Feedback</h1>
            <p>Tell us whether you received the item and whether the system was helpful.</p>
        </div>

        <div class="card">
            <h2>Feedback Form</h2>

            <div class="info-box">
                <strong>Item:</strong> <?= e($claim['title']); ?><br>
                <strong>Claim ID:</strong> #<?= e($claim['id']); ?>
            </div>

            <?php if ($msg): ?>
                <div class="msg <?= e($msg_type); ?>">
                    <?= e($msg); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>Did you receive the item?</label>
                    <select name="item_received" required>
                        <option value="">Select an option</option>
                        <option value="yes" <?= (($existing['item_received'] ?? '') === 'yes') ? 'selected' : ''; ?>>Yes</option>
                        <option value="no" <?= (($existing['item_received'] ?? '') === 'no') ? 'selected' : ''; ?>>No</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Was the system helpful?</label>
                    <select name="system_helpful" required>
                        <option value="">Select an option</option>
                        <option value="yes" <?= (($existing['system_helpful'] ?? '') === 'yes') ? 'selected' : ''; ?>>Yes</option>
                        <option value="no" <?= (($existing['system_helpful'] ?? '') === 'no') ? 'selected' : ''; ?>>No</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Additional Comment (Optional)</label>
                    <textarea name="comments" placeholder="Write your feedback here..."><?= e($existing['comments'] ?? ''); ?></textarea>
                </div>

                <div class="button-row">
                    <button type="submit" name="save_feedback" class="btn btn-primary">
                        <?= $existing ? 'Update Feedback' : 'Submit Feedback'; ?>
                    </button>
                    <a href="my_feedback.php" class="btn btn-secondary">⬅ Back to My Feedback</a>
                </div>
            </form>
        </div>

    </div>
</div>

</body>
</html>
