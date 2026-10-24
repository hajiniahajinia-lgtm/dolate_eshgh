<?php
session_start();
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/community_member_removal_helpers.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: profile.php");
    exit;
}
$user_id = (int)$_SESSION['user_id'];
$community_id = (int)($_POST['community_id'] ?? 0);
$target_user_id = (int)($_POST['target_user_id'] ?? 0);
$token = (string)($_POST['csrf_token'] ?? '');

if (empty($_SESSION['community_duty_csrf']) || !hash_equals($_SESSION['community_duty_csrf'], $token)) {
    http_response_code(403);
    exit('درخواست از نظر امنیتی معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.');
}
if ($community_id <= 0 || $target_user_id <= 0 || $target_user_id === $user_id) {
    header("Location: community.php?id=" . max(0, $community_id) . "&removal_msg=invalid");
    exit;
}
if (!community_removal_tables_ready($conn)) {
    header("Location: community.php?id=" . $community_id . "&removal_msg=setup");
    exit;
}
community_removal_finalize_expired($conn, $community_id);

$member = $conn->prepare("SELECT COUNT(*) AS total FROM community_members WHERE community_id=?");
$member->bind_param("i", $community_id);
$member->execute();
$member_count = (int)($member->get_result()->fetch_assoc()['total'] ?? 0);
$member->close();

$check = $conn->prepare("SELECT
    SUM(CASE WHEN user_id=? THEN 1 ELSE 0 END) AS requester_is_member,
    SUM(CASE WHEN user_id=? THEN 1 ELSE 0 END) AS target_is_member
    FROM community_members WHERE community_id=?");
$check->bind_param("iii", $user_id, $target_user_id, $community_id);
$check->execute();
$membership = $check->get_result()->fetch_assoc() ?: [];
$check->close();
if ((int)($membership['requester_is_member'] ?? 0) < 1 || (int)($membership['target_is_member'] ?? 0) < 1) {
    header("Location: community.php?id=" . $community_id . "&removal_msg=membership");
    exit;
}
$open = $conn->prepare("SELECT id FROM community_member_removal_requests WHERE community_id=? AND target_user_id=? AND status='open' LIMIT 1");
$open->bind_param("ii", $community_id, $target_user_id);
$open->execute();
$already_open = $open->get_result()->fetch_assoc();
$open->close();
if ($already_open) {
    header("Location: community.php?id=" . $community_id . "&removal_msg=exists");
    exit;
}
if ($member_count < 1) {
    header("Location: community.php?id=" . $community_id . "&removal_msg=invalid");
    exit;
}
$deadline = date('Y-m-d H:i:s', time() + 3 * 24 * 60 * 60);
$insert = $conn->prepare("INSERT INTO community_member_removal_requests
    (community_id, target_user_id, requester_user_id, member_count_snapshot, deadline_at)
    VALUES (?, ?, ?, ?, ?)");
$insert->bind_param("iiiis", $community_id, $target_user_id, $user_id, $member_count, $deadline);
if (!$insert->execute()) {
    $insert->close();
    header("Location: community.php?id=" . $community_id . "&removal_msg=error");
    exit;
}
$insert->close();
header("Location: community.php?id=" . $community_id . "&removal_msg=created");
exit;
?>