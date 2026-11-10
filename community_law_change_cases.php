<?php
session_start();
require __DIR__ . '/db.php';
require_once __DIR__ . '/community_law_helpers.php';
require_once __DIR__ . '/community_law_change_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$user_id=(int)$_SESSION['user_id'];
$community_id=(int)($_GET['community_id']??0);
if ($community_id<=0) { http_response_code(400); exit('شناسه جامعه معتبر نیست.'); }

$community_stmt=$conn->prepare('SELECT id,name,parent_community_id FROM communities WHERE id=? LIMIT 1');
$community_stmt->bind_param('i',$community_id);
$community_stmt->execute();
$community=$community_stmt->get_result()->fetch_assoc();
$community_stmt->close();
if (!$community) { http_response_code(404); exit('جامعه پیدا نشد.'); }

$member_stmt=$conn->prepare('SELECT 1 FROM community_members WHERE community_id=? AND user_id=? LIMIT 1');
$member_stmt->bind_param('ii',$community_id,$user_id);
$member_stmt->execute();
$is_member=(bool)$member_stmt->get_result()->fetch_row();
$member_stmt->close();

if (empty($_SESSION['community_law_change_csrf'])) {
    $_SESSION['community_law_change_csrf']=bin2hex(random_bytes(32));
}
$csrf=$_SESSION['community_law_change_csrf'];
function clcp_h($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }

$status=(string)($_GET['status']??'');
$status_messages=[
    'schema'=>'ساختار گردش‌کار تغییر قانون هنوز در پایگاه داده نصب نشده است.',
    'request_submitted'=>'درخواست ثبت شد و برای ارزیابی مستقل ارسال گردید؛ قانون تا تصمیم معتبر تغییر نمی‌کند.',
    'request_failed'=>'درخواست ثبت نشد؛ عضویت، دامنه قانون، مهلت‌ها یا وجود پرونده باز را بررسی کنید.',
    'invalid_date'=>'تاریخ واردشده معتبر نیست.',
    'assessment_saved'=>'ارزیابی مستقل ثبت شد.',
    'assessment_failed'=>'ارزیابی ثبت نشد؛ شاید ارزیاب تعیین‌شده نباشید یا پرونده بسته شده باشد.',
    'vote_saved'=>'رأی شما ثبت شد.',
    'vote_failed'=>'رأی ثبت نشد؛ فقط اعضای فهرست رأی‌دهندگان و در مهلت مجاز می‌توانند رأی دهند.',
    'vote_approved'=>'درخواست با رأی جامعهٔ مادر تصویب شد و اثر قانونی آن اعمال گردید.',
    'vote_rejected'=>'درخواست در رأی جامعهٔ مادر تصویب نشد؛ قانون بدون تغییر باقی ماند.',
    'vote_insufficient'=>'حدنصاب مشارکت رأی‌گیری تأمین نشد؛ قانون بدون تغییر باقی ماند.',
    'finalize_failed'=>'هنوز مهلت رأی‌گیری تمام نشده یا نتیجه قابل نهایی‌سازی نیست.',
    'notice_read'=>'اعلان خوانده‌شده علامت‌گذاری شد.',
    'not_member'=>'برای تغییر وضعیت خواندن اعلان باید عضو جامعه باشید.',
    'save_error'=>'ذخیره‌سازی انجام نشد؛ دوباره تلاش کنید.',
    'invalid_action'=>'درخواست نامعتبر است.'
];

$schema_ready=community_law_tables_ready($conn) && community_law_change_tables_ready($conn);
$notices=[]; $cases=[]; $changeable_laws=[];
if ($schema_ready) {
    // اعلان‌های هر جامعه فقط برای اعضای همان جامعه قابل مشاهده است.
    if ($is_member) {
        $notice_stmt=$conn->prepare(
        "SELECT id,case_id,event_type,notice_text,created_at,read_at,notice_source
         FROM (
             SELECT id,case_id,event_type,notice_text,created_at,read_at,'change' AS notice_source
             FROM community_law_community_notices WHERE community_id=?
             UNION ALL
             SELECT id,NULL AS case_id,transition AS event_type,notice_text,created_at,read_at,'scope' AS notice_source
             FROM community_law_scope_notices WHERE community_id=?
             UNION ALL
             SELECT id,NULL AS case_id,event_type,notice_text,created_at,read_at,'version' AS notice_source
             FROM community_law_version_notices WHERE community_id=?
         ) notices
         ORDER BY created_at DESC,id DESC LIMIT 30"
    );
    if ($notice_stmt) {
        $notice_stmt->bind_param('iii',$community_id,$community_id,$community_id);
        if ($notice_stmt->execute()) {
            $res=$notice_stmt->get_result();
            while ($row=$res->fetch_assoc()) $notices[]=$row;
        }
        $notice_stmt->close();
        }
    }

    $case_stmt=$conn->prepare(
        "SELECT c.id,c.law_id,c.law_version_id,c.request_type,c.status,c.review_result,
                c.requested_by,c.requester_community_id,c.reason,c.evidence,c.emergency_requested,
                c.created_at,c.review_completed_at,c.review_summary,c.vote_opened_at,c.vote_closes_at,
                c.electorate_count,c.vote_result,c.effect_applied_at,c.effect_summary,
                l.law_code,l.status AS law_status,l.originating_community_id,
                origin.name AS originating_community_name,v.title,
                (SELECT COUNT(*) FROM community_law_change_reviewers r WHERE r.case_id=c.id) AS reviewer_count,
                (SELECT COUNT(*) FROM community_law_change_assessments a WHERE a.case_id=c.id) AS assessment_count,
                (SELECT COUNT(*) FROM community_law_change_assessments a WHERE a.case_id=c.id AND a.reviewer_user_id=?) AS has_assessed,
                (SELECT COUNT(*) FROM community_law_change_reviewers r WHERE r.case_id=c.id AND r.reviewer_user_id=?) AS is_reviewer,
                (SELECT COUNT(*) FROM community_law_change_electorate e WHERE e.case_id=c.id AND e.user_id=?) AS is_elector,
                (SELECT COUNT(*) FROM community_law_change_votes x WHERE x.case_id=c.id AND x.user_id=?) AS has_voted,
                (SELECT x.vote FROM community_law_change_votes x WHERE x.case_id=c.id AND x.user_id=? LIMIT 1) AS my_vote,
                (SELECT COUNT(*) FROM community_law_change_votes x WHERE x.case_id=c.id) AS vote_count,
                (SELECT COUNT(*) FROM community_law_change_votes x WHERE x.case_id=c.id AND x.vote='yes') AS yes_count,
                (SELECT COUNT(*) FROM community_law_change_votes x WHERE x.case_id=c.id AND x.vote='no') AS no_count,
                (SELECT COUNT(*) FROM community_law_change_votes x WHERE x.case_id=c.id AND x.vote='abstain') AS abstain_count
         FROM community_law_change_cases c
         JOIN community_laws l ON l.id=c.law_id
         JOIN community_law_versions v ON v.id=c.law_version_id
         JOIN communities origin ON origin.id=l.originating_community_id
         WHERE c.requester_community_id=? OR l.originating_community_id=?
            OR EXISTS (SELECT 1 FROM community_law_community_notices n
                       WHERE n.case_id=c.id AND n.community_id=?)
         ORDER BY c.created_at DESC,c.id DESC LIMIT 50"
    );
    if ($is_member && $case_stmt) {
        $case_stmt->bind_param('iiiiiiii',$user_id,$user_id,$user_id,$user_id,$user_id,$community_id,$community_id,$community_id);
        if ($case_stmt->execute()) {
            $res=$case_stmt->get_result();
            while ($row=$res->fetch_assoc()) $cases[]=$row;
        }
        $case_stmt->close();
    }

    $law_result=$conn->query(
        "SELECT l.id AS law_id,l.law_code,l.status,l.expires_at,l.effective_at,
                l.originating_community_id,origin.name AS originating_community_name,
                v.id AS version_id,v.title
         FROM community_laws l
         JOIN community_law_versions v ON v.id=l.current_version_id
         JOIN communities origin ON origin.id=l.originating_community_id
         WHERE l.status IN ('approved','suspended') AND v.status='approved'
           AND l.repealed_at IS NULL
         ORDER BY l.updated_at DESC,l.id DESC LIMIT 100"
    );
    if ($law_result) {
        while ($row=$law_result->fetch_assoc()) {
            if ((int)$row['originating_community_id']===$community_id ||
                community_law_scope_matches($conn,(int)$row['version_id'],$community_id)) {
                $changeable_laws[]=$row;
            }
        }
        $law_result->free();
    }
}
$type_labels=[
    'suspend'=>'تعلیق قانون','repeal'=>'لغو قانون',
    'review_expiry'=>'بازبینی پایان اعتبار','reinstate'=>'بازگرداندن قانون از تعلیق'
];
$case_status_labels=[
    'submitted'=>'ثبت‌شده','under_review'=>'در حال بررسی مستقل',
    'review_complete'=>'بررسی تکمیل شد؛ نتیجه نامعین',
    'awaiting_vote'=>'در انتظار رأی جامعهٔ مادر',
    'approved'=>'تصویب و اثر اعمال‌شده','rejected'=>'ردشده','withdrawn'=>'پس‌گرفته‌شده'
];
$notice_labels=[
    'change_requested'=>'درخواست تازه','change_vote_result'=>'نتیجه رأی جامعه مادر',
    'suspension_requested'=>'درخواست تعلیق','suspended'=>'تعلیق قانون',
    'repealed'=>'لغو قانون','expiry_review_requested'=>'درخواست بازبینی اعتبار',
    'expiry_updated'=>'به‌روزرسانی اعتبار','reinstated'=>'بازگردانی قانون',
    'scope_entered'=>'ورود به دامنهٔ قانون','scope_left'=>'خروج از دامنهٔ قانون',
    'version_activated'=>'فعال‌شدن نسخهٔ جدید قانون'
];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>رسیدگی به تغییر قوانین — <?= clcp_h($community['name']) ?></title>
<style>
body{font-family:Tahoma,Arial,sans-serif;background:#f3f6fa;color:#1e293b;margin:0;padding:12px;line-height:1.9}
.container{max-width:980px;margin:auto}.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin:12px 0;box-shadow:0 2px 8px #0f172a0a}
h2,h3{margin:0 0 10px}p{margin:8px 0}.muted,.meta{color:#64748b;font-size:13px}.meta{margin:5px 0}
.notice{background:#f8fafc;border-right:4px solid #64748b;padding:10px 12px;border-radius:7px;margin:9px 0}
.unread{border-right-color:#2563eb;background:#eff6ff}
.warning{border-right-color:#d97706;background:#fffbeb}
label{display:block;font-weight:bold;margin:9px 0 4px}
input,select,textarea,button{font:inherit;width:100%;box-sizing:border-box;padding:9px;border:1px solid #cbd5e1;border-radius:7px;background:#fff}
textarea{min-height:90px;resize:vertical}button{background:#1e40af;color:#fff;border:0;font-weight:bold;cursor:pointer;margin-top:10px}
button.secondary{background:#475569}details{border-top:1px solid #e2e8f0;padding-top:10px;margin-top:10px}summary{font-weight:bold;cursor:pointer}
.row{display:flex;gap:10px;align-items:center}.row input{width:auto}.case-title{display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap}
@media(max-width:600px){body{padding:6px}.card{padding:12px}input,select,textarea,button{font-size:16px}}
</style>
</head>
<body>
<div class="container">
<div class="card">
    <h2>رسیدگی به تغییر قوانین: <?= clcp_h($community['name']) ?></h2>
    <p class="muted">درخواست تعلیق، لغو، بازبینی اعتبار یا بازگردانی قانون در این بخش بررسی می‌شود. اعتراض یا درخواست اضطراری به‌تنهایی قانون را متوقف نمی‌کند؛ تغییر فقط پس از بررسی مستقل و رأی معتبر جامعهٔ مادر اعمال می‌شود.</p>
    <p><a href="community_laws.php?community_id=<?= $community_id ?>">بازگشت به صفحه قوانین</a> — <a href="community.php?id=<?= $community_id ?>">صفحه جامعه</a></p>
</div>
<?php if (isset($status_messages[$status])): ?>
<div class="card notice"><?= clcp_h($status_messages[$status]) ?></div>
<?php endif; ?>
<?php if (!$schema_ready): ?>
<div class="card notice warning">
    <strong>ساختار این بخش هنوز نصب نشده است.</strong>
    <p>Migrationهای گردش‌کار تغییر قانون باید پس از بررسی در پایگاه داده نصب شوند. این صفحه در نبود ساختار کامل هیچ تغییری ایجاد نمی‌کند.</p>
</div>
<?php else: ?>

<div class="card">
    <h3>اعلان‌های قوانین این جامعه</h3>
    <?php if (!$notices): ?>
        <p class="muted">اعلان تازه‌ای برای این جامعه وجود ندارد.</p>
    <?php else: ?>
        <?php foreach ($notices as $notice): ?>
            <div class="notice <?= $notice['read_at']===null?'unread':'' ?>">
                <strong><?= clcp_h($notice_labels[$notice['event_type']]??$notice['event_type']) ?><?= $notice['notice_source']==='scope'?' — تغییر دامنهٔ شمول':($notice['notice_source']==='version'?' — ابلاغ نسخهٔ جدید':' — پرونده #'.(int)$notice['case_id']) ?></strong>
                <div><?= clcp_h($notice['notice_text']) ?></div>
                <div class="meta"><?= clcp_h($notice['created_at']) ?><?= $notice['read_at']===null?' — خوانده‌نشده':'' ?></div>
                <?php if ($is_member && $notice['read_at']===null): ?>
                    <form method="post" action="community_law_change_action.php">
                        <input type="hidden" name="csrf_token" value="<?= clcp_h($csrf) ?>">
                        <input type="hidden" name="community_id" value="<?= $community_id ?>">
                        <input type="hidden" name="notice_id" value="<?= (int)$notice['id'] ?>">
                        <input type="hidden" name="notice_source" value="<?= clcp_h($notice['notice_source']) ?>">
                        <button class="secondary" name="action" value="mark_notice_read">علامت‌گذاری به‌عنوان خوانده‌شده</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="card">
    <h3>ثبت درخواست برای قوانین مشمول</h3>
    <?php if (!$is_member): ?>
        <p class="muted">برای ثبت درخواست باید عضو این جامعه باشید. ارزیابی و رأی فقط برای افراد تعیین‌شده و واجد شرایط فعال می‌شود.</p>
    <?php elseif (!$changeable_laws): ?>
        <p class="muted">قانون مادرِ مشمولی برای ثبت درخواست در این جامعه پیدا نشد.</p>
    <?php else: ?>
        <?php foreach ($changeable_laws as $law): ?>
            <details>
                <summary><?= clcp_h($law['title']) ?> — <?= clcp_h($law['law_code']) ?> (<?= $law['status']==='suspended'?'تعلیق‌شده':'مصوب' ?>)</summary>
                <div class="meta">جامعهٔ مبدأ: <?= clcp_h($law['originating_community_name']) ?> — شناسه <?= (int)$law['originating_community_id'] ?> — پایان اعتبار: <?= clcp_h($law['expires_at']??'بدون تاریخ پایان') ?></div>
                <form method="post" action="community_law_change_action.php">
                    <input type="hidden" name="csrf_token" value="<?= clcp_h($csrf) ?>">
                    <input type="hidden" name="community_id" value="<?= $community_id ?>">
                    <input type="hidden" name="law_id" value="<?= (int)$law['law_id'] ?>">
                    <label>نوع درخواست</label>
                    <select name="request_type" required>
                        <?php if ($law['status']==='approved'): ?>
                            <?php if ($law['effective_at']===null || strtotime($law['effective_at'])<=time()): ?>
                                <?php if ($law['expires_at']===null || strtotime($law['expires_at'])>time()): ?>
                                    <option value="suspend">درخواست تعلیق قانون</option>
                                <?php endif; ?>
                            <?php endif; ?>
                            <option value="repeal">درخواست لغو قانون</option>
                            <option value="review_expiry">درخواست بازبینی تاریخ پایان اعتبار</option>
                        <?php else: ?>
                            <option value="repeal">درخواست لغو قانون تعلیق‌شده</option>
                            <?php if ($law['expires_at']===null || strtotime($law['expires_at'])>time()+8*86400): ?>
                                <option value="reinstate">درخواست بازگرداندن قانون از تعلیق</option>
                            <?php endif; ?>
                        <?php endif; ?>
                    </select>
                    <label>دلیل و مستندات درخواست</label>
                    <textarea name="reason" maxlength="30000" required placeholder="دلیل روشن، پیامدها و شواهد را شرح دهید."></textarea>
                    <label>شواهد تکمیلی (اختیاری)</label>
                    <textarea name="evidence" maxlength="30000"></textarea>
                    <label>تاریخ جدید پایان اعتبار (فقط برای بازبینی اعتبار؛ باید بیش از ۸ روز آینده باشد)</label>
                    <input type="datetime-local" name="requested_effective_at">
                    <div class="row">
                        <input type="checkbox" name="emergency_requested" value="1">
                        <span>درخواست تعلیق اضطراری به دلیل خطر فوری جدی (فقط برای نوع تعلیق)</span>
                    </div>
                    <button type="submit" name="action" value="open_case">ثبت درخواست برای بررسی مستقل</button>
                </form>
            </details>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="card">
    <h3>پرونده‌های تغییر قانون و رأی‌ها</h3>
    <?php if (!$cases): ?>
        <p class="muted">پرونده‌ای برای این جامعه ثبت نشده است.</p>
    <?php else: ?>
        <?php foreach ($cases as $case): ?>
            <?php
                $vote_closed=$case['vote_closes_at']!==null && strtotime($case['vote_closes_at'])<=time();
                $case_type=$type_labels[$case['request_type']]??$case['request_type'];
                $case_status=$case_status_labels[$case['status']]??$case['status'];
                $review_summary_data=json_decode((string)($case['review_summary']??''),true);
                $review_block_reason=is_array($review_summary_data)?(string)($review_summary_data['block_reason']??''):'';
                $review_messages=[
                    'insufficient_review_support'=>'بررسی مستقل تکمیل شد، اما شواهد و ارزیابی‌ها حدنصاب حمایت لازم را تأمین نکردند.',
                    'insufficient_parent_electorate'=>'بررسی مستقل تکمیل شد، اما تعداد اعضای واجد رأی در جامعهٔ مادر برای تشکیل رأی‌گیری کافی نیست.',
                    'missing_or_invalid_parent_voting_rule'=>'بررسی مستقل تکمیل شد، اما قواعد رأی‌گیری مصوب و معتبر برای همین نسخهٔ قانون موجود نیست؛ رأی‌گیری آغاز نشده است.'
                ];
                $review_message=$review_messages[$review_block_reason]??'بررسی تکمیل شد، اما شرایط لازم برای ارجاع پرونده به رأی فراهم نشد.';

                // دلایل ارزیابی‌ها و رویدادهای مرتبط با همین پرونده، فقط برای مشاهده خوانده می‌شوند.
                $case_assessments=[];
                $assessment_stmt=$conn->prepare(
                    'SELECT reviewer_user_id,verdict,reasoning,evidence,submitted_at
                     FROM community_law_change_assessments WHERE case_id=?
                     ORDER BY submitted_at ASC,id ASC'
                );
                if ($assessment_stmt) {
                    $case_id_for_read=(int)$case['id'];
                    $assessment_stmt->bind_param('i',$case_id_for_read);
                    if ($assessment_stmt->execute()) {
                        $assessment_result=$assessment_stmt->get_result();
                        while ($assessment_row=$assessment_result->fetch_assoc()) $case_assessments[]=$assessment_row;
                    }
                    $assessment_stmt->close();
                }

                $case_history=[];
                $history_stmt=$conn->prepare(
                    'SELECT event_type,actor_user_id,details,created_at
                     FROM community_law_history
                     WHERE law_id=? AND details LIKE ?
                     ORDER BY id ASC LIMIT 50'
                );
                if ($history_stmt) {
                    $law_id_for_read=(int)$case['law_id'];
                    $case_marker='%"case_id":'.(int)$case['id'].'%';
                    $history_stmt->bind_param('is',$law_id_for_read,$case_marker);
                    if ($history_stmt->execute()) {
                        $history_result=$history_stmt->get_result();
                        while ($history_row=$history_result->fetch_assoc()) $case_history[]=$history_row;
                    }
                    $history_stmt->close();
                }
            ?>
            <div class="card">
                <div class="case-title">
                    <strong>پرونده #<?= (int)$case['id'] ?> — <?= clcp_h($case['title']) ?></strong>
                    <strong><?= clcp_h($case_status) ?></strong>
                </div>
                <div class="meta">
                    <?= clcp_h($case_type) ?> — قانون <?= clcp_h($case['law_code']) ?> —
                    جامعهٔ مادر: <?= clcp_h($case['originating_community_name']) ?> —
                    ثبت‌کننده #<?= (int)$case['requested_by'] ?> — <?= clcp_h($case['created_at']) ?>
                </div>
                <p><?= nl2br(clcp_h($case['reason'])) ?></p>
                <?php if (!empty($case['evidence'])): ?><p class="muted">شواهد: <?= nl2br(clcp_h($case['evidence'])) ?></p><?php endif; ?>
                <?php if ((int)$case['emergency_requested']===1): ?><div class="notice warning">درخواست تعلیق اضطراری مطرح شده است؛ این علامت به‌خودی‌خود قانون را متوقف نمی‌کند.</div><?php endif; ?>
                <div class="meta">
                    ارزیابان: <?= (int)$case['assessment_count'] ?> از <?= (int)$case['reviewer_count'] ?> —
                    رأی‌دهندگان: <?= (int)$case['vote_count'] ?> از <?= (int)$case['electorate_count'] ?> —
                    موافق: <?= (int)$case['yes_count'] ?>، مخالف: <?= (int)$case['no_count'] ?>، ممتنع: <?= (int)$case['abstain_count'] ?>
                </div>
                <?php if ($case['vote_closes_at']!==null): ?>
                    <div class="meta">مهلت رأی‌گیری تا <?= clcp_h($case['vote_closes_at']) ?></div>
                <?php endif; ?>
                <?php if ($case['effect_applied_at']!==null): ?>
                    <div class="notice">اثر حقوقی در <?= clcp_h($case['effect_applied_at']) ?> اعمال شده است.</div>
                <?php elseif ($case['status']==='review_complete'): ?>
                    <div class="notice warning"><?= clcp_h($review_message) ?> می‌توان پس از رفع مانع، درخواست تازه‌ای ثبت کرد.</div>
                <?php endif; ?>

                <details>
                    <summary>مشاهده ارزیابی‌های مستقل و استدلال‌ها (<?= count($case_assessments) ?>)</summary>
                    <?php if (!$case_assessments): ?>
                        <p class="muted">هنوز ارزیابی‌ای ثبت نشده است.</p>
                    <?php else: ?>
                        <?php foreach ($case_assessments as $assessment): ?>
                            <div class="notice">
                                <div class="meta">ارزیاب #<?= (int)$assessment['reviewer_user_id'] ?> —
                                    <?= clcp_h($assessment['submitted_at']) ?> —
                                    نتیجه:
                                    <?= $assessment['verdict']==='uphold'?'موجه':($assessment['verdict']==='dismiss'?'ناموجه':'نامعین') ?>
                                </div>
                                <p><?= nl2br(clcp_h($assessment['reasoning'])) ?></p>
                                <?php if (!empty($assessment['evidence'])): ?>
                                    <p class="muted">شواهد: <?= nl2br(clcp_h($assessment['evidence'])) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </details>

                <details>
                    <summary>تاریخچه رویدادهای همین پرونده (<?= count($case_history) ?>)</summary>
                    <?php if (!$case_history): ?>
                        <p class="muted">رویداد جزئی‌تری برای این پرونده در تاریخچه ثبت نشده است.</p>
                    <?php else: ?>
                        <?php foreach ($case_history as $history): ?>
                            <div class="notice">
                                <div class="meta"><?= clcp_h($history['event_type']) ?> —
                                    <?= clcp_h($history['created_at']) ?>
                                    <?php if ($history['actor_user_id']!==null): ?>
                                        — عامل ثبت #<?= (int)$history['actor_user_id'] ?>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($history['details'])): ?>
                                    <pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?= clcp_h($history['details']) ?></pre>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </details>

                <?php if ($case['status']==='under_review' && (int)$case['is_reviewer']===1 && (int)$case['has_assessed']===0): ?>
                    <details>
                        <summary>ثبت ارزیابی مستقل این پرونده</summary>
                        <form method="post" action="community_law_change_action.php">
                            <input type="hidden" name="csrf_token" value="<?= clcp_h($csrf) ?>">
                            <input type="hidden" name="community_id" value="<?= $community_id ?>">
                            <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                            <label>نتیجه بررسی</label>
                            <select name="verdict" required>
                                <option value="uphold">درخواست از نظر مستندات موجه است</option>
                                <option value="dismiss">درخواست از نظر مستندات موجه نیست</option>
                                <option value="unclear">شواهد برای نتیجه قطعی کافی نیست</option>
                            </select>
                            <label>استدلال (حداقل ۲۰ نویسه)</label>
                            <textarea name="reasoning" minlength="20" maxlength="20000" required></textarea>
                            <label>شواهد تکمیلی</label>
                            <textarea name="evidence" maxlength="20000"></textarea>
                            <button type="submit" name="action" value="assess_case">ثبت ارزیابی</button>
                        </form>
                    </details>
                <?php elseif ($case['status']==='under_review' && (int)$case['is_reviewer']===1): ?>
                    <p class="muted">ارزیابی شما ثبت شده است.</p>
                <?php endif; ?>

                <?php if ($case['status']==='awaiting_vote' && (int)$case['is_elector']===1 && !$vote_closed): ?>
                    <details>
                        <summary><?= (int)$case['has_voted']===1?'ویرایش رأی ثبت‌شده':'ثبت رأی جامعهٔ مادر' ?></summary>
                        <form method="post" action="community_law_change_action.php">
                            <input type="hidden" name="csrf_token" value="<?= clcp_h($csrf) ?>">
                            <input type="hidden" name="community_id" value="<?= $community_id ?>">
                            <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                            <label>رأی شما</label>
                            <select name="vote" required>
                                <option value="yes" <?= $case['my_vote']==='yes'?'selected':'' ?>>موافق تغییر قانون</option>
                                <option value="no" <?= $case['my_vote']==='no'?'selected':'' ?>>مخالف تغییر قانون</option>
                                <option value="abstain" <?= $case['my_vote']==='abstain'?'selected':'' ?>>ممتنع</option>
                            </select>
                            <label>توضیح رأی (اختیاری)</label>
                            <textarea name="comment" maxlength="5000"></textarea>
                            <button type="submit" name="action" value="cast_vote">ثبت رأی</button>
                        </form>
                    </details>
                <?php endif; ?>

                <?php if ($case['status']==='awaiting_vote' && $vote_closed): ?>
                    <form method="post" action="community_law_change_action.php">
                        <input type="hidden" name="csrf_token" value="<?= clcp_h($csrf) ?>">
                        <input type="hidden" name="community_id" value="<?= $community_id ?>">
                        <input type="hidden" name="case_id" value="<?= (int)$case['id'] ?>">
                        <button class="secondary" type="submit" name="action" value="finalize_vote">محاسبه و ثبت نتیجه نهایی رأی</button>
                    </form>
                <?php endif; ?>

                <?php if ($case['status']==='approved' && $case['effect_summary']): ?>
                    <details><summary>مشاهده خلاصه اثر اعمال‌شده</summary><pre><?= clcp_h($case['effect_summary']) ?></pre></details>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>
</div>
</body>
</html>
