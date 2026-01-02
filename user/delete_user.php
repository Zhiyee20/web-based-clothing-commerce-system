<?php
require __DIR__ . '/../config.php';
session_start();

if (!isset($_POST['UserID'])) {
    die("Invalid request.");
}

$id = (int)$_POST['UserID'];

// Ensure logged-in user matches
if (!isset($_SESSION['user']) || $_SESSION['user']['UserID'] != $id) {
    die("Unauthorized request.");
}

try {
    $pdo->beginTransaction();

    // Soft delete: mark user as deleted
    $stmt = $pdo->prepare("UPDATE user SET IsDeleted = 1, DeletedAt = NOW() WHERE UserID = ?");
    $stmt->execute([$id]);

    $pdo->commit();

    // Log out user
    session_destroy();

    echo "User deleted successfully";
} catch (PDOException $e) {
    $pdo->rollBack();
    echo "Error: " . $e->getMessage();
}
