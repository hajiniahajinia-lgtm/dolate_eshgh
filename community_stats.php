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

// تعداد اعضا و فعالیت‌های تازه؛ ستون تاریخ هر جدول پیش از استفاده بررسی می‌شود.
$members_count = 0;
$members_query = $conn->prepare("SELECT COUNT(*) AS total FROM community_members WHERE community_id=?");
if ($members_query) {
    $members_query->bind_param("i", $community_id);
    $members_query->execute();
    $members_count = (int)($members_query->get_result()->fetch_assoc()['total'] ?? 0);
    $members_query->close();
}
function community_stats_time_column($conn, $table) {
    $allowed = ['topics', 'ideas', 'community_topic_discussions'];
    if (!in_array($table, $allowed, true)) return null;
    $result = $conn->query("SHOW COLUMNS FROM `" . $table . "`");
    if (!$result) return null;
    $fields = [];
    while ($row = $result->fetch_assoc()) $fields[] = $row['Field'];
    $result->free();
    foreach (['created_at', 'created_on', 'created', 'date_created'] as $field) {
        if (in_array($field, $fields, true)) return $field;
    }
    return null;
}
$recent_topics = 0;
$recent_ideas = 0;
$recent_discussions = 0;
$topics_time_column = community_stats_time_column($conn, 'topics');
if ($topics_time_column) {
    $query = $conn->prepare("SELECT COUNT(*) AS total FROM topics WHERE community_id=? AND `" . $topics_time_column . "` >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    if ($query) {
        $query->bind_param("i", $community_id);
        $query->execute();
        $recent_topics = (int)($query->get_result()->fetch_assoc()['total'] ?? 0);
        $query->close();
    }
}
$ideas_time_column = community_stats_time_column($conn, 'ideas');
if ($ideas_time_column) {
    $query = $conn->prepare("SELECT COUNT(*) AS total FROM ideas i INNER JOIN topics t ON t.id=i.topic_id WHERE t.community_id=? AND i.`" . $ideas_time_column . "` >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    if ($query) {
        $query->bind_param("i", $community_id);
        $query->execute();
        $recent_ideas = (int)($query->get_result()->fetch_assoc()['total'] ?? 0);
        $query->close();
    }
}
$discussion_table = $conn->query("SHOW TABLES LIKE 'community_topic_discussions'");
if ($discussion_table && $discussion_table->num_rows > 0) {
    $discussion_time_column = community_stats_time_column($conn, 'community_topic_discussions');
    if ($discussion_time_column) {
        $query = $conn->prepare("SELECT COUNT(*) AS total FROM community_topic_discussions d INNER JOIN topics t ON t.id=d.topic_id WHERE t.community_id=? AND d.`" . $discussion_time_column . "` >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        if ($query) {
            $query->bind_param("i", $community_id);
            $query->execute();
            $recent_discussions = (int)($query->get_result()->fetch_assoc()['total'] ?? 0);
            $query->close();
        }
    }
}
if ($discussion_table) $discussion_table->close();
$recent_activity_total = $recent_topics + $recent_ideas + $recent_discussions;

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
    <p>👥 تعداد اعضای جامعه: <b><?= $members_count ?></b></p>
    <div style="margin:14px 0;padding:12px;background:#f3f8ff;border:1px solid #d5e4f5;border-radius:8px;">
        <strong>📈 فعالیت جامعه در هفت روز اخیر</strong>
        <p>موضوع‌های تازه: <b><?= $recent_topics ?></b></p>
        <p>ایده‌های تازه: <b><?= $recent_ideas ?></b></p>
        <p>گفت‌وگوهای تازه: <b><?= $recent_discussions ?></b></p>
        <p>مجموع فعالیت‌های ثبت‌شده: <b><?= $recent_activity_total ?></b></p>
    </div>

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