<?php
session_start();
require_once __DIR__ . '/db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$community_id = filter_input(INPUT_GET, 'community_id', FILTER_VALIDATE_INT);
$community_id = $community_id && $community_id > 0 ? (int) $community_id : 0;

if ($community_id < 1) {
    http_response_code(400);
    exit('شناسه جامعه معتبر نیست.');
}

$member = $conn->prepare('SELECT 1 FROM community_members WHERE community_id = ? AND user_id = ? LIMIT 1');
$member->bind_param('ii', $community_id, $user_id);
$member->execute();
$is_member = (bool) $member->get_result()->fetch_row();
$member->close();

if (!$is_member) {
    http_response_code(403);
    exit('این گزارش فقط برای اعضای جامعه قابل مشاهده است.');
}

$community_stmt = $conn->prepare('SELECT name FROM communities WHERE id = ? LIMIT 1');
$community_stmt->bind_param('i', $community_id);
$community_stmt->execute();
$community = $community_stmt->get_result()->fetch_assoc();
$community_stmt->close();

if (!$community) {
    http_response_code(404);
    exit('جامعه پیدا نشد.');
}

function report_count(mysqli $conn, string $sql, int $community_id): int {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('i', $community_id);
    if (!$stmt->execute()) {
        $stmt->close();
        return 0;
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['total'] ?? 0);
}

$topics_count = report_count(
    $conn,
    'SELECT COUNT(*) AS total FROM topics WHERE community_id = ?',
    $community_id
);

$ideas_count = report_count(
    $conn,
    'SELECT COUNT(*) AS total FROM ideas i INNER JOIN topics t ON t.id = i.topic_id WHERE t.community_id = ?',
    $community_id
);

$finalized_count = report_count(
    $conn,
    'SELECT COUNT(DISTINCT t.id) AS total FROM topics t INNER JOIN topic_voting_meta m ON m.topic_id = t.id WHERE t.community_id = ? AND m.finalized = 1',
    $community_id
);

$pending_count = report_count(
    $conn,
    'SELECT COUNT(*) AS total FROM topics t LEFT JOIN topic_voting_meta m ON m.topic_id = t.id AND m.finalized = 1 WHERE t.community_id = ? AND t.closed = 0 AND m.topic_id IS NULL',
    $community_id
);

$active_topics = [];
$list_stmt = $conn->prepare(
    'SELECT t.id, t.title, COUNT(i.id) AS ideas_count
     FROM topics t
     LEFT JOIN ideas i ON i.topic_id = t.id
     LEFT JOIN topic_voting_meta m ON m.topic_id = t.id AND m.finalized = 1
     WHERE t.community_id = ? AND t.closed = 0 AND m.topic_id IS NULL
     GROUP BY t.id, t.title
     ORDER BY ideas_count DESC, t.id DESC
     LIMIT 10'
);
if ($list_stmt) {
    $list_stmt->bind_param('i', $community_id);
    if ($list_stmt->execute()) {
        $result = $list_stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $active_topics[] = $row;
        }
    }
    $list_stmt->close();
}

function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>گزارش‌های جامعه | دولت عشق</title>
<style>
:root{color-scheme:light}
*{box-sizing:border-box}
body{margin:0;background:#f3f5f8;color:#202733;font-family:Tahoma,Arial,sans-serif;line-height:1.9}
main{max-width:900px;margin:24px auto;padding:16px}
header,.panel,.stat{background:#fff;border:1px solid #e1e6ed;border-radius:14px;padding:18px}
header{margin-bottom:16px}
h1{font-size:22px;margin:0 0 6px}
h2{font-size:18px;margin:0 0 12px}
.muted{color:#687385;font-size:13px}
.stats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-bottom:16px}
.stat strong{display:block;font-size:25px;margin-top:5px}
.panel{margin-bottom:16px}
.topic{padding:12px 0;border-bottom:1px solid #edf0f4}
.topic:last-child{border-bottom:0}
.topic a{color:#174f91;text-decoration:none;font-weight:bold}
.topic a:hover{text-decoration:underline}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
.actions a{display:inline-block;background:#174f91;color:#fff;text-decoration:none;padding:9px 14px;border-radius:9px}
@media(min-width:650px){.stats{grid-template-columns:repeat(4,minmax(0,1fr))}main{padding:20px}}
</style>
</head>
<body>
<main>
<header>
<h1>گزارش‌های جامعه</h1>
<div><?= e($community['name']) ?></div>
<p class="muted">گزارش‌های خودکار از داده‌های ثبت‌شده؛ بدون هوش مصنوعی و بدون تغییر در اطلاعات سایت.</p>
</header>

<section class="stats" aria-label="آمار جامعه">
<div class="stat"><span class="muted">موضوعات</span><strong><?= $topics_count ?></strong></div>
<div class="stat"><span class="muted">ایده‌ها</span><strong><?= $ideas_count ?></strong></div>
<div class="stat"><span class="muted">موضوعات دارای رأی نهایی</span><strong><?= $finalized_count ?></strong></div>
<div class="stat"><span class="muted">موضوعات باز بدون رأی نهایی</span><strong><?= $pending_count ?></strong></div>
</section>

<section class="panel">
<h2>موضوعات بازِ بدون رأی نهایی</h2>
<p class="muted">این فهرست فقط موضوعات باز را نشان می‌دهد که در جدول رأی‌گیری هنوز رأی نهایی ثبت‌شده ندارند؛ این به‌تنهایی به معنای تأخیر یا قصور نیست.</p>
<?php if ($active_topics): ?>
<?php foreach ($active_topics as $topic): ?>
<div class="topic">
<a href="community.php?id=<?= $community_id ?>&amp;open_topic=<?= (int) $topic['id'] ?>#topic-<?= (int) $topic['id'] ?>"><?= e($topic['title']) ?></a>
<div class="muted"><?= (int) $topic['ideas_count'] ?> ایده</div>
</div>
<?php endforeach; ?>
<?php else: ?>
<p>در حال حاضر موضوعی بدون رأی نهایی ثبت‌شده پیدا نشد.</p>
<?php endif; ?>
<div class="actions">
<a href="community.php?id=<?= $community_id ?>">بازگشت به جامعه</a>
</div>
</section>
</main>
</body>
</html>
