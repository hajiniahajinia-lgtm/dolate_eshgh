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
$urgency_units = isset($_POST['urgency_units']) ? (int)$_POST['urgency_units'] : 1;
$urgency_units = max(1, min(360, $urgency_units));

if ($topic_id <= 0 || $community_id <= 0) {
    http_response_code(400);
    exit("موضوع یا جامعه نامعتبر است.");
}

// ثبت فوریت کاربر با نام ستون واقعی جدول هاست: urgency_units
$save_vote = $conn->prepare("
    INSERT INTO topic_urgency_votes (topic_id, user_id, urgency_units)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE urgency_units = VALUES(urgency_units)
");
$save_vote->bind_param("iii", $topic_id, $user_id, $urgency_units);
$save_vote->execute();
$save_vote->close();

// محاسبه میانگین فوریت ثبت‌شده توسط اعضا (واحد: روز؛ ذخیره در ستون urgency_units)
$avg_sql = $conn->prepare("
    SELECT AVG(urgency_units) AS avg_urgency
    FROM topic_urgency_votes
    WHERE topic_id = ?
");
$avg_sql->bind_param("i", $topic_id);
$avg_sql->execute();
$avg_result = $avg_sql->get_result()->fetch_assoc();
$avg_sql->close();

$avg_urgency_units = max(1, min(360, (int)round((float)($avg_result['avg_urgency'] ?? $urgency_units))));

// به‌روزرسانی فوریت موضوع بر اساس میانگین روزها
$update_topic = $conn->prepare("
    UPDATE topics
    SET urgency = ?
    WHERE id = ? AND community_id = ?
");
$update_topic->bind_param("iii", $avg_urgency_units, $topic_id, $community_id);
$update_topic->execute();
$updated = $update_topic->affected_rows;
$update_topic->close();

// بازگشت به جامعه انتخاب‌شده
header("Location: community.php?id=" . $community_id);
exit;
