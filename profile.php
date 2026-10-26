<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
if (empty($_SESSION['community_duty_csrf'])) {
    $_SESSION['community_duty_csrf'] = bin2hex(random_bytes(32));
}
$community_duty_csrf = $_SESSION['community_duty_csrf'];

if (empty($_SESSION['community_hierarchy_csrf'])) {
    $_SESSION['community_hierarchy_csrf'] = bin2hex(random_bytes(32));
}
$community_hierarchy_csrf = $_SESSION['community_hierarchy_csrf'];

// دریافت مشخصات کاربر
$ust = $conn->prepare("SELECT username, phone, national_id, gender, education, job, age, city, resume FROM users WHERE id=?");
$ust->bind_param("i", $user_id);
$ust->execute();
$user = $ust->get_result()->fetch_assoc();
$ust->close();

// بروزرسانی پروفایل
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (
        empty($_SESSION['community_duty_csrf']) ||
        !isset($_POST['csrf_token']) ||
        !is_string($_POST['csrf_token']) ||
        !hash_equals($_SESSION['community_duty_csrf'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit('درخواست معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.');
    }

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
.community-action {display:flex;align-items:center;justify-content:center;width:100%;height:44px;min-height:44px;padding:0 10px;margin-top:10px;border:0;border-radius:6px;box-sizing:border-box;font-family:tahoma,sans-serif;font-size:15px;font-weight:bold;line-height:1;text-align:center;}
.enter-community {background:#7edb95;color:#124d25;cursor:pointer;}
.enter-community:hover {background:#68cf82;}
.community-management {margin-bottom:15px;}
.community-management > summary {display:block;cursor:pointer;list-style:none;padding:12px 14px;border-radius:8px;background:#2065d1;color:#fff;font-weight:bold;text-align:center;}
.community-management > summary::-webkit-details-marker {display:none;}
.community-management[open] > summary {margin-bottom:12px;}
.community-management .box {margin-bottom:10px;}
.community-select-native {position:absolute !important;width:1px !important;height:1px !important;opacity:0 !important;pointer-events:none !important;}
.community-picker {position:relative;width:100%;margin-top:8px;}
.community-picker-toggle {display:flex;justify-content:space-between;align-items:center;width:100%;padding:12px;background:#fff;color:#333;border:1px solid #bbb;border-radius:6px;text-align:right;}
.community-picker-list {position:absolute;z-index:20;top:100%;right:0;left:0;max-height:280px;overflow-y:auto;background:#fff;border:1px solid #bbb;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.12);}
.community-picker-option {display:flex;align-items:baseline;gap:10px;width:100%;padding:10px;text-align:right;background:#fff;color:#333;border-bottom:1px solid #eee;border-radius:0;margin:0;}
.community-picker-option:hover {background:#f4f7fb;}
.community-picker-option .community-name {font-size:17px;font-weight:bold;}
.community-picker-option .community-kind {font-size:12px;font-weight:normal;white-space:nowrap;}
.community-picker-option.independent-community .community-name,.community-picker-option.independent-community .community-kind {color:#d97706;}
.community-picker-option.child-community .community-name,.community-picker-option.child-community .community-kind {color:#1769c2;}
.leave-community-form {display:block !important;width:100%;margin:0 !important;padding:0;}
.leave-community-form button.leave {display:flex;width:100%;height:44px;min-height:44px;padding:0 10px;margin-top:10px;align-items:center;justify-content:center;box-sizing:border-box;line-height:1;}
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
    .community-action{height:44px;min-height:44px;font-size:16px;padding:0 10px;}
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
    const communitySelect = document.getElementById("joinCommunitySelect");
    const pickerToggle = document.getElementById("communityPickerToggle");
    const pickerList = document.getElementById("communityPickerList");
    if (communitySelect && pickerToggle && pickerList) {
        pickerToggle.addEventListener("click", function() {
            pickerList.hidden = !pickerList.hidden;
        });
        pickerList.querySelectorAll(".community-picker-option").forEach(function(option) {
            option.addEventListener("click", function() {
                communitySelect.value = this.dataset.value;
                pickerToggle.textContent = this.querySelector(".community-name").textContent.replace(/^□\\s*/, "");
                pickerList.hidden = true;
            });
        });
        document.addEventListener("click", function(event) {
            if (!event.target.closest("#communityPicker")) pickerList.hidden = true;
        });
    }
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
<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
<h2 style="margin:0;">پروفایل</h2>
<a href="search.php" style="display:inline-flex;align-items:center;justify-content:center;gap:6px;background:#1769aa;color:#fff;text-decoration:none;padding:9px 13px;border-radius:8px;font-size:14px;font-weight:bold;white-space:nowrap;">🔎 سرچ</a>
</div>
<button type="button" class="edit" onclick="toggleEditForm()">تکمیل/ویرایش پروفایل</button>
<div id="editForm" class="edit-form">
<form method="post" action="">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($community_duty_csrf, ENT_QUOTES, 'UTF-8') ?>">
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

<!-- رأی‌گیری عمومی دربارهٔ جایگاه جوامع -->
<div class="box" id="community-parent-voting">
<details>
<summary style="display:block;cursor:pointer;background:#2065d1;color:#fff;border-radius:7px;padding:12px;text-align:center;font-weight:bold;list-style:none;">🗳️ رأی‌گیری دربارهٔ جایگاه جوامع</summary>
<div style="padding-top:10px;">
<p style="font-size:13px;line-height:1.9;color:#596579;">هر کاربر می‌تواند برای هر جامعه یک رأی ثبت کند: جامعهٔ مستقل یا زیرمجموعهٔ یک جامعهٔ دیگر. هر رأی‌دهنده می‌تواند نظرش را تغییر دهد. جایگاه برنده فقط وقتی تغییر می‌کند که یک گزینه بیشترین رأی یکتا را داشته باشد؛ در تساوی، جایگاه فعلی حفظ می‌شود.</p>
<?php
$hierarchy_schema_check = $conn->query("SHOW TABLES LIKE 'community_parent_votes'");
$hierarchy_votes_ready = $hierarchy_schema_check && $hierarchy_schema_check->num_rows > 0;
if ($hierarchy_schema_check) $hierarchy_schema_check->close();

$hierarchy_communities = [];
$hierarchy_parent_map = [];
$hierarchy_vote_counts = [];
$hierarchy_user_votes = [];
if ($hierarchy_votes_ready) {
    $hres = $conn->query("SELECT id, name, parent_community_id FROM communities ORDER BY name");
    if ($hres) {
        while ($hr = $hres->fetch_assoc()) {
            $hr['id'] = (int)$hr['id'];
            $hr['parent_community_id'] = $hr['parent_community_id'] === null ? 0 : (int)$hr['parent_community_id'];
            $hierarchy_communities[] = $hr;
            $hierarchy_parent_map[$hr['id']] = $hr['parent_community_id'];
        }
        $hres->free();
    }
    $vc = $conn->query("SELECT community_id, parent_community_id, COUNT(*) AS vote_count FROM community_parent_votes GROUP BY community_id, parent_community_id");
    if ($vc) {
        while ($vr = $vc->fetch_assoc()) {
            $cid = (int)$vr['community_id'];
            $pid = $vr['parent_community_id'] === null ? 0 : (int)$vr['parent_community_id'];
            $hierarchy_vote_counts[$cid][$pid] = (int)$vr['vote_count'];
        }
        $vc->free();
    }
    $uv = $conn->prepare("SELECT community_id, parent_community_id FROM community_parent_votes WHERE user_id=?");
    $uv->bind_param("i", $user_id);
    $uv->execute();
    $uvr = $uv->get_result();
    while ($vr = $uvr->fetch_assoc()) {
        $hierarchy_user_votes[(int)$vr['community_id']] = $vr['parent_community_id'] === null ? 0 : (int)$vr['parent_community_id'];
    }
    $uv->close();
}
?>
<?php if (!$hierarchy_votes_ready): ?>
<p style="font-size:13px;line-height:1.9;color:#b42318;">برای فعال‌شدن رأی‌گیری، فایل <strong>community_parent_votes.sql</strong> را یک‌بار در پایگاه دادهٔ هاست اجرا کنید. این فایل فقط جدول رأی‌ها را اضافه می‌کند و داده‌های موجود را حذف نمی‌کند.</p>
<?php elseif (empty($hierarchy_communities)): ?>
<p>هنوز جامعه‌ای برای رأی‌گیری وجود ندارد.</p>
<?php else: ?>
<?php if (($_GET['hierarchy_status'] ?? '') === 'voted'): ?>
<p style="color:#18794e;font-size:13px;">رأی شما ثبت شد. جایگاه جامعه بر اساس بیشترین رأی یکتا به‌روزرسانی می‌شود.</p>
<?php elseif (($_GET['hierarchy_status'] ?? '') === 'cycle'): ?>
<p style="color:#b42318;font-size:13px;">این گزینه می‌تواند چرخه در ساختار جوامع ایجاد کند و قابل رأی‌دادن نیست.</p>
<?php elseif (($_GET['hierarchy_status'] ?? '') === 'schema'): ?>
<p style="color:#b42318;font-size:13px;">جدول رأی‌گیری هنوز در پایگاه داده آماده نیست.</p>
<?php endif; ?>
<?php foreach ($hierarchy_communities as $hc): ?>
<?php
$cid = (int)$hc['id'];
$counts_for_community = $hierarchy_vote_counts[$cid] ?? [];
$total_community_votes = array_sum($counts_for_community);
$current_vote = array_key_exists($cid, $hierarchy_user_votes) ? $hierarchy_user_votes[$cid] : $hc['parent_community_id'];
$current_parent_id = $hc['parent_community_id'];
$current_parent_name = 'مستقل';
foreach ($hierarchy_communities as $candidate_row) {
    if ((int)$candidate_row['id'] === $current_parent_id && $current_parent_id > 0) {
        $current_parent_name = $candidate_row['name'];
        break;
    }
}
?>
<div style="border:1px solid #dce5ef;border-radius:8px;padding:10px;margin:10px 0;background:#fbfdff;">
<strong><?= htmlspecialchars($hc['name'], ENT_QUOTES, 'UTF-8') ?></strong>
<div style="font-size:12px;color:#596579;margin:4px 0 8px;line-height:1.8;">
جایگاه فعلی: <strong><?= htmlspecialchars($current_parent_name, ENT_QUOTES, 'UTF-8') ?></strong>
— مجموع رأی‌ها: <?= $total_community_votes ?>
</div>
<form method="post" action="set_community_parent.php" style="margin:0;">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($community_hierarchy_csrf, ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="community_id" value="<?= $cid ?>">
<input type="hidden" name="return_to" value="profile">
<label style="font-size:13px;display:block;">نظر شما دربارهٔ جایگاه این جامعه</label>
<select name="parent_community_id" required>
<option value="0" <?= $current_vote === 0 ? 'selected' : '' ?>>جامعهٔ مستقل (<?= (int)($counts_for_community[0] ?? 0) ?> رأی)</option>
<?php foreach ($hierarchy_communities as $candidate): ?>
<?php
$pid = (int)$candidate['id'];
if ($pid === $cid) continue;
$is_descendant = false;
$cursor = $pid;
$seen = [];
while ($cursor > 0 && isset($hierarchy_parent_map[$cursor]) && !isset($seen[$cursor])) {
    if ($cursor === $cid) { $is_descendant = true; break; }
    $seen[$cursor] = true;
    $cursor = (int)$hierarchy_parent_map[$cursor];
}
if ($is_descendant) continue;
?>
<option value="<?= $pid ?>" <?= $current_vote === $pid ? 'selected' : '' ?>>زیرمجموعهٔ <?= htmlspecialchars($candidate['name'], ENT_QUOTES, 'UTF-8') ?> (<?= (int)($counts_for_community[$pid] ?? 0) ?> رأی)</option>
<?php endforeach; ?>
</select>
<button type="submit" style="margin-top:7px;background:#facc15;color:#3b2f00;">ثبت / تغییر رأی</button>
</form>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
</details>
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

<!-- جوامع من؛ بسته به‌صورت پیش‌فرض برای صرفه‌جویی در فضا -->
<div class="box" id="my-communities">
<details>
<summary style="display:block;cursor:pointer;background:#2065d1;color:#fff;border-radius:7px;padding:12px;text-align:center;font-weight:bold;list-style:none;">👥 جوامع من</summary>
<div style="padding-top:10px;">
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

// نمایش پیشنهاد دارای بیشترین رأی معقولیت در بخش جوامع من.
$duties_tables_ready = false;
$duties_table_check = $conn->query("SHOW TABLES LIKE 'community_duties'");
$duty_votes_table_check = $conn->query("SHOW TABLES LIKE 'community_duty_votes'");
if ($duties_table_check && $duty_votes_table_check) {
    $duties_tables_ready = ($duties_table_check->num_rows > 0 && $duty_votes_table_check->num_rows > 0);
}
if ($duties_table_check) $duties_table_check->close();
if ($duty_votes_table_check) $duty_votes_table_check->close();

if($user_comms->num_rows>0):
    while($uc=$user_comms->fetch_assoc()):
        $top_duty = null;
        if ($duties_tables_ready) {
            $top_q = $conn->prepare("
                SELECT d.id, d.content, d.created_at, COUNT(v.user_id) AS vote_count
                FROM community_duties d
                LEFT JOIN community_duty_votes v ON v.proposal_id=d.id AND v.community_id=d.community_id
                WHERE d.community_id=?
                GROUP BY d.id, d.content, d.created_at
                HAVING COUNT(v.user_id) > 0
                ORDER BY vote_count DESC, d.id ASC
                LIMIT 1
            ");
            if ($top_q) {
                $top_q->bind_param("i", $uc['id']);
                $top_q->execute();
                $top_duty = $top_q->get_result()->fetch_assoc();
                $top_q->close();
            }

        }
?>
<div class="accordion"><?= htmlspecialchars($uc['name'], ENT_QUOTES, 'UTF-8') ?></div>
<div class="panel">
<?php if ($top_duty): ?>
<p><strong>وظایف و رویکردها:</strong><br><?= nl2br(htmlspecialchars($top_duty['content'], ENT_QUOTES, 'UTF-8')) ?></p>
<?php else: ?>
<p><strong>وظایف و رویکردها:</strong> <?= nl2br(htmlspecialchars((string)$uc['approach'], ENT_QUOTES, 'UTF-8')) ?></p>
<?php endif; ?>
<p><strong>تعداد اعضا:</strong> <?= (int)$uc['members_count'] ?></p>
<div style="margin-top:8px;">
<a href="community.php?id=<?= (int)$uc['id'] ?>" class="community-action enter-community">🚪 ورود به جامعه</a>
<form class="leave-community-form" action="leave_community.php" method="post" onsubmit="return confirm('آیا مطمئن هستید؟');">
<input type="hidden" name="community_id" value="<?= (int)$uc['id'] ?>">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($community_duty_csrf, ENT_QUOTES, 'UTF-8') ?>">
<button type="submit" class="community-action leave">🚫 ترک جامعه</button>
</form>
</div>
</div>
<?php endwhile; else: ?>
<p>هنوز عضو هیچ جامعه‌ای نیستید.</p>
<?php endif; ?>
</div>
</div>
</details>
</div>

<details class="community-management" id="join-community" <?= isset($_GET['join_community']) ? 'open' : '' ?>>
<summary>➕ عضویت در جامعه یا ایجاد جامعه جدید</summary>

<!-- عضویت در جوامع موجود -->
<div class="box">
<h3>عضویت در جامعه‌های موجود</h3>
<form action="join_community.php" method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($community_duty_csrf, ENT_QUOTES, 'UTF-8') ?>">
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
?>
<select name="community_id" id="joinCommunitySelect" required class="community-select-native">
<option value="">— انتخاب جامعه —</option>
<?php while($nc=$new_comms->fetch_assoc()): ?>
<option value="<?= (int)$nc['id'] ?>" <?= isset($_GET['join_community']) && (int)$_GET['join_community'] === (int)$nc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($nc['name']) ?></option>
<?php endwhile; ?>
</select>
<div class="community-picker" id="communityPicker">
<button type="button" class="community-picker-toggle" id="communityPickerToggle">— انتخاب جامعه — <span>▾</span></button>
<div class="community-picker-list" id="communityPickerList" hidden>
<?php
$new_comms->data_seek(0);
while($nc=$new_comms->fetch_assoc()):
$is_child = !empty($nc['parent_community_id']);
?>
<button type="button" class="community-picker-option <?= $is_child ? 'child-community' : 'independent-community' ?>" data-value="<?= (int)$nc['id'] ?>">
<span class="community-name">□ <?= htmlspecialchars($nc['name']) ?></span>
<span class="community-kind"><?= $is_child ? 'زیرمجموعهٔ ' . htmlspecialchars($nc['parent_name']) : 'جامعهٔ مستقل' ?></span>
</button>
<?php endwhile; ?>
</div>
</div>
<button type="submit">عضویت</button>
</form>
</div>

<!-- ایجاد جامعه جدید -->
<div class="box">
<h3>ایجاد جامعه جدید</h3>
<form action="create_community.php" method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($community_hierarchy_csrf, ENT_QUOTES, 'UTF-8') ?>">
<label>نام جامعه*</label>
<input type="text" name="community_name" required>

<label>نوع جامعه*</label>
<select name="community_type" id="communityType" required>
    <option value="کشور">کشور</option>
    <option value="شهر">شهر</option>
    <option value="روستا یا منطقه">روستا یا منطقه</option>
    <option value="کارخانه یا محل کار">کارخانه یا محل کار</option>
    <option value="دانشگاه یا مرکز آموزشی">دانشگاه یا مرکز آموزشی</option>
    <option value="سازمان یا نهاد">سازمان یا نهاد</option>
    <option value="گروه با هدف مشترک" selected>گروه با هدف مشترک</option>
    <option value="سایر">سایر</option>
</select>
<input type="text" name="community_type_custom" id="communityTypeCustom" maxlength="100" placeholder="نوع جامعه را بنویسید" style="display:none;margin-top:8px;">

<label>وظایف و رویکردها*</label>
<textarea name="community_approach" required></textarea>

<label>
<input type="checkbox" name="join_now" value="1"> همین الان عضو شوم
</label>

<button type="submit">✅ ایجاد جامعه</button>
</form>
<script>
(function () {
    const typeSelect = document.getElementById('communityType');
    const customType = document.getElementById('communityTypeCustom');
    if (!typeSelect || !customType) return;
    function updateCustomType() {
        const isOther = typeSelect.value === 'سایر';
        customType.style.display = isOther ? 'block' : 'none';
        customType.required = isOther;
        if (!isOther) customType.value = '';
    }
    typeSelect.addEventListener('change', updateCustomType);
    updateCustomType();
})();
</script>
</div>
</details>

<div style="text-align:center;margin-top:20px;">
<a href="logout.php">🚪 خروج از حساب</a>
</div>

</div>
</body>
</html>