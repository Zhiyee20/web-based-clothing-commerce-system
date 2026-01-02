<?php
// user/set_default.php
declare(strict_types=1);
header('Content-Type: application/json');

// Use the same DB + session as my_addresses.php
include '../login_base.php';

// Logged-in user
$userID = $_SESSION['user']['UserID'] ?? null;
if (!$userID) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'User is not logged in.'
    ]);
    exit;
}

// Get AddressID from POST
$addressID = isset($_POST['AddressID']) ? (int)$_POST['AddressID'] : 0;
if ($addressID <= 0) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Invalid AddressID.'
    ]);
    exit;
}

try {
    // Start transaction
    $_db->beginTransaction();

    // (1) Check that this address actually belongs to this user
    $check = $_db->prepare("
        SELECT AddressID
          FROM user_address
         WHERE AddressID = ?
           AND UserID    = ?
        LIMIT 1
    ");
    $check->execute([$addressID, $userID]);
    if (!$check->fetch()) {
        $_db->rollBack();
        echo json_encode([
            'status'  => 'error',
            'message' => 'Address not found for this user.'
        ]);
        exit;
    }

    // (2) Clear any existing default for THIS user
    $stmt = $_db->prepare("UPDATE user_address SET IsDefault = 0 WHERE UserID = ?");
    $stmt->execute([$userID]);

    // (3) Set THIS address as default for THIS user
    $stmt = $_db->prepare("
        UPDATE user_address
           SET IsDefault = 1
         WHERE AddressID = ?
           AND UserID    = ?
    ");
    $stmt->execute([$addressID, $userID]);

    if ($stmt->rowCount() === 0) {
        // Nothing updated -> rollback
        $_db->rollBack();
        echo json_encode([
            'status'  => 'error',
            'message' => 'Failed to set default address.'
        ]);
        exit;
    }

    // Commit transaction
    $_db->commit();

    echo json_encode([
        'status'  => 'success',
        'message' => 'Default address updated.'
    ]);

} catch (Throwable $e) {
    if ($_db->inTransaction()) {
        $_db->rollBack();
    }
    echo json_encode([
        'status'  => 'error',
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
