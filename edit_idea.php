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
$community_id = (int)($_POST['community_id'] ?? 0);
$content = trim((string)($_POST['idea_text'] ?? ''));
$revision_note = trim((string)($_POST['revision_note'] ?? ''));

$length = function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content);
$note_length = function_exists('mb_strlen') ? mb_strlen($revision_note, 'UTF-8') : strlen($revision_note);
if ($idea_id < 1 || $community_id < 1 || $content === '') {
    http_response_code(400);
    exit("لطفاً متن ایده را وارد کنید.");
}
if ($length > 25000 || $note_length > 1000) {
    http_response_code(400);
    exit("متن ایده حداکثر ۲۵٬۰۰۰ نویسه و دلیل اصلاح حداکثر ۱٬۰۰۰ نویسه است.");
}

$member = $conn->prepare("SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1");
$member->bind_param("ii", $community_id, $user_id);
$member->execute();
$is_member = (bool)$member->get_result()->fetch_row();
$member->close();
if (!$is_member) {
    http_response_code(403);
    exit("فقط اعضای جامعه می‌توانند ایده را اصلاح کنند.");
}

$idea_q = $conn->prepare("
    SELECT i.content, i.topic_id, t.closed
    FROM ideas i
    JOIN topics t ON t.id=i.topic_id
    WHERE i.id=? AND i.user_id=? AND t.community_id=?
    LIMIT 1
");
$idea_q->bind_param("iii", $idea_id, $user_id, $community_id);
$idea_q->execute();
$idea = $idea_q->get_result()->fetch_assoc();
$idea_q->close();
if (!$idea) {
    http_response_code(404);
    exit("ایده پیدا نشد یا اجازهٔ اصلاح آن را ندارید.");
}
if ((int)$idea['closed'] !== 0) {
    http_response_code(400);
    exit("پس از بسته‌شدن موضوع، ایده قابل اصلاح نیست.");
}
if ($content === $idea['content']) {
    header("Location: community.php?id=" . $community_id . "&open_topic=" . (int)$idea['topic_id'] . "#topic-" . (int)$idea['topic_id']);
    exit;
}

try {
    $conn->begin_transaction();
    $revision = $conn->prepare("
        INSERT INTO community_idea_revisions (idea_id, editor_user_id, previous_content, revision_note)
        VALUES (?, ?, ?, ?)
    ");
    $revision->bind_param("iiss", $idea_id, $user_id, $idea['content'], $revision_note);
    if (!$revision->execute()) {
        throw new RuntimeException("revision insert failed");
    }
    $revision->close();

    $update = $conn->prepare("UPDATE ideas SET content=? WHERE id=? AND user_id=?");
    $update->bind_param("sii", $content, $idea_id, $user_id);
    if (!$update->execute()) {
        throw new RuntimeException("idea update failed");
    }
    $update->close();
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log("[Dolate Eshgh] Failed to revise idea: " . $e->getMessage());
    http_response_code(500);
    exit("اصلاح ایده ذخیره نشد. لطفاً دوباره تلاش کنید.");
}

$conn->close();
header("Location: community.php?id=" . $community_id . "&open_topic=" . (int)$idea['topic_id'] . "#topic-" . (int)$idea['topic_id']);
exit;
