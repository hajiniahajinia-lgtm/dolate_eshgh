<?php
session_start();
include __DIR__ . "/db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$topic_id = isset($_POST['topic_id']) ? (int)$_POST['topic_id'] : 0;
$urgency_minutes = isset($_POST['urgency_minutes']) ? (int)$_POST['urgency_minutes'] : 0;

if($topic_id <= 0 || $urgency_minutes <= 0){
    die("خطا: شناسه موضوع یا فوریت نامعتبر است.");
}

// بررسی اینکه موضوع مربوط به این جامعه و آماده رأی‌گیری باشد
$check_stmt = $conn->prepare("SELECT id FROM topics WHERE id=? AND closed=1");
if(!$check_stmt){
    die("خطا در prepare: " . $conn->error);
}
$check_stmt->bind_param("i",$topic_id);
$check_stmt->execute();
$check_res = $check_stmt->get_result();
if($check_res->num_rows == 0){
    die("موضوع یافت نشد یا هنوز آماده رأی‌گیری نیست.");
}
$check_stmt->close();

// به‌روزرسانی فوریت موضوع
$update_stmt = $conn->prepare("UPDATE topics SET urgency=? WHERE id=?");
if(!$update_stmt){
    die("خطا در prepare: " . $conn->error);
}
$update_stmt->bind_param("ii", $urgency_minutes, $topic_id);
$update_stmt->execute();
$update_stmt->close();

// بازگشت به صفحه votes.php
$redirect = isset($_POST['redirect']) ? $_POST['redirect'] : "votes.php";
header("Location: $redirect");
exit;
?>