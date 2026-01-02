<?php
// File: admin/admin_rewards.php
// Simple admin page to view/update reward settings (no expiry).
// Assumes: require_once '../config.php'; session has admin role check.

session_start();
require_once __DIR__ . '/../config.php';

// TODO: Replace with your actual admin auth check
if (!isset($_SESSION['Role']) || $_SESSION['Role'] !== 'Admin') {
    http_response_code(403);
    echo "Forbidden: Admins only.";
    exit;
}

$errors = [];
$success = null;

// Fetch latest settings
function get_settings(PDO $pdo): array {
    $stmt = $pdo->query("SELECT SettingID, PointPerRM, ConversionRate, MinRedeem FROM reward_settings ORDER BY SettingID DESC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        // bootstrap defaults if table empty
        $pdo->exec("INSERT INTO reward_settings (PointPerRM, ConversionRate, MinRedeem) VALUES (1.00, 0.01, 100)");
        return ['SettingID'=>1,'PointPerRM'=>1.00,'ConversionRate'=>0.01,'MinRedeem'=>100];
    }
    return $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF (basic)
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
        $errors[] = "Invalid form token. Please try again.";
    } else {
        $pointPerRM     = isset($_POST['PointPerRM']) ? (float)$_POST['PointPerRM'] : null;
        $conversionRate = isset($_POST['ConversionRate']) ? (float)$_POST['ConversionRate'] : null;
        $minRedeem      = isset($_POST['MinRedeem']) ? (int)$_POST['MinRedeem'] : null;

        // Validation
        if ($pointPerRM <= 0 || $pointPerRM > 1000) $errors[] = "Point per RM must be between 0 and 1000.";
        if ($conversionRate < 0 || $conversionRate > 10) $errors[] = "Conversion rate must be between 0 and 10 RM per point.";
        if ($minRedeem < 0 || $minRedeem > 100000) $errors[] = "Minimum redeem must be between 0 and 100000 points.";

        if (!$errors) {
            $stmt = $pdo->prepare("INSERT INTO reward_settings (PointPerRM, ConversionRate, MinRedeem) VALUES (?, ?, ?)");
            $stmt->execute([$pointPerRM, $conversionRate, $minRedeem]);
            $success = "Settings updated successfully.";
        }
    }
}

$settings = get_settings($pdo);
// Generate CSRF
$_SESSION['csrf'] = bin2hex(random_bytes(16));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Reward Settings</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 24px; }
    .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; max-width: 680px; }
    .row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    label { display:block; font-weight:600; margin-bottom:6px; }
    input[type="number"] { width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px; }
    .btn { background:#0ea5e9; color:#fff; border:none; padding:10px 16px; border-radius:10px; cursor:pointer; }
    .btn:hover { filter:brightness(0.95); }
    .muted { color:#64748b; font-size: 14px; }
    .msg-ok { background:#ecfeff; border:1px solid #a5f3fc; color:#0c4a6e; padding:10px 12px; border-radius:8px; margin-bottom:12px;}
    .msg-err { background:#fef2f2; border:1px solid #fecaca; color:#7f1d1d; padding:10px 12px; border-radius:8px; margin-bottom:12px;}
  </style>
</head>
<body>
  <h1>Reward Settings</h1>
  <p class="muted">Configure how customers <strong>earn</strong> and <strong>redeem</strong> points. Points do not expire.</p>

  <div class="card">
    <?php if ($success): ?><div class="msg-ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="msg-err"><ul><?php foreach ($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul></div><?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
      <div class="row">
        <div>
          <label for="PointPerRM">Point per RM</label>
          <input type="number" step="0.01" min="0.01" max="1000" id="PointPerRM" name="PointPerRM" value="<?= htmlspecialchars($settings['PointPerRM']) ?>">
          <div class="muted">Earning: points = floor(<code>order_total × PointPerRM</code>)</div>
        </div>
        <div>
          <label for="ConversionRate">Conversion Rate (RM per point)</label>
          <input type="number" step="0.01" min="0.00" max="10" id="ConversionRate" name="ConversionRate" value="<?= htmlspecialchars($settings['ConversionRate']) ?>">
          <div class="muted">Redemption: discount = <code>points × ConversionRate</code></div>
        </div>
      </div>

      <div style="margin-top:16px;">
        <label for="MinRedeem">Minimum redeem (points)</label>
        <input type="number" step="1" min="0" max="100000" id="MinRedeem" name="MinRedeem" value="<?= htmlspecialchars($settings['MinRedeem']) ?>">
        <div class="muted">Users must redeem at least this many points (0 means no minimum).</div>
      </div>

      <div style="margin-top:20px;">
        <button class="btn" type="submit">Save</button>
      </div>
    </form>
  </div>
</body>
</html>
