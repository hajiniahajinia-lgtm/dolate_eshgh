<?php
session_start();
require_once __DIR__ . "/db.php";

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit("برای افزودن دسته ابتدا وارد شوید.");
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit("درخواست نامعتبر است.");
}

$user_id = (int)$_SESSION['user_id'];
$community_id = (int)($_POST['community_id'] ?? 0);
$csrf_token = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['community_duty_csrf']) || $csrf_token === '' ||
    !hash_equals($_SESSION['community_duty_csrf'], $csrf_token)) {
    http_response_code(403);
    exit("نشست معتبر نیست؛ صفحه را تازه‌سازی کنید.");
}

$member_check = $conn->prepare("SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1");
if (!$member_check) {
    http_response_code(500);
    exit("بررسی عضویت انجام نشد.");
}
$member_check->bind_param("ii", $community_id, $user_id);
$member_check->execute();
$is_member = (bool)$member_check->get_result()->fetch_row();
$member_check->close();
if (!$is_member) {
    http_response_code(403);
    exit("فقط اعضای جامعه می‌توانند دستهٔ اختصاصی بسازند.");
}

$name = trim((string)($_POST['category_name'] ?? ''));
$description = trim((string)($_POST['category_description'] ?? ''));
if (function_exists('mb_substr')) {
    $name = mb_substr($name, 0, 120, 'UTF-8');
    $description = mb_substr($description, 0, 500, 'UTF-8');
} else {
    $name = substr($name, 0, 360);
    $description = substr($description, 0, 1500);
}
if ($name === '') {
    http_response_code(400);
    exit("نام دسته نمی‌تواند خالی باشد.");
}

$duplicate = $conn->prepare("SELECT id FROM topic_categories WHERE community_id=? AND is_active=1 AND name=? LIMIT 1");
$duplicate->bind_param("is", $community_id, $name);
$duplicate->execute();
$existing = $duplicate->get_result()->fetch_assoc();
$duplicate->close();
if ($existing) {
    header("Location: community.php?id=" . $community_id . "&category_exists=1");
    exit();
}

$slug = 'community-' . $community_id . '-' . bin2hex(random_bytes(8));
$insert = $conn->prepare("INSERT INTO topic_categories (community_id, slug, name, description, sort_order, is_active) VALUES (?, ?, ?, ?, 1000, 1)");
if (!$insert) {
    http_response_code(500);
    exit("ساخت دسته انجام نشد. ابتدا به‌روزرسانی پایگاه داده لازم است.");
}
$insert->bind_param("isss", $community_id, $slug, $name, $description);
if (!$insert->execute()) {
    error_log("Community topic category insert failed: " . $insert->error);
    http_response_code(500);
    exit("ذخیره دسته ناموفق بود.");
}
$insert->close();

header("Location: community.php?id=" . $community_id . "&category_added=1");
exit();
?>