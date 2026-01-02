<?php
session_start();
require_once '../login_base.php'; // uses $_db and session like your other user pages

if (empty($_SESSION['user']['UserID'])) {
  header('Location: ../login.php');
  exit;
}
$userID = (int)$_SESSION['user']['UserID'];

$errors  = [];
$success = null;

// Small helper for HTML escaping
function h($v)
{
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* ============================================================
    1) Handle rating + review submission (POST)
    ============================================================ */
if (
  $_SERVER['REQUEST_METHOD'] === 'POST'
  && isset($_POST['action'])
  && $_POST['action'] === 'rate_review'
) {
  $productID = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
  $orderID   = isset($_POST['order_id'])   ? (int)$_POST['order_id']   : 0;
  $rating    = isset($_POST['rating'])     ? (float)$_POST['rating']   : 0;
  $comment   = trim($_POST['comment'] ?? '');

  // Basic validation
  if ($productID <= 0 || $orderID <= 0) {
    $errors[] = 'Invalid product or order.';
  }
  if ($rating < 1 || $rating > 5) {
    $errors[] = 'Please choose a rating between 1 and 5.';
  }

  // Check that this order belongs to this user AND is Delivered AND has this product
  if (!$errors) {
    $sql = "
              SELECT o.OrderID, o.Status
              FROM orderitem oi
                JOIN orders o ON o.OrderID = oi.OrderID
              WHERE o.OrderID = :oid
                AND o.UserID  = :uid
                AND oi.ProductID = :pid
              LIMIT 1
          ";
    $stmt = $_db->prepare($sql);
    $stmt->execute([
      ':oid' => $orderID,
      ':uid' => $userID,
      ':pid' => $productID,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      $errors[] = 'You are not allowed to review this product for this order.';
    } elseif ($row['Status'] !== 'Delivered') {
      $errors[] = 'You can only review products from Delivered orders.';
    }
  }

  if (!$errors) {
    // Save rating into product_ratings (unique ProductID+UserID)
    $stmt = $_db->prepare("
              INSERT INTO product_ratings (ProductID, UserID, Rating)
              VALUES (:pid, :uid, :rating)
              ON DUPLICATE KEY UPDATE
                Rating    = VALUES(Rating),
                CreatedAt = CURRENT_TIMESTAMP
          ");
    $stmt->execute([
      ':pid'    => $productID,
      ':uid'    => $userID,
      ':rating' => $rating,
    ]);

    // Save comment into product_reviews (unique ProductID+UserID)
    if ($comment !== '') {
      $stmt = $_db->prepare("
                  INSERT INTO product_reviews (ProductID, UserID, Comment)
                  VALUES (:pid, :uid, :comment)
                  ON DUPLICATE KEY UPDATE
                    Comment  = VALUES(Comment),
                    CreatedAt = CURRENT_TIMESTAMP
              ");
      $stmt->execute([
        ':pid'     => $productID,
        ':uid'     => $userID,
        ':comment' => $comment,
      ]);
    }

    $success = 'Your rating / review has been saved.';
  }
}

/* ============================================================
  2) Cancellation for Pending / Packing orders
  - Pending  = instant auto-cancel (same as before)
  - Packing  = request, needs admin approval (no proof image)
  ============================================================ */
if (
  $_SERVER['REQUEST_METHOD'] === 'POST'
  && isset($_POST['action'])
  && $_POST['action'] === 'cancel_order'
) {
  $orderID = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;

  if ($orderID <= 0) {
    $errors[] = 'Invalid order selected.';
  }

  // Make sure order belongs to this user
  if (!$errors) {
    $stmt = $_db->prepare("
        SELECT *
        FROM orders
        WHERE OrderID = :oid
          AND UserID  = :uid
        LIMIT 1
    ");
    $stmt->execute([
      ':oid' => $orderID,
      ':uid' => $userID
    ]);
    $orderRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$orderRow) {
      $errors[] = 'You are not allowed to cancel this order.';
    }
  }

  if (!$errors) {
    $currentStatus = $orderRow['Status'] ?? '';

    // Shared: reason is always required
    $cancelReason = trim($_POST['reason'] ?? '');
    if ($cancelReason === '') {
      $errors[] = 'Cancellation reason is required.';
    }

    // For Packing orders: behave like refund (need admin approval)
    if (!$errors && $currentStatus === 'Packing') {

      // Check existing request (Pending / Approved / Rejected) to avoid duplicates
      $stmt = $_db->prepare("
    SELECT Status
    FROM ordercancellation
    WHERE OrderID = :oid
    ORDER BY RequestedAt DESC
    LIMIT 1
");
      $stmt->execute([':oid' => $orderID]);
      $ocRow = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($ocRow && in_array($ocRow['Status'], ['Pending', 'Approved', 'Rejected'], true)) {
        $errors[] = 'A cancellation or return/refund request for this order already exists.';
      }

      if (!$errors) {
        $stmt = $_db->prepare("
            INSERT INTO ordercancellation (OrderID, Reason, Status, ProcessedBy)
            VALUES (:oid, :reason, 'Pending', 'User')
        ");
        $stmt->execute([
          ':oid'    => $orderID,
          ':reason' => $cancelReason,
        ]);

        // IMPORTANT: For Packing, do NOT touch stock / rewards / promos or order.Status.
        $success = 'Your cancellation request has been submitted and is pending admin approval.';
      }
    }
    // For Pending orders: keep old instant auto-cancel flow
    elseif (!$errors && $currentStatus === 'Pending') {
      try {
        $_db->beginTransaction();

        /* -----------------------------------------
             A) Insert cancellation record (Approved, Auto)
             ----------------------------------------- */
        $insCancel = $_db->prepare("
              INSERT INTO ordercancellation (OrderID, Reason, Status, ProcessedBy)
              VALUES (:oid, :reason, 'Approved', 'Auto')
          ");
        $insCancel->execute([
          ':oid'    => $orderID,
          ':reason' => $cancelReason,
        ]);
        $cancellationID = (int)$_db->lastInsertId();

        /* -----------------------------------------
             B) Return stock for this order
             ----------------------------------------- */
        $itemsStmt = $_db->prepare("
              SELECT 
                  oi.OrderItemID,
                  oi.ProductID,
                  oi.Quantity,
                  oi.ColorName,
                  oi.Size,
                  pcs.ColorSizeID,
                  pcs.Stock AS CurrentStock
              FROM orderitem oi
              LEFT JOIN product_colors pc
                  ON pc.ProductID = oi.ProductID
                 AND pc.ColorName = oi.ColorName
              LEFT JOIN product_color_sizes pcs
                  ON pcs.ProductColorID = pc.ProductColorID
                 AND pcs.Size = oi.Size
              WHERE oi.OrderID = :oid
          ");
        $itemsStmt->execute([':oid' => $orderID]);
        $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($orderItems) {
          $updateStockStmt = $_db->prepare("
                  UPDATE product_color_sizes
                  SET Stock = :newStock
                  WHERE ColorSizeID = :csid
              ");
          $insertMovementStmt = $_db->prepare("
                  INSERT INTO stock_movements
                      (ColorSizeID, MovementType, Reason, QtyChange, OldStock, NewStock,
                       ReferenceType, ReferenceID, Note, PerformedBy)
                  VALUES
                      (:csid, 'IN', :reason, :qtyChange, :oldStock, :newStock,
                       'ORDER_CANCEL', :refId, :note, :performedBy)
              ");

          foreach ($orderItems as $it) {
            if (empty($it['ColorSizeID'])) {
              continue;
            }

            $oldStock = (int)$it['CurrentStock'];
            $qty      = (int)$it['Quantity'];
            $newStock = $oldStock + $qty;

            // update stock
            $updateStockStmt->execute([
              ':newStock' => $newStock,
              ':csid'     => (int)$it['ColorSizeID'],
            ]);

            // log stock movement
            $insertMovementStmt->execute([
              ':csid'        => (int)$it['ColorSizeID'],
              ':reason'      => 'Cancel',
              ':qtyChange'   => $qty,
              ':oldStock'    => $oldStock,
              ':newStock'    => $newStock,
              ':refId'       => $cancellationID,
              ':note'        => $cancelReason,
              ':performedBy' => $userID,
            ]);
          }
        }

        /* -----------------------------------------
             C) Reward points reversal (consistent with ledger)
             ----------------------------------------- */
        $rpStmt = $_db->prepare("
              SELECT Balance, Accumulated
              FROM reward_points
              WHERE UserID = :uid
              LIMIT 1
          ");
        $rpStmt->execute([':uid' => $userID]);
        $rpRow = $rpStmt->fetch(PDO::FETCH_ASSOC);

        if ($rpRow) {
          $currentBalance     = (int)$rpRow['Balance'];
          $currentAccumulated = (int)$rpRow['Accumulated'];

          // points earned for this order
          $earnStmt = $_db->prepare("
                  SELECT COALESCE(SUM(Points), 0) AS EarnPoints
                  FROM reward_ledger
                  WHERE UserID = :uid
                    AND RefOrderID = :oid
                    AND Type = 'EARN'
              ");
          $earnStmt->execute([
            ':uid' => $userID,
            ':oid' => $orderID,
          ]);
          $earnPoints = (int)$earnStmt->fetchColumn();

          // points redeemed on this order
          $redeemStmt = $_db->prepare("
                  SELECT COALESCE(SUM(Points), 0) AS RedeemPoints
                  FROM reward_ledger
                  WHERE UserID = :uid
                    AND RefOrderID = :oid
                    AND Type = 'REDEEM'
              ");
          $redeemStmt->execute([
            ':uid' => $userID,
            ':oid' => $orderID,
          ]);
          $redeemPoints = (int)$redeemStmt->fetchColumn();

          // new balance / accumulated
          $newBalance     = $currentBalance - $earnPoints + $redeemPoints;
          if ($newBalance < 0) $newBalance = 0;
          $newAccumulated = $currentAccumulated - $earnPoints;
          if ($newAccumulated < 0) $newAccumulated = 0;

          // log auto reversal into ledger (for audit)
          if ($earnPoints > 0) {
            $insLedger = $_db->prepare("
                      INSERT INTO reward_ledger (UserID, Type, Points, RefOrderID)
                      VALUES (:uid, 'AUTO_REVERSAL_EARN', :pts, :oid)
                  ");
            $insLedger->execute([
              ':uid' => $userID,
              ':pts' => $earnPoints,
              ':oid' => $orderID,
            ]);
          }
          if ($redeemPoints > 0) {
            $insLedger = $_db->prepare("
                      INSERT INTO reward_ledger (UserID, Type, Points, RefOrderID)
                      VALUES (:uid, 'AUTO_REVERSAL_REDEEM', :pts, :oid)
                  ");
            $insLedger->execute([
              ':uid' => $userID,
              ':pts' => $redeemPoints,
              ':oid' => $orderID,
            ]);
          }

          // update reward_points
          $updRP = $_db->prepare("
                  UPDATE reward_points
                  SET Balance = :bal,
                      Accumulated = :acc,
                      UpdatedAt = CURRENT_TIMESTAMP
                  WHERE UserID = :uid
              ");
          $updRP->execute([
            ':bal' => $newBalance,
            ':acc' => $newAccumulated,
            ':uid' => $userID,
          ]);
        }

        /* -----------------------------------------
             D) Campaign promo RedemptionCount reversal
             ----------------------------------------- */
        $campStmt = $_db->prepare("
              SELECT DISTINCT p.PromotionID
              FROM promotions p
              JOIN promotion_products pp ON pp.PromotionID = p.PromotionID
              JOIN orderitem oi         ON oi.ProductID   = pp.ProductID
              WHERE oi.OrderID = :oid
                AND p.PromotionType = 'Campaign'
          ");
        $campStmt->execute([':oid' => $orderID]);
        $campaignIDs = $campStmt->fetchAll(PDO::FETCH_COLUMN);

        if ($campaignIDs) {
          $updPromo = $_db->prepare("
                  UPDATE promotions
                  SET RedemptionCount = GREATEST(RedemptionCount - 1, 0)
                  WHERE PromotionID = :pid
              ");
          foreach ($campaignIDs as $pid) {
            $updPromo->execute([':pid' => (int)$pid]);
          }
        }

        /* -----------------------------------------
             F) Targeted promo reversal (promotion_users + promotions)
             ----------------------------------------- */
        $targetPromoID = isset($orderRow['TargetPromotionID'])
          ? (int)$orderRow['TargetPromotionID']
          : 0;

        if ($targetPromoID > 0) {
          $resetPU = $_db->prepare("
              UPDATE promotion_users
              SET IsRedeemed = 0,
                  RedeemedAt = NULL
              WHERE PromotionID = :pid
                AND UserID      = :uid
          ");
          $resetPU->execute([
            ':pid' => $targetPromoID,
            ':uid' => $userID,
          ]);

          $updTargetPromo = $_db->prepare("
              UPDATE promotions
              SET RedemptionCount = GREATEST(RedemptionCount - 1, 0)
              WHERE PromotionID = :pid
          ");
          $updTargetPromo->execute([
            ':pid' => $targetPromoID,
          ]);
        }

        /* -----------------------------------------
             E) Mark order as cancelled
             ----------------------------------------- */
        $updOrder = $_db->prepare("
              UPDATE orders
              SET Status = 'Canceled'
              WHERE OrderID = :oid
          ");
        $updOrder->execute([':oid' => $orderID]);

        $_db->commit();
        $success = 'Your order has been cancelled successfully. Stock, rewards, and campaign promo usage have been adjusted.';
      } catch (Throwable $ex) {
        if ($_db->inTransaction()) {
          $_db->rollBack();
        }
        $errors[] = 'Failed to cancel order. Please try again later.';
      }
    }
    // Any other status: cannot cancel this way
    elseif (!$errors) {
      $errors[] = 'This order cannot be cancelled at this stage.';
    }
  }
}

/* ============================================================
    2) Handle Return / Refund request (POST)
    ============================================================ */
if (
  $_SERVER['REQUEST_METHOD'] === 'POST'
  && isset($_POST['action'])
  && $_POST['action'] === 'request_refund'
) {
  $orderID = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
  $reason  = trim($_POST['reason'] ?? '');

  if ($orderID <= 0) {
    $errors[] = 'Invalid order selected.';
  }
  if ($reason === '') {
    $errors[] = 'Please provide a reason for your return/refund request.';
  }

  // Check that order belongs to this user
  if (!$errors) {
    $stmt = $_db->prepare("
              SELECT OrderID, Status
              FROM orders
              WHERE OrderID = :oid AND UserID = :uid
              LIMIT 1
          ");
    $stmt->execute([
      ':oid' => $orderID,
      ':uid' => $userID
    ]);
    $orderRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$orderRow) {
      $errors[] = 'You are not allowed to request return/refund for this order.';
    }
  }

  // Check if there is already a pending/approved cancellation for this order
  if (!$errors) {
    $stmt = $_db->prepare("
              SELECT Status
              FROM ordercancellation
              WHERE OrderID = :oid
              ORDER BY RequestedAt DESC
              LIMIT 1
          ");
    $stmt->execute([':oid' => $orderID]);
    $ocRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($ocRow && in_array($ocRow['Status'], ['Pending', 'Approved'], true)) {
      $errors[] = 'A return/refund request for this order already exists.';
    }
  }

  // Handle proof image upload (REQUIRED)
  $proofFileName = null;
  if (!$errors) {
    if (empty($_FILES['proof']['name']) || $_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
      $errors[] = 'Proof image is required for return / refund.';
    } else {
      $tmpName  = $_FILES['proof']['tmp_name'];
      $origName = $_FILES['proof']['name'];

      $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
      $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

      if (!in_array($ext, $allowed, true)) {
        $errors[] = 'Proof image must be JPG, PNG, GIF, or WEBP.';
      } else {
        $uploadDirFs = dirname(__DIR__) . '/uploads/refund_proof/';
        if (!is_dir($uploadDirFs)) {
          @mkdir($uploadDirFs, 0777, true);
        }

        $proofFileName = bin2hex(random_bytes(8)) . '.' . $ext;
        $targetPath = $uploadDirFs . $proofFileName;

        if (!move_uploaded_file($tmpName, $targetPath)) {
          $errors[] = 'Failed to upload proof image. Please try again.';
          $proofFileName = null;
        }
      }
    }
  }

  // Insert into ordercancellation with Status = 'Pending'
  if (!$errors) {
    $stmt = $_db->prepare("
              INSERT INTO ordercancellation (OrderID, Reason, ProofImage, Status)
              VALUES (:oid, :reason, :proof, 'Pending')
          ");
    $stmt->execute([
      ':oid'    => $orderID,
      ':reason' => $reason,
      ':proof'  => $proofFileName
    ]);

    // IMPORTANT: do NOT change orders.Status here.
    // Admin will approve/reject later in backend.
    $success = 'Your return/refund request has been submitted. It will first be reviewed by admin, then refunded after the returned item is received and checked.';
  }
}

/* ============================================================
    3) Fetch all orders + items for this user
    ============================================================ */
$sql = "
      SELECT
        o.OrderID,
        o.OrderDate,
        o.Status,
        o.PaymentStatus,
        o.TotalAmt AS OrderTotal,
        oi.OrderItemID,
        oi.ProductID,
        oi.Quantity,
        oi.Price,
        p.Name  AS ProductName,
        (
              SELECT ImagePath
              FROM product_images
              WHERE ProductID = p.ProductID
              ORDER BY IsPrimary DESC, SortOrder ASC, ImageID ASC
              LIMIT 1
          ) AS ProductPhoto
      FROM orders o
        JOIN orderitem oi ON oi.OrderID = o.OrderID
        JOIN product p    ON p.ProductID = oi.ProductID
          WHERE o.UserID = :uid
      AND o.PaymentStatus = 'Paid'
      ORDER BY o.OrderDate DESC, o.OrderID DESC
  ";

$stmt = $_db->prepare($sql);
$stmt->execute([':uid' => $userID]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by order
$orders = [];
foreach ($rows as $r) {
  $oid = (int)$r['OrderID'];
  if (!isset($orders[$oid])) {
    $orders[$oid] = [
      'OrderID'       => $oid,
      'OrderDate'     => $r['OrderDate'],
      'Status'        => $r['Status'],
      'PaymentStatus' => $r['PaymentStatus'],
      'OrderTotal'    => $r['OrderTotal'],
      'items'         => [],
    ];
  }
  $orders[$oid]['items'][] = $r;
}


/* ============================================================
    4) Fetch existing ratings & reviews for this user
    ============================================================ */
$ratingsMap = []; // [ProductID] => Rating
$reviewsMap = []; // [ProductID] => Comment

$stmt = $_db->prepare("
      SELECT ProductID, Rating
      FROM product_ratings
      WHERE UserID = :uid
  ");
$stmt->execute([':uid' => $userID]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $ratingsMap[(int)$row['ProductID']] = (float)$row['Rating'];
}

$stmt = $_db->prepare("
      SELECT ProductID, Comment
      FROM product_reviews
      WHERE UserID = :uid
  ");
$stmt->execute([':uid' => $userID]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $reviewsMap[(int)$row['ProductID']] = $row['Comment'];
}

/* ============================================================
    5) Fetch latest cancellation info for these orders
    ============================================================ */
$cancellationMap = []; // [OrderID] => latest ordercancellation row

if ($orders) {
  $orderIDs = array_keys($orders);
  $placeholders = implode(',', array_fill(0, count($orderIDs), '?'));

  $sql = "
          SELECT *
          FROM ordercancellation
          WHERE OrderID IN ($placeholders)
          ORDER BY RequestedAt DESC
      ";
  $stmt = $_db->prepare($sql);
  $stmt->execute($orderIDs);

  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $oid = (int)$row['OrderID'];
    // keep only the latest per order
    if (!isset($cancellationMap[$oid])) {
      $cancellationMap[$oid] = $row;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Order History</title>
  <link rel="stylesheet" href="../assets/style.css">
  <style>
    body {
      color: #000;
    }

    .orders-wrapper {
      padding: 40px 0;
    }

    .orders-header h2 {
      margin: 0 0 10px 0;
      color: #000;
    }

    .alert {
      padding: 10px 14px;
      border-radius: 6px;
      font-size: 0.9rem;
      margin-bottom: 10px;
    }

    .alert-success {
      background: #ecfdf3;
      border: 1px solid #22c55e;
      color: #166534;
    }

    .alert-error {
      background: #fef2f2;
      border: 1px solid #f97373;
      color: #b91c1c;
    }

    .order-card {
      border-radius: 10px;
      border: 1px solid #e5e7eb;
      background: #fff;
      margin-bottom: 20px;
      box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);
      overflow: hidden;
    }

    .order-header {
      padding: 10px 14px;
      background: #f9fafb;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 6px;
    }

    .order-header-left {
      font-size: 0.9rem;
    }

    .order-status {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 999px;
      font-size: 0.8rem;
      border: 1px solid #d1d5db;
    }

    .status-delivered {
      background: #dcfce7;
      border-color: #86efac;
      color: #166534;
    }

    .status-pending {
      background: #fef9c3;
      border-color: #fde68a;
      color: #854d0e;
    }

    .status-other {
      background: #f3f4f6;
      border-color: #d1d5db;
      color: #374151;
    }

    .badge-refund {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 999px;
      font-size: 0.75rem;
      margin-left: 6px;
      border: 1px solid #d4d4d8;
      background: #f5f3ff;
      color: #4c1d95;
    }

    .badge-refund-pending {
      background: #fef3c7;
      border-color: #fbbf24;
      color: #92400e;
    }

    .badge-refund-approved {
      background: #dcfce7;
      border-color: #22c55e;
      color: #166534;
    }

    .badge-refund-rejected {
      background: #fee2e2;
      border-color: #f97373;
      color: #991b1b;
    }

    .order-items-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.9rem;
      color: #000;
    }

    .order-items-table th,
    .order-items-table td {
      border-bottom: 1px solid #e5e7eb;
      padding: 8px 6px;
      vertical-align: top;
      text-align: left;
    }

    .order-items-table th {
      background: #f9fafb;
      font-weight: 600;
    }

    .product-cell {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .product-cell img {
      width: 50px;
      height: 50px;
      object-fit: cover;
      border-radius: 6px;
      border: 1px solid #e5e7eb;
    }

    .rate-form-row {
      margin-bottom: 4px;
    }

    .rate-form-row select,
    .rate-form-row textarea {
      width: 100%;
      padding: 4px 6px;
      border-radius: 6px;
      border: 1px solid #d1d5db;
      font-size: 0.85rem;
    }

    .rate-form-row textarea {
      min-height: 60px;
      resize: vertical;
    }

    .rate-small-text {
      font-size: 0.8rem;
      color: #6b7280;
    }

    .btn-black {
      display: inline-block;
      padding: 6px 12px;
      border-radius: 999px;
      border: none;
      background: #000;
      color: #fff;
      font-size: 0.85rem;
      font-weight: 600;
      cursor: pointer;
      margin-top: 4px;
    }

    .btn-black:hover {
      background: #222;
    }

    .order-refund-section {
      padding: 10px 14px 14px 14px;
      background: #f9fafb;
      border-top: 1px solid #e5e7eb;
      font-size: 0.85rem;
    }

    .order-refund-section h4 {
      margin: 0 0 6px 0;
      font-size: 0.9rem;
    }

    .order-refund-section textarea {
      width: 100%;
      min-height: 60px;
      resize: vertical;
      border-radius: 6px;
      border: 1px solid #d1d5db;
      padding: 4px 6px;
      font-size: 0.85rem;
    }

    .order-refund-row {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: flex-end;
      margin-top: 6px;
    }

    .order-refund-row input[type="file"] {
      font-size: 0.8rem;
    }

    .refund-note {
      font-size: 0.8rem;
      color: #6b7280;
      margin-top: 4px;
    }

    .refund-form-hidden {
      display: none;
      margin-top: 6px;
    }

    /* ===== Order Detail Modal ===== */
    .order-modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.55);
      display: none;
      /* hidden by default */
      align-items: center;
      justify-content: center;
      z-index: 9999;
    }

    .order-modal {
      background: #ffffff;
      width: 96%;
      max-width: 1200px;
      /* wider */
      height: 90vh;
      /* almost full height */
      max-height: 90vh;
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 20px 50px rgba(15, 23, 42, 0.4);
      display: flex;
      flex-direction: column;
    }

    .order-modal-header {
      padding: 10px 18px;
      border-bottom: 1px solid #e5e7eb;
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: #0f172a;
      color: #f9fafb;
    }

    .order-modal-header h3 {
      margin: 0;
      font-size: 1rem;
      font-weight: 600;
    }

    .order-modal-close {
      border: none;
      background: transparent;
      color: #e5e7eb;
      font-size: 1.3rem;
      cursor: pointer;
      padding: 2px 6px;
    }

    .order-modal-body {
      flex: 1;
      overflow: hidden;
    }

    .order-modal-iframe {
      width: 100%;
      height: 100%;
      border: none;
      display: block;
      background: #f3f4f6;
    }
  </style>
</head>

<body>
  <?php include 'header.php'; ?>

  <main class="orders-wrapper">
    <div class="container">
      <div class="orders-header">
        <h2>My Orders &amp; Product Reviews</h2>
        <p style="font-size:0.9rem; margin-top:4px;">
          View your orders below. For products in <strong>Delivered</strong> status, you can rate and review them directly on this page.
        </p>
      </div>

      <?php if ($success): ?>
        <div class="alert alert-success"><?= h($success) ?></div>
      <?php endif; ?>
      <?php if ($errors): ?>
        <div class="alert alert-error">
          <?php foreach ($errors as $e): ?>
            <div><?= h($e) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!$orders): ?>
        <p style="font-size:0.9rem;">You have not placed any orders yet.</p>
      <?php else: ?>
        <?php foreach ($orders as $order): ?>
          <?php
          $status = $order['Status'];
          $statusClass = 'status-other';

          if ($status === 'Delivered') {
            $statusClass = 'status-delivered';
          } elseif (in_array($status, ['Pending', 'Packing', 'Shipped'], true)) {
            $statusClass = 'status-pending';
          } elseif ($status === 'Canceled' || $status === 'Return & Refund') {
            $statusClass = 'status-other';
          }

          $StatusRaw = $order['Status'] ?? 'Unknown';
          if (strcasecmp($StatusRaw, 'Pending') === 0) {
            $Status = 'Paid';
          } else {
            $Status = $StatusRaw;
          }

          $oid   = (int)$order['OrderID'];
          $ocRow = $cancellationMap[$oid] ?? null;
          $hasCancellation = (bool)$ocRow;
          ?>
          <div class="order-card">
            <div class="order-header">
              <div class="order-header-left">
                <strong>
                  <button
                    type="button"
                    onclick="openOrderDetailModal(<?= (int)$order['OrderID'] ?>)"
                    style="background:none;border:none;padding:0;margin:0;color:#000;cursor:pointer;text-decoration:underline;">
                    Order #<?= (int)$order['OrderID'] ?>
                  </button>
                </strong><br>

                <span style="font-size:0.85rem;">Date: <?= h($order['OrderDate']) ?></span>
              </div>
              <div class="order-header-right">
                <?php
                // Default values
                $cStatus         = null;
                $hasProof        = false;
                $isCancel        = false;
                $hideMainStatus  = false;

                if ($hasCancellation) {
                  $cStatus          = $ocRow['Status'] ?? 'Pending';
                  $hasProof         = !empty($ocRow['ProofImage']);   // with proof = Return/Refund
                  $isCancel         = !$hasProof;
                  $refundFinalStatus = $ocRow['RefundFinalStatus'] ?? 'Pending';

                  if (!$isCancel && $cStatus === 'Approved' && $refundFinalStatus === 'Pending') {
                    // Waiting for item received → hide big status
                    $hideMainStatus = true;
                  }
                }
                ?>

                <?php if (!empty($Status) && !$hideMainStatus): ?>
                  <span class="order-status status-delivered">
                    <?= h($Status) ?>
                  </span>
                <?php endif; ?>

                <?php if ($hasCancellation): ?>
                  <?php
                  // Decide whether to show the green/red badge
                  $showBadge = true;

                  // Hide APPROVED cancellation (we rely on main pill "Canceled")
                  if ($isCancel && $cStatus === 'Approved') {
                    $showBadge = false;
                  }

                  // Hide refund badge when final status is Approved → main big pill already shows Return & Refund
                  if (!$isCancel && $refundFinalStatus === 'Approved') {
                    $showBadge = false;
                  }

                  if ($showBadge):
                    $badgeClass = 'badge-refund';
                    if ($cStatus === 'Pending')  $badgeClass .= ' badge-refund-pending';
                    if ($cStatus === 'Approved') $badgeClass .= ' badge-refund-approved';
                    if ($cStatus === 'Rejected') $badgeClass .= ' badge-refund-rejected';

                    // Label text
                    if ($isCancel && $cStatus === 'Rejected') {
                      $label = 'Cancellation: Rejected';
                    } elseif ($isCancel) {
                      $label = 'Cancellation: ' . $cStatus;
                    } else {
                      // Return / Refund labels
                      if ($cStatus === 'Approved' && $refundFinalStatus === 'Pending') {
                        $label = 'Return / Refund: Approved (waiting for item received)';
                      } else {
                        // Return / Refund labels
                        if ($cStatus === 'Approved' && $refundFinalStatus === 'Pending') {
                          // Step 1 approved, waiting for item
                          $label = 'Return / Refund: Approved (waiting for item received)';
                        } elseif ($refundFinalStatus === 'Rejected') {
                          // Final rejection after inspection
                          $label = 'Refund: Rejected';
                        } else {
                          // Covers: Pending, Admin Rejected (before inspection)
                          $label = 'Return / Refund: ' . $cStatus;
                        }
                      }
                    }
                  ?>
                    <span class="<?= $badgeClass ?>">
                      <?= h($label) ?>
                    </span>
                  <?php endif; ?>
                <?php endif; ?>

                <?php if (!empty($order['OrderTotal'])): ?>
                  <span style="font-size:0.85rem; margin-left:10px;">
                    Total: RM <?= number_format((float)$order['OrderTotal'], 2) ?>
                  </span>
                <?php endif; ?>
              </div>

            </div>

            <table class="order-items-table">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Qty</th>
                  <th>Price</th>
                  <th style="width:260px;">Your Rating &amp; Review</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($order['items'] as $item): ?>
                  <?php
                  $pid    = (int)$item['ProductID'];
                  $pname  = $item['ProductName'];
                  $photo  = $item['ProductPhoto'] ?: 'default_product.jpg';
                  $qty    = (int)$item['Quantity'];
                  $price  = (float)$item['Price'];

                  $userRating  = $ratingsMap[$pid]  ?? null;
                  $userComment = $reviewsMap[$pid]  ?? null;
                  $isDelivered = ($status === 'Delivered');
                  ?>
                  <tr>
                    <td>
                      <div class="product-cell">
                        <img src="<?= h('../uploads/' . $photo) ?>" alt="<?= h($pname) ?>">
                        <span><?= h($pname) ?></span>
                      </div>
                    </td>
                    <td><?= $qty ?></td>
                    <td>RM <?= number_format($price, 2) ?></td>
                    <td>
                      <?php if ($isDelivered): ?>
                        <form method="post" style="margin:0;">
                          <input type="hidden" name="action" value="rate_review">
                          <input type="hidden" name="order_id" value="<?= (int)$order['OrderID'] ?>">
                          <input type="hidden" name="product_id" value="<?= $pid ?>">

                          <div class="rate-form-row">
                            <label for="rating_<?= $order['OrderID'] ?>_<?= $pid ?>" style="font-size:0.8rem; font-weight:600;">
                              Rating (1–5)
                            </label>
                            <select name="rating" id="rating_<?= $order['OrderID'] ?>_<?= $pid ?>">
                              <option value="">-- Select --</option>
                              <?php for ($i = 5; $i >= 1; $i--): ?>
                                <option value="<?= $i ?>" <?= ($userRating !== null && (float)$userRating == $i) ? 'selected' : '' ?>>
                                  <?= $i ?> star<?= $i > 1 ? 's' : '' ?>
                                </option>
                              <?php endfor; ?>
                            </select>
                          </div>

                          <div class="rate-form-row">
                            <label for="comment_<?= $order['OrderID'] ?>_<?= $pid ?>" style="font-size:0.8rem; font-weight:600;">
                              Review (optional)
                            </label>
                            <textarea
                              name="comment"
                              id="comment_<?= $order['OrderID'] ?>_<?= $pid ?>"
                              placeholder="Share your experience..."><?= h($userComment ?? '') ?></textarea>
                          </div>

                          <button type="submit" class="btn-black">
                            <?= ($userRating !== null || $userComment !== null) ? 'Update Review' : 'Submit Review' ?>
                          </button>
                          <?php if ($userRating !== null || $userComment !== null): ?>
                            <div class="rate-small-text">
                              You have already reviewed this product. You can update it above.
                            </div>
                          <?php endif; ?>
                        </form>
                      <?php else: ?>
                        <div class="rate-small-text">
                          Rating and review will be available after this order is Delivered.
                        </div>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>

            <!-- Return / Refund section per order -->
            <div class="order-refund-section">
              <h4>Cancellation / Return & Refund</h4>

              <?php if ($status === 'Pending' || $status === 'Packing'): ?>

                <?php if ($hasCancellation && in_array(($ocRow['Status'] ?? 'Pending'), ['Pending', 'Approved', 'Rejected'], true)): ?>
                  <!-- There is already a cancellation request (Pending or Approved) -->
                  <div class="refund-note">
                    Latest request status: <strong><?= h($ocRow['Status']) ?></strong><br>

                    <?php if (!empty($ocRow['Reason'])): ?>
                      <span>Your reason: <?= h($ocRow['Reason']) ?></span><br>
                    <?php endif; ?>

                    <?php if (!empty($ocRow['AdminNote'])): ?>
                      <span><strong>Admin note:</strong> <?= h($ocRow['AdminNote']) ?></span>
                    <?php endif; ?>
                  </div>

                <?php else: ?>
                  <!-- No active cancellation -> show button + form -->
                  <button
                    type="button"
                    class="btn-black cancel-toggle"
                    data-target="cancel_form_<?= (int)$order['OrderID'] ?>">
                    Request cancellation
                  </button>

                  <div id="cancel_form_<?= (int)$order['OrderID'] ?>" class="refund-form-hidden">
                    <form method="post" style="margin-top:8px;">
                      <input type="hidden" name="action" value="cancel_order">
                      <input type="hidden" name="order_id" value="<?= (int)$order['OrderID'] ?>">

                      <label for="cancel_reason_<?= (int)$order['OrderID'] ?>" style="font-size:0.8rem; font-weight:600;">
                        Reason for cancellation
                      </label>
                      <textarea
                        name="reason"
                        id="cancel_reason_<?= (int)$order['OrderID'] ?>"
                        required
                        placeholder="Example: Change of mind, duplicate order, wrong details, etc."></textarea>

                      <button type="submit" class="btn-black" style="margin-top:6px;">
                        Confirm cancellation
                      </button>

                      <?php if ($status === 'Pending'): ?>
                        <div class="refund-note">
                          You can cancel a pending order instantly before it is shipped.
                        </div>
                      <?php elseif ($status === 'Packing'): ?>
                        <div class="refund-note">
                          Your cancellation request will be submitted to admin and requires approval.
                        </div>
                      <?php endif; ?>
                    </form>
                  </div>
                <?php endif; ?>

              <?php elseif ($status === 'Canceled'): ?>

                <div class="refund-note">
                  This order has been cancelled.
                </div>

              <?php elseif ($status === 'Return & Refund'): ?>

                <div class="refund-note">
                  This order has been processed for Return &amp; Refund.
                </div>

              <?php else: ?>
                <!-- Shipped / Delivered (Return & Refund flow remains unchanged) -->

                <?php if ($hasCancellation && in_array(($ocRow['Status'] ?? 'Pending'), ['Pending', 'Approved', 'Rejected'], true)): ?>
                  <?php
                  $cStatus         = $ocRow['Status'] ?? 'Pending';
                  $hasProof        = !empty($ocRow['ProofImage']);
                  $isRefund        = $hasProof;
                  $refundFinalStatus = $ocRow['RefundFinalStatus'] ?? 'Pending';
                  ?>
                  <div class="refund-note">
                    Latest request status:
                    <strong>
                      <?php
                      if ($isRefund && $cStatus === 'Approved' && $refundFinalStatus === 'Pending') {
                        echo 'Approved (waiting for returned items to be received and checked)';
                      } else {
                        echo h($cStatus);
                      }
                      ?>
                    </strong><br>

                    <?php if (!empty($ocRow['Reason'])): ?>
                      <span>Your reason: <?= h($ocRow['Reason']) ?></span><br>
                    <?php endif; ?>

                    <?php if (!empty($ocRow['AdminNote'])): ?>
                      <span><strong>Admin note:</strong> <?= h($ocRow['AdminNote']) ?></span>
                    <?php endif; ?>

                    <?php if ($isRefund && $cStatus === 'Approved' && $refundFinalStatus === 'Pending'): ?>
                      <br>
                      <span><i>
                          Once the returned item is received and checked by our team, your refund will be processed.
                        </i></span>
                    <?php endif; ?>
                  </div>

                <?php else: ?>

                  <button
                    type="button"
                    class="btn-black refund-toggle"
                    data-target="refund_form_<?= (int)$order['OrderID'] ?>">
                    Request Return / Refund
                  </button>

                  <div id="refund_form_<?= (int)$order['OrderID'] ?>" class="refund-form-hidden">
                    <form method="post" enctype="multipart/form-data" style="margin-top:8px;">
                      <input type="hidden" name="action" value="request_refund">
                      <input type="hidden" name="order_id" value="<?= (int)$order['OrderID'] ?>">

                      <label for="reason_<?= $order['OrderID'] ?>" style="font-size:0.8rem; font-weight:600;">
                        Reason for Return / Refund
                      </label>
                      <textarea
                        name="reason"
                        id="reason_<?= $order['OrderID'] ?>"
                        required></textarea>

                      <div class="order-refund-row">
                        <div>
                          <label style="font-size:0.8rem; font-weight:600; display:block;">
                            Proof image (required)
                          </label>
                          <input type="file" name="proof" accept="image/*">
                        </div>
                        <button type="submit" class="btn-black">
                          Submit Request
                        </button>
                      </div>

                    </form>
                  </div>

                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </main>

  <!-- Order Detail Modal -->
  <div id="orderDetailModalBackdrop" class="order-modal-backdrop">
    <div class="order-modal">
      <div class="order-modal-header">
        <h3>Order Details</h3>
        <button class="order-modal-close" onclick="closeOrderDetailModal()">&times;</button>
      </div>
      <div class="order-modal-body">
        <!-- We load the existing order_detail.php into an iframe -->
        <iframe id="orderDetailIframe" class="order-modal-iframe"></iframe>
      </div>
    </div>
  </div>

  <?php include 'footer.php'; ?>

  <script>
    // Simple JS to toggle refund / cancellation form visibility per order
    document.addEventListener('DOMContentLoaded', function() {
      function attachToggle(selector) {
        const buttons = document.querySelectorAll(selector);
        buttons.forEach(function(btn) {
          btn.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const formDiv = document.getElementById(targetId);
            if (!formDiv) return;
            const isHidden = (formDiv.style.display === '' || formDiv.style.display === 'none');
            formDiv.style.display = isHidden ? 'block' : 'none';
          });
        });
      }

      attachToggle('.refund-toggle'); // existing Return / Refund buttons
      attachToggle('.cancel-toggle'); // new Cancelation buttons
    });

    // ===== Order Detail Modal (iframe using order_detail.php) =====
    function openOrderDetailModal(orderID) {
      var backdrop = document.getElementById('orderDetailModalBackdrop');
      var iframe = document.getElementById('orderDetailIframe');
      if (!backdrop || !iframe) return;

      // set iframe src to your existing detailed page
      iframe.src = 'order_detail.php?order_id=' +
        encodeURIComponent(orderID) +
        '&view=modal';

      backdrop.style.display = 'flex';
      document.body.style.overflow = 'hidden'; // prevent background scroll
    }

    function closeOrderDetailModal() {
      var backdrop = document.getElementById('orderDetailModalBackdrop');
      var iframe = document.getElementById('orderDetailIframe');
      if (!backdrop || !iframe) return;

      backdrop.style.display = 'none';
      iframe.src = ''; // optional: clear to stop loading
      document.body.style.overflow = '';
    }


    document.addEventListener('click', function(e) {
      var backdrop = document.getElementById('orderDetailModalBackdrop');
      var modal = backdrop ? backdrop.querySelector('.order-modal') : null;
      if (!backdrop || !modal) return;

      if (backdrop.style.display === 'flex' && e.target === backdrop) {
        closeOrderDetailModal();
      }
    });
  </script>
</body>

</html>