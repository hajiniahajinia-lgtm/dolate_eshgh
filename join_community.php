<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: profile.php");
    exit;
}

$community_id = (int)($_POST['community_id'] ?? 0);

if ($community_id <= 0) {
    die("شناسه جامعه نامعتبر است. <a href='profile.php'>بازگشت</a>");
}

// بررسی اینکه کاربر قبلاً عضو نشده باشد
$check = $conn->prepare("SELECT 1 FROM community_members WHERE community_id=? AND user_id=?");
$check->bind_param("ii", $community_id, $user_id);
$check->execute();
$res = $check->get_result();
$check->close();

if ($res->num_rows > 0) {
    die("شما قبلاً عضو این جامعه هستید. <a href='profile.php'>بازگشت</a>");
}

// درج عضویت
$stmt = $conn->prepare("INSERT INTO community_members (community_id, user_id) VALUES (?, ?)");
if (!$stmt) die("خطا در آماده‌سازی کوئری: " . $conn->error);
$stmt->bind_param("ii", $community_id, $user_id);

if ($stmt->execute()) {
    $stmt->close();
    // افزایش شمارش اعضا در communities
    $conn->query("UPDATE communities SET members_count = members_count + 1 WHERE id = $community_id");
    header("Location: profile.php?msg=joined_community");
    exit;
} else {
    die("خطا در عضویت در جامعه: " . $stmt->error);
}