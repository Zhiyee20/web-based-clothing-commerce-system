<?php
// admin/reports/customer_service_export.php
declare(strict_types=1);

require __DIR__ . '/../../config.php';
session_start();

// Only admins
$user = $_SESSION['user'] ?? null;
if (!$user || ($user['Role'] ?? '') !== 'Admin') {
    header('Location: ../login.php');
    exit;
}

date_default_timezone_set('Asia/Kuala_Lumpur');

/* -----------------------------
   1) Date range
   ----------------------------- */
$start = isset($_GET['start']) && $_GET['start'] !== '' ? $_GET['start'] : null;
$end   = isset($_GET['end'])   && $_GET['end']   !== '' ? $_GET['end']   : null;

$params     = [];
$dateClause = '';

if ($start) {
    $dateClause       .= " AND oc.RequestedAt >= :ps";
    $params[':ps']     = $start . ' 00:00:00';
}
if ($end) {
    $dateClause       .= " AND oc.RequestedAt <= :pe";
    $params[':pe']     = $end . ' 23:59:59';
}

/* -----------------------------
   2) Cancellation requests
   ----------------------------- */
$sql = "
  SELECT
    oc.cancellationId,
    oc.OrderID,
    oc.Status,
    oc.Reason,
    oc.RequestedAt,
    u.Username
  FROM ordercancellation oc
  JOIN orders o ON o.OrderID = oc.OrderID
  LEFT JOIN user u ON u.UserID = o.UserID
  WHERE 1=1
  $dateClause
  ORDER BY oc.RequestedAt DESC, oc.cancellationId DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -----------------------------
   3) CSV headers
   ----------------------------- */
$filenameParts = ['customer_service'];
$filenameParts[] = $start ?: 'all';
$filenameParts[] = $end   ?: 'all';
$filename = implode('_', $filenameParts) . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');

fputcsv($out, [
    'Cancellation ID',
    'Order ID',
    'Username',
    'Status',
    'Reason',
    'Requested At',
]);

if ($rows) {
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['cancellationId'],
            $r['OrderID'],
            $r['Username'] ?? 'Unknown',
            $r['Status'],
            $r['Reason'],
            $r['RequestedAt'],
        ]);
    }
} else {
    fputcsv($out, ['No cancellation requests in this period']);
}

fclose($out);
exit;