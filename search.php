<?php
session_start();
require_once __DIR__ . "/db.php";

$q = trim((string)($_GET['q'] ?? ''));
$q = mb_substr($q, 0, 120, 'UTF-8');
$like = '%' . $q . '%';
$topics = [];
$ideas = [];
$communities = [];
$users = [];
$member_community_ids = [];
$search_error = false;

if ($q !== '') {
    try {
        $stmt = $conn->prepare("SELECT t.id, t.title, t.description, t.community_id, c.name AS community_name
            FROM topics t LEFT JOIN communities c ON c.id=t.community_id
            WHERE t.title LIKE ? OR t.description LIKE ?
            ORDER BY t.id DESC LIMIT 30");
        $stmt->bind_param("ss", $like, $like);
        $stmt->execute();
        $topics = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $stmt = $conn->prepare("SELECT i.id, i.content, t.id AS topic_id, t.title AS topic_title,
                t.community_id, c.name AS community_name
            FROM ideas i JOIN topics t ON t.id=i.topic_id
            LEFT JOIN communities c ON c.id=t.community_id
            WHERE i.content LIKE ?
            ORDER BY i.id DESC LIMIT 30");
        $stmt->bind_param("s", $like);
        $stmt->execute();
        $ideas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (isset($_SESSION['user_id'])) {
            $membership_stmt = $conn->prepare("SELECT community_id FROM community_members WHERE user_id=?");
            $logged_in_user_id = (int)$_SESSION['user_id'];
            $membership_stmt->bind_param("i", $logged_in_user_id);
            $membership_stmt->execute();
            $membership_result = $membership_stmt->get_result();
            while ($membership_row = $membership_result->fetch_assoc()) {
                $member_community_ids[] = (int)$membership_row['community_id'];
            }
            $membership_stmt->close();
        }

        $stmt = $conn->prepare("SELECT id, name FROM communities WHERE name LIKE ? ORDER BY name ASC LIMIT 30");
        $stmt->bind_param("s", $like);
        $stmt->execute();
        $communities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // جست‌وجوی اعضا فقط بر اساس نام کاربری؛ اطلاعات خصوصی کاربران نمایش داده نمی‌شود.
        $stmt = $conn->prepare("SELECT id, username FROM users WHERE username LIKE ? ORDER BY username ASC LIMIT 30");
        $stmt->bind_param("s", $like);
        $stmt->execute();
        $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (Throwable $e) {
        $search_error = true;
    }
}

function search_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function search_excerpt($value, $limit = 220) {
    $value = trim(preg_replace('/\\s+/u', ' ', strip_tags((string)$value)));
    if (mb_strlen($value, 'UTF-8') > $limit) {
        $value = mb_substr($value, 0, $limit, 'UTF-8') . '…';
    }
    return $value;
}
$mission_text = 'دولت عشق بستری برای مشارکت همگانی، شناخت مسائل و چالش‌ها، ارائه ایده و راه‌حل، تصمیم‌گیری جمعی و پیگیری اجرای راه‌حل‌هاست. هوش مصنوعی ابزار کمکی انسان‌هاست و جایگزین اراده و مشارکت آنان نیست.';
$mission_match = false;
if ($q !== '') {
    $mission_match = (function_exists('mb_stripos') ? mb_stripos($mission_text, $q, 0, 'UTF-8') : stripos($mission_text, $q)) !== false;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>سرچ در دولت عشق</title>
<style>
*{box-sizing:border-box}body{margin:0;padding:16px;font-family:Tahoma,Arial,sans-serif;background:#f3f7fa;color:#263238;line-height:1.9}
.container{max-width:900px;margin:0 auto}.top,.panel{background:#fff;border:1px solid #dce5eb;border-radius:12px;padding:18px;margin-bottom:14px}
.top{background:#e8f4fb}.topline{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
h1{font-size:22px;margin:0}.search-form{display:flex;gap:8px;margin-top:14px}
input[name=q]{flex:1;min-width:0;padding:12px;border:1px solid #b8c7d1;border-radius:8px;font:inherit}
button,.link{display:inline-flex;align-items:center;justify-content:center;padding:9px 14px;border:0;border-radius:8px;background:#1769aa;color:white;text-decoration:none;font:inherit;font-weight:bold;cursor:pointer}
h2{font-size:18px;margin:0 0 10px}.result{padding:12px 0;border-bottom:1px solid #e7edf1}.result:last-child{border-bottom:0}
.result-title{font-weight:bold;color:#1769aa;text-decoration:none}.meta{font-size:13px;color:#64727d}.empty{color:#667781}
@media(max-width:520px){body{padding:9px}.top,.panel{padding:13px}.search-form{flex-direction:column}.search-form button{width:100%}h1{font-size:20px}}
</style>
</head>
<body>
<div class="container">
<section class="top">
<div class="topline"><h1>🔎 سرچ در دولت عشق</h1><a class="link" href="<?= isset($_SESSION['user_id']) ? 'profile.php' : 'index.html' ?>">بازگشت</a></div>
<p>در موضوعات، ایده‌ها، جامعه‌ها و مأموریت دولت عشق جست‌وجو کنید.</p>
<form class="search-form" method="get" action="search.php">
<input type="search" name="q" value="<?= search_h($q) ?>" placeholder="عبارت موردنظر را بنویسید…" maxlength="120" autofocus>
<button type="submit">جست‌وجو</button>
</form>
</section>
<?php if ($q === ''): ?>
<section class="panel"><p class="empty">عبارت موردنظر را وارد کنید؛ نتایج موضوعات، ایده‌ها و جامعه‌های مرتبط نمایش داده می‌شوند.</p></section>
<?php else: ?>
<?php if ($search_error): ?><section class="panel"><p>در خواندن بعضی نتایج مشکلی پیش آمد. لطفاً دوباره تلاش کنید.</p></section><?php endif; ?>
<?php if ($mission_match): ?>
<section class="panel"><h2>مأموریت و کارکرد دولت عشق</h2><div class="result"><?= search_h($mission_text) ?><p><a class="result-title" href="about.html">آشنایی عمیق‌تر با مفاهیم و علت ایجاد دولت عشق ←</a></p></div></section>
<?php endif; ?>
<section class="panel"><h2>جامعه‌ها (<?= count($communities) ?>)</h2>
<?php if (!$communities): ?><p class="empty">جامعه‌ای پیدا نشد.</p><?php endif; ?>
<?php foreach ($communities as $item): ?>
<?php $community_is_member = in_array((int)$item['id'], $member_community_ids, true); ?>
<div class="result">
<?php if ($community_is_member): ?>
<a class="result-title" href="community.php?id=<?= (int)$item['id'] ?>"><?= search_h($item['name']) ?></a>
<div class="meta">شما عضو این جامعه هستید؛ برای ورود کلیک کنید.</div>
<?php elseif (isset($_SESSION['user_id'])): ?>
<a class="result-title" href="profile.php?join_community=<?= (int)$item['id'] ?>#join-community">عضویت در جامعهٔ «<?= search_h($item['name']) ?>» ←</a>
<div class="meta">برای ورود به این جامعه، ابتدا باید عضو شوید.</div>
<?php else: ?>
<a class="result-title" href="login.php">ورود برای عضویت در جامعهٔ «<?= search_h($item['name']) ?>» ←</a>
<div class="meta">ابتدا وارد حساب کاربری شوید و سپس عضویت را انجام دهید.</div>
<?php endif; ?>
</div>
<?php endforeach; ?>
</section>
<section class="panel"><h2>کاربران دولت عشق (<?= count($users) ?>)</h2>
<?php if (!$users): ?><p class="empty">کاربری با این نام پیدا نشد.</p><?php endif; ?>
<?php foreach ($users as $item): ?><div class="result"><strong><?= search_h($item['username']) ?></strong><div class="meta">عضو دولت عشق</div></div><?php endforeach; ?>
</section>
<section class="panel"><h2>موضوعات (<?= count($topics) ?>)</h2>
<?php if (!$topics): ?><p class="empty">موضوعی پیدا نشد.</p><?php endif; ?>
<?php foreach ($topics as $item): ?><div class="result"><a class="result-title" href="community.php?id=<?= (int)$item['community_id'] ?>#topic-<?= (int)$item['id'] ?>"><?= search_h($item['title']) ?></a>
<div class="meta"><?= search_h($item['community_name'] ?? 'جامعه') ?></div>
<?php if (!empty($item['description'])): ?><div><?= search_h(search_excerpt($item['description'])) ?></div><?php endif; ?></div><?php endforeach; ?>
</section>
<section class="panel"><h2>ایده‌ها و راه‌حل‌ها (<?= count($ideas) ?>)</h2>
<?php if (!$ideas): ?><p class="empty">ایده‌ای پیدا نشد.</p><?php endif; ?>
<?php foreach ($ideas as $item): ?><div class="result"><a class="result-title" href="community.php?id=<?= (int)$item['community_id'] ?>#topic-<?= (int)$item['topic_id'] ?>"><?= search_h($item['topic_title']) ?></a>
<div class="meta"><?= search_h($item['community_name'] ?? 'جامعه') ?></div><div><?= search_h(search_excerpt($item['content'])) ?></div></div><?php endforeach; ?>
</section>
<?php endif; ?>
</div>
</body>
</html>
