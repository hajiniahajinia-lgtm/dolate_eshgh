<?php
// votes.php - یک فایل کامل: PHP + HTML + JS
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
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

// --- پاسخ AJAX برای رأی‌ها ---
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    header('Content-Type: application/json; charset=utf-8');

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
</style>
</head>
<body>
<div class="container">
  <div class="header-box">
    <h2>🗳 مدیریت رأی‌گیری در جامعه «<?= htmlspecialchars($community['name']) ?>»</h2>
    <p class="small">در این صفحه: موضوعات در حال رأی‌گیری (سمت چپ) و پیوند به بایگانی. رأی لازم‌الاجرا اکنون در جدول جداگانه ذخیره و شمارش می‌شود.</p>
  </div>

  <a href="archived_topics.php?community_id=<?= $community_id ?>" style="font-size:14px;color:#2065d1; display:block; margin-bottom:10px;">
   📦 مشاهده موضوعات بایگانی‌شده (<?= $arch_count ?>)
  </a>

  <!-- کارت اول: فعال -->
  <div class="card">
  <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end">
      <h3>موضوعات در حال رأی‌گیری (<?= $active_count ?>)</h3>
      <span class="badge">فعال</span>
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
        SELECT tfv.idea_id, i.content, u.username
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

            // counts
            $yes_count = (int)$conn->query("SELECT COUNT(*) AS c FROM topic_voting_ballots WHERE topic_id=$tid AND idea_id=$iid AND vote_type='yes'")->fetch_assoc()['c'];
            $no_count  = (int)$conn->query("SELECT COUNT(*) AS c FROM topic_voting_ballots WHERE topic_id=$tid AND idea_id=$iid AND vote_type='no'")->fetch_assoc()['c'];

            // counts for enforce votes (per-user table)
            $enf_yes = (int)$conn->query("SELECT COUNT(*) AS c FROM topic_enforce_votes WHERE topic_id=$tid AND idea_id=$iid AND enforceable=1")->fetch_assoc()['c'];
            $enf_no  = (int)$conn->query("SELECT COUNT(*) AS c FROM topic_enforce_votes WHERE topic_id=$tid AND idea_id=$iid AND enforceable=0")->fetch_assoc()['c'];

            $pct = ($yes_count + $no_count) > 0 ? round(($yes_count / ($yes_count + $no_count)) * 100, 2) : 0;
    ?>
     <div class="idea" data-idea="<?= $iid ?>">
  <button class="show-hide-btn" onclick="toggleIdea(<?= $iid ?>)">💡 نمایش/مخفی کردن ایده</button>
  <div class="idea-box" id="idea-box-<?= $iid ?>" style="display:none; margin-top:6px; padding:6px; border:1px solid #f0c86e; border-radius:6px; background:#fff9e5;">
      <strong>👤 <?= htmlspecialchars($iauthor) ?></strong><br>
      <?= nl2br(htmlspecialchars($icontent)) ?>
  </div>

  <div class="small" style="margin-top:6px">
    آری: <strong class="yes-count"><?= $yes_count ?></strong> —
    نه: <strong class="no-count"><?= $no_count ?></strong> —
    درصد آری: <strong class="pct"><?= $pct ?>%</strong><br>
    لازم‌الاجرا: <strong class="enf-yes"><?= $enf_yes ?></strong> —
    غیر لازم‌الاجرا: <strong class="enf-no"><?= $enf_no ?></strong>
  </div>

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

    // ---------- فرم فوریت (AJAX) ----------
    document.querySelectorAll('.form-inline').forEach(function(form){
        form.addEventListener('submit', function(e){
            e.preventDefault();
            const data = new URLSearchParams(new FormData(this));
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
</body>
</html>