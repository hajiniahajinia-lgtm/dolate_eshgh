<?php
// toggle_priority.php — نسخهٔ دیباگ شده و سالم
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

session_start();
include __DIR__ . "/db.php"; // مطمئن شو مسیر درست است

// اگر کاربر لاگین نکرده؛ به صفحه جامعه برگردان (اگر community_id ارسال شده باشد)
if (!isset($_SESSION['user_id'])) {
    $cid = isset($_POST['community_id']) ? (int)$_POST['community_id'] : 0;
    header("Location: community.php?id=" . $cid);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// فقط POST را قبول کن
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit("درخواست نامعتبر است.");
}

// بررسی توکن CSRF برای جلوگیری از ثبت یا لغو ناخواستهٔ اولویت
if (
    empty($_SESSION['community_duty_csrf']) ||
    !isset($_POST['csrf_token']) ||
    !is_string($_POST['csrf_token']) ||
    !hash_equals($_SESSION['community_duty_csrf'], $_POST['csrf_token'])
) {
    http_response_code(403);
    exit("درخواست معتبر نیست. لطفاً صفحه را تازه‌سازی کنید و دوباره تلاش کنید.");
}

// دریافت پارامترها
$topic_id = isset($_POST['topic_id']) ? (int)$_POST['topic_id'] : 0;
$community_id = isset($_POST['community_id']) ? (int)$_POST['community_id'] : 0;

// اعتبارسنجی اولیه
if ($topic_id <= 0 || $community_id <= 0) {
    // برای دیباگ، خطای کامل را لاگ کن
    error_log("toggle_priority: پارامتر ناقص topic_id={$topic_id} community_id={$community_id}");
    http_response_code(400);
    exit("اطلاعات ارسالی معتبر نیست.");
}

// بررسی وجود موضوع و اینکه باز است
$stmt = $conn->prepare("SELECT id, community_id, closed FROM topics WHERE id=? LIMIT 1");
if (!$stmt) { error_log("toggle_priority topic prepare error: " . $conn->error); http_response_code(500); exit("خطای پایگاه‌داده. لطفاً بعداً دوباره تلاش کنید."); }
$stmt->bind_param("i", $topic_id);
$stmt->execute();
$topic = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$topic) {
    http_response_code(404);
    exit("موضوع مورد نظر پیدا نشد.");
}

// جامعهٔ ارسالی باید دقیقاً همان جامعهٔ متعلق به موضوع باشد.
if ((int)$topic['community_id'] !== $community_id) {
    http_response_code(403);
    exit("جامعهٔ ارسالی با جامعهٔ این موضوع مطابقت ندارد.");
}

// فقط اعضای همان جامعه اجازهٔ ثبت یا لغو اولویت دارند.
$member_check = $conn->prepare(
    "SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1"
);
if (!$member_check) {
    error_log("toggle_priority membership prepare error: " . $conn->error);
    http_response_code(500);
    exit("خطای پایگاه‌داده. لطفاً بعداً دوباره تلاش کنید.");
}
$member_check->bind_param("ii", $community_id, $user_id);
$member_check->execute();
$is_member = (bool)$member_check->get_result()->fetch_row();
$member_check->close();

if (!$is_member) {
    http_response_code(403);
    exit("فقط اعضای این جامعه می‌توانند اولویت موضوع را ثبت یا لغو کنند.");
}

if ((int)$topic['closed'] === 1) {
    http_response_code(400);
    exit("این موضوع بسته شده و امکان اولویت‌دهی وجود ندارد.");
}

// نام جدول رأی‌ها: از نام یکنواخت استفاده کن
// در این پروژه ما از نام topic_priority_votes استفاده می‌کنیم.
// اگر جدول تو فرق دارد، پایین SQL ساخت را ببین و جدول را مطابق کن.

// آیا کاربر قبلاً رأی داده؟
$check = $conn->prepare("SELECT 1 FROM topic_priority_votes WHERE topic_id=? AND user_id=? LIMIT 1");
if (!$check) { error_log("toggle_priority check prepare error: " . $conn->error); http_response_code(500); exit("خطای پایگاه‌داده. لطفاً بعداً دوباره تلاش کنید."); }
$check->bind_param("ii", $topic_id, $user_id);
$check->execute();
$has = $check->get_result()->fetch_row();
$check->close();

if ($has) {
    // حذف رأی (toggle off)
    $del = $conn->prepare("DELETE FROM topic_priority_votes WHERE topic_id=? AND user_id=?");
    if (!$del) { error_log("toggle_priority del prepare error: " . $conn->error); die("خطای پایگاه‌داده هنگام حذف رأی."); }
    $del->bind_param("ii", $topic_id, $user_id);
    $del->execute();
    $del->close();
} else {
    // درج رأی (toggle on)
    $ins = $conn->prepare("INSERT INTO topic_priority_votes (topic_id, user_id, created_at) VALUES (?, ?, NOW())");
    if (!$ins) { error_log("toggle_priority insert prepare error: " . $conn->error); die("خطای پایگاه‌داده هنگام ثبت رأی."); }
    $ins->bind_param("ii", $topic_id, $user_id);
    // اگر کلید تکراری ممکن است، می‌توانیم از INSERT IGNORE استفاده کنیم یا ابتدا چک کنیم (ما بالا چک کردیم)
    $ins->execute();
    $ins->close();
}

// شمردن کل رأی‌ها و به‌روزرسانی فیلد priority در topics
$count = $conn->prepare("SELECT COUNT(*) AS total FROM topic_priority_votes WHERE topic_id=?");
if (!$count) { error_log("toggle_priority count prepare error: " . $conn->error); die("خطای پایگاه‌داده هنگام شمارش رأی‌ها."); }
$count->bind_param("i", $topic_id);
$count->execute();
$r = $count->get_result()->fetch_assoc();
$count->close();

$total_votes = isset($r['total']) ? (int)$r['total'] : 0;

$upd = $conn->prepare("UPDATE topics SET priority=? WHERE id=?");
if (!$upd) { error_log("toggle_priority update prepare error: " . $conn->error); die("خطای پایگاه‌داده هنگام به‌روزرسانی اولویت."); }
$upd->bind_param("ii", $total_votes, $topic_id);
$upd->execute();
$upd->close();

// پاسخ کوتاه برای درخواست AJAX؛ فرم معمولی همچنان به صفحه جامعه برمی‌گردد.
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'total_votes' => $total_votes,
        'has_priority' => !$has
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Redirect بازگشت به صفحه جامعه برای ارسال معمولی فرم
header("Location: community.php?id=" . $community_id);
exit;