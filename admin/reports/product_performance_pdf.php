<?php
// admin/reports/product_performance_pdf.php
declare(strict_types=1);

require __DIR__ . '/../../config.php';
session_start();

$user = $_SESSION['user'] ?? null;
if (!$user || ($user['Role'] ?? '') !== 'Admin') {
    header('Location: ../login.php');
    exit;
}

date_default_timezone_set('Asia/Kuala_Lumpur');

$periodStart = isset($_GET['start']) && $_GET['start'] !== '' ? $_GET['start'] : null;
$periodEnd   = isset($_GET['end'])   && $_GET['end']   !== '' ? $_GET['end']   : null;

$dateClause = '';
$params     = [];

if ($periodStart) {
    $dateClause         .= " AND o.OrderDate >= :from";
    $params[':from']     = $periodStart . ' 00:00:00';
}
if ($periodEnd) {
    $dateClause         .= " AND o.OrderDate <= :to";
    $params[':to']       = $periodEnd . ' 23:59:59';
}

/*
 * Product performance PER COLOUR:
 *  - Row level: Product + Colour
 *  - Stock / MinStock: summed from product_color_sizes for that ProductColorID
 *  - QtySold / Revenue: from orderitem (matching ProductID + ColorName) + orders
 *
 *  NOTE:
 *    We read *current stock in hand* from product_color_sizes.Stock.
 *    stock_movements is your movement ledger used for history/analysis,
 *    not needed here to show current stock snapshot.
 */
$sql = "
    SELECT 
        p.ProductID,
        p.Name AS ProductName,
        COALESCE(cat.CategoryName, 'Uncategorised') AS CategoryName,
        pc.ProductColorID,
        pc.ColorName,
        p.Price AS CurrentPrice,

        /* Sum stock across all sizes for this colour (current snapshot) */
        (
            SELECT COALESCE(SUM(pcs.Stock), 0)
            FROM product_color_sizes pcs
            WHERE pcs.ProductColorID = pc.ProductColorID
        ) AS ColorStock,

        /* Sum min stock across all sizes for this colour */
        (
            SELECT COALESCE(SUM(pcs.MinStock), 0)
            FROM product_color_sizes pcs
            WHERE pcs.ProductColorID = pc.ProductColorID
        ) AS ColorMinStock,

        COALESCE(SUM(oi.Quantity), 0)            AS QtySold,
        COALESCE(SUM(oi.Quantity * oi.Price), 0) AS Revenue
    FROM product p
    INNER JOIN product_colors pc 
            ON pc.ProductID = p.ProductID
    LEFT JOIN categories cat 
           ON cat.CategoryID = p.CategoryID

    /* Join order items by product + colour (orderitem.ColorName) */
    LEFT JOIN orderitem oi 
           ON oi.ProductID = p.ProductID
          AND oi.ColorName = pc.ColorName
    LEFT JOIN orders o
           ON o.OrderID = oi.OrderID
          AND o.Status <> 'Cancel / Return & Refund'
          $dateClause

    GROUP BY 
        p.ProductID,
        p.Name,
        cat.CategoryName,
        pc.ProductColorID,
        pc.ColorName,
        p.Price
    ORDER BY 
        Revenue DESC,
        QtySold DESC,
        p.Name,
        pc.ColorName
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* --------- Aggregate metrics (overall, across all colours) ---------- */
$totalRevenue = 0.0;
$totalQty     = 0;

foreach ($rows as $r) {
    $totalRevenue += (float)$r['Revenue'];
    $totalQty     += (int)$r['QtySold'];
}

$companyName = 'Luxera Store';
$reportTitle = 'Product Performance Report (By Colour)';
$nowDate     = date('Y-m-d');
$nowTime     = date('H:i:s');

$periodLabel = 'All Time';
if ($periodStart && $periodEnd) {
    $periodLabel = $periodStart . ' to ' . $periodEnd;
} elseif ($periodStart) {
    $periodLabel = 'From ' . $periodStart;
} elseif ($periodEnd) {
    $periodLabel = 'Until ' . $periodEnd;
}

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($reportTitle) ?></title>
<style>
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; color:#000; }
  .header { text-align:center; margin-bottom:16px; }
  .header h1 { margin:0; font-size:20px; text-transform:uppercase; }
  .header h2 { margin:4px 0 0; font-size:14px; font-weight:normal; }

  .meta-table { width:100%; border-collapse:collapse; margin-bottom:10px; }
  .meta-table td { padding:3px 4px; }
  .meta-label { width:90px; font-weight:bold; }

  .summary { margin:6px 0 12px; padding:6px 8px; border:1px solid #000; font-size:10px; }
  .summary span { display:inline-block; margin-right:12px; }

  table.data { width:100%; border-collapse:collapse; font-size:9px; }
  table.data th, table.data td { border:1px solid #000; padding:3px 4px; white-space:nowrap; }
  table.data th { background:#f3f3f3; }

  .text-right { text-align:right; }
  .text-center { text-align:center; }
</style>
</head>
<body>

<div class="header">
  <h1><?= htmlspecialchars($companyName) ?></h1>
  <h2><?= htmlspecialchars($reportTitle) ?></h2>
</div>

<table class="meta-table">
  <tr>
    <td class="meta-label">Generated On:</td>
    <td><?= htmlspecialchars($nowDate . ' ' . $nowTime) ?> (MYT)</td>
    <td class="meta-label">Prepared By:</td>
    <td><?= htmlspecialchars($user['Username'] ?? 'System') ?></td>
  </tr>
  <tr>
    <td class="meta-label">Period:</td>
    <td><?= htmlspecialchars($periodLabel) ?></td>
    <td class="meta-label">Total Product Colours:</td>
    <td><?= number_format(count($rows)) ?></td>
  </tr>
</table>

<div class="summary">
  <span><strong>Total Revenue (RM):</strong> <?= number_format($totalRevenue, 2) ?></span>
  <span><strong>Total Qty Sold:</strong> <?= number_format($totalQty) ?></span>
</div>

<table class="data">
  <thead>
    <tr>
      <th class="text-center" style="width:20px;">#</th>
      <th style="width:80px;">Product</th>
      <th style="width:60px;">Colour</th>
      <th style="width:70px;">Category</th>
      <th class="text-right" style="width:50px;">Current Price</th>
      <th class="text-right" style="width:55px;">Stock (Colour)</th>
      <th class="text-right" style="width:65px;">Min Stock (Colour)</th>
      <th class="text-right" style="width:50px;">Qty Sold</th>
      <th class="text-right" style="width:60px;">Revenue (RM)</th>
    </tr>
  </thead>
  <tbody>
    <?php if ($rows): $i = 1; foreach ($rows as $r): ?>
      <tr>
        <td class="text-center"><?= $i++ ?></td>
        <td><?= htmlspecialchars($r['ProductName']) ?></td>
        <td><?= htmlspecialchars($r['ColorName']) ?></td>
        <td><?= htmlspecialchars($r['CategoryName']) ?></td>
        <td class="text-right"><?= number_format((float)$r['CurrentPrice'], 2) ?></td>
        <td class="text-right"><?= (int)$r['ColorStock'] ?></td>
        <td class="text-right"><?= (int)$r['ColorMinStock'] ?></td>
        <td class="text-right"><?= (int)$r['QtySold'] ?></td>
        <td class="text-right"><?= number_format((float)$r['Revenue'], 2) ?></td>
      </tr>
    <?php endforeach; else: ?>
      <tr>
        <td colspan="9" class="text-center">No product data found for this period.</td>
      </tr>
    <?php endif; ?>
  </tbody>
</table>

</body>
</html>
<?php
$html = ob_get_clean();

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape'); // landscape for wide table
$dompdf->render();

$filename = 'product_performance_colour_' . date('Ymd_His') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;