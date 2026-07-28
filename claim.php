<?php
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = mysqli_connect("localhost", "root", "", "lost_found");

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");

/* =========================
   HELPERS
========================= */
function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function redirect_to($url) {
    header("Location: " . $url);
    exit();
}

function set_flash($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function get_flash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function prepare_and_execute($conn, $sql, $types = "", $params = []) {
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        throw new Exception("Query prepare failed.");
    }

    if ($types !== "" && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);
    return $stmt;
}

function fetch_one($conn, $sql, $types = "", $params = []) {
    $stmt = prepare_and_execute($conn, $sql, $types, $params);
    $result = mysqli_stmt_get_result($stmt);
    return mysqli_fetch_assoc($result);
}

function count_value($conn, $sql) {
    $result = mysqli_query($conn, $sql);
    $row = mysqli_fetch_row($result);
    return (int)($row[0] ?? 0);
}

/* =========================
   LOGIN + ADMIN CHECK
========================= */
if (!isset($_SESSION['user'])) {
    redirect_to("login.php");
}

$email = $_SESSION['user'];

$user = fetch_one(
    $conn,
    "SELECT id, name, email, role FROM users WHERE email = ? LIMIT 1",
    "s",
    [$email]
);

if (!$user) {
    session_destroy();
    redirect_to("login.php");
}

if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo "<h2 style='font-family:Arial; color:red; text-align:center; margin-top:50px;'>❌ Access Denied! Admin only.</h2>";
    exit();
}

/* =========================
   CSRF TOKEN
========================= */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* =========================
   HANDLE ACTIONS
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (
            !isset($_POST['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
        ) {
            throw new Exception("Invalid request token.");
        }

        $action = trim($_POST['action'] ?? '');
        $item_id = (int)($_POST['item_id'] ?? 0);
        $claim_id = (int)($_POST['claim_id'] ?? 0);

        /* -------- APPROVE ITEM -------- */
        if ($action === 'approve_item') {
            if ($item_id <= 0) {
                throw new Exception("Invalid item ID.");
            }

            prepare_and_execute(
                $conn,
                "UPDATE items
                 SET status = 'approved',
                     approved_at = NOW(),
                     rejected_at = NULL
                 WHERE id = ? AND status <> 'returned'",
                "i",
                [$item_id]
            );

            set_flash("success", "✅ Item approved successfully.");
            redirect_to("admin.php");
        }

        /* -------- REJECT ITEM -------- */
        if ($action === 'reject_item') {
            if ($item_id <= 0) {
                throw new Exception("Invalid item ID.");
            }

            mysqli_begin_transaction($conn);

            prepare_and_execute(
                $conn,
                "UPDATE items
                 SET status = 'rejected',
                     rejected_at = NOW(),
                     approved_at = NULL,
                     returned_at = NULL
                 WHERE id = ? AND status <> 'returned'",
                "i",
                [$item_id]
            );

            prepare_and_execute(
                $conn,
                "UPDATE claims
                 SET status = 'rejected',
                     rejected_at = NOW(),
                     approved_at = NULL
                 WHERE item_id = ? AND status = 'pending'",
                "i",
                [$item_id]
            );

            mysqli_commit($conn);

            set_flash("success", "❌ Item rejected. Related pending claims also rejected.");
            redirect_to("admin.php");
        }

        /* -------- DELETE ITEM -------- */
        if ($action === 'delete_item') {
            if ($item_id <= 0) {
                throw new Exception("Invalid item ID.");
            }

            $item = fetch_one(
                $conn,
                "SELECT image FROM items WHERE id = ? LIMIT 1",
                "i",
                [$item_id]
            );

            mysqli_begin_transaction($conn);

            prepare_and_execute($conn, "DELETE FROM claims WHERE item_id = ?", "i", [$item_id]);
            prepare_and_execute($conn, "DELETE FROM items WHERE id = ?", "i", [$item_id]);

            mysqli_commit($conn);

            if (!empty($item['image']) && file_exists(__DIR__ . '/uploads/' . $item['image'])) {
                @unlink(__DIR__ . '/uploads/' . $item['image']);
            }

            set_flash("success", "🗑️ Item deleted successfully.");
            redirect_to("admin.php");
        }

        /* -------- APPROVE CLAIM -------- */
        if ($action === 'approve_claim') {
            if ($claim_id <= 0) {
                throw new Exception("Invalid claim ID.");
            }

            mysqli_begin_transaction($conn);

            $claim = fetch_one(
                $conn,
                "SELECT c.id, c.item_id, c.status, i.status AS item_status
                 FROM claims c
                 INNER JOIN items i ON c.item_id = i.id
                 WHERE c.id = ?
                 LIMIT 1",
                "i",
                [$claim_id]
            );

            if (!$claim) {
                throw new Exception("Claim not found.");
            }

            if ($claim['status'] !== 'pending') {
                throw new Exception("This claim is already processed.");
            }

            if ($claim['item_status'] === 'rejected') {
                throw new Exception("Cannot approve claim. Item is rejected.");
            }

            if ($claim['item_status'] === 'returned') {
                throw new Exception("Cannot approve claim. Item is already returned.");
            }

            prepare_and_execute(
                $conn,
                "UPDATE claims
                 SET status = 'approved',
                     approved_at = NOW(),
                     rejected_at = NULL
                 WHERE id = ?",
                "i",
                [$claim_id]
            );

            prepare_and_execute(
                $conn,
                "UPDATE items
                 SET status = 'returned',
                     returned_at = NOW()
                 WHERE id = ?",
                "i",
                [$claim['item_id']]
            );

            prepare_and_execute(
                $conn,
                "UPDATE claims
                 SET status = 'rejected',
                     rejected_at = NOW(),
                     approved_at = NULL
                 WHERE item_id = ? AND id <> ? AND status = 'pending'",
                "ii",
                [$claim['item_id'], $claim_id]
            );

            mysqli_commit($conn);

            set_flash("success", "✅ Claim approved. Item marked as returned. Other pending claims rejected.");
            redirect_to("admin.php");
        }

        /* -------- REJECT CLAIM -------- */
        if ($action === 'reject_claim') {
            if ($claim_id <= 0) {
                throw new Exception("Invalid claim ID.");
            }

            prepare_and_execute(
                $conn,
                "UPDATE claims
                 SET status = 'rejected',
                     rejected_at = NOW(),
                     approved_at = NULL
                 WHERE id = ? AND status = 'pending'",
                "i",
                [$claim_id]
            );

            set_flash("success", "❌ Claim rejected successfully.");
            redirect_to("admin.php");
        }

        throw new Exception("Unknown action.");
    } catch (Exception $ex) {
        @mysqli_rollback($conn);
        set_flash("error", "⚠️ " . $ex->getMessage());
        redirect_to("admin.php");
    }
}

/* =========================
   FILTERS
========================= */
$q = trim($_GET['q'] ?? '');
$item_status = $_GET['item_status'] ?? 'all';
$item_type = $_GET['item_type'] ?? 'all';
$claim_status = $_GET['claim_status'] ?? 'all';

$allowed_item_status = ['all', 'pending', 'approved', 'rejected', 'returned'];
$allowed_item_type = ['all', 'lost', 'found'];
$allowed_claim_status = ['all', 'pending', 'approved', 'rejected'];

if (!in_array($item_status, $allowed_item_status, true)) {
    $item_status = 'all';
}
if (!in_array($item_type, $allowed_item_type, true)) {
    $item_type = 'all';
}
if (!in_array($claim_status, $allowed_claim_status, true)) {
    $claim_status = 'all';
}

/* =========================
   STATS
========================= */
$total_users = count_value($conn, "SELECT COUNT(*) FROM users");
$total_items = count_value($conn, "SELECT COUNT(*) FROM items");
$pending_items = count_value($conn, "SELECT COUNT(*) FROM items WHERE status='pending'");
$approved_items = count_value($conn, "SELECT COUNT(*) FROM items WHERE status='approved'");
$rejected_items = count_value($conn, "SELECT COUNT(*) FROM items WHERE status='rejected'");
$returned_items = count_value($conn, "SELECT COUNT(*) FROM items WHERE status='returned'");

$total_claims = count_value($conn, "SELECT COUNT(*) FROM claims");
$pending_claims = count_value($conn, "SELECT COUNT(*) FROM claims WHERE status='pending'");
$approved_claims = count_value($conn, "SELECT COUNT(*) FROM claims WHERE status='approved'");
$rejected_claims = count_value($conn, "SELECT COUNT(*) FROM claims WHERE status='rejected'");

/* =========================
   ITEMS QUERY
========================= */
$item_sql = "SELECT id, title, description, image, user_email, type, status, created_at, approved_at, rejected_at, returned_at
             FROM items
             WHERE 1=1";
$item_types = "";
$item_params = [];

if ($q !== '') {
    $like = "%" . $q . "%";
    $item_sql .= " AND (title LIKE ? OR description LIKE ? OR user_email LIKE ?)";
    $item_types .= "sss";
    $item_params[] = $like;
    $item_params[] = $like;
    $item_params[] = $like;
}

if ($item_status !== 'all') {
    $item_sql .= " AND status = ?";
    $item_types .= "s";
    $item_params[] = $item_status;
}

if ($item_type !== 'all') {
    $item_sql .= " AND type = ?";
    $item_types .= "s";
    $item_params[] = $item_type;
}

$item_sql .= " ORDER BY id DESC";

$item_stmt = prepare_and_execute($conn, $item_sql, $item_types, $item_params);
$items_result = mysqli_stmt_get_result($item_stmt);

/* =========================
   CLAIMS QUERY
========================= */
$claim_sql = "SELECT c.id, c.item_id, c.user_email, c.status,
                     c.claim_reason, c.item_color, c.brand, c.unique_mark,
                     c.lost_location, c.lost_date, c.proof_image, c.admin_note,
                     c.created_at, c.approved_at, c.rejected_at,
                     i.title AS item_title, i.type AS item_type, i.status AS item_status
              FROM claims c
              LEFT JOIN items i ON c.item_id = i.id
              WHERE 1=1";
$claim_types = "";
$claim_params = [];

if ($q !== '') {
    $like = "%" . $q . "%";
    $claim_sql .= " AND (c.user_email LIKE ? OR i.title LIKE ? OR c.claim_reason LIKE ?)";
    $claim_types .= "sss";
    $claim_params[] = $like;
    $claim_params[] = $like;
    $claim_params[] = $like;
}

if ($claim_status !== 'all') {
    $claim_sql .= " AND c.status = ?";
    $claim_types .= "s";
    $claim_params[] = $claim_status;
}

$claim_sql .= " ORDER BY c.id DESC";

$claim_stmt = prepare_and_execute($conn, $claim_sql, $claim_types, $claim_params);
$claims_result = mysqli_stmt_get_result($claim_stmt);

$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Lost & Found</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fb;
            color: #222;
        }

        .container {
            width: 95%;
            max-width: 1750px;
            margin: 25px auto;
        }

        .topbar {
            background: linear-gradient(135deg, #2b59ff, #6a5cff);
            color: white;
            padding: 18px 22px;
            border-radius: 14px;
            margin-bottom: 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
        }

        .topbar h1 {
            margin: 0 0 8px;
            font-size: 28px;
        }

        .topbar p {
            margin: 0;
            opacity: 0.95;
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
            transition: 0.2s;
            font-weight: 600;
        }

        .nav-links a:hover {
            background: rgba(255,255,255,0.25);
        }

        .flash {
            padding: 14px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 600;
        }

        .flash.success {
            background: #e8fff0;
            color: #117a37;
            border: 1px solid #b6f0ca;
        }

        .flash.error {
            background: #fff1f1;
            color: #b42318;
            border: 1px solid #ffcdcd;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .card {
            background: white;
            border-radius: 14px;
            padding: 18px;
            box-shadow: 0 10px 22px rgba(0,0,0,0.06);
        }

        .card h3 {
            margin: 0 0 10px;
            font-size: 16px;
            color: #555;
        }

        .card .num {
            font-size: 30px;
            font-weight: 700;
            color: #222;
        }

        .section {
            background: white;
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 10px 22px rgba(0,0,0,0.06);
            margin-bottom: 25px;
        }

        .section h2 {
            margin-top: 0;
            margin-bottom: 18px;
            font-size: 24px;
        }

        .filters {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
            gap: 12px;
            margin-bottom: 18px;
        }

        .filters input,
        .filters select,
        .filters button {
            width: 100%;
            padding: 11px 12px;
            border-radius: 8px;
            border: 1px solid #d0d7e2;
            font-size: 15px;
        }

        .filters button {
            background: #2b59ff;
            color: white;
            border: none;
            cursor: pointer;
            font-weight: 700;
        }

        .filters button:hover {
            background: #1f49e0;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1900px;
        }

        th, td {
            padding: 13px 12px;
            border-bottom: 1px solid #edf0f5;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #f8faff;
            color: #444;
            font-size: 14px;
        }

        tr:hover {
            background: #fafcff;
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

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .actions form {
            margin: 0;
        }

        .btn {
            border: none;
            padding: 9px 12px;
            border-radius: 8px;
            color: white;
            font-weight: 700;
            cursor: pointer;
            font-size: 13px;
        }

        .btn-approve { background: #16a34a; }
        .btn-reject { background: #dc2626; }
        .btn-delete { background: #111827; }
        .btn-chat {
            background: #2563eb;
            color: white;
            text-decoration: none;
            display: inline-block;
        }

        .btn:hover,
        .btn-chat:hover { opacity: 0.9; }

        .muted {
            color: #667085;
            font-size: 14px;
        }

        .empty {
            text-align: center;
            padding: 20px;
            color: #667085;
            font-weight: 600;
        }

        .small {
            font-size: 13px;
            color: #667085;
        }

        .proof-link {
            color: #2563eb;
            font-weight: 700;
            text-decoration: none;
        }

        .proof-link:hover {
            text-decoration: underline;
        }

        @media (max-width: 900px) {
            .filters {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <link rel="stylesheet" href="ui-enhancements.css">
    <script src="ui-enhancements.js" defer></script>
</head>
<body>

<div class="container">

    <div class="topbar">
        <h1>Admin Panel</h1>
        <p>Welcome, <?= e($user['name']); ?> (<?= e($user['email']); ?>)</p>

        <div class="nav-links">
            <a href="dashboard.php">🏠 Dashboard</a>
            <a href="report.php">➕ Report Item</a>
            <a href="search.php">🔍 Search Items</a>
            <a href="logout.php">🚪 Logout</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="flash <?= e($flash['type']); ?>">
            <?= e($flash['message']); ?>
        </div>
    <?php endif; ?>

    <div class="stats">
        <div class="card"><h3>Total Users</h3><div class="num"><?= $total_users; ?></div></div>
        <div class="card"><h3>Total Items</h3><div class="num"><?= $total_items; ?></div></div>
        <div class="card"><h3>Pending Items</h3><div class="num"><?= $pending_items; ?></div></div>
        <div class="card"><h3>Approved Items</h3><div class="num"><?= $approved_items; ?></div></div>
        <div class="card"><h3>Rejected Items</h3><div class="num"><?= $rejected_items; ?></div></div>
        <div class="card"><h3>Returned Items</h3><div class="num"><?= $returned_items; ?></div></div>
        <div class="card"><h3>Total Claims</h3><div class="num"><?= $total_claims; ?></div></div>
        <div class="card"><h3>Pending Claims</h3><div class="num"><?= $pending_claims; ?></div></div>
        <div class="card"><h3>Approved Claims</h3><div class="num"><?= $approved_claims; ?></div></div>
        <div class="card"><h3>Rejected Claims</h3><div class="num"><?= $rejected_claims; ?></div></div>
    </div>

    <div class="section">
        <h2>Search & Filters</h2>
        <form method="GET" class="filters">
            <input type="text" name="q" placeholder="Search by item title, description, user email, or claim reason..." value="<?= e($q); ?>">

            <select name="item_status">
                <option value="all" <?= $item_status === 'all' ? 'selected' : ''; ?>>All Item Status</option>
                <option value="pending" <?= $item_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="approved" <?= $item_status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="rejected" <?= $item_status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                <option value="returned" <?= $item_status === 'returned' ? 'selected' : ''; ?>>Returned</option>
            </select>

            <select name="item_type">
                <option value="all" <?= $item_type === 'all' ? 'selected' : ''; ?>>All Item Types</option>
                <option value="lost" <?= $item_type === 'lost' ? 'selected' : ''; ?>>Lost</option>
                <option value="found" <?= $item_type === 'found' ? 'selected' : ''; ?>>Found</option>
            </select>

            <select name="claim_status">
                <option value="all" <?= $claim_status === 'all' ? 'selected' : ''; ?>>All Claim Status</option>
                <option value="pending" <?= $claim_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="approved" <?= $claim_status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="rejected" <?= $claim_status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
            </select>

            <button type="submit">Filter</button>
        </form>
    </div>

    <div class="section">
        <h2>📦 Manage Items</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Image</th>
                        <th>Title</th>
                        <th>Description</th>
                        <th>Reported By</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Reported At</th>
                        <th>Approved At</th>
                        <th>Rejected At</th>
                        <th>Returned At</th>
                        <th width="280">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($items_result) > 0): ?>
                    <?php while ($row = mysqli_fetch_assoc($items_result)): ?>
                        <tr>
                            <td>#<?= e($row['id']); ?></td>
                            <td>
                                <?php if (!empty($row['image']) && file_exists(__DIR__ . '/uploads/' . $row['image'])): ?>
                                    <img class="thumb" src="uploads/<?= e($row['image']); ?>" alt="Item Image">
                                <?php else: ?>
                                    <div class="no-thumb">No Image</div>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= e($row['title']); ?></strong></td>
                            <td><?= e($row['description']); ?></td>
                            <td><?= e($row['user_email']); ?></td>
                            <td>
                                <span class="badge <?= e($row['type']); ?>">
                                    <?= ucfirst(e($row['type'])); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= e($row['status']); ?>">
                                    <?= ucfirst(e($row['status'])); ?>
                                </span>
                            </td>
                            <td><?= e($row['created_at'] ?: 'N/A'); ?></td>
                            <td><?= e($row['approved_at'] ?: 'N/A'); ?></td>
                            <td><?= e($row['rejected_at'] ?: 'N/A'); ?></td>
                            <td><?= e($row['returned_at'] ?: 'N/A'); ?></td>
                            <td>
                                <div class="actions">
                                    <?php if ($row['status'] === 'pending' || $row['status'] === 'rejected'): ?>
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="action" value="approve_item">
                                            <input type="hidden" name="item_id" value="<?= (int)$row['id']; ?>">
                                            <button class="btn btn-approve" type="submit">✅ Approve</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($row['status'] === 'pending' || $row['status'] === 'approved'): ?>
                                        <form method="POST" onsubmit="return confirm('Are you sure you want to reject this item?');">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="action" value="reject_item">
                                            <input type="hidden" name="item_id" value="<?= (int)$row['id']; ?>">
                                            <button class="btn btn-reject" type="submit">❌ Reject</button>
                                        </form>
                                    <?php endif; ?>

                                    <form method="POST" onsubmit="return confirm('This will permanently delete the item and related claims. Continue?');">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="delete_item">
                                        <input type="hidden" name="item_id" value="<?= (int)$row['id']; ?>">
                                        <button class="btn btn-delete" type="submit">🗑 Delete</button>
                                    </form>

                                    <?php if ($row['status'] === 'returned'): ?>
                                        <span class="small">Completed item</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="12" class="empty">No items found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="section">
        <h2>📥 Manage Claims</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Claim ID</th>
                        <th>Item ID</th>
                        <th>Item Title</th>
                        <th>Claimed By</th>
                        <th>Reason</th>
                        <th>Color</th>
                        <th>Brand</th>
                        <th>Unique Mark</th>
                        <th>Lost Location</th>
                        <th>Lost Date</th>
                        <th>Proof</th>
                        <th>Chat</th>
                        <th>Claim Status</th>
                        <th>Claimed At</th>
                        <th>Approved At</th>
                        <th>Rejected At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($claims_result) > 0): ?>
                    <?php while ($c = mysqli_fetch_assoc($claims_result)): ?>
                        <tr>
                            <td>#<?= e($c['id']); ?></td>
                            <td>#<?= e($c['item_id']); ?></td>
                            <td><strong><?= e($c['item_title'] ?? 'Deleted Item'); ?></strong></td>
                            <td><?= e($c['user_email']); ?></td>
                            <td><?= e($c['claim_reason']); ?></td>
                            <td><?= e($c['item_color']); ?></td>
                            <td><?= e($c['brand']); ?></td>
                            <td><?= e($c['unique_mark']); ?></td>
                            <td><?= e($c['lost_location']); ?></td>
                            <td><?= e($c['lost_date']); ?></td>
                            <td>
                                <?php if (!empty($c['proof_image']) && file_exists(__DIR__ . '/claim_uploads/' . $c['proof_image'])): ?>
                                    <a class="proof-link" href="claim_uploads/<?= e($c['proof_image']); ?>" target="_blank">View Proof</a>
                                <?php else: ?>
                                    No Proof
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="messages.php?claim_id=<?= (int)$c['id']; ?>" class="btn-chat">💬 Open Chat</a>
                            </td>
                            <td>
                                <span class="badge <?= e($c['status']); ?>">
                                    <?= ucfirst(e($c['status'])); ?>
                                </span>
                            </td>
                            <td><?= e($c['created_at'] ?: 'N/A'); ?></td>
                            <td><?= e($c['approved_at'] ?: 'N/A'); ?></td>
                            <td><?= e($c['rejected_at'] ?: 'N/A'); ?></td>
                            <td>
                                <div class="actions">
                                    <?php if ($c['status'] === 'pending' && $c['item_status'] !== 'returned' && $c['item_status'] !== 'rejected'): ?>
                                        <form method="POST" onsubmit="return confirm('Approve this claim?');">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="action" value="approve_claim">
                                            <input type="hidden" name="claim_id" value="<?= (int)$c['id']; ?>">
                                            <button class="btn btn-approve" type="submit">✅ Approve</button>
                                        </form>

                                        <form method="POST" onsubmit="return confirm('Reject this claim?');">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="action" value="reject_claim">
                                            <input type="hidden" name="claim_id" value="<?= (int)$c['id']; ?>">
                                            <button class="btn btn-reject" type="submit">❌ Reject</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="small">No action needed</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="17" class="empty">No claims found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
</body>
</html>
