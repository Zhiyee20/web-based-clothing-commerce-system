<?php
/*  add_address.php – Add new address with auto-prefilled name & phone  */

session_start();
require_once '../login_base.php'; // gives $_db (PDO) & keeps session

/* ─── Pull default values from the user table ───────────────────────── */
$defaultName  = '';
$defaultPhone = '';

if (isset($_SESSION['user']['UserID'])) {
    $uid = $_SESSION['user']['UserID'];
    $q   = $_db->prepare("
        SELECT Username, phone_number
        FROM user
        WHERE UserID = ?
    ");
    $q->execute([$uid]);
    if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
        $defaultName  = $row['Username']     ?? '';
        $defaultPhone = $row['phone_number'] ?? '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Add New Address</title>

    <!-- HERE Maps -->
    <script src="https://js.api.here.com/v3/3.1/mapsjs-core.js"></script>
    <script src="https://js.api.here.com/v3/3.1/mapsjs-service.js"></script>
    <script src="https://js.api.here.com/v3/3.1/mapsjs-ui.js"></script>
    <script src="https://js.api.here.com/v3/3.1/mapsjs-mapevents.js"></script>
    <link rel="stylesheet" href="https://js.api.here.com/v3/3.1/mapsjs-ui.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <style>
        .address-form-container {
            max-width: 600px;
            margin: 20px auto;
            padding: 20px;
            border: 1px solid #ccc;
            border-radius: 5px;
            background: #f9f9f9;
            font-family: Arial, sans-serif;
        }

        #map {
            width: 100%;
            height: 300px;
            margin-top: 10px;
            border-radius: 5px;
        }

        label {
            display: block;
            margin-top: 10px;
            font-weight: 600;
        }

        input[type=text] {
            width: 100%;
            padding: 8px;
            margin-top: 5px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }

        input[type=checkbox] {
            margin-top: 5px;
            transform: scale(1.2);
            width: auto;
        }

        button {
            margin-top: 10px;
            padding: 10px 15px;
            background: #007bff;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        button:hover {
            background: #0056b3;
        }

        #addAddressForm {
            margin-top: 20px;
        }

        #fullAddress {
            margin-top: 8px;
            font-size: 0.95rem;
            color: #333;
        }
    </style>
</head>

<body>
    <div class="address-form-container">
        <h2>Add New Address</h2>

        <label for="searchBox">Enter Location:</label>
        <input type="text" id="searchBox" placeholder="Search location…" autocomplete="off">
        <button id="searchButton" type="button">Search</button>

        <div id="fullAddress"></div>
        <div id="map"></div>
        <button id="confirmButton" type="button">Confirm</button>

        <!-- ――― form (hidden until Confirm) ――― -->
        <form id="addAddressForm" action="saveAddress.php" method="POST" style="display:none">

            <label for="name">Name:</label>
            <input type="text" id="name" name="Name"
                value="<?= htmlspecialchars($defaultName, ENT_QUOTES, 'UTF-8') ?>" required>

            <label for="phoneNumber">Phone Number:</label>
            <input type="text" id="phoneNumber" name="PhoneNumber"
                value="<?= htmlspecialchars($defaultPhone, ENT_QUOTES, 'UTF-8') ?>" required>

            <label for="label">Address Type (Optional):</label>
            <input type="text" id="label" name="Label" placeholder="Home, Office, etc.">

            <label for="home_unit">Home Unit (Optional):</label>
            <input type="text" id="home_unit" name="HomeUnit" placeholder="No 1 or Block unit">

            <label for="autocomplete">Full Address:</label>
            <input type="text" id="autocomplete" name="FullAddress" readonly>

            <label for="city">City:</label>
            <input type="text" id="city" name="City">

            <label for="state">State:</label>
            <input type="text" id="state" name="State">

            <label for="postalCode">Postal Code:</label>
            <input type="text" id="postalCode" name="PostalCode">

            <input type="hidden" id="latitude" name="Latitude">
            <input type="hidden" id="longitude" name="Longitude">

            <label for="isDefault">Set as Default:</label>
            <input type="checkbox" id="isDefault" name="IsDefault" value="1">

            <button type="submit">Save Address</button>
        </form>
    </div>

    <!-- Success popup -->
    <div id="successPopupBackdrop" style="
    display:none;
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.4);
    z-index:999;
">
        <div id="successPopup" style="
      position:absolute;
      top:50%;
      left:50%;
      transform:translate(-50%,-50%);
      background:#fff;
      border-radius:8px;
      padding:20px 24px;
      box-shadow:0 4px 12px rgba(0,0,0,0.15);
      max-width:320px;
      text-align:center;
      font-family:Arial,sans-serif;
  ">
            <h3 style="margin-top:0;margin-bottom:10px;color:#16a34a;">Address Added</h3>
            <p style="margin:0 0 16px;">Your address has been saved successfully.</p>
            <button id="successPopupOk" style="
          padding:8px 16px;
          border:none;
          border-radius:4px;
          background:#007bff;
          color:#fff;
          cursor:pointer;
          font-size:0.95rem;
      ">
                OK
            </button>
        </div>
    </div>

    <script>
        const hereApiKey = 'QE7WGIuEVTkarepTEKFoAjGAvPzlxn-1WtcpvkxEf8g'; // your HERE API key

        // ====== HERE Platform & Map (only for display) ======
        let platform = new H.service.Platform({
            apikey: hereApiKey
        });
        let defaultLayers = platform.createDefaultLayers();

        let map = new H.Map(
            document.getElementById('map'),
            defaultLayers.vector.normal.map, {
                center: {
                    lat: 3.1390,
                    lng: 101.6869
                }, // KL
                zoom: 12,
                pixelRatio: window.devicePixelRatio || 1
            }
        );

        window.addEventListener('resize', () => map.getViewPort().resize());

        let mapEvents = new H.mapevents.MapEvents(map);
        new H.mapevents.Behavior(mapEvents);
        let ui = H.ui.UI.createDefault(map, defaultLayers);

        let marker = null;

        /* ──────────────── Helper: update form from address ──────────────── */
        function updateAddress(lat, lng, addr) {
            if (marker) map.removeObject(marker);
            marker = new H.map.Marker({
                lat,
                lng
            });
            map.addObject(marker);
            map.setCenter({
                lat,
                lng
            });

            let full =
                addr.label || [
                    addr.houseNumber || '',
                    addr.street || '',
                    addr.district || '',
                    addr.city || '',
                    addr.state || '',
                    addr.postalCode || '',
                    addr.countryName || ''
                ].filter(Boolean).join(', ');

            $('#autocomplete').val(full);
            $('#fullAddress').text(full);

            $('#latitude').val(lat);
            $('#longitude').val(lng);

            $('#city').val(addr.city || '');
            $('#state').val(addr.state || '');
            $('#postalCode').val(addr.postalCode || '');
        }

        /* ──────────────── v7: forward geocode by text ──────────────── */
        function geocodeText(q) {
            const url = `https://geocode.search.hereapi.com/v1/geocode` +
                `?q=${encodeURIComponent(q)}` +
                `&apikey=${hereApiKey}`;

            return fetch(url)
                .then(res => {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                });
        }

        /* ──────────────── v7: reverse geocode by lat/lng ──────────────── */
        function reverseGeocode(lat, lng) {
            const url = `https://revgeocode.search.hereapi.com/v1/revgeocode` +
                `?at=${lat},${lng}` +
                `&apikey=${hereApiKey}`;

            return fetch(url)
                .then(res => {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                });
        }

        /* ──────────────── Search button ──────────────── */
        $('#searchButton').on('click', function() {
            const q = $('#searchBox').val().trim();
            if (!q) {
                alert('Enter a location');
                return;
            }

            geocodeText(q)
                .then(data => {
                    if (!data.items || !data.items.length) {
                        alert('No results found.');
                        return;
                    }
                    const item = data.items[0];
                    const pos = item.position;
                    const addr = item.address;

                    updateAddress(pos.lat, pos.lng, addr);
                })
                .catch(err => {
                    console.error('Geocoding v7 error:', err);
                    alert('Geocoding failed. You can still fill the address manually.');
                    // Fallback: let user type address manually
                    const typed = $('#searchBox').val().trim();
                    if (typed) {
                        $('#autocomplete').val(typed);
                        $('#fullAddress').text(typed);
                    }
                    $('#addAddressForm').fadeIn();
                });
        });

        /* ──────────────── Tap on map → reverse geocode (v7) ──────────────── */
        map.addEventListener('tap', function(e) {
            const p = map.screenToGeo(
                e.currentPointer.viewportX,
                e.currentPointer.viewportY
            );

            reverseGeocode(p.lat, p.lng)
                .then(data => {
                    if (!data.items || !data.items.length) {
                        console.warn('No address found for this location');
                        return;
                    }
                    const item = data.items[0];
                    updateAddress(item.position.lat, item.position.lng, item.address);
                })
                .catch(err => {
                    console.error('Reverse geocode v7 error:', err);
                });
        });

        /* ──────────────── Confirm button ──────────────── */
        $('#confirmButton').on('click', () => {
            if (!$('#autocomplete').val()) {
                const typed = $('#searchBox').val().trim();
                if (!typed) {
                    alert('Please enter or select an address first.');
                    return;
                }
                $('#autocomplete').val(typed);
                $('#fullAddress').text(typed);
            }
            $('#addAddressForm').fadeIn();
        });

        /* ──────────────── Form submit ──────────────── */
        $('#addAddressForm').on('submit', function(e) {
            e.preventDefault();

            const phone = $('#phoneNumber').val().trim();

            // E.164 validation: e.g. +60123456789
            if (!/^\+[1-9]\d{1,14}$/.test(phone)) {
                alert('Phone number must be in E.164 format, e.g. +60123456789');
                return;
            }

            if (!$('#city').val().trim() ||
                !$('#state').val().trim() ||
                !$('#postalCode').val().trim()) {
                alert('Fill in city, state and postal code');
                return;
            }

            $.post(
                'saveAddress.php', // keep your filename as is
                $(this).serialize(),
                function(resp) {
                    if (resp && resp.status === 'success') {
                        // Show success popup
                        $('#successPopupBackdrop').fadeIn(200);

                        const redirectTimer = setTimeout(function() {
                            window.location.href = 'myAddress.php';
                        }, 1500);

                        $('#successPopupOk').one('click', function() {
                            clearTimeout(redirectTimer);
                            window.location.href = 'myAddress.php';
                        });

                    } else {
                        alert('Error: ' + (resp && resp.message ? resp.message : 'Unknown'));
                    }
                },
                'json'
            ).fail(function(x) {
                alert('Server error');
                console.error(x.responseText);
            });
        });
    </script>
</body>

</html>