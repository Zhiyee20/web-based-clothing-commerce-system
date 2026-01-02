<?php
require __DIR__ . '/../config.php';


$stmt = $conn->query("SELECT * FROM categories"); // Change from 'category' to 'categories'
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($categories);
?>
