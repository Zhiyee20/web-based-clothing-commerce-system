<?php
require '../config.php';
include '../user/header.php';


$results = [];
$uploadedFile = '';
$error = '';

function h($v)
{
  return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}
function money_rm($n)
{
  return 'RM ' . number_format((float)$n, 2, '.', ',');
}
function product_img(string $photo): string
{
  $photo = trim($photo);
  $abs = __DIR__ . '../../uploads/' . $photo;
  return ($photo !== '' && is_file($abs)) ? ('../../uploads/' . $photo) : '../../uploads/default.jpg';
}

// --- Step 1: Check if a file was uploaded ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {

  // Extract file info safely
  $fileError = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
  $tmpName   = $_FILES['image']['tmp_name'] ?? '';
  $origName  = $_FILES['image']['name'] ?? '';
  $mimeType  = strtolower($_FILES['image']['type'] ?? '');
  $fileSize  = $_FILES['image']['size'] ?? 0;
  $ext       = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

  // --- ERROR CHECK 1: PHP upload error ---
  if ($fileError !== UPLOAD_ERR_OK) {
    if ($fileError === UPLOAD_ERR_NO_FILE) {
      $error = "Please select an image to upload.";
    } else {
      $error = "Upload failed. Please try again.";
    }
  }

  // --- ERROR CHECK 2: Ensure a valid uploaded file exists ---
  if ($error === '' && (!$tmpName || !is_uploaded_file($tmpName))) {
    $error = "Invalid upload. Please select a valid image file.";
  }

  // --- ERROR CHECK 3: Block unsupported formats ---
  $blockedExt  = ['heic', 'heif', 'heifs', 'heics', 'avif'];
  $blockedMime = ['image/heic', 'image/heif', 'image/avif'];

  if ($error === '' && (in_array($ext, $blockedExt, true) || in_array($mimeType, $blockedMime, true))) {
    $error = "This image format (HEIC / HEIF / AVIF) is not supported. Please upload JPG, PNG, WEBP, GIF, BMP, or TIFF.";
  }

  // --- ERROR CHECK 4: Must be an image MIME type ---
  if ($error === '' && strpos($mimeType, 'image/') !== 0) {
    $error = "Invalid file type. Please upload an image file only.";
  }

  // --- ERROR CHECK 5: File size limit ---
  if ($error === '' && $fileSize > 5 * 1024 * 1024) {
    $error = "Your image is too large. Maximum allowed size is 5MB.";
  }

  // Prepare upload folder and target file only if no error
  if ($error === '') {
    $uploadDir = '../uploads/';
    if (!is_dir($uploadDir)) {
      mkdir($uploadDir, 0777, true);
    }

    $fileName   = basename($origName);
    $targetFile = $uploadDir . $fileName;
  }

  // --- Step 2: Move uploaded file ---
  if ($error === '' && move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {

    $uploadedFile = $targetFile;
    $uploadedFileWeb = $uploadDir . $fileName;

    // --- Step 3: Call Python script ---
    // $cmd = "python search_clip_upload.py " . escapeshellarg($targetFile);
    // $jsonOutput = shell_exec($cmd);

    // // --- Step 4: Decode JSON directly ---
    // $data = json_decode($jsonOutput, true);

    // if (json_last_error() !== JSON_ERROR_NONE) {
    //     $error = "Failed to parse recommendation results: " . json_last_error_msg();
    //     $images = [];
    // } else {
    //     $images = $data['top_similar'] ?? [];
    // }
    // ------------------------------
    // Call FastAPI via cURL
    // ------------------------------
    $post = [
      'file' => curl_file_create($targetFile, $_FILES['image']['type'], $_FILES['image']['name'])
    ];

    $ch = curl_init("http://127.0.0.1:8000/search");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Optional: verbose output for debugging
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    $verbose = fopen('php://temp', 'w+');
    curl_setopt($ch, CURLOPT_STDERR, $verbose);

    $response = curl_exec($ch);
    if ($response === false) {
      $error = "cURL error: " . curl_error($ch);
    }
    curl_close($ch);

    // Show verbose info
    // rewind($verbose);
    // $verboseLog = stream_get_contents($verbose);
    // fclose($verbose);
    // if ($verboseLog) {
    //     echo "<pre>cURL verbose info:\n$verboseLog</pre>";
    // }

    // ------------------------------
    // Decode JSON response
    // ------------------------------
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
      $error = "JSON decode error: " . json_last_error_msg();
      $images = [];
    } else {
      $images = $data['top_similar'] ?? [];
      $category = $data['category'] ?? null;
    }

    // --- Step 5: Lookup each recommended image in DB ---
    $results = [];
    foreach ($images as $fname) {
      $basename = basename($fname);

      $stmt = $pdo->prepare("
    SELECT 
        p.ProductID,
        p.Name,
        pi.ImageID,
        p.Price,
        pi.ImagePath
    FROM product p
    JOIN product_images pi 
        ON p.ProductID = pi.ProductID
    JOIN product_colors pc
        ON pc.ProductID = p.ProductID
    JOIN product_color_sizes pcs
        ON pcs.ProductColorID = pc.ProductColorID
    WHERE pi.ImagePath = :photo
    GROUP BY 
        p.ProductID,
        p.Name,
        pi.ImageID,
        p.Price,
        pi.ImagePath
    HAVING SUM(pcs.Stock) > 0
    LIMIT 1
");

      $stmt->execute([':photo' => $basename]);
      $product = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($product) {
        $results[] = [
          'Photo' => $product['ImagePath'],
          'Name' => $product['Name'],
          'Price' => $product['Price'],
          'ProductID' => $product['ProductID'],
          'ImageID' => $product['ImageID']
        ];
      }
      // If $product is false, DO NOT push a fallback item.
      // Simply skip it, so out-of-stock / invalid items are ignored.

    }

    // --- Step 6: Save recommendations for logged-in user ---
    if (!empty($results) && isset($_SESSION['user']['UserID'])) {
      $userId = (int)($_SESSION['user']['UserID']);

      // Delete existing recommendations
      $stmtDelete = $pdo->prepare("DELETE FROM recommend WHERE userid = :userid");
      $stmtDelete->execute([':userid' => $userId]);

      // Insert new recommendations
      $stmtInsert = $pdo->prepare("INSERT INTO recommend (userid, productid, imageid) VALUES (:userid, :productid, :imageid)");
      foreach ($results as $p) {
        $pid = (int)($p['ProductID'] ?? 0);
        $iid = (int)($p['ImageID'] ?? 0);
        if ($pid > 0) {
          $stmtInsert->execute([
            ':userid' => $userId,
            ':productid' => $pid,
            ':imageid' => $iid
          ]);
        }
      }
    }
  } else {
    // generic error 
    if ($error === '') {
      $error = "Failed to upload file. Please try again.";
    }
  }

  // --- DELETE uploaded image after processing (temporary search image) ---
  if (!empty($uploadedFile) && is_file($uploadedFile)) {
    unlink($uploadedFile);
  }
} else {
  echo "<p>DEBUG: No file uploaded.</p>";
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

      /* Ensure page fills laptop viewport height */
      min-height: calc(100vh - 160px);
      box-sizing: border-box;
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
      margin-top: 20vh;
      font-size: 16px;
    }
  </style>
</head>

<body>

  <section class="catalog">
    <div class="catalog-wrap">
      <?php if ($error): ?>
        <p style="max-width:800px;margin:16px auto;padding:10px;background:#ffe6e6;color:#a30000;border:1px solid #f5b5b5;border-radius:6px;">
          <?= h($error) ?>
        </p>
      <?php endif; ?>
      <h2 class="catalog-title">Similar Items</h2>
      <?php if (!$results): ?>
        <p class="empty">No products found.</p>
      <?php else: ?>
        <div class="catalog-grid">
          <?php foreach ($results as $p): ?>
            <article class="catalog-item">
              <a class="catalog-card" href="../user/product_detail.php?ProductID=<?= urlencode($p['ProductID']) ?>">
                <img class="catalog-img" src="<?= h(product_img($p['Photo'] ?? '')) ?>" alt="<?= h($p['Name']) ?>" loading="lazy">
              </a>
              <h3 class="catalog-name">
                <a href="../user/product_detail.php?ProductID=<?= urlencode($p['ProductID']) ?>"><?= h($p['Name']) ?></a>
              </h3>
              <p class="catalog-price"><?= money_rm($p['Price']) ?></p>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <?php include '../user/footer.php'; ?>
</body>

</html>