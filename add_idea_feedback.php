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
$idea_id = (int)($_POST['idea_id'] ?? 0);
$topic_id = (int)($_POST['topic_id'] ?? 0);
$community_id = (int)($_POST['community_id'] ?? 0);
$content = trim((string)($_POST['feedback_text'] ?? ''));

if ($idea_id < 1 || $topic_id < 1 || $community_id < 1 || $content === '') {
    http_response_code(400);
    exit("لطفاً متن پیشنهاد یا نظر را وارد کنید.");
}
if (function_exists('mb_strlen')) {
    $content_length = mb_strlen($content, 'UTF-8');
} else {
    preg_match_all('/./us', $content, $characters);
    $content_length = count($characters[0]);
}
if ($content_length > 5000) {
    http_response_code(400);
    exit("متن پیشنهاد یا نظر حداکثر می‌تواند ۵۰۰۰ نویسه باشد.");
}

$member = $conn->prepare("SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1");
$member->bind_param("ii", $community_id, $user_id);
$member->execute();
$is_member = (bool)$member->get_result()->fetch_row();
$member->close();
if (!$is_member) {
    http_response_code(403);
    exit("فقط اعضای جامعه می‌توانند درباره ایده‌ها نظر بدهند.");
}

$idea = $conn->prepare("SELECT i.id FROM ideas i JOIN topics t ON t.id=i.topic_id WHERE i.id=? AND t.id=? AND t.community_id=? AND t.closed=0 LIMIT 1");
$idea->bind_param("iii", $idea_id, $topic_id, $community_id);
$idea->execute();
$idea_exists = (bool)$idea->get_result()->fetch_row();
$idea->close();
if (!$idea_exists) {
    http_response_code(404);
    exit("ایده پیدا نشد یا موضوع آن بسته شده است.");
}

$insert = $conn->prepare("INSERT INTO community_idea_feedback (idea_id, topic_id, community_id, user_id, content) VALUES (?, ?, ?, ?, ?)");
$insert->bind_param("iiiis", $idea_id, $topic_id, $community_id, $user_id, $content);
if (!$insert->execute()) {
    error_log("[Dolate Eshgh] Failed to save idea feedback: " . $insert->error);
    http_response_code(500);
    exit("ذخیره پیشنهاد انجام نشد. لطفاً دوباره تلاش کنید.");
}
$insert->close();
$conn->close();

header("Location: community.php?id=" . $community_id . "&open_topic=" . $topic_id . "#community-idea-feedback-" . $idea_id);
exit;
