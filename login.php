<?php
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = mysqli_connect("localhost", "root", "", "lost_found");

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");

$error = "";

if (isset($_POST['login'])) {
    $email = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';

    $sql = "SELECT * FROM users WHERE email = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);

        if (password_verify($pass, $row['password'])) {
            $_SESSION['user'] = $row['email'];
            header("Location: dashboard.php");
            exit();
        } else {
            $error = "❌ Wrong Password!";
        }
    } else {
        $error = "❌ User not found!";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Lost & Found</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #74ABE2, #5563DE);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }

        .login-container {
            background: #fff;
            padding: 36px 30px;
            border-radius: 16px;
            box-shadow: 0 14px 35px rgba(0,0,0,0.18);
            width: 100%;
            max-width: 420px;
            text-align: center;
        }

        h2 {
            margin-bottom: 10px;
            color: #1f2937;
        }

        .subtitle {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 24px;
        }

        .error {
            color: #b42318;
            background: #fff1f1;
            border: 1px solid #ffcdcd;
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 16px;
            font-weight: 600;
            font-size: 14px;
            text-align: left;
        }

        .form-group {
            text-align: left;
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
            color: #374151;
            font-size: 14px;
        }

        input[type="email"],
        input[type="password"],
        input[type="text"] {
            width: 100%;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid #d1d5db;
            font-size: 15px;
            outline: none;
            transition: 0.2s ease;
        }

        input[type="email"]:focus,
        input[type="password"]:focus,
        input[type="text"]:focus {
            border-color: #5563DE;
            box-shadow: 0 0 0 4px rgba(85, 99, 222, 0.12);
        }

        .password-wrapper {
            position: relative;
        }

        .password-wrapper input {
            padding-right: 90px;
        }

        .toggle-btn {
            position: absolute;
            top: 50%;
            right: 10px;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #4353c0;
            font-weight: 700;
            cursor: pointer;
            font-size: 13px;
            padding: 4px 6px;
        }

        .toggle-btn:hover {
            color: #2f3aa6;
        }

        .login-btn {
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
            margin-top: 6px;
        }

        .login-btn:hover {
            opacity: 0.95;
        }

        .extra-links {
            margin-top: 18px;
            font-size: 14px;
            color: #4b5563;
        }

        .extra-links a {
            color: #4353c0;
            text-decoration: none;
            font-weight: 700;
        }

        .extra-links a:hover {
            text-decoration: underline;
        }

        @media (max-width: 500px) {
            .login-container {
                padding: 28px 20px;
            }
        }
    </style>
    <link rel="stylesheet" href="ui-enhancements.css">
    <script src="ui-enhancements.js" defer></script>
</head>
<body>

<div class="login-container">
    <h2>Login to Lost & Found</h2>
    <div class="subtitle">Access your account to manage items and claims</div>

    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label for="email">Email Address</label>
            <input
                type="email"
                id="email"
                name="email"
                placeholder="Enter Email"
                value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                required
            >
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <div class="password-wrapper">
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Enter Password"
                    required
                >
                <button type="button" class="toggle-btn" onclick="togglePassword()">Show</button>
            </div>
        </div>

        <button type="submit" name="login" class="login-btn">Login</button>
    </form>

    <div class="extra-links">
        Don’t have an account? <a href="register.php">Register</a>
    </div>
</div>

<script>
function togglePassword() {
    const passwordInput = document.getElementById("password");
    const toggleBtn = document.querySelector(".toggle-btn");

    if (passwordInput.type === "password") {
        passwordInput.type = "text";
        toggleBtn.textContent = "Hide";
    } else {
        passwordInput.type = "password";
        toggleBtn.textContent = "Show";
    }
}
</script>

</body>
</html>
