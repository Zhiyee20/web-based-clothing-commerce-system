<?php
require '../config.php';
include '../user/header.php';

$q = trim((string)($_GET['query'] ?? ''));
$results = [];

function h($v)
{
  return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}
function money_rm($n)
{
  return 'RM ' . number_format((float)$n, 2, '.', ',');
}

/**
 * Map an ImagePath filename from product_images to a web path.
 * This file is in /web/ai (or similar), uploads are in /web/uploads.
 * So web path from here is "../uploads/<filename>".
 */
function product_img(string $photo): string
{
  $photo = trim($photo);
  if ($photo === '') {
    return '../uploads/default.jpg';
  }
  // Filesystem path: /web/uploads/<photo>
  $fs = dirname(__DIR__) . '/uploads/' . $photo;
  return is_file($fs) ? ('../uploads/' . $photo) : '../uploads/default.jpg';
}

if ($q !== '') {
  // ----- Option B family gate -----
  $term = mb_strtolower($q);
  $families = [
    'pants'    => ['pant', 'pants', 'trouser', 'trackpant', 'trackpants'],
    'jacket'   => ['jacket', 'blouson', 'bomber'],
    'shirt'    => ['shirt', 't-shirt', 'tee'],
    'pullover' => ['pullover', 'sweater', 'knit'],
  ];
  $familyRegex = null;
  foreach ($families as $key => $alts) {
    if (preg_match('/' . preg_quote($key, '/') . '/i', $term)) {
      $familyRegex = implode('|', array_map('preg_quote', $alts));
      break;
    }
  }

  // ----- Try FULLTEXT first -----
  try {
    $sql = "
      SELECT 
        p.*,
        c.CategoryName,
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
          (MATCH(p.Name) AGAINST(:q IN BOOLEAN MODE)) * 3 +
          (MATCH(p.Description) AGAINST(:q IN BOOLEAN MODE)) * 0.7 +
          (CASE WHEN LOWER(c.CategoryName) LIKE :q_like THEN 2 ELSE 0 END)
        ) AS relevance
      FROM product p
LEFT JOIN categories c ON p.CategoryID = c.CategoryID
WHERE (
        MATCH(p.Name, p.Description) AGAINST(:q IN BOOLEAN MODE)
        OR LOWER(c.CategoryName) LIKE :q_like
      )
  AND EXISTS (
        SELECT 1
        FROM product_colors pc
        JOIN product_color_sizes pcs ON pcs.ProductColorID = pc.ProductColorID
        WHERE pc.ProductID = p.ProductID
          AND pcs.Stock > 0
      )
    ";
    if ($familyRegex) {
      $sql .= "
    AND (
      LOWER(c.CategoryName) REGEXP :fam
      OR LOWER(p.Name) REGEXP :fam
      OR MATCH(p.Description) AGAINST(:q IN BOOLEAN MODE)
    )
  ";
    }

    $sql .= " HAVING relevance > 0 ORDER BY relevance DESC LIMIT 50";

    $stmt = $pdo->prepare($sql);
    $params = [
      ':q'      => '+' . preg_replace('/\s+/', ' +', mb_strtolower($q)),
      ':q_like' => '%' . mb_strtolower($q) . '%',
    ];
    if ($familyRegex) $params[':fam'] = "({$familyRegex})";
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (PDOException $e) {
    // 1191 = Can't find FULLTEXT index matching the column list
    if ($e->errorInfo[1] == 1191) {
      // ----- Fallback to LIKE/REGEXP with Option B gate -----
      $word = preg_quote(mb_strtolower($q), '/');
      $like = '%' . mb_strtolower($q) . '%';

      $sql2 = "
        SELECT 
          p.*,
          c.CategoryName,
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
LEFT JOIN categories c ON p.CategoryID = c.CategoryID
WHERE (
        LOWER(p.Name) LIKE :q_like
        OR LOWER(c.CategoryName) LIKE :q_like
";

      $params2 = [
        ':q_like' => $like,
        ':exact'  => mb_strtolower($q),
        ':start'  => mb_strtolower($q) . '%'
      ];

      if ($familyRegex) {
        // Gate description matches to same family
        $sql2 .= "
  OR (
      LOWER(p.Description) LIKE :q_like
      AND (
          LOWER(c.CategoryName) REGEXP :fam
          OR LOWER(p.Name) REGEXP :fam
      )
  )
";

        $params2[':fam'] = "({$familyRegex})";
      }

      $sql2 .= "
)
AND EXISTS (
    SELECT 1
    FROM product_colors pc
    JOIN product_color_sizes pcs ON pcs.ProductColorID = pc.ProductColorID
    WHERE pc.ProductID = p.ProductID
      AND pcs.Stock > 0
)
";

      $sql2 .= "
        ORDER BY 
          CASE
            WHEN LOWER(p.Name) = :exact THEN 1
            WHEN LOWER(p.Name) LIKE :start THEN 2
            WHEN LOWER(c.CategoryName) LIKE :start THEN 3
            ELSE 4
          END,
          p.ProductID
        LIMIT 50
      ";

      $stmt2 = $pdo->prepare($sql2);
      $stmt2->execute($params2);
      $results = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    } else {
      throw $e;
    }
  }
}

/* --- Wishlist product IDs for hearts (same style as product.php) --- */
$wishlistProductIDs = [];
$loggedInUser = $_SESSION['user'] ?? null;

if ($loggedInUser) {
  $uid = (int)$loggedInUser['UserID'];
  $stW = $pdo->prepare("SELECT ProductID FROM wishlist_items WHERE UserID = ?");
  $stW->execute([$uid]);
  $wishlistProductIDs = $stW->fetchAll(PDO::FETCH_COLUMN); // array of ProductID
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Search Results</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet">
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
      text-align: center;
      margin: 0 0 6px;
      font-family: 'Playfair Display', serif;
    }

    .catalog-sub {
      text-align: center;
      margin: 0 0 20px;
      color: #666;
      font-size: 14px;
    }

    .catalog-wrap {
      max-width: 1280px;
      margin: 0 auto;
      padding: 0 16px;
      box-sizing: border-box;
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

    /* wishlist heart (same style as product.php cards) */
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
  </style>
</head>

<body>

  <section class="catalog">
    <div class="catalog-wrap">
      <h2 class="catalog-title">Search Results</h2>
      <p class="catalog-sub">
        <?= $q === '' ? 'Type something to search.' : 'for “' . h($q) . '”' ?>
      </p>

      <?php if ($q === ''): ?>
        <p class="empty">No query provided.</p>
      <?php elseif (!$results): ?>
        <p class="empty">No products found.</p>
      <?php else: ?>
        <div class="catalog-grid">
          <?php foreach ($results as $p): ?>
            <?php
            // Use PrimaryImage from product_images; fallback handled in product_img()
            $imgFile = $p['PrimaryImage'] ?? '';
            $pid = (int)$p['ProductID'];

            // build color dots (max 4)
            $colorCodes = [];
            if (!empty($p['ColorCodeList'])) {
              $parts = explode(',', $p['ColorCodeList']);
              foreach ($parts as $code) {
                $code = trim($code);
                if ($code === '') continue;
                if ($code[0] !== '#') $code = '#' . $code;
                $colorCodes[] = strtolower($code);
              }
            }

            $isWished = $loggedInUser && in_array($pid, $wishlistProductIDs);
            ?>
            <article class="catalog-item">
              <!-- wishlist heart -->
              <?php if ($pid > 0): ?>
                <button type="button"
                  class="catalog-heart<?= $isWished ? ' active' : '' ?>"
                  data-product-id="<?= $pid ?>">
                  <span class="wishlist-icon"><?= $isWished ? '♥' : '♡' ?></span>
                </button>
              <?php endif; ?>

              <a class="catalog-card" href="../user/product_detail.php?ProductID=<?= urlencode($p['ProductID']) ?>">
                <img class="catalog-img"
                  src="<?= h(product_img($imgFile)) ?>"
                  alt="<?= h($p['Name']) ?>"
                  loading="lazy">
              </a>
              <h3 class="catalog-name">
                <a href="../user/product_detail.php?ProductID=<?= urlencode($p['ProductID']) ?>"><?= h($p['Name']) ?></a>
              </h3>
              <p class="catalog-price"><?= money_rm($p['Price']) ?></p>

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
      <?php endif; ?>
    </div>
  </section>

  <script>
    // Wishlist heart toggle (same behaviour path as other non-/user pages)
    document.addEventListener('click', function(e) {
      const btn = e.target.closest('.catalog-heart');
      if (!btn) return;

      const pid = btn.dataset.productId;
      const isActive = btn.classList.contains('active');
      const action = isActive ? 'remove' : 'add';

      fetch('../user/wishlist_toggle.php', {
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
            window.location.href = '/security/login.php';
          }
        })
        .catch(err => {
          console.error(err);
          alert('An error occurred while updating wishlist.');
        });
    });
  </script>

  <?php include '../user/footer.php'; ?>
</body>

</html>