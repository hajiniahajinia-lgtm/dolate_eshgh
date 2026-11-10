<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// جامعه جاری
$community_id = isset($_GET['community_id']) ? (int)$_GET['community_id'] : 0;
$community = null;
if($community_id > 0){
    $cstmt = $conn->prepare("SELECT * FROM communities WHERE id=?");
    $cstmt->bind_param("i", $community_id);
    $cstmt->execute();
    $community = $cstmt->get_result()->fetch_assoc();
    $cstmt->close();
}

// تعداد موضوعات
$topics_count_q = $conn->prepare("SELECT COUNT(*) AS total FROM topics WHERE community_id=?");
$topics_count_q->bind_param("i", $community_id);
$topics_count_q->execute();
$topics_count_res = $topics_count_q->get_result()->fetch_assoc();
$topics_count_q->close();
$topics_count = (int)$topics_count_res['total'];

// تعداد ایده‌ها
$ideas_count_q = $conn->prepare("SELECT COUNT(*) AS total FROM ideas i INNER JOIN topics t ON i.topic_id=t.id WHERE t.community_id=?");
$ideas_count_q->bind_param("i", $community_id);
$ideas_count_q->execute();
$ideas_count_res = $ideas_count_q->get_result()->fetch_assoc();
$ideas_count_q->close();
$ideas_count = (int)$ideas_count_res['total'];

// پویاترین موضوع
$active_topic_q = $conn->prepare("
    SELECT t.title, COUNT(i.id) AS idea_count 
    FROM topics t 
    LEFT JOIN ideas i ON t.id = i.topic_id 
    WHERE t.community_id=?
    GROUP BY t.id 
    ORDER BY idea_count DESC 
    LIMIT 1
");
$active_topic_q->bind_param("i", $community_id);
$active_topic_q->execute();
$active_topic = $active_topic_q->get_result()->fetch_assoc();
$active_topic_q->close();

// منطقی‌ترین ایده
$top_idea_q = $conn->prepare("
    SELECT i.content, i.idea_rationality
    FROM ideas i
    INNER JOIN topics t ON i.topic_id = t.id
    WHERE t.community_id=?
    ORDER BY i.idea_rationality DESC
    LIMIT 1
");
$top_idea_q->bind_param("i", $community_id);
$top_idea_q->execute();
$top_idea = $top_idea_q->get_result()->fetch_assoc();
$top_idea_q->close();

// بالاترین اولویت
$top_priority_q = $conn->prepare("SELECT title, priority FROM topics WHERE community_id=? ORDER BY priority DESC LIMIT 1");
$top_priority_q->bind_param("i", $community_id);
$top_priority_q->execute();
$top_priority = $top_priority_q->get_result()->fetch_assoc();
$top_priority_q->close();

// بالاترین فوریت
$top_urgency_q = $conn->prepare("SELECT title, urgency FROM topics WHERE community_id=? ORDER BY urgency DESC LIMIT 1");
$top_urgency_q->bind_param("i", $community_id);
$top_urgency_q->execute();
$top_urgency = $top_urgency_q->get_result()->fetch_assoc();
$top_urgency_q->close();
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>گزارش آماری جامعه</title>
<style>
body {font-family: tahoma; background: #eef2f7; padding: 20px;}
.box {
    background: white; padding: 18px; border-radius: 10px;
    width: 500px; margin: auto; border: 1px solid #ccc;
}
h2 {color: #003399; text-align:center}
p {font-size:16px; margin:10px 0}
a {display:block;text-align:center;margin-top:15px;font-size:15px;text-decoration:none;color:#fff;background:#0066cc;padding:10px;border-radius:8px}
</style>
</head>
<body>

<div class="box">
    <h2>📊 گزارش آماری جامعه</h2>

    <?php if($community): ?>
    <p>🏘️ جامعه: <b><?= htmlspecialchars($community['name']) ?></b></p>
    <p>✅ تعداد کل موضوعات: <b><?= $topics_count ?></b></p>
    <p>📝 تعداد کل ایده‌ها: <b><?= $ideas_count ?></b></p>

    <?php if($active_topic): ?>
        <p>🔥 پویاترین موضوع: <b><?= htmlspecialchars($active_topic['title']) ?></b> (<?= $active_topic['idea_count'] ?> ایده)</p>
    <?php endif; ?>

    <?php if($top_idea): ?>
        <p>💡 منطقی‌ترین ایده: <b><?= htmlspecialchars($top_idea['content']) ?></b> (امتیاز: <?= $top_idea['idea_rationality'] ?>)</p>
    <?php endif; ?>

    <?php if($top_priority): ?>
        <p>⭐ بالاترین اولویت: <b><?= htmlspecialchars($top_priority['title']) ?></b> (<?= $top_priority['priority'] ?>)</p>
    <?php endif; ?>

    <?php if($top_urgency): ?>
        <p>⏱️ بالاترین فوریت: <b><?= htmlspecialchars($top_urgency['title']) ?></b> (<?= $top_urgency['urgency'] ?>)</p>
    <?php endif; ?>

    <a href="community.php?id=<?= $community_id ?>">بازگشت به جامعه</a>
    <?php else: ?>
    <p style="color:red">هیچ جامعه‌ای انتخاب نشده است.</p>
    <?php endif; ?>

</div>

</body>
</html>