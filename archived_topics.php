<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$user_id = (int)$_SESSION['user_id'];

$community_id = isset($_GET['community_id']) ? (int)$_GET['community_id'] : 0;
if ($community_id <= 0) die("Community not specified.");

// اطلاعات جامعهٔ جاری برای سربرگ کامل صفحهٔ جامعه
$cstmt = $conn->prepare("SELECT * FROM communities WHERE id=? LIMIT 1");
$cstmt->bind_param("i", $community_id);
$cstmt->execute();
$community = $cstmt->get_result()->fetch_assoc();
$cstmt->close();
if (!$community) die("Community not found.");
if (empty($_SESSION['community_duty_csrf'])) { $_SESSION['community_duty_csrf'] = bin2hex(random_bytes(32)); }
$community_duty_csrf = $_SESSION['community_duty_csrf'];
require_once __DIR__ . '/community_member_removal_helpers.php';
$community_removal_ready = community_removal_tables_ready($conn);
if ($community_removal_ready) community_removal_finalize_expired($conn, $community_id);
$arch_count = 0;
$arch_count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM topics t JOIN topic_voting_meta m ON m.topic_id=t.id WHERE t.community_id=? AND m.finalized=1 AND m.finalized_at IS NOT NULL");
if ($arch_count_stmt) { $arch_count_stmt->bind_param("i", $community_id); $arch_count_stmt->execute(); $arch_count = (int)($arch_count_stmt->get_result()->fetch_assoc()['total'] ?? 0); $arch_count_stmt->close(); }

$candidate_tables_ready=false;$election_tables_ready=false;
$tables_check=$conn->query("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('topic_execution_candidates','topic_execution_candidate_votes')");
if($tables_check){$candidate_tables_ready=((int)$tables_check->fetch_assoc()['n']===2);$tables_check->close();}
$election_check=$conn->query("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('topic_execution_elections','topic_execution_election_urgency_votes')");
if($election_check){$election_tables_ready=((int)$election_check->fetch_assoc()['n']===2);$election_check->close();}
$election_message='';
if(isset($_GET['candidate_signup_status'])){if($_GET['candidate_signup_status']==='joined')$election_message='داوطلبی شما برای اجرای طرح برنده ثبت شد.';elseif($_GET['candidate_signup_status']==='cancelled')$election_message='داوطلبی شما لغو شد.';elseif($_GET['candidate_signup_status']==='invalid')$election_message='فقط اعضای جامعه می‌توانند برای اجرای طرح برنده داوطلب شوند.';elseif($_GET['candidate_signup_status']==='missing_schema')$election_message='ساختار داوطلبی هنوز در پایگاه داده نصب نشده است.';}
if(isset($_GET['candidate_vote_status'])){if($_GET['candidate_vote_status']==='saved')$election_message='رأی شما برای انتخاب مجری ثبت شد.';elseif($_GET['candidate_vote_status']==='changed')$election_message='رأی شما به نامزد جدید منتقل شد.';elseif($_GET['candidate_vote_status']==='invalid')$election_message='این رأی معتبر نیست؛ فقط اعضای جامعه می‌توانند برای نامزدهای طرح برنده رأی بدهند.';elseif($_GET['candidate_vote_status']==='missing_schema')$election_message='برای فعال‌شدن انتخابات مجری، فایل migration نامزدی را در پایگاه داده اجرا کنید.';elseif($_GET['candidate_vote_status']==='closed')$election_message='مهلت انتخابات مجری پایان یافته و دیگر نمی‌توان رأی داد.';}
if(isset($_GET['urgency_status'])){if($_GET['urgency_status']==='saved')$election_message='فوریت انتخابات مجری ثبت شد.';elseif($_GET['urgency_status']==='closed')$election_message='مهلت انتخابات پایان یافته است؛ ثبت فوریت دیگر ممکن نیست.';elseif($_GET['urgency_status']==='invalid')$election_message='فقط اعضای جامعه می‌توانند فوریت انتخابات را ثبت کنند.';elseif($_GET['urgency_status']==='missing_schema')$election_message='برای فعال‌شدن فوریت انتخابات، فایل migration انتخابات مجری را در پایگاه داده اجرا کنید.';}
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['toggle_execution_candidate'])){
 if(!$candidate_tables_ready){header('Location: archived_topics.php?community_id='.$community_id.'&candidate_signup_status=missing_schema');exit;}
 $topic_id=(int)($_POST['topic_id']??0);$idea_id=(int)($_POST['idea_id']??0);
 $ms=$conn->prepare("SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1");$ms->bind_param("ii",$community_id,$user_id);$ms->execute();$is_member=(bool)$ms->get_result()->fetch_assoc();$ms->close();
 $ws=$conn->prepare("SELECT 1 FROM topics t JOIN topic_voting_meta m ON m.topic_id=t.id JOIN topic_winners w ON w.topic_id=t.id AND w.idea_id=? WHERE t.id=? AND t.community_id=? AND m.finalized=1 LIMIT 1");$ws->bind_param("iii",$idea_id,$topic_id,$community_id);$ws->execute();$is_winner=(bool)$ws->get_result()->fetch_assoc();$ws->close();
 if(!$is_member||!$is_winner){header('Location: archived_topics.php?community_id='.$community_id.'&candidate_signup_status=invalid');exit;}
 $check=$conn->prepare("SELECT id FROM topic_execution_candidates WHERE topic_id=? AND idea_id=? AND user_id=? LIMIT 1");$check->bind_param("iii",$topic_id,$idea_id,$user_id);$check->execute();$existing=$check->get_result()->fetch_assoc();$check->close();
 if($existing){$del=$conn->prepare("DELETE FROM topic_execution_candidates WHERE id=?");$cid=(int)$existing['id'];$del->bind_param("i",$cid);$del->execute();$del->close();$delvotes=$conn->prepare("DELETE FROM topic_execution_candidate_votes WHERE topic_id=? AND idea_id=? AND candidate_user_id=?");$delvotes->bind_param("iii",$topic_id,$idea_id,$user_id);$delvotes->execute();$delvotes->close();$status='cancelled';}
 else{$ins=$conn->prepare("INSERT INTO topic_execution_candidates (topic_id,idea_id,user_id) VALUES (?,?,?)");$ins->bind_param("iii",$topic_id,$idea_id,$user_id);$ins->execute();$ins->close();$status='joined';}
 header('Location: archived_topics.php?community_id='.$community_id.'&candidate_signup_status='.$status);exit;
}
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['set_execution_election_urgency'])){
 if(!$candidate_tables_ready||!$election_tables_ready){header('Location: archived_topics.php?community_id='.$community_id.'&urgency_status=missing_schema');exit;}
 $topic_id=(int)($_POST['topic_id']??0);$idea_id=(int)($_POST['idea_id']??0);$days=(int)($_POST['urgency_days']??0);
 $ms=$conn->prepare("SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1");$ms->bind_param("ii",$community_id,$user_id);$ms->execute();$is_member=(bool)$ms->get_result()->fetch_assoc();$ms->close();
 $ws=$conn->prepare("SELECT m.finalized_at FROM topics t JOIN topic_voting_meta m ON m.topic_id=t.id JOIN topic_winners w ON w.topic_id=t.id AND w.idea_id=? WHERE t.id=? AND t.community_id=? AND m.finalized=1 LIMIT 1");$ws->bind_param("iii",$idea_id,$topic_id,$community_id);$ws->execute();$archive_row=$ws->get_result()->fetch_assoc();$ws->close();
 if(!$is_member||!$archive_row||$days<1||$days>300){header('Location: archived_topics.php?community_id='.$community_id.'&urgency_status=invalid');exit;}
 $start=$conn->prepare("INSERT IGNORE INTO topic_execution_elections (topic_id,idea_id,started_at) VALUES (?,?,?)");$start->bind_param("iis",$topic_id,$idea_id,$archive_row['finalized_at']);$start->execute();$start->close();
 $state=$conn->prepare("SELECT started_at,finalized_at FROM topic_execution_elections WHERE topic_id=? AND idea_id=? LIMIT 1");$state->bind_param("ii",$topic_id,$idea_id);$state->execute();$state_row=$state->get_result()->fetch_assoc();$state->close();
 $avgq=$conn->prepare("SELECT AVG(urgency_days) AS avg_days,COUNT(*) AS total FROM topic_execution_election_urgency_votes WHERE topic_id=? AND idea_id=?");$avgq->bind_param("ii",$topic_id,$idea_id);$avgq->execute();$avgrow=$avgq->get_result()->fetch_assoc();$avgq->close();$duration=((int)$avgrow['total']>0)?(int)ceil((float)$avgrow['avg_days']):5;$deadline=strtotime($state_row['started_at'])+$duration*86400;
 if(!empty($state_row['finalized_at'])||time()>=$deadline){header('Location: archived_topics.php?community_id='.$community_id.'&urgency_status=closed');exit;}
 $save=$conn->prepare("INSERT INTO topic_execution_election_urgency_votes (topic_id,idea_id,user_id,urgency_days) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE urgency_days=VALUES(urgency_days),updated_at=CURRENT_TIMESTAMP");$save->bind_param("iiii",$topic_id,$idea_id,$user_id,$days);$save->execute();$save->close();
 header('Location: archived_topics.php?community_id='.$community_id.'&urgency_status=saved');exit;
}
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['vote_for_candidate'])){
 if(!$candidate_tables_ready){header('Location: archived_topics.php?community_id='.$community_id.'&candidate_vote_status=missing_schema');exit;}
 $topic_id=(int)($_POST['topic_id']??0);$idea_id=(int)($_POST['idea_id']??0);$candidate_uid=(int)($_POST['candidate_user_id']??0);
 $ms=$conn->prepare("SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1");$ms->bind_param("ii",$community_id,$user_id);$ms->execute();$is_member=(bool)$ms->get_result()->fetch_assoc();$ms->close();
 $ws=$conn->prepare("SELECT 1 FROM topics t JOIN topic_voting_meta m ON m.topic_id=t.id JOIN topic_winners w ON w.topic_id=t.id AND w.idea_id=? WHERE t.id=? AND t.community_id=? AND m.finalized=1 LIMIT 1");$ws->bind_param("iii",$idea_id,$topic_id,$community_id);$ws->execute();$is_winner=(bool)$ws->get_result()->fetch_assoc();$ws->close();
 $cs=$conn->prepare("SELECT 1 FROM topic_execution_candidates WHERE topic_id=? AND idea_id=? AND user_id=? LIMIT 1");$cs->bind_param("iii",$topic_id,$idea_id,$candidate_uid);$cs->execute();$is_candidate=(bool)$cs->get_result()->fetch_assoc();$cs->close();
 if(!$is_member||!$is_winner||!$is_candidate){header('Location: archived_topics.php?community_id='.$community_id.'&candidate_vote_status=invalid');exit;}
 if(!$election_tables_ready){header('Location: archived_topics.php?community_id='.$community_id.'&candidate_vote_status=missing_schema');exit;}
 $startq=$conn->prepare("SELECT m.finalized_at FROM topic_voting_meta m WHERE m.topic_id=? AND m.finalized=1 LIMIT 1");$startq->bind_param("i",$topic_id);$startq->execute();$startrow=$startq->get_result()->fetch_assoc();$startq->close();
 $start=$conn->prepare("INSERT IGNORE INTO topic_execution_elections (topic_id,idea_id,started_at) VALUES (?,?,?)");$start->bind_param("iis",$topic_id,$idea_id,$startrow['finalized_at']);$start->execute();$start->close();
 $state=$conn->prepare("SELECT started_at,finalized_at FROM topic_execution_elections WHERE topic_id=? AND idea_id=? LIMIT 1");$state->bind_param("ii",$topic_id,$idea_id);$state->execute();$state_row=$state->get_result()->fetch_assoc();$state->close();
 $avgq=$conn->prepare("SELECT AVG(urgency_days) AS avg_days,COUNT(*) AS total FROM topic_execution_election_urgency_votes WHERE topic_id=? AND idea_id=?");$avgq->bind_param("ii",$topic_id,$idea_id);$avgq->execute();$avgrow=$avgq->get_result()->fetch_assoc();$avgq->close();$duration=((int)$avgrow['total']>0)?(int)ceil((float)$avgrow['avg_days']):5;$deadline=strtotime($state_row['started_at'])+$duration*86400;
 if(!empty($state_row['finalized_at'])||time()>=$deadline){header('Location: archived_topics.php?community_id='.$community_id.'&candidate_vote_status=closed');exit;}
 $ps=$conn->prepare("SELECT candidate_user_id FROM topic_execution_candidate_votes WHERE topic_id=? AND idea_id=? AND voter_user_id=? LIMIT 1");$ps->bind_param("iii",$topic_id,$idea_id,$user_id);$ps->execute();$prior=$ps->get_result()->fetch_assoc();$ps->close();
 $sv=$conn->prepare("INSERT INTO topic_execution_candidate_votes (topic_id,idea_id,candidate_user_id,voter_user_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE candidate_user_id=VALUES(candidate_user_id),created_at=CURRENT_TIMESTAMP");$sv->bind_param("iiii",$topic_id,$idea_id,$candidate_uid,$user_id);$sv->execute();$sv->close();
 header('Location: archived_topics.php?community_id='.$community_id.'&candidate_vote_status='.($prior?'changed':'saved'));exit;
}

// =====================
// توابع کمکی
// =====================
// تبدیل تاریخ میلادی به شمسی؛ مستقل از افزونه‌های اضافی PHP
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

function archived_gregorian_to_jalali($gy, $gm, $gd) {
    $gdm = [0,31,59,90,120,151,181,212,243,273,304,334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy)
        + (int)(($gy2 + 3) / 4)
        - (int)(($gy2 + 99) / 100)
        + (int)(($gy2 + 399) / 400)
        + $gd + $gdm[$gm - 1];
    $jy = -1595 + 33 * (int)($days / 12053);
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
        $jm = 7 + (int)(($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

function archived_persian_digits($value) {
    return strtr((string)$value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
}

function archived_jalali_datetime($value) {
    if (empty($value)) return '—';
    $timestamp = is_numeric($value) ? (int)$value : strtotime($value);
    if (!$timestamp) return '—';
    $parts = archived_gregorian_to_jalali((int)date('Y', $timestamp), (int)date('n', $timestamp), (int)date('j', $timestamp));
    $formatted = sprintf('%04d/%02d/%02d %s', $parts[0], $parts[1], $parts[2], date('H:i:s', $timestamp));
    return archived_persian_digits($formatted);
}

function get_vote_stats($conn, $topic_id) {
    $stmt = $conn->prepare("
        SELECT 
            SUM(vote_type='yes') AS yes_votes,
            SUM(vote_type='no') AS no_votes,
            COUNT(*) AS total_votes
        FROM topic_voting_ballots
        WHERE topic_id=?
    ");
    if (!$stmt) return ['yes'=>0,'no'=>0,'total'=>0,'pct_yes'=>0];
    $stmt->bind_param("i", $topic_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $yes = (int)($res['yes_votes'] ?? 0);
    $no  = (int)($res['no_votes'] ?? 0);
    $total = (int)($res['total_votes'] ?? 0);
    $pct_yes = $total>0 ? round(($yes/$total)*100,1) : 0;
    return ['yes'=>$yes,'no'=>$no,'total'=>$total,'pct_yes'=>$pct_yes];
}

function participation_percent($conn, $topic_id, $community_id) {
    $r1 = $conn->prepare("SELECT COUNT(*) AS total FROM community_members WHERE community_id=?");
    if (!$r1) return 0;
    $r1->bind_param("i",$community_id);
    $r1->execute();
    $total=(int)$r1->get_result()->fetch_assoc()['total'];
    $r1->close();
    if ($total<=0) return 0;
    $r2 = $conn->prepare("SELECT COUNT(DISTINCT user_id) AS voters FROM topic_voting_ballots WHERE topic_id=?");
    if (!$r2) return 0;
    $r2->bind_param("i",$topic_id);
    $r2->execute();
    $voters=(int)$r2->get_result()->fetch_assoc()['voters'];
    $r2->close();
    return round(($voters/$total)*100,1);
}

function get_enforce_stats($conn, $topic_id) {
    $stmt = $conn->prepare("
        SELECT 
            SUM(enforceable=1) AS enforce_count,
            SUM(enforceable=0) AS non_enforce_count
        FROM topic_enforce_votes
        WHERE topic_id=?
    ");
    if (!$stmt) return ['enforce'=>0,'non_enforce'=>0,'pct_enforce'=>0];
    $stmt->bind_param("i",$topic_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $enforce=(int)($res['enforce_count']??0);
    $non_enforce=(int)($res['non_enforce_count']??0);
    $total=$enforce+$non_enforce;
    $pct_enforce=$total>0 ? round(($enforce/$total)*100,1) : 0;
    return ['enforce'=>$enforce,'non_enforce'=>$non_enforce,'pct_enforce'=>$pct_enforce];
}

function get_winner_idea($conn, $topic_id) {
    $stmt=$conn->prepare("
        SELECT w.idea_id, i.content AS idea_content, u.username AS idea_author
        FROM topic_winners w
        LEFT JOIN ideas i ON i.id=w.idea_id
        LEFT JOIN users u ON u.id=i.user_id
        WHERE w.topic_id=? LIMIT 1
    ");
    if(!$stmt) return ['idea_id'=>null,'idea_content'=>'','idea_author'=>''];
    $stmt->bind_param("i",$topic_id);
    $stmt->execute();
    $res=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if($res) return ['idea_id'=>$res['idea_id'],'idea_content'=>$res['idea_content'],'idea_author'=>$res['idea_author']];

    $stmt=$conn->prepare("
        SELECT tfv.idea_id, i.content AS idea_content, u.username AS idea_author
        FROM topic_final_votes tfv
        LEFT JOIN ideas i ON i.id=tfv.idea_id
        LEFT JOIN users u ON u.id=i.user_id
        WHERE tfv.topic_id=? LIMIT 1
    ");
    if(!$stmt) return ['idea_id'=>null,'idea_content'=>'','idea_author'=>''];
    $stmt->bind_param("i",$topic_id);
    $stmt->execute();
    $res=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res ? ['idea_id'=>$res['idea_id'],'idea_content'=>$res['idea_content'],'idea_author'=>$res['idea_author']] : ['idea_id'=>null,'idea_content'=>'','idea_author'=>''];
}

// =====================
// گرفتن موضوعات نهایی شده
// =====================
$sql = "
    SELECT t.id, t.title, t.description, t.created_at, t.user_id, m.finalized_at, t.community_id
    FROM topics t
    JOIN topic_voting_meta m ON m.topic_id=t.id
    WHERE t.community_id=? AND m.finalized=1 AND m.finalized_at IS NOT NULL
    ORDER BY m.finalized_at DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i",$community_id);
$stmt->execute();
$result=$stmt->get_result();

$winners = [];
$losers = [];

while($row=$result->fetch_assoc()){
    $topic_id=(int)$row['id'];
    $votes=get_vote_stats($conn,$topic_id);
    $participation=participation_percent($conn,$topic_id,(int)$row['community_id']);
    $enforce=get_enforce_stats($conn,$topic_id);
    $winner_idea=get_winner_idea($conn,$topic_id);

    $creator_name='ناشناس';
    if(!empty($row['user_id'])){
        $u_stmt=$conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
        if($u_stmt){
            $u_stmt->bind_param("i",$row['user_id']);
            $u_stmt->execute();
            $urow=$u_stmt->get_result()->fetch_assoc();
            $u_stmt->close();
            $creator_name=$urow['username'] ?? 'ناشناس';
        }
    }

    $status = ($votes['pct_yes']>=50) ? 'winner' : 'loser';
    // انتقال نامزدهای مجری به آرشیو فقط وقتی ایدهٔ برنده اکثریت رأی «لازم‌الاجرا» داشته باشد.
    $winner_idea_is_enforceable=false;
    if(!empty($winner_idea['idea_id'])){
      $winner_idea_id_for_enforce=(int)$winner_idea['idea_id'];
      $enf_stmt=$conn->prepare("SELECT SUM(enforceable=1) AS yes_count, SUM(enforceable=0) AS no_count FROM topic_enforce_votes WHERE topic_id=? AND idea_id=?");
      if($enf_stmt){
        $enf_stmt->bind_param("ii",$topic_id,$winner_idea_id_for_enforce);
        $enf_stmt->execute();
        $enf_row=$enf_stmt->get_result()->fetch_assoc();
        $enf_stmt->close();
        $winner_idea_is_enforceable=(int)($enf_row['yes_count']??0)>(int)($enf_row['no_count']??0);
      }
    }
    $execution_candidates=[];$my_is_candidate=false;
    if($status==='winner' && $winner_idea_is_enforceable && $candidate_tables_ready && !empty($winner_idea['idea_id'])){
      $winner_idea_id=(int)$winner_idea['idea_id'];
      $mine=$conn->prepare("SELECT 1 FROM topic_execution_candidates WHERE topic_id=? AND idea_id=? AND user_id=? LIMIT 1");$mine->bind_param("iii",$topic_id,$winner_idea_id,$user_id);$mine->execute();$my_is_candidate=(bool)$mine->get_result()->fetch_assoc();$mine->close();
      $cl=$conn->prepare("SELECT c.user_id,u.username FROM topic_execution_candidates c JOIN users u ON u.id=c.user_id JOIN community_members cm ON cm.user_id=c.user_id AND cm.community_id=? WHERE c.topic_id=? AND c.idea_id=? ORDER BY u.username ASC");
      $cl->bind_param("iii",$community_id,$topic_id,$winner_idea_id);$cl->execute();$clr=$cl->get_result();
      while($cr=$clr->fetch_assoc()){$uid=(int)$cr['user_id'];
        $ps=$conn->prepare("SELECT COALESCE(AVG((lawfulness+ethics+honesty+executive_skill)/4.0),0) AS score,COUNT(scorer_user_id) AS score_votes FROM user_scores WHERE target_user_id=? AND scorer_user_id<>? AND scorer_user_id IN (SELECT user_id FROM community_members WHERE community_id=?)");$ps->bind_param("iii",$uid,$uid,$community_id);$ps->execute();$pr=$ps->get_result()->fetch_assoc();$ps->close();
        $vs=$conn->prepare("SELECT COUNT(*) AS total,COALESCE(MAX(voter_user_id=?),0) AS mine FROM topic_execution_candidate_votes WHERE topic_id=? AND idea_id=? AND candidate_user_id=?");$vs->bind_param("iiii",$user_id,$topic_id,$winner_idea_id,$uid);$vs->execute();$vr=$vs->get_result()->fetch_assoc();$vs->close();
        $execution_candidates[]=['user_id'=>$uid,'username'=>$cr['username'],'popularity'=>(float)($pr['score']??0),'popularity_votes'=>(int)($pr['score_votes']??0),'election_votes'=>(int)($vr['total']??0),'my_vote'=>(int)($vr['mine']??0)===1];
      }$cl->close();
    }

    $election=['ready'=>false,'started_at'=>$row['finalized_at'],'deadline'=>strtotime($row['finalized_at'])+5*86400,'duration_days'=>5,'urgency_average'=>null,'urgency_vote_count'=>0,'my_urgency_days'=>null,'final_candidate_user_id'=>null,'finalized_at'=>null,'final_candidate_name'=>null];
    if($status==='winner' && $candidate_tables_ready && $election_tables_ready && !empty($winner_idea['idea_id'])){
      $winner_idea_id=(int)$winner_idea['idea_id'];
      $insstate=$conn->prepare("INSERT IGNORE INTO topic_execution_elections (topic_id,idea_id,started_at) VALUES (?,?,?)");$insstate->bind_param("iis",$topic_id,$winner_idea_id,$row['finalized_at']);$insstate->execute();$insstate->close();
      $st=$conn->prepare("SELECT started_at,finalized_at,final_candidate_user_id FROM topic_execution_elections WHERE topic_id=? AND idea_id=? LIMIT 1");$st->bind_param("ii",$topic_id,$winner_idea_id);$st->execute();$state_row=$st->get_result()->fetch_assoc();$st->close();
      $uq=$conn->prepare("SELECT AVG(urgency_days) AS avg_days,COUNT(*) AS total,MAX(CASE WHEN user_id=? THEN urgency_days END) AS mine FROM topic_execution_election_urgency_votes WHERE topic_id=? AND idea_id=?");$uq->bind_param("iii",$user_id,$topic_id,$winner_idea_id);$uq->execute();$ur=$uq->get_result()->fetch_assoc();$uq->close();
      $duration=((int)$ur['total']>0)?(int)ceil((float)$ur['avg_days']):5;$deadline=strtotime($state_row['started_at'])+$duration*86400;
      $final_uid=!empty($state_row['final_candidate_user_id'])?(int)$state_row['final_candidate_user_id']:0;
      if(!$final_uid && time()>=$deadline && count($execution_candidates)>0){
        $pick=$conn->prepare("SELECT c.user_id,COUNT(v.voter_user_id) AS vote_count,c.volunteered_at FROM topic_execution_candidates c JOIN community_members cm ON cm.user_id=c.user_id AND cm.community_id=? LEFT JOIN topic_execution_candidate_votes v ON v.topic_id=c.topic_id AND v.idea_id=c.idea_id AND v.candidate_user_id=c.user_id WHERE c.topic_id=? AND c.idea_id=? GROUP BY c.user_id,c.volunteered_at ORDER BY vote_count DESC,c.volunteered_at ASC,c.user_id ASC LIMIT 1");$pick->bind_param("iii",$community_id,$topic_id,$winner_idea_id);$pick->execute();$picked=$pick->get_result()->fetch_assoc();$pick->close();
        if($picked){$final_uid=(int)$picked['user_id'];$fin=$conn->prepare("UPDATE topic_execution_elections SET final_candidate_user_id=?,finalized_at=NOW() WHERE topic_id=? AND idea_id=? AND finalized_at IS NULL AND final_candidate_user_id IS NULL");$fin->bind_param("iii",$final_uid,$topic_id,$winner_idea_id);$fin->execute();$fin->close();$st=$conn->prepare("SELECT finalized_at,final_candidate_user_id FROM topic_execution_elections WHERE topic_id=? AND idea_id=? LIMIT 1");$st->bind_param("ii",$topic_id,$winner_idea_id);$st->execute();$state_row=array_merge($state_row,$st->get_result()->fetch_assoc()?:[]);$st->close();$final_uid=(int)($state_row['final_candidate_user_id']??$final_uid);}
      }
      $final_name=null;if($final_uid){$fn=$conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1");$fn->bind_param("i",$final_uid);$fn->execute();$final_name=$fn->get_result()->fetch_assoc()['username']??('عضو '.$final_uid);$fn->close();}
      $election=['ready'=>true,'started_at'=>$state_row['started_at'],'deadline'=>$deadline,'duration_days'=>$duration,'urgency_average'=>(int)$ur['total']>0?(float)$ur['avg_days']:null,'urgency_vote_count'=>(int)$ur['total'],'my_urgency_days'=>$ur['mine']!==null?(int)$ur['mine']:null,'final_candidate_user_id'=>$final_uid?:null,'finalized_at'=>$state_row['finalized_at']??null,'final_candidate_name'=>$final_name];
      foreach($execution_candidates as &$ec){$ec['election_votes']=0;$ec['my_vote']=false;$ecq=$conn->prepare("SELECT COUNT(*) AS total,COALESCE(MAX(voter_user_id=?),0) AS mine FROM topic_execution_candidate_votes WHERE topic_id=? AND idea_id=? AND candidate_user_id=?");$ecq->bind_param("iiii",$user_id,$topic_id,$winner_idea_id,$ec['user_id']);$ecq->execute();$ecr=$ecq->get_result()->fetch_assoc();$ecq->close();$ec['election_votes']=(int)$ecr['total'];$ec['my_vote']=(int)$ecr['mine']===1;}unset($ec);
       usort($execution_candidates,static function($a,$b){$byVotes=(int)$b['election_votes']<=>(int)$a['election_votes'];if($byVotes!==0)return $byVotes;$byPopularity=(float)$b['popularity']<=>(float)$a['popularity'];if($byPopularity!==0)return $byPopularity;return strcasecmp((string)$a['username'],(string)$b['username']);});
    }

    $data=[
        'id'=>$topic_id,
        'title'=>$row['title'],
        'description'=>$row['description'],
        'finalized_at'=>$row['finalized_at'],
        'creator'=>$creator_name,
        'votes'=>$votes,
        'participation'=>$participation,
        'enforce'=>$enforce,
        'idea'=>$winner_idea,
        'execution_candidates'=>$execution_candidates,
        'election'=>$election,
        'status'=>$status
    ];

    if($status==='winner') $winners[]=$data;
    else $losers[]=$data;
}
?>

<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>آرشیو موضوعات</title>
<style>
body{font-family:tahoma, Arial, sans-serif;background:#f3f6fa;margin:0;padding:18px}
.container{max-width:1200px;margin:0 auto}
h1{margin:0 0 18px;font-size:22px;text-align:center}
.columns{display:flex;gap:16px;flex-wrap:wrap}
.column{flex:1;min-width:320px;max-height:85vh;overflow-y:auto;padding:10px;border-radius:12px;box-shadow:0 3px 8px rgba(0,0,0,0.1)}
.column.winners{background:#e8f9e8}
.column.losers{background:#ffe5e5}
.column h2{margin-top:0;font-size:20px;text-align:center;font-weight:700;color:#222}
.card{background:#fff;border-radius:12px;padding:14px;box-shadow:0 2px 8px rgba(0,0,0,0.06);display:flex;gap:14px;align-items:flex-start;margin-bottom:12px;flex-direction:column; word-break:break-word; overflow-wrap:break-word; min-width:0;}
.title{font-weight:700;color:#222;font-size:17px;margin-bottom:6px; word-break:break-word;}
.title-wrap{position:relative;display:inline-block;max-width:100%;}
.title-wrap>.title{cursor:help;}
.desc-tooltip{display:none;position:absolute;top:calc(100% + 5px);right:0;width:360px;max-width:calc(100vw - 48px);box-sizing:border-box;padding:10px 12px;background:#fff;color:#333;font-size:14px;line-height:1.9;white-space:pre-wrap;overflow-wrap:anywhere;border:1px solid #9ac7ff;border-radius:8px;box-shadow:0 5px 16px rgba(0,0,0,.18);z-index:1500;font-weight:normal;}
.title-wrap:hover .desc-tooltip,.title-wrap:focus-within .desc-tooltip{display:block;}
.desc{color:#333;font-size:14px;white-space:pre-wrap;margin-bottom:6px; word-break:break-word;}
.votebox{margin-top:6px;padding:10px;background:#fbfeff;border:1px solid #e6f3ff;border-radius:10px;font-size:13px;display:flex;flex-direction:column;gap:5px; word-break:break-word;}
.idea-box{background:#fff9e5;border:1px solid #f5e2b3;border-radius:10px;padding:12px;font-size:14px;color:#222;margin-top:8px; display:none; word-break:break-word;}
.enforce-box{background:#4CAF50;color:#fff;border-radius:4px;padding:3px 8px;margin-top:4px;display:inline-block;font-weight:600;}
.non-enforce-box{background:#FF9800;color:#fff;border-radius:4px;padding:3px 8px;margin-top:2px;display:inline-block;font-weight:600;}
.meta{font-size:13px;color:#555}
.show-hide-btn{margin-top:6px;background:#1976D2;color:#fff;border:none;padding:4px 8px;border-radius:6px;cursor:pointer;font-size:12px;}
.execution-details{width:100%;margin-top:8px;border:1px solid #a9d4b0;background:#f2fff4;border-radius:10px;box-sizing:border-box;overflow:hidden;}
.execution-details>summary{padding:10px 12px;cursor:pointer;font-weight:bold;color:#20572a;list-style-position:inside;}
.execution-details>summary:hover{background:#e5f8e8;}
.execution-details-body{padding:0 12px 12px;}
/* واکنشگرایی */
@media(max-width:900px){
    .columns {flex-direction: column;}
}
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
@media(max-width:720px){.topic-navigation-buttons{display:flex!important;flex-direction:row!important;flex-wrap:nowrap!important;gap:6px!important;width:100%;}.topic-navigation-buttons>a{flex:1 1 0!important;min-width:0!important;width:50%;padding:9px 4px!important;font-size:12px!important;line-height:1.4!important;overflow-wrap:anywhere;white-space:normal;text-align:center;}
.community-header{border-radius:14px;}.community-mobile-actions{display:flex;flex-direction:row;flex-wrap:nowrap;align-items:center;gap:6px;width:100%;margin:8px 0 12px;}.community-mobile-actions>*{flex:1 1 0;min-width:0;max-width:33.333%;margin:0!important;text-align:center!important;}.community-mobile-actions a,.community-mobile-actions form button,.community-mobile-actions .members-button{display:flex;align-items:center;justify-content:center;width:100%;min-height:42px;box-sizing:border-box;padding:7px 3px!important;font-size:10px!important;line-height:1.35;white-space:normal;margin:0!important;}.community-mobile-actions form{display:flex;align-items:center;min-width:0;}.community-mobile-actions .members-dropdown{display:flex;align-items:stretch;min-width:0;}.community-duty-item{align-items:flex-start;flex-direction:column;}.community-duty-vote-form{align-self:flex-start;}}

</style>
<script>
function toggleIdea(id){
    const box=document.getElementById('idea-box-'+id);
    if(box.style.display==='none'){ box.style.display='block'; }
    else{ box.style.display='none'; }
}

function updateElectionCountdowns(){
    const now=Math.floor(Date.now()/1000);
    document.querySelectorAll('.election-countdown').forEach(function(el){
        const deadline=parseInt(el.dataset.deadline,10);
        let remaining=deadline-now;
        if(!Number.isFinite(deadline)||remaining<=0){
            el.textContent='⏳ مهلت به پایان رسیده؛ برای نهایی‌شدن نتیجه صفحه را تازه‌سازی کنید.';
            el.style.background='#ffe5e5';
            el.style.borderColor='#e6aaaa';
            el.style.color='#8b1e1e';
            return;
        }
        const days=Math.floor(remaining/86400); remaining%=86400;
        const hours=Math.floor(remaining/3600); remaining%=3600;
        const minutes=Math.floor(remaining/60); const seconds=remaining%60;
        const fa=function(n){return String(n).replace(/[0-9]/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'[Number(d)];});};
        const parts=[];
        if(days>0) parts.push(fa(days)+' روز');
        parts.push(fa(hours)+' ساعت',fa(minutes)+' دقیقه',fa(seconds)+' ثانیه');
        el.textContent='⏳ زمان باقی‌مانده تا پایان انتخابات: '+parts.join(' و ');
    });
}
document.addEventListener('DOMContentLoaded',function(){
    // بعد از ثبت فرم‌های داوطلبی، رأی یا فوریت، موقعیت صفحه و ستون‌ها حفظ شود.
    const scrollKey='archivedTopicsScroll:'+window.location.pathname+window.location.search;
    try{
        const saved=JSON.parse(sessionStorage.getItem(scrollKey)||'null');
        if(saved){
            sessionStorage.removeItem(scrollKey);
            if(Array.isArray(saved.columns)){
                document.querySelectorAll('.column').forEach(function(col,index){
                    if(saved.columns[index]!==undefined) col.scrollTop=saved.columns[index];
                });
            }
            if(Array.isArray(saved.details)){
                document.querySelectorAll('.execution-details').forEach(function(details,index){
                    if(saved.details[index]) details.open=true;
                });
            }
            window.requestAnimationFrame(function(){
                window.scrollTo(0,Number(saved.windowY)||0);
                window.requestAnimationFrame(function(){
                    if(Array.isArray(saved.columns)){
                        document.querySelectorAll('.column').forEach(function(col,index){
                            if(saved.columns[index]!==undefined) col.scrollTop=saved.columns[index];
                        });
                    }
                });
            });
        }
    }catch(e){}
    document.querySelectorAll('.execution-details form[method="post"]').forEach(function(form){
        form.addEventListener('submit',function(){
            try{
                sessionStorage.setItem(scrollKey,JSON.stringify({
                    windowY:window.scrollY,
                    columns:Array.from(document.querySelectorAll('.column')).map(function(col){return col.scrollTop;}),
                    details:Array.from(document.querySelectorAll('.execution-details')).map(function(details){return details.open;})
                }));
            }catch(e){}
        });
    });
    updateElectionCountdowns();
    window.setInterval(updateElectionCountdowns,1000);
});
</script>
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

  <div class="topic-navigation-buttons" style="display:flex;gap:10px;flex-wrap:nowrap;margin:12px 0 16px;width:100%;box-sizing:border-box;">
    <a href="community.php?id=<?= (int)$community_id ?>" style="display:inline-flex;align-items:center;justify-content:center;flex:1 1 180px;min-height:42px;box-sizing:border-box;background:#4A82DA;color:#fff;text-decoration:none;padding:10px 14px;border-radius:9px;font-weight:bold;text-align:center;">موضوعات در حال بحث</a>
    <a href="votes.php?community_id=<?= (int)$community_id ?>" style="display:inline-flex;align-items:center;justify-content:center;flex:1 1 180px;min-height:42px;box-sizing:border-box;background:#4A82DA;color:#fff;text-decoration:none;padding:10px 14px;border-radius:9px;font-weight:bold;text-align:center;">موضوعات در حال رأی‌گیری</a>
  </div>

  
  <h1>📦 آرشیو موضوعات (نهایی‌شده)</h1>
  <?php if($election_message!==''): ?><div style="margin:0 0 14px;padding:10px;border-radius:8px;background:#fff3cd;color:#664d03;text-align:center"><?= htmlspecialchars($election_message,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>
  <?php if(!$candidate_tables_ready): ?><div style="margin:0 0 14px;padding:10px;border-radius:8px;background:#fff3cd;color:#664d03;text-align:center">برای فعال‌شدن داوطلبی و انتخاب مجری، فایل <strong>migration_execution_candidates.sql</strong> را یک‌بار در پایگاه داده همین سایت اجرا کنید.</div><?php elseif(!$election_tables_ready): ?><div style="margin:0 0 14px;padding:10px;border-radius:8px;background:#fff3cd;color:#664d03;text-align:center">برای فعال‌شدن فوریت و نهایی‌شدن انتخابات مجری، فایل <strong>migration_execution_election_urgency.sql</strong> را یک‌بار و به‌صورت کنترل‌شده در پایگاه داده اجرا کنید.</div><?php endif; ?>
  <div class="columns">
    <div class="column winners">
        <h2>🏆 برنده‌ها</h2>
        <?php if(!empty($winners)): foreach($winners as $row): ?>
        <div class="card">
            <div class="title-wrap">
              <div class="title" tabindex="0"><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php if (trim((string)($row['description'] ?? '')) !== ''): ?>
              <div class="desc-tooltip"><?= nl2br(htmlspecialchars($row['description'], ENT_QUOTES, 'UTF-8')) ?></div>
              <?php endif; ?>
            </div>

            <?php if(!empty($row['idea']['idea_content'])): ?>
            <button class="show-hide-btn" onclick="toggleIdea(<?= $row['id'] ?>)">💡 نمایش/مخفی کردن ایده</button>
            <div class="idea-box" id="idea-box-<?= $row['id'] ?>">
                <?= nl2br(htmlspecialchars($row['idea']['idea_content'])) ?>
                <div class="meta">ایده‌دهنده: <?= htmlspecialchars($row['idea']['idea_author'] ?? 'ناشناس') ?></div>
            </div>
            <?php endif; ?>

            <?php if(($row['election']['final_candidate_user_id'] ?? null) && !empty($row['election']['final_candidate_name'])): ?>
            <div class="selected-executor" style="margin-top:8px;padding:9px 12px;border:1px solid #9bcda5;border-radius:8px;background:#e9f8ec;color:#175b2b">
              <strong>🏆 مجری منتخب این ایده: <?= htmlspecialchars($row['election']['final_candidate_name'],ENT_QUOTES,'UTF-8') ?></strong>
            </div>
            <?php endif; ?>

            <?php if($candidate_tables_ready && !empty($row['idea']['idea_id']) && empty($row['election']['final_candidate_user_id'])): ?>
            <details class="execution-details">
              <summary>🙋 داوطلبان اجرای طرح برنده (<?= count($row['execution_candidates']) ?>)</summary>
              <div class="execution-details-body">
              <form method="post" style="margin-top:8px"><input type="hidden" name="toggle_execution_candidate" value="1"><input type="hidden" name="topic_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="idea_id" value="<?= (int)$row['idea']['idea_id'] ?>"><button type="submit" style="width:100%;padding:9px;border:0;border-radius:6px;background:<?= $my_is_candidate?'#e7a93b':'#dceeff' ?>;color:#173b63;font-weight:bold;cursor:pointer"><?= $my_is_candidate?'✓ داوطلب اجرای این طرح هستید — لغو داوطلبی':'🙋 اعلام آمادگی برای اجرای این طرح' ?></button></form>
              <div class="meta" style="margin:5px 0 10px"><?= count($row['execution_candidates']) ?> داوطلب. نام داوطلبان زیر نمایش داده می‌شود. محبوبیت و رأی انتخاب مجری جداگانه نمایش داده می‌شوند.</div>
              <?php if(!empty($row['execution_candidates'])): foreach($row['execution_candidates'] as $candidate): ?>
                <div style="padding:9px;margin-top:7px;border:1px solid #d9e7dc;border-radius:8px;background:#fff"><div><strong><?= htmlspecialchars($candidate['username'],ENT_QUOTES,'UTF-8') ?></strong></div>
                  <div class="meta">محبوبیت: <strong><?= number_format($candidate['popularity'],2) ?></strong> از <?= (int)$candidate['popularity_votes'] ?> امتیازدهی</div>
                  <div class="meta">رأی مستقل برای انتخاب مجری: <strong><?= (int)$candidate['election_votes'] ?></strong></div>
                  <?php if($row['election']['ready'] && empty($row['election']['final_candidate_user_id']) && time() < (int)$row['election']['deadline']): ?><form method="post" style="margin-top:7px"><input type="hidden" name="vote_for_candidate" value="1"><input type="hidden" name="topic_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="idea_id" value="<?= (int)$row['idea']['idea_id'] ?>"><input type="hidden" name="candidate_user_id" value="<?= (int)$candidate['user_id'] ?>"><button type="submit" style="width:100%;padding:8px;border:0;border-radius:6px;background:<?= $candidate['my_vote']?'#218838':'#dceeff' ?>;color:<?= $candidate['my_vote']?'#fff':'#173b63' ?>;font-weight:bold;cursor:pointer"><?= $candidate['my_vote']?'✓ رأی شما برای این نامزد ثبت شده':'انتخاب این نامزد به‌عنوان مجری' ?></button></form><?php elseif(!$row['election']['ready']): ?><div class="meta" style="margin-top:7px">پس از نصب ساختار فوریت انتخابات، رأی‌گیری فعال می‌شود.</div><?php else: ?><div class="meta" style="margin-top:7px">مهلت رأی‌گیری پایان یافته است.</div><?php endif; ?>
                </div>
              <?php endforeach; else: ?><div class="meta" style="margin-top:8px">هنوز کسی برای اجرای این طرح داوطلب نشده است.</div><?php endif; ?>
              <?php if($row['election']['ready']): ?>
                <div style="margin-top:12px;padding:10px;border:1px solid #c7d7ed;border-radius:8px;background:#f8fbff">
                  <strong>⏳ فوریت و انتخابات نهایی مجری</strong>
                  <div class="meta" style="margin-top:5px">شروع مهلت از ورود ایده به آرشیو: <?= htmlspecialchars(archived_jalali_datetime($row['election']['started_at']),ENT_QUOTES,'UTF-8') ?></div>
                  <div class="meta">مدت فعلی: <strong><?= (int)$row['election']['duration_days'] ?> روز</strong> (<?= (int)$row['election']['urgency_vote_count'] ?> پیشنهاد فوریت<?= $row['election']['urgency_average']!==null?'؛ میانگین '.number_format($row['election']['urgency_average'],2).' روز':'' ?>)</div>
                  <?php if($row['election']['final_candidate_user_id']): ?>
                    <div style="margin-top:8px;padding:9px;border-radius:7px;background:#dff4e3;color:#175b2b"><strong>🏆 مجری نهایی: <?= htmlspecialchars($row['election']['final_candidate_name']??'نامشخص',ENT_QUOTES,'UTF-8') ?></strong><div class="meta">انتخابات در <?= htmlspecialchars(archived_jalali_datetime($row['election']['finalized_at']??''),ENT_QUOTES,'UTF-8') ?> نهایی شده است.</div></div>
                  <?php else: ?>
                    <div class="meta" style="margin-top:5px">پایان مهلت: <?= htmlspecialchars(archived_jalali_datetime((int)$row['election']['deadline']),ENT_QUOTES,'UTF-8') ?></div>
                    <?php if(time() < (int)$row['election']['deadline']): ?>
                      <div class="election-countdown" data-deadline="<?= (int)$row['election']['deadline'] ?>" style="margin-top:8px;padding:9px 11px;border-radius:8px;background:#fff3cd;border:1px solid #f0d98a;color:#654d00;font-weight:bold;text-align:center">⏳ زمان باقی‌مانده تا پایان انتخابات: در حال محاسبه…</div>
                      <form method="post" style="margin-top:8px;display:flex;gap:6px;align-items:center;flex-wrap:wrap"><input type="hidden" name="set_execution_election_urgency" value="1"><input type="hidden" name="topic_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="idea_id" value="<?= (int)$row['idea']['idea_id'] ?>"><label for="urgency-<?= (int)$row['id'] ?>">فوریت پیشنهادی (روز، ۱ تا ۳۰۰):</label><input id="urgency-<?= (int)$row['id'] ?>" type="number" name="urgency_days" min="1" max="300" required value="<?= $row['election']['my_urgency_days']!==null?(int)$row['election']['my_urgency_days']:5 ?>" style="width:85px;padding:7px;border:1px solid #b7c7d9;border-radius:6px"><button type="submit" style="padding:8px 12px;border:0;border-radius:6px;background:#e7b943;color:#4b3500;font-weight:bold;cursor:pointer">ثبت فوریت</button></form>
                    <?php else: ?><div class="meta" style="margin-top:8px;color:#a33">مهلت به پایان رسیده؛ با بارگذاری صفحه انتخابات نهایی می‌شود.</div><?php endif; ?>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
              </div>
            </details>
            <?php endif; ?>

            <div class="votebox">
                <div>کل آرا: <strong><?= (int)$row['votes']['total'] ?></strong></div>
                <div>آری: <strong><?= (int)$row['votes']['yes'] ?></strong></div>
                <div>نه: <strong><?= (int)$row['votes']['no'] ?></strong></div>
                <div>درصد آری: <strong><?= $row['votes']['pct_yes'] ?>%</strong></div>
                <div>مشارکت: <strong><?= $row['participation'] ?>%</strong></div>
                <div>لازم‌الاجرا: <span class="enforce-box"><?= $row['enforce']['enforce'] ?> (<?= $row['enforce']['pct_enforce'] ?>%)</span></div>
                <div>غیر لازم‌الاجرا: <span class="non-enforce-box"><?= $row['enforce']['non_enforce'] ?></span></div>
                <div class="meta">ایجادکننده: <strong><?= htmlspecialchars($row['creator']) ?></strong></div>
                <div class="meta">نهایی‌شده در: <strong><?= htmlspecialchars(archived_jalali_datetime($row['finalized_at']), ENT_QUOTES, 'UTF-8') ?></strong></div>
            </div>
        </div>
        <?php endforeach; else: ?>
        <p>هیچ موضوع برنده‌ای یافت نشد.</p>
        <?php endif; ?>
    </div>

    <div class="column losers">
        <h2>❌ بازنده‌ها</h2>
        <?php if(!empty($losers)): foreach($losers as $row): ?>
        <div class="card">
            <div class="title-wrap">
              <div class="title" tabindex="0"><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php if (trim((string)($row['description'] ?? '')) !== ''): ?>
              <div class="desc-tooltip"><?= nl2br(htmlspecialchars($row['description'], ENT_QUOTES, 'UTF-8')) ?></div>
              <?php endif; ?>
            </div>

            <?php if(!empty($row['idea']['idea_content'])): ?>
            <button class="show-hide-btn" onclick="toggleIdea(<?= $row['id'] ?>)">💡 نمایش/مخفی کردن ایده</button>
            <div class="idea-box" id="idea-box-<?= $row['id'] ?>">
                <?= nl2br(htmlspecialchars($row['idea']['idea_content'])) ?>
                <div class="meta">ایده‌دهنده: <?= htmlspecialchars($row['idea']['idea_author'] ?? 'ناشناس') ?></div>
            </div>
            <?php endif; ?>

            <div class="votebox">
                <div>کل آرا: <strong><?= (int)$row['votes']['total'] ?></strong></div>
                <div>آری: <strong><?= (int)$row['votes']['yes'] ?></strong></div>
                <div>نه: <strong><?= (int)$row['votes']['no'] ?></strong></div>
                <div>درصد آری: <strong><?= $row['votes']['pct_yes'] ?>%</strong></div>
                <div>مشارکت: <strong><?= $row['participation'] ?>%</strong></div>
                <div>لازم‌الاجرا: <span class="enforce-box"><?= $row['enforce']['enforce'] ?> (<?= $row['enforce']['pct_enforce'] ?>%)</span></div>
                <div>غیر لازم‌الاجرا: <span class="non-enforce-box"><?= $row['enforce']['non_enforce'] ?></span></div>
                <div class="meta">ایجادکننده: <strong><?= htmlspecialchars($row['creator']) ?></strong></div>
                <div class="meta">نهایی‌شده در: <strong><?= htmlspecialchars(archived_jalali_datetime($row['finalized_at']), ENT_QUOTES, 'UTF-8') ?></strong></div>
            </div>
        </div>
        <?php endforeach; else: ?>
        <p>هیچ موضوع بازنده‌ای یافت نشد.</p>
        <?php endif; ?>
    </div>
  </div>
</div>
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