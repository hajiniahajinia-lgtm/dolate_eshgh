<?php
include "db.php";

// تعداد موضوعات
$topics_count_q = $conn->query("SELECT COUNT(*) AS total FROM topics");
$topics_count = $topics_count_q ? $topics_count_q->fetch_assoc()['total'] : 0;

// تعداد ایده‌ها
$ideas_count_q = $conn->query("SELECT COUNT(*) AS total FROM ideas");
$ideas_count = $ideas_count_q ? $ideas_count_q->fetch_assoc()['total'] : 0;

// پویاترین موضوع
$active_topic_q = $conn->query("
    SELECT t.title, COUNT(i.id) AS idea_count 
    FROM topics t 
    LEFT JOIN ideas i ON t.id = i.topic_id 
    GROUP BY t.id 
    ORDER BY idea_count DESC 
    LIMIT 1
");
$active_topic = $active_topic_q ? $active_topic_q->fetch_assoc() : null;

// منطقی‌ترین ایده
$top_idea_q = $conn->query("
    SELECT content, rationality 
    FROM ideas 
    ORDER BY rationality DESC 
    LIMIT 1
");
$top_idea = $top_idea_q ? $top_idea_q->fetch_assoc() : null;

// بیشترین اولویت
$top_priority_q = $conn->query("SELECT title, priority FROM topics ORDER BY priority DESC LIMIT 1");
$top_priority = $top_priority_q ? $top_priority_q->fetch_assoc() : null;

// بیشترین فوریت از جدول meta
$top_urgency_q = $conn->query("
    SELECT t.title, m.urgency_days AS urgency 
    FROM topic_voting_meta m
    JOIN topics t ON t.id = m.topic_id
    WHERE m.finalized = 1
    ORDER BY m.urgency_days ASC
    LIMIT 1
");
$top_urgency = $top_urgency_q ? $top_urgency_q->fetch_assoc() : null;
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>گزارش آماری سامانه</title>
<style>
    body {font-family: tahoma; background: #eef2f7; padding: 20px;}
    .box {background: white; padding: 18px; border-radius: 10px; width: 450px; margin: auto; border: 1px solid #ccc}
    h2 {color: #003399; text-align:center}
    p {font-size:16px; margin:10px 0}
    a {display:block;text-align:center;margin-top:15px;font-size:15px;text-decoration:none;color:#fff;background:#0066cc;padding:10px;border-radius:8px}
</style>
</head>
<body>

<div class="box">
    <h2>📊 گزارش آماری سامانه</h2>

    <p>✅ تعداد کل موضوعات: <b><?= $topics_count ?></b></p>
    <p>📝 تعداد کل ایده‌های ثبت‌شده: <b><?= $ideas_count ?></b></p>

    <?php if($active_topic): ?>
        <p>🔥 پویاترین موضوع: <b><?= htmlspecialchars($active_topic['title']) ?></b> 
        (<?= $active_topic['idea_count'] ?> ایده)</p>
    <?php endif; ?>

    <?php if($top_idea): ?>
        <p>💡 منطقی‌ترین ایده: <b><?= htmlspecialchars($top_idea['content']) ?></b> 
        (امتیاز: <?= $top_idea['rationality'] ?>)</p>
    <?php endif; ?>

    <?php if($top_priority): ?>
        <p>⭐ بالاترین اولویت: <b><?= htmlspecialchars($top_priority['title']) ?></b> 
        (<?= $top_priority['priority'] ?>)</p>
    <?php endif; ?>

    <?php if($top_urgency): ?>
        <p>⏱️ بالاترین فوریت: <b><?= htmlspecialchars($top_urgency['title']) ?></b> 
        (<?= $top_urgency['urgency'] ?> روز)</p>
    <?php endif; ?>

    <a href="index.php">بازگشت به صفحه اصلی</a>
</div>

</body>
</html>