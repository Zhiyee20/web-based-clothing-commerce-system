<?php
// user/campaign.php
// Light luxury campaign hero slider for active Campaign promotions

// Reuse global escaper if already defined
if (!function_exists('h')) {
  function h($v)
  {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
  }
}

// Use existing $pdo from index.php if available, otherwise require config
if (!isset($pdo)) {
  require_once __DIR__ . '/../config.php';
}

$campaigns       = [];
$productsByPromo = [];
$hadError        = false;

try {
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

  // 1) Fetch Campaign promotions (Active only)
  $sql = "
    SELECT
      p.PromotionID,
      p.Title,
      p.Description,
      p.DiscountType,
      p.DiscountValue,
      p.EndDate
    FROM promotions p
    WHERE p.PromotionType = 'Campaign'
      AND p.PromoStatus   = 'Active'
    ORDER BY p.StartDate DESC, p.PromotionID DESC
  ";

  $campaigns = $pdo->query($sql)->fetchAll();

  if ($campaigns) {
    // 2) Fetch products for these campaigns from junction table promotion_products
    $ids = array_column($campaigns, 'PromotionID');
    $ids = array_map('intval', $ids);
    $ids = array_values(array_filter($ids, fn($x) => $x > 0));

    if ($ids) {
      $placeholders = implode(',', array_fill(0, count($ids), '?'));

      $stmt = $pdo->prepare("
        SELECT
          pp.PromotionID,
          pr.ProductID,
          pr.Name,
          pr.Price,
          (
            SELECT pi.ImagePath
            FROM product_images pi
            WHERE pi.ProductID = pr.ProductID
            ORDER BY pi.IsPrimary DESC, pi.SortOrder ASC, pi.ImageID ASC
            LIMIT 1
          ) AS ImagePath
        FROM promotion_products pp
        JOIN product pr ON pr.ProductID = pp.ProductID
        WHERE pp.PromotionID IN ($placeholders)
        ORDER BY pr.Name ASC
      ");
      $stmt->execute($ids);

      $rows = $stmt->fetchAll();
      foreach ($rows as $row) {
        $promoId = (int)$row['PromotionID'];
        if (!isset($productsByPromo[$promoId])) {
          $productsByPromo[$promoId] = [];
        }
        $productsByPromo[$promoId][] = $row;
      }
    }
  }
} catch (Throwable $e) {
  $hadError = true;
}
?>

<style>
  /* ===== Campaign section (light, simple, high-class) ===== */
  .campaign-section {
    max-width: 1200px;
    margin: 40px auto 60px;
    padding: 0 1.25rem;
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "SF Pro Text",
      "Segoe UI", sans-serif;
  }

  .campaign-heading {
    font-size: 0.85rem;
    letter-spacing: .25em;
    text-transform: uppercase;
    color: #9ca3af;
    margin-bottom: .3rem;
  }

  .campaign-title {
    font-size: 1.6rem;
    font-weight: 600;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: #111827;
    margin-bottom: 1rem;
  }

  .campaign-slider-shell {
    position: relative;
  }

  .campaign-card {
    position: relative;
    overflow: hidden;
    border-radius: 24px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
  }

  .campaign-slides-inner {
    position: relative;
    min-height: 220px;
  }

  .campaign-slide {
    position: absolute;
    inset: 0;
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    padding: 24px 26px 22px;
    opacity: 0;
    pointer-events: none;
    transform: translateX(20px);
    transition: opacity .5s ease, transform .5s ease;
  }

  .campaign-slide.is-active {
    position: relative;
    opacity: 1;
    pointer-events: auto;
    transform: translateX(0);
  }

  /* ===== Design A (slides 1, 3, 5, ...) ===== */
  .campaign-slide.design-a {
    background: linear-gradient(135deg, #f9fafb, #edf2fb);
  }

  /* ===== Design B (slides 2, 4, 6, ...) ===== */
  .campaign-slide.design-b {
    background: radial-gradient(circle at 0 0, #fdf7ee 0, #f3f4ff 50%, #edf2fb 100%);
  }

  .campaign-main {
    flex: 1 1 260px;
    min-width: 240px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
  }

  .campaign-products {
    flex: 1 1 280px;
    min-width: 250px;
    display: flex;
    align-items: stretch;
    justify-content: flex-end;
  }

  /* -- Flip order only for Design B: image left, text right -- */
  .campaign-slide.design-b .campaign-products {
    order: 1;
    justify-content: flex-start;
  }

  .campaign-slide.design-b .campaign-main {
    order: 2;
  }

  .campaign-tagline {
    font-size: .78rem;
    letter-spacing: .18em;
    text-transform: uppercase;
    color: #6b7280;
    margin-bottom: .65rem;
  }

  .campaign-name-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: .5rem;
  }

  .campaign-name {
    font-size: 1.3rem;
    font-weight: 600;
    letter-spacing: .14em;
    text-transform: uppercase;
    color: #111827;
  }

  .campaign-discount-pill {
    padding: .25rem .7rem;
    border-radius: 999px;
    border: 1px solid #e0b95b;
    background: linear-gradient(120deg, #fff7e0, #f5e7c1);
    color: #784b10;
    font-size: .75rem;
    font-weight: 500;
    letter-spacing: .16em;
    text-transform: uppercase;
    white-space: nowrap;
  }
  .campaign-time-left {
    display: inline-flex;
    align-items: center;
    margin-left: 1rem;
    padding: 0.25rem 0.85rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 600;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: #b91c1c;
    background: rgba(248, 113, 113, 0.08);
    border: 1px solid rgba(220, 38, 38, 0.45);
    box-shadow:
      0 6px 16px rgba(248, 113, 113, 0.28),
      0 0 0 1px rgba(248, 113, 113, 0.15);
    white-space: nowrap;
  }

  .campaign-time-left::before {
    content: "";
    width: 7px;
    height: 7px;
    border-radius: 999px;
    background: #ef4444;
    box-shadow: 0 0 0 4px rgba(248, 113, 113, 0.45);
    margin-right: 8px;
    animation: promo-pulse 1.4s ease-in-out infinite;
  }

  @keyframes promo-pulse {
    0% {
      transform: scale(1);
      opacity: 1;
    }
    70% {
      transform: scale(1.7);
      opacity: 0;
    }
    100% {
      transform: scale(1);
      opacity: 0;
    }
  }

  /* Slight variation of discount pill in Design B */
  .campaign-slide.design-b .campaign-discount-pill {
    border-color: #a5b4fc;
    background: linear-gradient(120deg, #eef2ff, #e0e7ff);
    color: #1e3a8a;
  }

  .campaign-snippet {
    font-size: .9rem;
    color: #4b5563;
    max-width: 30rem;
    margin-bottom: 0.9rem;
  }

  .campaign-cta-row {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-top: .4rem;
  }

  .campaign-cta-btn {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .45rem 1.1rem;
    border-radius: 999px;
    border: 1px solid #111827;
    background: #111827;
    color: #f9fafb;
    font-size: .78rem;
    letter-spacing: .16em;
    text-transform: uppercase;
    text-decoration: none;
  }

  .campaign-cta-btn span:last-child {
    font-size: .9rem;
  }

  /* CTA variant for Design B (lighter button) */
  .campaign-slide.design-b .campaign-cta-btn {
    border-color: #4b5563;
    background: #ffffff;
    color: #111827;
  }

  .campaign-cta-note {
    font-size: .8rem;
    color: #6b7280;
  }

  .campaign-product-grid {
    display: grid;
    grid-auto-flow: column;
    grid-auto-columns: minmax(120px, 160px);
    gap: 14px;
  }

  /* wrapper link for product card */
  .campaign-product-link {
    text-decoration: none;
    color: inherit;
    display: block;
  }

  .campaign-product-card {
    background: #ffffff;
    border-radius: 18px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 10px 20px rgba(15, 23, 42, 0.06);
    padding: 10px 10px 12px;
    display: flex;
    flex-direction: column;
    gap: 5px;
  }

  /* Slightly stronger border for Design B product cards */
  .campaign-slide.design-b .campaign-product-card {
    border-color: #d4d4ff;
    box-shadow: 0 10px 24px rgba(129, 140, 248, 0.18);
  }

  .campaign-product-img-wrapper {
    border-radius: 14px;
    overflow: hidden;
    background: #f3f4f6;
    aspect-ratio: 4 / 5;
  }

  .campaign-product-img-wrapper img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  .campaign-product-name {
    font-size: .8rem;
    font-weight: 500;
    color: #111827;
    white-space: nowrap;
    text-overflow: ellipsis;
    overflow: hidden;
  }

  /* OLD single-line price (kept for no-discount case) */
  .campaign-product-price {
    font-size: .78rem;
    color: #6b7280;
  }

  /* --- NEW: campaign product price styling (match product.php) --- */
  .campaign-product-price-block {
    text-align: left;
    margin-top: 2px;
    font-size: .78rem;
  }

  .campaign-product-price-original {
    font-size: .7rem;
    color: #9ca3af;
    text-decoration: line-through;
    margin-bottom: 2px;
  }

  .campaign-product-price-row {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    justify-content: flex-start;
  }

  .campaign-product-price-discount {
    font-weight: 600;
    font-size: .8rem;
    color: #cb5151ff;
  }

  .campaign-product-price-pill {
    font-size: .62rem;
    letter-spacing: .16em;
    text-transform: uppercase;
    padding: 2px 6px;
    border-radius: 5px;
    background: transparent;
    color: #cb5151ff;
    border: 1px solid #cb5151ff;
  }

  .campaign-product-tagline {
    font-size: .7rem;
    text-transform: uppercase;
    letter-spacing: .12em;
    color: #9ca3af;
  }

  /* Dots */
  .campaign-dots {
    display: flex;
    justify-content: center;
    gap: 8px;
    padding: 10px 0 14px;
  }

  .campaign-dot {
    width: 8px;
    height: 8px;
    border-radius: 999px;
    border: none;
    padding: 0;
    background: #d1d5db;
    cursor: pointer;
    transition: width .25s ease, background-color .25s ease, opacity .25s ease;
    opacity: .7;
  }

  .campaign-dot.is-active {
    width: 22px;
    background: #e0b95b;
    opacity: 1;
  }

  /* Placeholder (no campaigns or error) */
  .campaign-empty-card {
    border-radius: 24px;
    background: linear-gradient(135deg, #f9fafb, #eef2ff);
    border: 1px solid #e5e7eb;
    padding: 20px 22px;
    font-size: .9rem;
    box-shadow: 0 14px 30px rgba(15, 23, 42, 0.06);
  }

  .campaign-empty-title {
    font-weight: 500;
    color: #111827;
    margin-bottom: .1rem;
  }

  .campaign-empty-sub {
    color: #6b7280;
  }

  @media (max-width: 768px) {
    .campaign-slide {
      padding: 18px 16px 18px;
    }

    .campaign-name {
      font-size: 1.15rem;
    }

    .campaign-title {
      font-size: 1.4rem;
    }

    .campaign-product-grid {
      grid-auto-columns: minmax(120px, 140px);
    }
  }
</style>

<section class="campaign-section">
  <div class="campaign-heading">Exclusive</div>
  <div class="campaign-title">Promotions For You</div>

  <?php if ($hadError): ?>
    <div class="campaign-empty-card">
      <div class="campaign-empty-title">Promotions are temporarily unavailable.</div>
      <div class="campaign-empty-sub">Please refresh again in a moment.</div>
    </div>
  <?php elseif (!$campaigns): ?>
    <div class="campaign-empty-card">
      <div class="campaign-empty-title">No active campaign promotions now.</div>
      <div class="campaign-empty-sub">New seasonal campaigns will appear here once launched.</div>
    </div>
  <?php else: ?>
    <div class="campaign-slider-shell">
      <div class="campaign-card">
        <div class="campaign-slides-inner" id="campaignSlider">
          <?php foreach ($campaigns as $idx => $promo):
            $promoId      = (int)$promo['PromotionID'];
            $allProducts  = $productsByPromo[$promoId] ?? [];
            // Show up to 3 products, no internal scrolling
            $displayProducts = array_slice($allProducts, 0, 3);

            // Discount label for main pill
            $discValue = (float)$promo['DiscountValue'];
            if ($promo['DiscountType'] === 'Percentage') {
              $discLabel = rtrim(rtrim(number_format($discValue, 2), '0'), '.') . '% OFF';
            } else {
              $discLabel = 'RM ' . number_format($discValue, 2) . ' OFF';
            }

            // Short snippet from description (max ~90 chars)
            $desc = trim((string)$promo['Description']);
            $snippet = mb_substr($desc, 0, 90);
            if (mb_strlen($desc) > 90) {
              $snippet .= '…';
            }

            // Tagline varies by design
            $tagline = ($idx % 2 === 0) ? 'Exclusive Campaign' : 'Limited Time Edit';

            // CSS design variant:
            // idx 0,2,4... => design-a (image right)
            // idx 1,3,5... => design-b (image left)
            $designClass = ($idx % 2 === 0) ? ' design-a' : ' design-b';

            // Link to full promotion detail page
            $detailUrl = '/user/product.php?promo=' . $promoId;
            // Time-left badge (for campaigns ending within next 24 hours)
            $timeLeftLabel = '';
            $endDateStr    = $promo['EndDate'] ?? null;

            if (!empty($endDateStr)) {
              // Treat EndDate as ending at 23:59:59 of that day
              $endTs = strtotime($endDateStr . ' 23:59:59');
              if ($endTs !== false) {
                $nowTs   = time();
                $diffSec = $endTs - $nowTs;

                // Show badge only if > 0 and <= 24 hours
                if ($diffSec > 0 && $diffSec <= 24 * 3600) {
                  $hoursLeft = (int)ceil($diffSec / 3600);

                  if ($hoursLeft <= 1) {
                    // very close – you can change this text if you want
                    $timeLeftLabel = 'Ending soon';
                  } else {
                    $timeLeftLabel = $hoursLeft . ' hrs left';
                  }
                }
              }
            }
          ?>
            <article
              class="campaign-slide<?= $idx === 0 ? ' is-active' : '' ?><?= $designClass ?>"
              data-index="<?= $idx ?>">
              <div class="campaign-main">
                <div>
                  <div class="campaign-tagline"><?= h($tagline) ?></div>

                  <div class="campaign-name-row">
                    <h3 class="campaign-name"><?= h($promo['Title']) ?></h3>
                    <span class="campaign-discount-pill"><?= h($discLabel) ?></span>
                    <?php if ($timeLeftLabel !== ''): ?>
                      <span class="campaign-time-left"><?= h($timeLeftLabel) ?></span>
                    <?php endif; ?>
                  </div>

                  <?php if ($snippet !== ''): ?>
                    <p class="campaign-snippet"><?= h($snippet) ?></p>
                  <?php endif; ?>

                  <div class="campaign-cta-row">
                    <a href="<?= h($detailUrl) ?>" class="campaign-cta-btn">
                      <span>View Promotion</span>
                      <span>➝</span>
                    </a>
                    <span class="campaign-cta-note">Tap to see all items.</span>
                  </div>
                </div>
              </div>

              <div class="campaign-products">
                <?php if ($displayProducts): ?>
                  <div class="campaign-product-grid">
                    <?php foreach ($displayProducts as $p): ?>
                      <?php
                      $imgFile = trim((string)($p['ImagePath'] ?? ''));
                      $imgUrl  = $imgFile !== '' ? '/uploads/' . ltrim($imgFile, '/') : '';
                      $productId = (int)$p['ProductID'];
                      $productDetailUrl = '/user/product_detail.php?ProductID=' . $productId;

                      // Price + promo computation (same rule as product.php, but based on this campaign)
                      $origPrice  = (float)$p['Price'];
                      $discType   = $promo['DiscountType'] ?? null;
                      $discValRaw = $promo['DiscountValue'] ?? null;
                      $discValue  = isset($discValRaw) ? (float)$discValRaw : 0.0;
                      $promoPrice = null;

                      if ($discType === 'Percentage' && $discValue > 0) {
                        $promoPrice = round($origPrice * (1 - $discValue / 100), 2);
                      } elseif ($discValue > 0) {
                        // treat any non-zero non-Percentage as RM off
                        $promoPrice = max(round($origPrice - $discValue, 2), 0);
                      }

                      $hasCampaignPrice = $promoPrice !== null && $promoPrice < $origPrice;
                      ?>
                      <a
                        href="<?= h($productDetailUrl) ?>"
                        class="campaign-product-link">
                        <div class="campaign-product-card">
                          <div class="campaign-product-img-wrapper">
                            <?php if ($imgUrl !== ''): ?>
                              <img src="<?= h($imgUrl) ?>" alt="<?= h($p['Name']) ?>">
                            <?php else: ?>
                              <div style="width:100%;height:100%;background:#e5e7eb;"></div>
                            <?php endif; ?>
                          </div>
                          <div class="campaign-product-name" title="<?= h($p['Name']) ?>">
                            <?= h($p['Name']) ?>
                          </div>

                          <?php if ($hasCampaignPrice): ?>
                            <div class="campaign-product-price-block">
                              <div class="campaign-product-price-original">
                                RM <?= number_format($origPrice, 2) ?>
                              </div>
                              <div class="campaign-product-price-row">
                                <span class="campaign-product-price-discount">
                                  RM <?= number_format($promoPrice, 2) ?>
                                </span>
                              </div>
                            </div>
                          <?php else: ?>
                            <div class="campaign-product-price">
                              RM <?= number_format($origPrice, 2) ?>
                            </div>
                          <?php endif; ?>
                        </div>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <div style="align-self:center;font-size:.8rem;color:#9ca3af;">
                    Products for this campaign will be revealed soon.
                  </div>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if (count($campaigns) > 1): ?>
        <div class="campaign-dots" id="campaignDots">
          <?php foreach ($campaigns as $i => $_): ?>
            <button
              type="button"
              class="campaign-dot<?= $i === 0 ? ' is-active' : '' ?>"
              data-index="<?= $i ?>"
              aria-label="Go to promotion <?= $i + 1 ?>"></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>

<script>
  (function() {
    const slider = document.getElementById('campaignSlider');
    if (!slider) return;

    const slides = slider.querySelectorAll('.campaign-slide');
    if (!slides.length) return;

    const dotsContainer = document.getElementById('campaignDots');
    const dots = dotsContainer ? dotsContainer.querySelectorAll('.campaign-dot') : null;

    let current = 0;
    let timer = null;

    function setActive(index) {
      if (index === current) return;
      if (index < 0 || index >= slides.length) return;

      slides[current].classList.remove('is-active');
      if (dots) dots[current].classList.remove('is-active');

      slides[index].classList.add('is-active');
      if (dots) dots[index].classList.add('is-active');

      current = index;
    }

    function nextSlide() {
      const next = (current + 1) % slides.length;
      setActive(next);
    }

    function startAuto() {
      if (timer) clearInterval(timer);
      if (slides.length > 1) {
        timer = setInterval(nextSlide, 3000); // 3 seconds
      }
    }

    function stopAuto() {
      if (timer) {
        clearInterval(timer);
        timer = null;
      }
    }

    if (dots) {
      dots.forEach(dot => {
        dot.addEventListener('click', function() {
          const idx = parseInt(this.getAttribute('data-index'), 10);
          if (!Number.isNaN(idx)) {
            setActive(idx);
            startAuto();
          }
        });
      });
    }

    const shell = document.querySelector('.campaign-slider-shell');
    if (shell) {
      shell.addEventListener('mouseenter', stopAuto);
      shell.addEventListener('mouseleave', startAuto);
    }

    slides[0].classList.add('is-active');
    if (dots && dots[0]) dots[0].classList.add('is-active');
    startAuto();
  })();
</script>