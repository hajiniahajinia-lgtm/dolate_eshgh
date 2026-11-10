<?php
session_start();
require __DIR__ . "/db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit("درخواست نامعتبر است.");
}

if (
    empty($_SESSION['community_duty_csrf']) ||
    !isset($_POST['csrf_token']) ||
    !is_string($_POST['csrf_token']) ||
    !hash_equals($_SESSION['community_duty_csrf'], $_POST['csrf_token'])
) {
    http_response_code(403);
    exit("درخواست معتبر نیست. لطفاً صفحه را تازه‌سازی کنید و دوباره تلاش کنید.");
}

$community_id = (int)($_POST['community_id'] ?? 0);
if ($community_id <= 0) {
    http_response_code(400);
    exit("شناسه جامعه نامعتبر است.");
}

try {
    if (!$conn->begin_transaction()) {
        throw new RuntimeException("begin membership transaction failed");
    }

    // همان ردیف جامعه‌ای که آغاز دور رأی‌گیری قفل می‌کند، اینجا نیز قفل می‌شود.
    // در نتیجه snapshot اعضا با عضویت/خروج هم‌زمان تداخل پیدا نمی‌کند.
    $community_lock = $conn->prepare("SELECT id FROM communities WHERE id=? FOR UPDATE");
    if (!$community_lock) {
        throw new RuntimeException("prepare community lock failed");
    }
    $community_lock->bind_param("i", $community_id);
    if (!$community_lock->execute()) {
        throw new RuntimeException("lock community failed");
    }
    $community_exists = (bool)$community_lock->get_result()->fetch_row();
    $community_lock->close();

    if (!$community_exists) {
        throw new DomainException("community not found");
    }

    $check = $conn->prepare("SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1");
    if (!$check) {
        throw new RuntimeException("prepare membership lookup failed");
    }
    $check->bind_param("ii", $community_id, $user_id);
    if (!$check->execute()) {
        throw new RuntimeException("membership lookup failed");
    }
    $already_member = (bool)$check->get_result()->fetch_row();
    $check->close();

    if ($already_member) {
        $conn->rollback();
        header("Location: profile.php?msg=already_member");
        exit;
    }

    $insert = $conn->prepare("INSERT INTO community_members (community_id, user_id) VALUES (?, ?)");
    if (!$insert) {
        throw new RuntimeException("prepare membership insert failed");
    }
    $insert->bind_param("ii", $community_id, $user_id);
    if (!$insert->execute()) {
        throw new RuntimeException("membership insert failed");
    }
    $insert->close();

    $count_update = $conn->prepare("UPDATE communities SET members_count=members_count+1 WHERE id=?");
    if (!$count_update) {
        throw new RuntimeException("prepare member count update failed");
    }
    $count_update->bind_param("i", $community_id);
    if (!$count_update->execute() || $count_update->affected_rows !== 1) {
        throw new RuntimeException("member count update failed");
    }
    $count_update->close();

    if (!$conn->commit()) {
        throw new RuntimeException("commit membership transaction failed");
    }

    header("Location: profile.php?msg=joined_community");
    exit;
} catch (Throwable $e) {
    $conn->rollback();
    error_log("Community join failed: " . $e->getMessage());
    http_response_code(500);
    exit("عضویت انجام نشد. لطفاً دوباره تلاش کنید.");
}
?>