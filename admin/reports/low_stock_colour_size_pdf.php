<?php
// admin/reports/stock_colour_size_movement_pdf.php
declare(strict_types=1);

require __DIR__ . '/../../config.php';
session_start();

$user = $_SESSION['user'] ?? null;
if (!$user || ($user['Role'] ?? '') !== 'Admin') {
    header('Location: ../login.php');
    exit;
}

date_default_timezone_set('Asia/Kuala_Lumpur');

/* ---------------------------------------------
   1) Optional period filter (by stock_movements.CreatedAt)
   --------------------------------------------- */
$periodStart = isset($_GET['start']) && $_GET['start'] !== '' ? $_GET['start'] : null;
$periodEnd   = isset($_GET['end'])   && $_GET['end']   !== '' ? $_GET['end']   : null;

$dateClause = '';
$params = [];

if ($periodStart) {
    $dateClause      .= " AND sm.CreatedAt >= :from";
    $params[':from']  = $periodStart . ' 00:00:00';
}
if ($periodEnd) {
    $dateClause      .= " AND sm.CreatedAt <= :to";
    $params[':to']    = $periodEnd . ' 23:59:59';
}

/* ---------------------------------------------
   2) Main query: ALL colour-size rows with
      stock in / out / adjust + status
   --------------------------------------------- */
/*
   Tables based on your SQL:
   - product               (ProductID, Name, CategoryID, ...)
   - categories            (CategoryID, CategoryName)
   - product_colors        (ProductColorID, ProductID, ColorName, ColorCode, ...)
   - product_color_sizes   (ColorSizeID, ProductColorID, Size, Stock, MinStock, ...)
   - stock_movements       (MovementID, ColorSizeID, MovementType, Reason,
                            QtyChange, OldStock, NewStock, CreatedAt, ...)
*/
$sql = "
    SELECT
        p.ProductID,
        p.Name                 AS ProductName,
        cat.CategoryName       AS CategoryName,
        pc.ColorName           AS ColorName,
        pcs.Size               AS Size,
        pcs.Stock              AS CurrentStock,
        pcs.MinStock           AS MinStock,

        -- Movement summary IN / OUT / ADJUST
        COALESCE(SUM(
            CASE WHEN sm.MovementType = 'IN' THEN sm.QtyChange ELSE 0 END
        ), 0) AS QtyIn,

        COALESCE(SUM(
            CASE WHEN sm.MovementType = 'OUT' THEN ABS(sm.QtyChange) ELSE 0 END
        ), 0) AS QtyOut,

        COALESCE(SUM(
            CASE WHEN sm.MovementType = 'ADJUST' THEN sm.QtyChange ELSE 0 END
        ), 0) AS QtyAdjust,

        COALESCE(SUM(sm.QtyChange), 0) AS NetMovement,

        MIN(sm.CreatedAt) AS FirstMovementAt,
        MAX(sm.CreatedAt) AS LastMovementAt
    FROM product_color_sizes pcs
    JOIN product_colors pc
      ON pc.ProductColorID = pcs.ProductColorID
    JOIN product p
      ON p.ProductID = pc.ProductID
    LEFT JOIN categories cat
      ON cat.CategoryID = p.CategoryID
    LEFT JOIN stock_movements sm
      ON sm.ColorSizeID = pcs.ColorSizeID
     $dateClause
    GROUP BY
        p.ProductID,
        p.Name,
        cat.CategoryName,
        pc.ColorName,
        pcs.Size,
        pcs.Stock,
        pcs.MinStock
    ORDER BY
        cat.CategoryName,
        p.Name,
        pc.ColorName,
        FIELD(pcs.Size, 'XS','S','M','L','XL', 'XXL', 'XXXL')
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------------------------------------------
   3) Summary totals
   --------------------------------------------- */
$totalVariants   = count($rows);
$totalQtyIn      = 0;
$totalQtyOut     = 0;
$totalQtyAdjust  = 0;
$totalNetMove    = 0;
$totalCurrent    = 0;
$totalMin        = 0;

foreach ($rows as $r) {
    $totalQtyIn     += (int)$r['QtyIn'];
    $totalQtyOut    += (int)$r['QtyOut'];
    $totalQtyAdjust += (int)$r['QtyAdjust'];
    $totalNetMove   += (int)$r['NetMovement'];
    $totalCurrent   += (int)$r['CurrentStock'];
    $totalMin       += (int)$r['MinStock'];
}

/* ---------------------------------------------
   4) Report meta info
   --------------------------------------------- */
$companyName = 'Luxera Store';
$reportTitle = 'Stock Movement by Colour & Size';
$nowDate     = date('Y-m-d');
$nowTime     = date('H:i:s');

$periodLabel = 'All Movements';
if ($periodStart && $periodEnd) {
    $periodLabel = $periodStart . ' to ' . $periodEnd;
} elseif ($periodStart) {
    $periodLabel = 'From ' . $periodStart;
} elseif ($periodEnd) {
    $periodLabel = 'Until ' . $periodEnd;
}

/* ---------------------------------------------
   5) Build HTML (for Dompdf)
   --------------------------------------------- */
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($reportTitle) ?></title>
<style>
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9px; color:#000; }
  .header { text-align:center; margin-bottom:16px; }
  .header h1 { margin:0; font-size:20px; text-transform:uppercase; }
  .header h2 { margin:4px 0 0; font-size:14px; font-weight:normal; }

  .meta-table { width:100%; border-collapse:collapse; margin-bottom:10px; }
  .meta-table td { padding:3px 4px; }
  .meta-label { width:90px; font-weight:bold; }

  .summary { margin:6px 0 12px; padding:6px 8px; border:1px solid #000; font-size:9px; }
  .summary span { display:inline-block; margin-right:12px; }

  table.data { width:100%; border-collapse:collapse; font-size:8px; }
  table.data th, table.data td { border:1px solid #000; padding:3px 4px; white-space:nowrap; }
  table.data th { background:#f3f3f3; }

  .text-right { text-align:right; }
  .text-center { text-align:center; }

  .status-ok  { color:#166534; font-weight:bold; }   /* green */
  .status-low { color:#b45309; font-weight:bold; }   /* amber */
  .status-out { color:#b91c1c; font-weight:bold; }   /* red */
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
    <td class="meta-label">Colour-Size Items:</td>
    <td><?= number_format($totalVariants) ?></td>
  </tr>
</table>

<div class="summary">
  <span><strong>Total Current Stock (pcs):</strong> <?= number_format($totalCurrent) ?></span>
  <span><strong>Total Min Stock (pcs):</strong> <?= number_format($totalMin) ?></span>
  <span><strong>Total Stock In (pcs):</strong> <?= number_format($totalQtyIn) ?></span>
  <span><strong>Total Stock Out (pcs):</strong> <?= number_format($totalQtyOut) ?></span>
  <span><strong>Total Adjust (pcs):</strong> <?= number_format($totalQtyAdjust) ?></span>
  <span><strong>Total Net Movement (pcs):</strong> <?= number_format($totalNetMove) ?></span>
</div>

<table class="data">
  <thead>
    <tr>
      <th class="text-center" style="width:18px;">#</th>
      <th style="width:70px;">Product</th>
      <th style="width:55px;">Category</th>
      <th style="width:45px;">Colour</th>
      <th style="width:25px;">Size</th>
      <th class="text-right" style="width:35px;">Current</th>
      <th class="text-right" style="width:35px;">Min</th>
      <th style="width:35px;">Status</th>
      <th class="text-right" style="width:40px;">Stock In</th>
      <th class="text-right" style="width:40px;">Stock Out</th>
      <th class="text-right" style="width:40px;">Adjust</th>
      <th class="text-right" style="width:45px;">Net Move</th>
      <th style="width:55px;">First Move</th>
      <th style="width:55px;">Last Move</th>
    </tr>
  </thead>
  <tbody>
    <?php if ($rows): $i = 1; ?>
      <?php foreach ($rows as $r): ?>
        <?php
          $current = (int)$r['CurrentStock'];
          $min     = (int)$r['MinStock'];

          if ($current <= 0) {
              $statusLabel = 'OUT';
              $statusClass = 'status-out';
          } elseif ($min > 0 && $current <= $min) {
              $statusLabel = 'LOW';
              $statusClass = 'status-low';
          } else {
              $statusLabel = 'OK';
              $statusClass = 'status-ok';
          }
        ?>
        <tr>
          <td class="text-center"><?= $i++ ?></td>
          <td><?= htmlspecialchars($r['ProductName']) ?></td>
          <td><?= htmlspecialchars($r['CategoryName'] ?? '-') ?></td>
          <td><?= htmlspecialchars($r['ColorName']) ?></td>
          <td class="text-center"><?= htmlspecialchars($r['Size']) ?></td>
          <td class="text-right"><?= $current ?></td>
          <td class="text-right"><?= $min ?></td>
          <td class="<?= $statusClass ?> text-center"><?= $statusLabel ?></td>
          <td class="text-right"><?= (int)$r['QtyIn'] ?></td>
          <td class="text-right"><?= (int)$r['QtyOut'] ?></td>
          <td class="text-right"><?= (int)$r['QtyAdjust'] ?></td>
          <td class="text-right"><?= (int)$r['NetMovement'] ?></td>
          <td><?= htmlspecialchars($r['FirstMovementAt'] ?? '-') ?></td>
          <td><?= htmlspecialchars($r['LastMovementAt'] ?? '-') ?></td>
        </tr>
      <?php endforeach; ?>
    <?php else: ?>
      <tr>
        <td colspan="14" class="text-center">
          No stock records found for this period.
        </td>
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
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

$filename = 'stock_colour_size_movement_' . date('Ymd_His') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;