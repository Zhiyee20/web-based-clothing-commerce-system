<?php
// admin/reports/reward_ledger_pdf.php
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
    $dateClause      .= " AND rl.CreatedAt >= :from";
    $params[':from']  = $periodStart . ' 00:00:00';
}
if ($periodEnd) {
    $dateClause      .= " AND rl.CreatedAt <= :to";
    $params[':to']    = $periodEnd . ' 23:59:59';
}

// Ledger with username & tier
$sql = "
    SELECT 
        rl.LedgerID,
        rl.UserID,
        u.Username,
        rl.Type,
        rl.Points,
        rl.Reference,
        rl.CreatedAt,
        rp.Accumulated,
        rp.Balance,
        rt.TierName,
        rt.ConversionRate
    FROM reward_ledger rl
    JOIN user u         ON u.UserID = rl.UserID
    JOIN reward_points rp ON rp.UserID = rl.UserID
    JOIN reward_tiers  rt ON rp.Accumulated BETWEEN rt.MinPoints AND rt.MaxPoints
    WHERE 1=1
    $dateClause
    ORDER BY rl.CreatedAt ASC, rl.LedgerID ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalEarn   = 0;
$totalRedeem = 0;
foreach ($rows as $r) {
    if ($r['Type'] === 'EARN')   $totalEarn   += (int)$r['Points'];
    if ($r['Type'] === 'REDEEM') $totalRedeem += (int)$r['Points'];
}

$companyName = 'Luxera Store';
$reportTitle = 'Reward & Loyalty Ledger Report';
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
  .meta-label { width:90px; font-weight:bold; }
  .summary { margin:6px 0 12px; padding:6px 8px; border:1px solid #000; font-size:9px; }
  .summary span { display:inline-block; margin-right:12px; }
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
    <td class="meta-label">Total Entries:</td>
    <td><?= number_format(count($rows)) ?></td>
  </tr>
</table>

<div class="summary">
  <span><strong>Total Points Earned:</strong> <?= number_format($totalEarn) ?></span>
  <span><strong>Total Points Redeemed:</strong> <?= number_format($totalRedeem) ?></span>
</div>

<table class="data">
  <thead>
    <tr>
      <th style="width:18px;" class="text-center">#</th>
      <th style="width:70px;">Date</th>
      <th style="width:60px;">User</th>
      <th style="width:35px;">Type</th>
      <th style="width:45px;" class="text-right">Points</th>
      <th style="width:100px;">Reference</th>
      <th style="width:50px;" class="text-right">Accum.</th>
      <th style="width:45px;" class="text-right">Balance</th>
      <th style="width:55px;">Tier</th>
      <th style="width:50px;" class="text-right">Rate</th>
    </tr>
  </thead>
  <tbody>
    <?php if ($rows): $i=1; foreach ($rows as $r): ?>
      <tr>
        <td class="text-center"><?= $i++ ?></td>
        <td><?= htmlspecialchars($r['CreatedAt']) ?></td>
        <td><?= htmlspecialchars($r['Username']) ?></td>
        <td><?= htmlspecialchars($r['Type']) ?></td>
        <td class="text-right"><?= (int)$r['Points'] ?></td>
        <td><?= htmlspecialchars($r['Reference'] ?? '-') ?></td>
        <td class="text-right"><?= (int)$r['Accumulated'] ?></td>
        <td class="text-right"><?= (int)$r['Balance'] ?></td>
        <td><?= htmlspecialchars($r['TierName']) ?></td>
        <td class="text-right"><?= number_format((float)$r['ConversionRate'], 2) ?></td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="10" class="text-center">No reward ledger entries for this period.</td></tr>
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

$filename = 'reward_ledger_' . date('Ymd_His') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;