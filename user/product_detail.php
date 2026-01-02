<?php
require __DIR__ . '/../config.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_GET['ProductID']) || empty($_GET['ProductID'])) {
  echo "<p class='error-msg'>Invalid product!</p>";
  exit;
}

$productID = (int)$_GET['ProductID'];

/* ───────── Fetch product + category ───────── */
$stmt = $pdo->prepare("
    SELECT p.*,
           c.CategoryName,
           c.SizeGuideGroup
    FROM product p
    LEFT JOIN categories c ON c.CategoryID = p.CategoryID
    WHERE p.ProductID = ?
");
$stmt->execute([$productID]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
  echo "<p class='error-msg'>Product not found!</p>";
  exit;
}

/* ───────── NEW SIZE GUIDE SYSTEM ───────── */

/*
  SizeGuideGroup:
  - TOP    → shirt, pullover, jacket, blouson, dress
  - BOTTOM → pants, trackpant
*/
$group = strtoupper($product['SizeGuideGroup'] ?? ''); // TOP / BOTTOM / DRESS

// Dress uses top-body measurements (shoulders/chest/waist/hips)
if ($group === 'DRESS') {
  $group = 'TOP';
}

// Normalise gender from product row (using TargetGender: Male / Female / Unisex)
$rawTargetGender = $product['TargetGender'] ?? 'Unisex';
// e.g. "Male", "Female", "Unisex"
$rawTargetGender = strtolower(trim((string)$rawTargetGender));

// flag for Unisex (used later to show extra image)
$isUnisex = ($rawTargetGender === 'unisex');

if ($rawTargetGender === 'female') {
  $gender = 'female';
} else {
  // Treat Male + Unisex + anything else as male
  $gender = 'male';
}

/* -----------------------------------------
   PART 1 — MEASUREMENT TABLE
   - TOP + male   → SHOULDERS, ARMS, CHEST, WAIST, HIPS
   - TOP + female → CHEST, WAIST, HIPS
   - BOTTOM + male/female → WAIST, HIPS
----------------------------------------- */

$MEASUREMENTS = [

  /* =======  TOP + MALE  (SHOULDERS, ARMS, CHEST, WAIST, HIPS) ======= */
  'TOP_M' => [
    'columns' => ['Shoulders', 'Arms', 'Chest', 'Waist', 'Hips'],
    'rows' => [
      [
        'size'      => 'XS',
        'Shoulders' => '45.5 cm',
        'Arms'      => '87.5 cm',
        'Chest'     => '94.5 cm',
        'Waist'     => '78 cm',
        'Hips'      => '95 cm',
      ],
      [
        'size'      => 'S',
        'Shoulders' => '46.5 cm',
        'Arms'      => '89 cm',
        'Chest'     => '98.5 cm',
        'Waist'     => '82 cm',
        'Hips'      => '99 cm',
      ],
      [
        'size'      => 'M',
        'Shoulders' => '47.5 cm',
        'Arms'      => '90.5 cm',
        'Chest'     => '102.5 cm',
        'Waist'     => '86 cm',
        'Hips'      => '103 cm',
      ],
      [
        'size'      => 'L',
        'Shoulders' => '48.5 cm',
        'Arms'      => '92 cm',
        'Chest'     => '106.5 cm',
        'Waist'     => '90 cm',
        'Hips'      => '107 cm',
      ],
      [
        'size'      => 'XL',
        'Shoulders' => '49.5 cm',
        'Arms'      => '93.5 cm',
        'Chest'     => '110.5 cm',
        'Waist'     => '94 cm',
        'Hips'      => '111 cm',
      ],
    ],
  ],

  /* =======  TOP + FEMALE (BUST, WAIST, HIPS) ======= */
  'TOP_F' => [
    'columns' => ['Bust', 'Waist', 'Hips'],
    'rows' => [
      [
        'size'  => 'XS',
        'Bust' => '82 cm',
        'Waist' => '62 cm',
        'Hips'  => '88 cm',
      ],
      [
        'size'  => 'S',
        'Bust' => '85 cm',
        'Waist' => '65 cm',
        'Hips'  => '91 cm',
      ],
      [
        'size'  => 'M',
        'Bust' => '89 cm',
        'Waist' => '69 cm',
        'Hips'  => '95 cm',
      ],
      [
        'size'  => 'L',
        'Bust' => '93 cm',
        'Waist' => '73 cm',
        'Hips'  => '99 cm',
      ],
      [
        'size'  => 'XL',
        'Bust' => '97 cm',
        'Waist' => '77 cm',
        'Hips'  => '103 cm',
      ],
    ],
  ],

  /* =======  BOTTOM + MALE (WAIST, HIPS) ======= */
  'BOTTOM_M' => [
    'columns' => ['Waist', 'Hips'],
    'rows' => [
      [
        'size'  => 'XS',
        'Waist' => '78 cm',
        'Hips'  => '95 cm',
      ],
      [
        'size'  => 'S',
        'Waist' => '82 cm',
        'Hips'  => '99 cm',
      ],
      [
        'size'  => 'M',
        'Waist' => '86 cm',
        'Hips'  => '103 cm',
      ],
      [
        'size'  => 'L',
        'Waist' => '90 cm',
        'Hips'  => '107 cm',
      ],
      [
        'size'  => 'XL',
        'Waist' => '94 cm',
        'Hips'  => '111 cm',
      ],
    ],
  ],

  /* =======  BOTTOM + FEMALE (WAIST, HIPS) ======= */
  'BOTTOM_F' => [
    'columns' => ['Waist', 'Hips'],
    'rows' => [
      [
        'size'  => 'XS',
        'Waist' => '62 cm',
        'Hips'  => '88 cm',
      ],
      [
        'size'  => 'S',
        'Waist' => '65 cm',
        'Hips'  => '91 cm',
      ],
      [
        'size'  => 'M',
        'Waist' => '69 cm',
        'Hips'  => '95 cm',
      ],
      [
        'size'  => 'L',
        'Waist' => '73 cm',
        'Hips'  => '99 cm',
      ],
      [
        'size'  => 'XL',
        'Waist' => '77 cm',
        'Hips'  => '103 cm',
      ],
    ],
  ],
];

/* -----------------------------------------
   SELECT measurement table for this product
   - SizeGuideGroup: 'TOP' or 'BOTTOM'
   - Gender: 'male' / 'female'
----------------------------------------- */
if ($group === 'TOP' && $gender === 'male') {
  $currentMeasurement = $MEASUREMENTS['TOP_M'];
} elseif ($group === 'TOP' && $gender === 'female') {
  $currentMeasurement = $MEASUREMENTS['TOP_F'];
} elseif ($group === 'BOTTOM' && $gender === 'male') {
  $currentMeasurement = $MEASUREMENTS['BOTTOM_M'];
} elseif ($group === 'BOTTOM' && $gender === 'female') {
  $currentMeasurement = $MEASUREMENTS['BOTTOM_F'];
} else {
  $currentMeasurement = null;
}

/* -----------------------------------------
   PART 2 — HOW TO MEASURE (only 2 types)
----------------------------------------- */
$HOWTO = [
  'male' => [
    'title' => 'How to Measure (Male)',
    'image' => '/uploads/howto_male.jpg',
    'steps' => [
      ['label' => '1. Shoulder width',      'text' => 'Pass the tape measure straight across from the tip of one shoulder to the other, just above your shoulder blades'],
      ['label' => '2. Chest',      'text' => 'Pass the tape measure across your back, under your arms and over your breastbone at its widest point, taking care to keep the tape measure horizontal. It should sit snugly against your body, but should not be pulled too tight.'],
      ['label' => '3. Waist',      'text' => 'Pass the tape measure around your natural waistline, at the narrowest point of your waist. The tape measure should sit snugly against your body, but should not be pulled too tight.'],
      ['label' => '4. Hips',       'text' => 'Pass the tape measure across your hipbone, around the fullest point of your hips.'],
      ['label' => '5. Sleeve Length',      'text' => 'Keeping your arm straight by your side, measure from the tip of your shoulder to the base of your thumb.'],
    ]
  ],
  'female' => [
    'title' => 'How to Measure (Female)',
    'image' => '/uploads/howto_female.jpg',
    'steps' => [
      ['label' => '1. Bust',       'text' => 'Wearing a bra, pass the tape measure straight across your back, under your arms and over the fullest point of your bust.'],
      ['label' => '2. Waist',      'text' => 'Pass the tape measure around your natural waistline, at the narrowest point of your waist. The tape measure should sit snugly against your body, but should not be pulled too tight.'],
      ['label' => '3. Hips',       'text' => 'Pass the tape measure across your hipbone, around the fullest point of your hips.'],
    ]
  ],
];

$currentHowTo = $HOWTO[$gender] ?? null;

/* ───────── Fetch BEST active Campaign promotion for this product ───────── */
$promoRow = null;
try {
  $stPromo = $pdo->prepare("
    SELECT p2.DiscountType, p2.DiscountValue
    FROM promotions p2
    JOIN promotion_products pp2
      ON pp2.PromotionID = p2.PromotionID
    WHERE pp2.ProductID   = ?
      AND p2.PromotionType = 'Campaign'
      AND p2.PromoStatus   = 'Active'
  ");
  $stPromo->execute([$productID]);
  $rowsPromo = $stPromo->fetchAll(PDO::FETCH_ASSOC);

  $bestPct = 0.0;
  $price   = (float)$product['Price'];

  foreach ($rowsPromo as $r) {
    $type = $r['DiscountType'];
    $val  = (float)$r['DiscountValue'];
    if ($val <= 0 || $price <= 0) continue;

    if ($type === 'Percentage') {
      $pct = $val;
    } else {
      $pct = ($val / $price) * 100.0; // convert RM off → %
    }

    if ($pct > $bestPct) {
      $bestPct  = $pct;
      $promoRow = $r;
    }
  }
} catch (Throwable $e) {
  $promoRow = null;
}

/* ───────── Fetch colors (if any) ───────── */
$stColors = $pdo->prepare("
    SELECT ProductColorID, ColorName, ColorCode, IsDefault
    FROM product_colors
    WHERE ProductID = ?
    ORDER BY IsDefault DESC, ProductColorID ASC
");
$stColors->execute([$productID]);
$colors = $stColors->fetchAll(PDO::FETCH_ASSOC);

// Fetch sizes for all colors of this product
$stSizes = $pdo->prepare("
    SELECT pcs.ProductColorID, pcs.Size, pcs.Stock
    FROM product_color_sizes pcs
    INNER JOIN product_colors pc ON pc.ProductColorID = pcs.ProductColorID
    WHERE pc.ProductID = ?
    ORDER BY pc.ProductColorID,
             FIELD(pcs.Size, 'XS','S','M','L','XL')
");
$stSizes->execute([$productID]);
$rows = $stSizes->fetchAll(PDO::FETCH_ASSOC);

$sizesByColor = [];
foreach ($rows as $row) {
  $cid = (int)$row['ProductColorID'];
  if (!isset($sizesByColor[$cid])) {
    $sizesByColor[$cid] = [];
  }
  $sizesByColor[$cid][] = [
    'size'  => $row['Size'],
    'stock' => (int)$row['Stock'],
  ];
}
// Re-key by string for JS (match colorKey used in galleryData)
$sizesByColorKey = [];
foreach ($sizesByColor as $cid => $list) {
  $sizesByColorKey[(string)$cid] = $list;
}

/* ───────── Fetch images grouped by color ───────── */
$stImg = $pdo->prepare("
    SELECT ImageID, ProductColorID, ImagePath, IsPrimary, SortOrder
    FROM product_images
    WHERE ProductID = ?
    ORDER BY ProductColorID, IsPrimary DESC, SortOrder ASC, ImageID ASC
");
$stImg->execute([$productID]);
$rawImages = $stImg->fetchAll(PDO::FETCH_ASSOC);

/* ───────── Build gallery data (PHP side) ───────── */
$uploadsBase = __DIR__ . '/../uploads/';
$uploadsUrl  = '/uploads/';

$colorNamesById = [];
foreach ($colors as $c) {
  $cid = (int)$c['ProductColorID'];
  $colorNamesById[$cid] = $c['ColorName'];
}

/** Helper to resolve physical path → URL or default */
function resolve_image_url(string $file, string $basePath, string $baseUrl): string
{
  $file = ltrim($file, '/');
  if ($file === '') return $baseUrl . 'default.jpg';
  $full = rtrim($basePath, '/\\') . '/' . $file;
  if (file_exists($full)) {
    return $baseUrl . $file;
  }
  return $baseUrl . 'default.jpg';
}

// Group images by color key ('0' for NULL / generic)
$imagesByColor = [];
foreach ($rawImages as $img) {
  $cid     = $img['ProductColorID'];
  $key     = ($cid === null) ? '0' : (string)(int)$cid;
  $imagesByColor[$key] = $imagesByColor[$key] ?? [];
  $imagesByColor[$key][] = [
    'ImagePath'  => $img['ImagePath'],
    'IsPrimary'  => (bool)$img['IsPrimary'],
    'SortOrder'  => (int)$img['SortOrder'],
  ];
}

// Determine default/active color key
$defaultColorID = null;
if ($colors) {
  foreach ($colors as $c) {
    if ((int)$c['IsDefault'] === 1) {
      $defaultColorID = (int)$c['ProductColorID'];
      break;
    }
  }
  if ($defaultColorID === null) {
    $defaultColorID = (int)$colors[0]['ProductColorID'];
  }
}
$activeColorKey = ($defaultColorID !== null) ? (string)$defaultColorID : '0';

// Choose initial image
$initialImgSrc = $uploadsUrl . 'default.jpg';
if (!empty($imagesByColor[$activeColorKey])) {
  $activeSet = $imagesByColor[$activeColorKey];
  $primary   = null;
  foreach ($activeSet as $img) {
    if ($img['IsPrimary']) {
      $primary = $img;
      break;
    }
  }
  if ($primary === null) {
    $primary = $activeSet[0];
  }
  $initialImgSrc = resolve_image_url($primary['ImagePath'], $uploadsBase, $uploadsUrl);
} elseif (!empty($imagesByColor)) {
  $firstKey = array_key_first($imagesByColor);
  $activeColorKey = $firstKey;
  $activeSet = $imagesByColor[$firstKey];
  $primary   = null;
  foreach ($activeSet as $img) {
    if ($img['IsPrimary']) {
      $primary = $img;
      break;
    }
  }
  if ($primary === null) {
    $primary = $activeSet[0];
  }
  $initialImgSrc = resolve_image_url($primary['ImagePath'], $uploadsBase, $uploadsUrl);
}

// Build JS-friendly galleryData
$galleryData = [
  'productName'    => $product['Name'],
  'activeColorKey' => $activeColorKey,
  'images'         => [],
  'colorIds'       => [],
  'colorNames'     => [],
];

foreach ($imagesByColor as $key => $imgList) {
  $cid = ($key === '0') ? null : (int)$key;
  $cName = $cid !== null && isset($colorNamesById[$cid]) ? $colorNamesById[$cid] : '';

  $galleryData['colorIds'][$key]   = $cid;
  $galleryData['colorNames'][$key] = $cName;
  $galleryData['images'][$key]     = [];

  foreach ($imgList as $img) {
    $src = resolve_image_url($img['ImagePath'], $uploadsBase, $uploadsUrl);
    $galleryData['images'][$key][] = [
      'src'       => $src,
      'alt'       => trim($product['Name'] . ' ' . $cName),
      'isPrimary' => $img['IsPrimary'],
    ];
  }
}

// if absolutely no images
if (empty($galleryData['images'])) {
  $galleryData['images']['0'] = [[
    'src'       => $uploadsUrl . 'default.jpg',
    'alt'       => $product['Name'],
    'isPrimary' => true,
  ]];
  $galleryData['colorIds']['0']   = null;
  $galleryData['colorNames']['0'] = '';
  $galleryData['activeColorKey']  = '0';
  $initialImgSrc                  = $uploadsUrl . 'default.jpg';
}

/* ───────── Track viewed product ───────── */
if (session_status() === PHP_SESSION_NONE) session_start();

$_SESSION['viewed_product_ids'] = $_SESSION['viewed_product_ids'] ?? [];
$pid = (int)$product['ProductID'];
$_SESSION['viewed_product_ids'] = array_values(array_diff($_SESSION['viewed_product_ids'], [$pid]));
$_SESSION['viewed_product_ids'][] = $pid;
if (count($_SESSION['viewed_product_ids']) > 50) {
  $_SESSION['viewed_product_ids'] = array_slice($_SESSION['viewed_product_ids'], -50);
}

/* ───────── Rating summary ───────── */
$avgRating = null;
$ratingCount = 0;
$stR = $pdo->prepare("
    SELECT AVG(Rating) AS AvgRating, COUNT(*) AS RatingCount
    FROM product_ratings
    WHERE ProductID = ?
");
$stR->execute([$productID]);
if ($row = $stR->fetch(PDO::FETCH_ASSOC)) {
  $avgRating   = $row['AvgRating'] !== null ? (float)$row['AvgRating'] : null;
  $ratingCount = (int)$row['RatingCount'];
}


/* ───────── Reviews ───────── */
$reviews = [];
$stV = $pdo->prepare("
    SELECT rv.Comment,
           rv.CreatedAt,
           u.Username,
           pr.Rating AS UserRating
    FROM product_reviews rv
    JOIN `user` u ON u.UserID = rv.UserID
    LEFT JOIN product_ratings pr
           ON pr.ProductID = rv.ProductID AND pr.UserID = rv.UserID
    WHERE rv.ProductID = ?
    ORDER BY rv.CreatedAt DESC
    LIMIT 10
");
$stV->execute([$productID]);
$reviews = $stV->fetchAll(PDO::FETCH_ASSOC);

/* ───────── WISHLIST: current + list for similar ───────── */
$loggedInUser = $_SESSION['user'] ?? null;
$isWished = false;
$wishlistProductIDs = [];

if ($loggedInUser) {
  $userID = (int)$loggedInUser['UserID'];
  try {
    // Get all wished ProductID for this user
    $stW = $pdo->prepare("SELECT ProductID FROM wishlist_items WHERE UserID = ?");
    $stW->execute([$userID]);
    $wishlistProductIDs = $stW->fetchAll(PDO::FETCH_COLUMN);

    $isWished = in_array($productID, $wishlistProductIDs);
  } catch (Throwable $e) {
    $isWished = false;
    $wishlistProductIDs = [];
  }
}

/* ───────── Something similar (same category) ───────── */
$similarProducts = [];
if (!empty($product['CategoryID'])) {
  try {
    $stSim = $pdo->prepare("
      SELECT 
        p.ProductID,
        p.Name,
        p.Price,
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
        ) AS ColorCodeList
      FROM product p
      WHERE p.CategoryID = ?
        AND p.ProductID <> ?
      ORDER BY p.ProductID DESC
      LIMIT 4
    ");
    $stSim->execute([$product['CategoryID'], $productID]);
    $simRows = $stSim->fetchAll(PDO::FETCH_ASSOC);

    foreach ($simRows as $r) {
      $imgFile = $r['PrimaryImage'] ?? '';
      $r['DisplayImage'] = resolve_image_url((string)$imgFile, $uploadsBase, $uploadsUrl);
      $similarProducts[] = $r;
    }
  } catch (Throwable $e) {
    $similarProducts = [];
  }
}

include 'header.php';
?>
<link rel="stylesheet" href="/assets/product_detail.css">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet">

<style>
  .pd-gallery {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .pd-main-img img {
    width: 100%;
    display: block;
    border-radius: 12px;
  }

  .pd-thumbs {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  .pd-thumbs img {
    width: 64px;
    height: 64px;
    object-fit: cover;
    cursor: pointer;
    border-radius: 6px;
    border: 1px solid #ddd;
    transition: transform .15s, box-shadow .15s, border-color .15s;
  }

  .pd-thumbs img.active {
    border-color: #111;
    box-shadow: 0 0 0 2px rgba(0, 0, 0, .5);
    transform: translateY(-1px);
  }

  .pd-color-section {
    margin-top: 12px;
  }

  /* flex row: left = label+colors, right = heart */
  .pd-color-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
  }

  .pd-color-left {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .pd-color-label {
    font-size: 0.9rem;
    font-weight: 600;
  }

  .pd-color-options {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
  }

  .pd-color-options .color-dot {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    border: 1px solid #ccc;
    padding: 0;
    background: #fff;
    cursor: pointer;
    outline: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: transform .15s, box-shadow .15s, border-color .15s;
  }

  .pd-color-options .color-dot.active {
    border-color: #111;
    box-shadow: 0 0 0 2px rgba(0, 0, 0, .5);
    transform: translateY(-1px);
  }

  /* wishlist heart (main product) */
  .wishlist-heart-btn {
    border: none;
    background: transparent;
    padding: 6px 8px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }

  .wishlist-heart-btn .wishlist-icon {
    font-size: 28px;
    line-height: 1;
  }

  /* price styles */
  .pd-price {
    font-size: 1.3rem;
    font-weight: 700;
    margin: 18px 0 10px;
  }

  .pd-price-block {
    margin: 18px 0 10px;
  }

  .pd-price-original {
    font-size: 0.9rem;
    color: #9ca3af;
    text-decoration: line-through;
    margin-bottom: 2px;
  }

  .pd-price-row {
    display: inline-flex;
    align-items: center;
    gap: 8px;
  }

  .pd-price-discount {
    font-size: 1.3rem;
    font-weight: 700;
    color: #cb5151ff;
  }

  .pd-price-pill {
    font-size: 0.72rem;
    letter-spacing: .16em;
    text-transform: uppercase;
    padding: 3px 7px;
    border-radius: 5px;
    background: transparent;
    color: #cb5151ff;
    border: 1px solid #cb5151ff;
  }

  /* Something similar section wrapper */
  .pd-similar {
    margin-top: 48px;
    padding-top: 32px;
    border-top: 1px solid #eee;
  }

  .pd-similar-title {
    font-size: 1.3rem;
    font-weight: 600;
    margin-bottom: 18px;
    font-family: 'Playfair Display', serif;
  }

  /* ===== Copy of product card styles from product.php ===== */
  .catalog-wrap {
    max-width: 1280px;
    margin: 0 auto;
    padding: 0 16px;
  }

  .catalog-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 70px 52px;
    margin: 30px;
    justify-content: center;
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
    /* for heart positioning */
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

  /* color dots under price */
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

  /* wishlist heart on product card */
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

  .pd-price-inline {
    display: flex;
    align-items: center;
    gap: 14px;
    margin: 18px 0 10px;
  }

  .pd-price-original-inline {
    font-size: 1rem;
    color: #9ca3af;
    text-decoration: line-through;
  }

  .pd-price-discount-inline {
    font-size: 1.3rem;
    font-weight: 700;
    color: #cb5151ff;
  }

  .pd-price-pill-inline {
    font-size: 0.72rem;
    letter-spacing: .16em;
    text-transform: uppercase;
    padding: 3px 7px;
    border-radius: 5px;
    background: transparent;
    color: #cb5151ff;
    border: 1px solid #cb5151ff;
  }

  .pd-size-section {
    margin-top: 8px;
  }

  .pd-size-label {
    font-size: 0.9rem;
    font-weight: 600;
    margin-bottom: 6px;
  }

  .pd-size-options {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .size-btn {
    min-width: 40px;
    padding: 6px 10px;
    border-radius: 20px;
    border: 1px solid #d1d5db;
    background: #ffffff;
    font-size: 0.85rem;
    cursor: pointer;
    transition: background 0.15s, border-color 0.15s, transform 0.15s, box-shadow 0.15s;
  }

  .size-btn.selected {
    border-color: #111827;
    box-shadow: 0 0 0 1px #111827;
    transform: translateY(-1px);
  }

  .size-btn.disabled {
    opacity: 0.4;
    cursor: not-allowed;
    text-decoration: line-through;
  }

  /* Remove default numbers for "How to Measure" list */
  .sg-howto-list {
    list-style: none;
    /* hide 1,2,3 in the margin */
    margin: 0;
    padding-left: 0;
  }

  .sg-howto-list li {
    margin-bottom: 8px;
  }

  /* How-to images (main + extra for unisex) */
  .sg-howto-figure img {
    max-width: 540px;
    width: 100%;
    display: block;
  }

  /* space between first and second image */
  .sg-howto-figure img+img {
    margin-top: 12px;
  }

  .pd-size-note {
    margin-top: 10px;
    font-size: 0.85rem;
    color: #6b7280;
  }

  .pd-size-stock-badge {
    margin-top: 6px;
    font-size: 0.85rem;
    font-weight: 600;
    color: #b91c1c;
    /* elegant urgent red */
  }

  /* Low stock badge beside Back button (per color) */
  .pd-lowstock-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 999px;
    border: 1px solid #b45309;
    background: #fef3c7;
    color: #92400e;
    font-size: 0.78rem;
    letter-spacing: .12em;
    text-transform: uppercase;
    margin-left: 12px;
    white-space: nowrap;
  }
</style>

<?php
function h($v)
{
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function starInlineStyle($score)
{
  $pct = max(0, min(5, (float)$score)) / 5 * 100;
  return "--score: {$pct}%;";
}
function money_rm($n)
{
  return 'RM ' . number_format((float)$n, 2, '.', ',');
}
?>

<main class="pd-wrap">
  <section class="pd-grid">
    <!-- Left: Product Gallery -->
    <div class="pd-media">
      <div class="pd-gallery">
        <div class="pd-main-img">
          <img id="pdMainImage"
            src="<?= h($initialImgSrc) ?>"
            alt="<?= h($product['Name']) ?>">
        </div>
        <div class="pd-thumbs" id="pdThumbs"></div>
      </div>
    </div>

    <!-- Right: Info Panel -->
    <div class="pd-info">
      <h1 class="pd-title"><?= h($product['Name']) ?></h1>

      <!-- Rating row -->
      <div class="rating-row">
        <?php if ($avgRating !== null && $ratingCount > 0): ?>

          <div class="stars"
            style="<?= starInlineStyle($avgRating) ?>"
            aria-label="<?= number_format($avgRating, 1) ?> out of 5">
            ★★★★★
          </div>

          <span class="rating-num"><?= number_format($avgRating, 1) ?>/5</span>
          <span class="rating-count">(<?= (int)$ratingCount ?> ratings)</span>

        <?php else: ?>
          <span class="rating-empty">No ratings yet</span>
        <?php endif; ?>
      </div>

      <!-- Color selector + wishlist heart -->
      <?php if ($colors): ?>
        <div class="pd-color-section">
          <div class="pd-color-row">
            <div class="pd-color-left">
              <div class="pd-color-label">Color:</div>
              <div class="pd-color-options">
                <?php foreach ($colors as $c): ?>
                  <?php
                  $cid      = (int)$c['ProductColorID'];
                  $key      = (string)$cid;
                  $isActive = ($key === $galleryData['activeColorKey']);

                  $rawName  = $c['ColorName'];
                  $codeRaw  = $c['ColorCode'] ?? '';
                  $codeRaw  = trim((string)$codeRaw);

                  if ($codeRaw === '') {
                    $colorCode = '#d9d9d9';
                  } else {
                    $colorCode = ($codeRaw[0] === '#') ? $codeRaw : '#' . $codeRaw;
                  }
                  ?>
                  <button type="button"
                    class="color-dot<?= $isActive ? ' active' : '' ?>"
                    data-color-id="<?= $cid ?>"
                    data-color-name="<?= h($rawName) ?>"
                    data-color-code="<?= h($colorCode) ?>"
                    style="background: <?= h($colorCode) ?>;"
                    title="<?= h($rawName) ?>"
                    aria-label="<?= h($rawName) ?>">
                  </button>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- wishlist heart at rightmost side (main product) -->
            <button type="button"
              class="wishlist-heart-btn<?= $isWished ? ' active' : '' ?>"
              id="wishlistHeartBtn"
              data-product-id="<?= (int)$productID ?>">
              <span class="wishlist-icon"><?= $isWished ? '♥' : '♡' ?></span>
            </button>
          </div>
        </div>
      <?php endif; ?>

      <!-- Price (with Campaign discount if any) -->
      <?php
      $origPrice   = (float)$product['Price'];
      $promoPrice  = null;
      $promoType   = null;
      $promoValue  = null;

      if ($promoRow) {
        $promoType  = $promoRow['DiscountType'];
        $promoValue = (float)$promoRow['DiscountValue'];

        if ($promoType === 'Percentage' && $promoValue > 0) {
          $promoPrice = round($origPrice * (1 - $promoValue / 100), 2);
        } elseif ($promoValue > 0) {
          $promoPrice = max(round($origPrice - $promoValue, 2), 0);
        }
      }

      $hasCampaignPrice = $promoPrice !== null && $promoPrice < $origPrice;
      ?>

      <?php if ($hasCampaignPrice): ?>
        <div class="pd-price-inline">
          <span class="pd-price-original-inline"><?= money_rm($origPrice) ?></span>

          <span class="pd-price-discount-inline"><?= money_rm($promoPrice) ?></span>

          <span class="pd-price-pill-inline">
            <?php if ($promoType === 'Percentage'): ?>
              -<?= rtrim(rtrim(number_format($promoValue, 2), '0'), '.') ?>%
            <?php else: ?>
              -RM <?= number_format($promoValue, 2) ?>
            <?php endif; ?>
          </span>
        </div>
      <?php else: ?>
        <div class="pd-price"><?= money_rm($origPrice) ?></div>
      <?php endif; ?>

      <!-- Actions -->
      <div class="pd-cta secondary">
        <a id="addToCartLink"
          href="cart_process.php?action=add&ProductID=<?= urlencode((string)$productID) ?>"
          class="btn btn-add">
          Add to Cart
        </a>

        <a href="javascript:void(0);" class="btn btn-link" onclick="goBack()">Back</a>

        <!-- Color-level low stock badge (shown only when this color is low stock) -->
        <span id="pdLowStockBadge" class="pd-lowstock-badge" style="display:none;">
          Low Stock
        </span>
      </div>

      <!-- Tabs -->
      <div class="pd-tabs">
        <input type="radio" id="tab-desc" name="tabs" checked>
        <label for="tab-desc">Description</label>

        <input type="radio" id="tab-size" name="tabs">
        <label for="tab-size">Size & Fit</label>

        <input type="radio" id="tab-reviews" name="tabs">
        <label for="tab-reviews">Customer Reviews</label>

        <div class="tab-panels">
          <div class="tab-panel" data-for="tab-desc">
            <p class="pd-desc"><?= nl2br(h((string)$product['Description'])) ?></p>
          </div>

          <div class="tab-panel" data-for="tab-size">
            <div class="tab-panel" data-for="tab-size">
              <div class="pd-size-section">
                <div class="pd-size-label">Size:</div>
                <div class="pd-size-options" id="pdSizeOptions">
                  <!-- JS will populate XS–XL buttons per color -->
                </div>

                <!-- LOW STOCK message for selected size -->
                <p id="pdSizeStockMsg" class="pd-size-stock-badge" style="display:none;"></p>

                <p class="pd-size-note">
                  Not sure about your size? Click <strong>Size guide</strong> below to see detailed measurements and how to measure.
                </p>

                <?php if ($currentMeasurement): ?>
                  <button type="button" class="size-guide-toggle" id="sizeGuideToggle">
                    Size guide ▾
                  </button>

                  <div class="size-guide-panel" id="sizeGuidePanel" hidden>

                    <!-- Measurement Table -->
                    <h4 class="sg-heading">Measurement</h4>

                    <div class="sg-table-wrap">
                      <table class="sg-table">
                        <thead>
                          <tr>
                            <th>Size</th>
                            <?php foreach ($currentMeasurement['columns'] as $col): ?>
                              <th><?= h($col) ?></th>
                            <?php endforeach; ?>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($currentMeasurement['rows'] as $row): ?>
                            <tr>
                              <td><?= h($row['size']) ?></td>
                              <?php foreach ($currentMeasurement['columns'] as $col): ?>
                                <td><?= h($row[$col]) ?></td>
                              <?php endforeach; ?>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>

                    <!-- How to Measure -->
                    <?php if ($currentHowTo): ?>
                      <div class="sg-howto">
                        <h4>
                          <?php if (!empty($isUnisex) && $isUnisex): ?>
                            How to Measure (Unisex)
                          <?php else: ?>
                            <?= h($currentHowTo['title']) ?>
                          <?php endif; ?>
                        </h4>

                        <div class="sg-howto-layout">
                          <div class="sg-howto-figure">
                            <img src="<?= h($currentHowTo['image']) ?>" alt="How to measure">

                            <?php if (!empty($isUnisex) && $isUnisex): ?>
                              <img src="/uploads/howto_unisex.jpg"
                                alt="Unisex fit reference">
                            <?php endif; ?>
                          </div>

                          <ol class="sg-howto-list">
                            <?php foreach ($currentHowTo['steps'] as $step): ?>
                              <li>
                                <strong><?= h($step['label']) ?></strong><br>
                                <span><?= h($step['text']) ?></span>
                              </li>
                            <?php endforeach; ?>
                          </ol>
                        </div>

                      </div>
                    <?php endif; ?>

                  </div>
                <?php endif; ?>

              </div>
            </div>
          </div>

          <div class="tab-panel" data-for="tab-reviews">
            <?php if (!$reviews): ?>
              <p class="muted">No reviews yet.</p>
            <?php else: ?>
              <div class="reviews-wrap">
                <?php foreach ($reviews as $rv):
                  $dt = date('M j, Y g:i A', strtotime((string)$rv['CreatedAt']));
                  $ur = $rv['UserRating'] !== null ? (float)$rv['UserRating'] : null;
                ?>
                  <article class="review-card">
                    <header class="review-head">
                      <strong class="review-user"><?= h($rv['Username']) ?></strong>

                      <?php if ($ur !== null): ?>
                        <div class="review-rating">
                          <div class="stars stars-sm"
                            style="<?= starInlineStyle($ur) ?>"
                            aria-label="<?= number_format($ur, 1) ?> out of 5">
                            ★★★★★
                          </div>
                        </div>
                      <?php endif; ?>



                      <time class="review-time"><?= h($dt) ?></time>
                    </header>

                    <p class="review-text"><?= nl2br(h($rv['Comment'])) ?></p>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </section>

  <?php if (!empty($similarProducts)): ?>
    <section class="pd-similar">
      <h2 class="pd-similar-title">Explore Similar Designs</h2>
      <div class="catalog-wrap">
        <div class="catalog-grid">
          <?php foreach ($similarProducts as $sp): ?>
            <?php
            $spID   = (int)$sp['ProductID'];
            $imgWeb = $sp['DisplayImage'];

            // Prepare color codes (max 4 dots) – same as product.php
            $colorCodes = [];
            if (!empty($sp['ColorCodeList'])) {
              $parts = explode(',', $sp['ColorCodeList']);
              foreach ($parts as $code) {
                $code = trim($code);
                if ($code === '') continue;
                if ($code[0] !== '#') $code = '#' . $code;
                $colorCodes[] = strtolower($code);
              }
            }

            $spIsWished = $loggedInUser && in_array($spID, $wishlistProductIDs);
            ?>
            <article class="catalog-item">
              <!-- wishlist heart same style as product.php -->
              <button type="button"
                class="catalog-heart<?= $spIsWished ? ' active' : '' ?>"
                data-product-id="<?= $spID ?>">
                <span class="wishlist-icon"><?= $spIsWished ? '♥' : '♡' ?></span>
              </button>

              <a class="catalog-card" href="product_detail.php?ProductID=<?= urlencode($spID) ?>">
                <img class="catalog-img" src="<?= h($imgWeb) ?>" alt="<?= h($sp['Name']) ?>" loading="lazy">
              </a>
              <h3 class="catalog-name">
                <a href="product_detail.php?ProductID=<?= urlencode($spID) ?>">
                  <?= h($sp['Name']) ?>
                </a>
              </h3>
              <p class="catalog-price"><?= money_rm($sp['Price']) ?></p>

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
      </div>
    </section>
  <?php endif; ?>
</main>

<script>
  function goBack() {
    if (document.referrer && document.referrer !== window.location.href) {
      window.location.href = document.referrer;
    } else {
      window.location.href = '/index.php#shop';
    }
  }

  // ===== Gallery + Color + Size selection + Add-to-cart params =====
  (function() {
    const gallery = <?= json_encode($galleryData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const sizeData = <?= json_encode($sizesByColorKey, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    const mainImg = document.getElementById('pdMainImage');
    const thumbsWrap = document.getElementById('pdThumbs');
    const colorButtons = document.querySelectorAll('.pd-color-options .color-dot');
    const addToCartLink = document.getElementById('addToCartLink');
    const sizeOptionsWrap = document.getElementById('pdSizeOptions');
    const sizeStockMsg = document.getElementById('pdSizeStockMsg');
    const colorLowStockBadge = document.getElementById('pdLowStockBadge'); // NEW

    let activeColorKey = gallery.activeColorKey || '0';
    let selectedSize = null; // MUST be chosen by user

    // Update cart_process.php URL with ColorName & Size
    function updateAddToCart(colorKey) {
      if (!addToCartLink) return;

      const ck = colorKey || activeColorKey || '0';
      const colorName = (gallery.colorNames && gallery.colorNames[ck]) || '';

      try {
        const url = new URL(addToCartLink.href, window.location.origin);

        // Always sync chosen color
        if (colorName) {
          url.searchParams.set('ColorName', colorName);
        } else {
          url.searchParams.delete('ColorName');
        }

        // Only send Size if user has chosen one
        if (selectedSize) {
          url.searchParams.set('Size', selectedSize);
        } else {
          url.searchParams.delete('Size');
        }

        addToCartLink.href = url.toString();
      } catch (e) {
        console.error('updateAddToCart error', e);
      }
    }

    // Show / hide "Low Stock" badge based on all sizes for this color
    function updateColorLowStockBadge(colorKey) {
      if (!colorLowStockBadge) return;

      const ck = colorKey || activeColorKey || '0';
      const list = sizeData[ck] || [];

      let hasStock = false;
      let hasLowStock = false;

      list.forEach(row => {
        const s = Number(row.stock);
        if (s > 0) {
          hasStock = true;
          if (s < 5) {
            hasLowStock = true;
          }
        }
      });

      // Show badge only when this color has at least one size with stock 1–4
      if (hasStock && hasLowStock) {
        colorLowStockBadge.style.display = 'inline-flex';
      } else {
        colorLowStockBadge.style.display = 'none';
      }
    }

    function renderSizes(colorKey) {
      if (!sizeOptionsWrap) return;

      const ck = colorKey || activeColorKey || '0';
      const list = sizeData[ck] || [];

      // When color changes, user must re-choose size
      selectedSize = null;
      sizeOptionsWrap.innerHTML = '';

      // Reset size-level "Only X Left!" message whenever sizes are re-rendered
      if (sizeStockMsg) {
        sizeStockMsg.textContent = '';
        sizeStockMsg.style.display = 'none';
      }

      if (!list.length) {
        updateAddToCart(ck);
        // No sizes -> no low stock badge
        updateColorLowStockBadge(ck);
        return;
      }

      list.forEach(row => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'size-btn';
        btn.textContent = row.size;
        btn.dataset.size = row.size;
        btn.dataset.stock = row.stock;

        if (row.stock <= 0) {
          btn.classList.add('disabled');
          btn.disabled = true;
        }

        btn.addEventListener('click', () => {
          if (row.stock <= 0) return;

          // Clear previous selection
          sizeOptionsWrap.querySelectorAll('.size-btn.selected')
            .forEach(el => el.classList.remove('selected'));

          btn.classList.add('selected');
          selectedSize = row.size;

          // Show / hide "Only X Left!" for this size
          if (sizeStockMsg) {
            if (row.stock > 0 && row.stock < 5) {
              sizeStockMsg.textContent = 'Only ' + row.stock + ' Left!';
              sizeStockMsg.style.display = 'block';
            } else {
              sizeStockMsg.textContent = '';
              sizeStockMsg.style.display = 'none';
            }
          }

          // Now we can safely update Add-to-Cart with Size
          updateAddToCart(ck);
        });

        sizeOptionsWrap.appendChild(btn);
      });

      // After rendering sizes (with no selection yet), still update color in URL
      updateAddToCart(ck);

      // Update color-level "Low Stock" badge based on all sizes for this color
      updateColorLowStockBadge(ck);
    }

    function renderGallery(colorKey) {
      const ck = colorKey || activeColorKey || '0';
      const imgs = (gallery.images && gallery.images[ck]) || [];

      activeColorKey = ck;

      if (!imgs.length) {
        // Still keep color + size in sync even if no images
        renderSizes(ck);
        return;
      }

      const first = imgs.find(i => i.isPrimary) || imgs[0];

      if (mainImg) {
        mainImg.src = first.src;
        mainImg.alt = first.alt || gallery.productName || '';
        mainImg.dataset.colorKey = ck;
      }

      if (thumbsWrap) {
        thumbsWrap.innerHTML = '';
        imgs.forEach((img, idx) => {
          const t = document.createElement('img');
          t.src = img.src;
          t.alt = img.alt || '';
          t.className = 'pd-thumb' + (idx === 0 ? ' active' : '');
          t.dataset.colorKey = ck;
          t.dataset.index = String(idx);

          t.addEventListener('click', function() {
            thumbsWrap.querySelectorAll('.pd-thumb.active')
              .forEach(el => el.classList.remove('active'));
            t.classList.add('active');
            if (mainImg) {
              mainImg.src = img.src;
              mainImg.alt = img.alt || gallery.productName || '';
            }
          });

          thumbsWrap.appendChild(t);
        });
      }

      // Keep sizes + Add-to-Cart in sync with color
      renderSizes(ck);
    }

    // Color dot click → change gallery + ColorName, reset size
    if (colorButtons.length) {
      colorButtons.forEach(btn => {
        btn.addEventListener('click', function() {
          colorButtons.forEach(b => b.classList.remove('active'));
          btn.classList.add('active');

          const cid = btn.dataset.colorId || '';
          const colorKey = cid !== '' ? String(cid) : '0';
          renderGallery(colorKey);
        });
      });
    }

    // Force user to choose size before adding to cart
    if (addToCartLink) {
      addToCartLink.addEventListener('click', function(e) {
        if (!selectedSize) {
          e.preventDefault();

          // Switch to "Size & Fit" tab so user can see where to click
          const sizeTab = document.getElementById('tab-size');
          if (sizeTab) sizeTab.checked = true;

          alert('Please select your size before adding to cart.');
          return;
        }

        // Ensure URL is up-to-date with current color + size
        updateAddToCart(activeColorKey);
      });
    }

    // Initial load: use default color key from PHP
    const initialKey = gallery.activeColorKey || activeColorKey || '0';
    renderGallery(initialKey);
  })();

  // Size guide toggle
  (function() {
    const btn = document.getElementById('sizeGuideToggle');
    const panel = document.getElementById('sizeGuidePanel');
    if (!btn || !panel) return;

    function setState(open) {
      if (open) {
        panel.removeAttribute('hidden');
        btn.classList.add('active');
        btn.textContent = 'Size guide ▲';
      } else {
        panel.setAttribute('hidden', 'hidden');
        btn.classList.remove('active');
        btn.textContent = 'Size guide ▾';
      }
    }

    btn.addEventListener('click', function() {
      const isHidden = panel.hasAttribute('hidden');
      setState(isHidden);
    });
  })();

  // wishlist heart toggle (main heart)
  (function() {
    const btn = document.getElementById('wishlistHeartBtn');
    if (!btn) return;

    btn.addEventListener('click', function() {
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
          console.log('wishlist response:', data);
          if (data.ok) {
            const icon = btn.querySelector('.wishlist-icon');
            if (data.status === 'added') {
              btn.classList.add('active');
              if (icon) icon.textContent = '♥';
              alert("Added to wishlist successfully!");
            } else if (data.status === 'removed') {
              btn.classList.remove('active');
              if (icon) icon.textContent = '♡';
              alert("Removed from wishlist.");
            }
          } else if (data.error === 'not_logged_in') {
            window.location.href = '../security/login.php';
          }
        })
        .catch(console.error);
    });
  })();

  // wishlist hearts for "Something similar" cards (same behaviour as product.php)
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
        console.log('similar wishlist response:', data);
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
          window.location.href = '../security/login.php';
        }
      })
      .catch(err => {
        console.error(err);
        alert('An error occurred while updating wishlist.');
      });
  });
</script>

<?php include 'footer.php'; ?>