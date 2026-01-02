<?php
require '../config.php';
session_start();

// If you gate by role, keep this
// $user = $_SESSION['user'] ?? null;
// if (!$user || ($user['Role'] ?? '') !== 'Admin') { header('Location: login.php'); exit; }

function h($v)
{
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/**
 * Auto-determine eligible user IDs based on preset TargetGroup value.
 *
 * - "Silver Member" / "Gold Member" / "Platinum Member":
 *      Uses reward_points.Accumulated + reward_tiers.MinPoints/MaxPoints
 * - "Females" / "Males":
 *      Uses user.Gender
 * - "First Time Buyer":
 *      Users who have NO rows in orders table
 *
 * Returns array of integer UserID.
 */
function getEligibleUserIdsForTargetGroup(PDO $pdo, string $targetGroup): array
{
  $targetGroup = trim($targetGroup);
  if ($targetGroup === '') return [];

  try {
    switch ($targetGroup) {
      case 'Silver Member':
      case 'Gold Member':
      case 'Platinum Member':
        // Tier based on Accumulated vs reward_tiers range
        $tierName = $targetGroup; // assumes reward_tiers.TierName matches this
        $sql = "
          SELECT DISTINCT rp.UserID
          FROM reward_points rp
          JOIN reward_tiers rt
            ON rp.Accumulated BETWEEN rt.MinPoints AND rt.MaxPoints
          JOIN user u ON u.UserID = rp.UserID
          WHERE rt.TierName = :tierName
            AND u.Role = 'Member'
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':tierName' => $tierName]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map('intval', array_column($rows, 'UserID'));

      case 'Females':
        $gender = 'Female';
        $sql = "
          SELECT u.UserID
          FROM user u
          WHERE u.Gender = :gender
            AND u.Role = 'Member'
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':gender' => $gender]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map('intval', array_column($rows, 'UserID'));

      case 'Males':
        $gender = 'Male';
        $sql = "
          SELECT u.UserID
          FROM user u
          WHERE u.Gender = :gender
            AND u.Role = 'Member'
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':gender' => $gender]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map('intval', array_column($rows, 'UserID'));

      case 'First Time Buyer':
        // Users who have never placed an order
        $sql = "
          SELECT u.UserID
          FROM user u
          LEFT JOIN orders o ON o.UserID = u.UserID
          WHERE o.UserID IS NULL
            AND u.Role = 'Member'
        ";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map('intval', array_column($rows, 'UserID'));

      default:
        return [];
    }
  } catch (Throwable $e) {
    // On any error, just fall back to manual selection
    return [];
  }
}

$promoErrors = [];
$flash       = '';

// ---------- Fetch data for eligibility (users + products) ----------
try {
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

  $users = $pdo->query("SELECT UserID, username FROM user ORDER BY username")->fetchAll();
  $products = $pdo->query("SELECT ProductID, Name FROM product ORDER BY Name")->fetchAll();

  // Precompute preset TargetGroup eligible users for front-end auto selection
  $targetGroupPresetUsers = [];
  $presetNames = ['Silver Member', 'Gold Member', 'Platinum Member', 'Females', 'Males', 'First Time Buyer'];
  foreach ($presetNames as $name) {
    $targetGroupPresetUsers[$name] = getEligibleUserIdsForTargetGroup($pdo, $name);
  }
} catch (Throwable $e) {
  $users = [];
  $products = [];
  $targetGroupPresetUsers = [];
  $promoErrors[] = 'Failed to load eligibility lists.';
}

// ---------- CREATE / UPDATE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action         = $_POST['action'] ?? '';
  $title          = trim($_POST['Title'] ?? '');
  $description    = trim($_POST['Description'] ?? '');
  $tnc            = trim($_POST['Tnc'] ?? '');
  $startDate      = $_POST['StartDate'] ?? '';
  $endDate        = $_POST['EndDate'] ?? '';
  $discountType   = $_POST['DiscountType'] ?? '';
  $discountValue  = $_POST['DiscountValue'] ?? '';
  $promotionType  = $_POST['PromotionType'] ?? '';
  $promoStatus    = $_POST['PromoStatus'] ?? 'Active';
  $targetGroup    = trim($_POST['TargetGroup'] ?? '');
  $eligibilityRaw = trim($_POST['EligibilityIDs'] ?? '');

  // new: min spend (Targeted only, optional)
  $minSpendRaw = trim($_POST['MinSpend'] ?? '');
  $minSpend    = null;
  if ($promotionType === 'Targeted' && $minSpendRaw !== '') {
    if (!is_numeric($minSpendRaw) || (float)$minSpendRaw < 0) {
      $promoErrors[] = 'Minimum spend must be a non-negative number.';
    } else {
      $minSpend = (float)$minSpendRaw;
    }
  }

  // new: max redemptions (optional, integer >= 0, empty = unlimited)
  $maxRedemptionsRaw = trim($_POST['MaxRedemptions'] ?? '');
  $maxRedemptions    = null;
  if ($maxRedemptionsRaw !== '') {
    if (ctype_digit($maxRedemptionsRaw)) {
      $maxRedemptions = (int)$maxRedemptionsRaw;
      if ($maxRedemptions < 0) {
        $promoErrors[] = 'Max redemptions cannot be negative.';
      }
    } else {
      $promoErrors[] = 'Max redemptions must be a whole number.';
    }
  }

  // ---- basic validation ----
  if ($title === '')           $promoErrors[] = 'Title is required.';
  if ($description === '')     $promoErrors[] = 'Description is required.';
  if ($startDate === '')       $promoErrors[] = 'Start Date is required.';
  if (!in_array($discountType, ['Percentage', 'Fixed'], true)) $promoErrors[] = 'Discount Type is invalid.';
  if ($discountValue === '' || !is_numeric($discountValue))   $promoErrors[] = 'Discount Value must be numeric.';
  if (!in_array($promotionType, ['Campaign', 'Targeted'], true)) $promoErrors[] = 'Promotion Type is invalid.';
  if (!in_array($promoStatus, ['Active', 'Inactive'], true)) $promoStatus = 'Active';

  // End Date must be later than Start Date (if End Date is provided)
  if ($startDate !== '' && $endDate !== '') {
    // comparing YYYY-MM-DD strings is safe if same YYYY-MM-DD format
    if ($endDate <= $startDate) {
      $promoErrors[] = 'End Date must be later than the Start Date.';
    }
  }
  // Turn "1,2,3" into array of ints
  $eligIDs = [];
  if ($eligibilityRaw !== '') {
    foreach (explode(',', $eligibilityRaw) as $id) {
      $id = (int)trim($id);
      if ($id > 0) $eligIDs[] = $id;
    }
  }

  // ---- type-specific validation & adjustments ----
  if ($promotionType === 'Targeted') {
    // Also auto-add users for known preset target groups on backend (safety)
    if ($targetGroup !== '') {
      $autoIds = getEligibleUserIdsForTargetGroup($pdo, $targetGroup);
      if ($autoIds) {
        $eligIDs = array_values(array_unique(array_merge($eligIDs, $autoIds)));
      }
    }

    // EndDate may be NULL
    if ($targetGroup === '') {
      $promoErrors[] = 'Target Group is required for Targeted promotions.';
    }
    if (!$eligIDs) {
      $promoErrors[] = 'Please select at least one eligible user.';
    }
  } elseif ($promotionType === 'Campaign') {
    // EndDate must NOT be NULL
    if ($endDate === '') {
      $promoErrors[] = 'End Date is required for Campaign promotions.';
    }
    // TargetGroup must be NULL for campaign
    $targetGroup = null;
    if (!$eligIDs) {
      $promoErrors[] = 'Please select at least one eligible product.';
    }
  }
  // do not allow duplicate active promotion titles
  // Prevent duplicate ACTIVE promotion titles
  if (!$promoErrors) {
    $titleExists = 0;

    if ($action === 'create') {
      $sqlCheck = "
        SELECT COUNT(*)
        FROM promotions
        WHERE Title = :Title
          AND PromoStatus = 'Active'
      ";
      $stmtCheck = $pdo->prepare($sqlCheck);
      $stmtCheck->execute([':Title' => $title]);
      $titleExists = (int)$stmtCheck->fetchColumn();
    } elseif ($action === 'update') {
      // exclude the current promotion when checking duplicates
      $checkId = (int)($_POST['PromotionID'] ?? 0);
      if ($checkId > 0) {
        $sqlCheck = "
          SELECT COUNT(*)
          FROM promotions
          WHERE Title = :Title
            AND PromoStatus = 'Active'
            AND PromotionID <> :id
        ";
        $stmtCheck = $pdo->prepare($sqlCheck);
        $stmtCheck->execute([
          ':Title' => $title,
          ':id'    => $checkId,
        ]);
        $titleExists = (int)$stmtCheck->fetchColumn();
      }
    }

    if ($titleExists > 0) {
      $promoErrors[] = 'A promotion with this title is already active. Please use a different title or deactivate the existing promotion.';
    }
  }

  // Persist errors for this request so header/includes cannot wipe them
  if ($promoErrors) {
    $_SESSION['promo_errors'] = $promoErrors;
  }

  if (!$promoErrors) {
    try {
      if ($action === 'create') {
        $pdo->beginTransaction();

        // 1) Insert ONE master promotion row
        $sqlInsert = "INSERT INTO promotions
            (Title, Description, StartDate, EndDate,
             DiscountType, DiscountValue, MinSpend,
             PromotionType, PromoStatus, TargetGroup,
             MaxRedemptions, RedemptionCount,
             CreatedAt, UpdatedAt, Tnc)
          VALUES
            (:Title,:Description,:StartDate,:EndDate,
             :DiscountType,:DiscountValue,:MinSpend,
             :PromotionType,:PromoStatus,:TargetGroup,
             :MaxRedemptions, 0,
             NOW(),NOW(),:Tnc)";

        $stmt = $pdo->prepare($sqlInsert);
        $stmt->execute([
          ':Title'          => $title,
          ':Description'    => $description,
          ':StartDate'      => $startDate,
          ':EndDate'        => ($endDate !== '' ? $endDate : null),
          ':DiscountType'   => $discountType,
          ':DiscountValue'  => (float)$discountValue,
          ':MinSpend'       => ($promotionType === 'Targeted' ? $minSpend : null),
          ':PromotionType'  => $promotionType,
          ':PromoStatus'    => $promoStatus,
          ':TargetGroup'    => $promotionType === 'Targeted' ? $targetGroup : null,
          ':MaxRedemptions' => $maxRedemptions,
          ':Tnc'            => $tnc,
        ]);

        $promoID = (int)$pdo->lastInsertId();

        // 2) Insert eligibilities into junction tables
        if ($promotionType === 'Targeted') {
          $stmtElig = $pdo->prepare("
            INSERT IGNORE INTO promotion_users (PromotionID, UserID)
            VALUES (:PromotionID, :UserID)
          ");
          foreach ($eligIDs as $uid) {
            $stmtElig->execute([
              ':PromotionID' => $promoID,
              ':UserID'      => $uid,
            ]);
          }
        } elseif ($promotionType === 'Campaign') {
          $stmtElig = $pdo->prepare("
            INSERT IGNORE INTO promotion_products (PromotionID, ProductID)
            VALUES (:PromotionID, :ProductID)
          ");
          foreach ($eligIDs as $pid) {
            $stmtElig->execute([
              ':PromotionID' => $promoID,
              ':ProductID'   => $pid,
            ]);
          }
        }

        $pdo->commit();
        $flash = 'Promotion created.';
      }

      if ($action === 'update') {
        $id = (int)($_POST['PromotionID'] ?? 0);
        if ($id <= 0) {
          $promoErrors[] = 'Invalid Promotion ID.';
        } else {
          $pdo->beginTransaction();

          // 1) Update master row
          $sql = "UPDATE promotions
                     SET Title=:Title, Description=:Description,
                         StartDate=:StartDate, EndDate=:EndDate,
                         DiscountType=:DiscountType, DiscountValue=:DiscountValue,
                         MinSpend=:MinSpend,
                         PromotionType=:PromotionType, PromoStatus=:PromoStatus, TargetGroup=:TargetGroup,
                         MaxRedemptions=:MaxRedemptions,
                         Tnc=:Tnc,
                         UpdatedAt=NOW()
                   WHERE PromotionID=:id";

          $stmt = $pdo->prepare($sql);
          $stmt->execute([
            ':Title'          => $title,
            ':Description'    => $description,
            ':StartDate'      => $startDate,
            ':EndDate'        => ($endDate !== '' ? $endDate : null),
            ':DiscountType'   => $discountType,
            ':DiscountValue'  => (float)$discountValue,
            ':MinSpend'       => ($promotionType === 'Targeted' ? $minSpend : null),
            ':PromotionType'  => $promotionType,
            ':PromoStatus'    => $promoStatus,
            ':TargetGroup'    => ($promotionType === 'Targeted' ? $targetGroup : null),
            ':MaxRedemptions' => $maxRedemptions,
            ':Tnc'            => $tnc,
            ':id'             => $id,
          ]);

          // 2) Clear old eligibilities
          $pdo->prepare("DELETE FROM promotion_users WHERE PromotionID = ?")->execute([$id]);
          $pdo->prepare("DELETE FROM promotion_products WHERE PromotionID = ?")->execute([$id]);

          // 3) Re-insert eligibilities
          if ($promotionType === 'Targeted') {
            $stmtElig = $pdo->prepare("
              INSERT IGNORE INTO promotion_users (PromotionID, UserID)
              VALUES (:PromotionID, :UserID)
            ");
            foreach ($eligIDs as $uid) {
              $stmtElig->execute([
                ':PromotionID' => $id,
                ':UserID'      => $uid,
              ]);
            }
          } elseif ($promotionType === 'Campaign') {
            $stmtElig = $pdo->prepare("
              INSERT IGNORE INTO promotion_products (PromotionID, ProductID)
              VALUES (:PromotionID, :ProductID)
            ");
            foreach ($eligIDs as $pid) {
              $stmtElig->execute([
                ':PromotionID' => $id,
                ':ProductID'   => $pid,
              ]);
            }
          }

          $pdo->commit();
          $flash = 'Promotion updated.';
        }
      }
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $promoErrors[] = 'Database error: ' . $e->getMessage();
    }
  }
}

// Re-hydrate errors from session (in case header/includes overwrote the local variable)
if (!empty($_SESSION['promo_errors']) && is_array($_SESSION['promo_errors'])) {
  $promoErrors = $_SESSION['promo_errors'];
  unset($_SESSION['promo_errors']);
}

// ---------- LIST (with joins for eligibility display) ----------
$sqlList = "
  SELECT
    p.PromotionID, p.Title, p.Description, p.StartDate, p.EndDate,
    p.DiscountType, p.DiscountValue, p.MinSpend, p.PromotionType, p.PromoStatus, p.TargetGroup,
    p.MaxRedemptions, p.RedemptionCount,
    p.CreatedAt, p.UpdatedAt, p.Tnc,

    -- For Targeted
    GROUP_CONCAT(DISTINCT u.username ORDER BY u.username SEPARATOR ', ') AS UserNames,
    GROUP_CONCAT(DISTINCT pu.UserID ORDER BY pu.UserID SEPARATOR ',') AS UserIDs,

    -- For Campaign
    GROUP_CONCAT(DISTINCT pr.Name ORDER BY pr.Name SEPARATOR ', ') AS ProductNames,
    GROUP_CONCAT(DISTINCT pp.ProductID ORDER BY pp.ProductID SEPARATOR ',') AS ProductIDs

  FROM promotions p
  LEFT JOIN promotion_users pu    ON pu.PromotionID = p.PromotionID
  LEFT JOIN user u                ON u.UserID = pu.UserID
  LEFT JOIN promotion_products pp ON pp.PromotionID = p.PromotionID
  LEFT JOIN product pr            ON pr.ProductID = pp.ProductID
  GROUP BY p.PromotionID
  ORDER BY p.PromotionID ASC
";

$rows = $pdo->query($sqlList)->fetchAll(PDO::FETCH_ASSOC);

// Header/footer brings your original layout & CSS
include 'admin_header.php';
?>
<link rel="stylesheet" href="admin_style.css">

<style>
  .page-wrap {
    max-width: 1600px;
    margin: 20px;
    padding: 0 14px;
  }

  .table-toolbar {
    display: flex;
    justify-content: flex-end;
    margin: 8px 0 12px;
  }

  .btn-sky,
  #btn-edit {
    background-color: transparent;
    color: #000;
    border: 2px solid #000;
    padding: 10px 14px;
    border-radius: 30px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
  }

  .btn-sky:hover,
  #btn-edit:hover {
    background-color: #000;
    color: #fff;
  }

  .modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, .45);
    display: none;
    align-items: flex-start;
    justify-content: center;
    z-index: 9999;
    padding: 40px 0;
  }

  .modal {
    background: #fff;
    width: 96%;
    max-width: 760px;
    max-height: 90vh;
    overflow-y: auto;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 12px 30px rgba(0, 0, 0, .2);
  }

  .modal-header {
    padding: 16px 18px;
    border-bottom: 1px solid #eef2f7;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .modal-header .header-controls {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
  }

  .modal-header .header-controls label {
    margin: 0;
    font-size: 13px;
    color: #374151;
  }

  .modal-header .header-controls .select {
    min-width: 170px;
    padding: 6px 10px;
    border-radius: 6px;
  }

  .modal-body {
    padding: 16px 18px
  }

  .modal-actions {
    padding: 12px 18px;
    border-top: 1px solid #eef2f7;
    display: flex;
    gap: 10px;
    justify-content: flex-end
  }

  .form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
  }

  .form-grid .full {
    grid-column: 1 / -1;
  }

  label {
    display: block;
    font-size: 13px;
    color: #374151;
    margin-bottom: 6px
  }

  .input,
  .select,
  .textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: #fff;
    color: #0f172a;
    box-sizing: border-box;
  }

  .textarea {
    min-height: 90px;
    resize: vertical
  }

  .btn-secondary {
    background: #fff;
    color: #0a1a40;
    border: 1px solid #0a1a40;
    padding: 10px 14px;
    border-radius: 8px;
    cursor: pointer;
  }

  .table-responsive {
    overflow: auto
  }

  .admin-table th,
  .admin-table td {
    vertical-align: top;
  }

  body.promo-manage main {
    max-width: none;
    margin: 0;
    background: transparent;
    padding: 0;
    border-radius: 0;
    box-shadow: none;
  }

  .admin-table th:nth-child(2),
  .admin-table td:nth-child(2) {
    width: 18%;
  }

  .admin-table th:nth-child(3),
  .admin-table td:nth-child(3) {
    width: 24%;
  }

  .admin-table th:nth-child(4),
  .admin-table td:nth-child(4) {
    width: 12%;
  }

  .admin-table th:nth-child(7),
  .admin-table td:nth-child(7) {
    width: 10%;
  }

  .admin-table th:nth-child(8),
  .admin-table td:nth-child(8) {
    width: 15%;
  }

  .custom-multiselect {
    position: relative;
    width: 100%;
  }

  .select-box {
    border: 1px solid #cbd5e1;
    padding: 10px 12px;
    border-radius: 8px;
    background: #fff;
    cursor: pointer;
    position: relative;
  }

  .select-box::after {
    content: "▼";
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 12px;
    color: #0f172a;
    pointer-events: none;
  }

  .options {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    border: 1px solid #cbd5e1;
    background: #fff;
    border-radius: 8px;
    max-height: 200px;
    overflow-y: auto;
    z-index: 9999;
  }

  .options label {
    display: block;
    padding: 6px 10px;
    cursor: pointer;
    font-size: 14px;
  }

  .options label:hover {
    background: #f1f5f9;
  }

  /* ---- Status pill ---- */
  .status-pill {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
  }

  .status-active {
    background: #dcfce7;
    color: #166534;
  }

  .status-inactive {
    background: #fee2e2;
    color: #b91c1c;
  }

  .status-expired {
    background: #e5e7eb;
    color: #374151;
  }

  .status-full {
    background: #fef9c3;
    color: #854d0e;
  }
</style>

<body class="promo-manage">
  <div class="page-wrap">
    <h1 style="margin-top: 40px;">Promotions Management</h1>

    <?php if ($flash): ?>
      <div class="alert success"><?= h($flash) ?></div>
    <?php endif; ?>

    <?php if ($promoErrors): ?>
      <script>
        (function() {
          // Pass PHP errors into JS safely
          var messages = <?php echo json_encode(
                            $promoErrors,
                            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
                          ); ?>;

          if (Array.isArray(messages) && messages.length > 0) {
            alert(messages.join("\n"));
          }
        })();
      </script>
    <?php endif; ?>

    <div class="table-toolbar">
      <button id="btnAdd" class="btn-sky">
        <span>➕</span><span>Add Promotion</span>
      </button>
    </div>

    <div class="table-responsive">
      <table class="admin-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Title / Description</th>
            <th>TnC</th>
            <th>Dates</th>
            <th>Value</th>
            <th>Type</th>
            <th>Eligibility</th>
            <th>Audit</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr>
              <td colspan="9">No promotions yet.</td>
            </tr>
            <?php else: foreach ($rows as $r):

              // --- derive display status ---
              $today      = date('Y-m-d');
              $rawStatus  = $r['PromoStatus'] ?? 'Active';

              $maxRed     = $r['MaxRedemptions'];
              $current    = (int)($r['RedemptionCount'] ?? 0);
              $hasLimit   = ($maxRed !== null && $maxRed !== '');
              $isFull     = $hasLimit && $current >= (int)$maxRed;

              $displayStatus = 'Active';
              $statusClass   = 'status-active';

              if ($rawStatus === 'Inactive') {
                // Manual or auto inactive
                $displayStatus = 'Inactive';
                $statusClass   = 'status-inactive';
              } elseif (!empty($r['EndDate']) && $r['EndDate'] < $today) {
                // Date passed
                $displayStatus = 'Expired';
                $statusClass   = 'status-expired';
              } elseif ($isFull) {
                // Hit redemption limit
                $displayStatus = 'Fully Redeemed';
                $statusClass   = 'status-full';
              } else {
                $displayStatus = 'Active';
                $statusClass   = 'status-active';
              }
            ?>
              <tr
                data-id="<?= (int)$r['PromotionID'] ?>"
                data-title="<?= h($r['Title']) ?>"
                data-desc="<?= h($r['Description']) ?>"
                data-tnc="<?= h($r['Tnc']) ?>"
                data-start="<?= h($r['StartDate']) ?>"
                data-end="<?= h($r['EndDate'] ?? '') ?>"
                data-disctype="<?= h($r['DiscountType']) ?>"
                data-discval="<?= h($r['DiscountValue']) ?>"
                data-minspend="<?= h($r['MinSpend']) ?>"
                data-promotype="<?= h($r['PromotionType']) ?>"
                data-status="<?= h($r['PromoStatus']) ?>"
                data-target="<?= h($r['TargetGroup'] ?? '') ?>"
                data-max="<?= h($r['MaxRedemptions']) ?>"
                data-redeemed="<?= h($r['RedemptionCount']) ?>"
                data-userids="<?= h($r['UserIDs'] ?? '') ?>"
                data-productids="<?= h($r['ProductIDs'] ?? '') ?>">
                <td><?= (int)$r['PromotionID'] ?></td>
                <td>
                  <div style="font-weight:700;"><?= h($r['Title']) ?></div>
                  <div style="color:#64748b;"><?= nl2br(h($r['Description'])) ?></div>
                  <div style="padding-top:30px;">
                    <strong>Status:</strong>
                    <span class="status-pill <?= $statusClass ?>"><?= h($displayStatus) ?></span>
                  </div>
                </td>
                <td>
                  <?php if (!empty($r['Tnc'])): ?>
                    <?php
                    $tncLines = explode('-', $r['Tnc']);
                    foreach ($tncLines as $line):
                      $line = trim($line);
                      if ($line !== ''): ?>
                        <div>✔︎ <?= h($line) ?></div>
                    <?php endif;
                    endforeach;
                    ?>
                  <?php else: ?>
                    <div>—</div>
                  <?php endif; ?>
                </td>
                <td>
                  <div><strong>Start:</strong> <?= h($r['StartDate']) ?></div>
                  <div><strong>End:</strong> <?= $r['EndDate'] ? h($r['EndDate']) : 'NULL' ?></div>
                </td>
                <td>
                  <div><?= h($r['DiscountType']) ?></div>
                  <div><?= h($r['DiscountValue']) ?></div>
                  <?php if ($r['PromotionType'] === 'Targeted' && $r['MinSpend'] !== null && $r['MinSpend'] !== ''): ?>
                    <div style="margin-top:4px;font-size:12px;color:#0f172a;">
                      Min spend: RM <?= number_format((float)$r['MinSpend'], 2) ?>
                    </div>
                  <?php endif; ?>
                  <div style="margin-top:6px;font-size:12px;color:#64748b;">
                    <?php if ($hasLimit): ?>
                      Usage: <?= (int)$current ?> / <?= (int)$maxRed ?>
                    <?php else: ?>
                      Usage: <?= (int)$current ?> used (unlimited)
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <div><?= h($r['PromotionType']) ?></div>
                  <div style="color:#64748b;"><?= $r['TargetGroup'] ? h($r['TargetGroup']) : '—' ?></div>
                </td>
                <td class="eligibility">
                  <?php if ($r['PromotionType'] === 'Targeted'): ?>
                    <?= $r['UserNames'] ? '✔︎ ' . h($r['UserNames']) : '—' ?>
                  <?php elseif ($r['PromotionType'] === 'Campaign'): ?>
                    <?= $r['ProductNames'] ? '✔︎ ' . h($r['ProductNames']) : '—' ?>
                  <?php else: ?>
                    —</td>
              <?php endif; ?>
              <td>
                <div><strong>CreatedAt: </strong><?= h($r['CreatedAt']) ?></div>
                <div style="color:#64748b;"><strong>UpdatedAt: </strong><?= h($r['UpdatedAt']) ?: '—' ?></div>
              </td>
              <td style="white-space:nowrap;">
                <button type="button" id="btn-edit" class="btn-edit jsEdit" title="Edit">✏️</button>
              </td>
              </tr>
          <?php endforeach;
          endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Modal -->
  <div id="promoModal" class="modal-backdrop">
    <div class="modal">
      <form method="post" id="promoForm">
        <div class="modal-header">
          <span id="modalTitle">Add Promotion</span>
          <div class="header-controls">
            <label for="PromotionTypeHeader">Promotion Type <span style="color:#dc2626">*</span></label>
            <select class="select" name="PromotionType" id="PromotionTypeHeader" required>
              <option value="">— Select Promotion Type —</option>
              <option value="Campaign">Campaign</option>
              <option value="Targeted">Targeted</option>
            </select>
          </div>
        </div>
        <div class="modal-body">
          <input type="hidden" name="action" id="formAction" value="create">
          <input type="hidden" name="PromotionID" id="PromotionID">
          <input type="hidden" name="EligibilityIDs" id="EligibilityIDs">

          <div class="form-grid">
            <div class="full">
              <label for="Title">Title <span style="color:#dc2626">*</span></label>
              <input class="input" type="text" name="Title" id="Title" required>
            </div>

            <div class="full">
              <label for="Description">Description <span style="color:#dc2626">*</span></label>
              <textarea class="textarea" name="Description" id="Description" required></textarea>
            </div>

            <div class="full">
              <label for="Tnc">Terms &amp; Conditions</label>
              <textarea class="textarea" name="Tnc" id="Tnc" placeholder="- Line 1&#10;- Line 2"></textarea>
            </div>

            <div>
              <label for="StartDate">Start Date <span style="color:#dc2626">*</span></label>
              <input class="input" type="date" name="StartDate" id="StartDate" required>
            </div>
            <div>
              <label for="EndDate">End Date</label>
              <input class="input" type="date" name="EndDate" id="EndDate">
            </div>

            <div>
              <label for="DiscountType">Discount Type <span style="color:#dc2626">*</span></label>
              <select class="select" name="DiscountType" id="DiscountType" required>
                <option value="">— Select Discount Type —</option>
                <option value="Percentage">Percentage</option>
                <option value="Fixed">Fixed</option>
              </select>
            </div>

            <div>
              <label for="DiscountValue">Discount Value <span style="color:#dc2626">*</span></label>
              <input class="input" type="text" name="DiscountValue" id="DiscountValue" required>
            </div>

            <div class="target-group-row">
              <label for="TargetGroup">Target Group</label>
              <!-- Free text with suggestions; admin can type new group names directly -->
              <input
                class="input"
                type="text"
                name="TargetGroup"
                id="TargetGroup"
                placeholder="e.g. Silver Member, Gold Member, Female 25-35"
                list="TargetGroupList">

              <!-- Optional: presets so admin can still pick quickly -->
              <datalist id="TargetGroupList">
                <option value="Silver Member"></option>
                <option value="Gold Member"></option>
                <option value="Platinum Member"></option>
                <option value="Females"></option>
                <option value="Males"></option>
                <option value="First Time Buyer"></option>
                <!-- You can add more defaults here later if you want -->
              </datalist>

              <small style="font-size:12px;color:#64748b;">
                Type to create a new target group, or pick from existing ones.
              </small>
            </div>

            <div class="promo-status-row">
              <label for="PromoStatus">Promotion Status</label>
              <select class="select" name="PromoStatus" id="PromoStatus">
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
              </select>
            </div>

            <div>
              <label for="MaxRedemptions">Max Redemptions</label>
              <input class="input" type="number" min="0" step="1" name="MaxRedemptions" id="MaxRedemptions" placeholder="Leave empty = unlimited">
            </div>

            <!-- Min Spend (Targeted only) -->
            <div class="min-spend-row">
              <label for="MinSpend">Minimum Spend (Targeted only)</label>
              <input class="input" type="number" min="0" step="0.01" name="MinSpend" id="MinSpend" placeholder="Leave empty for no minimum">
            </div>

            <div class="full">
              <label>Eligibility</label>
              <div class="custom-multiselect">
                <div class="select-box" id="eligSelectBox" onclick="toggleOptions()">Select Eligibility</div>
                <div class="options" id="eligibilityOptions"></div>
              </div>
              <small id="eligHelpText" style="font-size:12px;color:#64748b;">
                For Targeted: select users. For Campaign: select products.
              </small>
            </div>
          </div>
        </div>

        <div class="modal-actions">
          <button type="submit" class="btn-sky">Save</button>
          <button type="button" class="btn-secondary" id="btnCancel" style="border-radius:30px;background:#000;color:#fff;">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</body>

<script>
  // ---------- Data from PHP for eligibility ----------
  const usersData = <?php
                    echo json_encode(array_map(function ($u) {
                      return ['id' => (int)$u['UserID'], 'label' => $u['username']];
                    }, $users), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                    ?>;

  const productsData = <?php
                        echo json_encode(array_map(function ($p) {
                          return ['id' => (int)$p['ProductID'], 'label' => $p['Name']];
                        }, $products), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                        ?>;

  // Preset target-group eligible users from PHP (UserID arrays)
  const presetEligibleUsers = <?php
                              echo json_encode($targetGroupPresetUsers ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                              ?>;

  const modal = document.getElementById('promoModal');
  const form = document.getElementById('promoForm');
  const modalTitle = document.getElementById('modalTitle');
  const formAction = document.getElementById('formAction');
  const idInput = document.getElementById('PromotionID');
  const promoTypeHeader = document.getElementById('PromotionTypeHeader');
  const targetGroupEl = document.getElementById('TargetGroup');
  const targetGroupRow = document.querySelector('.target-group-row');
  const endDateInput = document.getElementById('EndDate');
  const promoStatusEl = document.getElementById('PromoStatus');
  const maxRedEl = document.getElementById('MaxRedemptions');
  const minSpendRow = document.querySelector('.min-spend-row');
  const minSpendEl = document.getElementById('MinSpend');
  const eligBox = document.getElementById('eligSelectBox');
  const eligOptionsDiv = document.getElementById('eligibilityOptions');
  const eligHidden = document.getElementById('EligibilityIDs');
  const eligHelpText = document.getElementById('eligHelpText');

  function buildEligibilityOptions(type, selectedIds = []) {
    eligOptionsDiv.innerHTML = '';

    let list = [];
    let allLabel = '';

    if (type === 'Targeted') {
      list = usersData || [];
      allLabel = 'All users';
    } else if (type === 'Campaign') {
      list = productsData || [];
      allLabel = 'All products';
    }

    const selectedSet = new Set((selectedIds || []).map(String));

    // --- "All" checkbox (first item) ---
    if (list.length > 0 && allLabel) {
      const labelAll = document.createElement('label');
      const cbAll = document.createElement('input');
      cbAll.type = 'checkbox';
      cbAll.value = '__ALL__';
      cbAll.classList.add('elig-all');
      labelAll.appendChild(cbAll);
      labelAll.append(' ' + allLabel);
      eligOptionsDiv.appendChild(labelAll);
    }

    // --- Normal items (users or products) ---
    list.forEach(item => {
      const label = document.createElement('label');
      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.value = String(item.id);
      cb.dataset.label = item.label;
      if (selectedSet.has(String(item.id))) {
        cb.checked = true;
      }
      label.appendChild(cb);
      label.append(' ' + item.label);
      eligOptionsDiv.appendChild(label);
    });

    updateEligibilityState();
  }

  function updateEligibilityState() {
    // Ignore the "All" checkbox when building the ID list
    const checked = Array.from(
      eligOptionsDiv.querySelectorAll('input[type=checkbox]:checked')
    ).filter(cb => !cb.classList.contains('elig-all'));

    const ids = checked.map(cb => cb.value);
    const labels = checked.map(
      cb => cb.dataset.label || cb.parentNode.textContent.trim()
    );

    eligHidden.value = ids.join(',');
    eligBox.textContent = labels.length ? labels.join(', ') : 'Select Eligibility';
  }

  eligOptionsDiv.addEventListener('change', (e) => {
    const target = e.target;
    if (!target.matches('input[type=checkbox]')) return;

    const isAll = target.classList.contains('elig-all');

    if (isAll) {
      // "All" toggled → check/uncheck every other item
      const checked = target.checked;
      eligOptionsDiv.querySelectorAll('input[type=checkbox]').forEach(cb => {
        if (!cb.classList.contains('elig-all')) {
          cb.checked = checked;
        }
      });
    } else {
      // A normal item was changed → if any unchecked, turn off "All"
      const cbAll = eligOptionsDiv.querySelector('input[type=checkbox].elig-all');
      if (cbAll && !target.checked) {
        cbAll.checked = false;
      } else if (cbAll) {
        // If every normal item is now checked, tick "All"
        const allNormalsChecked = Array.from(
          eligOptionsDiv.querySelectorAll('input[type=checkbox]:not(.elig-all)')
        ).every(cb => cb.checked);
        cbAll.checked = allNormalsChecked;
      }
    }

    updateEligibilityState();
  });

  function toggleOptions() {
    const displayNow = eligOptionsDiv.style.display === 'block';
    eligOptionsDiv.style.display = displayNow ? 'none' : 'block';
  }

  function applyTypeRules(selectedIds = []) {
    const type = promoTypeHeader.value || '';

    if (type === 'Targeted') {
      // Targeted: EndDate optional, TargetGroup required, users as eligibility
      targetGroupRow.style.display = '';
      targetGroupEl.setAttribute('required', 'required');
      endDateInput.removeAttribute('required');
      eligHelpText.textContent = 'For Targeted: select eligible users.';

      if (minSpendRow) minSpendRow.style.display = '';
      if (minSpendEl) minSpendEl.disabled = false;

      buildEligibilityOptions('Targeted', selectedIds);
    } else if (type === 'Campaign') {
      // Campaign: EndDate required, TargetGroup hidden/null, products as eligibility
      targetGroupRow.style.display = 'none';
      targetGroupEl.removeAttribute('required');
      targetGroupEl.value = '';
      endDateInput.setAttribute('required', 'required');
      eligHelpText.textContent = 'For Campaign: select eligible products.';

      if (minSpendRow) minSpendRow.style.display = 'none';
      if (minSpendEl) {
        minSpendEl.value = '';
        minSpendEl.disabled = true;
      }

      buildEligibilityOptions('Campaign', selectedIds);
    } else {
      // default: hide target group, clear eligibility
      targetGroupRow.style.display = 'none';
      targetGroupEl.removeAttribute('required');
      eligOptionsDiv.innerHTML = '';
      eligHidden.value = '';
      eligBox.textContent = 'Select Eligibility';
      eligHelpText.textContent = 'Select a promotion type first.';

      if (minSpendRow) minSpendRow.style.display = 'none';
      if (minSpendEl) {
        minSpendEl.value = '';
        minSpendEl.disabled = true;
      }
    }
  }

  // Auto-select eligibility checkboxes when choosing a preset Target Group
  function autoSelectEligibilityForTargetGroup() {
    const type = promoTypeHeader.value || '';
    if (type !== 'Targeted') return;

    const group = (targetGroupEl.value || '').trim();
    if (!group || !presetEligibleUsers[group] || !presetEligibleUsers[group].length) {
      return; // unknown group or no users
    }

    const ids = presetEligibleUsers[group].map(String);

    // Uncheck all normal items
    eligOptionsDiv.querySelectorAll('input[type=checkbox]').forEach(cb => {
      if (!cb.classList.contains('elig-all')) {
        cb.checked = false;
      }
    });

    // Check matching user IDs
    eligOptionsDiv.querySelectorAll('input[type=checkbox]').forEach(cb => {
      if (!cb.classList.contains('elig-all') && ids.includes(cb.value)) {
        cb.checked = true;
      }
    });

    // Update "All" checkbox state
    const cbAll = eligOptionsDiv.querySelector('input[type=checkbox].elig-all');
    if (cbAll) {
      const allNormalsChecked = Array.from(
        eligOptionsDiv.querySelectorAll('input[type=checkbox]:not(.elig-all)')
      ).every(cb => cb.checked);
      cbAll.checked = allNormalsChecked;
    }

    updateEligibilityState();
  }

  promoTypeHeader.addEventListener('change', () => applyTypeRules());
  if (targetGroupEl) {
    targetGroupEl.addEventListener('change', autoSelectEligibilityForTargetGroup);
  }

  function openCreate() {
    form.reset();
    eligHidden.value = '';
    eligBox.textContent = 'Select Eligibility';
    modalTitle.textContent = 'Add Promotion';
    formAction.value = 'create';
    idInput.value = '';
    promoTypeHeader.value = '';
    if (promoStatusEl) promoStatusEl.value = 'Active';
    if (maxRedEl) maxRedEl.value = '';
    if (minSpendEl) minSpendEl.value = '';
    applyTypeRules();
    modal.style.display = 'flex';
  }

  function openEdit(data) {
    form.reset();
    eligHidden.value = '';
    eligBox.textContent = 'Select Eligibility';

    modalTitle.textContent = 'Edit Promotion';
    formAction.value = 'update';
    idInput.value = data.id || '';

    form.Title.value = data.title || '';
    form.Description.value = data.desc || '';
    if (form.Tnc) form.Tnc.value = data.tnc || '';
    form.StartDate.value = (data.start || '').slice(0, 10);
    form.EndDate.value = (data.end || '').slice(0, 10);
    form.DiscountValue.value = data.discval || '';
    document.getElementById('DiscountType').value = data.disctype || '';

    // Promotion type + rules
    const type = data.promotype || 'Campaign';
    promoTypeHeader.value = type;

    // Status
    if (promoStatusEl) promoStatusEl.value = data.status || 'Active';

    // Target group value (only meaningful for Targeted)
    targetGroupEl.value = data.target || '';

    // Max redemptions (empty string = unlimited)
    if (maxRedEl) {
      maxRedEl.value = data.max && data.max !== 'null' ? data.max : '';
    }

    // Min Spend (Targeted only)
    if (minSpendEl) {
      minSpendEl.value = data.minSpend && data.minSpend !== 'null' ? data.minSpend : '';
    }

    const selectedIds = (type === 'Targeted') ? (data.userIds || []) : (data.productIds || []);
    applyTypeRules(selectedIds);

    // For Targeted with preset TargetGroup, auto-select again to sync with latest preset logic
    if (type === 'Targeted') {
      autoSelectEligibilityForTargetGroup();
    }

    modal.style.display = 'flex';
  }

  document.getElementById('btnAdd').addEventListener('click', openCreate);

  document.querySelectorAll('.jsEdit').forEach(btn => {
    btn.addEventListener('click', (e) => {
      const tr = e.currentTarget.closest('tr');
      const userIdsCsv = tr.dataset.userids || '';
      const productIdsCsv = tr.dataset.productids || '';

      openEdit({
        id: tr.dataset.id,
        title: tr.dataset.title,
        desc: tr.dataset.desc,
        tnc: tr.dataset.tnc,
        start: tr.dataset.start,
        end: tr.dataset.end,
        disctype: tr.dataset.disctype,
        discval: tr.dataset.discval,
        promotype: tr.dataset.promotype,
        status: tr.dataset.status,
        target: tr.dataset.target,
        max: tr.dataset.max,
        redeemed: tr.dataset.redeemed,
        minSpend: tr.dataset.minspend,
        userIds: userIdsCsv ? userIdsCsv.split(',') : [],
        productIds: productIdsCsv ? productIdsCsv.split(',') : []
      });
    });
  });

  document.getElementById('btnCancel').addEventListener('click', () => {
    modal.style.display = 'none';
    eligOptionsDiv.style.display = 'none';
  });

  modal.addEventListener('click', (e) => {
    if (e.target === modal) {
      modal.style.display = 'none';
      eligOptionsDiv.style.display = 'none';
    }
  });

form.addEventListener('submit', (e) => { 
  const type = document.getElementById('DiscountType').value;
  const discountRaw = document.getElementById('DiscountValue').value.trim();

  // Front-end numeric validation
  if (discountRaw === '' || isNaN(discountRaw)) {
    alert('Discount Value must be numeric.');
    e.preventDefault();
    return;
  }

  const val = parseFloat(discountRaw);

  // NEW: prevent negative discount value (for all types)
  if (val < 0) {
    alert('Discount Value cannot be negative.');
    e.preventDefault();
    return;
  }

  if (type === 'Percentage' && (val <= 0 || val > 100)) {
    alert('Percentage must be between 0.1 and 100.');
    e.preventDefault();
    return;
  }

  // Ensure eligibility is chosen (will be auto-filled for preset groups)
  if (!eligHidden.value) {
    alert('Please select eligibility items.');
    e.preventDefault();
    return;
  }

  // MaxRedemptions validation on front-end (non-negative integer)
  if (maxRedEl && maxRedEl.value !== '') {
    const mr = maxRedEl.value;
    if (!/^\d+$/.test(mr)) {
      alert('Max redemptions must be a whole number.');
      e.preventDefault();
      return;
    }
  }

  // MinSpend validation (non-negative number) – only if visible/enabled
  if (minSpendEl && !minSpendEl.disabled && minSpendEl.value !== '') {
    const ms = parseFloat(minSpendEl.value);
    if (isNaN(ms) || ms < 0) {
      alert('Minimum spend must be a non-negative number.');
      e.preventDefault();
      return;
    }
  }
});

</script>

<?php include 'admin_footer.php'; ?>