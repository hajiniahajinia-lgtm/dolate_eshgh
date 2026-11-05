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

if ($community_id <= 0) {
    http_response_code(400);
    exit("شناسهٔ جامعه معتبر نیست.");
}

try {
    $conn->begin_transaction();
    $stmt = $conn->prepare("DELETE FROM community_members WHERE user_id=? AND community_id=?");
    if (!$stmt) {
        throw new RuntimeException("Leave-community prepare failed: " . $conn->error);
    }
    $stmt->bind_param("ii", $user_id, $community_id);
    if (!$stmt->execute()) {
        throw new RuntimeException("Leave-community delete failed: " . $stmt->error);
    }
    $removed = $stmt->affected_rows;
    $stmt->close();

    // شمار اعضا فقط وقتی کاهش می‌یابد که عضویت واقعاً حذف شده باشد.
    if ($removed > 0) {
        $count = $conn->prepare("UPDATE communities SET members_count = GREATEST(members_count-1,0) WHERE id=?");
        if (!$count) {
            throw new RuntimeException("Member-count update prepare failed: " . $conn->error);
        }
        $count->bind_param("i", $community_id);
        if (!$count->execute()) {
            throw new RuntimeException("Member-count update failed: " . $count->error);
        }
        $count->close();
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log("leave_community failed: " . $e->getMessage());
    http_response_code(500);
    exit("خروج از جامعه انجام نشد. لطفاً دوباره تلاش کنید.");
}

header("Location: profile.php");
exit;
?>