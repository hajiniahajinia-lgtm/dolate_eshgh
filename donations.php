<?php
session_start();
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$user_id = (int)$_SESSION['user_id'];
$community_id = (int)($_GET['community_id'] ?? $_POST['community_id'] ?? 0);
if ($community_id < 1) { http_response_code(400); exit('جامعهٔ معتبر انتخاب نشده است.'); }

$cm = $conn->prepare("SELECT c.id,c.name FROM communities c JOIN community_members m ON m.community_id=c.id WHERE c.id=? AND m.user_id=? LIMIT 1");
$cm->bind_param('ii', $community_id, $user_id);
$cm->execute();
$community = $cm->get_result()->fetch_assoc();
$cm->close();
if (!$community) { http_response_code(403); exit('فقط اعضای جامعه می‌توانند دفتر کمک‌های آن را ببینند.'); }

$table_check = $conn->prepare("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='community_donations'");
$table_check->execute();
$table_ready = (int)$table_check->get_result()->fetch_assoc()['n'] === 1;
$table_check->close();
if (!$table_ready) {
  ?><!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>کمک‌های جامعه</title><body style="font-family:tahoma;max-width:850px;margin:30px auto;padding:16px;line-height:2;background:#f4f7fb;color:#263548"><h2>🤝 کمک نقدی و غیر منقول</h2><p>ساختار این بخش هنوز نصب نشده است. فایل <code>migrations/20261028_community_donations.sql</code> باید یک‌بار در پایگاه دادهٔ هاست اجرا شود.</p><a href="finance.php?community_id=<?= $community_id ?>">بازگشت به بانک مالی</a></body></html><?php
  exit;
}
if (empty($_SESSION['community_donations_csrf'])) $_SESSION['community_donations_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['community_donations_csrf'];
$message = '';
$error = '';

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
    $currency = strtoupper(trim((string)($_POST['currency_code'] ?? 'IRR')));
    $valid_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4));
    if ($donor === '' || mb_strlen($donor) > 200 || !in_array($type, ['cash','immovable'], true) || !$valid_date || mb_strlen($description) > 10000 || mb_strlen($location) > 255 || mb_strlen($notes) > 10000 || !preg_match('/^[A-Z]{3,10}$/', $currency) || ($amount_raw !== '' && ($amount === false || $amount <= 0)) || ($value_raw !== '' && ($value === false || $value < 0))) {
      $error = 'اطلاعات کمک معتبر نیست؛ فیلدها را بررسی کنید.';
    } elseif (($type === 'cash' && ($amount === null || $amount === false)) || ($type === 'immovable' && $description === '')) {
      $error = 'برای کمک نقدی مبلغ و برای کمک غیرمنقول شرح ملک یا دارایی لازم است.';
    } else {
      $status = 'pending';
      $amount_db = $type === 'cash' ? $amount : null;
      $value_db = $type === 'immovable' ? $value : null;
      $description_db = $type === 'immovable' ? $description : '';
      $location_db = $type === 'immovable' ? $location : '';
      $ins = $conn->prepare("INSERT INTO community_donations (community_id,donor_name,donation_type,amount,currency_code,asset_description,estimated_value,location,donation_date,status,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,'pending',?,?)");
      $ins->bind_param('issdssdsssi', $community_id, $donor, $type, $amount_db, $currency, $description_db, $value_db, $location_db, $date, $notes, $user_id);
      if ($ins->execute()) $message = 'کمک ثبت شد و برای بررسی در وضعیت «در انتظار تأیید» قرار گرفت؛ هنوز به‌عنوان دریافت قطعی در دفتر گردش مالی یا فهرست اموال ثبت نشده است.';
      else $error = 'ثبت کمک انجام نشد؛ لطفاً دوباره تلاش کنید.';
      $ins->close();
    }
  }
}

$donations = [];
$q = $conn->prepare("SELECT * FROM community_donations WHERE community_id=? ORDER BY donation_date DESC,id DESC LIMIT 300");
$q->bind_param('i', $community_id);
$q->execute();
$res = $q->get_result();
while ($row = $res->fetch_assoc()) $donations[] = $row;
$q->close();

function donation_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function donation_num($v) { return number_format((float)$v, 2, '.', ','); }
$type_labels = ['cash'=>'کمک نقدی','immovable'=>'کمک غیرمنقول'];
$status_labels = ['pending'=>'در انتظار تأیید','accepted'=>'تأییدشده','rejected'=>'ردشده'];
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>کمک نقدی و غیر منقول</title>
<style>
*{box-sizing:border-box}body{margin:0;padding:12px;background:#f1f6fb;color:#243447;font-family:tahoma,Arial,sans-serif;line-height:1.9}.wrap{max-width:1000px;margin:auto}.top{display:flex;gap:8px;flex-wrap:wrap}.btn,button{display:inline-block;border:0;border-radius:8px;background:#2065d1;color:#fff;padding:9px 13px;font:inherit;text-decoration:none;cursor:pointer}.box{background:#fff;border:1px solid #dce5ef;border-radius:12px;padding:15px;margin:13px 0;overflow-wrap:anywhere}.hero{background:#eaf4ff;border-color:#bad8f8}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.field{display:block;width:100%;font:inherit;padding:9px;border:1px solid #cbd5e1;border-radius:7px;margin:5px 0 12px}.muted{font-size:13px;color:#64748b}.notice{padding:10px;border-radius:8px;background:#e9f8ec;border:1px solid #b7dfbf}.error{padding:10px;border-radius:8px;background:#fff0f0;border:1px solid #efb2b2;color:#9d2020}.item{border-top:1px solid #e5eaf0;padding:12px 0}.item:first-child{border-top:0}.badge{display:inline-block;padding:1px 8px;border-radius:5px;background:#edf3fc;font-size:12px}.warning{background:#fff7df;border:1px solid #f2d38a;padding:10px;border-radius:8px;font-size:13px}@media(max-width:600px){body{padding:8px}.box{padding:11px}.top .btn{flex:1;text-align:center}}
</style></head><body><main class="wrap">
<div class="top"><a class="btn" href="finance.php?community_id=<?= $community_id ?>">↩ بازگشت به بانک مالی</a><a class="btn" href="community.php?id=<?= $community_id ?>">بازگشت به جامعه</a></div>
<section class="box hero"><div class="muted">جامعه</div><h1 style="margin:0">🤝 کمک نقدی و غیر منقول</h1><h2 style="margin:4px 0"><?= donation_h($community['name']) ?></h2><p style="margin-bottom:0">ثبت شفاف کمک‌های پیشنهادی اعضا و خیرین به جامعه.</p></section>
<?php if($message!==''): ?><p class="notice"><?= donation_h($message) ?></p><?php endif; ?><?php if($error!==''): ?><p class="error"><?= donation_h($error) ?></p><?php endif; ?>
<div class="warning">کمک جدید ابتدا «در انتظار تأیید» ثبت می‌شود. این ثبت به‌تنهایی دریافت قطعی پول، انتقال مالکیت ملک یا ثبت دارایی تأییدشده نیست.</div>
<section class="box"><h2 style="margin-top:0">ثبت کمک جدید</h2><form method="post">
<input type="hidden" name="csrf_token" value="<?= donation_h($csrf) ?>"><input type="hidden" name="community_id" value="<?= $community_id ?>"><input type="hidden" name="action" value="add_donation">
<label>نام اهداکننده<input class="field" name="donor_name" maxlength="200" required></label>
<label>نوع کمک<select class="field" name="donation_type" id="donation-type" required><option value="cash">کمک نقدی</option><option value="immovable">کمک غیرمنقول (زمین، ساختمان و مانند آن)</option></select></label>
<div id="cash-fields"><label>مبلغ کمک<input class="field" name="amount" type="number" min="0.01" step="0.01"></label><label>واحد پول (کد سه‌حرفی)<input class="field" name="currency_code" maxlength="10" value="IRR" pattern="[A-Za-z]{3,10}"></label></div>
<div id="property-fields" hidden><label>شرح ملک یا دارایی غیرمنقول<textarea class="field" name="asset_description" rows="3" maxlength="10000"></textarea></label><label>ارزش تخمینی (اختیاری)<input class="field" name="estimated_value" type="number" min="0" step="0.01"></label><label>نشانی یا محل ملک<input class="field" name="location" maxlength="255"></label></div>
<label>تاریخ کمک<input class="field" name="donation_date" type="date" value="<?= date('Y-m-d') ?>" required></label>
<label>توضیحات تکمیلی<textarea class="field" name="notes" rows="3" maxlength="10000"></textarea></label>
<button type="submit">ثبت کمک برای بررسی</button></form></section>
<section class="box"><h2 style="margin-top:0">دفتر کمک‌ها (<?= count($donations) ?>)</h2>
<?php if(!$donations): ?><p class="muted">هنوز کمکی ثبت نشده است.</p><?php else: foreach($donations as $d): ?><article class="item">
<strong><?= donation_h($d['donor_name']) ?></strong> <span class="badge"><?= donation_h($type_labels[$d['donation_type']]??$d['donation_type']) ?></span> <span class="badge"><?= donation_h($status_labels[$d['status']]??$d['status']) ?></span>
<div class="muted">تاریخ: <?= donation_h($d['donation_date']) ?></div>
<?php if($d['donation_type']==='cash'): ?><div>مبلغ اعلام‌شده: <strong><?= donation_num($d['amount']) ?> <?= donation_h($d['currency_code']) ?></strong></div><?php else: ?><div><?= nl2br(donation_h($d['asset_description'])) ?></div><?php if($d['estimated_value']!==null): ?><div>ارزش تخمینی: <?= donation_num($d['estimated_value']) ?> <?= donation_h($d['currency_code']) ?></div><?php endif; ?><?php if($d['location']): ?><div>محل: <?= donation_h($d['location']) ?></div><?php endif; ?><?php endif; ?>
<?php if($d['notes']): ?><p><?= nl2br(donation_h($d['notes'])) ?></p><?php endif; ?>
<div class="muted">ثبت در <?= donation_h($d['created_at']) ?>؛ وضعیت دریافت/مالکیت باید جداگانه بررسی شود.</div></article><?php endforeach; endif; ?>
</section></main>
<script>
(function(){const type=document.getElementById('donation-type');const cash=document.getElementById('cash-fields');const property=document.getElementById('property-fields');function update(){const isCash=type.value==='cash';cash.hidden=!isCash;property.hidden=isCash;cash.querySelector('[name="amount"]').required=isCash;property.querySelector('[name="asset_description"]').required=!isCash;}type.addEventListener('change',update);update();})();
</script></body></html>
