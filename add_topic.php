<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    echo "⛔ دسترسی غیرمجاز!";
    exit;
}

$user_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit("درخواست نامعتبر است.");
}

$csrf_token = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['community_duty_csrf']) || $csrf_token === '' ||
    !hash_equals($_SESSION['community_duty_csrf'], $csrf_token)) {
    http_response_code(403);
    exit("درخواست معتبر نیست یا نشست شما منقضی شده است. لطفاً صفحه را تازه‌سازی و دوباره تلاش کنید.");
}

if (!isset($_POST['topic_title']) || !isset($_POST['community_id'])) {
    die("⚠️ لطفاً عنوان موضوع و جامعه را انتخاب کنید");
}

// ⬅️ محدودیت 100 کاراکتر
$title = mb_substr(trim($_POST['topic_title']), 0, 100);
$description = mb_substr(trim($_POST['topic_description'] ?? ''), 0, 500);

if ($title === "") {
    die("⚠️ عنوان موضوع نمی‌تواند خالی باشد");
}

$community_id = (int)$_POST['community_id'];

// مقدار فوریت اصلی (دقیقه)
$urgency_minutes = 10;

// فوریت قدیمی برای سازگاری
$urgency = $urgency_minutes;

$deadline = date("Y-m-d H:i:s", time() + ($urgency_minutes * 60));
$created_at = date("Y-m-d H:i:s");
$priority = 0;
$urgency_default_minutes = 3;
$urgency_updated_at = date("Y-m-d H:i:s");

$stmt = $conn->prepare("
    INSERT INTO topics 
    (community_id, title, description, user_id, priority, urgency, urgency_minutes, urgency_default_minutes, deadline, created_at, urgency_updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$stmt->bind_param(
    "issiiiiisss",
    $community_id,
    $title,
    $description,
    $user_id,
    $priority,
    $urgency,
    $urgency_minutes,
    $urgency_default_minutes,
    $deadline,
    $created_at,
    $urgency_updated_at
);

if ($stmt->execute()) {
    header("Location: community.php?id=" . $community_id . "&success_topic=1");
    exit();
} else {
    echo "❌ خطا در ذخیره موضوع: " . $stmt->error;
}

$stmt->close();
$conn->close();
?>