<?php
session_start();
require_once '../config.php';  // gives $pdo

// Ensure admin logged in
if (empty($_SESSION['user']) || ($_SESSION['user']['Role'] ?? '') !== 'Admin') {
    header("Location: ../login.php");
    exit();
}

$userID = $_SESSION['user']['UserID'];
$db     = $pdo;

/* ------------------------------------------------
   Flash messages
------------------------------------------------- */
$success = '';
if (isset($_GET['added']))    $success = 'User added successfully.';
if (isset($_GET['edited']))   $success = 'User updated successfully.';
if (isset($_GET['deleted']))  $success = 'User deleted (soft) successfully.';
if (isset($_GET['blocked']))  $success = 'User blocked successfully.';
if (isset($_GET['restored'])) $success = 'User reactivated successfully.'; // NEW

/* ------------------------------------------------
   ADD user
------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'add'
) {
    $username = trim($_POST['Username']);
    $email    = trim($_POST['Email']);
    $phone    = trim($_POST['Phone']);
    $gender   = $_POST['Gender'];
    $role     = $_POST['Role'];
    $password = $_POST['Password'];

    $passwordHash = sha1($password);

    // Photo upload (optional)
    $photoName = '';
    if (isset($_FILES['Photo']) && $_FILES['Photo']['error'] === UPLOAD_ERR_OK) {
        $tmpName  = $_FILES['Photo']['tmp_name'];
        $origName = basename($_FILES['Photo']['name']);
        $ext      = pathinfo($origName, PATHINFO_EXTENSION);
        $photoName = uniqid('user_') . '.' . $ext;

        $uploadDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        move_uploaded_file($tmpName, $uploadDir . $photoName);
    }

    $stmt = $db->prepare(
        'INSERT INTO user
           (Username, email, phone_number, gender, Role, Password, photo, IsDeleted)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0)'
    );
    $stmt->execute([$username, $email, $phone, $gender, $role, $passwordHash, $photoName]);

    header('Location: admin_users.php?added=1');
    exit;
}

/* ------------------------------------------------
   EDIT / BLOCK user
------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && !empty($_POST['UserID'])
) {
    $userId = (int)$_POST['UserID'];

    if ($_POST['action'] === 'edit') {
        $username = trim($_POST['Username']);
        $email    = trim($_POST['Email']);
        $phone    = trim($_POST['Phone']);
        $gender   = $_POST['Gender'];
        $role     = $_POST['Role'];

        $stmt = $db->prepare(
            'UPDATE user
             SET Username = ?, email = ?, phone_number = ?, gender = ?, Role = ?
             WHERE UserID = ?'
        );
        $stmt->execute([$username, $email, $phone, $gender, $role, $userId]);

        header('Location: admin_users.php?edited=1');
        exit;
    }

    if ($_POST['action'] === 'block') {
        $stmt = $db->prepare('UPDATE user SET Role = ? WHERE UserID = ?');
        $stmt->execute(['Blocked', $userId]);

        header('Location: admin_users.php?blocked=1');
        exit;
    }
}

/* ------------------------------------------------
   DELETE user (soft delete)
------------------------------------------------- */
if (isset($_GET['action'])
    && $_GET['action'] === 'delete'
    && !empty($_GET['UserID'])
) {
    $uid = (int)$_GET['UserID'];

    // Soft delete: mark as deleted, keep record for orders FK
    $stmt = $db->prepare(
        'UPDATE user
         SET IsDeleted = 1,
             DeletedAt = NOW()
         WHERE UserID = ?'
    );
    $stmt->execute([$uid]);

    header('Location: admin_users.php?deleted=1');
    exit;
}

/* ------------------------------------------------
   RESTORE user (reactivate soft-deleted)
------------------------------------------------- */
if (isset($_GET['action'])
    && $_GET['action'] === 'restore'
    && !empty($_GET['UserID'])
) {
    $uid = (int)$_GET['UserID'];

    $stmt = $db->prepare(
        'UPDATE user
         SET IsDeleted = 0,
             DeletedAt = NULL
         WHERE UserID = ?'
    );
    $stmt->execute([$uid]);

    header('Location: admin_users.php?restored=1');
    exit;
}

/* ------------------------------------------------
   DETAIL VIEW (with purchases)
------------------------------------------------- */
if (isset($_GET['action'])
    && $_GET['action'] === 'detail'
    && !empty($_GET['UserID'])
) {
    $userId = (int)$_GET['UserID'];

    // User basic info + soft delete flags
    $stmt = $db->prepare(
        'SELECT UserID, Username, email, phone_number, gender, Role, photo,
                IsDeleted, DeletedAt
         FROM user
         WHERE UserID = ?'
    );
    $stmt->execute([$userId]);
    $member = $stmt->fetch(PDO::FETCH_OBJ);

    // Purchases – orders made by this user
    $orderStmt = $db->prepare("
        SELECT
            o.OrderID,
            o.OrderDate,
            o.TotalAmt,
            o.Status,
            COUNT(oi.ProductID) AS item_count
        FROM orders o
        LEFT JOIN orderitem oi ON oi.OrderID = o.OrderID
        WHERE o.UserID = ?
        GROUP BY o.OrderID, o.OrderDate, o.TotalAmt, o.Status
        ORDER BY o.OrderDate DESC
        LIMIT 50
    ");
    $orderStmt->execute([$userId]);
    $orders = $orderStmt->fetchAll(PDO::FETCH_OBJ);

    include 'admin_header.php';
    ?>

    <main class="admin-main">
        <h2>User Detail</h2>

        <?php if ($member): ?>
            <div class="detail-wrapper">
                <div class="detail-card">
                    <?php
                    $filename = $member->photo;
                    $uploadDir = __DIR__ . '/uploads/';
                    $urlDir    = '../uploads/';
                    if (!empty($filename) && is_file($uploadDir . $filename)) {
                        $imgSrc = $urlDir . $filename;
                    } else {
                        $imgSrc = '../uploads/default_user.jpg';
                    }
                    ?>
                    <img src="<?= htmlspecialchars($imgSrc) ?>"
                         alt="Avatar"
                         class="detail-avatar">

                    <ul class="detail-list">
                        <li><strong>ID:</strong> <?= $member->UserID ?></li>
                        <li><strong>Username:</strong> <?= htmlspecialchars($member->Username) ?></li>
                        <li><strong>Email:</strong> <?= htmlspecialchars($member->email) ?></li>
                        <li><strong>Phone:</strong> <?= htmlspecialchars($member->phone_number) ?></li>
                        <li><strong>Gender:</strong> <?= htmlspecialchars($member->gender) ?></li>
                        <li><strong>Role:</strong> <?= htmlspecialchars($member->Role) ?></li>
                        <li>
                            <strong>Status:</strong>
                            <?php if ($member->IsDeleted): ?>
                                <span class="badge badge-deleted">Deleted</span>
                            <?php else: ?>
                                <span class="badge badge-active">Active</span>
                            <?php endif; ?>
                        </li>
                        <li>
                            <strong>Deleted At:</strong>
                            <?= $member->IsDeleted && $member->DeletedAt
                                ? htmlspecialchars($member->DeletedAt)
                                : '-' ?>
                        </li>
                    </ul>

                    <?php if ($member->IsDeleted): ?>
                        <form method="get" style="margin-top:10px;">
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="UserID" value="<?= (int)$member->UserID ?>">
                            <button type="submit"
                                    class="btn"
                                    style="background:#22c55e;color:#fff;"
                                    onclick="return confirm('Reactivate this user?');">
                                ♻️ Reactivate User
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="orders-card">
                    <h3>Purchases (Orders)</h3>
                    <?php if ($orders): ?>
                        <div class="admin-table-container">
                            <table class="admin-table small">
                                <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Items</th>
                                    <th>Total (RM)</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($orders as $o): ?>
                                    <tr>
                                        <td>#<?= (int)$o->OrderID ?></td>
                                        <td><?= htmlspecialchars(substr($o->OrderDate, 0, 16)) ?></td>
                                        <td><?= htmlspecialchars($o->Status) ?></td>
                                        <td><?= (int)$o->item_count ?></td>
                                        <td><?= number_format((float)$o->TotalAmt, 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p>This user has not placed any orders.</p>
                    <?php endif; ?>
                </div>
            </div>

            <p><a href="admin_users.php" class="btn">&larr; Back to User Listing</a></p>
        <?php else: ?>
            <p>User not found.</p>
            <p><a href="admin_users.php" class="btn">&larr; Back to User Listing</a></p>
        <?php endif; ?>
    </main>

    <style>
        .detail-wrapper {
            display: grid;
            grid-template-columns: minmax(260px, 320px) minmax(0, 1fr);
            gap: 20px;
            margin-top: 16px;
        }
        .detail-card,
        .orders-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 16px 18px;
            box-shadow: 0 6px 16px rgba(15,23,42,0.08);
        }
        .detail-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            display: block;
            margin-bottom: 10px;
        }
        .detail-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .detail-list li {
            margin-bottom: 6px;
            font-size: 0.9rem;
        }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.75rem;
        }
        .badge-active {
            background: #dcfce7;
            color: #166534;
        }
        .badge-deleted {
            background: #fee2e2;
            color: #b91c1c;
        }
    </style>

    <?php
    include 'admin_footer.php';
    exit;
}

/* ------------------------------------------------
   LISTING (with soft-delete columns)
------------------------------------------------- */
$search = trim($_GET['search'] ?? '');

$sql = 'SELECT UserID, Username, email, phone_number, gender, Role,
               photo, IsDeleted, DeletedAt
        FROM user';
$params = [];

if ($search !== '') {
    $sql .= ' WHERE Username LIKE :search OR email LIKE :search';
    $params[':search'] = "%{$search}%";
}
$sql .= ' ORDER BY UserID DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll(PDO::FETCH_OBJ);

include 'admin_header.php';
?>

<link rel="stylesheet" href="../assets/admin_product.css">

<main class="admin-main">
    <h2>User Listing</h2>

    <?php if ($success): ?>
        <script>alert("<?= addslashes($success) ?>");</script>
    <?php endif; ?>

    <form method="get" class="admin-form">
        <input type="text"
               name="search"
               value="<?= htmlspecialchars($search) ?>"
               placeholder="Search members by name or email..."
               style="width:400px;">
        <button type="submit">Search</button>
        <button type="button"
                id="addUserBtn"
                class="btn"
                style="background:#4da6ff; margin-right:auto;">
            ➕ Add New User
        </button>
    </form>

    <div class="admin-table-container">
        <table class="admin-table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Username</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Gender</th>
                <th>Role</th>
                <th>Deleted?</th>
                <th>Deleted At</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($members): ?>
                <?php foreach ($members as $m): ?>
                    <?php
                    $filename = $m->photo;
                    $uploadDir = __DIR__ . '/uploads/';
                    $urlDir    = 'uploads/';
                    if (!empty($filename) && is_file($uploadDir . $filename)) {
                        $thumbSrc = $urlDir . $filename;
                    } else {
                        $thumbSrc = '../uploads/default_user.jpg';
                    }
                    ?>
                    <tr>
                        <td><?= $m->UserID ?></td>
                        <td><?= htmlspecialchars($m->Username) ?></td>
                        <td><?= htmlspecialchars($m->email) ?></td>
                        <td><?= htmlspecialchars($m->phone_number) ?></td>
                        <td><?= htmlspecialchars($m->gender) ?></td>
                        <td><?= htmlspecialchars($m->Role) ?></td>
                        <td>
                            <?php if ($m->IsDeleted): ?>
                                <span class="badge badge-deleted">Deleted</span>
                            <?php else: ?>
                                <span class="badge badge-active">Active</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $m->IsDeleted && $m->DeletedAt ? htmlspecialchars($m->DeletedAt) : '-' ?></td>
                        <td>
                            <!-- View -->
                            <button type="button"
                                    class="viewBtn"
                                    onclick="window.location='admin_users.php?action=detail&UserID=<?= $m->UserID ?>';">
                                🔍
                            </button>

                            <?php if ($m->IsDeleted): ?>
                                <!-- Reactivate -->
                                <button type="button"
                                        class="restoreBtn"
                                        onclick="if(confirm('Reactivate this user?')) window.location='admin_users.php?action=restore&UserID=<?= $m->UserID ?>';">
                                    ♻️
                                </button>
                            <?php else: ?>
                                <!-- Edit -->
                                <button type="button"
                                        class="editBtn"
                                        data-id="<?= $m->UserID ?>">
                                    ✏️
                                </button>

                                <!-- Soft Delete -->
                                <button type="button"
                                        class="deleteBtn"
                                        onclick="if(confirm('Soft delete this user?')) window.location='admin_users.php?action=delete&UserID=<?= $m->UserID ?>';">
                                    🗑️
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="10">No members found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<!-- Add User Modal -->
<div id="addUserModal" class="modal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <h2>➕ Add New User</h2>
        <form method="post" id="addUserForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add">

            <label for="addUsername">Username:</label>
            <input type="text" id="addUsername" name="Username" required>

            <label for="addEmail">Email:</label>
            <input type="email" id="addEmail" name="Email" required>

            <label for="addPassword">Password:</label>
            <input type="password" id="addPassword" name="Password" required>

            <label for="addPhone">Phone:</label>
            <input type="text" id="addPhone" name="Phone">

            <label for="addGender">Gender:</label>
            <select id="addGender" name="Gender">
                <option value="Male">Male</option>
                <option value="Female">Female</option>
            </select>

            <label for="addRole">Role:</label>
            <select id="addRole" name="Role">
                <option value="Admin">Admin</option>
                <option value="Member">Member</option>
            </select>

            <label for="addPhoto">Photo:</label>
            <input type="file" id="addPhoto" name="Photo" accept="image/*">

            <button type="submit" class="btn">➕ Add User</button>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" class="modal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <h2>Edit User</h2>
        <form method="post" id="editUserForm">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" id="editUserID" name="UserID">

            <label for="editUsername">Username:</label>
            <input type="text" id="editUsername" name="Username" required>

            <label for="editEmail">Email:</label>
            <input type="email" id="editEmail" name="Email" required>

            <label for="editPhone">Phone:</label>
            <input type="text" id="editPhone" name="Phone">

            <label for="editGender">Gender:</label>
            <select id="editGender" name="Gender">
                <option value="Male">Male</option>
                <option value="Female">Female</option>
            </select>

            <label for="editRole">Role:</label>
            <select id="editRole" name="Role">
                <option value="Admin">Admin</option>
                <option value="Member">Member</option>
                <option value="Blocked">Blocked</option>
            </select>

            <button type="submit" class="btn">Update User</button>
            <button type="submit"
                    id="blockButton"
                    name="action"
                    value="block"
                    class="btn-danger"
                    onclick="return confirm('Are you sure you want to block this user?');">
                Block
            </button>
        </form>
    </div>
</div>

<?php include 'admin_footer.php'; ?>

<style>
    .admin-table-container {
        margin-top: 12px;
        overflow-x: auto;
    }
    .admin-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1200px; /* keeps columns readable but inside scroll */
    }
    .admin-table.small {
        min-width: 0;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    function bindModal(triggerSelector, modalId, onOpen) {
        const triggers = document.querySelectorAll(triggerSelector);
        const modal    = document.getElementById(modalId);
        if (!modal) return;
        const closeBtn = modal.querySelector('.close');

        triggers.forEach(btn => {
            btn.addEventListener('click', () => {
                if (onOpen) onOpen(btn);
                modal.style.display = 'flex';
            });
        });

        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                modal.style.display = 'none';
            });
        }

        window.addEventListener('click', e => {
            if (e.target === modal) {
                modal.style.display = 'none';
            }
        });
    }

    // Add user modal
    bindModal('#addUserBtn', 'addUserModal');

    // Edit user modal
    bindModal('.editBtn', 'editUserModal', btn => {
        const row = btn.closest('tr');
        if (!row) return;

        // Columns: 0 ID, 1 Username, 2 Email, 3 Phone, 4 Gender, 5 Role, 6 Deleted?, 7 DeletedAt, 8 Actions
        document.getElementById('editUserID').value   = row.cells[0].textContent.trim();
        document.getElementById('editUsername').value = row.cells[1].textContent.trim(); // FIXED
        document.getElementById('editEmail').value    = row.cells[2].textContent.trim(); // FIXED
        document.getElementById('editPhone').value    = row.cells[3].textContent.trim(); // FIXED
        document.getElementById('editGender').value   = row.cells[4].textContent.trim(); // FIXED
        document.getElementById('editRole').value     = row.cells[5].textContent.trim(); // FIXED
    });
});
</script>
