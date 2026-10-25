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

$csrf_token = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['community_hierarchy_csrf']) || $csrf_token === '' ||
    !hash_equals($_SESSION['community_hierarchy_csrf'], $csrf_token)) {
    http_response_code(403);
    exit("درخواست معتبر نیست یا نشست شما منقضی شده است. لطفاً به پروفایل بازگردید و دوباره تلاش کنید.");
}

// دریافت امن مقادیر
$name = trim($_POST['community_name'] ?? '');
$approach = trim($_POST['community_approach'] ?? '');
$join_now = isset($_POST['join_now']) && $_POST['join_now']==1 ? true : false;

if ($name === '' || $approach === '') {
    die("نام جامعه و وظایف/رویکردها نمی‌تواند خالی باشد. <a href='profile.php'>بازگشت</a>");
}

// یکسان‌سازی چند شکل رایج نوشتاری نام برای جلوگیری از تکرارهایی مانند ي/ی و ك/ک
function normalize_community_name($value) {
    $value = str_replace(["ي", "ى", "ك", "\u{200C}", "\u{200D}"], ["ی", "ی", "ک", "", ""], (string)$value);
    $value = preg_replace('/\\s+/u', ' ', trim($value));
    if (function_exists('mb_strtolower')) {
        $value = mb_strtolower($value, 'UTF-8');
    } else {
        $value = strtolower($value);
    }
    return $value;
}

// بررسی نام‌های موجود؛ این کار بدون تغییر ساختار پایگاه داده انجام می‌شود.
$existing_names = $conn->query("SELECT name FROM communities");
if (!$existing_names) {
    die("بررسی نام جامعه انجام نشد. لطفاً دوباره تلاش کنید. <a href='profile.php'>بازگشت</a>");
}

$normalized_name = normalize_community_name($name);
$name_exists = false;
while ($existing = $existing_names->fetch_assoc()) {
    if (normalize_community_name($existing['name']) === $normalized_name) {
        $name_exists = true;
        break;
    }
}
$existing_names->free();

if ($name_exists) {
    http_response_code(409);
    die("<!DOCTYPE html>
<html lang='fa' dir='rtl'>
<head><meta charset='utf-8'><meta name='viewport' content='width=device-width, initial-scale=1'>
<title>نام جامعه تکراری است</title>
<style>body{font-family:tahoma,Arial,sans-serif;background:#eef3f8;margin:0;padding:20px}.message{max-width:560px;margin:40px auto;background:#fff;border:1px solid #e5b8b8;border-radius:10px;padding:22px;text-align:center;line-height:2;color:#8a2525}a{display:inline-block;margin-top:12px;background:#2065d1;color:#fff;text-decoration:none;padding:8px 16px;border-radius:6px}</style></head>
<body><div class='message'><strong>جامعه‌ای با این نام از قبل وجود دارد.</strong><br>لطفاً نام دیگری انتخاب کنید.<br><a href='profile.php'>بازگشت به پروفایل</a></div></body></html>");
}

// درج جامعه در جدول communities با creator_id
$stmt = $conn->prepare("INSERT INTO communities (name, approach, members_count, creator_id) VALUES (?, ?, 0, ?)");
if (!$stmt) die("خطا در آماده‌سازی کوئری: " . $conn->error);

$stmt->bind_param("ssi", $name, $approach, $user_id);

if ($stmt->execute()) {
    $community_id = $stmt->insert_id;
    $stmt->close();

    // ثبت متن اولیه به‌عنوان پیشنهاد پایه، تا اعضا بتوانند آن را با پیشنهادهای بعدی مقایسه و رأی‌گیری کنند.
    $duties_table_check = $conn->query("SHOW TABLES LIKE 'community_duties'");
    $votes_table_check = $conn->query("SHOW TABLES LIKE 'community_duty_votes'");
    $duties_ready = $duties_table_check && $votes_table_check
        && $duties_table_check->num_rows > 0 && $votes_table_check->num_rows > 0;
    if ($duties_table_check) $duties_table_check->close();
    if ($votes_table_check) $votes_table_check->close();

    if ($duties_ready) {
        $initial_proposal = $conn->prepare("INSERT INTO community_duties (community_id, user_id, proposal_type, content) VALUES (?, ?, 'approach', ?)");
        if ($initial_proposal) {
            $initial_proposal->bind_param("iis", $community_id, $user_id, $approach);
            $initial_proposal->execute();
            $initial_proposal->close();
        }
    }

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