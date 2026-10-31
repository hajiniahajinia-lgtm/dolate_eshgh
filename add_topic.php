<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    echo "⛔ دسترسی غیرمجاز!";
    exit;
}

$user_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit("درخواست نامعتبر است.");
}

$csrf_token = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['community_duty_csrf']) || $csrf_token === '' ||
    !hash_equals($_SESSION['community_duty_csrf'], $csrf_token)) {
    http_response_code(403);
    exit("درخواست معتبر نیست یا نشست شما منقضی شده است. لطفاً صفحه را تازه‌سازی و دوباره تلاش کنید.");
}

if (!isset($_POST['topic_title']) || !isset($_POST['community_id'])) {
    die("⚠️ لطفاً عنوان موضوع و جامعه را انتخاب کنید");
}

// ⬅️ محدودیت 100 کاراکتر
$title = mb_substr(trim($_POST['topic_title']), 0, 100);
$description = mb_substr(trim($_POST['topic_description'] ?? ''), 0, 500);
$initial_idea = trim((string)($_POST['topic_initial_idea'] ?? ''));
if (function_exists('mb_strlen')) {
    $initial_idea_length = mb_strlen($initial_idea, 'UTF-8');
} else {
    preg_match_all('/./us', $initial_idea, $initial_idea_characters);
    $initial_idea_length = count($initial_idea_characters[0]);
}
if ($initial_idea_length > 25000) {
    http_response_code(400);
    exit('طرح ایده بیش از حد مجاز است. حداکثر ۲۵٬۰۰۰ نویسه وارد کنید.');
}

if ($title === "") {
    die("⚠️ عنوان موضوع نمی‌تواند خالی باشد");
}

$community_id = (int)$_POST['community_id'];

// حوزهٔ تخصصی فقط به‌عنوان پیشنهاد ثبت می‌شود؛ تا تأیید جامعه وزنی اعمال نمی‌کند.
$expertise_domain_id = (int)($_POST['expertise_domain_id'] ?? 0);
if ($expertise_domain_id > 0) {
    $domain_check = $conn->prepare("SELECT id FROM expertise_domains WHERE id=? AND status='approved' LIMIT 1");
    if (!$domain_check) {
        http_response_code(500);
        exit("امکان بررسی حوزهٔ تخصصی وجود ندارد. لطفاً بعداً تلاش کنید.");
    }
    $domain_check->bind_param("i", $expertise_domain_id);
    $domain_check->execute();
    $valid_domain = $domain_check->get_result()->fetch_assoc();
    $domain_check->close();
    if (!$valid_domain) {
        http_response_code(400);
        exit("حوزهٔ تخصصی انتخاب‌شده معتبر یا تأییدشده نیست.");
    }
}

// مقدار فوریت اصلی (دقیقه)
$urgency_minutes = 10;

// فوریت قدیمی برای سازگاری
$urgency = $urgency_minutes;

$deadline = date("Y-m-d H:i:s", time() + ($urgency_minutes * 60));
$created_at = date("Y-m-d H:i:s");
$priority = 0;
$urgency_default_minutes = 3;
$urgency_updated_at = date("Y-m-d H:i:s");

$stmt = $conn->prepare("
    INSERT INTO topics 
    (community_id, title, description, user_id, priority, urgency, urgency_minutes, urgency_default_minutes, deadline, created_at, urgency_updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$stmt->bind_param(
    "issiiiiisss",
    $community_id,
    $title,
    $description,
    $user_id,
    $priority,
    $urgency,
    $urgency_minutes,
    $urgency_default_minutes,
    $deadline,
    $created_at,
    $urgency_updated_at
);

if ($stmt->execute()) {
    $new_topic_id = (int)$conn->insert_id;
    if ($expertise_domain_id > 0) {
        $proposal_stmt = $conn->prepare("INSERT INTO topic_expertise_proposals (topic_id, community_id, domain_id, proposed_by, status, expires_at) VALUES (?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL 7 DAY))");
        if ($proposal_stmt) {
            $proposal_stmt->bind_param("iiii", $new_topic_id, $community_id, $expertise_domain_id, $user_id);
            if (!$proposal_stmt->execute()) {
                error_log("Topic expertise proposal insert failed: " . $proposal_stmt->error);
            }
            $proposal_stmt->close();
        } else {
            error_log("Topic expertise proposal prepare failed: " . $conn->error);
        }
    }
    if ($initial_idea !== '') {
        $idea_stmt = $conn->prepare("INSERT INTO ideas (topic_id, user_id, content) VALUES (?, ?, ?)");
        $idea_stmt->bind_param("iis", $new_topic_id, $user_id, $initial_idea);
        if (!$idea_stmt->execute()) {
            echo "⚠️ موضوع ثبت شد، اما ذخیره طرح ایده ناموفق بود: " . htmlspecialchars($idea_stmt->error, ENT_QUOTES, 'UTF-8');
            $idea_stmt->close();
            $stmt->close();
            $conn->close();
            exit();
        }
        $idea_stmt->close();
        header("Location: community.php?id=" . $community_id . "&success_topic=1&success_idea=1");
    } else {
        header("Location: community.php?id=" . $community_id . "&success_topic=1");
    }
    exit();
} else {
    echo "❌ خطا در ذخیره موضوع: " . htmlspecialchars($stmt->error, ENT_QUOTES, 'UTF-8');
}

$stmt->close();
$conn->close();
?>