<?php
// admin/reports/customer_service_pdf.php
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
    $dateClause      .= " AND oc.RequestedAt >= :from";
    $params[':from']  = $periodStart . ' 00:00:00';
}
if ($periodEnd) {
    $dateClause      .= " AND oc.RequestedAt <= :to";
    $params[':to']    = $periodEnd . ' 23:59:59';
}

// Cancellation / customer service requests
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
    ORDER BY oc.RequestedAt ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Basic counts by status
$total    = count($rows);
$pending  = 0;
$approved = 0;
$rejected = 0;

foreach ($rows as $r) {
    if ($r['Status'] === 'Pending')  { $pending++; }
    if ($r['Status'] === 'Approved') { $approved++; }
    if ($r['Status'] === 'Rejected') { $rejected++; }
}

// Rates (for formal summary)
$pendingRate  = $total > 0 ? round(($pending  / $total) * 100, 2) : 0.00;
$approvedRate = $total > 0 ? round(($approved / $total) * 100, 2) : 0.00;
$rejectedRate = $total > 0 ? round(($rejected / $total) * 100, 2) : 0.00;

// Active deliveries (support / logistics KPI)
$sqlDel = "
    SELECT COUNT(*) 
    FROM delivery d
    WHERE d.Status IN ('PickUp','WareHouse','Transit','OutOfDelivery')
";
$activeDeliveries = (int)($pdo->query($sqlDel)->fetchColumn() ?: 0);

$companyName = 'Luxera Store';
$reportTitle = 'Customer Service & Cancellation Report';
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
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9px; color:#000; }
  .header { text-align:center; margin-bottom:16px; }
  .header h1 { margin:0; font-size:20px; text-transform:uppercase; }
  .header h2 { margin:4px 0 0; font-size:14px; font-weight:normal; }

  .meta-table { width:100%; border-collapse:collapse; margin-bottom:10px; }
  .meta-table td { padding:3px 4px; }
  .meta-label { width:110px; font-weight:bold; }

  .summary { margin:6px 0 12px; padding:6px 8px; border:1px solid #000; font-size:9px; }
  .summary span { display:inline-block; margin-right:16px; margin-bottom:2px; }

  table.data { width:100%; border-collapse:collapse; font-size:8.5px; }
  table.data th, table.data td { border:1px solid #000; padding:2px 3px; white-space:nowrap; }
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
    <td class="meta-label">Total Requests:</td>
    <td><?= number_format($total) ?></td>
  </tr>
</table>

<div class="summary">
  <span><strong>Total Requests:</strong> <?= number_format($total) ?></span>
  <span><strong>Pending Requests:</strong> <?= number_format($pending) ?> (<?= number_format($pendingRate, 2) ?>%)</span>
  <span><strong>Approved Requests:</strong> <?= number_format($approved) ?> (<?= number_format($approvedRate, 2) ?>%)</span>
  <span><strong>Rejected Requests:</strong> <?= number_format($rejected) ?> (<?= number_format($rejectedRate, 2) ?>%)</span>
  <span><strong>Active Deliveries:</strong> <?= number_format($activeDeliveries) ?></span>
</div>

<table class="data">
  <thead>
    <tr>
      <th style="width:18px;" class="text-center">#</th>
      <th style="width:60px;">Requested At</th>
      <th style="width:45px;">Request ID</th>
      <th style="width:45px;">Order ID</th>
      <th style="width:60px;">User</th>
      <th style="width:50px;">Status</th>
      <th>Reason</th>
    </tr>
  </thead>
  <tbody>
    <?php if ($rows): $i=1; foreach ($rows as $r): ?>
      <tr>
        <td class="text-center"><?= $i++ ?></td>
        <td><?= htmlspecialchars($r['RequestedAt']) ?></td>
        <td>#<?= (int)$r['cancellationId'] ?></td>
        <td>#<?= (int)$r['OrderID'] ?></td>
        <td><?= htmlspecialchars($r['Username'] ?? 'Unknown') ?></td>
        <td><?= htmlspecialchars($r['Status']) ?></td>
        <td><?= htmlspecialchars($r['Reason'] ?? '-') ?></td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="7" class="text-center">No cancellation requests for this period.</td></tr>
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
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'customer_service_' . date('Ymd_His') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;