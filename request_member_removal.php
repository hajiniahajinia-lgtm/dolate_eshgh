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

// درخواست‌های قبلی منقضی‌شده ابتدا نهایی می‌شوند.
community_removal_finalize_expired($conn, $community_id);

try {
    if (!$conn->begin_transaction()) throw new RuntimeException("begin removal request transaction failed");

    // snapshot اعضا با همان قفل مشترک عضویت و رأی‌گیری هماهنگ می‌شود.
    $community_lock = $conn->prepare("SELECT id FROM communities WHERE id=? FOR UPDATE");
    if (!$community_lock) throw new RuntimeException("prepare community lock failed");
    $community_lock->bind_param("i", $community_id);
    if (!$community_lock->execute()) throw new RuntimeException("lock community failed");
    $community_exists = (bool)$community_lock->get_result()->fetch_row();
    $community_lock->close();
    if (!$community_exists) {
        $conn->rollback();
        header("Location: community.php?id=" . $community_id . "&removal_msg=invalid");
        exit;
    }

    $member = $conn->prepare("SELECT COUNT(*) AS total FROM community_members WHERE community_id=?");
    if (!$member) throw new RuntimeException("prepare member count failed");
    $member->bind_param("i", $community_id);
    if (!$member->execute()) throw new RuntimeException("read member count failed");
    $member_count = (int)($member->get_result()->fetch_assoc()['total'] ?? 0);
    $member->close();

    $check = $conn->prepare("SELECT
        SUM(CASE WHEN user_id=? THEN 1 ELSE 0 END) AS requester_is_member,
        SUM(CASE WHEN user_id=? THEN 1 ELSE 0 END) AS target_is_member
        FROM community_members WHERE community_id=?");
    if (!$check) throw new RuntimeException("prepare membership check failed");
    $check->bind_param("iii", $user_id, $target_user_id, $community_id);
    if (!$check->execute()) throw new RuntimeException("check removal memberships failed");
    $membership = $check->get_result()->fetch_assoc() ?: [];
    $check->close();

    if ((int)($membership['requester_is_member'] ?? 0) < 1 ||
        (int)($membership['target_is_member'] ?? 0) < 1 || $member_count < 1) {
        $conn->rollback();
        header("Location: community.php?id=" . $community_id . "&removal_msg=membership");
        exit;
    }

    $open = $conn->prepare("SELECT id FROM community_member_removal_requests
        WHERE community_id=? AND target_user_id=? AND status='open' LIMIT 1 FOR UPDATE");
    if (!$open) throw new RuntimeException("prepare open removal lookup failed");
    $open->bind_param("ii", $community_id, $target_user_id);
    if (!$open->execute()) throw new RuntimeException("lookup open removal request failed");
    $already_open = $open->get_result()->fetch_assoc();
    $open->close();
    if ($already_open) {
        $conn->rollback();
        header("Location: community.php?id=" . $community_id . "&removal_msg=exists");
        exit;
    }

    $insert = $conn->prepare("INSERT INTO community_member_removal_requests
        (community_id, target_user_id, requester_user_id, member_count_snapshot, deadline_at)
        VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 3 DAY))");
    if (!$insert) throw new RuntimeException("prepare removal request insert failed");
    $insert->bind_param("iiii", $community_id, $target_user_id, $user_id, $member_count);
    if (!$insert->execute()) throw new RuntimeException("insert removal request failed");
    $insert->close();

    if (!$conn->commit()) throw new RuntimeException("commit removal request failed");
    header("Location: community.php?id=" . $community_id . "&removal_msg=created");
    exit;
} catch (Throwable $e) {
    $conn->rollback();
    error_log("Community member removal request failed: " . $e->getMessage());
    http_response_code(500);
    exit("ثبت درخواست انجام نشد. لطفاً دوباره تلاش کنید.");
}
?>