<?php
require __DIR__ . '/../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
include 'header.php';

/* ---------------------------
   FILTER + SORT PARAMETERS
--------------------------- */
$cats       = (array)($_GET['category'] ?? []);
$min_price  = $_GET['min_price'] ?? '';
$max_price  = $_GET['max_price'] ?? '';
$sort       = $_GET['sort'] ?? '';
$gender     = $_GET['gender'] ?? '';          // '' | Female | Male | Unisex
$colors     = (array)($_GET['color'] ?? []);  // multi-select color names

$promoID    = isset($_GET['promo']) ? (int)$_GET['promo'] : 0;
/* ---------------------------
   BUILD FILTER QUERY
--------------------------- */
/*
 * Now we select:
 *  - p.* (product)
 *  - PrimaryImage from product_images
 *  - ColorCodeList from product_colors (comma-separated hex codes)
 */
$sql = "
  SELECT
    p.*,
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
    ) AS PrimaryImage,
    (
      SELECT GROUP_CONCAT(pc.ColorCode ORDER BY pc.IsDefault DESC, pc.ProductColorID ASC SEPARATOR ',')
      FROM product_colors pc
      WHERE pc.ProductID = p.ProductID
    ) AS ColorCodeList,
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
    ) AS CampaignPromoJSON,
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
    ) AS MinPositiveStock
  FROM product p
  WHERE 1
";

$params = [];

/* Category filter – named placeholders (:cat0, :cat1, ...) */
if (!empty($cats)) {
  $hasNull = in_array('NULL', $cats, true);
  $ids     = array_values(array_filter($cats, fn($v) => $v !== 'NULL'));
  $conds   = [];

  if (!empty($ids)) {
    $phs = [];
    foreach ($ids as $idx => $id) {
      $ph        = ":cat$idx";
      $phs[]     = $ph;
      $params[$ph] = $id;
    }
    $conds[] = "p.CategoryID IN (" . implode(',', $phs) . ")";
  }

  if ($hasNull) {
    $conds[] = "(p.CategoryID IS NULL OR p.CategoryID='')";
  }

  if (!empty($conds)) {
    $sql .= ' AND (' . implode(' OR ', $conds) . ')';
  }
}

/* Price range */
if ($min_price !== '') {
  $sql .= " AND p.Price >= :minp";
  $params[':minp'] = (float)$min_price;
}
if ($max_price !== '') {
  $sql .= " AND p.Price <= :maxp";
  $params[':maxp'] = (float)$max_price;
}

/* Gender filter – use TargetGender column */
if ($gender !== '') {               // '' = Any
  $sql .= " AND p.TargetGender = :gender";
  $params[':gender'] = $gender;     // Female, Male, Unisex
}

/* Color filter – match any chosen ColorName via EXISTS */
if (!empty($colors)) {
  $colors = array_values(array_filter($colors, fn($c) => $c !== '')); // clean
  if (!empty($colors)) {
    $colorPhs = [];
    foreach ($colors as $idx => $clr) {
      $ph          = ":clr$idx";
      $colorPhs[]  = $ph;
      $params[$ph] = $clr;
    }

    $sql .= "
      AND EXISTS (
        SELECT 1
        FROM product_colors pc_f
        WHERE pc_f.ProductID = p.ProductID
          AND pc_f.ColorName IN (" . implode(',', $colorPhs) . ")
      )
    ";
  }
}

/* Promotion filter – show only products in a given promotion (if ?promo=ID) */
if ($promoID > 0) {
  $sql .= "
    AND EXISTS (
      SELECT 1
      FROM promotion_products pp_f
      WHERE pp_f.ProductID = p.ProductID
        AND pp_f.PromotionID = :promoID
    )
  ";
  $params[':promoID'] = $promoID;
}

/* Sorting (multiple possible, from sort[] checkboxes) */
$sorts      = (array)$sort;
$orderParts = [];

if (in_array('name_asc',  $sorts, true))  $orderParts[] = 'p.Name ASC';
if (in_array('name_desc', $sorts, true))  $orderParts[] = 'p.Name DESC';
if (in_array('price_asc', $sorts, true))  $orderParts[] = 'p.Price ASC';
if (in_array('price_desc', $sorts, true))  $orderParts[] = 'p.Price DESC';

if (!empty($orderParts)) {
  $sql .= ' ORDER BY ' . implode(', ', $orderParts);
} else {
  $sql .= ' ORDER BY p.ProductID DESC';
}

/* ---------------------------
   FETCH PRODUCTS
--------------------------- */
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------------------------
   USER WISHLIST (for hearts)
--------------------------- */
$wishlistProductIDs = [];
$loggedInUser = $_SESSION['user'] ?? null;

if ($loggedInUser) {
  $uid = (int)$loggedInUser['UserID'];
  $stW = $pdo->prepare("SELECT ProductID FROM wishlist_items WHERE UserID = ?");
  $stW->execute([$uid]);
  $wishlistProductIDs = $stW->fetchAll(PDO::FETCH_COLUMN); // array of ProductID
}

/* ---------------------------
   HELPER FUNCTIONS
--------------------------- */
function h($v)
{
  return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}
function money_rm($n)
{
  return 'RM ' . number_format((float)$n, 2, '.', ',');
}
?>
<link rel="stylesheet" href="/assets/product.css">

<section class="catalog">
  <h3 class="catalog-title">Featured Products</h3>
  <div class="catalog-wrap">
    <?php if ($products): ?>
      <div class="catalog-grid">
        <?php foreach ($products as $row): ?>
          <?php
          // Use PrimaryImage from product_images; fallback to default.jpg
          $fileName  = $row['PrimaryImage'] ?: 'default.jpg';
          $imgPathFS = '../uploads/' . $fileName;
          $imgWeb    = '../uploads/' . $fileName;

          if ($fileName !== 'default.jpg' && !file_exists($imgPathFS)) {
            $imgWeb = '../uploads/default.jpg';
          }

          // Prepare color codes (max 4 dots)
          $colorCodes = [];
          if (!empty($row['ColorCodeList'])) {
            $parts = explode(',', $row['ColorCodeList']);
            foreach ($parts as $code) {
              $code = trim($code);
              if ($code === '') continue;
              if ($code[0] !== '#') $code = '#' . $code;
              $colorCodes[] = strtolower($code);
            }
          }

          $pid = (int)$row['ProductID'];
          $isWished = $loggedInUser && in_array($pid, $wishlistProductIDs);
          $totStock = isset($row['TotalStock']) ? (int)$row['TotalStock'] : null;
          $minPos   = isset($row['MinPositiveStock']) ? (int)$row['MinPositiveStock'] : null;

          $isSoldOut   = ($totStock !== null && $totStock <= 0);
          $hasLowStock = (!$isSoldOut && $minPos !== null && $minPos > 0 && $minPos < 5);
          ?>
          <article class="catalog-item<?= $isSoldOut ? ' sold-out' : '' ?>">
            <!-- wishlist heart in top-right -->
            <button type="button"
              class="catalog-heart<?= $isWished ? ' active' : '' ?>"
              data-product-id="<?= $pid ?>">
              <span class="wishlist-icon"><?= $isWished ? '♥' : '♡' ?></span>
            </button>

            <?php if ($isSoldOut): ?>
              <div class="stock-badge stock-badge-out">Sold Out</div>
            <?php elseif ($hasLowStock): ?>
              <div class="stock-badge stock-badge-low">Low Stock</div>
            <?php endif; ?>
            
            <a class="catalog-card" href="product_detail.php?ProductID=<?= urlencode($row['ProductID']) ?>">
              <img class="catalog-img" src="<?= h($imgWeb) ?>" alt="<?= h($row['Name']) ?>" loading="lazy">
            </a>
            <h3 class="catalog-name">
              <a href="product_detail.php?ProductID=<?= urlencode($row['ProductID']) ?>">
                <?= h($row['Name']) ?>
              </a>
            </h3>
            <?php
            $origPrice = (float)$row['Price'];
            $promoData = json_decode($row['CampaignPromoJSON'] ?? '', true);

            $promoPrice = $promoData['FinalPrice'] ?? null;
            $promoType  = $promoData['Type'] ?? null;
            $promoValue = $promoData['Value'] ?? null;

            $hasCampaignPrice = $promoPrice !== null && $promoPrice < $origPrice;
            ?>

            <?php if ($hasCampaignPrice): ?>
              <div class="catalog-price-block">
                <div class="catalog-price-original">
                  <?= money_rm($origPrice) ?>
                </div>
                <div class="catalog-price-row">
                  <span class="catalog-price-discount">
                    <?= money_rm($promoPrice) ?>
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
              <p class="catalog-price"><?= money_rm($origPrice) ?></p>
            <?php endif; ?>

            <?php if (!empty($colorCodes)): ?>
              <div class="color-dots">
                <?php foreach ($colorCodes as $idx => $code): ?>
                  <?php if ($idx >= 4) break; // show up to 4 dots 
                  ?>
                  <span class="color-dot" style="background: <?= h($code) ?>;"></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="empty">No products match your criteria.</p>
    <?php endif; ?>
  </div>
</section>

<script>
  // Wishlist heart toggle on catalog cards
  document.addEventListener('click', function(e) {
    const btn = e.target.closest('.catalog-heart');
    if (!btn) return;

    const pid = btn.dataset.productId;
    const isActive = btn.classList.contains('active');
    const action = isActive ? 'remove' : 'add';

    fetch('wishlist_toggle.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({
          product_id: pid,
          action: action
        })
      })
      .then(r => r.json())
      .then(data => {
        console.log('catalog wishlist response:', data);
        if (data.ok) {
          const icon = btn.querySelector('.wishlist-icon');
          if (data.status === 'added') {
            btn.classList.add('active');
            if (icon) icon.textContent = '♥';
            alert('Added to wishlist successfully!');
          } else if (data.status === 'removed') {
            btn.classList.remove('active');
            if (icon) icon.textContent = '♡';
            alert('Removed from wishlist.');
          }
        } else if (data.error === 'not_logged_in') {
          // if user not logged in, send to login
          window.location.href = '/login.php';
        }
      })
      .catch(err => {
        console.error(err);
        alert('An error occurred while updating wishlist.');
      });
  });
</script>

<?php include 'footer.php'; ?>