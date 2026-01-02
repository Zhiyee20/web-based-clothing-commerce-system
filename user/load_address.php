<?php
session_start();
include 'login_base.php';

// 获取当前用户 ID（假设已存入 session）
$user_id = $_SESSION['AddressID'] ?? 0;

if ($user_id == 0) {
    echo json_encode(["error" => "User not logged in."]);
    exit;
}

// 查询用户地址
$query = "SELECT * FROM user_address WHERE AddressID = :AddressID ORDER BY is_default DESC, id DESC";
$stmt = $pdo->prepare($query);
$stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$stmt->execute();
$addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($addresses);
?>
