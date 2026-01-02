<?php
// admin_stock.php — Stock In / Out / Adjustment + Audit Log

session_start();
require __DIR__ . '/../config.php';

if (empty($_SESSION['user'])) {
    header('Location: ../login.php');
    exit;
}
$currentUserID = (int)($_SESSION['user']['UserID'] ?? 0);

// Optional: enforce Admin only
// if (($_SESSION['user']['Role'] ?? '') !== 'Admin') {
//     die('Access denied.');
// }

try {
    // If config.php already makes $pdo, this is optional; otherwise:
    if (!isset($pdo)) {
        $pdo = new PDO(
            "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    die("DB connection error: " . htmlspecialchars($e->getMessage()));
}

/* ----------------------------------------------------------
   Helper: record_stock_movement
---------------------------------------------------------- */
function record_stock_movement(
    PDO $pdo,
    int $colorSizeID,
    string $movementType,   // 'IN','OUT','ADJUST'
    string $reason,         // 'RECEIVE','SALES','DAMAGE','ADJUSTMENT'
    int $qtyChange,         // + for IN, - for OUT, +/- for ADJUST
    ?string $referenceType,
    ?string $referenceID,
    ?string $note,
    ?int $userID
): void {
    $pdo->beginTransaction();

    // 1) Get current stock
    $st = $pdo->prepare("SELECT Stock FROM product_color_sizes WHERE ColorSizeID = ?");
    $st->execute([$colorSizeID]);
    $row = $st->fetch();
    if (!$row) {
        $pdo->rollBack();
        throw new RuntimeException("ColorSizeID not found.");
    }

    $oldStock = (int)$row['Stock'];
    $newStock = $oldStock + $qtyChange;

    if ($newStock < 0) {
        $pdo->rollBack();
        throw new RuntimeException("Stock cannot be negative.");
    }

    // 2) Update stock
    $up = $pdo->prepare("UPDATE product_color_sizes SET Stock = ? WHERE ColorSizeID = ?");
    $up->execute([$newStock, $colorSizeID]);

    // 3) Insert movement log
    $ins = $pdo->prepare("
        INSERT INTO stock_movements
            (ColorSizeID, MovementType, Reason,
             QtyChange, OldStock, NewStock,
             ReferenceType, ReferenceID, Note, PerformedBy)
        VALUES (?,?,?,?,?,?,?,?,?,?)
    ");
    $ins->execute([
        $colorSizeID,
        $movementType,
        $reason,
        $qtyChange,
        $oldStock,
        $newStock,
        $referenceType,
        $referenceID,
        $note,
        $userID
    ]);

    $pdo->commit();
}

/* ----------------------------------------------------------
   1) Handle POST: Stock In / Out / Adjust
---------------------------------------------------------- */
$flashSuccess = '';
$flashError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action      = $_POST['stock_action'] ?? '';
    $colorSizeID = (int)($_POST['ColorSizeID'] ?? 0);
    $productID   = (int)($_POST['ProductID'] ?? 0);
    $colorID     = (int)($_POST['ProductColorID'] ?? 0);
    $size        = $_POST['Size'] ?? '';

    try {
        if ($colorSizeID <= 0) {
            throw new RuntimeException("Invalid ColorSizeID.");
        }

        if ($action === 'stock_in') {
            $qty  = (int)($_POST['qty_in'] ?? 0);
            $ref  = trim($_POST['ref_in'] ?? '');
            $note = trim($_POST['note_in'] ?? '');

            if ($qty <= 0) {
                throw new RuntimeException("Quantity must be > 0.");
            }
            if ($ref === '') {
                throw new RuntimeException("Reference (PO number) is required for stock in.");
            }

            $refPattern = '/^[A-Za-z0-9\-\/]{3,20}$/';
            if (!preg_match($refPattern, $ref)) {
                throw new RuntimeException("Invalid reference format. Use only letters, numbers, '-' or '/' (3–20 chars).");
            }

            record_stock_movement(
                $pdo,
                $colorSizeID,
                'IN',
                'RECEIVE',
                +$qty,
                'PO',        // or 'MANUAL'
                $ref,
                $note ?: null,
                $currentUserID
            );

            $flashSuccess = "Stock in successful (+{$qty}).";
        } elseif ($action === 'stock_out') {
            $qty       = (int)($_POST['qty_out'] ?? 0);
            $reasonOut = strtoupper(trim($_POST['reason_out'] ?? ''));
            $ref       = trim($_POST['ref_out'] ?? '');
            $note      = trim($_POST['note_out'] ?? '');

            if ($qty <= 0) {
                throw new RuntimeException("Quantity must be > 0.");
            }

            // Allowed reasons from UI
            $allowedReasons = ['DAMAGE', 'RETURN_OUTWARD', 'OTHERS'];

            if ($reasonOut === '') {
                throw new RuntimeException("Please select a reason for stock out.");
            }

            if (!in_array($reasonOut, $allowedReasons, true)) {
                throw new RuntimeException("Invalid stock out reason.");
            }

            /* ======================================================
            VALIDATION RULES
            ====================================================== */

            if ($reasonOut === 'RETURN_OUTWARD') {
                if ($ref === '') {
                    throw new RuntimeException("Reference (PO number) is required for Return Outward.");
                }

                $refPattern = '/^[A-Za-z0-9\-\/]{3,20}$/';
                if (!preg_match($refPattern, $ref)) {
                    throw new RuntimeException("Invalid PO number format. Use letters, numbers, - or / (3–20 chars).");
                }
                // Note optional
            } else {
                // DAMAGE or OTHERS
                if ($note === '') {
                    throw new RuntimeException("Note is required for Damage or Others.");
                }
                // Validate reference if entered
                if ($ref !== '') {
                    $refPattern = '/^[A-Za-z0-9\-\/]{3,20}$/';
                    if (!preg_match($refPattern, $ref)) {
                        throw new RuntimeException("Invalid document number. Use letters, numbers, - or / (3–20 chars).");
                    }
                } else {
                    $ref = null;
                }
            }

            /* ======================================================
       MAP REFERENCE TYPE
    ====================================================== */

            switch ($reasonOut) {
                case 'RETURN_OUTWARD':
                    $referenceType = 'PO';
                    break;
                default:
                    $referenceType = 'MANUAL';
            }

            record_stock_movement(
                $pdo,
                $colorSizeID,
                'OUT',
                $reasonOut,
                -$qty,
                $referenceType,
                $ref,
                $note ?: null,
                $currentUserID
            );

            $flashSuccess = "Stock out successful (-{$qty}).";
        } elseif ($action === 'adjust') {
            $newCount = (int)($_POST['new_stock'] ?? 0);
            $note     = trim($_POST['note_adjust'] ?? '');

            if ($note === '') {
                throw new RuntimeException("Please enter a note for stock adjustment.");
            }

            // Get existing stock to compute delta
            $st = $pdo->prepare("SELECT Stock FROM product_color_sizes WHERE ColorSizeID = ?");
            $st->execute([$colorSizeID]);
            $row = $st->fetch();
            if (!$row) {
                throw new RuntimeException("ColorSizeID not found.");
            }
            $oldStock = (int)$row['Stock'];
            $delta    = $newCount - $oldStock;

            if ($delta === 0) {
                throw new RuntimeException("No change in stock.");
            }

            record_stock_movement(
                $pdo,
                $colorSizeID,
                'ADJUST',
                'ADJUSTMENT',
                $delta,
                'MANUAL',
                null,
                $note,
                $currentUserID
            );
            $flashSuccess = "Stock adjusted from {$oldStock} to {$newCount}.";
        } else {
            throw new RuntimeException("Unknown stock action.");
        }
    } catch (Throwable $e) {
        $flashError = $e->getMessage();
    }

    // Keep selection after POST
    $_GET['ProductID']      = $productID;
    $_GET['ProductColorID'] = $colorID;
    $_GET['Size']           = $size;
}

/* ----------------------------------------------------------
   2) Load dropdown data: products, colors, sizes
---------------------------------------------------------- */

// Products dropdown (active only)
$products = [];
try {
    $products = $pdo->query("
        SELECT ProductID, Name
        FROM product
        WHERE IsDeleted = 0
        ORDER BY Name
    ")->fetchAll();
} catch (Throwable $e) {
    $products = [];
}

/* ----------------------------------------------------------
   STEP 4) Deep-link support from admin_product.js
   Accepts: ?product=ID&color=ColorName&size=XS|S|M|L|XL
   Converts into: ProductID, ProductColorID, Size
---------------------------------------------------------- */

$dlProduct = isset($_GET['product']) ? (int)$_GET['product'] : 0;
$dlColor   = isset($_GET['color']) ? trim((string)$_GET['color']) : '';
$dlSize    = isset($_GET['size']) ? strtoupper(trim((string)$_GET['size'])) : '';

$allSizes = ['XS', 'S', 'M', 'L', 'XL'];
if (!in_array($dlSize, $allSizes, true)) {
    $dlSize = '';
}

// If deep-link product exists, override ProductID
if ($dlProduct > 0) {
    $_GET['ProductID'] = $dlProduct;
}

// If deep-link includes color name, resolve ProductColorID
if ($dlProduct > 0 && $dlColor !== '') {
    $st = $pdo->prepare("
        SELECT ProductColorID
        FROM product_colors
        WHERE ProductID = ?
          AND ColorName = ?
        LIMIT 1
    ");
    $st->execute([$dlProduct, $dlColor]);
    $resolvedColorID = (int)($st->fetchColumn() ?: 0);

    if ($resolvedColorID > 0) {
        $_GET['ProductColorID'] = $resolvedColorID;
    }
}

// If deep-link includes size, override Size
if ($dlSize !== '') {
    $_GET['Size'] = $dlSize;
}

$selectedProductID = isset($_GET['ProductID']) ? (int)$_GET['ProductID'] : 0;
if (!$selectedProductID && $products) {
    $selectedProductID = (int)$products[0]['ProductID'];
}

// Colors for selected product
$colors = [];
if ($selectedProductID) {
    $stColors = $pdo->prepare("
        SELECT ProductColorID, ColorName
        FROM product_colors
        WHERE ProductID = ?
        ORDER BY ColorName
    ");
    $stColors->execute([$selectedProductID]);
    $colors = $stColors->fetchAll();
}

$selectedColorID = isset($_GET['ProductColorID']) ? (int)$_GET['ProductColorID'] : 0;
if (!$selectedColorID && $colors) {
    $selectedColorID = (int)$colors[0]['ProductColorID'];
}

// Sizes (fixed)
$allSizes = ['XS', 'S', 'M', 'L', 'XL'];
$selectedSize = $_GET['Size'] ?? '';
if (!in_array($selectedSize, $allSizes, true)) {
    $selectedSize = 'XS';
}

/* ----------------------------------------------------------
   3) Load current stock for selected ColorSize
---------------------------------------------------------- */
$currentStock       = null;
$currentMin         = null;
$colorSizeID        = null;
$currentColorName   = '';
$currentProductName = '';

if ($selectedProductID && $selectedColorID && $selectedSize) {
    $st = $pdo->prepare("
        SELECT pcs.ColorSizeID, pcs.Stock, pcs.MinStock, pc.ColorName, p.Name AS ProductName
        FROM product_color_sizes pcs
        JOIN product_colors pc ON pcs.ProductColorID = pc.ProductColorID
        JOIN product p ON pc.ProductID = p.ProductID
        WHERE pcs.ProductColorID = ?
          AND pcs.Size = ?
        LIMIT 1
    ");
    $st->execute([$selectedColorID, $selectedSize]);
    $row = $st->fetch();

    if ($row) {
        $colorSizeID        = (int)$row['ColorSizeID'];
        $currentStock       = (int)$row['Stock'];
        $currentMin         = (int)$row['MinStock'];
        $currentColorName   = $row['ColorName'];
        $currentProductName = $row['ProductName'];
    }
}

$isOutOfStock = ($currentStock !== null && (int)$currentStock === 0);
$isBelowMin   = (!$isOutOfStock && $currentStock !== null && $currentMin !== null && $currentStock < $currentMin);

/* ----------------------------------------------------------
   4) Load movement log for this ColorSize
---------------------------------------------------------- */
$movements = [];
if ($colorSizeID) {
    $stMov = $pdo->prepare("
        SELECT sm.*, u.username
        FROM stock_movements sm
        LEFT JOIN user u ON sm.PerformedBy = u.UserID
        WHERE sm.ColorSizeID = ?
        ORDER BY sm.CreatedAt DESC, sm.MovementID DESC
        LIMIT 50
    ");
    $stMov->execute([$colorSizeID]);
    $movements = $stMov->fetchAll();
}

/* ----------------------------------------------------------
   4b) Load movement log for entire Product (all colors & sizes)
---------------------------------------------------------- */
$productMovements = [];
if ($selectedProductID) {
    $stPM = $pdo->prepare("
        SELECT 
            sm.*,
            u.username,
            pc.ColorName,
            pcs.Size
        FROM stock_movements sm
        JOIN product_color_sizes pcs ON sm.ColorSizeID = pcs.ColorSizeID
        JOIN product_colors pc       ON pcs.ProductColorID = pc.ProductColorID
        JOIN product p               ON pc.ProductID = p.ProductID
        LEFT JOIN user u             ON sm.PerformedBy = u.UserID
        WHERE p.ProductID = ?
        ORDER BY sm.CreatedAt DESC, sm.MovementID DESC
        LIMIT 100
    ");
    $stPM->execute([$selectedProductID]);
    $productMovements = $stPM->fetchAll();
}

// Shared admin header
include __DIR__ . '/admin_header.php';
?>
<link rel="stylesheet" href="/assets/admin_product.css">

<style>
    /* Page wrapper */
    .stock-page {
        max-width: 1100px;
        margin: 20px auto 40px;
        padding: 0 10px 30px;
    }

    .stock-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        margin-bottom: 14px;
        padding-bottom: 6px;
        border-bottom: 1px solid rgba(180, 152, 90, 0.45);
    }

    .stock-header h2 {
        margin: 0;
        font-size: 1.4rem;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        font-weight: 600;
        color: #111827;
    }

    .stock-header small {
        font-size: 0.75rem;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.14em;
    }

    .stock-header-tag {
        font-size: 0.75rem;
        padding: 3px 10px;
        border-radius: 999px;
        background: linear-gradient(135deg, #b68b4c, #e0c38c);
        color: #111827;
        text-transform: uppercase;
        letter-spacing: 0.14em;
        font-weight: 600;
    }

    .stock-panel {
        margin-top: 10px;
        background: #ffffff;
        border-radius: 14px;
        padding: 18px 20px 22px;
        box-shadow:
            0 18px 45px rgba(15, 23, 42, 0.07),
            0 0 0 1px rgba(148, 163, 184, 0.18);
        border: 1px solid rgba(209, 213, 219, 0.7);
    }

    .stock-filter-row {
        display: flex;
        flex-wrap: wrap;
        gap: 14px;
        align-items: flex-end;
        margin-bottom: 14px;
        padding: 10px 12px;
        border-radius: 12px;
        background: linear-gradient(135deg, #f9fafb, #f3f4f6);
        border: 1px solid rgba(209, 213, 219, 0.9);
    }

    .stock-filter-row>div label {
        display: block;
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        margin-bottom: 3px;
        color: #6b7280;
    }

    .stock-filter-row select {
        padding: 7px 10px;
        font-size: 0.85rem;
        border-radius: 999px;
        border: 1px solid #d1d5db;
        min-width: 180px;
        background: #ffffff;
    }

    .stock-filter-row .btn-refresh {
        border-radius: 999px;
        border: none;
        padding: 7px 14px;
        font-size: 0.82rem;
        cursor: pointer;
        background: linear-gradient(135deg, #111827, #374151);
        color: #f9fafb;
        text-transform: uppercase;
        letter-spacing: 0.1em;
    }

    .stock-summary {
        margin: 12px 0 18px;
        padding: 10px 12px;
        border-radius: 12px;
        background: radial-gradient(circle at top left, #f9fafb 0, #e5e7eb 40%, #f9fafb 100%);
        font-size: 0.92rem;
        display: flex;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px;
    }

    .stock-summary-left {
        max-width: 65%;
    }

    .stock-summary-left strong {
        font-weight: 600;
        color: #111827;
    }

    .stock-summary-right {
        text-align: right;
        min-width: 170px;
    }

    .stock-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 3px 10px;
        border-radius: 999px;
        font-size: 0.78rem;
        border: 1px solid rgba(148, 163, 184, 0.6);
        background: rgba(249, 250, 251, 0.9);
    }

    .stock-pill.low {
        border-color: rgba(248, 113, 113, 0.8);
        background: rgba(254, 242, 242, 0.95);
        color: #b91c1c;
    }

    .stock-pill.ok {
        border-color: rgba(52, 211, 153, 0.7);
        background: rgba(240, 253, 250, 0.96);
        color: #047857;
    }

    .stock-pill span.value {
        font-weight: 600;
    }

    .stock-actions-title {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin: 4px 2px 10px;
    }

    .stock-actions-title h3 {
        margin: 0;
        font-size: 0.98rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #374151;
    }

    .stock-actions-title small {
        font-size: 0.76rem;
        color: #6b7280;
    }

    .stock-actions {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 20px;
    }

    .stock-actions form {
        border-radius: 12px;
        padding: 12px 12px 14px;
        background: #f9fafb;
        border: 1px solid rgba(209, 213, 219, 0.9);
        position: relative;
        overflow: hidden;
    }

    .stock-actions form::before {
        content: "";
        position: absolute;
        inset: 0;
        border-radius: inherit;
        pointer-events: none;
        opacity: 0.65;
    }

    .stock-actions form.stock-in::before {
        border-left: 3px solid #16a34a;
    }

    .stock-actions form.stock-out::before {
        border-left: 3px solid #dc2626;
    }

    .stock-actions form.stock-adjust::before {
        border-left: 3px solid #2563eb;
    }

    .stock-actions h4 {
        margin: 0 0 8px;
        font-size: 0.92rem;
        font-weight: 600;
        color: #111827;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .stock-actions h4 span.icon {
        font-size: 1rem;
    }

    .stock-actions label {
        display: block;
        margin-top: 7px;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #6b7280;
    }

    .stock-actions input[type="number"],
    .stock-actions input[type="text"],
    .stock-actions textarea,
    .stock-actions select {
        width: 100%;
        box-sizing: border-box;
        padding: 6px 8px;
        font-size: 0.85rem;
        border-radius: 8px;
        border: 1px solid #d1d5db;
        background: #ffffff;
        margin-top: 3px;
    }

    .stock-actions textarea {
        min-height: 54px;
        resize: vertical;
    }

    .stock-actions button {
        margin-top: 10px;
        padding: 7px 10px;
        font-size: 0.82rem;
        border-radius: 999px;
        border: none;
        cursor: pointer;
        text-transform: uppercase;
        letter-spacing: 0.08em;
    }

    .btn-green {
        background: linear-gradient(135deg, #16a34a, #22c55e);
        color: #ecfdf5;
    }

    .btn-red {
        background: linear-gradient(135deg, #dc2626, #f97373);
        color: #fef2f2;
    }

    .btn-blue {
        background: linear-gradient(135deg, #1d4ed8, #3b82f6);
        color: #eff6ff;
    }

    .flash-success,
    .flash-error {
        margin: 12px auto 6px;
        max-width: 1100px;
        padding: 8px 12px;
        border-radius: 999px;
        font-size: 0.85rem;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .flash-success {
        background: #ecfdf3;
        color: #166534;
        border: 1px solid #bbf7d0;
    }

    .flash-error {
        background: #fef2f2;
        color: #b91c1c;
        border: 1px solid #fecaca;
    }

    .flash-success span.icon,
    .flash-error span.icon {
        font-size: 1rem;
    }

    .movement-block {
        margin-top: 4px;
        padding-top: 10px;
        border-top: 1px solid rgba(209, 213, 219, 0.8);
    }

    .movement-title-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 6px;
    }

    .movement-title-row h3 {
        margin: 0;
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #374151;
    }

    .movement-title-row small {
        font-size: 0.78rem;
        color: #6b7280;
    }

    .movement-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.82rem;
        background: #ffffff;
        border-radius: 12px;
        overflow: hidden;
    }

    .movement-table th,
    .movement-table td {
        padding: 6px 8px;
        border-bottom: 1px solid #e5e7eb;
        vertical-align: top;
    }

    .movement-table th {
        background: #f3f4f6;
        text-align: left;
        font-weight: 600;
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #6b7280;
    }

    .movement-table td.note-cell {
        max-width: 220px;
        white-space: normal;
        word-wrap: break-word;
        word-break: break-word;
    }

    .movement-table tr:nth-child(even) td {
        background: #f9fafb;
    }

    .badge {
        display: inline-flex;
        align-items: center;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 0.74rem;
        font-weight: 500;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        border: 1px solid transparent;
    }

    .badge-type-in {
        background: #ecfdf3;
        color: #15803d;
        border-color: #bbf7d0;
    }

    .badge-type-out {
        background: #fef2f2;
        color: #b91c1c;
        border-color: #fecaca;
    }

    .badge-type-adjust {
        background: #eff6ff;
        color: #1d4ed8;
        border-color: #bfdbfe;
    }

    .badge-reason-sales {
        background: #eef2ff;
        color: #4338ca;
        border-color: #c7d2fe;
    }

    .badge-reason-damage {
        background: #fef3c7;
        color: #92400e;
        border-color: #fde68a;
    }

    .badge-reason-receive {
        background: #ecfdf5;
        color: #047857;
        border-color: #a7f3d0;
    }

    .badge-reason-adjust {
        background: #e0f2fe;
        color: #0369a1;
        border-color: #bae6fd;
    }

    .badge-reason-return_outward {
        background: #dbeafe;
        color: #1e40af;
        border-color: #93c5fd;
    }

    .badge-reason-others {
        background: #f3f4f6;
        color: #374151;
        border-color: #d1d5db;
    }

    @media (max-width: 960px) {
        .stock-actions {
            grid-template-columns: 1fr;
        }

        .stock-summary {
            flex-direction: column;
            align-items: flex-start;
        }

        .stock-summary-right {
            text-align: left;
        }
    }

    .stock-product-search {
        padding: 6px 10px;
        font-size: 0.85rem;
        border-radius: 999px;
        border: 1px solid #d1d5db;
        background: #ffffff;
    }

    .stock-filter-row select,
    .stock-filter-row input.stock-product-search {
        padding: 7px 10px;
        font-size: 0.85rem;
        border-radius: 999px;
        border: 1px solid #d1d5db;
        min-width: 180px;
        background: #ffffff;
    }
</style>

<div class="stock-page">
    <div class="stock-header">
        <div>
            <h2>Stock Management</h2>
            <small>Color &amp; Size · Movement Log · Audit Trail</small>
        </div>
        <div class="stock-header-tag">Inventory Control</div>
    </div>

    <?php if ($flashSuccess): ?>
        <div class="flash-success">
            <span class="icon">✅</span>
            <span><?= htmlspecialchars($flashSuccess) ?></span>
        </div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="flash-error">
            <span class="icon">⚠️</span>
            <span><?= htmlspecialchars($flashError) ?></span>
        </div>
    <?php endif; ?>

    <div class="stock-panel">
        <!-- Filter: Product / Color / Size -->
        <form method="GET" class="stock-filter-row">
            <div>
                <label>Product</label>
                <!-- Visible text input with dropdown suggestions -->
                <input
                    type="text"
                    id="productSearch"
                    class="stock-product-search"
                    list="productList"
                    placeholder="Type product name..."
                    autocomplete="off"
                    value="<?php
                            // prefill with currently selected product name
                            $currentProdName = '';
                            foreach ($products as $prod) {
                                if ((int)$prod['ProductID'] === (int)$selectedProductID) {
                                    $currentProdName = $prod['Name'];
                                    break;
                                }
                            }
                            echo htmlspecialchars($currentProdName);
                            ?>">

                <!-- Hidden field that actually holds ProductID for PHP -->
                <input type="hidden" name="ProductID" id="productIdHidden"
                    value="<?= (int)$selectedProductID ?>">

                <!-- Datalist with all products -->
                <datalist id="productList">
                    <?php foreach ($products as $prod): ?>
                        <option
                            value="<?= htmlspecialchars($prod['Name']) ?>"
                            data-id="<?= (int)$prod['ProductID'] ?>">
                        </option>
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div>
                <label>Color</label>
                <select name="ProductColorID" onchange="this.form.submit()">
                    <?php if ($colors): ?>
                        <?php foreach ($colors as $col): ?>
                            <option value="<?= $col['ProductColorID'] ?>"
                                <?= $col['ProductColorID'] == $selectedColorID ? 'selected' : '' ?>>
                                <?= htmlspecialchars($col['ColorName']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="">No colors</option>
                    <?php endif; ?>
                </select>
            </div>

            <div>
                <label>Size</label>
                <select name="Size" onchange="this.form.submit()">
                    <?php foreach ($allSizes as $s): ?>
                        <option value="<?= $s ?>" <?= $s === $selectedSize ? 'selected' : '' ?>>
                            <?= $s ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <button type="submit" class="btn-refresh">Refresh</button>
            </div>
        </form>

        <?php if ($colorSizeID): ?>
            <div class="stock-summary">
                <div class="stock-summary-left">
                    <div>
                        <strong>Product:</strong>
                        <?= htmlspecialchars($currentProductName ?? '') ?>
                    </div>
                    <div>
                        <strong>Color:</strong>
                        <?= htmlspecialchars($currentColorName) ?>
                        &nbsp;|&nbsp;
                        <strong>Size:</strong>
                        <?= htmlspecialchars($selectedSize) ?>
                    </div>
                </div>
                <div class="stock-summary-right">
                    <div class="stock-pill <?= ($isOutOfStock || $isBelowMin) ? 'low' : 'ok' ?>">
                        <span>
                            <?php
                            if ($isOutOfStock) {
                                echo 'Out of stock';
                            } elseif ($isBelowMin) {
                                echo 'Below Min';
                            } else {
                                echo 'Stock Level';
                            }
                            ?>
                        </span>
                        <span class="value">
                            <?= (int)$currentStock ?> / <?= (int)$currentMin ?> Min
                        </span>
                    </div>
                </div>
            </div>

            <div class="stock-actions-title">
                <h3>Stock Actions</h3>
                <small>Record real-time movements with full traceability</small>
            </div>

            <!-- Stock Actions -->
            <div class="stock-actions">
                <!-- Stock In -->
                <form method="POST" class="stock-in">
                    <h4><span class="icon">➕</span> Stock In (Receive)</h4>
                    <input type="hidden" name="stock_action" value="stock_in">
                    <input type="hidden" name="ColorSizeID" value="<?= $colorSizeID ?>">
                    <input type="hidden" name="ProductID" value="<?= $selectedProductID ?>">
                    <input type="hidden" name="ProductColorID" value="<?= $selectedColorID ?>">
                    <input type="hidden" name="Size" value="<?= htmlspecialchars($selectedSize) ?>">

                    <label>Quantity</label>
                    <input type="number" name="qty_in" min="1" required>

                    <label>Reference (DOC NO.)</label>
                    <input type="text" name="ref_in" placeholder="PO number" required>

                    <label>Note</label>
                    <textarea name="note_in"></textarea>

                    <button type="submit" class="btn-green">Stock In</button>
                </form>

                <!-- Stock Out -->
                <form method="POST" class="stock-out">
                    <h4><span class="icon">➖</span> Stock Out (Sales / Damage)</h4>
                    <input type="hidden" name="stock_action" value="stock_out">
                    <input type="hidden" name="ColorSizeID" value="<?= $colorSizeID ?>">
                    <input type="hidden" name="ProductID" value="<?= $selectedProductID ?>">
                    <input type="hidden" name="ProductColorID" value="<?= $selectedColorID ?>">
                    <input type="hidden" name="Size" value="<?= htmlspecialchars($selectedSize) ?>">

                    <label>Quantity</label>
                    <input type="number" name="qty_out" min="1" required>

                    <label>Reason</label>
                    <select name="reason_out" id="reason_out" required>
                        <option value="">-- Select reason --</option>
                        <option value="DAMAGE">Damage</option>
                        <option value="RETURN_OUTWARD">Return outward</option>
                        <option value="OTHERS">Others</option>
                    </select>

                    <label>Reference (Doc No.)</label>
                    <input type="text" name="ref_out" id="ref_out" placeholder="Document number">

                    <label>Note</label>
                    <textarea name="note_out" id="note_out"></textarea>

                    <button type="submit" class="btn-red">Stock Out</button>
                </form>

                <!-- Adjustment -->
                <form method="POST" class="stock-adjust">
                    <h4><span class="icon">⚖</span> Stock Adjustment</h4>
                    <input type="hidden" name="stock_action" value="adjust">
                    <input type="hidden" name="ColorSizeID" value="<?= $colorSizeID ?>">
                    <input type="hidden" name="ProductID" value="<?= $selectedProductID ?>">
                    <input type="hidden" name="ProductColorID" value="<?= $selectedColorID ?>">
                    <input type="hidden" name="Size" value="<?= htmlspecialchars($selectedSize) ?>">

                    <label>New Physical Count</label>
                    <input type="number" name="new_stock" min="0" value="<?= (int)$currentStock ?>" required>

                    <label>Note (required)</label>
                    <textarea name="note_adjust"></textarea>

                    <button type="submit" class="btn-blue">Adjust</button>
                </form>
            </div>

            <!-- Movement Log -->
            <div class="movement-block">
                <div class="movement-title-row">
                    <h3>Recent Movements</h3>
                    <small>Latest 50 movements for this color &amp; size</small>
                </div>

                <?php if ($movements): ?>
                    <table class="movement-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Reason</th>
                                <th>Qty</th>
                                <th>Old → New</th>
                                <th>Ref</th>
                                <th>By (id)</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($movements as $m): ?>
                                <?php
                                $type      = $m['MovementType'];
                                $reason    = $m['Reason'];
                                $typeClass = 'badge-type-other';
                                if ($type === 'IN') {
                                    $typeClass = 'badge-type-in';
                                } elseif ($type === 'OUT') {
                                    $typeClass = 'badge-type-out';
                                } elseif ($type === 'ADJUST') {
                                    $typeClass = 'badge-type-adjust';
                                }

                                $reasonLower = strtolower($reason);
                                $reasonClass = '';

                                if ($reasonLower === 'sales') {
                                    $reasonClass = 'badge-reason-sales';
                                } elseif ($reasonLower === 'damage') {
                                    $reasonClass = 'badge-reason-damage';
                                } elseif ($reasonLower === 'receive') {
                                    $reasonClass = 'badge-reason-receive';
                                } elseif ($reasonLower === 'adjustment') {
                                    $reasonClass = 'badge-reason-adjust';
                                } elseif ($reasonLower === 'return_outward') {
                                    $reasonClass = 'badge-reason-return_outward';
                                } elseif ($reasonLower === 'others') {
                                    $reasonClass = 'badge-reason-others';
                                }

                                // Build reference display correctly
                                $refType = trim($m['ReferenceType'] ?? '');
                                $refID   = trim($m['ReferenceID'] ?? '');

                                if ($refID === '' || $refID === null) {
                                    $refDisplay = '-';   // show dash only
                                } else {
                                    // Show: "PO 12345" OR "MANUAL ABC001"
                                    $refDisplay = ($refType ? $refType . ' ' : '') . $refID;
                                }
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($m['CreatedAt']) ?></td>
                                    <td>
                                        <span class="badge <?= $typeClass ?>">
                                            <?= htmlspecialchars($m['MovementType']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($reasonClass): ?>
                                            <span class="badge <?= $reasonClass ?>">
                                                <?= htmlspecialchars(str_replace('_', ' ', ucfirst(strtolower($m['Reason'])))) ?>
                                            </span>
                                        <?php else: ?>
                                            <?= htmlspecialchars(str_replace('_', ' ', ucfirst(strtolower($m['Reason'])))) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= (int)$m['QtyChange'] ?></td>
                                    <td><?= (int)$m['OldStock'] ?> → <?= (int)$m['NewStock'] ?></td>
                                    <td><?= htmlspecialchars($refDisplay) ?></td>
                                    <td><?= (int)$m['PerformedBy'] ?></td>
                                    <td class="note-cell"><?= htmlspecialchars($m['Note'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="font-size:0.9rem; color:#6b7280;">
                        No stock movements yet for this color &amp; size.
                    </p>
                <?php endif; ?>
            </div>

            <?php if ($selectedProductID): ?>
                <div class="movement-block" style="margin-top:18px;">
                    <div class="movement-title-row">
                        <h3>All Movements for this Product</h3>
                        <small>All colors &amp; sizes · latest 100 records</small>
                    </div>

                    <?php if ($productMovements): ?>
                        <table class="movement-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Color</th>
                                    <th>Size</th>
                                    <th>Type</th>
                                    <th>Reason</th>
                                    <th>Qty</th>
                                    <th>Old → New</th>
                                    <th>Ref</th>
                                    <th>By (id)</th>
                                    <th>Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($productMovements as $m): ?>
                                    <?php
                                    $type      = $m['MovementType'];
                                    $reason    = $m['Reason'];
                                    $typeClass = 'badge-type-other';
                                    if ($type === 'IN') {
                                        $typeClass = 'badge-type-in';
                                    } elseif ($type === 'OUT') {
                                        $typeClass = 'badge-type-out';
                                    } elseif ($type === 'ADJUST') {
                                        $typeClass = 'badge-type-adjust';
                                    }

                                    $reasonLower = strtolower($reason);
                                    $reasonClass = '';
                                    if ($reasonLower === 'sales') {
                                        $reasonClass = 'badge-reason-sales';
                                    } elseif ($reasonLower === 'damage') {
                                        $reasonClass = 'badge-reason-damage';
                                    } elseif ($reasonLower === 'receive') {
                                        $reasonClass = 'badge-reason-receive';
                                    } elseif ($reasonLower === 'adjustment') {
                                        $reasonClass = 'badge-reason-adjust';
                                    } elseif ($reasonLower === 'return_outward') {
                                        $reasonClass = 'badge-reason-return_outward';
                                    } elseif ($reasonLower === 'others') {
                                        $reasonClass = 'badge-reason-others';
                                    }

                                    // Build reference display correctly
                                    $refType = trim($m['ReferenceType'] ?? '');
                                    $refID   = trim($m['ReferenceID'] ?? '');

                                    if ($refID === '' || $refID === null) {
                                        $refDisplay = '-';   // show dash only
                                    } else {
                                        // Show: "PO 12345" OR "MANUAL ABC001"
                                        $refDisplay = ($refType ? $refType . ' ' : '') . $refID;
                                    }
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($m['CreatedAt']) ?></td>
                                        <td><?= htmlspecialchars($m['ColorName'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($m['Size'] ?? '-') ?></td>
                                        <td>
                                            <span class="badge <?= $typeClass ?>">
                                                <?= htmlspecialchars($m['MovementType']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($reasonClass): ?>
                                                <span class="badge <?= $reasonClass ?>">
                                                    <?= htmlspecialchars(str_replace('_', ' ', ucfirst(strtolower($m['Reason'])))) ?>
                                                </span>
                                            <?php else: ?>
                                                <?= htmlspecialchars(str_replace('_', ' ', ucfirst(strtolower($m['Reason'])))) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= (int)$m['QtyChange'] ?></td>
                                        <td><?= (int)$m['OldStock'] ?> → <?= (int)$m['NewStock'] ?></td>
                                        <td><?= htmlspecialchars($refDisplay) ?></td>
                                        <td><?= (int)$m['PerformedBy'] ?></td>
                                        <td class="note-cell"><?= htmlspecialchars($m['Note'] ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="font-size:0.9rem; color:#6b7280;">
                            No stock movements yet recorded for this product.
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <p style="margin-top:16px;font-size:0.9rem;color:#6b7280;">
                No Color + Size combination found. Please ensure this product has color and size rows defined.
            </p>
        <?php endif; ?>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        /* ==========================================
   1) PRODUCT SEARCH (INPUT + DATALIST)
========================================== */
        const productSearch = document.getElementById("productSearch");
        const productIdHidden = document.getElementById("productIdHidden");
        const productList = document.getElementById("productList");
        const filterForm = document.querySelector(".stock-filter-row");

        function syncProductIdFromName() {
            if (!productSearch || !productIdHidden || !productList) return;

            const typed = productSearch.value.trim().toLowerCase();
            let foundId = "";

            Array.from(productList.options).forEach(opt => {
                if (opt.value.toLowerCase() === typed) {
                    foundId = opt.getAttribute("data-id") || "";
                }
            });

            // If match found, set ProductID, otherwise keep old value
            if (foundId !== "") {
                productIdHidden.value = foundId;
            }
        }

        // When user picks from dropdown or finishes typing
        if (productSearch) {
            productSearch.addEventListener("change", function() {
                syncProductIdFromName();
                if (filterForm) filterForm.submit();
            });

            // Optional: while typing, keep ProductID in sync when exact match
            productSearch.addEventListener("input", syncProductIdFromName);
        }

        /* ==========================================
           2) STOCK-OUT REASON FIELDS
        ========================================== */
        const reason = document.getElementById("reason_out");
        const ref = document.getElementById("ref_out");
        const note = document.getElementById("note_out");

        function updateFields() {
            if (!reason || !ref || !note) return;

            const val = reason.value;

            if (val === "RETURN_OUTWARD") {
                // Reference required, Note optional
                ref.placeholder = "PO number";
                ref.required = true;
                note.required = false;
            } else if (val === "DAMAGE" || val === "OTHERS") {
                // DAMAGE or OTHERS
                ref.placeholder = "Document number (optional)";
                ref.required = false;
                note.required = true;
            } else {
                // No reason selected
                ref.placeholder = "Document number";
                ref.required = false;
                note.required = false;
            }
        }

        if (reason) {
            reason.addEventListener("change", updateFields);
            updateFields(); // initial
        }
    });
</script>

<?php include __DIR__ . '/admin_footer.php'; ?>