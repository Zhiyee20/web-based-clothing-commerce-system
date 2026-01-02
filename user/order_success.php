<?php
// user/order_success.php — Receipt layout similar to sample

require __DIR__ . '/../config.php';
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$userID = $_SESSION['user']['UserID'] ?? null;
if (!$userID) {
  header('Location: ../login.php');
  exit;
}

$orderID = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if ($orderID <= 0) {
  die('Invalid order ID.');
}

// Fetch order (must belong to this user)
$stmt = $pdo->prepare("
    SELECT o.*, d.CourierName, d.TrackingNo, d.Status AS DeliveryStatus, d.AddressID
    FROM orders o
    LEFT JOIN delivery d ON d.OrderID = o.OrderID
    WHERE o.OrderID = ? AND o.UserID = ?
    LIMIT 1
");
$stmt->execute([$orderID, $userID]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
  die('Order not found.');
}

// Friendly display status logic
$rawOrderStatus   = $order['Status'] ?? '';
$rawPaymentStatus = $order['PaymentStatus'] ?? '';
$paymentStatusLc  = strtolower((string)$rawPaymentStatus);

$displayStatus = ($paymentStatusLc === 'paid')
  ? 'Paid'
  : ($rawOrderStatus !== '' ? $rawOrderStatus : $rawPaymentStatus);

// Fetch items
$itemStmt = $pdo->prepare("
    SELECT oi.*, p.Name, p.Price AS OrigPrice
    FROM orderitem oi
    JOIN product p ON p.ProductID = oi.ProductID
    WHERE oi.OrderID = ?
");
$itemStmt->execute([$orderID]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch address (from delivery AddressID)

$address = null;
if (!empty($order['AddressID'])) {
  $addrStmt = $pdo->prepare("
        SELECT Label, FullAddress, PhoneNumber, DistanceKm
        FROM user_address
        WHERE AddressID = ? AND UserID = ?
        LIMIT 1
    ");
  $addrStmt->execute([(int)$order['AddressID'], $userID]);
  $address = $addrStmt->fetch(PDO::FETCH_ASSOC);
}

// Rebuild totals to match cart.php logic

// 1) Subtotals and campaign discount
$itemsSubtotal = 0.0;    // discounted subtotal (after campaign)
$campaignDisc  = 0.0;    // total campaign savings

foreach ($items as $it) {
  $qty       = (int)$it['Quantity'];
  $unitPrice = (float)$it['Price']; // discounted unit price stored from cart.php
  $origPrice = isset($it['OrigPrice']) ? (float)$it['OrigPrice'] : $unitPrice;

  // subtotal after campaign
  $itemsSubtotal += $qty * $unitPrice;

  // campaign savings
  if ($origPrice > $unitPrice) {
    $campaignDisc += $qty * ($origPrice - $unitPrice);
  }
}

// 2) Shipping fee (same logic as cart.php)
$shippingRM = 0.0;
if (!empty($address)) {
  $km = isset($address['DistanceKm']) ? (float)$address['DistanceKm'] : 0.0;

  if ($km <= 20) {
    $shippingRM = 5.90;
  } elseif ($km <= 40) {
    $shippingRM = 7.90;
  } elseif ($km <= 60) {
    $shippingRM = 9.90;
  } else {
    $extraBlocks = ceil(($km - 60) / 20);
    $shippingRM  = 9.90 + ($extraBlocks * 2.00);
  }
}

// 3) Grand total = actual charged amount
$grandTotal = (float)$order['TotalAmt'];

// 4) Points + targeted discount:
//    grandTotal = subtotalAfterCampaign - (pt+tgt) + shipping
// => pt+tgt = subtotalAfterCampaign + shipping - grandTotal
$pointsAndTargetedDisc = max(0.0, $itemsSubtotal + $shippingRM - $grandTotal);

// 5) Total discount shown to user = campaign + (points + targeted)
$displayTotalDiscount = $campaignDisc + $pointsAndTargetedDisc;

// Simple customer name (fallbacks)
$customerName = $_SESSION['user']['username']
  ?? $_SESSION['user']['Email']
  ?? 'Customer';

function h($v)
{
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

include __DIR__ . '/header.php';
?>

<style>
  body {
    background: #f3f4f6;
  }

  .receipt-page {
    max-width: 900px;
    margin: 30px auto;
    background: #ffffff;
    padding: 40px 40px 32px;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.12);
    border-radius: 12px;
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  }

  /* TOP HEADER: LEFT (FROM/TO) + RIGHT (LOGO + META) */
  .receipt-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 32px;
    margin-bottom: 30px;
  }

  .receipt-from-to {
    font-size: 0.9rem;
    color: #374151;
  }

  .receipt-from-to h4 {
    font-size: 0.82rem;
    font-weight: 700;
    margin: 0 0 4px;
    letter-spacing: 0.08em;
  }

  .receipt-from-to p {
    margin: 0 0 2px;
  }

  .receipt-from-to .to-block {
    margin-top: 18px;
  }

  .receipt-right {
    text-align: right;
    min-width: 230px;
  }

  .receipt-logo {
    font-size: 1.9rem;
    font-weight: 700;
    letter-spacing: 0.14em;
  }

  .receipt-subtitle {
    margin-top: 2px;
    font-size: 0.9rem;
    color: #6b7280;
  }

  .receipt-meta {
    margin-top: 14px;
    font-size: 0.88rem;
    color: #111827;
  }

  .receipt-meta p {
    margin: 3px 0;
  }

  .receipt-meta strong {
    font-weight: 600;
  }

  /* ITEMS TABLE */
  .receipt-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
    font-size: 0.9rem;
  }

  .receipt-table thead {
    background: #f3f4f6;
  }

  .receipt-table th,
  .receipt-table td {
    padding: 8px 6px;
    border-bottom: 1px solid #e5e7eb;
  }

  .receipt-table th {
    text-align: left;
    font-weight: 600;
    color: #4b5563;
  }

  .receipt-table th.qty-col,
  .receipt-table td.qty-col {
    width: 70px;
    text-align: center;
  }

  .receipt-table th.unit-col,
  .receipt-table td.unit-col,
  .receipt-table th.amount-col,
  .receipt-table td.amount-col {
    width: 120px;
    text-align: right;
  }

  /* TOTALS (RIGHT SIDE BELOW TABLE) */
  .receipt-totals-wrapper {
    margin-top: 18px;
    display: flex;
    justify-content: flex-end;
  }

  .receipt-totals {
    width: 260px;
    font-size: 0.9rem;
  }

  .receipt-totals-row {
    display: flex;
    justify-content: space-between;
    margin: 3px 0;
  }

  .receipt-totals-row span:first-child {
    color: #4b5563;
  }

  .receipt-totals-row.total {
    margin-top: 6px;
    padding-top: 6px;
    border-top: 2px solid #e5e7eb;
    font-weight: 700;
    font-size: 1rem;
  }

  /* FOOTER */
  .receipt-footer {
    margin-top: 40px;
    font-size: 0.82rem;
    color: #6b7280;
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    border-top: 1px solid #e5e7eb;
    padding-top: 10px;
  }

  .receipt-actions {
    margin-top: 20px;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
  }

  .btn-print,
  .btn-history {
    padding: 9px 16px;
    border-radius: 999px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
  }

  .btn-print {
    background: #111827;
    border: none;
    color: #f9fafb;
  }

  .btn-history {
    background: #ffffff;
    border: 1px solid #d1d5db;
    color: #111827;
  }

  .btn-history:hover {
    background: #f3f4f6;
  }

  @media print {

    header,
    footer,
    .receipt-actions,
    .sticky-pay-wrapper,
    body>.breadcrumb {
      display: none !important;
    }

    body {
      background: #ffffff;
    }

    .receipt-page {
      margin: 0;
      box-shadow: none;
      border-radius: 0;
      padding: 20px 30px;
    }
  }
</style>

<main style="padding:20px 16px;">
  <div class="receipt-page" id="receipt">

    <!-- TOP HEADER -->
    <div class="receipt-top">
      <!-- FROM / TO -->
      <div class="receipt-from-to">
        <h4>FROM</h4>
        <p>LUXERA</p>
        <p>123 Luxera Avenue</p>
        <p>Kuala Lumpur, 50000, Malaysia</p>

        <div class="to-block">
          <h4>TO</h4>
          <p><?= h($customerName) ?></p>
          <?php if ($address): ?>
            <p><?= nl2br(h($address['FullAddress'])) ?></p>
            <?php if (!empty($address['PhoneNumber'])): ?>
              <p>📞 <?= h($address['PhoneNumber']) ?></p>
            <?php endif; ?>
          <?php else: ?>
            <p>No shipping address available.</p>
          <?php endif; ?>
        </div>
      </div>

      <!-- LOGO + META -->
      <div class="receipt-right">
        <div class="receipt-logo">LUXERA</div>
        <div class="receipt-subtitle">Order Receipt</div>

        <div class="receipt-meta">
          <br>
          <p><strong>Order ID:</strong> #<?= h($orderID) ?></p>
          <p><strong>Date:</strong> <?= h($order['OrderDate']) ?></p>
          <p><strong>Status:</strong> <?= h($displayStatus) ?></p>
          <p><strong>Payment:</strong> <?= h($order['PaymentStatus']) ?> via <?= h($order['PaymentMethod']) ?></p>
        </div>
      </div>
    </div>

    <!-- ITEMS TABLE -->
    <table class="receipt-table">
      <thead>
        <tr>
          <th class="qty-col">QTY</th>
          <th>Description</th>
          <th class="unit-col">Unit Price</th>
          <th class="amount-col">Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $itemsSubtotal = 0.0;
        foreach ($items as $it):
          $qty       = (int)$it['Quantity'];
          $price     = (float)$it['Price'];
          $lineTotal = $price * $qty;
          $itemsSubtotal += $lineTotal;

          // Safely read color & size (will be empty string if not set)
          $color = isset($it['ColorName']) ? trim((string)$it['ColorName']) : '';
          $size  = isset($it['Size'])      ? trim((string)$it['Size'])      : '';
        ?>
          <tr>
            <td class="qty-col"><?= $qty ?></td>
            <td>
              <?= h($it['Name']) ?>
              <?php if ($color || $size): ?>
                <div style="font-size:0.85rem;color:#6b7280;margin-top:2px;">
                  <?php if ($color): ?>
                    Color: <?= h($color) ?>
                  <?php endif; ?>
                  <?php if ($color && $size): ?>
                    &nbsp;|&nbsp;
                  <?php endif; ?>
                  <?php if ($size): ?>
                    Size: <?= h($size) ?>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </td>
            <td class="unit-col">
              <div class="price-stack">
                <?php if ($origPrice > $price): ?>
                  <div style="color:#9ca3af;text-decoration:line-through;">
                    RM <?= number_format($origPrice, 2) ?>
                  </div>
                  <div style="font-weight:600;">
                    RM <?= number_format($price, 2) ?>
                  </div>
                <?php else: ?>
                  RM <?= number_format($price, 2) ?>
                <?php endif; ?>
              </div>
            </td>
            <td class="amount-col">RM <?= number_format($lineTotal, 2) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- TOTALS -->
    <div style="margin-top:14px;">
      <div class="receipt-section-title">Totals</div>

      <div class="receipt-totals-row">
        <span>Subtotal</span>
        <span>RM <?= number_format($itemsSubtotal, 2) ?></span>
      </div>

      <div class="receipt-totals-row">
        <span>Shipping</span>
        <span>RM <?= number_format($shippingRM, 2) ?></span>
      </div>

      <div class="receipt-totals-row">
        <span>Total Discount</span>
        <span>− RM <?= number_format($displayTotalDiscount, 2) ?></span>
      </div>

      <div class="receipt-totals-row total">
        <span>Total Paid</span>
        <span>RM <?= number_format($grandTotal, 2) ?></span>
      </div>
    </div>



    <!-- FOOTER -->
    <div class="receipt-footer">
      <span>Thank you for shopping with Luxera.</span>
      <span>Tel: +60 12-345 6789 &nbsp; | &nbsp; Email: support@luxera.com &nbsp; | &nbsp; Web: www.luxera.com</span>
    </div>

    <!-- ACTION BUTTONS -->
    <div class="receipt-actions">
      <a href="/user/order_history.php" class="btn-history">Back to Order History</a>
      <button class="btn-print" onclick="window.print()">Download / Print Receipt</button>
    </div>
  </div>
</main>

<?php include __DIR__ . '/footer.php'; ?>