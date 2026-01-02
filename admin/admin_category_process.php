<?php
include '../config.php'; // Use config.php

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    /* =========================
       ADD CATEGORY
       ========================= */
    if (isset($_POST["add"])) {
        $categoryName = trim($_POST["CategoryName"]);

        if (!empty($categoryName)) {

            // Check if an ACTIVE category with same name already exists
            $checkStmt = $pdo->prepare("
                SELECT *
                FROM categories
                WHERE CategoryName = ?
                  AND (IsDeleted = 0 OR IsDeleted IS NULL)
            ");
            $checkStmt->execute([$categoryName]);

            if ($checkStmt->rowCount() > 0) {
                echo "<script>alert('Category already exists!'); window.location.href='admin_category.php';</script>";
                exit;
            }

            // Insert new category (IsDeleted default = 0)
            $stmt = $pdo->prepare("
                INSERT INTO categories (CategoryName)
                VALUES (?)
            ");
            if ($stmt->execute([$categoryName])) {
                echo "<script>alert('Category added successfully!'); window.location.href='admin_category.php';</script>";
            } else {
                echo "<script>alert('Failed to add category!'); window.location.href='admin_category.php';</script>";
            }

        } else {
            echo "<script>alert('Category name cannot be empty!'); window.location.href='admin_category.php';</script>";
        }
    }


    /* =========================
       EDIT CATEGORY
       ========================= */
    if (isset($_POST["edit"])) {
        $categoryID   = $_POST["CategoryID"];
        $categoryName = trim($_POST["CategoryName"]);

        if (!empty($categoryName)) {

            $stmt = $pdo->prepare("
                UPDATE categories
                SET CategoryName = ?
                WHERE CategoryID = ?
            ");

            if ($stmt->execute([$categoryName, $categoryID])) {
                echo "<script>alert('Category updated successfully!'); window.location.href='admin_category.php';</script>";
            } else {
                echo "<script>alert('Failed to update category!'); window.location.href='admin_category.php';</script>";
            }

        } else {
            echo "<script>alert('Category name cannot be empty!'); window.location.href='admin_category.php';</script>";
        }
    }


    /* =========================
       SOFT DELETE CATEGORY
       ========================= */
    if (isset($_POST["delete"])) {

        $categoryID = $_POST["CategoryID"];

        $stmt = $pdo->prepare("
            UPDATE categories
            SET IsDeleted = 1,
                DeletedAt = NOW()
            WHERE CategoryID = ?
        ");

        if ($stmt->execute([$categoryID])) {
            echo "<script>alert('Category deleted successfully!'); window.location.href='admin_category.php';</script>";
        } else {
            echo "<script>alert('Failed to delete category!'); window.location.href='admin_category.php';</script>";
        }
    }


    /* =========================
       RESTORE CATEGORY
       ========================= */
    if (isset($_POST["restore"])) {

        $categoryID = $_POST["CategoryID"];

        $stmt = $pdo->prepare("
            UPDATE categories
            SET IsDeleted = 0,
                DeletedAt = NULL
            WHERE CategoryID = ?
        ");

        if ($stmt->execute([$categoryID])) {
            echo "<script>alert('Category restored successfully!'); window.location.href='admin_category.php';</script>";
        } else {
            echo "<script>alert('Failed to restore!'); window.location.href='admin_category.php';</script>";
        }
    }

}
?>
