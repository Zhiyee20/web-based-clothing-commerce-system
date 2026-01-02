<?php
// admin_bulk_notif_action.php — AJAX endpoint for bulk approval / rejection
// of cancellation + return & refund requests.

session_start();
require __DIR__ . '/../config.php';

header('Content-Type: application/json');

// Optional: enforce admin only (recommended)
if (empty($_SESSION['user']) || (($_SESSION['user']['Role'] ?? '') !== 'Admin')) {
    http_response_code(403);
    echo json_encode([
        'ok'      => false,
        'message' => 'Unauthorized.'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok'      => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

// Read JSON body
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        'ok'      => false,
        'message' => 'Invalid JSON payload.'
    ]);
    exit;
}

$action    = $data['action'] ?? '';
$cancelIds = $data['cancel_ids'] ?? [];
$returnIds = $data['return_ids'] ?? [];

// Sanitize to integers and drop invalids
$cancelIds = array_values(array_unique(array_filter(array_map('intval', (array)$cancelIds))));
$returnIds = array_values(array_unique(array_filter(array_map('intval', (array)$returnIds))));

if (!in_array($action, ['approve_cancel_return', 'reject_cancel_return'], true)) {
    http_response_code(400);
    echo json_encode([
        'ok'      => false,
        'message' => 'Invalid action.'
    ]);
    exit;
}

if (!$cancelIds && !$returnIds) {
    http_response_code(400);
    echo json_encode([
        'ok'      => false,
        'message' => 'No requests selected.'
    ]);
    exit;
}

// Decide target status based on action
$newStatus = ($action === 'approve_cancel_return') ? 'Approved' : 'Rejected';

try {
    if (!isset($pdo)) {
        // If config.php hasn’t already created $pdo
        $pdo = new PDO(
            "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    $pdo->beginTransaction();

    // Normal cancellations
    if ($cancelIds) {
        $placeholders = implode(',', array_fill(0, count($cancelIds), '?'));
        $sql = "
            UPDATE ordercancellation
            SET Status = ?
            WHERE cancellationId IN ($placeholders)
        ";
        $stmt = $pdo->prepare($sql);
        $params = array_merge([$newStatus], $cancelIds);
        $stmt->execute($params);
    }

    // Return & refund requests
    if ($returnIds) {
        $placeholders = implode(',', array_fill(0, count($returnIds), '?'));
        $sql = "
            UPDATE ordercancellation
            SET Status = ?
            WHERE cancellationId IN ($placeholders)
        ";
        $stmt = $pdo->prepare($sql);
        $params = array_merge([$newStatus], $returnIds);
        $stmt->execute($params);
    }

    $pdo->commit();

    echo json_encode([
        'ok'              => true,
        'message'         => $newStatus === 'Approved'
            ? 'Requests approved.'
            : 'Requests rejected.',
        'status'          => $newStatus,
        'affected_cancel' => $cancelIds,
        'affected_return' => $returnIds,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'message' => 'Server error: ' . $e->getMessage(),
    ]);
}
