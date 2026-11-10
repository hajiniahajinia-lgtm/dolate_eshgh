<?php
session_start();
include "db.php";

// بررسی اینکه شناسه ایده ارسال شده باشد
if (!isset($_POST['idea_id'])) {
    die("شناسه ایده مشخص نشده است.");
}

$idea_id = (int)$_POST['idea_id'];

// آماده‌سازی و اجرای کوئری افزایش معقولیت
$stmt = $conn->prepare("UPDATE ideas SET idea_rationality = idea_rationality + 1 WHERE id = ?");
if (!$stmt) {
    die("خطا در آماده‌سازی کوئری: " . $conn->error);
}

$stmt->bind_param("i", $idea_id);

if (!$stmt->execute()) {
    die("خطا در اجرای کوئری: " . $stmt->error);
}

$stmt->close();

// بازگشت به صفحه‌ای که کاربر از آن آمده
$redirect = $_SERVER['HTTP_REFERER'] ?? 'community.php';
header("Location: " . $redirect);
exit;
?>