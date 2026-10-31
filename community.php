<?php
session_start();
include __DIR__ . "/db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// توکن امنیتی فرم‌های وظایف و رویکردهای جامعه
if (empty($_SESSION['community_duty_csrf'])) {
    $_SESSION['community_duty_csrf'] = bin2hex(random_bytes(32));
}
$community_duty_csrf = $_SESSION['community_duty_csrf'];

// مشخصات کاربر
$ust = $conn->prepare("SELECT username, phone, national_id FROM users WHERE id=?");
$ust->bind_param("i", $user_id);
$ust->execute();
$user = $ust->get_result()->fetch_assoc();
$ust->close();

// تبدیل تاریخ میلادی به شمسی، بدون نیاز به افزونهٔ اضافی PHP
function community_gregorian_to_jalali($gy, $gm, $gd) {
    $g_days_in_month = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $jy = 0;
    if ($gy > 1600) {
        $jy = 979;
        $gy -= 1600;
    } else {
        $jy = 0;
        $gy -= 621;
    }
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = (365 * $gy)
        + (int)(($gy2 + 3) / 4)
        - (int)(($gy2 + 99) / 100)
        + (int)(($gy2 + 399) / 400)
        - 80 + $gd;
    for ($i = 0; $i < $gm - 1; $i++) {
        $days += $g_days_in_month[$i];
    }
    $jy += 33 * (int)($days / 12053);
    $days %= 12053;
    $jy += 4 * (int)($days / 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + (int)($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $days -= 186;
        $jm = 7 + (int)($days / 30);
        $jd = 1 + ($days % 30);
    }
    return [$jy, $jm, $jd];
}
function community_persian_digits($value) {
    return strtr((string)$value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
}

// نمایش تاریخ و ساعت به تقویم شمسی برای موضوعات و ایده‌ها
function community_format_jalali_datetime($value) {
    $timestamp = strtotime((string)$value);
    if ($timestamp === false) return '';
    [$jy, $jm, $jd] = community_gregorian_to_jalali(
        (int)date('Y', $timestamp),
        (int)date('n', $timestamp),
        (int)date('j', $timestamp)
    );
    return community_persian_digits(sprintf('%04d/%02d/%02d %s', $jy, $jm, $jd, date('H:i', $timestamp)));
}

// جامعه جاری
$community_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$community = null;
if ($community_id > 0) {
    $cstmt = $conn->prepare("SELECT * FROM communities WHERE id=?");
    $cstmt->bind_param("i", $community_id);
    $cstmt->execute();
    $community = $cstmt->get_result()->fetch_assoc();
    $cstmt->close();
}

// نهایی‌کردن رأی‌گیری‌های اخراجی که مهلت آن‌ها گذشته است؛ فقط اگر جداول افزونه نصب شده باشند.
require_once __DIR__ . "/community_member_removal_helpers.php";
$community_removal_ready = community_removal_tables_ready($conn);
if ($community_removal_ready && $community_id > 0 && $community) {
    community_removal_finalize_expired($conn, $community_id);
}

// بررسی بسته شدن خودکار موضوعات و انتقال ایده برتر
$topics_check = $conn->prepare("SELECT id, urgency, created_at FROM topics WHERE community_id=? AND closed=0");
$topics_check->bind_param("i", $community_id);
$topics_check->execute();
$topics_result = $topics_check->get_result();

while ($topic = $topics_result->fetch_assoc()) {
    $created = strtotime($topic['created_at']);
    $urgency = intval($topic['urgency']);
   $urgency = intval($topic['urgency']);
$deadline = ($urgency > 0) ? $created + ($urgency * 24 * 60 * 60) : $created + (3 * 24 * 60 * 60);

    if (time() >= $deadline) {
        $up = $conn->prepare("UPDATE topics SET closed=1 WHERE id=?");
        $up->bind_param("i", $topic['id']);
        $up->execute();
        $up->close();

        $best_idea_q = $conn->prepare("
            SELECT id
            FROM ideas
            WHERE topic_id=?
            ORDER BY idea_rationality DESC, id ASC
            LIMIT 1
        ");
        $best_idea_q->bind_param("i", $topic['id']);
        $best_idea_q->execute();
        $best_idea = $best_idea_q->get_result()->fetch_assoc();
        $best_idea_q->close();

        if ($best_idea) {
            $ins = $conn->prepare("INSERT IGNORE INTO topic_final_votes (topic_id, idea_id) VALUES (?, ?)");
            $ins->bind_param("ii", $topic['id'], $best_idea['id']);
            $ins->execute();
            $ins->close();
        }
    }
}
$topics_check->close();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>جامعه</title>
<style>
body{font-family:tahoma, Arial, sans-serif;background:#eef3f8;margin:0;padding:12px;}
/* پس‌زمینهٔ تخت جمشید فقط برای جامعه‌ای با نام دقیق «کشور» */
body.country-community-page{
    background-color:#eef3f8;
    background-image:linear-gradient(rgba(255,255,255,.62),rgba(255,255,255,.62)),url('https://commons.wikimedia.org/wiki/Special:FilePath/20101229_Top_panoramic_view_of_Persepolis_Iran.jpg?width=1800');
    background-size:cover;
    background-position:center center;
    background-repeat:no-repeat;
    background-attachment:fixed;
}
@media(max-width:720px){
    body.country-community-page{background-attachment:scroll;background-position:center top;}
}
.container{max-width:960px;margin:0 auto;padding:0 6px;}
.box, .card-form, .card{background:#fff;border:1px solid #ccc;border-radius:10px;padding:14px;margin-bottom:16px;box-shadow:0 2px 5px rgba(0,0,0,0.05);}
/* پس‌زمینه رنگین‌کمانی ملایم برای سربرگ جامعه */
.community-mobile-actions{display:none;}
.community-header{
    background:linear-gradient(125deg,
        #ff9fba 0%,
        #ffc878 20%,
        #b8e88a 40%,
        #91d8ff 60%,
        #b3a0ff 80%,
        #ff9bd4 100%);
    border-color:#e2dff0;
}

h2,h3{margin-top:0;}
input,select,textarea,button{box-sizing:border-box;}
input[type=text], input[type=number], textarea, select {width:100%;padding:8px;margin-top:6px;border-radius:6px;border:1px solid #bbb;}
button{background:#2065d1;color:#fff;cursor:pointer;border:none;margin-top:10px;border-radius:6px;padding:8px;}
a{text-decoration:none;color:#2065d1;font-weight:bold;}
textarea{resize:vertical;}

.community-duties{margin:12px 0 8px;padding:12px;background:rgba(255,255,255,.76);border:1px solid #d9d6e9;border-radius:9px;text-align:right;}
.community-duties-toggle{margin:12px 0 8px;}
.community-duties-toggle>summary{display:block;list-style:none;background:#2065d1;color:#fff;cursor:pointer;border-radius:7px;padding:10px 14px;text-align:center;font-weight:bold;}
.community-duties-toggle>summary::-webkit-details-marker{display:none;}
.community-duties-toggle>summary::marker{content:"";}

.final-voting-toggle,.archive-topics-toggle,.finance-bank-toggle{display:block;text-decoration:none;text-align:center;box-sizing:border-box;}
.community-topics-toggle{margin:0 0 10px;}
.community-topics-toggle>summary{display:block;list-style:none;background:#2065d1;color:#fff;cursor:pointer;border-radius:8px;padding:12px 14px;text-align:center;font-weight:bold;}
.community-topics-toggle>summary::-webkit-details-marker{display:none;}
.community-topics-toggle>summary::marker{content:"";}
.community-topics-toggle[open]>summary{border-radius:8px 8px 0 0;}
.community-topics-toggle #topics-wrapper{margin-top:0;}
.final-voting-toggle{display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;min-height:42px;background:#4A82DA;color:#fff;border-radius:9px;padding:10px 14px;font-weight:bold;text-align:center;}
.archive-topics-toggle{background:#2065d1;color:#fff;border-radius:8px;padding:12px 14px;font-weight:bold;}
.finance-bank-toggle{display:block;width:100%;background:#147d64;color:#fff;border-radius:8px;padding:12px 14px;font-weight:bold;margin:8px 0 12px;}
.final-voting-toggle{margin:0 0 16px;}
.final-voting-toggle>summary{display:block;list-style:none;background:#4A82DA;color:#fff;cursor:pointer;border-radius:9px;padding:10px 14px;text-align:center;font-weight:bold;}
.final-voting-toggle>summary::-webkit-details-marker{display:none;}
.final-voting-toggle>summary::marker{content:"";}
.final-voting-toggle[open]>summary{border-radius:8px 8px 0 0;}
.final-voting-content{margin-top:0;border-radius:0 0 10px 10px;}

.community-topic-shortcuts{display:flex;align-items:flex-start;gap:10px;margin:0 0 16px;width:100%;box-sizing:border-box;}
.community-topic-shortcuts>.final-voting-toggle,.community-topic-shortcuts>.archive-topics-toggle{flex:1 1 0;min-width:0;margin:0;}
.archive-topics-toggle>summary{display:block;list-style:none;background:#2065d1;color:#fff;cursor:pointer;border-radius:8px;padding:12px 14px;text-align:center;font-weight:bold;overflow-wrap:anywhere;}
.archive-topics-toggle>summary::-webkit-details-marker{display:none;}
.archive-topics-toggle>summary::marker{content:"";}
.archive-topics-toggle[open]>summary{border-radius:8px 8px 0 0;}
.archive-topics-content{margin-top:0;border-radius:0 0 10px 10px;}
@media(max-width:520px){.community-topic-shortcuts{display:flex!important;flex-direction:row!important;flex-wrap:nowrap!important;align-items:stretch;gap:6px}.community-topic-shortcuts>.final-voting-toggle,.community-topic-shortcuts>.archive-topics-toggle{display:flex;flex:1 1 0;min-width:0;width:50%;min-height:42px;align-items:center;justify-content:center;padding:10px 4px;border-radius:9px;font-size:12px;line-height:1.45;text-align:center;overflow-wrap:anywhere;}}


.community-duties-toggle[open]>summary{border-radius:7px 7px 0 0;}
.community-duties h3{margin:0 0 6px;font-size:15px;color:#344054;}
.community-duties-help,.community-duties-empty,.community-duties-notice{font-size:12px;line-height:1.9;color:#596579;margin:5px 0 9px;}
.community-duties-notice{background:#fff8e1;padding:8px;border-radius:6px;}
.community-duty-list{display:flex;flex-direction:column;gap:8px;margin:10px 0;}
.community-duty-item{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;}
.community-duty-content{min-width:0;flex:1;line-height:1.9;overflow-wrap:anywhere;font-size:13px;}
.community-duty-content small{display:block;color:#667085;font-size:11px;margin-top:4px;}
.community-duty-top{display:block;color:#946200;margin-bottom:4px;font-size:12px;}
.community-duty-type{display:inline-block;background:#eaf2ff;color:#2456a6;border-radius:5px;padding:1px 7px;font-size:11px;margin-bottom:5px;}
.community-duty-vote-form{flex:0 0 auto;margin:0;}
.community-duty-vote-form button,.community-duty-form button{margin:0;padding:8px 10px;font-size:12px;white-space:nowrap;}
.community-duty-vote-form button.voted{background:#d9a514;color:#222;}
.community-duty-form{margin-top:10px;padding-top:10px;border-top:1px solid #e4e7ec;}
.community-duty-form label{display:block;font-size:12px;margin-top:7px;}
.community-duty-form select,.community-duty-form textarea{width:100%;box-sizing:border-box;margin-top:5px;padding:8px;border:1px solid #c5cbd3;border-radius:6px;font:inherit;font-size:13px;background:#fff;}
.members-dropdown{position:relative;display:inline-block;margin-top:8px;z-index:1100;}
.members-button{background:#2065d1;color:#fff;border:none;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:13px;}
.members-list{display:none;position:absolute;top:100%;right:0;background:#fff;min-width:200px;max-height:220px;overflow-y:auto;overflow-x:visible;border:1px solid #ccc;border-radius:8px;z-index:1101;padding:8px;box-shadow:0 2px 10px rgba(0,0,0,0.14);}
.members-list p{margin:4px 0;font-size:13px;color:#333;}
.member-entry{position:relative;padding:5px 4px;border-bottom:1px solid #eee;}
.member-name{display:block;color:#2065d1;font-weight:bold;cursor:default;}
.member-profile{display:none;position:fixed;top:0;right:12px;width:260px;max-width:calc(100vw - 24px);max-height:60vh;overflow:auto;box-sizing:border-box;margin:0;padding:10px;background:#f4f8ff;border:1px solid #9bbbe0;border-radius:8px;color:#333;font-size:12px;line-height:1.9;white-space:normal;overflow-wrap:anywhere;box-shadow:0 5px 18px rgba(0,0,0,.2);z-index:99999;}
.member-entry:hover .member-profile,.member-entry:focus-within .member-profile{display:block;}
.members-dropdown:hover .members-list,.members-dropdown:focus-within .members-list{display:block;}

.topic{background:#eef7ff;border:1px solid #9ac7ff;border-radius:10px;padding:0;margin-bottom:10px;word-wrap:break-word;overflow:visible;}
.community-topic-item>summary{display:flex;align-items:center;justify-content:space-between;gap:10px;list-style:none;cursor:pointer;padding:5px 12px;font-weight:bold;line-height:1.3;}
.community-topic-item>summary::-webkit-details-marker{display:none;}
.community-topic-item>summary::marker{content:"";}
.community-topic-item>summary:after{content:"＋";flex:0 0 auto;color:#2065d1;font-size:18px;}
.community-topic-item[open]>summary:after{content:"−";}
.community-topic-title{margin:0;font-size:16px;word-break:break-word;}
.community-topic-body{padding:0 12px 12px;}
.community-topic-section{margin:0 0 16px;padding:0 0 8px;border-bottom:1px solid #d5e4f3;}
.community-topic-section:last-child{border-bottom:0;margin-bottom:0;}
.community-topic-section-title{margin:0 0 8px;padding:8px 10px;background:#e5f1ff;border-radius:7px;color:#214d7a;font-size:14px;line-height:1.7;}
.topic-discussion{margin-top:14px;padding:12px;background:#f0f8ff;border:1px solid #b7d9f5;border-radius:9px;}
.topic-discussion h5{margin:0 0 8px;font-size:14px;color:#214d7a;}
.topic-discussion-entry{margin:8px 0;padding:9px 10px;background:#fff;border:1px solid #dceaf7;border-radius:7px;line-height:1.8;overflow-wrap:anywhere;}
.topic-discussion-entry-meta{font-size:12px;color:#687789;margin-bottom:4px;}
.topic-discussion textarea{box-sizing:border-box;width:100%;min-height:76px;margin-top:8px;padding:9px;border:1px solid #b8cde0;border-radius:7px;font:inherit;resize:vertical;}
.topic-discussion button{margin-top:7px;padding:7px 12px;border:0;border-radius:6px;background:#2065d1;color:#fff;cursor:pointer;font:inherit;}
.community-idea-edit{margin-top:9px;padding:8px;background:#f5f9ff;border:1px solid #c9def4;border-radius:7px;font-size:13px;}
.community-idea-edit>summary{cursor:pointer;color:#245b92;font-weight:bold;}
.community-idea-edit form{margin-top:8px;}
.community-idea-edit label{display:block;margin-top:7px;font-size:12px;}
.community-idea-edit textarea{width:100%;min-height:120px;box-sizing:border-box;padding:8px;border:1px solid #b8cde0;border-radius:6px;font:inherit;}
.community-idea-edit input[type=text]{width:100%;box-sizing:border-box;padding:8px;border:1px solid #b8cde0;border-radius:6px;font:inherit;}
.community-idea-history{margin-top:10px;padding-top:8px;border-top:1px solid #d6e5f4;}
.community-idea-history>strong{display:block;margin-bottom:6px;}
.community-idea-history details{margin-top:5px;padding:7px;background:#fff;border:1px solid #e0e8f0;border-radius:6px;}
.community-idea-history summary{cursor:pointer;color:#475569;}
.community-idea-feedback{margin-top:10px;padding:9px;background:#f0f9f7;border:1px solid #b9e2d6;border-radius:7px;font-size:13px;}
.community-idea-feedback>summary{cursor:pointer;color:#176b56;font-weight:bold;}
.community-idea-feedback-entry{margin-top:7px;padding:8px;background:#fff;border:1px solid #d7eee7;border-radius:6px;overflow-wrap:anywhere;}
.community-idea-feedback-meta{font-size:12px;color:#687789;margin-bottom:4px;}
.community-idea-feedback textarea{box-sizing:border-box;width:100%;min-height:72px;margin-top:8px;padding:8px;border:1px solid #b8d9ce;border-radius:6px;font:inherit;resize:vertical;}
.community-idea-feedback button{margin-top:7px;padding:7px 11px;border:0;border-radius:6px;background:#187a61;color:#fff;cursor:pointer;font:inherit;}
.topic-title-wrap{position:relative;flex:1 1 auto;min-width:0;}
.topic-header h2{margin:0;font-size:16px;word-break:break-word;}
.topic-description{display:none;position:absolute;top:calc(100% + 6px);right:0;width:360px;max-width:calc(100vw - 60px);margin:0;padding:10px 12px;background:#fff;color:#374151;font-size:14px;line-height:1.9;white-space:pre-wrap;overflow-wrap:anywhere;border:1px solid #9ac7ff;border-radius:8px;box-shadow:0 5px 16px rgba(0,0,0,.16);z-index:1500;font-weight:normal;}
.topic-title-wrap:hover .topic-description,.topic-title-wrap:focus-within .topic-description{display:block;}
.topic-header{display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;}
.topic-actions{display:flex;gap:6px;align-items:center;flex-wrap:nowrap;}
.topic-actions form{display:inline-flex;align-items:center;gap:6px;}
.priority-btn, .urgency-btn{padding:6px 10px;font-size:13px;border-radius:6px;cursor:pointer;}
.action-tooltip{position:relative;display:inline-flex;align-items:center;}
.action-tooltip-text{display:none;position:fixed;top:0;left:0;width:270px;max-width:calc(100vw - 24px);box-sizing:border-box;padding:9px 11px;background:#fff;color:#263238;border:1px solid #b8c7d8;border-radius:8px;box-shadow:0 4px 14px rgba(0,0,0,.16);font-size:12px;line-height:1.9;font-weight:normal;text-align:right;white-space:normal;z-index:99999;}
.action-tooltip:hover .action-tooltip-text,.action-tooltip:focus-within .action-tooltip-text{display:block;}
/* توضیح‌های دکمه‌های ایده؛ جای‌گذاری ثابت برای جلوگیری از بریده‌شدن زیر حاشیه‌ها */
.idea-action-tooltip{position:relative;display:inline-flex;align-items:center;}
.idea-action-tooltip-text{display:none;position:fixed;top:0;left:0;width:280px;max-width:calc(100vw - 24px);box-sizing:border-box;padding:10px 12px;background:#fff;color:#263238;border:1px solid #b8c7d8;border-radius:8px;box-shadow:0 5px 18px rgba(0,0,0,.2);font-size:12px;line-height:1.9;font-weight:normal;text-align:right;white-space:normal;overflow-wrap:anywhere;z-index:99999;pointer-events:none;}
.idea-action-tooltip:hover .idea-action-tooltip-text,
.idea-action-tooltip:focus-within .idea-action-tooltip-text{display:block;}
@media (max-width:720px){
    /* آبی پررنگ‌تر فقط برای کارت موضوعات در نسخه موبایل */
    .topic{background:#B7D9F5;}
    .community-mobile-actions{display:flex;flex-direction:row;flex-wrap:nowrap;align-items:center;gap:6px;width:100%;margin:8px 0 12px;}
    .community-mobile-actions>*{flex:1 1 0;min-width:0;max-width:33.333%;margin:0!important;text-align:center!important;}
    .community-mobile-actions a,.community-mobile-actions form button,.community-mobile-actions .members-button{display:flex;align-items:center;justify-content:center;width:100%;min-height:42px;box-sizing:border-box;padding:7px 3px!important;font-size:10px!important;line-height:1.35;white-space:normal;margin:0!important;}
    .community-mobile-actions form{display:flex;align-items:center;min-width:0;}
    .community-mobile-actions .members-dropdown{display:flex;align-items:stretch;min-width:0;}
    .community-duty-item{align-items:flex-start;flex-direction:column;}
    .community-duty-vote-form{align-self:flex-start;}
.idea-action-tooltip-text{display:none !important;}}
.urgency-input{width:60px;text-align:center;padding:6px;font-size:13px;}
.community-urgency-controls{position:relative;display:inline-block;max-width:100%;margin:0;}
.community-urgency-controls>summary{box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;width:92px;min-width:92px;max-width:92px;height:36px;min-height:36px;padding:0 6px;list-style:none;background:#f1c40f;color:#222;cursor:pointer;border-radius:6px;font-family:Tahoma,Arial,sans-serif;font-size:13px;font-weight:bold;line-height:1;text-align:center;white-space:nowrap;}
.community-urgency-controls>summary::-webkit-details-marker{display:none;}
.community-urgency-controls>summary::marker{content:"";}
.community-urgency-controls[open]>summary{border-radius:6px 6px 0 0;}
.community-urgency-controls>form{display:flex;flex-wrap:wrap;align-items:center;gap:7px;min-width:170px;max-width:260px;padding:10px;background:#fff;border:1px solid #e0c35b;border-radius:0 0 8px 8px;box-shadow:0 4px 12px rgba(0,0,0,.12);}
.community-urgency-controls .community-urgency-label{flex-basis:100%;font-size:12px;}
.community-urgency-controls .urgency-input{box-sizing:border-box;width:90px;min-height:36px;margin:0;text-align:center;}
.community-urgency-controls .urgency-btn{margin:0;min-height:36px;background:#f1c40f;color:#222;padding:7px 12px;}
.community-urgency-help{display:block;flex-basis:100%;font-size:11px;line-height:1.7;color:#596579;}
.ideas-box{margin-top:10px;background:#fff;padding:10px;border-radius:8px;border:1px solid #ddd;max-height:200px;overflow-y:auto;}
.idea{background:#fff;border:1px solid #e0f0ff;border-radius:8px;padding:10px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:flex-start;gap:8px;}
.idea .text{flex:1 1 auto;max-width:100%;word-break:break-word;}
.community-idea-content{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:3;line-clamp:3;overflow:hidden;white-space:normal;overflow-wrap:anywhere;}
.community-idea-content.is-expanded{display:block;-webkit-line-clamp:unset;line-clamp:unset;overflow:visible;}
.community-idea-read-more{display:none;margin-top:4px;padding:0;border:0;background:transparent;color:#1769aa;font:inherit;font-weight:bold;text-decoration:underline;cursor:pointer;}
.idea-rationality button{background:#27ae60;color:#fff;padding:6px 8px;font-size:13px;border-radius:6px;white-space:nowrap;border:none;}

.user-tooltip{position:relative;cursor:pointer;color:#2065d1;}
.tooltip-box{position:absolute;top:26px;left:0;background:#fff;border:1px solid #ccc;border-radius:8px;padding:8px;min-width:180px;z-index:1000;box-shadow:0 2px 6px rgba(0,0,0,0.15);font-size:13px;display:none;}

.topic-form-toggle{margin:12px 0 16px;}
/* دکمه و زمینهٔ فرم ثبت موضوع/ایده؛ رنگ دکمه ۲۰٪ روشن‌تر شده است */
.topic-form-toggle>summary{display:block;list-style:none;background:#4A82DA;color:#fff;cursor:pointer;border-radius:8px;padding:12px 14px;text-align:center;font-weight:bold;}
.topic-form-toggle>summary::-webkit-details-marker{display:none;}
.topic-form-toggle>summary::marker{content:"";}
.topic-form-toggle[open]>summary{border-radius:8px 8px 0 0;}
.topic-form-toggle[open]>.topic-form{margin-top:0;border-radius:0 0 10px 10px;background:#4A82DA;border-color:#4A82DA;}
.topic-field-hint{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;flex-wrap:wrap;margin:2px 2px 8px;color:#e1eaff;font-size:11px;line-height:1.6;}
.topic-char-count{color:#d2e0fb;font-size:11px;white-space:nowrap;font-variant-numeric:tabular-nums;}
.card-form h3{margin-top:0;margin-bottom:8px;}
.topic-picker{position:relative;margin-top:6px}
.topic-picker-button{width:100%;display:flex;align-items:center;justify-content:space-between;gap:12px;text-align:right;background:#fff;color:#263238;border:1px solid #bbb;padding:10px 12px;border-radius:7px;font:inherit;line-height:1.8;margin:0;min-height:44px}
.topic-picker-button:focus,.topic-picker-option:focus{outline:2px solid #2065d1;outline-offset:2px}
.topic-picker-arrow{flex:0 0 auto;color:#2065d1}
.topic-picker-list{position:absolute;top:calc(100% + 4px);right:0;left:0;z-index:1200;max-height:280px;overflow-y:auto;background:#fff;border:1px solid #aebbb0;border-radius:8px;box-shadow:0 5px 16px rgba(0,0,0,.14);padding:5px}
.topic-picker-list[hidden]{display:none}
.topic-picker-option{display:flex;align-items:flex-start;gap:9px;width:100%;text-align:right;white-space:normal;overflow-wrap:anywhere;word-break:normal;line-height:1.9;background:#fff;color:#26352b;border:0;border-radius:5px;padding:9px 8px;margin:0;font:inherit;cursor:pointer}
.topic-picker-option:hover,.topic-picker-option:focus{background:#eef7ff;color:#164e9a}
.topic-picker-marker{flex:0 0 auto;color:#2065d1;font-size:12px;padding-top:4px}
.topic-picker-title{display:block;flex:1;min-width:0;white-space:normal;overflow-wrap:anywhere}
.topic-picker-error{color:#c0392b;font-size:13px;margin:5px 0 0}

.card-form input,.card-form select,.card-form textarea,.card-form button{margin-top:6px;}
.card{background:#f7f8fa;border:1px solid #ccc;text-align:center;padding:14px;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,0.04);}

@media (min-width:721px){
    .topic-actions>.community-urgency-controls{display:inline-block;flex:0 0 92px;width:92px;min-width:92px;}
    .idea-action-tooltip{width:160px;max-width:160px;}
    /* هم‌اندازه‌کردن دکمه فوریت با دکمه اولویت فقط در صفحه‌نمایش کامپیوتر */
    .topic-actions form[action="toggle_priority.php"] button,
    .topic-actions form[action="update_urgency.php"] .urgency-btn{
        box-sizing:border-box !important;
        display:inline-flex !important;
        align-items:center !important;
        justify-content:center !important;
        width:92px !important;
        min-width:92px !important;
        max-width:92px !important;
        height:36px !important;
        min-height:36px !important;
        max-height:36px !important;
        padding:0 6px !important;
        margin-top:0 !important;
        font-family:Tahoma, Arial, sans-serif !important;
        font-size:13px !important;
        line-height:1 !important;
        white-space:nowrap !important;
    }

    /* هم‌اندازه‌کردن فیلد عدد فوریت با دکمه‌ها و نگه‌داشتن آن در همان ردیف، فقط در کامپیوتر */
    .topic-actions form[action="update_urgency.php"]{
        display:inline-flex !important;
        flex-direction:row !important;
        align-items:center !important;
        gap:6px !important;
        margin:0 !important;
    }
    .topic-actions form[action="update_urgency.php"] .urgency-input{
        box-sizing:border-box !important;
        width:92px !important;
        min-width:92px !important;
        max-width:92px !important;
        height:36px !important;
        min-height:36px !important;
        max-height:36px !important;
        padding:0 6px !important;
        margin:0 !important;
        font-family:Tahoma, Arial, sans-serif !important;
        font-size:13px !important;
        line-height:1 !important;
        text-align:center !important;
    }

    /* هم‌اندازه‌کردن دکمه‌های محبوبیت کاربر و معقول‌ترین ایده فقط در کامپیوتر */
    .idea-actions a[href^="give_score.php"],
    .idea-actions form[action="update_idea.php"] button,
    .idea-actions > span:not(.idea-action-tooltip),
    .idea-actions .idea-action-tooltip{
        box-sizing:border-box !important;
        display:inline-flex !important;
        align-items:center !important;
        justify-content:center !important;
        width:160px !important;
        min-width:160px !important;
        max-width:160px !important;
        height:36px !important;
        min-height:36px !important;
        max-height:36px !important;
        padding:0 6px !important;
        margin:0 !important;
        font-family:Tahoma, Arial, sans-serif !important;
        font-size:13px !important;
        line-height:1 !important;
        white-space:nowrap !important;
        text-align:center !important;
    }

    /* چیدمان منظم اطلاعات موضوع فقط در صفحه‌نمایش کامپیوتر */
    .topic-header{
        display:grid;
        grid-template-columns:minmax(0,1.2fr) minmax(0,1fr) minmax(0,1fr);
        align-items:center;
        column-gap:16px;
        row-gap:10px;
    }
    .topic-title-wrap{grid-column:1 / -1;}
    .topic-header .topic-meta,
    .topic-header .countdown{
        margin-top:0 !important;
        min-width:0;
    }
    .topic-summary{
        text-align:right !important;
        min-width:0;
        line-height:1.9;
    }
    .topic-actions{
        grid-column:1 / -1;
        border-top:1px solid #d6e7f8;
        padding-top:8px;
        justify-content:flex-start;
        align-items:center;
    }
    .topic-actions form{align-items:center;}
}

@media (max-width:720px){
    .container{padding:8px;}
    .topic-header{flex-direction:column;align-items:flex-start;}
    .topic-actions{flex-direction:column;align-items:flex-start;width:100%;}
    .ideas-box{max-height:250px;}
    .idea{flex-direction:column;align-items:flex-start;}
    .idea .text{max-width:100%;}
}


/* ===== پیش‌نمایش چیدمان موبایلی جامعه؛ فقط CSS و قابل بازگشت ===== */
@media (max-width: 720px) {
  body { padding: 8px; background: #f3f7fb; }
  .container { width: 100%; max-width: 100%; padding: 0 2px; box-sizing: border-box; }
  .box, .card-form, .card { padding: 12px; margin-bottom: 12px; border-radius: 14px; border-color: #e0e8f1; box-shadow: 0 3px 12px rgba(24, 55, 90, .06); }
  h2 { font-size: 19px; line-height: 1.7; }
  h3 { font-size: 16px; line-height: 1.8; }
  input[type=text], input[type=number], textarea, select { min-height: 44px; padding: 10px 11px; border-radius: 9px; font-size: 15px; }
  textarea { min-height: 88px; }
  button { min-height: 42px; padding: 10px 13px; border-radius: 9px; font-size: 14px; line-height: 1.7; }
  a { overflow-wrap: anywhere; }
  .community-header { border-radius: 16px; border: 1px solid #dce8f0; box-shadow: 0 4px 14px rgba(30, 70, 100, .08); }
  .community-duties { padding: 10px; border-radius: 12px; }
  .community-duties-toggle > summary { padding: 12px; border-radius: 10px; line-height: 1.8; }
  .community-duty-item { gap: 9px; padding: 11px; border-radius: 10px; }
  .community-duty-content { font-size: 14px; line-height: 2; }
  .community-duty-vote-form { width: 100%; }
  .community-duty-vote-form button, .community-duty-form button { min-height: 40px; white-space: normal; }
  .topic { padding: 12px; margin-bottom: 12px; border-radius: 13px; background: #B7D9F5; border-color: #dbe8f5; box-shadow: 0 2px 9px rgba(24, 55, 90, .04); }
  .topic-header { gap: 10px; }
  .topic-header h2 { font-size: 16px; line-height: 1.9; }
  .topic-title-wrap { width: 100%; }
  .topic-description { position: static; width: auto; max-width: 100%; margin-top: 8px; box-shadow: none; }
  .topic-actions { gap: 8px; align-items: stretch; }
  .topic-actions form { width: 100%; display: flex; flex-wrap: wrap; align-items: center; }
  .topic-actions form button, .topic-actions form input, .topic-actions form select { max-width: 100%; }
  .urgency-input { min-height: 42px; width: 84px; }
  .ideas-box { max-height: none; padding: 9px; border-radius: 10px; }
  .idea { padding: 11px; border-radius: 10px; }
  .idea .text { width: 100%; line-height: 1.9; }
  .idea-rationality button { min-height: 40px; white-space: normal; }
  .members-dropdown { display: block; width: 100%; }
  .members-button { min-height: 42px; }
  .members-list { min-width: 0; width: 100%; box-sizing: border-box; }
  .member-profile { max-width: calc(100vw - 24px); }
  .topic-picker-button { min-height: 46px; border-radius: 9px; }
  .topic-picker-list { max-height: 45vh; }
  .topic-picker-option { padding: 10px; }
  .action-tooltip-text, .idea-action-tooltip-text { max-width: calc(100vw - 24px); }
}
/* ===== پایان پیش‌نمایش چیدمان موبایلی جامعه ===== */


/* فقط موبایل: دکمه‌های اولویت و تنظیم فوریت در یک ردیف و هم‌اندازه */
@media (max-width: 720px) {
  .topic-actions > .community-urgency-controls {
    display: flex !important;
    flex: 1 1 0 !important;
    min-width: 0 !important;
    width: auto !important;
    margin: 0 !important;
  }
  .topic-actions > .community-urgency-controls > summary {
    box-sizing: border-box !important;
    display: inline-flex !important;
    flex: 1 1 0 !important;
    width: 100% !important;
    min-width: 0 !important;
    max-width: none !important;
    height: 42px !important;
    min-height: 42px !important;
    max-height: 42px !important;
    padding: 0 4px !important;
    justify-content: center !important;
    align-items: center !important;
    white-space: nowrap !important;
    font-size: 12px !important;
  }

  /* در موبایل، با باز شدن فوریت، فرم زیر دکمه نمایش داده شود نه کنار آن */
  .topic-actions > .community-urgency-controls[open] {
    flex-direction: column !important;
    align-items: stretch !important;
    min-width: 0 !important;
  }
  .topic-actions > .community-urgency-controls[open] > summary {
    flex: 0 0 42px !important;
    width: 100% !important;
  }
  .topic-actions > .community-urgency-controls > form {
    box-sizing: border-box !important;
    display: flex !important;
    flex-direction: row !important;
    flex-wrap: wrap !important;
    align-items: center !important;
    gap: 7px !important;
    width: 100% !important;
    min-width: 0 !important;
    max-width: 100% !important;
    padding: 10px !important;
    margin: 0 !important;
  }
  .topic-actions > .community-urgency-controls > form .urgency-input {
    flex: 0 1 90px !important;
    width: 90px !important;
    max-width: 100% !important;
  }
  .topic-actions > .community-urgency-controls > form .urgency-btn {
    flex: 0 1 auto !important;
    width: auto !important;
    min-width: 95px !important;
    max-width: 100% !important;
  }
  .community-urgency-status {
    display: block !important;
    width: 100% !important;
    overflow-wrap: anywhere;
    line-height: 1.8;
  }
}

/* فقط موبایل: اولویت، کادر روزهای فوریت و دکمه فوریت در یک ردیف و هم‌اندازه */
@media (max-width: 720px) {
  .topic-actions {
    display: flex !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    align-items: stretch !important;
    gap: 6px !important;
    width: 100% !important;
    box-sizing: border-box !important;
  }
  .topic-actions form[action="toggle_priority.php"] {
    display: flex !important;
    flex: 1 1 0 !important;
    min-width: 0 !important;
    width: auto !important;
    margin: 0 !important;
  }
  .topic-actions form[action="update_urgency.php"] {
    display: flex !important;
    flex: 2 1 0 !important;
    flex-direction: row !important;
    align-items: stretch !important;
    gap: 6px !important;
    min-width: 0 !important;
    width: auto !important;
    margin: 0 !important;
  }
  .topic-actions form[action="toggle_priority.php"] .action-tooltip,
  .topic-actions form[action="update_urgency.php"] .action-tooltip {
    display: flex !important;
    flex: 1 1 0 !important;
    min-width: 0 !important;
    width: 100% !important;
    margin: 0 !important;
  }
  .topic-actions form[action="toggle_priority.php"] button,
  .topic-actions form[action="update_urgency.php"] .urgency-btn,
  .topic-actions form[action="update_urgency.php"] .urgency-input {
    box-sizing: border-box !important;
    flex: 1 1 0 !important;
    min-width: 0 !important;
    max-width: none !important;
    width: 100% !important;
    height: 42px !important;
    min-height: 42px !important;
    max-height: 42px !important;
    margin: 0 !important;
    padding: 0 3px !important;
    font-size: 12px !important;
    line-height: 1.2 !important;
    text-align: center !important;
  }
  .topic-actions form[action="update_urgency.php"] .urgency-input {
    -moz-appearance: textfield;
  }
}


/* فقط موبایل: محبوبیت کاربر و معقول‌ترین ایده هم‌اندازه و کنار هم */
@media (max-width: 720px) {
  .idea-actions {
    display: flex !important;
    flex-direction: row !important;
    flex-wrap: nowrap !important;
    align-items: stretch !important;
    gap: 6px !important;
    width: 100% !important;
    min-width: 0 !important;
    box-sizing: border-box !important;
  }
  .idea-actions > .idea-action-tooltip,
  .idea-actions > form {
    display: flex !important;
    flex: 1 1 0 !important;
    flex-direction: row !important;
    align-items: stretch !important;
    min-width: 0 !important;
    width: auto !important;
    margin: 0 !important;
  }
  .idea-actions > .idea-action-tooltip > a,
  .idea-actions > form > .idea-action-tooltip,
  .idea-actions > form > .idea-action-tooltip > button {
    display: flex !important;
    flex: 1 1 0 !important;
    align-items: center !important;
    justify-content: center !important;
    box-sizing: border-box !important;
    min-width: 0 !important;
    max-width: none !important;
    width: 100% !important;
    height: 42px !important;
    min-height: 42px !important;
    max-height: 42px !important;
    margin: 0 !important;
    padding: 3px 4px !important;
    font-size: 12px !important;
    line-height: 1.25 !important;
    text-align: center !important;
    white-space: normal !important;
  }
}


.community-idea-review-feedback{display:inline-block;margin:8px 0;padding:8px 12px;border:0;border-radius:7px;background:#dcefe5;color:#20583b;font:inherit;cursor:pointer}
.community-idea-review-feedback:hover{background:#cce5d7}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.member-entry').forEach(function (entry) {
        const name = entry.querySelector('.member-name');
        const profile = entry.querySelector('.member-profile');
        if (!name || !profile) return;
        function positionProfile() {
            const rect = name.getBoundingClientRect();
            const width = Math.min(260, window.innerWidth - 24);
            let top = rect.bottom + 6;
            if (top + Math.min(profile.scrollHeight, window.innerHeight * 0.6) > window.innerHeight - 12) {
                top = rect.top - Math.min(profile.scrollHeight, window.innerHeight * 0.6) - 6;
            }
            top = Math.max(12, Math.min(top, window.innerHeight - 80));
            let right = Math.max(12, window.innerWidth - rect.right);
            if (right + width > window.innerWidth - 12) right = 12;
            profile.style.top = top + 'px';
            profile.style.right = right + 'px';
        }
        name.addEventListener('mouseenter', positionProfile);
        name.addEventListener('focus', positionProfile);
        entry.addEventListener('mouseenter', positionProfile);
    });
});
</script>

</head>
<body<?= (isset($community['name']) && trim($community['name']) === 'کشور') ? ' class="country-community-page"' : '' ?>>
<div class="container">

<?php if ($community): ?>
<div class="box community-header">
  <div class="community-header-top" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <div style="flex:1 1 auto;min-width:0;">
      <h2 style="margin:0;"><?=htmlspecialchars($community['name'])?></h2>
      <?php if (!empty($community['community_type'])): ?>
        <div style="margin-top:6px;color:#4b5563;font-size:13px;">نوع جامعه: <strong><?= htmlspecialchars($community['community_type'], ENT_QUOTES, 'UTF-8') ?></strong></div>
      <?php endif; ?>
    </div>
    <a href="community_stats.php?community_id=<?= (int)$community['id'] ?>" style="display:inline-flex;align-items:center;justify-content:center;gap:6px;background:#0099cc;color:#fff;text-decoration:none;padding:9px 13px;border-radius:8px;font-size:13px;font-weight:bold;white-space:nowrap;box-sizing:border-box;">📊 گزارش آماری جامعه</a>
    <a href="search.php" style="display:inline-flex;align-items:center;justify-content:center;gap:6px;background:#1769aa;color:#fff;text-decoration:none;padding:9px 13px;border-radius:8px;font-size:13px;font-weight:bold;white-space:nowrap;box-sizing:border-box;">🔎 سرچ</a>
  </div>
  <div class="community-mobile-actions" id="communityMobileActions" aria-label="دسترسی‌های جامعه"></div>
  <a class="finance-bank-toggle" id="finance-bank-toggle" href="finance.php?community_id=<?= (int)$community_id ?>">🏦 بانک مالی و اموال جامعه</a>
  <div style="margin:0 0 12px;text-align:right;">
    <a href="profile.php" style="display:inline-block;background:#2065d1;color:#fff;text-decoration:none;padding:9px 14px;border-radius:8px;font-weight:bold;font-size:14px;">↩ بازگشت به پروفایل</a>
  </div>

  <?php if (!empty($community['created_at']) && ($community_created_timestamp = strtotime($community['created_at'])) !== false): ?>
    <?php $community_jalali = community_gregorian_to_jalali((int)date('Y', $community_created_timestamp), (int)date('n', $community_created_timestamp), (int)date('j', $community_created_timestamp)); ?>
    <p style="margin: -4px 0 12px; color: #4b5563; font-size: 13px;">📅 تاریخ ایجاد جامعه: <strong><?= community_persian_digits(sprintf('%04d/%02d/%02d', $community_jalali[0], $community_jalali[1], $community_jalali[2])) ?></strong></p>
  <?php endif; ?>

  <?php
  // قابلیت وظایف و رویکردهای پیشنهادی فقط در صورت نصب جدول‌های افزوده‌شده فعال می‌شود.
  $community_duties_ready = false;
  $duty_tables_check = $conn->query("SHOW TABLES LIKE 'community_duties'");
  $vote_tables_check = $conn->query("SHOW TABLES LIKE 'community_duty_votes'");
  if ($duty_tables_check && $vote_tables_check) {
      $community_duties_ready = ($duty_tables_check->num_rows > 0 && $vote_tables_check->num_rows > 0);
  }
  if ($duty_tables_check) $duty_tables_check->close();
  if ($vote_tables_check) $vote_tables_check->close();

  $is_community_member = false;
  $member_check = $conn->prepare("SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1");
  if ($member_check) {
      $member_check->bind_param("ii", $community['id'], $user_id);
      $member_check->execute();
      $is_community_member = (bool)$member_check->get_result()->fetch_row();
      $member_check->close();
  }
  ?>

  <?php if ($is_community_member): ?>
    <form method="post" action="leave_community.php"
          onsubmit="return confirm('آیا مطمئن هستید که می‌خواهید از عضویت این جامعه خارج شوید؟');"
          style="margin:0 0 12px;text-align:right;">
      <input type="hidden" name="community_id" value="<?= (int)$community['id'] ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($community_duty_csrf, ENT_QUOTES, 'UTF-8') ?>">
      <button type="submit" style="display:inline-block;width:auto;background:#c62828;color:#fff;padding:9px 14px;border-radius:8px;font-weight:bold;font-size:14px;">
        🚪 خروج از عضویت جامعه
      </button>
    </form>
  <?php endif; ?>

  <?php
  $duty_proposals = [];
  if ($community_duties_ready) {
      $duty_query = $conn->prepare("
          SELECT d.id, d.proposal_type, d.content, d.created_at, u.username,
                 COUNT(v.user_id) AS vote_count,
                 MAX(CASE WHEN v.user_id = ? THEN 1 ELSE 0 END) AS current_user_voted
          FROM community_duties d
          JOIN users u ON u.id = d.user_id
          LEFT JOIN community_duty_votes v ON v.proposal_id = d.id
          WHERE d.community_id = ?
          GROUP BY d.id, d.proposal_type, d.content, d.created_at, u.username
          ORDER BY vote_count DESC, d.id ASC
          LIMIT 10
      ");
      if ($duty_query) {
          $duty_query->bind_param("ii", $user_id, $community['id']);
          $duty_query->execute();
          $duty_result = $duty_query->get_result();
          while ($duty_row = $duty_result->fetch_assoc()) $duty_proposals[] = $duty_row;
          $duty_query->close();
      }
  }
  ?>
  <?php
  // متن اولیه تا زمانی که دست‌کم یک پیشنهاد رأی معقولیت نگیرد، متن جاری جامعه باقی می‌ماند.
  $current_duty = null;
  if (!empty($duty_proposals) && (int)$duty_proposals[0]['vote_count'] > 0) {
      $current_duty = $duty_proposals[0];
  }
  ?>
  <p style="margin:10px 0 14px;line-height:1.9;"><strong>وظایف و رویکردهای جامعه:</strong><br>
    <span class="community-duty-description">
    <?php if ($current_duty): ?>
      <?= nl2br(htmlspecialchars($current_duty['content'], ENT_QUOTES, 'UTF-8')) ?>
      <span style="display:inline-block;color:#946200;font-size:12px;">(<?= (int)$current_duty['vote_count'] ?> رأی معقولیت)</span>
    <?php else: ?>
      <?= nl2br(htmlspecialchars((string)($community['approach'] ?? ''), ENT_QUOTES, 'UTF-8')) ?>
    <?php endif; ?>
    </span>
  </p>
  <details class="community-duties-toggle">
    <summary>پیشنهاد برای رویکردها و وظایف این جامعه</summary>
    <div class="community-duties" id="community-duties">
    <h3>🌱 وظایف و رویکردها</h3>
    <p class="community-duties-help">اعضای جامعه پیشنهاد می‌دهند و با رأی معقولیت، مناسب‌ترین پیشنهادها در صدر قرار می‌گیرند.</p>
    <?php if (!$community_duties_ready): ?>
      <p class="community-duties-notice">برای فعال‌شدن این بخش، یک‌بار فایل <strong>community_duties.sql</strong> موجود در مخزن را روی پایگاه داده هاست اجرا کنید. هیچ داده‌ای حذف یا بازنویسی نمی‌شود.</p>
    <?php else: ?>
      <?php if (count($duty_proposals) > 0): ?>
        <div class="community-duty-list">
          <?php foreach ($duty_proposals as $idx => $proposal): ?>
            <div class="community-duty-item">
              <div class="community-duty-content">
                <?php if ($idx === 0 && (int)$proposal['vote_count'] > 0): ?><strong class="community-duty-top">★ معقول‌ترین پیشنهاد فعلی</strong><?php endif; ?>
                <span class="community-duty-type">پیشنهاد رویکرد و وظایف</span>
                <div><?= nl2br(htmlspecialchars($proposal['content'], ENT_QUOTES, 'UTF-8')) ?></div>
                <small>پیشنهاددهنده: <?= htmlspecialchars($proposal['username'], ENT_QUOTES, 'UTF-8') ?> · رأی معقولیت: <?= (int)$proposal['vote_count'] ?></small>
              </div>
              <?php if ($is_community_member): ?>
                <form method="post" action="community_duty_action.php" class="community-duty-vote-form">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($community_duty_csrf, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="action" value="vote">
                  <input type="hidden" name="community_id" value="<?= (int)$community['id'] ?>">
                  <input type="hidden" name="proposal_id" value="<?= (int)$proposal['id'] ?>">
                  <button type="submit" class="<?= (int)$proposal['current_user_voted'] === 1 ? 'voted' : '' ?>">
                    <?= (int)$proposal['current_user_voted'] === 1 ? '✓ رأی شما' : 'رأی معقولیت' ?>
                  </button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if (count($duty_proposals) === 10): ?><small class="community-duties-help">۱۰ پیشنهاد برتر نمایش داده شده‌اند.</small><?php endif; ?>
      <?php else: ?>
        <p class="community-duties-empty">هنوز پیشنهادی ثبت نشده است.</p>
      <?php endif; ?>

      <?php if ($is_community_member): ?>
        <form method="post" action="community_duty_action.php" class="community-duty-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($community_duty_csrf, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="submit">
          <input type="hidden" name="community_id" value="<?= (int)$community['id'] ?>">
          <label for="community-duty-content">متن پیشنهادی برای رویکرد و وظایف</label>
          <textarea id="community-duty-content" name="content" rows="3" maxlength="600" required placeholder="پیشنهاد روشن و قابل‌فهم خود را بنویسید؛ حداکثر ۶۰۰ نویسه."></textarea>
          <button type="submit">➕ ثبت پیشنهاد</button>
        </form>
      <?php else: ?>
        <p class="community-duties-notice">برای ثبت پیشنهاد یا رأی‌دادن، ابتدا باید عضو این جامعه باشید.</p>
      <?php endif; ?>
    <?php endif; ?>
    </div>
  </details>

  <?php
  // ساختار سلسله‌مراتبی جوامع؛ ستون والد با فایل SQL جداگانه و به‌صورت کنترل‌شده افزوده می‌شود.
  $hierarchy_ready = false;
  $hierarchy_column_check = $conn->query("SHOW COLUMNS FROM communities LIKE 'parent_community_id'");
  if ($hierarchy_column_check) {
      $hierarchy_ready = ($hierarchy_column_check->num_rows > 0);
      $hierarchy_column_check->close();
  }
  $community_parent = null;
  $community_children = [];
  $hierarchy_by_id = [];
  $hierarchy_children = [];
  $ancestor_chain = [];
  if ($hierarchy_ready) {
      $all_communities_result = $conn->query("SELECT id, name, parent_community_id FROM communities ORDER BY name ASC");
      if ($all_communities_result) {
          while ($row = $all_communities_result->fetch_assoc()) {
              $row['id'] = (int)$row['id'];
              $row['parent_community_id'] = $row['parent_community_id'] === null ? 0 : (int)$row['parent_community_id'];
              $hierarchy_by_id[$row['id']] = $row;
              $hierarchy_children[$row['parent_community_id']][] = $row;
          }
          $all_communities_result->close();
      }

      $parent_id = $hierarchy_by_id[(int)$community['id']]['parent_community_id'] ?? 0;
      if ($parent_id > 0 && isset($hierarchy_by_id[$parent_id])) {
          $community_parent = $hierarchy_by_id[$parent_id];
      }

      $child_rows = $hierarchy_children[(int)$community['id']] ?? [];
      foreach ($child_rows as $child_row) $community_children[] = $child_row;

      // مسیر والدها را تا ریشه پیدا می‌کنیم؛ از چرخه‌های احتمالی نیز محافظت می‌کنیم.
      $cursor = (int)$community['id'];
      $seen_ancestors = [];
      while ($cursor > 0 && isset($hierarchy_by_id[$cursor]) && !isset($seen_ancestors[$cursor])) {
          $seen_ancestors[$cursor] = true;
          array_unshift($ancestor_chain, $hierarchy_by_id[$cursor]);
          $cursor = (int)$hierarchy_by_id[$cursor]['parent_community_id'];
      }
  }
  if (empty($_SESSION['community_hierarchy_csrf'])) {
      $_SESSION['community_hierarchy_csrf'] = bin2hex(random_bytes(32));
  }
  $hierarchy_status = $_GET['hierarchy_status'] ?? '';
  ?>
  <details id="community-hierarchy-toggle" style="margin:14px 0;">
    <summary style="display:block;cursor:pointer;background:#2065d1;color:#fff;padding:10px 14px;border-radius:8px;text-align:center;font-weight:bold;list-style:none;">🌐 جایگاه این جامعه (نمایش درخت)</summary>
  <section id="community-hierarchy" style="margin:14px 0;padding:12px;background:rgba(255,255,255,.82);border:1px solid #d9dfe8;border-radius:9px;text-align:right;">
    <h3 style="margin:0 0 7px;">🌐 جایگاه این جامعه</h3>
    <?php if (!$hierarchy_ready): ?>
      <p style="font-size:13px;line-height:1.9;margin:6px 0;">برای نمایش درخت جایگاه، ابتدا باید ستون جایگاه جوامع در پایگاه داده فعال باشد.</p>
    <?php else: ?>
      <p style="font-size:13px;line-height:1.9;margin:6px 0;">
        <?php if ($community_parent): ?>
          این جامعه زیرمجموعهٔ <strong><?= htmlspecialchars($community_parent['name'], ENT_QUOTES, 'UTF-8') ?></strong> است.
        <?php else: ?>
          این جامعه در حال حاضر <strong>مستقل</strong> است.
        <?php endif; ?>
      </p>
      <p style="font-size:13px;line-height:1.9;margin:8px 0;color:#596579;">
        درخت جایگاه در همین کادر نمایش داده می‌شود. برای ثبت یا تغییر رأی، به بخش «رأی‌گیری دربارهٔ جایگاه جوامع» در صفحهٔ پروفایل بروید.
      </p>
      <?php if (!empty($ancestor_chain)): ?>
        <div style="margin-top:10px;padding:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;line-height:1.9;">
          <strong style="display:block;margin-bottom:6px;">🌳 درخت جایگاه جامعه</strong>
          <?php foreach ($ancestor_chain as $depth => $tree_node): ?>
            <div style="margin-right:<?= (int)$depth * 18 ?>px;padding:6px 9px;margin-top:4px;border-right:3px solid <?= (int)$tree_node['id'] === (int)$community['id'] ? '#2065d1' : '#cbd5e1' ?>;background:<?= (int)$tree_node['id'] === (int)$community['id'] ? '#eaf2ff' : '#fff' ?>;border-radius:5px;">
              <?php if ((int)$tree_node['id'] === (int)$community['id']): ?>
                <strong>📍 <?= htmlspecialchars($tree_node['name'], ENT_QUOTES, 'UTF-8') ?> <span style="font-size:11px;color:#2065d1;">(جامعهٔ جاری)</span></strong>
              <?php else: ?>
                <a href="community.php?id=<?= (int)$tree_node['id'] ?>" style="font-weight:normal;">🌐 <?= htmlspecialchars($tree_node['name'], ENT_QUOTES, 'UTF-8') ?></a>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php
          $render_hierarchy_children = function($parent_id, $depth = 1) use (&$render_hierarchy_children, &$hierarchy_children, $community) {
              $nodes = $hierarchy_children[(int)$parent_id] ?? [];
              if (!$nodes) return;
              echo '<div style="margin-right:'.((int)$depth * 18).'px;border-right:2px solid #dbe4ef;padding-right:9px;">';
              foreach ($nodes as $node) {
                  $is_current = (int)$node['id'] === (int)$community['id'];
                  echo '<div style="margin-top:5px;padding:6px 9px;background:'.($is_current ? '#eaf2ff' : '#fff').';border:1px solid '.($is_current ? '#b8d1f5' : '#e2e8f0').';border-radius:5px;line-height:1.8;">';
                  if ($is_current) {
                      echo '<strong>📍 '.htmlspecialchars($node['name'], ENT_QUOTES, 'UTF-8').' <span style="font-size:11px;color:#2065d1;">(جامعهٔ جاری)</span></strong>';
                  } else {
                      echo '<a href="community.php?id='.(int)$node['id'].'" style="font-weight:normal;">'.htmlspecialchars($node['name'], ENT_QUOTES, 'UTF-8').'</a>';
                  }
                  echo '</div>';
                  $render_hierarchy_children((int)$node['id'], $depth + 1);
              }
              echo '</div>';
          };
          ?>
          <?php $render_hierarchy_children((int)$community['id'], 1); ?>
        </div>
      <?php else: ?>
        <p style="font-size:13px;color:#667085;">درخت جایگاه جامعه فعلاً در دسترس نیست.</p>
      <?php endif; ?>
    <?php endif; ?>
  </section>
  </details>

  <?php
  // تعداد واقعی اعضا از جدول عضویت خوانده می‌شود تا با شمارندهٔ ذخیره‌شدهٔ قدیمی اختلاف نداشته باشد.
  $actual_member_count = 0;
  $member_count_query = $conn->prepare("SELECT COUNT(*) AS total FROM community_members WHERE community_id=?");
  if ($member_count_query) {
      $member_count_query->bind_param("i", $community_id);
      $member_count_query->execute();
      $member_count_result = $member_count_query->get_result()->fetch_assoc();
      $actual_member_count = (int)($member_count_result['total'] ?? 0);
      $member_count_query->close();
  }
  ?>
  <p><strong>تعداد اعضا:</strong> <?= community_persian_digits($actual_member_count) ?></p>
  <div class="members-dropdown">
    <button class="members-button">👥 مشاهده اعضا</button>
    <div class="members-list">
      <?php
      // محبوبیت از میانگین چهار معیار موجود در user_scores محاسبه می‌شود؛
      // فقط امتیازدهندگان عضو همین جامعه در رتبه‌بندی لحاظ می‌شوند.
      $mem_q = $conn->prepare("
          SELECT users.id, users.username, users.age, users.gender, users.education,
                 users.job, users.city, users.resume,
                 AVG(NULLIF(us.lawfulness, 0)) AS avg_lawfulness,
                 AVG(NULLIF(us.ethics, 0)) AS avg_ethics,
                 AVG(NULLIF(us.honesty, 0)) AS avg_honesty,
                 AVG(NULLIF(us.executive_skill, 0)) AS avg_executive,
                 COUNT(CASE WHEN COALESCE(us.lawfulness, 0) > 0 OR COALESCE(us.ethics, 0) > 0 OR COALESCE(us.honesty, 0) > 0 OR COALESCE(us.executive_skill, 0) > 0 THEN us.scorer_user_id END) AS popularity_votes
          FROM community_members
          JOIN users ON users.id = community_members.user_id
          LEFT JOIN user_scores us
            ON us.target_user_id = users.id
           AND us.scorer_user_id <> users.id
           AND us.scorer_user_id IN (
               SELECT cm2.user_id
               FROM community_members cm2
               WHERE cm2.community_id = community_members.community_id
           )
          WHERE community_members.community_id = ?
          GROUP BY users.id, users.username, users.age, users.gender, users.education,
                   users.job, users.city, users.resume
          ORDER BY popularity_votes DESC, users.username ASC
      ");
      $mem_q->bind_param("i", $community['id']);
      $mem_q->execute();
      $mem_res = $mem_q->get_result();
      if ($mem_res->num_rows > 0) {
          // رتبهٔ این فهرست با رتبهٔ کادر اطلاعات یکسان است؛ اعضای بدون امتیاز رتبه نمی‌گیرند.
          $members_for_ranking = [];
          while ($member_row = $mem_res->fetch_assoc()) {
              $criterion_scores = [
                  $member_row['avg_lawfulness'],
                  $member_row['avg_ethics'],
                  $member_row['avg_honesty'],
                  $member_row['avg_executive']
              ];
              $valid_scores = array_filter($criterion_scores, function($value) { return $value !== null; });
              $member_row['popularity_score'] = count($valid_scores) > 0
                  ? array_sum($valid_scores) / count($valid_scores)
                  : 0;
              $members_for_ranking[] = $member_row;
          }

          usort($members_for_ranking, function($a, $b) {
              $aVotes = (int)$a['popularity_votes'];
              $bVotes = (int)$b['popularity_votes'];
              if (($aVotes > 0) !== ($bVotes > 0)) return $aVotes > 0 ? -1 : 1;
              if ($aVotes > 0 && (float)$a['popularity_score'] !== (float)$b['popularity_score']) {
                  return (float)$b['popularity_score'] <=> (float)$a['popularity_score'];
              }
              if ($aVotes !== $bVotes) return $bVotes <=> $aVotes;
              return strcmp((string)$a['username'], (string)$b['username']);
          });

          foreach ($members_for_ranking as $m) {
              $score_value = (float)$m['popularity_score'];
              $score_votes = (int)$m['popularity_votes'];
              $member_rank = null;
              if ($score_votes > 0) {
                  $member_rank = 1;
                  foreach ($members_for_ranking as $other_member) {
                      if ((int)$other_member['id'] !== (int)$m['id']
                          && (int)$other_member['popularity_votes'] > 0
                          && (float)$other_member['popularity_score'] > $score_value) {
                          $member_rank++;
                      }
                  }
              }
              $profile_fields = [
                  'سن' => $m['age'] ?? '',
                  'جنسیت' => $m['gender'] ?? '',
                  'تحصیلات' => $m['education'] ?? '',
                  'شغل' => $m['job'] ?? '',
                  'شهر' => $m['city'] ?? '',
                  'معرفی' => $m['resume'] ?? ''
              ];
              $score_label = number_format($score_value, 2, '.', '');
              echo "<div class='member-entry'>";
              echo "<span class='member-name' tabindex='0'>".($member_rank === 1 ? "🥇 " : "").htmlspecialchars($m['username'], ENT_QUOTES, 'UTF-8')."</span>";
              echo "<div class='member-profile'>";
              if ($member_rank !== null) {
                  echo "<div><strong>رتبه محبوبیت در این جامعه:</strong> ".(int)$member_rank." از ".$actual_member_count." عضو</div>";
              } else {
                  echo "<div><strong>رتبه محبوبیت در این جامعه:</strong> هنوز رتبه‌ای ندارد</div>";
              }
              if ((int)$m['popularity_votes'] > 0) {
                  echo "<div><strong>میانگین محبوبیت:</strong> ".htmlspecialchars($score_label, ENT_QUOTES, 'UTF-8')." از ۵</div>";
                  echo "<div><strong>تعداد ارزیابی‌ها:</strong> ".(int)$m['popularity_votes']."</div>";
              } else {
                  echo "<div style='color:#667085'>هنوز از سوی اعضای این جامعه ارزیابی نشده است.</div>";
              }
              foreach ($profile_fields as $label => $value) {
                  $value = trim((string)$value);
                  if ($value !== '') {
                      echo "<div><strong>".htmlspecialchars($label, ENT_QUOTES, 'UTF-8').":</strong> ".nl2br(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'))."</div>";
                  }
              }
              if (count(array_filter($profile_fields, function($value) { return trim((string)$value) !== ''; })) === 0) {
                  echo "<span style='color:#777'>اطلاعات پروفایل ثبت نشده است.</span>";
              }
              if ($is_community_member && (int)$m['id'] !== $user_id) {
                  $score_url = "give_score.php?user_id=".(int)$m['id']."&redirect=".urlencode("community.php?id=".(int)$community['id']);
                  echo "<div style='margin-top:7px'><a href='".htmlspecialchars($score_url, ENT_QUOTES, 'UTF-8')."' style='display:inline-block;background:#ff9800;color:#fff;padding:5px 8px;border-radius:5px;font-size:12px;'>⭐ ثبت / تغییر امتیاز محبوبیت</a></div>";
              }
              if ($is_community_member && (int)$m['id'] !== $user_id && $community_removal_ready) {
                  echo "<form method='post' action='request_member_removal.php' style='margin-top:7px' onsubmit=\"return confirm('آیا می‌خواهید درخواست رأی‌گیری برای اخراج این عضو ثبت شود؟ مهلت رأی‌گیری سه روز است.');\">";
                  echo "<input type='hidden' name='community_id' value='".(int)$community['id']."'>";
                  echo "<input type='hidden' name='target_user_id' value='".(int)$m['id']."'>";
                  echo "<input type='hidden' name='csrf_token' value='".htmlspecialchars($community_duty_csrf, ENT_QUOTES, 'UTF-8')."'>";
                  echo "<button type='submit' style='background:#b42318;color:#fff;padding:5px 8px;border-radius:5px;font-size:12px;margin:0'>⚖️ درخواست رأی‌گیری برای اخراج</button>";
                  echo "</form>";
              }
              echo "</div></div>";
          }
      } else {
          echo "<p style='color:#777'>عضوی وجود ندارد</p>";
      }
      $mem_q->close();
      ?>
    </div>
  </div>
</div>

<?php
// درخواست‌ها و نتایج رأی‌گیری اخراج عضو
$removal_requests = [];
if ($community_removal_ready && $community_id > 0) {
    $rq = $conn->prepare("SELECT r.*, target.username AS target_username, requester.username AS requester_username,
        (SELECT COUNT(*) FROM community_member_removal_votes v WHERE v.request_id=r.id AND v.vote='yes') AS yes_count,
        (SELECT COUNT(*) FROM community_member_removal_votes v WHERE v.request_id=r.id AND v.vote='no') AS no_count,
        (SELECT v2.vote FROM community_member_removal_votes v2 WHERE v2.request_id=r.id AND v2.user_id=? LIMIT 1) AS my_vote
        FROM community_member_removal_requests r
        LEFT JOIN users target ON target.id=r.target_user_id
        LEFT JOIN users requester ON requester.id=r.requester_user_id
        WHERE r.community_id=?
        ORDER BY CASE WHEN r.status='open' THEN 0 ELSE 1 END, r.created_at DESC
        LIMIT 20");
    if ($rq) {
        $rq->bind_param("ii", $user_id, $community_id);
        $rq->execute();
        $rr = $rq->get_result();
        while ($row = $rr->fetch_assoc()) $removal_requests[] = $row;
        $rq->close();
    }
}
$removal_messages = [
    'created' => 'درخواست رأی‌گیری ثبت شد؛ اعضا سه روز فرصت رأی‌دادن دارند.',
    'voted' => 'رأی شما ثبت شد؛ تا پایان مهلت می‌توانید آن را تغییر دهید.',
    'exists' => 'برای این عضو یک رأی‌گیری باز وجود دارد.',
    'closed' => 'مهلت این رأی‌گیری پایان یافته یا رأی‌گیری بسته شده است.',
    'membership' => 'برای انجام این کار باید عضو فعلی جامعه باشید و فرد هدف نیز عضو جامعه باشد.',
    'invalid' => 'اطلاعات درخواست معتبر نیست.',
    'error' => 'ثبت درخواست انجام نشد؛ لطفاً دوباره تلاش کنید.',
    'setup' => 'برای فعال‌شدن رأی‌گیری اخراج، ابتدا فایل SQL افزونه را در پایگاه داده اجرا کنید.'
];
$removal_msg_key = (string)($_GET['removal_msg'] ?? '');
?>
<details class="box" style="text-align:right;border-color:#e6b8b8;padding:0;overflow:hidden">
  <summary style="display:block;cursor:pointer;list-style:none;padding:12px 14px;background:#fff1f1;color:#8f1d1d;border-radius:10px;font-weight:bold">⚖️ رأی‌گیری برای اخراج عضو <span style="font-size:12px;font-weight:normal;color:#667085"> (برای مشاهده درخواست‌ها و رأی‌ها بزنید)</span></summary>
  <div style="padding:14px">
  <h3 style="margin-bottom:8px">⚖️ رأی‌گیری برای اخراج عضو</h3>
  <?php if (isset($removal_messages[$removal_msg_key])): ?>
    <p style="padding:8px;background:#fff8e1;border-radius:6px;color:#624b00"><?= htmlspecialchars($removal_messages[$removal_msg_key], ENT_QUOTES, 'UTF-8') ?></p>
  <?php endif; ?>
  <?php if (!$community_removal_ready): ?>
    <p style="font-size:13px;color:#667085;line-height:1.9">این قابلیت هنوز فعال نشده است. برای فعال‌سازی، فایل <code>community_member_removal.sql</code> باید یک‌بار در پایگاه داده اجرا شود؛ این کار در حال حاضر خودکار انجام نمی‌شود.</p>
  <?php elseif (empty($removal_requests)): ?>
    <p style="font-size:13px;color:#667085">درخواست یا نتیجه‌ای برای نمایش وجود ندارد.</p>
  <?php else: ?>
    <?php foreach ($removal_requests as $removal): ?>
      <?php
        $yes_votes = (int)$removal['yes_count'];
        $no_votes = (int)$removal['no_count'];
        $total_votes = $yes_votes + $no_votes;
        $required_votes = (int)ceil(((int)$removal['member_count_snapshot']) / 3);
        $remaining_seconds = max(0, strtotime($removal['deadline_at']) - time());
        $target_name = (string)($removal['target_username'] ?? ('عضو ' . (int)$removal['target_user_id']));
      ?>
      <div style="border:1px solid #e5e7eb;border-radius:8px;padding:10px;margin:10px 0;background:#fff">
        <div><strong>عضو مورد درخواست:</strong> <?= htmlspecialchars($target_name, ENT_QUOTES, 'UTF-8') ?></div>
        <div style="font-size:12px;color:#667085;margin-top:4px">درخواست‌دهنده: <?= htmlspecialchars((string)($removal['requester_username'] ?? 'عضو جامعه'), ENT_QUOTES, 'UTF-8') ?></div>
        <div style="margin-top:7px;line-height:1.9">
          موافق: <strong><?= $yes_votes ?></strong> — مخالف: <strong><?= $no_votes ?></strong><br>
          مشارکت: <strong><?= $total_votes ?></strong> از حد نصاب <strong><?= $required_votes ?></strong> رأی<br>
          <?php if ($removal['status'] === 'open'): ?>
            <span style="color:#9a6700">وضعیت: در حال رأی‌گیری</span><br>
            <span style="font-size:12px;color:#667085">زمان باقی‌مانده: <?= (int)floor($remaining_seconds / 86400) ?> روز و <?= (int)floor(($remaining_seconds % 86400) / 3600) ?> ساعت</span>
          <?php elseif ($removal['status'] === 'approved'): ?>
            <strong style="color:#16803c">نتیجه: اخراج با رأی اعضا تصویب شد.</strong>
          <?php else: ?>
            <strong style="color:#667085">نتیجه: درخواست تصویب نشد (حد نصاب یا اکثریت موافق حاصل نشد، یا عضو پیش‌تر خارج شده بود).</strong>
          <?php endif; ?>
        </div>
        <?php if ($removal['status'] === 'open' && $is_community_member && $remaining_seconds > 0): ?>
          <form method="post" action="vote_member_removal.php" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
            <input type="hidden" name="community_id" value="<?= (int)$community_id ?>">
            <input type="hidden" name="request_id" value="<?= (int)$removal['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($community_duty_csrf, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" name="vote" value="yes" style="margin:0;background:#16803c"><?= $removal['my_vote'] === 'yes' ? '✓ رأی موافق شما — تغییر به موافق' : 'موافق اخراج' ?></button>
            <button type="submit" name="vote" value="no" style="margin:0;background:#b42318"><?= $removal['my_vote'] === 'no' ? '✓ رأی مخالف شما — تغییر به مخالف' : 'مخالف اخراج' ?></button>
          </form>
        <?php elseif ($removal['status'] === 'open' && !$is_community_member): ?>
          <p style="font-size:12px;color:#667085">برای رأی‌دادن باید عضو این جامعه باشید.</p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
  </div>
</details>



<?php
// نتیجهٔ پیشنهاد حوزه فقط پس از پایان مهلت هفت‌روزه قطعی می‌شود.
// در تساوی، وضعیت pending باقی می‌ماند و رأی‌گیری بسته می‌شود.
$topic_expertise_proposals = [];
try {
    $finalize_expertise = $conn->prepare("
        UPDATE topic_expertise_proposals p
        SET p.status = CASE
            WHEN (SELECT COUNT(*) FROM topic_expertise_proposal_votes v WHERE v.proposal_id=p.id AND v.vote='yes')
               > (SELECT COUNT(*) FROM topic_expertise_proposal_votes v WHERE v.proposal_id=p.id AND v.vote='no') THEN 'approved'
            WHEN (SELECT COUNT(*) FROM topic_expertise_proposal_votes v WHERE v.proposal_id=p.id AND v.vote='no')
               > (SELECT COUNT(*) FROM topic_expertise_proposal_votes v WHERE v.proposal_id=p.id AND v.vote='yes') THEN 'rejected'
            ELSE 'pending'
        END,
        p.decided_at = CASE
            WHEN (SELECT COUNT(*) FROM topic_expertise_proposal_votes v WHERE v.proposal_id=p.id AND v.vote='yes')
               <> (SELECT COUNT(*) FROM topic_expertise_proposal_votes v WHERE v.proposal_id=p.id AND v.vote='no') THEN NOW()
            ELSE NULL
        END
        WHERE p.community_id=? AND p.status='pending' AND p.expires_at IS NOT NULL AND p.expires_at<=NOW()
    ");
    $finalize_expertise->bind_param("i", $community_id);
    $finalize_expertise->execute();
    $finalize_expertise->close();

    $expertise_list = $conn->prepare("
        SELECT p.id,p.status,p.expires_at,t.title AS topic_title,d.name AS domain_name,
               (SELECT COUNT(*) FROM topic_expertise_proposal_votes v WHERE v.proposal_id=p.id AND v.vote='yes') AS yes_count,
               (SELECT COUNT(*) FROM topic_expertise_proposal_votes v WHERE v.proposal_id=p.id AND v.vote='no') AS no_count,
               (SELECT v.vote FROM topic_expertise_proposal_votes v WHERE v.proposal_id=p.id AND v.user_id=? LIMIT 1) AS my_vote
        FROM topic_expertise_proposals p
        JOIN topics t ON t.id=p.topic_id
        JOIN expertise_domains d ON d.id=p.domain_id
        WHERE p.community_id=?
        ORDER BY CASE WHEN p.status='pending' AND p.expires_at>NOW() THEN 0 ELSE 1 END, p.proposed_at DESC
        LIMIT 20
    ");
    $expertise_list->bind_param("ii", $user_id, $community_id);
    $expertise_list->execute();
    $expertise_result = $expertise_list->get_result();
    while ($expertise_row = $expertise_result->fetch_assoc()) $topic_expertise_proposals[] = $expertise_row;
    $expertise_list->close();
} catch (Throwable $e) {
    error_log("Topic expertise proposal voting unavailable: " . $e->getMessage());
}
?>
<details id="topic-expertise-proposals" style="margin:12px 0;padding:12px;background:#f2f7ff;border:1px solid #c8d9f2;border-radius:10px;">
  <summary style="cursor:pointer;font-weight:bold;">⚖️ رأی‌گیری حوزه‌های تخصصی موضوعات (<?= count($topic_expertise_proposals) ?>)</summary>
  <div style="margin-top:10px;">
    <p style="font-size:12px;color:#475467;line-height:1.8;">هر پیشنهاد ۷ روز فرصت رأی‌گیری دارد. فقط اعضای این جامعه می‌توانند رأی بدهند و تا پایان مهلت امکان تغییر رأی وجود دارد. پس از پایان مهلت، اکثریت موافق یا مخالف نتیجه را تعیین می‌کند؛ تساوی در انتظار باقی می‌ماند.</p>
    <?php if (!$topic_expertise_proposals): ?>
      <p style="color:#667085;font-size:13px;">هنوز پیشنهادی برای تعیین حوزهٔ تخصصی ثبت نشده است.</p>
    <?php else: ?>
      <?php foreach ($topic_expertise_proposals as $ep): ?>
        <?php $expertise_open = ($ep['status'] === 'pending' && !empty($ep['expires_at']) && strtotime($ep['expires_at']) > time()); ?>
        <div style="background:#fff;border:1px solid #dbe4f0;border-radius:8px;padding:10px;margin:9px 0;">
          <div style="font-weight:bold;line-height:1.8;"><?= htmlspecialchars($ep['topic_title'], ENT_QUOTES, 'UTF-8') ?></div>
          <div style="font-size:13px;margin:3px 0 7px;">حوزهٔ پیشنهادی: <strong><?= htmlspecialchars($ep['domain_name'], ENT_QUOTES, 'UTF-8') ?></strong></div>
          <div style="font-size:12px;color:#475467;">موافق: <?= (int)$ep['yes_count'] ?>　 |　 مخالف: <?= (int)$ep['no_count'] ?></div>
          <?php if ($expertise_open): ?>
            <div style="font-size:12px;color:#667085;margin:5px 0;">پایان مهلت: <?= htmlspecialchars(community_format_jalali_datetime($ep['expires_at']), ENT_QUOTES, 'UTF-8') ?></div>
            <?php if ($is_community_member): ?>
              <form method="post" action="topic_expertise_proposal_vote.php" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($community_duty_csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="community_id" value="<?= (int)$community_id ?>">
                <input type="hidden" name="proposal_id" value="<?= (int)$ep['id'] ?>">
                <button type="submit" name="vote" value="yes" style="background:<?= $ep['my_vote']==='yes' ? '#176b37' : '#2e8b57' ?>;color:#fff;padding:7px 12px;border:0;border-radius:6px;"><?= $ep['my_vote']==='yes' ? '✓ رأی موافق شما' : 'موافق' ?></button>
                <button type="submit" name="vote" value="no" style="background:<?= $ep['my_vote']==='no' ? '#9f2525' : '#c0392b' ?>;color:#fff;padding:7px 12px;border:0;border-radius:6px;"><?= $ep['my_vote']==='no' ? '✓ رأی مخالف شما' : 'مخالف' ?></button>
              </form>
            <?php else: ?>
              <small style="color:#667085;">برای رأی‌دادن باید عضو جامعه باشید.</small>
            <?php endif; ?>
          <?php elseif ($ep['status'] === 'approved'): ?>
            <div style="margin-top:7px;color:#176b37;font-weight:bold;font-size:13px;">✓ حوزه تأیید شد؛ پس از این مرحله می‌تواند مبنای وزن تخصصی این موضوع باشد.</div>
          <?php elseif ($ep['status'] === 'rejected'): ?>
            <div style="margin-top:7px;color:#a12626;font-weight:bold;font-size:13px;">✕ حوزهٔ پیشنهادی رد شد.</div>
          <?php else: ?>
            <div style="margin-top:7px;color:#946200;font-weight:bold;font-size:13px;">مهلت پایان یافته و رأی‌ها مساوی‌اند؛ پیشنهاد در انتظار تصمیم جامعه باقی مانده است.</div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</details>

<!-- ثبت موضوع و طرح ایده؛ فرم به‌صورت پیش‌فرض جمع‌شده است -->
<details class="topic-form-toggle">
  <summary>✍️ ثبت موضوع و طرح ایده</summary>
  <div class="card-form topic-form">
    <form action="add_topic.php" method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($community_duty_csrf, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="community_id" value="<?= (int)$community['id'] ?>">
      <label for="topic-expertise-domain" style="display:block;margin-top:8px;font-size:13px;">حوزهٔ تخصصی مرتبط (پیشنهادی)</label>
      <select id="topic-expertise-domain" name="expertise_domain_id">
        <option value="0">بدون تعیین حوزهٔ تخصصی</option>
        <?php
        try {
            $expertise_domains_result = $conn->query("SELECT id, name FROM expertise_domains WHERE status='approved' ORDER BY name ASC");
            if ($expertise_domains_result) {
                while ($expertise_domain = $expertise_domains_result->fetch_assoc()) {
                    echo '<option value="' . (int)$expertise_domain['id'] . '">' .
                        htmlspecialchars($expertise_domain['name'], ENT_QUOTES, 'UTF-8') .
                        '</option>';
                }
                $expertise_domains_result->free();
            }
        } catch (Throwable $e) {
            error_log("Expertise domains list unavailable: " . $e->getMessage());
        }
        ?>
      </select>
      <div class="topic-field-hint"><span>این انتخاب فقط پیشنهاد است؛ تا زمانی که جامعه آن را تأیید نکند، هیچ وزن تخصصی به رأی‌ها اضافه نمی‌شود.</span></div>
      <textarea id="topic-title-input" name="topic_title" rows="2" maxlength="100" placeholder="عنوان موضوع*" aria-label="عنوان موضوع" required></textarea>
      <div class="topic-field-hint"><span>عنوانی کوتاه و روشن برای مسئله یا چالش بنویسید.</span><span class="topic-char-count" data-for="topic-title-input">۰ از ۱۰۰ نویسه</span></div>
      <textarea id="topic-description" name="topic_description" rows="3" maxlength="500" placeholder="شرح موضوع (اختیاری)" aria-label="شرح موضوع (اختیاری)"></textarea>
      <div class="topic-field-hint"><span>زمینه و جزئیات لازم را برای فهم بهتر موضوع توضیح دهید.</span><span class="topic-char-count" data-for="topic-description">۰ از ۵۰۰ نویسه</span></div>
      <textarea id="topic-initial-idea" name="topic_initial_idea" rows="4" maxlength="25000" placeholder="طرح ایده (اختیاری)" aria-label="طرح ایده (اختیاری)"></textarea>
      <div class="topic-field-hint"><span>راه‌حل یا پیشنهاد خود را بنویسید؛ می‌توانید این بخش را خالی بگذارید.</span><span class="topic-char-count" data-for="topic-initial-idea">۰ از ۲۵٬۰۰۰ نویسه</span></div>
      <button type="submit">✅ ثبت موضوع و طرح ایده</button>
    </form>
  </div>
</details>

<!-- موضوعات در حال بحث و ایده‌ها -->
<?php
$topics_count_q = $conn->prepare("SELECT COUNT(*) AS cnt FROM topics WHERE community_id=? AND closed=0");
$topics_count_q->bind_param("i", $community['id']);
$topics_count_q->execute();
$topics_count_res = $topics_count_q->get_result()->fetch_assoc();
$topics_count_q->close();
$topics_count = (int)$topics_count_res['cnt'];
?>
<div class="box" style="background:#fff3a6; padding:20px;">
<?php if ($topics_count > 5): ?>
<details class="community-topics-toggle"<?= (isset($_GET['open_topic']) && (int)$_GET['open_topic'] > 0) ? ' open' : '' ?>>
  <summary>📘 نمایش موضوعات در حال بحث (<?= $topics_count ?>)</summary>
  <div id="topics-wrapper" style="max-height:540px; overflow-y:auto; padding:10px;">
<?php else: ?>
<h3>📘 موضوعات در حال بحث (<?= $topics_count ?>)</h3>
<div id="topics-wrapper" style="max-height:540px; overflow-y:auto; padding:10px;">
<?php endif; ?>
<?php
$topics_list = $conn->prepare("
    SELECT t.*, 
           COUNT(tp.user_id) AS total_priority 
    FROM topics t 
    LEFT JOIN topic_priority_votes tp ON t.id=tp.topic_id 
    WHERE t.community_id=? AND t.closed=0 
    GROUP BY t.id 
    ORDER BY total_priority DESC, t.id ASC
");
$topics_list->bind_param("i", $community['id']);
$topics_list->execute();
$topics_res2 = $topics_list->get_result();

if($topics_res2->num_rows == 0){
    echo "<p style='padding:10px;color:#666'>موضوعی برای بحث وجود ندارد.</p>";
}

$topic_discussions_ready = false;
$discussion_table_check = $conn->query("SHOW TABLES LIKE 'community_topic_discussions'");
if ($discussion_table_check instanceof mysqli_result) {
    $topic_discussions_ready = $discussion_table_check->num_rows > 0;
    $discussion_table_check->free();
}
$idea_revisions_ready = false;
$idea_revisions_table_check = $conn->query("SHOW TABLES LIKE 'community_idea_revisions'");
if ($idea_revisions_table_check instanceof mysqli_result) {
    $idea_revisions_ready = $idea_revisions_table_check->num_rows > 0;
    $idea_revisions_table_check->free();
}
$idea_feedback_ready = false;
$idea_feedback_table_check = $conn->query("SHOW TABLES LIKE 'community_idea_feedback'");
if ($idea_feedback_table_check instanceof mysqli_result) {
    $idea_feedback_ready = $idea_feedback_table_check->num_rows > 0;
    $idea_feedback_table_check->free();
}

$topic_sections = [
    'important' => ['title' => '⭐ موضوعات مهم', 'items' => []],
    'new'       => ['title' => '🆕 موضوعات تازه', 'items' => []],
    'other'     => ['title' => '📚 سایر موضوعات', 'items' => []],
];
$new_topic_cutoff = time() - (3 * 24 * 60 * 60);
while ($topic_row = $topics_res2->fetch_assoc()) {
    $topic_priority_count = (int)$topic_row['total_priority'];
    $topic_created_time = strtotime((string)$topic_row['created_at']) ?: 0;
    if ($topic_priority_count > 0) {
        $topic_sections['important']['items'][] = $topic_row;
    } elseif ($topic_created_time >= $new_topic_cutoff) {
        $topic_sections['new']['items'][] = $topic_row;
    } else {
        $topic_sections['other']['items'][] = $topic_row;
    }
}
// موضوعات مهم بر اساس اولویت؛ تازه‌ها بر اساس زمان ثبت (جدیدترین ابتدا)؛ بقیه بر اساس اولویت.
usort($topic_sections['new']['items'], static function ($a, $b) {
    return (strtotime((string)$b['created_at']) ?: 0) <=> (strtotime((string)$a['created_at']) ?: 0);
});

foreach ($topic_sections as $topic_section):
    if (empty($topic_section['items'])) continue;
?>
    <section class="community-topic-section">
      <h4 class="community-topic-section-title"><?= htmlspecialchars($topic_section['title'], ENT_QUOTES, 'UTF-8') ?> (<?= count($topic_section['items']) ?>)</h4>
      <?php foreach ($topic_section['items'] as $t2): ?>
<?php
  $tid = $t2['id'];
  $created = strtotime($t2['created_at']);
  $urgency = intval($t2['urgency']); 
 $deadline = $created + ($urgency * 24 * 60 * 60); // روز تبدیل به ثانیه
$remaining_seconds = $deadline - time();
if ($remaining_seconds < 0) $remaining_seconds = 0;
  $is_closed = (int)$t2['closed'];

  $priority_check = $conn->prepare("SELECT id FROM topic_priority_votes WHERE topic_id=? AND user_id=?");
  $priority_check->bind_param("ii",$tid,$user_id);
  $priority_check->execute();
  $priority_res = $priority_check->get_result();
  $user_has_priority = ($priority_res->num_rows>0);
  $priority_check->close();
?>
<details class="topic community-topic-item" id="topic-<?= (int)$tid ?>"<?= (isset($_GET['open_topic']) && (int)$_GET['open_topic'] === (int)$tid) ? ' open' : '' ?> style="<?= $is_closed ? 'opacity:0.6;' : '' ?>">
  <summary><span class="community-topic-title"><?= htmlspecialchars($t2['title'], ENT_QUOTES, 'UTF-8') ?></span></summary>
  <div class="community-topic-body">
  <?php if (trim((string)($t2['description'] ?? '')) !== ''): ?>
    <div style="margin:0 0 10px;padding:8px 10px;background:rgba(255,255,255,.75);border-radius:7px;line-height:1.8;font-size:13px;"><?= nl2br(htmlspecialchars($t2['description'], ENT_QUOTES, 'UTF-8')) ?></div>
  <?php endif; ?>
  <div class="topic-header">
    <div class="topic-meta" style="font-size:13px;color:#333;margin-top:4px;">
        ⏱ ثبت شده در: <?= htmlspecialchars(community_format_jalali_datetime($t2['created_at']), ENT_QUOTES, 'UTF-8') ?>
    </div>
    <div class="countdown" data-remaining="<?= $remaining_seconds ?>" style="font-size:13px;color:#555;margin-top:6px;">در حال محاسبه...</div>
    <div class="topic-summary" style="text-align:left;font-size:13px;">
      <div>✅ مجموع اولویت: <?= (int)$t2['total_priority'] ?></div>
      <div>⏱ فوریت: <?= $urgency ?>روز</div>
    </div>
    <div class="topic-actions">
      <form action="toggle_priority.php" method="POST" style="display:inline;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($community_duty_csrf, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="topic_id" value="<?= $tid ?>">
          <input type="hidden" name="community_id" value="<?= $community['id'] ?>">
          <span class="action-tooltip">
            <button type="submit" title="اولویت موضوع را ثبت یا لغو می‌کند؛ مجموع اولویت اعضا در مرتب‌سازی موضوعات اثر دارد." style="background:<?= $user_has_priority ? '#f1c40f' : '#ccc' ?>;color:#000;">
                ⭐ اولویت
            </button>
            <span class="action-tooltip-text" role="tooltip">با ثبت اولویت، اهمیت این موضوع را برای جامعه نشان می‌دهید. موضوع‌ها بر اساس مجموع اولویت اعضا مرتب می‌شوند؛ با کلیک دوباره می‌توانید اولویت خود را لغو کنید.</span>
          </span>
      </form>
      <details class="community-urgency-controls">
        <summary>⏱ تنظیم فوریت</summary>
        <form action="update_urgency.php" method="post">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($community_duty_csrf, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="topic_id" value="<?= $tid ?>">
          <input type="hidden" name="community_id" value="<?= $community['id'] ?>">
          <label class="community-urgency-label">مدت فوریت (روز)</label>
          <input class="urgency-input" type="number" name="urgency_days" min="1" max="360" value="<?= $urgency ?>" required>
          <button class="urgency-btn" type="submit" title="فوریت را بر حسب روز ثبت می‌کند؛ میانگین پیشنهادهای اعضا زمان موضوع را تعیین می‌کند.">ثبت فوریت</button>
          <small class="community-urgency-help">زمان فوریت موضوع بر اساس میانگین پیشنهادهای ثبت‌شدهٔ اعضا محاسبه می‌شود.</small>
          <small class="community-urgency-status" role="status" aria-live="polite"></small>
        </form>
      </details>
    </div>
  </div>
  <div class="ideas-box">
    <?php
    $ideas_q2 = $conn->prepare("SELECT ideas.id, ideas.content, ideas.idea_rationality, ideas.created_at, users.username, users.id AS uid FROM ideas JOIN users ON ideas.user_id = users.id WHERE topic_id=? ORDER BY ideas.idea_rationality DESC, ideas.id ASC");
    $ideas_q2->bind_param("i",$tid);
    $ideas_q2->execute();
    $ideas_res2 = $ideas_q2->get_result();
    if($ideas_res2->num_rows == 0){
        echo "<p style='padding:6px;color:#777'>💡 هنوز ایده‌ای ثبت نشده است.</p>";
    }
    while($i=$ideas_res2->fetch_assoc()):
    ?>
    <div class="idea">
        <div class="text">
            <strong class="user-tooltip" data-user-id="<?= (int)$i['uid'] ?>">👤 <?= htmlspecialchars($i['username']) ?></strong>
            <div class="idea-meta" style="font-size:12px;color:#555;margin-bottom:4px;">
                ⏱ ثبت شده در: <?= htmlspecialchars(community_format_jalali_datetime($i['created_at']), ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="community-idea-content" id="community-idea-content-<?= (int)$i['id'] ?>"><?= nl2br(htmlspecialchars($i['content'], ENT_QUOTES, 'UTF-8')) ?></div>
            <button type="button" class="community-idea-read-more" aria-expanded="false" aria-controls="community-idea-content-<?= (int)$i['id'] ?>" onclick="toggleCommunityIdeaContent(<?= (int)$i['id'] ?>)">مشاهده ادامه</button>
            <?php if ($idea_revisions_ready): ?>
              <details class="community-idea-edit" id="community-idea-edit-<?= (int)$i['id'] ?>">
                <summary>📜 نسخه‌های قبلی و اصلاح ایده</summary>
                <?php if (!$is_closed && (int)$i['uid'] === $user_id): ?>
                  <form action="edit_idea.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($community_duty_csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="idea_id" value="<?= (int)$i['id'] ?>">
                    <input type="hidden" name="community_id" value="<?= (int)$community['id'] ?>">
                    <label>متن اصلاح‌شده (فقط صاحب ایده می‌تواند آن را تغییر دهد)</label>
                    <textarea name="idea_text" maxlength="25000" required><?= htmlspecialchars($i['content'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    <label>دلیل اصلاح (اختیاری)</label>
                    <input type="text" name="revision_note" maxlength="1000" placeholder="مثلاً: با توجه به پیشنهادهای مباحثه">
                    <button type="submit">ذخیره نسخهٔ اصلاح‌شده</button>
                  </form>
                <?php endif; ?>
                <?php
                $revisions_q = $conn->prepare("SELECT r.previous_content, r.revision_note, r.created_at, u.username FROM community_idea_revisions r JOIN users u ON u.id=r.editor_user_id WHERE r.idea_id=? ORDER BY r.id DESC");
                $revisions_q->bind_param("i", $i['id']);
                $revisions_q->execute();
                $revisions_res = $revisions_q->get_result();
                if ($revisions_res->num_rows > 0):
                ?>
                  <div class="community-idea-history">
                    <strong>📜 سابقهٔ نسخه‌ها (برای همهٔ اعضا قابل مشاهده)</strong>
                    <?php while ($revision = $revisions_res->fetch_assoc()): ?>
                      <details>
                        <summary><?= htmlspecialchars(community_format_jalali_datetime($revision['created_at']), ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($revision['username'], ENT_QUOTES, 'UTF-8') ?></summary>
                        <?php if (!empty($revision['revision_note'])): ?><p><?= htmlspecialchars($revision['revision_note'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                        <div><?= nl2br(htmlspecialchars($revision['previous_content'], ENT_QUOTES, 'UTF-8')) ?></div>
                      </details>
                    <?php endwhile; ?>
                  </div>
                <?php else: ?>
                  <p style="font-size:12px;color:#687789;">هنوز نسخهٔ قبلی ثبت نشده است.</p>
                <?php endif; $revisions_q->close(); ?>
              </details>
            <?php endif; ?>
            <?php if ($idea_feedback_ready): ?>
              <details class="community-idea-feedback" id="community-idea-feedback-<?= (int)$i['id'] ?>">
                <?php
                $feedback_q = $conn->prepare("SELECT f.id, f.content, f.created_at, f.review_status, u.username FROM community_idea_feedback f JOIN users u ON u.id=f.user_id WHERE f.idea_id=? ORDER BY f.id ASC");
                $feedback_q->bind_param("i", $i['id']);
                $feedback_q->execute();
                $feedback_res = $feedback_q->get_result();
                $feedback_count = $feedback_res->num_rows;
                ?>
                <summary>💬 پیشنهادها و نظرها دربارهٔ این ایده (<?= (int)$feedback_count ?>)</summary>
                <?php if (!$is_closed && (int)$i['uid'] === $user_id): ?>
                  <button type="button" class="community-idea-review-feedback" onclick="document.getElementById('community-idea-edit-<?= (int)$i['id'] ?>').open=true; document.getElementById('community-idea-edit-<?= (int)$i['id'] ?>').scrollIntoView({behavior:'smooth',block:'center'});">📝 بررسی پیشنهادها و اصلاح ایده</button>
                <?php endif; ?>
                <?php if ($feedback_count === 0): ?>
                  <p style="margin:7px 0;color:#61758a;">هنوز پیشنهادی برای این ایده ثبت نشده است.</p>
                <?php endif; ?>
                <?php while ($feedback = $feedback_res->fetch_assoc()): ?>
                  <?php
                    $feedback_status = (string)($feedback['review_status'] ?? 'pending');
                    $feedback_status_labels = ['pending' => 'در انتظار بررسی', 'accepted' => 'پذیرفته‌شده', 'rejected' => 'پذیرفته‌نشده'];
                    if (!isset($feedback_status_labels[$feedback_status])) {
                        $feedback_status = 'pending';
                    }
                  ?>
                  <div class="community-idea-feedback-entry" id="community-idea-feedback-item-<?= (int)$feedback['id'] ?>">
                    <div class="community-idea-feedback-meta">👤 <?= htmlspecialchars($feedback['username'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(community_format_jalali_datetime($feedback['created_at']), ENT_QUOTES, 'UTF-8') ?></div>
                    <div><?= nl2br(htmlspecialchars($feedback['content'], ENT_QUOTES, 'UTF-8')) ?></div>
                    <div style="margin-top:7px;font-size:12px;color:#176b56;"><strong>وضعیت:</strong> <?= htmlspecialchars($feedback_status_labels[$feedback_status], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if (!$is_closed && (int)$i['uid'] === $user_id): ?>
                      <form action="update_idea_feedback_status.php" method="post" style="margin-top:7px;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($community_duty_csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="feedback_id" value="<?= (int)$feedback['id'] ?>">
                        <input type="hidden" name="idea_id" value="<?= (int)$i['id'] ?>">
                        <input type="hidden" name="topic_id" value="<?= (int)$tid ?>">
                        <input type="hidden" name="community_id" value="<?= (int)$community['id'] ?>">
                        <label style="display:block;font-size:12px;margin-top:5px;">تغییر وضعیت پیشنهاد</label>
                        <select name="review_status" aria-label="وضعیت بررسی پیشنهاد">
                          <option value="pending" <?= $feedback_status === 'pending' ? 'selected' : '' ?>>در انتظار بررسی</option>
                          <option value="accepted" <?= $feedback_status === 'accepted' ? 'selected' : '' ?>>پذیرفته‌شده</option>
                          <option value="rejected" <?= $feedback_status === 'rejected' ? 'selected' : '' ?>>پذیرفته‌نشده</option>
                        </select>
                        <button type="submit">ثبت وضعیت</button>
                      </form>
                    <?php endif; ?>
                  </div>
                <?php endwhile; $feedback_q->close(); ?>
                <?php if (!$is_closed): ?>
                  <form action="add_idea_feedback.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($community_duty_csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="idea_id" value="<?= (int)$i['id'] ?>">
                    <input type="hidden" name="topic_id" value="<?= (int)$tid ?>">
                    <input type="hidden" name="community_id" value="<?= (int)$community['id'] ?>">
                    <textarea name="feedback_text" maxlength="5000" placeholder="پیشنهاد اصلاحی یا نظر خود را دربارهٔ این ایده بنویسید (حداکثر ۵۰۰۰ نویسه)" required></textarea>
                    <button type="submit">💬 ثبت پیشنهاد یا نظر</button>
                  </form>
                <?php else: ?>
                  <p style="margin:7px 0 0;color:#687789;font-size:12px;">موضوع بسته شده است؛ امکان ثبت نظر تازه وجود ندارد.</p>
                <?php endif; ?>
              </details>
            <?php endif; ?>
            <div class="tooltip-box" id="tooltip-<?= (int)$i['uid'] ?>"><p>در حال بارگذاری...</p></div>
        </div>
        <div class="idea-actions" style="display:flex; flex-direction:column; gap:6px; min-width:110px; text-align:right;">
            <span class="idea-action-tooltip">
                <a href="give_score.php?user_id=<?= (int)$i['uid'] ?>&redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>" 
                   style="display:inline-block; font-size:13px; padding:4px 8px; background:#ff9800; color:#fff; border-radius:5px; text-decoration:none;">
                    ⭐  محبوبیت کاربر
                </a>
                <span class="idea-action-tooltip-text" role="tooltip">با این گزینه به صاحب ایده امتیاز محبوبیت می‌دهید. این امتیاز برای ارزیابی خودِ کاربر است، نه تعیین معقول‌ترین راه‌حل موضوع.</span>
            </span>
            <?php if(!$is_closed): ?>
            <form action="update_idea.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($community_duty_csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="idea_id" value="<?= (int)$i['id'] ?>">
                <span class="idea-action-tooltip">
                    <button type="submit" style="background:#27ae60; color:#fff; padding:6px 8px; font-size:13px; border-radius:6px; border:none;">
                        ✅  معقول‌ترین ایده  <strong><?= (int)$i['idea_rationality'] ?></strong>
                    </button>
                    <span class="idea-action-tooltip-text" role="tooltip">اگر این ایده را منطقی‌ترین راه‌حل این موضوع می‌دانید، آن را انتخاب کنید. هر کاربر برای هر موضوع یک رأی معقولیت دارد؛ با انتخاب ایده‌ای دیگر، رأی قبلی او جابه‌جا می‌شود.</span>
                </span>
            </form>
            <?php else: ?>
            <span class="idea-action-tooltip">
                <span style="background:#27ae60; color:#fff; padding:6px 8px; border-radius:6px;">
                    ✅ معقول‌ترین <strong><?= (int)$i['idea_rationality'] ?></strong>
                </span>
                <span class="idea-action-tooltip-text" role="tooltip">این ایده در زمان بسته‌شدن موضوع، معقول‌ترین ایده بر اساس امتیاز ثبت‌شده بوده است.</span>
            </span>
            <?php endif; ?>
        </div>
    </div>
    <?php endwhile; $ideas_q2->close(); ?>

    <?php if(!$is_closed): ?>
    <div class="add-idea" style="margin-top:8px">
  <form action="add_idea.php" method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($community_duty_csrf, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="community_id" value="<?= $community['id'] ?>">
    <input type="hidden" name="topic_id" value="<?= $tid ?>">
    <textarea name="idea_text" maxlength="25000" placeholder="✍️ ثبت ایده جدید... (حداکثر ۲۵٬۰۰۰ نویسه)" required></textarea>
    <small style="display:block;color:#666;margin-top:4px;">حداکثر ۲۵٬۰۰۰ نویسه.</small>
    <button type="submit">➕ ثبت ایده</button>
  </form>
</div>
    <?php endif; ?>

  </div> <!-- ideas-box -->
  <div class="topic-discussion">
    <h5>💬 مباحثه دربارهٔ موضوع</h5>
    <?php
    if ($topic_discussions_ready):
        $discussion_q = $conn->prepare("SELECT d.content, d.created_at, u.username FROM community_topic_discussions d JOIN users u ON u.id=d.user_id WHERE d.topic_id=? ORDER BY d.created_at ASC, d.id ASC");
        $discussion_q->bind_param("i", $tid);
        $discussion_q->execute();
        $discussion_res = $discussion_q->get_result();
        if ($discussion_res->num_rows === 0):
    ?>
      <p style="margin:0;color:#61758a;font-size:13px;">هنوز مباحثه‌ای ثبت نشده است. با احترام به دیدگاه دیگران، برای پخته‌تر شدن راه‌حل‌ها گفت‌وگو را آغاز کنید.</p>
    <?php
        endif;
        while ($discussion = $discussion_res->fetch_assoc()):
    ?>
      <div class="topic-discussion-entry">
        <div class="topic-discussion-entry-meta">👤 <?= htmlspecialchars($discussion['username'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(community_format_jalali_datetime($discussion['created_at']), ENT_QUOTES, 'UTF-8') ?></div>
        <div><?= nl2br(htmlspecialchars($discussion['content'], ENT_QUOTES, 'UTF-8')) ?></div>
      </div>
    <?php
        endwhile;
        $discussion_q->close();
    else:
    ?>
      <p style="margin:0;color:#8a5b00;font-size:13px;">بخش مباحثه پس از آماده‌شدن جدول پایگاه داده فعال می‌شود.</p>
    <?php endif; ?>
    <?php if (!$is_closed && $topic_discussions_ready): ?>
      <form action="add_topic_discussion.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($community_duty_csrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="topic_id" value="<?= (int)$tid ?>">
        <input type="hidden" name="community_id" value="<?= (int)$community['id'] ?>">
        <textarea name="discussion_text" maxlength="5000" placeholder="دیدگاه، پرسش یا راه‌حل خود را برای مباحثه بنویسید (حداکثر ۵۰۰۰ نویسه)" required></textarea>
        <button type="submit">💬 ثبت در مباحثه</button>
      </form>
    <?php elseif ($is_closed): ?>
      <p style="margin:8px 0 0;color:#687789;font-size:12px;">این موضوع بسته شده است؛ امکان افزودن پیام تازه وجود ندارد.</p>
    <?php endif; ?>
  </div>
  </div> <!-- community-topic-body -->
</details> <!-- topic -->
<?php endforeach; ?>
    </section>
<?php endforeach; $topics_list->close(); ?>
</div> <!-- topics-wrapper -->
<?php if ($topics_count > 5): ?>
</details>
<?php endif; ?>
</div> <!-- box موضوعات در حال بحث -->

<!-- موضوعات در حال رأی‌گیری؛ این بخش پس از سربرگ قرار می‌گیرد -->
<?php
$vote_count_q = $conn->prepare("
    SELECT COUNT(*) AS cnt
    FROM topics
    WHERE community_id = ?
      AND closed = 1
      AND id IN (SELECT topic_id FROM topic_final_votes)
");
$vote_count_q->bind_param("i", $community['id']);
$vote_count_q->execute();
$vote_count_res = $vote_count_q->get_result()->fetch_assoc();
$vote_count_q->close();
$vote_count = (int)$vote_count_res['cnt'];
?>
<a class="final-voting-toggle" id="final-voting-toggle" href="votes.php?community_id=<?= (int)$community['id'] ?>">🗳 موضوعات در حال رأی‌گیری (<?= $vote_count ?>)</a>

<!-- کارت آرشیو موضوعات -->
<?php
$arch_stats_q = $conn->prepare("
    SELECT t.id,
           SUM(CASE WHEN IFNULL(vb.total_votes,0)>0 AND (vb.yes_votes/vb.total_votes)*100 > 50 THEN 1 ELSE 0 END) AS winners,
           SUM(CASE WHEN IFNULL(vb.total_votes,0)=0 OR (vb.yes_votes/vb.total_votes)*100 <= 50 THEN 1 ELSE 0 END) AS losers,
           COUNT(*) AS total
    FROM topics t
    LEFT JOIN (
        SELECT topic_id,
               SUM(CASE WHEN vote_type='yes' THEN 1 ELSE 0 END) AS yes_votes,
               COUNT(*) AS total_votes
        FROM topic_voting_ballots
        GROUP BY topic_id
    ) vb ON vb.topic_id = t.id
    WHERE t.community_id=? AND t.closed=2
");
$arch_stats_q->bind_param("i", $community_id);
$arch_stats_q->execute();
$arch_stats = $arch_stats_q->get_result()->fetch_assoc();
$arch_stats_q->close();

$total_archived = (int)$arch_stats['total'];
$winners_count = (int)$arch_stats['winners'];
$losers_count  = (int)$arch_stats['losers'];
?>
<a class="archive-topics-toggle" id="archive-topics-toggle" href="archived_topics.php?community_id=<?= (int)$community_id ?>">📦 آرشیو موضوعات (<?= $total_archived ?>)</a>

<?php else: ?>
<div class="box">
  <p style="color:red">هیچ جامعه‌ای انتخاب نشده است.</p>
</div>
<?php endif; ?>

</div> <!-- container -->

<script>
document.addEventListener("DOMContentLoaded", function() {
    // شمارش زندهٔ نویسه‌ها در فرم ثبت موضوع و ایده
    const faDigits = function(value) {
        return String(value).replace(/[0-9]/g, function(d) { return '۰۱۲۳۴۵۶۷۸۹'[Number(d)]; });
    };
    document.querySelectorAll('.topic-char-count[data-for]').forEach(function(counter) {
        const field = document.getElementById(counter.dataset.for);
        if (!field) return;
        const max = Number(field.getAttribute('maxlength')) || 0;
        const updateCount = function() {
            counter.textContent = faDigits(field.value.length) + ' از ' + faDigits(max) + ' نویسه';
        };
        field.addEventListener('input', updateCount);
        updateCount();
    });

    // شرح وظایف در موبایل پس از ۲۰۰ نویسه کوتاه می‌شود و با لمس باز می‌شود.
    const dutyDescription = document.querySelector('.community-duty-description');
    if (dutyDescription) {
        const originalHtml = dutyDescription.innerHTML;
        const originalText = dutyDescription.textContent.trim();
        const limit = 200;
        let expanded = false;
        function renderDutyDescription() {
            if (window.matchMedia('(max-width: 720px)').matches && Array.from(originalText).length > limit) {
                dutyDescription.style.cursor = 'pointer';
                dutyDescription.setAttribute('role', 'button');
                dutyDescription.setAttribute('tabindex', '0');
                dutyDescription.setAttribute('aria-expanded', String(expanded));
                if (expanded) {
                    dutyDescription.innerHTML = originalHtml + ' <span style="color:#2065d1;font-size:12px;">(نمایش کمتر)</span>';
                } else {
                    dutyDescription.textContent = Array.from(originalText).slice(0, limit).join('') + '…';
                }
            } else {
                dutyDescription.innerHTML = originalHtml;
                dutyDescription.style.cursor = '';
                dutyDescription.removeAttribute('role');
                dutyDescription.removeAttribute('tabindex');
                dutyDescription.removeAttribute('aria-expanded');
                expanded = false;
            }
        }
        function toggleDutyDescription() {
            if (!window.matchMedia('(max-width: 720px)').matches || Array.from(originalText).length <= limit) return;
            expanded = !expanded;
            renderDutyDescription();
        }
        dutyDescription.addEventListener('click', toggleDutyDescription);
        dutyDescription.addEventListener('keydown', function(event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                toggleDutyDescription();
            }
        });
        renderDutyDescription();
        window.addEventListener('resize', renderDutyDescription);
    }

    // ثبت اولویت و فوریت بدون بارگذاری مجدد صفحه
    // استفاده از رویداددهی واگذار‌شده تا فرم‌های ایده‌ای که بعداً به‌روزرسانی می‌شوند نیز بدون رفرش کار کنند.
    document.addEventListener("submit", async function(event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('form[action="toggle_priority.php"], form[action="update_urgency.php"], form[action="update_idea.php"]')) return;
        event.preventDefault();
            const submitButton = form.querySelector('button[type="submit"]') || form.querySelector('button');
            if (form.dataset.sending === "1") return;
            form.dataset.sending = "1";
            if (submitButton) submitButton.disabled = true;

            try {
                const response = await fetch(form.action, {
                    method: "POST",
                    body: new FormData(form),
                    credentials: "same-origin",
                    headers: { "X-Requested-With": "XMLHttpRequest" }
                });
                if (!response.ok) throw new Error("ثبت انجام نشد");
                const topicId = form.querySelector('input[name="topic_id"]')?.value || (form.closest('.topic')?.id || '').replace(/^topic-/, '');
                const currentTopic = topicId ? document.getElementById("topic-" + topicId) : null;
                if (!currentTopic) throw new Error("موضوع در صفحه پیدا نشد");

                // اولویت فقط پاسخ کوتاه JSON می‌گیرد؛ نیازی به دریافت و پردازش کل صفحه نیست.
                if (form.action.includes("toggle_priority.php")) {
                    const result = await response.json();
                    if (!result.success) throw new Error("ثبت اولویت انجام نشد");

                    const currentSummary = currentTopic.querySelector(".topic-summary");
                    const priorityLine = currentSummary ? currentSummary.querySelector("div") : null;
                    if (priorityLine) {
                        priorityLine.textContent = "✅ مجموع اولویت: " + (Number(result.total_votes) || 0);
                    }

                    if (submitButton) {
                        submitButton.style.backgroundColor = result.has_priority ? "#f1c40f" : "#ccc";
                    }
                    return;
                }

                // فوریت نیز پاسخ کوتاه JSON می‌گیرد و بدون پردازش کل صفحه به‌روز می‌شود.
                if (form.action.includes("update_urgency.php")) {
                    const result = await response.json();
                    if (!result.success) throw new Error("ثبت فوریت انجام نشد");

                    const urgency = Math.max(1, Math.min(360, Number(result.urgency_units) || 1));
                    // انتخاب شخصی عضو را نگه دار؛ میانگین جامعه جداگانه نمایش داده می‌شود.
                    const chosenInput = form.querySelector(".urgency-input");
                    const chosenDays = chosenInput ? chosenInput.value : "";
                    const currentSummary = currentTopic.querySelector(".topic-summary");
                    const summaryLines = currentSummary ? currentSummary.querySelectorAll("div") : [];
                    if (summaryLines.length > 1) {
                        summaryLines[1].textContent = "⏱ فوریت: " + urgency + " روز (میانگین اعضا)";
                    }

                    const status = form.querySelector(".community-urgency-status");
                    const voteCount = Number(result.vote_count) || 0;
                    if (status) {
                        status.textContent = "✓ انتخاب شما: " + chosenDays + " روز ثبت شد؛ میانگین جامعه: " + urgency + " روز (" + voteCount + " رأی فوریت).";
                    }

                    const currentCountdown = currentTopic.querySelector(".countdown");
                    if (currentCountdown) {
                        currentCountdown.dataset.remaining = String(Math.max(0, Number(result.remaining_seconds) || 0));
                        startTopicCountdown(currentCountdown, Math.max(0, Number(result.remaining_seconds) || 0));
                    }
                    return;
                }

                const html = await response.text();
                const parsed = new DOMParser().parseFromString(html, "text/html");
                const freshTopic = parsed.getElementById("topic-" + topicId);
                if (!freshTopic) throw new Error("پاسخ صفحه قابل شناسایی نیست");

                // برای رأی معقولیت، فقط بخش ایده‌های همان موضوع را به‌روز کن تا صفحه بالا نپرد.
                if (form.action.includes("update_idea.php")) {
                    const freshIdeasBox = freshTopic.querySelector(".ideas-box");
                    const currentIdeasBox = currentTopic.querySelector(".ideas-box");
                    if (!freshIdeasBox || !currentIdeasBox) throw new Error("بخش ایده‌ها در پاسخ پیدا نشد");
                    currentIdeasBox.innerHTML = freshIdeasBox.innerHTML;
                    setupCommunityIdeaReadMore(currentIdeasBox);
                    return;
                }

                const freshSummary = freshTopic.querySelector(".topic-summary");
                const currentSummary = currentTopic.querySelector(".topic-summary");
                if (freshSummary && currentSummary) currentSummary.innerHTML = freshSummary.innerHTML;

                const freshPriorityButton = freshTopic.querySelector('form[action="toggle_priority.php"] button');
                const currentPriorityButton = currentTopic.querySelector('form[action="toggle_priority.php"] button');
                if (freshPriorityButton && currentPriorityButton) {
                    currentPriorityButton.style.backgroundColor = freshPriorityButton.style.backgroundColor;
                    currentPriorityButton.disabled = false;
                }

                const freshUrgencyInput = freshTopic.querySelector('.urgency-input');
                const currentUrgencyInput = currentTopic.querySelector('.urgency-input');
                if (freshUrgencyInput && currentUrgencyInput) currentUrgencyInput.value = freshUrgencyInput.value;

                const freshCountdown = freshTopic.querySelector('.countdown');
                const currentCountdown = currentTopic.querySelector('.countdown');
                if (freshCountdown && currentCountdown) {
                    currentCountdown.dataset.remaining = freshCountdown.dataset.remaining;
                    startTopicCountdown(currentCountdown, parseInt(freshCountdown.dataset.remaining, 10) || 0);
                }
            } catch (error) {
                // در صورت خطای ارتباط، روش معمول فرم را حفظ کن.
                form.dataset.sending = "0";
                HTMLFormElement.prototype.submit.call(form);
                return;
            } finally {
                form.dataset.sending = "0";
                if (submitButton) submitButton.disabled = false;
            }
    });

    // ===== جای‌گذاری توضیحات دکمه‌ها خارج از محدوده برش کادرها =====
    document.querySelectorAll('.action-tooltip, .idea-action-tooltip').forEach(function(wrapper) {
        const tip = wrapper.querySelector('.action-tooltip-text');
        const button = wrapper.querySelector('button, a, span');
        if (!tip || !button) return;
        const isIdeaTooltip = wrapper.classList.contains('idea-action-tooltip');
        if (isIdeaTooltip && window.innerWidth <= 720) return;
        // انتقال توضیح به body تا overflow کادر ایده‌ها نتواند آن را ببُرد.
        if (isIdeaTooltip && tip.parentElement !== document.body) {
            document.body.appendChild(tip);
        }

        function placeTip() {
            tip.style.display = 'block';
            const buttonRect = button.getBoundingClientRect();
            const tipRect = tip.getBoundingClientRect();
            const margin = 8;
            let left = Math.min(
                Math.max(margin, buttonRect.right - tipRect.width),
                window.innerWidth - tipRect.width - margin
            );
            let top = buttonRect.bottom + 7;
            if (top + tipRect.height > window.innerHeight - margin) {
                top = buttonRect.top - tipRect.height - 7;
            }
            top = Math.max(margin, Math.min(top, window.innerHeight - tipRect.height - margin));
            tip.style.left = left + 'px';
            tip.style.top = top + 'px';
        }

        wrapper.addEventListener('mouseenter', placeTip);
        wrapper.addEventListener('focusin', placeTip);
        wrapper.addEventListener('mouseleave', function() {
            tip.style.display = '';
        });
        wrapper.addEventListener('focusout', function() {
            tip.style.display = '';
        });
        window.addEventListener('resize', function() {
            if (wrapper.matches(':hover') || wrapper.contains(document.activeElement)) placeTip();
        });
        window.addEventListener('scroll', function() {
            if (wrapper.matches(':hover') || wrapper.contains(document.activeElement)) placeTip();
        }, true);
    });

    // ===== انتخاب موضوع با علامت و متن چندسطری =====
    const topicPicker = document.getElementById('topic-picker');
    if (topicPicker) {
        const pickerButton = document.getElementById('topic-picker-button');
        const pickerList = document.getElementById('topic-picker-list');
        const selectedId = document.getElementById('selected-topic-id');
        const selectedLabel = document.getElementById('selected-topic-label');
        const pickerError = document.getElementById('topic-picker-error');
        const ideaForm = topicPicker.closest('form');

        pickerButton.addEventListener('click', function() {
            const willOpen = pickerList.hidden;
            pickerList.hidden = !willOpen;
            pickerButton.setAttribute('aria-expanded', String(willOpen));
        });

        pickerList.querySelectorAll('.topic-picker-option').forEach(function(option) {
            option.addEventListener('click', function() {
                selectedId.value = option.dataset.topicId;
                selectedLabel.textContent = option.querySelector('.topic-picker-title').textContent;
                pickerList.querySelectorAll('.topic-picker-option').forEach(function(item) {
                    item.setAttribute('aria-selected', String(item === option));
                });
                pickerError.hidden = true;
                pickerList.hidden = true;
                pickerButton.setAttribute('aria-expanded', 'false');
                pickerButton.focus();
            });
        });

        document.addEventListener('click', function(event) {
            if (!topicPicker.contains(event.target)) {
                pickerList.hidden = true;
                pickerButton.setAttribute('aria-expanded', 'false');
            }
        });

        if (ideaForm) {
            ideaForm.addEventListener('submit', function(event) {
                if (!selectedId.value) {
                    event.preventDefault();
                    pickerError.hidden = false;
                    pickerList.hidden = false;
                    pickerButton.setAttribute('aria-expanded', 'true');
                    pickerButton.focus();
                }
            });
        }
    }

    // ===== شمارش معکوس: برای هر موضوع فقط یک تایمر فعال باشد =====
    
window.toggleCommunityIdeaContent = function(id){
    const content = document.getElementById('community-idea-content-' + id);
    if(!content) return;
    const button = content.parentElement.querySelector('.community-idea-read-more');
    const expanded = content.classList.toggle('is-expanded');
    if(expanded){
        content.style.display = 'block';
        content.style.webkitLineClamp = 'unset';
        content.style.overflow = 'visible';
        content.style.maxHeight = 'none';
        content.style.height = 'auto';
    } else {
        content.style.display = '-webkit-box';
        content.style.webkitLineClamp = '3';
        content.style.overflow = 'hidden';
        content.style.maxHeight = '';
        content.style.height = '';
    }
    if(button){
        button.textContent = expanded ? 'بستن ادامه' : 'مشاهده ادامه';
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }
}
function setupCommunityIdeaReadMore(root){
    (root || document).querySelectorAll('.community-idea-content').forEach(function(content){
        const button = content.parentElement.querySelector('.community-idea-read-more');
        if(!button) return;
        const wasExpanded = content.classList.contains('is-expanded');
        if(wasExpanded){
            content.style.display = 'block';
            content.style.webkitLineClamp = 'unset';
            content.style.overflow = 'visible';
            content.style.maxHeight = 'none';
            content.style.height = 'auto';
            button.textContent = 'بستن ادامه';
            button.setAttribute('aria-expanded','true');
            button.style.display = 'inline-block';
            return;
        }
        content.style.display = '-webkit-box';
        content.style.webkitLineClamp = '3';
        content.style.overflow = 'hidden';
        content.style.maxHeight = '';
        content.style.height = '';
        button.textContent = 'مشاهده ادامه';
        button.setAttribute('aria-expanded','false');
        button.style.display = content.scrollHeight > content.clientHeight + 2 ? 'inline-block' : 'none';
    });
}
if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', function(){ setupCommunityIdeaReadMore(); });
} else {
    setupCommunityIdeaReadMore();
}
window.addEventListener('resize', function(){ setupCommunityIdeaReadMore(); });

function startTopicCountdown(el, initialSeconds) {
        if (el._countdownTimer) clearTimeout(el._countdownTimer);
        const endTs = Date.now() + Math.max(0, initialSeconds) * 1000;

        function updateOnce() {
            const diff = Math.max(0, Math.ceil((endTs - Date.now()) / 1000));
            if (diff <= 0) {
                el.textContent = "⏰ زمان به پایان رسید";
                el.style.color = "#c0392b";
                el._countdownTimer = null;
                return;
            }
            const days = Math.floor(diff / 86400);
            const hours = Math.floor((diff % 86400) / 3600);
            const minutes = Math.floor((diff % 3600) / 60);
            const seconds = diff % 60;
            el.textContent = (days > 0 ? days + " روز " : "") + hours + " ساعت " + minutes + " دقیقه " + seconds + " ثانیه";
            el._countdownTimer = setTimeout(updateOnce, 1000);
        }
        updateOnce();
    }

    document.querySelectorAll(".countdown").forEach(function(el) {
        startTopicCountdown(el, parseInt(el.dataset.remaining, 10) || 0);
    });

    // ===== تولتیپ کاربران =====
    document.querySelectorAll('.user-tooltip').forEach(function(el){
        const userId = el.dataset.userId;
        const tooltip = document.getElementById('tooltip-' + userId);
        let hideTimeout = null;

        el.addEventListener('mouseenter', function(e){
            tooltip.style.display = 'block';
            tooltip.innerHTML = '<p>در حال بارگذاری...</p>';

            const rect = el.getBoundingClientRect();
            tooltip.style.top = (rect.bottom + window.scrollY + 6) + 'px';
            tooltip.style.left = (rect.left + window.scrollX) + 'px';

            fetch('get_user_info.php?user_id=' + encodeURIComponent(userId) + '&community_id=' + encodeURIComponent(<?= (int)$community_id ?>))
                .then(res => res.json())
                .then(data => {
                    if (data.error) {
                        tooltip.innerHTML = '<p>' + (data.error) + '</p>';
                    } else {
                      tooltip.innerHTML = `
    <p><strong>سن:</strong> ${data.age || '-'}</p>
    <p><strong>جنسیت:</strong> ${data.gender || '-'}</p>
    <p><strong>تحصیلات:</strong> ${data.education || '-'}</p>
    <p><strong>شغل:</strong> ${data.job || '-'}</p>
    <p><strong>میانگین امتیازات:</strong></p>
    <ul style="padding-left:15px; margin:2px 0;">
      <li>قانون‌مداری: ${data.avg_lawfulness ?? '-'}</li>
      <li>اخلاق: ${data.avg_ethics ?? '-'}</li>
      <li>صداقت: ${data.avg_honesty ?? '-'}</li>
      <li>مهارت اجرایی: ${data.avg_executive ?? '-'}</li>
    </ul>
    <p><strong>رتبه محبوبیت در این جامعه:</strong> ${data.community_rank == null ? 'هنوز امتیاز کافی برای رتبه‌بندی ثبت نشده' : 'رتبه ' + data.community_rank + ' از ' + data.community_member_count + ' عضو'}</p>
    <p><strong>تعداد اعضای جامعه:</strong> ${data.community_member_count ?? 0}</p>
`;
                    }
                }).catch(() => {
                    tooltip.innerHTML = '<p>خطا در بارگذاری اطلاعات</p>';
                });
        });

el.addEventListener('mouseleave', function(){
            hideTimeout = setTimeout(() => {
                tooltip.style.display = 'none';
            }, 200);
        });

        tooltip.addEventListener('mouseenter', function(){
            clearTimeout(hideTimeout);
        });

        tooltip.addEventListener('mouseleave', function(){
            tooltip.style.display = 'none';
        });
    });
});

// قرار دادن کلید رأی‌گیری نهایی بلافاصله زیر سربرگ جامعه.
(function(){
  const votingToggle = document.getElementById('final-voting-toggle');
  const archiveToggle = document.getElementById('archive-topics-toggle');
  const communityHeader = document.querySelector('.community-header');
  if (votingToggle && archiveToggle && communityHeader) {
    const shortcuts = document.createElement('div');
    shortcuts.className = 'community-topic-shortcuts';
    communityHeader.insertAdjacentElement('afterend', shortcuts);
    shortcuts.appendChild(votingToggle);
    shortcuts.appendChild(archiveToggle);
  }
})();

// در موبایل سه دسترسی اصلی زیر عنوان جامعه در یک ردیف قرار می‌گیرند؛
 // در دسکتاپ جای اصلی عناصر حفظ می‌شود.
(function(){
  const actions = document.getElementById('communityMobileActions');
  if (!actions) return;
  const header = actions.closest('.community-header');
  const actionsPlaceholder = document.createComment('community-mobile-actions-position');
  actions.parentNode.insertBefore(actionsPlaceholder, actions);
  const backLink = document.querySelector('.community-header a[href="profile.php"]');
  const backWrap = backLink ? backLink.parentElement : null;
  const leaveForm = document.querySelector('.community-header form[action="leave_community.php"]');
  const members = document.querySelector('.community-header .members-dropdown');
  const nodes = [backWrap, leaveForm, members].filter(Boolean);
  const placeholders = new Map();
  nodes.forEach(node => {
    const placeholder = document.createComment('community-action-position');
    node.parentNode.insertBefore(placeholder, node);
    placeholders.set(node, placeholder);
  });
  function arrangeActions(){
    if (window.matchMedia('(max-width: 720px)').matches) {
      nodes.forEach(node => actions.appendChild(node));
      // ردیف دکمه‌ها باید در پایین‌ترین بخش کادر سربرگ باشد.
      header.appendChild(actions);
    } else {
      if (actionsPlaceholder.parentNode) actionsPlaceholder.parentNode.insertBefore(actions, actionsPlaceholder);
      nodes.forEach(node => {
        const placeholder = placeholders.get(node);
        if (placeholder && placeholder.parentNode) placeholder.parentNode.insertBefore(node, placeholder);
      });
    }
  }
  arrangeActions();
  window.addEventListener('resize', arrangeActions);
})();
</script>
</body>
</html>