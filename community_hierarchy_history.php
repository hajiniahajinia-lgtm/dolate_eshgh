<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$community_id = filter_input(INPUT_GET, 'community_id', FILTER_VALIDATE_INT);
if (!$community_id || $community_id <= 0) {
    http_response_code(400);
    exit('شناسه جامعه معتبر نیست.');
}

// جامعه و عضویت در یک پرس‌وجو بررسی می‌شوند تا بین دو بررسی، وضعیت دسترسی تغییر نکند.
$community_stmt = $conn->prepare(
    'SELECT c.id, c.name
     FROM communities c
     INNER JOIN community_members cm ON cm.community_id=c.id AND cm.user_id=?
     WHERE c.id=? LIMIT 1'
);
if (!$community_stmt) {
    http_response_code(503);
    exit('امکان بررسی دسترسی به جامعه وجود ندارد.');
}
$community_stmt->bind_param('ii', $user_id, $community_id);
if (!$community_stmt->execute()) {
    $community_stmt->close();
    http_response_code(503);
    exit('امکان بررسی دسترسی به جامعه وجود ندارد.');
}
$community = $community_stmt->get_result()->fetch_assoc();
$community_stmt->close();
if (!$community) {
    $exists_stmt = $conn->prepare('SELECT 1 FROM communities WHERE id=? LIMIT 1');
    if (!$exists_stmt) {
        http_response_code(503);
        exit('امکان دریافت اطلاعات جامعه وجود ندارد.');
    }
    $exists_stmt->bind_param('i', $community_id);
    if (!$exists_stmt->execute()) {
        $exists_stmt->close();
        http_response_code(503);
        exit('امکان دریافت اطلاعات جامعه وجود ندارد.');
    }
    $community_exists = (bool)$exists_stmt->get_result()->fetch_row();
    $exists_stmt->close();
    http_response_code($community_exists ? 403 : 404);
    exit($community_exists
        ? 'مشاهده تاریخچه فقط برای اعضای همین جامعه مجاز است.'
        : 'جامعه پیدا نشد.');
}

function community_hierarchy_history_escape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$table_check = $conn->query(
    "SELECT COUNT(*) AS total FROM information_schema.tables
     WHERE table_schema=DATABASE() AND table_name='community_hierarchy_change_history'"
);
$history_ready = $table_check && (int)$table_check->fetch_assoc()['total'] === 1;
if ($table_check) $table_check->free();

$page_number = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page_number = $page_number && $page_number > 0 ? min($page_number, 1000000) : 1;
$page_size = 30;
$offset = ($page_number - 1) * $page_size;
$total_rows = 0;
$history = [];

if ($history_ready) {
    $count_stmt = $conn->prepare('SELECT COUNT(*) AS total FROM community_hierarchy_change_history WHERE community_id=?');
    if (!$count_stmt) {
        http_response_code(503);
        exit('امکان دریافت تاریخچه وجود ندارد.');
    }
    $count_stmt->bind_param('i', $community_id);
    if (!$count_stmt->execute()) {
        $count_stmt->close();
        http_response_code(503);
        exit('امکان دریافت تاریخچه وجود ندارد.');
    }
    $total_rows = (int)($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $count_stmt->close();

    $history_stmt = $conn->prepare(
        "SELECT h.id, h.vote_round_id, h.old_parent_community_id, h.new_parent_community_id,
                h.actor_user_id, h.created_at,
                old_parent.name AS old_parent_name,
                new_parent.name AS new_parent_name,
                actor.username AS actor_username
         FROM community_hierarchy_change_history h
         LEFT JOIN communities old_parent ON old_parent.id=h.old_parent_community_id
         LEFT JOIN communities new_parent ON new_parent.id=h.new_parent_community_id
         LEFT JOIN users actor ON actor.id=h.actor_user_id
         WHERE h.community_id=?
         ORDER BY h.created_at DESC, h.id DESC
         LIMIT ? OFFSET ?"
    );
    if (!$history_stmt) {
        http_response_code(503);
        exit('امکان دریافت تاریخچه وجود ندارد.');
    }
    $history_stmt->bind_param('iii', $community_id, $page_size, $offset);
    if (!$history_stmt->execute()) {
        $history_stmt->close();
        http_response_code(503);
        exit('امکان دریافت تاریخچه وجود ندارد.');
    }
    $result = $history_stmt->get_result();
    while ($row = $result->fetch_assoc()) $history[] = $row;
    $history_stmt->close();
}

$total_pages = max(1, (int)ceil($total_rows / $page_size));
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تاریخچه جایگاه جامعه</title>
<style>
body{font-family:Tahoma,Arial,sans-serif;background:#f3f6fa;color:#1e293b;margin:0;padding:12px;line-height:1.9}
.container{max-width:900px;margin:auto}.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:15px;margin:12px 0}
.muted,.meta{color:#64748b;font-size:13px}.entry{border:1px solid #e2e8f0;border-radius:9px;padding:12px;margin:10px 0;background:#fafcff}
a{color:#1d4ed8;text-decoration:none;font-weight:bold}.pager{display:flex;gap:12px;justify-content:space-between;margin-top:14px}
@media(max-width:600px){body{padding:6px}.card{padding:11px}}
</style>
</head>
<body>
<div class="container">
<div class="card">
    <h2>تاریخچه جایگاه: <?= community_hierarchy_history_escape($community['name']) ?></h2>
    <p class="muted">این سوابق، تغییرات ثبت‌شده در رابطهٔ مادر و زیرشاخه را نشان می‌دهد؛ نمایش آن‌ها به اعضای همین جامعه محدود است.</p>
    <a href="community.php?id=<?= (int)$community_id ?>#community-hierarchy">بازگشت به جایگاه جامعه</a>
</div>
<?php if (!$history_ready): ?>
<div class="card"><p class="muted">ساختار تاریخچه هنوز در پایگاه داده نصب نشده است؛ در حال حاضر سابقه‌ای قابل نمایش نیست.</p></div>
<?php elseif (!$history): ?>
<div class="card"><p class="muted">هنوز تغییری در جایگاه این جامعه ثبت نشده است.</p></div>
<?php else: ?>
<div class="card">
    <p class="muted">تعداد رویدادهای ثبت‌شده: <?= $total_rows ?></p>
    <?php foreach ($history as $item): ?>
    <article class="entry">
        <strong>تغییر جایگاه جامعه</strong>
        <div>والد پیشین: <strong><?= community_hierarchy_history_escape($item['old_parent_name'] ?? 'مستقل') ?></strong></div>
        <div>والد جدید: <strong><?= community_hierarchy_history_escape($item['new_parent_name'] ?? 'مستقل') ?></strong></div>
        <div class="meta">ثبت‌کننده: <?= community_hierarchy_history_escape($item['actor_username'] ?? ($item['actor_user_id'] === null ? 'نامشخص' : 'کاربر #' . (int)$item['actor_user_id'])) ?></div>
        <div class="meta">زمان ثبت: <?= community_hierarchy_history_escape($item['created_at']) ?> — دور رأی‌گیری: #<?= (int)$item['vote_round_id'] ?></div>
    </article>
    <?php endforeach; ?>
    <?php if ($total_pages > 1): ?>
    <nav class="pager" aria-label="صفحه‌های تاریخچه">
        <?php if ($page_number > 1): ?><a href="?community_id=<?= (int)$community_id ?>&page=<?= $page_number - 1 ?>">صفحه قبلی</a><?php else: ?><span></span><?php endif; ?>
        <span class="meta">صفحه <?= $page_number ?> از <?= $total_pages ?></span>
        <?php if ($page_number < $total_pages): ?><a href="?community_id=<?= (int)$community_id ?>&page=<?= $page_number + 1 ?>">صفحه بعدی</a><?php endif; ?>
    </nav>
    <?php endif; ?>
</div>
<?php endif; ?>
</div>
</body>
</html>
