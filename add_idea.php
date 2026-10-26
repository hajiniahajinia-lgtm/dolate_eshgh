<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    echo "⛔ دسترسی غیرمجاز!";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['community_duty_csrf']) || !isset($_POST['csrf_token']) || !is_string($_POST['csrf_token']) || !hash_equals($_SESSION['community_duty_csrf'], $_POST['csrf_token'])) {
    http_response_code(403);
    exit('درخواست معتبر نیست. لطفاً صفحه را تازه‌سازی کنید و دوباره تلاش کنید.');
}

$user_id = (int)$_SESSION['user_id'];

if (!isset($_POST['topic_id']) || !isset($_POST['community_id']) || !isset($_POST['idea_text'])) {
    die("⚠️ لطفاً موضوع و متن ایده را وارد کنید");
}

$topic_id = (int)$_POST['topic_id'];
$community_id = (int)$_POST['community_id'];
$content = trim($_POST['idea_text']);

// سقف متن ایده: ۲۵٬۰۰۰ نویسه (تقریباً معادل ۱۰ صفحه کتاب).
// کنترل سمت سرور نیز لازم است تا محدودیت مرورگر دور زده نشود.
if (function_exists('mb_strlen')) {
    $idea_length = mb_strlen($content, 'UTF-8');
} else {
    preg_match_all('/./us', $content, $idea_characters);
    $idea_length = count($idea_characters[0]);
}

if ($idea_length > 25000) {
    http_response_code(400);
    exit('متن ایده بیش از حد مجاز است. لطفاً متن را به حداکثر ۲۵٬۰۰۰ نویسه (تقریباً ۱۰ صفحه کتاب) کاهش دهید و دوباره ثبت کنید.');
}

$stmt = $conn->prepare("INSERT INTO ideas (topic_id, user_id, content) VALUES (?, ?, ?)");
$stmt->bind_param("iis", $topic_id, $user_id, $content);

if ($stmt->execute()) {
    header("Location: community.php?id=" . $community_id . "&success_idea=1");
    exit;
} else {
    echo "❌ خطا در ذخیره ایده: " . $conn->error;
}

$stmt->close();
$conn->close();
?>