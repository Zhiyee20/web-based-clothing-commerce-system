<?php
require __DIR__ . '/../config.php';


if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $productID = $_POST["editProductID"];
    $name = $_POST["editName"];
    $description = $_POST["editDescription"];
    $price = $_POST["editPrice"];
    $stock = $_POST["editStock"];
    $categoryID = $_POST["editCategory"]; // Ensure this field matches your form

    try {
        // Prepare the update query
        $stmt = $conn->prepare("UPDATE product SET Name=?, Description=?, Price=?, Stock=?, Category=? WHERE ProductID=?");
        $stmt->execute([$name, $description, $price, $stock, $categoryID, $productID]);

        echo json_encode(["success" => true]); // Return success response
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
    }
}
?>
