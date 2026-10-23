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