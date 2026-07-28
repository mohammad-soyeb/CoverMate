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

$current_email = $_SESSION['user'];

$q = trim($_GET['q'] ?? '');
$type = $_GET['type'] ?? 'all';
$status = $_GET['status'] ?? 'all';

$allowed_types = ['all', 'lost', 'found'];
$allowed_status = ['all', 'pending', 'approved', 'rejected', 'returned'];

if (!in_array($type, $allowed_types, true)) {
    $type = 'all';
}

if (!in_array($status, $allowed_status, true)) {
    $status = 'all';
}

$claimed_items = [];
$claim_stmt = mysqli_prepare(
    $conn,
    "SELECT item_id, status, created_at, approved_at
     FROM claims
     WHERE user_email = ? AND status IN ('pending', 'approved')"
);
mysqli_stmt_bind_param($claim_stmt, "s", $current_email);
mysqli_stmt_execute($claim_stmt);
$claim_result = mysqli_stmt_get_result($claim_stmt);

while ($claim = mysqli_fetch_assoc($claim_result)) {
    $claimed_items[$claim['item_id']] = [
        'status' => $claim['status'],
        'created_at' => $claim['created_at'] ?? null,
        'approved_at' => $claim['approved_at'] ?? null
    ];
}

$sql = "SELECT id, title, description, image, user_email, type, status, created_at, approved_at, rejected_at, returned_at
        FROM items
        WHERE 1=1";

$types = "";
$params = [];

if ($q !== '') {
    $like = "%" . $q . "%";
    $sql .= " AND (title LIKE ? OR description LIKE ?)";
    $types .= "ss";
    $params[] = $like;
    $params[] = $like;
}

if ($type !== 'all') {
    $sql .= " AND type = ?";
    $types .= "s";
    $params[] = $type;
}

if ($status !== 'all') {
    $sql .= " AND status = ?";
    $types .= "s";
    $params[] = $status;
}

$sql .= " ORDER BY id DESC";

$stmt = mysqli_prepare($conn, $sql);

if ($types !== "") {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$total_items = mysqli_num_rows($result);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Search Items - Lost & Found</title>
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
            max-width: 1200px;
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

        .filter-card {
            background: white;
            border-radius: 18px;
            padding: 22px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
            margin-bottom: 24px;
        }

        .filter-form {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto auto;
            gap: 12px;
            align-items: end;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            font-size: 15px;
            background: #fff;
            outline: none;
            transition: 0.2s ease;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
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

        .top-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        .results-count {
            font-weight: 700;
            color: #374151;
        }

        .items-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(330px, 1fr));
            gap: 18px;
        }

        .item-card {
            background: white;
            border-radius: 18px;
            padding: 22px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .item-image {
            width: 100%;
            height: 220px;
            object-fit: cover;
            border-radius: 14px;
            margin-bottom: 16px;
            border: 1px solid #e5e7eb;
            background: #f8fafc;
        }

        .no-image {
            width: 100%;
            height: 220px;
            border-radius: 14px;
            margin-bottom: 16px;
            border: 1px dashed #d1d5db;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6b7280;
            font-weight: 600;
        }

        .item-title {
            margin: 0 0 10px;
            font-size: 22px;
            color: #111827;
        }

        .item-desc {
            margin: 0 0 16px;
            color: #4b5563;
            line-height: 1.6;
            min-height: 72px;
        }

        .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 14px;
        }

        .badge {
            display: inline-block;
            padding: 7px 11px;
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

        .small {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 6px;
            line-height: 1.6;
        }

        .action-box {
            margin-top: 14px;
        }

        .status-note {
            padding: 12px 14px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            line-height: 1.5;
        }

        .note-blue {
            background: #eef4ff;
            color: #1d4ed8;
        }

        .note-green {
            background: #e8fff0;
            color: #117a37;
        }

        .note-yellow {
            background: #fff8db;
            color: #9a6700;
        }

        .note-red {
            background: #fff1f1;
            color: #b42318;
        }

        .empty-box {
            background: white;
            border-radius: 18px;
            padding: 35px;
            text-align: center;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
            color: #6b7280;
            font-weight: 600;
        }

        @media (max-width: 900px) {
            .filter-form {
                grid-template-columns: 1fr;
            }

            .hero h1 {
                font-size: 26px;
            }
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
            <h1>Search Items</h1>
            <p>Browse all reported items and claim the ones that belong to you.</p>
        </div>

        <div class="filter-card">
            <form method="GET" class="filter-form">
                <div class="form-group">
                    <label for="q">Search</label>
                    <input type="text" id="q" name="q" placeholder="Search by item title or description" value="<?php echo e($q); ?>">
                </div>

                <div class="form-group">
                    <label for="type">Type</label>
                    <select id="type" name="type">
                        <option value="all" <?php if($type === 'all') echo 'selected'; ?>>All Types</option>
                        <option value="lost" <?php if($type === 'lost') echo 'selected'; ?>>Lost</option>
                        <option value="found" <?php if($type === 'found') echo 'selected'; ?>>Found</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="all" <?php if($status === 'all') echo 'selected'; ?>>All Status</option>
                        <option value="pending" <?php if($status === 'pending') echo 'selected'; ?>>Pending</option>
                        <option value="approved" <?php if($status === 'approved') echo 'selected'; ?>>Approved</option>
                        <option value="rejected" <?php if($status === 'rejected') echo 'selected'; ?>>Rejected</option>
                        <option value="returned" <?php if($status === 'returned') echo 'selected'; ?>>Returned</option>
                    </select>
                </div>

                <button class="btn btn-primary" type="submit">Search</button>
                <a class="btn btn-secondary" href="search.php">Reset</a>
            </form>
        </div>

        <div class="top-actions">
            <div class="results-count">Total Items Found: <?php echo $total_items; ?></div>
            <a class="btn btn-secondary" href="dashboard.php">⬅ Back to Dashboard</a>
        </div>

        <?php if ($total_items > 0): ?>
            <div class="items-grid">
                <?php while($row = mysqli_fetch_assoc($result)): ?>
                    <div class="item-card">
                        <div>
                            <?php if (!empty($row['image']) && file_exists(__DIR__ . '/uploads/' . $row['image'])): ?>
                                <img class="item-image" src="uploads/<?php echo e($row['image']); ?>" alt="Item Image">
                            <?php else: ?>
                                <div class="no-image">No Image</div>
                            <?php endif; ?>

                            <h2 class="item-title"><?php echo e($row['title']); ?></h2>
                            <p class="item-desc"><?php echo e($row['description']); ?></p>

                            <div class="meta">
                                <span class="badge <?php echo e($row['type']); ?>">
                                    <?php echo ucfirst(e($row['type'])); ?>
                                </span>

                                <span class="badge <?php echo e($row['status']); ?>">
                                    <?php echo ucfirst(e($row['status'])); ?>
                                </span>
                            </div>

                            <div class="small">
                                <strong>Reported by:</strong> <?php echo e($row['user_email']); ?><br>
                                <strong>Reported at:</strong> <?php echo e($row['created_at'] ?: 'N/A'); ?><br>
                                <strong>Approved at:</strong> <?php echo e($row['approved_at'] ?: 'N/A'); ?><br>
                                <strong>Rejected at:</strong> <?php echo e($row['rejected_at'] ?: 'N/A'); ?><br>
                                <strong>Returned at:</strong> <?php echo e($row['returned_at'] ?: 'N/A'); ?>
                            </div>
                        </div>

                        <div class="action-box">
                            <?php if ($row['user_email'] === $current_email): ?>
                                <div class="status-note note-blue">This is your reported item.</div>

                            <?php elseif (isset($claimed_items[$row['id']]) && $claimed_items[$row['id']]['status'] === 'pending'): ?>
                                <div class="status-note note-yellow">
                                    You already claimed this item.<br>
                                    Claim submitted at: <?php echo e($claimed_items[$row['id']]['created_at'] ?: 'N/A'); ?>
                                </div>

                            <?php elseif (isset($claimed_items[$row['id']]) && $claimed_items[$row['id']]['status'] === 'approved'): ?>
                                <div class="status-note note-green">
                                    Your claim for this item has been approved.<br>
                                    Claim approved at: <?php echo e($claimed_items[$row['id']]['approved_at'] ?: 'N/A'); ?>
                                </div>

                            <?php elseif ($row['status'] === 'approved'): ?>
                                <a class="btn btn-primary" href="claim.php?id=<?php echo (int)$row['id']; ?>">Claim Item</a>

                            <?php elseif ($row['status'] === 'pending'): ?>
                                <div class="status-note note-yellow">This item is waiting for admin approval.</div>

                            <?php elseif ($row['status'] === 'rejected'): ?>
                                <div class="status-note note-red">This item has been rejected and cannot be claimed.</div>

                            <?php elseif ($row['status'] === 'returned'): ?>
                                <div class="status-note note-green">This item has already been returned.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="empty-box">
                No items found for your search.
            </div>
        <?php endif; ?>

    </div>
</div>

</body>
</html>
