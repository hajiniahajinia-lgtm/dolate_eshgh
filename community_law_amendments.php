<?php
session_start();
require __DIR__.'/db.php';
require_once __DIR__.'/community_law_helpers.php';
require_once __DIR__.'/community_law_amendment_helpers.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$user_id=(int)$_SESSION['user_id'];
$community_id=(int)($_GET['community_id']??$_POST['community_id']??0);
if ($community_id<=0) { http_response_code(400); exit('شناسه جامعه معتبر نیست.'); }
$community_stmt=$conn->prepare('SELECT id,name FROM communities WHERE id=? LIMIT 1');
$community_stmt->bind_param('i',$community_id); $community_stmt->execute();
$community=$community_stmt->get_result()->fetch_assoc(); $community_stmt->close();
if (!$community) { http_response_code(404); exit('جامعه پیدا نشد.'); }
$member_stmt=$conn->prepare('SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1');
$member_stmt->bind_param('ii',$community_id,$user_id); $member_stmt->execute();
$is_member=(bool)$member_stmt->get_result()->fetch_row(); $member_stmt->close();
if (empty($_SESSION['community_law_amendment_csrf'])) $_SESSION['community_law_amendment_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['community_law_amendment_csrf'];
if (empty($_SESSION['community_law_csrf'])) $_SESSION['community_law_csrf']=bin2hex(random_bytes(32));
$law_csrf=$_SESSION['community_law_csrf'];
function clamp_h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
$status=(string)($_GET['status']??'');
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!$is_member || !isset($_POST['csrf_token']) || !is_string($_POST['csrf_token']) ||
        !hash_equals($csrf,$_POST['csrf_token'])) { http_response_code(403); exit('درخواست معتبر نیست.'); }
    if (isset($_POST['submit_amendment_for_vote'])) {
        $submitted=community_law_submit_amendment_for_vote(
            $conn,(int)($_POST['amendment_id']??0),$community_id,$user_id,
            isset($_POST['adopt_base_rules']) && (string)$_POST['adopt_base_rules']==='1'
        );
        header('Location: community_law_amendments.php?community_id='.$community_id.'&status='.($submitted?'submitted_for_vote':'submission_failed'));
        exit;
    }
    $articles=[];
    for ($i=1;$i<=5;$i++) {
        $reference=trim((string)($_POST['article_reference_'.$i]??''));
        $previous=trim((string)($_POST['previous_text_'.$i]??''));
        $proposed=trim((string)($_POST['proposed_text_'.$i]??''));
        $reason=trim((string)($_POST['article_reason_'.$i]??''));
        if ($reference==='' && $previous==='' && $proposed==='' && $reason==='') continue;
        $articles[]=['reference'=>$reference,'previous_text'=>$previous,'proposed_text'=>$proposed,'reason'=>$reason];
    }
    $result=community_law_create_amendment_draft(
        $conn,(int)($_POST['conformity_case_id']??0),$community_id,$user_id,
        trim((string)($_POST['rationale']??'')),$articles,
        trim((string)($_POST['evidence']??''))?:null
    );
    header('Location: community_law_amendments.php?community_id='.$community_id.'&status='.($result===false?'save_failed':'draft_created'));
    exit;
}
$schema_ready=community_law_amendment_tables_ready($conn);
$cases=[];
if ($schema_ready) {
    $stmt=$conn->prepare(
        "SELECT c.id AS conformity_case_id,c.parent_community_id,c.review_summary,
                l.id AS law_id,l.law_code,v.id AS version_id,v.version_number,v.title,v.law_text,
                (SELECT COUNT(*) FROM community_law_conformity_conflicts f
                 WHERE f.case_id=c.id AND f.finding='conflict_confirmed') AS confirmed_conflicts,
                (SELECT COUNT(*) FROM community_law_amendment_cases a
                 WHERE a.conformity_case_id=c.id AND a.status IN ('draft','submitted','local_voting','parent_review')) AS open_amendments
         FROM community_law_conformity_cases c
         JOIN community_laws l ON l.id=c.local_law_id
         JOIN community_law_versions v ON v.id=c.local_law_version_id
         WHERE c.community_id=? AND c.status='amendment_required'
           AND l.originating_community_id=? AND l.status='approved'
           AND l.current_version_id=v.id AND v.status='approved'
         ORDER BY c.updated_at DESC,c.id DESC"
    );
    if ($stmt) {
        $stmt->bind_param('ii',$community_id,$community_id);
        if ($stmt->execute()) { $res=$stmt->get_result(); while($row=$res->fetch_assoc()) $cases[]=$row; }
        $stmt->close();
    }
}
$drafts=[];
if ($schema_ready) {
    $stmt=$conn->prepare(
        "SELECT a.id,a.status,a.rationale,a.created_at,a.base_version_id,a.proposed_version_id,
                v.version_number,v.law_text,v.status AS version_status,v.vote_deadline,v.vote_started_at,
                l.id AS law_id,l.current_version_id,l.law_code,a.voting_rules_source_version_id
         FROM community_law_amendment_cases a
         JOIN community_law_versions v ON v.id=a.proposed_version_id
         JOIN community_laws l ON l.id=a.law_id
         WHERE a.community_id=? ORDER BY a.id DESC LIMIT 30"
    );
    if ($stmt) {
        $stmt->bind_param('i',$community_id);
        if ($stmt->execute()) { $res=$stmt->get_result(); while($row=$res->fetch_assoc()) $drafts[]=$row; }
        $stmt->close();
    }
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>پیشنهاد اصلاح قوانین — <?=clamp_h($community['name'])?></title>
<style>
body{font-family:Tahoma,Arial,sans-serif;background:#f3f6fa;color:#1e293b;margin:0;padding:10px;line-height:1.9}
main{max-width:900px;margin:auto}.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:15px;margin:12px 0}
h2,h3{margin:0 0 10px}.muted{color:#64748b;font-size:13px}.notice{background:#eff6ff;border-right:4px solid #2563eb;padding:10px;margin:10px 0}
.warning{background:#fffbeb;border-right-color:#d97706}label{display:block;font-weight:bold;margin:9px 0 4px}
input,textarea,button{box-sizing:border-box;width:100%;font:inherit;padding:9px;border:1px solid #cbd5e1;border-radius:7px}
textarea{min-height:90px}button{background:#1e40af;color:white;border:0;margin-top:12px;font-weight:bold}
.article{border:1px solid #e2e8f0;border-radius:9px;padding:10px;margin:12px 0}
pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#f8fafc;padding:10px;border-radius:8px}
a{color:#1d4ed8}@media(max-width:600px){body{padding:5px}.card{padding:11px}input,textarea,button{font-size:16px}}
</style>
</head>
<body><main>
<section class="card">
<h2>پیشنهاد اصلاح قوانین محلی — <?=clamp_h($community['name'])?></h2>
<p class="muted">این بخش فقط برای پرونده‌هایی فعال است که تعارض ماده‌ای آن‌ها با قانون مادر مستند و تأیید شده باشد. متن مصوب قبلی تغییر نمی‌کند؛ پیشنهاد، نسخهٔ تازه و مستقل می‌سازد.</p>
<p><a href="community_laws.php?community_id=<?=$community_id?>">بازگشت به قوانین جامعه</a> — <a href="community_law_conformity_cases.php?community_id=<?=$community_id?>">پرونده‌های هم‌ترازی</a></p>
</section>
<?php if($status==='submitted_for_vote'):?><section class="card notice">اصلاحیه برای رأی محلی ارسال شد. قانون مصوب جاری بدون تغییر باقی مانده است.</section><?php elseif($status==='submission_failed'):?><section class="card notice warning">ارسال انجام نشد؛ عضویت، وضعیت نسخهٔ مبنا و وجود قواعد رأی‌گیریِ مصوب را بررسی کنید.</section><?php elseif($status==='draft_created'):?><section class="card notice">پیش‌نویس اصلاحیه و نسخهٔ تازه ساخته شد. هنوز هیچ رأی‌گیری یا تغییر قانونی انجام نشده است.</section><?php elseif($status==='save_failed'):?><section class="card notice warning">پیش‌نویس ساخته نشد. عضویت، پروندهٔ تعارض، متن دقیق ماده و نبود اصلاحیهٔ باز را بررسی کنید.</section><?php endif;?>
<?php if(!$schema_ready):?>
<section class="card notice warning">ساختار اصلاحیه هنوز نصب نشده است؛ این صفحه هیچ تغییری در داده‌ها ایجاد نمی‌کند.</section>
<?php elseif(!$is_member):?>
<section class="card">برای پیشنهاد اصلاح قانون محلی باید عضو همین جامعه باشید.</section>
<?php else:?>
<section class="card">
<h3>پرونده‌های آمادهٔ اصلاح</h3>
<?php if(!$cases):?><p class="muted">در حال حاضر پروندهٔ دارای تعارض تأییدشده و نسخهٔ مصوب جاری برای این جامعه وجود ندارد.</p><?php endif;?>
<?php foreach($cases as $case):?>
<details class="article">
<summary>پرونده انطباق #<?=(int)$case['conformity_case_id']?> — <?=clamp_h($case['title'])?> — <?=clamp_h($case['law_code'])?></summary>
<p class="muted">نسخهٔ مصوب مبنا: <?= (int)$case['version_number']?> — تعارض‌های تأییدشده: <?= (int)$case['confirmed_conflicts']?></p>
<pre><?=clamp_h($case['law_text'])?></pre>
<?php if((int)$case['open_amendments']>0):?>
<p class="notice warning">برای این قانون یک اصلاحیهٔ باز وجود دارد؛ تا تعیین تکلیف آن، پیشنهاد موازی ثبت نمی‌شود.</p>
<?php else:?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?=clamp_h($csrf)?>">
<input type="hidden" name="community_id" value="<?=$community_id?>">
<input type="hidden" name="conformity_case_id" value="<?=(int)$case['conformity_case_id']?>">
<label>دلیل کلی اصلاحیه</label><textarea name="rationale" required maxlength="30000" placeholder="توضیح دهید چرا اصلاح مواد مشخص‌شده برای رفع تعارض لازم است."></textarea>
<label>شواهد تکمیلی (اختیاری)</label><textarea name="evidence" maxlength="30000"></textarea>
<?php for($i=1;$i<=5;$i++):?>
<div class="article">
<strong>مادهٔ قابل اصلاح <?=$i?></strong>
<label>شماره یا ارجاع ماده</label><input name="article_reference_<?=$i?>" maxlength="120" <?=$i===1?'required':''?>>
<label>متن فعلی ماده (عیناً از نسخهٔ مصوب)</label><textarea name="previous_text_<?=$i?>" <?=$i===1?'required':''?>></textarea>
<label>متن پیشنهادی جدید</label><textarea name="proposed_text_<?=$i?>" <?=$i===1?'required':''?>></textarea>
<label>دلیل تغییر این ماده</label><textarea name="article_reason_<?=$i?>" <?=$i===1?'required':''?>></textarea>
</div>
<?php endfor;?>
<p class="muted">برای هر ماده، متن قبلی باید دقیقاً یک‌بار در نسخهٔ مصوب وجود داشته باشد. بخش‌های دیگر قانون بدون تغییر منتقل می‌شوند. حداکثر پنج ماده در هر پیشنهاد قابل اصلاح است.</p>
<button type="submit">ساخت پیش‌نویس اصلاحیه</button>
</form>
<?php endif;?>
</details>
<?php endforeach;?>
</section>
<section class="card">
<h3>پیش‌نویس‌ها و نسخه‌های پیشنهادی</h3>
<?php if(!$drafts):?><p class="muted">هنوز پیش‌نویس اصلاحیه‌ای ثبت نشده است.</p><?php else:?>
<?php foreach($drafts as $draft):
$amendment_version_id=(int)$draft['proposed_version_id'];
$can_amendment_community_vote=false;
$can_amendment_direct_vote=false;
if($is_member && $draft['status']==='local_voting') {
    $eligible_stmt=$conn->prepare('SELECT 1 FROM community_law_electorate WHERE law_version_id=? AND voting_community_id=? AND user_id=? LIMIT 1');
    if($eligible_stmt) {
        $eligible_stmt->bind_param('iii',$amendment_version_id,$community_id,$user_id);
        if($eligible_stmt->execute()) $can_amendment_community_vote=(bool)$eligible_stmt->get_result()->fetch_row();
        $eligible_stmt->close();
    }
    $direct_stmt=$conn->prepare('SELECT 1 FROM community_law_direct_electorate WHERE law_version_id=? AND user_id=? LIMIT 1');
    if($direct_stmt) {
        $direct_stmt->bind_param('ii',$amendment_version_id,$user_id);
        if($direct_stmt->execute()) $can_amendment_direct_vote=(bool)$direct_stmt->get_result()->fetch_row();
        $direct_stmt->close();
    }
}
?><details class="article"><summary>اصلاحیه #<?=(int)$draft['id']?> — نسخهٔ پیشنهادی <?=(int)$draft['version_number']?> — <?=clamp_h($draft['status'])?></summary>
<p><?=clamp_h($draft['rationale'])?></p><pre><?=clamp_h($draft['law_text'])?></pre>
<?php if($draft['status']==='draft'):?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?=clamp_h($csrf)?>">
<input type="hidden" name="community_id" value="<?=$community_id?>">
<input type="hidden" name="amendment_id" value="<?=(int)$draft['id']?>">
<label><input type="checkbox" name="adopt_base_rules" value="1" required style="width:auto"> می‌پذیرم که همین قواعد رأی‌گیریِ قبلاً تصویب‌شده برای نسخهٔ مبنا، بدون تغییر و با ثبت منشأ، مبنای رأی‌گیری این اصلاحیه باشد.</label>
<p class="muted">قواعد جدیدی ساخته یا بی‌سابقه تصویب نمی‌شود؛ نسخهٔ دقیق قواعد مصوب مبنا برای این اصلاحیه snapshot می‌شود. اگر قواعد مصوبی برای نسخهٔ مبنا وجود نداشته باشد، ارسال ممکن نیست.</p>
<button type="submit" name="submit_amendment_for_vote" value="1">ارسال اصلاحیه برای رأی جامعهٔ محلی</button>
</form>
<?php elseif($draft['status']==='submitted'):?>
<p class="notice">اصلاحیه ارسال شده است. قواعد رأی‌گیری از نسخهٔ مصوب مبنا گرفته شده و منشأ آن ثبت شده است؛ قانون جاری هنوز تغییر نکرده است.</p>
<form method="post" action="community_laws.php?community_id=<?=$community_id?>">
<input type="hidden" name="csrf_token" value="<?=clamp_h($law_csrf)?>">
<input type="hidden" name="law_id" value="<?=(int)$draft['law_id']?>">
<input type="hidden" name="version_id" value="<?=$amendment_version_id?>">
<button type="submit" name="start_law_vote" value="1">آغاز رأی‌گیری اصلاحیه در جامعهٔ محلی</button>
</form>
<?php elseif($draft['status']==='local_voting'):?>
<p class="notice">رأی‌گیری اصلاحیه در جریان است. مهلت پایان: <?=clamp_h($draft['vote_deadline'])?>. قانون مصوب قبلی همچنان جاری است.</p>
<?php if($can_amendment_community_vote):?>
<form method="post" action="community_laws.php?community_id=<?=$community_id?>">
<input type="hidden" name="csrf_token" value="<?=clamp_h($law_csrf)?>">
<input type="hidden" name="version_id" value="<?=$amendment_version_id?>">
<label>رأی جامعهٔ محلی</label><select name="vote"><option value="yes">موافق</option><option value="no">مخالف</option><option value="abstain">ممتنع</option></select>
<label>توضیح (اختیاری)</label><textarea name="comment" maxlength="5000"></textarea>
<button type="submit" name="cast_law_vote" value="1">ثبت یا اصلاح رأی جامعه‌ای</button>
</form>
<?php endif;?>
<?php if($can_amendment_direct_vote):?>
<form method="post" action="community_laws.php?community_id=<?=$community_id?>">
<input type="hidden" name="csrf_token" value="<?=clamp_h($law_csrf)?>">
<input type="hidden" name="version_id" value="<?=$amendment_version_id?>">
<label>رأی مستقیم فردی</label><select name="direct_vote"><option value="yes">موافق</option><option value="no">مخالف</option><option value="abstain">ممتنع</option></select>
<label>توضیح (اختیاری)</label><textarea name="direct_comment" maxlength="5000"></textarea>
<button type="submit" name="cast_law_direct_vote" value="1">ثبت یا اصلاح رأی مستقیم</button>
</form>
<?php endif;?>
<?php if($is_member && !empty($draft['vote_deadline']) && strtotime($draft['vote_deadline'])<=time()):?>
<form method="post" action="community_laws.php?community_id=<?=$community_id?>">
<input type="hidden" name="csrf_token" value="<?=clamp_h($law_csrf)?>">
<input type="hidden" name="law_id" value="<?=(int)$draft['law_id']?>">
<input type="hidden" name="version_id" value="<?=$amendment_version_id?>">
<button type="submit" name="finalize_law_tally" value="1">محاسبهٔ نتیجهٔ رأی‌گیری</button>
</form>
<?php endif;?>
<?php elseif($draft['status']==='parent_review'):?>
<p class="notice">رأی محلی اصلاحیه پذیرفته شده است. مرحلهٔ بعد، بررسی انطباق با قانون مادر است؛ تا پایان آن قانون جاری تغییر نمی‌کند.</p>
<?php elseif($draft['status']==='vote_insufficient'):?>
<p class="notice warning">حدنصاب مشارکت رأی‌گیری تأمین نشد؛ این نتیجه به‌معنای رد ماهوی اصلاحیه نیست.</p>
<?php elseif($draft['status']==='rejected'):?>
<p class="notice warning">اصلاحیه در رأی محلی پذیرفته نشد؛ نسخهٔ مصوب قبلی همچنان معتبر است.</p>
<?php endif;?>
</details><?php endforeach;?>
<?php endif;?>
</section>
<?php endif;?>
</main></body></html>
