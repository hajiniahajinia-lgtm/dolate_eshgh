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

// اگر مهلت قبلاً تمام شده باشد، ابتدا نتیجه نهایی می‌شود.
community_removal_finalize_expired($conn, $community_id);

try {
    if (!$conn->begin_transaction()) throw new RuntimeException("begin removal vote transaction failed");

    // قفل مشترک با عضویت، خروج، اخراج و آغاز دور رأی‌گیری.
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

    $req = $conn->prepare(
        "SELECT status, (deadline_at > NOW()) AS is_before_deadline
         FROM community_member_removal_requests
         WHERE id=? AND community_id=? FOR UPDATE"
    );
    if (!$req) throw new RuntimeException("prepare removal request lookup failed");
    $req->bind_param("ii", $request_id, $community_id);
    if (!$req->execute()) throw new RuntimeException("read removal request failed");
    $request = $req->get_result()->fetch_assoc();
    $req->close();

    if (!$request || $request['status'] !== 'open' || (int)$request['is_before_deadline'] !== 1) {
        $conn->rollback();
        header("Location: community.php?id=" . $community_id . "&removal_msg=closed");
        exit;
    }

    $member = $conn->prepare("SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1");
    if (!$member) throw new RuntimeException("prepare voter membership check failed");
    $member->bind_param("ii", $community_id, $user_id);
    if (!$member->execute()) throw new RuntimeException("check voter membership failed");
    $is_member = (bool)$member->get_result()->fetch_row();
    $member->close();

    if (!$is_member) {
        $conn->rollback();
        header("Location: community.php?id=" . $community_id . "&removal_msg=membership");
        exit;
    }

    $save = $conn->prepare(
        "INSERT INTO community_member_removal_votes (request_id, user_id, vote)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE vote=VALUES(vote), updated_at=CURRENT_TIMESTAMP"
    );
    if (!$save) throw new RuntimeException("prepare removal vote save failed");
    $save->bind_param("iis", $request_id, $user_id, $vote);
    if (!$save->execute()) throw new RuntimeException("save removal vote failed");
    $save->close();

    if (!$conn->commit()) throw new RuntimeException("commit removal vote failed");
    header("Location: community.php?id=" . $community_id . "&removal_msg=voted");
    exit;
} catch (Throwable $e) {
    $conn->rollback();
    error_log("Community member removal vote failed: " . $e->getMessage());
    http_response_code(500);
    exit("ثبت رأی انجام نشد. لطفاً دوباره تلاش کنید.");
}
?>