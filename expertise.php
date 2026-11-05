<?php
session_start();
include __DIR__ . "/db.php";
if(!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
$user_id=(int)$_SESSION['user_id'];
if(empty($_SESSION['community_duty_csrf'])) $_SESSION['community_duty_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['community_duty_csrf'];
$community_id=(int)($_GET['community_id']??0);
$communities=[];
$q=$conn->prepare("SELECT c.id,c.name FROM communities c JOIN community_members cm ON cm.community_id=c.id WHERE cm.user_id=? ORDER BY c.name");
$q->bind_param("i",$user_id); $q->execute(); $communities=$q->get_result()->fetch_all(MYSQLI_ASSOC); $q->close();
if($community_id<1 && $communities) $community_id=(int)$communities[0]['id'];
$is_member=false;
foreach($communities as $c) if((int)$c['id']===$community_id) $is_member=true;
if(!$is_member) $community_id=0;

// Finalization of expired applications and domain proposals is handled only by CLI maintenance.
$domainProposals=[];$domainVoteCounts=[];
if($community_id){
 $dp=$conn->prepare("SELECT d.id,d.name,d.description,d.status,d.created_by,d.created_at,d.decided_at,u.username proposer_name FROM expertise_domains d JOIN users u ON u.id=d.created_by WHERE d.proposed_in_community_id=? ORDER BY d.created_at DESC LIMIT 100");
 $dp->bind_param("i",$community_id);$dp->execute();$domainProposals=$dp->get_result()->fetch_all(MYSQLI_ASSOC);$dp->close();
 $dv=$conn->prepare("SELECT v.domain_id,SUM(v.vote='yes') yes_count,SUM(v.vote='no') no_count,MAX(v.user_id=?) my_vote FROM expertise_domain_votes v JOIN expertise_domains d ON d.id=v.domain_id WHERE d.proposed_in_community_id=? AND d.status='pending' AND d.decided_at IS NULL GROUP BY v.domain_id");
 $dv->bind_param("ii",$user_id,$community_id);$dv->execute();foreach($dv->get_result()->fetch_all(MYSQLI_ASSOC) as $vrow)$domainVoteCounts[(int)$vrow['domain_id']]=$vrow;$dv->close();
}
$domains=[];$d=$conn->query("SELECT id,name FROM expertise_domains WHERE status='approved' ORDER BY name");if($d)$domains=$d->fetch_all(MYSQLI_ASSOC);
$mine=[];$s=$conn->prepare("SELECT a.*,d.name domain_name FROM expertise_applications a JOIN expertise_domains d ON d.id=a.domain_id WHERE a.target_user_id=? ORDER BY a.created_at DESC LIMIT 30");$s->bind_param("i",$user_id);$s->execute();$mine=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
$open=[];if($community_id){$s=$conn->prepare("SELECT a.*,d.name domain_name,u.username FROM expertise_applications a JOIN expertise_domains d ON d.id=a.domain_id JOIN users u ON u.id=a.target_user_id WHERE a.community_id=? AND a.status='pending' AND a.decision_at IS NULL ORDER BY a.created_at DESC");$s->bind_param("i",$community_id);$s->execute();$open=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();}
function eh($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>شایستگی‌های تخصصی</title><style>
body{font-family:Tahoma,Arial,sans-serif;background:#f4f7fb;color:#243447;margin:0;padding:18px;line-height:1.8}.wrap{max-width:900px;margin:auto}.panel{background:white;border:1px solid #dce5ef;border-radius:12px;padding:16px;margin:14px 0}.row{display:flex;gap:10px;flex-wrap:wrap}input,select,textarea,button{font:inherit;padding:9px;border:1px solid #cbd5e1;border-radius:8px;max-width:100%}input,select,textarea{box-sizing:border-box;width:100%;margin:5px 0 12px}button{background:#e5f0ff;cursor:pointer}.muted{color:#64748b;font-size:.9em}.item{border-top:1px solid #e2e8f0;padding:12px 0}.tag{display:inline-block;background:#edf2f7;border-radius:6px;padding:2px 8px}
</style></head><body><main class="wrap"><h2>درخواست و ارزیابی شایستگی تخصصی</h2><p><a href="profile.php">بازگشت به پروفایل</a></p>
<form method="get" class="panel"><label>جامعه</label><select name="community_id" onchange="this.form.submit()"><?php foreach($communities as $c):?><option value="<?= (int)$c['id']?>" <?=$community_id===(int)$c['id']?'selected':''?>><?=eh($c['name'])?></option><?php endforeach;?></select><noscript><button>نمایش</button></noscript></form>
<?php if($community_id):?><section class="panel"><h3>ثبت درخواست تخصص</h3><p class="muted">درخواست برای خودتان ثبت می‌شود. ابتدا سه ارزیابی مستقل تکمیل می‌شود؛ سپس رأی‌گیری عمومی انجام می‌گیرد. تصویب نیازمند دوسوم آرای معتبر موافق است. اعتبار شایستگی سه سال است.</p>
<section class="panel"><h3>پیشنهاد و تأیید جمعی حوزهٔ تخصصی جدید</h3><p class="muted">هر عضو می‌تواند حوزه‌ای پیشنهاد کند. رأی‌گیری ۷ روز ادامه دارد؛ هر عضو یک رأی موافق یا مخالف دارد و می‌تواند رأی خود را تغییر دهد. تساوی به معنی تأیید نیست.</p>
<form method="post" action="expertise_domain_action.php"><input type="hidden" name="csrf_token" value="<?=eh($csrf)?>"><input type="hidden" name="action" value="propose"><input type="hidden" name="community_id" value="<?=$community_id?>">
<label>نام حوزه</label><input name="name" maxlength="150" required><label>توضیح و محدودهٔ حوزه</label><textarea name="description" rows="3" maxlength="5000" required></textarea><button>ثبت حوزه برای رأی‌گیری</button></form>
<h4>حوزه‌های پیشنهادی این جامعه</h4>
<?php if(!$domainProposals):?><p>هنوز حوزه‌ای پیشنهاد نشده است.</p><?php endif;?>
<?php foreach($domainProposals as $dp):$did=(int)$dp['id'];$counts=$domainVoteCounts[$did]??['yes_count'=>0,'no_count'=>0,'my_vote'=>0];?>
<div class="item"><b><?=eh($dp['name'])?></b> <span class="tag"><?=eh($dp['status'])?></span><p><?=nl2br(eh($dp['description']))?></p><p class="muted">پیشنهاددهنده: <?=eh($dp['proposer_name'])?> | ثبت: <?=eh($dp['created_at'])?></p>
<?php if($dp['status']==='pending' && !$dp['decided_at'] && strtotime($dp['created_at'].' +7 days')>time()):?><p>موافق: <?= (int)$counts['yes_count']?> | مخالف: <?= (int)$counts['no_count']?> | پایان رأی‌گیری: <?=eh(date('Y-m-d H:i',strtotime($dp['created_at'].' +7 days')))?></p>
<form method="post" action="expertise_domain_action.php" class="row"><input type="hidden" name="csrf_token" value="<?=eh($csrf)?>"><input type="hidden" name="action" value="vote"><input type="hidden" name="domain_id" value="<?=$did?>"><input type="hidden" name="community_id" value="<?=$community_id?>"><button name="vote" value="yes">موافق</button><button name="vote" value="no">مخالف</button></form>
<?php elseif($dp['status']==='pending' && $dp['decided_at']):?><p class="muted">رأی‌گیری بسته شد؛ به دلیل تساوی یا نبود اکثریت، حوزه تأیید نشده است.</p><?php elseif($dp['status']==='pending'):?><p class="muted">مهلت رأی‌گیری پایان یافته و نتیجه هنگام بارگذاری صفحه نهایی می‌شود.</p><?php endif;?></div><?php endforeach;?></section>
<form method="post" action="expertise_action.php"><input type="hidden" name="csrf_token" value="<?=eh($csrf)?>"><input type="hidden" name="action" value="submit"><input type="hidden" name="community_id" value="<?=$community_id?>">
<label>حوزه تخصصی</label><select name="domain_id" required><option value="">انتخاب حوزه</option><?php foreach($domains as $d):?><option value="<?=(int)$d['id']?>"><?=eh($d['name'])?></option><?php endforeach;?></select>
<label>سطح درخواستی</label><select name="requested_level" required><option value="1">سطح ۱ — وزن ۱٫۲۵</option><option value="2">سطح ۲ — وزن ۱٫۵</option><option value="3">سطح ۳ — وزن ۲</option><option value="4">سطح ۴ — وزن ۳</option></select><button>ثبت درخواست</button></form></section><?php endif;?>
<section class="panel"><h3>درخواست‌های من</h3><?php if(!$mine):?><p>هنوز درخواستی ثبت نکرده‌اید.</p><?php endif;?><?php foreach($mine as $a):?><div class="item"><b><?=eh($a['domain_name'])?></b> — سطح <?= (int)$a['requested_level']?> <span class="tag"><?=eh(['pending'=>'در انتظار رأی','approved'=>'تأییدشده','rejected'=>'ردشده','not_approved'=>'عدم تصویب؛ نرسیدن به حد نصاب','needs_renewal'=>'نیازمند تمدید','under_review'=>'در حال بررسی'][$a['status']]??$a['status'])?></span><p class="muted"><?php if(empty($a['expires_at'])):?>در انتظار تکمیل سه ارزیابی مستقل؛ پس از آن رأی‌گیری هفت‌روزه آغاز می‌شود.<?php else:?>مهلت رأی‌گیری تا <?=eh($a['expires_at'])?> است.<?php endif;?> | <?=$a['decision_at']?'رأی‌گیری بسته شده':'در انتظار رأی'?></p>
<?php if($a['status']==='pending' && !$a['decision_at']):?><form method="post" action="expertise_action.php"><input type="hidden" name="csrf_token" value="<?=eh($csrf)?>"><input type="hidden" name="action" value="evidence"><input type="hidden" name="application_id" value="<?=(int)$a['id']?>"><label>عنوان مدرک</label><input name="title" required maxlength="200"><label>شرح و توضیح</label><textarea name="description" required rows="3"></textarea><label>پیوند مرجع (اختیاری)</label><input name="reference_url" type="url"><button>افزودن مدرک</button></form><?php endif;?>
<?php $ev=$conn->prepare("SELECT title,description,reference_url FROM expertise_application_evidence WHERE application_id=? ORDER BY id");$aid=(int)$a['id'];$ev->bind_param("i",$aid);$ev->execute();$evidence=$ev->get_result()->fetch_all(MYSQLI_ASSOC);$ev->close();foreach($evidence as $e):?><p><b><?=eh($e['title'])?></b><br><?=nl2br(eh($e['description']))?> <?php if($e['reference_url']):?><a href="<?=eh($e['reference_url'])?>" rel="noopener" target="_blank">مرجع</a><?php endif;?></p><?php endforeach;?></div><?php endforeach;?></section>
<?php if($community_id):?><section class="panel"><h3>درخواست‌های در انتظار رأی جامعه</h3><?php if(!$open):?><p>درخواستی برای رأی‌گیری وجود ندارد.</p><?php endif;?><?php foreach($open as $a):?><div class="item"><b><?=eh($a['username'])?></b> — <?=eh($a['domain_name'])?>، سطح <?=(int)$a['requested_level']?><p class="muted"><?php if(empty($a['expires_at'])):?>در انتظار تکمیل سه ارزیابی مستقل؛ پس از آن مهلت هفت‌روزهٔ رأی‌گیری آغاز می‌شود.<?php else:?>پایان مهلت رأی‌گیری: <?=eh($a['expires_at'])?><?php endif;?></p>
<?php $aid=(int)$a['id'];$ev=$conn->prepare("SELECT title,description,reference_url FROM expertise_application_evidence WHERE application_id=? ORDER BY id");$ev->bind_param("i",$aid);$ev->execute();$evidence=$ev->get_result()->fetch_all(MYSQLI_ASSOC);$ev->close();foreach($evidence as $e):?><p><b><?=eh($e['title'])?></b><br><?=nl2br(eh($e['description']))?> <?php if($e['reference_url']):?><a href="<?=eh($e['reference_url'])?>" target="_blank" rel="noopener">مرجع</a><?php endif;?></p><?php endforeach;?>
<?php
$rv=$conn->prepare("SELECT COUNT(*) total,AVG(theoretical_score*0.20+practical_score*0.30+achievements_score*0.30+professional_score*0.20) weighted_score FROM expertise_application_reviews WHERE application_id=? AND status='submitted' AND conflict_of_interest='none'");
$rv->bind_param("i",$aid);$rv->execute();$reviewStats=$rv->get_result()->fetch_assoc();$reviewCount=(int)$reviewStats['total'];$reviewScore=$reviewStats['weighted_score'];$rv->close();
$mineReview=$conn->prepare("SELECT status FROM expertise_application_reviews WHERE application_id=? AND reviewer_user_id=? LIMIT 1");
$mineReview->bind_param("ii",$aid,$user_id);$mineReview->execute();$myReview=$mineReview->get_result()->fetch_assoc();$mineReview->close();
$requesterCheck=$conn->prepare("SELECT 1 FROM expertise_application_requesters WHERE application_id=? AND requester_user_id=? LIMIT 1");
$requesterCheck->bind_param("ii",$aid,$user_id);$requesterCheck->execute();$isRequester=(bool)$requesterCheck->get_result()->fetch_row();$requesterCheck->close();
?>
<p class="muted">ارزیابی مستقل تکمیل‌شده: <?=$reviewCount?> از حداقل ۳<?php if($reviewScore!==null):?> | میانگین امتیاز وزنی: <?=number_format((float)$reviewScore,2)?> از ۱۰۰<?php endif;?></p>
<?php if((int)$a['target_user_id']!==$user_id && !$isRequester && !$myReview && $reviewCount<3):?>
<form method="post" action="expertise_action.php" class="row"><input type="hidden" name="csrf_token" value="<?=eh($csrf)?>"><input type="hidden" name="action" value="review_join"><input type="hidden" name="application_id" value="<?=$aid?>"><button>پذیرش مسئولیت ارزیابی</button></form>
<?php elseif($myReview && $myReview['status']==='pending' && $reviewCount<3):?>
<form method="post" action="expertise_action.php"><input type="hidden" name="csrf_token" value="<?=eh($csrf)?>"><input type="hidden" name="action" value="review_submit"><input type="hidden" name="application_id" value="<?=$aid?>">
<label>دانش نظری و علمی (۲۰٪)</label><input type="number" name="theoretical_score" min="0" max="100" required>
<label>تجربه عملی (۳۰٪)</label><input type="number" name="practical_score" min="0" max="100" required>
<label>دستاوردهای قابل اثبات (۳۰٪)</label><input type="number" name="achievements_score" min="0" max="100" required>
<label>ارزیابی حرفه‌ای مستقل (۲۰٪)</label><input type="number" name="professional_score" min="0" max="100" required>
<label>تعارض منافع</label><select name="conflict_of_interest"><option value="none">تعارض منافع ندارم</option><option value="declared">تعارض منافع دارم و کنار می‌روم</option></select>
<label>توضیحات</label><textarea name="review_notes" rows="3"></textarea><button>ثبت ارزیابی مستقل</button></form>
<?php endif;?>
<?php if((int)$a['target_user_id']!==$user_id && !$isRequester && $reviewCount>=3):?><form method="post" action="expertise_action.php" class="row"><input type="hidden" name="csrf_token" value="<?=eh($csrf)?>"><input type="hidden" name="action" value="vote"><input type="hidden" name="application_id" value="<?=$aid?>"><button name="vote" value="yes">موافق</button><button name="vote" value="no">مخالف</button></form>
<?php elseif((int)$a['target_user_id']!==$user_id && !$isRequester):?><p class="muted">رأی نهایی پس از تکمیل سه ارزیابی مستقل فعال می‌شود.</p><?php elseif($isRequester):?><p class="muted">شما درخواست‌دهندهٔ این پرونده هستید و نمی‌توانید ارزیاب یا رأی‌دهنده باشید.</p><?php else:?><p class="muted">شما متقاضی این درخواست هستید و نمی‌توانید در ارزیابی یا رأی‌گیری آن شرکت کنید.</p><?php endif;?></div><?php endforeach;?></section><?php endif;?>
</main></body></html>
