<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// دریافت مشخصات کاربر
$ust = $conn->prepare("SELECT username, phone, national_id, gender, education, job, age, city, resume FROM users WHERE id=?");
$ust->bind_param("i", $user_id);
$ust->execute();
$user = $ust->get_result()->fetch_assoc();
$ust->close();

// بروزرسانی پروفایل
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $gender = trim($_POST['gender'] ?? '');
    $education = trim($_POST['education'] ?? '');
    $job = trim($_POST['job'] ?? '');
    $age = intval($_POST['age'] ?? 0);
    $city = trim($_POST['city'] ?? '');
    $resume = trim($_POST['resume'] ?? '');

    $update_stmt = $conn->prepare("UPDATE users SET gender=?, education=?, job=?, age=?, city=?, resume=? WHERE id=?");
    $update_stmt->bind_param("sssissi", $gender, $education, $job, $age, $city, $resume, $user_id);
    $update_stmt->execute();
    $update_stmt->close();

    header("Location: profile.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>پروفایل کاربر</title>
<style>
/* --- کلی --- */
body {font-family:tahoma,sans-serif;background:#eef3f8;margin:0;padding:0;}
.container {max-width:900px;margin:0 auto;padding:10px;}
.box {background:#fff;border:1px solid #ccc;border-radius:10px;padding:15px;margin-bottom:15px;box-sizing:border-box;}
h2,h3 {margin-top:0;}
input, select, textarea, button {width:100%;padding:10px;margin-top:8px;border-radius:6px;border:1px solid #bbb;box-sizing:border-box;font-size:14px;}
button {background:#2065d1;color:#fff;cursor:pointer;border:none;margin-top:10px;font-size:15px;}
button.leave {background:#e63946;}
button.edit {background:#2a9d8f;margin-bottom:10px;}
a {text-decoration:none;color:#2065d1;font-weight:bold;}
/* --- آکاردئون جوامع --- */
.accordion {cursor:pointer;padding:10px;border:1px solid #ccc;border-radius:6px;background:#f8f8f8;margin-bottom:6px;transition:background 0.3s;font-weight:bold;font-size:14px;}
.accordion:hover {background:#e7e7e7;}
.accordion.active {background:#d4f7d4;}
.panel {padding:10px 12px;display:none;border:1px solid #ccc;border-radius:6px;margin-bottom:8px;background:#fffbe6;font-size:14px;}
.panel form {display:inline-block;margin-right:8px;}
.edit-form {display:none;margin-top:10px;}
.communities-box {max-height:400px;overflow-y:auto;border:1px solid #ccc;border-radius:10px;padding:8px;background:#fdfdfd;}

/* --- درخت جوامع --- */
.community-tree{list-style:none;margin:0;padding:0;}
.community-tree ul{list-style:none;margin:7px 0 7px 0;padding-right:22px;border-right:2px solid #d8e2ef;}
.community-tree-item{margin:7px 0;}
.community-tree-link{display:flex;align-items:center;gap:8px;padding:9px 11px;border:1px solid #dce5ef;border-radius:7px;background:#fbfdff;color:#263b53;font-weight:normal;}
.community-tree-link:hover{background:#edf5ff;}
.community-tree-root>.community-tree-link{background:#f0f6ff;font-weight:bold;border-color:#b9d1ee;}
.community-tree-icon{flex:0 0 auto;color:#b88719;}
.community-tree-empty{color:#667085;font-size:14px;}

/* --- واکنشگرایی --- */
@media (max-width:600px){
    .container{padding:8px;}
    input, select, textarea, button{font-size:16px;padding:12px;}
    h2,h3{font-size:18px;}
    .accordion{font-size:16px;}
}
</style>
<script>
function toggleEditForm() {
    const form = document.getElementById('editForm');
    form.style.display = (form.style.display === 'block') ? 'none' : 'block';
}
function toggleCommunityTree() {
    const panel = document.getElementById('communityTreePanel');
    const button = document.getElementById('communityTreeToggle');
    const isOpen = panel.style.display === 'block';
    panel.style.display = isOpen ? 'none' : 'block';
    button.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
    button.textContent = isOpen ? '🌳 نمایش درخت جوامع' : '🌳 بستن درخت جوامع';
}
document.addEventListener("DOMContentLoaded", function() {
    const acc = document.getElementsByClassName("accordion");
    for (let i=0;i<acc.length;i++){
        acc[i].addEventListener("click",function(){
            this.classList.toggle("active");
            const panel = this.nextElementSibling;
            panel.style.display = (panel.style.display==="block")?"none":"block";
        });
    }
});
</script>
</head>
<body>
<div class="container">

<!-- پروفایل -->
<div class="box">
<h2>پروفایل</h2>
<button type="button" class="edit" onclick="toggleEditForm()">تکمیل/ویرایش پروفایل</button>
<div id="editForm" class="edit-form">
<form method="post" action="">
<input type="hidden" name="update_profile" value="1">

<label>جنسیت</label>
<select name="gender">
<option value="" <?= $user['gender']==''?'selected':'' ?>>انتخاب کنید</option>
<option value="مرد" <?= $user['gender']=='مرد'?'selected':'' ?>>مرد</option>
<option value="زن" <?= $user['gender']=='زن'?'selected':'' ?>>زن</option>
<option value="دیگر" <?= $user['gender']=='دیگر'?'selected':'' ?>>دیگر</option>
</select>

<label>تحصیلات</label>
<select name="education">
<option value="" <?= $user['education']==''?'selected':'' ?>>انتخاب کنید</option>
<option value="دیپلم" <?= $user['education']=='دیپلم'?'selected':'' ?>>دیپلم</option>
<option value="کارشناسی" <?= $user['education']=='کارشناسی'?'selected':'' ?>>کارشناسی</option>
<option value="کارشناسی ارشد" <?= $user['education']=='کارشناسی ارشد'?'selected':'' ?>>کارشناسی ارشد</option>
<option value="دکتری" <?= $user['education']=='دکتری'?'selected':'' ?>>دکتری</option>
</select>

<label>شغل</label>
<input type="text" name="job" value="<?= htmlspecialchars($user['job']) ?>" placeholder="مثال: مهندس نرم‌افزار">

<label>سن</label>
<input type="number" name="age" value="<?= (int)$user['age'] ?>" min="10" max="120" placeholder="مثال: 30">

<label>شهر</label>
<input type="text" name="city" value="<?= htmlspecialchars($user['city']) ?>" placeholder="مثال: تهران">

<label>رزومه</label>
<textarea name="resume" rows="6" maxlength="3000" placeholder="حداکثر 500 کلمه"><?= htmlspecialchars($user['resume']) ?></textarea>

<button type="submit">ذخیره تغییرات</button>
</form>
</div>

<p><strong>نام کاربری:</strong> <?= htmlspecialchars($user['username']) ?></p>
<p><strong>شماره همراه:</strong> <?= htmlspecialchars($user['phone']) ?></p>
<p><strong>شماره ملی:</strong> <?= htmlspecialchars($user['national_id']) ?></p>
<?php if($user['gender']): ?><p><strong>جنسیت:</strong> <?= htmlspecialchars($user['gender']) ?></p><?php endif; ?>
<?php if($user['education']): ?><p><strong>تحصیلات:</strong> <?= htmlspecialchars($user['education']) ?></p><?php endif; ?>
<?php if($user['job']): ?><p><strong>شغل:</strong> <?= htmlspecialchars($user['job']) ?></p><?php endif; ?>
<?php if($user['age']): ?><p><strong>سن:</strong> <?= (int)$user['age'] ?></p><?php endif; ?>
<?php if($user['city']): ?><p><strong>شهر:</strong> <?= htmlspecialchars($user['city']) ?></p><?php endif; ?>
<?php if($user['resume']): ?><p><strong>رزومه:</strong> <?= nl2br(htmlspecialchars($user['resume'])) ?></p><?php endif; ?>
</div>

<!-- درخت ساختار همه جوامع؛ بسته به‌صورت پیش‌فرض برای صرفه‌جویی در فضا -->
<div class="box">
<button type="button" id="communityTreeToggle" class="tree-toggle" aria-expanded="false" aria-controls="communityTreePanel" onclick="toggleCommunityTree()">🌳 نمایش درخت جوامع</button>
<div id="communityTreePanel" style="display:none">
<p style="font-size:13px;color:#667085;line-height:1.9">جوامع مستقل در سطح اصلی و جوامع زیرمجموعه در زیر جامعهٔ مادر نمایش داده می‌شوند. برای ورود به هر جامعه، روی نام آن بزنید.</p>
<div class="communities-box">
<?php
$tree_result = $conn->query("SELECT id, name, parent_community_id FROM communities ORDER BY name");
$tree_rows = [];
$tree_children = [];
if ($tree_result) {
    while ($tr = $tree_result->fetch_assoc()) {
        $tr['id'] = (int)$tr['id'];
        $tr['parent_community_id'] = ($tr['parent_community_id'] === null) ? null : (int)$tr['parent_community_id'];
        $tree_rows[$tr['id']] = $tr;
    }
    $tree_result->free();
}
foreach ($tree_rows as $tr) {
    $parent_id = $tr['parent_community_id'];
    if ($parent_id !== null && isset($tree_rows[$parent_id]) && $parent_id !== $tr['id']) {
        $tree_children[$parent_id][] = $tr;
    } else {
        $tree_children[0][] = $tr;
    }
}
$render_community_tree = function($parent_id, $depth = 0, $ancestors = []) use (&$render_community_tree, &$tree_children, &$tree_rows) {
    $items = $tree_children[$parent_id] ?? [];
    if (!$items) return;
    echo '<ul class="community-tree' . ($depth === 0 ? ' community-tree-roots' : '') . '">';
    foreach ($items as $item) {
        $id = (int)$item['id'];
        if (isset($ancestors[$id])) continue;
        $next_ancestors = $ancestors;
        $next_ancestors[$id] = true;
        $has_children = !empty($tree_children[$id]);
        echo '<li class="community-tree-item' . ($depth === 0 ? ' community-tree-root' : '') . '">';
        echo '<a class="community-tree-link" href="community.php?id=' . $id . '">';
        echo '<span class="community-tree-icon" aria-hidden="true">' . ($has_children ? '▣' : '▪') . '</span>';
        echo '<span>' . htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') . '</span>';
        echo '</a>';
        if ($has_children) $render_community_tree($id, $depth + 1, $next_ancestors);
        echo '</li>';
    }
    echo '</ul>';
};
if (empty($tree_rows)) {
    echo '<p class="community-tree-empty">هنوز جامعه‌ای ایجاد نشده است.</p>';
} else {
    $render_community_tree(0);
}
?>
</div>
</div>
</div>

<!-- جوامع من -->
<div class="box">
<h3>جوامع من</h3>
<div class="communities-box">
<?php
$mq = $conn->prepare("
SELECT c.id, c.name, c.approach, c.members_count
FROM community_members m
JOIN communities c ON m.community_id=c.id
WHERE m.user_id=?
ORDER BY c.name
");
$mq->bind_param("i",$user_id);
$mq->execute();
$user_comms = $mq->get_result();
$mq->close();

if($user_comms->num_rows>0):
    while($uc=$user_comms->fetch_assoc()):
?>
<div class="accordion"><?= htmlspecialchars($uc['name']) ?></div>
<div class="panel">
<p><strong>وظایف و رویکردها:</strong> <?= htmlspecialchars($uc['approach']) ?></p>
<p><strong>تعداد اعضا:</strong> <?= (int)$uc['members_count'] ?></p>
<p>
<a href="community.php?id=<?= $uc['id'] ?>">ورود به جامعه</a>
<form action="leave_community.php" method="post" onsubmit="return confirm('آیا مطمئن هستید؟');">
<input type="hidden" name="community_id" value="<?= $uc['id'] ?>">
<button type="submit" class="leave">🚫 ترک جامعه</button>
</form>
</p>
</div>
<?php endwhile; else: ?>
<p>هنوز عضو هیچ جامعه‌ای نیستید.</p>
<?php endif; ?>
</div>
</div>

<!-- عضویت در جوامع موجود -->
<div class="box">
<h3>عضویت در جامعه‌های موجود</h3>
<form action="join_community.php" method="post">
<select name="community_id" required>
<option value="">— انتخاب جامعه —</option>
<?php
$sq=$conn->prepare("
SELECT c.id, c.name, c.parent_community_id, p.name AS parent_name
FROM communities c
LEFT JOIN communities p ON p.id = c.parent_community_id
WHERE c.id NOT IN (SELECT community_id FROM community_members WHERE user_id=?)
ORDER BY COALESCE(p.name, c.name), (c.parent_community_id IS NOT NULL), c.name
");
$sq->bind_param("i",$user_id);
$sq->execute();
$new_comms=$sq->get_result();
$sq->close();
while($nc=$new_comms->fetch_assoc()):
?>
<option value="<?= (int)$nc['id'] ?>"><?= !empty($nc['parent_community_id']) ? '　↳ ' . htmlspecialchars($nc['name']) . ' — زیرمجموعهٔ ' . htmlspecialchars($nc['parent_name']) : htmlspecialchars($nc['name']) . ' — جامعهٔ مستقل' ?></option>
<?php endwhile; ?>
</select>
<button type="submit">عضویت</button>
</form>
</div>

<!-- ایجاد جامعه جدید -->
<div class="box">
<h3>ایجاد جامعه جدید</h3>
<form action="create_community.php" method="post">
<label>نام جامعه*</label>
<input type="text" name="community_name" required>

<label>وظایف و رویکردها*</label>
<textarea name="community_approach" required></textarea>

<label>
<input type="checkbox" name="join_now" value="1"> همین الان عضو شوم
</label>

<button type="submit">✅ ایجاد جامعه</button>
</form>
</div>

<div style="text-align:center;margin-top:20px;">
<a href="logout.php">🚪 خروج از حساب</a>
</div>

</div>
</body>
</html>