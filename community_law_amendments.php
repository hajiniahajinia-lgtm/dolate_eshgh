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
    if (isset($_POST['submit_parent_compatibility_review'])) {
        $finding=(string)($_POST['parent_finding']??'');
        $references=[];
        foreach (preg_split('/\\R/u',(string)($_POST['conflicting_article_references']??'')) as $reference) {
            $reference=trim($reference);
            if ($reference!=='') $references[]=$reference;
        }
        $reviewed=community_law_submit_amendment_parent_review(
            $conn,(int)($_POST['amendment_id']??0),$community_id,$user_id,
            $finding,$references,trim((string)($_POST['parent_reasoning']??'')),
            trim((string)($_POST['parent_evidence']??''))?:null
        );
        header('Location: community_law_amendments.php?community_id='.$community_id.'&status='.($reviewed?'parent_review_saved':'parent_review_failed'));
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
        "SELECT a.id,a.status,a.rationale,a.created_at,a.community_id AS originating_community_id,
                a.base_version_id,a.proposed_version_id,a.proposed_by,
                v.version_number,v.law_text,v.status AS version_status,v.vote_deadline,v.vote_started_at,
                l.id AS law_id,l.current_version_id,l.law_code,a.voting_rules_source_version_id,
                c.parent_community_id,a.decision_note
         FROM community_law_amendment_cases a
         JOIN community_law_versions v ON v.id=a.proposed_version_id
         JOIN community_laws l ON l.id=a.law_id
         JOIN community_law_conformity_cases c ON c.id=a.conformity_case_id
         WHERE a.status IN ('draft','submitted','local_voting','vote_insufficient','parent_review','parent_review_approved','revision_required','rejected','approved')
         ORDER BY a.id DESC LIMIT 100"
    );
    if ($stmt) {
        if ($stmt->execute()) {
            $res=$stmt->get_result();
            while($row=$res->fetch_assoc()) {
                $visible=(int)$row['originating_community_id']===$community_id ||
                    (in_array($row['status'],['parent_review','parent_review_approved','revision_required'],true) &&
                        (int)$row['parent_community_id']===$community_id && $is_member) ||
                    community_law_scope_match_checked($conn,(int)$row['proposed_version_id'],$community_id)===true;
                if (!$visible && in_array($row['status'],['local_voting','parent_review','vote_insufficient','rejected','approved'],true)) {
                    $snapshot_member=$conn->prepare(
                        'SELECT 1 FROM community_law_electorate WHERE law_version_id=? AND voting_community_id=? AND user_id=? LIMIT 1'
                    );
                    if ($snapshot_member) {
                        $version_for_snapshot=(int)$row['proposed_version_id'];
                        $snapshot_member->bind_param('iii',$version_for_snapshot,$community_id,$user_id);
                        if ($snapshot_member->execute()) $visible=(bool)$snapshot_member->get_result()->fetch_row();
                        $snapshot_member->close();
                    }
                    if (!$visible) {
                        $direct_snapshot=$conn->prepare(
                            'SELECT 1 FROM community_law_direct_electorate WHERE law_version_id=? AND user_id=? LIMIT 1'
                        );
                        if ($direct_snapshot) {
                            $version_for_snapshot=(int)$row['proposed_version_id'];
                            $direct_snapshot->bind_param('ii',$version_for_snapshot,$user_id);
                            if ($direct_snapshot->execute()) $visible=(bool)$direct_snapshot->get_result()->fetch_row();
                            $direct_snapshot->close();
                        }
                    }
                }
                if ($visible) $drafts[]=$row;
            }
        }
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
<?php if($status==='parent_review_saved'):?><section class="card notice">بررسی انطباق جامعهٔ مادر ثبت شد؛ نتیجه پس از دو بررسی مستقل در یک دور مشخص می‌شود.</section><?php elseif($status==='parent_review_failed'):?><section class="card notice warning">بررسی ثبت نشد؛ عضویت در جامعهٔ مادر، استقلال ارزیاب و ارجاع دقیق مواد اصلاح‌شده را بررسی کنید.</section><?php elseif($status==='amendment_voting_started'):?><section class="card notice">رأی‌گیری اصلاحیه آغاز شد؛ قانون مصوب قبلی همچنان جاری است.</section><?php elseif($status==='amendment_vote_saved'):?><section class="card notice">رأی شما در پروندهٔ اصلاحیه ثبت شد.</section><?php elseif($status==='amendment_tally_saved'):?><section class="card notice">نتیجهٔ رأی محلی ثبت شد. مرحلهٔ بعد، در صورت پذیرش، بررسی انطباق با قانون مادر است.</section><?php elseif($status==='submitted_for_vote'):?><section class="card notice">اصلاحیه برای رأی محلی ارسال شد. قانون مصوب جاری بدون تغییر باقی مانده است.</section><?php elseif($status==='submission_failed'):?><section class="card notice warning">ارسال انجام نشد؛ عضویت، وضعیت نسخهٔ مبنا و وجود قواعد رأی‌گیریِ مصوب را بررسی کنید.</section><?php elseif($status==='draft_created'):?><section class="card notice">پیش‌نویس اصلاحیه و نسخهٔ تازه ساخته شد. هنوز هیچ رأی‌گیری یا تغییر قانونی انجام نشده است.</section><?php elseif($status==='save_failed'):?><section class="card notice warning">پیش‌نویس ساخته نشد. عضویت، پروندهٔ تعارض، متن دقیق ماده و نبود اصلاحیهٔ باز را بررسی کنید.</section><?php endif;?>
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
$can_amendment_legal_review=false;
$can_amendment_authority_review=false;
$amendment_review_state=['screening_status'=>null,'authority_status'=>null,'jurisdiction_level'=>null];
if($draft['status']==='submitted' && $is_member && (int)$draft['proposed_by']!==$user_id) {
    $review_state_stmt=$conn->prepare(
        'SELECT rc.status AS screening_status,ac.status AS authority_status,ac.derived_jurisdiction_level
         FROM community_law_review_cases rc
         JOIN community_law_authority_cases ac ON ac.law_version_id=rc.law_version_id
         WHERE rc.law_version_id=?'
    );
    if($review_state_stmt) {
        $review_state_stmt->bind_param('i',$amendment_version_id);
        if($review_state_stmt->execute()) {
            $state_row=$review_state_stmt->get_result()->fetch_assoc();
            if($state_row) $amendment_review_state=[
                'screening_status'=>$state_row['screening_status'],
                'authority_status'=>$state_row['authority_status'],
                'jurisdiction_level'=>$state_row['derived_jurisdiction_level']
            ];
        }
        $review_state_stmt->close();
    }
    $elector_stmt=$conn->prepare('SELECT 1 FROM community_law_review_electorate WHERE law_version_id=? AND user_id=? LIMIT 1');
    if($elector_stmt) {
        $elector_stmt->bind_param('ii',$amendment_version_id,$user_id);
        if($elector_stmt->execute()) $can_amendment_legal_review=(bool)$elector_stmt->get_result()->fetch_row();
        $elector_stmt->close();
    }
    if($can_amendment_legal_review && $amendment_review_state['screening_status']==='screening_clear' && $amendment_review_state['authority_status']==='pending') {
        $prior_authority_stmt=$conn->prepare('SELECT 1 FROM community_law_authority_assessments WHERE law_version_id=? AND reviewer_user_id=? LIMIT 1');
        if($prior_authority_stmt) {
            $prior_authority_stmt->bind_param('ii',$amendment_version_id,$user_id);
            if($prior_authority_stmt->execute()) $can_amendment_authority_review=!(bool)$prior_authority_stmt->get_result()->fetch_row();
            $prior_authority_stmt->close();
        }
    }
    if($can_amendment_legal_review && $amendment_review_state['screening_status']==='pending') {
        $prior_legal_stmt=$conn->prepare('SELECT 1 FROM community_law_review_assessments WHERE law_version_id=? AND reviewer_user_id=? LIMIT 1');
        if($prior_legal_stmt) {
            $prior_legal_stmt->bind_param('ii',$amendment_version_id,$user_id);
            if($prior_legal_stmt->execute()) $can_amendment_legal_review=!(bool)$prior_legal_stmt->get_result()->fetch_row();
            $prior_legal_stmt->close();
        }
    }
}
if($is_member && $draft['status']==='local_voting' && !empty($draft['vote_deadline']) && strtotime($draft['vote_deadline'])>time()) {
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
<?php
$review_rows=[];
$review_list=$conn->prepare('SELECT review_round,finding,conflicting_article_references,reasoning,evidence,created_at FROM community_law_amendment_parent_reviews WHERE case_id=? ORDER BY review_round,id');
if($review_list) {
    $review_list->bind_param('i',$draft['id']);
    if($review_list->execute()) { $review_result=$review_list->get_result(); while($review_row=$review_result->fetch_assoc()) $review_rows[]=$review_row; }
    $review_list->close();
}
?>
<?php if($review_rows):?>
<div class="article"><strong>گزارش بررسی‌های مستقل جامعهٔ مادر</strong>
<?php foreach($review_rows as $review_row):?>
<p><strong>دور <?= (int)$review_row['review_round']?> — <?= $review_row['finding']==='compatible'?'سازگار':'تعارض باقی‌مانده'?></strong> — <?=clamp_h($review_row['created_at'])?></p>
<?php $review_refs=json_decode((string)$review_row['conflicting_article_references'],true); if(is_array($review_refs) && $review_refs):?>
<p>مواد مورد اشاره: <?=clamp_h(implode('، ',$review_refs))?></p>
<?php endif;?>
<p><?=nl2br(clamp_h($review_row['reasoning']))?></p>
<?php if(!empty($review_row['evidence'])):?><p class="muted">شواهد: <?=nl2br(clamp_h($review_row['evidence']))?></p><?php endif;?>
<?php endforeach;?>
</div>
<?php endif;?>
<?php if($draft['status']==='draft' && (int)$draft['originating_community_id']===$community_id):?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?=clamp_h($csrf)?>">
<input type="hidden" name="community_id" value="<?=$community_id?>">
<input type="hidden" name="amendment_id" value="<?=(int)$draft['id']?>">
<label><input type="checkbox" name="adopt_base_rules" value="1" required style="width:auto"> می‌پذیرم که همین قواعد رأی‌گیریِ قبلاً تصویب‌شده برای نسخهٔ مبنا، بدون تغییر و با ثبت منشأ، مبنای رأی‌گیری این اصلاحیه باشد.</label>
<p class="muted">قواعد جدیدی ساخته یا بی‌سابقه تصویب نمی‌شود؛ نسخهٔ دقیق قواعد مصوب مبنا برای این اصلاحیه snapshot می‌شود. اگر قواعد مصوبی برای نسخهٔ مبنا وجود نداشته باشد، ارسال ممکن نیست.</p>
<button type="submit" name="submit_amendment_for_vote" value="1">ارسال اصلاحیه برای رأی جامعهٔ محلی</button>
</form>
<?php elseif($draft['status']==='draft'):?>
<p class="muted">پیش‌نویس اصلاحیه در جامعهٔ مبدأ در حال آماده‌سازی است و هنوز برای رأی‌گیری ارسال نشده است.</p>
<?php elseif($draft['status']==='submitted'):?>
<p class="notice">اصلاحیه ارسال شده است. قواعد رأی‌گیری از نسخهٔ مصوب مبنا ثبت شده‌اند؛ اما پیش از رأی‌گیری، غربالگری حقوقی و بررسی صلاحیت مستقل باید تکمیل شوند. قانون جاری تغییر نمی‌کند.</p>
<?php if($amendment_review_state['screening_status']==='pending' && $can_amendment_legal_review):?>
<form method="post" action="community_laws.php?community_id=<?=$community_id?>">
<input type="hidden" name="csrf_token" value="<?=clamp_h($law_csrf)?>">
<input type="hidden" name="law_version_id" value="<?=$amendment_version_id?>">
<strong>غربالگری حقوقی مستقل</strong>
<?php foreach(['charter_compatibility'=>'سازگاری با منشور','hierarchy_compatibility'=>'سازگاری با سلسله‌مراتب قوانین','rights_impact'=>'اثر بر حقوق','environmental_impact'=>'اثر زیست‌محیطی','implementation_feasibility'=>'امکان‌پذیری اجرا'] as $field=>$caption):?>
<label><?=clamp_h($caption)?></label><select name="<?=clamp_h($field)?>" required><option value="compliant">سازگار</option><option value="conflict">دارای تعارض</option><option value="unclear">نیازمند بررسی بیشتر</option></select>
<?php endforeach;?>
<label>استدلال</label><textarea name="reasoning" required maxlength="30000"></textarea>
<label>شواهد (اختیاری)</label><textarea name="evidence" maxlength="30000"></textarea>
<button type="submit" name="submit_law_legal_assessment" value="1">ثبت ارزیابی حقوقی</button>
</form>
<?php elseif($amendment_review_state['screening_status']==='pending'):?>
<p class="muted">غربالگری حقوقی در انتظار ارزیابی اعضای مستقل واجد شرایط است.</p>
<?php elseif(in_array($amendment_review_state['screening_status'],['conflict_found','needs_revision'],true)):?>
<p class="notice warning">غربالگری حقوقی تعارض یا ابهام یافته است؛ تا رفع آن رأی‌گیری آغاز نمی‌شود.</p>
<?php endif;?>
<?php if($amendment_review_state['screening_status']==='screening_clear' && $amendment_review_state['authority_status']==='pending' && $can_amendment_authority_review):?>
<?php
$authority_refs=[];
$authority_ref_stmt=$conn->prepare(
    'SELECT ar.id,ar.relation_type,rl.law_code,rv.title,rv.version_number
     FROM community_law_authority_references ar
     JOIN community_laws rl ON rl.id=ar.reference_law_id
     JOIN community_law_versions rv ON rv.id=ar.reference_law_version_id
     WHERE ar.law_version_id=? ORDER BY ar.relation_type,ar.id'
);
if($authority_ref_stmt) {
    $authority_ref_stmt->bind_param('i',$amendment_version_id);
    if($authority_ref_stmt->execute()) { $authority_ref_result=$authority_ref_stmt->get_result(); while($authority_ref=$authority_ref_result->fetch_assoc()) $authority_refs[]=$authority_ref; }
    $authority_ref_stmt->close();
}
?>
<form method="post" action="community_laws.php?community_id=<?=$community_id?>">
<input type="hidden" name="csrf_token" value="<?=clamp_h($law_csrf)?>">
<input type="hidden" name="law_version_id" value="<?=$amendment_version_id?>">
<strong>بررسی صلاحیت و قوانین بالادستی</strong>
<label>نتیجهٔ کلی صلاحیت</label><select name="authority_verdict" required><option value="confirm">تأیید</option><option value="dispute">دارای تعارض</option><option value="unclear">نیازمند بررسی بیشتر</option></select>
<?php foreach($authority_refs as $authority_ref):?>
<div class="article">
<strong><?=clamp_h($authority_ref['law_code'].' — '.$authority_ref['title'])?> (نسخه <?= (int)$authority_ref['version_number']?>)</strong>
<p class="muted">نوع ارتباط: <?=clamp_h($authority_ref['relation_type'])?></p>
<label>سازگاری با این قانون مرجع</label><select name="precedent_assessment[<?=(int)$authority_ref['id']?>]" required><option value="compatible">سازگار</option><option value="conflict">دارای تعارض</option><option value="unclear">نیازمند بررسی بیشتر</option></select>
<label>استدلال دربارهٔ این مرجع</label><textarea name="precedent_reasoning[<?=(int)$authority_ref['id']?>]" required maxlength="30000"></textarea>
<label>شواهد (اختیاری)</label><textarea name="precedent_evidence[<?=(int)$authority_ref['id']?>]" maxlength="30000"></textarea>
</div>
<?php endforeach;?>
<label>استدلال کلی</label><textarea name="authority_reasoning" required maxlength="30000"></textarea>
<label>شواهد کلی (اختیاری)</label><textarea name="authority_evidence" maxlength="30000"></textarea>
<button type="submit" name="submit_law_authority_assessment" value="1">ثبت بررسی صلاحیت</button>
</form>
<?php elseif($amendment_review_state['screening_status']==='screening_clear' && $amendment_review_state['authority_status']==='pending'):?>
<p class="muted">بررسی صلاحیت در انتظار ارزیابی مستقل است.</p>
<?php elseif(in_array($amendment_review_state['authority_status'],['conflict_found','needs_revision'],true)):?>
<p class="notice warning">بررسی صلاحیت تعارض یا ابهام یافته است؛ رأی‌گیری تا رفع آن متوقف می‌ماند.</p>
<?php endif;?>
<?php if($amendment_review_state['screening_status']==='screening_clear' && $amendment_review_state['authority_status']==='confirmed' && (int)$draft['originating_community_id']===$community_id):?>
<form method="post" action="community_laws.php?community_id=<?=$community_id?>">
<input type="hidden" name="csrf_token" value="<?=clamp_h($law_csrf)?>">
<input type="hidden" name="law_id" value="<?=(int)$draft['law_id']?>">
<input type="hidden" name="version_id" value="<?=$amendment_version_id?>">
<button type="submit" name="start_law_vote" value="1">آغاز رأی‌گیری اصلاحیه در جامعهٔ محلی</button>
</form>
<?php elseif($amendment_review_state['screening_status']==='screening_clear' && $amendment_review_state['authority_status']==='confirmed'):?>
<p class="muted">بررسی‌های مقدماتی کامل شده‌اند؛ آغاز رأی‌گیری در جامعهٔ مبدأ انجام می‌شود.</p>
<?php endif;?>
<?php elseif($draft['status']==='local_voting'):?>
<?php if(!empty($draft['vote_deadline']) && strtotime($draft['vote_deadline'])>time()):?>
<p class="notice">رأی‌گیری اصلاحیه در جریان است. مهلت پایان: <?=clamp_h($draft['vote_deadline'])?>. قانون مصوب قبلی همچنان جاری است.</p>
<?php else:?>
<p class="notice warning">مهلت رأی‌گیری پایان یافته است؛ تا محاسبهٔ نتیجه، وضعیت اصلاحیه در انتظار شمارش باقی می‌ماند. قانون مصوب قبلی همچنان جاری است.</p>
<?php endif;?>
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
<?php if($is_member && (int)$draft['originating_community_id']===$community_id && !empty($draft['vote_deadline']) && strtotime($draft['vote_deadline'])<=time()):?>
<form method="post" action="community_laws.php?community_id=<?=$community_id?>">
<input type="hidden" name="csrf_token" value="<?=clamp_h($law_csrf)?>">
<input type="hidden" name="law_id" value="<?=(int)$draft['law_id']?>">
<input type="hidden" name="version_id" value="<?=$amendment_version_id?>">
<button type="submit" name="finalize_law_tally" value="1">محاسبهٔ نتیجهٔ رأی‌گیری</button>
</form>
<?php endif;?>
<?php elseif($draft['status']==='parent_review'):?>
<p class="notice">رأی محلی اصلاحیه پذیرفته شده است. دو عضو مستقل جامعهٔ مادر باید فقط سازگاری مواد اصلاح‌شده با قانون مادر را بررسی کنند. جامعهٔ مادر حق بازنویسی متن قانون محلی را ندارد.</p>
<?php if($is_member && (int)$draft['parent_community_id']===$community_id):?>
<?php
$parent_review_stmt=$conn->prepare(
    'SELECT COALESCE(MAX(review_round),0) AS latest_round,
            SUM(review_round=(SELECT COALESCE(MAX(r2.review_round),0) FROM community_law_amendment_parent_reviews r2 WHERE r2.case_id=?)) AS current_count,
            SUM(reviewer_user_id=?) AS my_review
     FROM community_law_amendment_parent_reviews WHERE case_id=?'
);
$parent_review_stmt->bind_param('iii',$draft['id'],$user_id,$draft['id']);$parent_review_stmt->execute();
$parent_review_state=$parent_review_stmt->get_result()->fetch_assoc();$parent_review_stmt->close();
$parent_review_round=(int)($parent_review_state['latest_round']??0);
$parent_review_count=(int)($parent_review_state['current_count']??0);
$parent_my_review=(int)($parent_review_state['my_review']??0);
?>
<p class="muted">دور بررسی: <?=$parent_review_round?> — بررسی‌های این دور: <?=$parent_review_count?> از ۲</p>
<?php if($parent_review_count<2 && $parent_my_review===0):?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?=clamp_h($csrf)?>">
<input type="hidden" name="community_id" value="<?=$community_id?>">
<input type="hidden" name="amendment_id" value="<?=(int)$draft['id']?>">
<label>نتیجهٔ بررسی انطباق</label>
<select name="parent_finding" required><option value="compatible">مواد اصلاح‌شده با قانون مادر سازگارند</option><option value="conflict_remains">تعارض در یک یا چند ماده باقی مانده است</option></select>
<label>ارجاع مواد اصلاح‌شده‌ای که هنوز تعارض دارند (هر ماده در یک خط؛ فقط در صورت باقی‌ماندن تعارض)</label>
<textarea name="conflicting_article_references" placeholder="مثلاً: ماده ۳"></textarea>
<label>استدلال بررسی</label><textarea name="parent_reasoning" required maxlength="30000"></textarea>
<label>شواهد (اختیاری)</label><textarea name="parent_evidence" maxlength="30000"></textarea>
<button type="submit" name="submit_parent_compatibility_review" value="1">ثبت بررسی مستقل جامعهٔ مادر</button>
</form>
<?php endif;?>
<?php endif;?>
<?php elseif($draft['status']==='parent_review_approved'):?>
<p class="notice">دو بررسی مستقل جامعهٔ مادر سازگاری مواد اصلاح‌شده را تأیید کرده‌اند. اصلاحیه هنوز قانون جاری نیست و برای بررسی نهایی مستقل آماده است.</p>
<?php elseif($draft['status']==='revision_required'):?>
<p class="notice warning">این نسخه در یکی از مراحل بررسی مستقل نیازمند بازنگری تشخیص داده شده و برای تهیه نسخه تازه به جامعهٔ محلی بازگشته است. ارزیابان متن جایگزین را تحمیل نمی‌کنند.</p>
<?php if(!empty($draft['decision_note'])):?><p><?=nl2br(clamp_h($draft['decision_note']))?></p><?php endif;?>
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
