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
.container{max-width:960px;margin:0 auto;padding:0 6px;}
.box, .card-form, .card{background:#fff;border:1px solid #ccc;border-radius:10px;padding:14px;margin-bottom:16px;box-shadow:0 2px 5px rgba(0,0,0,0.05);}
/* پس‌زمینه رنگین‌کمانی ملایم برای سربرگ جامعه */
.community-mobile-actions{display:none;}
.community-header{
    background:linear-gradient(125deg,
        #ffe4eb 0%,
        #ffefd6 20%,
        #e7f8d9 40%,
        #dff4ff 60%,
        #e9e0ff 80%,
        #ffe2f3 100%);
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

.topic{background:#eef7ff;border:1px solid #9ac7ff;border-radius:10px;padding:12px;margin-bottom:14px;word-wrap:break-word;}
.topic-title-wrap{position:relative;flex:1 1 auto;min-width:0;}
.topic-header h2{margin:0;font-size:16px;word-break:break-word;}
.topic-description{display:none;position:absolute;top:calc(100% + 6px);right:0;width:360px;max-width:calc(100vw - 60px);margin:0;padding:10px 12px;background:#fff;color:#374151;font-size:14px;line-height:1.9;white-space:pre-wrap;overflow-wrap:anywhere;border:1px solid #9ac7ff;border-radius:8px;box-shadow:0 5px 16px rgba(0,0,0,.16);z-index:1500;font-weight:normal;}
.topic-title-wrap:hover .topic-description,.topic-title-wrap:focus-within .topic-description{display:block;}
.topic-header{display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;}
.topic-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}
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
    .community-mobile-actions{display:flex;flex-direction:row;flex-wrap:nowrap;align-items:center;gap:6px;width:100%;margin:8px 0 12px;}
    .community-mobile-actions>*{flex:1 1 0;min-width:0;max-width:33.333%;margin:0!important;text-align:center!important;}
    .community-mobile-actions a,.community-mobile-actions form button,.community-mobile-actions .members-button{display:flex;align-items:center;justify-content:center;width:100%;min-height:42px;box-sizing:border-box;padding:7px 3px!important;font-size:10px!important;line-height:1.35;white-space:normal;margin:0!important;}
    .community-mobile-actions form{display:flex;align-items:center;min-width:0;}
    .community-mobile-actions .members-dropdown{display:flex;align-items:stretch;min-width:0;}
    .community-duty-item{align-items:flex-start;flex-direction:column;}
    .community-duty-vote-form{align-self:flex-start;}
.idea-action-tooltip-text{display:none !important;}}
.urgency-input{width:60px;text-align:center;padding:6px;font-size:13px;}
.ideas-box{margin-top:10px;background:#fff;padding:10px;border-radius:8px;border:1px solid #ddd;max-height:200px;overflow-y:auto;}
.idea{background:#fff;border:1px solid #e0f0ff;border-radius:8px;padding:10px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:flex-start;gap:8px;}
.idea .text{flex:1 1 auto;max-width:100%;word-break:break-word;}
.idea-rationality button{background:#27ae60;color:#fff;padding:6px 8px;font-size:13px;border-radius:6px;white-space:nowrap;border:none;}

.user-tooltip{position:relative;cursor:pointer;color:#2065d1;}
.tooltip-box{position:absolute;top:26px;left:0;background:#fff;border:1px solid #ccc;border-radius:8px;padding:8px;min-width:180px;z-index:1000;box-shadow:0 2px 6px rgba(0,0,0,0.15);font-size:13px;display:none;}

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
  .topic { padding: 12px; margin-bottom: 12px; border-radius: 13px; background: #fff; border-color: #dbe8f5; box-shadow: 0 2px 9px rgba(24, 55, 90, .04); }
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
<body>
<div class="container">

<?php if ($community): ?>
<div class="box community-header">
  <h2><?=htmlspecialchars($community['name'])?></h2>
  <div class="community-mobile-actions" id="communityMobileActions" aria-label="دسترسی‌های جامعه"></div>
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
    <summary>پیشنهاد برای رویکردها و وظایف</summary>
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

<div style="text-align:center; margin:15px 0;">
  <a href="community_stats.php?community_id=<?= $community['id'] ?>">
    <button style="background:#0099cc;color:#fff;border:none;padding:10px 18px;border-radius:8px;font-size:14px;cursor:pointer;">
      📊 گزارش آماری جامعه
    </button>
  </a>
</div>

<!-- ثبت موضوع -->
<div class="card-form topic-form">
  <h3>ثبت موضوع جدید</h3>
  <form action="add_topic.php" method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($community_duty_csrf, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="community_id" value="<?= (int)$community['id'] ?>">
    <textarea name="topic_title" rows="2" maxlength="100" placeholder="عنوان موضوع*" aria-label="عنوان موضوع" required></textarea>
    <textarea id="topic-description" name="topic_description" rows="3" maxlength="500" placeholder="شرح موضوع (اختیاری)" aria-label="شرح موضوع (اختیاری)"></textarea>
    <small style="display:block;color:#666;margin-top:4px;">پر کردن این بخش اختیاری است.</small>
    <label for="topic-initial-idea">طرح ایده (اختیاری)</label>
    <textarea id="topic-initial-idea" name="topic_initial_idea" rows="4" maxlength="25000" placeholder="اگر ایده‌ای برای حل این موضوع دارید، اینجا بنویسید؛ اختیاری است."></textarea>
    <small style="display:block;color:#666;margin-top:4px;">نوشتن طرح ایده اختیاری است.</small>
    <button type="submit">✅ ثبت موضوع</button>
  </form>
</div>

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
<h3>📘 موضوعات در حال بحث (<?= $topics_count ?>)</h3>
<div id="topics-wrapper" style="max-height:540px; overflow-y:auto; padding:10px;">
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

while($t2 = $topics_res2->fetch_assoc()):
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
<div class="topic" id="topic-<?= (int)$tid ?>" style="<?= $is_closed ? 'opacity:0.6;' : '' ?>">
  <div class="topic-header">
    <div class="topic-title-wrap">
      <h2><?= htmlspecialchars($t2['title']) ?></h2>
      <?php if (trim((string)($t2['description'] ?? '')) !== ''): ?>
        <div class="topic-description"><?= htmlspecialchars($t2['description'], ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
    </div>
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
            <button type="submit" title="اولویت موضوع را ثبت یا لغو می‌کند؛ مجموع اولویت اعضا در مرتب‌سازی موضوعات اثر دارد." style="background:<?= $user_has_priority ? '#f1c40f' : '#ccc' ?>;">
                ⭐ اولویت
            </button>
            <span class="action-tooltip-text" role="tooltip">با ثبت اولویت، اهمیت این موضوع را برای جامعه نشان می‌دهید. موضوع‌ها بر اساس مجموع اولویت اعضا مرتب می‌شوند؛ با کلیک دوباره می‌توانید اولویت خود را لغو کنید.</span>
          </span>
      </form>
      <form action="update_urgency.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($community_duty_csrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="topic_id" value="<?= $tid ?>">
        <input type="hidden" name="community_id" value="<?= $community['id'] ?>">
        <input class="urgency-input" type="number" name="urgency_days" min="1" max="360" value="<?= $urgency ?>" required>
        <span class="action-tooltip">
          <button class="urgency-btn" type="submit" title="فوریت را بر حسب روز ثبت می‌کند؛ میانگین پیشنهادهای اعضا زمان موضوع را تعیین می‌کند.">⏱ فوریت</button>
          <span class="action-tooltip-text" role="tooltip">تعداد روزهای پیشنهادی تا پایان فرصت بحث را در کادر وارد کنید و روی این دکمه بزنید. زمان فوریت موضوع بر اساس میانگین پیشنهادهای ثبت‌شدهٔ اعضا محاسبه می‌شود.</span>
        </span>
      </form>
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
            <?= nl2br(htmlspecialchars($i['content'])) ?>
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
</div> <!-- topic -->
<?php endwhile; $topics_list->close(); ?>
</div> <!-- topics-wrapper -->
</div> <!-- box موضوعات در حال بحث -->

<!-- موضوعات در حال رأی‌گیری -->
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
<div class="box">
  <h3>🗳 موضوعات در حال رأی‌گیری نهایی (<?= $vote_count ?>)</h3>
  <p>برای مشاهده و رأی‌گیری روی دکمه زیر کلیک کنید:</p>
  <a href="votes.php?community_id=<?= $community['id'] ?>">
    <button style="padding:8px 16px; font-size:14px; border-radius:6px; background:#27ae60; color:#fff; border:none; cursor:pointer;">
      مشاهده موضوعات رأی‌گیری
    </button>
  </a>
</div>

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
<div class="card">
    <h3 style="margin:0 0 8px 0">📦 آرشیو موضوعات</h3>
    <div class="small" style="margin-bottom:8px">
        مجموع آرشیو: <strong><?= $total_archived ?></strong> &nbsp;|&nbsp;
        ✅ برنده‌ها: <strong><?= $winners_count ?></strong> &nbsp;|&nbsp;
        ❌ بازنده‌ها: <strong><?= $losers_count ?></strong>
    </div>
    <a href="archived_topics.php?community_id=<?= $community_id ?>" style="display:inline-block;padding:8px 14px;background:#2065d1;color:#fff;border-radius:8px;text-decoration:none;font-weight:bold">
        مشاهده جزئیات آرشیو
    </a>
</div>

<?php else: ?>
<div class="box">
  <p style="color:red">هیچ جامعه‌ای انتخاب نشده است.</p>
</div>
<?php endif; ?>

</div> <!-- container -->

<script>
document.addEventListener("DOMContentLoaded", function() {
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
                    const urgencyInput = currentTopic.querySelector(".urgency-input");
                    if (urgencyInput) urgencyInput.value = urgency;

                    const currentSummary = currentTopic.querySelector(".topic-summary");
                    const summaryLines = currentSummary ? currentSummary.querySelectorAll("div") : [];
                    if (summaryLines.length > 1) {
                        summaryLines[1].textContent = "⏱ فوریت: " + urgency + "روز";
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