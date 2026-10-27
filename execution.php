<?php
session_start();

// Temporary diagnostic handler for the execution page's HTTP 500.
// It avoids exposing diagnostic details to visitors who are not logged in.
set_exception_handler(function ($e) {
    error_log('execution.php failure: ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    if (!empty($_SESSION['user_id'])) {
        $detail = htmlspecialchars(get_class($e) . ': ' . $e->getMessage(), ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>خطای صفحهٔ اجرا</title><body style="font-family:tahoma;max-width:850px;margin:30px auto;padding:16px;line-height:2"><h2>خطا در صفحهٔ نظارت و اجرای طرح</h2><p>این پیام برای تشخیص خطاست؛ لطفاً متن زیر را برای پشتیبانی بفرستید.</p><pre style="white-space:pre-wrap;overflow-wrap:anywhere;background:#f3f4f6;padding:12px;border-radius:8px">' . $detail . '</pre><p><a href="javascript:history.back()">بازگشت</a></p></body></html>';
    } else {
        echo 'خطای داخلی سرور. لطفاً دوباره وارد شوید.';
    }
    exit;
});

include __DIR__ . '/db.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$user_id = (int)$_SESSION['user_id'];
$topic_id = (int)($_GET['topic_id'] ?? 0);
$idea_id = (int)($_GET['idea_id'] ?? 0);
if ($topic_id < 1 || $idea_id < 1) { http_response_code(400); exit('شناسهٔ طرح معتبر نیست.'); }
$schema = $conn->query("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('topic_execution_candidate_plans','topic_execution_plans','topic_execution_updates','topic_execution_elections')");
if (!$schema || (int)$schema->fetch_assoc()['n'] < 4) {
  echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="font-family:tahoma;max-width:800px;margin:30px auto;padding:16px"><h2>نظارت و اجرای طرح</h2><p>ساختار پایگاه دادهٔ این بخش هنوز نصب نشده است. فایل migrations/20261027_topic_execution_monitoring.sql باید طبق روش امن پروژه اجرا شود.</p><a href="javascript:history.back()">بازگشت</a></body></html>'; exit;
}
$stmt=$conn->prepare("SELECT t.id,t.title,t.description,t.community_id,c.name AS community_name,i.content AS idea_content,e.final_candidate_user_id,e.finalized_at,COALESCE(cp.initial_plan,'') AS initial_plan,COALESCE(cp.estimated_days,0) AS estimated_days FROM topics t JOIN communities c ON c.id=t.community_id JOIN topic_winners w ON w.topic_id=t.id AND w.idea_id=? JOIN topic_voting_meta m ON m.topic_id=t.id AND m.finalized=1 JOIN topic_execution_elections e ON e.topic_id=t.id AND e.idea_id=w.idea_id AND e.final_candidate_user_id IS NOT NULL JOIN ideas i ON i.id=w.idea_id LEFT JOIN topic_execution_candidate_plans cp ON cp.topic_id=t.id AND cp.idea_id=w.idea_id AND cp.user_id=e.final_candidate_user_id WHERE t.id=? LIMIT 1");
$stmt->bind_param('ii',$idea_id,$topic_id); $stmt->execute(); $plan=$stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$plan) { http_response_code(404); exit('طرح برنده یا مجری نهایی پیدا نشد.'); }
$community_id=(int)$plan['community_id'];
$finance_schema_q=$conn->query("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('community_finance_settings','community_finance_transactions','community_assets','community_asset_movements','community_budget_requests','community_budget_request_votes')");
$finance_tables_ready=$finance_schema_q && (int)$finance_schema_q->fetch_assoc()['n']===6;
$finance_columns_q=$conn->query("SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='community_budget_requests' AND column_name IN ('request_type','topic_id','idea_id','execution_plan_id')");
$finance_columns_ready=$finance_columns_q && (int)$finance_columns_q->fetch_assoc()['n']===4;
$finance_schema_ready=$finance_tables_ready && $finance_columns_ready;
$member=$conn->prepare("SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1");$member->bind_param('ii',$community_id,$user_id);$member->execute();$is_member=(bool)$member->get_result()->fetch_assoc();$member->close();
if (!$is_member) { http_response_code(403); exit('فقط اعضای این جامعه می‌توانند صفحهٔ نظارت را ببینند.'); }
if (empty($_SESSION['execution_monitor_csrf'])) $_SESSION['execution_monitor_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['execution_monitor_csrf']; $message='';
$state=$conn->prepare("SELECT * FROM topic_execution_plans WHERE topic_id=? AND idea_id=? LIMIT 1");$state->bind_param('ii',$topic_id,$idea_id);$state->execute();$execution=$state->get_result()->fetch_assoc();$state->close();
if (!$execution) {
  $ins=$conn->prepare("INSERT IGNORE INTO topic_execution_plans (topic_id,idea_id,community_id,executor_user_id,initial_plan, status) VALUES (?,?,?,?,?,'preparing')");
  $executor=(int)$plan['final_candidate_user_id'];$initial=(string)$plan['initial_plan'];$ins->bind_param('iiiis',$topic_id,$idea_id,$community_id,$executor,$initial);$ins->execute();$ins->close();
  $state=$conn->prepare("SELECT * FROM topic_execution_plans WHERE topic_id=? AND idea_id=? LIMIT 1");$state->bind_param('ii',$topic_id,$idea_id);$state->execute();$execution=$state->get_result()->fetch_assoc();$state->close();
}
$pid=(int)$execution['id'];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!hash_equals($csrf,(string)($_POST['csrf_token']??''))) { http_response_code(403); exit('درخواست معتبر نیست؛ صفحه را تازه‌سازی کنید.'); }
  $action=(string)($_POST['action']??'');
  if ($action==='save_plan' && $user_id===(int)$plan['final_candidate_user_id']) {
    $detail=trim((string)($_POST['detailed_plan']??''));$timeline=trim((string)($_POST['timeline']??''));$status=(string)($_POST['status']??'preparing');
    if (!in_array($status,['preparing','in_progress','blocked','completed'],true)) $status='preparing';
    if (mb_strlen($detail)>20000 || mb_strlen($timeline)>12000) { $message='متن برنامه بیش از حد طولانی است.'; }
    else {
      $upd=$conn->prepare("UPDATE topic_execution_plans SET detailed_plan=?,timeline=?,status=?,started_at=CASE WHEN ?='in_progress' AND started_at IS NULL THEN NOW() ELSE started_at END,completed_at=CASE WHEN ?='completed' THEN COALESCE(completed_at,NOW()) WHEN ?<>'completed' THEN NULL ELSE completed_at END WHERE id=? AND executor_user_id=?");
      $pid=(int)$execution['id'];$upd->bind_param('ssssssii',$detail,$timeline,$status,$status,$status,$status,$pid,$user_id);$upd->execute();$upd->close();
      $type='progress';$title='به‌روزرسانی برنامهٔ اجرایی';$body='مجری برنامهٔ تفصیلی، زمان‌بندی یا وضعیت اجرا را به‌روزرسانی کرد.';
      $log=$conn->prepare("INSERT INTO topic_execution_updates (execution_plan_id,user_id,update_type,title,body) VALUES (?,?,?,?,?)");$log->bind_param('iisss',$pid,$user_id,$type,$title,$body);$log->execute();$log->close();$message='برنامهٔ اجرایی ذخیره شد.';
    }
  } elseif ($action==='add_finance_request') {
    if (!$finance_schema_ready) {
      $message='بانک مالی هنوز نصب نشده است؛ پس از اجرای migration بانک مالی، درخواست را ثبت کنید.';
    } elseif ($user_id!==(int)$plan['final_candidate_user_id']) {
      $message='ثبت درخواست مالی مرتبط با اجرا فقط برای مجری منتخب فعال است.';
    } else {
      $request_type=(string)($_POST['request_type']??'budget');
      $title=trim((string)($_POST['finance_title']??''));
      $description=trim((string)($_POST['finance_description']??''));
      $amount=filter_var($_POST['requested_amount']??null,FILTER_VALIDATE_FLOAT);
      if (!in_array($request_type,['budget','expense'],true) || $title==='' || mb_strlen($title)>200 || $description==='' || mb_strlen($description)>10000 || $amount===false || $amount<=0 || $amount>9999999999999999) {
        $message='نوع، عنوان، شرح و مبلغ درخواست مالی را بررسی کنید.';
      } else {
        $pid=(int)$execution['id'];
        $ins=$conn->prepare("INSERT INTO community_budget_requests (community_id,requested_by,title,description,requested_amount,request_type,topic_id,idea_id,execution_plan_id,status) VALUES (?,?,?,?,?,?,?,?,?,'voting')");
        $ins->bind_param('iissdsiii',$community_id,$user_id,$title,$description,$amount,$request_type,$topic_id,$idea_id,$pid);
        if ($ins->execute()) {
          $request_id=(int)$conn->insert_id;
          $type='decision';$log_title='ثبت درخواست مالی برای طرح';$log_body=($request_type==='budget'?'درخواست بودجه':'درخواست هزینه')." با مبلغ ".$amount." ثبت شد. شناسه درخواست: ".$request_id;
          $log=$conn->prepare("INSERT INTO topic_execution_updates (execution_plan_id,user_id,update_type,title,body) VALUES (?,?,?,?,?)");
          $log->bind_param('iisss',$pid,$user_id,$type,$log_title,$log_body);$log->execute();$log->close();
          $message='درخواست مالی برای همین طرح ثبت شد و در اختیار اعضا قرار گرفت؛ رأی‌گیری به‌تنهایی پرداخت را انجام نمی‌دهد.';
        } else $message='ثبت درخواست مالی انجام نشد؛ بانک مالی و ساختار ارتباط آن را بررسی کنید.';
        $ins->close();
      }
    }
  } elseif ($action==='vote_finance_request') {
    if (!$finance_schema_ready) {
      $message='ساختار بانک مالی هنوز نصب نشده است.';
    } else {
      $request_id=(int)($_POST['request_id']??0);
      $choice=(string)($_POST['vote_choice']??'');
      if ($request_id<1 || !in_array($choice,['approve','reject','abstain'],true)) {
        $message='رأی معتبر نیست.';
      } else {
        $rq=$conn->prepare("SELECT id FROM community_budget_requests WHERE id=? AND community_id=? AND topic_id=? AND idea_id=? AND execution_plan_id=? AND status='voting' LIMIT 1");
        $pid=(int)$execution['id'];$rq->bind_param('iiiii',$request_id,$community_id,$topic_id,$idea_id,$pid);$rq->execute();
        $valid=(bool)$rq->get_result()->fetch_assoc();$rq->close();
        if (!$valid) $message='این درخواست مالی مربوط به همین طرح نیست یا رأی‌گیری آن باز نیست.';
        else {
          $vote=$conn->prepare("INSERT INTO community_budget_request_votes (request_id,community_id,user_id,vote_choice) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE vote_choice=VALUES(vote_choice),voted_at=CURRENT_TIMESTAMP");
          $vote->bind_param('iiis',$request_id,$community_id,$user_id,$choice);
          if ($vote->execute()) $message='رأی شما ثبت شد؛ این رأی به‌تنهایی مجوز پرداخت نیست.';
          else $message='ثبت رأی انجام نشد.';
          $vote->close();
        }
      }
    }
  } elseif ($action==='add_update') {
    $types=['progress','discussion','problem','delay','misconduct','response'];
    $type=(string)($_POST['update_type']??'discussion');if(!in_array($type,$types,true))$type='discussion';
    $title=trim((string)($_POST['title']??''));$body=trim((string)($_POST['body']??''));
    if ($title==='' || $body==='' || mb_strlen($title)>200 || mb_strlen($body)>10000) $message='عنوان و متن گزارش را کامل و در محدودهٔ مجاز وارد کنید.';
    else {
      $pid=(int)$execution['id'];$log=$conn->prepare("INSERT INTO topic_execution_updates (execution_plan_id,user_id,update_type,title,body) VALUES (?,?,?,?,?)");$log->bind_param('iisss',$pid,$user_id,$type,$title,$body);$log->execute();$log->close();$message='گزارش شما ثبت شد.';
    }
  }
  $state=$conn->prepare("SELECT * FROM topic_execution_plans WHERE topic_id=? AND idea_id=? LIMIT 1");$state->bind_param('ii',$topic_id,$idea_id);$state->execute();$execution=$state->get_result()->fetch_assoc();$state->close();
}
$finance_requests=[];
if ($finance_schema_ready) {
  $fr=$conn->prepare("SELECT r.*,COALESCE(u.username,CONCAT('عضو ',r.requested_by)) AS requester,
    (SELECT COUNT(*) FROM community_budget_request_votes v WHERE v.request_id=r.id AND v.vote_choice='approve') AS approvals,
    (SELECT COUNT(*) FROM community_budget_request_votes v WHERE v.request_id=r.id AND v.vote_choice='reject') AS rejections,
    (SELECT COUNT(*) FROM community_budget_request_votes v WHERE v.request_id=r.id AND v.vote_choice='abstain') AS abstentions,
    (SELECT v.vote_choice FROM community_budget_request_votes v WHERE v.request_id=r.id AND v.user_id=? LIMIT 1) AS my_vote
    FROM community_budget_requests r LEFT JOIN users u ON u.id=r.requested_by
    WHERE r.community_id=? AND r.topic_id=? AND r.idea_id=? AND r.execution_plan_id=?
    ORDER BY r.created_at DESC,r.id DESC LIMIT 100");
  $pid=(int)$execution['id'];$fr->bind_param('iiiii',$user_id,$community_id,$topic_id,$idea_id,$pid);$fr->execute();$frr=$fr->get_result();while($r=$frr->fetch_assoc())$finance_requests[]=$r;$fr->close();
}
$updates=[];$uq=$conn->prepare("SELECT u.*,COALESCE(us.username,CONCAT('عضو ',u.user_id)) AS username FROM topic_execution_updates u LEFT JOIN users us ON us.id=u.user_id WHERE u.execution_plan_id=? ORDER BY u.created_at DESC,u.id DESC LIMIT 200");$pid=(int)$execution['id'];$uq->bind_param('i',$pid);$uq->execute();$ur=$uq->get_result();while($r=$ur->fetch_assoc())$updates[]=$r;$uq->close();
$executor_name='';$us=$conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1");$executor=(int)$plan['final_candidate_user_id'];$us->bind_param('i',$executor);$us->execute();$executor_name=$us->get_result()->fetch_assoc()['username']??('عضو '.$executor);$us->close();
function em_h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function em_date($v){
 if(!$v)return '—';$ts=strtotime($v);if(!$ts)return '—';
 $gy=(int)date('Y',$ts);$gm=(int)date('n',$ts);$gd=(int)date('j',$ts);
 $gdm=[31,28,31,30,31,30,31,31,30,31,30,31];$jy=0;
 if($gy>1600){$jy=979;$gy-=1600;}else{$jy=0;$gy-=621;}
 $gy2=($gm>2)?$gy+1:$gy;
 $days=(365*$gy)+(int)(($gy2+3)/4)-(int)(($gy2+99)/100)+(int)(($gy2+399)/400)-80+$gd;
 for($i=0;$i<$gm-1;$i++)$days+=$gdm[$i];
 $jy+=33*(int)($days/12053);$days%=12053;$jy+=4*(int)($days/1461);$days%=1461;
 if($days>365){$jy+=(int)(($days-1)/365);$days=($days-1)%365;}
 if($days<186){$jm=1+(int)($days/31);$jd=1+($days%31);}else{$days-=186;$jm=7+(int)($days/30);$jd=1+($days%30);}
 $fa=strtr(sprintf('%04d/%02d/%02d %02d:%02d',$jy,$jm,$jd,(int)date('H',$ts),(int)date('i',$ts)),['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
 return $fa;
}
$labels=['preparing'=>'آماده‌سازی','in_progress'=>'در حال اجرا','blocked'=>'دارای مانع','completed'=>'تکمیل‌شده','progress'=>'گزارش پیشرفت','discussion'=>'هم‌اندیشی','problem'=>'مشکل اجرایی','delay'=>'گزارش تأخیر','misconduct'=>'گزارش تخلف احتمالی','response'=>'پاسخ مجری'];
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>نظارت و اجرای طرح</title>
<style>body{margin:0;padding:14px;background:#f2f6fb;color:#263548;font-family:tahoma,Arial,sans-serif;line-height:1.9}.wrap{max-width:950px;margin:auto}.box{background:white;border:1px solid #dce5ef;border-radius:12px;padding:15px;margin:12px 0;overflow-wrap:anywhere}.hero{background:#eaf4ff;border-color:#bad8f8}.muted{font-size:13px;color:#667085}.field{display:block;width:100%;box-sizing:border-box;font:inherit;padding:9px;border:1px solid #cbd5e1;border-radius:7px;margin:5px 0 12px}button,.btn{display:inline-block;border:0;border-radius:7px;background:#2065d1;color:#fff;padding:9px 14px;font:inherit;text-decoration:none;cursor:pointer}.notice{background:#e9f8ec;border:1px solid #b7dfbf;padding:9px;border-radius:7px}.entry{border-top:1px solid #e5eaf0;padding:12px 0}.entry:first-child{border-top:0}.tag{display:inline-block;background:#edf3fc;border-radius:5px;padding:1px 7px;font-size:12px}</style></head><body><main class="wrap">
<p><a class="btn" href="community.php?id=<?= $community_id ?>">بازگشت به جامعه</a> <a class="btn" href="archived_topics.php?community_id=<?= $community_id ?>">بازگشت به آرشیو</a> <a class="btn" href="finance.php?community_id=<?= $community_id ?>">🏦 بانک مالی جامعه</a></p>
<h1>🔎 نظارت و اجرای طرح</h1><section class="box hero"><div class="muted"><?= em_h($plan['community_name']) ?></div><h2><?= em_h($plan['title']) ?></h2><h3>طرح منتخب</h3><div><?= nl2br(em_h($plan['idea_content'])) ?></div><p>مجری منتخب: <strong><?= em_h($executor_name) ?></strong></p><p>برنامهٔ اولیهٔ ارائه‌شده هنگام نامزدی:</p><div><?= nl2br(em_h($plan['initial_plan'])) ?></div><p>مدت تخمینی اولیه: <?= $plan['estimated_days'] ? (int)$plan['estimated_days'].' روز' : 'اعلام نشده' ?></p><p>وضعیت فعلی: <strong><?= em_h($labels[$execution['status']]??$execution['status']) ?></strong></p></section>
<?php if($message!==''): ?><p class="notice"><?= em_h($message) ?></p><?php endif; ?>
<section class="box"><h2>برنامهٔ تفصیلی و زمان‌بندی اعلام‌شده</h2><?php if(trim((string)($execution['detailed_plan']??''))===''): ?><p class="muted">مجری هنوز جزئیات برنامه را ثبت نکرده است.</p><?php else: ?><div><?= nl2br(em_h($execution['detailed_plan'])) ?></div><?php endif; ?><h3>زمان‌بندی مراحل</h3><?php if(trim((string)($execution['timeline']??''))===''): ?><p class="muted">زمان‌بندی تفصیلی هنوز اعلام نشده است.</p><?php else: ?><div><?= nl2br(em_h($execution['timeline'])) ?></div><?php endif; ?></section>
<?php if($user_id===(int)$plan['final_candidate_user_id']): ?><section class="box"><h2>برنامهٔ تفصیلی مجری</h2><p class="muted">برنامهٔ اولیه برای رأی‌گیری ثبت شده بود؛ در اینجا جزئیات مراحل و زمان‌بندی را اعلام و در طول اجرا به‌روزرسانی کنید.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= em_h($csrf) ?>"><input type="hidden" name="action" value="save_plan"><label>جزئیات مراحل اجرا<textarea class="field" name="detailed_plan" rows="6" maxlength="20000"><?= em_h($execution['detailed_plan']??'') ?></textarea></label><label>زمان‌بندی و مهلت هر مرحله<textarea class="field" name="timeline" rows="5" maxlength="12000"><?= em_h($execution['timeline']??'') ?></textarea></label><label>وضعیت اجرا<select class="field" name="status"><?php foreach(['preparing','in_progress','blocked','completed'] as $st): ?><option value="<?= $st ?>" <?= $execution['status']===$st?'selected':'' ?>><?= em_h($labels[$st]) ?></option><?php endforeach; ?></select></label><button type="submit">ذخیرهٔ برنامه و وضعیت</button></form></section><?php endif; ?>
<section class="box"><h2>💰 درخواست بودجه یا هزینهٔ این طرح</h2>
<p class="muted">درخواست در همین صفحه به طرح منتخب پیوند می‌خورد و در بانک مالی جامعه نیز دیده می‌شود. ثبت درخواست یا رأی مثبت به معنی پرداخت نیست.</p>
<?php if(!$finance_schema_ready): ?><p class="notice">برای فعال‌شدن این بخش، ابتدا فایل migrations/20261027_community_finance_assets.sql باید در پایگاه دادهٔ هاست اجرا شود.</p>
<?php else: ?>
<?php if($user_id===(int)$plan['final_candidate_user_id']): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= em_h($csrf) ?>"><input type="hidden" name="action" value="add_finance_request"><label>نوع درخواست<select class="field" name="request_type"><option value="budget">درخواست بودجه برای طرح</option><option value="expense">درخواست هزینهٔ مشخص</option></select></label><label>عنوان<input class="field" name="finance_title" maxlength="200" required></label><label>مبلغ درخواستی<input class="field" name="requested_amount" type="number" min="0.01" step="0.01" required></label><label>شرح، ضرورت و جزئیات مصرف<textarea class="field" name="finance_description" rows="4" maxlength="10000" required></textarea></label><button type="submit">ثبت درخواست مالی برای رأی اعضا</button></form><?php else: ?><p class="muted">درخواست مالی مرتبط با اجرا را مجری منتخب ثبت می‌کند؛ همهٔ اعضا می‌توانند درخواست‌ها را ببینند و رأی دهند.</p><?php endif; ?>
<?php if(!$finance_requests): ?><p class="muted">هنوز درخواست مالی برای این طرح ثبت نشده است.</p><?php else: foreach($finance_requests as $r): ?><article class="entry"><strong><?= em_h($r['title']) ?></strong> <span class="tag"><?= $r['request_type']==='expense'?'درخواست هزینه':'درخواست بودجه' ?></span> <span class="tag"><?= em_h(['draft'=>'پیش‌نویس','voting'=>'در حال رأی‌گیری','approved'=>'تصویب‌شده','rejected'=>'ردشده','allocated'=>'بودجه تخصیص‌یافته','completed'=>'تکمیل‌شده','cancelled'=>'لغوشده'][$r['status']]??$r['status']) ?></span><div class="muted">درخواست‌دهنده: <?= em_h($r['requester']) ?> · مبلغ: <?= number_format((float)$r['requested_amount'],2) ?></div><div><?= nl2br(em_h($r['description'])) ?></div><p>موافق: <?= (int)$r['approvals'] ?> · مخالف: <?= (int)$r['rejections'] ?> · ممتنع: <?= (int)$r['abstentions'] ?></p><?php if($r['status']==='voting'): ?><form method="post" class="vote-buttons"><input type="hidden" name="csrf_token" value="<?= em_h($csrf) ?>"><input type="hidden" name="action" value="vote_finance_request"><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>"><?php foreach(['approve'=>'موافق','reject'=>'مخالف','abstain'=>'ممتنع'] as $v=>$label): ?><button type="submit" name="vote_choice" value="<?= $v ?>" style="background:<?= $r['my_vote']===$v?'#167647':'#2065d1' ?>"><?= $r['my_vote']===$v?'✓ رأی شما: ':'رأی ' ?><?= $label ?></button><?php endforeach; ?></form><?php endif; ?><p class="muted">رأی‌گیری هنوز به‌صورت خودکار نهایی نمی‌شود و رأی موافق مجوز پرداخت نیست.</p></article><?php endforeach; endif; ?>
<?php endif; ?></section>
<section class="box"><h2>ثبت گزارش، پرسش یا هم‌اندیشی</h2><p class="muted">اعضا می‌توانند پیشرفت، مشکل، تأخیر یا تخلف احتمالی را گزارش کنند. گزارش تخلف به‌تنهایی اثبات تخلف نیست و باید بررسی شود.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= em_h($csrf) ?>"><input type="hidden" name="action" value="add_update"><label>نوع ثبت<select class="field" name="update_type"><?php foreach(['discussion','progress','problem','delay','misconduct','response'] as $st): ?><option value="<?= $st ?>"><?= em_h($labels[$st]) ?></option><?php endforeach; ?></select></label><label>عنوان<input class="field" name="title" maxlength="200" required></label><label>شرح<textarea class="field" name="body" rows="5" maxlength="10000" required></textarea></label><button type="submit">ثبت در سابقهٔ نظارت</button></form></section>
<section class="box"><h2>سابقهٔ اجرا و نظارت (<?= count($updates) ?>)</h2><?php if(!$updates): ?><p class="muted">هنوز گزارشی ثبت نشده است.</p><?php else: foreach($updates as $u): ?><article class="entry"><span class="tag"><?= em_h($labels[$u['update_type']]??$u['update_type']) ?></span><strong><?= em_h($u['title']) ?></strong><div class="muted"><?= em_h($u['username']) ?> — <?= em_h(em_date($u['created_at'])) ?></div><div><?= nl2br(em_h($u['body'])) ?></div></article><?php endforeach; endif; ?></section>
</main></body></html>