<?php
session_start();
include __DIR__ . "/db.php";

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit("برای ثبت ایده ابتدا وارد شوید.");
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit("درخواست نامعتبر است.");
}
$csrf = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['community_duty_csrf']) || $csrf === '' ||
    !hash_equals($_SESSION['community_duty_csrf'], $csrf)) {
    http_response_code(403);
    exit("درخواست معتبر نیست. صفحه را تازه‌سازی کنید.");
}

$user_id = (int)$_SESSION['user_id'];
$topic_id = (int)($_POST['topic_id'] ?? 0);
$community_id = (int)($_POST['community_id'] ?? 0);
$content = trim((string)($_POST['idea_text'] ?? ''));

if ($topic_id < 1 || $community_id < 1 || $content === '') {
    http_response_code(400);
    exit("موضوع، جامعه و متن ایده الزامی هستند.");
}
$idea_length = function_exists('mb_strlen')
    ? mb_strlen($content, 'UTF-8')
    : (preg_match_all('/./us', $content, $matches) ?: 0);
if ($idea_length > 25000) {
    http_response_code(400);
    exit("متن ایده بیش از حد مجاز است؛ حداکثر ۲۵٬۰۰۰ نویسه وارد کنید.");
}

// بررسی هم‌زمان تعلق موضوع به جامعه، بازبودن موضوع و عضویت ارسال‌کننده.
$check = $conn->prepare("
    SELECT t.id
    FROM topics t
    JOIN community_members cm ON cm.community_id=t.community_id AND cm.user_id=?
    WHERE t.id=? AND t.community_id=? AND t.closed=0
    LIMIT 1
");
if (!$check) {
    http_response_code(500);
    exit("امکان بررسی موضوع و عضویت وجود ندارد.");
}
$check->bind_param("iii", $user_id, $topic_id, $community_id);
$check->execute();
$valid = (bool)$check->get_result()->fetch_row();
$check->close();
if (!$valid) {
    http_response_code(403);
    exit("موضوع در این جامعه باز نیست یا شما عضو جامعه نیستید.");
}

$stmt = $conn->prepare("INSERT INTO ideas (topic_id, user_id, content) VALUES (?, ?, ?)");
if (!$stmt) {
    http_response_code(500);
    exit("امکان آماده‌سازی ثبت ایده وجود ندارد.");
}
$stmt->bind_param("iis", $topic_id, $user_id, $content);
if (!$stmt->execute()) {
    error_log("add_idea insert failed: " . $stmt->error);
    $stmt->close();
    http_response_code(500);
    exit("ثبت ایده انجام نشد. لطفاً دوباره تلاش کنید.");
}
$stmt->close();
$conn->close();
header("Location: community.php?id=" . $community_id . "&success_idea=1");
exit;
?>