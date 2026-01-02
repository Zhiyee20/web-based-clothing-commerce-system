<?php
session_start();
require_once '../login_base.php';

// Helper: escape HTML
function h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Helper: money format
function money_rm($n)
{
    return 'RM ' . number_format((float)$n, 2, '.', ',');
}

// Assume user is already logged in
$userID = $_SESSION['user']['UserID'] ?? null;

// Redirect if not logged in
if (!$userID) {
    header("Location: login.php");
    exit();
}

// Fetch user details from the database
$stmt = $_db->prepare("SELECT Username, email, gender, photo FROM user WHERE UserID = ?");
$stmt->execute([$userID]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // If user record not found, force logout
    session_destroy();
    header("Location: login.php");
    exit();
}

$dbUsername = $user['Username'] ?? 'User';
$email      = $user['email'] ?? 'No email';
$gender     = strtolower(trim($user['gender'] ?? ''));
$photo      = !empty($user['photo']) ? $user['photo'] : 'default_user.jpg';

/* ============================
   CUSTOMER DASHBOARD METRICS
   ============================ */

// 1) Reward ledger (earned vs redeemed)
$rlStmt = $_db->prepare("
    SELECT 
      COALESCE(SUM(CASE WHEN Type='EARN'   THEN Points END),0) AS earned,
      COALESCE(SUM(CASE WHEN Type='REDEEM' THEN Points END),0) AS redeemed
    FROM reward_ledger
    WHERE UserID = ?
");
$rlStmt->execute([$userID]);
$rlRow          = $rlStmt->fetch(PDO::FETCH_ASSOC) ?: ['earned' => 0, 'redeemed' => 0];
$pointsEarned   = (int)$rlRow['earned'];
$pointsRedeemed = (int)$rlRow['redeemed'];

// 2) Orders summary (total, spent, last order date)
$orderSummaryStmt = $_db->prepare("
    SELECT 
      COUNT(*) AS total_orders,
      COALESCE(SUM(TotalAmt),0) AS total_spent,
      MAX(OrderDate) AS last_order
    FROM orders
    WHERE UserID = ?
");
$orderSummaryStmt->execute([$userID]);
$orderSummary = $orderSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_orders' => 0,
    'total_spent'  => 0,
    'last_order'   => null,
];
$totalOrders = (int)$orderSummary['total_orders'];
$totalSpent  = (float)$orderSummary['total_spent'];
$lastOrder   = $orderSummary['last_order'];

// 3) Orders by status
$statusCounts = [
    'Pending'                  => 0,
    'Shipped'                  => 0,
    'Delivered'                => 0,
    'Cancel / Return & Refund' => 0,
];

$statusStmt = $_db->prepare("
    SELECT Status, COUNT(*) AS cnt
    FROM orders
    WHERE UserID = ?
    GROUP BY Status
");
$statusStmt->execute([$userID]);
while ($row = $statusStmt->fetch(PDO::FETCH_ASSOC)) {
    $st = $row['Status'];
    if (isset($statusCounts[$st])) {
        $statusCounts[$st] = (int)$row['cnt'];
    }
}

// 4) Recent orders (latest 5)
$recentStmt = $_db->prepare("
    SELECT OrderID, OrderDate, TotalAmt, Status
    FROM orders
    WHERE UserID = ?
    ORDER BY OrderDate DESC
    LIMIT 5
");
$recentStmt->execute([$userID]);
$recentOrders = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

// 5) Monthly spending for this user (for bar chart)
$spendMonths   = [];
$spendAmounts  = [];
$spendStmt = $_db->prepare("
    SELECT DATE_FORMAT(OrderDate, '%Y-%m') AS ym,
           SUM(TotalAmt) AS total_spent
    FROM orders
    WHERE UserID = ?
      AND Status <> 'Cancel / Return & Refund'
    GROUP BY ym
    ORDER BY ym
");
$spendStmt->execute([$userID]);
while ($row = $spendStmt->fetch(PDO::FETCH_ASSOC)) {
    $spendMonths[]  = $row['ym'];
    $spendAmounts[] = (float)$row['total_spent'];
}

// JSON for charts
$spendMonthsJson       = json_encode($spendMonths);
$spendAmountsJson      = json_encode($spendAmounts);
$orderStatusLabels     = ['Pending', 'Shipped', 'Delivered', 'Cancel / Return & Refund'];
$orderStatusCounts     = [
    $statusCounts['Pending'],
    $statusCounts['Shipped'],
    $statusCounts['Delivered'],
    $statusCounts['Cancel / Return & Refund'],
];
$orderStatusLabelsJson  = json_encode($orderStatusLabels);
$orderStatusCountsJson  = json_encode($orderStatusCounts);
$pointsEarnedRedeemJson = json_encode([
    'earned'   => $pointsEarned,
    'redeemed' => $pointsRedeemed,
]);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Profile & Dashboard</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="../assets/profile.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        var userID = <?php echo json_encode($userID); ?>;
    </script>
    <script src="/../assets/app.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        /* FULL-WIDTH PAGE MAIN WRAPPER */
        body.customer-dashboard main.profile-main {
            max-width: 100% !important;
            margin: 0 !important;
            padding: 24px 32px 40px;
            background: #f3f4f6;
            box-shadow: none !important;
        }

        .profile-main {
            width: 100%;
        }

        /* =======================
           TOP STRIP – 2 COLS + PROFILE
           ======================= */
        .profile-top {
            width: 100%;
            display: grid;
            grid-template-columns: 260px 2fr;
            gap: 20px;
            align-items: flex-start;
            margin-bottom: 18px;
        }

        .profile-top>* {
            min-width: 0;
        }

        /* LEFT: profile card */
        .profile-info-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 18px 18px 20px;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
        }

        .avatar-container {
            width: 110px;
            height: 110px;
            border-radius: 999px;
            overflow: hidden;
            margin: 0 auto 14px;
            border: 2px solid #111827;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .avatar-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-box {
            border: 1px solid #111827;
            border-radius: 6px;
            padding: 6px 10px;
            margin-bottom: 8px;
            font-size: 0.9rem;
            text-align: center;
            background: #fff;
        }

        .profile-box-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #6b7280;
            margin-bottom: 2px;
        }

        /* MIDDLE: menu grid (4 x 2) */
        .profile-menu-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px 18px;
            padding-right: 40px;
        }

        .menu-button {
            width: 100%;
            box-sizing: border-box;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 10px;
            border: 2px solid #111827;
            background: #ffffff;
            cursor: pointer;
            transition: transform .15s ease, box-shadow .15s ease, background .15s ease, color .15s ease, border-color .15s ease;
            font-size: 0.95rem;
            height: 56px;
        }


        .menu-button-left {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .menu-button .icon {
            font-size: 1rem;
        }

        .menu-button .text {
            font-size: 0.95rem;
            white-space: nowrap;
        }

        .menu-button .arrow {
            font-size: 0.9rem;
        }

        .menu-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 14px rgba(15, 23, 42, 0.12);
            background: #f9fafb;
        }

        /* Reset Password hover */
        .menu-button.menu-reset {
            border-color: #111827;
            color: #111827;
        }

        .menu-button.menu-reset:hover {
            background: #111827;
            color: #fff;
        }

        /* Delete Account hover */
        .menu-button.menu-delete {
            border-color: #b91c1c;
            color: #b91c1c;
        }

        .menu-button.menu-delete:hover {
            background: #b91c1c;
            color: #fff;
        }

        /* ==============================
           BOTTOM DASHBOARD CONTAINER
           ============================== */
        .customer-dashboard-panel {
            width: 100%;
            background: #ffffff;
            border-radius: 16px;
            padding: 18px 20px 24px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
        }

        .customer-dashboard-panel h2 {
            margin: 0 0 6px;
            font-size: 22px;
            text-align: center;
        }

        .customer-dashboard-panel p.subtitle {
            margin: 0 0 18px;
            font-size: 0.9rem;
            color: #6b7280;
            text-align: center;
        }

        .section-title {
            margin: 18px 0 8px;
            font-size: 1rem;
            font-weight: 600;
        }

        .small-table-wrap {
            overflow-x: auto;
        }

        .small-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        .small-table th,
        .small-table td {
            padding: 6px 8px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            white-space: nowrap;
        }

        .small-table th {
            background: #f3f4f6;
        }

        .small-link {
            font-size: 0.8rem;
            text-decoration: none;
            color: #111827;
            border-radius: 999px;
            border: 1px solid #111827;
            padding: 4px 10px;
            display: inline-block;
            margin-top: 8px;
        }

        .small-link:hover {
            background: #111827;
            color: #ffffff;
        }

        .status-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.7rem;
            color: #111827;
            background: #e5e7eb;
        }

        .status-pill.Pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-pill.Shipped {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-pill.Delivered {
            background: #dcfce7;
            color: #166534;
        }


        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
            margin-top: 10px;
        }

        .chart-card {
            background: #f9fafb;
            border-radius: 12px;
            padding: 12px 14px 16px;
            border: 1px solid #e5e7eb;
        }

        .chart-card h4 {
            margin: 0 0 6px;
            font-size: 0.9rem;
        }

        .chart-box {
            width: 100%;
            height: 210px;
        }

        .chart-box canvas {
            width: 100% !important;
            height: 100% !important;
        }

        .recent-orders-wrap {
            max-height: 260px;
            overflow-y: auto;
        }

        /* Responsive */
        @media (max-width: 992px) {
            body.customer-dashboard main.profile-main {
                padding: 20px 16px 40px;
            }

            .profile-top {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body class="customer-dashboard">

    <?php if ($msg = temp('info')): ?>
        <div id="popupMessage" style="
        position: fixed;
        top: 100px;
        left: 50%;
        transform: translateX(-50%);
        background-color: #d4edda;
        color: #155724;
        padding: 15px 30px;
        border: 1px solid #c3e6cb;
        border-radius: 5px;
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
        z-index: 9999;
        font-weight: bold;
        text-align: center;">
            <?= h($msg) ?>
        </div>
        <script>
            setTimeout(function() {
                var p = document.getElementById('popupMessage');
                if (p) {
                    p.style.transition = "opacity 0.5s ease-out";
                    p.style.opacity = 0;
                    setTimeout(() => p.remove(), 500);
                }
            }, 3000);
        </script>
    <?php endif; ?>

    <?php include 'header.php'; ?>

    <main class="profile-main">
        <!-- TOP STRIP -->
        <section class="profile-top">
            <!-- LEFT: profile picture + name/email boxes -->
            <div class="profile-info-card">
                <div class="avatar-container">
                    <img src="<?= h('../uploads/' . $photo); ?>" alt="User Photo">
                </div>

                <div class="profile-box">
                    <div class="profile-box-label">Name</div>
                    <div>
                        <?= $gender === 'male' ? 'Mr. ' : ($gender === 'female' ? 'Ms. ' : ''); ?>
                        <?= h($dbUsername); ?>
                    </div>
                </div>

                <div class="profile-box">
                    <div class="profile-box-label">Email</div>
                    <div><?= h($email); ?></div>
                </div>
            </div>

            <!-- MIDDLE: menu buttons grid -->
            <div class="profile-menu-grid">
                <!-- Edit Profile -->
                <div class="menu-button" onclick="location.href='edit_details.php'">
                    <div class="menu-button-left">
                        <span class="icon">✏️</span>
                        <span class="text">Edit Profile</span>
                    </div>
                    <span class="arrow">></span>
                </div>

                <!-- Order History -->
                <div class="menu-button" onclick="location.href='order_history.php'">
                    <div class="menu-button-left">
                        <span class="icon">📦</span>
                        <span class="text">Order History</span>
                    </div>
                    <span class="arrow">></span>
                </div>

                <!-- Wishlist -->
                <div class="menu-button" onclick="location.href='wishlist.php'">
                    <div class="menu-button-left">
                        <span class="icon">❤️</span>
                        <span class="text">Wishlist</span>
                    </div>
                    <span class="arrow">></span>
                </div>

                <!-- My Complaints -->
                <div class="menu-button" onclick="location.href='my_complaints.php'">
                    <div class="menu-button-left">
                        <span class="icon">📝</span>
                        <span class="text">My Complaints</span>
                    </div>
                    <span class="arrow">></span>
                </div>

                <!-- My Address -->
                <div class="menu-button" onclick="location.href='myAddress.php'">
                    <div class="menu-button-left">
                        <span class="icon">🏠</span>
                        <span class="text">My Address</span>
                    </div>
                    <span class="arrow">></span>
                </div>

                <!-- Reward Points -->
                <div class="menu-button" onclick="location.href='points_report.php'">
                    <div class="menu-button-left">
                        <span class="icon">🪙</span>
                        <span class="text">Reward Points</span>
                    </div>
                    <span class="arrow">></span>
                </div>

                <!-- Reset Password – black, invert on hover -->
                <div class="menu-button menu-reset" onclick="location.href='/security/reset_password.php'">
                    <div class="menu-button-left">
                        <span class="icon">🔐</span>
                        <span class="text">Reset Password</span>
                    </div>
                    <span class="arrow">></span>
                </div>

                <div class="menu-button menu-delete"
                    id="delete-account"
                    data-userid="<?= h($_SESSION['user']['UserID']); ?>"
                    onclick="deleteAccount()">
                    <div class="menu-button-left">
                        <span class="icon">🗑️</span>
                        <span class="text">Delete Account</span>
                    </div>
                    <span class="arrow">></span>
                </div>

        </section>

        <!-- BOTTOM: full-width dashboard -->
        <section class="customer-dashboard-panel">
            <h2>My Dashboard</h2>
            <p class="subtitle">Quick overview of your orders, rewards &amp; activity.</p>

            <h3 class="section-title">My Activity Charts</h3>
            <div class="charts-grid">
                <div class="chart-card">
                    <h4>Monthly Spending (RM)</h4>
                    <div class="chart-box">
                        <canvas id="chartMonthlySpend"></canvas>
                    </div>
                </div>

                <div class="chart-card">
                    <h4>Orders by Status</h4>
                    <div class="chart-box">
                        <canvas id="chartOrderStatus"></canvas>
                    </div>
                </div>

                <div class="chart-card">
                    <h4>Points Earned vs Redeemed</h4>
                    <div class="chart-box">
                        <canvas id="chartPoints"></canvas>
                    </div>
                </div>
            </div>

            <h3 class="section-title">Recent Orders</h3>
            <div class="small-table-wrap recent-orders-wrap">
                <table class="small-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Order #</th>
                            <th>Status</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recentOrders): ?>
                            <?php foreach ($recentOrders as $o): ?>
                                <tr>
                                    <td><?= h(substr($o['OrderDate'], 0, 16)); ?></td>
                                    <td>#<?= (int)$o['OrderID']; ?></td>
                                    <td>
                                        <span class="status-pill <?= h($o['Status']); ?>">
                                            <?= h($o['Status']); ?>
                                        </span>
                                    </td>
                                    <td><?= money_rm($o['TotalAmt']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4">You have not placed any orders yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <a class="small-link" href="order_history.php">View full order history</a>
        </section>
    </main>

    <?php include 'footer.php'; ?>

    <script>
        (function() {
            const spendMonths = <?= $spendMonthsJson ?> || [];
            const spendAmounts = <?= $spendAmountsJson ?> || [];
            const orderStatusLabels = <?= $orderStatusLabelsJson ?> || [];
            const orderStatusCounts = <?= $orderStatusCountsJson ?> || [];
            const pointsData = <?= $pointsEarnedRedeemJson ?> || {
                earned: 0,
                redeemed: 0
            };

            // Monthly Spending (Bar)
            const spendCanvas = document.getElementById('chartMonthlySpend');
            if (spendCanvas && spendMonths.length) {
                new Chart(spendCanvas.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: spendMonths,
                        datasets: [{
                            label: 'Total Spending (RM)',
                            data: spendAmounts
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'RM'
                                }
                            }
                        }
                    }
                });
            }

            // Orders by Status (Pie)
            const statusCanvas = document.getElementById('chartOrderStatus');
            const totalStatus = orderStatusCounts.reduce((a, b) => a + b, 0);
            if (statusCanvas && totalStatus > 0) {
                new Chart(statusCanvas.getContext('2d'), {
                    type: 'pie',
                    data: {
                        labels: orderStatusLabels,
                        datasets: [{
                            label: 'Orders',
                            data: orderStatusCounts
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false
                    }
                });
            }

            // Points Earned vs Redeemed (Bar)
            const pointsCanvas = document.getElementById('chartPoints');
            if (pointsCanvas && (pointsData.earned > 0 || pointsData.redeemed > 0)) {
                new Chart(pointsCanvas.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: ['Earned', 'Redeemed'],
                        datasets: [{
                            label: 'Points',
                            data: [pointsData.earned, pointsData.redeemed]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Points'
                                }
                            }
                        }
                    }
                });
            }
        })();
    </script>


</body>

</html>