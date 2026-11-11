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
    SELECT content, idea_rationality 
    FROM ideas 
    ORDER BY idea_rationality DESC 
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

/* --- هماهنگ‌سازی بصری آمار سامانه --- */
body{min-height:100vh;display:flex;align-items:flex-start;justify-content:center;box-sizing:border-box;background:#f3f6fb;color:#26364a;line-height:1.9;padding:28px 14px}
.box{box-sizing:border-box;width:min(100%,620px);margin:24px auto;padding:22px 24px;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 7px 24px rgba(30,55,90,.07)}
h2{color:#294d73;font-size:21px;line-height:1.7;margin:0 0 18px;padding-bottom:12px;border-bottom:1px solid #edf1f6}
p{font-size:15px;line-height:2;margin:0;padding:12px 2px;border-bottom:1px solid #edf1f6;overflow-wrap:anywhere}
p:last-of-type{border-bottom:0}
p b{color:#1f4f82}
a{display:flex;align-items:center;justify-content:center;min-height:44px;margin-top:20px;padding:10px 14px;border-radius:9px;background:#245fa8;transition:filter .18s ease,transform .18s ease}
a:hover{background:#194b89;filter:brightness(.97)}
a:active{transform:translateY(1px)}
@media(max-width:520px){body{padding:12px}.box{margin:10px auto;padding:17px 15px;border-radius:12px}h2{font-size:19px}p{font-size:14px}}

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
        (امتیاز: <?= $top_idea['idea_rationality'] ?>)</p>
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