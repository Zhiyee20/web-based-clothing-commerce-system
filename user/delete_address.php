<?php
// user/delete_address.php
declare(strict_types=1);
header('Content-Type: application/json');

include '../login_base.php';

// Ensure user is logged in
$userID = $_SESSION['user']['UserID'] ?? null;
if (!$userID) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Not logged in.'
    ]);
    exit;
}

// Get AddressID from POST
$addrID = isset($_POST['AddressID']) ? (int)$_POST['AddressID'] : 0;
if ($addrID <= 0) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Invalid address ID.'
    ]);
    exit;
}

// Delete ONLY this user's address (safer)
$stmt = $_db->prepare("
    DELETE FROM user_address
     WHERE AddressID = ?
       AND UserID = ?
");
$stmt->execute([$addrID, $userID]);

if ($stmt->rowCount() > 0) {
    echo json_encode([
        'status'  => 'success',
        'message' => 'Address deleted successfully.'
    ]);
} else {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Address not found or could not be deleted.'
    ]);
}
