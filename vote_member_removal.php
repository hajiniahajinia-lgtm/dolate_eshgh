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
$request_id = (int)($_POST['request_id'] ?? 0);
$community_id = (int)($_POST['community_id'] ?? 0);
$vote = (string)($_POST['vote'] ?? '');
$token = (string)($_POST['csrf_token'] ?? '');

if (empty($_SESSION['community_duty_csrf']) || !hash_equals($_SESSION['community_duty_csrf'], $token)) {
    http_response_code(403);
    exit('درخواست از نظر امنیتی معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.');
}
if ($request_id <= 0 || $community_id <= 0 || !in_array($vote, ['yes', 'no'], true)) {
    header("Location: community.php?id=" . max(0, $community_id) . "&removal_msg=invalid");
    exit;
}
if (!community_removal_tables_ready($conn)) {
    header("Location: community.php?id=" . $community_id . "&removal_msg=setup");
    exit;
}
community_removal_finalize_expired($conn, $community_id);

$req = $conn->prepare("SELECT id, deadline_at, status FROM community_member_removal_requests WHERE id=? AND community_id=?");
$req->bind_param("ii", $request_id, $community_id);
$req->execute();
$request = $req->get_result()->fetch_assoc();
$req->close();
if (!$request || $request['status'] !== 'open' || strtotime($request['deadline_at']) <= time()) {
    header("Location: community.php?id=" . $community_id . "&removal_msg=closed");
    exit;
}
$member = $conn->prepare("SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1");
$member->bind_param("ii", $community_id, $user_id);
$member->execute();
$is_member = (bool)$member->get_result()->fetch_row();
$member->close();
if (!$is_member) {
    header("Location: community.php?id=" . $community_id . "&removal_msg=membership");
    exit;
}
$save = $conn->prepare("INSERT INTO community_member_removal_votes (request_id, user_id, vote)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE vote=VALUES(vote), updated_at=CURRENT_TIMESTAMP");
$save->bind_param("iis", $request_id, $user_id, $vote);
if (!$save->execute()) {
    $save->close();
    header("Location: community.php?id=" . $community_id . "&removal_msg=error");
    exit;
}
$save->close();
header("Location: community.php?id=" . $community_id . "&removal_msg=voted");
exit;
?>