<?php
// user/wishlist_toggle.php

require __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// IMPORTANT: do NOT output PHP warnings into JSON
error_reporting(E_ALL);
ini_set('display_errors', '0');

// 1) Auth
$user = $_SESSION['user'] ?? null;
if (!$user) {
    echo json_encode(['ok' => false, 'error' => 'not_logged_in']);
    exit;
}
$userID = (int)$user['UserID'];

// 2) Input
$productID = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
$action    = $_POST['action'] ?? '';

if ($productID <= 0 || !in_array($action, ['add','remove'], true)) {
    echo json_encode(['ok' => false, 'error' => 'invalid_input']);
    exit;
}

try {
    // Optional: check product exists & is active
    $stP = $pdo->prepare("SELECT ProductID FROM product WHERE ProductID = ?");
    $stP->execute([$productID]);
    if (!$stP->fetchColumn()) {
        echo json_encode(['ok' => false, 'error' => 'product_not_found']);
        exit;
    }

    if ($action === 'add') {
        $st = $pdo->prepare("
            INSERT INTO wishlist_items (UserID, ProductID)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE CreatedAt = NOW()
        ");
        $st->execute([$userID, $productID]);

        echo json_encode(['ok' => true, 'status' => 'added']);
        exit;
    } else {
        $st = $pdo->prepare("
            DELETE FROM wishlist_items
            WHERE UserID = ? AND ProductID = ?
        ");
        $st->execute([$userID, $productID]);

        echo json_encode(['ok' => true, 'status' => 'removed']);
        exit;
    }
} catch (Throwable $e) {
    // while debugging you can temporarily include $e->getMessage()
    echo json_encode(['ok' => false, 'error' => 'db_error']);
    exit;
}
