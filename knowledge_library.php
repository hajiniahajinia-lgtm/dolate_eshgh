<?php
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$user_id = (int)$_SESSION['user_id'];
if (empty($_SESSION['knowledge_csrf'])) {
    $_SESSION['knowledge_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['knowledge_csrf'];

function kl_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function kl_type_label($type) {
    $labels = ['experience'=>'تجربه','solution'=>'راه‌حل','research'=>'پژوهش و دانش','lesson'=>'درس‌آموخته'];
    return $labels[$type] ?? 'دانش جمعی';
}
function kl_status_label($status) {
    $labels = ['unreviewed'=>'بررسی‌نشده','under_review'=>'در حال بررسی','verified'=>'تأییدشده با شواهد','rejected'=>'ردشده با دلیل','needs_update'=>'نیازمند به‌روزرسانی'];
    return $labels[$status] ?? 'وضعیت نامشخص';
}
function kl_member($conn, $community_id, $user_id) {
    $stmt = $conn->prepare('SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('ii', $community_id, $user_id);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $ok;
}

$message = '';
$error = '';
$allowed_types = ['experience','solution','research','lesson'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf = (string)($_POST['csrf_token'] ?? '');
    if ($posted_csrf === '' || !hash_equals($csrf, $posted_csrf)) {
        http_response_code(403);
        exit('درخواست معتبر نیست. صفحه را تازه‌سازی کنید.');
    }

    $action = (string)($_POST['action'] ?? '');
    $title = trim((string)($_POST['title'] ?? ''));
    $type = (string)($_POST['entry_type'] ?? '');
    $summary = trim((string)($_POST['summary'] ?? ''));
    $content = trim((string)($_POST['content'] ?? ''));
    $evidence = trim((string)($_POST['evidence'] ?? ''));
    $visibility = (string)($_POST['visibility'] ?? 'public');
    $community_id = (int)($_POST['community_id'] ?? 0);
    $source_topic_id = (int)($_POST['source_topic_id'] ?? 0);
    $source_idea_id = (int)($_POST['source_idea_id'] ?? 0);
    $revision_note = trim((string)($_POST['revision_note'] ?? ''));

    if ($title === '' || mb_strlen($title, 'UTF-8') > 180 ||
        !in_array($type, $allowed_types, true) ||
        $summary === '' || mb_strlen($summary, 'UTF-8') > 1000 ||
        $content === '' || mb_strlen($content, 'UTF-8') > 60000 ||
        mb_strlen($evidence, 'UTF-8') > 12000) {
        $error = 'لطفاً عنوان، نوع، خلاصه و متن را کامل کنید و محدودیت طول نوشته‌ها را رعایت کنید.';
    } elseif (!in_array($visibility, ['public','community'], true)) {
        $error = 'نوع دسترسی انتخاب‌شده معتبر نیست.';
    } elseif ($visibility === 'community' && ($community_id <= 0 || !kl_member($conn, $community_id, $user_id))) {
        $error = 'برای ثبت دانش مخصوص یک جامعه، باید عضو همان جامعه باشید.';
    } else {
        if ($source_topic_id > 0) {
            $source_check = $conn->prepare('SELECT community_id FROM topics WHERE id=? LIMIT 1');
            $source_check->bind_param('i', $source_topic_id);
            $source_check->execute();
            $source_row = $source_check->get_result()->fetch_assoc();
            $source_check->close();
            if (!$source_row || ($visibility === 'community' && (int)$source_row['community_id'] !== $community_id)) {
                $error = 'موضوع مرجع پیدا نشد یا با جامعه انتخاب‌شده هم‌خوانی ندارد.';
            }
        }
        if ($error === '' && $source_idea_id > 0) {
            $source_check = $conn->prepare('SELECT t.community_id FROM ideas i JOIN topics t ON t.id=i.topic_id WHERE i.id=? LIMIT 1');
            $source_check->bind_param('i', $source_idea_id);
            $source_check->execute();
            $source_row = $source_check->get_result()->fetch_assoc();
            $source_check->close();
            if (!$source_row || ($visibility === 'community' && (int)$source_row['community_id'] !== $community_id)) {
                $error = 'ایدهٔ مرجع پیدا نشد یا با جامعه انتخاب‌شده هم‌خوانی ندارد.';
            }
        }
    }

    if ($error === '' && $action === 'create') {
        $scope_community = $visibility === 'community' ? $community_id : null;
        $topic_value = $source_topic_id > 0 ? $source_topic_id : null;
        $idea_value = $source_idea_id > 0 ? $source_idea_id : null;
        $stmt = $conn->prepare('INSERT INTO collective_knowledge_entries (title, entry_type, summary, content, evidence, visibility, community_id, source_topic_id, source_idea_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ssssssiiii', $title, $type, $summary, $content, $evidence, $visibility, $scope_community, $topic_value, $idea_value, $user_id);
        if ($stmt->execute()) {
            $new_id = (int)$conn->insert_id;
            $stmt->close();
            header('Location: knowledge_library.php?id=' . $new_id . '&saved=1');
            exit;
        }
        $error = 'ذخیرهٔ مدخل انجام نشد. لطفاً دوباره تلاش کنید.';
        $stmt->close();
    } elseif ($error === '' && $action === 'edit') {
        $entry_id = (int)($_POST['entry_id'] ?? 0);
        $check = $conn->prepare('SELECT * FROM collective_knowledge_entries WHERE id=? AND created_by=? LIMIT 1');
        $check->bind_param('ii', $entry_id, $user_id);
        $check->execute();
        $old = $check->get_result()->fetch_assoc();
        $check->close();
        if (!$old) {
            http_response_code(403);
            exit('فقط ثبت‌کنندهٔ مدخل می‌تواند آن را ویرایش کند.');
        }
        $scope_community = $visibility === 'community' ? $community_id : null;
        $topic_value = $source_topic_id > 0 ? $source_topic_id : null;
        $idea_value = $source_idea_id > 0 ? $source_idea_id : null;
        $rev = $conn->prepare('INSERT INTO collective_knowledge_revisions (entry_id, editor_user_id, previous_title, previous_summary, previous_content, previous_evidence, revision_note) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $rev->bind_param('iisssss', $entry_id, $user_id, $old['title'], $old['summary'], $old['content'], $old['evidence'], $revision_note);
        if (!$rev->execute()) {
            $error = 'ثبت سابقهٔ ویرایش ناموفق بود؛ متن قبلی حفظ شد و تغییری اعمال نشد.';
        }
        $rev->close();
        if ($error === '') {
            $update = $conn->prepare("UPDATE collective_knowledge_entries SET title=?, entry_type=?, summary=?, content=?, evidence=?, visibility=?, community_id=?, source_topic_id=?, source_idea_id=?, updated_by=?, status='unreviewed' WHERE id=? AND created_by=?");
            $update->bind_param('ssssssiiiiii', $title, $type, $summary, $content, $evidence, $visibility, $scope_community, $topic_value, $idea_value, $user_id, $entry_id, $user_id);
            if ($update->execute()) {
                $update->close();
                header('Location: knowledge_library.php?id=' . $entry_id . '&updated=1');
                exit;
            }
            $error = 'ویرایش انجام نشد.';
            $update->close();
        }
    }
}

if (isset($_GET['saved'])) $message = 'مدخل دانش با موفقیت ثبت شد.';
if (isset($_GET['updated'])) $message = 'مدخل به‌روزرسانی شد؛ وضعیت آن برای بررسی دوباره به «بررسی‌نشده» برگشت.';

$communities = [];
$cs = $conn->prepare('SELECT c.id, c.name FROM communities c JOIN community_members m ON m.community_id=c.id WHERE m.user_id=? ORDER BY c.name');
$cs->bind_param('i', $user_id);
$cs->execute();
$communities = $cs->get_result()->fetch_all(MYSQLI_ASSOC);
$cs->close();

$q = trim((string)($_GET['q'] ?? ''));
$q = mb_substr($q, 0, 120, 'UTF-8');
$like = '%' . $q . '%';
$entry_id = (int)($_GET['id'] ?? 0);
$edit_mode = isset($_GET['edit']) && $_GET['edit'] === '1';
$entry = null;
$revisions = [];

if ($entry_id > 0) {
    $stmt = $conn->prepare("SELECT k.*, u.username, c.name AS community_name, t.title AS source_topic_title, t.community_id AS source_topic_community_id,
        i.content AS source_idea_content, it.id AS source_idea_topic_id, it.community_id AS source_idea_community_id
        FROM collective_knowledge_entries k
        JOIN users u ON u.id=k.created_by
        LEFT JOIN communities c ON c.id=k.community_id
        LEFT JOIN topics t ON t.id=k.source_topic_id
        LEFT JOIN ideas i ON i.id=k.source_idea_id
        LEFT JOIN topics it ON it.id=i.topic_id
        WHERE k.id=? LIMIT 1");
    $stmt->bind_param('i', $entry_id);
    $stmt->execute();
    $entry = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($entry && $entry['visibility'] === 'community' && !kl_member($conn, (int)$entry['community_id'], $user_id)) {
        http_response_code(403);
        exit('این مدخل فقط برای اعضای جامعهٔ مربوط قابل مشاهده است.');
    }
    if ($entry && $edit_mode && (int)$entry['created_by'] === $user_id) {
        $rs = $conn->prepare('SELECT r.*, u.username FROM collective_knowledge_revisions r JOIN users u ON u.id=r.editor_user_id WHERE r.entry_id=? ORDER BY r.id DESC LIMIT 20');
        $rs->bind_param('i', $entry_id);
        $rs->execute();
        $revisions = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
        $rs->close();
    }
}

$entries = [];
if ($entry_id === 0) {
    $stmt = $conn->prepare("SELECT k.id, k.title, k.entry_type, k.summary, k.visibility, k.community_id, k.status, k.created_at, u.username, c.name AS community_name
        FROM collective_knowledge_entries k
        JOIN users u ON u.id=k.created_by
        LEFT JOIN communities c ON c.id=k.community_id
        WHERE (k.visibility='public' OR (k.visibility='community' AND EXISTS (
            SELECT 1 FROM community_members m WHERE m.community_id=k.community_id AND m.user_id=?
        ))) AND (?='' OR k.title LIKE ? OR k.summary LIKE ? OR k.content LIKE ?)
        ORDER BY k.updated_at DESC, k.id DESC LIMIT 100");
    $stmt->bind_param('issss', $user_id, $q, $like, $like, $like);
    $stmt->execute();
    $entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

function kl_render_form($csrf, $communities, $entry = null) {
    $editing = (bool)$entry;
    $type = $entry['entry_type'] ?? 'experience';
    $visibility = $entry['visibility'] ?? 'public';
    $community_id = (int)($entry['community_id'] ?? 0);
    echo '<form method="post" class="entry-form">';
    echo '<input type="hidden" name="csrf_token" value="' . kl_h($csrf) . '">';
    echo '<input type="hidden" name="action" value="' . ($editing ? 'edit' : 'create') . '">';
    if ($editing) echo '<input type="hidden" name="entry_id" value="' . (int)$entry['id'] . '">';
    echo '<label>عنوان مدخل</label><input name="title" maxlength="180" required value="' . kl_h($entry['title'] ?? '') . '">';
    echo '<label>نوع دانش</label><select name="entry_type" required>';
    foreach (['experience'=>'تجربه','solution'=>'راه‌حل','research'=>'پژوهش و دانش','lesson'=>'درس‌آموخته'] as $key=>$label) echo '<option value="' . $key . '"' . ($type===$key?' selected':'') . '>' . $label . '</option>';
    echo '</select>';
    echo '<label>خلاصهٔ کوتاه</label><textarea name="summary" rows="2" maxlength="1000" required>' . kl_h($entry['summary'] ?? '') . '</textarea>';
    echo '<label>شرح کامل و جزئیات</label><textarea name="content" rows="8" maxlength="60000" required>' . kl_h($entry['content'] ?? '') . '</textarea>';
    echo '<label>شواهد و منابع (نشانی اینترنتی یا مشخصات منبع؛ اختیاری)</label><textarea name="evidence" rows="3" maxlength="12000">' . kl_h($entry['evidence'] ?? '') . '</textarea>';
    echo '<label>دامنهٔ دسترسی</label><select name="visibility"><option value="public"' . ($visibility==='public'?' selected':'') . '>عمومی؛ قابل استفاده در همهٔ جوامع</option><option value="community"' . ($visibility==='community'?' selected':'') . '>فقط اعضای یک جامعه</option></select>';
    echo '<label>جامعهٔ مربوط (برای مدخل اختصاصی)</label><select name="community_id"><option value="0">انتخاب نشده</option>';
    foreach ($communities as $c) echo '<option value="' . (int)$c['id'] . '"' . ($community_id===(int)$c['id']?' selected':'') . '>' . kl_h($c['name']) . '</option>';
    echo '</select>';
    echo '<label>شناسهٔ موضوع مرتبط (اختیاری)</label><input type="number" min="0" name="source_topic_id" value="' . (int)($entry['source_topic_id'] ?? 0) . '">';
    echo '<label>شناسهٔ ایدهٔ مرتبط (اختیاری)</label><input type="number" min="0" name="source_idea_id" value="' . (int)($entry['source_idea_id'] ?? 0) . '">';
    if ($editing) echo '<label>دلیل یا توضیح ویرایش (اختیاری)</label><input name="revision_note" maxlength="1000">';
    echo '<button type="submit">' . ($editing ? 'ذخیرهٔ نسخهٔ جدید' : 'ثبت در کتابخانه') . '</button></form>';
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>کتابخانهٔ دانش جمعی</title>
<style>
*{box-sizing:border-box}body{margin:0;padding:12px;background:#eef3f8;color:#263238;font-family:Tahoma,Arial,sans-serif;line-height:1.9}
.container{max-width:920px;margin:0 auto}.panel,.entry{background:#fff;border:1px solid #d8e2eb;border-radius:10px;padding:14px;margin-bottom:12px}
h1{font-size:21px;margin:0}h2{font-size:17px;margin:0 0 9px}.top{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
a{color:#1769aa;text-decoration:none;font-weight:bold}.muted{font-size:12px;color:#687789}.summary{margin:7px 0}
.entry-form label{display:block;margin:10px 0 3px;font-size:13px;font-weight:bold}
input,select,textarea{width:100%;padding:9px;border:1px solid #bdc9d4;border-radius:7px;font:inherit;background:#fff}
textarea{resize:vertical}button,.button{display:inline-block;border:0;border-radius:7px;padding:9px 14px;background:#2065d1;color:#fff;font:inherit;font-weight:bold;cursor:pointer;margin-top:10px}
.button.secondary{background:#64748b}.notice{padding:9px;border-radius:7px;background:#e8f7ef;color:#17633d;margin-bottom:10px}.error{padding:9px;border-radius:7px;background:#fff0ef;color:#a12622;margin-bottom:10px}
.tag{display:inline-block;padding:2px 8px;border-radius:5px;background:#eaf2fb;color:#315a83;font-size:12px;margin-left:4px}
.entry-title{font-size:16px}.content{white-space:pre-wrap;overflow-wrap:anywhere}.entry-actions{display:flex;gap:8px;flex-wrap:wrap}
@media(max-width:520px){body{padding:8px}.panel,.entry{padding:11px}h1{font-size:19px}}
</style>
</head>
<body><main class="container">
<section class="panel">
<div class="top"><h1>📚 کتابخانهٔ دانش جمعی</h1><a href="profile.php">بازگشت به پروفایل</a></div>
<p>تجربه‌ها، راه‌حل‌ها، پژوهش‌ها و درس‌آموخته‌ها را ثبت کنید تا دانش مفید برای دیگر جوامع نیز قابل استفاده باشد.</p>
<?php if ($message): ?><div class="notice"><?= kl_h($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="error"><?= kl_h($error) ?></div><?php endif; ?>
</section>

<?php if ($entry_id > 0): ?>
<?php if (!$entry): ?>
<section class="panel">مدخل موردنظر پیدا نشد. <a href="knowledge_library.php">بازگشت به کتابخانه</a></section>
<?php elseif ($edit_mode && (int)$entry['created_by'] === $user_id): ?>
<section class="panel"><h2>ویرایش مدخل دانش</h2>
<?php kl_render_form($csrf, $communities, $entry); ?>
<h2 style="margin-top:20px">سابقهٔ نسخه‌ها</h2>
<?php if (!$revisions): ?><p class="muted">هنوز نسخهٔ قبلی ثبت نشده است.</p><?php endif; ?>
<?php foreach ($revisions as $rev): ?><details class="entry"><summary>نسخهٔ قبلی — <?= kl_h($rev['username']) ?> — <?= kl_h($rev['created_at']) ?></summary><p><strong><?= kl_h($rev['previous_title']) ?></strong></p><p><?= nl2br(kl_h($rev['previous_summary'])) ?></p><div class="content"><?= kl_h($rev['previous_content']) ?></div><?php if ($rev['revision_note']): ?><p class="muted">یادداشت ویرایش: <?= kl_h($rev['revision_note']) ?></p><?php endif; ?></details><?php endforeach; ?>
</section>
<?php else: ?>
<section class="panel">
<div class="top"><h2><?= kl_h($entry['title']) ?></h2><span class="tag"><?= kl_h(kl_type_label($entry['entry_type'])) ?></span></div>
<p class="muted">ثبت‌کننده: <?= kl_h($entry['username']) ?> · <?= $entry['visibility']==='public'?'دانش عمومی':'ویژهٔ جامعهٔ '.kl_h($entry['community_name'] ?? '') ?> · وضعیت: <?= kl_h(kl_status_label($entry['status'])) ?></p>
<h3>خلاصه</h3><p><?= nl2br(kl_h($entry['summary'])) ?></p>
<h3>شرح و جزئیات</h3><div class="content"><?= kl_h($entry['content']) ?></div>
<?php if (!empty($entry['evidence'])): ?><h3>شواهد و منابع</h3><div class="content"><?= kl_h($entry['evidence']) ?></div><?php endif; ?>
<?php if (!empty($entry['source_topic_id'])): ?><p>موضوع مرتبط: <a href="community.php?id=<?= (int)($entry['source_topic_community_id'] ?? 0) ?>#topic-<?= (int)$entry['source_topic_id'] ?>">موضوع شمارهٔ <?= (int)$entry['source_topic_id'] ?></a> — <?= kl_h($entry['source_topic_title'] ?? '') ?></p><?php endif; ?>
<?php if (!empty($entry['source_idea_id'])): ?><p>ایدهٔ مرتبط: <a href="community.php?id=<?= (int)($entry['source_idea_community_id'] ?? 0) ?>#topic-<?= (int)($entry['source_idea_topic_id'] ?? 0) ?>">مشاهدهٔ موضوع و ایدهٔ مرتبط</a> — <?= kl_h(mb_substr(strip_tags((string)($entry['source_idea_content'] ?? '')),0,180,'UTF-8')) ?></p><?php endif; ?>
<div class="entry-actions"><a class="button secondary" href="knowledge_library.php">بازگشت به فهرست</a><?php if ((int)$entry['created_by']===$user_id): ?><a class="button" href="knowledge_library.php?id=<?= (int)$entry['id'] ?>&edit=1">ویرایش و تکمیل</a><?php endif; ?></div>
</section>
<?php endif; ?>
<?php else: ?>
<section class="panel"><h2>ثبت دانش تازه</h2><?php kl_render_form($csrf, $communities); ?></section>
<section class="panel"><h2>جست‌وجو در کتابخانه</h2><form method="get"><input type="search" name="q" maxlength="120" value="<?= kl_h($q) ?>" placeholder="عنوان، خلاصه یا متن دانش را جست‌وجو کنید"><button type="submit">جست‌وجو</button></form></section>
<section class="panel"><h2>مدخل‌های کتابخانه (<?= count($entries) ?>)</h2>
<?php if (!$entries): ?><p class="muted">هنوز مدخلی ثبت نشده یا نتیجه‌ای با این عبارت پیدا نشد.</p><?php endif; ?>
<?php foreach ($entries as $item): ?><article class="entry">
<div class="top"><a class="entry-title" href="knowledge_library.php?id=<?= (int)$item['id'] ?>"><?= kl_h($item['title']) ?></a><span class="tag"><?= kl_h(kl_type_label($item['entry_type'])) ?></span></div>
<p class="summary"><?= nl2br(kl_h($item['summary'])) ?></p>
<div class="muted">ثبت‌کننده: <?= kl_h($item['username']) ?> · <?= $item['visibility']==='public'?'عمومی':'ویژهٔ '.kl_h($item['community_name'] ?? 'جامعه') ?> · <?= kl_h(kl_status_label($item['status'])) ?></div>
</article><?php endforeach; ?>
</section>
<?php endif; ?>
</main></body></html>
