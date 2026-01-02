<?php
// user/points_report.php

require __DIR__ . '/../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user'])) {
  header('Location: /login.php');
  exit;
}

$userID = (int)$_SESSION['user']['UserID'];

require __DIR__ . '/RewardsService.php';
$rewards = new RewardsService($pdo);

// ---- Filters (GET) ----------------------------------------------------------
$type     = isset($_GET['type']) && in_array($_GET['type'], ['EARN', 'REDEEM', 'AUTO_REVERSAL_EARN', 'AUTO_REVERSAL_REDEEM']) ? $_GET['type'] : '';
$fromDate = isset($_GET['from']) ? trim($_GET['from']) : '';
$toDate   = isset($_GET['to'])   ? trim($_GET['to'])   : '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$limit    = 20;
$offset   = ($page - 1) * $limit;

// Validate dates (YYYY-MM-DD)
$fromOk = $fromDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate);
$toOk   = $toDate   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate);

// ---- Summary ---------------------------------------------------------------
$balance = $rewards->getBalance($userID);

// Lifetime summary from reward_points (Earned = Accumulated, Redeemed = Accumulated - Balance)
$rpStmt = $pdo->prepare("
  SELECT 
    COALESCE(Balance, 0)     AS Bal,
    COALESCE(Accumulated, 0) AS Acc
  FROM reward_points
  WHERE UserID = ?
  LIMIT 1
");
$rpStmt->execute([$userID]);
$rp = $rpStmt->fetch(PDO::FETCH_ASSOC) ?: ['Bal' => 0, 'Acc' => 0];

$lifetimeEarned   = (int)$rp['Acc'];                         // Earned
$lifetimeRedeemed = max(0, (int)$rp['Acc'] - (int)$rp['Bal']); // Redeemed

// (Optional) Simple tiering – adjust thresholds as you wish.
function points_tier(int $points): string
{
  if ($points >= 500001) return 'Platinum';
  if ($points >= 250001)  return 'Gold';
  if ($points >= 0)  return 'Silver';
  return 'Silver';
}
$tier = points_tier($balance);

// Quick stats in current filter window
$where = ["UserID = ?"];
$args  = [$userID];

if ($type) {
  $where[] = "Type = ?";
  $args[] = $type;
}
if ($fromOk) {
  $where[] = "DATE(CreatedAt) >= ?";
  $args[] = $fromDate;
}
if ($toOk) {
  $where[] = "DATE(CreatedAt) <= ?";
  $args[] = $toDate;
}
$whereSql = implode(' AND ', $where);


// ---- Pagination + rows ------------------------------------------------------
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM reward_ledger WHERE $whereSql");
$countStmt->execute($args);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));

$listSql = "SELECT LedgerID, Type, Points, RefOrderID, CreatedAt
            FROM reward_ledger
            WHERE $whereSql
            ORDER BY CreatedAt DESC
            LIMIT $limit OFFSET $offset";
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($args);
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

function ledger_description(array $row): string
{
  $order = isset($row['RefOrderID']) && $row['RefOrderID'] ? " #{$row['RefOrderID']}" : '';
  switch ($row['Type'] ?? '') {
    case 'EARN':
      return "Earned from order{$order}";
    case 'REDEEM':
      return $order ? "Redeemed at checkout (order{$order})" : "Redeemed at checkout";
    case 'AUTO_REVERSAL_EARN':
      return "Auto reversal of previously earned points";
    case 'AUTO_REVERSAL_REDEEM':
      return "Auto reversal of previously redeemed points";
    case 'ADJUST':
      return "Manual adjustment";
    default:
      return "";
  }
}
// ---- UI --------------------------------------------------------------------
include __DIR__ . '/header.php';
?>
<style>
  .report-wrap {
    max-width: 1000px;
    margin: 24px auto;
    font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
  }

  .cards {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 16px;
  }

  .card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 16px;
  }

  .card h3 {
    margin: 0 0 6px;
    font-size: 16px;
    color: #334155;
  }

  .card .big {
    font-size: 22px;
    font-weight: 700;
  }

  .filters {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr 120px 120px;
    gap: 20px;
    margin: 20px 0;
    align-items: end;
  }

  .filters label {
    font-size: 12px;
    color: #475569;
    display: block;
    margin-bottom: 4px;
  }

  .filters input,
  .filters select {
    padding: 8px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    min-width: 140px;
  }

  .filters input[type="date"] {
    min-width: 180px;
  }

  .filters button,
  .filters .btn {
    display: inline-block;
    width: 100%;
    text-align: center;
    padding: 10px 0;
    border-radius: 10px;
    font-size: 14px;
    box-sizing: border-box;
  }

  #btn,
  .btn.secondary {
    display: inline-block;
    padding: 10px 15px;
    background-color: #000;
    color: #fff;
    text-decoration: none;
    font-size: 14px;
    border: 2px solid #fff;
    border-radius: 30px;
    transition: background-color 0.3s ease, color 0.3s ease;
    text-align: center;
    cursor: pointer;
  }

  #btn:hover,
  .btn.secondary:hover {
    background-color: #fff;
    color: #000;
    border: 2px solid #000;
  }

  #btn {
    background-color: transparent;
    color: #000;
    border: 2px solid #000;
  }

  #btn:hover {
    background-color: #000;
    color: #fff;
  }

  table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 12px;
  }

  th,
  td {
    padding: 10px 8px;
    border-bottom: 1px solid #e5e7eb;
    font-size: 14px;
  }

  th {
    text-align: left;
    color: #334155;
  }

  .type-pill {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 999px;
    font-size: 12px;
  }

  .EARN,
  .AUTO_REVERSAL_REDEEM {
    background: #ecfdf5;
    color: #065f46;
    border: 1px solid #a7f3d0;
  }

  .REDEEM,
  .AUTO_REVERSAL_EARN {
    background: #fef2f2;
    color: #7f1d1d;
    border: 1px solid #fecaca;
  }

  .ADJUST {
    background: #eff6ff;
    color: #1e40af;
    border: 1px solid #bfdbfe;
  }

  .pagination {
    margin: 12px 0;
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
  }

  .pagination a,
  .pagination span {
    padding: 6px 10px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    text-decoration: none;
    color: #334155;
  }

  .pagination .active {
    background: #0ea5e9;
    color: #fff;
    border-color: #0ea5e9;
  }
</style>

<div class="report-wrap">
  <h2>Point Tracking Report</h2>

  <div class="cards">
    <div class="card">
      <h3>Current Balance</h3>
      <div class="big"><?= number_format($balance) ?> pts</div>
    </div>
    <div class="card">
      <h3>Tier</h3>
      <div class="big"><?= htmlspecialchars($tier) ?></div>
    </div>
    <div class="card">
      <h3>Activity Summary</h3>
      <div>Earned: <strong><?= number_format($lifetimeEarned) ?></strong> pts</div>
      <div>Redeemed: <strong><?= number_format($lifetimeRedeemed) ?></strong> pts</div>
    </div>
  </div>

  <form class="filters" method="get">
    <div>
      <label for="type">Type</label>
      <select id="type" name="type">
        <option value="" <?= $type === '' ? 'selected' : '' ?>>All</option>
        <option value="EARN" <?= $type === 'EARN'   ? 'selected' : '' ?>>EARN</option>
        <option value="REDEEM" <?= $type === 'REDEEM' ? 'selected' : '' ?>>REDEEM</option>
        <option value="AUTO_REVERSAL_EARN" <?= $type === 'AUTO_REVERSAL_EARN' ? 'selected' : '' ?>>AUTO_REVERSAL_EARN</option>
        <option value="AUTO_REVERSAL_REDEEM" <?= $type === 'AUTO_REVERSAL_REDEEM' ? 'selected' : '' ?>>AUTO_REVERSAL_REDEEM</option>
      </select>
    </div>
    <div>
      <label for="from">From (YYYY-MM-DD)</label>
      <input id="from" type="date" name="from" value="<?= htmlspecialchars($fromOk ? $fromDate : '') ?>">
    </div>
    <div>
      <label for="to">To (YYYY-MM-DD)</label>
      <input id="to" type="date" name="to" value="<?= htmlspecialchars($toOk ? $toDate : '') ?>">
    </div>
    <div>
      <button id="btn" class="btn" type="submit">Apply</button>
    </div>
    <div>
      <a class="btn secondary" href="?">Reset</a>
    </div>
  </form>

  <div style="display:flex; justify-content:space-between; align-items:center;">
    <h3 style="margin:12px 0;">Points History</h3>
  </div>

  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Type</th>
        <th>Points</th>
        <th>Order</th>
        <th>Description</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr>
          <td colspan="5" style="text-align:center; color:#64748b;">No records in this view.</td>
        </tr>
        <?php else: foreach ($rows as $r): ?>
          <?php $desc = ledger_description($r); ?>
          <tr>
            <td><?= htmlspecialchars($r['CreatedAt']) ?></td>
            <td><span class="type-pill <?= htmlspecialchars($r['Type']) ?>"><?= htmlspecialchars($r['Type']) ?></span></td>
            <td><?= number_format((int)$r['Points']) ?></td>
            <td><?= $r['RefOrderID'] ? (int)$r['RefOrderID'] : '-' ?></td>
            <td><?= htmlspecialchars($desc) ?></td>
          </tr>
      <?php endforeach;
      endif; ?>
    </tbody>
  </table>

  <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php
      $baseParams = array_filter(['type' => $type, 'from' => $fromOk ? $fromDate : '', 'to' => $toOk ? $toDate : '']);
      for ($p = 1; $p <= $totalPages; $p++):
        $baseParams['page'] = $p;
        $url = '?' . http_build_query($baseParams);
      ?>
        <?php if ($p == $page): ?>
          <span class="active"><?= $p ?></span>
        <?php else: ?>
          <a href="<?= $url ?>"><?= $p ?></a>
        <?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>