<?php
// web/user/paypal_complete.php — verify PayPal order (Sandbox) and update DB
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', '0');

require __DIR__ . '/../config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo json_encode(['ok' => false, 'error' => 'DB not ready']);
    exit;
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/* ---------------------------------------------------
   1) Read JSON from JS (order_id, paypal_order_id, etc.)
   --------------------------------------------------- */
$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);

if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload']);
    exit;
}

if (empty($in['order_id']) || empty($in['paypal_order_id'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing order_id or paypal_order_id']);
    exit;
}

$orderID       = (int)$in['order_id'];
$paypalOrderID = (string)$in['paypal_order_id'];
$payerEmail    = $in['payer_email'] ?? null;

/* ---------------------------------------------------
   2) PayPal credentials (Sandbox)
   --------------------------------------------------- */
$IS_SANDBOX = true;
$PP_BASE    = $IS_SANDBOX ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

$CLIENT = 'AfKh0TVGQg_3kfs_4jda5BdsVQCSGnvGj_izprWX2kb-VpBZgzKCe3L3fgt3axrgApdQ_luga0aH2EqM';
$SECRET = 'EK9sRbNmJSOB1zSpNW_CIrH8XHNVVsSh1JCmKpAzxRY1m1062LKbvmidRpCbpv7k7GGenLaxTIQ1hd1S';

/* ---------------------------------------------------
   3) Get OAuth token from PayPal
   --------------------------------------------------- */
$ch = curl_init("$PP_BASE/v1/oauth2/token");
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_USERPWD        => "$CLIENT:$SECRET",
    CURLOPT_POSTFIELDS     => "grant_type=client_credentials",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 45,
]);
$res  = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($code !== 200 || !$res) {
    http_response_code(502);
    echo json_encode([
        'ok'         => false,
        'error'      => 'Token error',
        'http'       => $code,
        'curl_error' => $err,
        'body'       => $res,
    ]);
    exit;
}

$tok    = json_decode($res, true);
$access = $tok['access_token'] ?? '';
if (!$access) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'No access token from PayPal', 'body' => $res]);
    exit;
}

/* ---------------------------------------------------
   4) Verify the PayPal order (v2 Orders API)
   --------------------------------------------------- */
$ch = curl_init("$PP_BASE/v2/checkout/orders/" . urlencode($paypalOrderID));
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer $access",
        "Content-Type: application/json",
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 45,
]);
$resp = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err2 = curl_error($ch);
curl_close($ch);

if ($code !== 200 || !$resp) {
    http_response_code(502);
    echo json_encode([
        'ok'         => false,
        'error'      => 'Fetch order failed',
        'http'       => $code,
        'curl_error' => $err2,
        'body'       => $resp,
    ]);
    exit;
}

$pp = json_decode($resp, true);
if (!is_array($pp)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid PayPal response']);
    exit;
}

// Must be COMPLETED
if (($pp['status'] ?? '') !== 'COMPLETED') {
    echo json_encode([
        'ok'        => false,
        'error'     => 'Order not completed',
        'pp_status' => $pp['status'] ?? null,
    ]);
    exit;
}

$pu       = $pp['purchase_units'][0] ?? [];
$customId = $pu['custom_id'] ?? null;
$capture  = $pu['payments']['captures'][0] ?? [];
$amtV     = $capture['amount']['value'] ?? '0.00';
$cur      = $capture['amount']['currency_code'] ?? 'MYR';

// Match our internal orderID
if ($customId !== (string)$orderID) {
    echo json_encode([
        'ok'      => false,
        'error'   => 'custom_id mismatch',
        'custom'  => $customId,
        'expected' => $orderID,
    ]);
    exit;
}

/* ---------------------------------------------------
   5) Update DB: check order, validate amount,
      reduce stock, mark Paid, rewards, clear cart, delivery, promo
   --------------------------------------------------- */
try {
    $pdo->beginTransaction();

    // Lock the order row
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE OrderID = ? FOR UPDATE");
    $stmt->execute([$orderID]);
    $ord = $stmt->fetch();

    if (!$ord) {
        throw new RuntimeException('Order not found');
    }

    // If already paid, idempotent OK
    if (strtolower((string)$ord['PaymentStatus']) === 'paid') {
        $pdo->commit();
        echo json_encode(['ok' => true, 'message' => 'Already paid']);
        exit;
    }

    // Compare amount & currency
    $expected = number_format((float)$ord['TotalAmt'], 2, '.', '');
    $received = number_format((float)$amtV,           2, '.', '');
    if ($expected !== $received) {
        throw new RuntimeException("Amount mismatch: expected $expected, got $received");
    }
    if ($cur !== 'MYR') {
        throw new RuntimeException("Unsupported currency: $cur");
    }

    // Reduce stock for each order item (by color + size) + compute SUBTOTAL for rewards
    $it = $pdo->prepare("
        SELECT ProductID, Quantity, Price, ColorName, Size
        FROM orderitem
        WHERE OrderID = ?
    ");
    $it->execute([$orderID]);

    $subtotalRM = 0.0;

    // User who performed this order (for stock_movements.PerformedBy)
    $uid = (int)$ord['UserID'];

    foreach ($it as $r) {
        $qty       = (int)$r['Quantity'];
        $pid       = (int)$r['ProductID'];
        $price     = (float)$r['Price'];
        $colorName = $r['ColorName'] ?? null;
        $size      = $r['Size'] ?? null; // 'XS','S','M','L','XL'

        // subtotal BEFORE shipping / promos / points
        $subtotalRM += $qty * $price;

        // We always expect a size; color might be NULL in orderitem.
        if (!$size) {
            // If somehow size is missing, skip stock deduction for this row.
            continue;
        }

        // ---------- Find the correct ColorSizeID + current stock ----------
        $pcsRow = null;

        if ($colorName) {
            // Case 1: ColorName is set in orderitem → use full match (ProductID + ColorName + Size)
            $pcsSel = $pdo->prepare("
                SELECT pcs.ColorSizeID, pcs.Stock
                FROM product_color_sizes pcs
                JOIN product_colors pc
                  ON pc.ProductColorID = pcs.ProductColorID
                WHERE pc.ProductID = ?
                  AND pc.ColorName = ?
                  AND pcs.Size = ?
                LIMIT 1
                FOR UPDATE
            ");
            $pcsSel->execute([$pid, $colorName, $size]);
            $pcsRow = $pcsSel->fetch();
        } else {
            // Case 2: ColorName is NULL → fall back to the product's default color
            //   (or first color if no IsDefault=1)
            $pcSel = $pdo->prepare("
                SELECT ProductColorID
                FROM product_colors
                WHERE ProductID = ?
                ORDER BY IsDefault DESC, ProductColorID ASC
                LIMIT 1
            ");
            $pcSel->execute([$pid]);
            $pcRow = $pcSel->fetch();

            if ($pcRow) {
                $defaultColorID = (int)$pcRow['ProductColorID'];

                $pcsSel = $pdo->prepare("
                    SELECT ColorSizeID, Stock
                    FROM product_color_sizes
                    WHERE ProductColorID = ?
                      AND Size = ?
                    LIMIT 1
                    FOR UPDATE
                ");
                $pcsSel->execute([$defaultColorID, $size]);
                $pcsRow = $pcsSel->fetch();
            }
        }

        // If we still did not find a matching color+size, skip
        if (!$pcsRow) {
            continue;
        }

        $colorSizeId = (int)$pcsRow['ColorSizeID'];
        $oldStock    = (int)$pcsRow['Stock'];
        $newStock    = max(0, $oldStock - $qty);

        // 2) Update stock in product_color_sizes
        $updPCS = $pdo->prepare("
            UPDATE product_color_sizes
            SET Stock = ?
            WHERE ColorSizeID = ?
        ");
        $updPCS->execute([$newStock, $colorSizeId]);

        // 3) Insert stock movement (OUT, SALES)
        $insMove = $pdo->prepare("
            INSERT INTO stock_movements
                (ColorSizeID, MovementType, Reason, QtyChange, OldStock, NewStock,
                 ReferenceType, ReferenceID, Note, PerformedBy)
            VALUES
                (?, 'OUT', 'SALES', ?, ?, ?, 'ORDER', ?, 'Checkout PayPal', ?)
        ");
        $insMove->execute([
            $colorSizeId,
            $qty,
            $oldStock,
            $newStock,
            (string)$orderID,
            $uid > 0 ? $uid : null
        ]);
    }

    // Read redeemed points info from session (set in cart.php)
    $redeemPts  = isset($_SESSION['redeem_points'])   ? (int)$_SESSION['redeem_points']   : 0;
    $redeemDisc = isset($_SESSION['redeem_discount']) ? (float)$_SESSION['redeem_discount'] : 0.00;

    // Mark order as Paid (store rewards usage too)
    $upd = $pdo->prepare("
      UPDATE orders
      SET PaymentStatus     = 'Paid',
          PaymentMethod     = 'PayPal',
          PayPalOrderID     = ?,
          PaymentDateTime   = NOW(),
          RewardPointsUsed  = ?,
          RewardDiscount    = ?
      WHERE OrderID = ?
    ");
    $upd->execute([$paypalOrderID, $redeemPts, $redeemDisc, $orderID]);

    // Clear user's cart(s)
    $uid = (int)$ord['UserID'];
    if ($uid > 0) {
        $cid = $pdo->prepare("SELECT CartID FROM shoppingcart WHERE UserID = ?");
        $cid->execute([$uid]);
        $cartIds = $cid->fetchAll(PDO::FETCH_COLUMN);

        if ($cartIds) {
            $placeholders = implode(',', array_fill(0, count($cartIds), '?'));
            $intIds = array_map('intval', $cartIds);

            $delItems = $pdo->prepare("DELETE FROM cartitem WHERE CartID IN ($placeholders)");
            $delItems->execute($intIds);

            $updCart = $pdo->prepare("UPDATE shoppingcart SET TotalPrice = 0.00 WHERE CartID IN ($placeholders)");
            $updCart->execute($intIds);
        }
    }

    // ---- Rewards: insert into reward_ledger + update reward_points ----
    // ---- Rewards: reward_ledger + reward_points update ----
    if ($uid > 0) {

        // Earn from subtotal ONLY
        $earnPoints   = (int)floor($subtotalRM);  // subtotal RM = EARN
        $redeemPoints = max(0, (int)$redeemPts);  // from session

        // 1) reward_ledger rows
        if ($earnPoints > 0) {
            $stmtEarn = $pdo->prepare("
            INSERT INTO reward_ledger (UserID, Type, Points, RefOrderID)
            VALUES (?, 'EARN', ?, ?)
        ");
            $stmtEarn->execute([$uid, $earnPoints, $orderID]);
        }

        if ($redeemPoints > 0) {
            $stmtRedeem = $pdo->prepare("
            INSERT INTO reward_ledger (UserID, Type, Points, RefOrderID)
            VALUES (?, 'REDEEM', ?, ?)
        ");
            $stmtRedeem->execute([$uid, $redeemPoints, $orderID]);
        }

        // 2) reward_points aggregates
        $rp = $pdo->prepare("
        SELECT Balance, Accumulated
        FROM reward_points
        WHERE UserID = ?
        FOR UPDATE
    ");
        $rp->execute([$uid]);
        $rowRP = $rp->fetch();

        if ($rowRP) {
            // UPDATE EXISTING ROW
            $newAccum   = (int)$rowRP['Accumulated'] + $earnPoints;
            // Balance = old balance + earned - redeemed
            $newBalance = max(0, (int)$rowRP['Balance'] + $earnPoints - $redeemPoints);

            $upRP = $pdo->prepare("
            UPDATE reward_points
            SET Balance = ?, Accumulated = ?, UpdatedAt = NOW()
            WHERE UserID = ?
        ");
            $upRP->execute([$newBalance, $newAccum, $uid]);
        } else {
            // NO EXISTING RECORD → create new
            $initAccum   = $earnPoints;
            // Starting balance = earned - redeemed (not below 0)
            $initBalance = max(0, $earnPoints - $redeemPoints);

            $insRP = $pdo->prepare("
            INSERT INTO reward_points (UserID, Balance, Accumulated)
            VALUES (?, ?, ?)
        ");
            $insRP->execute([$uid, $initBalance, $initAccum]);
        }
    }

    // Create delivery row once (if not exists)
    // Only store OrderID + AddressID; leave courier/tracking/status for Admin to fill
    $addressID = $_SESSION['checkout_addressID'] ?? null;
    $addressID = $addressID ? (int)$addressID : null;

    $delCheck = $pdo->prepare("SELECT DeliveryID FROM delivery WHERE OrderID = ?");
    $delCheck->execute([$orderID]);
    $existingDelivery = $delCheck->fetchColumn();

    if (!$existingDelivery) {
        $insDel = $pdo->prepare("
          INSERT INTO delivery
            (OrderID, AddressID, CourierName, TrackingNo, Status)
          VALUES (?, ?, '', '', 'Pending')
        ");
        $insDel->execute([$orderID, $addressID]);
    }

    // --- Mark targeted promo as redeemed, if any ---
    if (!empty($_SESSION['applied_promo_id']) && $uid > 0) {
        $promoID = (int) $_SESSION['applied_promo_id'];

        $stmtPromo = $pdo->prepare("
            UPDATE promotion_users
            SET IsRedeemed = 1, RedeemedAt = NOW()
            WHERE PromotionID = ? 
              AND UserID = ? 
              AND (IsRedeemed = 0 OR IsRedeemed IS NULL)
        ");
        $stmtPromo->execute([$promoID, $uid]);

        // If a row was actually updated, bump RedemptionCount
        if ($stmtPromo->rowCount() > 0) {
            $updPromo = $pdo->prepare("
                UPDATE promotions
                SET RedemptionCount = RedemptionCount + 1
                WHERE PromotionID = ?
            ");
            $updPromo->execute([$promoID]);
        }

        // Clear promo from session so it won’t be reused
        unset($_SESSION['applied_promo_id']);
    }

    // --- Campaign promotions: increment RedemptionCount for any campaign used in this order ---
    $campStmt = $pdo->prepare("
        SELECT DISTINCT p.PromotionID
        FROM promotions p
        JOIN promotion_products pp
          ON pp.PromotionID = p.PromotionID
        JOIN orderitem oi
          ON oi.ProductID = pp.ProductID
        WHERE oi.OrderID = ?
          AND p.PromotionType = 'Campaign'
          AND p.PromoStatus   = 'Active'
          AND p.StartDate    <= CURDATE()
          AND (p.EndDate IS NULL OR p.EndDate >= CURDATE())
    ");
    $campStmt->execute([$orderID]);
    $campaignPromoIds = $campStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($campaignPromoIds)) {
        $updCamp = $pdo->prepare("
            UPDATE promotions
            SET RedemptionCount = RedemptionCount + 1
            WHERE PromotionID = ?
        ");
        foreach ($campaignPromoIds as $campPromoID) {
            $updCamp->execute([$campPromoID]);
        }
    }

    // Clear pending order session
    unset($_SESSION['pending_order_id']);

    // Clear cart reward / promo selections after successful payment
    unset($_SESSION['redeem_points'], $_SESSION['redeem_discount']);
    unset($_SESSION['target_choice']); // optional but recommended

    $pdo->commit();

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // error_log('PayPal complete error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
