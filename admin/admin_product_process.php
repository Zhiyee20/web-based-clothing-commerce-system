<?php
// admin_product_process.php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Content-Type: application/json; charset=utf-8");

require __DIR__ . '/../config.php';

/**
 * Build a safe filename from product name + color name + extension.
 *
 * New rules (Option 3):
 * - Remove all non-alphanumeric characters from product name and color name
 * - Base prefix: Product_Color  (Color optional)
 * - Look at ALL existing files starting with that prefix (any extension)
 * - If $isMain = true and no file exists → use Product_Color.ext
 * - Otherwise → continue numbering: Product_Color1.ext, Product_Color2.ext, etc.
 *   (Numbering continues even across extensions.)
 */
function build_image_filename(
    string $productName,
    string $colorName,
    string $ext,
    string $uploadsDir,
    bool $isMain = false
): string {
    // Sanitize product name
    $base = preg_replace('/[^A-Za-z0-9]/', '', $productName);
    if ($base === '') {
        $base = 'Product';
    }

    // Sanitize color
    $color = preg_replace('/[^A-Za-z0-9]/', '', $colorName);
    $basePrefix = $color !== '' ? ($base . '_' . $color) : $base;

    $ext = strtolower($ext);

    // Normalize uploadsDir
    $dir = rtrim($uploadsDir, '/\\') . '/';

    // Find all existing files for this base prefix (any extension)
    $pattern  = $dir . $basePrefix . '*.*';
    $files    = glob($pattern) ?: [];
    $maxIndex = -1; // -1 = no file yet

    foreach ($files as $fullpath) {
        $filename = basename($fullpath); // e.g. CottonShirt_Black1.jpg

        $dotPos = strrpos($filename, '.');
        if ($dotPos === false) {
            continue; // no extension
        }

        $namePart = substr($filename, 0, $dotPos); // e.g. CottonShirt_Black1
        if (strpos($namePart, $basePrefix) !== 0) {
            continue; // not our base
        }

        $suffix = substr($namePart, strlen($basePrefix)); // '' or '1' or '2'
        if ($suffix === '') {
            $index = 0; // main (no number)
        } elseif (ctype_digit($suffix)) {
            $index = (int)$suffix;
        } else {
            // e.g. CottonShirt_Black_thumb.jpg – ignore
            continue;
        }

        if ($index > $maxIndex) {
            $maxIndex = $index;
        }
    }

    // Decide index for the new file
    if ($maxIndex === -1 && $isMain) {
        // No existing file yet & this is main image → no number
        $filename = $basePrefix . '.' . $ext;
    } else {
        // Extras OR main when something already exists → continue sequence
        $newIndex = ($maxIndex < 0) ? 1 : $maxIndex + 1;
        $filename = $basePrefix . $newIndex . '.' . $ext;
    }

    return $filename; // just the filename, directory handled by caller
}

/**
 * Ensure the base folders exist and, if categoryName is given,
 * ensure img_category/<CategoryName>/ exists. Return that path or null.
 */
function prepare_image_dirs(PDO $pdo, ?int $categoryID): array
{
    $uploadsDir     = __DIR__ . '/../uploads/';
    $imgDir         = __DIR__ . '/../img/';
    $imgCategoryDir = __DIR__ . '/../img_category/';

    if (!is_dir($uploadsDir))     mkdir($uploadsDir, 0755, true);
    if (!is_dir($imgDir))         mkdir($imgDir, 0755, true);
    if (!is_dir($imgCategoryDir)) mkdir($imgCategoryDir, 0755, true);

    $categorySubDir = null;

    if ($categoryID) {
        $stmt = $pdo->prepare("SELECT CategoryName FROM categories WHERE CategoryID = ?");
        $stmt->execute([$categoryID]);
        $catName = $stmt->fetchColumn();

        if ($catName) {
            // example: "Pants" -> img_category/Pants/
            // allow letters, digits, spaces, dash, underscore; then trim spaces
            $safeCat = preg_replace('/[^A-Za-z0-9 _-]/', '', $catName);
            $safeCat = trim($safeCat);
            if ($safeCat === '') {
                $safeCat = 'Uncategorized';
            }

            $categorySubDir = rtrim($imgCategoryDir, '/\\') . '/' . $safeCat . '/';
            if (!is_dir($categorySubDir)) {
                mkdir($categorySubDir, 0755, true);
            }
        }
    }

    return [$uploadsDir, $imgDir, $imgCategoryDir];
}

/**
 * Delete a given image filename from:
 * - /uploads/
 * - /img/
 * - /img_category/<CategoryName>/  (for the product's category if given, otherwise all matches)
 */
function delete_image_files(PDO $pdo, string $fileName, ?int $productID = null): void
{
    if ($fileName === '' || $fileName === 'default.jpg') {
        return;
    }

    $uploadsDir     = __DIR__ . '/../uploads/';
    $imgDir         = __DIR__ . '/../img/';
    $imgCategoryDir = __DIR__ . '/../img_category/';

    $paths = [];

    // /uploads/
    $paths[] = rtrim($uploadsDir, '/\\') . '/' . $fileName;
    // /img/
    $paths[] = rtrim($imgDir, '/\\') . '/' . $fileName;

    // /img_category/...
    if ($productID !== null) {
        $st = $pdo->prepare("
            SELECT c.CategoryName
            FROM product p
            LEFT JOIN categories c ON p.CategoryID = c.CategoryID
            WHERE p.ProductID = ?
        ");
        $st->execute([$productID]);
        $catName = $st->fetchColumn();

        if ($catName) {
            $safeCat = preg_replace('/[^A-Za-z0-9 _-]/', '', $catName);
            $safeCat = trim($safeCat);
            if ($safeCat === '') {
                $safeCat = 'Uncategorized';
            }
            $catFile = rtrim($imgCategoryDir, '/\\') . '/' . $safeCat . '/' . $fileName;
            $paths[] = $catFile;
        } else {
            // fallback: try any category folder
            $pattern = rtrim($imgCategoryDir, '/\\') . '/*/' . $fileName;
            $matches = glob($pattern) ?: [];
            foreach ($matches as $m) {
                $paths[] = $m;
            }
        }
    } else {
        $pattern = rtrim($imgCategoryDir, '/\\') . '/*/' . $fileName;
        $matches = glob($pattern) ?: [];
        foreach ($matches as $m) {
            $paths[] = $m;
        }
    }

    foreach ($paths as $p) {
        if (is_file($p)) {
            @unlink($p);
        }
    }
}

/**
 * After representative (preview) image is removed, choose a new one and
 * copy it from /uploads/ to:
 * - /img/
 * - /img_category/<CategoryName>/
 *
 * Rules:
 * - If only 1 color → pick any remaining image (prefer main, then extra).
 * - If >1 color     → pick another main image (IsPrimary=1) from the product.
 */
function refresh_representative_image(PDO $pdo, int $productID): void
{
    $uploadsDir     = __DIR__ . '/../uploads/';
    $imgDir         = __DIR__ . '/../img/';
    $imgCategoryDir = __DIR__ . '/../img_category/';

    // 1) Check how many non-empty colors this product has
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT NULLIF(TRIM(ColorName), ''))
        FROM product_colors
        WHERE ProductID = ?
    ");
    $stmt->execute([$productID]);
    $colorCount = (int)$stmt->fetchColumn();
    $multiColor = $colorCount > 1;

    // 2) Pick candidate image
    $candidate = null;

    if ($multiColor) {
        // Prefer another main image (IsPrimary=1)
        $st = $pdo->prepare("
            SELECT ImagePath
            FROM product_images
            WHERE ProductID = ?
              AND IsPrimary = 1
            ORDER BY SortOrder ASC, ImageID ASC
            LIMIT 1
        ");
        $st->execute([$productID]);
        $candidate = $st->fetchColumn();
    }

    if (!$candidate) {
        // Single color OR no mains left: pick any image
        $st = $pdo->prepare("
            SELECT ImagePath
            FROM product_images
            WHERE ProductID = ?
            ORDER BY IsPrimary DESC, SortOrder ASC, ImageID ASC
            LIMIT 1
        ");
        $st->execute([$productID]);
        $candidate = $st->fetchColumn();
    }

    if (!$candidate) {
        // No images left at all → nothing to set as preview
        return;
    }

    $src = rtrim($uploadsDir, '/\\') . '/' . $candidate;
    if (!is_file($src)) {
        return;
    }

    // 3) Copy to /img/
    $destImg = rtrim($imgDir, '/\\') . '/' . $candidate;
    @copy($src, $destImg);

    // 4) Copy to /img_category/<CategoryName>/
    $st2 = $pdo->prepare("
        SELECT c.CategoryName
        FROM product p
        LEFT JOIN categories c ON p.CategoryID = c.CategoryID
        WHERE p.ProductID = ?
    ");
    $st2->execute([$productID]);
    $catName = $st2->fetchColumn();

    if ($catName) {
        $safeCat = preg_replace('/[^A-Za-z0-9 _-]/', '', $catName);
        $safeCat = trim($safeCat);
        if ($safeCat === '') {
            $safeCat = 'Uncategorized';
        }
        $catDir = rtrim($imgCategoryDir, '/\\') . '/' . $safeCat . '/';
        if (!is_dir($catDir)) {
            mkdir($catDir, 0755, true);
        }
        $destCat = rtrim($catDir, '/\\') . '/' . $candidate;
        @copy($src, $destCat);
    }
}

try {
    // use $pdo from config.php

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['action'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }

    $action            = $_POST['action'];
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    // product_images insert (now includes ProductColorID)
    $insertImageStmt = $pdo->prepare("
        INSERT INTO product_images (ProductID, ProductColorID, ImagePath, SortOrder, IsPrimary)
        VALUES (:pid, :pcid, :path, :sort, :primary)
    ");

    // 🔹 Clear existing primary for a given product + color (enforce one main per color)
    $clearPrimaryStmt = $pdo->prepare("
        UPDATE product_images
           SET IsPrimary = 0
         WHERE ProductID = :pid
           AND (
                 (ProductColorID IS NULL AND :pcid IS NULL)
              OR ProductColorID = :pcid
           )
    ");

    // product_colors insert (includes ColorCode)
    $insertColorStmt = $pdo->prepare("
        INSERT INTO product_colors (ProductID, ColorName, ColorCode, IsDefault)
        VALUES (:pid, :name, :code, :isdef)
    ");

    //
    // 🔹 ADD PRODUCT (per-color uploads)
    //
    if ($action === 'add') {
        $name        = trim($_POST['Name'] ?? '');
        $description = trim($_POST['Description'] ?? '');
        $price       = floatval($_POST['Price'] ?? 0);
        // Stock is stored by Color + Size in product_color_sizes, not in product table
        $sizeStockArr    = (isset($_POST['SizeStock']) && is_array($_POST['SizeStock'])) ? $_POST['SizeStock'] : [];
        $sizeMinStockArr = (isset($_POST['SizeMinStock']) && is_array($_POST['SizeMinStock'])) ? $_POST['SizeMinStock'] : [];
        $categoryID  = $_POST['CategoryID'] !== '' ? intval($_POST['CategoryID']) : null;

        $targetGender = $_POST['TargetGender'] ?? 'Unisex';
        if (!in_array($targetGender, ['Male', 'Female', 'Unisex'], true)) {
            $targetGender = 'Unisex';
        }

        // arrays from per-color blocks
        $colorNames = isset($_POST['ColorNames']) && is_array($_POST['ColorNames'])
            ? $_POST['ColorNames']
            : [];

        $colorCodes = isset($_POST['ColorCodes']) && is_array($_POST['ColorCodes'])
            ? $_POST['ColorCodes']
            : [];

        // Validate Name
        if ($name === '') {
            echo json_encode(['success' => false, 'message' => 'Product name cannot be empty.']);
            exit;
        }

        // Validate Description
        if ($description === '') {
            echo json_encode(['success' => false, 'message' => 'Product description cannot be empty.']);
            exit;
        }

        // Validate Price
        if (!is_numeric($price) || $price <= 0) {
            echo json_encode(['success' => false, 'message' => 'Price must be a numeric value greater than 0.']);
            exit;
        }

        // 1) Insert product
        $stmt = $pdo->prepare("
            INSERT INTO product
              (Name, Description, Price, CategoryID, TargetGender)
            VALUES (?,?,?,?,?)
        ");
        $ok = $stmt->execute([$name, $description, $price, $categoryID, $targetGender]);

        if (!$ok) {
            echo json_encode(['success' => false, 'message' => 'Insert failed.']);
            exit;
        }

        $productID = (int)$pdo->lastInsertId();

        // Prepare directories (uploads, img, img_category/<CategoryName>/)
        [$uploadsDir, $imgDir, $imgCategoryDir] = prepare_image_dirs($pdo, $categoryID);

        // 2) Insert colors → product_colors
        //    Build map: colorIndex => [id, name]
        $colorMap        = []; // index => ['id' => ProductColorID|null, 'name' => ColorName]
        $defaultColorId  = null;
        $defaultColorSet = false;

        foreach ($colorNames as $idx => $cNameRaw) {
            $cName = trim($cNameRaw);
            if ($cName === '') {
                // generic (no named color)
                $colorMap[$idx] = ['id' => null, 'name' => ''];
                continue;
            }

            // read matching hex from ColorCodes[]
            $rawCode = $colorCodes[$idx] ?? '';
            $rawCode = trim($rawCode);
            if ($rawCode !== '' && $rawCode[0] !== '#') {
                $rawCode = '#' . $rawCode;
            }
            $normalizedCode = $rawCode !== '' ? $rawCode : null;

            $isDefault = $defaultColorSet ? 0 : 1;

            $insertColorStmt->execute([
                ':pid'   => $productID,
                ':name'  => $cName,
                ':code'  => $normalizedCode,
                ':isdef' => $isDefault
            ]);

            $colorID = (int)$pdo->lastInsertId();
            $colorMap[$idx] = ['id' => $colorID, 'name' => $cName];

            if (!$defaultColorSet) {
                $defaultColorId  = $colorID;
                $defaultColorSet = true;
            }
        }

        // If no colors at all (all empty), still keep index 0 as generic
        if (empty($colorMap) && isset($colorNames[0])) {
            $colorMap[0] = ['id' => null, 'name' => ''];
        }

        $sizeStockArr    = $_POST['SizeStock'] ?? [];
        $sizeMinStockArr = $_POST['SizeMinStock'] ?? [];

        $insSize = $pdo->prepare("
    INSERT INTO product_color_sizes
      (ProductColorID, Size, Stock, MinStock)
    VALUES (?,?,?,?)
");

        $sizes = ['XS', 'S', 'M', 'L', 'XL'];

        foreach ($colorMap as $idx => $cInfo) {
            $pcid = $cInfo['id'];
            if (!$pcid) continue; // skip empty / generic color

            foreach ($sizes as $sz) {
                $stockVal = isset($sizeStockArr[$idx][$sz])
                    ? (int)$sizeStockArr[$idx][$sz]
                    : 0;

                $minVal = isset($sizeMinStockArr[$idx][$sz])
                    ? (int)$sizeMinStockArr[$idx][$sz]
                    : 0;

                $insSize->execute([$pcid, $sz, $stockVal, $minVal]);
            }
        }
        // 3) Insert images per color block
        $sort = 0;

        // 🚩 Track if we've already saved the preview image (first main of first color)
        $previewImageSaved = false;

        // Main photos: ColorMainPhoto[]
        if (!empty($_FILES['ColorMainPhoto']['name']) && is_array($_FILES['ColorMainPhoto']['name'])) {
            foreach ($_FILES['ColorMainPhoto']['name'] as $i => $fileNameMain) {
                $origMain = $fileNameMain;
                if (empty($origMain)) continue;
                if (!isset($colorMap[$i])) {
                    $colorMap[$i] = ['id' => null, 'name' => ''];
                }

                $colorInfo = $colorMap[$i];
                $cName     = $colorInfo['name'];
                $cId       = $colorInfo['id'];

                if ($_FILES['ColorMainPhoto']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $ext = strtolower(pathinfo($origMain, PATHINFO_EXTENSION));

                if (!in_array($ext, $allowedExtensions, true)) {
                    echo json_encode([
                        'success' => false,
                        'message' => "Invalid image format for main photo: $origMain. Only JPG, PNG, GIF, WEBP, AVIF are allowed."
                    ]);
                    exit;
                }

                $tmp = $_FILES['ColorMainPhoto']['tmp_name'][$i];

                // main = true
                $fileName      = build_image_filename($name, $cName, $ext, $uploadsDir, true);
                $targetUploads = rtrim($uploadsDir, '/\\') . '/' . $fileName;

                if (!move_uploaded_file($tmp, $targetUploads)) {
                    continue;
                }

                // ✅ Only for the VERY FIRST main photo of the product:
                //    copy to /img/ and /img_category/<category>/
                if (!$previewImageSaved) {
                    // /img/
                    $destImg = rtrim($imgDir, '/\\') . '/' . $fileName;
                    @copy($targetUploads, $destImg);

                    // /img_category/<CategoryName>/
                    if ($categoryID !== null) {
                        $stCat = $pdo->prepare("SELECT CategoryName FROM categories WHERE CategoryID = ?");
                        $stCat->execute([$categoryID]);
                        $catName = $stCat->fetchColumn();
                        if ($catName) {
                            $safeCat = preg_replace('/[^A-Za-z0-9 _-]/', '', $catName);
                            $safeCat = trim($safeCat);
                            if ($safeCat === '') {
                                $safeCat = 'Uncategorized';
                            }
                            $catDir = rtrim($imgCategoryDir, '/\\') . '/' . $safeCat . '/';
                            if (!is_dir($catDir)) {
                                mkdir($catDir, 0755, true);
                            }
                            $destCat = rtrim($catDir, '/\\') . '/' . $fileName;
                            @copy($targetUploads, $destCat);
                        }
                    }

                    $previewImageSaved = true;
                }
                // (Other mains of other colors do NOT go to /img or /img_category)

                // clear old primary for this product + color (safety, though for add there should be none)
                $clearPrimaryStmt->execute([
                    ':pid'  => $productID,
                    ':pcid' => $cId
                ]);

                $insertImageStmt->execute([
                    ':pid'     => $productID,
                    ':pcid'    => $cId,
                    ':path'    => $fileName,
                    ':sort'    => $sort,
                    ':primary' => 1
                ]);

                $sort++;
            }
        }

        // Extra photos: ColorExtraPhotos[index][]
        if (!empty($_FILES['ColorExtraPhotos']['name']) && is_array($_FILES['ColorExtraPhotos']['name'])) {
            foreach ($_FILES['ColorExtraPhotos']['name'] as $colorIndex => $namesArray) {
                if (!isset($colorMap[$colorIndex])) {
                    $colorMap[$colorIndex] = ['id' => null, 'name' => ''];
                }
                $colorInfo = $colorMap[$colorIndex];
                $cName     = $colorInfo['name'];
                $cId       = $colorInfo['id'];

                if (!is_array($namesArray)) continue;

                foreach ($namesArray as $j => $origName) {
                    if (empty($origName)) continue;
                    if ($_FILES['ColorExtraPhotos']['error'][$colorIndex][$j] !== UPLOAD_ERR_OK) continue;

                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowedExtensions, true)) {
                        echo json_encode([
                            'success' => false,
                            'message' => "Invalid image format for extra photo: $origName. Only JPG, PNG, GIF, WEBP, AVIF are allowed."
                        ]);
                        exit;
                    }

                    $tmp = $_FILES['ColorExtraPhotos']['tmp_name'][$colorIndex][$j];

                    // extra = false
                    $fileName      = build_image_filename($name, $cName, $ext, $uploadsDir, false);
                    $targetUploads = rtrim($uploadsDir, '/\\') . '/' . $fileName;

                    if (!move_uploaded_file($tmp, $targetUploads)) {
                        continue;
                    }

                    // ❌ Do NOT copy extras to /img or /img_category/
                    // Only /uploads/ keeps everything

                    $insertImageStmt->execute([
                        ':pid'     => $productID,
                        ':pcid'    => $cId,
                        ':path'    => $fileName,
                        ':sort'    => $sort,
                        ':primary' => 0
                    ]);

                    $sort++;
                }
            }
        }

        echo json_encode(['success' => true, 'message' => 'Product added!']);
        exit;
    }

    //
    // 🔹 EDIT PRODUCT (per-color uploads, no global EditPhotos)
    //
    if ($action === 'edit') {
        $id          = intval($_POST['ProductID'] ?? 0);
        $name        = trim($_POST['Name'] ?? '');
        $description = trim($_POST['Description'] ?? '');
        $price       = floatval($_POST['Price'] ?? 0);
        $categoryID  = $_POST['CategoryID'] !== '' ? intval($_POST['CategoryID']) : null;

        $targetGender = $_POST['TargetGender'] ?? 'Unisex';
        if (!in_array($targetGender, ['Male', 'Female', 'Unisex'], true)) {
            $targetGender = 'Unisex';
        }

        // Validate ID
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid Product ID.']);
            exit;
        }

        // Validate Name
        if ($name === '') {
            echo json_encode(['success' => false, 'message' => 'Product name cannot be empty.']);
            exit;
        }

        // Validate Description
        if ($description === '') {
            echo json_encode(['success' => false, 'message' => 'Product description cannot be empty.']);
            exit;
        }

        // Validate Price
        if (!is_numeric($price) || $price <= 0) {
            echo json_encode(['success' => false, 'message' => 'Price must be a numeric value greater than 0.']);
            exit;
        }

        // 1) Update product
        $stmt = $pdo->prepare("
            UPDATE product
               SET Name = ?, Description = ?, Price = ?, CategoryID = ?, TargetGender = ?
             WHERE ProductID = ?
        ");
        $ok = $stmt->execute([$name, $description, $price, $categoryID, $targetGender, $id]);

        if (!$ok) {
            echo json_encode(['success' => false, 'message' => 'Update failed.']);
            exit;
        }

        // Prepare directories according to (possibly new) category
        [$uploadsDir, $imgDir, $imgCategoryDir] = prepare_image_dirs($pdo, $categoryID);

        // 2) Handle colors from EditColorNames[] / EditColorCodes[]
        $editColorNames = isset($_POST['EditColorNames']) && is_array($_POST['EditColorNames'])
            ? $_POST['EditColorNames']
            : [];
        $editColorCodes = isset($_POST['EditColorCodes']) && is_array($_POST['EditColorCodes'])
            ? $_POST['EditColorCodes']
            : [];

        // Fetch existing colors for this product
        $stmtColors = $pdo->prepare("
            SELECT ProductColorID, ColorName, ColorCode, IsDefault
            FROM product_colors
            WHERE ProductID = ?
        ");
        $stmtColors->execute([$id]);
        $existingColors = $stmtColors->fetchAll(PDO::FETCH_ASSOC);

        $existingByKey = [];
        $hasDefault    = false;

        foreach ($existingColors as $row) {
            $key = strtolower(trim((string)($row['ColorName'] ?? '')));
            if ($key !== '') {
                $existingByKey[$key] = $row;
            }
            if ((int)$row['IsDefault'] === 1) {
                $hasDefault = true;
            }
        }

        // index => ['id'=>ProductColorID|null,'name'=>ColorName]
        $colorMap = [];

        foreach ($editColorNames as $idx => $cNameRaw) {
            $cName = trim((string)$cNameRaw);
            if ($cName === '') {
                // block with empty color name – treat as generic
                $colorMap[$idx] = ['id' => null, 'name' => ''];
                continue;
            }

            $key = strtolower($cName);

            $rawCode = $editColorCodes[$idx] ?? '';
            $rawCode = trim($rawCode);
            if ($rawCode !== '' && $rawCode[0] !== '#') {
                $rawCode = '#' . $rawCode;
            }
            $code = $rawCode !== '' ? $rawCode : null;

            if (isset($existingByKey[$key])) {
                // update existing color row
                $row     = $existingByKey[$key];
                $colorID = (int)$row['ProductColorID'];

                $upd = $pdo->prepare("
                    UPDATE product_colors
                       SET ColorName = ?, ColorCode = ?
                     WHERE ProductColorID = ?
                ");
                $upd->execute([$cName, $code, $colorID]);

                $colorMap[$idx] = ['id' => $colorID, 'name' => $cName];

                unset($existingByKey[$key]); // mark used
            } else {
                // insert new color row
                $isDefault = $hasDefault ? 0 : 1;

                $insertColorStmt->execute([
                    ':pid'   => $id,
                    ':name'  => $cName,
                    ':code'  => $code,
                    ':isdef' => $isDefault
                ]);

                $newId = (int)$pdo->lastInsertId();
                if (!$hasDefault && $isDefault === 1) {
                    $hasDefault = true;
                }

                $colorMap[$idx] = ['id' => $newId, 'name' => $cName];
            }
        }

        // 3) Append new images per color from EditColorMainPhoto / EditColorExtraPhotos
        $sortStmt = $pdo->prepare("
            SELECT COALESCE(MAX(SortOrder), -1)
            FROM product_images
            WHERE ProductID = ?
        ");
        $sortStmt->execute([$id]);
        $sort = (int)$sortStmt->fetchColumn() + 1;

        // Main photos
        if (!empty($_FILES['EditColorMainPhoto']['name']) && is_array($_FILES['EditColorMainPhoto']['name'])) {
            foreach ($_FILES['EditColorMainPhoto']['name'] as $i => $origName) {
                if (empty($origName)) continue;
                if ($_FILES['EditColorMainPhoto']['error'][$i] !== UPLOAD_ERR_OK) continue;

                if (!isset($colorMap[$i])) {
                    $colorMap[$i] = ['id' => null, 'name' => ''];
                }
                $colorInfo = $colorMap[$i];
                $cName     = $colorInfo['name'];
                $cId       = $colorInfo['id'];

                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExtensions, true)) {
                    echo json_encode([
                        'success' => false,
                        'message' => "Invalid image format for main photo: $origName. Only JPG, PNG, GIF, WEBP, AVIF are allowed."
                    ]);
                    exit;
                }

                $tmp = $_FILES['EditColorMainPhoto']['tmp_name'][$i];

                // main = true
                $fileName      = build_image_filename($name, $cName, $ext, $uploadsDir, true);
                $targetUploads = rtrim($uploadsDir, '/\\') . '/' . $fileName;

                if (!move_uploaded_file($tmp, $targetUploads)) {
                    continue;
                }

                // ❌ On edit: DO NOT mirror to /img or /img_category/
                // Only /uploads/ should be updated; /img & /img_category keep first add main.

                // enforce only one main per color (for this product + color)
                $clearPrimaryStmt->execute([
                    ':pid'  => $id,
                    ':pcid' => $cId
                ]);

                // new main
                $insertImageStmt->execute([
                    ':pid'     => $id,
                    ':pcid'    => $cId,
                    ':path'    => $fileName,
                    ':sort'    => $sort,
                    ':primary' => 1
                ]);
                $sort++;
            }
        }

        // Extra photos
        if (!empty($_FILES['EditColorExtraPhotos']['name']) && is_array($_FILES['EditColorExtraPhotos']['name'])) {
            foreach ($_FILES['EditColorExtraPhotos']['name'] as $colorIndex => $namesArray) {
                if (!isset($colorMap[$colorIndex])) {
                    $colorMap[$colorIndex] = ['id' => null, 'name' => ''];
                }
                $colorInfo = $colorMap[$colorIndex];
                $cName     = $colorInfo['name'];
                $cId       = $colorInfo['id'];

                if (!is_array($namesArray)) continue;

                foreach ($namesArray as $j => $origName) {
                    if (empty($origName)) continue;
                    if ($_FILES['EditColorExtraPhotos']['error'][$colorIndex][$j] !== UPLOAD_ERR_OK) continue;

                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowedExtensions, true)) {
                        echo json_encode([
                            'success' => false,
                            'message' => "Invalid image format for extra photo: $origName. Only JPG, PNG, GIF, WEBP, AVIF are allowed."
                        ]);
                        exit;
                    }

                    $tmp = $_FILES['EditColorExtraPhotos']['tmp_name'][$colorIndex][$j];

                    // extra = false
                    $fileName      = build_image_filename($name, $cName, $ext, $uploadsDir, false);
                    $targetUploads = rtrim($uploadsDir, '/\\') . '/' . $fileName;

                    if (!move_uploaded_file($tmp, $targetUploads)) {
                        continue;
                    }

                    // ❌ On edit: extras also only live in /uploads/

                    $insertImageStmt->execute([
                        ':pid'     => $id,
                        ':pcid'    => $cId,
                        ':path'    => $fileName,
                        ':sort'    => $sort,
                        ':primary' => 0
                    ]);
                    $sort++;
                }
            }
        }

        echo json_encode(['success' => true, 'message' => 'Product updated!']);
        exit;
    }

    //
    // 🔹 DELETE PRODUCT (SOFT DELETE)
    //
    if ($action === 'delete') {
        $id = intval($_POST['ProductID'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid ID.']);
            exit;
        }

        // Soft delete: mark product as deleted, keep images & rows
        $stmt = $pdo->prepare("UPDATE product SET IsDeleted = 1 WHERE ProductID = ?");
        $ok   = $stmt->execute([$id]);

        echo json_encode([
            'success' => (bool)$ok,
            'message' => $ok ? 'Product archived (soft deleted).' : 'Delete failed.'
        ]);
        exit;
    }

    // 🔹 RESTORE PRODUCT (undo soft delete)
    if ($action === 'restore') {
        $id = intval($_POST['ProductID'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid ID.']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE product SET IsDeleted = 0 WHERE ProductID = ?");
        $ok   = $stmt->execute([$id]);

        echo json_encode([
            'success' => (bool)$ok,
            'message' => $ok ? 'Product reactivated.' : 'Restore failed.'
        ]);
        exit;
    }

    //
    // 🔹 DELETE SINGLE IMAGE (called from JS when clicking ✕ on thumbnail)
    //
    if ($action === 'delete_image') {
        $imageID = (int)($_POST['ImageID'] ?? 0);
        if ($imageID <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid image ID.']);
            exit;
        }

        $imgDir = __DIR__ . '/../img/';

        // Get file name + product ID + color + primary flag
        $st = $pdo->prepare("
            SELECT ImagePath, ProductID, ProductColorID, IsPrimary
            FROM product_images
            WHERE ImageID = ?
        ");
        $st->execute([$imageID]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['ImagePath'])) {
            echo json_encode(['success' => false, 'message' => 'Image not found.']);
            exit;
        }

        $fileName  = $row['ImagePath'];
        $productID = (int)$row['ProductID'];

        // Was this file the current representative? (i.e. exists in /img/)
        $wasRepresentative = is_file(rtrim($imgDir, '/\\') . '/' . $fileName);

        // Delete DB row
        $del = $pdo->prepare("DELETE FROM product_images WHERE ImageID = ?");
        $ok  = $del->execute([$imageID]);

        if ($ok) {
            // Delete physical files from all three locations
            delete_image_files($pdo, $fileName, $productID);

            // If representative image was removed, choose new preview
            if ($wasRepresentative) {
                refresh_representative_image($pdo, $productID);
            }

            echo json_encode(['success' => true, 'message' => 'Image deleted.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete image.']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Action not recognized.']);
    exit;
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
    exit;
}
