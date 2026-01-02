<?php
// admin/reports/product_performance_export.php
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
    $dateClause       .= " AND sm.CreatedAt >= :ps";
    $params[':ps']     = $start . ' 00:00:00';
}
if ($end) {
    $dateClause       .= " AND sm.CreatedAt <= :pe";
    $params[':pe']     = $end . ' 23:59:59';
}

/* -----------------------------
   2) Stock movement history
   ----------------------------- */
/*
  This matches the detail_report "Stock Movement History" section:
  - stock_movements sm
  - product_color_sizes pcs
  - product_colors pc
  - product p
*/

$sql = "
  SELECT
    sm.MovementID,
    sm.CreatedAt,
    sm.MovementType,
    sm.Reason,
    sm.QtyChange,
    sm.OldStock,
    sm.NewStock,
    sm.ReferenceType,
    sm.ReferenceID,
    sm.Note,
    u.Username AS PerformedBy,
    p.ProductID,
    p.Name AS ProductName,
    pc.ColorName,
    pcs.Size
  FROM stock_movements sm
  JOIN product_color_sizes pcs ON pcs.ColorSizeID    = sm.ColorSizeID
  JOIN product_colors pc       ON pc.ProductColorID  = pcs.ProductColorID
  JOIN product p               ON p.ProductID        = pc.ProductID
  LEFT JOIN user u             ON u.UserID           = sm.PerformedBy
  WHERE 1=1
  $dateClause
  ORDER BY sm.CreatedAt DESC, sm.MovementID DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -----------------------------
   3) CSV headers
   ----------------------------- */
$filenameParts = ['stock_movements'];
$filenameParts[] = $start ?: 'all';
$filenameParts[] = $end   ?: 'all';
$filename = implode('_', $filenameParts) . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// UTF-8 BOM
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Header row
fputcsv($out, [
    'Movement ID',
    'Date',
    'Product ID',
    'Product Name',
    'Color',
    'Size',
    'Movement Type',
    'Reason',
    'Qty Change',
    'Old Stock',
    'New Stock',
    'Reference Type',
    'Reference ID',
    'Performed By',
    'Note',
]);

/* -----------------------------
   4) Rows
   ----------------------------- */
if ($rows) {
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['MovementID'],
            $r['CreatedAt'],
            $r['ProductID'],
            $r['ProductName'],
            $r['ColorName'],
            $r['Size'],
            $r['MovementType'],
            $r['Reason'],
            (int)$r['QtyChange'],
            (int)$r['OldStock'],
            (int)$r['NewStock'],
            $r['ReferenceType'],
            $r['ReferenceID'],
            $r['PerformedBy'],
            $r['Note'],
        ]);
    }
}

fclose($out);
exit;