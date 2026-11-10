<?php
session_start();
require_once __DIR__ . '/db.php';

function guest_community_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
$community_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$community = null;
if ($community_id > 0) {
    $stmt = $conn->prepare("SELECT id, name, approach FROM communities WHERE id=? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $community_id);
        $stmt->execute();
        $community = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}
$topics = [];
if ($community) {
    $stmt = $conn->prepare("SELECT id, title, description, created_at, closed FROM topics WHERE community_id=? ORDER BY created_at DESC, id DESC");
    if ($stmt) {
        $stmt->bind_param("i", $community_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $topics[] = $row;
        $stmt->close();
    }
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $community ? guest_community_h($community['name']) : 'جامعه پیدا نشد' ?> | دولت عشق</title>
<style>
*{box-sizing:border-box}body{font-family:tahoma,Arial,sans-serif;background:#f2f6fa;color:#263238;margin:0;padding:14px;line-height:1.95}.wrap{max-width:1000px;margin:0 auto}.panel,.topic{background:#fff;border:1px solid #dce5ed;border-radius:12px;padding:16px;margin-bottom:14px;min-width:0}.header{background:linear-gradient(125deg,#ffe4eb,#ffefd6,#e7f8d9,#dff4ff,#e9e0ff);border-color:#e2dff0}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn{display:inline-block;text-decoration:none;background:#216b45;color:#fff;border:0;border-radius:7px;padding:8px 12px;font:inherit;font-size:13px;cursor:pointer}.secondary{background:#fff;color:#216b45;border:1px solid #216b45}.topic h2{font-size:18px;margin:0 0 7px;overflow-wrap:anywhere}.meta{font-size:12px;color:#667085}.description{white-space:pre-wrap;overflow-wrap:anywhere;color:#4b5563;margin:8px 0}.ideas{margin-top:12px;padding:12px;background:#f8fafc;border:1px solid #e5eaf0;border-radius:9px}.idea{background:#fff;border:1px solid #e4e7ec;border-radius:8px;padding:10px;margin-top:8px;white-space:pre-wrap;overflow-wrap:anywhere}.notice{margin-top:12px;background:#fff8e1;border:1px solid #f2d675;border-radius:8px;padding:10px;font-size:13px}.empty{color:#667085}@media(max-width:520px){body{padding:8px}.panel,.topic{padding:12px}.actions{flex-direction:column}.actions .btn{text-align:center;width:100%}}
</style>
<script>
function guestNeedsAccount(action){
  const message = 'برای ' + action + ' در دولت عشق، ابتدا باید ثبت‌نام کنید یا وارد حساب کاربری خود شوید.';
  if (window.confirm(message + '\n\nبرای رفتن به صفحهٔ ثبت‌نام «تأیید» را بزنید؛ برای ماندن در این صفحه «لغو» را انتخاب کنید.')) {
    window.location.href = 'register.php';
  }
}
</script>
</head>
<body><main class="wrap">
<?php if (!$community): ?>
<section class="panel"><h1>جامعه پیدا نشد</h1><p>این جامعه در دسترس نیست یا نشانی آن معتبر نیست.</p><a class="btn" href="guest_communities.php">بازگشت به فهرست جامعه‌ها</a></section>
<?php else: ?>
<section class="panel header">
<h1 style="margin:0 0 6px"><?= guest_community_h($community['name']) ?></h1>
<?php if (trim((string)($community['approach'] ?? '')) !== ''): ?><p><?= nl2br(guest_community_h($community['approach'])) ?></p><?php endif; ?>
<p>این صفحه در حالت مهمان و فقط برای مشاهده است. می‌توانید موضوعات و ایده‌ها را بخوانید؛ برای مشارکت، ثبت‌نام یا ورود لازم است.</p>
<div class="actions">
<a class="btn secondary" href="guest_communities.php">بازگشت به جامعه‌ها</a>
<a class="btn" href="register.php">ثبت‌نام برای مشارکت</a>
<a class="btn secondary" href="login.php">ورود به حساب</a>
</div>
</section>
<?php if (!$topics): ?>
<section class="panel empty">هنوز موضوعی برای این جامعه ثبت نشده است.</section>
<?php else: foreach ($topics as $topic): ?>
<section class="topic">
<h2><?= guest_community_h($topic['title']) ?></h2>
<div class="meta"><?= !empty($topic['closed']) ? '📦 موضوع بایگانی‌شده یا بسته‌شده' : '🟢 موضوع باز' ?><?= !empty($topic['created_at']) ? ' · تاریخ ثبت: '.guest_community_h($topic['created_at']) : '' ?></div>
<?php if (trim((string)($topic['description'] ?? '')) !== ''): ?><div class="description"><?= guest_community_h($topic['description']) ?></div><?php endif; ?>
<div class="actions">
<button class="btn" type="button" onclick="guestNeedsAccount('رأی‌دادن یا ثبت فوریت')">رأی‌گیری و فوریت</button>
<button class="btn secondary" type="button" onclick="guestNeedsAccount('ثبت ایده یا پیشنهاد')">ثبت ایده</button>
</div>
<div class="ideas"><strong>💡 ایده‌های این موضوع</strong>
<?php
$ideas = [];
$idea_stmt = $conn->prepare("SELECT i.id, i.content, u.username FROM ideas i LEFT JOIN users u ON u.id=i.user_id WHERE i.topic_id=? ORDER BY i.id ASC");
if ($idea_stmt) {
    $topic_id_for_ideas = (int)$topic['id'];
    $idea_stmt->bind_param("i", $topic_id_for_ideas);
    $idea_stmt->execute();
    $idea_result = $idea_stmt->get_result();
    while ($idea = $idea_result->fetch_assoc()) $ideas[] = $idea;
    $idea_stmt->close();
}
?>
<?php if (!$ideas): ?><p class="meta">هنوز ایده‌ای برای این موضوع ثبت نشده است.</p>
<?php else: foreach ($ideas as $idea): ?>
<div class="idea"><div><?= nl2br(guest_community_h($idea['content'])) ?></div><div class="meta" style="margin-top:7px">ارائه‌دهنده: <?= guest_community_h($idea['username'] ?: 'عضو سامانه') ?></div></div>
<?php endforeach; endif; ?>
</div>
<div class="notice">این بخش فقط برای مشاهده است. برای رأی‌دادن، تغییر فوریت، ثبت ایده یا انجام هر عمل دیگری باید وارد حساب شوید.</div>
</section>
<?php endforeach; endif; ?>
<?php endif; ?>
</main></body></html>
