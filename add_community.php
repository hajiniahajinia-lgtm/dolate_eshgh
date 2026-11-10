<?php
// add_community.php
session_start();
include "db.php";

if(!isset($_SESSION['user_id'])){
    die("ابتدا وارد سایت شوید.");
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $approach = trim($_POST['approach'] ?? '');
    $user_id = (int)$_SESSION['user_id'];

    if(empty($name) || empty($description) || empty($approach)){
        die("لطفاً همه فیلدها را پر کنید.");
    }

    // درج جامعه جدید
    $stmt = $conn->prepare("INSERT INTO communities (name, description, approach, members_count) VALUES (?, ?, ?, 0)");
    if(!$stmt){
        die("خطا در آماده‌سازی کوئری: " . $conn->error);
    }
    $stmt->bind_param("sss", $name, $description, $approach);

    if($stmt->execute()){
        // بعد از ایجاد، ریدایرکت به پروفایل
        header("Location: profile.php");
        exit;
    } else {
        die("خطا در ایجاد جامعه: " . $conn->error);
    }
} else {
    header("Location: profile.php");
    exit;
}