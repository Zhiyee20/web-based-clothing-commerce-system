<?php
// dashboard.php (Reward Dashboard – charts only)
require '../config.php';
session_start();

// only admins
$user = $_SESSION['user'] ?? null;
if (!$user || $user['Role'] !== 'Admin') {
  header('Location: login.php');
  exit;
}

/* ===========
   DATE RANGE 
   ===========*/
$periodStart = isset($_GET['start']) && $_GET['start'] !== '' ? $_GET['start'] : null;
$periodEnd   = isset($_GET['end'])   && $_GET['end']   !== '' ? $_GET['end']   : null;

$today = date('Y-m-d');

// for quick report links
$qs = http_build_query([
  'start' => $periodStart,
  'end'   => $periodEnd,
]);

/* =========================================
   REWARD KPI + CHART DATA (YOUR ORIGINAL)
   ========================================= */
$dateClause = '';
$paramsCommon = [];

if ($periodStart) {
  $dateClause          .= " AND rl.CreatedAt >= :ps";
  $paramsCommon[':ps']  = $periodStart . ' 00:00:00';
}
if ($periodEnd) {
  $dateClause          .= " AND rl.CreatedAt <= :pe";
  $paramsCommon[':pe']  = $periodEnd . ' 23:59:59';
}

/* Points Earned (period) */
$sqlEarned = "
  SELECT COALESCE(SUM(rl.Points),0) AS earned_points
  FROM reward_ledger rl
  WHERE rl.Type = 'EARN'
  $dateClause
";
$stmt = $pdo->prepare($sqlEarned);
$stmt->execute($paramsCommon);
$kpiEarned = (int)$stmt->fetchColumn();

/* Points Redeemed (period) */
$sqlRedeem = "
  SELECT COALESCE(SUM(rl.Points),0) AS redeemed_points
  FROM reward_ledger rl
  WHERE rl.Type = 'REDEEM'
  $dateClause
";
$stmt = $pdo->prepare($sqlRedeem);
$stmt->execute($paramsCommon);
$kpiRedeemed = (int)$stmt->fetchColumn();

/* Redemption Rate (period) */
$kpiRate = $kpiEarned > 0 ? round(($kpiRedeemed / $kpiEarned) * 100, 2) : 0.00;

/* Total Redemption Value (points * tier conversion) */
$sqlRedeemValue = "
  SELECT COALESCE(SUM(rl.Points * rt.ConversionRate),0) AS total_value
  FROM reward_ledger rl
  JOIN reward_points rp ON rp.UserID = rl.UserID
  JOIN reward_tiers rt ON rp.Accumulated BETWEEN rt.MinPoints AND rt.MaxPoints
  WHERE rl.Type = 'REDEEM'
  $dateClause
";
$stmt = $pdo->prepare($sqlRedeemValue);
$stmt->execute($paramsCommon);
$kpiRedeemValue = (float)$stmt->fetchColumn();

/* Monthly Earned vs Redeemed within range (or all time if no dates) */
$sqlMonthly = "
  SELECT ym, 
         SUM(CASE WHEN Type='EARN'   THEN Points ELSE 0 END) AS earned,
         SUM(CASE WHEN Type='REDEEM' THEN Points ELSE 0 END) AS redeemed
  FROM (
    SELECT DATE_FORMAT(rl.CreatedAt, '%Y-%m') AS ym, rl.Type, rl.Points
    FROM reward_ledger rl
    WHERE rl.Type IN ('EARN','REDEEM')
    $dateClause
  ) t
  GROUP BY ym
  ORDER BY ym
";
$stmt = $pdo->prepare($sqlMonthly);
$stmt->execute($paramsCommon);
$monthlyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$chartMonths   = [];
$chartEarned   = [];
$chartRedeemed = [];
$chartRatePct  = [];
foreach ($monthlyRows as $r) {
  $chartMonths[]   = $r['ym'];
  $e = (int)$r['earned'];
  $d = (int)$r['redeemed'];
  $chartEarned[]   = $e;
  $chartRedeemed[] = $d;
  $chartRatePct[]  = $e > 0 ? round(($d / $e) * 100, 2) : 0.00;
}

/* Tier Distribution (current, no date filter) */
$sqlTierDist = "
  SELECT rt.TierName, COUNT(*) AS users_count
  FROM reward_points rp
  JOIN reward_tiers rt ON rp.Accumulated BETWEEN rt.MinPoints AND rt.MaxPoints
  GROUP BY rt.TierName
  ORDER BY rt.MinPoints
";
$tierDist = $pdo->query($sqlTierDist)->fetchAll(PDO::FETCH_ASSOC);

/* Top Redeemers (period/all) */
$sqlTopRedeemers = "
  SELECT u.Username,
         SUM(rl.Points) AS points_redeemed,
         SUM(rl.Points * rt.ConversionRate) AS discount_value
  FROM reward_ledger rl
  JOIN user u ON u.UserID = rl.UserID
  JOIN reward_points rp ON rp.UserID = rl.UserID
  JOIN reward_tiers rt ON rp.Accumulated BETWEEN rt.MinPoints AND rt.MaxPoints
  WHERE rl.Type = 'REDEEM'
  $dateClause
  GROUP BY rl.UserID
  ORDER BY points_redeemed DESC
  LIMIT 10
";
$stmt = $pdo->prepare($sqlTopRedeemers);
$stmt->execute($paramsCommon);
$topRedeemers = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* User Reward Points (period totals + current balance/tier) */
$dateClauseJoin = '';
if ($periodStart) {
  $dateClauseJoin .= " AND rl.CreatedAt >= :ps";
}
if ($periodEnd) {
  $dateClauseJoin .= " AND rl.CreatedAt <= :pe";
}

$sqlUserTotals = "
  SELECT u.Username,
         COALESCE(SUM(CASE WHEN rl.Type='EARN'   THEN rl.Points END),0) AS earned_period,
         COALESCE(SUM(CASE WHEN rl.Type='REDEEM' THEN rl.Points END),0) AS redeemed_period
  FROM user u
  LEFT JOIN reward_ledger rl ON rl.UserID = u.UserID
       AND rl.Type IN ('EARN','REDEEM')
       $dateClauseJoin
  GROUP BY u.UserID
";
$stmt = $pdo->prepare($sqlUserTotals);
$stmt->execute($paramsCommon);
$userPeriod = $stmt->fetchAll(PDO::FETCH_ASSOC);

$byUser = [];
foreach ($userPeriod as $r) {
  $byUser[$r['Username']] = [
    'earned_period'   => (int)$r['earned_period'],
    'redeemed_period' => (int)$r['redeemed_period'],
  ];
}

/* Current balances + tier + discount */
$sqlBalances = "
  SELECT u.Username, rp.Balance, rp.Accumulated, rt.TierName, rt.ConversionRate
  FROM user u
  JOIN reward_points rp ON rp.UserID = u.UserID
  JOIN reward_tiers  rt ON rp.Accumulated BETWEEN rt.MinPoints AND rt.MaxPoints
";
$balances = $pdo->query($sqlBalances)->fetchAll(PDO::FETCH_ASSOC);

$userRewardsTable = [];
foreach ($balances as $b) {
  $uname     = $b['Username'];
  $earnedP   = $byUser[$uname]['earned_period']   ?? 0;
  $redeemP   = $byUser[$uname]['redeemed_period'] ?? 0;
  $discountP = $redeemP * (float)$b['ConversionRate'];

  $userRewardsTable[] = [
    'Username'            => $uname,
    'TotalPointsEarned'   => $earnedP,
    'TotalPointsRedeemed' => $redeemP,
    'Balance'             => (int)$b['Balance'],
    'CurrentTier'         => $b['TierName'],
    'TotalDiscount'       => $discountP,
  ];
}

/* JSON for Reward JS */
$chartMonthsJson   = json_encode($chartMonths);
$chartEarnedJson   = json_encode($chartEarned);
$chartRedeemedJson = json_encode($chartRedeemed);
$chartRateJson     = json_encode($chartRatePct);
$tierDistJson      = json_encode($tierDist);
$topRedeemersJson  = json_encode($topRedeemers);
$userRewardsJson   = json_encode($userRewardsTable);

/* ===========================
   SALES & ORDERS ANALYTICS
   =========================== */

// orders(OrderID, UserID, OrderDate, TotalAmt, Status,...)
$orderDateClause = '';
$orderParams = [];

if ($periodStart) {
  $orderDateClause         .= " AND o.OrderDate >= :od_from";
  $orderParams[':od_from']  = $periodStart . ' 00:00:00';
}
if ($periodEnd) {
  $orderDateClause         .= " AND o.OrderDate <= :od_to";
  $orderParams[':od_to']    = $periodEnd . ' 23:59:59';
}

// KPI: total sales, total orders (exclude cancelled/refunded)
$sqlSalesKpi = "
  SELECT 
    COALESCE(SUM(o.TotalAmt),0) AS total_sales,
    COUNT(*) AS total_orders
  FROM orders o
  WHERE o.Status <> 'Cancel / Return & Refund'
  $orderDateClause
";
$stmt = $pdo->prepare($sqlSalesKpi);
$stmt->execute($orderParams);
$kpiSalesRow    = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_sales' => 0, 'total_orders' => 0];
$kpiTotalSales  = (float)$kpiSalesRow['total_sales'];
$kpiTotalOrders = (int)$kpiSalesRow['total_orders'];
$kpiAvgOrderValue = $kpiTotalOrders > 0 ? $kpiTotalSales / $kpiTotalOrders : 0.0;

// Orders today
$sqlOrdersToday = "
  SELECT COUNT(*)
  FROM orders o
  WHERE DATE(o.OrderDate) = :tod
";
$stmt = $pdo->prepare($sqlOrdersToday);
$stmt->execute([':tod' => $today]);
$kpiOrdersToday = (int)$stmt->fetchColumn();

// Monthly sales trend
$sqlSalesMonthly = "
  SELECT DATE_FORMAT(o.OrderDate, '%Y-%m') AS ym,
         SUM(o.TotalAmt) AS total_sales
  FROM orders o
  WHERE o.Status <> 'Cancel / Return & Refund'
  $orderDateClause
  GROUP BY ym
  ORDER BY ym
";
$stmt = $pdo->prepare($sqlSalesMonthly);
$stmt->execute($orderParams);
$salesMonthlyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$salesMonths = [];
$salesAmount = [];
foreach ($salesMonthlyRows as $r) {
  $salesMonths[] = $r['ym'];
  $salesAmount[] = (float)$r['total_sales'];
}

// Orders by status
$sqlOrderStatus = "
  SELECT o.Status, COUNT(*) AS cnt
  FROM orders o
  WHERE 1=1
  $orderDateClause
  GROUP BY o.Status
";
$stmt = $pdo->prepare($sqlOrderStatus);
$stmt->execute($orderParams);
$orderStatusRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Top 10 products by quantity sold
// orderitem(OrderID, ProductID, Quantity, Price), product(ProductID, Name)
$sqlTopProducts = "
  SELECT p.Name AS ProductName,
         SUM(oi.Quantity) AS qty_sold,
         SUM(oi.Quantity * oi.Price) AS revenue
  FROM orderitem oi
  JOIN orders o  ON o.OrderID   = oi.OrderID
  JOIN product p ON p.ProductID = oi.ProductID
  WHERE o.Status <> 'Cancel / Return & Refund'
  $orderDateClause
  GROUP BY p.ProductID
  ORDER BY qty_sold DESC
  LIMIT 10
";
$stmt = $pdo->prepare($sqlTopProducts);
$stmt->execute($orderParams);
$topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* JSON for Sales JS */
$salesMonthsJson = json_encode($salesMonths);
$salesAmountJson = json_encode($salesAmount);
$orderStatusJson = json_encode($orderStatusRows);

/* ===========================
   INVENTORY & LOW STOCK
   =========================== */
/*
  New logic:
  - Current stock lives in product_color_sizes (per Color + Size).
  - product_color_sizes.Stock, product_color_sizes.MinStock
  - Joined to product + categories for price & category info.
*/

// 1) Inventory KPI – based on product variants (Color + Size)
$sqlInvKpi = "
  SELECT 
    COUNT(DISTINCT p.ProductID) AS total_products,
    SUM(CASE WHEN pcs.Stock <= 0 THEN 1 ELSE 0 END) AS out_of_stock_variants,
    SUM(CASE WHEN pcs.Stock > 0 AND pcs.Stock < pcs.MinStock THEN 1 ELSE 0 END) AS low_stock_variants,
    COALESCE(SUM(pcs.Stock * p.Price),0) AS stock_value
  FROM product_color_sizes pcs
  JOIN product_colors pc ON pc.ProductColorID = pcs.ProductColorID
  JOIN product p        ON p.ProductID       = pc.ProductID
";
$invRow = $pdo->query($sqlInvKpi)->fetch(PDO::FETCH_ASSOC) ?: [
  'total_products'        => 0,
  'out_of_stock_variants' => 0,
  'low_stock_variants'    => 0,
  'stock_value'           => 0,
];

$invTotalProducts = (int)$invRow['total_products'];
$invOutOfStock    = (int)$invRow['out_of_stock_variants'];
$invLowStock      = (int)$invRow['low_stock_variants'];
$invStockValue    = (float)$invRow['stock_value'];

// 2) Low-stock variants (for detail / drill-down – still used by detail report & can be
//    shown if you re-enable the table section)
$sqlLowStock = "
  SELECT 
    p.ProductID,
    p.Name AS Name,
    COALESCE(c.CategoryName, 'Uncategorised') AS CategoryName,
    pc.ColorName,
    pcs.Size,
    pcs.Stock,
    pcs.MinStock
  FROM product_color_sizes pcs
  JOIN product_colors pc ON pc.ProductColorID = pcs.ProductColorID
  JOIN product p        ON p.ProductID       = pc.ProductID
  LEFT JOIN categories c ON c.CategoryID     = p.CategoryID
  WHERE pcs.Stock <= 0 OR pcs.Stock < pcs.MinStock
  ORDER BY pcs.Stock ASC, p.Name, pc.ColorName, pcs.Size
  LIMIT 10
";
$lowStockRows = $pdo->query($sqlLowStock)->fetchAll(PDO::FETCH_ASSOC);

// 3) Stock by category (sum of all variant stock)
$sqlStockByCat = "
  SELECT 
    COALESCE(c.CategoryName, 'Uncategorised') AS cat,
    COALESCE(SUM(pcs.Stock), 0) AS qty
  FROM product_color_sizes pcs
  JOIN product_colors pc ON pc.ProductColorID = pcs.ProductColorID
  JOIN product p        ON p.ProductID       = pc.ProductID
  LEFT JOIN categories c ON c.CategoryID     = p.CategoryID
  GROUP BY c.CategoryID, cat
  ORDER BY cat
";
$stockByCatRows = $pdo->query($sqlStockByCat)->fetchAll(PDO::FETCH_ASSOC);
$stockByCatJson = json_encode($stockByCatRows);

/* ===========================
   FEEDBACK & RATING ANALYTICS
   =========================== */
// feedback(FeedbackID, UserID, Type, FeedbackText, Rating, CreatedAt)
// product_ratings(ProductID, UserID, Rating, CreatedAt)
// product_reviews(ProductID, UserID, Comment, CreatedAt)

$fbDateClause = '';
$fbParams = [];
if ($periodStart) {
  $fbDateClause        .= " AND f.CreatedAt >= :fb_from";
  $fbParams[':fb_from'] = $periodStart . ' 00:00:00';
}
if ($periodEnd) {
  $fbDateClause        .= " AND f.CreatedAt <= :fb_to";
  $fbParams[':fb_to']   = $periodEnd . ' 23:59:59';
}

// Feedback KPIs (all types)
$sqlFbKpi = "
  SELECT 
    COUNT(*) AS total_fb,
    AVG(f.Rating) AS avg_rating,
    SUM(CASE WHEN DATE(f.CreatedAt) = :tod THEN 1 ELSE 0 END) AS today_fb,
    SUM(CASE WHEN f.Rating >= 4 THEN 1 ELSE 0 END) AS high_fb
  FROM feedback f
  WHERE 1=1
  $fbDateClause
";
$fbParams[':tod'] = $today;
$stmt = $pdo->prepare($sqlFbKpi);
$stmt->execute($fbParams);
$fbRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
  'total_fb'   => 0,
  'avg_rating' => null,
  'today_fb'   => 0,
  'high_fb'    => 0,
];
$fbTotal     = (int)$fbRow['total_fb'];
$fbAvg       = $fbRow['avg_rating'] !== null ? round((float)$fbRow['avg_rating'], 2) : 0.0;
$fbToday     = (int)$fbRow['today_fb'];
$fbHighCount = (int)$fbRow['high_fb'];
$fbHighPct   = $fbTotal > 0 ? round(($fbHighCount / $fbTotal) * 100, 2) : 0.0;

// Product rating distribution (use product_ratings)
$prDateClause = '';
$prParams = [];
if ($periodStart) {
  $prDateClause        .= " AND pr.CreatedAt >= :pr_from";
  $prParams[':pr_from'] = $periodStart . ' 00:00:00';
}
if ($periodEnd) {
  $prDateClause        .= " AND pr.CreatedAt <= :pr_to";
  $prParams[':pr_to']   = $periodEnd . ' 23:59:59';
}

$sqlRatingDist = "
  SELECT pr.Rating, COUNT(*) AS cnt
  FROM product_ratings pr
  WHERE 1=1
  $prDateClause
  GROUP BY pr.Rating
  ORDER BY pr.Rating
";
$stmt = $pdo->prepare($sqlRatingDist);
$stmt->execute($prParams);
$ratingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ratingLabels = [];
$ratingCounts = [];
foreach ($ratingRows as $r) {
  $ratingLabels[] = (float)$r['Rating'];
  $ratingCounts[] = (int)$r['cnt'];
}
$ratingLabelsJson = json_encode($ratingLabels);
$ratingCountsJson = json_encode($ratingCounts);

// Latest product reviews (table)
$sqlLatestReviews = "
  SELECT pr.CreatedAt, u.Username, p.Name AS ProductName, pr.Comment
  FROM product_reviews pr
  JOIN product p ON p.ProductID = pr.ProductID
  JOIN user u    ON u.UserID    = pr.UserID
  ORDER BY pr.CreatedAt DESC
  LIMIT 5
";
$latestReviews = $pdo->query($sqlLatestReviews)->fetchAll(PDO::FETCH_ASSOC);

/* =========================================
   CUSTOMER SERVICE / TICKET MONITORING
   ========================================= */
// Use ordercancellation as "tickets"
// ordercancellation(cancellationId, OrderID, Reason, Status, RequestedAt,...)

$ocDateClause = '';
$ocParams = [];
if ($periodStart) {
  $ocDateClause        .= " AND oc.RequestedAt >= :oc_from";
  $ocParams[':oc_from'] = $periodStart . ' 00:00:00';
}
if ($periodEnd) {
  $ocDateClause        .= " AND oc.RequestedAt <= :oc_to";
  $ocParams[':oc_to']   = $periodEnd . ' 23:59:59';
}

$sqlOcKpi = "
  SELECT 
    COUNT(*) AS total_requests,
    SUM(CASE WHEN oc.Status = 'Pending'  THEN 1 ELSE 0 END) AS pending_requests,
    SUM(CASE WHEN oc.Status = 'Approved' THEN 1 ELSE 0 END) AS approved_requests,
    SUM(CASE WHEN oc.Status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_requests
  FROM ordercancellation oc
  WHERE 1=1
  $ocDateClause
";
$stmt = $pdo->prepare($sqlOcKpi);
$stmt->execute($ocParams);
$ocRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
  'total_requests'   => 0,
  'pending_requests' => 0,
  'approved_requests' => 0,
  'rejected_requests' => 0,
];
$csTotalTickets   = (int)$ocRow['total_requests'];
$csPendingTickets = (int)$ocRow['pending_requests'];
$csApprovedTickets = (int)$ocRow['approved_requests'];
$csRejectedTickets = (int)$ocRow['rejected_requests'];

// Ticket status distribution for chart
$sqlOcStatus = "
  SELECT oc.Status, COUNT(*) AS cnt
  FROM ordercancellation oc
  WHERE 1=1
  $ocDateClause
  GROUP BY oc.Status
";
$stmt = $pdo->prepare($sqlOcStatus);
$stmt->execute($ocParams);
$cancelStatusRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$cancelStatusJson = json_encode($cancelStatusRows);

// Latest tickets table
$sqlLatestTickets = "
  SELECT oc.cancellationId, oc.OrderID, oc.Status, oc.Reason, oc.RequestedAt,
         u.Username
  FROM ordercancellation oc
  JOIN orders o ON o.OrderID = oc.OrderID
  LEFT JOIN user u ON u.UserID = o.UserID
  ORDER BY oc.RequestedAt DESC
  LIMIT 5
";
$latestTickets = $pdo->query($sqlLatestTickets)->fetchAll(PDO::FETCH_ASSOC);

// Active deliveries (rough CS/shipping metric)
$sqlActiveDeliveries = "
  SELECT COUNT(*)
  FROM delivery d
  WHERE d.Status IN ('PickUp','WareHouse','Transit','OutOfDelivery')
";
$activeDeliveries = (int)($pdo->query($sqlActiveDeliveries)->fetchColumn() ?: 0);

/* =====================================
   SYSTEM ACTIVITY & ALERT-LIKE METRICS
   ===================================== */

// User counts
$sqlUserCounts = "
  SELECT
    COUNT(*) AS total_users,
    SUM(CASE WHEN Role='Admin'  THEN 1 ELSE 0 END) AS admins,
    SUM(CASE WHEN Role='Member' THEN 1 ELSE 0 END) AS members
  FROM user
";
$userCountsRow = $pdo->query($sqlUserCounts)->fetch(PDO::FETCH_ASSOC) ?: [
  'total_users' => 0,
  'admins'      => 0,
  'members'     => 0,
];
$totalUsers = (int)$userCountsRow['total_users'];
$totalAdmins = (int)$userCountsRow['admins'];
$totalMembers = (int)$userCountsRow['members'];

// Order status summary (all time)
$sqlOrderStatusSummary = "
  SELECT
    SUM(CASE WHEN Status='Pending' THEN 1 ELSE 0 END) AS pending_orders,
    SUM(CASE WHEN Status='Shipped' THEN 1 ELSE 0 END) AS shipped_orders,
    SUM(CASE WHEN Status='Delivered' THEN 1 ELSE 0 END) AS delivered_orders,
    SUM(CASE WHEN Status='Cancel / Return & Refund' THEN 1 ELSE 0 END) AS cancelled_orders,
    COUNT(*) AS total_orders
  FROM orders
";
$orderStatusSummary = $pdo->query($sqlOrderStatusSummary)->fetch(PDO::FETCH_ASSOC) ?: [
  'pending_orders'   => 0,
  'shipped_orders'   => 0,
  'delivered_orders' => 0,
  'cancelled_orders' => 0,
  'total_orders'     => 0,
];
$pendingOrdersAll   = (int)$orderStatusSummary['pending_orders'];
$shippedOrdersAll   = (int)$orderStatusSummary['shipped_orders'];
$deliveredOrdersAll = (int)$orderStatusSummary['delivered_orders'];
$cancelledOrdersAll = (int)$orderStatusSummary['cancelled_orders'];
$totalOrdersAll     = (int)$orderStatusSummary['total_orders'];

// Cancellation rate
$cancelRateAll = $totalOrdersAll > 0
  ? round(($cancelledOrdersAll / $totalOrdersAll) * 100, 2)
  : 0.0;

// Recent orders = simple "activity feed"
$sqlRecentOrders = "
  SELECT o.OrderDate, o.OrderID, o.Status, o.TotalAmt, u.Username
  FROM orders o
  LEFT JOIN user u ON u.UserID = o.UserID
  ORDER BY o.OrderDate DESC
  LIMIT 10
";
$recentOrders = $pdo->query($sqlRecentOrders)->fetchAll(PDO::FETCH_ASSOC);

// Alerts
$alerts = [];
if ($pendingOrdersAll > 0) {
  $alerts[] = "There are {$pendingOrdersAll} orders still Pending.";
}
if ($csPendingTickets > 0) {
  $alerts[] = "There are {$csPendingTickets} cancellation requests pending review.";
}
if ($invLowStock > 0) {
  $alerts[] = "There are {$invLowStock} low-stock or out-of-stock products.";
}
if ($cancelRateAll > 20) { // 20% threshold – adjust as you like
  $alerts[] = "High cancellation rate detected ({$cancelRateAll}%).";
}

/* ===========================
   QUICK REPORT LINKS (PDF)
   =========================== */
$salesReportUrl    = "reports/sales_report_pdf.php?"          . $qs;
$productReportUrl  = "reports/product_performance_pdf.php?"   . $qs;
$customerReportUrl = "reports/customer_activity_pdf.php?"     . $qs;
$rewardReportUrl   = "reports/reward_ledger_pdf.php?"         . $qs;
$csReportUrl       = "reports/customer_service_pdf.php?"      . $qs;
$stockReportUrl       = "reports/low_stock_colour_size_pdf.php?"      . $qs;


/* ===========================
   VIEW
   =========================== */
include 'admin_header.php';
?>

<link rel="stylesheet" href="assets/admin_product.css">
<script src="https://code.highcharts.com/highcharts.js"></script>

<style>
  body.promo-manage main {
    max-width: none;
    margin: 0;
    background: transparent;
    padding: 55px 150px;
    border-radius: 0;
    box-shadow: none;
  }

  #reward-apply {
    background: #fff;
    border: 2px solid #000;
    color: #000;
  }

  .chart-card h3 {
    text-align: center;
    margin: 32px;
  }

  .section-title {
    margin-top: 36px;
    margin-bottom: 12px;
    font-size: 1.4rem;
    font-weight: 600;
  }

  .kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
    margin: 14px 0;
  }

  .kpi-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 12px 16px;
  }

  .kpi-title {
    font-size: 0.8rem;
    color: #6b7280;
    margin-bottom: 4px;
    text-transform: uppercase;
    letter-spacing: .05em;
  }

  .kpi-value {
    font-size: 1.3rem;
    font-weight: 600;
  }

  .charts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 18px;
    margin-bottom: 24px;
  }

  .chart-card {
    background: #fff;
    border-radius: 10px;
    padding: 12px 18px 18px;
    border: 1px solid #e5e7eb;
  }

  .chart-box {
    width: 100%;
    height: 280px;
  }

  .chart-card.span-2 {
    grid-column: span 2;
  }

  .table-box {
    overflow-x: auto;
  }

  table.dashboard-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
  }

  table.dashboard-table th,
  table.dashboard-table td {
    border-bottom: 1px solid #e5e7eb;
    padding: 6px 8px;
    text-align: left;
    white-space: nowrap;
  }

  table.dashboard-table th {
    background: #f3f4f6;
    font-weight: 600;
  }

  .alerts-list {
    list-style: disc;
    padding-left: 18px;
    margin: 4px 0 0;
    color: #b91c1c;
  }

  .alert-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 999px;
    background: #fef2f2;
    color: #b91c1c;
    font-size: 0.8rem;
    margin-right: 6px;
  }

  .reports-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 12px;
  }

  .report-card {
    background: #fff;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    padding: 10px 14px;
  }

  .report-card h4 {
    margin: 0 0 4px;
    font-size: 0.95rem;
  }

  .report-card p {
    margin: 0 0 8px;
    font-size: 0.8rem;
    color: #6b7280;
  }

  .report-card a {
    display: inline-block;
    font-size: 0.8rem;
    padding: 6px 12px;
    border-radius: 999px;
    border: 1px solid #111827;
    text-decoration: none;
    color: #111827;
  }

  .section-link {
    text-decoration: none;
    cursor: pointer;
    color: #333;
    transition: color 0.3s ease;
  }

  .section-link:hover {
    color: #0155afff;
    text-decoration: underline;
  }
</style>

<body class="promo-manage">
  <!-- Date Filter -->
  <form method="get"
    class="date-filter admin-form"
    style="margin:12px 0; display:flex; gap:20px; align-items:center; flex-wrap:wrap;">

    <label for="reward-date-from">From</label>
    <input type="date" id="reward-date-from" name="start" class="admin-date"
      value="<?= htmlspecialchars($periodStart ?? '') ?>" placeholder="dd/mm/yyyy">

    <label for="reward-date-to">To</label>
    <input type="date" id="reward-date-to" name="end" class="admin-date"
      value="<?= htmlspecialchars($periodEnd ?? '') ?>" placeholder="dd/mm/yyyy">

    <button type="submit" id="reward-apply" style="padding: 12px 28px;">Apply</button>
    <button type="button" id="reward-clear" style="padding: 12px 28px;"
      onclick="window.location.href='dashboard.php'">Clear</button>
  </form>

  <!-- SALES & ORDERS OVERVIEW -->
  <h2 class="section-title">
    <a class="section-link" href="detail_report.php?section=sales&<?= $qs ?>">Sales &amp; Orders Overview</a>
  </h2>
  <div class="kpi-grid">
    <div class="kpi-card">
      <div class="kpi-title">Total Sales (RM)</div>
      <div class="kpi-value">RM <?= number_format($kpiTotalSales, 2) ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-title">Total Orders</div>
      <div class="kpi-value"><?= number_format($kpiTotalOrders) ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-title">Average Order Value</div>
      <div class="kpi-value">RM <?= number_format($kpiAvgOrderValue, 2) ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-title">Orders Today (<?= htmlspecialchars($today) ?>)</div>
      <div class="kpi-value"><?= number_format($kpiOrdersToday) ?></div>
    </div>
  </div>

  <div class="charts-grid" id="salesChartsGrid">
    <section class="chart-card">
      <h3>Monthly Sales Trend (RM)</h3>
      <div class="chart-box" id="chartSalesMonthly"></div>
    </section>

    <section class="chart-card">
      <h3>Orders by Status</h3>
      <div class="chart-box" id="chartOrderStatus"></div>
    </section>

    <!-- <section class="chart-card span-2">
      <h3>Top Products by Quantity Sold</h3>
      <div class="table-box">
        <table class="dashboard-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Product</th>
              <th>Qty Sold</th>
              <th>Revenue (RM)</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($topProducts): $i = 1;
              foreach ($topProducts as $p): ?>
                <tr>
                  <td><?= $i++ ?></td>
                  <td><?= htmlspecialchars($p['ProductName']) ?></td>
                  <td><?= (int)$p['qty_sold'] ?></td>
                  <td><?= number_format($p['revenue'], 2) ?></td>
                </tr>
              <?php endforeach;
            else: ?>
              <tr>
                <td colspan="4">No product sales in this period.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section> -->
  </div>

  <!-- INVENTORY & LOW STOCK -->
  <h2 class="section-title">
    <a class="section-link" href="detail_report.php?section=inventory&<?= $qs ?>">Inventory &amp; Low-Stock Monitoring</a>
  </h2>
  <div class="kpi-grid">
    <div class="kpi-card">
      <div class="kpi-title">Total Products</div>
      <div class="kpi-value"><?= number_format($invTotalProducts) ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-title">Low-Stock Products</div>
      <div class="kpi-value"><?= number_format($invLowStock) ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-title">Out-of-Stock Products</div>
      <div class="kpi-value"><?= number_format($invOutOfStock) ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-title">Total Stock Value (RM)</div>
      <div class="kpi-value">RM <?= number_format($invStockValue, 2) ?></div>
    </div>
  </div>

  <div class="charts-grid" id="inventoryGrid">
    <section class="chart-card">
      <h3>Stock by Category (Units)</h3>
      <div class="chart-box" id="chartStockByCategory"></div>
    </section>

    <!-- <section class="chart-card">
      <h3>Low-Stock Items</h3>
      <div class="table-box">
        <table class="dashboard-table">
          <thead>
            <tr>
              <th>Product</th>
              <th>Category</th>
              <th>Stock</th>
              <th>Min Stock</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($lowStockRows): foreach ($lowStockRows as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($row['Name']) ?></td>
                  <td><?= htmlspecialchars($row['CategoryName']) ?></td>
                  <td><?= (int)$row['Stock'] ?></td>
                  <td><?= (int)$row['MinStock'] ?></td>
                </tr>
              <?php endforeach;
            else: ?>
              <tr>
                <td colspan="4">No low-stock items detected.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section> -->
  </div>

  <!-- FEEDBACK & RATINGS -->
  <h2 class="section-title">
    <a class="section-link" href="detail_report.php?section=feedback&<?= $qs ?>">Feedback &amp; Rating Analytics</a>
  </h2>
  <div class="kpi-grid">
    <div class="kpi-card">
      <div class="kpi-title">Total Feedback</div>
      <div class="kpi-value"><?= number_format($fbTotal) ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-title">Average Rating (Feedback)</div>
      <div class="kpi-value"><?= number_format($fbAvg, 2) ?> / 5</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-title">Feedback Today</div>
      <div class="kpi-value"><?= number_format($fbToday) ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-title">4★–5★ Feedback</div>
      <div class="kpi-value"><?= number_format($fbHighPct, 2) ?>%</div>
    </div>
  </div>

  <div class="charts-grid" id="feedbackGrid">
    <section class="chart-card">
      <h3>Product Rating Distribution</h3>
      <div class="chart-box" style="height: 280px;">
        <canvas id="chartRatingDist" height="140"></canvas>
      </div>
    </section>

    <!-- <section class="chart-card">
      <h3>Latest Product Reviews</h3>
      <div class="table-box">
        <table class="dashboard-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>User</th>
              <th>Product</th>
              <th>Comment</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($latestReviews): foreach ($latestReviews as $rv): ?>
                <tr>
                  <td><?= htmlspecialchars($rv['CreatedAt']) ?></td>
                  <td><?= htmlspecialchars($rv['Username']) ?></td>
                  <td><?= htmlspecialchars($rv['ProductName']) ?></td>
                  <td><?= htmlspecialchars(mb_strimwidth($rv['Comment'], 0, 80, '…')) ?></td>
                </tr>
              <?php endforeach;
            else: ?>
              <tr>
                <td colspan="4">No reviews yet.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section> -->
  </div>

  <!-- REWARD & LOYALTY -->
  <!-- KPI CARDS -->
  <h2 class="section-title">
    <a class="section-link" href="detail_report.php?section=reward&<?= $qs ?>">Reward &amp; Loyalty Overview</a>
  </h2>
  <div class="kpi-grid" style="display:grid; grid-template-columns: repeat(4, minmax(180px,1fr)); gap:14px; margin:14px 0;">
    <div class="kpi-card">
      <div class="kpi-title">Points Earned</div>
      <div class="kpi-value"><?= number_format($kpiEarned) ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-title">Points Redeemed</div>
      <div class="kpi-value"><?= number_format($kpiRedeemed) ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-title">Redemption Rate</div>
      <div class="kpi-value"><?= number_format($kpiRate, 2) ?>%</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-title">Total Redemption Value</div>
      <div class="kpi-value">RM <?= number_format($kpiRedeemValue, 2) ?></div>
    </div>
  </div>

  <!-- CHARTS -->
  <div class="charts-grid" id="rewardChartsGrid">
    <section class="chart-card" id="card-earnredeem">
      <h3>Monthly Points Earned vs Redeemed</h3>
      <div class="chart-box" id="chartEarnRedeem"></div>
    </section>

    <section class="chart-card" id="card-rate">
      <h3>Points Redemption Rate (%)</h3>
      <div class="chart-box" id="chartRate"></div>
    </section>

    <section class="chart-card" id="card-tier">
      <h3>Tier Distribution</h3>
      <div class="chart-box" id="chartTier"></div>
    </section>

    <section class="chart-card" id="card-userpoints">
      <h3>User Reward Points Overview</h3>
      <div class="chart-box" style="height: 350px;">
        <canvas id="chartRewardPoints" height="160"></canvas>
      </div>
    </section>

    <section class="chart-card" id="card-topredeemers">
      <h3>Top Redeemers</h3>
      <div class="chart-box">
        <canvas id="chartTopRedeemers" height="160"></canvas>
      </div>
    </section>
  </div>

  <!-- CUSTOMER SERVICE / TICKETS -->
  <h2 class="section-title">
    <a class="section-link" href="detail_report.php?section=cs&<?= $qs ?>">Customer Service &amp; Ticket Monitoring</a>
  </h2>
  <div class="kpi-grid">
    <div class="kpi-card">
      <div class="kpi-title">Total Cancellation Requests</div>
      <div class="kpi-value"><?= number_format($csTotalTickets) ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-title">Pending Requests</div>
      <div class="kpi-value"><?= number_format($csPendingTickets) ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-title">Approved Requests</div>
      <div class="kpi-value"><?= number_format($csApprovedTickets) ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-title">Active Deliveries</div>
      <div class="kpi-value"><?= number_format($activeDeliveries) ?></div>
    </div>
  </div>

  <div class="charts-grid" id="csGrid">
    <section class="chart-card">
      <h3>Cancellation Requests by Status</h3>
      <div class="chart-box" style="height: 280px;">
        <canvas id="chartCancelStatus" height="140"></canvas>
      </div>
    </section>

    <!-- <section class="chart-card">
      <h3>Latest Requests</h3>
      <div class="table-box">
        <table class="dashboard-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Order ID</th>
              <th>User</th>
              <th>Status</th>
              <th>Reason</th>
              <th>Requested At</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($latestTickets): foreach ($latestTickets as $t): ?>
                <tr>
                  <td>#<?= (int)$t['cancellationId'] ?></td>
                  <td>#<?= (int)$t['OrderID'] ?></td>
                  <td><?= htmlspecialchars($t['Username'] ?? 'Unknown') ?></td>
                  <td><?= htmlspecialchars($t['Status']) ?></td>
                  <td><?= htmlspecialchars(mb_strimwidth($t['Reason'], 0, 40, '…')) ?></td>
                  <td><?= htmlspecialchars($t['RequestedAt']) ?></td>
                </tr>
              <?php endforeach;
            else: ?>
              <tr>
                <td colspan="6">No cancellation requests yet.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section> -->
  </div>

  <!-- SYSTEM ACTIVITY & ALERTS -->
  <h2 class="section-title">
    <a class="section-link" href="detail_report.php?section=system&<?= $qs ?>">
      System Activity &amp; Alerts
    </a>
  </h2>
  <div class="kpi-grid">
    <div class="kpi-card">
      <div class="kpi-title">Total Users</div>
      <div class="kpi-value"><?= number_format($totalUsers) ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-title">Admins</div>
      <div class="kpi-value"><?= number_format($totalAdmins) ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-title">Members</div>
      <div class="kpi-value"><?= number_format($totalMembers) ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-title">Pending Orders (All Time)</div>
      <div class="kpi-value"><?= number_format($pendingOrdersAll) ?></div>
    </div>
  </div>

  <div class="charts-grid">
    <section class="chart-card">
      <h3>Alerts</h3>
      <?php if ($alerts): ?>
        <ul class="alerts-list">
          <?php foreach ($alerts as $a): ?>
            <li><span class="alert-badge">ALERT</span><?= htmlspecialchars($a) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p>No critical alerts at the moment.</p>
      <?php endif; ?>
    </section>

    <section class="chart-card span-2">
      <h3>Recent Orders</h3>
      <div class="table-box">
        <table class="dashboard-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Order ID</th>
              <th>User</th>
              <th>Status</th>
              <th>Total (RM)</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($recentOrders): foreach ($recentOrders as $ro): ?>
                <tr>
                  <td><?= htmlspecialchars($ro['OrderDate']) ?></td>
                  <td>#<?= (int)$ro['OrderID'] ?></td>
                  <td><?= htmlspecialchars($ro['Username'] ?? 'Unknown') ?></td>
                  <td><?= htmlspecialchars($ro['Status']) ?></td>
                  <td><?= number_format($ro['TotalAmt'], 2) ?></td>
                </tr>
              <?php endforeach;
            else: ?>
              <tr>
                <td colspan="5">No orders found.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>


  <?php include 'admin_footer.php'; ?>

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    (function() {
      const monthsReward = <?= $chartMonthsJson ?>;
      const earnedReward = <?= $chartEarnedJson ?>;
      const redeemedReward = <?= $chartRedeemedJson ?>;
      const ratePct = <?= $chartRateJson ?>;
      const tierData = <?= $tierDistJson ?>;
      const topRedeemers = <?= $topRedeemersJson ?> || [];
      const userRewards = <?= $userRewardsJson ?> || [];

      const salesMonths = <?= $salesMonthsJson ?>;
      const salesAmount = <?= $salesAmountJson ?>;
      const orderStatus = <?= $orderStatusJson ?> || [];
      const stockByCat = <?= $stockByCatJson ?> || [];
      const ratingLabels = <?= $ratingLabelsJson ?> || [];
      const ratingCounts = <?= $ratingCountsJson ?> || [];
      const cancelStatus = <?= $cancelStatusJson ?> || [];

      // Highcharts: Monthly Sales
      if (document.getElementById('chartSalesMonthly')) {
        Highcharts.chart('chartSalesMonthly', {
          chart: {
            type: 'column'
          },
          title: {
            text: ' '
          },
          xAxis: {
            categories: salesMonths,
            crosshair: true
          },
          yAxis: {
            min: 0,
            title: {
              text: 'Sales (RM)'
            }
          },
          tooltip: {
            shared: true,
            valuePrefix: 'RM '
          },
          series: [{
            name: 'Sales',
            data: salesAmount
          }]
        });
      }

      // Highcharts: Orders by Status
      if (document.getElementById('chartOrderStatus')) {
        Highcharts.chart('chartOrderStatus', {
          chart: {
            type: 'pie'
          },
          title: {
            text: ' '
          },
          series: [{
            name: 'Orders',
            data: orderStatus.map(s => ({
              name: s.Status,
              y: parseInt(s.cnt, 10)
            }))
          }]
        });
      }

      // Highcharts: Stock by Category
      if (document.getElementById('chartStockByCategory')) {
        Highcharts.chart('chartStockByCategory', {
          chart: {
            type: 'column'
          },
          title: {
            text: ' '
          },
          xAxis: {
            categories: stockByCat.map(r => r.cat),
            crosshair: true
          },
          yAxis: {
            min: 0,
            title: {
              text: 'Units'
            }
          },
          series: [{
            name: 'Stock',
            data: stockByCat.map(r => parseInt(r.qty, 10))
          }]
        });
      }

      // Highcharts: Reward Earn vs Redeem
      if (document.getElementById('chartEarnRedeem')) {
        Highcharts.chart('chartEarnRedeem', {
          chart: {
            type: 'column'
          },
          title: {
            text: ' '
          },
          xAxis: {
            categories: monthsReward,
            crosshair: true
          },
          yAxis: {
            min: 0,
            title: {
              text: 'Points'
            }
          },
          tooltip: {
            shared: true
          },
          series: [{
              name: 'Earned',
              data: earnedReward
            },
            {
              name: 'Redeemed',
              data: redeemedReward
            }
          ]
        });
      }

      // Highcharts: Reward Rate
      if (document.getElementById('chartRate')) {
        Highcharts.chart('chartRate', {
          chart: {
            type: 'line'
          },
          title: {
            text: ' '
          },
          xAxis: {
            categories: monthsReward
          },
          yAxis: {
            title: {
              text: 'Rate (%)'
            },
            max: 100
          },
          tooltip: {
            valueSuffix: '%'
          },
          series: [{
            name: 'Redemption Rate',
            data: ratePct
          }]
        });
      }

      // Highcharts: Tier Distribution
      if (document.getElementById('chartTier')) {
        Highcharts.chart('chartTier', {
          chart: {
            type: 'pie'
          },
          title: {
            text: ' '
          },
          series: [{
            name: 'Users',
            data: tierData.map(t => ({
              name: t.TierName,
              y: parseInt(t.users_count, 10)
            }))
          }]
        });
      }

      // Chart.js: Top Redeemers
      const trCanvas = document.getElementById('chartTopRedeemers');
      if (trCanvas && topRedeemers.length) {
        const trLabels = topRedeemers.map(r => r.Username);
        const trRedeemed = topRedeemers.map(r => Number(r.points_redeemed || 0));
        const trValue = topRedeemers.map(r => Number(r.discount_value || 0));

        new Chart(trCanvas.getContext('2d'), {
          type: 'bar',
          data: {
            labels: trLabels,
            datasets: [{
                label: 'Points Redeemed',
                data: trRedeemed
              },
              {
                label: 'Total Discount (RM)',
                data: trValue
              }
            ]
          },
          options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: {
                position: 'top'
              },
              tooltip: {
                callbacks: {
                  label: (ctx) => {
                    const v = ctx.parsed.x ?? ctx.parsed.y;
                    return ` ${ctx.dataset.label}: ${Number(v).toLocaleString()}`;
                  }
                }
              }
            },
            scales: {
              x: {
                beginAtZero: true,
                title: {
                  display: true,
                  text: 'Value'
                }
              },
              y: {
                ticks: {
                  autoSkip: true,
                  callback: (val, idx) => {
                    const label = trLabels[idx] || '';
                    return label.length > 18 ? label.slice(0, 16) + '…' : label;
                  }
                }
              }
            }
          }
        });
      }

      // Chart.js: User Reward Points Overview
      const urCanvas = document.getElementById('chartRewardPoints');
      if (urCanvas && userRewards.length) {
        const urLabels = userRewards.map(r => r.Username);
        const urEarned = userRewards.map(r => Number(r.TotalPointsEarned || 0));
        const urRedeemed = userRewards.map(r => Number(r.TotalPointsRedeemed || 0));
        const urBalance = userRewards.map(r => Number(r.Balance || 0));

        new Chart(urCanvas.getContext('2d'), {
          type: 'bar',
          data: {
            labels: urLabels,
            datasets: [{
                label: 'Total Points Earned',
                data: urEarned
              },
              {
                label: 'Total Points Redeemed',
                data: urRedeemed
              },
              {
                type: 'line',
                label: 'Current Balance',
                data: urBalance,
                yAxisID: 'y2'
              }
            ]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: {
                position: 'top'
              },
              tooltip: {
                callbacks: {
                  label: (ctx) => {
                    const v = (ctx.parsed.y !== undefined) ? ctx.parsed.y : ctx.parsed.x;
                    return ` ${ctx.dataset.label}: ${Number(v).toLocaleString()}`;
                  }
                }
              }
            },
            scales: {
              x: {
                ticks: {
                  maxRotation: 45,
                  callback: (val, idx) => {
                    const label = urLabels[idx] || '';
                    return label.length > 14 ? label.slice(0, 12) + '…' : label;
                  }
                }
              },
              y: {
                beginAtZero: true,
                title: {
                  display: true,
                  text: 'Points (Earned/Redeemed)'
                }
              },
              y2: {
                position: 'right',
                beginAtZero: true,
                grid: {
                  drawOnChartArea: false
                },
                title: {
                  display: true,
                  text: 'Balance'
                }
              }
            }
          }
        });
      }

      // Chart.js: Product Rating Distribution
      const ratingCanvas = document.getElementById('chartRatingDist');
      if (ratingCanvas && ratingLabels.length) {
        new Chart(ratingCanvas.getContext('2d'), {
          type: 'bar',
          data: {
            labels: ratingLabels,
            datasets: [{
              label: 'Ratings Count',
              data: ratingCounts
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
              x: {
                title: {
                  display: true,
                  text: 'Rating (Stars)'
                }
              },
              y: {
                beginAtZero: true,
                title: {
                  display: true,
                  text: 'Number of Ratings'
                }
              }
            }
          }
        });
      }

      // Chart.js: Cancellation Status
      const cancelCanvas = document.getElementById('chartCancelStatus');
      if (cancelCanvas && cancelStatus.length) {
        const cLabels = cancelStatus.map(c => c.Status);
        const cCounts = cancelStatus.map(c => Number(c.cnt || 0));

        new Chart(cancelCanvas.getContext('2d'), {
          type: 'pie',
          data: {
            labels: cLabels,
            datasets: [{
              label: 'Requests',
              data: cCounts
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false
          }
        });
      }

      // Make last reward card span full width if leftover in grid
      function fixRewardGrid() {
        const grid = document.getElementById('rewardChartsGrid');
        if (!grid) return;
        const cards = Array.from(grid.querySelectorAll('.chart-card'));
        cards.forEach(c => c.classList.remove('span-2'));
        const colCount = getComputedStyle(grid).gridTemplateColumns.split(' ').length;
        if (cards.length % colCount !== 0) {
          cards[cards.length - 1].classList.add('span-2');
        }
      }
      fixRewardGrid();
      window.addEventListener('resize', fixRewardGrid);
    })();
  </script>
</body>