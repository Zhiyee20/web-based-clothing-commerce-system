<?php
// Assumes: session_start() already called, $pdo is a valid PDO.

/* ---------- Helper: safe HTML ---------- */
if (!function_exists('h')) {
    function h($v)
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($pdo->query("SELECT DATABASE()")->fetchColumn() !== 'webassignment') {
    throw new RuntimeException("This page must run on database 'webassignment'.");
}

/* ---------- INFORMATION_SCHEMA helpers ---------- */
function table_exists(PDO $pdo, string $tbl): bool
{
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$tbl]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
function column_exists(PDO $pdo, string $tbl, string $col): bool
{
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$tbl, $col]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/* ---------- Resolve product table & columns ---------- */
$productTable = table_exists($pdo, 'product') ? 'product' : (table_exists($pdo, 'products') ? 'products' : null);
if ($productTable === null) {
    echo "<p style='color:red;font-weight:bold'>Error: No table named <code>product</code> or <code>products</code> found.</p>";
    include '../user/footer.php';
    exit;
}

$idCol    = (function ($pdo, $t) {
    foreach (['ProductID', 'product_id', 'ID', 'id'] as $c) if (column_exists($pdo, $t, $c)) return $c;
    return 'id';
})($pdo, $productTable);
$nameCol  = (function ($pdo, $t) {
    foreach (['Name', 'ProductName', 'Title', 'name', 'title'] as $c) if (column_exists($pdo, $t, $c)) return $c;
    return 'Name';
})($pdo, $productTable);
$priceCol = (function ($pdo, $t) {
    foreach (['Price', 'UnitPrice', 'SellingPrice', 'price', 'unit_price', 'selling_price'] as $c) if (column_exists($pdo, $t, $c)) return $c;
    return null;
})($pdo, $productTable);

/*
 * IMPORTANT CHANGE:
 * We IGNORE any image column on product table and always derive image
 * from product_images + product_colors using the same rule as product.php
 */
$imgCol   = null;

$catCol   = (function ($pdo, $t) {
    foreach (['CategoryID', 'CategoryId', 'category_id', 'Category', 'category'] as $c) if (column_exists($pdo, $t, $c)) return $c;
    return null;
})($pdo, $productTable);

/* ---------- Resolve recommend table & columns ---------- */
$recommendTable = table_exists($pdo, 'recommend') ? 'recommend' : null;

/* ---------- Image resolver (uploads) ---------- */
function resolve_image_src(?string $val): string
{
    $v = trim((string)$val);
    if ($v === '') return '/assets/no-image.png';

    // Already a full URL?
    if (preg_match('~^https?://~i', $v)) return $v;

    // Normalize to a web path (same style as product.php)
    $web = $v[0] === '/' ? $v
        : (stripos($v, '../../uploads/') === 0 ? '/' . $v : '../../uploads/' . $v);

    // Try several absolute locations (works in subfolders, localhost, etc.)
    $candidates = [
        ($_SERVER['DOCUMENT_ROOT'] ?? '') . $web,
        dirname(__DIR__) . $web,
        dirname(__FILE__, 1) . $web,
        realpath(__DIR__ . '/../') . $web,
    ];
    foreach ($candidates as $abs) {
        if ($abs && is_file($abs)) return $web;
    }
    return '/assets/no-image.png';
}

/* ---------- Utilities ---------- */
function product_url($id): string
{
    return '../user/product_detail.php?ProductID=' . urlencode((string)$id);
}

/* ---------- Variant-level in-stock condition ---------- */
function sql_instock_exists(string $productAlias, string $idCol): string
{
    return "EXISTS (
        SELECT 1
        FROM product_colors pc
        JOIN product_color_sizes pcs ON pcs.ProductColorID = pc.ProductColorID
        WHERE pc.ProductID = {$productAlias}.`{$idCol}`
          AND pcs.Stock > 0
    )";
}
/* ORDER for secondary trending tie-breakers */
$trendOrder = 'RAND()';
if (column_exists($pdo, $productTable, 'ViewCount')) {
    $trendOrder = "`$productTable`.`ViewCount` DESC";
} elseif (column_exists($pdo, $productTable, 'SoldCount')) {
    $trendOrder = "`$productTable`.`SoldCount` DESC";
} elseif (column_exists($pdo, $productTable, 'CreatedAt')) {
    $trendOrder = "`$productTable`.`CreatedAt` DESC";
}

/* Does ratings table exist? */
$hasRatings = table_exists($pdo, 'product_ratings') && column_exists($pdo, 'product_ratings', 'ProductID') && column_exists($pdo, 'product_ratings', 'Rating');

/* ---------- Fetch helpers (return rows with id,name,price,image) ---------- */
/**
 * This now always gets `image` from product_images like product.php:
 *  - prefer default color images
 *  - fallback to colorless images
 */
function fetch_by_ids(
    PDO $pdo,
    string $tbl,
    string $idCol,
    string $nameCol,
    ?string $priceCol,
    ?string $imgCol,   // unused, but kept for signature compatibility
    array $ids
): array {
    if (empty($ids)) return [];
    $in  = implode(',', array_fill(0, count($ids), '?'));

    $sel = "`$idCol` AS id, `$nameCol` AS name"
        . ($priceCol ? ", `$priceCol` AS price" : "") . ",
        (
          SELECT pi.ImagePath
          FROM product_images pi
          WHERE pi.ProductID = `$tbl`.`$idCol`
            AND (
              pi.ProductColorID IS NULL
              OR pi.ProductColorID = (
                  SELECT pc.ProductColorID
                  FROM product_colors pc
                  WHERE pc.ProductID = `$tbl`.`$idCol`
                    AND pc.IsDefault = 1
                  LIMIT 1
              )
            )
          ORDER BY pi.IsPrimary DESC, pi.SortOrder ASC, pi.ImageID ASC
          LIMIT 1
        ) AS image";

    $sql = "SELECT $sel FROM `$tbl` WHERE `$idCol` IN ($in)";
    $st  = $pdo->prepare($sql);
    $st->execute($ids);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // Preserve input order
    $map = [];
    foreach ($rows as $r) {
        $map[(int)$r['id']] = $r;
    }
    $out = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if (isset($map[$id])) {
            $out[] = $map[$id];
        }
    }
    return $out;
}

/**
 * Fetch products similar to recently viewed ones, based on dominant category.
 * Image is derived via product_images + product_colors.
 */
function fetch_similar(
    PDO $pdo,
    string $tbl,
    string $idCol,
    string $nameCol,
    ?string $priceCol,
    ?string $imgCol,   // unused
    ?string $catCol,
    array $viewedIds,
    string $trendOrder,
    int $limit = 6
): array {
    if (!$catCol || empty($viewedIds)) return [];

    // Find the most frequent category among viewed products
    $in     = implode(',', array_fill(0, count($viewedIds), '?'));
    $sqlCat = "SELECT `$catCol` AS cat, COUNT(*) AS cnt
             FROM `$tbl`
             WHERE `$idCol` IN ($in) AND `$catCol` IS NOT NULL
             GROUP BY `$catCol`
             ORDER BY cnt DESC
             LIMIT 1";
    $st = $pdo->prepare($sqlCat);
    $st->execute($viewedIds);
    $catRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$catRow || $catRow['cat'] === null || $catRow['cat'] === '') return [];

    $sel = "p.`$idCol` AS id, p.`$nameCol` AS name"
        . ($priceCol ? ", p.`$priceCol` AS price" : "") . ",
        (
          SELECT pi.ImagePath
          FROM product_images pi
          WHERE pi.ProductID = p.`$idCol`
            AND (
              pi.ProductColorID IS NULL
              OR pi.ProductColorID = (
                  SELECT pc.ProductColorID
                  FROM product_colors pc
                  WHERE pc.ProductID = p.`$idCol`
                    AND pc.IsDefault = 1
                  LIMIT 1
              )
            )
          ORDER BY pi.IsPrimary DESC, pi.SortOrder ASC, pi.ImageID ASC
          LIMIT 1
        ) AS image";

    $where  = ["p.`$catCol` = ?"];
    $params = [$catRow['cat']];

    // Exclude out-of-stock items using variant-level stock
    $where[] = sql_instock_exists('p', $idCol);

    // Do not suggest the products that were already viewed
    if (!empty($viewedIds)) {
        $ph = implode(',', array_fill(0, count($viewedIds), '?'));
        $where[] = "p.`$idCol` NOT IN ($ph)";
        $params  = array_merge($params, $viewedIds);
    }

    $sql = "SELECT $sel FROM `$tbl` p";
    if ($where) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }
    $sql .= " ORDER BY $trendOrder LIMIT $limit";

    $st2 = $pdo->prepare($sql);
    $st2->execute($params);
    return $st2->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch trending products ordered by average rating (desc),
 * excluding out-of-stock products and any IDs passed in $excludeIds.
 * Image is derived via product_images + product_colors.
 */
function fetch_trending_by_rating(
    PDO $pdo,
    string $tbl,
    string $idCol,
    string $nameCol,
    ?string $priceCol,
    ?string $imgCol,   // unused
    bool $hasRatings,
    string $trendOrder,
    int $limit,
    array $excludeIds = []
): array {

    $sel = "p.`$idCol` AS id, p.`$nameCol` AS name"
        . ($priceCol ? ", p.`$priceCol` AS price" : "") . ",
        (
          SELECT pi.ImagePath
          FROM product_images pi
          WHERE pi.ProductID = p.`$idCol`
            AND (
              pi.ProductColorID IS NULL
              OR pi.ProductColorID = (
                  SELECT pc.ProductColorID
                  FROM product_colors pc
                  WHERE pc.ProductID = p.`$idCol`
                    AND pc.IsDefault = 1
                  LIMIT 1
              )
            )
          ORDER BY pi.IsPrimary DESC, pi.SortOrder ASC, pi.ImageID ASC
          LIMIT 1
        ) AS image";

    $sql = "SELECT $sel, COALESCE(rs.AvgRating,0) AS AvgRating, COALESCE(rs.RatingCount,0) AS RatingCount
          FROM `$tbl` p";

    if ($hasRatings && table_exists($pdo, 'product_rating_stats')) {
        $sql .= " LEFT JOIN product_rating_stats rs ON rs.ProductID = p.`$idCol`";
    } else {
        $sql .= " LEFT JOIN (SELECT 0 AS ProductID, 0 AS AvgRating, 0 AS RatingCount) rs
              ON rs.ProductID = p.`$idCol`";
    }

    $where  = [];
    $params = [];

    // Only in-stock items using variant-level stock
    $where[] = sql_instock_exists('p', $idCol);

    // Exclude any already picked IDs
    if (!empty($excludeIds)) {
        $ph = implode(',', array_fill(0, count($excludeIds), '?'));
        $where[] = "p.`$idCol` NOT IN ($ph)";
        $params  = array_merge($params, $excludeIds);
    }

    if ($where) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }

    // Order: highest AvgRating first, then your secondary trend order
    $sql .= " ORDER BY rs.AvgRating DESC, $trendOrder LIMIT $limit";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}


/* ---------- Build final suggestions (4 items) with view-history rules ---------- */
$viewedIds = [];
if (!empty($_SESSION['viewed_product_ids']) && is_array($_SESSION['viewed_product_ids'])) {
    // Keep the most recent 20 viewed product IDs (latest first)
    $viewedIds = array_slice(array_values(array_unique(array_map('intval', $_SESSION['viewed_product_ids']))), -20);
    $viewedIds = array_reverse($viewedIds);
}

$prevShown = [];
if (!empty($_SESSION['last_suggestions']) && is_array($_SESSION['last_suggestions'])) {
    $prevShown = array_values(array_unique(array_map('intval', $_SESSION['last_suggestions'])));
}

$finalIds  = [];
$finalRows = [];

// ---------- Case A: No viewed items → 4 trending products by average rating ----------
if (empty($viewedIds)) {
    $rows      = fetch_trending_by_rating($pdo, $productTable, $idCol, $nameCol, $priceCol, $imgCol, $hasRatings, $trendOrder, 4, []);
    $finalRows = array_slice($rows, 0, 4);
    $finalIds  = array_map(static fn($r) => (int)$r['id'], $finalRows);
}

// ---------- Case B: Exactly 1 viewed item → 4 similar from that category ----------
elseif (count($viewedIds) === 1) {
    $similar   = fetch_similar($pdo, $productTable, $idCol, $nameCol, $priceCol, $imgCol, $catCol, $viewedIds, $trendOrder, 4);
    $finalRows = $similar;
    $finalIds  = array_map(static fn($r) => (int)$r['id'], $finalRows);

    // If similar < 4, top-up with trending products (still in-stock only)
    if (count($finalIds) < 4) {
        $need = 4 - count($finalIds);
        $more = fetch_trending_by_rating($pdo, $productTable, $idCol, $nameCol, $priceCol, $imgCol, $hasRatings, $trendOrder, $need, $finalIds);
        foreach ($more as $r) {
            $finalIds[] = (int)$r['id'];
        }
    }

    // Re-fetch rows to preserve the order in $finalIds
    $finalIds  = array_slice(array_values(array_unique($finalIds)), 0, 4);
    $finalRows = fetch_by_ids($pdo, $productTable, $idCol, $nameCol, $priceCol, $imgCol, $finalIds);
}

// ---------- Case C: 2+ viewed items → "carry-over 2" rule ----------
else {
    // Get a pool of similar items from the dominant category
    $similar = fetch_similar($pdo, $productTable, $idCol, $nameCol, $priceCol, $imgCol, $catCol, $viewedIds, $trendOrder, 8);

    // 1) Up to 2 NEW similar products not in the previous suggestions
    $newSimilar = [];
    $prevSet    = array_flip($prevShown);
    foreach ($similar as $row) {
        $rid = (int)$row['id'];
        if (!isset($prevSet[$rid])) {
            $newSimilar[] = $rid;
        }
        if (count($newSimilar) >= 2) break;
    }

    $finalIds = $newSimilar;

    // 2) Keep previous 1st & 2nd suggestions → now become 3rd & 4th (if still available)
    $carry = array_slice($prevShown, 0, 2);
    foreach ($carry as $pid) {
        $pid = (int)$pid;
        if (!in_array($pid, $finalIds, true)) {
            $finalIds[] = $pid;
        }
        if (count($finalIds) >= 4) break;
    }

    // 3) If still less than 4, top-up with trending products by rating
    if (count($finalIds) < 4) {
        $need = 4 - count($finalIds);
        $more = fetch_trending_by_rating($pdo, $productTable, $idCol, $nameCol, $priceCol, $imgCol, $hasRatings, $trendOrder, $need, $finalIds);
        foreach ($more as $r) {
            $finalIds[] = (int)$r['id'];
        }
    }

    // Fetch rows in the order of $finalIds
    $finalIds  = array_slice(array_values(array_unique($finalIds)), 0, 4);
    $finalRows = fetch_by_ids($pdo, $productTable, $idCol, $nameCol, $priceCol, $imgCol, $finalIds);
}

// Remember the last suggestions for the next visit
$_SESSION['last_suggestions'] = $finalIds;

$extraRecommend = [];
if (isset($_SESSION['user']['UserID']) && $recommendTable) {
    $userId = (int)$_SESSION['user']['UserID'];

    // Exclude products already shown in $finalRows
    $excludeIds = array_values(array_unique(array_map('intval', $finalIds ?? [])));
    $excludeSql = '';
    if (!empty($excludeIds)) {
        $excludeSql = ' AND p.ProductID NOT IN (' . implode(',', array_fill(0, count($excludeIds), '?')) . ') ';
    }

    $stmt = $pdo->prepare("
    SELECT p.ProductID AS id,
        p.Name AS name,
        p.Price AS price,
        pi.ImageID AS image_id,
        pi.ImagePath AS image
    FROM recommend r
    JOIN product_images pi ON r.ImageID = pi.ImageID
    JOIN product p ON pi.ProductID = p.ProductID
    WHERE r.UserID = ?
      $excludeSql
      AND EXISTS (
          SELECT 1
          FROM product_colors pc
          JOIN product_color_sizes pcs ON pcs.ProductColorID = pc.ProductColorID
          WHERE pc.ProductID = p.ProductID
            AND pcs.Stock > 0
      )
    LIMIT 4
");
    $params = array_merge([$userId], $excludeIds);
    $stmt->execute($params);
    $extraRecommend = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* STEP 2: top-up if fewer than 4 after exclusion */
    if (count($extraRecommend) < 4) {
        $need = 4 - count($extraRecommend);

        $pickedExtraIds = array_map(
            static fn($r) => (int)$r['id'],
            $extraRecommend
        );

        $excludeAll = array_values(array_unique(array_merge(
            $finalIds ?? [],
            $pickedExtraIds
        )));

        $topup = fetch_trending_by_rating(
            $pdo,
            $productTable,
            $idCol,
            $nameCol,
            $priceCol,
            $imgCol,
            $hasRatings,
            $trendOrder,
            $need,
            $excludeAll
        );

        foreach ($topup as $r) {
            $extraRecommend[] = [
                'id'    => (int)$r['id'],
                'name'  => $r['name'],
                'price' => $r['price'] ?? null,
                'image' => $r['image'] ?? null
            ];
        }
    }
}

?>

<style>
    .trending-section h2 {
        text-align: center;
        font-size: 2.0rem;
        margin: 10px 0 20px;
        font-family: 'Playfair Display', serif;
    }

    .trending-products-container {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 20px;
        padding: 0 20px;
        margin-bottom: 30px;
    }

    .trending-product-item {
        width: 220px;
        border-radius: 10px;
        overflow: hidden;
        background-color: #fff;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        padding: 10px;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
    }

    .trending-product-item img {
        width: 100%;
        height: 220px;
        object-fit: cover;
        border-radius: 8px;
    }

    .trending-product-item h3 {
        font-size: 1rem;
        margin: 10px 0 6px;
        min-height: 2.2em;
        line-height: 1.1em;
    }

    .trending-product-item p {
        margin: 0 0 10px;
        font-weight: 600;
    }

    .discover-collection-btn {
        text-align: center;
        margin: 10px 0 30px;
    }

    .discover-collection-btn .btn {
        display: inline-block;
        padding: 10px 20px;
        background: transparent;
        color: #000;
        text-decoration: none;
        font-size: 16px;
        border: 2px solid #000;
        border-radius: 30px;
        transition: background-color .3s ease, color .3s ease;
        text-align: center;
    }

    .discover-collection-btn .btn:hover {
        background-color: #000;
        color: #fff;
    }

    @media (max-width: 768px) {
        .trending-products-container {
            gap: 14px;
        }

        .trending-product-item {
            width: 45%;
        }

        .trending-product-item img {
            height: 180px;
        }
    }
</style>

<section class="trending-section">
    <h2><?php echo empty($viewedIds) ? 'Trending Now' : 'You May Also Like'; ?></h2>

    <!-- Main trending / similar products -->
    <div class="trending-products-container">
        <?php foreach ($finalRows as $p):
            $imgSrc = resolve_image_src($p['image'] ?? '');
            $priceTxt = isset($p['price']) && $p['price'] !== '' ? 'RM ' . number_format((float)$p['price'], 2) : '';
        ?>
            <div class="trending-product-item">
                <a href="<?php echo h(product_url($p['id'])); ?>">
                    <img src="<?php echo h($imgSrc); ?>" alt="<?php echo h($p['name'] ?? 'Product'); ?>">
                </a>
                <h3><?php echo h($p['name'] ?? 'Product'); ?></h3>
                <?php if ($priceTxt): ?><p><?php echo h($priceTxt); ?></p><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>



    <!-- Extra recommendations -->
    <?php if (!empty($extraRecommend)): ?>
        <div class="trending-products-container">
            <?php foreach ($extraRecommend as $p):
                $imgSrc = resolve_image_src($p['image'] ?? '');
                $priceTxt = isset($p['price']) && $p['price'] !== '' ? 'RM ' . number_format((float)$p['price'], 2) : '';
            ?>
                <div class="trending-product-item">
                    <a href="<?php echo h(product_url($p['id'])); ?>">
                        <img src="<?php echo h($imgSrc); ?>" alt="<?php echo h($p['name'] ?? 'Product'); ?>">
                    </a>
                    <h3><?php echo h($p['name'] ?? 'Product'); ?></h3>
                    <?php if ($priceTxt): ?><p><?php echo h($priceTxt); ?></p><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>


    <div class="discover-collection-btn">
        <a href="../user/product.php" class="btn">Discover the Collection</a>
    </div>
</section>