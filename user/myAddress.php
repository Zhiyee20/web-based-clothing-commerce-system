<?php
// user/my_addresses.php
require __DIR__ . '/../config.php';
include '../login_base.php';

// session already started in login_base.php
$userID = $_SESSION['user']['UserID'] ?? null;
if (!$userID) {
  header('Location: login.php');
  exit;
}

// Fetch addresses, default first
$stmt = $_db->prepare("
    SELECT * 
      FROM user_address 
     WHERE UserID = ? 
     ORDER BY IsDefault DESC, AddressID
");
$stmt->execute([$userID]);
$addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'header.php';
?>

<style>
  body {
    font-family: Arial, sans-serif;
    background: #f5f5f5;
    margin: 0;
    padding: 0;
  }

  /* Container under header */
  .container {
    max-width: 1000px;
    margin: 30px auto;
    padding: 0 20px;
  }

  /* Heading + button */
  .page-header {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 30px;
  }

  .page-header h2 {
    margin: 0;
    font-size: 2em;
    color: #333;
  }

  .page-header .add-btn {
    background: #007bff;
    color: #fff;
    border: none;
    padding: 10px 18px;
    border-radius: 4px;
    font-size: 1em;
    cursor: pointer;
    transition: background .2s;
  }

  .page-header .add-btn:hover {
    background: #0069d9;
  }

  /* Empty state */
  .empty-state {
    text-align: left;
    color: #666;
    font-size: 1em;
  }

  /* Grid of address cards */
  .address-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
  }

  .address-card {
    position: relative;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
    padding: 20px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-height: 200px;
  }

  .default-badge {
    position: absolute;
    top: 16px;
    right: 16px;
    background: #28a745;
    color: #fff;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: .75em;
  }

  .addr-body h3 {
    margin: 0 0 8px;
    font-size: 1.1em;
    color: #222;
  }

  .addr-body p {
    margin: 4px 0;
    font-size: .95em;
    color: #555;
  }

  .addr-body .phone {
    margin-top: 10px;
    font-weight: bold;
    color: #333;
  }

  .addr-actions {
    margin-top: 15px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
  }

  .addr-actions .btn {
    flex: 1;
    border: none;
    padding: 8px;
    border-radius: 4px;
    font-size: .9em;
    cursor: pointer;
    transition: background .2s;
    color: #fff;
  }

  .edit-btn {
    background: #007bff;
  }

  .edit-btn:hover {
    background: #0069d9;
  }

  .delete-btn {
    background: #dc3545;
  }

  .delete-btn:hover {
    background: #c82333;
  }

  .default-btn {
    background: #ffc107;
    color: #222;
  }

  .default-btn:hover {
    background: #e0a800;
  }

  .default-btn[disabled] {
    opacity: 0.6;
    cursor: default;
  }

  /* Responsive tweaks */
  @media (max-width: 600px) {
    .page-header {
      align-items: stretch;
    }

    .addr-actions {
      flex-direction: column;
    }
  }
</style>

<main class="container-wrapper">
  <div class="detail-container">
    <div class="container">
      <div class="page-header">
        <h2>My Addresses</h2>
        <button class="add-btn" onclick="location.href='add_address.php'">
          + Add New Address
        </button>
      </div>

      <?php if (empty($addresses)): ?>
        <div class="empty-state">
          <p>You haven’t added any addresses yet.</p>
          <p>Click “Add New Address” above to get started.</p>
        </div>
      <?php else: ?>
        <div class="address-grid">
          <?php foreach ($addresses as $addr): ?>
            <div class="address-card" id="address-<?= (int)$addr['AddressID'] ?>">
              <?php if (!empty($addr['IsDefault'])): ?>
                <div class="default-badge">Default</div>
              <?php endif; ?>

              <div class="addr-body">
                <h3><?= htmlspecialchars($addr['Label']) ?></h3>
                <p><?= nl2br(htmlspecialchars($addr['FullAddress'])) ?></p>
                <p class="phone">📞 <?= htmlspecialchars($addr['PhoneNumber']) ?></p>
              </div>

              <div class="addr-actions">
                <button class="btn edit-btn" data-id="<?= (int)$addr['AddressID'] ?>">
                  Edit
                </button>
                <button class="btn delete-btn" data-id="<?= (int)$addr['AddressID'] ?>">
                  Delete
                </button>
                <?php if (empty($addr['IsDefault'])): ?>
                  <button class="btn default-btn" data-id="<?= (int)$addr['AddressID'] ?>">
                    Set Default
                  </button>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
  $(function() {
    // Edit
    $('.edit-btn').click(function() {
      window.location = 'edit_address.php?id=' + $(this).data('id');
    });

    // Delete (uses JSON response)
    $('.delete-btn').click(function() {
      var id = $(this).data('id');
      if (!confirm('Delete this address?')) return;

      $.post(
        'delete_address.php',
        { AddressID: id },
        function(res) {
          // Expect JSON: { status: 'success'|'error', message: '...' }
          if (res && res.status === 'success') {
            $('#address-' + id).fadeOut(200, function() {
              $(this).remove();
            });
          } else {
            alert('Failed to delete address: ' + (res && res.message ? res.message : 'Unknown error'));
          }
        },
        'json'
      ).fail(function() {
        alert('Request failed. Please try again.');
      });
    });

    // Set default (already expecting JSON)
    $('.default-btn').click(function() {
      var btn = $(this),
          id  = btn.data('id');

      $.post(
        'set_default.php',
        { AddressID: id },
        function(res) {
          if (res.status === 'success') {
            location.reload();
          } else {
            alert('Error: ' + (res.message || 'Unable to set default'));
          }
        },
        'json'
      ).fail(function() {
        alert('Request failed. Please try again.');
      });
    });
  });
</script>

<?php include 'footer.php'; ?>
