<?php
session_start();
require __DIR__ . '/db.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$user_id = (int)$_SESSION['user_id'];
if (empty($_SESSION['community_duty_csrf'])) $_SESSION['community_duty_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['community_duty_csrf'];

$communities = [];
$stmt = $conn->prepare('SELECT c.id,c.name FROM communities c JOIN community_members cm ON cm.community_id=c.id WHERE cm.user_id=? ORDER BY c.name');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$communities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$community_id = (int)($_GET['community_id'] ?? 0);
if ($community_id <= 0 && $communities) $community_id = (int)$communities[0]['id'];
$community_names = [];
foreach ($communities as $community) $community_names[(int)$community['id']] = $community['name'];
if (!isset($community_names[$community_id])) $community_id = 0;

$topics = [];
if ($community_id > 0) {
    $stmt = $conn->prepare('SELECT id,title FROM topics WHERE community_id=? ORDER BY id DESC LIMIT 300');
    $stmt->bind_param('i', $community_id);
    $stmt->execute();
    $topics = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
$topic_id = (int)($_GET['topic_id'] ?? 0);
$topic_exists = false;
foreach ($topics as $topic) if ((int)$topic['id'] === $topic_id) $topic_exists = true;
if (!$topic_exists) $topic_id = 0;

$domains = [];
$result = $conn->query("SELECT id,name FROM expertise_domains WHERE status='approved' ORDER BY name");
if ($result) $domains = $result->fetch_all(MYSQLI_ASSOC);

$profiles = [];
if ($topic_id > 0) {
    $stmt = $conn->prepare('SELECT p.*,u.username AS proposer_name FROM topic_expertise_profiles p LEFT JOIN users u ON u.id=p.proposed_by WHERE p.topic_id=? ORDER BY p.version_number DESC LIMIT 12');
    $stmt->bind_param('i', $topic_id);
    $stmt->execute();
    $profiles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
function tep_escape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>سهم حوزه‌های تخصصی موضوع</title>
<style>
body{font-family:Tahoma,Arial,sans-serif;background:#f4f7fb;color:#243447;margin:0;padding:16px;line-height:1.8}
.wrap{max-width:900px;margin:auto}.panel{background:#fff;border:1px solid #dce5ef;border-radius:12px;padding:15px;margin:12px 0}
label{display:block;font-weight:bold;margin-top:8px}select,input,button{font:inherit;padding:9px;border:1px solid #cbd5e1;border-radius:8px;max-width:100%;box-sizing:border-box}
select,input{width:100%;margin:4px 0 10px}button{background:#e5f0ff;cursor:pointer}.row{display:grid;grid-template-columns:minmax(0,2fr) minmax(90px,1fr) auto;gap:8px;align-items:end}
.muted{color:#64748b;font-size:.9em}.item{border-top:1px solid #e2e8f0;padding:12px 0}.tag{display:inline-block;background:#edf2f7;border-radius:6px;padding:2px 8px;font-size:.85em}
.actions{display:flex;gap:8px;flex-wrap:wrap}.danger{background:#fff0f0}.good{background:#e9f7ee}
@media(max-width:520px){body{padding:9px}.panel{padding:11px}.row{grid-template-columns:1fr 100px}.row button{grid-column:1/-1}}
</style>
</head>
<body>
<main class="wrap">
<h2>تعیین سهم حوزه‌های تخصصی هر موضوع</h2>
<p><a href="expertise.php">بازگشت به شایستگی‌های تخصصی</a> | <a href="profile.php">پروفایل</a></p>
<p class="muted">اعضای جامعه می‌توانند یک پروفایل چندحوزه‌ای پیشنهاد کنند. مجموع سهم‌ها باید دقیقاً ۱۰۰٪ باشد. رأی‌گیری هفت‌روزه است و تصویب به دست‌کم سه رأی و موافقت دوسوم آرا نیاز دارد. نسخهٔ تأییدشده تغییر نمی‌کند؛ اصلاح آن با نسخهٔ تازه انجام می‌شود.</p>

<section class="panel">
<form method="get">
<label>جامعه</label>
<select name="community_id" onchange="this.form.submit()">
<?php foreach ($communities as $c): ?>
<option value="<?= (int)$c['id'] ?>" <?= $community_id===(int)$c['id']?'selected':'' ?>><?= tep_escape($c['name']) ?></option>
<?php endforeach; ?>
</select>
<label>موضوع</label>
<select name="topic_id" onchange="this.form.submit()">
<option value="">انتخاب موضوع</option>
<?php foreach ($topics as $t): ?>
<option value="<?= (int)$t['id'] ?>" <?= $topic_id===(int)$t['id']?'selected':'' ?>>#<?= (int)$t['id'] ?> — <?= tep_escape($t['title']) ?></option>
<?php endforeach; ?>
</select>
</form>
</section>

<?php if ($topic_id > 0): ?>
<?php
$has_pending = false;
foreach ($profiles as $p) if ($p['status']==='pending') $has_pending = true;
?>
<?php if (!$has_pending && $domains): ?>
<section class="panel">
<h3>پیشنهاد پروفایل تخصصی جدید</h3>
<form method="post" action="topic_expertise_profile_action.php" id="profile-form">
<input type="hidden" name="csrf_token" value="<?= tep_escape($csrf) ?>">
<input type="hidden" name="action" value="submit">
<input type="hidden" name="community_id" value="<?= $community_id ?>">
<input type="hidden" name="topic_id" value="<?= $topic_id ?>">
<div id="domain-rows">
<div class="row domain-row">
<div><label>حوزهٔ تخصصی</label><select name="domain_id[]" required>
<option value="">انتخاب حوزه</option>
<?php foreach ($domains as $d): ?><option value="<?= (int)$d['id'] ?>"><?= tep_escape($d['name']) ?></option><?php endforeach; ?>
</select></div>
<div><label>سهم درصدی</label><input name="share_percent[]" type="number" min="0.01" max="100" step="0.01" value="100" required></div>
<button type="button" class="danger" onclick="removeDomainRow(this)" disabled>حذف</button>
</div>
</div>
<div class="actions"><button type="button" onclick="addDomainRow()">افزودن حوزه</button><button type="submit">ثبت پروفایل برای رأی‌گیری</button></div>
</form>
<p class="muted">نمونه: اگر موضوع به سه حوزه مرتبط باشد، سهم‌ها می‌توانند ۵۰٪، ۳۰٪ و ۲۰٪ باشند. سهم‌ها باید بر اساس ماهیت واقعی موضوع تعیین شوند.</p>
</section>
<?php elseif (!$domains): ?>
<section class="panel"><p>برای پیشنهاد پروفایل، ابتدا باید دست‌کم یک حوزهٔ تخصصی تأییدشده وجود داشته باشد.</p></section>
<?php else: ?>
<section class="panel"><p>برای این موضوع یک پروفایل در حال رأی‌گیری است. پس از تعیین نتیجه می‌توان نسخهٔ بعدی را پیشنهاد کرد.</p></section>
<?php endif; ?>

<section class="panel">
<h3>نسخه‌های پروفایل تخصصی موضوع</h3>
<?php if (!$profiles): ?><p>هنوز پروفایل تخصصی‌ای برای این موضوع ثبت نشده است.</p><?php endif; ?>
<?php foreach ($profiles as $p): ?>
<?php
$pid=(int)$p['id'];
$ds=$conn->prepare('SELECT d.name,pd.share_percent FROM topic_expertise_profile_domains pd JOIN expertise_domains d ON d.id=pd.domain_id WHERE pd.profile_id=? ORDER BY d.name');
$ds->bind_param('i',$pid);$ds->execute();$profile_domains=$ds->get_result()->fetch_all(MYSQLI_ASSOC);$ds->close();
$vs=$conn->prepare("SELECT COUNT(*) total,SUM(vote='yes') yes_count,SUM(vote='no') no_count,(SELECT vote FROM topic_expertise_profile_votes WHERE profile_id=? AND user_id=? LIMIT 1) my_vote FROM topic_expertise_profile_votes WHERE profile_id=?");
$vs->bind_param('iii',$pid,$user_id,$pid);$vs->execute();$votes=$vs->get_result()->fetch_assoc();$vs->close();
$ec=$conn->prepare('SELECT COUNT(*) AS total FROM topic_expertise_profile_electorate WHERE profile_id=?');
$ec->bind_param('i',$pid);$ec->execute();$eligible_count=(int)$ec->get_result()->fetch_assoc()['total'];$ec->close();
$expired=strtotime($p['expires_at'])<=time();
?>
<div class="item">
<b>نسخه <?= (int)$p['version_number'] ?></b> <span class="tag"><?= tep_escape($p['status']) ?></span>
<p class="muted">پیشنهاددهنده: <?= tep_escape($p['proposer_name'] ?? 'عضو جامعه') ?> | ثبت: <?= tep_escape($p['created_at']) ?></p>
<ul><?php foreach ($profile_domains as $pd): ?><li><?= tep_escape($pd['name']) ?>: <?= number_format((float)$pd['share_percent'],2) ?>٪</li><?php endforeach; ?></ul>
<?php if ($p['status']==='pending'): ?>
<p>رأی‌دهندگان مستقل: <?= $eligible_count ?> | موافق: <?= (int)$votes['yes_count'] ?> | مخالف: <?= (int)$votes['no_count'] ?> | پایان: <?= tep_escape($p['expires_at']) ?></p>
<?php if (!$expired): ?>
<form method="post" action="topic_expertise_profile_action.php" class="actions">
<input type="hidden" name="csrf_token" value="<?= tep_escape($csrf) ?>"><input type="hidden" name="action" value="vote">
<input type="hidden" name="community_id" value="<?= $community_id ?>"><input type="hidden" name="topic_id" value="<?= $topic_id ?>"><input type="hidden" name="profile_id" value="<?= $pid ?>">
<button name="vote" value="yes" class="good"><?= $votes['my_vote']==='yes'?'✓ رأی موافق ثبت‌شده':'موافق' ?></button>
<button name="vote" value="no" class="danger"><?= $votes['my_vote']==='no'?'✓ رأی مخالف ثبت‌شده':'مخالف' ?></button>
</form>
<?php else: ?>
<p class="muted">مهلت تمام شده است؛ هر عضو جامعه می‌تواند نتیجه را جمع‌بندی کند.</p>
<form method="post" action="topic_expertise_profile_action.php">
<input type="hidden" name="csrf_token" value="<?= tep_escape($csrf) ?>"><input type="hidden" name="action" value="finalize">
<input type="hidden" name="community_id" value="<?= $community_id ?>"><input type="hidden" name="topic_id" value="<?= $topic_id ?>"><input type="hidden" name="profile_id" value="<?= $pid ?>">
<button>جمع‌بندی رأی‌گیری</button></form>
<?php endif; ?>
<?php endif; ?>
</div>
<?php endforeach; ?>
</section>
<?php endif; ?>
</main>
<script>
function addDomainRow(){
 const host=document.getElementById('domain-rows');
 const first=host.querySelector('.domain-row');
 const clone=first.cloneNode(true);
 clone.querySelector('select').selectedIndex=0;
 clone.querySelector('input').value='';
 const remove=clone.querySelector('button');
 remove.disabled=false;
 remove.onclick=function(){removeDomainRow(remove);};
 host.appendChild(clone);
 updateDomainRemoveButtons();
}
function removeDomainRow(button){
 const rows=document.querySelectorAll('.domain-row');
 if(rows.length<=1)return;
 button.closest('.domain-row').remove();
 updateDomainRemoveButtons();
}
function updateDomainRemoveButtons(){
 const rows=document.querySelectorAll('.domain-row');
 rows.forEach(row=>row.querySelector('button').disabled=rows.length<=1);
}
</script>
</body></html>
