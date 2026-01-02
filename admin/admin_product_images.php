<?php
// admin_product_images.php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Content-Type: application/json; charset=utf-8");

require __DIR__ . '/../config.php';

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $productID = isset($_GET['ProductID']) ? (int)$_GET['ProductID'] : 0;
    if ($productID <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid ProductID.']);
        exit;
    }

    // ⬇️ Now include ProductColorID + ColorName, and sort by color
    $stmt = $pdo->prepare("
        SELECT
            pi.ImageID,
            pi.ProductColorID,
            pi.ImagePath,
            pi.SortOrder,
            pi.IsPrimary,
            pc.ColorName
        FROM product_images pi
        LEFT JOIN product_colors pc
               ON pc.ProductColorID = pi.ProductColorID
        WHERE pi.ProductID = ?
        ORDER BY
            pc.ColorName IS NULL,      -- generic / no color last
            pc.ColorName,
            pi.IsPrimary DESC,
            pi.SortOrder ASC,
            pi.ImageID ASC
    ");
    $stmt->execute([$productID]);
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'images'  => $images
    ]);
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
    exit;
}
