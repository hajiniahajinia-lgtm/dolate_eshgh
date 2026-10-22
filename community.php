<?php
session_start();
include __DIR__ . "/db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// مشخصات کاربر
$ust = $conn->prepare("SELECT username, phone, national_id FROM users WHERE id=?");
$ust->bind_param("i", $user_id);
$ust->execute();
$user = $ust->get_result()->fetch_assoc();
$ust->close();

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
  <p><strong>وظایف و رویکردها:</strong> <?=nl2br(htmlspecialchars($community['approach'] ?? 'بدون توضیحات'))?></p>
  <p><strong>تعداد اعضا:</strong> <?= (int)$community['members_count'] ?></p>
  <div class="members-dropdown">
    <button class="members-button">👥 مشاهده اعضا</button>
    <div class="members-list">
      <?php
      $mem_q = $conn->prepare("SELECT users.username, users.age, users.gender, users.education, users.job, users.city, users.resume FROM community_members JOIN users ON users.id = community_members.user_id WHERE community_members.community_id = ?");
      $mem_q->bind_param("i", $community['id']);
      $mem_q->execute();
      $mem_res = $mem_q->get_result();
      if ($mem_res->num_rows > 0) {
          while ($m = $mem_res->fetch_assoc()) {
              $profile_fields = [
                  'سن' => $m['age'] ?? '',
                  'جنسیت' => $m['gender'] ?? '',
                  'تحصیلات' => $m['education'] ?? '',
                  'شغل' => $m['job'] ?? '',
                  'شهر' => $m['city'] ?? '',
                  'معرفی' => $m['resume'] ?? ''
              ];
              echo "<div class='member-entry'>";
              echo "<span class='member-name' tabindex='0'>".htmlspecialchars($m['username'], ENT_QUOTES, 'UTF-8')."</span>";
              echo "<div class='member-profile'>";
              foreach ($profile_fields as $label => $value) {
                  $value = trim((string)$value);
                  if ($value !== '') {
                      echo "<div><strong>".htmlspecialchars($label, ENT_QUOTES, 'UTF-8').":</strong> ".nl2br(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'))."</div>";
                  }
              }
              if (count(array_filter($profile_fields, function($value) { return trim((string)$value) !== ''; })) === 0) {
                  echo "<span style='color:#777'>اطلاعات پروفایل ثبت نشده است.</span>";
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
    <input type="hidden" name="community_id" value="<?= (int)$community['id'] ?>">
    <label>عنوان موضوع*</label>
    <textarea name="topic_title" rows="2" maxlength="100" placeholder="حداکثر 100 کاراکتر..." required></textarea>
    <label for="topic-description">شرح موضوع (اختیاری)</label>
    <textarea id="topic-description" name="topic_description" rows="3" maxlength="500" placeholder="شرح کوتاهی از موضوع بنویسید؛ حداکثر 500 کاراکتر..."></textarea>
    <small style="display:block;color:#666;margin-top:4px;">پر کردن این بخش اختیاری است.</small>
    <button type="submit">✅ ثبت موضوع</button>
  </form>
</div>

<!-- ثبت ایده -->
<div class="card-form idea-form">
  <h3>ثبت ایده</h3>
  <form action="add_idea.php" method="post">
    <input type="hidden" name="community_id" value="<?= (int)$community['id'] ?>">
    <label for="topic-picker-button">انتخاب موضوع*</label>
    <div class="topic-picker" id="topic-picker">
      <input type="hidden" name="topic_id" id="selected-topic-id" value="">
      <button type="button" class="topic-picker-button" id="topic-picker-button" aria-haspopup="listbox" aria-expanded="false">
        <span id="selected-topic-label">انتخاب موضوع...</span>
        <span class="topic-picker-arrow" aria-hidden="true">▾</span>
      </button>
      <div class="topic-picker-list" id="topic-picker-list" role="listbox" aria-label="موضوع‌ها" hidden>
      <?php
      $tq = $conn->prepare("SELECT id,title FROM topics WHERE community_id=? ORDER BY id DESC");
      $tq->bind_param("i",$community['id']);
      $tq->execute();
      $tres = $tq->get_result();
      while($tt = $tres->fetch_assoc()):
      ?>
        <button type="button" class="topic-picker-option" role="option" data-topic-id="<?= (int)$tt['id'] ?>">
          <span class="topic-picker-marker" aria-hidden="true">◆</span>
          <span class="topic-picker-title"><?= htmlspecialchars($tt['title'], ENT_QUOTES, 'UTF-8') ?></span>
        </button>
      <?php endwhile; $tq->close(); ?>
      </div>
      <p class="topic-picker-error" id="topic-picker-error" role="alert" hidden>لطفاً یک موضوع انتخاب کنید.</p>
    </div>
    <label>متن ایده</label>
    <textarea name="idea_text" rows="4" required placeholder="ایده خود را بنویسید..."></textarea>
    <button type="submit">✅ ثبت ایده</button>
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
<div class="box">
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
        ⏱ ثبت شده در: <?= date("Y-m-d H:i", strtotime($t2['created_at'])) ?>
    </div>
    <div class="countdown" data-remaining="<?= $remaining_seconds ?>" style="font-size:13px;color:#555;margin-top:6px;">در حال محاسبه...</div>
    <div class="topic-summary" style="text-align:left;font-size:13px;">
      <div>✅ مجموع اولویت: <?= (int)$t2['total_priority'] ?></div>
      <div>⏱ فوریت: <?= $urgency ?>روز</div>
    </div>
    <div class="topic-actions">
      <form action="toggle_priority.php" method="POST" style="display:inline;">
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
                ⏱ ثبت شده در: <?= date("Y-m-d H:i", strtotime($i['created_at'])) ?>
            </div>
            <?= nl2br(htmlspecialchars($i['content'])) ?>
            <div class="tooltip-box" id="tooltip-<?= (int)$i['uid'] ?>"><p>در حال بارگذاری...</p></div>
        </div>
        <div class="idea-actions" style="display:flex; flex-direction:column; gap:6px; min-width:110px; text-align:right;">
            <a href="give_score.php?user_id=<?= (int)$i['uid'] ?>&redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>" 
               style="display:inline-block; font-size:13px; padding:4px 8px; background:#ff9800; color:#fff; border-radius:5px; text-decoration:none;">
                ⭐  محبوبیت کاربر
            </a>
            <?php if(!$is_closed): ?>
            <form action="update_idea.php" method="post">
                <input type="hidden" name="idea_id" value="<?= (int)$i['id'] ?>">
                <button type="submit" style="background:#27ae60; color:#fff; padding:6px 8px; font-size:13px; border-radius:6px; border:none;">
                    ✅  معقول‌ترین ایده  <strong><?= (int)$i['idea_rationality'] ?></strong>
                </button>
            </form>
            <?php else: ?>
            <span style="background:#27ae60; color:#fff; padding:6px 8px; font-size:13px; border-radius:6px;">
                ✅ معقول‌ترین <strong><?= (int)$i['idea_rationality'] ?></strong>
            </span>
            <?php endif; ?>
        </div>
    </div>
    <?php endwhile; $ideas_q2->close(); ?>

    <?php if(!$is_closed): ?>
    <div class="add-idea" style="margin-top:8px">
  <form action="add_idea.php" method="post">
    <input type="hidden" name="community_id" value="<?= $community['id'] ?>">
    <input type="hidden" name="topic_id" value="<?= $tid ?>">
    <textarea name="idea_text" placeholder="✍️ ثبت ایده جدید..." required></textarea>
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
    // ثبت اولویت و فوریت بدون بارگذاری مجدد صفحه
    document.querySelectorAll('form[action="toggle_priority.php"], form[action="update_urgency.php"]').forEach(function(form) {
        form.addEventListener("submit", async function(event) {
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
                const html = await response.text();
                const parsed = new DOMParser().parseFromString(html, "text/html");
                const topicId = form.querySelector('input[name="topic_id"]')?.value;
                const freshTopic = topicId ? parsed.getElementById("topic-" + topicId) : null;
                const currentTopic = topicId ? document.getElementById("topic-" + topicId) : null;
                if (!freshTopic || !currentTopic) throw new Error("پاسخ صفحه قابل شناسایی نیست");

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
                    let diff = parseInt(freshCountdown.dataset.remaining, 10) || 0;
                    const endTime = Date.now() + diff * 1000;
                    function updateCountdown() {
                        const remaining = Math.max(0, Math.ceil((endTime - Date.now()) / 1000));
                        if (remaining <= 0) {
                            currentCountdown.textContent = "⏰ زمان به پایان رسید";
                            currentCountdown.style.color = "#c0392b";
                            return;
                        }
                        const days = Math.floor(remaining / 86400);
                        const hours = Math.floor((remaining % 86400) / 3600);
                        const minutes = Math.floor((remaining % 3600) / 60);
                        const seconds = remaining % 60;
                        currentCountdown.textContent = (days > 0 ? days + " روز " : "") + hours + " ساعت " + minutes + " دقیقه " + seconds + " ثانیه";
                        setTimeout(updateCountdown, 1000);
                    }
                    updateCountdown();
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
    });

    // ===== جای‌گذاری توضیحات دکمه‌ها خارج از محدوده برش کادرها =====
    document.querySelectorAll('.action-tooltip').forEach(function(wrapper) {
        const tip = wrapper.querySelector('.action-tooltip-text');
        const button = wrapper.querySelector('button');
        if (!tip || !button) return;

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


    // ===== شمارش معکوس =====
    document.querySelectorAll(".countdown").forEach(function(el) {
        let initial = parseInt(el.dataset.remaining, 10) || 0;
        const endTs = Date.now() + initial * 1000;

        function updateOnce() {
            let diff = Math.max(0, Math.ceil((endTs - Date.now()) / 1000));
            if (diff <= 0) {
                el.textContent = "⏰ زمان به پایان رسید";
                el.style.color = "#c0392b";
                return;
            }
            const days = Math.floor(diff / (24*60*60));
            const hours = Math.floor((diff % (24*60*60)) / 3600);
            const minutes = Math.floor((diff % 3600) / 60);
            const seconds = diff % 60;

            let text = "";
            if (days > 0) text += days + " روز ";
            text += hours + " ساعت ";
            text += minutes + " دقیقه ";
            text += seconds + " ثانیه";
            el.textContent = text;

            setTimeout(updateOnce, 1000);
        }
        updateOnce();
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

            fetch('get_user_info.php?user_id=' + encodeURIComponent(userId))
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
</script>
</body>
</html>