<?php
// cart_process.php

require __DIR__ . '/../config.php';

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors',1);

session_start();

$userID = $_SESSION['user']['UserID'] ?? null;

if (!$userID) {
    header('Location: /login.php');
    exit();
}

$action = $_GET['action'] ?? '';
$isAjax = isset($_GET['ajax']);

function respond($data) {
    echo json_encode($data);
    exit;
}

if (!$action) {
    respond(['success'=>false,'message'=>'No action.']);
}

// 1) Get or create cartID
$stmt = $pdo->prepare("SELECT CartID FROM shoppingcart WHERE UserID=?");
$stmt->execute([$userID]);
$row = $stmt->fetch();
if ($row) {
    $cartID = $row['CartID'];
} else {
    $pdo->prepare("INSERT INTO shoppingcart (UserID,TotalPrice) VALUES (?,0.00)")
        ->execute([$userID]);
    $cartID = $pdo->lastInsertId();
}

// Helper for text parameter (used for ColorName & Size)
function normalizeColorParam(string $key = 'ColorName'): ?string {
    if (!isset($_GET[$key])) return null;
    $v = trim($_GET[$key]);
    if ($v === '') return null;
    return $v;
}

/**
 * Get available stock for a specific Product + ColorName + Size
 * Uses product_color_sizes joined with product_colors.
 */
function getSizeStock(PDO $pdo, int $productID, ?string $colorName, ?string $size): ?int
{
    if ($size === null) {
        return null;
    }

    if ($colorName !== null) {
        // Exact color + size
        $sql = "
            SELECT pcs.Stock
            FROM product_color_sizes pcs
            JOIN product_colors pc ON pc.ProductColorID = pcs.ProductColorID
            WHERE pc.ProductID = ?
              AND pc.ColorName = ?
              AND pcs.Size = ?
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$productID, $colorName, $size]);
    } else {
        // No colorName provided: pick default/first color for that product + size
        $sql = "
            SELECT pcs.Stock
            FROM product_color_sizes pcs
            JOIN product_colors pc ON pc.ProductColorID = pcs.ProductColorID
            WHERE pc.ProductID = ?
              AND pcs.Size = ?
            ORDER BY pc.IsDefault DESC, pc.ProductColorID ASC
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$productID, $size]);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return (int)$row['Stock'];
}

// Perform the requested action
switch ($action) {

  case 'add':
    if (empty($_GET['ProductID'])) {
        respond(['success'=>false,'message'=>'Missing ProductID']);
    }
    $pid = (int)$_GET['ProductID'];

    // Color name coming from product_detail.php (optional)
    $colorName = normalizeColorParam('ColorName');

    // Size coming from product_detail.php (required by UI, but still nullable here)
    $size = normalizeColorParam('Size');

    // check existing same product + same color + same size
    $stmt = $pdo->prepare("
        SELECT CartItemID, Quantity
          FROM cartitem
         WHERE CartID = ?
           AND ProductID = ?
           AND (ColorName <=> ?)
           AND (Size      <=> ?)
    ");
    $stmt->execute([$cartID, $pid, $colorName, $size]);
    $item = $stmt->fetch();

    // --- STOCK CHECK (cannot exceed available size stock) ---
    $sizeStock = getSizeStock($pdo, $pid, $colorName, $size);
    $newQty    = $item ? ((int)$item['Quantity'] + 1) : 1;

    if ($sizeStock !== null && $newQty > $sizeStock) {
        // Block adding more than available
        if ($isAjax) {
            respond([
                'success'  => false,
                'message'  => "Sorry, you've reached the maximum stock for this size.",
                'maxStock' => $sizeStock
            ]);
        } else {
            $_SESSION['cart_error'] = "Sorry, you've reached the maximum stock for this size ({$sizeStock} available).";
            header('Location: cart.php');
            exit;
        }
    }

    if ($item) {
        $pdo->prepare("UPDATE cartitem SET Quantity = Quantity + 1 WHERE CartItemID = ?")
            ->execute([$item['CartItemID']]);
    } else {
        // correct parameter order & 5 placeholders
        $pdo->prepare("
            INSERT INTO cartitem (CartID, ProductID, Quantity, ColorName, Size)
            VALUES (?,?,?,?,?)
        ")->execute([$cartID, $pid, 1, $colorName, $size]);
    }
    break;

  case 'update':
    if (!isset($_GET['ProductID'],$_GET['Quantity'])) {
        respond(['success'=>false,'message'=>'Missing parameters']);
    }
    $pid = (int)$_GET['ProductID'];
    $qty = max(1,(int)$_GET['Quantity']);

    $colorName = normalizeColorParam('ColorName');
    $size      = normalizeColorParam('Size');

    // --- STOCK CHECK on update (clamp to max stock) ---
    $sizeStock = getSizeStock($pdo, $pid, $colorName, $size);
    $wasClamped = false;
    if ($sizeStock !== null && $qty > $sizeStock) {
        $qty = $sizeStock;     // clamp to max available
        $wasClamped = true;
        if ($qty < 1) {
            // If somehow stock is 0, remove item entirely
            $delSql = "
                DELETE FROM cartitem
                 WHERE CartID = ?
                   AND ProductID = ?
                   AND IFNULL(ColorName,'') = ?
                   AND IFNULL(Size,'')      = ?
            ";
            $pdo->prepare($delSql)->execute([
                $cartID,
                $pid,
                $colorName ?? '',
                $size ?? ''
            ]);

            if ($isAjax) {
                respond([
                    'success' => false,
                    'message' => "This size is currently out of stock."
                ]);
            } else {
                $_SESSION['cart_error'] = "This size is currently out of stock.";
                header('Location: cart.php');
                exit;
            }
        }
    }

    // Match row by product + color + size
    $sql = "
        UPDATE cartitem
           SET Quantity = ?
         WHERE CartID = ?
           AND ProductID = ?
           AND IFNULL(ColorName,'') = ?
           AND IFNULL(Size,'')      = ?
    ";
    $params = [
        $qty,
        $cartID,
        $pid,
        $colorName ?? '',
        $size ?? ''
    ];
    $pdo->prepare($sql)->execute($params);

    // If AJAX and clamped, we do not early-respond here because
    // we will send full cart JSON below (with SizeStock) which
    // front-end can use to show the “max stock” pill nicely.
    break;

  case 'remove':
    if (empty($_GET['ProductID'])) {
        respond(['success'=>false,'message'=>'Missing ProductID']);
    }
    $pid = (int)$_GET['ProductID'];

    $colorName = normalizeColorParam('ColorName');
    $size      = normalizeColorParam('Size');

    $sql = "
        DELETE FROM cartitem
         WHERE CartID = ?
           AND ProductID = ?
           AND IFNULL(ColorName,'') = ?
           AND IFNULL(Size,'')      = ?
    ";
    $params = [
        $cartID,
        $pid,
        $colorName ?? '',
        $size ?? ''
    ];
    $pdo->prepare($sql)->execute($params);
    break;

  case 'clear':
    $pdo->prepare("DELETE FROM cartitem WHERE CartID=?")
        ->execute([$cartID]);
    break;

  default:
    respond(['success'=>false,'message'=>'Unknown action']);
}

// Recalculate total
$pdo->prepare("
  UPDATE shoppingcart 
     SET TotalPrice = (
         SELECT COALESCE(SUM(ci.Quantity * p.Price),0)
           FROM cartitem ci
           JOIN product p ON ci.ProductID=p.ProductID
          WHERE ci.CartID=?
     )
   WHERE CartID=?
")->execute([$cartID,$cartID]);

// If AJAX, return JSON of updated cart
if ($isAjax) {
    // Fetch items with ColorName + Size + color-specific image + size stock
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
                JOIN product_colors pc2 
                  ON pc2.ProductColorID = pi.ProductColorID
                WHERE pc2.ProductID = ci.ProductID
                  AND pc2.ColorName = ci.ColorName
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
      JOIN product p ON ci.ProductID=p.ProductID
      LEFT JOIN product_colors pc
        ON pc.ProductID = ci.ProductID
       AND (ci.ColorName IS NULL OR pc.ColorName = ci.ColorName)
      LEFT JOIN product_color_sizes pcs
        ON pcs.ProductColorID = pc.ProductColorID
       AND pcs.Size = ci.Size
      WHERE ci.CartID=?
    ");
    $stmt->execute([$cartID]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch total
    $stmt = $pdo->prepare("SELECT TotalPrice FROM shoppingcart WHERE CartID=?");
    $stmt->execute([$cartID]);
    $tot = $stmt->fetch(PDO::FETCH_ASSOC);

    respond([
      'success'    => true,
      'cartItems'  => $items,
      'totalPrice' => $tot['TotalPrice'] ?? 0
    ]);
}

// Non-AJAX: redirect back
header('Location: cart.php');
exit;
