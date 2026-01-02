<?php
// user/header.php

require __DIR__ . '/../config.php';
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
$_user = $_SESSION['user'] ?? null;

/* 🔁 AUTO-ADJUST PROMOTION STATUS (run every request) */
try {
  // 1) Force INACTIVE if out of valid date range
  $sqlInactive = "
    UPDATE promotions
    SET PromoStatus = 'Inactive'
    WHERE PromoStatus = 'Active'
      AND (
        (EndDate IS NOT NULL AND EndDate < CURDATE())        -- already expired
        OR (StartDate IS NOT NULL AND StartDate > CURDATE()) -- not yet started
      )
  ";
  $pdo->exec($sqlInactive);

  // 2) Force ACTIVE if within valid date range
  $sqlActive = "
    UPDATE promotions
    SET PromoStatus = 'Active'
    WHERE PromoStatus = 'Inactive'
      AND (StartDate IS NULL OR StartDate <= CURDATE())
      AND (EndDate   IS NULL OR EndDate   >= CURDATE())
  ";
  $pdo->exec($sqlActive);
} catch (Throwable $e) {
  // Optional: log error somewhere, but don't break the header
  // error_log('Promo auto-status adjust failed: ' . $e->getMessage());
}

$currentPage = basename($_SERVER['PHP_SELF']);
if ($currentPage === 'product.php') {
  // Categories
  $categories = $pdo
    ->query("SELECT CategoryID, CategoryName FROM categories UNION SELECT NULL,'Unknown'")
    ->fetchAll(PDO::FETCH_ASSOC);

  // NEW: All available genders from product table (TargetGender)
  $genderOptions = $pdo
    ->query("
      SELECT DISTINCT TargetGender
      FROM product
      WHERE TargetGender IS NOT NULL
        AND TargetGender <> ''
      ORDER BY TargetGender
    ")
    ->fetchAll(PDO::FETCH_COLUMN);

  $colorOptions = $pdo
    ->query("
      SELECT 
        ColorName,
        MIN(ColorCode) AS ColorCode  -- pick one code if multiple
      FROM product_colors
      WHERE ColorName IS NOT NULL
        AND ColorName <> ''
      GROUP BY ColorName
      ORDER BY ColorName
    ")
    ->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <meta name="description" content="Luxury Store - Your destination for timeless elegance. Shop now!" />
  <title>Luxera</title>

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
  <script src="/assets/app.js"></script>

  <style>
    /* ===== Header ===== */
    header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 20px 40px;
      background: #fff;
      font-family: Arial, sans-serif;
      box-shadow: 0 2px 8px rgba(0, 0, 0, .1)
    }

    .header-left,
    .header-right {
      display: flex;
      align-items: center
    }

    .navbar {
      display: flex;
      gap: 20px
    }

    .navbar a {
      text-decoration: none;
      color: #fff;
      font-size: 16px
    }

    .header-center {
      flex-grow: 1;
      text-align: center
    }

    .logo {
      font-family: 'Playfair Display', serif;
      font-size: 36px;
      color: #fff;
      text-decoration: none
    }

    .header-right {
      gap: 20px
    }

    .admin-panel,
    .call-us,
    .user-logo,
    .info-icon {
      font-size: 14px;
      color: #fff
    }

    /* ===== Tooltip (hover label) ===== */
    .tooltip {
      position: relative;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
    }

    .tooltip .tooltiptext {
      visibility: hidden;
      opacity: 0;
      width: max-content;
      background-color: rgba(0, 0, 0, 0.9);
      color: #fff;
      text-align: center;
      padding: 6px 10px;
      border-radius: 6px;
      font-size: 12px;
      white-space: nowrap;

      position: absolute;
      z-index: 9999;
      bottom: -36px;
      /* slightly below icon */
      left: 50%;
      transform: translateX(-50%);
      transition: opacity 0.2s ease;
      pointer-events: none;
    }

    .tooltip:hover .tooltiptext {
      visibility: visible;
      opacity: 1;
    }

    .header-right a,
    .header-right a:link,
    .header-right a:visited,
    .header-right a:hover,
    .header-right a:active {
      text-decoration: none !important;
    }

    /* ===== User dropdown (new) ===== */
    .profile-container {
      position: relative;
      display: flex;
      align-items: center;
      cursor: pointer;
    }

    .profile-pic {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      object-fit: cover;
      border: 2px solid rgba(255, 255, 255, 0.6);
    }

    .profile-menu {
      position: absolute;
      right: 0;
      top: 48px;
      min-width: 160px;
      background: #fff;
      border: 1px solid #eee;
      border-radius: 10px;
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
      padding: 8px 0;
      display: none;
      opacity: 0;
      transition: opacity .2s ease;
      z-index: 9999;
    }

    .profile-menu a {
      display: block;
      padding: 10px 14px;
      color: #333;
      text-decoration: none;
      font-size: 14px;
      white-space: nowrap;
    }

    .profile-menu a:hover {
      background: #f6f8fa;
    }

    /* ===== Drawer ===== */
    html,
    body {
      max-width: 100%;
      overflow-x: hidden;
    }

    #drawer-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .6);
      opacity: 0;
      visibility: hidden;
      transition: opacity .2s, visibility .2s;
      z-index: 10000
    }

    #filter-drawer {
      max-width: min(420px, 86vw);
      overflow-x: hidden;
      position: fixed;
      top: 0;
      left: 0;
      height: 100vh;
      width: min(420px, 86vw);
      background: #fff;
      color: #000;
      transform: translateX(-100%);
      transition: transform .28s;
      z-index: 10001;
      box-shadow: 2px 0 24px rgba(0, 0, 0, .18);
      overflow-y: auto;
      -webkit-overflow-scrolling: touch
    }

    .drawer-open #drawer-backdrop {
      opacity: 1;
      visibility: visible
    }

    .drawer-open #filter-drawer {
      transform: translateX(0)
    }

    .drawer-open {
      overflow: hidden
    }

    .drawer-header {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 18px 22px;
      border-bottom: 1px solid #eee;
      position: sticky;
      top: 0;
      z-index: 1;
      background: #fff
    }

    .drawer-x {
      font-size: 28px;
      line-height: 1;
      background: none;
      border: 0;
      cursor: pointer;
      color: #000
    }

    .drawer-title {
      margin: 0;
      flex: 1;
      text-align: center;
      font-size: 16px;
      font-weight: 700;
      color: #222
    }

    .drawer-nav {
      display: block;
      padding: 0
    }

    .drawer-section {
      border-bottom: 1px solid #eee
    }

    .drawer-section>summary {
      list-style: none;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 14px 22px;
      cursor: pointer;
      font-size: 16px;
      color: #222
    }

    .drawer-section>summary i.fa {
      transition: transform .2s;
      color: #444;
      font-size: 14px
    }

    .drawer-section[open]>summary i.fa {
      transform: rotate(90deg)
    }

    .drawer-panel {
      padding: 10px 22px 18px
    }

    .drawer-panel select,
    .drawer-panel input[type=number] {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid #ddd;
      border-radius: 8px;
      font-size: 14px;
      background: #fff
    }

    .checkbox-select::-webkit-scrollbar {
      width: 6px;
    }

    .checkbox-select::-webkit-scrollbar-thumb {
      background: #ccc;
      border-radius: 4px;
    }

    .checkbox-select label:hover {
      background: #f9f9f9;
    }

    .price-row {
      display: flex;
      align-items: center;
      gap: 10px
    }

    .price-row .dash {
      color: #666
    }

    .drawer-footer {
      position: sticky;
      bottom: 0;
      left: 0;
      width: 100%;
      background: #fff;
      border-top: 1px solid #eee;
      padding: 12px 16px;
      /* ⬅️ added horizontal padding */
      display: grid;
      grid-template-columns: 1fr 1.4fr;
      gap: 10px;
      box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.05);
      z-index: 2;
    }

    .drawer-footer .btn {
      flex: 1;
      padding: 12px 0;
      font-size: 15px;
      font-weight: 600;
      border-radius: 8px;
      text-align: center;
      cursor: pointer;
    }

    .drawer-footer .btn-light {
      background: #fff;
      border: 1px solid #ccc;
      color: #333;
    }

    .drawer-footer .btn-dark {
      background: #e74c3c;
      border: none;
      color: #fff;
    }

    /* ===== Top-Sheet (Search) ===== */
    #search-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .6);
      opacity: 0;
      visibility: hidden;
      transition: opacity .25s, visibility .25s;
      z-index: 11000
    }

    #search-sheet {
      position: fixed;
      left: 0;
      right: 0;
      top: 0;
      height: 100vh;
      background: #fff;
      transform: translateY(-100%);
      transition: transform .32s ease;
      z-index: 11001;
      box-shadow: 0 10px 30px rgba(0, 0, 0, .2);
      display: flex;
      flex-direction: column
    }

    .search-frame {
      width: 100%;
      height: 100vh;
      border: 0
    }

    .search-open #search-backdrop {
      opacity: 1;
      visibility: visible
    }

    .search-open #search-sheet {
      transform: translateY(0)
    }

    .search-open {
      overflow: hidden
    }
  </style>
</head>

<body>
  <header>
    <div class="header-left">
      <div class="navbar">
        <!-- HOME -->
        <a href="/index.php" class="tooltip">
          <i class="fa fa-home"></i>
          <span class="tooltiptext">Home</span>
        </a>

        <!-- CART -->
        <a href="/user/cart.php" class="tooltip">
          <i class="fa fa-shopping-cart"></i>
          <span class="tooltiptext">Cart</span>
        </a>

        <!-- SEARCH -->
        <a href="/search/search.php" id="search-icon" class="tooltip">
          <i class="fa fa-search"></i>
          <span class="tooltiptext">Search</span>
        </a>

        <!-- FILTER (only on product.php) -->
        <?php if ($currentPage === 'product.php'): ?>
          <button
            id="filter-open"
            type="button"
            aria-controls="filter-drawer"
            aria-expanded="false"
            title="Filters"
            class="tooltip"
            style="background:none;border:0;padding:0;color:#fff;">
            <i class="fa fa-filter"></i>
            <span class="tooltiptext">Filter</span>
          </button>
        <?php endif; ?>
      </div>
    </div>
    <div class="header-center">
      <a href="/" class="logo">Luxera</a>
    </div>
    <div class="header-right">
      <?php if ($_user && (($_user['Role'] ?? '') === 'Admin')): ?>
        <a href="/admin/notification.php" class="admin-panel tooltip">
          <i class="fa fa-envelope"></i>
          <span class="tooltiptext">Inbox</span>
        </a>
        <a href="/admin/dashboard.php" class="admin-panel tooltip">
          <i class="fa fa-cogs"></i>
          <span class="tooltiptext">Admin</span>
        </a>
      <?php endif; ?>

      <a href="/user/about.php" class="info-icon tooltip">
        <i class="fa fa-info-circle"></i>
        <span class="tooltiptext">About</span>
      </a>

      <a href="/user/contact.php" class="call-us tooltip">
        <i class="fa fa-phone"></i>
        <span class="tooltiptext">Contact</span>
      </a>

      <?php if ($_user): ?>
        <div class="profile-container tooltip" id="profileContainer">
          <img
            class="profile-pic"
            src="/../uploads/<?= htmlspecialchars($_user['photo'] ?: 'default_user.jpg') ?>"
            alt="Profile Picture" />
          <div class="profile-menu" id="profileMenu">
            <a href="/user/profile.php">Profile</a>
            <a href="/user/password.php">Password</a>
            <a href="/security/logout.php">Logout</a>
          </div>
        </div>
      <?php else: ?>
        <div class="profile-container tooltip" id="profileContainer">
          <a class="user-logo" id="authToggle" aria-label="Account">
            <i class="fa fa-user"></i>
          </a>
          <span class="tooltiptext">Account</span>
          <div class="profile-menu" id="profileMenu">
            <a href="/security/login.php">Login</a>
            <a href="/security/register.php">Register</a>
            <a href="/security/reactivate_account.php">Reactivate</a>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </header>

  <!-- Backdrop + Drawer -->
  <div id="drawer-backdrop" tabindex="-1" aria-hidden="true"></div>
  <aside id="filter-drawer" role="dialog" aria-modal="true" aria-label="Filters" aria-hidden="true">
    <div class="drawer-header">
      <button id="filter-close" type="button" class="drawer-x" aria-label="Close filters">×</button>
      <h3 class="drawer-title">Filter & Sort</h3>
    </div>
    <nav class="drawer-nav">
      <form id="drawer-filter-form" method="GET" action="product.php">
        <details class="drawer-section" <?= !empty($_GET['category']) ? 'open' : '' ?>>
          <summary><span>Category</span><i class="fa fa-chevron-right"></i></summary>
          <div class="drawer-panel">
            <div class="checkbox-select" style="border:1px solid #ddd;border-radius:8px;padding:10px;max-height:180px;overflow-y:auto;">
              <?php
              $selectedCats = (array)($_GET['category'] ?? []);
              foreach ($categories ?? [] as $cat):
                $id = $cat['CategoryID'];
                $name = $cat['CategoryName'] ?? 'Unknown';
                $checked = in_array((string)$id, $selectedCats) ? 'checked' : '';
                $isUnknown = ($name === 'Unknown');
                if (!$isUnknown):
              ?>
                  <label style="display:flex;align-items:center;justify-content:space-between;padding:6px 4px;border-bottom:1px solid #f1f1f1;font-size:14px;color:#333;">
                    <span><?= htmlspecialchars($name) ?></span>
                    <input type="checkbox" name="category[]" value="<?= htmlspecialchars($id) ?>" <?= $checked ?>
                      style="width:16px;height:16px;cursor:pointer;">
                  </label>
              <?php endif;
              endforeach; ?>
              <!-- Unknown option -->
              <label style="display:flex;align-items:center;justify-content:space-between;padding:6px 4px;font-size:14px;color:#333;">
                <span>Unknown</span>
                <input type="checkbox" name="category[]" value="NULL"
                  <?= in_array('NULL', $selectedCats) ? 'checked' : '' ?>
                  style="width:16px;height:16px;cursor:pointer;">
              </label>
            </div>
          </div>
        </details>

        <!-- GENDER FILTER -->
        <details class="drawer-section" <?= ($_GET['gender'] ?? '') !== '' ? 'open' : '' ?>>
          <summary><span>Gender</span><i class="fa fa-chevron-right"></i></summary>
          <div class="drawer-panel">
            <div class="checkbox-select" style="border:1px solid #eee;border-radius:8px;padding:10px;max-height:150px;overflow-y:auto;">
              <?php
              $selectedGender = $_GET['gender'] ?? '';
              // Single-select via radio
              foreach ($genderOptions ?? [] as $g):
                if ($g === null || $g === '') continue;
                $label   = htmlspecialchars($g, ENT_QUOTES, 'UTF-8');
                $checked = ($selectedGender === $g) ? 'checked' : '';
              ?>
                <label style="display:flex;align-items:center;justify-content:space-between;padding:6px 4px;border-bottom:1px solid #f1f1f1;font-size:14px;color:#333;">
                  <span><?= $label ?></span>
                  <input
                    type="radio"
                    name="gender"
                    value="<?= $label ?>"
                    <?= $checked ?>
                    style="width:16px;height:16px;cursor:pointer;">
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </details>

        <!-- COLOR FILTER -->
        <details class="drawer-section" <?= !empty($_GET['color']) ? 'open' : '' ?>>
          <summary><span>Color</span><i class="fa fa-chevron-right"></i></summary>
          <div class="drawer-panel">
            <div class="checkbox-select" style="border:1px solid #eee;border-radius:8px;padding:10px;max-height:180px;overflow-y:auto;">

              <?php
              $selectedColors = (array)($_GET['color'] ?? []);

              foreach ($colorOptions ?? [] as $c):
                $name = $c['ColorName'];
                $code = $c['ColorCode'] ?: '#cccccc';  // fallback

                if ($name === null || $name === '') continue;

                $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
                $checked  = in_array($name, $selectedColors, true) ? 'checked' : '';
              ?>

                <label style="display:flex;align-items:center;justify-content:space-between;padding:6px 4px;border-bottom:1px solid #f1f1f1;font-size:14px;color:#333;">

                  <span style="display:flex;align-items:center;gap:8px;">
                    <span style="
                      width:14px;
                      height:14px;
                      border-radius:50%;
                      background: <?= htmlspecialchars($code, ENT_QUOTES) ?>;
                      border:1px solid #999;
                      display:inline-block;
                    "></span>

                    <?= $safeName ?>
                  </span>

                  <input
                    type="checkbox"
                    name="color[]"
                    value="<?= $safeName ?>"
                    <?= $checked ?>
                    style="width:16px;height:16px;cursor:pointer;">
                </label>

              <?php endforeach; ?>

            </div>
          </div>
        </details>

        <details class="drawer-section" <?= (!empty($_GET['min_price']) || !empty($_GET['max_price'])) ? 'open' : '' ?>>
          <summary><span>Price</span><i class="fa fa-chevron-right"></i></summary>
          <div class="drawer-panel price-row">
            <input type="number" name="min_price" min="0" placeholder="Min Price" value="<?= htmlspecialchars($_GET['min_price'] ?? '') ?>">
            <span class="dash">–</span>
            <input type="number" name="max_price" min="0" placeholder="Max Price" value="<?= htmlspecialchars($_GET['max_price'] ?? '') ?>">
          </div>
        </details>

        <details class="drawer-section" <?= !empty($_GET['sort']) ? 'open' : '' ?>>
          <summary><span>Sort</span><i class="fa fa-chevron-right"></i></summary>
          <div class="drawer-panel">
            <div class="checkbox-select" style="border:1px solid #ddd;border-radius:8px;padding:10px;max-height:150px;overflow-y:auto;">
              <?php
              $selectedSort = (array)($_GET['sort'] ?? []);
              $sortOptions = [
                'name_asc'  => 'Name (A-Z)',
                'name_desc' => 'Name (Z-A)',
                'price_asc' => 'Price ↗',
                'price_desc' => 'Price ↘'
              ];
              foreach ($sortOptions as $val => $label):
                $checked = in_array($val, $selectedSort) ? 'checked' : '';
              ?>
                <label style="display:flex;align-items:center;justify-content:space-between;padding:6px 4px;border-bottom:1px solid #f1f1f1;font-size:14px;color:#333;">
                  <span><?= htmlspecialchars($label) ?></span>
                  <input type="checkbox" name="sort[]" value="<?= htmlspecialchars($val) ?>" <?= $checked ?> style="width:16px;height:16px;cursor:pointer;"
                    onclick="
                      if(this.value.startsWith('name')){
                        this.closest('.checkbox-select').querySelectorAll('input[value^=name]').forEach(cb=>{
                          if(cb!==this) cb.checked=false;
                        });
                      }
                      if(this.value.startsWith('price')){
                        this.closest('.checkbox-select').querySelectorAll('input[value^=price]').forEach(cb=>{
                          if(cb!==this) cb.checked=false;
                        });
                      }
                    ">
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </details>
        <div class="drawer-footer">
          <a class="btn btn-light" href="product.php" id="btn-reset">Reset all filters</a>
          <button type="submit" class="btn btn-dark" id="btn-apply">See results</button>
        </div>
      </form>
    </nav>

  </aside>

  <!-- Top-sheet (Search) -->
  <div id="search-backdrop" aria-hidden="true"></div>
  <section id="search-sheet" role="dialog" aria-modal="true" aria-label="Search overlay" aria-hidden="true">
    <iframe class="search-frame" src="/search/search.php" title="Search"></iframe>
  </section>

</body>

<script>
  (function() {
    const body = document.body;

    /* ===== User dropdown hover (new) ===== */
    const profileContainer = document.getElementById('profileContainer');
    const profileMenu = document.getElementById('profileMenu');
    let hideT;

    if (profileContainer && profileMenu) {
      profileContainer.addEventListener('mouseenter', () => {
        clearTimeout(hideT);
        profileMenu.style.display = 'block';
        requestAnimationFrame(() => profileMenu.style.opacity = '1');
      });
      profileContainer.addEventListener('mouseleave', () => {
        hideT = setTimeout(() => {
          profileMenu.style.opacity = '0';
          setTimeout(() => (profileMenu.style.display = 'none'), 150);
        }, 200);
      });
    }
    /* ========== Filter Drawer ========== */
    const openBtn = document.getElementById('filter-open');
    const closeBtn = document.getElementById('filter-close');
    const drawer = document.getElementById('filter-drawer');
    const backdrop = document.getElementById('drawer-backdrop');

    function openDrawer() {
      body.classList.add('drawer-open');
      drawer.setAttribute('aria-hidden', 'false');
      openBtn?.setAttribute('aria-expanded', 'true');
      setTimeout(() => closeBtn?.focus(), 50);
    }

    function closeDrawer() {
      body.classList.remove('drawer-open');
      drawer.setAttribute('aria-hidden', 'true');
      openBtn?.setAttribute('aria-expanded', 'false');
      openBtn?.focus();
    }
    window.openFilterDrawer = openDrawer; // expose for iframe
    window.closeFilterDrawer = closeDrawer;

    openBtn?.addEventListener('click', e => {
      e.preventDefault();
      openDrawer();
    });
    closeBtn?.addEventListener('click', closeDrawer);
    backdrop?.addEventListener('click', closeDrawer);

    /* ========== Search Top-Sheet ========== */
    const searchLink = document.getElementById('search-icon');
    const searchSheet = document.getElementById('search-sheet');
    const searchBackdrop = document.getElementById('search-backdrop');
    const searchFrame = document.querySelector('.search-frame');

    function openSearch() {
      body.classList.add('search-open');
      searchSheet?.setAttribute('aria-hidden', 'false');
    }

    function closeSearch() {
      body.classList.remove('search-open');
      searchSheet?.setAttribute('aria-hidden', 'true');
      searchLink?.focus();
    }
    searchLink?.addEventListener('click', e => {
      e.preventDefault();
      openSearch();
    });
    searchBackdrop?.addEventListener('click', closeSearch);

    /* ========== Inject Back & Filter into search.php (iframe) ========== */
    function hasQuery(doc) {
      const url = new URL(doc.defaultView.location.href);
      return !!(url.searchParams.get('q') || url.searchParams.get('query') || url.searchParams.get('search'));
    }

    function hasResults(doc) {
      // Match common classes you use for product cards
      return doc.querySelectorAll('.product-item, .product-card, [data-product-id], .product-grid .product-item').length > 0;
    }

    function ensureControls(doc) {
      const leftBar = doc.querySelector('.header-left .navbar');
      if (!leftBar) return {
        back: null,
        filter: null
      };

      // Back button
      let backBtn = doc.getElementById('sr-back-btn');
      if (!backBtn) {
        backBtn = doc.createElement('a');
        backBtn.id = 'sr-back-btn';
        backBtn.href = '#';
        backBtn.style.cssText = 'display:none;color:#fff;font-size:18px;margin-right:14px;';
        backBtn.innerHTML = '<i class="fa fa-arrow-left"></i>';
        backBtn.addEventListener('click', e => {
          e.preventDefault();
          doc.defaultView.location.href = '/search/search.php';
        });
        leftBar.prepend(backBtn);
      }

      // Filter button
      let filterBtn = doc.getElementById('sr-filter-btn');
      if (!filterBtn) {
        filterBtn = doc.createElement('button');
        filterBtn.id = 'sr-filter-btn';
        filterBtn.type = 'button';
        filterBtn.style.cssText = 'display:none;background:none;border:0;color:#fff;font-size:18px;cursor:pointer;';
        filterBtn.innerHTML = '<i class="fa fa-filter"></i>';
        filterBtn.addEventListener('click', e => {
          e.preventDefault();
          if (window.parent && typeof window.parent.openFilterDrawer === 'function') {
            window.parent.openFilterDrawer();
          }
        });
        leftBar.insertBefore(filterBtn, leftBar.children[1] || null);
      }

      return {
        back: backBtn,
        filter: filterBtn
      };
    }

    function tuneSearchHeader() {
      try {
        const doc = searchFrame?.contentDocument || searchFrame?.contentWindow?.document;
        if (!doc) return;

        const header = doc.querySelector('header');
        if (!header) return;

        // Hide left icons inside iframe search header
        doc.querySelectorAll('.header-left .navbar a, .header-left .navbar button').forEach(a => {
          a.style.display = 'none';
        });

        // Right side: hide everything and inject a dedicated Close (×) button
        const right = doc.querySelector('.header-right');
        if (right) {
          Array.from(right.children).forEach(el => {
            el.style.display = 'none';
          });

          let closeBtn = doc.getElementById('sr-close');
          if (!closeBtn) {
            closeBtn = doc.createElement('button');
            closeBtn.id = 'sr-close';
            closeBtn.type = 'button';
            closeBtn.setAttribute('aria-label', 'Close');
            closeBtn.style.cssText =
              'background:none;border:0;font-size:22px;cursor:pointer;color:#fff;';
            closeBtn.innerHTML = '&times;';
            closeBtn.addEventListener('click', ev => {
              ev.preventDefault();
              // call the parent's closeSearch()
              if (window.parent && typeof window.parent.closeSearch === 'function') {
                window.parent.closeSearch();
              } else {
                window.top.postMessage({
                  action: 'closeSearch'
                }, '*');
              }
            });
            right.appendChild(closeBtn);
          } else {
            closeBtn.style.display = 'inline-block';
          }
        }
        // Add Back & Filter controls on the left
        const ctrls = ensureControls(doc);

        const refresh = () => {
          const showBack = hasQuery(doc);
          const showFilter = showBack && hasResults(doc);
          if (ctrls.back) ctrls.back.style.display = showBack ? 'inline-block' : 'none';
          if (ctrls.filter) ctrls.filter.style.display = showFilter ? 'inline-block' : 'none';
        };

        // Initial state
        refresh();

        // Update on DOM changes (e.g., results loaded)
        const mo = new MutationObserver(() => refresh());
        mo.observe(doc.body, {
          subtree: true,
          childList: true,
          attributes: true
        });

        // Update on in-iframe navigation
        doc.defaultView.addEventListener('popstate', refresh);
      } catch (err) {
        console.warn('Search header tune error:', err);
      }
    }
    window.addEventListener('message', (e) => {
      if (e.data && e.data.action === 'closeSearch') {
        closeSearch();
      }
    });

    // Run on first load + any subsequent reloads of search.php
    searchFrame?.addEventListener('load', tuneSearchHeader);

    // Global ESC
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') {
        if (body.classList.contains('drawer-open')) closeDrawer();
        if (body.classList.contains('search-open')) closeSearch();
      }
    });
  })();
</script>