<?php
// votes.php - یک فایل کامل: PHP + HTML + JS
// در محیط هاست خطاها به کاربر نمایش داده نشوند؛ جزئیات در لاگ سرور ثبت می‌شوند.
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

session_start();

// توکن CSRF مخصوص عملیات AJAX صفحهٔ رأی‌گیری
if (empty($_SESSION['votes_csrf_token'])) {
    $_SESSION['votes_csrf_token'] = bin2hex(random_bytes(32));
}
$votes_csrf_token = $_SESSION['votes_csrf_token'];

include "db.php"; // اتصال شما به دیتابیسzzz

if(!defined('X')) define('X', 5); // پیش‌فرض روز

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$user_id = (int)$_SESSION['user_id'];
$community_id = isset($_GET['community_id']) ? (int)$_GET['community_id'] : 0;
if($community_id <= 0) die("Community not specified.");

// ----- create helper table for enforce votes (one row per user/idea) -----
$conn->query("
CREATE TABLE IF NOT EXISTS topic_enforce_votes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  topic_id INT NOT NULL,
  idea_id INT NOT NULL,
  user_id INT NOT NULL,
  enforceable TINYINT(1) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_topic_idea_user_enf (topic_id, idea_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// جدول آمار لازم‌الاجرا؛ در نسخهٔ پایگاه دادهٔ قدیمی ممکن است هنوز وجود نداشته باشد.
$conn->query("
CREATE TABLE IF NOT EXISTS topic_enforce_stats (
  id INT AUTO_INCREMENT PRIMARY KEY,
  topic_id INT NOT NULL,
  idea_id INT NOT NULL,
  enforce_count INT NOT NULL DEFAULT 0,
  non_enforce_count INT NOT NULL DEFAULT 0,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_topic_idea_enforce_stats (topic_id, idea_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// (existing tables creation in your code were left intact — ensure they exist)
// if topic_voting_ballots/topic_voting_meta/topic_winners not present, previous code creates them

// --- اطلاعات جامعه ---
$cstmt = $conn->prepare("SELECT * FROM communities WHERE id=? LIMIT 1");
$cstmt->bind_param("i", $community_id);
$cstmt->execute();
$community = $cstmt->get_result()->fetch_assoc();
$cstmt->close();
if(!$community) die("Community not found.");

if (empty($_SESSION['community_duty_csrf'])) { $_SESSION['community_duty_csrf'] = bin2hex(random_bytes(32)); }
$community_duty_csrf = $_SESSION['community_duty_csrf'];
function community_gregorian_to_jalali($gy, $gm, $gd) {
    $g_days_in_month = [31,28,31,30,31,30,31,31,30,31,30,31];
    $jy = $gy > 1600 ? 979 : 0; $gy -= $gy > 1600 ? 1600 : 621;
    $gy2 = $gm > 2 ? $gy + 1 : $gy;
    $days = 365*$gy + (int)(($gy2+3)/4) - (int)(($gy2+99)/100) + (int)(($gy2+399)/400) - 80 + $gd;
    for ($i=0; $i<$gm-1; $i++) $days += $g_days_in_month[$i];
    $jy += 33*(int)($days/12053); $days %= 12053; $jy += 4*(int)($days/1461); $days %= 1461;
    if ($days > 365) { $jy += (int)(($days-1)/365); $days = ($days-1)%365; }
    if ($days < 186) { $jm=1+(int)($days/31); $jd=1+$days%31; } else { $days-=186; $jm=7+(int)($days/30); $jd=1+$days%30; }
    return [$jy,$jm,$jd];
}
function community_persian_digits($value) { return strtr((string)$value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']); }
require_once __DIR__ . '/community_member_removal_helpers.php';
$community_removal_ready = community_removal_tables_ready($conn);
if ($community_removal_ready && $community_id > 0 && $community) community_removal_finalize_expired($conn, $community_id);


// جداول نامزدی با migration مستقل ساخته می‌شوند؛ در این فایل schema خودکار تغییر نمی‌کند.
$candidate_tables_ready = false;
$tables_check = $conn->query("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('topic_execution_candidates','topic_execution_candidate_votes')");
if ($tables_check) { $candidate_tables_ready = ((int)$tables_check->fetch_assoc()['n'] === 2); $tables_check->close(); }

// --- پاسخ AJAX برای رأی‌ها ---
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    header('Content-Type: application/json; charset=utf-8');

    // رد درخواست‌های فاقد توکن معتبر پیش از هر تغییر در رأی‌ها یا نامزدی
    if (
        empty($_SESSION['votes_csrf_token']) ||
        !isset($_POST['csrf_token']) ||
        !is_string($_POST['csrf_token']) ||
        !hash_equals($_SESSION['votes_csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'درخواست معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.']);
        exit;
    }

    // ---------- ثبت/لغو داوطلبی اجرای یک ایده ----------
    if(isset($_POST['toggle_execution_candidate'])){
        if (!$candidate_tables_ready) { echo json_encode(['success'=>false,'message'=>'ساختار نامزدی هنوز در پایگاه داده نصب نشده است.']); exit; }
        $topic_id=(int)($_POST['topic_id']??0); $idea_id=(int)($_POST['idea_id']??0);
        $valid=$conn->prepare("SELECT t.id FROM topics t JOIN topic_voting_meta m ON m.topic_id=t.id JOIN topic_final_votes tfv ON tfv.topic_id=t.id AND tfv.idea_id=? WHERE t.id=? AND t.community_id=? AND t.closed=1 AND m.finalized=0 LIMIT 1");
        $valid->bind_param("iii",$idea_id,$topic_id,$community_id); $valid->execute(); $valid_topic=$valid->get_result()->fetch_assoc(); $valid->close();
        if(!$valid_topic){echo json_encode(['success'=>false,'message'=>'این موضوع یا ایده برای داوطلبی معتبر نیست.']);exit;}
        $member_check=$conn->prepare("SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1");
        $member_check->bind_param("ii",$community_id,$user_id);$member_check->execute();$is_member=(bool)$member_check->get_result()->fetch_assoc();$member_check->close();
        if(!$is_member){echo json_encode(['success'=>false,'message'=>'فقط اعضای جامعه می‌توانند داوطلب اجرای طرح شوند.']);exit;}
        $check=$conn->prepare("SELECT id FROM topic_execution_candidates WHERE topic_id=? AND idea_id=? AND user_id=? LIMIT 1");
        $check->bind_param("iii",$topic_id,$idea_id,$user_id);$check->execute();$existing=$check->get_result()->fetch_assoc();$check->close();
        if($existing){
            $del=$conn->prepare("DELETE FROM topic_execution_candidates WHERE id=?");$cid=(int)$existing['id'];$del->bind_param("i",$cid);$del->execute();$del->close();
            $delvotes=$conn->prepare("DELETE FROM topic_execution_candidate_votes WHERE topic_id=? AND idea_id=? AND candidate_user_id=?");$delvotes->bind_param("iii",$topic_id,$idea_id,$user_id);$delvotes->execute();$delvotes->close();$is_candidate=false;
        }else{$ins=$conn->prepare("INSERT INTO topic_execution_candidates (topic_id,idea_id,user_id) VALUES (?,?,?)");$ins->bind_param("iii",$topic_id,$idea_id,$user_id);$ins->execute();$ins->close();$is_candidate=true;}
        $cs=$conn->prepare("SELECT COUNT(*) AS n FROM topic_execution_candidates WHERE topic_id=? AND idea_id=?");$cs->bind_param("ii",$topic_id,$idea_id);$cs->execute();$cc=(int)$cs->get_result()->fetch_assoc()['n'];$cs->close();
        $names_stmt=$conn->prepare("SELECT COALESCE(u.username, CONCAT('عضو ', c.user_id)) AS username FROM topic_execution_candidates c LEFT JOIN users u ON u.id=c.user_id WHERE c.topic_id=? AND c.idea_id=? ORDER BY c.id ASC");
        $names_stmt->bind_param("ii",$topic_id,$idea_id);$names_stmt->execute();$names_res=$names_stmt->get_result();$candidate_names=[];while($name_row=$names_res->fetch_assoc()){$candidate_names[]=$name_row['username'];}$names_stmt->close();
        echo json_encode(['success'=>true,'is_candidate'=>$is_candidate,'candidate_count'=>$cc,'candidate_names'=>$candidate_names]);exit;
    }

    // ---------- رأی آری/نه ----------
    if(isset($_POST['vote_yesno'])){
        $topic_id = (int)$_POST['topic_id'];
        $idea_id  = (int)$_POST['idea_id'];
        $vote_type = (isset($_POST['vote_type']) && $_POST['vote_type'] === 'no') ? 'no' : 'yes';

        $stmt = $conn->prepare("
            INSERT INTO topic_voting_ballots (topic_id, idea_id, user_id, vote_type)
            VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE vote_type=VALUES(vote_type), created_at=CURRENT_TIMESTAMP
        ");
        $stmt->bind_param("iiis", $topic_id, $idea_id, $user_id, $vote_type);
        $stmt->execute();
        $stmt->close();

        $yes = (int)$conn->query("SELECT COUNT(*) AS c FROM topic_voting_ballots WHERE topic_id=$topic_id AND idea_id=$idea_id AND vote_type='yes'")->fetch_assoc()['c'];
        $no  = (int)$conn->query("SELECT COUNT(*) AS c FROM topic_voting_ballots WHERE topic_id=$topic_id AND idea_id=$idea_id AND vote_type='no'")->fetch_assoc()['c'];

        echo json_encode(['success'=>true,'counts'=>['yes'=>$yes,'no'=>$no]]);
        exit;
    }

    // ---------- رأی لازم‌الاجرا / غیر لازم‌الاجرا ----------
    if(isset($_POST['vote_enforceable'])){
        $topic_id = (int)$_POST['topic_id'];
        $idea_id  = (int)$_POST['idea_id'];
        $enforceable = (isset($_POST['vote_enforceable']) && intval($_POST['vote_enforceable']) === 1) ? 1 : 0;

        // insert/update در جدول topic_enforce_votes (هر کاربر فقط یک رکورد دارد)
        $stmt = $conn->prepare("
            INSERT INTO topic_enforce_votes (topic_id, idea_id, user_id, enforceable)
            VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE enforceable=VALUES(enforceable), created_at=CURRENT_TIMESTAMP
        ");
        $stmt->bind_param("iiii", $topic_id, $idea_id, $user_id, $enforceable);
        $stmt->execute();
        $stmt->close();

        // حالا تعداد yes/no برای enforce را برگردان
        $enf_yes = (int)$conn->query("SELECT COUNT(*) AS c FROM topic_enforce_votes WHERE topic_id=$topic_id AND idea_id=$idea_id AND enforceable=1")->fetch_assoc()['c'];
        $enf_no  = (int)$conn->query("SELECT COUNT(*) AS c FROM topic_enforce_votes WHERE topic_id=$topic_id AND idea_id=$idea_id AND enforceable=0")->fetch_assoc()['c'];

        echo json_encode(['success'=>true,'enforceable'=>['yes'=>$enf_yes,'no'=>$enf_no]]);
        exit;
    }

    // ---------- ثبت فوریت کاربر، محاسبه میانگین و به‌روزرسانی مدت رأی‌گیری ----------
    if(isset($_POST['set_urgency'])){
        $topic_id = (int)($_POST['topic_id'] ?? 0);
        $urg = max(1, min(365, (int)($_POST['urgency_days'] ?? 5)));

        if($topic_id <= 0){
            echo json_encode(['success'=>false,'message'=>'موضوع نامعتبر است']);
            exit;
        }

        // ساخت جدول با همان نام ستون‌های نسخه SQL؛ هر کاربر برای هر موضوع یک رأی دارد.
        $conn->query("
            CREATE TABLE IF NOT EXISTS topic_voting_urgency_votes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                topic_id INT NOT NULL,
                user_id INT NOT NULL,
                urgency_days INT NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_voting_topic_user (topic_id, user_id),
                KEY idx_topic_id (topic_id),
                KEY idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $save_vote = $conn->prepare("
            INSERT INTO topic_voting_urgency_votes (topic_id, user_id, urgency_days)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE urgency_days = VALUES(urgency_days)
        ");
        $save_vote->bind_param("iii", $topic_id, $user_id, $urg);
        $save_vote->execute();
        $save_vote->close();

        // میانگین فوریت‌های ثبت‌شده توسط کاربران برای همین موضوع.
        $avg_sql = $conn->prepare("
            SELECT AVG(urgency_days) AS avg_days, COUNT(*) AS vote_count
            FROM topic_voting_urgency_votes
            WHERE topic_id = ?
        ");
        $avg_sql->bind_param("i", $topic_id);
        $avg_sql->execute();
        $avg_result = $avg_sql->get_result()->fetch_assoc();
        $avg_sql->close();

        $avg_days = max(1, min(365, (int)ceil((float)($avg_result['avg_days'] ?? $urg))));

        // ثبت مدت نهایی در جدول مخصوص رأی‌گیری نهایی؛ زمان شروع رأی‌گیری تغییر نمی‌کند.
        $check = $conn->prepare("SELECT topic_id FROM topic_voting_meta WHERE topic_id=? LIMIT 1");
        $check->bind_param("i", $topic_id);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();

        if($exists){
            $upd = $conn->prepare("UPDATE topic_voting_meta SET urgency_days=? WHERE topic_id=?");
            $upd->bind_param("ii", $avg_days, $topic_id);
            $upd->execute();
            $upd->close();
        } else {
            $ins = $conn->prepare("INSERT INTO topic_voting_meta (topic_id, urgency_days, voting_started, finalized) VALUES (?, ?, NOW(), 0)");
            $ins->bind_param("ii", $topic_id, $avg_days);
            $ins->execute();
            $ins->close();
        }

        echo json_encode([
            'success'=>true,
            'urgency'=>$avg_days,
            'vote_count'=>(int)($avg_result['vote_count'] ?? 1)
        ]);
        exit;
    }

    // اگر هیچ شرطی صادق نبود
    echo json_encode(['success'=>false,'message'=>'invalid request']);
    exit;
}

// ----------------- توابع کمکی -----------------
function sql_date_to_unix($ds){ return ($ds)?strtotime($ds):null; }
function participation_percent($conn,$topic_id){
    $stmt = $conn->prepare("SELECT community_id FROM topics WHERE id=? LIMIT 1");
    $stmt->bind_param("i",$topic_id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if(!$row) return 0;
    $community_id = (int)$row['community_id'];
    $r1 = $conn->prepare("SELECT COUNT(*) AS total FROM community_members WHERE community_id=?");
    $r1->bind_param("i",$community_id); $r1->execute();
    $total = (int)$r1->get_result()->fetch_assoc()['total']; $r1->close();
    if($total<=0) return 0;
    $r2 = $conn->prepare("SELECT COUNT(DISTINCT user_id) AS voters FROM topic_voting_ballots WHERE topic_id=?");
    $r2->bind_param("i",$topic_id); $r2->execute();
    $voters = (int)$r2->get_result()->fetch_assoc()['voters']; $r2->close();
    return round(($voters/$total)*100,1);
}
$conn->query("
    INSERT INTO topic_voting_meta (topic_id, urgency_days, voting_started, finalized)
    SELECT t.id, 5, t.created_at, 0
    FROM topics t
    LEFT JOIN topic_voting_meta m ON m.topic_id = t.id
    WHERE t.closed=1 AND m.topic_id IS NULL
");
// =========================
// نهایی‌سازی اتوماتیک موضوعات
// =========================

// ساخت meta برای تمام موضوعات بسته شده که meta ندارند
$conn->query("
    INSERT INTO topic_voting_meta (topic_id, urgency_days, voting_started, finalized)
    SELECT t.id, 5, t.created_at, 0
    FROM topics t
    LEFT JOIN topic_voting_meta m ON m.topic_id = t.id
    WHERE t.community_id=$community_id AND t.closed=1 AND m.topic_id IS NULL
");

// گرفتن لیست موضوعات در حال رأی‌گیری
$finalize_q = $conn->prepare("
    SELECT t.id, m.voting_started, m.urgency_days, m.finalized
    FROM topics t
    JOIN topic_voting_meta m ON m.topic_id = t.id
    WHERE t.community_id=? 
      AND t.closed = 1
      AND m.finalized = 0
");
$finalize_q->bind_param("i", $community_id);
$finalize_q->execute();
$finalize_res = $finalize_q->get_result();

while($row = $finalize_res->fetch_assoc()){

    $tid = (int)$row['id'];
    $started = strtotime($row['voting_started']);
    $days    = (int)$row['urgency_days'];
    $deadline = $started + ($days * 24 * 3600);

    // اگر زمان رأی‌گیری تمام شده
    if(time() >= $deadline){

        // ==========================
        // 1️⃣ — گرفتن تعداد لازم‌الاجرا / غیر لازم‌الاجرا برای هر ایده
        // ==========================
        $ideas_q = $conn->query("
            SELECT id AS idea_id
            FROM ideas
            WHERE topic_id = $tid
        ");

        if($ideas_q && $ideas_q->num_rows > 0){
            while($idea_row = $ideas_q->fetch_assoc()){
                $iid = (int)$idea_row['idea_id'];

                $en = $conn->query("
                    SELECT 
                        SUM(enforceable = 1) AS enforce_count,
                        SUM(enforceable = 0) AS non_enforce_count
                    FROM topic_enforce_votes
                    WHERE topic_id = $tid AND idea_id = $iid
                ")->fetch_assoc();

                $enforce_count      = (int)$en['enforce_count'];
                $non_enforce_count  = (int)$en['non_enforce_count'];

                // ذخیره در topic_enforce_stats با idea_id
                $conn->query("
                    INSERT INTO topic_enforce_stats (topic_id, idea_id, enforce_count, non_enforce_count)
                    VALUES ($tid, $iid, $enforce_count, $non_enforce_count)
                    ON DUPLICATE KEY UPDATE 
                        enforce_count = VALUES(enforce_count),
                        non_enforce_count = VALUES(non_enforce_count),
                        updated_at = CURRENT_TIMESTAMP
                ");
            }
        }

        // ==========================
        // 2️⃣ — مشخص کردن ایده برنده
        // ==========================
        $best = $conn->query("
            SELECT id, content, user_id
            FROM ideas
            WHERE topic_id=$tid
            ORDER BY idea_rationality DESC, id ASC
            LIMIT 1
        ");

        if($best && $best->num_rows > 0){
            $best_data = $best->fetch_assoc();
            $idea_id = (int)$best_data['id'];

            // جدول topic_winners در پایگاه دادهٔ فعلی فقط ستون‌های id, topic_id, idea_id و selected_at را دارد.
            $winner_check = $conn->prepare("SELECT id FROM topic_winners WHERE topic_id=? LIMIT 1");
            $winner_check->bind_param("i", $tid);
            $winner_check->execute();
            $existing_winner = $winner_check->get_result()->fetch_assoc();
            $winner_check->close();

            if ($existing_winner) {
                $winner_row_id = (int)$existing_winner['id'];
                $winner_update = $conn->prepare("UPDATE topic_winners SET idea_id=?, selected_at=NOW() WHERE id=?");
                $winner_update->bind_param("ii", $idea_id, $winner_row_id);
                $winner_update->execute();
                $winner_update->close();
            } else {
                $winner_insert = $conn->prepare("INSERT INTO topic_winners (topic_id, idea_id, selected_at) VALUES (?, ?, NOW())");
                $winner_insert->bind_param("ii", $tid, $idea_id);
                $winner_insert->execute();
                $winner_insert->close();
            }        }

        // ==========================
        // 3️⃣ — نهایی‌سازی meta
        // ==========================
        $conn->query("
            UPDATE topic_voting_meta 
            SET finalized=1, finalized_at=NOW() 
            WHERE topic_id=$tid
        ");

        // ==========================
        // 4️⃣ — انتقال موضوع به آرشیو
        // ==========================
        $conn->query("UPDATE topics SET closed=2 WHERE id=$tid");
    }
}

$finalize_q->close();
// ------------- existing finalization logic (kept) --------------
// (اگر می‌خواهی enforceable_weight براساس votes_enforce محاسبه شود، اطلاع بده تا اضافه کنم)
// ... (بقیه منطق نهایی‌سازی شما همان است - دست نزدم تا رفتار کلی طبق قبلی بماند) ...

// --- نمایش HTML و کارت‌ها ---
$active_q = $conn->prepare("
  SELECT t.id AS topic_id, t.title AS topic_title, t.description AS topic_description,
         COALESCE(tfv.created_at, t.created_at) AS voting_started
  FROM topics t
  JOIN topic_final_votes tfv ON tfv.topic_id = t.id
  LEFT JOIN topic_voting_meta m ON m.topic_id = t.id
  WHERE t.community_id=? AND t.closed=1 AND (m.finalized IS NULL OR m.finalized=0)
  GROUP BY t.id ORDER BY t.id DESC
");
$active_q->bind_param("i",$community_id); $active_q->execute(); $active_res=$active_q->get_result();
$active_count = $active_res->num_rows;

// archived count (برای کارت بایگانی)
$arch_q = $conn->prepare("
  SELECT t.id AS topic_id
  FROM topics t
  JOIN topic_voting_meta m ON m.topic_id = t.id
  WHERE t.community_id=? AND m.finalized=1
  GROUP BY t.id
");
$arch_q->bind_param("i",$community_id); $arch_q->execute(); $arch_res=$arch_q->get_result();
$arch_count = $arch_res->num_rows;

// محاسبهٔ رتبهٔ محبوبیت هر عضو در همین جامعه، برای نمایش کنار نام ایده‌دهنده.
$community_ranks = [];
$community_member_total = 0;
$rank_stmt = $conn->prepare("
    SELECT cm.user_id,
           AVG(NULLIF(us.lawfulness, 0)) AS avg_lawfulness,
           AVG(NULLIF(us.ethics, 0)) AS avg_ethics,
           AVG(NULLIF(us.honesty, 0)) AS avg_honesty,
           AVG(NULLIF(us.executive_skill, 0)) AS avg_executive,
           COUNT(CASE WHEN COALESCE(us.lawfulness, 0) > 0 OR COALESCE(us.ethics, 0) > 0 OR COALESCE(us.honesty, 0) > 0 OR COALESCE(us.executive_skill, 0) > 0 THEN us.scorer_user_id END) AS score_count
    FROM community_members cm
    LEFT JOIN user_scores us
      ON us.target_user_id = cm.user_id
     AND us.scorer_user_id <> cm.user_id
     AND us.scorer_user_id IN (SELECT user_id FROM community_members WHERE community_id = ?)
    WHERE cm.community_id = ?
    GROUP BY cm.user_id
");
if ($rank_stmt) {
    $rank_stmt->bind_param("ii", $community_id, $community_id);
    $rank_stmt->execute();
    $rank_result = $rank_stmt->get_result();
    $rank_scores = [];
    while ($rank_row = $rank_result->fetch_assoc()) {
        $community_member_total++;
        $rank_row['user_id'] = (int)$rank_row['user_id'];
        $rank_row['score_count'] = (int)$rank_row['score_count'];
        $rank_row['composite_score'] = null;
        if ($rank_row['score_count'] > 0) {
            $criteria = [
                $rank_row['avg_lawfulness'],
                $rank_row['avg_ethics'],
                $rank_row['avg_honesty'],
                $rank_row['avg_executive']
            ];
            $criteria = array_filter($criteria, function($value) { return $value !== null; });
            if (count($criteria) > 0) {
                $rank_row['composite_score'] = array_sum($criteria) / count($criteria);
            }
        }
        $rank_scores[] = $rank_row;
    }
    $rank_stmt->close();

    foreach ($rank_scores as $rank_row) {
        if ($rank_row['composite_score'] === null) continue;
        $rank = 1;
        foreach ($rank_scores as $other_row) {
            if ($other_row['composite_score'] !== null
                && $other_row['composite_score'] > $rank_row['composite_score']) {
                $rank++;
            }
        }
        $community_ranks[$rank_row['user_id']] = $rank;
    }
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<title>موضوعات در حال رأی‌گیری - <?=htmlspecialchars($community['name'])?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{
    font-family:tahoma, Arial, sans-serif;
    background:#eef3f8;
    margin:0;
    padding:12px;
}

.container{
    max-width:900px;
    margin:0 auto;
    display:flex;
    flex-direction:column;
    gap:16px;
}

.header-box, .card{
    background:#fff;
    border:1px solid #ccc;
    border-radius:12px;
    padding:14px;
    box-shadow:0 2px 6px rgba(0,0,0,0.05);
}

/* هر موضوع در یک کلید باز و بسته‌شونده */
.topic-collapse{width:100%;box-sizing:border-box;border:1px solid #d7b35d;border-radius:12px;background:#fffdf3;overflow:hidden;}
.topic-collapse>summary{display:block;list-style:none;cursor:pointer;padding:13px 42px 13px 14px;background:#fff0c2;color:#4c3a0b;font-weight:bold;border-radius:11px;position:relative;overflow-wrap:anywhere;}
.topic-collapse>summary::-webkit-details-marker{display:none;}
.topic-collapse>summary:before{content:"＋";position:absolute;right:14px;top:50%;transform:translateY(-50%);font-size:18px;}
.topic-collapse[open]>summary{border-radius:11px 11px 0 0;border-bottom:1px solid #e5d7ac;}
.topic-collapse[open]>summary:before{content:"−";}
.topic-collapse>.topic{border:0;border-radius:0;box-shadow:none;margin:0;}
.topic-collapse .topic-title-wrap h4{display:none;}

/* موضوع */
.topic{
    background:#fffdf3;
    border:1px solid #f5c86e;
    border-radius:12px;
    padding:12px;
    margin-bottom:14px;
}
.topic-title-wrap{position:relative;display:inline-block;max-width:100%;}
.topic-title-wrap h4{cursor:help;}
.topic-description{display:none;position:absolute;top:calc(100% + 5px);right:0;width:360px;max-width:calc(100vw - 48px);box-sizing:border-box;padding:10px 12px;background:#fff;color:#374151;font-size:13px;line-height:1.9;white-space:pre-wrap;overflow-wrap:anywhere;border:1px solid #9ac7ff;border-radius:8px;box-shadow:0 5px 16px rgba(0,0,0,.18);z-index:1500;font-weight:normal;}
.topic-title-wrap:hover .topic-description,.topic-title-wrap:focus-within .topic-description{display:block;}

/* اطلاعات و محبوبیت ایده‌دهنده */
.idea-author-wrap{position:relative;display:inline-block;cursor:help;color:#174ea6;text-decoration:underline;text-decoration-style:dotted;text-underline-offset:3px;outline-offset:3px;}
.idea-author-tooltip{display:none;position:absolute;right:0;top:calc(100% + 5px);width:270px;max-width:calc(100vw - 56px);box-sizing:border-box;padding:10px 12px;background:#fff;color:#263445;font-size:13px;line-height:1.9;white-space:normal;overflow-wrap:anywhere;border:1px solid #9ac7ff;border-radius:8px;box-shadow:0 5px 16px rgba(0,0,0,.18);z-index:1600;font-weight:normal;text-decoration:none;}
.idea-author-wrap:hover .idea-author-tooltip,.idea-author-wrap:focus .idea-author-tooltip,.idea-author-wrap:focus-within .idea-author-tooltip{display:block;}

/* ایده */
.idea{
    background:#fff9e5;
    border:1px solid #f5e2b3;
    border-radius:10px;
    padding:10px;
    margin-bottom:12px;
    display:flex;
    flex-direction:column;
    gap:8px;

    word-wrap: break-word;
    overflow-wrap: break-word;
    white-space: normal;
}

.idea-box{
    word-wrap: break-word;
    overflow-wrap: break-word;
    white-space: normal;
}

.small{
    font-size:14px;
    color:#444;
}

.countdown{
    font-weight:bold;
}

/* دکمهٔ جمع‌شوندهٔ تنظیم فوریت */
.urgency-controls{margin-top:8px;text-align:right;}
.urgency-controls summary{display:inline-block;cursor:pointer;list-style:none;padding:8px 12px;border-radius:7px;background:#f1c40f;color:#333;font-size:14px;font-weight:bold;}
.urgency-controls summary::-webkit-details-marker{display:none;}
.urgency-controls[open]{padding:8px;border:1px solid #e7d58a;border-radius:8px;background:#fffdf3;}
.urgency-controls[open] summary{margin-bottom:8px;}
.urgency-controls .form-inline{margin-top:4px;}
/* فرم فوریت */
.form-inline{
    display:flex;
    flex-direction:row;
    justify-content:flex-start;
    gap:10px;
    align-items:center;
    flex-wrap:wrap;
}
.form-inline input[type=number]{
    width:90px;
    padding:6px;
    border-radius:6px;
    border:1px solid #bbb;
    text-align:center;
}
.form-inline button{
    padding:8px 12px;
    border-radius:6px;
    border:none;
    background:#f1c40f;
    color:#333;
    cursor:pointer;
    font-size:14px;
}

/* راهنمای توضیحی دکمهٔ ثبت فوریت */
.urgency-help-wrap{
    position:relative;
    display:inline-flex;
    align-items:center;
}
.urgency-tooltip{
    display:none;
    position:absolute;
    z-index:1000;
    top:50%;
    right:calc(100% + 10px);
    transform:translateY(-50%);
    width:300px;
    max-width:min(300px, 75vw);
    padding:11px 13px;
    border:1px solid #d7b64a;
    border-radius:9px;
    background:#fffbea;
    color:#343434;
    box-shadow:0 4px 14px rgba(0,0,0,0.16);
    font-size:13px;
    line-height:1.9;
    text-align:right;
    direction:rtl;
    white-space:normal;
}
.urgency-help-wrap:hover .urgency-tooltip,
.urgency-help-wrap:focus-within .urgency-tooltip{
    display:block;
}
.urgency-tooltip strong{
    display:block;
    margin-bottom:4px;
    color:#665000;
    font-size:14px;
}

/* دکمه رأی */
.vote-btn{
    background:#27ae60;
    padding:8px 12px;
    border-radius:6px;
    color:#fff;
    border:none;
    cursor:pointer;
    width:100%;
    font-size:15px;
}
.vote-btn.no{
    background:#e74c3c;
}

/* لیبل کارت */
.badge{
    background:#2065d1;
    color:#fff;
    padding:6px 10px;
    border-radius:8px;
    font-weight:bold;
}

/* چینش هدر هر موضوع (عنوان + آمار) */
.header-row{
    display:flex;
    flex-direction:column;
    gap:10px;
}

/* نمایش/مخفی ایده */
.show-hide-btn{
    background:#f5d67b;
    border:none;
    border-radius:8px;
    padding:6px 10px;
    cursor:pointer;
    width:100%;
}

/* صفحه در موبایل */
@media(max-width:600px){

    body{
        padding:8px;
    }

    .form-inline{
        flex-direction:column;
        align-items:stretch;
    }

    /* در موبایل راهنما زیر دکمه باز شود تا از لبهٔ صفحه بیرون نزند. */
    .urgency-help-wrap{
        align-self:flex-start;
    }
    .urgency-tooltip{
        top:calc(100% + 8px);
        right:0;
        transform:none;
        width:280px;
        max-width:75vw;
    }

    .vote-btn{
        font-size:16px;
        padding:10px;
    }

    .topic, .idea{
        padding:10px;
    }
}
/* سربرگ جامعه در صفحه رأی‌گیری */
.community-header{background:linear-gradient(125deg,#ff9fba 0%,#ffc878 20%,#b8e88a 40%,#91d8ff 60%,#b3a0ff 80%,#ff9bd4 100%);border-color:#e2dff0;text-align:right;}
.community-mobile-actions{display:none;}
.community-duties{margin:12px 0 8px;padding:12px;background:rgba(255,255,255,.76);border:1px solid #d9d6e9;border-radius:9px;text-align:right;}
.community-duties-toggle{margin:12px 0 8px;}
.community-duties-toggle>summary{display:block;list-style:none;background:#2065d1;color:#fff;cursor:pointer;border-radius:7px;padding:10px 14px;text-align:center;font-weight:bold;}
.community-duties-toggle>summary::-webkit-details-marker{display:none;}
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
.community-duty-form textarea{width:100%;box-sizing:border-box;margin-top:5px;padding:8px;border:1px solid #c5cbd3;border-radius:6px;font:inherit;font-size:13px;background:#fff;}
.members-dropdown{position:relative;display:inline-block;margin-top:8px;z-index:1100;}
.members-button{background:#2065d1;color:#fff;border:none;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:13px;}
.members-list{display:none;position:absolute;top:100%;right:0;background:#fff;min-width:200px;max-height:220px;overflow-y:auto;border:1px solid #ccc;border-radius:8px;z-index:1101;padding:8px;box-shadow:0 2px 10px rgba(0,0,0,.14);}
.member-entry{position:relative;padding:5px 4px;border-bottom:1px solid #eee;}
.member-name{display:block;color:#2065d1;font-weight:bold;cursor:default;}
.member-profile{display:none;position:fixed;top:0;right:12px;width:260px;max-width:calc(100vw - 24px);max-height:60vh;overflow:auto;box-sizing:border-box;margin:0;padding:10px;background:#f4f8ff;border:1px solid #9bbbe0;border-radius:8px;color:#333;font-size:12px;line-height:1.9;white-space:normal;overflow-wrap:anywhere;box-shadow:0 5px 18px rgba(0,0,0,.2);z-index:99999;}
.member-entry:hover .member-profile,.member-entry:focus-within .member-profile{display:block;}
.members-dropdown:hover .members-list,.members-dropdown:focus-within .members-list{display:block;}
@media(max-width:720px){.member-profile{left:50%;right:auto;transform:translateX(-50%);top:12px;width:280px;max-width:calc(100vw - 24px);}.community-header{border-radius:14px;}.community-mobile-actions{display:flex;flex-direction:row;flex-wrap:nowrap;align-items:center;gap:6px;width:100%;margin:8px 0 12px;}.community-mobile-actions>*{flex:1 1 0;min-width:0;max-width:33.333%;margin:0!important;text-align:center!important;}.community-mobile-actions a,.community-mobile-actions form button,.community-mobile-actions .members-button{display:flex;align-items:center;justify-content:center;width:100%;min-height:42px;box-sizing:border-box;padding:7px 3px!important;font-size:10px!important;line-height:1.35;white-space:normal;margin:0!important;}.community-mobile-actions form{display:flex;align-items:center;min-width:0;}.community-mobile-actions .members-dropdown{display:flex;align-items:stretch;min-width:0;}.community-duty-item{align-items:flex-start;flex-direction:column;}.community-duty-vote-form{align-self:flex-start;}}
</style>
</head>
<body>
<div class="container">

<div class="box community-header">
  <div class="community-header-top" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <h2 style="margin:0;flex:1 1 auto;min-width:0;"><?=htmlspecialchars($community['name'])?></h2>
    <a href="community_stats.php?community_id=<?= (int)$community['id'] ?>" style="display:inline-flex;align-items:center;justify-content:center;gap:6px;background:#0099cc;color:#fff;text-decoration:none;padding:9px 13px;border-radius:8px;font-size:13px;font-weight:bold;white-space:nowrap;box-sizing:border-box;">📊 گزارش آماری جامعه</a>
  </div>
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

  <div class="topic-navigation-buttons" style="display:flex;gap:10px;flex-wrap:wrap;margin:12px 0 16px;">
    <a href="community.php?id=<?= (int)$community_id ?>" style="display:inline-flex;align-items:center;justify-content:center;flex:1 1 180px;min-height:42px;box-sizing:border-box;background:#4A82DA;color:#fff;text-decoration:none;padding:10px 14px;border-radius:9px;font-weight:bold;text-align:center;">موضوعات در حال بحث</a>
    <a href="archived_topics.php?community_id=<?= (int)$community_id ?>" style="display:inline-flex;align-items:center;justify-content:center;flex:1 1 180px;min-height:42px;box-sizing:border-box;background:#4A82DA;color:#fff;text-decoration:none;padding:10px 14px;border-radius:9px;font-weight:bold;text-align:center;">آرشیو موضوعات</a>
  </div>

  <div class="header-box">
    <h2>🗳 مدیریت رأی‌گیری در جامعه «<?= htmlspecialchars($community['name']) ?>»</h2>
    <p class="small">در این صفحه: موضوعات در حال رأی‌گیری (سمت چپ) و پیوند به بایگانی. رأی لازم‌الاجرا اکنون در جدول جداگانه ذخیره و شمارش می‌شود.</p>
  </div>

  <a href="archived_topics.php?community_id=<?= $community_id ?>" style="font-size:14px;color:#2065d1; display:block; margin-bottom:10px;">
   📦 مشاهده موضوعات بایگانی‌شده (<?= $arch_count ?>)
  </a>

  <!-- کارت اول: فعال -->
  <div class="card" id="active-topics">
  <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end">
      <h3>موضوعات در حال رأی‌گیری (<?= $active_count ?>)</h3>
    </div>

<?php while($t = $active_res->fetch_assoc()):
    $tid = (int)$t['topic_id'];
    $topic_title = $t['topic_title'];
    $voting_started_unix = sql_date_to_unix($t['voting_started']) ?: time();

    $mstmt = $conn->prepare("SELECT urgency_days, voting_started, finalized, finalized_at FROM topic_voting_meta WHERE topic_id=? LIMIT 1");
    $mstmt->bind_param("i",$tid); $mstmt->execute();
    $meta = $mstmt->get_result()->fetch_assoc(); $mstmt->close();
    $urg_days = ($meta && $meta['urgency_days']) ? (int)$meta['urgency_days'] : X;

    // نمایش انتخاب شخصی کاربر جدا از میانگین جمعی فوریت.
    $my_urgency_days = X;
    $has_urgency_vote = false;
    $urgency_vote_count = 0;

    $my_urgency_stmt = $conn->prepare("SELECT urgency_days FROM topic_voting_urgency_votes WHERE topic_id=? AND user_id=? LIMIT 1");
    $my_urgency_stmt->bind_param("ii", $tid, $user_id);
    $my_urgency_stmt->execute();
    $my_urgency_row = $my_urgency_stmt->get_result()->fetch_assoc();
    $my_urgency_stmt->close();
    if ($my_urgency_row) {
        $my_urgency_days = (int)$my_urgency_row['urgency_days'];
        $has_urgency_vote = true;
    }

    $urgency_count_stmt = $conn->prepare("SELECT COUNT(*) AS vote_count FROM topic_voting_urgency_votes WHERE topic_id=?");
    $urgency_count_stmt->bind_param("i", $tid);
    $urgency_count_stmt->execute();
    $urgency_vote_count = (int)$urgency_count_stmt->get_result()->fetch_assoc()['vote_count'];
    $urgency_count_stmt->close();

    if($meta && $meta['voting_started']){
        $vs_unix = sql_date_to_unix($meta['voting_started']);
        if($vs_unix) $voting_started_unix = $vs_unix;
    }
    $deadline_unix = $voting_started_unix + ($urg_days*24*3600);
    $remaining_seconds = max(0, $deadline_unix - time());
    $is_finalized = ($meta && (int)($meta['finalized'] ?? 0) === 1 && time() >= $deadline_unix);

    // دریافت ایده‌هایی که در topic_final_votes برای این موضوع مشخص شده‌اند
    $ideas_q = $conn->prepare("
        SELECT tfv.idea_id, i.content, i.user_id AS author_id,
               u.username, u.age, u.gender, u.job, u.education, u.city
        FROM topic_final_votes tfv
        JOIN ideas i ON i.id = tfv.idea_id
        LEFT JOIN users u ON u.id = i.user_id
        WHERE tfv.topic_id = ?
    ");
    $ideas_q->bind_param("i",$tid); $ideas_q->execute(); $ideas_res = $ideas_q->get_result();

    // آمار
    $total_votes = 0; $participation = 0;
    if($ideas_res->num_rows > 0){
        $sum_q = $conn->prepare("SELECT COUNT(*) AS total FROM topic_voting_ballots WHERE topic_id=?");
        $sum_q->bind_param("i",$tid); $sum_q->execute();
        $total_votes = (int)$sum_q->get_result()->fetch_assoc()['total']; $sum_q->close();
        $participation = participation_percent($conn,$tid);
    }
?>
  <details class="topic-collapse">
    <summary><?= htmlspecialchars($topic_title, ENT_QUOTES, 'UTF-8') ?></summary>
    <div class="topic" data-topic="<?= $tid ?>" style="<?= $is_finalized ? 'opacity:0.7;' : '' ?>">
    <div class="header-row">
      <div style="flex:1 1 60%;">
        <div class="topic-title-wrap">
          <h4 style="margin:0 0 6px 0"><?= htmlspecialchars($topic_title, ENT_QUOTES, 'UTF-8') ?></h4>
          <?php if (trim((string)($t['topic_description'] ?? '')) !== ''): ?>
            <div class="topic-description"><?= nl2br(htmlspecialchars($t['topic_description'], ENT_QUOTES, 'UTF-8')) ?></div>
          <?php endif; ?>
        </div>
        <div class="meta">
          آغاز رأی‌گیری: <?= date('Y-m-d H:i', $voting_started_unix) ?> —
          میانگین فعلی فوریت: <strong class="urgency-average"><?= $urg_days ?></strong> روز
          (<span class="urgency-vote-count"><?= $urgency_vote_count ?></span> رأی فوریت)
        </div>
      </div>
      <div style="min-width:240px;text-align:left">
        <div class="small">آراء ثبت‌شده: <strong><?= $total_votes ?></strong></div>
        <div class="small">مشارکت اعضا: <strong><?= $participation ?>%</strong></div>
       <div class="small">زمان باقی‌مانده: 
    <span class="countdown" data-remaining="<?= $remaining_seconds ?>" data-start="<?= $voting_started_unix ?>"></span>
</div>
        <details class="urgency-controls">
          <summary>⏱ تنظیم فوریت</summary>
          <form class="form-inline" method="post" data-topic="<?= $tid ?>">
            <input type="hidden" name="topic_id" value="<?= $tid ?>">
            <label for="urgency-days-<?= $tid ?>">فوریت انتخابی شما (روز):</label>
            <input id="urgency-days-<?= $tid ?>" type="number" name="urgency_days" min="1" max="365" value="<?= $my_urgency_days ?>" title="فوریت انتخابی شما بر حسب روز">
            <span class="urgency-help-wrap">
              <button type="submit" name="set_urgency" aria-describedby="urgency-help-<?= $tid ?>">ثبت فوریت</button>
              <span class="urgency-tooltip" id="urgency-help-<?= $tid ?>" role="tooltip">
                <strong>چرا فوریت را ثبت می‌کنیم؟</strong>
                در «دولت عشق»، مدت رأی‌گیری با مشارکت اعضا تعیین می‌شود. هر عضو زمان مورد نیاز خود را انتخاب می‌کند و همهٔ رأی‌ها وزن یکسان دارند. سامانه میانگین انتخاب‌ها را محاسبه می‌کند و آن را به روز کامل بالاتر گرد می‌کند. اگر انتخابتان را تغییر دهید، رأی قبلی شما جایگزین می‌شود. با تغییر میانگین، زمان باقی‌مانده دوباره محاسبه می‌شود؛ شمارش از ابتدا آغاز نمی‌شود.
              </span>
            </span>
            <div class="small urgency-own-status"><?= $has_urgency_vote ? 'انتخاب قبلی شما ثبت شده است.' : 'هنوز انتخابی ثبت نکرده‌اید؛ با ثبت فرم، مقدار این کادر به‌عنوان رأی شما ذخیره می‌شود.' ?></div>
          </form>
        </details>
      </div>
    </div>

    <div style="margin-top:10px">
    <?php
    if($ideas_res->num_rows == 0){
        echo "<p style='color:#666;padding:6px'>برای این موضوع ایده‌ای مشخص نشده است.</p>";
    } else {
        while($idea = $ideas_res->fetch_assoc()):
            $iid = (int)$idea['idea_id'];
            $icontent = $idea['content'];
            $iauthor = $idea['username'] ?? 'ناشناس';
            $author_id = (int)($idea['author_id'] ?? 0);
            $author_community_rank = $author_id > 0 ? ($community_ranks[$author_id] ?? null) : null;
            $author_age = trim((string)($idea['age'] ?? ''));
            $author_gender = trim((string)($idea['gender'] ?? ''));
            $author_job = trim((string)($idea['job'] ?? ''));
            $author_education = trim((string)($idea['education'] ?? ''));
            $author_city = trim((string)($idea['city'] ?? ''));

            // محبوبیت ایده‌دهنده بر اساس رأی‌های آری و نه به تمام ایده‌های او
            $author_yes = 0;
            $author_no = 0;
            if ($author_id > 0) {
                $pop_q = $conn->prepare("SELECT
                    SUM(CASE WHEN b.vote_type='yes' THEN 1 ELSE 0 END) AS yes_count,
                    SUM(CASE WHEN b.vote_type='no' THEN 1 ELSE 0 END) AS no_count
                    FROM ideas ai
                    LEFT JOIN topic_voting_ballots b ON b.idea_id=ai.id
                    WHERE ai.user_id=?");
                if ($pop_q) {
                    $pop_q->bind_param("i", $author_id);
                    $pop_q->execute();
                    $pop_row = $pop_q->get_result()->fetch_assoc();
                    $author_yes = (int)($pop_row['yes_count'] ?? 0);
                    $author_no = (int)($pop_row['no_count'] ?? 0);
                    $pop_q->close();
                }
            }
            $author_total = $author_yes + $author_no;
            $author_popularity = $author_total > 0 ? round(($author_yes / $author_total) * 100) : null;

            // محبوبیت خود شخص بر اساس امتیازدهی اعضای همین جامعه؛ هم‌راستا با صفحهٔ بایگانی.
            $author_person_score = null;
            $author_person_score_votes = 0;
            if ($author_id > 0) {
                $person_score_q = $conn->prepare("SELECT
                    AVG(NULLIF(lawfulness, 0)) AS avg_lawfulness,
                    AVG(NULLIF(ethics, 0)) AS avg_ethics,
                    AVG(NULLIF(honesty, 0)) AS avg_honesty,
                    AVG(NULLIF(executive_skill, 0)) AS avg_executive,
                    COUNT(CASE WHEN COALESCE(lawfulness, 0) > 0 OR COALESCE(ethics, 0) > 0 OR COALESCE(honesty, 0) > 0 OR COALESCE(executive_skill, 0) > 0 THEN scorer_user_id END) AS score_votes
                    FROM user_scores
                    WHERE target_user_id = ?
                      AND scorer_user_id <> ?
                      AND scorer_user_id IN (SELECT user_id FROM community_members WHERE community_id = ?)");
                if ($person_score_q) {
                    $person_score_q->bind_param("iii", $author_id, $author_id, $community_id);
                    $person_score_q->execute();
                    $person_score_row = $person_score_q->get_result()->fetch_assoc();
                    $author_person_score_votes = (int)($person_score_row['score_votes'] ?? 0);
                    if ($author_person_score_votes > 0) {
                        $criterion_scores = [
                            $person_score_row['avg_lawfulness'],
                            $person_score_row['avg_ethics'],
                            $person_score_row['avg_honesty'],
                            $person_score_row['avg_executive']
                        ];
                        $valid_scores = array_filter($criterion_scores, function($value) { return $value !== null; });
                        if (count($valid_scores) > 0) {
                            $author_person_score = round(array_sum($valid_scores) / count($valid_scores), 2);
                        }
                    }
                    $person_score_q->close();
                }
            }

            // counts
            $yes_count = (int)$conn->query("SELECT COUNT(*) AS c FROM topic_voting_ballots WHERE topic_id=$tid AND idea_id=$iid AND vote_type='yes'")->fetch_assoc()['c'];
            $no_count  = (int)$conn->query("SELECT COUNT(*) AS c FROM topic_voting_ballots WHERE topic_id=$tid AND idea_id=$iid AND vote_type='no'")->fetch_assoc()['c'];

            // counts for enforce votes (per-user table)
            $enf_yes = (int)$conn->query("SELECT COUNT(*) AS c FROM topic_enforce_votes WHERE topic_id=$tid AND idea_id=$iid AND enforceable=1")->fetch_assoc()['c'];
            $enf_no  = (int)$conn->query("SELECT COUNT(*) AS c FROM topic_enforce_votes WHERE topic_id=$tid AND idea_id=$iid AND enforceable=0")->fetch_assoc()['c'];

            $pct = ($yes_count + $no_count) > 0 ? round(($yes_count / ($yes_count + $no_count)) * 100, 2) : 0;
            $candidate_count=0; $my_is_candidate=false; $candidate_names=[];
            if($candidate_tables_ready){
                $cs=$conn->prepare("SELECT COUNT(*) AS total, COALESCE(MAX(user_id=?),0) AS mine FROM topic_execution_candidates WHERE topic_id=? AND idea_id=?");
                $cs->bind_param("iii",$user_id,$tid,$iid);$cs->execute();$cr=$cs->get_result()->fetch_assoc();$cs->close();
                $candidate_count=(int)($cr['total']??0);$my_is_candidate=(int)($cr['mine']??0)===1;
                $names_stmt=$conn->prepare("SELECT COALESCE(u.username, CONCAT('عضو ', c.user_id)) AS username FROM topic_execution_candidates c LEFT JOIN users u ON u.id=c.user_id WHERE c.topic_id=? AND c.idea_id=? ORDER BY c.id ASC");
                $names_stmt->bind_param("ii",$tid,$iid);$names_stmt->execute();$names_res=$names_stmt->get_result();
                while($name_row=$names_res->fetch_assoc()){$candidate_names[]=$name_row['username'];}
                $names_stmt->close();
            }
    ?>
     <div class="idea" data-idea="<?= $iid ?>">
  <button class="show-hide-btn" onclick="toggleIdea(<?= $iid ?>)">💡 نمایش/مخفی کردن ایده</button>
  <div class="idea-box" id="idea-box-<?= $iid ?>" style="display:none; margin-top:6px; padding:6px; border:1px solid #f0c86e; border-radius:6px; background:#fff9e5;">
      <strong>👤 <span class="idea-author-wrap" tabindex="0" aria-label="نمایش اطلاعات و محبوبیت ایده‌دهنده"><?= htmlspecialchars($iauthor, ENT_QUOTES, 'UTF-8') ?>
        <span class="idea-author-tooltip" role="tooltip">
          <strong>اطلاعات ایده‌دهنده</strong><br>
          نام کاربری: <?= htmlspecialchars($iauthor, ENT_QUOTES, 'UTF-8') ?><br>
          رتبه در این جامعه: <?= $author_community_rank === null ? 'هنوز رتبه‌ای ندارد' : ($author_community_rank . ' از ' . $community_member_total . ' عضو') ?><br>
          <?php if ($author_age !== ''): ?>سن: <?= htmlspecialchars($author_age, ENT_QUOTES, 'UTF-8') ?><br><?php endif; ?>
          <?php if ($author_gender !== ''): ?>جنسیت: <?= htmlspecialchars($author_gender, ENT_QUOTES, 'UTF-8') ?><br><?php endif; ?>
          <?php if ($author_job !== ''): ?>شغل: <?= htmlspecialchars($author_job, ENT_QUOTES, 'UTF-8') ?><br><?php endif; ?>
          <?php if ($author_education !== ''): ?>تحصیلات: <?= htmlspecialchars($author_education, ENT_QUOTES, 'UTF-8') ?><br><?php endif; ?>
          <?php if ($author_city !== ''): ?>شهر: <?= htmlspecialchars($author_city, ENT_QUOTES, 'UTF-8') ?><br><?php endif; ?>
          <hr style="border:0;border-top:1px solid #e2e8f0;margin:5px 0">
          <strong>محبوبیت ایده‌ها:</strong><br>
          آری: <?= $author_yes ?> — نه: <?= $author_no ?><br>
          درصد آری: <?= $author_popularity === null ? 'هنوز رأیی ثبت نشده' : $author_popularity . '%' ?><br>
          <hr style="border:0;border-top:1px solid #e2e8f0;margin:5px 0">
          <strong>محبوبیت خود شخص در این جامعه:</strong><br>
          میانگین امتیاز اعضا: <?= $author_person_score === null ? 'هنوز امتیازی ثبت نشده' : number_format($author_person_score, 2) ?><br>
          تعداد امتیازدهی‌ها: <?= $author_person_score_votes ?>
        </span>
      </span></strong><br>
      <?= nl2br(htmlspecialchars($icontent, ENT_QUOTES, 'UTF-8')) ?>
  </div>

  <div class="small" style="margin-top:6px">
    آری: <strong class="yes-count"><?= $yes_count ?></strong> —
    نه: <strong class="no-count"><?= $no_count ?></strong> —
    درصد آری: <strong class="pct"><?= $pct ?>%</strong><br>
    لازم‌الاجرا: <strong class="enf-yes"><?= $enf_yes ?></strong> —
    غیر لازم‌الاجرا: <strong class="enf-no"><?= $enf_no ?></strong>
  </div>
  <?php if(!$is_finalized): ?>
    <?php if($candidate_tables_ready): ?>
      <div class="candidate-signup" style="margin-top:8px;padding:9px;border:1px solid #c8d9f0;border-radius:8px;background:#f7fbff">
        <button type="button" class="candidate-btn" data-topic="<?= $tid ?>" data-idea="<?= $iid ?>" style="width:100%;padding:9px;border:0;border-radius:6px;background:<?= $my_is_candidate?'#e7a93b':'#dceeff' ?>;color:#173b63;font-weight:bold;cursor:pointer"><?= $my_is_candidate?'✓ داوطلب اجرای این طرح هستید — لغو داوطلبی':'🙋 اعلام آمادگی برای اجرای این طرح' ?></button>
        <div class="small candidate-count" style="margin-top:5px">تعداد داوطلبان این ایده: <strong><?= $candidate_count ?></strong></div>
        <div class="small candidate-names" style="margin-top:5px;line-height:1.8"><?= $candidate_count > 0 ? 'داوطلبان: ' . htmlspecialchars(implode('، ', $candidate_names), ENT_QUOTES, 'UTF-8') : 'هنوز کسی برای اجرای این طرح داوطلب نشده است.' ?></div>
      </div>
    <?php else: ?><div class="small" style="margin-top:8px;color:#875b13">برای فعال‌شدن داوطلبی، ابتدا فایل migration نامزدی مجری را در پایگاه داده اجرا کنید.</div><?php endif; ?>
  <?php endif; ?>

        <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end">
        <?php if(!$is_finalized): ?>
          <!-- آری / نه -->
          <button class="vote-btn" data-type="yes" data-topic="<?= $tid ?>" data-idea="<?= $iid ?>">آری</button>
          <button class="vote-btn no" data-type="no" data-topic="<?= $tid ?>" data-idea="<?= $iid ?>">نه</button>

          <!-- لازم‌الاجرا / غیر لازم‌الاجرا -->
          <button class="vote-btn" data-type="enforceable" data-value="1" data-topic="<?= $tid ?>" data-idea="<?= $iid ?>">لازم‌الاجرا</button>
          <button class="vote-btn no" data-type="enforceable" data-value="0" data-topic="<?= $tid ?>" data-idea="<?= $iid ?>">غیر لازم‌الاجرا</button>
        <?php else: ?>
          <div class="small">نهایی‌شده</div>
        <?php endif; ?>
        </div>
      </div>
    <?php
        endwhile;
    } // end ideas loop
    ?>
    </div>
    </details>
  </div>
<?php endwhile; $active_res->close(); ?>
  </div>

  <div style="grid-column:1/3;text-align:center;margin-top:10px" class="card">
    <a href="community.php?id=<?= $community_id ?>" class="footer-link">🔙 بازگشت به جامعه</a>
  </div>

</div> <!-- container -->

<script>
// JS برای شمارش معکوس و رأی‌گیری زنده
document.addEventListener('DOMContentLoaded', function(){

    // ---------- توابع ----------
    function startCountdown(el){
        if(el._countdownTimer) clearTimeout(el._countdownTimer);
        let remaining = parseInt(el.dataset.remaining, 10) || 0;
        function tick(){
            if(remaining <= 0){
                el.textContent = "⏰ زمان به پایان رسید";
                el.style.color = "#c0392b";
                if(el._countdownTimer) clearTimeout(el._countdownTimer);
                return;
            }
            const days = Math.floor(remaining / (24*3600));
            const hours = Math.floor((remaining % (24*3600)) / 3600);
            const mins = Math.floor((remaining % 3600) / 60);
            const secs = remaining % 60;
            let txt = "";
            if(days > 0) txt += days + " روز ";
            txt += hours.toString().padStart(2,'0') + ":" + mins.toString().padStart(2,'0') + ":" + secs.toString().padStart(2,'0');
            el.textContent = txt;
            remaining--;
            el.dataset.remaining = remaining;
            el._countdownTimer = setTimeout(tick, 1000);
        }
        tick();
    }

    // شمارش معکوس تمام کارت‌ها
    document.querySelectorAll('.countdown').forEach(startCountdown);

    // ---------- handler برای دکمه‌های رأی ----------
    document.querySelectorAll('.vote-btn').forEach(function(btn){
        btn.addEventListener('click', function(e){
            e.preventDefault();
            const topic = this.dataset.topic;
            const idea  = this.dataset.idea;
            const type  = this.dataset.type;
            const value = this.dataset.value;

            const data = new URLSearchParams();
            data.append('csrf_token', <?= json_encode($votes_csrf_token) ?>);
            data.append('topic_id', topic);
            data.append('idea_id', idea);

            if(type === 'yes' || type === 'no'){
                data.append('vote_yesno', 1);
                data.append('vote_type', type);
            } else if(type === 'enforceable'){
                data.append('vote_enforceable', value);
            }

            fetch('votes.php?community_id=<?= $community_id ?>', {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: data.toString()
            })
            .then(r => r.json())
            .then(resp => {
                if(!resp || !resp.success) return;
                const container = document.querySelector('.idea[data-idea="'+idea+'"]');
                if(resp.counts){
                    container.querySelector('.yes-count').textContent = resp.counts.yes;
                    container.querySelector('.no-count').textContent = resp.counts.no;
                    const total = resp.counts.yes + resp.counts.no;
                    const pct = total > 0 ? Math.round((resp.counts.yes/total)*100) : 0;
                    container.querySelector('.pct').textContent = pct + '%';
                }
                if(resp.enforceable){
                    container.querySelector('.enf-yes').textContent = resp.enforceable.yes;
                    container.querySelector('.enf-no').textContent = resp.enforceable.no;
                }
            })
            .catch(err => console.error(err));
        });
    });

    // ---------- ثبت/لغو داوطلبی اجرای ایده ----------
    document.querySelectorAll('.candidate-btn').forEach(function(btn){btn.addEventListener('click',function(){
        const button=this,data=new URLSearchParams();data.append('csrf_token', <?= json_encode($votes_csrf_token) ?>);data.append('toggle_execution_candidate','1');data.append('topic_id',button.dataset.topic);data.append('idea_id',button.dataset.idea);button.disabled=true;
        fetch('votes.php?community_id=<?= $community_id ?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:data.toString()}).then(r=>r.json()).then(resp=>{
            if(!resp||!resp.success){alert((resp&&resp.message)||'ثبت داوطلبی انجام نشد.');return;}
            button.textContent=resp.is_candidate?'✓ داوطلب اجرای این طرح هستید — لغو داوطلبی':'🙋 اعلام آمادگی برای اجرای این طرح';button.style.background=resp.is_candidate?'#e7a93b':'#dceeff';
            const box=button.closest('.candidate-signup'),count=box?box.querySelector('.candidate-count strong'):null;if(count)count.textContent=resp.candidate_count;
            const namesLabel=box?box.querySelector('.candidate-names'):null;if(namesLabel){namesLabel.textContent=resp.candidate_names&&resp.candidate_names.length?'داوطلبان: '+resp.candidate_names.join('، '):'هنوز کسی برای اجرای این طرح داوطلب نشده است.';}
        }).catch(()=>alert('ارتباط با سرور برقرار نشد.')).finally(()=>{button.disabled=false;});
    });});

    // ---------- فرم فوریت (AJAX) ----------
    document.querySelectorAll('.form-inline').forEach(function(form){
        form.addEventListener('submit', function(e){
            e.preventDefault();
            const data = new URLSearchParams(new FormData(this));
            data.append('csrf_token', <?= json_encode($votes_csrf_token) ?>);
            data.append('set_urgency', 1);

            fetch('votes.php?community_id=<?= $community_id ?>', {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: data.toString()
            }).then(r=>r.json()).then(resp=>{
                if(resp && resp.success){
                    const topicCard = this.closest('.topic');
                    const averageLabel = topicCard ? topicCard.querySelector('.urgency-average') : null;
                    const voteCountLabel = topicCard ? topicCard.querySelector('.urgency-vote-count') : null;
                    const ownStatus = this.querySelector('.urgency-own-status');

                    // میانگین جمعی و تعداد رأی‌های فوریت را بدون بارگذاری مجدد به‌روز کن.
                    if(averageLabel) averageLabel.textContent = resp.urgency;
                    if(voteCountLabel) voteCountLabel.textContent = resp.vote_count;
                    if(ownStatus) ownStatus.textContent = 'انتخاب شما ثبت شد.';

                    const chosenInput = this.querySelector('[name="urgency_days"]');
                    const chosenDays = chosenInput ? chosenInput.value : '';
                    alert("انتخاب شما: " + chosenDays + " روز؛ میانگین فعلی: " + resp.urgency + " روز (" + resp.vote_count + " رأی فوریت)");

                    // اعمال مدت میانگین جدید بدون تغییر زمان شروع رأی‌گیری
                    const countdown = topicCard ? topicCard.querySelector('.countdown') : null;
                    if(countdown){
                        const votingStartedUnix = parseInt(countdown.dataset.start, 10) || Math.floor(Date.now()/1000);
                        const elapsed = Math.max(0, Math.floor(Date.now()/1000) - votingStartedUnix);
                        countdown.dataset.remaining = Math.max(0, resp.urgency*24*3600 - elapsed);
                        if(countdown._countdownTimer) clearTimeout(countdown._countdownTimer);
                        countdown.textContent = '';
                        countdown.style.color = '';
                        startCountdown(countdown);
                    }
                }
            });
        });
    });

});
function toggleIdea(id){
    const box = document.getElementById('idea-box-' + id);
    if(box.style.display === 'none'){
        box.style.display = 'block';
    } else {
        box.style.display = 'none';
    }
}
</script>
<script>
(function(){
const actions=document.getElementById('communityMobileActions');if(!actions)return;
const header=actions.closest('.community-header');
const actionPlaceholder=document.createComment('community-mobile-actions-position');actions.parentNode.insertBefore(actionPlaceholder,actions);
const backLink=document.querySelector('.community-header a[href="profile.php"]');
const backWrap=backLink?backLink.parentElement:null;
const leaveForm=document.querySelector('.community-header form[action="leave_community.php"]');
const members=document.querySelector('.community-header .members-dropdown');
const nodes=[backWrap,leaveForm,members].filter(Boolean),placeholders=new Map();
nodes.forEach(node=>{const ph=document.createComment('community-action-position');node.parentNode.insertBefore(ph,node);placeholders.set(node,ph);});
function arrange(){if(window.matchMedia('(max-width: 720px)').matches){nodes.forEach(node=>actions.appendChild(node));header.appendChild(actions);}else{if(actionPlaceholder.parentNode)actionPlaceholder.parentNode.insertBefore(actions,actionPlaceholder);nodes.forEach(node=>{const ph=placeholders.get(node);if(ph&&ph.parentNode)ph.parentNode.insertBefore(node,ph);});}}
arrange();window.addEventListener('resize',arrange);
})();
</script>
</body>
</html>