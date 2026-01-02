<?php
// user/cart.php — Cart + Address + Direct PayPal (sticky bottom)

require __DIR__ . '/../config.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
if (empty($_SESSION['user'])) {
  header('Location: /login.php');
  exit;
}
$userID = (int)$_SESSION['user']['UserID'];

/* -----------------------------
   1) Shipping addresses
   ----------------------------- */
$addrListStmt = $pdo->prepare("
  SELECT AddressID, Label, FullAddress, PhoneNumber, DistanceKm,
         COALESCE(IsDefault, 0) AS IsDefault
  FROM user_address
  WHERE UserID = ?
  ORDER BY IsDefault DESC, AddressID DESC
");
$addrListStmt->execute([$userID]);
$addresses = $addrListStmt->fetchAll(PDO::FETCH_ASSOC);

if (!$addresses) {
  // Must have at least one address
  header('Location: add_address.php');
  exit;
}

$selectedAddressID = null;

// If user changed radio selection (not points form / not promo form)
if (
  $_SERVER['REQUEST_METHOD'] === 'POST'
  && isset($_POST['addressID'])
  && !isset($_POST['apply_points'])
  && !isset($_POST['target_promo_choice'])
) {
  $selectedAddressID = (int)$_POST['addressID'];
} else {
  $selectedAddressID = isset($_SESSION['checkout_addressID'])
    ? (int)$_SESSION['checkout_addressID']
    : null;
}

// Fallback to default / first
if (!$selectedAddressID) {
  foreach ($addresses as $a) {
    if ((int)$a['IsDefault'] === 1) {
      $selectedAddressID = (int)$a['AddressID'];
      break;
    }
  }
}
if (!$selectedAddressID) {
  $selectedAddressID = (int)$addresses[0]['AddressID'];
}

// Persist in session for later pages
$_SESSION['checkout_addressID'] = $selectedAddressID;

// Find selected address row
$address = null;
foreach ($addresses as $a) {
  if ((int)$a['AddressID'] === $selectedAddressID) {
    $address = $a;
    break;
  }
}
if (!$address) {
  $address = $addresses[0];
  $_SESSION['checkout_addressID'] = (int)$address['AddressID'];
}

/* -----------------------------
   2) Fetch cart items – include ColorName + Size + size stock + color-specific image
   ----------------------------- */
$stmt = $pdo->prepare("
    SELECT 
        ci.ProductID,
        p.Name,
        p.Price,
        ci.Quantity,
        ci.ColorName,
        ci.Size,
        pcs.Stock AS SizeStock,
        COALESCE(
            (
                SELECT pi.ImagePath
                FROM product_images pi
                JOIN product_colors pc 
                  ON pc.ProductColorID = pi.ProductColorID
                WHERE pc.ProductID = ci.ProductID
                  AND pc.ColorName = ci.ColorName
                ORDER BY pi.IsPrimary DESC, pi.SortOrder ASC, pi.ImageID ASC
                LIMIT 1
            ),
            (
                SELECT pi.ImagePath
                FROM product_images pi
                WHERE pi.ProductID = ci.ProductID
                ORDER BY pi.IsPrimary DESC, pi.SortOrder ASC, pi.ImageID ASC
                LIMIT 1
            )
        ) AS Photo
    FROM cartitem ci
    JOIN shoppingcart sc ON ci.CartID = sc.CartID
    JOIN product p      ON ci.ProductID = p.ProductID
    LEFT JOIN product_colors pc_main
      ON pc_main.ProductID = ci.ProductID
     AND pc_main.ColorName = ci.ColorName
    LEFT JOIN product_color_sizes pcs
      ON pcs.ProductColorID = pc_main.ProductColorID
     AND pcs.Size = ci.Size
    WHERE sc.UserID = ?
");
$stmt->execute([$userID]);
$initialItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Fetch subtotal from shoppingcart */
$stmt = $pdo->prepare("SELECT TotalPrice FROM shoppingcart WHERE UserID = ?");
$stmt->execute([$userID]);
$row          = $stmt->fetch(PDO::FETCH_ASSOC);
$initialTotal = (float)($row['TotalPrice'] ?? 0.0);

/* -----------------------------
   3) Promotions (for cart page)
   ----------------------------- */
$targetPromos   = [];
$campaignPromos = [];

try {
  // Targeted promos — only unredeemed
  $sqlTarget = "
        SELECT 
            p.PromotionID,
            p.Title,
            p.Description,
            p.DiscountType,
            p.DiscountValue,
            p.MinSpend,
            p.MaxRedemptions,
            p.RedemptionCount
        FROM promotions p
        INNER JOIN promotion_users pu
            ON pu.PromotionID = p.PromotionID
        WHERE pu.UserID = ?
          AND pu.IsRedeemed = 0
          AND p.PromotionType = 'Targeted'
          AND p.PromoStatus   = 'Active'
          AND (p.StartDate IS NULL OR p.StartDate <= CURDATE())
          AND (p.EndDate   IS NULL OR p.EndDate   >= CURDATE())
          AND (p.MaxRedemptions IS NULL OR p.MaxRedemptions = 0 OR p.RedemptionCount < p.MaxRedemptions)
    ";
  $stT = $pdo->prepare($sqlTarget);
  $stT->execute([$userID]);
  $targetPromos = $stT->fetchAll(PDO::FETCH_ASSOC);

  // Campaign promos for items in cart
  $productIDs = array_column($initialItems, 'ProductID');
  if ($productIDs) {
    $placeholders = implode(',', array_fill(0, count($productIDs), '?'));
    $sqlCamp = "
            SELECT 
                p.PromotionID,
                p.Title,
                p.Description,
                p.DiscountType,
                p.DiscountValue,
                p.MinSpend,
                p.MaxRedemptions,
                p.RedemptionCount,
                p.EndDate,
                pp.ProductID
            FROM promotions p
            INNER JOIN promotion_products pp
                ON pp.PromotionID = p.PromotionID
            WHERE p.PromotionType = 'Campaign'
              AND p.PromoStatus   = 'Active'
              AND pp.ProductID IN ($placeholders)
              AND (p.StartDate IS NULL OR p.StartDate <= CURDATE())
              AND (p.EndDate   IS NULL OR p.EndDate   >= CURDATE())
              AND (p.MaxRedemptions IS NULL OR p.MaxRedemptions = 0 OR p.RedemptionCount < p.MaxRedemptions)
        ";

    $stC = $pdo->prepare($sqlCamp);
    $stC->execute($productIDs);
    $campaignPromos = $stC->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (Throwable $e) {
  $targetPromos   = [];
  $campaignPromos = [];
}
/* -----------------------------
   3a) Campaign “ending soon” label (within 24 hours)
   ----------------------------- */
$campaignEndingSoonLabel = '';

if (!empty($campaignPromos)) {
  $nearestDiff = null;
  $nowTs = time();

  foreach ($campaignPromos as $cp) {
    $endStr = $cp['EndDate'] ?? null;
    if (!$endStr) continue;

    // Treat EndDate as end of day 23:59:59
    $endTs = strtotime($endStr . ' 23:59:59');
    if ($endTs === false) continue;

    $diff = $endTs - $nowTs;
    if ($diff <= 0 || $diff > 24 * 3600) {
      // not within next 24 hours
      continue;
    }

    if ($nearestDiff === null || $diff < $nearestDiff) {
      $nearestDiff = $diff;
    }
  }

  if ($nearestDiff !== null) {
    $hoursLeft = (int)ceil($nearestDiff / 3600);
    if ($hoursLeft <= 1) {
      $campaignEndingSoonLabel = 'ENDING SOON';
    } else {
      $campaignEndingSoonLabel = $hoursLeft . ' HRS LEFT';
    }
  }
}

/* -----------------------------
   3b) Apply campaign promos as per-product discounts
   ----------------------------- */
$campaignPriceDiscTotal = 0.0;
$originalSubtotal       = 0.0;
$discountedSubtotal     = 0.0;

if (!empty($initialItems)) {
  foreach ($initialItems as &$item) {
    $orig = (float)$item['Price'];
    $qty  = (int)$item['Quantity'];
    $pid  = (int)$item['ProductID'];

    // Default: no campaign discount
    $bestUnitDisc = 0.0;

    // Find best campaign promo for this product (if any)
    if (!empty($campaignPromos)) {
      foreach ($campaignPromos as $cp) {
        if ((int)$cp['ProductID'] !== $pid) continue;

        $type  = $cp['DiscountType'] ?? null;
        $value = isset($cp['DiscountValue']) ? (float)$cp['DiscountValue'] : 0.0;
        if (!$type || $value <= 0) continue;

        if ($type === 'Percentage') {
          $disc = $orig * ($value / 100.0);
        } else {
          // Treat as RM off per unit
          $disc = $value;
        }

        if ($disc > $bestUnitDisc) {
          $bestUnitDisc = $disc;
        }
      }
    }

    $discounted = max(0.0, $orig - $bestUnitDisc);

    // Attach both prices for JS
    $item['OrigPrice']       = $orig;
    $item['DiscountedPrice'] = $discounted;

    $originalSubtotal       += $orig       * $qty;
    $discountedSubtotal     += $discounted * $qty;
    $campaignPriceDiscTotal += ($orig - $discounted) * $qty;
  }
  unset($item);
} else {
  // No items, just be safe
  $originalSubtotal   = 0.0;
  $discountedSubtotal = 0.0;
}

$initialTotalOriginal = $originalSubtotal;
$initialTotal         = $discountedSubtotal;

/* -----------------------------
   4) Rewards
   ----------------------------- */
require __DIR__ . '/RewardsService.php';
$rewards   = new RewardsService($pdo);
$settings  = $rewards->getSettings($userID);
$convRate  = (float)$settings['ConversionRate']; // RM per point
$balance   = $rewards->getBalance($userID);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_points'])) {
  $req = isset($_POST['redeem_points']) ? (int)$_POST['redeem_points'] : 0;
  if ($req < 0) $req = 0;
  $req = min($req, (int)$balance);
  $maxByCart = $convRate > 0 ? (int)floor($initialTotal / $convRate) : 0;
  $req = min($req, $maxByCart);

  $_SESSION['redeem_points']   = $req;
  $_SESSION['redeem_discount'] = round($req * $convRate, 2);
  $_SESSION['msg'] = $req > 0
    ? "Applied {$req} pts (−RM " . number_format($_SESSION['redeem_discount'], 2) . ")"
    : "Removed redeemed points";
  header("Location: /user/cart.php");
  exit;
}

$appliedPts  = (int)($_SESSION['redeem_points']   ?? 0);
$appliedDisc = (float)($_SESSION['redeem_discount'] ?? 0.00);

/* -----------------------------
   4b) Target promo choice (user can pick / none)
   ----------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['target_promo_choice'])) {
  $choice = $_POST['target_promo_choice'];

  if ($choice === 'none') {
    $_SESSION['target_choice'] = 'none';
  } else {
    // specific promo id
    $_SESSION['target_choice'] = 'id:' . (int)$choice;
  }
  header('Location: /user/cart.php');
  exit;
}

// Default: auto best targeted promo until user changes
$targetChoice = $_SESSION['target_choice'] ?? 'auto';

// If stored choice is specific promo but promo no longer exists, fallback to auto
if (strpos($targetChoice, 'id:') === 0) {
  $id = (int)substr($targetChoice, 3);
  $exists = false;
  foreach ($targetPromos as $p) {
    if ((int)$p['PromotionID'] === $id) {
      $exists = true;
      break;
    }
  }
  if (!$exists) {
    $targetChoice = 'auto';
    $_SESSION['target_choice'] = 'auto';
  }
}

/* -----------------------------
   5) Server-side promotion calculation
   ----------------------------- */
function computeTargetDiscount(float $subtotal, array $targetPromos, string $choice): array
{
  $result = [
    'amount'  => 0.0,
    'message' => 'No targeted promotion applied.',
    'promoID' => null,
  ];

  if ($subtotal <= 0 || !$targetPromos) {
    return $result;
  }

  // Helper: calculate discount RM for one targeted promo
  $calcAmt = function (array $p) use ($subtotal): float {
    // Min spend check
    $minSpend = 0.0;
    if (isset($p['MinSpend']) && $p['MinSpend'] !== null) {
      $minSpend = (float)$p['MinSpend'];
    }
    if ($minSpend > 0 && $subtotal < $minSpend) {
      // Not eligible: subtotal not reaching min spend
      return 0.0;
    }

    $type  = $p['DiscountType'] ?? null;
    $value = isset($p['DiscountValue']) ? (float)$p['DiscountValue'] : 0.0;
    if (!$type || $value <= 0) return 0.0;

    if ($type === 'Percentage') {
      return $subtotal * ($value / 100.0);
    }
    return $value;
  };

  // Helper: label text
  $labelText = function (array $p): string {
    $type  = $p['DiscountType'] ?? null;
    $value = isset($p['DiscountValue']) ? (float)$p['DiscountValue'] : 0.0;
    if ($type === 'Percentage') {
      return rtrim(rtrim((string)$value, '0'), '.') . '% OFF';
    }
    if ($value > 0) {
      return 'RM ' . number_format($value, 2) . ' OFF';
    }
    return '';
  };

  // User chose not to use targeted promotion
  if ($choice === 'none') {
    $result['message'] = 'You chose not to use targeted promotion for this order.';
    return $result;
  }

  // User chose specific promo id
  if (strpos($choice, 'id:') === 0) {
    $id = (int)substr($choice, 3);
    foreach ($targetPromos as $p) {
      if ((int)$p['PromotionID'] === $id) {
        $amt = $calcAmt($p);
        if ($amt > 0) {
          $label = $labelText($p);
          $result['amount']  = $amt;
          $result['promoID'] = $id;
          $result['message'] = 'Targeted promo applied: ' . $label . ' (' . ($p['Title'] ?? 'Exclusive deal') . ').';
        } else {
          $result['message'] = 'Selected targeted promotion is not applicable.';
        }
        return $result;
      }
    }
    // If not found, fall through to auto
  }

  // Auto: pick best targeted promotion
  $bestAmt   = 0.0;
  $bestPromo = null;

  foreach ($targetPromos as $p) {
    $amt = $calcAmt($p);
    if ($amt > $bestAmt) {
      $bestAmt   = $amt;
      $bestPromo = $p;
    }
  }

  if ($bestPromo && $bestAmt > 0) {
    $label = $labelText($bestPromo);
    $result['amount']  = $bestAmt;
    $result['promoID'] = (int)$bestPromo['PromotionID'];
    $result['message'] = 'Best targeted promo applied: ' . $label . ' (' . ($bestPromo['Title'] ?? 'Exclusive deal') . ').';
  } else {
    $result['message'] = 'No targeted promotion available.';
  }

  return $result;
}

function computeCampaignDiscount(array $items, float $promoMinSpend, array $campaignPromos): array
{
  $result = [
    'amount'  => 0.0,
    'message' => 'No campaign promotion applied.'
  ];

  if (!$items || !$campaignPromos) {
    return $result;
  }

  $promoMap = [];
  foreach ($items as $item) {
    $pid     = $item['ProductID'];
    $price   = (float)$item['Price'];
    $qty     = (int)$item['Quantity'];
    $lineSub = $price * $qty;

    foreach ($campaignPromos as $p) {
      if ((string)$p['ProductID'] !== (string)$pid) continue;
      $key = (string)$p['PromotionID'];
      if (!isset($promoMap[$key])) {
        $promoMap[$key] = ['promo' => $p, 'subtotal' => 0.0];
      }
      $promoMap[$key]['subtotal'] += $lineSub;
    }
  }

  if (!$promoMap) {
    return $result;
  }

  $bestAmt = 0.0;
  $bestMsg = $result['message'];

  foreach ($promoMap as $entry) {
    $p   = $entry['promo'];
    $sub = (float)$entry['subtotal'];

    if ($sub <= 0 || $sub < $promoMinSpend) {
      continue;
    }

    $type  = $p['DiscountType'] ?? null;
    $value = isset($p['DiscountValue']) ? (float)$p['DiscountValue'] : 0.0;
    if (!$type || $value <= 0) continue;

    if ($type === 'Percentage') {
      $amt   = $sub * ($value / 100.0);
      $label = rtrim(rtrim((string)$value, '0'), '.') . '% OFF';
    } else {
      $amt   = $value;
      $label = 'RM ' . number_format($value, 2) . ' OFF';
    }

    if ($amt > $bestAmt) {
      $bestAmt = $amt;
      $bestMsg = 'Campaign applied: ' . $label . ' on selected items (' . ($p['Title'] ?? 'Special campaign') . ').';
    }
  }

  if ($bestAmt > 0) {
    $result['amount']  = $bestAmt;
    $result['message'] = $bestMsg;
  }

  return $result;
}

/* -----------------------------
   6) Totals: shipping + rewards + promo
   ----------------------------- */
/* -----------------------------
   SHIPPING FEE BASED ON DISTANCE
   ----------------------------- */
$distance = isset($address['DistanceKm']) ? (float)$address['DistanceKm'] : 0;

function calculateShipping($km)
{
  if ($km <= 20) return 5.90;
  if ($km <= 40) return 7.90;
  if ($km <= 60) return 9.90;

  // For 61km onward (every extra 20km adds RM2)
  $extraBlocks = ceil(($km - 60) / 20);
  return 9.90 + ($extraBlocks * 2.00);
}

$shippingRM = calculateShipping($distance);

// IMPORTANT: $initialTotal is already the *discounted* subtotal
$subtotalRM = (float)$initialTotal;

// Points discount capped to subtotal (after campaign prices)
$pointsDisc = min($appliedDisc, $subtotalRM);

// Targeted promo (one max) based on user choice
$targetResult    = computeTargetDiscount($subtotalRM, $targetPromos, $targetChoice);
$targetDisc      = (float)$targetResult['amount'];
$targetMsg       = $targetResult['message'];
$appliedTargetID = $targetResult['promoID'];

// Campaign promo = already baked into line prices
// -> we only use it for message, NOT to minus again in totals
$campaignDisc = $campaignPriceDiscTotal;
$campaignMsg  = $campaignDisc > 0
  ? 'Campaign promo prices applied on eligible items.'
  : 'No campaign promotion applied.';

// Build combined promo message (targeted + campaign)
if ($targetDisc > 0 && $campaignDisc > 0) {
  $promoMessagePhp = $targetMsg . ' + ' . $campaignMsg;
} elseif ($targetDisc > 0) {
  $promoMessagePhp = $targetMsg;
} elseif ($campaignDisc > 0) {
  $promoMessagePhp = $campaignMsg;
} else {
  $promoMessagePhp = $targetMsg;
}

// Promotional discount (only targeted; campaign already in discounted prices)
$rawPromoDisc = $targetDisc;


// Promo discount cannot exceed subtotal - points
$promoDisc = min($rawPromoDisc, max(0.0, $subtotalRM - $pointsDisc));

// For real calculation (points + targeted)
$totalDiscountPhp = $pointsDisc + $promoDisc;

// For display: include campaign promo savings as well
$displayTotalDiscount = $campaignDisc + $totalDiscountPhp;

$grandTotal = max(0.0, $subtotalRM - $totalDiscountPhp + $shippingRM);

// Save applied targeted promo (for one-time usage) into session
if (!empty($appliedTargetID)) {
  $_SESSION['applied_promo_id'] = (int)$appliedTargetID;
} else {
  $_SESSION['applied_promo_id'] = null;
}

// Save for other pages if needed
$_SESSION['checkout_final_total'] = $grandTotal;

/* -----------------------------
   7) Create / update pending order for PayPal
   ----------------------------- */
$orderID = $_SESSION['pending_order_id'] ?? null;

if (!empty($initialItems)) {
  try {
    $pdo->beginTransaction();

    if ($orderID) {
      $check = $pdo->prepare("
        SELECT OrderID, UserID, PaymentStatus
        FROM orders
        WHERE OrderID = ?
        FOR UPDATE
      ");
      $check->execute([$orderID]);
      $existing = $check->fetch(PDO::FETCH_ASSOC);

      if (
        !$existing ||
        (int)$existing['UserID'] !== $userID ||
        strtolower((string)$existing['PaymentStatus']) === 'paid'
      ) {
        $orderID = null;
        unset($_SESSION['pending_order_id']);
      }
    }

    if (!$orderID) {
      $ins = $pdo->prepare("
        INSERT INTO orders (UserID, TotalAmt, Status, PaymentStatus, PaymentMethod)
        VALUES (?, ?, 'Pending', 'Pending', 'PayPal')
      ");
      $ins->execute([$userID, $grandTotal]);
      $orderID = (int)$pdo->lastInsertId();
      $_SESSION['pending_order_id'] = $orderID;
    } else {
      $upd = $pdo->prepare("UPDATE orders SET TotalAmt = ? WHERE OrderID = ?");
      $upd->execute([$grandTotal, $orderID]);

      $pdo->prepare("DELETE FROM orderitem WHERE OrderID = ?")->execute([$orderID]);
    }

    // Insert order items with color + size + (campaign) discounted price
    $insItem = $pdo->prepare("
      INSERT INTO orderitem (OrderID, ProductID, Quantity, Price, ColorName, Size)
      VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($initialItems as $rowItem) {
      // Use discounted unit price if available (campaign already applied),
      // otherwise fall back to original price
      $unitPrice = isset($rowItem['DiscountedPrice'])
        ? (float)$rowItem['DiscountedPrice']
        : (float)$rowItem['Price'];

      $insItem->execute([
        $orderID,
        (int)$rowItem['ProductID'],
        (int)$rowItem['Quantity'],
        $unitPrice,
        $rowItem['ColorName'] ?? null,
        $rowItem['Size'] ?? null,
      ]);
    }

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['msg'] = 'Failed to create/update order for PayPal: ' . $e->getMessage();
    $orderID = null;
  }
}

include __DIR__ . '/header.php';
?>
<link rel="stylesheet" href="/assets/cart.css">

<!-- PayPal JS SDK – only PayPal (no card/credit) -->
<script src="https://www.sandbox.paypal.com/sdk/js?client-id=AfKh0TVGQg_3kfs_4jda5BdsVQCSGnvGj_izprWX2kb-VpBZgzKCe3L3fgt3axrgApdQ_luga0aH2EqM&currency=MYR&disable-funding=card,credit"></script>

<main class="cart-fullbleed">
  <div class="cart-layout">
    <!-- LEFT: Items -->
    <div class="cart-left-wrapper">
      <section class="cart-left">
        <div class="cart-left-header">
          <h2>My Shopping Cart (<?= count($initialItems) ?>)</h2>
          <a class="continue-link" href="product.php" style="text-decoration: underline;">Continue Shopping</a>
        </div>

        <?php if (!empty($_SESSION['msg'])): ?>
          <div class="flash-info"><?= htmlspecialchars($_SESSION['msg']) ?></div>
          <?php unset($_SESSION['msg']); ?>
        <?php endif; ?>

        <div class="cart-search">
          <input type="text" id="cart-search" placeholder="🔍 Search in your cart…">
        </div>

        <div id="cart-items-root"></div>

        <div class="cart-bottom-actions">
          <button id="clear-cart" class="btn">Clear Cart</button>
        </div>
      </section>
    </div>

    <!-- RIGHT: Summary + rewards + promos + address -->
    <aside class="cart-right naked-summary">
      <!-- Totals -->
      <div class="sum-row">
        <span>Subtotal</span>
        <span id="sum-subtotal">RM <?= number_format($subtotalRM, 2) ?></span>
      </div>
      <div class="sum-row">
        <span>Shipping</span>
        <span id="sum-shipping">RM <?= number_format($shippingRM, 2) ?></span>
      </div>
      <hr class="sum-div">
      <div class="sum-row">
        <span>Total Discount</span>
        <span id="sum-discount">− RM <?= number_format($displayTotalDiscount, 2) ?></span>
      </div>
      <div class="sum-total">
        <span>Total</span>
        <span id="sum-total">RM <?= number_format($grandTotal, 2) ?></span>
      </div>

      <!-- Rewards -->
      <div class="reward-inline">
        <h3>Points Redemption</h3>
        <p>Balance: <strong><?= number_format($balance) ?></strong> pts</p>

        <form method="post" class="reward-form">
          <input type="hidden" name="apply_points" value="1">
          <input type="number" name="redeem_points" id="redeemPoints"
            min="0" max="<?= (int)$balance ?>" step="1"
            value="<?= (int)$appliedPts ?>">
          <button type="submit" id="btn-primary" class="btn">Apply</button>
        </form>

        <div class="reward-preview">
          <span class="discount-label">Points Discount =</span>
          <span class="discount-amount">RM <span id="discountRm"><?= number_format($pointsDisc, 2) ?></span></span>
        </div>
      </div>

      <!-- Promotions -->
      <div class="promo-wrapper">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:4px;">
          <h3 style="margin:0;">Promotions</h3>

          <?php if (!empty($campaignEndingSoonLabel)): ?>
            <span class="campaign-time-left">
              <?= htmlspecialchars($campaignEndingSoonLabel, ENT_QUOTES, 'UTF-8') ?>
            </span>
          <?php endif; ?>
        </div>

        <?php
        // Already computed earlier:
        // $targetChoice ( 'auto' | 'none' | 'id:X' )
        $appliedTargetID = $_SESSION['applied_promo_id'] ?? null;
        $hasTarget       = !empty($targetPromos);

        // Decide what initial hidden value should be
        $hiddenChoice = 'auto';
        if ($targetChoice === 'none') {
          $hiddenChoice = 'none';
        } elseif (strpos($targetChoice, 'id:') === 0) {
          $hiddenChoice = (int)substr($targetChoice, 3);
        } elseif (!empty($appliedTargetID)) {
          $hiddenChoice = (int)$appliedTargetID;
        }
        ?>

        <?php if ($hasTarget): ?>
          <form method="post" id="promoForm" style="margin-bottom:8px;max-height:260px;overflow-y:auto;">

            <!-- hidden field carries the actual choice sent to PHP -->
            <input type="hidden"
              name="target_promo_choice"
              id="promoChoiceHidden"
              value="<?= htmlspecialchars((string)$hiddenChoice, ENT_QUOTES, 'UTF-8') ?>">

            <?php foreach ($targetPromos as $p):
              $pid   = (int)$p['PromotionID'];
              $type  = $p['DiscountType'] ?? null;
              $value = isset($p['DiscountValue']) ? (float)$p['DiscountValue'] : 0.0;

              if ($type === 'Percentage') {
                $label = rtrim(rtrim((string)$value, '0'), '.') . '% OFF';
              } elseif ($value > 0) {
                $label = 'RM ' . number_format($value, 2) . ' OFF';
              } else {
                $label = '';
              }

              // --- Min spend + eligibility ---
              $minSpendValue = 0.0;
              if (isset($p['MinSpend']) && $p['MinSpend'] !== null) {
                $minSpendValue = (float)$p['MinSpend'];
              }
              // Promo is eligible only if no min spend OR subtotal reaches min spend
              $isEligible = ($minSpendValue <= 0) || ($subtotalRM >= $minSpendValue);

              // Selected only if eligible
              $isSelected = $isEligible && (
                (!empty($appliedTargetID) && (int)$appliedTargetID === $pid)
                || ($targetChoice === 'id:' . $pid)
              );
            ?>
              <label style="
    display:block;
    margin-bottom:8px;
    padding:8px 12px;
    border-radius:6px;
    border:1px solid <?= $isSelected ? '#0f766e' : '#e5e7eb' ?>;
    cursor:<?= $isEligible ? 'pointer' : 'not-allowed' ?>;
    background:<?= $isSelected ? '#ecfdf3' : '#ffffff' ?>;
    opacity:<?= $isEligible ? '1' : '0.6' ?>;
  ">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                  <div>
                    <div style="font-weight:bold;font-size:0.95rem;">
                      <?= htmlspecialchars($p['Title']) ?>
                    </div>
                    <?php if ($label): ?>
                      <div style="font-size:0.85rem;color:#0f766e;font-weight:600;">
                        <?= htmlspecialchars($label) ?>
                      </div>
                    <?php endif; ?>

                    <?php
                    // Min Spend display
                    $minSpendText = $minSpendValue > 0
                      ? 'RM ' . number_format($minSpendValue, 2) . ' Min spend'
                      : 'No min spend';

                    // Usage rate (only if limited)
                    $usageRate = '';
                    if (!empty($p['MaxRedemptions']) && (int)$p['MaxRedemptions'] > 0) {
                      $maxR  = (int)$p['MaxRedemptions'];
                      $usedR = (int)$p['RedemptionCount'];
                      $rate  = $maxR > 0 ? min(100, round(($usedR / $maxR) * 100)) : 0;
                      $usageRate = $rate . '% used';
                    }
                    ?>
                    <div style="font-size:0.82rem;color:#6b7280;margin-top:2px;">
                      <?= htmlspecialchars($minSpendText) ?>
                      <?php if ($usageRate): ?>
                        &nbsp; | &nbsp; <?= htmlspecialchars($usageRate) ?>
                      <?php endif; ?>

                      <?php if (!$isEligible && $minSpendValue > 0): ?>
                        <br><span style="color:#b91c1c;font-weight:500;">
                          &nbsp;• Min spend not reached •
                        </span>
                      <?php endif; ?>
                    </div>
                  </div>

                  <!-- Real radio: clicking it will submit form via JS -->
                  <input type="radio"
                    name="target_promo_choice"
                    value="<?= $pid ?>"
                    <?= $isSelected ? 'checked' : '' ?>
                    <?= $isEligible ? '' : 'disabled' ?>
                    style="margin-left:8px;transform:scale(1.1);">
                </div>
              </label>
            <?php endforeach; ?>

          </form>
        <?php else: ?>
          <p style="font-size:0.9rem;margin-bottom:8px;color:#6b7280;text-align:center">
            No targeted promotions available at the moment.
          </p>
        <?php endif; ?>

        <div class="promo-best">
          <span id="promoBestLine"><?= htmlspecialchars($promoMessagePhp) ?></span>
        </div>
      </div>

      <!-- Shipping Address -->
      <div class="checkout-box" style="margin-top:16px;background:#ffffff;border-radius:8px;border:1px solid #e5e7eb;padding:12px;overflow:visible;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
          <h3 style="margin:0;font-size:1.05rem;">Shipping Address</h3>

          <!-- Expand / collapse button -->
          <button type="button"
            id="addrToggleBtn"
            style="border:none;background:transparent;display:flex;align-items:center;gap:4px;font-size:0.85rem;color:#374151;cursor:pointer;padding:4px 6px;border-radius:999px;">
            <span>Change</span>
            <span id="addrToggleIcon" style="font-size:0.8rem;">▼</span>
          </button>
        </div>

        <!-- Always visible: selected address summary -->
        <div style="
            border-radius:8px;
            border:1px solid #0f766e;
            background:#ecfdf3;
            padding:8px 10px;
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:8px;
            margin-bottom:6px;
        ">
          <div>
            <span style="font-weight:bold;display:block;margin-bottom:2px;">
              <?= htmlspecialchars($address['Label']) ?>
            </span>
            <span style="font-size:0.85rem;white-space:pre-line;display:block;">
              <?= nl2br(htmlspecialchars($address['FullAddress'])) ?>
            </span>
            <span style="font-size:0.85rem;display:block;margin-top:2px;">
              📞 <?= htmlspecialchars($address['PhoneNumber']) ?>
            </span>
          </div>
          <span style="
              align-self:flex-start;
              font-size:0.75rem;
              font-weight:600;
              color:#047857;
              padding:2px 8px;
              border-radius:999px;
              background:#d1fae5;
          ">
            Selected
          </span>
        </div>

        <!-- Collapsible list of all addresses -->
        <div id="addrListWrapper" style="display:none;margin-top:6px;max-height:180px;overflow-y:auto;">
          <form id="addrForm" method="post" action="cart.php">
            <?php foreach ($addresses as $a):
              $id = (int)$a['AddressID'];
              $isSelected = ($id === $selectedAddressID);
            ?>
              <label style="
                display:block;
                margin-bottom:8px;
                padding:8px;
                border-radius:6px;
                border:1px solid <?= $isSelected ? '#0f766e' : '#e5e7eb' ?>;
                cursor:pointer;
                background:<?= $isSelected ? '#ecfdf3' : '#ffffff' ?>;
              ">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;">
                  <div>
                    <span style="font-weight:bold;"><?= htmlspecialchars($a['Label']) ?></span><br>
                    <span style="font-size:0.85rem;white-space:pre-line;"><?= nl2br(htmlspecialchars($a['FullAddress'])) ?></span><br>
                    <span style="font-size:0.85rem;">📞 <?= htmlspecialchars($a['PhoneNumber']) ?></span>
                  </div>
                  <input type="radio"
                    name="addressID"
                    value="<?= $id ?>"
                    <?= $isSelected ? 'checked' : '' ?>
                    onchange="document.getElementById('addrForm').submit();"
                    style="margin-left:8px;transform:scale(1.1);">
                </div>
              </label>
            <?php endforeach; ?>
          </form>
          <!-- Add new address box -->
          <a href="add_address.php" style="
              display:block;
              margin-top:4px;
              padding:10px;
              border-radius:8px;
              border:1px dashed #9ca3af;
              background:#f9fafb;
              text-decoration:none;
              color:#374151;
              font-size:0.9rem;
          ">
            <div style="display:flex;align-items:center;gap:8px;justify-content:center;">
              <span style="
                  width:22px;height:22px;
                  border-radius:999px;
                  border:1px solid #9ca3af;
                  display:flex;align-items:center;justify-content:center;
                  font-size:16px;line-height:1;
              ">+</span>
              <span>Add new address</span>
            </div>
          </a>
        </div>
      </div>
    </aside>
  </div>

  <!-- STICKY PayPal BAR – center bottom -->
  <div class="sticky-pay-wrapper" style="
      position:fixed;
      left:50%;
      transform:translateX(-50%);
      bottom:18px;
      z-index:999;
      max-width:440px;
      width:calc(100% - 32px);
      background:#0b1120;
      color:#f9fafb;
      border-radius:999px;
      box-shadow:0 10px 25px rgba(15,23,42,0.35);
      padding:10px 16px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
  ">
    <div style="display:flex;flex-direction:column;line-height:1.2;">
      <span style="font-size:0.78rem;opacity:0.9;">Total to Pay</span>
      <span id="stickyTotal" style="font-size:1.1rem;font-weight:600;">
        RM <?= number_format($grandTotal, 2) ?>
      </span>
    </div>
    <div id="paypal-button-container" style="min-width:170px;"></div>
  </div>
</main>

<script>
  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function showMaxStockMessage(row, stock) {
    const container = row.querySelector('.stock-msg-container');
    if (!container) return;

    container.innerHTML = `
      <div class="stock-msg stock-max" style="
        margin-top:4px;
        display:inline-flex;
        align-items:center;
        gap:6px;
        padding:3px 10px;
        border-radius:999px;
        font-size:0.78rem;
        background:#ecfdf3;
        color:#047857;
        border:1px solid #6ee7b7;
        font-weight:500;
      ">
        <span style="font-size:0.9rem;">✔</span>
        <span>Maximum available quantity reached.</span>
      </div>
    `;

    // Auto-hide after 2 seconds
    setTimeout(() => {
      if (container) {
        container.innerHTML = '';
      }
    }, 2000);
  }

  // Bootstrap data
  let cartItems = <?= json_encode($initialItems, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  let cartTotalPrice = <?= json_encode($initialTotal) ?>;

  const rewardBalance = <?= json_encode($balance) ?>;
  const rewardRate = <?= json_encode($convRate) ?>;
  let rewardAppliedPts = <?= json_encode($appliedPts) ?>;
  let rewardAppliedDisc = <?= json_encode($appliedDisc) ?>;
  const shippingRM = <?= json_encode($shippingRM) ?>;
  const fixedPromoDiscount = <?= json_encode($promoDisc) ?>;
  const campaignDisc = <?= json_encode($campaignDisc) ?>;
  const promoMessage = <?= json_encode($promoMessagePhp) ?>;

  function renderItemsTable(data) {
    const root = document.getElementById('cart-items-root');

    if (!data.cartItems.length) {
      root.innerHTML = `
        <div class="empty-box">
          <p>Your cart is empty.</p>
          <a href="product.php" 
   class="btn"
   style="border: 2px solid transparent; transition: .2s;"
   onmouseover="this.style.border='2px solid #000';"
   onmouseout="this.style.border='2px solid transparent';">
   Continue Shopping
</a>
        </div>`;
      syncRightSummary(data.totalPrice);
      return;
    }

    let html = `
      <table id="cart-table" class="cart-table">
        <thead>
          <tr>
            <th>Image</th><th>Product</th><th>Price</th>
            <th>Quantity</th><th>Subtotal</th><th>Action</th>
          </tr>
        </thead>
        <tbody>`;

    data.cartItems.forEach(item => {
      const pid = item.ProductID;
      const name = item.Name;

      const priceOrig = item.OrigPrice !== undefined ?
        parseFloat(item.OrigPrice) :
        parseFloat(item.Price);

      const priceDisc = item.DiscountedPrice !== undefined ?
        parseFloat(item.DiscountedPrice) :
        priceOrig;

      const qty = parseInt(item.Quantity, 10);

      const colorName = item.ColorName || '';
      const size = item.Size || '';

      const safeColorAttr = colorName.replace(/"/g, '&quot;');
      const safeSizeAttr = size.replace(/"/g, '&quot;');

      const photo = item.Photo ? '/uploads/' + item.Photo : '/uploads/default.jpg';

      // ---- NEW: stock info per size ----
      let stock = null;
      if (item.SizeStock !== undefined && item.SizeStock !== null) {
        const parsed = parseInt(item.SizeStock, 10);
        if (!isNaN(parsed) && parsed >= 0) {
          stock = parsed;
        }
      }
      const stockAttr = Number.isFinite(stock) ? stock : '';

      // Is this item already at max stock?
      const isMaxed = Number.isFinite(stock) && qty >= stock;

      // We DO NOT use real "disabled" so clicks still work.
      // Just add a data flag for styling / logic.
      const disableInc = isMaxed ? 'data-maxed="1"' : '';

      // ---- NEW: message pill under quantity ----
      let stockHint = '';
      if (Number.isFinite(stock) && stock > 0) {
        if (qty < stock && stock < 5) {
          // Low-stock warning
          stockHint = `
            <div class="stock-msg stock-low" style="
              margin-top:4px;
              display:inline-flex;
              align-items:center;
              gap:6px;
              padding:3px 10px;
              border-radius:999px;
              font-size:0.78rem;
              background:#fffbeb;
              color:#92400e;
              border:1px solid #fbbf24;
              font-weight:500;
            ">
              <span style="font-size:0.9rem;">⚠️</span>
              <span>Hurry, only ${stock} pcs left.</span>
            </div>
          `;
        }
        // No more "All stock selected" static pill here
      }

      const priceOrigStr = priceOrig.toFixed(2);
      const priceDiscStr = priceDisc.toFixed(2);
      const lineSubtotal = (priceDisc * qty).toFixed(2);

      let priceHtml;
      if (priceDisc < priceOrig) {
        priceHtml = `
          <div class="price-stack">
            <div class="price-original">RM ${priceOrigStr}</div>
            <div class="price-discounted">RM ${priceDiscStr}</div>
          </div>`;
      } else {
        priceHtml = `RM ${priceDiscStr}`;
      }

      html += `
        <tr data-pid="${pid}"
            data-color="${safeColorAttr}"
            data-size="${safeSizeAttr}"
            data-stock="${stockAttr}">
          <td>
            <img src="${photo}" width="60" height="60"
                 style="object-fit:cover;border-radius:8px;"
                 alt="${escHtml(name)}">
          </td>
          <td>
            ${escHtml(name)}
            ${(colorName || size) ? `
              <div class="cart-color" style="font-size:0.85rem;color:#555;margin-top:4px;">
                ${colorName ? `Color: ${escHtml(colorName)}` : ''}
                ${colorName && size ? ' | ' : ''}
                ${size ? `Size: ${escHtml(size)}` : ''}
              </div>` : ''}
          </td>
          <td>${priceHtml}</td>
            <td class="qty-cell" style="
            padding-top:4px;
            padding-bottom:4px;
          ">
            <div class="qty-wrap" style="
              display:inline-flex;
              align-items:center;
              gap:8px;
              padding:4px 10px;
              border-radius:999px;
              border:1px solid #e5e7eb;
              background:#f9fafb;
            ">
              <button class="qty-btn" data-act="decrease" style="
                border:none;
                background:transparent;
                font-size:1rem;
                padding:0 4px;
                cursor:pointer;
              ">−</button>
              <span style="min-width:24px;text-align:center;font-weight:500;">${qty}</span>
              <button class="qty-btn" data-act="increase" ${disableInc} style="
                border:none;
                background:transparent;
                font-size:1rem;
                padding:0 4px;
                cursor:${isMaxed ? 'not-allowed' : 'pointer'};
                opacity:${isMaxed ? '0.4' : '1'};
              ">+</button>
            </div>
            <div class="stock-msg-container" style="
              margin-top:6px;
              display:block;
            ">
              ${stockHint}
            </div>
          </td>
          <td>RM ${lineSubtotal}</td>
          <td><button class="btn remove-item">Remove</button></td>
        </tr>`;
    });

    html += `</tbody></table>`;
    root.innerHTML = html;
    attachListeners();
    syncRightSummary(data.totalPrice);
  }

  function syncRightSummary(baseTotal) {
    const subtotal = parseFloat(baseTotal) || 0;

    // Points discount (calculation)
    const pointsDisc = (rewardAppliedPts > 0) ?
      (rewardAppliedPts * rewardRate) :
      rewardAppliedDisc;

    const cappedPoints = Math.min(pointsDisc, subtotal);

    // Targeted promo discount (calculation)
    const promoDisc = Math.min(fixedPromoDiscount, Math.max(0, subtotal - cappedPoints));

    // THIS is the real discount used for total payable
    const totalForCalc = cappedPoints + promoDisc;

    // Final total (campaign discount is NOT included because campaign already in subtotal)
    const newTot = Math.max(0, subtotal - totalForCalc + shippingRM);

    // DISPLAY discount = campaign + (points + targeted)
    const displayTotalDiscount = campaignDisc + totalForCalc;

    // Update subtotal
    const subEl = document.getElementById('sum-subtotal');
    if (subEl) {
      subEl.textContent = 'RM ' + subtotal.toFixed(2);
    }

    // Update total discount (DISPLAY ONLY)
    const discEl = document.getElementById('sum-discount');
    if (discEl) {
      discEl.textContent = '− RM ' + displayTotalDiscount.toFixed(2);
    }

    // Update total payable
    const totalEl = document.getElementById('sum-total');
    if (totalEl) {
      totalEl.textContent = 'RM ' + newTot.toFixed(2);
    }

    // Update shipping
    const shipEl = document.getElementById('sum-shipping');
    if (shipEl) {
      shipEl.textContent = 'RM ' + shippingRM.toFixed(2);
    }

    // Update points discount preview
    const pointsEl = document.getElementById('discountRm');
    if (pointsEl) {
      pointsEl.textContent = 'RM ' + cappedPoints.toFixed(2);
    }

    // Update promo message
    const promoLine = document.getElementById('promoBestLine');
    if (promoLine) {
      promoLine.textContent = promoMessage || 'No available promotion';
    }

    // Update sticky footer
    const sticky = document.getElementById('stickyTotal');
    if (sticky) {
      sticky.textContent = 'RM ' + newTot.toFixed(2);
    }
  }

  function attachListeners() {
    document.querySelectorAll('.qty-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const row = btn.closest('tr');
        const pid = row.dataset.pid;
        const color = row.dataset.color || '';
        const size = row.dataset.size || '';
        const span = row.querySelector('.qty-wrap span');
        const current = parseInt(span.textContent, 10);
        const act = btn.dataset.act;

        const stockAttr = row.dataset.stock;
        const stock = stockAttr ? parseInt(stockAttr, 10) : null;

        let newQty = act === 'increase' ? current + 1 : Math.max(1, current - 1);

        // Hard stop: cannot exceed available stock
        if (act === 'increase' && Number.isFinite(stock) && newQty > stock) {
          // Small visual feedback
          btn.style.transform = 'scale(1.05)';
          setTimeout(() => {
            btn.style.transform = '';
          }, 120);

          // Show message ONLY when user tries to go beyond stock
          showMaxStockMessage(row, stock);
          return;
        }

        fetchAction(
          `update&ProductID=${encodeURIComponent(pid)}&Quantity=${encodeURIComponent(newQty)}&ColorName=${encodeURIComponent(color)}&Size=${encodeURIComponent(size)}`
        );
      });
    });

    document.querySelectorAll('.remove-item').forEach(btn => {
      btn.addEventListener('click', () => {
        const row = btn.closest('tr');
        const pid = row.dataset.pid;
        const color = row.dataset.color || '';
        const size = row.dataset.size || '';
        fetchAction(
          `remove&ProductID=${encodeURIComponent(pid)}&ColorName=${encodeURIComponent(color)}&Size=${encodeURIComponent(size)}`
        );
      });
    });

    const clearBtn = document.getElementById('clear-cart');
    if (clearBtn) clearBtn.addEventListener('click', () => fetchAction('clear'));

    const rpInput = document.getElementById('redeemPoints');
    if (rpInput) {
      rpInput.addEventListener('input', () => {
        let pts = parseInt(rpInput.value || '0', 10);
        if (isNaN(pts) || pts < 0) pts = 0;
        pts = Math.min(pts, rewardBalance);
        const maxByCart = rewardRate > 0 ? Math.floor(cartTotalPrice / rewardRate) : 0;
        pts = Math.min(pts, maxByCart);
        rpInput.value = pts;
        rewardAppliedPts = pts;
        syncRightSummary(cartTotalPrice);
      });
    }
  }

  async function fetchAction(query) {
    try {
      const res = await fetch('cart_process.php?action=' + query + '&ajax=1');
      if (!res.ok) throw 'Network error';
      const data = await res.json();
      if (!data.success) throw data.message || 'Server error';

      window.location.reload();
    } catch (e) {
      alert('Error: ' + e);
    }
  }

  // Initial render of all items
  renderItemsTable({
    cartItems: cartItems,
    totalPrice: cartTotalPrice
  });

  // Attach search listener (if the input exists)
  const cartSearchEl = document.getElementById('cart-search');
  if (cartSearchEl) {
    cartSearchEl.addEventListener('input', applySearch);
  }

  function applySearch() {
    const inputEl = document.getElementById('cart-search');
    if (!inputEl) {
      // Safety – if somehow not found, just render all items
      renderItemsTable({
        cartItems: cartItems,
        totalPrice: cartTotalPrice
      });
      return;
    }

    const q = inputEl.value.trim().toLowerCase();

    const filtered = cartItems.filter(item =>
      item.Name && item.Name.toLowerCase().includes(q)
    );

    renderItemsTable({
      cartItems: filtered,
      totalPrice: cartTotalPrice
    });
  }

  // =====================
  // Targeted promotion selection:
  // - click different promo => apply that promo
  // - click same promo again => remove targeted promotion (use none)
  // =====================
  (function() {
    const form = document.getElementById('promoForm');
    const hidden = document.getElementById('promoChoiceHidden');
    if (!form || !hidden) return;

    let currentChoice = hidden.value || 'auto'; // 'auto', 'none', or promoID as string

    form.querySelectorAll('input[name="target_promo_choice"]').forEach(radio => {
      radio.addEventListener('click', function(e) {
        const val = this.value;

        if (currentChoice !== 'none' && currentChoice === val) {
          // Clicked the *same* selected promo -> remove promotion
          e.preventDefault();
          this.checked = false;
          hidden.value = 'none';
          currentChoice = 'none';
        } else {
          // Clicked a different promo -> select & apply it
          hidden.value = val;
          currentChoice = val;
        }

        form.submit();
      });
    });
  })();


  // =====================
  // Shipping Address: toggle show/hide address list
  // =====================
  (function() {
    const toggleBtn = document.getElementById('addrToggleBtn');
    const icon = document.getElementById('addrToggleIcon');
    const wrapper = document.getElementById('addrListWrapper');

    if (!toggleBtn || !icon || !wrapper) return;

    let isOpen = false;

    toggleBtn.addEventListener('click', function() {
      isOpen = !isOpen;
      wrapper.style.display = isOpen ? 'block' : 'none';
      icon.textContent = isOpen ? '▲' : '▼';
    });
  })();



  <?php if (!empty($initialItems) && $orderID): ?>
    paypal.Buttons({
      fundingSource: paypal.FUNDING.PAYPAL,
      createOrder: function(data, actions) {
        return actions.order.create({
          purchase_units: [{
            amount: {
              value: '<?= number_format($grandTotal, 2, ".", "") ?>'
            },
            custom_id: '<?= (int)$orderID ?>'
          }]
        });
      },
      onApprove: function(data, actions) {
        return actions.order.capture().then(function(details) {
          return fetch('paypal_complete.php', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json'
              },
              body: JSON.stringify({
                order_id: <?= (int)$orderID ?>,
                paypal_order_id: data.orderID,
                payer_email: details.payer && details.payer.email_address ? details.payer.email_address : null
              })
            })
            .then(res => res.json())
            .then(function(res) {
              if (res.ok) {
                window.location.href = 'order_success.php?order_id=<?= (int)$orderID ?>';
              } else {
                alert('Payment captured, but system update failed: ' + (res.error || 'Unknown error'));
              }
            })
            .catch(function(err) {
              console.error(err);
              alert('Payment captured, but there was an error updating the system.');
            });
        });
      },
      onError: function(err) {
        console.error('PayPal error', err);
        alert('An error occurred with PayPal. Please try again.');
      }
    }).render('#paypal-button-container');
  <?php else: ?>
    const pb = document.getElementById('paypal-button-container');
    if (pb) {
      pb.innerHTML = '<button disabled style="padding:8px 16px;border-radius:999px;border:none;background:#9ca3af;color:#fff;font-weight:600;font-size:0.9rem;">Pay with PayPal</button>';
    }
  <?php endif; ?>
</script>

<?php include __DIR__ . '/footer.php'; ?>