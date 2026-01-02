<?php
session_start();
require __DIR__ . '/../config.php';

// require login (align with rest of site)
if (empty($_SESSION['user'])) {
    header('Location: /login.php');
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['place_order'])) {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $address = $_POST['address'];
    $paymentMethod = $_POST['payment'];

    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // [REWARDS A]
    // 1) Build precise cart total (qty * price)
    $cart = $_SESSION['cart'] ?? [];
    $cartTotal = 0.0;
    foreach ($cart as $pid => $item) {
        $qty   = isset($item['Quantity']) ? (int)$item['Quantity'] : 1;
        $price = (float)$item['Price'];
        $cartTotal += $price * $qty;
    }
    $cartTotal = max(0.0, round($cartTotal, 2));

    // 2) Read user, balance + accumulated (for tier)
    $userId = (int)($_SESSION['user']['UserID'] ?? 0);
    $balance = 0;
    $accumulated = 0;
    if ($userId > 0) {
        $rp = $pdo->prepare("SELECT Balance, Accumulated FROM reward_points WHERE UserID = ?");
        $rp->execute([$userId]);
        $row = $rp->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $balance = (int)$row['Balance'];
            $accumulated = (int)$row['Accumulated'];
        }
    }

    // 3) Conversion rate by ACCUMULATIVE points (tier)
    $convRate = 0.01; // fallback
    if ($userId > 0) {
        $t = $pdo->prepare("SELECT ConversionRate FROM reward_tiers WHERE ? BETWEEN MinPoints AND MaxPoints LIMIT 1");
        $t->execute([$accumulated]);
        $rt = $t->fetch(PDO::FETCH_ASSOC);
        if ($rt && isset($rt['ConversionRate'])) $convRate = (float)$rt['ConversionRate'];
    }

    // 4) Requested points (from session set in cart.php). No minimum rule.
    $requested = 0;
    if (isset($_SESSION['redeem_points'])) {
        $requested = (int)$_SESSION['redeem_points'];
    } elseif (isset($_SESSION['reward']['applied_points'])) {
        $requested = (int)$_SESSION['reward']['applied_points'];
    }
    if ($requested < 0) $requested = 0;
    if ($balance <= 0) $requested = 0;

    // 5) Cap by balance and by cart value (so discount <= cartTotal)
    $maxByCart = ($convRate > 0) ? (int)floor($cartTotal / $convRate) : 0;
    $redeemPts = max(0, min($requested, $balance, $maxByCart));

    // 6) Compute discount
    $discount = round($redeemPts * $convRate, 2);
    $finalTotal = max(0.0, round($cartTotal - $discount, 2));

    // 7) **Key trick**: proportionally reduce each item's *unit price* so that
    //    your existing array_sum(array_column($_SESSION['cart'], 'Price')) equals $finalTotal
    if ($discount > 0 && $cartTotal > 0 && !empty($cart)) {
        $remaining = $finalTotal;
        $i = 0;
        $count = count($cart);
        foreach ($cart as $pid => $item) {
            $i++;
            $qty   = isset($item['Quantity']) ? (int)$item['Quantity'] : 1;
            $qty   = max(1, $qty);
            $unit  = (float)$item['Price'];
            $line  = $unit * $qty;

            // proportional line subtotal after discount
            $newLine = round(($line / $cartTotal) * $finalTotal, 2);

            // last line adjustment to fix rounding pennies
            if ($i === $count) {
                $newLine = $remaining;
            }
            $remaining = round($remaining - $newLine, 2);

            // set reduced **unit** price so sum of 'Price' equals final total when your code sums them
            $newUnit = $qty > 0 ? round($newLine / $qty, 2) : $unit;
            $_SESSION['cart'][$pid]['Price'] = max(0.00, $newUnit);
        }

        // Store chosen points for post-insert ledger
        $_SESSION['__redeem_points__']  = $redeemPts;
        $_SESSION['__redeem_discount__'] = $discount;
    } else {
        $_SESSION['__redeem_points__']  = 0;
        $_SESSION['__redeem_discount__'] = 0.00;
    }

    // Insert order into database
    $stmt = $pdo->prepare("INSERT INTO orders (name, email, phone, address, payment_method, total_price) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$name, $email, $phone, $address, $paymentMethod, array_sum(array_column($_SESSION['cart'], 'Price'))]);

    // Get last inserted order ID
    $orderID = $pdo->lastInsertId();

    // Insert each product into order_details
    $stmt = $pdo->prepare("INSERT INTO order_details (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
    foreach ($_SESSION['cart'] as $id => $item) {
        $stmt->execute([$orderID, $id, $item['Quantity'], $item['Price']]);
    }

    // [REWARDS B]
    // Write ledger + deduct balance AFTER order is created (if any were redeemed)
    if ($userId > 0 && ($_SESSION['__redeem_points__'] ?? 0) > 0) {
        $redeemPts = (int)$_SESSION['__redeem_points__'];
        // Deduct from balance (Accumulated stays as lifetime)
        $upd = $pdo->prepare("UPDATE reward_points SET Balance = Balance - ?, UpdatedAt = NOW() WHERE UserID = ?");
        $upd->execute([$redeemPts, $userId]);

        // Ledger (no Note column in your schema)
        $lg = $pdo->prepare("INSERT INTO reward_ledger (UserID, Type, Points, RefOrderID, CreatedAt) VALUES (?, 'REDEEM', ?, ?, NOW())");
        $lg->execute([$userId, $redeemPts, $orderID]);
    }

    // Clean session flags
    unset($_SESSION['__redeem_points__'], $_SESSION['__redeem_discount__'], $_SESSION['redeem_points'], $_SESSION['redeem_discount']);

    // Clear cart after order placed
    unset($_SESSION['cart']);

    header("Location: order_success.php?order_id=" . $orderID);
    exit;
}
