<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// فقط درخواست POST با توکن CSRF معتبر پذیرفته می‌شود.
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