<?php
// confirm_address.php

require __DIR__ . '/../config.php';

session_start();
$userID = $_SESSION['user']['UserID'] ?? null;
if (!$userID) {
    header('Location: login.php');
    exit;
}

// handle address selection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['addressID'])) {
    $_SESSION['checkout_addressID'] = (int)$_POST['addressID'];
    header('Location: checkout.php');
    exit;
}

// fetch all addresses for this user
$stmt = $pdo->prepare("
  SELECT AddressID, Label, FullAddress, PhoneNumber, IsDefault
    FROM user_address
   WHERE UserID = ?
   ORDER BY IsDefault DESC, AddressID
");
$stmt->execute([$userID]);
$addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// if no addresses, send them to add one now, then come back
if (count($addresses) === 0) {
    header('Location: add_address.php?return_to=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

include 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Select Shipping Address</title>
  <style>
    .container {
      max-width:800px;
      margin:40px auto;
      padding:0 15px;
      font-family:Arial, sans-serif;
    }
    h2 {
      text-align:center;
      margin-bottom:24px;
      font-size:1.8em;
    }
    .address-card {
      background:#fff;
      border-radius:8px;
      box-shadow:0 2px 6px rgba(0,0,0,0.1);
      margin-bottom:16px;
      padding:16px;
      display:flex;
      justify-content:space-between;
      align-items:center;
    }
    .address-details {
      flex:1;
      line-height:1.4;
    }
    .address-actions {
      margin-left:16px;
    }
    .btn {
      padding:10px 20px;
      border:none;
      border-radius:4px;
      cursor:pointer;
      font-size:1em;
    }
    .btn-primary {
      background:#007bff;
      color:#fff;
      transition:background .2s;
    }
    .btn-primary:hover {
      background:#0056b3;
    }
    input[type=radio] {
      transform:scale(1.2);
      margin-right:8px;
    }
  </style>
</head>
<body>
<main class="container-wrapper">
<div class="detail-container">
  <div class="container">
    <h2>Select Your Shipping Address</h2>
    <form method="post">
      <?php foreach ($addresses as $a): ?>
        <div class="address-card">
          <div class="address-details">
            <strong><?= htmlspecialchars($a['Label'],ENT_QUOTES,'UTF-8') ?></strong>
            <?php if ($a['IsDefault']): ?>
              <span style="color:green;font-weight:bold;margin-left:8px;">(Default)</span>
            <?php endif; ?>
            <p><?= nl2br(htmlspecialchars($a['FullAddress'],ENT_QUOTES,'UTF-8')) ?></p>
            <p>📞 <?= htmlspecialchars($a['PhoneNumber'],ENT_QUOTES,'UTF-8') ?></p>
          </div>
          <div class="address-actions">
            <label>
              <input type="radio"
                     name="addressID"
                     value="<?= $a['AddressID'] ?>"
                     <?= $a['IsDefault'] ? 'checked' : '' ?>>
              Ship Here
            </label>
          </div>
        </div>
      <?php endforeach; ?>

      <button type="submit" class="btn btn-primary">
        Continue to Payment
      </button>
    </form>
  </div>
  </div>
</main>
<?php include 'footer.php'; ?>
</body>
</html>
