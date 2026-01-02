<?php
// admin/admin_category.php
require '../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// OPTIONAL: restrict to admin only
// if (empty($_SESSION['user']) || ($_SESSION['user']['Role'] ?? '') !== 'Admin') {
//     header('Location: ../login.php');
//     exit;
// }

// --- small helpers ---
function clean($v)
{
    return trim((string)$v);
}

function normalizeSizeGroup($value)
{
    $value = strtoupper(clean($value));
    $allowed = ['TOP', 'BOTTOM', 'DRESS'];
    if ($value === '' || !in_array($value, $allowed, true)) {
        return null; // store NULL if empty/invalid
    }
    return $value;
}

function h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// make sure $pdo exists
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

/* ==========================================================
   1) HANDLE POST ACTIONS (ADD / EDIT / DELETE / RESTORE)
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {
        // ---------- ADD ----------
        if (isset($_POST['add'])) {
            $name = clean($_POST['CategoryName'] ?? '');
            $sg   = normalizeSizeGroup($_POST['SizeGuideGroup'] ?? '');

            if ($name !== '') {
                $sql = "INSERT INTO categories (CategoryName, SizeGuideGroup, IsDeleted, DeletedAt)
                        VALUES (:name, :sg, 0, NULL)";
                $st = $pdo->prepare($sql);
                $st->execute([
                    ':name' => $name,
                    ':sg'   => $sg
                ]);
            }

            header('Location: admin_category.php');
            exit;
        }

        // ---------- EDIT ----------
        if (isset($_POST['edit'])) {
            $id   = (int)($_POST['CategoryID'] ?? 0);
            $name = clean($_POST['CategoryName'] ?? '');
            $sg   = normalizeSizeGroup($_POST['SizeGuideGroup'] ?? '');

            if ($id > 0 && $name !== '') {
                $sql = "UPDATE categories
                        SET CategoryName = :name,
                            SizeGuideGroup = :sg
                        WHERE CategoryID = :id";
                $st = $pdo->prepare($sql);
                $st->execute([
                    ':name' => $name,
                    ':sg'   => $sg,
                    ':id'   => $id
                ]);
            }

            header('Location: admin_category.php');
            exit;
        }

        // ---------- SOFT DELETE ----------
        if (isset($_POST['delete'])) {
            $id = (int)($_POST['CategoryID'] ?? 0);
            if ($id > 0) {
                $sql = "UPDATE categories
                        SET IsDeleted = 1,
                            DeletedAt = NOW()
                        WHERE CategoryID = :id";
                $st = $pdo->prepare($sql);
                $st->execute([':id' => $id]);
            }

            header('Location: admin_category.php');
            exit;
        }

        // ---------- RESTORE ----------
        if (isset($_POST['restore'])) {
            $id = (int)($_POST['CategoryID'] ?? 0);
            if ($id > 0) {
                $sql = "UPDATE categories
                        SET IsDeleted = 0,
                            DeletedAt = NULL
                        WHERE CategoryID = :id";
                $st = $pdo->prepare($sql);
                $st->execute([':id' => $id]);
            }

            header('Location: admin_category.php');
            exit;
        }
    } catch (Throwable $e) {
        // For debugging; you can change to logging if you want
        die('Error: ' . h($e->getMessage()));
    }

    // Fallback redirect
    header('Location: admin_category.php');
    exit;
}

/* ==========================================================
   2) AUTO-PURGE OLD SOFT-DELETED CATEGORIES + FETCH
   ========================================================== */

// 1) Permanently delete rows that have been soft-deleted for > 30 days
$pdo->exec("
    DELETE FROM categories
    WHERE IsDeleted = 1
      AND DeletedAt IS NOT NULL
      AND DeletedAt < (NOW() - INTERVAL 30 DAY)
");

// 2) Then fetch the remaining categories for display
$stmt = $pdo->query("
    SELECT CategoryID, CategoryName, SizeGuideGroup, IsDeleted, DeletedAt
    FROM categories
    ORDER BY CategoryID DESC
");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'admin_header.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Category Management</title>
    <link rel="stylesheet" href="../assets/admin_product.css">

    <style>
        .category-form {
            margin-top: 10px;
            margin-bottom: 15px;
        }

        .category-form input[type=text],
        .category-form select {
            padding: 10px;
            border-radius: 6px;
            border: 1.5px solid #ccc;
            margin-right: 8px;
            font-size: 0.95rem;
        }

        .category-form input[type=text] {
            width: 260px;
        }

        .category-form select {
            min-width: 160px;
        }

        .category-form button {
            padding: 10px 18px;
            background: #dee8feff;
            color: black;
            border: 2px solid #000;
            border-radius: 6px;
            cursor: pointer;
        }

        .category-form button:hover {
            background: #002246ff;
            border-radius: 6px;
        }

        .category-table {
            width: 100%;
            margin-top: 15px;
            border-collapse: collapse;
        }

        .category-table th,
        .category-table td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            text-align: left;
            vertical-align: middle;
        }

        .category-table th {
            background: #0b1a39;
            color: white;
        }

        .deleteBtn {
            background: #000000ff;
            color: white;
            border: 2px solid #000;
        }

        .deleteBtn:hover {
            background: #ffffffff;
            color: #000;
        }

        .restoreBtn {
            background: #ffc107;
            padding: 6px 10px;
            color: black;
            border: none;
            border-radius: 999px;
            cursor: pointer;
        }

        .restoreBtn:hover {
            background: #d39e00;
        }

        .deleted-badge {
            background: #ffdddd;
            color: #b30000;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.85rem;
        }

        .active-badge {
            background: #d4edda;
            color: #155724;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.85rem;
        }

        .sg-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 500;
            background: #e8f0ff;
            color: #12306b;
        }

        .sg-badge.sg-top {
            background: #e8f0ff;
            color: #12306b;
        }

        .sg-badge.sg-bottom {
            background: #e8fef1;
            color: #135b2b;
        }

        .sg-badge.sg-dress {
            background: #fff1f8;
            color: #7b184b;
        }

        .inline-form {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .inline-form input[type=text],
        .inline-form select {
            padding: 8px 10px;
            border-radius: 6px;
            border: 1.5px solid #ccc;
            font-size: 0.9rem;
        }

        .inline-form input[type=text] {
            width: 160px;
        }

        .inline-form select {
            min-width: 130px;
        }

        .deleted-days-left {
            margin-top: 4px;
            font-size: 0.8rem;
            color: #6b7280;
            /* subtle grey */
        }

        .deleted-days-left-inline {
            font-size: 0.8rem;
            color: #6b7280;
            white-space: nowrap;
        }

        .deleted-inline {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .deleted-days-left {
            font-size: 0.84rem;
            color: #6b7280;
            /* normal grey */
            white-space: nowrap;
        }

        /* < 24 hours or already due → turn red */
        .deleted-days-left.deleted-urgent {
            color: #b55050ff;
        }

        /* NEW: highlight row when navigated via #cat-XX from notifications */
        .category-table tr:target {
            background: #fff7ed;
            transition: background 0.3s ease;
        }
    </style>
</head>

<body>

    <h2>Category Management</h2>

    <!-- Add Category Form (POST to same page) -->
    <form class="category-form" action="admin_category.php" method="post">
        <input type="text" name="CategoryName" placeholder="Enter category name" required>

        <select name="SizeGuideGroup">
            <option value="">-- Size Guide Group --</option>
            <option value="TOP">TOP</option>
            <option value="BOTTOM">BOTTOM</option>
            <option value="DRESS">DRESS</option>
        </select>

        <button type="submit" name="add">Add Category</button>
    </form>

    <!-- Category Table -->
    <table class="category-table">
        <tr>
            <th>ID</th>
            <th>Category Name</th>
            <th>Size Guide Group</th>
            <th>Status</th>
            <th>Deleted At</th>
            <th>Actions</th>
        </tr>

        <?php foreach ($categories as $category): ?>
            <?php
            $sg = $category['SizeGuideGroup'] ?? null;
            $sgLabel = '';
            $sgClass = '';

            if ($sg === 'TOP') {
                $sgLabel = 'TOP';
                $sgClass = 'sg-top';
            } elseif ($sg === 'BOTTOM') {
                $sgLabel = 'BOTTOM';
                $sgClass = 'sg-bottom';
            } elseif ($sg === 'DRESS') {
                $sgLabel = 'DRESS';
                $sgClass = 'sg-dress';
            }

            // ===== Remaining time until permanent deletion (30 days) =====
            $daysLeftText = '';
            $isUrgent = false;

            if ((int)$category['IsDeleted'] === 1 && !empty($category['DeletedAt'])) {
                try {
                    $deletedAt = new DateTime($category['DeletedAt']);
                    // expiry = deleted time + 30 days
                    $expiry   = (clone $deletedAt)->modify('+30 days');
                    $now      = new DateTime();

                    if ($now >= $expiry) {
                        // already passed 30 days
                        $daysLeftText = 'Pending permanent deletion';
                        $isUrgent = true;
                    } else {
                        $interval = $now->diff($expiry); // difference from now to expiry

                        if ($interval->days >= 1) {
                            // ≥ 1 full day left → show days
                            $days = $interval->days;
                            $daysLeftText = $days . ' day' . ($days !== 1 ? 's' : '') . ' remaining before permanent deletion';
                        } else {
                            // < 1 day left → show hours + minutes
                            $hours   = $interval->h;
                            $minutes = $interval->i;

                            $isUrgent = true; // less than 24 hours → mark as urgent (red)

                            if ($hours > 0) {
                                $daysLeftText = $hours . ' hour' . ($hours !== 1 ? 's' : '');
                            }
                            if ($minutes > 0) {
                                $daysLeftText .= ($hours > 0 ? ' ' : '') .
                                    $minutes . ' minute' . ($minutes !== 1 ? 's' : '');
                            }

                            if ($daysLeftText === '') {
                                $daysLeftText = 'Less than 1 minute';
                            }

                            $daysLeftText .= ' remaining before permanent deletion';
                        }
                    }
                } catch (Exception $e) {
                    $daysLeftText = '';
                    $isUrgent = false;
                }
            }
            ?>
            <!-- NEW: anchor id so notification URL #cat-ID can scroll here -->
            <tr id="cat-<?= (int)$category['CategoryID'] ?>">
                <td><?= (int)$category['CategoryID'] ?></td>
                <td><?= h($category['CategoryName']) ?></td>

                <td>
                    <?php if ($sgLabel): ?>
                        <span class="sg-badge <?= $sgClass ?>"><?= h($sgLabel) ?></span>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>

                <td>
                    <?php if ((int)$category['IsDeleted'] === 1): ?>
                        <span class="deleted-badge">Deleted</span>
                    <?php else: ?>
                        <span class="active-badge">Active</span>
                    <?php endif; ?>
                </td>

                <td><?= (int)$category['IsDeleted'] === 1 ? h($category['DeletedAt']) : '-' ?></td>

                <td>
                    <?php if ((int)$category['IsDeleted'] === 0): ?>
                        <!-- Edit -->
                        <form action="admin_category.php" method="post" class="inline-form">
                            <input type="hidden" name="CategoryID" value="<?= (int)$category['CategoryID'] ?>">

                            <input type="text" name="CategoryName"
                                value="<?= h($category['CategoryName']) ?>" required>

                            <select name="SizeGuideGroup">
                                <option value="">-- Size Guide Group --</option>
                                <option value="TOP" <?= $sg === 'TOP'    ? 'selected' : '' ?>>TOP</option>
                                <option value="BOTTOM" <?= $sg === 'BOTTOM' ? 'selected' : '' ?>>BOTTOM</option>
                                <option value="DRESS" <?= $sg === 'DRESS'  ? 'selected' : '' ?>>DRESS</option>
                            </select>

                            <button type="submit" name="edit" class="edit-btn">Edit</button>
                        </form>

                        <!-- Soft Delete -->
                        <form action="admin_category.php" method="post" style="display:inline;">
                            <input type="hidden" name="CategoryID" value="<?= (int)$category['CategoryID'] ?>">
                            <button type="submit" name="delete" class="deleteBtn"
                                onclick="return confirm('Soft delete this category?');">
                                Delete
                            </button>
                        </form>
                    <?php else: ?>
                        <!-- Restore -->
                        <form action="admin_category.php" method="post" class="deleted-inline">
                            <input type="hidden" name="CategoryID" value="<?= (int)$category['CategoryID'] ?>">
                            <button type="submit" name="restore" class="restoreBtn">Restore</button>

                            <?php if ($daysLeftText): ?>
                                <span class="deleted-days-left <?= $isUrgent ? 'deleted-urgent' : '' ?>">
                                    <?= h($daysLeftText) ?>
                                </span>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>

    <script>
        // Scroll & highlight category row when opened via hash from notifications
        window.addEventListener('load', function() {
            const hash = window.location.hash || '';
            if (!hash) return;

            // Expect hashes like "#cat-5"
            if (!hash.startsWith('#cat-')) return;

            const rowId = hash.substring(1); // "cat-5"
            const row = document.getElementById(rowId);
            if (!row) return;

            // Add highlight class
            row.classList.add('highlight-row');

            // Scroll it into the center of viewport
            row.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });

            // Remove highlight after a short delay (same behaviour as orders)
            setTimeout(function() {
                row.classList.remove('highlight-row');
            }, 2500); // 2.5s
        });
    </script>

</body>

</html>

<?php include 'admin_footer.php'; ?>