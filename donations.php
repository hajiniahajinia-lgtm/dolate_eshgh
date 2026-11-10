<?php
session_start();
require_once __DIR__ . '/db.php';

if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$user_id = (int)$_SESSION['user_id'];
$community_id = (int)($_GET['community_id'] ?? $_POST['community_id'] ?? 0);
if ($community_id < 1) { http_response_code(400); exit('جامعهٔ معتبر انتخاب نشده است.'); }

function donation_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function donation_num($v): string { return number_format((float)$v, 2, '.', ','); }
function donation_fail(string $message): void { throw new RuntimeException($message); }

$cm = $conn->prepare("SELECT c.id,c.name FROM communities c JOIN community_members m ON m.community_id=c.id WHERE c.id=? AND m.user_id=? LIMIT 1");
$cm->bind_param('ii', $community_id, $user_id);
$cm->execute();
$community = $cm->get_result()->fetch_assoc();
$cm->close();
if (!$community) { http_response_code(403); exit('فقط اعضای جامعه می‌توانند دفتر کمک‌های آن را ببینند.'); }

$required = ['community_donations','community_donation_votes','community_finance_settings','community_finance_transactions','community_assets','community_asset_movements'];
$schema = $conn->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('community_donations','community_donation_votes','community_finance_settings','community_finance_transactions','community_assets','community_asset_movements')");
$found = [];
while ($r = $schema->fetch_assoc()) $found[] = $r['table_name'];
if (count(array_diff($required, $found)) > 0) {
    http_response_code(500);
    exit('ساختار لازم برای دفتر کمک‌ها یا بانک مالی کامل نیست. لطفاً جدول‌های مهاجرت‌شده را بررسی کنید.');
}

$currency = 'IRR';
$cur = $conn->prepare("SELECT currency_code FROM community_finance_settings WHERE community_id=? LIMIT 1");
$cur->bind_param('i', $community_id);
$cur->execute();
$currency_row = $cur->get_result()->fetch_assoc();
$cur->close();
if ($currency_row && !empty($currency_row['currency_code'])) $currency = strtoupper($currency_row['currency_code']);

if (empty($_SESSION['community_donations_csrf'])) $_SESSION['community_donations_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['community_donations_csrf'];
$message = '';
$error = '';

/* نهایی‌سازی رأی‌های منقضی‌شده؛ فقط پس از قفل‌کردن رکورد کمک */
function donation_finalize_expired(mysqli $conn, int $community_id): array {
    $messages = [];
    $q = $conn->prepare("SELECT id FROM community_donations WHERE community_id=? AND ((status='acceptance_voting' AND acceptance_vote_deadline<=NOW()) OR (status='receipt_voting' AND receipt_vote_deadline<=NOW())) ORDER BY id LIMIT 100");
    $q->bind_param('i', $community_id);
    $q->execute();
    $res = $q->get_result();
    $ids = [];
    while ($r = $res->fetch_assoc()) $ids[] = (int)$r['id'];
    $q->close();

    foreach ($ids as $id) {
        $conn->begin_transaction();
        try {
            $lock = $conn->prepare("SELECT * FROM community_donations WHERE id=? AND community_id=? FOR UPDATE");
            $lock->bind_param('ii', $id, $community_id);
            $lock->execute();
            $d = $lock->get_result()->fetch_assoc();
            $lock->close();
            if (!$d) { $conn->commit(); continue; }

            // شناسه‌ها فقط از پرس‌وجوی منقضی‌شده آمده‌اند؛ زمان را در SQL با NOW() سنجیده‌ایم
            // تا اختلاف منطقهٔ زمانی PHP و MySQL باعث توقف یا تأخیر رأی‌گیری نشود.
            $stage = null;
            if ($d['status'] === 'acceptance_voting') $stage = 'acceptance';
            if ($d['status'] === 'receipt_voting') $stage = 'receipt';
            if ($stage === null) { $conn->commit(); continue; }

            $vq = $conn->prepare("SELECT SUM(vote_choice='approve') AS yes_count, SUM(vote_choice='reject') AS no_count FROM community_donation_votes WHERE donation_id=? AND community_id=? AND vote_stage=?");
            $vq->bind_param('iis', $id, $community_id, $stage);
            $vq->execute();
            $votes = $vq->get_result()->fetch_assoc() ?: [];
            $vq->close();
            $yes = (int)($votes['yes_count'] ?? 0);
            $no = (int)($votes['no_count'] ?? 0);
            $valid = $yes + $no;
            $passed = $valid > 0 && ($yes * 3) >= ($valid * 2);

            if ($stage === 'acceptance') {
                if ($passed) {
                    $upd = $conn->prepare("UPDATE community_donations SET status='approved',reviewed_at=NOW(),final_decision_at=NOW(),final_decision_note='اصل کمک با رأی اعضا تصویب شد؛ ثبت دریافت واقعی نیازمند مدرک و رأی دوم است.' WHERE id=? AND community_id=? AND status='acceptance_voting'");
                    $upd->bind_param('ii', $id, $community_id);
                    $upd->execute(); $upd->close();
                } else {
                    $note = 'رأی‌گیری اصل کمک پایان یافت؛ حدنصاب دوسوم آرای موافق و مخالف به دست نیامد.';
                    $upd = $conn->prepare("UPDATE community_donations SET status='rejected',reviewed_at=NOW(),final_decision_at=NOW(),final_decision_note=? WHERE id=? AND community_id=? AND status='acceptance_voting'");
                    $upd->bind_param('sii', $note, $id, $community_id);
                    $upd->execute(); $upd->close();
                }
            } else {
                if (!$passed) {
                    $note = 'رأی‌گیری تأیید دریافت پایان یافت؛ حدنصاب دوسوم آرای موافق و مخالف به دست نیامد و کمک در دفتر مالی یا فهرست اموال ثبت نشد.';
                    $upd = $conn->prepare("UPDATE community_donations SET status='rejected',reviewed_at=NOW(),final_decision_at=NOW(),final_decision_note=? WHERE id=? AND community_id=? AND status='receipt_voting'");
                    $upd->bind_param('sii', $note, $id, $community_id);
                    $upd->execute(); $upd->close();
                } else {
                    if ($d['donation_type'] === 'cash') {
                        $sq = $conn->prepare("SELECT currency_code FROM community_finance_settings WHERE community_id=? LIMIT 1");
                        $sq->bind_param('i', $community_id); $sq->execute();
                        $setting = $sq->get_result()->fetch_assoc(); $sq->close();
                        $bank_currency = strtoupper($setting['currency_code'] ?? 'IRR');
                        if (strtoupper($d['currency_code']) !== $bank_currency) {
                            throw new RuntimeException('واحد پول کمک با واحد پول بانک جامعه یکسان نیست؛ ابتدا واحد پول بانک را اصلاح کنید.');
                        }
                        $reference = 'community_donation_id:' . $id;
                        $check = $conn->prepare("SELECT id FROM community_finance_transactions WHERE community_id=? AND receipt_reference=? LIMIT 1");
                        $check->bind_param('is', $community_id, $reference);
                        $check->execute(); $existing = $check->get_result()->fetch_assoc(); $check->close();
                        if (!$existing) {
                            $title = 'کمک نقدی تأییدشده از ' . $d['donor_name'];
                            $description = 'ثبت خودکار پس از رأی دوم کمک شماره ' . $id . '. مدرک دریافت: ' . (string)$d['receipt_evidence'];
                            $amount = (float)$d['amount'];
                            $date = $d['donation_date'];
                            $created_by = (int)$d['created_by'];
                            $ins = $conn->prepare("INSERT INTO community_finance_transactions (community_id,transaction_type,amount,title,description,transaction_date,receipt_reference,created_by) VALUES (?,'income',?,?,?,?,?,?)");
                            $ins->bind_param('idssssi', $community_id, $amount, $title, $description, $date, $reference, $created_by);
                            $ins->execute(); $ins->close();
                        }
                    } else {
                        $asset_name = 'کمک غیرمنقول از ' . $d['donor_name'];
                        $category = 'کمک غیرمنقول';
                        $description = (string)$d['asset_description'] . "\nمدرک دریافت/تحویل: " . (string)$d['receipt_evidence'] . "\nشناسه کمک: " . $id;
                        $quantity = 1.0; $unit = 'مورد';
                        $value = $d['estimated_value'] === null ? null : (float)$d['estimated_value'];
                        $location = (string)$d['location'];
                        $condition = 'unknown';
                        $date = $d['donation_date'];
                        $created_by = (int)$d['created_by'];
                        $asset = $conn->prepare("INSERT INTO community_assets (community_id,asset_name,category,description,quantity,unit,estimated_value,location,asset_condition,acquired_on,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                        $asset->bind_param('isssdsdsssii', $community_id, $asset_name, $category, $description, $quantity, $unit, $value, $location, $condition, $date, $created_by, $created_by);
                        $asset->execute();
                        $asset_id = (int)$conn->insert_id;
                        $asset->close();
                        $movement_type = 'registered';
                        $movement_notes = 'ثبت پس از رأی دوم کمک غیرمنقول شماره ' . $id;
                        $movement = $conn->prepare("INSERT INTO community_asset_movements (asset_id,community_id,movement_type,quantity_change,to_location,notes,created_by) VALUES (?,?,?, ?,?,?,?)");
                        $movement->bind_param('iisdssi', $asset_id, $community_id, $movement_type, $quantity, $location, $movement_notes, $created_by);
                        $movement->execute(); $movement->close();
                    }
                    $note = 'دریافت کمک با رأی دوم اعضا تأیید و در دفتر مالی یا فهرست اموال ثبت شد.';
                    $upd = $conn->prepare("UPDATE community_donations SET status='accepted',reviewed_at=NOW(),final_decision_at=NOW(),final_decision_note=? WHERE id=? AND community_id=? AND status='receipt_voting'");
                    $upd->bind_param('sii', $note, $id, $community_id);
                    $upd->execute(); $upd->close();
                }
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('Donation finalization failed: ' . $e->getMessage());
            $messages[] = 'نهایی‌سازی کمک شماره ' . $id . ' انجام نشد: ' . $e->getMessage();
        }
    }
    return $messages;
}

$finalize_messages = donation_finalize_expired($conn, $community_id);
if ($finalize_messages) $error = implode(' ', $finalize_messages);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403); exit('درخواست معتبر نیست؛ صفحه را تازه‌سازی کنید.');
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_donation') {
        $donor = trim((string)($_POST['donor_name'] ?? ''));
        $type = (string)($_POST['donation_type'] ?? '');
        $amount_raw = trim((string)($_POST['amount'] ?? ''));
        $amount = $amount_raw === '' ? null : filter_var($amount_raw, FILTER_VALIDATE_FLOAT);
        $value_raw = trim((string)($_POST['estimated_value'] ?? ''));
        $value = $value_raw === '' ? null : filter_var($value_raw, FILTER_VALIDATE_FLOAT);
        $description = trim((string)($_POST['asset_description'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $date = trim((string)($_POST['donation_date'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $currency_input = strtoupper(trim((string)($_POST['currency_code'] ?? $currency)));
        $valid_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4));
        if ($donor === '' || mb_strlen($donor) > 200 || !in_array($type, ['cash','immovable'], true) || !$valid_date || mb_strlen($description) > 10000 || mb_strlen($location) > 255 || mb_strlen($notes) > 10000 || ($amount_raw !== '' && ($amount === false || $amount <= 0)) || ($value_raw !== '' && ($value === false || $value < 0))) {
            $error = 'اطلاعات کمک معتبر نیست؛ فیلدها را بررسی کنید.';
        } elseif (($type === 'cash' && ($amount === null || $amount === false)) || ($type === 'immovable' && $description === '')) {
            $error = 'برای کمک نقدی مبلغ و برای کمک غیرمنقول شرح ملک یا دارایی لازم است.';
        } elseif ($type === 'cash' && $currency_input !== $currency) {
            $error = 'واحد پول کمک نقدی باید با واحد پول بانک جامعه (' . $currency . ') یکسان باشد.';
        } else {
            $amount_db = $type === 'cash' ? (float)$amount : null;
            $value_db = $type === 'immovable' ? ($value === null || $value === false ? null : (float)$value) : null;
            $description_db = $type === 'immovable' ? $description : '';
            $location_db = $type === 'immovable' ? $location : '';
            $status = 'acceptance_voting';
            $ins = $conn->prepare("INSERT INTO community_donations (community_id,donor_name,donation_type,amount,currency_code,asset_description,estimated_value,location,donation_date,status,notes,created_by,acceptance_vote_started_at,acceptance_vote_deadline) VALUES (?,?,?,?,?,?,?,?,?,? ,?,?,NOW(),DATE_ADD(NOW(),INTERVAL 3 DAY))");
            $ins->bind_param('issdssdssssi', $community_id, $donor, $type, $amount_db, $currency, $description_db, $value_db, $location_db, $date, $status, $notes, $user_id);
            if ($ins->execute()) $message = 'کمک ثبت شد و رأی‌گیری تصویب اصل کمک به مدت سه روز آغاز شد.';
            else $error = 'ثبت کمک انجام نشد؛ لطفاً دوباره تلاش کنید.';
            $ins->close();
        }
    } elseif ($action === 'vote') {
        $id = (int)($_POST['donation_id'] ?? 0);
        $stage = (string)($_POST['vote_stage'] ?? '');
        $choice = (string)($_POST['vote_choice'] ?? '');
        if ($id < 1 || !in_array($stage, ['acceptance','receipt'], true) || !in_array($choice, ['approve','reject','abstain'], true)) {
            $error = 'رأی معتبر نیست.';
        } else {
            $conn->begin_transaction();
            try {
                $lock = $conn->prepare("SELECT status,acceptance_vote_deadline,receipt_vote_deadline FROM community_donations WHERE id=? AND community_id=? FOR UPDATE");
                $lock->bind_param('ii', $id, $community_id); $lock->execute(); $d = $lock->get_result()->fetch_assoc(); $lock->close();
                $expected = $stage === 'acceptance' ? 'acceptance_voting' : 'receipt_voting';
                $deadline = $stage === 'acceptance' ? ($d['acceptance_vote_deadline'] ?? null) : ($d['receipt_vote_deadline'] ?? null);
                if (!$d || $d['status'] !== $expected || !$deadline || strtotime($deadline) <= time()) {
                    throw new RuntimeException('مهلت این رأی‌گیری پایان یافته یا رأی‌گیری باز نیست.');
                }
                $vote = $conn->prepare("INSERT INTO community_donation_votes (donation_id,community_id,user_id,vote_stage,vote_choice) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE vote_choice=VALUES(vote_choice),voted_at=CURRENT_TIMESTAMP");
                $vote->bind_param('iiiss', $id, $community_id, $user_id, $stage, $choice);
                $vote->execute(); $vote->close();
                $conn->commit();
                $message = 'رأی شما ثبت شد؛ تا پایان مهلت می‌توانید آن را تغییر دهید.';
            } catch (Throwable $e) {
                $conn->rollback(); $error = $e->getMessage();
            }
        }
    } elseif ($action === 'submit_evidence') {
        $id = (int)($_POST['donation_id'] ?? 0);
        $evidence = trim((string)($_POST['receipt_evidence'] ?? ''));
        if ($id < 1 || $evidence === '' || mb_strlen($evidence) > 10000) {
            $error = 'شرح مدرک دریافت واقعی را وارد کنید (حداکثر ۱۰ هزار نویسه).';
        } else {
            $conn->begin_transaction();
            try {
                $lock = $conn->prepare("SELECT status FROM community_donations WHERE id=? AND community_id=? FOR UPDATE");
                $lock->bind_param('ii', $id, $community_id); $lock->execute(); $d = $lock->get_result()->fetch_assoc(); $lock->close();
                if (!$d || $d['status'] !== 'approved') throw new RuntimeException('این کمک در انتظار ثبت مدرک دریافت نیست.');
                $upd = $conn->prepare("UPDATE community_donations SET receipt_evidence=?,receipt_evidence_by=?,receipt_evidence_at=NOW(),status='receipt_voting',receipt_vote_started_at=NOW(),receipt_vote_deadline=DATE_ADD(NOW(),INTERVAL 3 DAY),final_decision_note='مدرک دریافت ثبت شد؛ رأی‌گیری دوم برای تأیید دریافت واقعی آغاز شده است.' WHERE id=? AND community_id=? AND status='approved'");
                $upd->bind_param('siii', $evidence, $user_id, $id, $community_id);
                $upd->execute();
                if ($upd->affected_rows !== 1) throw new RuntimeException('ثبت مدرک انجام نشد؛ صفحه را تازه‌سازی کنید.');
                $upd->close();
                $conn->commit();
                $message = 'شرح مدرک ثبت شد و رأی‌گیری دوم برای تأیید دریافت واقعی به مدت سه روز آغاز شد.';
            } catch (Throwable $e) {
                $conn->rollback(); $error = $e->getMessage();
            }
        }
    }
}

$donations = [];
$q = $conn->prepare("SELECT d.*,
 (SELECT COUNT(*) FROM community_donation_votes v WHERE v.donation_id=d.id AND v.vote_stage=IF(d.status='receipt_voting','receipt','acceptance') AND v.vote_choice='approve') AS approvals,
 (SELECT COUNT(*) FROM community_donation_votes v WHERE v.donation_id=d.id AND v.vote_stage=IF(d.status='receipt_voting','receipt','acceptance') AND v.vote_choice='reject') AS rejections,
 (SELECT COUNT(*) FROM community_donation_votes v WHERE v.donation_id=d.id AND v.vote_stage=IF(d.status='receipt_voting','receipt','acceptance') AND v.vote_choice='abstain') AS abstentions,
 (SELECT v.vote_choice FROM community_donation_votes v WHERE v.donation_id=d.id AND v.user_id=? AND v.vote_stage=IF(d.status='receipt_voting','receipt','acceptance') LIMIT 1) AS my_vote
 FROM community_donations d WHERE d.community_id=? ORDER BY d.donation_date DESC,d.id DESC LIMIT 300");
$q->bind_param('ii', $user_id, $community_id);
$q->execute();
$res = $q->get_result();
while ($row = $res->fetch_assoc()) $donations[] = $row;
$q->close();

$type_labels = ['cash'=>'کمک نقدی','immovable'=>'کمک غیرمنقول'];
$status_labels = ['pending'=>'ثبت قدیمی؛ در انتظار بررسی','acceptance_voting'=>'رأی‌گیری تصویب اصل کمک','approved'=>'اصل کمک تصویب شد؛ در انتظار مدرک دریافت','receipt_voting'=>'رأی‌گیری تأیید دریافت واقعی','accepted'=>'دریافت تأیید و در دفتر ثبت شد','rejected'=>'ردشده'];
$vote_labels = ['approve'=>'موافق','reject'=>'مخالف','abstain'=>'ممتنع'];
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>کمک نقدی و غیرمنقول</title>
<style>
*{box-sizing:border-box}body{margin:0;padding:12px;background:#f1f6fb;color:#243447;font-family:tahoma,Arial,sans-serif;line-height:1.9}.wrap{max-width:1000px;margin:auto}.top{display:flex;gap:8px;flex-wrap:wrap}.btn,button{display:inline-block;border:0;border-radius:8px;background:#2065d1;color:#fff;padding:8px 12px;font:inherit;text-decoration:none;cursor:pointer}.box{background:#fff;border:1px solid #dce5ef;border-radius:12px;padding:15px;margin:13px 0;overflow-wrap:anywhere}.hero{background:#eaf4ff;border-color:#bad8f8}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.field{display:block;width:100%;font:inherit;padding:9px;border:1px solid #cbd5e1;border-radius:7px;margin:5px 0 12px}.muted{font-size:13px;color:#64748b}.notice{padding:10px;border-radius:8px;background:#e9f8ec;border:1px solid #b7dfbf}.error{padding:10px;border-radius:8px;background:#fff0f0;border:1px solid #efb2b2;color:#9d2020}.item{border-top:1px solid #e5eaf0;padding:12px 0}.item:first-child{border-top:0}.badge{display:inline-block;padding:1px 8px;border-radius:5px;background:#edf3fc;font-size:12px}.warning{background:#fff7df;border:1px solid #f2d38a;padding:10px;border-radius:8px;font-size:13px}.vote{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.vote button{font-size:13px;padding:6px 10px}.vote .selected{background:#167647}@media(max-width:600px){body{padding:8px}.box{padding:11px}.top .btn{flex:1;text-align:center}}
</style></head><body><main class="wrap">
<div class="top"><a class="btn" href="finance.php?community_id=<?= $community_id ?>">↩ بازگشت به بانک مالی</a><a class="btn" href="community.php?id=<?= $community_id ?>">بازگشت به جامعه</a></div>
<section class="box hero"><div class="muted">جامعه</div><h1 style="margin:0">🤝 کمک نقدی و غیرمنقول</h1><h2 style="margin:4px 0"><?= donation_h($community['name']) ?></h2><p style="margin-bottom:0">ثبت کمک‌ها، رأی‌گیری دربارهٔ اصل کمک و تأیید جداگانهٔ دریافت واقعی.</p></section>
<?php if($message!==''): ?><p class="notice"><?= donation_h($message) ?></p><?php endif; ?>
<?php if($error!==''): ?><p class="error"><?= donation_h($error) ?></p><?php endif; ?>
<div class="warning">هر مرحله سه روز است. تصویب اصل کمک به معنی دریافت پول یا انتقال مالکیت نیست. در هر مرحله حداقل دوسوم آرای موافق از مجموع موافق و مخالف لازم است؛ رأی ممتنع در محاسبه نیست. فقط پس از تصویب رأی دوم، کمک نقدی در دفتر درآمد و کمک غیرمنقول در فهرست اموال ثبت می‌شود. مدرک در این نسخه به‌صورت شرح متنی ثبت می‌شود.</div>
<section class="box"><h2 style="margin-top:0">ثبت کمک جدید</h2><form method="post">
<input type="hidden" name="csrf_token" value="<?= donation_h($csrf) ?>"><input type="hidden" name="community_id" value="<?= $community_id ?>"><input type="hidden" name="action" value="add_donation">
<label>نام اهداکننده<input class="field" name="donor_name" maxlength="200" required></label>
<label>نوع کمک<select class="field" name="donation_type" id="donation-type"><option value="cash">کمک نقدی</option><option value="immovable">کمک غیرمنقول (زمین، ساختمان و مانند آن)</option></select></label>
<div id="cash-fields"><label>مبلغ<input class="field" name="amount" type="number" min="0.01" step="0.01"></label><label>واحد پول<input class="field" value="<?= donation_h($currency) ?>" disabled><input type="hidden" name="currency_code" value="<?= donation_h($currency) ?>"><span class="muted">واحد پول باید با بانک مالی جامعه یکسان باشد.</span></label></div>
<div id="property-fields" hidden><label>شرح ملک یا دارایی غیرمنقول<textarea class="field" name="asset_description" rows="3" maxlength="10000"></textarea></label><label>ارزش تخمینی (اختیاری)<input class="field" name="estimated_value" type="number" min="0" step="0.01"></label><label>محل یا نشانی<input class="field" name="location" maxlength="255"></label></div>
<label>تاریخ کمک<input class="field" name="donation_date" type="date" value="<?= date('Y-m-d') ?>" required></label>
<label>توضیحات تکمیلی<textarea class="field" name="notes" rows="3" maxlength="10000"></textarea></label>
<button type="submit">ثبت کمک و آغاز رأی‌گیری اول</button></form></section>

<section class="box"><h2 style="margin-top:0">دفتر کمک‌ها (<?= count($donations) ?>)</h2>
<?php if(!$donations): ?><p class="muted">هنوز کمکی ثبت نشده است.</p><?php else: foreach($donations as $d): ?>
<article class="item">
<strong><?= donation_h($d['donor_name']) ?></strong> <span class="badge"><?= donation_h($type_labels[$d['donation_type']]??$d['donation_type']) ?></span> <span class="badge"><?= donation_h($status_labels[$d['status']]??$d['status']) ?></span>
<div class="muted">تاریخ: <?= donation_h($d['donation_date']) ?></div>
<?php if($d['donation_type']==='cash'): ?><div>مبلغ: <strong><?= donation_num($d['amount']) ?> <?= donation_h($d['currency_code']) ?></strong></div><?php else: ?><div><?= nl2br(donation_h($d['asset_description'])) ?></div><?php if($d['estimated_value']!==null): ?><div>ارزش تخمینی: <?= donation_num($d['estimated_value']) ?> <?= donation_h($d['currency_code']) ?></div><?php endif; ?><?php if($d['location']): ?><div>محل: <?= donation_h($d['location']) ?></div><?php endif; ?><?php endif; ?>
<?php if($d['notes']): ?><p><?= nl2br(donation_h($d['notes'])) ?></p><?php endif; ?>
<?php if($d['status']==='acceptance_voting' || $d['status']==='receipt_voting'): ?>
<div class="muted">پایان رأی‌گیری: <?= donation_h($d[$d['status']==='acceptance_voting'?'acceptance_vote_deadline':'receipt_vote_deadline']) ?></div>
<div>موافق: <strong><?= (int)$d['approvals'] ?></strong> · مخالف: <strong><?= (int)$d['rejections'] ?></strong> · ممتنع: <strong><?= (int)$d['abstentions'] ?></strong></div>
<form method="post" class="vote"><input type="hidden" name="csrf_token" value="<?= donation_h($csrf) ?>"><input type="hidden" name="community_id" value="<?= $community_id ?>"><input type="hidden" name="action" value="vote"><input type="hidden" name="donation_id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="vote_stage" value="<?= $d['status']==='acceptance_voting'?'acceptance':'receipt' ?>">
<?php foreach($vote_labels as $v=>$label): ?><button class="<?= $d['my_vote']===$v?'selected':'' ?>" name="vote_choice" value="<?= $v ?>" type="submit"><?= $d['my_vote']===$v?'✓ رأی شما: ':'رأی ' ?><?= $label ?></button><?php endforeach; ?></form>
<?php endif; ?>
<?php if($d['status']==='approved'): ?>
<div class="warning">اصل کمک تصویب شده است؛ برای شروع رأی‌گیری دوم، شرح مدرک دریافت واقعی یا تحویل دارایی را ثبت کنید.</div>
<form method="post"><input type="hidden" name="csrf_token" value="<?= donation_h($csrf) ?>"><input type="hidden" name="community_id" value="<?= $community_id ?>"><input type="hidden" name="action" value="submit_evidence"><input type="hidden" name="donation_id" value="<?= (int)$d['id'] ?>"><label>شرح مدرک دریافت واقعی<textarea class="field" name="receipt_evidence" rows="3" maxlength="10000" required placeholder="شماره رسید، تاریخ دریافت، مشخصات تحویل یا توضیح مدرک را بنویسید."></textarea></label><button type="submit">ثبت مدرک و آغاز رأی‌گیری دوم</button></form>
<?php endif; ?>
<?php if($d['receipt_evidence']): ?><div><strong>شرح مدرک دریافت:</strong><p><?= nl2br(donation_h($d['receipt_evidence'])) ?></p></div><?php endif; ?>
<?php if($d['final_decision_note']): ?><p class="muted"><?= donation_h($d['final_decision_note']) ?></p><?php endif; ?>
<div class="muted">ثبت اولیه: <?= donation_h($d['created_at']) ?></div>
</article>
<?php endforeach; endif; ?>
</section></main>
<script>
(function(){const type=document.getElementById('donation-type'),cash=document.getElementById('cash-fields'),property=document.getElementById('property-fields');function update(){const isCash=type.value==='cash';cash.hidden=!isCash;property.hidden=isCash;cash.querySelector('[name="amount"]').required=isCash;property.querySelector('[name="asset_description"]').required=!isCash;}type.addEventListener('change',update);update();})();
</script></body></html>
