<?php
// admin/admin_ajax_add_category.php
session_start();
require __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

// (Optional) restrict to admins
if (empty($_SESSION['user']) || ($_SESSION['user']['Role'] ?? '') !== 'Admin') {
    echo json_encode(['ok' => false, 'error' => 'Not allowed.']);
    exit;
}

$name = trim($_POST['CategoryName'] ?? '');
$sgg  = $_POST['SizeGuideGroup'] ?? null;   // can be TOP/BOTTOM/DRESS or empty

if ($name === '') {
    echo json_encode(['ok' => false, 'error' => 'Category name is required.']);
    exit;
}

// empty string → NULL for SizeGuideGroup
if ($sgg === '') {
    $sgg = null;
}

try {
    // categories: CategoryID, CategoryName, IsDeleted (default 0), DeletedAt, SizeGuideGroup
    $stmt = $pdo->prepare("
        INSERT INTO categories (CategoryName, SizeGuideGroup)
        VALUES (?, ?)
    ");
    $stmt->execute([$name, $sgg]);

    $newId = (int)$pdo->lastInsertId();

    echo json_encode([
        'ok'            => true,
        'CategoryID'    => $newId,
        'CategoryName'  => $name,
        'SizeGuideGroup'=> $sgg,
    ]);
} catch (PDOException $e) {
    // Duplicate CategoryName (UNIQUE) or other DB errors
    if ($e->getCode() === '23000') {
        echo json_encode(['ok' => false, 'error' => 'Category name already exists.']);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}
