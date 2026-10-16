<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['idea_id'])) {
    die("درخواست نامعتبر");
}

$idea_id = (int)$_POST['idea_id'];

// گرفتن اطلاعات ایده
$q = $conn->prepare("
    SELECT i.topic_id, t.community_id, t.closed
    FROM ideas i
    JOIN topics t ON i.topic_id=t.id
    WHERE i.id=?
");
$q->bind_param("i",$idea_id);
$q->execute();
$data = $q->get_result()->fetch_assoc();
$q->close();

if (!$data) die("ایده یافت نشد.");

$topic_id = (int)$data['topic_id'];
$community_id = (int)$data['community_id'];
if ((int)$data['closed'] === 1) {
    header("Location: community.php?id=".$community_id);
    exit;
}

// بررسی رأی قبلی این کاربر
$chk = $conn->prepare("SELECT idea_id FROM idea_votes WHERE user_id=? AND topic_id=?");
$chk->bind_param("ii",$user_id,$topic_id);
$chk->execute();
$old_vote = $chk->get_result()->fetch_assoc();
$chk->close();

// اگر قبلاً رأی داده → باید رأی قبلی حذف شود
if($old_vote){
    $old_idea = (int)$old_vote['idea_id'];

    // حذف رأی قبلی
    $del = $conn->prepare("DELETE FROM idea_votes WHERE user_id=? AND topic_id=?");
    $del->bind_param("ii",$user_id,$topic_id);
    $del->execute();
    $del->close();

    // کم کردن معقولیت ایده قبلی
    $dec = $conn->prepare("UPDATE ideas SET idea_rationality = idea_rationality - 1 WHERE id=? AND idea_rationality > 0");
    $dec->bind_param("i",$old_idea);
    $dec->execute();
    $dec->close();
}

// ثبت رأی جدید
$ins = $conn->prepare("INSERT INTO idea_votes (idea_id, topic_id, user_id) VALUES (?,?,?)");
$ins->bind_param("iii",$idea_id,$topic_id,$user_id);
$ins->execute();
$ins->close();

// افزایش معقولیت ایده جدید
$upd = $conn->prepare("UPDATE ideas SET idea_rationality = idea_rationality + 1 WHERE id=?");
$upd->bind_param("i",$idea_id);
$upd->execute();
$upd->close();

header("Location: community.php?id=".$community_id);
exit;
?>