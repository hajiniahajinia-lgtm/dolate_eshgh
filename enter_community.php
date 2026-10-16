<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['community_id'])) {

    $community_id = intval($_POST['community_id']);
    $_SESSION['community_id'] = $community_id;

    header("Location: community.php?id=" . $community_id);
    exit;
}

header("Location: profile.php?error=no_community");
exit;