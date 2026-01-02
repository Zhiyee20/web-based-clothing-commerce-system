<?php
date_default_timezone_set('Asia/Kuala_Lumpur');

// =======================
// Database Configuration
// =======================
$host = 'localhost';
$dbname = 'webassignment';
$username = 'root';
$password = ''; // local dev only

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed.");
}

