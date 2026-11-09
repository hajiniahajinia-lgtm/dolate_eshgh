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

    // با آغاز دور رأی‌گیری و عضویت جدید، ترتیب قفل یکسان است.
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

    $delete = $conn->prepare("DELETE FROM community_members WHERE user_id=? AND community_id=?");
    if (!$delete) {
        throw new RuntimeException("prepare membership deletion failed");
    }
    $delete->bind_param("ii", $user_id, $community_id);
    if (!$delete->execute()) {
        throw new RuntimeException("membership deletion failed");
    }
    $removed = $delete->affected_rows;
    $delete->close();

    // فقط در صورت حذف واقعی عضویت، شمارنده کاهش می‌یابد.
    if ($removed > 0) {
        $count_update = $conn->prepare(
            "UPDATE communities SET members_count=GREATEST(members_count-?,0) WHERE id=?"
        );
        if (!$count_update) {
            throw new RuntimeException("prepare member count update failed");
        }
        $count_update->bind_param("ii", $removed, $community_id);
        if (!$count_update->execute()) {
            throw new RuntimeException("member count update failed");
        }
        $count_update->close();
    }

    if (!$conn->commit()) {
        throw new RuntimeException("commit membership transaction failed");
    }

    header("Location: profile.php?msg=left_community");
    exit;
} catch (Throwable $e) {
    $conn->rollback();
    error_log("Community leave failed: " . $e->getMessage());
    http_response_code(500);
    exit("خروج از عضویت انجام نشد. لطفاً دوباره تلاش کنید.");
}
?>