<?php
// admin/reports/reward_ledger_export.php
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
    $dateClause       .= " AND rl.CreatedAt >= :ps";
    $params[':ps']     = $start . ' 00:00:00';
}
if ($end) {
    $dateClause       .= " AND rl.CreatedAt <= :pe";
    $params[':pe']     = $end . ' 23:59:59';
}

/* -----------------------------
   2) Ledger rows
   ----------------------------- */
$sql = "
  SELECT
    rl.LedgerID,
    rl.UserID,
    u.Username,
    rl.RefOrderID AS OrderID,
    rl.Points,
    rl.Type,
    rl.CreatedAt
  FROM reward_ledger rl
  JOIN user u ON u.UserID = rl.UserID
  WHERE rl.Type IN ('EARN','REDEEM','AUTO_REVERSAL_EARN','AUTO_REVERSAL_REDEEM')
  $dateClause
  ORDER BY rl.CreatedAt DESC, rl.LedgerID DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -----------------------------
   3) CSV headers
   ----------------------------- */
$filenameParts = ['reward_ledger'];
$filenameParts[] = $start ?: 'all';
$filenameParts[] = $end   ?: 'all';
$filename = implode('_', $filenameParts) . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');

fputcsv($out, [
    'Ledger ID',
    'User ID',
    'Username',
    'Order ID',
    'Points',
    'Type',
    'Created At',
]);

if ($rows) {
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['LedgerID'],
            $r['UserID'],
            $r['Username'],
            $r['OrderID'],
            $r['Points'],
            $r['Type'],
            $r['CreatedAt'],
        ]);
    }
} else {
    fputcsv($out, ['No ledger records in this period']);
}

fclose($out);
exit;