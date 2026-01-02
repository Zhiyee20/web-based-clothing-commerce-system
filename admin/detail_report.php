<?php
// admin/detail_report.php — Tables-only detail views
require '../config.php';
session_start();

$user = $_SESSION['user'] ?? null;
if (!$user || $user['Role'] !== 'Admin') {
  header('Location: login.php');
  exit;
}

/* -------- Params -------- */
$valid   = ['sales', 'inventory', 'feedback', 'reward', 'cs'];
$section = isset($_GET['section']) && in_array($_GET['section'], $valid, true)
  ? $_GET['section']
  : 'reward';

$start = isset($_GET['start']) && $_GET['start'] !== '' ? $_GET['start'] : null;
$end   = isset($_GET['end'])   && $_GET['end']   !== '' ? $_GET['end']   : null;

$qs = http_build_query(['section' => $section, 'start' => $start, 'end' => $end]);

/* -------- Export URLs -------- */
$exportCsvUrl = null;
$exportPdfUrl = null;
$title        = '';

/* -------- Common helpers -------- */
function buildDateClause(&$params, $col, $start, $end)
{
  $sql = '';
  if ($start) {
    $sql .= " AND $col >= :ps";
    $params[':ps'] = $start . ' 00:00:00';
  }
  if ($end) {
    $sql .= " AND $col <= :pe";
    $params[':pe'] = $end  . ' 23:59:59';
  }
  return $sql;
}

/* -------- Data by section -------- */

switch ($section) {

  /* ============= SALES & ORDERS (tables) ============= */
  case 'sales':
    $p  = [];
    $dc = buildDateClause($p, 'o.OrderDate', $start, $end);

    // Orders list (latest first)
    $sqlOrders = "
      SELECT o.OrderID, o.OrderDate, o.Status, o.TotalAmt, u.Username
      FROM orders o
      LEFT JOIN user u ON u.UserID = o.UserID
      WHERE 1=1 $dc
      ORDER BY o.OrderDate DESC
      LIMIT 200
    ";
    $orders = $pdo->prepare($sqlOrders);
    $orders->execute($p);
    $orders = $orders->fetchAll(PDO::FETCH_ASSOC);

    // Top products (more rows than dashboard)
    $sqlTop = "
      SELECT p.Name AS ProductName,
             SUM(oi.Quantity) AS qty_sold,
             SUM(oi.Quantity * oi.Price) AS revenue
      FROM orderitem oi
      JOIN orders o  ON o.OrderID   = oi.OrderID
      JOIN product p ON p.ProductID = oi.ProductID
      WHERE o.Status <> 'Cancel / Return & Refund' $dc
      GROUP BY p.ProductID
      ORDER BY qty_sold DESC
      LIMIT 50
    ";
    $stmt = $pdo->prepare($sqlTop);
    $stmt->execute($p);
    $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Orders by status (table)
    $sqlStatus = "
      SELECT o.Status, COUNT(*) AS cnt
      FROM orders o
      WHERE 1=1 $dc
      GROUP BY o.Status
      ORDER BY cnt DESC
    ";
    $stmt = $pdo->prepare($sqlStatus);
    $stmt->execute($p);
    $orderStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // EXPORT URLs
    $exportCsvUrl = "reports/sales_report_export.php?" . http_build_query([
      'start' => $start,
      'end'   => $end,
    ]);
    $exportPdfUrl = "reports/sales_report_pdf.php?" . http_build_query([
      'start' => $start,
      'end'   => $end,
    ]);

    $title = "Sales & Orders Report";
    break;

  /* ============= INVENTORY (current-state + movement tables) ============= */
  case 'inventory':
    /*
      Current stock is stored per Color + Size in product_color_sizes.
      We show:
      - Low-stock / out-of-stock variants
      - All variants (snapshot)
      - Stock by category (units)
      - Stock movement history from stock_movements (optionally filtered by date)
    */

    // 1) Low-stock / out-of-stock variants (snapshot, no date filter)
    $sqlLow = "
      SELECT 
        p.ProductID,
        p.Name AS ProductName,
        COALESCE(c.CategoryName, 'Uncategorised') AS Category,
        pc.ColorName,
        pcs.Size,
        pcs.Stock,
        pcs.MinStock,
        p.Price
      FROM product_color_sizes pcs
      JOIN product_colors pc ON pc.ProductColorID = pcs.ProductColorID
      JOIN product p        ON p.ProductID       = pc.ProductID
      LEFT JOIN categories c ON c.CategoryID     = p.CategoryID
      WHERE pcs.Stock <= 0 OR pcs.Stock < pcs.MinStock
      ORDER BY pcs.Stock ASC, ProductName, pc.ColorName, pcs.Size
      LIMIT 500
    ";
    $lowStock = $pdo->query($sqlLow)->fetchAll(PDO::FETCH_ASSOC);

    // 2) All variants (snapshot, no date filter)
    $sqlAll = "
      SELECT 
        p.ProductID,
        p.Name AS ProductName,
        COALESCE(c.CategoryName, 'Uncategorised') AS Category,
        pc.ColorName,
        pcs.Size,
        pcs.Stock,
        pcs.MinStock,
        p.Price
      FROM product_color_sizes pcs
      JOIN product_colors pc ON pc.ProductColorID = pcs.ProductColorID
      JOIN product p        ON p.ProductID       = pc.ProductID
      LEFT JOIN categories c ON c.CategoryID     = p.CategoryID
      ORDER BY Category, ProductName, pc.ColorName, pcs.Size
      LIMIT 1000
    ";
    $allProducts = $pdo->query($sqlAll)->fetchAll(PDO::FETCH_ASSOC);

    // 3) Stock by category (units)
    $sqlByCat = "
      SELECT 
        COALESCE(c.CategoryName, 'Uncategorised') AS Category,
        COALESCE(SUM(pcs.Stock), 0) AS Qty
      FROM product_color_sizes pcs
      JOIN product_colors pc ON pc.ProductColorID = pcs.ProductColorID
      JOIN product p        ON p.ProductID       = pc.ProductID
      LEFT JOIN categories c ON c.CategoryID     = p.CategoryID
      GROUP BY c.CategoryID, Category
      ORDER BY Category
    ";
    $byCat = $pdo->query($sqlByCat)->fetchAll(PDO::FETCH_ASSOC);

    // 4) Stock movement history (uses stock_movements, date-filtered)
    $p     = [];
    $dcMov = buildDateClause($p, 'sm.CreatedAt', $start, $end);

    $sqlMov = "
      SELECT
        sm.MovementID,
        sm.CreatedAt,
        sm.MovementType,
        sm.Reason,
        sm.QtyChange,
        sm.OldStock,
        sm.NewStock,
        sm.ReferenceType,
        sm.ReferenceID,
        sm.Note,
        u.Username AS PerformedBy,
        p.ProductID,
        p.Name AS ProductName,
        pc.ColorName,
        pcs.Size
      FROM stock_movements sm
      JOIN product_color_sizes pcs ON pcs.ColorSizeID    = sm.ColorSizeID
      JOIN product_colors pc       ON pc.ProductColorID  = pcs.ProductColorID
      JOIN product p               ON p.ProductID        = pc.ProductID
      LEFT JOIN user u             ON u.UserID           = sm.PerformedBy
      WHERE 1=1 $dcMov
      ORDER BY sm.CreatedAt DESC, sm.MovementID DESC
      LIMIT 500
    ";
    $stmt = $pdo->prepare($sqlMov);
    $stmt->execute($p);
    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // EXPORT URLs
    $exportCsvUrl = "reports/product_performance_export.php?" . http_build_query([
      'start' => $start,
      'end'   => $end,
    ]);
    $exportPdfUrl = "reports/low_stock_colour_size_pdf.php?" . http_build_query([
      'start' => $start,
      'end'   => $end,
    ]);

    $title = "Inventory, Stock Levels & Movement Report";
    break;

  /* ============= FEEDBACK & RATINGS (tables) ============= */
  case 'feedback':
    $p   = [];
    $dcF = buildDateClause($p, 'f.CreatedAt', $start, $end);

    // Feedback list
    $sqlFb = "
      SELECT f.FeedbackID, u.Username, f.Type, f.Rating, f.FeedbackText, f.CreatedAt
      FROM feedback f
      LEFT JOIN user u ON u.UserID = f.UserID
      WHERE 1=1 $dcF
      ORDER BY f.CreatedAt DESC
      LIMIT 200
    ";
    $stmt = $pdo->prepare($sqlFb);
    $stmt->execute($p);
    $feedback = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Product reviews
    $p2  = [];
    $dcR = buildDateClause($p2, 'pr.CreatedAt', $start, $end);
    $sqlReviews = "
      SELECT pr.ProductID, p.Name AS Product, u.Username, pr.Rating, pr.Comment, pr.CreatedAt
      FROM product_reviews pr
      JOIN product p ON p.ProductID = pr.ProductID
      JOIN user u    ON u.UserID    = pr.UserID
      WHERE 1=1 $dcR
      ORDER BY pr.CreatedAt DESC
      LIMIT 200
    ";
    $stmt = $pdo->prepare($sqlReviews);
    $stmt->execute($p2);
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Rating distribution table
    $p3  = [];
    $dcD = buildDateClause($p3, 'pr.CreatedAt', $start, $end);
    $sqlDist = "
      SELECT pr.Rating, COUNT(*) AS Cnt
      FROM product_ratings pr
      WHERE 1=1 $dcD
      GROUP BY pr.Rating
      ORDER BY pr.Rating
    ";
    $stmt = $pdo->prepare($sqlDist);
    $stmt->execute($p3);
    $ratingDist = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // EXPORT URLs
    $exportCsvUrl = "reports/customer_activity_export.php?" . http_build_query([
      'start' => $start,
      'end'   => $end,
    ]);
    // Only valid if you actually create this PDF later
    $exportPdfUrl = "reports/feedback_report_pdf.php?" . http_build_query([
      'start' => $start,
      'end'   => $end,
    ]);

    $title = "Feedback & Ratings Report";
    break;

  /* ============= CUSTOMER SERVICE (tables) ============= */
  case 'cs':
    $p  = [];
    $dc = buildDateClause($p, 'oc.RequestedAt', $start, $end);

    $sqlTickets = "
      SELECT oc.cancellationId, oc.OrderID, oc.Status, oc.Reason, oc.RequestedAt,
             u.Username
      FROM ordercancellation oc
      JOIN orders o ON o.OrderID = oc.OrderID
      LEFT JOIN user u ON u.UserID = o.UserID
      WHERE 1=1 $dc
      ORDER BY oc.RequestedAt DESC
      LIMIT 300
    ";
    $stmt = $pdo->prepare($sqlTickets);
    $stmt->execute($p);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sqlStatus = "
      SELECT oc.Status, COUNT(*) AS cnt
      FROM ordercancellation oc
      WHERE 1=1 $dc
      GROUP BY oc.Status
      ORDER BY cnt DESC
    ";
    $stmt = $pdo->prepare($sqlStatus);
    $stmt->execute($p);
    $ticketStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // EXPORT URLs
    $exportCsvUrl = "reports/customer_service_export.php?" . http_build_query([
      'start' => $start,
      'end'   => $end,
    ]);
    $exportPdfUrl = "reports/customer_service_pdf.php?" . http_build_query([
      'start' => $start,
      'end'   => $end,
    ]);

    $title = "Customer Service Report";
    break;

  /* ============= REWARD & LOYALTY (tables as in your screenshot) ============= */
  case 'reward':
  default:
    $p  = [];
    $dc = buildDateClause($p, 'rl.CreatedAt', $start, $end);

    // Top Redeemers
    $sqlTopRedeemers = "
      SELECT u.Username,
             SUM(rl.Points) AS points_redeemed,
             SUM(rl.Points * rt.ConversionRate) AS discount_value
      FROM reward_ledger rl
      JOIN user u ON u.UserID = rl.UserID
      JOIN reward_points rp ON rp.UserID = rl.UserID
      JOIN reward_tiers rt ON rp.Accumulated BETWEEN rt.MinPoints AND rt.MaxPoints
      WHERE rl.Type = 'REDEEM' $dc
      GROUP BY rl.UserID
      ORDER BY points_redeemed DESC
      LIMIT 50
    ";
    $stmt = $pdo->prepare($sqlTopRedeemers);
    $stmt->execute($p);
    $topRedeemers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // User Reward Points table (period earned/redeemed + current)
    $sqlUserTotals = "
      SELECT u.Username,
             COALESCE(SUM(CASE WHEN rl.Type='EARN'   THEN rl.Points END),0) AS earned_period,
             COALESCE(SUM(CASE WHEN rl.Type='REDEEM' THEN rl.Points END),0) AS redeemed_period
      FROM user u
      LEFT JOIN reward_ledger rl ON rl.UserID = u.UserID
           AND rl.Type IN ('EARN','REDEEM') $dc
      GROUP BY u.UserID
    ";
    $stmt = $pdo->prepare($sqlUserTotals);
    $stmt->execute($p);
    $userPeriod = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $periodByUser = [];
    foreach ($userPeriod as $r) {
      $periodByUser[$r['Username']] = [
        'earned_period'   => (int)$r['earned_period'],
        'redeemed_period' => (int)$r['redeemed_period'],
      ];
    }

    $sqlBalances = "
      SELECT u.Username, rp.Balance, rp.Accumulated, rt.TierName, rt.ConversionRate
      FROM user u
      JOIN reward_points rp ON rp.UserID = u.UserID
      JOIN reward_tiers  rt ON rp.Accumulated BETWEEN rt.MinPoints AND rt.MaxPoints
    ";
    $balances = $pdo->query($sqlBalances)->fetchAll(PDO::FETCH_ASSOC);

    $userRewardsRows = [];
    foreach ($balances as $b) {
      $u = $b['Username'];
      $earned = $periodByUser[$u]['earned_period'] ?? 0;
      $redeem = $periodByUser[$u]['redeemed_period'] ?? 0;
      $userRewardsRows[] = [
        'Username'            => $u,
        'TotalPointsEarned'   => $earned,
        'TotalPointsRedeemed' => $redeem,
        'Balance'             => (int)$b['Balance'],
        'CurrentTier'         => $b['TierName'],
        'TotalDiscount'       => $redeem * (float)$b['ConversionRate'],
      ];
    }

    // Ledger log history (period)
    $sqlLog = "
      SELECT rl.LedgerID, u.Username, rl.RefOrderID AS OrderID, rl.Points, rl.Type, rl.CreatedAt
      FROM reward_ledger rl
      JOIN user u ON u.UserID = rl.UserID
      WHERE rl.Type IN ('EARN','REDEEM','AUTO_REVERSAL_EARN','AUTO_REVERSAL_REDEEM')
      $dc
      ORDER BY rl.CreatedAt DESC, rl.LedgerID DESC
      LIMIT 300
    ";
    $stmt = $pdo->prepare($sqlLog);
    $stmt->execute($p);
    $ledger = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // EXPORT URLs
    $exportCsvUrl = "reports/reward_ledger_export.php?" . http_build_query([
      'start' => $start,
      'end'   => $end,
    ]);
    $exportPdfUrl = "reports/reward_ledger_pdf.php?" . http_build_query([
      'start' => $start,
      'end'   => $end,
    ]);

    $title = "Reward Report";
    break;
}

/* -------- View -------- */
include 'admin_header.php';
?>
<link rel="stylesheet" href="assets/admin_product.css">
<style>
  body.promo-manage main {
    max-width: none;
    margin: 0;
    background: transparent;
    padding: 55px 150px;
    border-radius: 0;
    box-shadow: none;
  }

  .section-title {
    font-size: 1.4rem;
    font-weight: 600;
    margin: 10px 0 14px;
  }

  .table-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 12px 18px;
    margin-bottom: 14px;
  }

  .table-box {
    overflow-x: auto;
  }

  table.report {
    width: 100%;
    border-collapse: collapse;
    font-size: .88rem;
  }

  table.report th,
  table.report td {
    border-bottom: 1px solid #e5e7eb;
    padding: 8px 10px;
    white-space: nowrap;
    text-align: left;
  }

  table.report th {
    background: #f3f4f6;
  }

  .btn-outline {
    background: #fff !important;
    border: 2px solid #000 !important;
    color: #000 !important;
    padding: 10px 16px !important;
    border-radius: 6px !important;
    text-decoration: none !important;
  }
</style>

<body class="promo-manage">
  <h2 class="section-title"><?= htmlspecialchars($title) ?></h2>

  <!-- Date Filter (mirrors dashboard behavior) -->
  <form method="get"
        class="date-filter admin-form"
        style="margin:12px 0; display:flex; gap:20px; align-items:center; flex-wrap:wrap;">
    <input type="hidden" name="section" value="<?= htmlspecialchars($section) ?>">

    <label for="reward-date-from">From</label>
    <input type="date" id="reward-date-from" name="start" class="admin-date"
           value="<?= htmlspecialchars($start ?? '') ?>">

    <label>To</label>
    <input type="date" name="end" value="<?= htmlspecialchars($end ?? '') ?>">

    <button type="submit" class="btn-outline">Apply</button>
    <a href="detail_report.php?section=<?= htmlspecialchars($section) ?>" class="btn-outline">Clear</a>

    <?php if (!empty($exportCsvUrl)): ?>
      <a class="btn-outline" href="<?= htmlspecialchars($exportCsvUrl) ?>">Export CSV</a>
    <?php endif; ?>

    <?php if (!empty($exportPdfUrl)): ?>
      <a class="btn-outline" href="<?= htmlspecialchars($exportPdfUrl) ?>">Export PDF</a>
    <?php endif; ?>

    <a class="btn-outline"
       href="dashboard.php?<?= http_build_query(['start' => $start, 'end' => $end]) ?>">
      ← Back to Dashboard
    </a>
  </form>

  <?php if ($section === 'reward'): ?>
    <div class="table-card">
      <h3 style="margin:8px 0">Top Redeemers</h3>
      <div class="table-box">
        <table class="report">
          <thead>
            <tr>
              <th>Username</th>
              <th>Points Redeemed</th>
              <th>Total Discount (RM)</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($topRedeemers): ?>
              <?php foreach ($topRedeemers as $r): ?>
                <tr>
                  <td><?= htmlspecialchars($r['Username']) ?></td>
                  <td><?= number_format($r['points_redeemed']) ?></td>
                  <td><?= number_format($r['discount_value'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="3">No data.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="table-card">
      <h3 style="margin:8px 0">User Reward Points Table</h3>
      <div class="table-box">
        <table class="report">
          <thead>
            <tr>
              <th>Username</th>
              <th>Total Points Earned</th>
              <th>Total Points Redeemed</th>
              <th>Balance</th>
              <th>Current Tier</th>
              <th>Total Discount (RM)</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($userRewardsRows): ?>
              <?php foreach ($userRewardsRows as $r): ?>
                <tr>
                  <td><?= htmlspecialchars($r['Username']) ?></td>
                  <td><?= number_format($r['TotalPointsEarned']) ?></td>
                  <td><?= number_format($r['TotalPointsRedeemed']) ?></td>
                  <td><?= number_format($r['Balance']) ?></td>
                  <td><?= htmlspecialchars($r['CurrentTier']) ?></td>
                  <td><?= number_format($r['TotalDiscount'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="6">No data.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="table-card">
      <h3 style="margin:8px 0">Log History</h3>
      <div class="table-box">
        <table class="report">
          <thead>
            <tr>
              <th>Ledger ID</th>
              <th>Username</th>
              <th>Order ID</th>
              <th>Points</th>
              <th>Type</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($ledger): ?>
              <?php foreach ($ledger as $l): ?>
                <tr>
                  <td><?= (int)$l['LedgerID'] ?></td>
                  <td><?= htmlspecialchars($l['Username']) ?></td>
                  <td><?= (int)$l['OrderID'] ?></td>
                  <td><?= number_format($l['Points']) ?></td>
                  <td><?= htmlspecialchars($l['Type']) ?></td>
                  <td><?= htmlspecialchars($l['CreatedAt']) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="6">No data.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php elseif ($section === 'sales'): ?>
    <div class="table-card">
      <h3 style="margin:8px 0">Orders</h3>
      <div class="table-box">
        <table class="report">
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
            <?php if ($orders): ?>
              <?php foreach ($orders as $o): ?>
                <tr>
                  <td><?= htmlspecialchars($o['OrderDate']) ?></td>
                  <td>#<?= (int)$o['OrderID'] ?></td>
                  <td><?= htmlspecialchars($o['Username'] ?? 'Unknown') ?></td>
                  <td><?= htmlspecialchars($o['Status']) ?></td>
                  <td><?= number_format($o['TotalAmt'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="5">No orders.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="table-card">
      <h3 style="margin:8px 0">Top Products</h3>
      <div class="table-box">
        <table class="report">
          <thead>
            <tr>
              <th>#</th>
              <th>Product</th>
              <th>Qty Sold</th>
              <th>Revenue (RM)</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($topProducts): $i = 1; ?>
              <?php foreach ($topProducts as $p): ?>
                <tr>
                  <td><?= $i++ ?></td>
                  <td><?= htmlspecialchars($p['ProductName']) ?></td>
                  <td><?= (int)$p['qty_sold'] ?></td>
                  <td><?= number_format($p['revenue'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="4">No data.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="table-card">
      <h3 style="margin:8px 0">Orders by Status</h3>
      <div class="table-box">
        <table class="report">
          <thead>
            <tr>
              <th>Status</th>
              <th>Count</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($orderStatus): ?>
              <?php foreach ($orderStatus as $s): ?>
                <tr>
                  <td><?= htmlspecialchars($s['Status']) ?></td>
                  <td><?= (int)$s['cnt'] ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="2">No data.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php elseif ($section === 'inventory'): ?>
    <!-- Low-stock / Out-of-stock variants -->
    <div class="table-card">
      <h3 style="margin:8px 0">Low-Stock / Out-of-Stock Variants</h3>
      <div class="table-box">
        <table class="report">
          <thead>
            <tr>
              <th>ID</th>
              <th>Product</th>
              <th>Category</th>
              <th>Color</th>
              <th>Size</th>
              <th>Stock</th>
              <th>Min</th>
              <th>Price (RM)</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($lowStock): ?>
              <?php foreach ($lowStock as $r): ?>
                <tr>
                  <td><?= (int)$r['ProductID'] ?></td>
                  <td><?= htmlspecialchars($r['ProductName']) ?></td>
                  <td><?= htmlspecialchars($r['Category']) ?></td>
                  <td><?= htmlspecialchars($r['ColorName']) ?></td>
                  <td><?= htmlspecialchars($r['Size']) ?></td>
                  <td><?= (int)$r['Stock'] ?></td>
                  <td><?= (int)$r['MinStock'] ?></td>
                  <td><?= number_format($r['Price'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="8">No low-stock or out-of-stock variants.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- All variants snapshot -->
    <div class="table-card">
      <h3 style="margin:8px 0">All Variants (first 1000)</h3>
      <div class="table-box">
        <table class="report">
          <thead>
            <tr>
              <th>ID</th>
              <th>Product</th>
              <th>Category</th>
              <th>Color</th>
              <th>Size</th>
              <th>Stock</th>
              <th>Min</th>
              <th>Price (RM)</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($allProducts): ?>
              <?php foreach ($allProducts as $r): ?>
                <tr>
                  <td><?= (int)$r['ProductID'] ?></td>
                  <td><?= htmlspecialchars($r['ProductName']) ?></td>
                  <td><?= htmlspecialchars($r['Category']) ?></td>
                  <td><?= htmlspecialchars($r['ColorName']) ?></td>
                  <td><?= htmlspecialchars($r['Size']) ?></td>
                  <td><?= (int)$r['Stock'] ?></td>
                  <td><?= (int)$r['MinStock'] ?></td>
                  <td><?= number_format($r['Price'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="8">No products / variants found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Stock by Category -->
    <div class="table-card">
      <h3 style="margin:8px 0">Stock by Category (Units)</h3>
      <div class="table-box">
        <table class="report">
          <thead>
            <tr>
              <th>Category</th>
              <th>Units</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($byCat): ?>
              <?php foreach ($byCat as $r): ?>
                <tr>
                  <td><?= htmlspecialchars($r['Category']) ?></td>
                  <td><?= (int)$r['Qty'] ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="2">No data.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Stock movement history -->
    <div class="table-card">
      <h3 style="margin:8px 0">Stock Movement History<?= $start || $end ? ' (Filtered by Date)' : '' ?></h3>
      <div class="table-box">
        <table class="report">
          <thead>
            <tr>
              <th>Date</th>
              <th>Movement ID</th>
              <th>Product</th>
              <th>Color</th>
              <th>Size</th>
              <th>Type</th>
              <th>Reason</th>
              <th>Qty Change</th>
              <th>Old Stock</th>
              <th>New Stock</th>
              <th>Ref Type</th>
              <th>Ref ID</th>
              <th>Performed By</th>
              <th>Note</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($movements): ?>
              <?php foreach ($movements as $m): ?>
                <tr>
                  <td><?= htmlspecialchars($m['CreatedAt']) ?></td>
                  <td><?= (int)$m['MovementID'] ?></td>
                  <td><?= htmlspecialchars($m['ProductName']) ?></td>
                  <td><?= htmlspecialchars($m['ColorName']) ?></td>
                  <td><?= htmlspecialchars($m['Size']) ?></td>
                  <td><?= htmlspecialchars($m['MovementType']) ?></td>
                  <td><?= htmlspecialchars($m['Reason']) ?></td>
                  <td><?= (int)$m['QtyChange'] ?></td>
                  <td><?= (int)$m['OldStock'] ?></td>
                  <td><?= (int)$m['NewStock'] ?></td>
                  <td><?= htmlspecialchars($m['ReferenceType'] ?? '') ?></td>
                  <td><?= htmlspecialchars($m['ReferenceID'] ?? '') ?></td>
                  <td><?= htmlspecialchars($m['PerformedBy'] ?? '') ?></td>
                  <td><?= htmlspecialchars($m['Note'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="14">No stock movements in this period.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php elseif ($section === 'feedback'): ?>

    <div class="table-card">
      <h3 style="margin:8px 0">Feedback (latest)</h3>
      <div class="table-box">
        <table class="report">
          <thead>
            <tr>
              <th>ID</th>
              <th>User</th>
              <th>Type</th>
              <th>Rating</th>
              <th>Feedback</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($feedback): ?>
              <?php foreach ($feedback as $f): ?>
                <tr>
                  <td><?= (int)$f['FeedbackID'] ?></td>
                  <td><?= htmlspecialchars($f['Username']) ?></td>
                  <td><?= htmlspecialchars($f['Type']) ?></td>
                  <td><?= htmlspecialchars($f['Rating']) ?></td>
                  <td><?= htmlspecialchars(mb_strimwidth($f['FeedbackText'], 0, 80, '…')) ?></td>
                  <td><?= htmlspecialchars($f['CreatedAt']) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="6">No feedback.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="table-card">
      <h3 style="margin:8px 0">Product Reviews (latest)</h3>
      <div class="table-box">
        <table class="report">
          <thead>
            <tr>
              <th>Product</th>
              <th>User</th>
              <th>Rating</th>
              <th>Comment</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($reviews): ?>
              <?php foreach ($reviews as $r): ?>
                <tr>
                  <td><?= htmlspecialchars($r['Product']) ?></td>
                  <td><?= htmlspecialchars($r['Username']) ?></td>
                  <td><?= htmlspecialchars($r['Rating']) ?></td>
                  <td><?= htmlspecialchars(mb_strimwidth($r['Comment'], 0, 80, '…')) ?></td>
                  <td><?= htmlspecialchars($r['CreatedAt']) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="5">No reviews.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="table-card">
      <h3 style="margin:8px 0">Rating Distribution</h3>
      <div class="table-box">
        <table class="report">
          <thead>
            <tr>
              <th>Rating</th>
              <th>Count</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($ratingDist): ?>
              <?php foreach ($ratingDist as $r): ?>
                <tr>
                  <td><?= htmlspecialchars($r['Rating']) ?></td>
                  <td><?= (int)$r['Cnt'] ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="2">No data.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php elseif ($section === 'cs'): ?>
    <div class="table-card">
      <h3 style="margin:8px 0">Cancellation Requests</h3>
      <div class="table-box">
        <table class="report">
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
            <?php if ($tickets): ?>
              <?php foreach ($tickets as $t): ?>
                <tr>
                  <td>#<?= (int)$t['cancellationId'] ?></td>
                  <td>#<?= (int)$t['OrderID'] ?></td>
                  <td><?= htmlspecialchars($t['Username'] ?? 'Unknown') ?></td>
                  <td><?= htmlspecialchars($t['Status']) ?></td>
                  <td><?= htmlspecialchars(mb_strimwidth($t['Reason'], 0, 80, '…')) ?></td>
                  <td><?= htmlspecialchars($t['RequestedAt']) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="6">No requests.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="table-card">
      <h3 style="margin:8px 0">Requests by Status</h3>
      <div class="table-box">
        <table class="report">
          <thead>
            <tr>
              <th>Status</th>
              <th>Count</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($ticketStatus): ?>
              <?php foreach ($ticketStatus as $s): ?>
                <tr>
                  <td><?= htmlspecialchars($s['Status']) ?></td>
                  <td><?= (int)$s['cnt'] ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="2">No data.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</body>

<?php include 'admin_footer.php'; ?>
