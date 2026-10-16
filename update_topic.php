<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    die("⛔ دسترسی غیرمجاز");
}

$user_id = (int)$_SESSION['user_id'];

if (!isset($_POST['topic_id']) || !isset($_POST['field'])) {
    die("⚠️ داده‌های ورودی ناقص است.");
}

$topic_id = (int)$_POST['topic_id'];
$field = $_POST['field'];

if ($field === 'urgency') {
    if (!isset($_POST['urgency_days'])) {
        die("⚠️ داده‌های ورودی ناقص است.");
    }
    $urgency = (int)$_POST['urgency_days'];
    $stmt = $conn->prepare("UPDATE topics SET urgency=? WHERE id=?");
    $stmt->bind_param("ii", $urgency, $topic_id);
    if ($stmt->execute()) {
        echo "⏱ فوریت با موفقیت بروزرسانی شد";
    } else {
        echo "❌ خطا در بروزرسانی فوریت: " . $conn->error;
    }
    $stmt->close();
}

elseif ($field === 'priority') {
    // بررسی وجود رأی قبلی
    $check = $conn->prepare("SELECT id FROM topic_votes WHERE topic_id=? AND user_id=?");
    $check->bind_param("ii", $topic_id, $user_id);
    $check->execute();
    $res = $check->get_result();

    if ($res->num_rows > 0) {
        // حذف رأی (برداشتن اولویت)
        $del = $conn->prepare("DELETE FROM topic_votes WHERE topic_id=? AND user_id=?");
        $del->bind_param("ii", $topic_id, $user_id);
        $del->execute();
        $del->close();
        echo "✅ اولویت برداشته شد";
    } else {
        // ثبت رأی جدید
        $ins = $conn->prepare("INSERT INTO topic_votes (topic_id,user_id) VALUES (?,?)");
        $ins->bind_param("ii", $topic_id, $user_id);
        $ins->execute();
        $ins->close();
        echo "✅ اولویت ثبت شد";
    }
    $check->close();
}

else {
    die("⚠️ فیلد درخواستی معتبر نیست.");
}

$conn->close();
?>