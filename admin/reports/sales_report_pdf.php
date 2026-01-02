<?php
// admin/reports/sales_report_pdf.php
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

// Date filters
$periodStart = isset($_GET['start']) && $_GET['start'] !== '' ? $_GET['start'] : null;
$periodEnd   = isset($_GET['end'])   && $_GET['end']   !== '' ? $_GET['end']   : null;

$orderDateClause = '';
$params = [];

if ($periodStart) {
    $orderDateClause        .= " AND o.OrderDate >= :od_from";
    $params[':od_from']      = $periodStart . ' 00:00:00';
}
if ($periodEnd) {
    $orderDateClause        .= " AND o.OrderDate <= :od_to";
    $params[':od_to']        = $periodEnd . ' 23:59:59';
}

// Fetch orders (exclude cancelled/refunded)
$sql = "
    SELECT 
        o.OrderID,
        o.OrderDate,
        o.Status,
        o.TotalAmt,
        u.Username
    FROM orders o
    LEFT JOIN user u ON u.UserID = o.UserID
    WHERE o.Status <> 'Cancel / Return & Refund'
    $orderDateClause
    ORDER BY o.OrderDate ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totals
$totalSales  = 0.0;
$totalOrders = count($rows);
foreach ($rows as $r) {
    $totalSales += (float)($r['TotalAmt'] ?? 0);
}

// Meta info
$companyName = 'Luxera Store'; // change to your final brand name
$reportTitle = 'Sales Report';
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

// Build HTML
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($reportTitle) ?></title>
<style>
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color:#000; }
  .header { text-align:center; margin-bottom:16px; }
  .header h1 { margin:0; font-size:20px; text-transform:uppercase; }
  .header h2 { margin:4px 0 0; font-size:14px; font-weight:normal; }
  .meta-table { width:100%; border-collapse:collapse; margin-bottom:10px; font-size:10px; }
  .meta-table td { padding:3px 4px; }
  .meta-label { width:90px; font-weight:bold; }
  .summary { margin:6px 0 12px; padding:6px 8px; border:1px solid #000; font-size:10px; }
  .summary span { display:inline-block; margin-right:12px; }
  table.data { width:100%; border-collapse:collapse; font-size:10px; }
  table.data th, table.data td { border:1px solid #000; padding:4px 5px; white-space:nowrap; }
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
    <td class="meta-label">Total Records:</td>
    <td><?= number_format($totalOrders) ?></td>
  </tr>
</table>

<div class="summary">
  <span><strong>Total Sales (RM):</strong> <?= number_format($totalSales, 2) ?></span>
  <span><strong>Total Orders:</strong> <?= number_format($totalOrders) ?></span>
</div>

<table class="data">
  <thead>
    <tr>
      <th class="text-center" style="width:25px;">#</th>
      <th style="width:80px;">Order Date</th>
      <th style="width:55px;">Order ID</th>
      <th style="width:90px;">Customer</th>
      <th>Status</th>
      <th class="text-right" style="width:70px;">Total (RM)</th>
    </tr>
  </thead>
  <tbody>
    <?php if ($rows): $i=1; foreach ($rows as $r): ?>
      <tr>
        <td class="text-center"><?= $i++ ?></td>
        <td><?= htmlspecialchars($r['OrderDate']) ?></td>
        <td>#<?= (int)$r['OrderID'] ?></td>
        <td><?= htmlspecialchars($r['Username'] ?? 'Unknown') ?></td>
        <td><?= htmlspecialchars($r['Status']) ?></td>
        <td class="text-right"><?= number_format((float)$r['TotalAmt'], 2) ?></td>
      </tr>
    <?php endforeach; else: ?>
      <tr>
        <td colspan="6" class="text-center">No orders found for this period.</td>
      </tr>
    <?php endif; ?>
  </tbody>
</table>

</body>
</html>
<?php
$html = ob_get_clean();

// Dompdf
use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'sales_report_' . date('Ymd_His') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;