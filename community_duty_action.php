<?php
session_start();
require __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('درخواست نامعتبر است.');
}

$user_id = (int)$_SESSION['user_id'];
$community_id = (int)($_POST['community_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$csrf_token = (string)($_POST['csrf_token'] ?? '');

if ($community_id < 1 || empty($_SESSION['community_duty_csrf']) ||
    !hash_equals((string)$_SESSION['community_duty_csrf'], $csrf_token)) {
    http_response_code(403);
    exit('درخواست معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.');
}

// فقط اعضای همان جامعه مجاز به ثبت پیشنهاد یا رأی هستند.
$member = $conn->prepare('SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1');
$member->bind_param('ii', $community_id, $user_id);
$member->execute();
$is_member = (bool)$member->get_result()->fetch_row();
$member->close();

if (!$is_member) {
    http_response_code(403);
    exit('فقط اعضای جامعه می‌توانند پیشنهاد ثبت کنند یا رأی بدهند.');
}

if ($action === 'submit') {
    $proposal_type = (string)($_POST['proposal_type'] ?? '');
    $content = trim((string)($_POST['content'] ?? ''));

    if (!in_array($proposal_type, ['duty', 'approach'], true)) {
        exit('نوع پیشنهاد معتبر نیست.');
    }
    if ($content === '' || mb_strlen($content, 'UTF-8') > 600) {
        exit('متن پیشنهاد باید بین ۱ تا ۶۰۰ نویسه باشد.');
    }

    $insert = $conn->prepare('INSERT INTO community_duties (community_id, user_id, proposal_type, content) VALUES (?, ?, ?, ?)');
    if (!$insert) {
        http_response_code(500);
        exit('جدول‌های وظایف و رویکردها آماده نیستند. فایل community_duties.sql را اجرا کنید.');
    }
    $insert->bind_param('iiss', $community_id, $user_id, $proposal_type, $content);
    if (!$insert->execute()) {
        $insert->close();
        http_response_code(500);
        exit('ثبت پیشنهاد انجام نشد. لطفاً دوباره تلاش کنید.');
    }
    $insert->close();
} elseif ($action === 'vote') {
    $proposal_id = (int)($_POST['proposal_id'] ?? 0);
    if ($proposal_id < 1) exit('پیشنهاد معتبر نیست.');

    $proposal = $conn->prepare('SELECT id FROM community_duties WHERE id=? AND community_id=? LIMIT 1');
    $proposal->bind_param('ii', $proposal_id, $community_id);
    $proposal->execute();
    $exists = (bool)$proposal->get_result()->fetch_row();
    $proposal->close();
    if (!$exists) exit('این پیشنهاد در این جامعه وجود ندارد.');

    // هر عضو در هر جامعه فقط یک رأی دارد؛ می‌تواند رأی را به پیشنهاد دیگری منتقل کند.
    $conn->begin_transaction();
    try {
        $current = $conn->prepare('SELECT proposal_id FROM community_duty_votes WHERE community_id=? AND user_id=? LIMIT 1');
        $current->bind_param('ii', $community_id, $user_id);
        $current->execute();
        $current_vote = $current->get_result()->fetch_assoc();
        $current->close();

        if ($current_vote && (int)$current_vote['proposal_id'] === $proposal_id) {
            $remove = $conn->prepare('DELETE FROM community_duty_votes WHERE community_id=? AND user_id=?');
            $remove->bind_param('ii', $community_id, $user_id);
            $remove->execute();
            $remove->close();
        } else {
            $save = $conn->prepare('
                INSERT INTO community_duty_votes (community_id, proposal_id, user_id)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE proposal_id=VALUES(proposal_id)
            ');
            $save->bind_param('iii', $community_id, $proposal_id, $user_id);
            $save->execute();
            $save->close();
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        http_response_code(500);
        exit('ثبت رأی انجام نشد. لطفاً دوباره تلاش کنید.');
    }
} else {
    http_response_code(400);
    exit('عملیات ناشناخته است.');
}

header('Location: community.php?id=' . $community_id . '#community-duties');
exit;
?>