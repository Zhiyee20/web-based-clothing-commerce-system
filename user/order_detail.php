<?php
// user/order_detail.php — Detailed order view similar to order_success.php

require __DIR__ . '/../config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// detect if opened inside modal iframe
$isModalView = isset($_GET['view']) && $_GET['view'] === 'modal';

$userID = $_SESSION['user']['UserID'] ?? null;
if (!$userID) {
    header('Location: ../login.php');
    exit;
}

$orderID = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if ($orderID <= 0) {
    die('Invalid order ID.');
}

// -------------------------------
// 1) Fetch order (must belong to user) + delivery
// -------------------------------
$stmt = $pdo->prepare("
    SELECT 
        o.*,
        d.CourierName,
        d.TrackingNo,
        d.Status        AS DeliveryStatus,
        d.AddressID,
        d.UpdatedAt     AS ShippedAt,
        d.DeliveredAt   AS DeliveredAt
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

// Friendly overall status
$rawOrderStatus   = $order['Status'] ?? '';
$rawPaymentStatus = $order['PaymentStatus'] ?? '';
$paymentStatusLc  = strtolower((string)$rawPaymentStatus);

$displayStatus = ($paymentStatusLc === 'paid')
    ? 'Paid'
    : ($rawOrderStatus !== '' ? $rawOrderStatus : $rawPaymentStatus);

// -------------------------------
// 2) Fetch order items
// -------------------------------
$itemStmt = $pdo->prepare("
    SELECT 
        oi.*,
        p.Name,
        p.Price AS OrigPrice
    FROM orderitem oi
    JOIN product p ON p.ProductID = oi.ProductID
    WHERE oi.OrderID = ?
");
$itemStmt->execute([$orderID]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------
// 3) Fetch shipping address (with DistanceKm for correct shipping fee)
// -------------------------------
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

// -------------------------------
// 4) Totals: subtotal, shipping, discount, grand total
// -------------------------------
$itemsSubtotal           = 0.0; // discounted subtotal (same as cart.php $initialTotal)
$itemsSubtotalOriginal   = 0.0; // before campaign discount

foreach ($items as $it) {
    $qty        = (int)$it['Quantity'];
    $priceDisc  = (float)$it['Price']; // stored discounted unit price
    $priceOrig  = isset($it['OrigPrice'])
        ? (float)$it['OrigPrice']
        : $priceDisc;

    $itemsSubtotal         += $qty * $priceDisc;
    $itemsSubtotalOriginal += $qty * $priceOrig;
}

// ---------------------------------------------
// FIND CAMPAIGN PROMOTIONS APPLIED TO PRODUCTS
// ---------------------------------------------
$campaignPromoTitles = [];

foreach ($items as $it) {
    $pid = (int)$it['ProductID'];

    $cpStmt = $pdo->prepare("
        SELECT p.Title
        FROM promotion_products pp
        JOIN promotions p ON pp.PromotionID = p.PromotionID
        WHERE pp.ProductID = ?
          AND p.PromotionType = 'Campaign'
        LIMIT 1
    ");
    $cpStmt->execute([$pid]);
    $title = $cpStmt->fetchColumn();

    if ($title) {
        $campaignPromoTitles[$title] = true; // ensure unique titles
    }
}

$campaignPromoTitles     = array_keys($campaignPromoTitles);
$campaignPromoTitleText  = $campaignPromoTitles
    ? implode(', ', $campaignPromoTitles)
    : 'Campaign Promotion';

// Shipping (same style as your receipt logic)
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

$grandTotal = (float)$order['TotalAmt'];

// 1) Campaign discount = difference between original and discounted subtotals
$campaignDiscount = max(0.0, $itemsSubtotalOriginal - $itemsSubtotal);

// 2) Points + targeted promo discount:
//    grandTotal = discountedSubtotal - (points+targeted) + shipping
// => points+targeted = discountedSubtotal + shipping - grandTotal
$baseDiscountPhp = max(0.0, $itemsSubtotal + $shippingRM - $grandTotal);

// 3) Total discount shown (campaign + points + targeted)
$totalDiscountPhp = $campaignDiscount + $baseDiscountPhp;

// -------------------------------
// 5) Reward points info for this order
// -------------------------------
// From orders table (points used & RM discount)
$rewardPointsUsed   = (int)($order['RewardPointsUsed']   ?? 0);
$rewardDiscountRM   = (float)($order['RewardDiscount']   ?? 0.0);

// Targeted promotion discount = (points + targeted) - points
$targetPromoDiscountRM = max(0.0, $baseDiscountPhp - $rewardDiscountRM);

// Applied targeted promo title (if any)
$targetPromoTitle = null;
if (!empty($order['AppliedPromoID'])) {
    $promoStmt = $pdo->prepare("
        SELECT Title 
        FROM promotions 
        WHERE PromotionID = ?
        LIMIT 1
    ");
    $promoStmt->execute([(int)$order['AppliedPromoID']]);
    $targetPromoTitle = $promoStmt->fetchColumn();
}

// From reward_ledger (points earned & redeemed)
$pointsEarned   = 0;
$pointsRedeemed = 0;

$ledStmt = $pdo->prepare("
    SELECT Type, SUM(Points) AS TotalPoints
    FROM reward_ledger
    WHERE UserID = ? AND RefOrderID = ?
    GROUP BY Type
");
$ledStmt->execute([$userID, $orderID]);
while ($row = $ledStmt->fetch(PDO::FETCH_ASSOC)) {
    $type = $row['Type'];
    $pts  = (int)$row['TotalPoints'];
    if ($type === 'EARN') {
        $pointsEarned = $pts;
    } elseif ($type === 'REDEEM') {
        $pointsRedeemed = $pts;
    }
}
$pointsNet = $pointsEarned - $pointsRedeemed;

// -------------------------------
// 6) Timeline dates (with logical visibility)
// -------------------------------
$orderDate    = $order['OrderDate']       ?? null;
$paymentTime  = $order['PaymentDateTime'] ?? null;
$shippedAt    = $order['ShippedAt']       ?? null;
$deliveredAt  = $order['DeliveredAt']     ?? null;

$orderStatus = $rawOrderStatus;

// Only show "Shipped" timestamp if order is Shipped or Delivered
$isShippedStatus   = in_array($orderStatus, ['Shipped', 'Delivered'], true);
// Only show "Delivered" timestamp if order is Delivered
$isDeliveredStatus = ($orderStatus === 'Delivered');

// Final display values (what user sees)
$displayOrderDate   = $orderDate ?: '-';
$displayPaymentTime = $paymentTime ?: '-';
$displayShippedAt   = ($isShippedStatus   && !empty($shippedAt))   ? $shippedAt   : '-';
$displayDeliveredAt = ($isDeliveredStatus && !empty($deliveredAt)) ? $deliveredAt : '-';

// Simple customer name
$customerName = $_SESSION['user']['username']
    ?? $_SESSION['user']['email']
    ?? 'Customer';

function h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Only include full site header when NOT in modal
if (!$isModalView) {
    include __DIR__ . '/header.php';
}
?>

<style>
    body {
        background: #f3f4f6;
    }

    .order-detail-page {
        max-width: 900px;
        margin: 30px auto;
        background: #ffffff;
        padding: 32px 32px 28px;
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.12);
        border-radius: 12px;
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }

    .order-detail-top {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 32px;
        margin-bottom: 24px;
    }

    .od-from-to {
        font-size: 0.9rem;
        color: #374151;
    }

    .od-from-to h4 {
        font-size: 0.82rem;
        font-weight: 700;
        margin: 0 0 4px;
        letter-spacing: 0.08em;
    }

    .od-from-to p {
        margin: 0 0 2px;
    }

    .od-from-to .to-block {
        margin-top: 16px;
    }

    .od-right {
        text-align: right;
        min-width: 260px;
    }

    .od-logo {
        font-size: 1.8rem;
        font-weight: 700;
        letter-spacing: 0.14em;
    }

    .od-subtitle {
        margin-top: 2px;
        font-size: 0.9rem;
        color: #6b7280;
    }

    .od-meta {
        margin-top: 12px;
        font-size: 0.88rem;
        color: #111827;
    }

    .od-meta p {
        margin: 2px 0;
    }

    .od-status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 0.82rem;
        padding: 4px 10px;
        border-radius: 999px;
        border: 1px solid #d1d5db;
        background: #f3f4f6;
        margin-top: 6px;
    }

    .od-status-dot {
        width: 8px;
        height: 8px;
        border-radius: 999px;
        background: #22c55e;
    }

    /* TIMELINE */
    .od-timeline {
        margin: 10px 0 18px;
        padding: 10px 12px;
        border-radius: 10px;
        background: #f9fafb;
        border: 1px solid #e5e7eb;
        font-size: 0.86rem;
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        gap: 12px;
    }

    .od-timeline-step {
        min-width: 160px;
    }

    .od-timeline-step-title {
        font-weight: 600;
        margin-bottom: 2px;
    }

    .od-timeline-step-date {
        color: #4b5563;
    }

    /* COURIER BLOCK */
    .od-courier-box {
        margin-top: 4px;
        padding: 10px 12px;
        border-radius: 10px;
        background: #f9fafb;
        border: 1px solid #e5e7eb;
        font-size: 0.86rem;
    }

    .od-courier-row {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        gap: 8px;
    }

    .od-courier-row span {
        display: block;
    }

    /* ITEMS */
    .od-items-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 14px;
        font-size: 0.9rem;
    }

    .od-items-table thead {
        background: #f3f4f6;
    }

    .od-items-table th,
    .od-items-table td {
        padding: 8px 6px;
        border-bottom: 1px solid #e5e7eb;
    }

    .od-items-table th {
        text-align: left;
        font-weight: 600;
        color: #4b5563;
    }

    .od-items-table th.qty-col,
    .od-items-table td.qty-col {
        width: 70px;
        text-align: center;
    }

    .od-items-table th.unit-col,
    .od-items-table td.unit-col,
    .od-items-table th.amount-col,
    .od-items-table td.amount-col {
        width: 120px;
        text-align: right;
    }

    /* TOTALS + REWARDS */
    .od-bottom-row {
        display: flex;
        align-items: flex-start;
        margin-top: 18px;
        width: 100%;
    }

    /* LEFT SIDE — exactly 50% */
    .od-rewards-box {
        width: 50%;
        padding: 10px 12px;
        border-radius: 10px;
        background: #f9fafb;
        border: 1px solid #e5e7eb;
        font-size: 0.86rem;
    }

    .od-rewards-box h4 {
        margin: 0 0 6px;
        font-size: 0.9rem;
    }

    /* RIGHT SIDE — stick to far right */
    .od-totals {
        width: 260px;
        margin-left: auto;
        font-size: 0.9rem;
        text-align: right;
    }

    .od-totals-row {
        display: flex;
        justify-content: space-between;
        margin: 3px 0;
    }

    .od-totals-row span:first-child {
        color: #4b5563;
        text-align: left;
    }

    .od-totals-row span:last-child {
        min-width: 120px;
        text-align: right;
        display: inline-block;
    }

    .od-totals-row.total {
        margin-top: 6px;
        padding-top: 6px;
        border-top: 2px solid #e5e7eb;
        font-weight: 700;
        font-size: 1rem;
    }

    /* FOOTER + ACTIONS */
    .od-footer {
        margin-top: 28px;
        font-size: 0.82rem;
        color: #6b7280;
        display: flex;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px;
        border-top: 1px solid #e5e7eb;
        padding-top: 10px;
    }

    .od-actions {
        margin-top: 18px;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }

    .btn-ghost,
    .btn-black {
        padding: 9px 16px;
        border-radius: 999px;
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
    }

    .btn-black {
        background: #111827;
        border: none;
        color: #f9fafb;
    }

    .btn-ghost {
        background: #ffffff;
        border: 1px solid #d1d5db;
        color: #111827;
    }

    .btn-ghost:hover {
        background: #f3f4f6;
    }

    @media print {

        header,
        footer,
        .od-actions,
        .sticky-pay-wrapper,
        body>.breadcrumb {
            display: none !important;
        }

        body {
            background: #ffffff;
        }

        .order-detail-page {
            margin: 0;
            box-shadow: none;
            border-radius: 0;
            padding: 20px 30px;
        }
    }

    .od-reward-row {
        display: flex;
        justify-content: space-between;
        margin: 2px 0;
    }
</style>

<main style="padding:20px 16px;">
    <div class="order-detail-page">

        <div class="order-detail-top">
            <!-- FROM / TO -->
            <div class="od-from-to">
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
            <div class="od-right">
                <div class="od-logo">LUXERA</div>
                <div class="od-subtitle">Order Details</div>

                <div class="od-meta">
                    <p><strong>Order ID:</strong> #<?= h($orderID) ?></p>
                    <p><strong>Order Date:</strong> <?= h($orderDate ?? '-') ?></p>
                    <p><strong>Payment:</strong> <?= h($order['PaymentStatus']) ?> via <?= h($order['PaymentMethod']) ?></p>
                    <p><strong>Order Status:</strong> <?= h($rawOrderStatus) ?></p>
                </div>

                <div class="od-status-badge">
                    <span class="od-status-dot"></span>
                    <span><?= h($displayStatus) ?></span>
                </div>
            </div>
        </div>

        <!-- TIMELINE -->
        <div class="od-timeline">
            <div class="od-timeline-step">
                <div class="od-timeline-step-title">Order Placed</div>
                <div class="od-timeline-step-date">
                    <?= h($displayOrderDate) ?>
                </div>
            </div>
            <div class="od-timeline-step">
                <div class="od-timeline-step-title">Payment Time</div>
                <div class="od-timeline-step-date">
                    <?= h($displayPaymentTime) ?>
                </div>
            </div>
            <div class="od-timeline-step">
                <div class="od-timeline-step-title">Shipped</div>
                <div class="od-timeline-step-date">
                    <?= h($displayShippedAt) ?>
                </div>
            </div>
            <div class="od-timeline-step">
                <div class="od-timeline-step-title">Delivered</div>
                <div class="od-timeline-step-date">
                    <?= h($displayDeliveredAt) ?>
                </div>
            </div>
        </div>

        <!-- COURIER INFO -->
        <div class="od-courier-box">
            <div class="od-courier-row">
                <span><strong>Courier:</strong> <?= h($order['CourierName'] ?? '—') ?></span>
                <span><strong>Tracking No:</strong> <?= h($order['TrackingNo'] ?? '—') ?></span>
                <span><strong>Delivery Status:</strong> <?= h($order['DeliveryStatus'] ?? '—') ?></span>
            </div>
        </div>

        <!-- ITEMS -->
        <table class="od-items-table">
            <thead>
                <tr>
                    <th class="qty-col">QTY</th>
                    <th>Description</th>
                    <th class="unit-col">Unit Price</th>
                    <th class="amount-col">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                    <?php
                    $qty       = (int)$it['Quantity'];
                    $price     = (float)$it['Price']; // discounted unit price
                    $origPrice = isset($it['OrigPrice']) ? (float)$it['OrigPrice'] : $price;
                    $lineTotal = $qty * $price;

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
                        </td>
                        <td class="amount-col">RM <?= number_format($lineTotal, 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- REWARDS + TOTALS -->
        <div class="od-bottom-row">
            <!-- Rewards Box -->
            <div class="od-rewards-box">
                <h4>Reward Points Summary</h4>
                <div class="od-reward-row">
                    <span>Points Earned from this order</span>
                    <span><?= number_format($pointsEarned) ?> pts</span>
                </div>
                <div class="od-reward-row">
                    <span>Points Redeemed on this order</span>
                    <span><?= number_format($pointsRedeemed) ?> pts</span>
                </div>

                <hr style="border:none;border-top:1px solid #e5e7eb;margin:6px 0;">
                <h4>Discount Summary</h4>
                <div class="od-reward-row">
                    <span>Discount from points</span>
                    <span>RM <?= number_format($rewardDiscountRM, 2) ?></span>
                </div>
                <div class="od-reward-row">
                    <span>Discount from <?= h($campaignPromoTitleText ?: "Campaign Promotion") ?></span>
                    <span>RM <?= number_format($campaignDiscount, 2) ?></span>
                </div>
                <div class="od-reward-row">
                    <span>Discount from <?= h($targetPromoTitle ?: "Targeted Promotion") ?></span>
                    <span>RM <?= number_format($targetPromoDiscountRM, 2) ?></span>
                </div>
            </div>

            <!-- Totals -->
            <div class="od-totals">
                <div class="od-totals-row">
                    <span>Subtotal</span>
                    <span>RM <?= number_format($itemsSubtotal, 2) ?></span>
                </div>
                <div class="od-totals-row">
                    <span>Shipping</span>
                    <span>RM <?= number_format($shippingRM, 2) ?></span>
                </div>
                <div class="od-totals-row">
                    <span>Total Discount</span>
                    <span>− RM <?= number_format($totalDiscountPhp, 2) ?></span>
                </div>
                <div class="od-totals-row total">
                    <span>Total Paid</span>
                    <span>RM <?= number_format($grandTotal, 2) ?></span>
                </div>
            </div>
        </div>

        <!-- FOOTER -->
        <div class="od-footer">
            <span>Thank you for shopping with Luxera.</span>
            <span>Tel: +60 12-345 6789 &nbsp; | &nbsp; Email: support@luxera.com</span>
        </div>

    </div>
</main>

<?php
// only include full site footer (and chatbot) when NOT in modal
if (!$isModalView) {
    include __DIR__ . '/footer.php';
}
?>