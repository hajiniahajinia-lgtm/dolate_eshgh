<?php
session_start();
require_once __DIR__ . "/db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit("درخواست نامعتبر است.");
}

$csrf_token = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['community_duty_csrf']) || $csrf_token === '' ||
    !hash_equals($_SESSION['community_duty_csrf'], $csrf_token)) {
    http_response_code(403);
    exit("درخواست معتبر نیست یا نشست شما منقضی شده است. لطفاً صفحه را تازه‌سازی کنید.");
}

$user_id = (int)$_SESSION['user_id'];
$feedback_id = filter_var($_POST['feedback_id'] ?? null, FILTER_VALIDATE_INT);
$idea_id = filter_var($_POST['idea_id'] ?? null, FILTER_VALIDATE_INT);
$topic_id = filter_var($_POST['topic_id'] ?? null, FILTER_VALIDATE_INT);
$community_id = filter_var($_POST['community_id'] ?? null, FILTER_VALIDATE_INT);
$review_status = (string)($_POST['review_status'] ?? '');

if (!$feedback_id || !$idea_id || !$topic_id || !$community_id ||
    !in_array($review_status, ['pending', 'accepted', 'rejected'], true)) {
    http_response_code(400);
    exit("اطلاعات ارسالی معتبر نیست.");
}

$member = $conn->prepare("SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1");
$member->bind_param("ii", $community_id, $user_id);
$member->execute();
$is_member = (bool)$member->get_result()->fetch_row();
$member->close();
if (!$is_member) {
    http_response_code(403);
    exit("فقط اعضای جامعه می‌توانند این کار را انجام دهند.");
}

$check = $conn->prepare("
    SELECT f.id
    FROM community_idea_feedback f
    JOIN ideas i ON i.id=f.idea_id
    JOIN topics t ON t.id=i.topic_id
    WHERE f.id=? AND f.idea_id=? AND f.topic_id=? AND f.community_id=?
      AND i.user_id=? AND t.id=? AND t.community_id=? AND t.closed=0
    LIMIT 1
");
$check->bind_param("iiiiiii", $feedback_id, $idea_id, $topic_id, $community_id, $user_id, $topic_id, $community_id);
$check->execute();
$authorized = (bool)$check->get_result()->fetch_row();
$check->close();

if (!$authorized) {
    http_response_code(403);
    exit("فقط صاحب ایده می‌تواند وضعیت پیشنهادهای مربوط به ایدهٔ خود را در موضوع باز تغییر دهد.");
}

$update = $conn->prepare("
    UPDATE community_idea_feedback
    SET review_status=?
    WHERE id=? AND idea_id=? AND topic_id=? AND community_id=?
    LIMIT 1
");
$update->bind_param("siiii", $review_status, $feedback_id, $idea_id, $topic_id, $community_id);
if (!$update->execute()) {
    error_log("[Dolate Eshgh] Failed to update idea feedback status: " . $update->error);
    http_response_code(500);
    exit("ثبت وضعیت انجام نشد. لطفاً دوباره تلاش کنید.");
}
$update->close();
$conn->close();

header("Location: community.php?id=" . $community_id . "&open_topic=" . $topic_id . "#community-idea-feedback-item-" . $feedback_id);
exit;
