<?php
session_start();
require __DIR__ . '/../config.php';

try {

  // 2) Base SQL
  $sql = "
        SELECT
            p.*,
            COALESCE(c.CategoryName, 'Unknown') AS CategoryName,
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
                SELECT GROUP_CONCAT(pc.ColorName ORDER BY pc.ProductColorID SEPARATOR ', ')
                FROM product_colors pc
                WHERE pc.ProductID = p.ProductID
            ) AS ColorList,
            (
                SELECT GROUP_CONCAT(pc.ColorCode ORDER BY pc.ProductColorID SEPARATOR ', ')
                FROM product_colors pc
                WHERE pc.ProductID = p.ProductID
            ) AS ColorCodeList
        FROM product p
        LEFT JOIN categories c ON p.CategoryID = c.CategoryID
        WHERE 1=1
    ";
  $params = [];

  // 3) Filters
  if (!empty($_GET['search'])) {
    $sql      .= " AND p.Name LIKE ?";
    $params[]  = '%' . $_GET['search'] . '%';
  }

  if (isset($_GET['category']) && $_GET['category'] !== '') {
    if ($_GET['category'] === 'NULL') {
      $sql .= " AND p.CategoryID IS NULL";
    } else {
      $sql      .= " AND p.CategoryID = ?";
      $params[]  = $_GET['category'];
    }
  }

  // gender filter
  if (!empty($_GET['gender'])) {
    if (in_array($_GET['gender'], ['Male', 'Female', 'Unisex'], true)) {
      $sql     .= " AND p.TargetGender = ?";
      $params[] = $_GET['gender'];
    }
  }

  if (!empty($_GET['min_price'])) {
    $sql      .= " AND p.Price >= ?";
    $params[]  = $_GET['min_price'];
  }
  if (!empty($_GET['max_price'])) {
    $sql      .= " AND p.Price <= ?";
    $params[]  = $_GET['max_price'];
  }

  // 4) Sorting
  $sortColumn = 'p.Name';
  $sortOrder  = 'ASC';
  switch ($_GET['sort'] ?? '') {
    case 'name_desc':
      $sortOrder = 'DESC';
      break;
    case 'price_asc':
      $sortColumn = 'p.Price';
      break;
    case 'price_desc':
      $sortColumn = 'p.Price';
      $sortOrder  = 'DESC';
      break;
  }

  $sql .= " ORDER BY {$sortColumn} {$sortOrder}";

  // 5) Execute
  $stmt     = $pdo->prepare($sql);
  $stmt->execute($params);
  $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

  /* -------- Build [ProductID][ColorName] => TotalStock map ---------- */
  $colorStockMap = [];
  if ($products) {
    $productIds = array_column($products, 'ProductID');
    $in = implode(',', array_fill(0, count($productIds), '?'));

    // ⚠️ assuming stock column in product_color_sizes is named `Stock`
    //    If your column name is different, change pcs.Stock accordingly.
    $sqlStock = "
          SELECT
              pc.ProductID,
              pc.ColorName,
              COALESCE(SUM(pcs.Stock), 0) AS TotalStock
          FROM product_colors pc
          LEFT JOIN product_color_sizes pcs
                 ON pcs.ProductColorID = pc.ProductColorID
          WHERE pc.ProductID IN ($in)
          GROUP BY pc.ProductID, pc.ColorName
      ";
    $stStock = $pdo->prepare($sqlStock);
    $stStock->execute($productIds);

    while ($row = $stStock->fetch(PDO::FETCH_ASSOC)) {
      $pid   = (int)$row['ProductID'];
      $cname = $row['ColorName'];
      $colorStockMap[$pid][$cname] = (int)$row['TotalStock'];
    }
  }

  /* -------- Build [ProductID][ColorName][Size] => {stock, min} map ---------- */
  $colorSizeMap = [];
  if (!empty($productIds)) {
    $in = implode(',', array_fill(0, count($productIds), '?'));

    $sqlSize = "
        SELECT
            pc.ProductID,
            pc.ColorName,
            pcs.Size,
            pcs.Stock,
            pcs.MinStock
        FROM product_colors pc
        LEFT JOIN product_color_sizes pcs
               ON pcs.ProductColorID = pc.ProductColorID
        WHERE pc.ProductID IN ($in)
        ORDER BY pc.ProductID,
                 pc.ProductColorID,
                 FIELD(pcs.Size, 'XS','S','M','L','XL')
    ";
    $stSize = $pdo->prepare($sqlSize);
    $stSize->execute($productIds);

    while ($row = $stSize->fetch(PDO::FETCH_ASSOC)) {
      $pid   = (int)$row['ProductID'];
      $cname = $row['ColorName'];
      $size  = $row['Size'] ?? null;
      if (!$size) continue;

      if (!isset($colorSizeMap[$pid][$cname])) {
        $colorSizeMap[$pid][$cname] = [];
      }
      $colorSizeMap[$pid][$cname][$size] = [
        'stock' => (int)($row['Stock'] ?? 0),
        'min'   => (int)($row['MinStock'] ?? 0),
      ];
    }
  }

  // 6) Categories (only active)
  $catStmt = $pdo->query("
        SELECT CategoryID, CategoryName
        FROM categories
        WHERE IsDeleted = 0
        ORDER BY CategoryName
    ");
  $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  die("Database error: " . $e->getMessage());
}

// Shared admin header
include __DIR__ . '/admin_header.php';
?>

<link rel="stylesheet" href="/assets/admin_product.css">

<!-- Search & Filter -->
<form method="GET" action="admin_product.php" class="search-form">
  <input
    type="text" name="search"
    placeholder="🔍 Search by name…"
    value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">

  <select name="category">
    <option value="">📁 All Categories</option>
    <?php foreach ($categories as $c): ?>
      <option
        value="<?= $c['CategoryID'] ?>"
        <?= (($_GET['category'] ?? '') == $c['CategoryID']) ? 'selected' : '' ?>>
        <?= htmlspecialchars($c['CategoryName']) ?>
      </option>
    <?php endforeach; ?>
  </select>

  <select name="gender">
    <option value="">🚻 All Genders</option>
    <option value="Male" <?= (($_GET['gender'] ?? '') === 'Male')   ? 'selected' : '' ?>>Male</option>
    <option value="Female" <?= (($_GET['gender'] ?? '') === 'Female') ? 'selected' : '' ?>>Female</option>
    <option value="Unisex" <?= (($_GET['gender'] ?? '') === 'Unisex') ? 'selected' : '' ?>>Unisex</option>
  </select>

  <input
    type="number" name="min_price"
    placeholder="💰 Min Price (RM)"
    min="0"
    value="<?= htmlspecialchars($_GET['min_price'] ?? '') ?>">

  <input
    type="number" name="max_price"
    placeholder="💰 Max Price (RM)"
    min="0"
    value="<?= htmlspecialchars($_GET['max_price'] ?? '') ?>">

  <select name="sort" onchange="this.form.submit()">
    <option value="">🔀 Sort By</option>
    <option value="name_asc" <?= (($_GET['sort'] ?? '') === 'name_asc') ? 'selected' : '' ?>>
      🔠 Name (A–Z)
    </option>
    <option value="name_desc" <?= (($_GET['sort'] ?? '') === 'name_desc') ? 'selected' : '' ?>>
      🔠 Name (Z–A)
    </option>
    <option value="price_asc" <?= (($_GET['sort'] ?? '') === 'price_asc') ? 'selected' : '' ?>>
      💰 Price (Low→High)
    </option>
    <option value="price_desc" <?= (($_GET['sort'] ?? '') === 'price_desc') ? 'selected' : '' ?>>
      💰 Price (High→Low)
    </option>
  </select>

  <button type="submit" class="btn search-btn">🔎 Search</button>
  <a href="admin_product.php" class="btn reset-btn">❌ Reset</a>
</form>

<section class="admin-products">
  <h2>Product Management</h2>
  <button id="addProductBtn" class="btn btn-green">➕ Add New Product</button>

  <table class="admin-table">
    <thead>
      <tr>
        <th>Image</th>
        <th>Name</th>
        <th>Price</th>
        <th>Category</th>
        <th>Gender</th>
        <th>Colors</th>
        <th>Stock</th>
        <th>Deleted?</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody id="productTable">
      <?php if (count($products)): ?>
        <?php foreach ($products as $p): ?>
          <?php
          $imgFile        = $p['PrimaryImage'] ?: 'default.jpg';
          $genderLabel    = $p['TargetGender'] ?? 'Unisex';
          $colorLabel     = $p['ColorList'] ?? '';
          $colorCodeLabel = $p['ColorCodeList'] ?? '';

          // Colors come from GROUP_CONCAT, split them back to array
          $colorNames = $colorLabel !== ''
            ? explode(', ', $colorLabel)
            : [];
          ?>
          <tr>
            <td>
              <img
                src="/uploads/<?= htmlspecialchars($imgFile) ?>"
                width="50" alt="">
            </td>
            <td class="productName"><?= htmlspecialchars($p['Name']) ?></td>
            <td class="productPrice">RM <?= number_format($p['Price'], 2) ?></td>
            <td class="productCategory"><?= htmlspecialchars($p['CategoryName']) ?></td>
            <td class="productGender"><?= htmlspecialchars($genderLabel) ?></td>
            <!-- Colors column: one line per color name -->
            <td class="productColors">
              <?php if ($colorNames): ?>
                <?php foreach ($colorNames as $cName): ?>
                  <div><?= htmlspecialchars($cName) ?></div>
                <?php endforeach; ?>
              <?php else: ?>
                <span>-</span>
              <?php endif; ?>
            </td>
            <!-- Stock column: one line per color, total XS–XL from product_color_sizes -->
            <td class="productStock">
              <?php if ($colorNames): ?>
                <?php foreach ($colorNames as $cName): ?>
                  <?php
                  $stockVal = $colorStockMap[$p['ProductID']][$cName] ?? 0;
                  ?>
                  <div><?= (int)$stockVal ?></div>
                <?php endforeach; ?>
              <?php else: ?>
                <span>0</span>
              <?php endif; ?>
            </td>
            <td class="productDeleted">
              <?php if ((int)$p['IsDeleted'] === 1): ?>
                <span style="color:red;font-weight:bold;">Yes</span>
              <?php else: ?>
                <span style="color:green;">No</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ((int)$p['IsDeleted'] === 1): ?>
                <button
                  class="restoreBtn"
                  data-id="<?= $p['ProductID'] ?>">
                  ♻️ Restore
                </button>
              <?php else: ?>
                <?php
                // Prepare per-color size+minstock payload for this product
                $sizePayload = $colorSizeMap[$p['ProductID']] ?? [];
                $sizeJson    = htmlspecialchars(json_encode($sizePayload), ENT_QUOTES, 'UTF-8');
                ?>
                <button
                  class="editBtn"
                  data-id="<?= $p['ProductID'] ?>"
                  data-name="<?= htmlspecialchars($p['Name']) ?>"
                  data-description="<?= htmlspecialchars($p['Description']) ?>"
                  data-price="<?= htmlspecialchars($p['Price']) ?>"
                  data-category="<?= htmlspecialchars($p['CategoryID']) ?>"
                  data-gender="<?= htmlspecialchars($p['TargetGender'] ?? 'Unisex') ?>"
                  data-colors="<?= htmlspecialchars($colorLabel) ?>"
                  data-colorcodes="<?= htmlspecialchars($colorCodeLabel) ?>"
                  data-sizes="<?= $sizeJson ?>">
                  ✏️
                </button>
                <button
                  class="deleteBtn"
                  data-id="<?= $p['ProductID'] ?>">
                  🗑️
                </button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr>
          <td colspan="9">❌ No products found.</td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
</section>

<!-- Add Product Modal -->
<div id="addProductModal" class="modal">
  <div class="modal-content">
    <span class="close">&times;</span>
    <h3 style="text-align:center;">➕ Add New Product</h3>
    <form id="addProductForm" enctype="multipart/form-data">
      <input type="hidden" name="action" value="add">

      <label>Name:</label>
      <input type="text" name="Name" required>

      <label>Description:</label>
      <textarea name="Description" required></textarea>

      <label>Price (RM):</label>
      <input type="number" name="Price" step="0.01" required>

      <label>Category:</label>
      <div style="display:flex; gap:6px; align-items:center;">
        <select name="CategoryID" id="addCategorySelect" style="flex:1;">
          <?php foreach ($categories as $c): ?>
            <option value="<?= $c['CategoryID'] ?>">
              <?= htmlspecialchars($c['CategoryName']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button type="button" class="btn btn-secondary btn-mini" id="openAddCategoryModal">➕</button>
      </div>

      <label>Target Gender:</label>
      <select name="TargetGender">
        <option value="Unisex">Unisex</option>
        <option value="Male">Male</option>
        <option value="Female">Female</option>
      </select>

      <!-- Colors & Photos -->
      <div style="margin-top: 10px; border-top: 1px solid #eee; padding-top: 10px;">
        <h4 style="margin: 6px 0;">Colors &amp; Photos</h4>
        <small>Each block = one color with its own image set. For single-color product, just use the first block.</small>

        <div id="colorBlocks">
          <div class="color-block" data-color-index="0">
            <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">
              <label style="margin:0;white-space:nowrap;">Color Name:</label>
              <input type="text"
                name="ColorNames[]"
                placeholder="e.g. Beige"
                required
                style="flex:1;padding:8px 10px;font-size:14px;">
              <button type="button"
                class="remove-color-block"
                title="Remove this color"
                style="border:none;background:#eee;color:#c00;border-radius:50%;
                       width:20px;height:20px;font-size:12px;line-height:20px;
                       display:flex;align-items:center;justify-content:center;
                       flex:0 0 auto;cursor:pointer;">
                ✕
              </button>
            </div>

            <!-- Size-wise stock for this color -->
            <div class="size-stock-wrapper">
              <p class="size-title">Sizes &amp; Stock (this color):</p>

              <div class="size-grid">
                <!-- header row -->
                <div class="size-label"></div>
                <div class="size-header">XS</div>
                <div class="size-header">S</div>
                <div class="size-header">M</div>
                <div class="size-header">L</div>
                <div class="size-header">XL</div>

                <!-- Stock row -->
                <div class="size-label">Stock</div>
                <input type="number" name="SizeStock[0][XS]" min="0" class="size-input">
                <input type="number" name="SizeStock[0][S]" min="0" class="size-input">
                <input type="number" name="SizeStock[0][M]" min="0" class="size-input">
                <input type="number" name="SizeStock[0][L]" min="0" class="size-input">
                <input type="number" name="SizeStock[0][XL]" min="0" class="size-input">

                <!-- Min stock row -->
                <div class="size-label">Min</div>
                <input type="number" name="SizeMinStock[0][XS]" min="0" class="size-input">
                <input type="number" name="SizeMinStock[0][S]" min="0" class="size-input">
                <input type="number" name="SizeMinStock[0][M]" min="0" class="size-input">
                <input type="number" name="SizeMinStock[0][L]" min="0" class="size-input">
                <input type="number" name="SizeMinStock[0][XL]" min="0" class="size-input">
              </div>

              <small class="size-hint">
                Leave blank = treat as 0. These values are per color.
              </small>
            </div>

            <label style="margin-top:6px;">Color Swatch:</label>
            <div class="color-swatch-row">
              <input type="color"
                name="ColorCodes[]"
                value="#ffffff"
                class="color-swatch-input">
            </div>

            <label style="margin-top:6px;">Main Photo (this color):</label>
            <input type="file" name="ColorMainPhoto[]" accept="image/*" required>

            <label style="margin-top:6px;">Extra Photos (optional, this color):</label>
            <input type="file" name="ColorExtraPhotos[0][]" accept="image/*" multiple>
          </div>
        </div>

        <button type="button" id="addColorBlockBtn" class="btn btn-secondary">
          ➕ Add Another Color
        </button>
      </div>
      <button type="submit" class="btn btn-green" style="margin-top:12px;">➕ Add Product</button>
    </form>
  </div>
</div>

<!-- Edit Product Modal -->
<div id="editProductModal" class="modal">
  <div class="modal-content">
    <span class="close">&times;</span>
    <h3>Edit Product</h3>
    <form id="editProductForm" enctype="multipart/form-data">
      <input type="hidden" id="editProductID" name="ProductID">

      <label>Name:</label>
      <input type="text" id="editName" name="Name" required>

      <label>Description:</label>
      <textarea id="editDescription" name="Description" required></textarea>

      <label>Price (RM):</label>
      <input type="number" id="editPrice" name="Price" step="0.01" required>

      <label>Category:</label>
      <div style="display:flex; gap:6px; align-items:center;">
        <select id="editCategoryID" name="CategoryID" style="flex:1;">
          <?php foreach ($categories as $c): ?>
            <option value="<?= $c['CategoryID'] ?>">
              <?= htmlspecialchars($c['CategoryName']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button type="button" class="btn btn-secondary btn-mini" id="openEditCategoryModal">➕</button>
      </div>

      <label>Target Gender:</label>
      <select id="editTargetGender" name="TargetGender">
        <option value="Unisex">Unisex</option>
        <option value="Male">Male</option>
        <option value="Female">Female</option>
      </select>

      <!-- Colors (edit) -->
      <div class="edit-colors-wrapper" style="margin-top:10px;">
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <label style="margin:0;">Colors:</label>
          <button type="button"
            id="editAddColorBlockBtn"
            class="btn btn-secondary"
            style="padding:2px 8px;font-size:12px;">
            ➕ Add Color
          </button>
        </div>
        <div id="editColorBlocks" style="margin-top:6px;"></div>
      </div>

      <button type="submit" class="btn btn-blue" style="margin-top:12px;">✏️ Update Product</button>
    </form>
  </div>
</div>

<!-- Add Category Mini Modal (used by both Add + Edit Product forms) -->
<div id="addCategoryModal" class="modal">
  <div class="modal-content" style="max-width:360px; position:relative;">
    <span class="close-add-category">&times;</span>
    <h3 style="margin-top:0;">➕ Add New Category</h3>

    <label>Category Name:</label>
    <input type="text" id="newCategoryName" required>

    <label style="margin-top:8px;">Size Guide Group (optional):</label>
    <select id="newSizeGuideGroup">
      <option value="">-- None / Not set --</option>
      <option value="TOP">TOP</option>
      <option value="BOTTOM">BOTTOM</option>
      <option value="DRESS">DRESS</option>
    </select>

    <button class="btn btn-green" id="saveCategoryBtn" style="margin-top:12px;">
      💾 Save Category
    </button>
  </div>
</div>

<script src="../assets/app.js"></script>
<script src="../assets/admin_product.js"></script>
<?php include __DIR__ . '/admin_footer.php'; ?>