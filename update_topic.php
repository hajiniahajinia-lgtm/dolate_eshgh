<?php
session_start();
include __DIR__ . "/db.php";

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit("برای انجام این عملیات ابتدا وارد شوید.");
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
$field = (string)($_POST['field'] ?? '');
if ($topic_id < 1 || !in_array($field, ['urgency', 'priority'], true)) {
    http_response_code(400);
    exit("اطلاعات درخواست معتبر نیست.");
}

// جامعه و وضعیت موضوع از پایگاه داده تعیین می‌شوند؛ به community_id ارسالی اعتماد نمی‌کنیم.
$topic = $conn->prepare("SELECT community_id, closed FROM topics WHERE id=? LIMIT 1");
$topic->bind_param("i", $topic_id);
$topic->execute();
$topic_row = $topic->get_result()->fetch_assoc();
$topic->close();
if (!$topic_row || (int)$topic_row['closed'] !== 0) {
    http_response_code(409);
    exit("این موضوع باز نیست یا وجود ندارد.");
}
$community_id = (int)$topic_row['community_id'];

$member = $conn->prepare("SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1");
$member->bind_param("ii", $community_id, $user_id);
$member->execute();
$is_member = (bool)$member->get_result()->fetch_row();
$member->close();
if (!$is_member) {
    http_response_code(403);
    exit("فقط اعضای جامعه می‌توانند این عملیات را انجام دهند.");
}

if ($field === 'urgency') {
    if (!isset($_POST['urgency_days']) || !is_scalar($_POST['urgency_days'])) {
        http_response_code(400);
        exit("مدت فوریت معتبر نیست.");
    }
    $urgency = (int)$_POST['urgency_days'];
    if ($urgency < 1 || $urgency > 300) {
        http_response_code(400);
        exit("مدت فوریت باید بین ۱ تا ۳۰۰ روز باشد.");
    }
    $stmt = $conn->prepare("UPDATE topics SET urgency=? WHERE id=? AND community_id=? AND closed=0");
    $stmt->bind_param("iii", $urgency, $topic_id, $community_id);
    $stmt->execute();
    $stmt->close();
    echo "⏱ فوریت با موفقیت بروزرسانی شد";
} else {
    $conn->begin_transaction();
    try {
        $check = $conn->prepare("SELECT id FROM topic_votes WHERE topic_id=? AND user_id=? FOR UPDATE");
        $check->bind_param("ii", $topic_id, $user_id);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();

        if ($existing) {
            $del = $conn->prepare("DELETE FROM topic_votes WHERE topic_id=? AND user_id=?");
            $del->bind_param("ii", $topic_id, $user_id);
            $del->execute();
            $del->close();
            $message = "✅ اولویت برداشته شد";
        } else {
            $ins = $conn->prepare("INSERT INTO topic_votes (topic_id,user_id) VALUES (?,?)");
            $ins->bind_param("ii", $topic_id, $user_id);
            $ins->execute();
            $ins->close();
            $message = "✅ اولویت ثبت شد";
        }
        $conn->commit();
        echo $message;
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("update_topic failed: " . $e->getMessage());
        http_response_code(500);
        exit("ثبت تغییر انجام نشد.");
    }
}

$conn->close();
?>