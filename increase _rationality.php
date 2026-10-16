<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    exit("دسترسی غیر مجاز!");
}

if (!isset($_GET['topic_id'])) {
    exit("خطا: موضوع مشخص نیست!");
}

$topic_id = (int)$_GET['topic_id'];

// افزایش معقولیت موضوع
$stmt = $conn->prepare("UPDATE topics SET rationality = rationality + 1 WHERE id = ?");
$stmt->bind_param("i", $topic_id);
$stmt->execute();
$stmt->close();

// بازگشت به صفحه جامعه
header("Location: community.php?id=" . $_SESSION['community_id']);
exit;