<?php
// admin/reports/customer_activity_pdf.php
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
$params = [];

if ($periodStart) {
    $dateClause      .= " AND o.OrderDate >= :from";
    $params[':from']  = $periodStart . ' 00:00:00';
}
if ($periodEnd) {
    $dateClause      .= " AND o.OrderDate <= :to";
    $params[':to']    = $periodEnd . ' 23:59:59';
}

// Customer-level activity (per member)
$sql = "
    SELECT 
        u.UserID,
        u.Username,
        u.Email,
        COUNT(o.OrderID) AS OrdersCount,
        COALESCE(SUM(o.TotalAmt),0) AS TotalSpend,
        MAX(o.OrderDate) AS LastOrderDate
    FROM user u
    LEFT JOIN orders o ON o.UserID = u.UserID
                       AND o.Status <> 'Cancel / Return & Refund'
                       $dateClause
    WHERE u.Role = 'Member'
    GROUP BY u.UserID, u.Username, u.Email
    ORDER BY TotalSpend DESC, OrdersCount DESC, u.Username
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalCustomers = count($rows);
$totalSpend     = 0.0;
$totalOrders    = 0;

foreach ($rows as $r) {
    $totalSpend  += (float)$r['TotalSpend'];
    $totalOrders += (int)$r['OrdersCount'];
}

// Extra summary metrics for formal report
$avgSpendPerCustomer = $totalCustomers > 0 ? $totalSpend / $totalCustomers : 0.0;
$avgOrderValue       = $totalOrders    > 0 ? $totalSpend / $totalOrders    : 0.0;

$companyName = 'Luxera Store';
$reportTitle = 'Customer Activity Report';
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
  .summary span { display:inline-block; margin-right:16px; margin-bottom:2px; }
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
    <td class="meta-label">Total Customers:</td>
    <td><?= number_format($totalCustomers) ?></td>
  </tr>
</table>

<div class="summary">
  <span><strong>Total Spend (RM):</strong> <?= number_format($totalSpend, 2) ?></span>
  <span><strong>Total Orders:</strong> <?= number_format($totalOrders) ?></span>
  <span><strong>Avg Spend / Customer (RM):</strong> <?= number_format($avgSpendPerCustomer, 2) ?></span>
  <span><strong>Avg Order Value (RM):</strong> <?= number_format($avgOrderValue, 2) ?></span>
</div>

<table class="data">
  <thead>
    <tr>
      <th class="text-center" style="width:20px;">#</th>
      <th style="width:70px;">Username</th>
      <th style="width:100px;">Email</th>
      <th class="text-right" style="width:50px;">Orders</th>
      <th class="text-right" style="width:60px;">Total Spend (RM)</th>
      <th style="width:90px;">Last Order</th>
    </tr>
  </thead>
  <tbody>
    <?php if ($rows): $i=1; foreach ($rows as $r): ?>
      <tr>
        <td class="text-center"><?= $i++ ?></td>
        <td><?= htmlspecialchars($r['Username']) ?></td>
        <td><?= htmlspecialchars($r['Email'] ?? '-') ?></td>
        <td class="text-right"><?= (int)$r['OrdersCount'] ?></td>
        <td class="text-right"><?= number_format((float)$r['TotalSpend'], 2) ?></td>
        <td><?= htmlspecialchars($r['LastOrderDate'] ?? '-') ?></td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="6" class="text-center">No customer activity for this period.</td></tr>
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

$filename = 'customer_activity_' . date('Ymd_His') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;