<?php
// test_enforce.php - اسکریپت تستی برای بررسی topic_enforce_votes و topic_enforce_stats

// نمایش همه خطاها
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

session_start();
include "db.php"; // مطمئن شو که مسیر درست است

if(!$conn){
    die("اتصال به دیتابیس برقرار نشد: " . mysqli_connect_error());
}

// --- انتخاب یک community_id برای تست ---
$community_id = isset($_GET['community_id']) ? (int)$_GET['community_id'] : 1; // پیشفرض 1
echo "<h3>Community ID: $community_id</h3>";

// --- گرفتن تمام موضوعات بسته شده این جامعه ---
$sql = "SELECT id, title FROM topics WHERE community_id=? AND closed=1";
$stmt = $conn->prepare($sql);
if(!$stmt) die("خطا در prepare: ".$conn->error);
$stmt->bind_param("i",$community_id);
$stmt->execute();
$result = $stmt->get_result();

if($result->num_rows === 0){
    die("موضوع بسته‌ای برای این جامعه پیدا نشد.");
}

while($topic = $result->fetch_assoc()){
    $tid = (int)$topic['id'];
    echo "<hr>";
    echo "<h4>Topic ID: {$tid} — {$topic['title']}</h4>";

    // --- گرفتن ایده‌ها ---
    $iq = $conn->prepare("SELECT id, content FROM ideas WHERE topic_id=?");
    if(!$iq) die("خطا در prepare ideas: ".$conn->error);
    $iq->bind_param("i",$tid);
    $iq->execute();
    $ideas_res = $iq->get_result();
    $iq->close();

    if($ideas_res->num_rows === 0){
        echo "ایده‌ای برای این موضوع وجود ندارد.<br>";
        continue;
    }

    while($idea = $ideas_res->fetch_assoc()){
        $iid = (int)$idea['id'];
        echo "<b>Idea ID: {$iid}</b> — ".htmlspecialchars($idea['content'])."<br>";

        // --- محاسبه رأی‌های لازم‌الاجرا و غیر لازم‌الاجرا ---
        $stmt2 = $conn->prepare("
            SELECT 
                SUM(enforceable=1) AS enforce_count,
                SUM(enforceable=0) AS non_enforce_count
            FROM topic_enforce_votes
            WHERE topic_id=? AND idea_id=?
        ");
        if(!$stmt2) die("خطا در prepare enforce_votes: ".$conn->error);
        $stmt2->bind_param("ii",$tid,$iid);
        $stmt2->execute();
        $res2 = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();

        $enforce = (int)($res2['enforce_count'] ?? 0);
        $non_enforce = (int)($res2['non_enforce_count'] ?? 0);

        echo "لازم‌الاجرا: {$enforce} — غیر لازم‌الاجرا: {$non_enforce}<br>";

        // --- بروزرسانی topic_enforce_stats ---
        $upd = $conn->prepare("
            INSERT INTO topic_enforce_stats (topic_id, idea_id, enforce_count, non_enforce_count, updated_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                enforce_count=VALUES(enforce_count),
                non_enforce_count=VALUES(non_enforce_count),
                updated_at=NOW()
        ");
        if(!$upd) die("خطا در prepare update stats: ".$conn->error);
        $upd->bind_param("iiii",$tid,$iid,$enforce,$non_enforce);
        if(!$upd->execute()) die("خطا در execute update stats: ".$upd->error);
        $upd->close();

        echo "✅ جدول topic_enforce_stats بروزرسانی شد.<br>";
    }
}

echo "<hr><b>اسکریپت تستی با موفقیت اجرا شد.</b>";
?>