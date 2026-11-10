<?php
session_start();
require_once __DIR__ . '/db.php';

$communities = [];
$result = $conn->query("SELECT id, name FROM communities ORDER BY name ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $communities[] = $row;
    }
    $result->close();
}
function guest_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>گشت‌وگذار در جامعه‌های دولت عشق</title>
<style>
*{box-sizing:border-box}body{font-family:tahoma,Arial,sans-serif;background:#f2f6f2;color:#26352b;margin:0;padding:16px;line-height:1.9}.wrap{max-width:900px;margin:0 auto}.panel{background:#fff;border:1px solid #dce7dc;border-radius:12px;padding:18px;margin-bottom:14px}.community{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px;border:1px solid #e1e8e1;border-radius:9px;margin-top:10px}.community h2{font-size:17px;margin:0}.btn{display:inline-block;text-decoration:none;background:#216b45;color:#fff;border:0;border-radius:7px;padding:8px 13px;font:inherit;font-size:14px;cursor:pointer}.secondary{background:#fff;color:#216b45;border:1px solid #216b45}.actions{display:flex;gap:8px;flex-wrap:wrap}.muted{color:#66736a;font-size:13px}@media(max-width:520px){body{padding:9px}.panel{padding:13px}.community{align-items:stretch;flex-direction:column}.community .btn{width:100%;text-align:center}}
</style>
</head>
<body><main class="wrap">
<section class="panel">
<h1 style="margin:0 0 8px;font-size:24px">گشت‌وگذار در جامعه‌های دولت عشق</h1>
<p>بدون ثبت‌نام، جامعه‌ها، موضوعات و ایده‌ها را ببینید. برای رأی‌دادن یا هرگونه مشارکت، لازم است وارد حساب شوید یا ثبت‌نام کنید.</p>
<div class="actions">
<a class="btn" href="register.php">ثبت‌نام</a>
<a class="btn secondary" href="login.php">ورود به حساب</a>
<a class="btn secondary" href="index.html">بازگشت به صفحهٔ اصلی</a>
</div>
</section>
<section class="panel">
<h2 style="margin-top:0">جامعه‌ها</h2>
<?php if (!$communities): ?>
<p class="muted">در حال حاضر جامعه‌ای برای نمایش پیدا نشد.</p>
<?php else: foreach ($communities as $community): ?>
<div class="community">
<h2>🌐 <?= guest_h($community['name']) ?></h2>
<a class="btn" href="guest_community.php?id=<?= (int)$community['id'] ?>">مشاهدهٔ موضوعات و ایده‌ها</a>
</div>
<?php endforeach; endif; ?>
</section>
</main></body></html>
