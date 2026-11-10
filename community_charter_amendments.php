<?php
session_start();
require __DIR__ . '/db.php';
require_once __DIR__ . '/community_law_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$user_id = (int)$_SESSION['user_id'];
if (empty($_SESSION['charter_amendment_csrf']) || !is_string($_SESSION['charter_amendment_csrf'])) {
    $_SESSION['charter_amendment_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['charter_amendment_csrf'];

function cam_page_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function cam_page_status(string $status): string {
    $labels = [
        'draft_created' => 'پیش‌نویس اصلاح منشور ثبت شد.',
        'initial_review_started' => 'منشور آغازین برای بررسی مستقل و سپس رأی عمومی ارسال شد.',
        'review_started' => 'پیشنهاد برای بررسی مستقل ارسال شد.',
        'assessment_saved' => 'ارزیابی مستقل ثبت شد.',
        'public_voting_started' => 'بررسی مستقل موفق بود؛ رأی‌گیری عمومی آغاز شد.',
        'vote_saved' => 'رأی شما ثبت شد.',
        'appeal_period' => 'پیشنهاد در مهلت هفت‌روزه اعتراض قرار گرفت.',
        'appeal_submitted' => 'اعتراض ثبت و برای رسیدگی مستقل ارجاع شد.',
        'conflict_disclosed_clear' => 'اعلام نبود تعارض ثبت شد؛ در صورت تکمیل پنج ارزیاب، بررسی مستقل آغاز می‌شود.',
        'reviewer_recused' => 'کناره‌گیری شما ثبت شد؛ نامزدهای ذخیره برای تکمیل هیئت بررسی می‌شوند.',
        'conflict_referred' => 'اعلام تعارض برای رسیدگی محرمانه ارجاع شد.',
        'conflict_decision_saved' => 'تصمیم رسیدگی محرمانه ثبت شد؛ نتیجه پس از رأی کافی اعمال می‌شود.',
        'appeal_assessment_saved' => 'ارزیابی اعتراض ثبت شد؛ رسیدگی ادامه دارد.',
        'appeal_upheld' => 'اعتراض وارد تشخیص داده شد و اصلاحیه رد شد.',
        'appeal_dismissed' => 'اعتراض وارد تشخیص داده نشد.',
        'amendment_rejected' => 'اصلاحیه به حد نصاب یا شرایط لازم نرسید و رد شد.',
        'amendment_activated' => 'نسخه تازه منشور پس از طی همه مراحل لازم‌الاجرا شد.'
    ];
    return $labels[$status] ?? '';
}

$required = [
    'community_charter_versions','community_charter_articles','community_charter_amendments',
    'community_charter_amendment_items','community_charter_amendment_reviewers',
    'community_charter_amendment_assessments','community_charter_amendment_electorate',
    'community_charter_amendment_votes','community_charter_amendment_appeals',
    'community_charter_amendment_appeal_reviewers',
    'community_charter_amendment_appeal_reviewer_assignments',
    'community_charter_amendment_reviewer_candidates',
    'community_charter_amendment_conflict_adjudicators',
    'community_charter_amendment_conflict_decisions'
];
$marks = implode(',', array_fill(0, count($required), '?'));
$check = $conn->prepare(
    "SELECT COUNT(*) AS total FROM information_schema.tables
     WHERE table_schema=DATABASE() AND table_name IN ($marks)"
);
$ready = false;
if ($check) {
    $types = str_repeat('s', count($required));
    $params = array_merge([$types], $required);
    $refs = [];
    foreach ($params as $i => &$value) $refs[$i] = &$value;
    call_user_func_array([$check, 'bind_param'], $refs);
    if ($check->execute()) $ready = (int)$check->get_result()->fetch_assoc()['total'] === count($required);
    $check->close();
}
if ($ready) {
    $case_type_check = $conn->query(
        "SELECT COUNT(*) AS total FROM information_schema.columns
         WHERE table_schema=DATABASE() AND table_name='community_charter_amendments'
           AND column_name='case_type'"
    );
    $ready = $case_type_check && (int)$case_type_check->fetch_assoc()['total'] === 1;
    if ($case_type_check) $case_type_check->free();
}

$active_version = null;
$effective_version_total = 0;
$articles = [];
$amendments = [];
$initial_draft = null;
$initial_articles = [];
$initial_case = null;
if ($ready) {
    $effective_count_result = $conn->query(
        "SELECT COUNT(*) AS total FROM community_charter_versions WHERE status='effective'"
    );
    if ($effective_count_result) {
        $effective_version_total = (int)$effective_count_result->fetch_assoc()['total'];
        $effective_count_result->free();
    } else {
        $ready = false;
    }

    $active_result = $conn->query(
        "SELECT id,version_number,title FROM community_charter_versions
         WHERE status='effective' AND effective_at<=NOW()"
    );
    if ($active_result) {
        $active_rows = [];
        while ($row = $active_result->fetch_assoc()) $active_rows[] = $row;
        $active_result->free();
        if (count($active_rows) === 1) {
            $active_version = $active_rows[0];
            $article_stmt = $conn->prepare(
                "SELECT id,article_code,title,is_core FROM community_charter_articles
                 WHERE charter_version_id=? ORDER BY display_order,id"
            );
            if ($article_stmt) {
                $version_id = (int)$active_version['id'];
                $article_stmt->bind_param('i', $version_id);
                if ($article_stmt->execute()) {
                    $res = $article_stmt->get_result();
                    while ($row = $res->fetch_assoc()) $articles[] = $row;
                }
                $article_stmt->close();
            }
        }

        $list = $conn->query(
            "SELECT a.*,v.version_number AS proposed_version_number,u.username AS proposer_name
             FROM community_charter_amendments a
             JOIN community_charter_versions v ON v.id=a.proposed_charter_version_id
             LEFT JOIN users u ON u.id=a.proposer_user_id
             ORDER BY a.created_at DESC,a.id DESC LIMIT 50"
        );
        if ($list) {
            while ($row = $list->fetch_assoc()) $amendments[] = $row;
            $list->free();
        }
    }

    if (!$active_version) {
        $list = $conn->query(
            "SELECT a.*,v.version_number AS proposed_version_number,u.username AS proposer_name
             FROM community_charter_amendments a
             JOIN community_charter_versions v ON v.id=a.proposed_charter_version_id
             LEFT JOIN users u ON u.id=a.proposer_user_id
             ORDER BY a.created_at DESC,a.id DESC LIMIT 50"
        );
        if ($list) {
            while ($row = $list->fetch_assoc()) $amendments[] = $row;
            $list->free();
        }

        $draft_result = $conn->query(
            "SELECT id,version_number,title FROM community_charter_versions
             WHERE status='draft' AND approved_at IS NULL AND effective_at IS NULL
             ORDER BY id"
        );
        if ($draft_result) {
            $draft_rows = [];
            while ($row = $draft_result->fetch_assoc()) $draft_rows[] = $row;
            $draft_result->free();
            if (count($draft_rows) === 1 && (int)$draft_rows[0]['version_number'] === 1) {
                $initial_draft = $draft_rows[0];
                $draft_id = (int)$initial_draft['id'];
                $initial_article_stmt = $conn->prepare(
                    "SELECT id,article_code,title,article_text,is_core FROM community_charter_articles
                     WHERE charter_version_id=? ORDER BY display_order,id"
                );
                if ($initial_article_stmt) {
                    $initial_article_stmt->bind_param('i', $draft_id);
                    if ($initial_article_stmt->execute()) {
                        $initial_article_result = $initial_article_stmt->get_result();
                        while ($row = $initial_article_result->fetch_assoc()) $initial_articles[] = $row;
                    }
                    $initial_article_stmt->close();
                }
            }
        }
        $initial_case_stmt = $conn->prepare(
            "SELECT id,status FROM community_charter_amendments
             WHERE case_type='initial_adoption' ORDER BY id DESC LIMIT 1"
        );
        if ($initial_case_stmt) {
            if ($initial_case_stmt->execute()) $initial_case = $initial_case_stmt->get_result()->fetch_assoc() ?: null;
            $initial_case_stmt->close();
        }
    }
}
$charter_case_status_labels = [
    'draft' => 'پیش‌نویس',
    'independent_review' => 'بررسی مستقل',
    'public_voting' => 'رأی‌گیری عمومی',
    'appeal_period' => 'مهلت اعتراض',
    'approved_pending_activation' => 'آماده فعال‌سازی',
    'activated' => 'لازم‌الاجرا',
    'rejected' => 'رد شده',
    'withdrawn' => 'پس‌گرفته‌شده'
];
$group_labels = [
    'human_dignity' => 'کرامت انسانی','equality' => 'برابری','freedom' => 'آزادی',
    'justice' => 'عدالت','peace' => 'صلح','living_beings' => 'موجودات زنده',
    'environment' => 'محیط زیست','shared_resources' => 'منابع مشترک',
    'self_governance' => 'خودگردانی','accountability' => 'پاسخ‌گویی',
    'future_generations' => 'نسل‌های آینده','technology' => 'فناوری'
];
$dimension_labels = [
    'rights_protection' => 'حقوق و حمایت‌های بنیادین',
    'equality_and_non_discrimination' => 'برابری و منع تبعیض',
    'living_beings_and_environment' => 'موجودات زنده و محیط زیست',
    'power_and_accountability' => 'مهار قدرت و پاسخ‌گویی',
    'future_generations' => 'حقوق نسل‌های آینده'
];
$dimension_values = [
    'maintained' => 'حفظ شده','improved' => 'تقویت شده',
    'weakened' => 'تضعیف شده','unclear' => 'نامشخص'
];
$verdict_labels = ['supports'=>'موافق','conflict'=>'دارای تعارض','unclear'=>'نامشخص'];
$appeal_status_labels = [
    'submitted' => 'ثبت‌شده؛ در انتظار رسیدگی',
    'under_review' => 'در حال رسیدگی مستقل',
    'upheld' => 'اعتراض وارد تشخیص داده شد',
    'dismissed' => 'اعتراض وارد تشخیص داده نشد'
];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>اصلاح منشور بنیادین</title>
<style>
*{box-sizing:border-box}body{margin:0;padding:16px;background:#f2f5f8;color:#243247;font-family:Tahoma,Arial,sans-serif;line-height:1.9}
.wrap{max-width:1000px;margin:0 auto}.panel{background:#fff;border:1px solid #dce3eb;border-radius:12px;padding:18px;margin:14px 0}
h1{font-size:24px;margin:0 0 8px;color:#183b68}h2{font-size:19px;margin:0 0 12px;color:#244b78}h3{font-size:17px;margin:10px 0}
small,.muted{color:#66758a}.notice{padding:10px 12px;border-radius:8px;background:#eaf2ff;margin:12px 0}
.warning{background:#fff4df;color:#79520c;padding:12px;border-radius:8px}
label{display:block;font-weight:600;margin:9px 0 4px}input,textarea,select{width:100%;padding:11px;border:1px solid #c8d2df;border-radius:8px;font:inherit;background:#fff}
textarea{min-height:90px;resize:vertical}button,.button{border:0;border-radius:8px;background:#245da8;color:white;padding:10px 16px;font:inherit;cursor:pointer;margin-top:10px}
button.secondary{background:#58677a}button.danger{background:#8a3940}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.item{border:1px solid #dbe3ed;border-radius:9px;padding:12px;margin:12px 0}.amendment{border-top:1px solid #e2e8ef;padding:14px 0}
.actions{display:flex;gap:8px;flex-wrap:wrap}.actions form{margin:0}.tag{display:inline-block;background:#edf1f5;border-radius:20px;padding:2px 9px;font-size:13px}
@media(max-width:650px){body{padding:9px}.panel{padding:13px}.grid{grid-template-columns:1fr}h1{font-size:21px}button{width:100%}.actions{display:block}.actions form button{width:100%}}
</style>
</head>
<body>
<div class="wrap">
<section class="panel">
<h1>اصلاح منشور بنیادین</h1>
<p>منشور، مرجع حقوقی برتر همه جوامع است. اصلاح آن تنها پس از بررسی مستقل، رأی مستقیم کاربران، مهلت اعتراض و رسیدگی به اعتراض‌ها امکان‌پذیر است.</p>
<div class="notice">قواعد این فرایند: دست‌کم ۵ ارزیاب مستقل و حمایت ۴ نفر؛ مشارکت دست‌کم دوسوم رأی‌دهندگان؛ موافقت دست‌کم سه‌چهارم آرای موافق و مخالف؛ سپس ۷ روز مهلت اعتراض.</div>
<div class="notice">ارزیابان، رسیدگی‌کنندگان تعارض و رأی‌دهندگان به‌صورت تصادفی و در فهرست‌های جدا انتخاب می‌شوند. نامزد ارزیابی پیش از شروع بررسی باید نبود تعارض را اعلام کند، کناره‌گیری کند یا درخواست رسیدگی محرمانه بدهد. جزئیات خصوصی فقط برای رسیدگی‌کنندگان همان پرونده نمایش داده می‌شود؛ تا تکمیل پنج ارزیاب واجد شرایط، بررسی مستقل آغاز نمی‌شود.</div>
<?php if ($message = cam_page_status((string)($_GET['status'] ?? ''))): ?>
<div class="notice"><?= cam_page_h($message) ?></div>
<?php endif; ?>
<?php if (!$ready): ?>
<div class="warning">ساختار پایگاه داده اصلاح منشور هنوز کامل نیست. این صفحه هیچ migrationای را اجرا نمی‌کند.</div>
</section>
<?php elseif (!$active_version): ?>
<?php if ($effective_version_total !== 0): ?>
<div class="warning">وضعیت نسخه‌های منشور یکتا و روشن نیست؛ تا رفع این وضعیت، تصویب اولیه یا اصلاح منشور متوقف است.</div>
<?php else: ?>
<div class="warning">هنوز نسخهٔ لازم‌الاجرای منشور وجود ندارد؛ قوانین عادی تا تصویب جمعی منشور آغازین نباید لازم‌الاجرا شوند.</div>
<h2>تصویب نخستین منشور بنیادین</h2>
<?php if ($initial_draft && count($initial_articles) === 12): ?>
<p>نسخهٔ آغازین هنوز پیش‌نویس است و ۱۲ مادهٔ آن برای بررسی عمومی آماده‌اند. فعال‌سازی فقط پس از بررسی مستقل، رأی عمومی و مهلت اعتراض انجام می‌شود.</p>
<p><strong><?= cam_page_h($initial_draft['title']) ?></strong></p>
<?php foreach ($initial_articles as $article): ?>
<div class="item">
<strong><?= cam_page_h($article['article_code'] . ' — ' . $article['title']) ?><?= (int)$article['is_core'] === 1 ? ' (ماده بنیادین)' : '' ?></strong>
<p><?= nl2br(cam_page_h($article['article_text'])) ?></p>
</div>
<?php endforeach; ?>
<?php if ($initial_case): ?>
<p>پروندهٔ تصویب اولیه ثبت شده است. وضعیت فعلی: <strong><?= cam_page_h($initial_case['status']==='draft' ? 'در انتظار اعلام و رسیدگی به تعارض منافع' : ($charter_case_status_labels[$initial_case['status']] ?? 'وضعیت نامشخص')) ?></strong>. جزئیات بررسی، رأی‌گیری و اعتراض در پروندهٔ پایین نمایش داده می‌شود.</p>
<?php else: ?>
<form method="post" action="community_charter_amendment_action.php">
<input type="hidden" name="csrf_token" value="<?= cam_page_h($csrf) ?>">
<input type="hidden" name="action" value="start_initial_adoption">
<button type="submit">ارسال منشور آغازین برای بررسی مستقل و رأی عمومی</button>
</form>
<?php endif; ?>
<?php elseif ($initial_case): ?>
<p>پروندهٔ تصویب اولیه ثبت شده است. وضعیت فعلی: <strong><?= cam_page_h($charter_case_status_labels[$initial_case['status']] ?? 'وضعیت نامشخص') ?></strong>.</p>
<?php else: ?>
<div class="warning">پیش‌نویس آغازین یکتا با ۱۲ مادهٔ بنیادین کامل پیدا نشد؛ فرایند تصویب متوقف است.</div>
<?php endif; ?>
<?php endif; ?>
</section>
<?php else: ?>
<p class="muted">نسخه جاری: <?= cam_page_h($active_version['version_number']) ?> — <?= cam_page_h($active_version['title']) ?></p>
</section>

<section class="panel">
<h2>مواد فعلی منشور</h2>
<?php foreach ($articles as $article): ?>
<div class="amendment">
<strong><?= cam_page_h($article['article_code']) ?> — <?= cam_page_h($article['title']) ?></strong>
<span class="tag"><?= (int)$article['is_core'] === 1 ? 'ماده بنیادین' : 'ماده تکمیلی' ?></span>
</div>
<?php endforeach; ?>
</section>

<section class="panel">
<h2>ثبت پیشنهاد اصلاح</h2>
<p class="muted">هر پیشنهاد باید دلیل ضرورت و ارزیابی پیامدهای انسانی، اجتماعی، زیست‌محیطی و بلندمدت را توضیح دهد. حذف مواد بنیادین امکان‌پذیر نیست؛ اصلاح متن آن‌ها فقط با بررسی سخت‌گیرانه انجام می‌شود.</p>
<form method="post" action="community_charter_amendment_action.php">
<input type="hidden" name="csrf_token" value="<?= cam_page_h($csrf) ?>">
<input type="hidden" name="action" value="create_draft">
<label>عنوان پیشنهاد</label><input name="title" maxlength="200" required>
<label>دلیل و ضرورت اصلاح</label><textarea name="justification" minlength="30" maxlength="30000" required></textarea>
<label>ارزیابی پیامدها و آثار احتمالی</label><textarea name="impact_assessment" minlength="30" maxlength="30000" required></textarea>
<div id="items">
<div class="item amendment-item">
<h3>ماده مورد اصلاح</h3>
<label>نوع تغییر</label>
<select name="item_action[]" class="item-action">
<option value="replace">جایگزینی متن ماده</option><option value="add">افزودن ماده</option><option value="remove">پیشنهاد حذف ماده تکمیلی</option>
</select>
<label>ماده هدف (برای افزودن خالی بماند)</label>
<select name="target_article_id[]" class="target-article">
<option value="">بدون ماده هدف</option>
<?php foreach ($articles as $article): ?>
<option value="<?= (int)$article['id'] ?>" data-code="<?= cam_page_h($article['article_code']) ?>"><?= cam_page_h($article['article_code'] . ' — ' . $article['title']) ?><?= (int)$article['is_core'] === 1 ? ' (بنیادین؛ حذف ممنوع)' : '' ?></option>
<?php endforeach; ?>
</select>
<label>شناسه ماده</label><input name="article_code[]" maxlength="30" placeholder="مثلاً C-03 یا C-13" required>
<label>عنوان پیشنهادی</label><input name="proposed_title[]" maxlength="200">
<label>متن پیشنهادی</label><textarea name="proposed_text[]" maxlength="30000"></textarea>
<label>دسته اصل</label>
<select name="principle_group[]">
<?php foreach ($group_labels as $key=>$label): ?><option value="<?= cam_page_h($key) ?>"><?= cam_page_h($label) ?></option><?php endforeach; ?>
</select>
<label>دلیل مشخص این تغییر</label><textarea name="item_rationale[]" minlength="10" maxlength="10000" required></textarea>
<button type="button" class="secondary remove-item">حذف این ردیف</button>
</div>
</div>
<div class="actions">
<button type="button" class="secondary" id="add-item">افزودن ماده دیگر به پیشنهاد</button>
<button type="submit">ذخیره پیش‌نویس</button>
</div>
</form>
</section>

<?php endif; ?>

<section class="panel">
<h2>پرونده‌های منشور بنیادین</h2>
<?php if (!$amendments): ?><p class="muted">هنوز پیشنهادی ثبت نشده است.</p><?php endif; ?>
<?php foreach ($amendments as $a):
    $aid = (int)$a['id'];
    $status_labels = $charter_case_status_labels;
    $is_reviewer = false; $has_assessment = false; $is_elector = false; $my_vote = '';
    $is_adjudicator = false; $candidate_status = '';
    $q = $conn->prepare("SELECT 1 FROM community_charter_amendment_reviewers WHERE amendment_id=? AND reviewer_user_id=? LIMIT 1");
    if ($q) { $q->bind_param('ii',$aid,$user_id); $q->execute(); $is_reviewer=(bool)$q->get_result()->fetch_row(); $q->close(); }
    if ($is_reviewer) {
        $q=$conn->prepare("SELECT 1 FROM community_charter_amendment_assessments WHERE amendment_id=? AND reviewer_user_id=? LIMIT 1");
        if($q){$q->bind_param('ii',$aid,$user_id);$q->execute();$has_assessment=(bool)$q->get_result()->fetch_row();$q->close();}
    }
    $candidate_status = '';
    $candidate_q = $conn->prepare(
        "SELECT status FROM community_charter_amendment_reviewer_candidates
         WHERE amendment_id=? AND candidate_user_id=? LIMIT 1"
    );
    if ($candidate_q) {
        $candidate_q->bind_param('ii',$aid,$user_id);
        if ($candidate_q->execute()) $candidate_status=(string)($candidate_q->get_result()->fetch_assoc()['status'] ?? '');
        $candidate_q->close();
    }
    $adjudicator_q = $conn->prepare(
        "SELECT 1 FROM community_charter_amendment_conflict_adjudicators
         WHERE amendment_id=? AND adjudicator_user_id=? LIMIT 1"
    );
    if ($adjudicator_q) {
        $adjudicator_q->bind_param('ii',$aid,$user_id);
        $adjudicator_q->execute(); $is_adjudicator=(bool)$adjudicator_q->get_result()->fetch_row();
        $adjudicator_q->close();
    } else { $is_adjudicator=false; }
    $q=$conn->prepare("SELECT 1 FROM community_charter_amendment_electorate WHERE amendment_id=? AND user_id=? LIMIT 1");
    if($q){$q->bind_param('ii',$aid,$user_id);$q->execute();$is_elector=(bool)$q->get_result()->fetch_row();$q->close();}
    if($is_elector){$q=$conn->prepare("SELECT vote FROM community_charter_amendment_votes WHERE amendment_id=? AND user_id=? LIMIT 1");if($q){$q->bind_param('ii',$aid,$user_id);$q->execute();$my_vote=(string)($q->get_result()->fetch_assoc()['vote']??'');$q->close();}}
?>
<div class="amendment">
<h3><?= cam_page_h($a['title']) ?></h3>
<p><span class="tag"><?= ($a['case_type'] ?? 'amendment') === 'initial_adoption' ? 'تصویب اولیه' : 'اصلاح منشور' ?></span>
<span class="tag"><?= cam_page_h((($a['case_type'] ?? 'amendment')==='initial_adoption' && $a['status']==='draft') ? 'در انتظار اعلام تعارض' : ($status_labels[$a['status']] ?? $a['status'])) ?></span>
<span class="muted">پیشنهاددهنده: <?= cam_page_h($a['proposer_name'] ?? 'کاربر') ?> — نسخه پیشنهادی: <?= cam_page_h($a['proposed_version_number']) ?></span></p>
<p><strong>دلیل:</strong> <?= nl2br(cam_page_h($a['justification'])) ?></p>
<p><strong>ارزیابی پیامدها:</strong> <?= nl2br(cam_page_h($a['impact_assessment'])) ?></p>

<?php if ($a['status']==='draft' && $candidate_status==='invited'): ?>
<div class="item">
<strong>اعلام محرمانه تعارض منافع پیش از ارزیابی</strong>
<p class="muted">پیش از دسترسی به نقش ارزیاب، یکی از وضعیت‌ها را صادقانه انتخاب کنید. کناره‌گیری بدون ذکر دلیل عمومی پذیرفته می‌شود؛ جزئیات این فرم در صفحه عمومی نمایش داده نمی‌شود.</p>
<form method="post" action="community_charter_amendment_action.php">
<input type="hidden" name="csrf_token" value="<?= cam_page_h($csrf) ?>">
<input type="hidden" name="action" value="declare_reviewer_conflict">
<input type="hidden" name="amendment_id" value="<?= $aid ?>">
<label><input type="radio" name="declaration" value="no_conflict" required style="width:auto"> تعارضی نمی‌شناسم</label>
<label><input type="radio" name="declaration" value="recuse" style="width:auto"> بدون افشای دلیل، از ارزیابی این پرونده کناره‌گیری می‌کنم</label>
<label><input type="radio" name="declaration" value="review" style="width:auto"> احتمال تعارض وجود دارد؛ درخواست رسیدگی محرمانه دارم</label>
<p><strong>در صورت درخواست رسیدگی، نوع احتمالی تعارض را مشخص کنید:</strong></p>
<?php
$conflict_categories = [
 'family_personal'=>'رابطه خانوادگی یا شخصی نزدیک',
 'financial'=>'رابطه مالی یا قراردادی',
 'employment'=>'وابستگی استخدامی یا سازمانی مستقیم',
 'professional'=>'همکاری حرفه‌ای نزدیک یا رابطه استاد/شاگردی',
 'personal_dispute'=>'اختلاف شخصی مؤثر یا دعوای جاری',
 'direct_involvement'=>'نقش مستقیم در تهیه، اجرا یا تصمیم مورد بررسی',
 'other'=>'سایر شرایط مؤثر بر بی‌طرفی'
];
foreach ($conflict_categories as $category_key=>$category_label):
?>
<label><input type="checkbox" name="conflict_categories[]" value="<?= cam_page_h($category_key) ?>" style="width:auto"> <?= cam_page_h($category_label) ?></label>
<?php endforeach; ?>
<label>توضیح محرمانه برای رسیدگی‌کنندگان (درخواست بررسی)</label>
<textarea name="private_details" maxlength="10000"></textarea>
<button type="submit">ثبت اعلام محرمانه</button>
</form>
</div>
<?php elseif ($a['status']==='draft' && in_array($candidate_status,['eligible','assigned','recused','review_pending','conflict','insufficient'],true)): ?>
<p class="muted">وضعیت اعلام تعارض شما ثبت شده است: <?= cam_page_h($candidate_status==='assigned'?'واجد شرایط و ارزیاب':($candidate_status==='eligible'?'واجد شرایط':($candidate_status==='recused'?'کناره‌گیری':($candidate_status==='review_pending'?'در انتظار رسیدگی':'تعیین تکلیف شده')))) ?>.</p>
<?php endif; ?>

<?php if ($a['status']==='draft' && $is_adjudicator): ?>
<div class="item">
<strong>رسیدگی محرمانه به اعلام تعارض</strong>
<?php
$conflict_categories = [
 'family_personal'=>'رابطه خانوادگی یا شخصی نزدیک',
 'financial'=>'رابطه مالی یا قراردادی',
 'employment'=>'وابستگی استخدامی یا سازمانی مستقیم',
 'professional'=>'همکاری حرفه‌ای نزدیک یا رابطه استاد/شاگردی',
 'personal_dispute'=>'اختلاف شخصی مؤثر یا دعوای جاری',
 'direct_involvement'=>'نقش مستقیم در تهیه، اجرا یا تصمیم مورد بررسی',
 'other'=>'سایر شرایط مؤثر بر بی‌طرفی'
];
?>
<?php
$pending_conflicts = $conn->prepare(
    "SELECT c.candidate_user_id,c.conflict_categories,c.private_details,u.username
     FROM community_charter_amendment_reviewer_candidates c
     LEFT JOIN users u ON u.id=c.candidate_user_id
     LEFT JOIN community_charter_amendment_conflict_decisions d
       ON d.amendment_id=c.amendment_id AND d.candidate_user_id=c.candidate_user_id
      AND d.adjudicator_user_id=?
     WHERE c.amendment_id=? AND c.status='review_pending' AND d.candidate_user_id IS NULL
     ORDER BY c.candidate_order"
);
if ($pending_conflicts):
$pending_conflicts->bind_param('ii',$user_id,$aid);
if ($pending_conflicts->execute()):
$pending_result=$pending_conflicts->get_result();
if ($pending_result->num_rows===0): ?><p class="muted">در حال حاضر اعلامی برای تصمیم شما باقی نمانده است.</p><?php endif; ?>
<?php while($conflict=$pending_result->fetch_assoc()):
$category_keys=json_decode((string)$conflict['conflict_categories'],true) ?: [];
$category_names=[];
foreach($category_keys as $category_key) if(isset($conflict_categories[$category_key])) $category_names[]=$conflict_categories[$category_key];
?>
<div class="amendment">
<strong>نامزد: <?= cam_page_h($conflict['username'] ?: 'کاربر') ?></strong>
<p>موضوعات اعلام‌شده: <?= cam_page_h(implode('، ',$category_names)) ?></p>
<p><?= nl2br(cam_page_h($conflict['private_details'] ?? '')) ?></p>
<form method="post" action="community_charter_amendment_action.php">
<input type="hidden" name="csrf_token" value="<?= cam_page_h($csrf) ?>">
<input type="hidden" name="action" value="decide_reviewer_conflict">
<input type="hidden" name="amendment_id" value="<?= $aid ?>">
<input type="hidden" name="candidate_user_id" value="<?= (int)$conflict['candidate_user_id'] ?>">
<label>نتیجه رسیدگی</label>
<select name="verdict"><option value="clear">تعارض مؤثر احراز نشد</option><option value="conflict">تعارض مؤثر است؛ نامزد جایگزین شود</option><option value="insufficient">اطلاعات برای تصمیم کافی نیست</option></select>
<label>دلایل محرمانه تصمیم</label>
<textarea name="reasoning" minlength="20" maxlength="10000" required></textarea>
<button type="submit">ثبت تصمیم مستقل</button>
</form>
</div>
<?php endwhile; endif; $pending_conflicts->close(); endif; ?>
</div>
<?php endif; ?>

<?php
$item_stmt=$conn->prepare(
    "SELECT i.action,i.article_code,i.proposed_title,i.proposed_text,i.rationale,
            old.title AS old_title,old.article_text AS old_text
     FROM community_charter_amendment_items i
     LEFT JOIN community_charter_articles old ON old.id=i.target_article_id
     WHERE i.amendment_id=? ORDER BY i.id"
);
if($item_stmt):
$item_stmt->bind_param('i',$aid);
if($item_stmt->execute()):
$item_result=$item_stmt->get_result();
?>
<div class="item">
<strong>متن دقیق تغییرات پیشنهادی</strong>
<?php while($change=$item_result->fetch_assoc()): ?>
<div class="amendment">
<strong><?= cam_page_h($change['article_code']) ?> — <?= cam_page_h(
    $change['action']==='add'?'افزودن ماده':($change['action']==='replace'?'جایگزینی ماده':'حذف ماده تکمیلی')
) ?></strong>
<?php if($change['action']!=='add'): ?>
<p><strong>متن فعلی:</strong> <?= cam_page_h($change['old_title'] ?? '') ?><br><?= nl2br(cam_page_h($change['old_text'] ?? '')) ?></p>
<?php endif; ?>
<?php if($change['action']!=='remove'): ?>
<p><strong>متن پیشنهادی:</strong> <?= cam_page_h($change['proposed_title'] ?? '') ?><br><?= nl2br(cam_page_h($change['proposed_text'] ?? '')) ?></p>
<?php endif; ?>
<p><strong>دلیل این تغییر:</strong> <?= nl2br(cam_page_h($change['rationale'])) ?></p>
</div>
<?php endwhile; ?>
</div>
<?php endif; $item_stmt->close(); endif; ?>
<?php
if (!in_array($a['status'], ['draft','independent_review'], true)) {
    $review_stats = $conn->prepare(
        "SELECT COUNT(*) AS total,SUM(verdict='supports') AS supports,
                SUM(verdict='conflict') AS conflicts,SUM(verdict='unclear') AS unclear
         FROM community_charter_amendment_assessments WHERE amendment_id=?"
    );
    if ($review_stats) {
        $review_stats->bind_param('i',$aid);
        if($review_stats->execute()) {
            $rs=$review_stats->get_result()->fetch_assoc();
            echo '<p class="notice"><strong>نتیجه بررسی مستقل:</strong> '.
                (int)$rs['total'].' ارزیابی؛ حمایت: '.(int)$rs['supports'].
                '، تعارض: '.(int)$rs['conflicts'].'، نامشخص: '.(int)$rs['unclear'].'</p>';
        }
        $review_stats->close();
    }
    $detail_stmt=$conn->prepare(
        "SELECT r.reviewer_user_id,u.username,r.verdict,r.rights_protection,
                r.equality_and_non_discrimination,r.living_beings_and_environment,
                r.power_and_accountability,r.future_generations,r.reasoning,r.evidence
         FROM community_charter_amendment_assessments r
         LEFT JOIN users u ON u.id=r.reviewer_user_id
         WHERE r.amendment_id=? ORDER BY r.submitted_at,r.reviewer_user_id"
    );
    if($detail_stmt):
        $detail_stmt->bind_param('i',$aid);
        if($detail_stmt->execute()):
            $detail_result=$detail_stmt->get_result();
            if($detail_result->num_rows>0):
?>
<div class="item">
<strong>استدلال‌های ارزیابان مستقل</strong>
<?php while($review=$detail_result->fetch_assoc()): ?>
<div class="amendment">
<strong><?= cam_page_h($review['username'] ?: 'ارزیاب مستقل') ?> — <?= cam_page_h($verdict_labels[$review['verdict']] ?? $review['verdict']) ?></strong>
<p class="muted">
حقوق: <?= cam_page_h($dimension_values[$review['rights_protection']] ?? $review['rights_protection']) ?>؛
برابری: <?= cam_page_h($dimension_values[$review['equality_and_non_discrimination']] ?? $review['equality_and_non_discrimination']) ?>؛
جانداران و محیط زیست: <?= cam_page_h($dimension_values[$review['living_beings_and_environment']] ?? $review['living_beings_and_environment']) ?>؛
مهار قدرت: <?= cam_page_h($dimension_values[$review['power_and_accountability']] ?? $review['power_and_accountability']) ?>؛
نسل‌های آینده: <?= cam_page_h($dimension_values[$review['future_generations']] ?? $review['future_generations']) ?>
</p>
<p><?= nl2br(cam_page_h($review['reasoning'])) ?></p>
<?php if($review['evidence']): ?><p class="muted"><strong>شواهد:</strong> <?= nl2br(cam_page_h($review['evidence'])) ?></p><?php endif; ?>
</div>
<?php endwhile; ?>
</div>
<?php
            endif;
        endif;
        $detail_stmt->close();
    endif;
}
if (!empty($a['voting_opens_at']) && !in_array($a['status'], ['draft','independent_review','public_voting'], true)) {
    $vote_stats = $conn->prepare(
        "SELECT
            (SELECT COUNT(*) FROM community_charter_amendment_electorate WHERE amendment_id=?) AS electorate,
            SUM(vote='yes') AS yes_count,SUM(vote='no') AS no_count,
            SUM(vote='abstain') AS abstain_count
         FROM community_charter_amendment_votes WHERE amendment_id=?"
    );
    if ($vote_stats) {
        $vote_stats->bind_param('ii',$aid,$aid);
        if($vote_stats->execute()) {
            $vs=$vote_stats->get_result()->fetch_assoc();
            $cast=(int)$vs['yes_count']+(int)$vs['no_count']+(int)$vs['abstain_count'];
            echo '<p class="notice"><strong>نتیجه رأی مستقیم:</strong> '.
                $cast.' از '.(int)$vs['electorate'].' نفر مشارکت کردند؛ موافق: '.
                (int)$vs['yes_count'].'، مخالف: '.(int)$vs['no_count'].
                '، ممتنع: '.(int)$vs['abstain_count'].'</p>';
        }
        $vote_stats->close();
    }
}
?>
<?php if ($a['status']==='draft' && (int)$a['proposer_user_id']===$user_id): ?>
<form method="post" action="community_charter_amendment_action.php">
<input type="hidden" name="csrf_token" value="<?= cam_page_h($csrf) ?>"><input type="hidden" name="action" value="submit_for_review"><input type="hidden" name="amendment_id" value="<?= $aid ?>">
<button type="submit">ارسال برای بررسی مستقل</button></form>
<?php endif; ?>

<?php if ($a['status']==='independent_review' && $is_reviewer && !$has_assessment): ?>
<h3>ارزیابی مستقل شما</h3>
<form method="post" action="community_charter_amendment_action.php">
<input type="hidden" name="csrf_token" value="<?= cam_page_h($csrf) ?>"><input type="hidden" name="action" value="assess"><input type="hidden" name="amendment_id" value="<?= $aid ?>">
<label>نتیجه کلی</label><select name="verdict"><?php foreach($verdict_labels as $k=>$v): ?><option value="<?= cam_page_h($k) ?>"><?= cam_page_h($v) ?></option><?php endforeach; ?></select>
<?php foreach($dimension_labels as $key=>$label): ?>
<label><?= cam_page_h($label) ?></label><select name="<?= cam_page_h($key) ?>"><?php foreach($dimension_values as $k=>$v): ?><option value="<?= cam_page_h($k) ?>"><?= cam_page_h($v) ?></option><?php endforeach; ?></select>
<?php endforeach; ?>
<label>استدلال و دلایل</label><textarea name="reasoning" minlength="20" maxlength="20000" required></textarea>
<label>شواهد (اختیاری)</label><textarea name="evidence" maxlength="20000"></textarea>
<button type="submit">ثبت ارزیابی نهایی</button></form>
<?php elseif($a['status']==='independent_review' && $is_reviewer && $has_assessment): ?>
<p class="muted">ارزیابی مستقل شما ثبت شده است.</p>
<?php endif; ?>

<?php if($a['status']==='independent_review'): ?>
<form method="post" action="community_charter_amendment_action.php"><input type="hidden" name="csrf_token" value="<?= cam_page_h($csrf) ?>"><input type="hidden" name="action" value="finalize_review"><input type="hidden" name="amendment_id" value="<?= $aid ?>"><button class="secondary" type="submit">جمع‌بندی بررسی (پس از پایان مهلت)</button></form>
<?php endif; ?>

<?php if($a['status']==='public_voting' && $is_elector): ?>
<form method="post" action="community_charter_amendment_action.php">
<input type="hidden" name="csrf_token" value="<?= cam_page_h($csrf) ?>"><input type="hidden" name="action" value="vote"><input type="hidden" name="amendment_id" value="<?= $aid ?>">
<label>رأی شما <?= $my_vote ? '(رأی فعلی: '.cam_page_h($my_vote).')' : '' ?></label>
<select name="vote"><option value="yes">موافق</option><option value="no">مخالف</option><option value="abstain">ممتنع</option></select>
<button type="submit">ثبت یا تغییر رأی</button></form>
<?php endif; ?>

<?php if($a['status']==='public_voting'): ?>
<form method="post" action="community_charter_amendment_action.php"><input type="hidden" name="csrf_token" value="<?= cam_page_h($csrf) ?>"><input type="hidden" name="action" value="finalize_vote"><input type="hidden" name="amendment_id" value="<?= $aid ?>"><button class="secondary" type="submit">جمع‌بندی رأی‌گیری (پس از پایان مهلت)</button></form>
<?php endif; ?>

<?php if($a['status']==='appeal_period' && $is_elector): ?>
<h3>ثبت اعتراض</h3>
<form method="post" action="community_charter_amendment_action.php">
<input type="hidden" name="csrf_token" value="<?= cam_page_h($csrf) ?>"><input type="hidden" name="action" value="appeal"><input type="hidden" name="amendment_id" value="<?= $aid ?>">
<label>دلایل اعتراض و ایرادهای مشخص</label><textarea name="grounds" minlength="30" maxlength="20000" required></textarea>
<label>شواهد (اختیاری)</label><textarea name="evidence" maxlength="20000"></textarea>
<button type="submit">ثبت اعتراض</button></form>
<?php endif; ?>

<?php if($a['status']==='appeal_period'): ?>
<form method="post" action="community_charter_amendment_action.php"><input type="hidden" name="csrf_token" value="<?= cam_page_h($csrf) ?>"><input type="hidden" name="action" value="activate"><input type="hidden" name="amendment_id" value="<?= $aid ?>"><button class="secondary" type="submit">فعال‌سازی پس از پایان مهلت و رسیدگی به اعتراض‌ها</button></form>
<?php endif; ?>

<?php
$appeal_stmt=$conn->prepare("SELECT id,appellant_user_id,grounds,evidence,status FROM community_charter_amendment_appeals WHERE amendment_id=? ORDER BY id");
if($appeal_stmt):
$appeal_stmt->bind_param('i',$aid);
if($appeal_stmt->execute()):
$appeal_result=$appeal_stmt->get_result();
while($appeal=$appeal_result->fetch_assoc()):
$appeal_id=(int)$appeal['id'];$assigned_appeal=false;$my_appeal_assessment=false;
$q=$conn->prepare("SELECT 1 FROM community_charter_amendment_appeal_reviewer_assignments WHERE appeal_id=? AND reviewer_user_id=? LIMIT 1");
if($q){$q->bind_param('ii',$appeal_id,$user_id);$q->execute();$assigned_appeal=(bool)$q->get_result()->fetch_row();$q->close();}
if($assigned_appeal){$q=$conn->prepare("SELECT 1 FROM community_charter_amendment_appeal_reviewers WHERE appeal_id=? AND reviewer_user_id=? LIMIT 1");if($q){$q->bind_param('ii',$appeal_id,$user_id);$q->execute();$my_appeal_assessment=(bool)$q->get_result()->fetch_row();$q->close();}}
?>
<div class="item">
<strong>اعتراض شماره <?= $appeal_id ?> — <?= cam_page_h($appeal_status_labels[$appeal['status']] ?? 'وضعیت نامشخص') ?></strong>
<p><?= nl2br(cam_page_h($appeal['grounds'])) ?></p>
<?php if($appeal['evidence']): ?><p class="muted"><?= nl2br(cam_page_h($appeal['evidence'])) ?></p><?php endif; ?>
<?php
$appeal_detail=$conn->prepare(
    "SELECT r.reviewer_user_id,u.username,r.verdict,r.reasoning
     FROM community_charter_amendment_appeal_reviewers r
     LEFT JOIN users u ON u.id=r.reviewer_user_id
     WHERE r.appeal_id=? ORDER BY r.submitted_at,r.reviewer_user_id"
);
if($appeal_detail):
$appeal_detail->bind_param('i',$appeal_id);
if($appeal_detail->execute()):
$appeal_detail_result=$appeal_detail->get_result();
while($assessment=$appeal_detail_result->fetch_assoc()):
$appeal_verdict_labels=['uphold'=>'اعتراض وارد است','dismiss'=>'اعتراض وارد نیست','unclear'=>'نامشخص'];
?>
<div class="item">
<strong><?= cam_page_h($assessment['username'] ?: 'ارزیاب اعتراض') ?> — <?= cam_page_h($appeal_verdict_labels[$assessment['verdict']] ?? $assessment['verdict']) ?></strong>
<p><?= nl2br(cam_page_h($assessment['reasoning'])) ?></p>
</div>
<?php endwhile; endif; $appeal_detail->close(); endif; ?>
<?php if($assigned_appeal && in_array($appeal['status'],['submitted','under_review'],true)): ?>
<form method="post" action="community_charter_amendment_action.php">
<input type="hidden" name="csrf_token" value="<?= cam_page_h($csrf) ?>"><input type="hidden" name="action" value="assess_appeal"><input type="hidden" name="appeal_id" value="<?= $appeal_id ?>">
<label>نتیجه بررسی مستقل اعتراض</label><select name="verdict"><option value="uphold">اعتراض وارد است</option><option value="dismiss">اعتراض وارد نیست</option><option value="unclear">نیازمند بررسی بیشتر</option></select>
<label>استدلال</label><textarea name="reasoning" minlength="20" maxlength="20000" required></textarea>
<button type="submit"><?= $my_appeal_assessment ? 'به‌روزرسانی ارزیابی' : 'ثبت ارزیابی اعتراض' ?></button></form>
<?php endif; ?>
</div>
<?php endwhile; endif; $appeal_stmt->close(); endif; ?>
</div>
<?php endforeach; ?>
</section>
</div>
<script>
(function(){
 const holder=document.getElementById('items'), add=document.getElementById('add-item');
 if(!holder||!add)return;
 function wire(row){
   const action=row.querySelector('.item-action'), target=row.querySelector('.target-article');
   function sync(){
     const isAdd=action.value==='add', isRemove=action.value==='remove';
     // همه آرایه‌ها باید هم‌اندازه به سرور برسند؛ فیلدهای غیرمرتبط خالی می‌مانند، نه disabled.
     const codeInput=row.querySelector('[name="article_code[]"]');
     if(!isAdd && target.value){
       const opt=target.options[target.selectedIndex];
       codeInput.value=opt.dataset.code||'';
       codeInput.dataset.autoFilled='1';
     } else if(isAdd){
       target.value='';
       if(codeInput.dataset.autoFilled==='1')codeInput.value='';
       codeInput.dataset.autoFilled='0';
     }
   }
   action.addEventListener('change',sync);target.addEventListener('change',sync);
   row.querySelector('.remove-item').addEventListener('click',function(){
     if(holder.querySelectorAll('.amendment-item').length>1)row.remove();
   });
   sync();
 }
 const first=holder.querySelector('.amendment-item');if(first)wire(first);
 add.addEventListener('click',function(){
   if(holder.querySelectorAll('.amendment-item').length>=30)return;
   const row=first.cloneNode(true);
   row.querySelectorAll('input,textarea').forEach(el=>el.value='');
   row.querySelectorAll('select').forEach(el=>el.selectedIndex=0);
   holder.appendChild(row);wire(row);
 });
})();
</script>
</body>
</html>
