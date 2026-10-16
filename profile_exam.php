
<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// دریافت مشخصات کاربر
$ust = $conn->prepare("SELECT username, phone, national_id FROM users WHERE id=?");
$ust->bind_param("i", $user_id);
$ust->execute();
$user = $ust->get_result()->fetch_assoc();
$ust->close();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<title>پروفایل کاربر</title>
<style>
body{font-family:tahoma;background:#eef3f8;margin:0;padding:20px}
.container{max-width:900px;margin:0 auto}
.box{background:#fff;border:1px solid #ccc;border-radius:10px;padding:15px;margin-bottom:18px}
h2,h3{margin-top:0}
input,select,textarea,button{
 width:100%;padding:8px;margin-top:6px;border-radius:6px;border:1px solid #bbb;box-sizing:border-box
}
button{background:#2065d1;color:#fff;cursor:pointer;border:none;margin-top:10px}
a{text-decoration:none;color:#2065d1;font-weight:bold}
</style>
</head>
<body>
<div class="container">

<!-- ✅ مشخصات کاربر -->
<div class="box">
<h2>پروفایل</h2>
<p><strong>نام کاربری:</strong> <?=htmlspecialchars($user['username'])?></p>
<p><strong>شماره همراه:</strong> <?=htmlspecialchars($user['phone'])?></p>
<p><strong>شماره ملی:</strong> <?=htmlspecialchars($user['national_id'])?></p>
</div>

<!-- ✅ جوامع من -->
<div class="box">
<h3>جوامع من</h3>
<?php
$mq = $conn->prepare("
SELECT c.id, c.name, c.approach, c.members_count
FROM community_members m
JOIN communities c ON m.community_id=c.id
WHERE m.user_id=?
ORDER BY c.name
");
$mq->bind_param("i", $user_id);
$mq->execute();
$user_comms = $mq->get_result();
$mq->close();

if($user_comms->num_rows>0): ?>
<ul>
<?php while($uc=$user_comms->fetch_assoc()): ?>
    <li>
        <strong><?=htmlspecialchars($uc['name'])?></strong>
        (تعداد اعضا: <?= (int)$uc['members_count'] ?>)
        — <a href="community.php?id=<?=$uc['id']?>">ورود</a>
    </li>
<?php endwhile; ?>
</ul>
<?php else: ?>
<p>هنوز عضو هیچ جامعه‌ای نیستید.</p>
<?php endif; ?>
</div>

<!-- ✅ عضویت در جامعه جدید -->
<div class="box">
<h3>عضویت در جامعه جدید</h3>
<form action="join_community.php" method="post">
<select name="community_id" required>
<option value="">— انتخاب جامعه —</option>
<?php
// همه جوامع موجود که کاربر هنوز عضو نشده است
$sq = $conn->prepare("
SELECT c.id, c.name
FROM communities c
WHERE c.id NOT IN (
    SELECT community_id FROM community_members WHERE user_id=?
)
ORDER BY c.name
");
$sq->bind_param("i",$user_id);
$sq->execute();
$new_comms = $sq->get_result();
$sq->close();

while($nc=$new_comms->fetch_assoc()):
?>
<option value="<?=$nc['id']?>"><?=htmlspecialchars($nc['name'])?></option>
<?php endwhile; ?>
</select>
<button type="submit">عضویت</button>
</form>
</div>

<!-- ✅ ایجاد جامعه جدید -->
<div class="box">
<h3>ایجاد جامعه جدید</h3>
<form action="create_community.php" method="post">
<label>نام جامعه*</label>
<input type="text" name="community_name" required>

<label>وظایف و رویکردها*</label>
<textarea name="community_approach" required></textarea>

<button type="submit">✅ ایجاد جامعه</button>
</form>
</div>

<div style="text-align:center;margin-top:20px">
<a href="logout.php">🚪 خروج از حساب</a>
</div>

</div>
</body>
</html>