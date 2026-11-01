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

/* نهایی‌سازی فقط پس از پایان مهلت؛ در تساوی درخواست pending می‌ماند اما decision_at رأی‌گیری را می‌بندد. */
$expired=$conn->prepare("SELECT id FROM expertise_applications WHERE community_id=? AND status='pending' AND decision_at IS NULL AND expires_at<=NOW()");
if($expired && $community_id){$expired->bind_param("i",$community_id);$expired->execute();$ids=$expired->get_result()->fetch_all(MYSQLI_ASSOC);$expired->close();
 foreach($ids as $item){
  $conn->begin_transaction();
  try{
   $id=(int)$item['id']; $lock=$conn->prepare("SELECT * FROM expertise_applications WHERE id=? FOR UPDATE");$lock->bind_param("i",$id);$lock->execute();$a=$lock->get_result()->fetch_assoc();$lock->close();
   if($a && $a['status']==='pending' && $a['decision_at']===null && strtotime($a['expires_at'])<=time()){
    $cnt=$conn->prepare("SELECT SUM(vote='yes') yes_count,SUM(vote='no') no_count FROM expertise_application_votes WHERE application_id=?");$cnt->bind_param("i",$id);$cnt->execute();$votes=$cnt->get_result()->fetch_assoc();$cnt->close();
    $yes=(int)($votes['yes_count']??0);$no=(int)($votes['no_count']??0);
    if($yes>$no){
      $level=(int)$a['requested_level'];$weight=[1=>1.25,2=>1.50,3=>2.00,4=>2.50][$level]??1.00;
      $up=$conn->prepare("INSERT INTO expertise_competencies (user_id,domain_id,level,weight,approved_in_community_id,source_application_id,valid_from,valid_until) VALUES (?,?,?,?,?,?,NOW(),DATE_ADD(NOW(),INTERVAL 1 YEAR)) ON DUPLICATE KEY UPDATE level=VALUES(level),weight=VALUES(weight),approved_in_community_id=VALUES(approved_in_community_id),source_application_id=VALUES(source_application_id),valid_from=NOW(),valid_until=DATE_ADD(NOW(),INTERVAL 1 YEAR)");
      $up->bind_param("iiidii",$a['target_user_id'],$a['domain_id'],$level,$weight,$a['community_id'],$id);$up->execute();$up->close();
      $status='approved';
    } elseif($no>$yes){$status='rejected';} else {$status='pending';}
    $fin=$conn->prepare("UPDATE expertise_applications SET status=?,decision_at=NOW() WHERE id=? AND decision_at IS NULL");$fin->bind_param("si",$status,$id);$fin->execute();$fin->close();
    $detail="نتیجه رأی‌گیری ۷روزه: موافق ".$yes."، مخالف ".$no.($yes===$no?"؛ تساوی و بسته‌شدن رأی‌گیری":"");
    $hist=$conn->prepare("INSERT INTO expertise_application_history (application_id,actor_user_id,event_type,details) VALUES (?,NULL,'voting_closed',?)");$hist->bind_param("is",$id,$detail);$hist->execute();$hist->close();
   }
   $conn->commit();
  }catch(Throwable $e){$conn->rollback();error_log("Expertise finalization: ".$e->getMessage());}
 }
}
/* بستن رأی‌گیری حوزه‌های تخصصی پس از ۷ روز؛ تساوی بدون تأیید باقی می‌ماند. */
if($community_id){
 $expiredDomains=$conn->prepare("SELECT id FROM expertise_domains WHERE proposed_in_community_id=? AND status='pending' AND decided_at IS NULL AND created_at<=DATE_SUB(NOW(),INTERVAL 7 DAY)");
 $expiredDomains->bind_param("i",$community_id);$expiredDomains->execute();$expiredIds=$expiredDomains->get_result()->fetch_all(MYSQLI_ASSOC);$expiredDomains->close();
 foreach($expiredIds as $expiredDomain){
  $did=(int)$expiredDomain['id'];$conn->begin_transaction();
  try{
   $lock=$conn->prepare("SELECT id,status,decided_at FROM expertise_domains WHERE id=? FOR UPDATE");$lock->bind_param("i",$did);$lock->execute();$domainRow=$lock->get_result()->fetch_assoc();$lock->close();
   if($domainRow && $domainRow['status']==='pending' && $domainRow['decided_at']===null){
    $cnt=$conn->prepare("SELECT SUM(vote='yes') yes_count,SUM(vote='no') no_count FROM expertise_domain_votes WHERE domain_id=?");$cnt->bind_param("i",$did);$cnt->execute();$vr=$cnt->get_result()->fetch_assoc();$cnt->close();
    $yes=(int)($vr['yes_count']??0);$no=(int)($vr['no_count']??0);
    if($yes>$no){$up=$conn->prepare("UPDATE expertise_domains SET status='approved',approved_at=NOW(),decided_at=NOW() WHERE id=? AND status='pending' AND decided_at IS NULL");}
    elseif($no>$yes){$up=$conn->prepare("UPDATE expertise_domains SET status='rejected',decided_at=NOW() WHERE id=? AND status='pending' AND decided_at IS NULL");}
    else{$up=$conn->prepare("UPDATE expertise_domains SET decided_at=NOW() WHERE id=? AND status='pending' AND decided_at IS NULL");}
    $up->bind_param("i",$did);$up->execute();$up->close();
   }
   $conn->commit();
  }catch(Throwable $e){$conn->rollback();error_log("Expertise domain finalization: ".$e->getMessage());}
 }
}
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
<?php if($community_id):?><section class="panel"><h3>ثبت درخواست تخصص</h3><p class="muted">درخواست برای خودتان ثبت می‌شود. اعضای جامعه ۷ روز فرصت رأی دارند. در صورت تأیید، اعتبار شایستگی یک سال است.</p>
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
<label>سطح درخواستی</label><select name="requested_level" required><option value="1">سطح ۱ — وزن ۱٫۲۵</option><option value="2">سطح ۲ — وزن ۱٫۵</option><option value="3">سطح ۳ — وزن ۲</option><option value="4">سطح ۴ — وزن ۲٫۵</option></select><button>ثبت درخواست</button></form></section><?php endif;?>
<section class="panel"><h3>درخواست‌های من</h3><?php if(!$mine):?><p>هنوز درخواستی ثبت نکرده‌اید.</p><?php endif;?><?php foreach($mine as $a):?><div class="item"><b><?=eh($a['domain_name'])?></b> — سطح <?= (int)$a['requested_level']?> <span class="tag"><?=eh($a['status'])?></span><p class="muted">مهلت رأی: <?=eh($a['expires_at'])?> | <?=$a['decision_at']?'رأی‌گیری بسته شده':'در انتظار رأی'?></p>
<?php if($a['status']==='pending' && !$a['decision_at']):?><form method="post" action="expertise_action.php"><input type="hidden" name="csrf_token" value="<?=eh($csrf)?>"><input type="hidden" name="action" value="evidence"><input type="hidden" name="application_id" value="<?=(int)$a['id']?>"><label>عنوان مدرک</label><input name="title" required maxlength="200"><label>شرح و توضیح</label><textarea name="description" required rows="3"></textarea><label>پیوند مرجع (اختیاری)</label><input name="reference_url" type="url"><button>افزودن مدرک</button></form><?php endif;?>
<?php $ev=$conn->prepare("SELECT title,description,reference_url FROM expertise_application_evidence WHERE application_id=? ORDER BY id");$aid=(int)$a['id'];$ev->bind_param("i",$aid);$ev->execute();$evidence=$ev->get_result()->fetch_all(MYSQLI_ASSOC);$ev->close();foreach($evidence as $e):?><p><b><?=eh($e['title'])?></b><br><?=nl2br(eh($e['description']))?> <?php if($e['reference_url']):?><a href="<?=eh($e['reference_url'])?>" rel="noopener" target="_blank">مرجع</a><?php endif;?></p><?php endforeach;?></div><?php endforeach;?></section>
<?php if($community_id):?><section class="panel"><h3>درخواست‌های در انتظار رأی جامعه</h3><?php if(!$open):?><p>درخواستی برای رأی‌گیری وجود ندارد.</p><?php endif;?><?php foreach($open as $a):?><div class="item"><b><?=eh($a['username'])?></b> — <?=eh($a['domain_name'])?>، سطح <?=(int)$a['requested_level']?><p class="muted">پایان مهلت: <?=eh($a['expires_at'])?></p>
<?php $aid=(int)$a['id'];$ev=$conn->prepare("SELECT title,description,reference_url FROM expertise_application_evidence WHERE application_id=? ORDER BY id");$ev->bind_param("i",$aid);$ev->execute();$evidence=$ev->get_result()->fetch_all(MYSQLI_ASSOC);$ev->close();foreach($evidence as $e):?><p><b><?=eh($e['title'])?></b><br><?=nl2br(eh($e['description']))?> <?php if($e['reference_url']):?><a href="<?=eh($e['reference_url'])?>" target="_blank" rel="noopener">مرجع</a><?php endif;?></p><?php endforeach;?>
<?php if((int)$a['target_user_id']!==$user_id):?><form method="post" action="expertise_action.php" class="row"><input type="hidden" name="csrf_token" value="<?=eh($csrf)?>"><input type="hidden" name="action" value="vote"><input type="hidden" name="application_id" value="<?=$aid?>"><button name="vote" value="yes">موافق</button><button name="vote" value="no">مخالف</button></form><?php else:?><p class="muted">شما متقاضی این درخواست هستید و نمی‌توانید به آن رأی دهید.</p><?php endif;?></div><?php endforeach;?></section><?php endif;?>
</main></body></html>
