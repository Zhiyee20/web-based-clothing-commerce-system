<?php
// user/target_promo_popup.php
// Show a popup for Targeted promotions for the currently logged-in user

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . '/../config.php';

$userID = $_SESSION['user']['UserID'] ?? null;

/* ---------------------------------------------------
   1) Handle "Collect All / OK" for shown vouchers only
   --------------------------------------------------- */
if (
    $userID
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['tp_action'])
) {
    $action  = $_POST['tp_action'];
    $promoIDs = [];

    if (!empty($_POST['promo_ids'])) {
        // promo_ids = "3,5,7"
        $parts = explode(',', $_POST['promo_ids']);
        foreach ($parts as $p) {
            $id = (int)trim($p);
            if ($id > 0) {
                $promoIDs[] = $id;
            }
        }
    }

    if ($action === 'mark_seen' && $promoIDs) {
        // COLLECT mode: mark only these as HasSeenPopup=1
        $placeholders = implode(',', array_fill(0, count($promoIDs), '?'));
        $params = array_merge([$userID], $promoIDs);

        $sql = "
            UPDATE promotion_users pu
            INNER JOIN promotions p ON p.PromotionID = pu.PromotionID
            SET pu.HasSeenPopup = 1
            WHERE pu.UserID = ?
              AND pu.IsRedeemed = 0
              AND pu.HasSeenPopup = 0
              AND p.PromotionType = 'Targeted'
              AND p.PromoStatus   = 'Active'
              AND (p.StartDate IS NULL OR p.StartDate <= CURDATE())
              AND (p.EndDate   IS NULL OR p.EndDate   >= CURDATE())
              AND pu.PromotionID IN ($placeholders)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'ack_fully' && $promoIDs) {
        // FULLY mode: mark only these as HasSeenFullyPopup=1
        $placeholders = implode(',', array_fill(0, count($promoIDs), '?'));
        $params = array_merge([$userID], $promoIDs);

        $sql = "
            UPDATE promotion_users pu
            INNER JOIN promotions p ON p.PromotionID = pu.PromotionID
            SET pu.HasSeenFullyPopup = 1
            WHERE pu.UserID = ?
              AND pu.IsRedeemed = 0
              AND pu.HasSeenPopup = 1
              AND pu.HasSeenFullyPopup = 0
              AND p.PromotionType = 'Targeted'
              AND p.PromoStatus   = 'Active'
              AND (p.StartDate IS NULL OR p.StartDate <= CURDATE())
              AND (p.EndDate   IS NULL OR p.EndDate   >= CURDATE())
              AND p.MaxRedemptions IS NOT NULL
              AND p.MaxRedemptions > 0
              AND p.RedemptionCount >= p.MaxRedemptions
              AND pu.PromotionID IN ($placeholders)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    }

    // Fallback
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false]);
    exit;
}

/* ---------------------------------------------------
   2) Fetch promos for popup with 2 modes:
      - collect: first time collect (HasSeenPopup = 0, not fully redeemed)
      - fully:   already collected, now fully redeemed (HasSeenPopup = 1)
   --------------------------------------------------- */

$targetPromos = [];
$popupMode    = null; // 'collect' | 'fully' | null

if ($userID) {
    // 2A) First priority: COLLECT mode
    if (empty($_SESSION['target_popup_shown'])) {
        $sqlCollect = "
            SELECT 
                p.PromotionID,
                p.Title,
                p.Description,
                p.StartDate,
                p.EndDate,
                p.DiscountType,
                p.DiscountValue,
                p.PromoStatus,
                p.MaxRedemptions,
                p.RedemptionCount,
                p.Tnc,
                p.MinSpend,
                p.TargetGroup
            FROM promotions p
            INNER JOIN promotion_users pu
                ON pu.PromotionID = p.PromotionID
            WHERE pu.UserID = ?
              AND pu.IsRedeemed = 0
              AND pu.HasSeenPopup = 0
              AND p.PromotionType = 'Targeted'
              AND p.PromoStatus   = 'Active'
              AND (p.StartDate IS NULL OR p.StartDate <= CURDATE())
              AND (p.EndDate   IS NULL OR p.EndDate   >= CURDATE())
              -- Exclude fully redeemed from first-time collect
              AND (
                    p.MaxRedemptions IS NULL
                 OR p.MaxRedemptions = 0
                 OR p.RedemptionCount < p.MaxRedemptions
              )
            ORDER BY p.StartDate DESC, p.PromotionID DESC
        ";
        $stmt = $pdo->prepare($sqlCollect);
        $stmt->execute([$userID]);
        $targetPromos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($targetPromos) {
            $popupMode = 'collect';
            $_SESSION['target_popup_shown'] = true; // once per session
        }
    }

    // 2B) Second priority: FULLY-REDEEMED mode
    if ($popupMode === null && empty($_SESSION['target_fully_popup_shown'])) {
        $sqlFully = "
            SELECT 
                p.PromotionID,
                p.Title,
                p.Description,
                p.StartDate,
                p.EndDate,
                p.DiscountType,
                p.DiscountValue,
                p.PromoStatus,
                p.MaxRedemptions,
                p.RedemptionCount,
                p.Tnc,
                p.MinSpend,
                p.TargetGroup
            FROM promotions p
            INNER JOIN promotion_users pu
                ON pu.PromotionID = p.PromotionID
            WHERE pu.UserID = ?
              AND pu.IsRedeemed = 0
              AND pu.HasSeenPopup = 1
              AND pu.HasSeenFullyPopup = 0
              AND p.PromotionType = 'Targeted'
              AND p.PromoStatus   = 'Active'
              AND (p.StartDate IS NULL OR p.StartDate <= CURDATE())
              AND (p.EndDate   IS NULL OR p.EndDate   >= CURDATE())
              -- Only those that are now fully redeemed
              AND p.MaxRedemptions IS NOT NULL
              AND p.MaxRedemptions > 0
              AND p.RedemptionCount >= p.MaxRedemptions
            ORDER BY p.EndDate DESC, p.PromotionID DESC
        ";
        $stmt = $pdo->prepare($sqlFully);
        $stmt->execute([$userID]);
        $targetPromos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($targetPromos) {
            $popupMode = 'fully';
            $_SESSION['target_fully_popup_shown'] = true; // once per session
        }
    }
}

// Nothing to show
if ($popupMode === null || !$targetPromos) {
    return;
}

function h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!-- Targeted Promotion Popup -->
<div id="target-promo-overlay" class="tp-overlay" data-mode="<?= h($popupMode) ?>">
    <div class="tp-modal">
        <button type="button" class="tp-close" aria-label="Close">&times;</button>

        <div class="tp-header">
            <div class="tp-gift"></div>
            <h2>
                <?php if ($popupMode === 'fully'): ?>
                    Saved Promotions Fully Redeemed
                <?php else: ?>
                    Special Deals Just for You
                <?php endif; ?>
            </h2>
            <p class="tp-sub">
                <?php if ($popupMode === 'fully'): ?>
                    Some targeted deals you collected have reached their redemption limit.
                <?php else: ?>
                    First-come, first-served basis
                <?php endif; ?>
            </p>
        </div>

        <div class="tp-body">
            <?php foreach ($targetPromos as $promo): ?>
                <?php
                $isPercent    = ($promo['DiscountType'] === 'Percentage');
                $discountText = $isPercent
                    ? rtrim(rtrim((float)$promo['DiscountValue'], '0'), '.') . '% OFF'
                    : 'RM ' . number_format((float)$promo['DiscountValue'], 2) . ' OFF';

                // Redemption cap / fully redeemed status
                $maxRed    = !empty($promo['MaxRedemptions']) ? (int)$promo['MaxRedemptions'] : 0;
                $usedCnt   = isset($promo['RedemptionCount']) ? (int)$promo['RedemptionCount'] : 0;
                $isLimited = ($maxRed > 0);
                $remaining = $isLimited ? max(0, $maxRed - $usedCnt) : null;
                $isFullyRedeemed = $isLimited && $remaining <= 0;

                // Min spend text
                $minSpendRaw = $promo['MinSpend'] ?? null;
                if ($minSpendRaw === null || $minSpendRaw === '' || (float)$minSpendRaw <= 0) {
                    $minSpendText = 'No min. spend';
                } else {
                    $minSpendText = 'Min. spend RM ' . number_format((float)$minSpendRaw, 2);
                }
                ?>
                <div class="tp-coupon-card <?= $isFullyRedeemed ? 'tp-disabled-card' : '' ?>"
                    data-promo-id="<?= (int)$promo['PromotionID'] ?>">
                    <div class="tp-tag">
                        <?= h($promo['TargetGroup'] ?: 'Targeted') ?> Only
                    </div>

                    <?php if ($isFullyRedeemed): ?>
                        <div class="tp-chop">FULLY REDEEMED</div>
                    <?php endif; ?>

                    <div class="tp-coupon-main">

                        <!-- LEFT: discount + min spend -->
                        <div class="tp-discount">
                            <div class="tp-discount-value"><?= h($discountText) ?></div>

                            <div class="tp-discount-note">
                                <?= h($minSpendText) ?>
                            </div>
                        </div>

                        <!-- RIGHT: promo info -->
                        <div class="tp-coupon-info">
                            <div class="tp-coupon-type"><?= h($promo['Title']) ?></div>

                            <div class="tp-line-2">
                                <?php
                                $parts = [];

                                if (!empty($promo['EndDate'])) {
                                    $parts[] = 'Time-limited';
                                }

                                if ($isLimited && $maxRed > 0) {
                                    $percentUsed = round(($usedCnt / $maxRed) * 100);
                                    $parts[] = $percentUsed . '% used';
                                }

                                echo h(implode(' | ', $parts));
                                ?>
                            </div>

                            <button
                                type="button"
                                class="tp-tnc-link"
                                data-tnc="<?= h($promo['Tnc']) ?>">
                                T&amp;C
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="tp-footer">
            <button type="button" class="tp-collect-btn">
                <?php if ($popupMode === 'fully'): ?>
                    OK, Got it
                <?php else: ?>
                    Collect All
                <?php endif; ?>
            </button>
            <p class="tp-footnote">
                <?php if ($popupMode === 'fully'): ?>
                    These promotions have reached their redemption limit and can no longer be used.
                <?php else: ?>
                    Promotions will be applied automatically at checkout if eligible.
                <?php endif; ?>
            </p>
        </div>
    </div>
</div>

<style>
    /* Backdrop */
    .tp-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.72);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.3s ease;
    }

    .tp-overlay.tp-show {
        opacity: 1;
        pointer-events: auto;
    }

    .tp-modal {
        position: relative;
        width: 100%;
        max-width: 540px;
        max-height: 90vh;
        background: linear-gradient(180deg, #fdf7ef 0%, #f6efe6 45%, #f8f4ee 100%);
        border-radius: 24px;
        box-shadow: 0 24px 80px rgba(0, 0, 0, 0.45);
        padding: 32px 32px 28px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        animation: tp-pop-in 0.35s ease-out;
    }

    .tp-close {
        position: absolute;
        top: 14px;
        right: 16px;
        border: none;
        background: transparent;
        font-size: 26px;
        cursor: pointer;
        color: #a17b4d;
    }

    .tp-header {
        text-align: center;
        margin-bottom: 20px;
        position: relative;
        padding-top: 24px;
    }

    .tp-header h2 {
        font-size: 24px;
        letter-spacing: 0.04em;
        color: #b37426;
        margin: 0 0 4px;
        font-weight: 700;
    }

    .tp-sub {
        margin: 0;
        font-size: 13px;
        color: #7f6a53;
    }

    .tp-gift {
        position: absolute;
        top: -38px;
        left: 50%;
        transform: translateX(-50%);
        width: 120px;
        height: 60px;
        background: radial-gradient(circle at 50% 0%, #ffe9b6 0%, transparent 60%);
        filter: blur(2px);
    }

    .tp-body {
        overflow-y: auto;
        padding-right: 4px;
        margin: 4px -8px 16px 0;
    }

    .tp-coupon-card {
        background: #fff7f0;
        border-radius: 18px;
        padding: 14px 18px 14px;
        margin-bottom: 10px;
        border: 1px solid #f2dcc4;
        display: flex;
        flex-direction: column;
        position: relative;
    }

    .tp-disabled-card {
        opacity: 0.55;
    }

    .tp-tag {
        position: absolute;
        top: 10px;
        left: 16px;
        padding: 2px 8px;
        font-size: 11px;
        font-weight: 600;
        color: #fff;
        background: linear-gradient(90deg, #f0a13d, #f9c07a);
        border-radius: 999px;
    }

    .tp-chop {
        position: absolute;
        right: 18px;
        top: 50%;
        transform: translateY(-50%) rotate(18deg);
        padding: 4px 14px;
        border-radius: 999px;
        border: 2px solid #d1d5db;
        color: #6b7280;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        background: rgba(249, 250, 251, 0.96);
        box-shadow: 0 0 0 1px rgba(209, 213, 219, 0.4);
    }

    .tp-coupon-main {
        display: flex;
        margin-top: 18px;
        gap: 18px;
    }

    .tp-discount {
        min-width: 160px;
        border-right: 1px dashed #f0c89b;
        padding-right: 16px;
    }

    .tp-discount-value {
        font-size: 24px;
        font-weight: 800;
        color: #f08a2f;
    }

    .tp-discount-note {
        font-size: 12px;
        color: #7b684e;
        margin-top: 4px;
    }

    .tp-coupon-info {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 6px;
        font-size: 12px;
    }

    .tp-coupon-type {
        font-weight: 600;
        color: #9b6a2a;
    }

    .tp-line-2 {
        font-size: 11px;
        color: #7b684e;
    }

    .tp-tnc-link {
        margin-top: 4px;
        align-self: flex-start;
        font-size: 11px;
        font-weight: 600;
        color: #111827;
        background: transparent;
        border: none;
        text-decoration: underline;
        cursor: pointer;
        padding: 0;
    }

    .tp-tnc-link:hover {
        opacity: 0.8;
    }

    .tp-footer {
        margin-top: 4px;
        text-align: center;
    }

    .tp-collect-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 260px;
        padding: 14px 32px;
        border-radius: 999px;
        border: none;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        background: #111827;
        color: #ffffff;
        box-shadow: 0 12px 26px rgba(0, 0, 0, 0.35);
        transition: transform 0.12s ease, box-shadow 0.12s ease;
    }

    .tp-collect-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 16px 36px rgba(0, 0, 0, 0.35);
    }

    .tp-footnote {
        margin-top: 6px;
        font-size: 11px;
        color: #8f7b60;
    }

    @keyframes tp-pop-in {
        from {
            transform: translateY(10px) scale(0.97);
            opacity: 0;
        }

        to {
            transform: translateY(0) scale(1);
            opacity: 1;
        }
    }

    @media (max-width: 720px) {
        .tp-modal {
            margin: 0 14px;
            padding: 24px 18px 20px;
        }

        .tp-coupon-main {
            flex-direction: column;
        }

        .tp-discount {
            border-right: none;
            border-bottom: 1px dashed #f0c89b;
            padding-right: 0;
            padding-bottom: 8px;
        }
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var overlay = document.getElementById('target-promo-overlay');
        if (!overlay) return;

        var mode = overlay.getAttribute('data-mode') || 'collect';

        setTimeout(function() {
            overlay.classList.add('tp-show');
        }, 600);

        function closePopup() {
            overlay.classList.remove('tp-show');
        }

        var closeBtn = overlay.querySelector('.tp-close');
        var collectBtn = overlay.querySelector('.tp-collect-btn');

        if (closeBtn) closeBtn.addEventListener('click', closePopup);

        if (collectBtn) {
            collectBtn.addEventListener('click', function() {
                // Gather promo IDs shown in this popup
                var ids = [];
                overlay.querySelectorAll('.tp-coupon-card').forEach(function(card) {
                    var pid = card.getAttribute('data-promo-id');
                    if (pid) {
                        ids.push(pid);
                    }
                });

                // If nothing, just close
                if (!ids.length) {
                    closePopup();
                    return;
                }

                var formData = new FormData();

                if (mode === 'fully') {
                    // FULLY mode: acknowledge fully redeemed → will not show again
                    formData.append('tp_action', 'ack_fully');
                } else {
                    // COLLECT mode: mark as HasSeenPopup = 1
                    formData.append('tp_action', 'mark_seen');
                }

                formData.append('promo_ids', ids.join(','));

                fetch('/user/target_promo_popup.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                }).finally(function() {
                    closePopup();
                });
            });
        }
        
        overlay.querySelectorAll('.tp-tnc-link').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var text = this.getAttribute('data-tnc') || 'No detailed terms available.';
                alert(text);
            });
        });

        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                closePopup();
            }
        });
    });
</script>