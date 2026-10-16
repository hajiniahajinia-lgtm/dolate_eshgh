<?php
session_start();
include "db.php";

if (!isset($_GET['topic_id'], $_GET['community_id'])) exit("❌ داده نامعتبر");

$topic_id = (int)$_GET['topic_id'];
$community_id = (int)$_GET['community_id'];

$conn->query("UPDATE topics SET reasonability = reasonability + 1 WHERE id = $topic_id");

header("Location: community.php?id=".$community_id);
exit;
?>