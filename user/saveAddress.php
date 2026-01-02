<?php
session_start();
require __DIR__ . '/../config.php';

// Always return JSON to the AJAX caller
header('Content-Type: application/json');

/**
 * Haversine distance (km) between two lat/lng points
 */
function haversineDistanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadius = 6371.0; // km

    $lat1Rad = deg2rad($lat1);
    $lon1Rad = deg2rad($lon1);
    $lat2Rad = deg2rad($lat2);
    $lon2Rad = deg2rad($lon2);

    $dLat = $lat2Rad - $lat1Rad;
    $dLon = $lon2Rad - $lon1Rad;

    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos($lat1Rad) * cos($lat2Rad)
       * sin($dLon / 2) * sin($dLon / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

// ✅ Set your shop’s fixed location here (example: KL center)
const SHOP_LAT = 3.144142;  
const SHOP_LON = 101.713069; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Retrieve form data (trimmed)
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

    // Retrieve user ID from session (this should be set during login)
    $user_id = $_SESSION['user']['UserID'] ?? null;

    if (!$user_id) {
        echo json_encode(['status' => 'error', 'message' => 'User is not logged in.']);
        exit;
    }

    // Backend validation
    $errors = [];

    if ($name === '') {
        $errors[] = 'Name is required.';
    }

    // ✅ E.164 validation: e.g. +60123456789 (up to 15 digits total)
    if (!preg_match('/^\+[1-9]\d{1,14}$/', $phoneNumber)) {
        $errors[] = 'Phone number must be in E.164 format, e.g. +60123456789.';
    }

    if ($fullAddress === '') {
        $errors[] = 'Full address is required.';
    }

    if ($city === '') {
        $errors[] = 'City is required.';
    }

    if ($state === '') {
        $errors[] = 'State is required.';
    }

    if ($postalCode === '') {
        $errors[] = 'Postal code is required.';
    }

    // Latitude & longitude are strongly recommended for distance
    if ($latitude === '' || $longitude === '') {
        $errors[] = 'Please select the location on the map so that latitude and longitude are captured.';
    }

    // If there are validation errors, return the errors
    if (!empty($errors)) {
        echo json_encode([
            'status'  => 'error',
            'message' => implode(' ', $errors)
        ]);
        exit;
    }

    // ---- Calculate distance from shop to this address (in km) ----
    $distanceKm = null;
    try {
        $lat = (float)$latitude;
        $lon = (float)$longitude;

        // Basic sanity check; you can enhance this if you like
        if ($lat !== 0.0 || $lon !== 0.0) {
            $distanceKm = haversineDistanceKm(SHOP_LAT, SHOP_LON, $lat, $lon);
        }
    } catch (Throwable $e) {
        // If conversion fails, keep $distanceKm as null
        $distanceKm = null;
    }

    // Insert the new address into the database
    try {
        // ⚠️ Ensure your user_address table has the DistanceKm column
        $sql = "
            INSERT INTO user_address 
                (UserID, Name, PhoneNumber, Label, HomeUnit, FullAddress, City, State, PostalCode, Latitude, Longitude, DistanceKm, IsDefault)
            VALUES 
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $user_id,
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
            $distanceKm,
            $isDefault
        ]);

        echo json_encode([
            'status'  => 'success',
            'message' => 'Address saved successfully.'
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Invalid request method.'
    ]);
}
