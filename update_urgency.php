<?php
session_start();
include __DIR__ . "/db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// فقط POST و درخواست دارای توکن معتبر پذیرفته می‌شود
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

$user_id = (int)$_SESSION['user_id'];

// دریافت و اعتبارسنجی داده‌های فرم
$topic_id = isset($_POST['topic_id']) ? (int)$_POST['topic_id'] : 0;
$community_id = isset($_POST['community_id']) ? (int)$_POST['community_id'] : 0;
$urgency_units = isset($_POST['urgency_hours']) ? (int)$_POST['urgency_hours'] : 1;
$urgency_units = max(1, min(1000, $urgency_units));

if ($topic_id <= 0 || $community_id <= 0) {
    http_response_code(400);
    exit("موضوع یا جامعه نامعتبر است.");
}

// ثبت فوریت کاربر با نام ستون واقعی جدول هاست: urgency_units
$save_vote = $conn->prepare("
    INSERT INTO topic_urgency_votes (topic_id, user_id, urgency_units)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE urgency_units = VALUES(urgency_units)
");
$save_vote->bind_param("iii", $topic_id, $user_id, $urgency_units);
$save_vote->execute();
$save_vote->close();

// محاسبه میانگین فوریت ثبت‌شده توسط اعضا (واحد: ساعت؛ ذخیره در ستون urgency_units)
$avg_sql = $conn->prepare("
    SELECT AVG(urgency_units) AS avg_urgency
    FROM topic_urgency_votes
    WHERE topic_id = ?
");
$avg_sql->bind_param("i", $topic_id);
$avg_sql->execute();
$avg_result = $avg_sql->get_result()->fetch_assoc();
$avg_sql->close();

$avg_urgency_units = max(1, min(1000, (int)round((float)($avg_result['avg_urgency'] ?? $urgency_units))));

// ذخیرهٔ میانگین ساعت‌ها و زمان پایان متناظر با زمان ایجاد موضوع.
$urgency_minutes = $avg_urgency_units * 60;
$update_topic = $conn->prepare("
    UPDATE topics
    SET urgency = ?, urgency_minutes = ?, deadline = DATE_ADD(created_at, INTERVAL ? MINUTE)
    WHERE id = ? AND community_id = ?
");
$update_topic->bind_param("iiiii", $avg_urgency_units, $urgency_minutes, $urgency_minutes, $topic_id, $community_id);
$update_topic->execute();
$updated = $update_topic->affected_rows;
$update_topic->close();

// محاسبه زمان باقی‌مانده برای به‌روزرسانی فوری شمارشگر در صفحه
$created_sql = $conn->prepare("
    SELECT UNIX_TIMESTAMP(created_at) AS created_ts
    FROM topics
    WHERE id = ? AND community_id = ?
    LIMIT 1
");
$created_sql->bind_param("ii", $topic_id, $community_id);
$created_sql->execute();
$created_row = $created_sql->get_result()->fetch_assoc();
$created_sql->close();

$created_ts = (int)($created_row['created_ts'] ?? time());
$remaining_seconds = max(0, $created_ts + ($avg_urgency_units * 3600) - time());

// تعداد پیشنهادهای فوریت برای نمایش نتیجه، بدون بارگذاری دوباره صفحه
$count_sql = $conn->prepare("SELECT COUNT(*) AS vote_count FROM topic_urgency_votes WHERE topic_id = ?");
$count_sql->bind_param("i", $topic_id);
$count_sql->execute();
$vote_count = (int)($count_sql->get_result()->fetch_assoc()['vote_count'] ?? 0);
$count_sql->close();

// درخواست AJAX پاسخ کوتاه می‌گیرد تا نیازی به دریافت کل صفحه نباشد.
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'urgency_units' => $avg_urgency_units,
        'vote_count' => $vote_count,
        'remaining_seconds' => $remaining_seconds
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// بازگشت به جامعه انتخاب‌شده برای فرم معمولی
header("Location: community.php?id=" . $community_id);
exit;
