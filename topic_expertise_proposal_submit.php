<?php
session_start();
include __DIR__ . "/db.php";

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit("ابتدا وارد شوید.");
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit("درخواست نامعتبر است.");
}

$user_id = (int)$_SESSION['user_id'];
$community_id = (int)($_POST['community_id'] ?? 0);
$topic_id = (int)($_POST['topic_id'] ?? 0);
$domain_id = (int)($_POST['domain_id'] ?? 0);
$csrf = (string)($_POST['csrf_token'] ?? '');

if (empty($_SESSION['community_duty_csrf']) || $csrf === '' ||
    !hash_equals($_SESSION['community_duty_csrf'], $csrf)) {
    http_response_code(403);
    exit("نشست معتبر نیست؛ صفحه را تازه‌سازی کنید.");
}
if ($community_id < 1 || $topic_id < 1 || $domain_id < 1) {
    http_response_code(400);
    exit("موضوع، جامعه یا حوزهٔ تخصصی معتبر نیست.");
}

try {
    $member = $conn->prepare("SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1");
    $member->bind_param("ii", $community_id, $user_id);
    $member->execute();
    $is_member = (bool)$member->get_result()->fetch_row();
    $member->close();
    if (!$is_member) {
        http_response_code(403);
        exit("فقط اعضای جامعه می‌توانند پیشنهاد ثبت کنند.");
    }

    $topic = $conn->prepare("SELECT id FROM topics WHERE id=? AND community_id=? LIMIT 1");
    $topic->bind_param("ii", $topic_id, $community_id);
    $topic->execute();
    $valid_topic = (bool)$topic->get_result()->fetch_row();
    $topic->close();
    if (!$valid_topic) {
        throw new RuntimeException("موضوع انتخاب‌شده متعلق به این جامعه نیست.");
    }

    $domain = $conn->prepare("SELECT id FROM expertise_domains WHERE id=? AND status='approved' LIMIT 1");
    $domain->bind_param("i", $domain_id);
    $domain->execute();
    $valid_domain = (bool)$domain->get_result()->fetch_row();
    $domain->close();
    if (!$valid_domain) {
        throw new RuntimeException("حوزهٔ تخصصی انتخاب‌شده تأیید نشده است.");
    }

    $duplicate = $conn->prepare("SELECT id FROM topic_expertise_proposals WHERE topic_id=? LIMIT 1");
    $duplicate->bind_param("i", $topic_id);
    $duplicate->execute();
    $already_exists = (bool)$duplicate->get_result()->fetch_row();
    $duplicate->close();
    if ($already_exists) {
        throw new RuntimeException("برای این موضوع قبلاً پیشنهاد حوزهٔ تخصصی ثبت شده است.");
    }

    $insert = $conn->prepare("INSERT INTO topic_expertise_proposals (topic_id,community_id,domain_id,proposed_by,status,proposed_at,expires_at) VALUES (?,?,?,?,'pending',NOW(),DATE_ADD(NOW(), INTERVAL 7 DAY))");
    $insert->bind_param("iiii", $topic_id, $community_id, $domain_id, $user_id);
    if (!$insert->execute()) {
        if ($insert->errno === 1062) {
            throw new RuntimeException("برای این موضوع قبلاً پیشنهاد حوزهٔ تخصصی ثبت شده است.");
        }
        throw new RuntimeException("ثبت پیشنهاد انجام نشد.");
    }
    $insert->close();

    header("Location: community.php?id=" . $community_id . "#topic-expertise-proposals");
    exit;
} catch (Throwable $e) {
    error_log("Topic expertise proposal submit: " . $e->getMessage());
    http_response_code(400);
    exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
