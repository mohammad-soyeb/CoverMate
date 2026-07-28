<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

$item_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($item_id <= 0) {
    die("Invalid item ID.");
}

/* =========================
   GET ITEM
========================= */
$stmt = mysqli_prepare(
    $conn,
    "SELECT id, title, description, image, user_email, type, status, created_at
     FROM items
     WHERE id = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, "i", $item_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$item = mysqli_fetch_assoc($result);

if (!$item) {
    die("Item not found.");
}

$claim_upload_dir = __DIR__ . "/claim_uploads/";
if (!is_dir($claim_upload_dir)) {
    mkdir($claim_upload_dir, 0777, true);
}

/* =========================
   HANDLE CLAIM SUBMIT
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_claim'])) {
    $claim_reason = trim($_POST['claim_reason'] ?? '');
    $item_color = trim($_POST['item_color'] ?? '');
    $brand = trim($_POST['brand'] ?? '');
    $unique_mark = trim($_POST['unique_mark'] ?? '');
    $lost_location = trim($_POST['lost_location'] ?? '');
    $lost_date = trim($_POST['lost_date'] ?? '');
    $proof_image_name = null;

    if ($item['user_email'] === $current_email) {
        $msg = "❌ You cannot claim your own item.";
        $msg_type = "error";

    } elseif ($item['status'] !== 'approved') {
        $msg = "❌ Only approved items can be claimed.";
        $msg_type = "error";

    } elseif ($claim_reason === '' || $item_color === '' || $brand === '' || $unique_mark === '' || $lost_location === '' || $lost_date === '') {
        $msg = "❌ Please fill in all claim verification fields.";
        $msg_type = "error";

    } else {
        $check = mysqli_prepare(
            $conn,
            "SELECT id, status
             FROM claims
             WHERE item_id = ? AND user_email = ? AND status IN ('pending', 'approved')
             LIMIT 1"
        );
        mysqli_stmt_bind_param($check, "is", $item_id, $current_email);
        mysqli_stmt_execute($check);
        $check_result = mysqli_stmt_get_result($check);
        $existing_claim = mysqli_fetch_assoc($check_result);

        if ($existing_claim) {
            $msg = "❌ You already submitted a claim for this item.";
            $msg_type = "error";
        } else {
            /* =========================
               PROOF IMAGE UPLOAD
            ========================= */
            if (isset($_FILES['proof_image']) && $_FILES['proof_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES['proof_image'];

                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $msg = "❌ Error uploading proof image!";
                    $msg_type = "error";
                } else {
                    $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
                    $max_size = 2 * 1024 * 1024;

                    $original_name = $file['name'];
                    $tmp_name = $file['tmp_name'];
                    $file_size = $file['size'];

                    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

                    if (!in_array($ext, $allowed_ext, true)) {
                        $msg = "❌ Proof image must be JPG, JPEG, PNG, or WEBP.";
                        $msg_type = "error";
                    } elseif ($file_size > $max_size) {
                        $msg = "❌ Proof image must be under 2MB.";
                        $msg_type = "error";
                    } elseif (@getimagesize($tmp_name) === false) {
                        $msg = "❌ Uploaded proof file is not a valid image.";
                        $msg_type = "error";
                    } else {
                        $proof_image_name = "proof_" . time() . "_" . bin2hex(random_bytes(5)) . "." . $ext;
                        $destination = $claim_upload_dir . $proof_image_name;

                        if (!move_uploaded_file($tmp_name, $destination)) {
                            $msg = "❌ Failed to save proof image.";
                            $msg_type = "error";
                            $proof_image_name = null;
                        }
                    }
                }
            }

            if ($msg === "") {
                $insert = mysqli_prepare(
                    $conn,
                    "INSERT INTO claims
                    (item_id, user_email, claim_reason, item_color, brand, unique_mark, lost_location, lost_date, proof_image, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())"
                );
                mysqli_stmt_bind_param(
                    $insert,
                    "issssssss",
                    $item_id,
                    $current_email,
                    $claim_reason,
                    $item_color,
                    $brand,
                    $unique_mark,
                    $lost_location,
                    $lost_date,
                    $proof_image_name
                );

                if (mysqli_stmt_execute($insert)) {
                    $msg = "✅ Claim submitted successfully! Admin will verify your details.";
                    $msg_type = "success";
                } else {
                    $msg = "❌ Failed to submit claim!";
                    $msg_type = "error";
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claim Item - Lost & Found</title>
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
            max-width: 900px;
            margin: 0 auto;
        }
        .hero {
            background: linear-gradient(135deg, #4f46e5, #2563eb);
            color: white;
            border-radius: 18px;
            padding: 28px;
            margin-bottom: 22px;
        }
        .card {
            background: white;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
        }
        .item-image {
            width: 100%;
            height: 280px;
            object-fit: cover;
            border-radius: 14px;
            margin-bottom: 16px;
            border: 1px solid #e5e7eb;
        }
        .no-image {
            width: 100%;
            height: 280px;
            border-radius: 14px;
            margin-bottom: 16px;
            border: 1px dashed #d1d5db;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6b7280;
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
        .info-box {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            padding: 16px;
            border-radius: 12px;
            line-height: 1.7;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 16px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }
        input, textarea {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            font-size: 15px;
        }
        textarea {
            min-height: 110px;
            resize: vertical;
        }
        .button-row {
            margin-top: 20px;
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
    </style>
    <link rel="stylesheet" href="ui-enhancements.css">
    <script src="ui-enhancements.js" defer></script>
</head>
<body>
<div class="wrapper">
    <div class="container">

        <div class="hero">
            <h1>Claim Item</h1>
            <p>Fill in the verification details so admin can confirm the item really belongs to you.</p>
        </div>

        <div class="card">
            <?php if ($msg): ?>
                <div class="msg <?php echo e($msg_type); ?>">
                    <?php echo e($msg); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($item['image']) && file_exists(__DIR__ . '/uploads/' . $item['image'])): ?>
                <img class="item-image" src="uploads/<?php echo e($item['image']); ?>" alt="Item Image">
            <?php else: ?>
                <div class="no-image">No Image</div>
            <?php endif; ?>

            <div class="info-box">
                <strong>Item:</strong> <?php echo e($item['title']); ?><br>
                <strong>Description:</strong> <?php echo e($item['description']); ?><br>
                <strong>Type:</strong> <?php echo e($item['type']); ?><br>
                <strong>Status:</strong> <?php echo e($item['status']); ?><br>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Why do you think this item is yours?</label>
                    <textarea name="claim_reason" required></textarea>
                </div>

                <div class="form-group">
                    <label>Item Color</label>
                    <input type="text" name="item_color" required>
                </div>

                <div class="form-group">
                    <label>Brand</label>
                    <input type="text" name="brand" required>
                </div>

                <div class="form-group">
                    <label>Unique Mark / Special Identification</label>
                    <textarea name="unique_mark" required></textarea>
                </div>

                <div class="form-group">
                    <label>Where did you lose it?</label>
                    <input type="text" name="lost_location" required>
                </div>

                <div class="form-group">
                    <label>Lost Date</label>
                    <input type="date" name="lost_date" required>
                </div>

                <div class="form-group">
                    <label>Proof Image (Optional)</label>
                    <input type="file" name="proof_image" accept=".jpg,.jpeg,.png,.webp">
                </div>

                <div class="button-row">
                    <button type="submit" name="confirm_claim" class="btn btn-primary">Submit Claim</button>
                    <a href="search.php" class="btn btn-secondary">⬅ Back to Search</a>
                </div>
            </form>
        </div>

    </div>
</div>
</body>
</html>
