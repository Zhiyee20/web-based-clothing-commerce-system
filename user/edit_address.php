<?php
// user/edit_address.php

require_once '../login_base.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Ensure user is logged in
$userID = $_SESSION['user']['UserID'] ?? null;
if (!$userID) {
    header('Location: /login.php');
    exit;
}

// Validate ID from query
if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    die("Invalid request.");
}
$id = (int) $_GET['id']; // AddressID

$errors = [];

// Fetch existing address and ensure it belongs to this user
$stmt = $_db->prepare("SELECT * FROM user_address WHERE AddressID = ? AND UserID = ?");
$stmt->execute([$id, $userID]);
$address = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$address) {
    die("Address not found.");
}

// Handle form submit (no HTML output yet)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize & validate inputs
    $name        = trim($_POST['Name']        ?? '');
    $phoneNumber = trim($_POST['PhoneNumber'] ?? '');
    $label       = trim($_POST['Label']       ?? '');
    $home_unit   = trim($_POST['HomeUnit']    ?? '');
    $fullAddress = trim($_POST['FullAddress'] ?? '');
    $city        = trim($_POST['City']        ?? '');
    $state       = trim($_POST['State']       ?? '');
    $postalCode  = trim($_POST['PostalCode']  ?? '');
    $latitude    = trim($_POST['Latitude']    ?? '');
    $longitude   = trim($_POST['Longitude']   ?? '');
    $isDefault   = isset($_POST['IsDefault']) ? 1 : 0;

    // Required field checks
    if ($name === '') {
        $errors[] = "Name is required.";
    }
    if ($phoneNumber === '') {
        $errors[] = "Phone number is required.";
    }
    if ($fullAddress === '') {
        $errors[] = "Full address is required.";
    }

    // Phone number validation (must start with 6 and be 10–11 digits)
    if ($phoneNumber !== '' && !preg_match('/^6\d{9,10}$/', $phoneNumber)) {
        $errors[] = "Phone number must start with 6 and be 10–11 digits total.";
    }

    // Postal code validation (numeric only)
    if ($postalCode !== '' && !ctype_digit($postalCode)) {
        $errors[] = "Postal code must be numeric.";
    }

    // Validate latitude and longitude
    if ($latitude === '' || $longitude === '' ||
        !filter_var($latitude, FILTER_VALIDATE_FLOAT) ||
        !filter_var($longitude, FILTER_VALIDATE_FLOAT)
    ) {
        $errors[] = "Invalid latitude or longitude.";
    }

    // ✅ No duplicate checks for Name or PhoneNumber anymore

    // If no errors, update address (with default logic)
    if (empty($errors)) {
        try {
            $_db->beginTransaction();

            // If this address should be default, clear default on all others of this user
            if ($isDefault === 1) {
                $clear = $_db->prepare("UPDATE user_address SET IsDefault = 0 WHERE UserID = ?");
                $clear->execute([$userID]);
            }

            // Now update this address (including IsDefault flag)
            $stmt = $_db->prepare("
                UPDATE user_address SET 
                    Name        = ?, 
                    PhoneNumber = ?, 
                    Label       = ?, 
                    HomeUnit    = ?,
                    FullAddress = ?, 
                    City        = ?, 
                    State       = ?, 
                    PostalCode  = ?, 
                    Latitude    = ?, 
                    Longitude   = ?,
                    IsDefault   = ?
                WHERE AddressID = ? 
                  AND UserID    = ?
            ");
            $stmt->execute([
                $name,
                $phoneNumber,
                $label,
                $home_unit,
                $fullAddress,
                $city,
                $state,
                $postalCode,
                $latitude,
                $longitude,
                $isDefault,
                $id,
                $userID
            ]);

            $_db->commit();

            // Redirect back to address list BEFORE any HTML
            header('Location: /user/myAddress.php'); // change if your list page is named differently
            exit();
        } catch (PDOException $e) {
            $_db->rollBack();
            if (strpos($e->getMessage(), '1062 Duplicate entry') !== false) {
                // There is still some DB unique constraint (maybe on another column)
                $errors[] = "Duplicate data detected in the database. Please review your input.";
            } else {
                $errors[] = "Error updating the address: " . $e->getMessage();
            }
        }
    }

    // If there were errors, also update $address array so form re-shows entered values
    $address['Name']        = $name;
    $address['PhoneNumber'] = $phoneNumber;
    $address['Label']       = $label;
    $address['HomeUnit']    = $home_unit;
    $address['FullAddress'] = $fullAddress;
    $address['City']        = $city;
    $address['State']       = $state;
    $address['PostalCode']  = $postalCode;
    $address['Latitude']    = $latitude;
    $address['Longitude']   = $longitude;
    $address['IsDefault']   = $isDefault;
}

// Decide whether checkbox should be checked (form display)
$isDefaultChecked = !empty($address['IsDefault']);

// NOW include the common header (starts HTML)
include 'header.php';
?>

<style>
  .account-section { padding: 40px 0; }
  .page-head { display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; }
  .page-head h1 { margin:0; font-size:24px; line-height:1.2; display:flex; align-items:center; gap:10px; }

  .card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
  .card-header { padding:14px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between; }
  .card-title { margin:0; font-size:18px; }
  .card-body { padding:16px; }

  .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  @media (max-width: 860px) { .form-grid { grid-template-columns:1fr; } }

  .input-group label { display:block; font-size:.9rem; margin-bottom:6px; color:#111827; }
  .input-group input[type="text"],
  .input-group input[type="tel"] {
    width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:10px; font-size:.95rem;
    outline:none; transition:border-color .15s ease, box-shadow .15s ease;
  }
  .input-group input:focus { border-color:#000; box-shadow:0 0 0 3px rgba(0,0,0,.06); }

  .help { font-size:.8rem; color:#6b7280; margin-top:4px; }

  .actions { display:flex; gap:10px; justify-content:flex-end; margin-top:16px; }
  .btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:10px 14px; border-radius:999px; border:1px solid #111827; font-size:.95rem; cursor:pointer; text-decoration:none; }
  .btn:hover { opacity:.9; }
  .btn-secondary { background:#fff; color:#111827; }
  .btn-primary { background:#111827; color:#fff; }

  .error-box { padding:12px 14px; border:1px solid #fecaca; background:#fff1f2; color:#991b1b; border-radius:10px; margin-bottom:12px; }

  .map-wrap { margin-top:16px; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; }
</style>

<main class="account-section">
  <div class="container" style="max-width:980px;margin:0 auto;">
    <div class="page-head">
      <h1>
        <!-- pin icon -->
        <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7zm0 9.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5z"/>
        </svg>
        Edit Address
      </h1>
      <div>
        <a class="btn btn-secondary" href="/user/myAddress.php">Back to My Addresses</a>
      </div>
    </div>

    <div class="card" style="margin-top:16px;">
      <div class="card-header">
        <h3 class="card-title">Address Information</h3>
      </div>
      <div class="card-body">
        <?php if (!empty($errors)): ?>
          <div class="error-box">
            <?php foreach ($errors as $error): ?>
              <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="POST" novalidate>
          <input type="hidden" name="id" value="<?= htmlspecialchars($address['AddressID']) ?>">

          <div class="form-grid">
            <div class="input-group">
              <label>Name</label>
              <input type="text" name="Name" value="<?= htmlspecialchars($address['Name'] ?? '') ?>" required>
            </div>

            <div class="input-group">
              <label>Phone Number</label>
              <input type="tel" name="PhoneNumber" value="<?= htmlspecialchars($address['PhoneNumber'] ?? '') ?>" required>
              <div class="help">Must start with 6 and be 10–11 digits total</div>
            </div>

            <div class="input-group">
              <label>Address Type (Optional)</label>
              <input type="text" name="Label" value="<?= htmlspecialchars($address['Label'] ?? '') ?>">
            </div>

            <div class="input-group">
              <label>Home Unit (Optional)</label>
              <input type="text" name="HomeUnit" value="<?= htmlspecialchars($address['HomeUnit'] ?? '') ?>">
            </div>

            <div class="input-group" style="grid-column:1/-1;">
              <label>Full Address</label>
              <input type="text" id="autocomplete" name="FullAddress" value="<?= htmlspecialchars($address['FullAddress'] ?? '') ?>" required>
            </div>

            <div class="input-group">
              <label>City</label>
              <input type="text" id="city" name="City" value="<?= htmlspecialchars($address['City'] ?? '') ?>">
            </div>

            <div class="input-group">
              <label>State</label>
              <input type="text" id="state" name="State" value="<?= htmlspecialchars($address['State'] ?? '') ?>">
            </div>

            <div class="input-group">
              <label>Postal Code</label>
              <input type="text" id="postalCode" name="PostalCode" value="<?= htmlspecialchars($address['PostalCode'] ?? '') ?>">
            </div>

            <div class="input-group" style="display:flex;align-items:center;gap:10px;">
              <label style="margin:0;">
                <input type="checkbox" name="IsDefault" value="1" <?= $isDefaultChecked ? 'checked' : '' ?>>
                Set as default address
              </label>
            </div>
          </div>

          <input type="hidden" id="latitude" name="Latitude" value="<?= htmlspecialchars($address['Latitude'] ?? '') ?>">
          <input type="hidden" id="longitude" name="Longitude" value="<?= htmlspecialchars($address['Longitude'] ?? '') ?>">

          <div class="actions">
            <a class="btn btn-secondary" href="/user/myAddress.php">Cancel</a>
            <button type="submit" class="btn btn-primary">Update Address</button>
          </div>
        </form>

        <div class="map-wrap">
          <div id="map" style="width: 100%; height: 320px;"></div>
        </div>
      </div>
    </div>
  </div>
</main>

<!-- HERE Maps & jQuery -->
<link rel="stylesheet" href="https://js.api.here.com/v3/3.1/mapsjs-ui.css" />
<script src="https://js.api.here.com/v3/3.1/mapsjs-core.js"></script>
<script src="https://js.api.here.com/v3/3.1/mapsjs-service.js"></script>
<script src="https://js.api.here.com/v3/3.1/mapsjs-ui.js"></script>
<script src="https://js.api.here.com/v3/3.1/mapsjs-mapevents.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
const hereApiKey = 'XOD5TEhzV0-7XUh50f2-wLLjAVHwCJ8ZcVG61rVHTUI';

// Fallback center if no coords
const initialLat = <?= $address['Latitude']  !== '' ? (float)$address['Latitude']  : 3.1390 ?>;
const initialLng = <?= $address['Longitude'] !== '' ? (float)$address['Longitude'] : 101.6869 ?>;

const platform = new H.service.Platform({ apikey: hereApiKey });
const defaultLayers = platform.createDefaultLayers();
const map = new H.Map(
    document.getElementById('map'),
    defaultLayers.vector.normal.map,
    {
        center: { lat: initialLat, lng: initialLng },
        zoom: 15,
        pixelRatio: window.devicePixelRatio || 1
    }
);
window.addEventListener('resize', () => map.getViewPort().resize());
const behavior = new H.mapevents.Behavior(new H.mapevents.MapEvents(map));
const ui = H.ui.UI.createDefault(map, defaultLayers);

let marker = new H.map.Marker(
    { lat: initialLat, lng: initialLng },
    { volatility: true }
);
marker.draggable = true;
map.addObject(marker);

// Drag handlers
map.addEventListener('dragstart', function (ev) {
    if (ev.target instanceof H.map.Marker) behavior.disable();
}, false);
map.addEventListener('dragend', function (ev) {
    behavior.enable();
    let coord = ev.target.getGeometry();
    updateAddressFromLatLng(coord.lat, coord.lng);
}, false);
map.addEventListener('drag', function (ev) {
    let pointer = ev.currentPointer;
    if (ev.target instanceof H.map.Marker) {
        ev.target.setGeometry(map.screenToGeo(pointer.viewportX, pointer.viewportY));
    }
}, false);

map.addEventListener('dbltap', function (evt) {
    let coord = map.screenToGeo(evt.currentPointer.viewportX, evt.currentPointer.viewportY);
    marker.setGeometry(coord);
    updateAddressFromLatLng(coord.lat, coord.lng);
});

function updateAddressFromLatLng(lat, lng) {
    $('#latitude').val(lat);
    $('#longitude').val(lng);

    $.getJSON(`https://revgeocode.search.hereapi.com/v1/revgeocode?at=${lat},${lng}&apikey=${hereApiKey}`, function (data) {
        if (data.items && data.items.length > 0) {
            let addr = data.items[0].address;
            $('#autocomplete').val(addr.label || '');
            $('#city').val(addr.city || '');
            $('#state').val(addr.state || '');
            $('#postalCode').val(addr.postalCode || '');
        }
    });
}

$('#autocomplete').on('change', function () {
    let address = $(this).val();
    if (address.length > 5) {
        let center = map.getCenter();
        let at = `${center.lat},${center.lng}`;
        $.getJSON(`https://geocode.search.hereapi.com/v1/geocode?q=${encodeURIComponent(address)}&at=${at}&apikey=${hereApiKey}`, function (data) {
            if (data.items && data.items.length > 0) {
                const loc = data.items[0];
                marker.setGeometry(loc.position);
                map.setCenter(loc.position);
                $('#latitude').val(loc.position.lat);
                $('#longitude').val(loc.position.lng);
                $('#city').val(loc.address.city || '');
                $('#state').val(loc.address.state || '');
                $('#postalCode').val(loc.address.postalCode || '');
            } else {
                alert("Address not found. Try a more specific one.");
            }
        }).fail(() => {
            alert("Error fetching address.");
        });
    }
});
</script>

<?php include 'footer.php'; ?>
