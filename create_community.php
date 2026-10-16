<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: profile.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: profile.php");
    exit;
}

// دریافت امن مقادیر
$name = trim($_POST['community_name'] ?? '');
$approach = trim($_POST['community_approach'] ?? '');
$join_now = isset($_POST['join_now']) && $_POST['join_now']==1 ? true : false;

if ($name === '' || $approach === '') {
    die("نام جامعه و وظایف/رویکردها نمی‌تواند خالی باشد. <a href='profile.php'>بازگشت</a>");
}

// درج جامعه در جدول communities با creator_id
$stmt = $conn->prepare("INSERT INTO communities (name, approach, members_count, creator_id) VALUES (?, ?, 0, ?)");
if (!$stmt) die("خطا در آماده‌سازی کوئری: " . $conn->error);

$stmt->bind_param("ssi", $name, $approach, $user_id);

if ($stmt->execute()) {
    $community_id = $stmt->insert_id;
    $stmt->close();

    // اگر تیک "همین الان عضو شوم" زده شده، اضافه کردن کاربر به members
    if ($join_now) {
        $mem_stmt = $conn->prepare("INSERT INTO community_members (community_id, user_id) VALUES (?, ?)");
        if ($mem_stmt) {
            $mem_stmt->bind_param("ii", $community_id, $user_id);
            $mem_stmt->execute();
            $mem_stmt->close();
        }
    }

    header("Location: profile.php?msg=community_created");
    exit;
} else {
    die("خطا در ایجاد جامعه: " . $stmt->error);
}
?>