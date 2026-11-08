<?php
session_start();
require __DIR__ . '/db.php';
require_once __DIR__ . '/community_law_conformity_helpers.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$user_id=(int)$_SESSION['user_id'];
$community_id=(int)($_GET['community_id']??0);
if ($community_id<=0) { http_response_code(400); exit('شناسه جامعه معتبر نیست.'); }
$community_stmt=$conn->prepare('SELECT id,name FROM communities WHERE id=? LIMIT 1');
$community_stmt->bind_param('i',$community_id); $community_stmt->execute();
$community=$community_stmt->get_result()->fetch_assoc(); $community_stmt->close();
if (!$community) { http_response_code(404); exit('جامعه پیدا نشد.'); }
$is_member=community_law_conformity_is_member($conn,$community_id,$user_id);
if (!$is_member) { http_response_code(403); exit('فقط اعضای جامعه می‌توانند پرونده‌های مرتبط را مشاهده کنند.'); }
if (empty($_SESSION['community_law_conformity_csrf'])) $_SESSION['community_law_conformity_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['community_law_conformity_csrf'];
function clcc_h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
$status=(string)($_GET['status']??'');
$messages=[
 'schema'=>'ساختار پرونده‌های هم‌ترازی هنوز در پایگاه داده نصب نشده است.',
 'conflict_added'=>'مادهٔ مورد اختلاف ثبت شد و برای بررسی دوطرفه در دسترس قرار گرفت.',
 'conflict_failed'=>'ثبت مادهٔ مورد اختلاف انجام نشد؛ عضویت جامعهٔ محلی و اطلاعات ارسالی را بررسی کنید.',
 'review_saved'=>'بررسی شما ثبت شد؛ نتیجهٔ نهایی به بررسی مستقل هر دو جامعه وابسته است.',
 'review_failed'=>'بررسی ثبت نشد؛ ممکن است عضو جامعهٔ مربوط نباشید، ثبت‌کنندهٔ تعارض باشید یا قبلاً بررسی کرده باشید.',
 'not_member'=>'برای این اقدام باید عضو جامعه باشید.',
 'invalid_action'=>'درخواست نامعتبر است.'
];
$ready=community_law_conformity_tables_ready($conn);
$cases=[];
if ($ready) {
 $stmt=$conn->prepare(
  "SELECT c.*,ll.law_code AS local_law_code,lv.title AS local_title,
          pl.law_code AS parent_law_code,pv.title AS parent_title,
          lc.name AS local_community_name,pc.name AS parent_community_name
   FROM community_law_conformity_cases c
   JOIN community_laws ll ON ll.id=c.local_law_id
   JOIN community_law_versions lv ON lv.id=c.local_law_version_id
   JOIN community_laws pl ON pl.id=c.parent_law_id
   JOIN community_law_versions pv ON pv.id=c.parent_law_version_id
   JOIN communities lc ON lc.id=c.community_id
   JOIN communities pc ON pc.id=c.parent_community_id
   WHERE c.community_id=? OR c.parent_community_id=?
   ORDER BY c.created_at DESC,c.id DESC LIMIT 100"
 );
 if ($stmt) {
  $stmt->bind_param('ii',$community_id,$community_id);
  if ($stmt->execute()) { $res=$stmt->get_result(); while($row=$res->fetch_assoc()) $cases[]=$row; }
  $stmt->close();
 }
}
$labels=['review_required'=>'در انتظار بررسی','amendment_required'=>'اصلاحیه لازم است','amendment_in_progress'=>'اصلاحیه در حال پیگیری','resolved'=>'سازگاری تأیید شده','closed'=>'بسته‌شده'];
?>
<!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>پرونده‌های هم‌ترازی قوانین</title>
<style>
body{font-family:Tahoma,Arial,sans-serif;background:#f5f6f8;color:#20242a;margin:0;padding:16px;line-height:1.8}.container{max-width:900px;margin:auto}.card{background:#fff;border:1px solid #ddd;border-radius:10px;padding:16px;margin:12px 0}.muted{color:#666;font-size:.92em}.notice{background:#f0f4f8;padding:10px;border-radius:7px;margin:10px 0}label{display:block;margin-top:9px}input,textarea,select{box-sizing:border-box;width:100%;padding:9px;border:1px solid #bbb;border-radius:6px;font:inherit}textarea{min-height:85px}button{margin-top:10px;padding:9px 15px;border:0;border-radius:6px;background:#245b91;color:#fff;font:inherit;cursor:pointer}details{border-top:1px solid #ddd;padding:10px 0}summary{cursor:pointer;font-weight:bold}pre{white-space:pre-wrap;overflow-wrap:anywhere}
</style></head><body><div class="container">
<div class="card"><h2>پرونده‌های هم‌ترازی قوانین — <?=clcc_h($community['name'])?></h2>
<p class="muted">این پرونده‌ها تعارض قطعی را از پیش فرض نمی‌گیرند. هر مورد باید با ارجاع دقیق به مواد دو قانون و بررسی مستقل جامعهٔ محلی و مادر ارزیابی شود.</p>
<p><a href="community_laws.php?community_id=<?=$community_id?>">بازگشت به قوانین جامعه</a></p></div>
<?php if(isset($messages[$status])): ?><div class="card notice"><?=clcc_h($messages[$status])?></div><?php endif; ?>
<?php if(!$ready): ?><div class="card notice">Migration پرونده‌های هم‌ترازی هنوز در پایگاه داده نصب نشده است.</div>
<?php elseif(!$cases): ?><div class="card">پرونده‌ای برای این جامعه ثبت نشده است.</div>
<?php else: foreach($cases as $case):
 $case_id=(int)$case['id']; $is_local=(int)$case['community_id']===$community_id; $conflicts=[];
 $q=$conn->prepare("SELECT f.*, (SELECT COUNT(*) FROM community_law_conformity_conflict_reviews r WHERE r.conflict_id=f.id) AS review_count,
   (SELECT COUNT(*) FROM community_law_conformity_conflict_reviews r WHERE r.conflict_id=f.id AND r.reviewer_user_id=?) AS my_review,
   (SELECT COUNT(*) FROM community_law_conformity_conflict_reviews r WHERE r.conflict_id=f.id AND r.reviewer_community_id=?) AS side_review
   FROM community_law_conformity_conflicts f WHERE f.case_id=? ORDER BY f.id");
 if($q){$q->bind_param('iii',$user_id,$community_id,$case_id);if($q->execute()){$rr=$q->get_result();while($x=$rr->fetch_assoc())$conflicts[]=$x;}$q->close();}
?>
<div class="card">
<h3>پرونده #<?=$case_id?> — <?=clcc_h($case['local_title'])?></h3>
<div class="muted">جامعهٔ محلی: <?=clcc_h($case['local_community_name'])?> | قانون محلی: <?=clcc_h($case['local_law_code'])?>، نسخه #<?= (int)$case['local_law_version_id']?><br>
جامعهٔ مادر: <?=clcc_h($case['parent_community_name'])?> | قانون مادر: <?=clcc_h($case['parent_title'])?>، نسخه #<?= (int)$case['parent_law_version_id']?><br>
وضعیت: <?=clcc_h($labels[$case['status']]??$case['status'])?></div>
<?php if($is_local && in_array($case['status'],['review_required','amendment_required'],true)): ?>
<details><summary>ثبت مادهٔ مورد اختلاف</summary><form method="post" action="community_law_conformity_action.php">
<input type="hidden" name="csrf_token" value="<?=clcc_h($csrf)?>"><input type="hidden" name="community_id" value="<?=$community_id?>"><input type="hidden" name="case_id" value="<?=$case_id?>">
<label>شماره یا ارجاع مادهٔ قانون محلی</label><input name="local_article_reference" maxlength="120" required>
<label>شماره یا ارجاع مادهٔ قانون مادر</label><input name="parent_article_reference" maxlength="120" required>
<label>شرح دقیق ناسازگاری احتمالی</label><textarea name="conflict_description" maxlength="30000" required></textarea>
<label>شواهد و متن مواد مرتبط</label><textarea name="evidence" maxlength="30000"></textarea>
<button name="action" value="add_conflict">ثبت مورد برای بررسی</button></form></details>
<?php endif; ?>
<?php if(!$conflicts): ?><p class="muted">هنوز مادهٔ مورد اختلافی ثبت نشده است.</p><?php endif; ?>
<?php foreach($conflicts as $f): ?>
<details><summary>مورد #<?=(int)$f['id']?> — <?=clcc_h($f['local_article_reference'])?> ↔ <?=clcc_h($f['parent_article_reference'])?></summary>
<p><?=nl2br(clcc_h($f['conflict_description']))?></p><p class="muted">وضعیت بررسی: <?=clcc_h($f['finding'])?> | بررسی‌ها: <?=(int)$f['review_count']?> از ۲</p>
<?php if($f['evidence']): ?><pre><?=clcc_h($f['evidence'])?></pre><?php endif; ?>
<?php if((int)$f['identified_by']!==$user_id && (int)$f['my_review']===0 && (int)$f['side_review']===0 && in_array($case['status'],['review_required','amendment_required'],true)): ?>
<form method="post" action="community_law_conformity_action.php">
<input type="hidden" name="csrf_token" value="<?=clcc_h($csrf)?>"><input type="hidden" name="community_id" value="<?=$community_id?>"><input type="hidden" name="conflict_id"><?php /* conflict id set below */ ?>
<input type="hidden" name="conflict_id" value="<?=(int)$f['id']?>"><input type="hidden" name="reviewer_community_id" value="<?=$community_id?>">
<label>نتیجهٔ بررسی مستقل</label><select name="finding" required><option value="conflict_confirmed">تعارض تأیید می‌شود</option><option value="no_conflict">تعارض تأیید نمی‌شود</option></select>
<label>استدلال مستند (حداقل ۲۰ نویسه)</label><textarea name="reasoning" minlength="20" maxlength="20000" required></textarea><label>شواهد تکمیلی</label><textarea name="evidence" maxlength="20000"></textarea>
<button name="action" value="review_conflict">ثبت بررسی</button></form>
<?php endif; ?>
<?php $reviews=$conn->prepare("SELECT reviewer_community_id,finding,reasoning,evidence,created_at FROM community_law_conformity_conflict_reviews WHERE conflict_id=? ORDER BY id"); if($reviews){$reviews->bind_param('i',$f['id']);if($reviews->execute()){$res=$reviews->get_result();while($rv=$res->fetch_assoc()): ?><div class="notice"><strong><?= (int)$rv['reviewer_community_id']===(int)$case['community_id']?'دیدگاه جامعهٔ محلی':'دیدگاه جامعهٔ مادر' ?> — <?=clcc_h($rv['finding'])?></strong><p><?=nl2br(clcc_h($rv['reasoning']))?></p><?php if($rv['evidence']): ?><pre><?=clcc_h($rv['evidence'])?></pre><?php endif; ?></div><?php endwhile;}$reviews->close();} ?>
</details>
<?php endforeach; ?>
</div>
<?php endforeach; endif; ?>
</div></body></html>