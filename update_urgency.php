<?php
session_start();
include __DIR__ . "/db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// دریافت و اعتبارسنجی داده‌های فرم
$topic_id = isset($_POST['topic_id']) ? (int)$_POST['topic_id'] : 0;
$community_id = isset($_POST['community_id']) ? (int)$_POST['community_id'] : 0;
$urgency_days = isset($_POST['urgency_days']) ? (int)$_POST['urgency_days'] : 1;
$urgency_days = max(1, min(360, $urgency_days));

if ($topic_id <= 0 || $community_id <= 0) {
    http_response_code(400);
    exit("موضوع یا جامعه نامعتبر است.");
}

// ثبت فوریت کاربر با نام ستون واقعی جدول: urgency_days
$save_vote = $conn->prepare("
    INSERT INTO topic_urgency_votes (topic_id, user_id, urgency_days)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE urgency_days = VALUES(urgency_days)
");
$save_vote->bind_param("iii", $topic_id, $user_id, $urgency_days);
$save_vote->execute();
$save_vote->close();

// محاسبه میانگین فوریت ثبت‌شده توسط اعضا (واحد: روز)
$avg_sql = $conn->prepare("
    SELECT AVG(urgency_days) AS avg_urgency
    FROM topic_urgency_votes
    WHERE topic_id = ?
");
$avg_sql->bind_param("i", $topic_id);
$avg_sql->execute();
$avg_result = $avg_sql->get_result()->fetch_assoc();
$avg_sql->close();

$avg_urgency_days = max(1, min(360, (int)round((float)($avg_result['avg_urgency'] ?? $urgency_days))));

// به‌روزرسانی فوریت موضوع بر اساس میانگین روزها
$update_topic = $conn->prepare("
    UPDATE topics
    SET urgency = ?
    WHERE id = ? AND community_id = ?
");
$update_topic->bind_param("iii", $avg_urgency_days, $topic_id, $community_id);
$update_topic->execute();
$updated = $update_topic->affected_rows;
$update_topic->close();

// بازگشت به جامعه انتخاب‌شده
header("Location: community.php?id=" . $community_id);
exit;
