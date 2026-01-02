<?php
// admin_orders.php
require '../config.php';
session_start();

// only admins
$user = $_SESSION['user'] ?? null;
if (!$user || $user['Role'] !== 'Admin') {
  header('Location: login.php');
  exit;
}
$adminID = (int)($user['UserID'] ?? 0);

/**
 * Finalize a cancellation or return/refund:
 * - return stock
 * - reverse reward points
 * - reverse promotions
 * - set order status (Canceled vs Return & Refund)
 *
 * @param string $mode 'CANCEL' or 'REFUND'
 */
function finalizeCancellationOrRefund(PDO $pdo, int $cID, int $adminID, string $mode = 'REFUND')
{
  // 2a) Fetch order + user
  $stmt2 = $pdo->prepare("
    SELECT o.OrderID,
           o.UserID,
           o.TotalAmt,
           o.RewardPointsUsed
    FROM orders o
    JOIN ordercancellation c ON c.OrderID = o.OrderID
    WHERE c.CancellationID = ?
    LIMIT 1
  ");
  $stmt2->execute([$cID]);
  $orderRow = $stmt2->fetch(PDO::FETCH_ASSOC);

  if (!$orderRow) {
    throw new RuntimeException('Order not found for this cancellation.');
  }

  $orderID     = (int)$orderRow['OrderID'];
  $orderUserID = (int)$orderRow['UserID'];

  /* 2b) Points from reward_ledger (EARN / REDEEM) */
  $pointsEarned   = 0;
  $pointsRedeemed = 0;

  $ledStmt = $pdo->prepare("
    SELECT Type, SUM(Points) AS TotalPoints
    FROM reward_ledger
    WHERE RefOrderID = ?
    GROUP BY Type
  ");
  $ledStmt->execute([$orderID]);

  foreach ($ledStmt as $row) {
    $type  = $row['Type'];
    $total = (int)$row['TotalPoints'];
    if ($type === 'EARN') {
      $pointsEarned = $total;
    } elseif ($type === 'REDEEM') {
      $pointsRedeemed = $total;
    }
  }

  /* 2c) Return stock based on order items */

  // Fetch user reason for Note column
  $cReasonStmt = $pdo->prepare("
    SELECT Reason
    FROM ordercancellation
    WHERE CancellationID = ?
    LIMIT 1
  ");
  $cReasonStmt->execute([$cID]);
  $cReason = $cReasonStmt->fetchColumn() ?? '';

  // Get items for this order
  $itemStmt = $pdo->prepare("
    SELECT ProductID, ColorName, Size, Quantity
    FROM orderitem
    WHERE OrderID = ?
  ");
  $itemStmt->execute([$orderID]);
  $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

  // Map (ProductID + ColorName + Size) -> ColorSizeID
  $csStmt = $pdo->prepare("
    SELECT pcs.ColorSizeID, pcs.Stock
    FROM product_color_sizes pcs
    JOIN product_colors pc ON pc.ProductColorID = pcs.ProductColorID
    WHERE pc.ProductID = ?
      AND pc.ColorName = ?
      AND pcs.Size = ?
    LIMIT 1
  ");

  // Update stock in product_color_sizes
  $stockUpdStmt = $pdo->prepare("
    UPDATE product_color_sizes
       SET Stock = ?
     WHERE ColorSizeID = ?
  ");

  // Insert movement row
  $smIns = $pdo->prepare("
    INSERT INTO stock_movements
      (ColorSizeID, MovementType, Reason, QtyChange, OldStock, NewStock,
       ReferenceType, ReferenceID, Note, PerformedBy, CreatedAt)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
  ");

  foreach ($items as $it) {
    $productID = (int)$it['ProductID'];
    $colorName = $it['ColorName'];
    $size      = $it['Size'];
    $qty       = (int)$it['Quantity'];

    if ($productID <= 0 || !$colorName || !$size || $qty <= 0) {
      continue;
    }

    // Find ColorSizeID + current stock
    $csStmt->execute([$productID, $colorName, $size]);
    $csRow = $csStmt->fetch(PDO::FETCH_ASSOC);
    if (!$csRow) {
      continue;
    }

    $colorSizeID = (int)$csRow['ColorSizeID'];
    $oldStock    = (int)$csRow['Stock'];
    $newStock    = $oldStock + $qty; // stock coming back in

    // 1) Update stock
    $stockUpdStmt->execute([$newStock, $colorSizeID]);

    // 2) Insert stock movement
    $smIns->execute([
      $colorSizeID,
      'IN',             // MovementType
      'RETURN',         // Reason (you can change label later if you want)
      $qty,
      $oldStock,
      $newStock,
      'Return',         // ReferenceType
      (string)$orderID, // ReferenceID
      $cReason,         // Note = user's reason
      $adminID
    ]);
  }

  /* 2d) Reverse reward points */
  if ($pointsEarned > 0 || $pointsRedeemed > 0) {
    $netBalanceChange     = -$pointsEarned + $pointsRedeemed;
    $netAccumulatedChange = -$pointsEarned;

    $rpUpd = $pdo->prepare("
      UPDATE reward_points
         SET Balance     = Balance + ?,
             Accumulated = Accumulated + ?
       WHERE UserID = ?
    ");
    $rpUpd->execute([$netBalanceChange, $netAccumulatedChange, $orderUserID]);

    $ledgerIns = $pdo->prepare("
      INSERT INTO reward_ledger
        (UserID, Type, Points, RefOrderID, CreatedAt)
      VALUES (?, ?, ?, ?, NOW())
    ");

    if ($pointsEarned > 0) {
      $ledgerIns->execute([
        $orderUserID,
        'AUTO_REVERSAL_EARN',
        $pointsEarned,
        $orderID
      ]);
    }
    if ($pointsRedeemed > 0) {
      $ledgerIns->execute([
        $orderUserID,
        'AUTO_REVERSAL_REDEEM',
        $pointsRedeemed,
        $orderID
      ]);
    }
  }

  /* 2e) Reverse TARGET promotion redemption */
  $tgtStmt = $pdo->prepare("
    SELECT pu.PromotionID
    FROM promotion_users pu
    JOIN promotions p ON p.PromotionID = pu.PromotionID
    WHERE pu.UserID = ?
      AND pu.IsRedeemed = 1
      AND p.PromotionType = 'Targeted'
  ");
  $tgtStmt->execute([$orderUserID]);
  $targetPromoIDs = $tgtStmt->fetchAll(PDO::FETCH_COLUMN);

  if ($targetPromoIDs) {
    $resetPU = $pdo->prepare("
      UPDATE promotion_users
         SET IsRedeemed = 0,
             RedeemedAt = NULL
       WHERE UserID = ?
         AND PromotionID = ?
    ");

    $decPromo = $pdo->prepare("
      UPDATE promotions
         SET RedemptionCount = GREATEST(RedemptionCount - 1, 0)
       WHERE PromotionID = ?
    ");

    foreach ($targetPromoIDs as $pid) {
      $pid = (int)$pid;
      if ($pid > 0) {
        $resetPU->execute([$orderUserID, $pid]);
        $decPromo->execute([$pid]);
      }
    }
  }

  /* 2f) Reverse CAMPAIGN promotion redemption */
  $cpStmt = $pdo->prepare("
    SELECT DISTINCT pp.PromotionID
    FROM promotion_products pp
    JOIN orderitem oi ON oi.ProductID = pp.ProductID
    JOIN promotions p ON p.PromotionID = pp.PromotionID
    WHERE oi.OrderID = ?
      AND p.PromotionType = 'Campaign'
  ");
  $cpStmt->execute([$orderID]);
  $campaignIDs = $cpStmt->fetchAll(PDO::FETCH_COLUMN);

  if ($campaignIDs) {
    $updPromo = $pdo->prepare("
      UPDATE promotions
         SET RedemptionCount = GREATEST(RedemptionCount - 1, 0)
       WHERE PromotionID = ?
    ");
    foreach ($campaignIDs as $pid) {
      $pid = (int)$pid;
      if ($pid > 0) {
        $updPromo->execute([$pid]);
      }
    }
  }

  /* 2g) Set order status */
  $newStatus = ($mode === 'CANCEL') ? 'Canceled' : 'Return & Refund';
  $upd2 = $pdo->prepare("
    UPDATE orders
       SET Status = ?
     WHERE OrderID = ?
  ");
  $upd2->execute([$newStatus, $orderID]);

  // 2h) Final status (RefundFinalStatus / RefundFinalAt) is now handled
  // in the caller (approval or final inspection step).
}

/* ==========================================================
   1) AJAX: update delivery info
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deliveryOrderID'])) {
  $orderID        = (int)$_POST['deliveryOrderID'];
  $courierName    = trim($_POST['courierName']   ?? '');
  $trackingNo     = trim($_POST['trackingNo']    ?? '');
  $deliveryStatus = trim($_POST['deliveryStatus'] ?? '');

  // Do not allow editing delivery for canceled / returned orders
  $stCheck = $pdo->prepare("SELECT Status FROM orders WHERE OrderID = ?");
  $stCheck->execute([$orderID]);
  $currentStatus = $stCheck->fetchColumn();

  if (in_array($currentStatus, ['Canceled', 'Return & Refund'], true)) {
    echo "Cannot update delivery for canceled / return-refund order";
    exit;
  }

  $validCouriers = ['JNT', 'GDEX', 'PosLaju'];
  $validDelStats = ['PickUp', 'WareHouse', 'Transit', 'OutOfDelivery'];

  if (
    !in_array($courierName,   $validCouriers, true) ||
    !in_array($deliveryStatus, $validDelStats, true) ||
    $trackingNo === ''
  ) {
    echo "Invalid delivery data";
    exit;
  }

  // upsert delivery row
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM delivery WHERE OrderID = ?");
  $stmt->execute([$orderID]);
  $exists = $stmt->fetchColumn() > 0;

  if ($exists) {
    $sql = "UPDATE delivery
               SET CourierName = ?, TrackingNo = ?, Status = ?
             WHERE OrderID = ?";
    $params = [$courierName, $trackingNo, $deliveryStatus, $orderID];
  } else {
    $sql = "INSERT INTO delivery (OrderID, CourierName, TrackingNo, Status)
            VALUES (?, ?, ?, ?)";
    $params = [$orderID, $courierName, $trackingNo, $deliveryStatus];
  }

  $ok = $pdo->prepare($sql)->execute($params);
  echo $ok ? "success" : "DB error";
  exit;
}

/* ==========================================================
   2) AJAX: update order status
   ========================================================== */
if (
  $_SERVER['REQUEST_METHOD'] === 'POST'
  && isset($_POST['orderID'], $_POST['status'])
  && !isset($_POST['deliveryOrderID']) // avoid clash with #1
  && !isset($_POST['cancellationID'])  // avoid clash with #3
  && !isset($_POST['finalizeRefundID']) // avoid clash with #3b
) {
  $orderID = (int)$_POST['orderID'];
  $status  = trim($_POST['status']);

  $allowed = ['Pending', 'Packing', 'Shipped', 'Delivered', 'Canceled', 'Return & Refund'];
  if (!in_array($status, $allowed, true)) {
    echo "Invalid status";
    exit;
  }

  // 1) Update orders.Status
  $stUpd = $pdo->prepare("UPDATE orders SET Status = ? WHERE OrderID = ?");
  $ok    = $stUpd->execute([$status, $orderID]);

  if (!$ok) {
    echo "DB error";
    exit;
  }

  // 2) If status = Delivered, also update delivery.DeliveredAt
  if ($status === 'Delivered') {
    // Check if a delivery row already exists
    $stCheckDel = $pdo->prepare("
      SELECT DeliveryID
      FROM delivery
      WHERE OrderID = ?
      LIMIT 1
    ");
    $stCheckDel->execute([$orderID]);
    $deliveryID = $stCheckDel->fetchColumn();

    if ($deliveryID) {
      // Update existing row: mark as Delivered + set DeliveredAt + UpdatedAt
      $stDelUpd = $pdo->prepare("
        UPDATE delivery
           SET Status      = 'Delivered',
               DeliveredAt = NOW(),
               UpdatedAt   = NOW()
         WHERE OrderID = ?
      ");
      $stDelUpd->execute([$orderID]);
    } else {
      // Create a new delivery row with minimal info (NO NULLs for NOT NULL fields)
      $stDelIns = $pdo->prepare("
        INSERT INTO delivery (
          OrderID,
          AddressID,
          CourierName,
          TrackingNo,
          Status,
          UpdatedAt,
          DeliveredAt,
          Notes
        )
        VALUES (
          ?,            -- OrderID
          NULL,         -- AddressID
          'Not Assigned', -- CourierName (NOT NULL, so use default text)
          '',           -- TrackingNo (NOT NULL, so use empty string)
          'Delivered',  -- Status
          NOW(),        -- UpdatedAt
          NOW(),        -- DeliveredAt
          NULL          -- Notes
        )
      ");
      $stDelIns->execute([$orderID]);
    }
  }

  echo "success";
  exit;
}

/* ==========================================================
   3) AJAX: approve / reject cancellation (with reason)
   - For cancellation (no proof image): approve = finalize immediately
   - For return & refund (with proof): approve = STEP 1 only,
     admin must later click "Finalize Refund" to do stock/points rollback
   ========================================================== */
if (
  $_SERVER['REQUEST_METHOD'] === 'POST'
  && isset($_POST['cancellationID'], $_POST['cancelStatus'])
) {
  header('Content-Type: application/json');

  $cID       = (int) $_POST['cancellationID'];
  $cStat     = trim($_POST['cancelStatus']);
  $adminNote = trim($_POST['adminNote'] ?? '');

  if (!in_array($cStat, ['Approved', 'Rejected'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid status']);
    exit;
  }

  // require reason when Rejected
  if ($cStat === 'Rejected' && $adminNote === '') {
    echo json_encode([
      'status'  => 'error',
      'message' => 'Rejection reason is required.'
    ]);
    exit;
  }

  // 1) update ordercancellation row
  $upd1 = $pdo->prepare("
    UPDATE ordercancellation
       SET Status      = ?,
           ProcessedBy = 'Admin',
           AdminNote   = ?,
           ProcessedAt = NOW()
     WHERE CancellationID = ?
  ");
  if (!$upd1->execute([$cStat, $adminNote, $cID])) {
    echo json_encode(['status' => 'error', 'message' => 'Failed updating cancellation']);
    exit;
  }

  // Load row so we can see type (cancellation vs refund) and current flags
  $ocStmt = $pdo->prepare("
    SELECT OrderID, ProofImage
    FROM ordercancellation
    WHERE CancellationID = ?
    LIMIT 1
  ");

  $ocStmt->execute([$cID]);
  $oc = $ocStmt->fetch(PDO::FETCH_ASSOC);

  if (!$oc) {
    echo json_encode(['status' => 'error', 'message' => 'Cancellation record not found after update.']);
    exit;
  }

  $isRefund = !empty($oc['ProofImage']); // with proof = Return & Refund

  /* ======================================================
     2) If approved:
        - Cancellation (no proof): finalize immediately
        - Return & Refund (with proof): only step 1, no rollback yet
     ====================================================== */
  if ($cStat === 'Approved') {
    if ($isRefund) {
      // RETURN & REFUND STEP 1 ONLY
      // Mark refund flow as pending final inspection / finalization
      $updRefundPending = $pdo->prepare("
      UPDATE ordercancellation
         SET RefundFinalStatus = 'Pending'
       WHERE CancellationID = ?
    ");
      $updRefundPending->execute([$cID]);

      echo json_encode([
        'status'  => 'success',
        'message' => 'Return / refund request approved. Please finalize after goods are received.'
      ]);
      exit;
    }

    // CANCELLATION (Packing) -> finalize immediately
    try {
      finalizeCancellationOrRefund($pdo, $cID, $adminID, 'CANCEL');
      echo json_encode([
        'status'  => 'success',
        'message' => 'Cancellation approved and finalized.'
      ]);
      exit;
    } catch (Throwable $e) {
      echo json_encode([
        'status'  => 'error',
        'message' => 'Approved but failed to finalize cancellation: ' . $e->getMessage()
      ]);
      exit;
    }
  }

  // Rejected (for both types)
  echo json_encode(['status' => 'success', 'message' => 'Updated']);
  exit;
}
/* ==========================================================
   3b) AJAX: finalize return & refund AFTER inspection
   - Admin chooses:
     * Approved (item OK) -> rollback stock/points/promos
     * Rejected (after inspection) -> no rollback
   ========================================================== */
if (
  $_SERVER['REQUEST_METHOD'] === 'POST'
  && isset($_POST['finalizeRefundID'], $_POST['finalizeDecision'])
) {
  header('Content-Type: application/json');

  $cID      = (int)$_POST['finalizeRefundID'];
  $decision = ($_POST['finalizeDecision'] === 'Approved') ? 'Approved' : 'Rejected';
  $note     = trim($_POST['finalizeNote'] ?? '');

  if ($decision === 'Rejected' && $note === '') {
    echo json_encode(['status' => 'error', 'message' => 'Reason is required when rejecting after inspection.']);
    exit;
  }

  // Load cancellation row
  $stmt = $pdo->prepare("
    SELECT CancellationID,
           OrderID,
           ProofImage,
           Status,
           RefundFinalStatus
    FROM ordercancellation
    WHERE CancellationID = ?
    LIMIT 1
  ");

  $stmt->execute([$cID]);
  $oc = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$oc) {
    echo json_encode(['status' => 'error', 'message' => 'Return record not found.']);
    exit;
  }

  if (empty($oc['ProofImage'])) {
    echo json_encode(['status' => 'error', 'message' => 'This record is a cancellation, not a return.']);
    exit;
  }

  if ($oc['Status'] !== 'Approved') {
    echo json_encode(['status' => 'error', 'message' => 'Return is not approved yet (first-level decision).']);
    exit;
  }

  if (!empty($oc['RefundFinalStatus']) && $oc['RefundFinalStatus'] !== 'Pending') {
    echo json_encode([
      'status'  => 'error',
      'message' => 'Refund already finalized with status: ' . $oc['RefundFinalStatus']
    ]);
    exit;
  }

  try {
    if ($decision === 'Approved') {
      // APPROVED AFTER INSPECTION: do full rollback
      $pdo->beginTransaction();

      finalizeCancellationOrRefund($pdo, $cID, $adminID, 'REFUND');

      $upd = $pdo->prepare("
        UPDATE ordercancellation
           SET RefundFinalStatus = 'Approved',
               RefundFinalNote   = ?,
               RefundFinalAt     = NOW()
         WHERE CancellationID = ?
      ");
      $upd->execute([$note, $cID]);

      $pdo->commit();

      echo json_encode([
        'status'  => 'success',
        'message' => 'Refund approved. Stock, points and promotions have been reversed.'
      ]);
    } else {
      // REJECTED AFTER INSPECTION: no rollback, just mark as final
      $pdo->beginTransaction();

      $upd = $pdo->prepare("
        UPDATE ordercancellation
           SET Status            = 'Rejected',
               RefundFinalStatus = 'Rejected',
               RefundFinalNote   = ?,
               RefundFinalAt     = NOW()
         WHERE CancellationID = ?
      ");

      $upd->execute([$note, $cID]);

      $pdo->commit();

      echo json_encode([
        'status'  => 'success',
        'message' => 'Refund rejected. No changes were made to stock, points or promotions.'
      ]);
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode([
      'status' => 'error',
      'message' => 'Failed to finalize refund: ' . $e->getMessage()
    ]);
  }
  exit;
}

/* ==========================================================
   4) Fetch data for display
   ========================================================== */

// orders + delivery
$order_rows = $pdo->query("
  SELECT
    o.OrderID,
    o.OrderDate,
    o.TotalAmt,
    o.Status,
    u.Username AS CustomerName,
    u.Email,
    d.CourierName,
    d.TrackingNo,
    d.Status AS DeliveryStatus
  FROM orders o
  JOIN `user` u ON o.UserID = u.UserID
  LEFT JOIN delivery d ON o.OrderID = d.OrderID
  ORDER BY o.OrderDate DESC
")->fetchAll(PDO::FETCH_ASSOC);

// cancellation requests (with proof image + admin note)
$cancellation_rows = $pdo->query("
  SELECT
    c.CancellationID,
    c.OrderID,
    c.Reason,
    c.ProofImage,
    c.Status           AS CancelStatus,
    c.AdminNote,
    c.RequestedAt,
    c.RefundFinalStatus
  FROM ordercancellation c
  ORDER BY c.RequestedAt DESC
")->fetchAll(PDO::FETCH_ASSOC);

include 'admin_header.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Admin Orders</title>
  <link rel="stylesheet" href="assets/admin_product.css">
  <style>
    .main-content {
      background: #fff;
      border-radius: 8px;
      padding: 24px;
      margin: 20px 0;
      /* full width inside admin layout */
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .tab-container {
      display: flex;
      margin-bottom: 16px;
      border-bottom: 2px solid #ddd;
    }

    .tab {
      padding: 12px 24px;
      cursor: pointer;
      font-weight: bold;
      border-bottom: 2px solid transparent;
      transition: background .2s, border-color .2s;
    }

    .tab.active {
      background: #f8f9fa;
      border-color: #0d1b2a;
    }

    .tab-content {
      display: none;
    }

    .tab-content.active {
      display: block;
    }

    .table-responsive {
      overflow-x: auto;
      margin-bottom: 24px;
    }

    .admin-table {
      width: 100%;
      border-collapse: collapse;
      min-width: 800px;
    }

    .admin-table th,
    .admin-table td {
      padding: 12px;
      border: 1px solid #ddd;
      vertical-align: middle;
    }

    .admin-table th {
      background: #0d1b2a;
      color: #fff;
    }

    .btn {
      display: inline-block;
      padding: 8px 14px;
      margin: 4px 0;
      border: none;
      border-radius: 20px;
      color: #fff;
      cursor: pointer;
      font-size: 13px;
    }

    .btn-primary {
      background: #007bff;
    }

    .btn-cancel {
      background: #dc3545;
    }

    .proof-thumb {
      max-width: 80px;
      max-height: 80px;
      border-radius: 4px;
      object-fit: cover;
      display: block;
      border: 1px solid #ddd;
    }

    .proof-wrapper {
      display: flex;
      flex-direction: column;
      gap: 4px;
      align-items: flex-start;
    }

    .admin-note-text {
      font-size: 12px;
      color: #555;
      margin-top: 4px;
    }

    /* Filter bar */
    .filter-bar {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      align-items: center;
      margin: 10px 0 16px;
    }

    .filter-bar label {
      font-size: 13px;
      color: #555;
      margin-right: 4px;
    }

    .filter-bar input[type="text"],
    .filter-bar select {
      padding: 6px 10px;
      border-radius: 4px;
      border: 1px solid #ccc;
      font-size: 13px;
      min-width: 200px;
      background: #fff;
    }

    /* Make status & delivery fields match search style */
    .order-status,
    .delivery-courier,
    .delivery-status,
    .delivery-tracking {
      padding: 6px 10px;
      border-radius: 4px;
      border: 1px solid #ccc;
      font-size: 13px;
      background: #fff;
      min-width: 160px;
    }

    .delivery-tracking {
      min-width: 180px;
    }

    .order-status:disabled,
    .delivery-courier:disabled,
    .delivery-status:disabled,
    .delivery-tracking:disabled {
      background: #f3f4f6;
      color: #6b7280;
      cursor: not-allowed;
    }

    /* Status badges for cancellation status */
    .status-badge {
      display: inline-block;
      padding: 3px 8px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }

    .badge-pending {
      background: #fff3cd;
      color: #856404;
    }

    .badge-approved {
      background: #d4edda;
      color: #155724;
    }

    .badge-rejected {
      background: #f8d7da;
      color: #721c24;
    }

    /* Highlighted row from notifications */
    .highlight-row {
      background: #effde4ff !important;
      /* soft yellow */
      transition: background 0.3s ease;
    }
  </style>
</head>

<body>
  <div class="main-content">

    <div class="tab-container">
      <div class="tab active" data-target="order-maint">Order Maintenance</div>
      <div class="tab" data-target="cancel">Cancel / Return & Refund</div>
    </div>

    <!-- Order Maintenance -->
    <div id="order-maint" class="tab-content active">
      <h2>Order Maintenance</h2>

      <!-- Filters for order table -->
      <div class="filter-bar">
        <div>
          <label for="orderSearch">Search:</label>
          <input type="text" id="orderSearch" placeholder="Order ID, customer, email...">
        </div>
        <div>
          <label for="orderStatusFilter">Status:</label>
          <select id="orderStatusFilter">
            <option value="">All statuses</option>
            <option value="Pending">Pending</option>
            <option value="Packing">Packing</option>
            <option value="Shipped">Shipped</option>
            <option value="Delivered">Delivered</option>
            <option value="Canceled">Canceled</option>
            <option value="Return & Refund">Return & Refund</option>
          </select>
        </div>
      </div>

      <div class="table-responsive">
        <table class="admin-table">
          <thead>
            <tr>
              <th>Order ID</th>
              <th>Customer</th>
              <th>Date</th>
              <th>Status</th>
              <th>Delivery</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($order_rows as $r): ?>
              <?php
              $deliveryDisabled = (
                $r['Status'] === 'Canceled' ||
                $r['Status'] === 'Return & Refund'
              ) ? 'disabled' : '';
              ?>
              <tr data-order-id="<?= (int)$r['OrderID'] ?>">
                <td>#<?= htmlspecialchars($r['OrderID']) ?></td>
                <td>
                  <?= htmlspecialchars($r['CustomerName']) ?><br>
                  <small><?= htmlspecialchars($r['Email']) ?></small>
                </td>
                <td><?= htmlspecialchars($r['OrderDate']) ?></td>
                <td>
                  <select class="order-status"
                    data-order-id="<?= $r['OrderID'] ?>">
                    <?php foreach (['Pending', 'Packing', 'Shipped', 'Delivered', 'Canceled', 'Return & Refund'] as $s): ?>
                      <option <?= ($r['Status'] === $s ? 'selected' : '') ?>><?= $s ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td>
                  <select class="delivery-courier"
                    data-order-id="<?= $r['OrderID'] ?>" <?= $deliveryDisabled ?>>
                    <option <?= ($r['CourierName'] === '' || $r['CourierName'] === null ? 'selected' : '') ?>>Not Assigned</option>
                    <?php foreach (['JNT', 'GDEX', 'PosLaju'] as $c): ?>
                      <option <?= ($r['CourierName'] === $c ? 'selected' : '') ?>><?= $c ?></option>
                    <?php endforeach; ?>
                  </select><br>
                  <input type="text" placeholder="Tracking #"
                    class="delivery-tracking"
                    data-order-id="<?= $r['OrderID'] ?>"
                    value="<?= htmlspecialchars($r['TrackingNo'] ?? '') ?>" <?= $deliveryDisabled ?>><br>
                  <select class="delivery-status"
                    data-order-id="<?= $r['OrderID'] ?>" <?= $deliveryDisabled ?>>
                    <?php foreach (['PickUp', 'WareHouse', 'Transit', 'OutOfDelivery'] as $ds): ?>
                      <option <?= ($r['DeliveryStatus'] === $ds ? 'selected' : '') ?>><?= $ds ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td>
                  <button class="btn btn-primary"
                    onclick="updateOrder(<?= $r['OrderID'] ?>)">
                    Update Status
                  </button><br>
                  <button class="btn btn-primary"
                    onclick="updateDelivery(<?= $r['OrderID'] ?>)"
                    <?= $deliveryDisabled ?>>
                    Update Delivery
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Cancellation -->
    <div id="cancel" class="tab-content">
      <h2>Return & Refund Requests</h2>

      <!-- Filters for cancellation table -->
      <div class="filter-bar">
        <div>
          <label for="cancelSearch">Search:</label>
          <input type="text" id="cancelSearch" placeholder="Return ID, Order ID, reason...">
        </div>
        <div>
          <label for="cancelStatusFilter">Status:</label>
          <select id="cancelStatusFilter">
            <option value="">All statuses</option>
            <option value="Pending">Pending</option>
            <option value="Approved">Approved</option>
            <option value="Rejected">Rejected</option>
          </select>
        </div>
      </div>

      <div class="table-responsive">
        <table class="admin-table">
          <thead>
            <tr>
              <th>Return ID</th>
              <th>Order ID</th>
              <th>Reason</th>
              <th>Proof Image</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cancellation_rows as $c): ?>
              <?php
              $cStatus = $c['CancelStatus'] ?? 'Pending';
              $badgeClass = 'badge-pending';
              if ($cStatus === 'Approved') {
                $badgeClass = 'badge-approved';
              } elseif ($cStatus === 'Rejected') {
                $badgeClass = 'badge-rejected';
              }
              ?>
              <tr data-is-refund="<?= !empty($c['ProofImage']) ? '1' : '0' ?>"
                data-cancel-id="<?= (int)$c['CancellationID'] ?>">
                <td>#<?= htmlspecialchars($c['CancellationID']) ?></td>
                <td>#<?= htmlspecialchars($c['OrderID']) ?></td>
                <td><?= htmlspecialchars($c['Reason']) ?></td>
                <td>
                  <div class="proof-wrapper">
                    <?php if (!empty($c['ProofImage'])):
                      $imgPath = '../uploads/refund_proof/' . $c['ProofImage'];
                    ?>
                      <a href="<?= htmlspecialchars($imgPath) ?>" target="_blank">
                        <img src="<?= htmlspecialchars($imgPath) ?>" alt="Proof"
                          class="proof-thumb">
                      </a>
                    <?php else: ?>
                      <em>No image</em>
                    <?php endif; ?>

                    <?php if (!empty($c['AdminNote'])): ?>
                      <div class="admin-note-text">
                        <strong>Admin note:</strong>
                        <?= htmlspecialchars($c['AdminNote']) ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </td>
                <td class="cancel-status-cell" data-status="<?= htmlspecialchars($cStatus) ?>">
                  <span class="status-badge <?= $badgeClass ?>">
                    <?= htmlspecialchars($cStatus) ?>
                  </span>
                </td>
                <td>
                  <?php
                  $cStatus           = $c['CancelStatus'] ?? 'Pending';
                  $isRefund          = !empty($c['ProofImage']); // with proof = Return & Refund
                  $refundFinalStatus = $c['RefundFinalStatus'] ?? 'Pending';
                  $canDecision       = ($cStatus === 'Pending');
                  $canFinalizeRefund = (
                    $isRefund &&
                    $cStatus === 'Approved' &&
                    $refundFinalStatus === 'Pending'
                  );
                  ?>

                  <?php if ($canDecision): ?>
                    <button class="btn btn-primary"
                      onclick="updateCancel(<?= $c['CancellationID'] ?>,'Approved')">
                      Approve
                    </button>
                    <button class="btn btn-cancel"
                      onclick="updateCancel(<?= $c['CancellationID'] ?>,'Rejected')">
                      Reject
                    </button>
                  <?php endif; ?>

                  <?php if ($canFinalizeRefund): ?>
                    <button class="btn btn-primary"
                      onclick="finalizeRefund(<?= $c['CancellationID'] ?>,'Approved')">
                      Proceed Refund
                    </button>
                    <button class="btn btn-cancel"
                      onclick="finalizeRefund(<?= $c['CancellationID'] ?>,'Rejected')">
                      Reject Refund
                    </button>
                  <?php endif; ?>

                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /.main-content -->

  <script>
    // Tab switching + support for #cancel / #return anchors
    function activateTab(targetId) {
      const tabs = document.querySelectorAll('.tab');
      const contents = document.querySelectorAll('.tab-content');

      tabs.forEach(x => x.classList.remove('active'));
      contents.forEach(x => x.classList.remove('active'));

      const tab = Array.from(tabs).find(el => el.dataset.target === targetId);
      const panel = document.getElementById(targetId);

      if (tab && panel) {
        tab.classList.add('active');
        panel.classList.add('active');
      }
    }

    // Click on tab header
    document.querySelectorAll('.tab').forEach(t => {
      t.addEventListener('click', () => {
        const target = t.dataset.target;
        activateTab(target);

        // Keep URL hash roughly in sync
        if (target === 'cancel') {
          history.replaceState(null, '', '#cancel');
        } else {
          history.replaceState(null, '', window.location.pathname);
        }
      });
    });

    // When page loads, handle anchor from notifications
    window.addEventListener('DOMContentLoaded', () => {
      const hash = window.location.hash;

      if (hash === '#cancel') {
        // Show Cancel / Return & Refund tab (all rows)
        activateTab('cancel');
        if (typeof cancelStatusFilter !== 'undefined' && cancelStatusFilter) {
          cancelStatusFilter.value = '';
        }
        if (typeof cancelSearchInput !== 'undefined' && cancelSearchInput) {
          cancelSearchInput.value = '';
        }
        if (typeof filterCancels === 'function') {
          filterCancels();
        }

      } else if (hash === '#return') {
        // Show only Return & Refund rows (data-is-refund="1")
        activateTab('cancel');
        if (typeof cancelStatusFilter !== 'undefined' && cancelStatusFilter) {
          cancelStatusFilter.value = '';
        }
        if (typeof cancelSearchInput !== 'undefined' && cancelSearchInput) {
          cancelSearchInput.value = '';
        }
        if (typeof filterCancels === 'function') {
          filterCancels();
        }

        document.querySelectorAll('#cancel tbody tr').forEach(row => {
          const isRefund = row.dataset.isRefund === '1';
          row.style.display = isRefund ? '' : 'none';
        });

      } else {
        // Default: show Order Maintenance
        activateTab('order-maint');
      }
    });

    const endpoint = window.location.pathname;

    function updateOrder(id) {
      const val = document.querySelector(`.order-status[data-order-id="${id}"]`).value;
      fetch(endpoint, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: new URLSearchParams({
            orderID: id,
            status: val
          })
        })
        .then(r => r.text())
        .then(txt => {
          alert(txt.trim() === 'success' ? 'Status updated!' : 'Error: ' + txt);
          if (txt.trim() === 'success') location.reload();
        });
    }

    function updateDelivery(id) {
      const c = document.querySelector(`.delivery-courier[data-order-id="${id}"]`).value;
      const t = document.querySelector(`.delivery-tracking[data-order-id="${id}"]`).value;
      const s = document.querySelector(`.delivery-status[data-order-id="${id}"]`).value;

      fetch(endpoint, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: new URLSearchParams({
            deliveryOrderID: id,
            courierName: c,
            trackingNo: t,
            deliveryStatus: s
          })
        })
        .then(r => r.text())
        .then(txt => {
          alert(txt.trim() === 'success' ? 'Delivery updated!' : 'Error: ' + txt);
          if (txt.trim() === 'success') location.reload();
        });
    }

    function updateCancel(cid, stat) {
      let note = '';

      if (stat === 'Rejected') {
        note = prompt('Please provide a reason for rejection:');
        if (note === null) return;
        note = note.trim();
        if (!note) {
          alert('Rejection reason is required.');
          return;
        }
      }

      const params = new URLSearchParams({
        cancellationID: cid,
        cancelStatus: stat
      });
      if (note) params.append('adminNote', note);

      fetch(endpoint, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: params.toString()
        })
        .then(r => r.json())
        .then(js => {
          if (js.status === 'success') {
            alert(js.message);
            location.reload();
          } else {
            alert('Error: ' + js.message);
          }
        })
        .catch(err => {
          alert('Unexpected error: ' + err);
        });
    }

    function finalizeRefund(cid, decision) {
      decision = (decision === 'Approved') ? 'Approved' : 'Rejected';

      let note = '';

      if (decision === 'Approved') {
        const ok = confirm(
          'Confirm that returned goods are received and in good condition?\n\n' +
          'If you confirm, the system will reverse stock, reward points and promotions.'
        );
        if (!ok) return;
      } else {
        note = prompt(
          'The refund will be REJECTED.\n' +
          'Please enter the reason:'
        );
        if (note === null) return;
        note = note.trim();
        if (!note) {
          alert('Reason is required when rejecting after inspection.');
          return;
        }
      }

      const params = new URLSearchParams({
        finalizeRefundID: cid,
        finalizeDecision: decision
      });
      if (note) params.append('finalizeNote', note);

      fetch(endpoint, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: params.toString()
        })
        .then(r => r.json())
        .then(js => {
          if (js.status === 'success') {
            alert(js.message);
            location.reload();
          } else {
            alert('Error: ' + js.message);
          }
        })
        .catch(err => {
          alert('Unexpected error: ' + err);
        });
    }

    // ======= Order filters (search + status) =======
    const orderSearchInput = document.getElementById('orderSearch');
    const orderStatusFilter = document.getElementById('orderStatusFilter');

    function filterOrders() {
      const term = (orderSearchInput.value || '').toLowerCase().trim();
      const statusVal = orderStatusFilter.value;

      document.querySelectorAll('#order-maint tbody tr').forEach(row => {
        const orderIdText = (row.querySelector('td:nth-child(1)')?.innerText || '').toLowerCase();
        const customerText = (row.querySelector('td:nth-child(2)')?.innerText || '').toLowerCase();
        const statusSelect = row.querySelector('.order-status');
        const rowStatus = statusSelect ? statusSelect.value : '';

        const matchSearch = !term ||
          orderIdText.includes(term) ||
          customerText.includes(term);

        const matchStatus = !statusVal || rowStatus === statusVal;

        row.style.display = (matchSearch && matchStatus) ? '' : 'none';
      });
    }

    if (orderSearchInput && orderStatusFilter) {
      orderSearchInput.addEventListener('keyup', filterOrders);
      orderStatusFilter.addEventListener('change', filterOrders);
      filterOrders();
    }

    // ======= Cancellation filters (search + status) =======
    const cancelSearchInput = document.getElementById('cancelSearch');
    const cancelStatusFilter = document.getElementById('cancelStatusFilter');

    function filterCancels() {
      const term = (cancelSearchInput.value || '').toLowerCase().trim();
      const statusVal = cancelStatusFilter.value;

      document.querySelectorAll('#cancel tbody tr').forEach(row => {
        const returnIdText = (row.querySelector('td:nth-child(1)')?.innerText || '').toLowerCase();
        const orderIdText = (row.querySelector('td:nth-child(2)')?.innerText || '').toLowerCase();
        const reasonText = (row.querySelector('td:nth-child(3)')?.innerText || '').toLowerCase();
        const statusCell = row.querySelector('.cancel-status-cell');
        const rowStatus = statusCell ? (statusCell.dataset.status || '').trim() : '';

        const matchSearch = !term ||
          returnIdText.includes(term) ||
          orderIdText.includes(term) ||
          reasonText.includes(term);

        const matchStatus = !statusVal || rowStatus === statusVal;

        row.style.display = (matchSearch && matchStatus) ? '' : 'none';
      });
    }

    if (cancelSearchInput && cancelStatusFilter) {
      cancelSearchInput.addEventListener('keyup', filterCancels);
      cancelStatusFilter.addEventListener('change', filterCancels);
      filterCancels();
    }

    // ======= Scroll & highlight row based on hash from notification =======
    function highlightRow(rowSelector) {
      const row = document.querySelector(rowSelector);
      if (!row) return;

      row.classList.add('highlight-row');
      row.scrollIntoView({
        behavior: 'smooth',
        block: 'center'
      });

      // remove highlight after a couple of seconds
      setTimeout(() => {
        row.classList.remove('highlight-row');
      }, 2500);
    }

    window.addEventListener('load', () => {
      const hash = window.location.hash || '';
      if (!hash) return;

      let base = hash;
      let idPart = null;

      // e.g. "#order-12", "#cancel-5", "#return-7"
      if (hash.includes('-')) {
        const parts = hash.split('-');
        base = parts[0]; // "#order", "#cancel", "#return"
        idPart = parts[1]; // "12", "5", "7"
      }

      if (base === '#order') {
        // Open Order Maintenance tab
        document.querySelector('.tab[data-target="order-maint"]')?.click();

        if (idPart) {
          highlightRow(`#order-maint tbody tr[data-order-id="${idPart}"]`);
        }

      } else if (base === '#cancel') {
        // Open Cancel / Return & Refund tab
        document.querySelector('.tab[data-target="cancel"]')?.click();

        // Apply current filters, then highlight specific cancellation
        if (typeof filterCancels === 'function') {
          filterCancels();
        }

        if (idPart) {
          highlightRow(`#cancel tbody tr[data-cancel-id="${idPart}"]`);
        }

      } else if (base === '#return') {
        // Open Cancel / Return & Refund tab and show ONLY return/refund rows
        document.querySelector('.tab[data-target="cancel"]')?.click();

        if (typeof filterCancels === 'function') {
          filterCancels();
        }

        // Keep only rows with data-is-refund="1"
        document.querySelectorAll('#cancel tbody tr').forEach(row => {
          const isRefund = row.dataset.isRefund === '1';
          row.style.display = isRefund ? '' : 'none';
        });

        if (idPart) {
          highlightRow(`#cancel tbody tr[data-cancel-id="${idPart}"]`);
        }
      }
    });
  </script>

  <?php include 'admin_footer.php'; ?>
</body>

</html>