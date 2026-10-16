<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$community_id = isset($_POST['community_id']) ? (int)$_POST['community_id'] : 0;

if ($community_id > 0) {
    $stmt = $conn->prepare("DELETE FROM community_members WHERE user_id=? AND community_id=?");
    $stmt->bind_param("ii", $user_id, $community_id);
    $stmt->execute();
    $stmt->close();

    // کاهش شمار اعضا
    $conn->query("UPDATE communities SET members_count = GREATEST(members_count-1,0) WHERE id=$community_id");
}

header("Location: profile.php");
exit;
?>