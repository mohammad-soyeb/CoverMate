<?php
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$conn = mysqli_connect("localhost", "root", "", "lost_found");

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");

function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

$msg = "";
$msg_type = "";

$upload_dir = __DIR__ . "/uploads/";
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

if (isset($_POST['submit'])) {
    $title = trim($_POST['title'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $type = trim($_POST['type'] ?? '');
    $user_email = $_SESSION['user'];
    $image_name = null;

    if ($title === '' || $desc === '' || $type === '') {
        $msg = "❌ All fields are required!";
        $msg_type = "error";
    } elseif (!in_array($type, ['lost', 'found'], true)) {
        $msg = "❌ Invalid item type!";
        $msg_type = "error";
    } else {
        /* =========================
           IMAGE UPLOAD
        ========================= */
        if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['image'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $msg = "❌ Error uploading image!";
                $msg_type = "error";
            } else {
                $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
                $max_size = 2 * 1024 * 1024; // 2MB

                $original_name = $file['name'];
                $tmp_name = $file['tmp_name'];
                $file_size = $file['size'];

                $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

                if (!in_array($ext, $allowed_ext, true)) {
                    $msg = "❌ Only JPG, JPEG, PNG, and WEBP images are allowed!";
                    $msg_type = "error";
                } elseif ($file_size > $max_size) {
                    $msg = "❌ Image size must be under 2MB!";
                    $msg_type = "error";
                } elseif (@getimagesize($tmp_name) === false) {
                    $msg = "❌ Uploaded file is not a valid image!";
                    $msg_type = "error";
                } else {
                    $image_name = time() . "_" . bin2hex(random_bytes(5)) . "." . $ext;
                    $destination = $upload_dir . $image_name;

                    if (!move_uploaded_file($tmp_name, $destination)) {
                        $msg = "❌ Failed to save uploaded image!";
                        $msg_type = "error";
                        $image_name = null;
                    }
                }
            }
        }

        /* =========================
           INSERT ITEM
        ========================= */
        if ($msg === "") {
            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO items (title, description, image, user_email, type, status, created_at)
                 VALUES (?, ?, ?, ?, ?, 'pending', NOW())"
            );

            mysqli_stmt_bind_param($stmt, "sssss", $title, $desc, $image_name, $user_email, $type);

            if (mysqli_stmt_execute($stmt)) {
                $msg = "✅ Item submitted successfully!";
                $msg_type = "success";
            } else {
                $msg = "❌ Error submitting item!";
                $msg_type = "error";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Report Item - Lost & Found</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #eef2ff, #f8fbff);
            color: #1f2937;
        }

        .page-wrapper {
            min-height: 100vh;
            padding: 30px 15px;
        }

        .container {
            width: 100%;
            max-width: 850px;
            margin: 0 auto;
        }

        .hero {
            background: linear-gradient(135deg, #4f46e5, #2563eb);
            color: white;
            border-radius: 18px;
            padding: 28px;
            box-shadow: 0 14px 35px rgba(37, 99, 235, 0.20);
            margin-bottom: 24px;
        }

        .hero h1 {
            margin: 0 0 8px;
            font-size: 32px;
            font-weight: 700;
        }

        .hero p {
            margin: 0;
            font-size: 16px;
            opacity: 0.95;
        }

        .card {
            background: white;
            border-radius: 18px;
            padding: 28px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
        }

        .card h2 {
            margin-top: 0;
            margin-bottom: 22px;
            font-size: 26px;
            color: #111827;
        }

        .msg {
            margin-bottom: 18px;
            padding: 13px 15px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 15px;
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
            color: #374151;
        }

        input, textarea, select {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            font-size: 15px;
            background: #fff;
            transition: 0.2s ease;
            outline: none;
        }

        input:focus,
        textarea:focus,
        select:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
        }

        textarea {
            min-height: 140px;
            resize: vertical;
        }

        .button-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .btn {
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: 0.2s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(37, 99, 235, 0.20);
        }

        .btn-secondary {
            background: #eef2ff;
            color: #1d4ed8;
        }

        .btn-secondary:hover {
            background: #dfe7ff;
        }

        .hint-box {
            margin-top: 22px;
            padding: 16px 18px;
            border-radius: 12px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
        }

        .hint-box h3 {
            margin: 0 0 10px;
            font-size: 17px;
            color: #111827;
        }

        .hint-box ul {
            margin: 0;
            padding-left: 18px;
            color: #4b5563;
        }

        .hint-box li {
            margin-bottom: 6px;
        }

        @media (max-width: 768px) {
            .hero { padding: 22px; }
            .hero h1 { font-size: 26px; }
            .card { padding: 20px; }
            .card h2 { font-size: 22px; }
            .button-row { flex-direction: column; }
            .btn { width: 100%; text-align: center; }
        }
    </style>
    <link rel="stylesheet" href="ui-enhancements.css">
    <script src="ui-enhancements.js" defer></script>
</head>
<body>

<?php include "navbar.php"; ?>

<div class="page-wrapper">
    <div class="container">

        <div class="hero">
            <h1>Report an Item</h1>
            <p>Submit details of a lost or found item so others can identify and claim it easily.</p>
        </div>

        <div class="card">
            <h2>Lost / Found Item Form</h2>

            <?php if ($msg): ?>
                <div class="msg <?php echo $msg_type; ?>">
                    <?php echo e($msg); ?>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="title">Item Name</label>
                    <input type="text" id="title" name="title" placeholder="Enter item name" required>
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" placeholder="Describe the item clearly..." required></textarea>
                </div>

                <div class="form-group">
                    <label for="image">Item Image (Optional)</label>
                    <input type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.webp">
                </div>

                <div class="form-group">
                    <label for="type">Item Type</label>
                    <select id="type" name="type" required>
                        <option value="lost">Lost</option>
                        <option value="found">Found</option>
                    </select>
                </div>

                <div class="button-row">
                    <button class="btn btn-primary" type="submit" name="submit">Submit Item</button>
                    <a class="btn btn-secondary" href="dashboard.php">⬅ Back to Dashboard</a>
                </div>
            </form>

            <div class="hint-box">
                <h3>Tips for a better report</h3>
                <ul>
                    <li>Write the exact item name if possible.</li>
                    <li>Add clear identifying details in the description.</li>
                    <li>Upload a clear item image if available.</li>
                    <li>Select the correct type: Lost or Found.</li>
                </ul>
            </div>
        </div>

    </div>
</div>

</body>
</html>
