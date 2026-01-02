<?php
// Start session only if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get the current page filename
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Panel</title>
  <link rel="stylesheet" href="/assets/admin_style.css">
  <style>
    /* === Highlight style === */
    .admin-header nav a.active {
      border: 1px solid #fff;
      border-radius: 6px;
      padding: 10px;
    }
  </style>
</head>
<body>

<header class="admin-header">
  <h1>Admin Panel</h1>
  <nav>
    <ul>
      <li><a href="/index.php" class="<?= $currentPage === 'index.php' ? 'active' : '' ?>">Home</a></li>
      <li><a href="dashboard.php" class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">Dashboard</a></li>
      <li class="dropdown">
        <?php
          // Any page under "Manage" group
          $managePages = ['admin_product.php','admin_category.php','admin_orders.php','admin_users.php','admin_promo.php'];
          $isManageActive = in_array($currentPage, $managePages);
        ?>
        <a href="#" class="<?= $isManageActive ? 'active' : '' ?>">Manage</a>
        <div class="dropdown-content">
          <a href="admin_product.php" class="<?= $currentPage === 'admin_product.php' ? 'active' : '' ?>">Manage Products</a>
          <a href="admin_stock.php" class="<?= $currentPage === 'admin_stock.php' ? 'active' : '' ?>">Manage Stocks</a>
          <a href="admin_category.php" class="<?= $currentPage === 'admin_category.php' ? 'active' : '' ?>">Manage Categories</a>
          <a href="admin_orders.php" class="<?= $currentPage === 'admin_orders.php' ? 'active' : '' ?>">Manage Orders</a>
          <a href="admin_users.php" class="<?= $currentPage === 'admin_users.php' ? 'active' : '' ?>">Manage Users</a>
          <a href="admin_promo.php" class="<?= $currentPage === 'admin_promo.php' ? 'active' : '' ?>">Manage Promotions</a>
        </div>
      </li>
      <li><a href="cust_service.php" class="<?= $currentPage === 'cust_service.php' ? 'active' : '' ?>">Customer Service</a></li>
    </ul>
  </nav>
</header>

<main>
