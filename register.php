<?php
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = mysqli_connect("localhost", "root", "", "lost_found");

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");

$msg = "";
$msg_type = "";

function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

if (isset($_POST['register'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role = 'user';

    if ($name === '' || $email === '' || $password === '' || $confirm_password === '') {
        $msg = "❌ All fields are required!";
        $msg_type = "error";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = "❌ Please enter a valid email address!";
        $msg_type = "error";

    } elseif (strlen($password) < 8) {
        $msg = "❌ Password must be at least 8 characters long!";
        $msg_type = "error";

    } elseif (!preg_match('/[A-Z]/', $password)) {
        $msg = "❌ Password must contain at least 1 uppercase letter!";
        $msg_type = "error";

    } elseif (!preg_match('/[a-z]/', $password)) {
        $msg = "❌ Password must contain at least 1 lowercase letter!";
        $msg_type = "error";

    } elseif (!preg_match('/[0-9]/', $password)) {
        $msg = "❌ Password must contain at least 1 number!";
        $msg_type = "error";

    } elseif (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        $msg = "❌ Password must contain at least 1 special character!";
        $msg_type = "error";

    } elseif ($password !== $confirm_password) {
        $msg = "❌ Password and Confirm Password do not match!";
        $msg_type = "error";

    } else {
        $check_stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? LIMIT 1");
        mysqli_stmt_bind_param($check_stmt, "s", $email);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);

        if (mysqli_num_rows($check_result) > 0) {
            $msg = "❌ Email already exists!";
            $msg_type = "error";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            $insert_stmt = mysqli_prepare(
                $conn,
                "INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param($insert_stmt, "ssss", $name, $email, $hashed_password, $role);

            if (mysqli_stmt_execute($insert_stmt)) {
                $msg = "✅ Registration successful! Now you can login.";
                $msg_type = "success";
            } else {
                $msg = "❌ Registration failed!";
                $msg_type = "error";
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
    <title>Register - Lost & Found</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #74ABE2, #5563DE);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .register-container {
            width: 100%;
            max-width: 440px;
            background: #ffffff;
            border-radius: 16px;
            padding: 32px 28px;
            box-shadow: 0 14px 35px rgba(0,0,0,0.18);
        }

        h2 {
            margin: 0 0 10px;
            text-align: center;
            color: #1f2937;
        }

        .subtitle {
            text-align: center;
            color: #6b7280;
            margin-bottom: 22px;
            font-size: 14px;
        }

        .msg {
            margin-bottom: 16px;
            padding: 12px 14px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
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
            margin-bottom: 16px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
            color: #374151;
            font-size: 14px;
        }

        input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            font-size: 15px;
            outline: none;
            transition: 0.2s ease;
        }

        input:focus {
            border-color: #5563DE;
            box-shadow: 0 0 0 4px rgba(85, 99, 222, 0.12);
        }

        .hint {
            margin-top: 6px;
            font-size: 13px;
            color: #6b7280;
            line-height: 1.5;
        }

        button {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #5563DE, #4353c0);
            border: none;
            border-radius: 10px;
            color: white;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s ease;
        }

        button:hover {
            opacity: 0.95;
        }

        .bottom-link {
            text-align: center;
            margin-top: 18px;
            font-size: 14px;
            color: #4b5563;
        }

        .bottom-link a {
            color: #4353c0;
            text-decoration: none;
            font-weight: 700;
        }

        .bottom-link a:hover {
            text-decoration: underline;
        }
    </style>
    <link rel="stylesheet" href="ui-enhancements.css">
    <script src="ui-enhancements.js" defer></script>
</head>
<body>

<div class="register-container">
    <h2>Create Account</h2>
    <div class="subtitle">Register for the Lost & Found system</div>

    <?php if ($msg): ?>
        <div class="msg <?php echo e($msg_type); ?>">
            <?php echo e($msg); ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label for="name">Full Name</label>
            <input
                type="text"
                id="name"
                name="name"
                placeholder="Enter your full name"
                value="<?php echo e($_POST['name'] ?? ''); ?>"
                required
            >
        </div>

        <div class="form-group">
            <label for="email">Email Address</label>
            <input
                type="email"
                id="email"
                name="email"
                placeholder="Enter your email"
                value="<?php echo e($_POST['email'] ?? ''); ?>"
                required
            >
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                placeholder="Enter password"
                minlength="8"
                required
            >
            <div class="hint">
                Password must be at least 8 characters and include:<br>
                1 uppercase letter, 1 lowercase letter, 1 number, and 1 special character.
            </div>
        </div>

        <div class="form-group">
            <label for="confirm_password">Confirm Password</label>
            <input
                type="password"
                id="confirm_password"
                name="confirm_password"
                placeholder="Re-enter password"
                minlength="8"
                required
            >
        </div>

        <button type="submit" name="register">Register</button>
    </form>

    <div class="bottom-link">
        Already have an account? <a href="login.php">Login here</a>
    </div>
</div>

</body>
</html>
