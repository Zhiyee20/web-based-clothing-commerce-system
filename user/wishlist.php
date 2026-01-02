<?php
// user/wishlist.php
require __DIR__ . '/../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user'])) {
    header('Location: /login.php');
    exit;
}
$userID = (int)$_SESSION['user']['UserID'];

// Fetch wishlist items with product details + primary image + color codes + stock + campaign promo
$stmt = $pdo->prepare("
    SELECT 
        wi.ProductID,
        p.Name,
        p.Price,
        p.CategoryID,   -- for matching similar items by category
        (
            SELECT pi.ImagePath
            FROM product_images pi
            WHERE pi.ProductID = p.ProductID
              AND (
                pi.ProductColorID IS NULL
                OR pi.ProductColorID = (
                    SELECT pc.ProductColorID
                    FROM product_colors pc
                    WHERE pc.ProductID = p.ProductID
                      AND pc.IsDefault = 1
                    LIMIT 1
                )
              )
            ORDER BY pi.IsPrimary DESC, pi.SortOrder ASC, pi.ImageID ASC
            LIMIT 1
        ) AS Photo,
        (
          SELECT GROUP_CONCAT(pc.ColorCode ORDER BY pc.IsDefault DESC, pc.ProductColorID ASC SEPARATOR ',')
          FROM product_colors pc
          WHERE pc.ProductID = p.ProductID
        ) AS ColorCodeList,
        (
          SELECT SUM(pcs.Stock)
          FROM product_color_sizes pcs
          INNER JOIN product_colors pc2
            ON pc2.ProductColorID = pcs.ProductColorID
          WHERE pc2.ProductID = p.ProductID
        ) AS TotalStock,
        (
          SELECT MIN(pcs.Stock)
          FROM product_color_sizes pcs
          INNER JOIN product_colors pc2
            ON pc2.ProductColorID = pcs.ProductColorID
          WHERE pc2.ProductID = p.ProductID
            AND pcs.Stock > 0
        ) AS MinPositiveStock,
        (
          SELECT JSON_OBJECT(
              'FinalPrice',
              CASE 
                WHEN p2.DiscountType = 'Percentage'
                  THEN ROUND(p.Price * (1 - p2.DiscountValue / 100), 2)
                ELSE
                  GREATEST(ROUND(p.Price - p2.DiscountValue, 2), 0)
              END,
              'Type', p2.DiscountType,
              'Value', p2.DiscountValue
          )
          FROM promotions p2
          JOIN promotion_products pp2
            ON pp2.PromotionID = p2.PromotionID
          WHERE pp2.ProductID   = p.ProductID
            AND p2.PromotionType = 'Campaign'
            AND p2.PromoStatus   = 'Active'
          ORDER BY
            CASE 
              WHEN p2.DiscountType = 'Percentage'
                THEN p2.DiscountValue
              ELSE
                (p2.DiscountValue / p.Price * 100)
            END DESC,
            p2.PromotionID DESC
          LIMIT 1
        ) AS CampaignPromoJSON
    FROM wishlist_items wi
    JOIN product p ON wi.ProductID = p.ProductID
    WHERE wi.UserID = ?
    ORDER BY wi.CreatedAt DESC
");
$stmt->execute([$userID]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/**
 * Prepared statement to fetch similar products (global section).
 * - Same category as the base unavailable product
 * - Only in-stock (using TotalStock > 0)
 * - Exclude the base ProductID
 * - Price between 80% and 120% of the base product
 */
$similarStmt = $pdo->prepare("
    SELECT 
        p.ProductID,
        p.Name,
        p.Price,
        p.CategoryID,
        (
            SELECT pi.ImagePath
            FROM product_images pi
            WHERE pi.ProductID = p.ProductID
              AND (
                pi.ProductColorID IS NULL
                OR pi.ProductColorID = (
                    SELECT pc.ProductColorID
                    FROM product_colors pc
                    WHERE pc.ProductID = p.ProductID
                      AND pc.IsDefault = 1
                    LIMIT 1
                )
              )
            ORDER BY pi.IsPrimary DESC, pi.SortOrder ASC, pi.ImageID ASC
            LIMIT 1
        ) AS Photo,
        (
          SELECT GROUP_CONCAT(pc.ColorCode ORDER BY pc.IsDefault DESC, pc.ProductColorID ASC SEPARATOR ',')
          FROM product_colors pc
          WHERE pc.ProductID = p.ProductID
        ) AS ColorCodeList,
        (
          SELECT SUM(pcs.Stock)
          FROM product_color_sizes pcs
          INNER JOIN product_colors pc2
            ON pc2.ProductColorID = pcs.ProductColorID
          WHERE pc2.ProductID = p.ProductID
        ) AS TotalStock,
        (
          SELECT MIN(pcs.Stock)
          FROM product_color_sizes pcs
          INNER JOIN product_colors pc2
            ON pc2.ProductColorID = pcs.ProductColorID
          WHERE pc2.ProductID = p.ProductID
            AND pcs.Stock > 0
        ) AS MinPositiveStock,
        (
          SELECT JSON_OBJECT(
              'FinalPrice',
              CASE 
                WHEN p2.DiscountType = 'Percentage'
                  THEN ROUND(p.Price * (1 - p2.DiscountValue / 100), 2)
                ELSE
                  GREATEST(ROUND(p.Price - p2.DiscountValue, 2), 0)
              END,
              'Type', p2.DiscountType,
              'Value', p2.DiscountValue
          )
          FROM promotions p2
          JOIN promotion_products pp2
            ON pp2.PromotionID = p2.PromotionID
          WHERE pp2.ProductID   = p.ProductID
            AND p2.PromotionType = 'Campaign'
            AND p2.PromoStatus   = 'Active'
          ORDER BY
            CASE 
              WHEN p2.DiscountType = 'Percentage'
                THEN p2.DiscountValue
              ELSE
                (p2.DiscountValue / p.Price * 100)
            END DESC,
            p2.PromotionID DESC
          LIMIT 1
        ) AS CampaignPromoJSON
    FROM product p
    WHERE 
        (
          SELECT SUM(pcs.Stock)
          FROM product_color_sizes pcs
          INNER JOIN product_colors pc2
            ON pc2.ProductColorID = pcs.ProductColorID
          WHERE pc2.ProductID = p.ProductID
        ) > 0
      AND p.ProductID <> ?
      AND p.CategoryID = ?
      AND p.Price BETWEEN ? AND ?
    ORDER BY p.ProductID DESC
    LIMIT 4
");

// include header
include '../user/header.php';
?>

<style>
    html,
    body {
        max-width: 100%;
        overflow-x: hidden;
    }

    .catalog {
        padding: 16px 0 40px;
        background: #f6f7f9;
    }

    .catalog-title {
        text-align: left;
        margin: 0 0 14px;
        font-size: 22px;
    }

    .catalog-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 70px 52px;
        justify-content: center;
        align-items: start;
    }

    @media (max-width:1200px) {
        .catalog-grid {
            grid-template-columns: repeat(3, 1fr);
        }
    }

    @media (max-width:900px) {
        .catalog-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width:560px) {
        .catalog-grid {
            grid-template-columns: 1fr;
            justify-content: center;
        }
    }

    .catalog-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        margin: 0 auto;
        position: relative;
    }

    .catalog-item.sold-out .catalog-card {
        opacity: 0.9;
    }

    .catalog-card {
        width: 100%;
        background: #fff;
        border-radius: 20px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, .06);
        transition: transform .18s ease, box-shadow .18s ease;
        display: block;
        padding: 18px;
    }

    .catalog-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 28px rgba(0, 0, 0, .08);
    }

    .catalog-img {
        width: 100%;
        aspect-ratio: 1/1;
        object-fit: contain;
        display: block;
    }

    .catalog-name {
        font-size: 18px;
        font-weight: 700;
        margin: 16px 0 8px;
        text-align: center;
        color: #111;
    }

    .catalog-name a {
        color: inherit;
        text-decoration: none;
    }

    .catalog-price {
        font-weight: 600;
        color: #333;
        text-align: center;
        margin: 0;
    }

    .empty {
        text-align: center;
        color: #555;
        margin: 24px 0 10px;
    }

    /* color dots (same as product.php) */
    .color-dots {
        margin-top: 6px;
        display: flex;
        justify-content: center;
        gap: 6px;
    }

    .color-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        border: 1px solid rgba(0, 0, 0, 0.2);
        display: inline-block;
    }

    /* wishlist heart like product.php, used as remove trigger */
    .catalog-heart {
        position: absolute;
        top: 12px;
        right: 10px;
        z-index: 3;
        border: none;
        background: transparent;
        padding: 4px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .catalog-heart .wishlist-icon {
        font-size: 22px;
        line-height: 1;
    }

    .catalog-heart.active .wishlist-icon {
        color: #e63946;
    }

    /* stock badges (same naming as product.php) */
    .stock-badge {
        position: absolute;
        top: 12px;
        left: 10px;
        z-index: 3;
        font-size: 11px;
        padding: 4px 10px;
        border-radius: 999px;
        color: #fff;
        font-weight: 600;
        letter-spacing: 0.03em;
        text-transform: uppercase;
    }

    .stock-badge-out {
        background: #111827;
    }

    .stock-badge-low {
        background: #d97706;
    }

    /* price block (campaign promo) */
    .catalog-price-block {
        margin-top: 4px;
        text-align: center;
    }

    .catalog-price-original {
        font-size: 13px;
        color: #9ca3af;
        text-decoration: line-through;
        margin-bottom: 2px;
    }

    .catalog-price-row {
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .catalog-price-discount {
        font-size: 16px;
        font-weight: 700;
        color: #111827;
    }

    .catalog-price-pill {
        font-size: 11px;
        font-weight: 600;
        padding: 3px 8px;
        border-radius: 999px;
        background: #fee2e2;
        color: #b91c1c;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    /* ===== Global "Explore Similar Designs" section ===== */
    .similar-section-global {
        margin: 60px auto 0;
        max-width: 1200px;
        padding: 0 16px 40px;
    }

    .similar-main-title {
        font-size: 24px;
        font-weight: 700;
        text-align: center;
        margin: 0 0 24px;
    }
    .catalog-card--disabled {
    cursor: default;
    pointer-events: none; /* safety, in case you ever wrap in <a> again */
}

</style>

<main class="account-section" style="padding:40px 0;">
    <div class="container">
        <h2 class="page-title">My Wishlist</h2>

        <?php if (!$items): ?>
            <p>You have no items in your wishlist yet.</p>
        <?php else: ?>

            <?php
            // prepare global similar products (based on first sold-out item found)
            $similarProductsGlobal = [];
            foreach ($items as $tmp) {
                $totStockTmp = isset($tmp['TotalStock']) ? (int)$tmp['TotalStock'] : 0;
                if ($totStockTmp <= 0 && $tmp['Price'] > 0) {
                    $basePid   = (int)$tmp['ProductID'];
                    $baseCat   = $tmp['CategoryID'] ?? null;
                    $minPrice  = max(0, (float)$tmp['Price'] * 0.8);
                    $maxPrice  = (float)$tmp['Price'] * 1.2;

                    $similarStmt->execute([
                        $basePid,    // exclude this product
                        $baseCat,    // same category
                        $minPrice,
                        $maxPrice
                    ]);
                    $similarProductsGlobal = $similarStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    break; // only need one base product
                }
            }
            ?>

            <!-- ===== Wishlist grid ===== -->
            <div class="catalog-grid">
                <?php foreach ($items as $item): ?>
                    <?php
                    $photo = $item['Photo'] ? $item['Photo'] : 'default.jpg';

                    // color dots
                    $colorCodes = [];
                    if (!empty($item['ColorCodeList'])) {
                        $parts = explode(',', $item['ColorCodeList']);
                        foreach ($parts as $code) {
                            $code = trim($code);
                            if ($code === '') continue;
                            if ($code[0] !== '#') $code = '#' . $code;
                            $colorCodes[] = strtolower($code);
                        }
                    }

                    $pid = (int)$item['ProductID'];

                    $totStock = isset($item['TotalStock']) ? (int)$item['TotalStock'] : 0;
                    $minPos   = isset($item['MinPositiveStock']) ? (int)$item['MinPositiveStock'] : null;

                    $isSoldOut   = ($totStock <= 0);
                    $hasLowStock = (!$isSoldOut && $minPos !== null && $minPos > 0 && $minPos < 5);

                    // campaign promo
                    $origPrice = (float)$item['Price'];
                    $promoData = json_decode($item['CampaignPromoJSON'] ?? '', true);

                    $promoPrice = $promoData['FinalPrice'] ?? null;
                    $promoType  = $promoData['Type'] ?? null;
                    $promoValue = $promoData['Value'] ?? null;

                    $hasCampaignPrice = $promoPrice !== null && $promoPrice < $origPrice;
                    ?>
                    <article class="catalog-item<?= $isSoldOut ? ' sold-out' : '' ?>">
                        <!-- Filled heart; click to remove from wishlist -->
                        <button type="button"
                            class="catalog-heart remove-wishlist-btn active"
                            data-product-id="<?= $pid ?>">
                            <span class="wishlist-icon">♥</span>
                        </button>

                        <?php if ($isSoldOut): ?>
                            <div class="stock-badge stock-badge-out">Sold Out</div>
                        <?php elseif ($hasLowStock): ?>
                            <div class="stock-badge stock-badge-low">Low Stock</div>
                        <?php endif; ?>

                        <?php if ($isSoldOut): ?>
                            <!-- Sold out: not clickable -->
                            <div class="catalog-card catalog-card--disabled">
                                <img class="catalog-img"
                                    src="/uploads/<?= h($photo) ?>"
                                    alt="<?= h($item['Name']) ?>">
                            </div>
                        <?php else: ?>
                            <!-- In stock: clickable -->
                            <a class="catalog-card"
                                href="/user/product_detail.php?ProductID=<?= $pid ?>">
                                <img class="catalog-img"
                                    src="/uploads/<?= h($photo) ?>"
                                    alt="<?= h($item['Name']) ?>">
                            </a>
                        <?php endif; ?>

                        <h3 class="catalog-name">
                            <?php if ($isSoldOut): ?>
                                <?= h($item['Name']) ?>
                            <?php else: ?>
                                <a href="/user/product_detail.php?ProductID=<?= $pid ?>">
                                    <?= h($item['Name']) ?>
                                </a>
                            <?php endif; ?>
                        </h3>

                        <?php if ($hasCampaignPrice): ?>
                            <div class="catalog-price-block">
                                <div class="catalog-price-original">
                                    RM <?= number_format($origPrice, 2) ?>
                                </div>
                                <div class="catalog-price-row">
                                    <span class="catalog-price-discount">
                                        RM <?= number_format($promoPrice, 2) ?>
                                    </span>
                                    <span class="catalog-price-pill">
                                        <?php if ($promoType === 'Percentage'): ?>
                                            -<?= rtrim(rtrim(number_format($promoValue, 2), '0'), '.') ?>%
                                        <?php else: ?>
                                            -RM <?= number_format($promoValue, 2) ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="catalog-price">
                                RM <?= number_format($origPrice, 2) ?>
                            </p>
                        <?php endif; ?>

                        <?php if (!empty($colorCodes)): ?>
                            <div class="color-dots">
                                <?php foreach ($colorCodes as $idx => $code): ?>
                                    <?php if ($idx >= 4) break; ?>
                                    <span class="color-dot" style="background: <?= h($code) ?>;"></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>

            <!-- ===== Similar products section (same card CSS) ===== -->
            <?php if (!empty($similarProductsGlobal)): ?>
                <section class="similar-section-global">
                    <h3 class="similar-main-title">Similar Price Range Picks</h3>

                    <div class="catalog-grid">
                        <?php foreach ($similarProductsGlobal as $sp): ?>
                            <?php
                            $spPhoto = $sp['Photo'] ? $sp['Photo'] : 'default.jpg';

                            $simColorCodes = [];
                            if (!empty($sp['ColorCodeList'])) {
                                $spParts = explode(',', $sp['ColorCodeList']);
                                foreach ($spParts as $cc) {
                                    $cc = trim($cc);
                                    if ($cc === '') continue;
                                    if ($cc[0] !== '#') $cc = '#' . $cc;
                                    $simColorCodes[] = strtolower($cc);
                                }
                            }

                            $spid = (int)$sp['ProductID'];

                            $spTotStock = isset($sp['TotalStock']) ? (int)$sp['TotalStock'] : 0;
                            $spMinPos   = isset($sp['MinPositiveStock']) ? (int)$sp['MinPositiveStock'] : null;

                            $spSoldOut   = ($spTotStock <= 0);
                            $spLowStock  = (!$spSoldOut && $spMinPos !== null && $spMinPos > 0 && $spMinPos < 5);

                            $spOrigPrice = (float)$sp['Price'];
                            $spPromoData = json_decode($sp['CampaignPromoJSON'] ?? '', true);

                            $spPromoPrice = $spPromoData['FinalPrice'] ?? null;
                            $spPromoType  = $spPromoData['Type'] ?? null;
                            $spPromoValue = $spPromoData['Value'] ?? null;

                            $spHasCampaignPrice = $spPromoPrice !== null && $spPromoPrice < $spOrigPrice;
                            ?>
                            <article class="catalog-item<?= $spSoldOut ? ' sold-out' : '' ?>">
                                <!-- Static heart (not linked to wishlist toggle here) -->
                                <button type="button" class="catalog-heart">
                                    <span class="wishlist-icon">♡</span>
                                </button>

                                <?php if ($spSoldOut): ?>
                                    <div class="stock-badge stock-badge-out">Sold Out</div>
                                <?php elseif ($spLowStock): ?>
                                    <div class="stock-badge stock-badge-low">Low Stock</div>
                                <?php endif; ?>

                                <?php if ($spSoldOut): ?>
                                    <div class="catalog-card catalog-card--disabled">
                                        <img class="catalog-img"
                                            src="/uploads/<?= h($spPhoto) ?>"
                                            alt="<?= h($sp['Name']) ?>">
                                    </div>
                                <?php else: ?>
                                    <a class="catalog-card"
                                        href="/user/product_detail.php?ProductID=<?= $spid ?>">
                                        <img class="catalog-img"
                                            src="/uploads/<?= h($spPhoto) ?>"
                                            alt="<?= h($sp['Name']) ?>">
                                    </a>
                                <?php endif; ?>

                                <h3 class="catalog-name">
                                    <a href="/user/product_detail.php?ProductID=<?= $spid ?>">
                                        <?= h($sp['Name']) ?>
                                    </a>
                                </h3>

                                <?php if ($spHasCampaignPrice): ?>
                                    <div class="catalog-price-block">
                                        <div class="catalog-price-original">
                                            RM <?= number_format($spOrigPrice, 2) ?>
                                        </div>
                                        <div class="catalog-price-row">
                                            <span class="catalog-price-discount">
                                                RM <?= number_format($spPromoPrice, 2) ?>
                                            </span>
                                            <span class="catalog-price-pill">
                                                <?php if ($spPromoType === 'Percentage'): ?>
                                                    -<?= rtrim(rtrim(number_format($spPromoValue, 2), '0'), '.') ?>%
                                                <?php else: ?>
                                                    -RM <?= number_format($spPromoValue, 2) ?>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <p class="catalog-price">
                                        RM <?= number_format($spOrigPrice, 2) ?>
                                    </p>
                                <?php endif; ?>

                                <?php if (!empty($simColorCodes)): ?>
                                    <div class="color-dots">
                                        <?php foreach ($simColorCodes as $idx => $ccode): ?>
                                            <?php if ($idx >= 4) break; ?>
                                            <span class="color-dot" style="background: <?= h($ccode) ?>;"></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

        <?php endif; ?>

    </div>
</main>

<script>
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.remove-wishlist-btn');
        if (!btn) return;

        const pid = btn.dataset.productId;

        fetch('/user/wishlist_toggle.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    product_id: pid,
                    action: 'remove'
                })
            })
            .then(r => r.json())
            .then(data => {
                console.log('wishlist remove response:', data);
                if (data.ok && data.status === 'removed') {
                    alert('Removed from wishlist.');
                    location.reload();
                } else if (data.error === 'not_logged_in') {
                    window.location.href = '/login.php';
                } else {
                    alert('Failed to remove from wishlist. Please try again.');
                }
            })
            .catch(err => {
                console.error(err);
                alert('Error removing from wishlist.');
            });
    });
</script>

<?php include '../user/footer.php'; ?>