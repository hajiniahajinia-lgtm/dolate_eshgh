<?php
session_start();
include __DIR__ . "/db.php";

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit("برای رأی‌دادن ابتدا وارد شوید.");
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit("درخواست نامعتبر است.");
}
$user_id = (int)$_SESSION['user_id'];
$csrf = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['community_duty_csrf']) || $csrf === '' ||
    !hash_equals($_SESSION['community_duty_csrf'], $csrf)) {
    http_response_code(403);
    exit("نشست شما معتبر نیست؛ صفحه را تازه‌سازی کنید.");
}
$proposal_id = (int)($_POST['proposal_id'] ?? 0);
$community_id = (int)($_POST['community_id'] ?? 0);
$vote = (string)($_POST['vote'] ?? '');
if ($proposal_id < 1 || $community_id < 1 || !in_array($vote, ['yes','no'], true)) {
    http_response_code(400);
    exit("اطلاعات رأی معتبر نیست.");
}

$member = $conn->prepare("SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1");
$member->bind_param("ii", $community_id, $user_id);
$member->execute();
$is_member = (bool)$member->get_result()->fetch_row();
$member->close();
if (!$is_member) {
    http_response_code(403);
    exit("فقط اعضای جامعه می‌توانند رأی بدهند.");
}

$conn->begin_transaction();
try {
    $lock = $conn->prepare("SELECT id, status, expires_at FROM topic_expertise_proposals WHERE id=? AND community_id=? FOR UPDATE");
    $lock->bind_param("ii", $proposal_id, $community_id);
    $lock->execute();
    $proposal = $lock->get_result()->fetch_assoc();
    $lock->close();
    if (!$proposal || $proposal['status'] !== 'pending' || empty($proposal['expires_at']) || strtotime($proposal['expires_at']) <= time()) {
        throw new RuntimeException("مهلت رأی‌گیری پایان یافته یا این پیشنهاد دیگر باز نیست.");
    }

    $save = $conn->prepare("INSERT INTO topic_expertise_proposal_votes (proposal_id,user_id,vote) VALUES (?,?,?) ON DUPLICATE KEY UPDATE vote=VALUES(vote), updated_at=CURRENT_TIMESTAMP");
    $save->bind_param("iis", $proposal_id, $user_id, $vote);
    if (!$save->execute()) throw new RuntimeException("ثبت رأی انجام نشد.");
    $save->close();
    $conn->commit();
    header("Location: community.php?id=" . $community_id . "#topic-expertise-proposals");
    exit;
} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(400);
    exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
