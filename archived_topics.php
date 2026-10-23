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
    $execution_candidates=[];$my_is_candidate=false;
    if($status==='winner' && $candidate_tables_ready && !empty($winner_idea['idea_id'])){
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
        $pick=$conn->prepare("SELECT c.user_id,COUNT(v.voter_user_id) AS vote_count,c.volunteered_at FROM topic_execution_candidates c LEFT JOIN topic_execution_candidate_votes v ON v.topic_id=c.topic_id AND v.idea_id=c.idea_id AND v.candidate_user_id=c.user_id WHERE c.topic_id=? AND c.idea_id=? GROUP BY c.user_id,c.volunteered_at ORDER BY vote_count DESC,c.volunteered_at ASC,c.user_id ASC LIMIT 1");$pick->bind_param("ii",$topic_id,$winner_idea_id);$pick->execute();$picked=$pick->get_result()->fetch_assoc();$pick->close();
        if($picked){$final_uid=(int)$picked['user_id'];$fin=$conn->prepare("UPDATE topic_execution_elections SET final_candidate_user_id=?,finalized_at=NOW() WHERE topic_id=? AND idea_id=? AND finalized_at IS NULL AND final_candidate_user_id IS NULL");$fin->bind_param("iii",$final_uid,$topic_id,$winner_idea_id);$fin->execute();$fin->close();$st=$conn->prepare("SELECT finalized_at,final_candidate_user_id FROM topic_execution_elections WHERE topic_id=? AND idea_id=? LIMIT 1");$st->bind_param("ii",$topic_id,$winner_idea_id);$st->execute();$state_row=array_merge($state_row,$st->get_result()->fetch_assoc()?:[]);$st->close();$final_uid=(int)($state_row['final_candidate_user_id']??$final_uid);}
      }
      $final_name=null;if($final_uid){$fn=$conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1");$fn->bind_param("i",$final_uid);$fn->execute();$final_name=$fn->get_result()->fetch_assoc()['username']??('عضو '.$final_uid);$fn->close();}
      $election=['ready'=>true,'started_at'=>$state_row['started_at'],'deadline'=>$deadline,'duration_days'=>$duration,'urgency_average'=>(int)$ur['total']>0?(float)$ur['avg_days']:null,'urgency_vote_count'=>(int)$ur['total'],'my_urgency_days'=>$ur['mine']!==null?(int)$ur['mine']:null,'final_candidate_user_id'=>$final_uid?:null,'finalized_at'=>$state_row['finalized_at']??null,'final_candidate_name'=>$final_name];
      foreach($execution_candidates as &$ec){$ec['election_votes']=0;$ec['my_vote']=false;$ecq=$conn->prepare("SELECT COUNT(*) AS total,COALESCE(MAX(voter_user_id=?),0) AS mine FROM topic_execution_candidate_votes WHERE topic_id=? AND idea_id=? AND candidate_user_id=?");$ecq->bind_param("iiii",$user_id,$topic_id,$winner_idea_id,$ec['user_id']);$ecq->execute();$ecr=$ecq->get_result()->fetch_assoc();$ecq->close();$ec['election_votes']=(int)$ecr['total'];$ec['my_vote']=(int)$ecr['mine']===1;}unset($ec);
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
/* واکنشگرایی */
@media(max-width:900px){
    .columns {flex-direction: column;}
}
</style>
<script>
function toggleIdea(id){
    const box=document.getElementById('idea-box-'+id);
    if(box.style.display==='none'){ box.style.display='block'; }
    else{ box.style.display='none'; }
}
</script>
</head>
<body>
<div class="container">
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

            <?php if($candidate_tables_ready && !empty($row['idea']['idea_id'])): ?>
            <div style="width:100%;margin-top:8px;padding:12px;border:1px solid #a9d4b0;background:#f2fff4;border-radius:10px;box-sizing:border-box">
              <strong>🙋 داوطلبان اجرای طرح برنده</strong>
              <form method="post" style="margin-top:8px"><input type="hidden" name="toggle_execution_candidate" value="1"><input type="hidden" name="topic_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="idea_id" value="<?= (int)$row['idea']['idea_id'] ?>"><button type="submit" style="width:100%;padding:9px;border:0;border-radius:6px;background:<?= $my_is_candidate?'#e7a93b':'#dceeff' ?>;color:#173b63;font-weight:bold;cursor:pointer"><?= $my_is_candidate?'✓ داوطلب اجرای این طرح هستید — لغو داوطلبی':'🙋 اعلام آمادگی برای اجرای این طرح' ?></button></form>
              <div class="meta" style="margin:5px 0 10px"><?= count($row['execution_candidates']) ?> داوطلب. نام داوطلبان زیر نمایش داده می‌شود. محبوبیت و رأی انتخاب مجری جداگانه نمایش داده می‌شوند.</div>
              <?php if(!empty($row['execution_candidates'])): foreach($row['execution_candidates'] as $candidate): ?>
                <div style="padding:9px;margin-top:7px;border:1px solid #d9e7dc;border-radius:8px;background:#fff"><div><strong><?= htmlspecialchars($candidate['username'],ENT_QUOTES,'UTF-8') ?></strong></div>
                  <div class="meta">محبوبیت: <strong><?= number_format($candidate['popularity'],2) ?></strong> از <?= (int)$candidate['popularity_votes'] ?> امتیازدهی</div>
                  <div class="meta">رأی مستقل برای انتخاب مجری: <strong><?= (int)$candidate['election_votes'] ?></strong></div>
                  <form method="post" style="margin-top:7px"><input type="hidden" name="vote_for_candidate" value="1"><input type="hidden" name="topic_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="idea_id" value="<?= (int)$row['idea']['idea_id'] ?>"><input type="hidden" name="candidate_user_id" value="<?= (int)$candidate['user_id'] ?>"><button type="submit" style="width:100%;padding:8px;border:0;border-radius:6px;background:<?= $candidate['my_vote']?'#218838':'#dceeff' ?>;color:<?= $candidate['my_vote']?'#fff':'#173b63' ?>;font-weight:bold;cursor:pointer"><?= $candidate['my_vote']?'✓ رأی شما برای این نامزد ثبت شده':'انتخاب این نامزد به‌عنوان مجری' ?></button></form>
                </div>
              <?php endforeach; else: ?><div class="meta" style="margin-top:8px">هنوز کسی برای اجرای این طرح داوطلب نشده است.</div><?php endif; ?>
              <?php if($row['election']['ready']): ?>
                <div style="margin-top:12px;padding:10px;border:1px solid #c7d7ed;border-radius:8px;background:#f8fbff">
                  <strong>⏳ فوریت و انتخابات نهایی مجری</strong>
                  <div class="meta" style="margin-top:5px">شروع مهلت از ورود ایده به آرشیو: <?= htmlspecialchars($row['election']['started_at'],ENT_QUOTES,'UTF-8') ?></div>
                  <div class="meta">مدت فعلی: <strong><?= (int)$row['election']['duration_days'] ?> روز</strong> (<?= (int)$row['election']['urgency_vote_count'] ?> پیشنهاد فوریت<?= $row['election']['urgency_average']!==null?'؛ میانگین '.number_format($row['election']['urgency_average'],2).' روز':'' ?>)</div>
                  <?php if($row['election']['final_candidate_user_id']): ?>
                    <div style="margin-top:8px;padding:9px;border-radius:7px;background:#dff4e3;color:#175b2b"><strong>🏆 مجری نهایی: <?= htmlspecialchars($row['election']['final_candidate_name']??'نامشخص',ENT_QUOTES,'UTF-8') ?></strong><div class="meta">انتخابات در <?= htmlspecialchars($row['election']['finalized_at']??'',ENT_QUOTES,'UTF-8') ?> نهایی شده است.</div></div>
                  <?php else: ?>
                    <div class="meta" style="margin-top:5px">پایان مهلت: <?= date('Y-m-d H:i:s',(int)$row['election']['deadline']) ?></div>
                    <?php if(time() < (int)$row['election']['deadline']): ?>
                      <form method="post" style="margin-top:8px;display:flex;gap:6px;align-items:center;flex-wrap:wrap"><input type="hidden" name="set_execution_election_urgency" value="1"><input type="hidden" name="topic_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="idea_id" value="<?= (int)$row['idea']['idea_id'] ?>"><label for="urgency-<?= (int)$row['id'] ?>">فوریت پیشنهادی (روز، ۱ تا ۳۰۰):</label><input id="urgency-<?= (int)$row['id'] ?>" type="number" name="urgency_days" min="1" max="300" required value="<?= $row['election']['my_urgency_days']!==null?(int)$row['election']['my_urgency_days']:5 ?>" style="width:85px;padding:7px;border:1px solid #b7c7d9;border-radius:6px"><button type="submit" style="padding:8px 12px;border:0;border-radius:6px;background:#e7b943;color:#4b3500;font-weight:bold;cursor:pointer">ثبت فوریت</button></form>
                    <?php else: ?><div class="meta" style="margin-top:8px;color:#a33">مهلت به پایان رسیده؛ با بارگذاری صفحه انتخابات نهایی می‌شود.</div><?php endif; ?>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
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
                <div class="meta">نهایی‌شده در: <strong><?= htmlspecialchars($row['finalized_at']) ?></strong></div>
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
                <div class="meta">نهایی‌شده در: <strong><?= htmlspecialchars($row['finalized_at']) ?></strong></div>
            </div>
        </div>
        <?php endforeach; else: ?>
        <p>هیچ موضوع بازنده‌ای یافت نشد.</p>
        <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>