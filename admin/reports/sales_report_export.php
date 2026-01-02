<?php
// admin/reports/sales_report_export.php
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
   1) Read date range from GET
   ----------------------------- */
$start = isset($_GET['start']) && $_GET['start'] !== '' ? $_GET['start'] : null;
$end   = isset($_GET['end'])   && $_GET['end']   !== '' ? $_GET['end']   : null;

$params     = [];
$dateClause = '';

if ($start) {
    $dateClause      .= " AND o.OrderDate >= :ps";
    $params[':ps']    = $start . ' 00:00:00';
}
if ($end) {
    $dateClause      .= " AND o.OrderDate <= :pe";
    $params[':pe']    = $end . ' 23:59:59';
}

/* -----------------------------
   2) Fetch orders for export
   ----------------------------- */
/*
   Tables used (based on your other code):
   - orders (OrderID, UserID, OrderDate, TotalAmt, Status, ...)
   - user   (UserID, Username, ...)
   - orderitem (OrderID, ProductID, Quantity, Price, ...)

   We export one row per order with:
   - Order ID
   - Order Date
   - Username
   - Status
   - Total Quantity (sum of orderitem.Quantity)
   - Total Amount
*/
$sql = "
  SELECT 
    o.OrderID,
    o.OrderDate,
    o.Status,
    o.TotalAmt,
    u.Username,
    COALESCE(SUM(oi.Quantity), 0) AS TotalQty
  FROM orders o
  LEFT JOIN user u      ON u.UserID   = o.UserID
  LEFT JOIN orderitem oi ON oi.OrderID = o.OrderID
  WHERE 1=1
  $dateClause
  GROUP BY 
    o.OrderID,
    o.OrderDate,
    o.Status,
    o.TotalAmt,
    u.Username
  ORDER BY o.OrderDate DESC, o.OrderID DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -----------------------------
   3) Output CSV headers
   ----------------------------- */
$filenameParts = ['sales_report'];
$filenameParts[] = $start ? $start : 'all';
$filenameParts[] = $end   ? $end   : 'all';
$filename = implode('_', $filenameParts) . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Optional: BOM for Excel (so UTF-8 looks correct)
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// CSV header row
fputcsv($out, [
    'Order ID',
    'Order Date',
    'Username',
    'Status',
    'Total Quantity',
    'Total Amount (RM)',
]);

/* -----------------------------
   4) Rows
   ----------------------------- */
if ($rows) {
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['OrderID'],
            $r['OrderDate'],
            $r['Username'] ?? 'Unknown',
            $r['Status'],
            (int)$r['TotalQty'],
            number_format((float)$r['TotalAmt'], 2, '.', ''), // keep numeric
        ]);
    }
}

fclose($out);
exit;