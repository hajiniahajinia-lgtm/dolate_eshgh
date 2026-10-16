<?php
session_start();
include __DIR__ . "/db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// گرفتن داده‌ها از فرم
$topic_id = isset($_POST['topic_id']) ? (int)$_POST['topic_id'] : 0;
$urgency_days = isset($_POST['urgency_days']) ? (int)$_POST['urgency_days'] : 3; // پیش‌فرض 3 روز

if ($topic_id <= 0) {
    die("موضوع نامعتبر است.");
}

// تبدیل روز به دقیقه (اگر واحد شما دقیقه است)
$urgency_minutes = $urgency_days * 24 * 60;

// ---- ۱. ذخیره فوریت کاربر در جدول topic_urgency_votes ----
$save_vote = $conn->prepare("
    INSERT INTO topic_urgency_votes (topic_id, user_id, urgency_minutes)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE urgency_minutes = VALUES(urgency_minutes)
");
$save_vote->bind_param("iii", $topic_id, $user_id, $urgency_minutes);
$save_vote->execute();
$save_vote->close();

// ---- ۲. محاسبه میانگین فوریت کل اعضا ----
$avg_sql = $conn->prepare("
    SELECT AVG(urgency_minutes) AS avg_urgency
    FROM topic_urgency_votes
    WHERE topic_id = ?
");
$avg_sql->bind_param("i", $topic_id);
$avg_sql->execute();
$avg_result = $avg_sql->get_result()->fetch_assoc();
$avg_sql->close();

$avg_urgency_minutes = round($avg_result['avg_urgency']); // دقیقه
$avg_urgency_days = ceil($avg_urgency_minutes / (24*60)); // تبدیل به روز برای نمایش

// ---- ۳. آپدیت فوریت میانگین در جدول topics ----
$update_topic = $conn->prepare("
    UPDATE topics
    SET urgency = ?
    WHERE id = ?
");
$update_topic->bind_param("ii", $avg_urgency_days, $topic_id);
$update_topic->execute();
$update_topic->close();

// ---- ۴. بازگشت به صفحه قبلی ----
$redirect = isset($_POST['redirect']) ? $_POST['redirect'] : "community.php?id=" . (int)$_POST['community_id'];
header("Location: $redirect");
exit;