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
if (!$community) { http_response_code(403); exit('فقط اعضای جامعه می‌توانند بانک مالی و اموال آن را ببینند.'); }

$required_tables = ['community_finance_settings','community_finance_transactions','community_assets','community_asset_movements','community_budget_requests','community_budget_request_votes'];
$schema_stmt = $conn->prepare("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('community_finance_settings','community_finance_transactions','community_assets','community_asset_movements','community_budget_requests','community_budget_request_votes')");
$schema_stmt->execute();
$schema_ready = (int)$schema_stmt->get_result()->fetch_assoc()['n'] === count($required_tables);
$schema_stmt->close();
if (!$schema_ready) {
  ?><!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>بانک مالی و اموال جامعه</title><body style="font-family:tahoma;max-width:850px;margin:30px auto;padding:16px;line-height:2;background:#f4f7fb;color:#263548"><h2>🏦 بانک مالی و اموال جامعه</h2><p>ساختار پایگاه دادهٔ این بخش هنوز نصب نشده است. فایل <code>migrations/20261027_community_finance_assets.sql</code> باید یک‌بار در پایگاه دادهٔ هاست اجرا شود.</p><a href="community.php?id=<?= $community_id ?>">بازگشت به جامعه</a></body></html><?php
  exit;
}

if (empty($_SESSION['community_finance_csrf'])) $_SESSION['community_finance_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['community_finance_csrf'];
$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403); exit('درخواست معتبر نیست؛ صفحه را تازه‌سازی کنید.');
  }
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'add_asset') {
    $name = trim((string)($_POST['asset_name'] ?? ''));
    $category = trim((string)($_POST['category'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
    $unit = trim((string)($_POST['unit'] ?? 'عدد'));
    $value_raw = trim((string)($_POST['estimated_value'] ?? ''));
    $value = $value_raw === '' ? null : filter_var($value_raw, FILTER_VALIDATE_FLOAT);
    $location = trim((string)($_POST['location'] ?? ''));
    $condition = (string)($_POST['asset_condition'] ?? 'unknown');
    $acquired_raw = trim((string)($_POST['acquired_on'] ?? ''));
    $acquired = $acquired_raw === '' ? null : $acquired_raw;
    if ($name === '' || mb_strlen($name) > 200 || $quantity === false || $quantity <= 0 || $quantity > 1000000000 || $unit === '' || mb_strlen($unit) > 50 || mb_strlen($category) > 100 || mb_strlen($location) > 255 || mb_strlen($description) > 10000 || ($value_raw !== '' && ($value === false || $value < 0))) {
      $error = 'اطلاعات مال معتبر نیست؛ نام، مقدار و واحد را بررسی کنید.';
    } elseif (!in_array($condition, ['good','needs_repair','unusable','unknown'], true)) {
      $error = 'وضعیت مال معتبر نیست.';
    } elseif ($acquired !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $acquired)) {
      $error = 'تاریخ ثبت‌شده معتبر نیست.';
    } else {
      $ins = $conn->prepare("INSERT INTO community_assets (community_id,asset_name,category,description,quantity,unit,estimated_value,location,asset_condition,acquired_on,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
      $types = 'isssdsdsssii';
      $ins->bind_param($types, $community_id, $name, $category, $description, $quantity, $unit, $value, $location, $condition, $acquired, $user_id, $user_id);
      if ($ins->execute()) {
        $asset_id = (int)$conn->insert_id;
        $move_type = 'registered'; $notes = 'ثبت اولیهٔ مال در دفتر جامعه';
        $mv = $conn->prepare("INSERT INTO community_asset_movements (asset_id,community_id,movement_type,quantity_change,to_location,notes,created_by) VALUES (?,?,?, ?,?,?,?)");
        $mv->bind_param('iis dssi', $asset_id, $community_id, $move_type, $quantity, $location, $notes, $user_id);
        $mv->close();
        $mv = $conn->prepare("INSERT INTO community_asset_movements (asset_id,community_id,movement_type,quantity_change,to_location,notes,created_by) VALUES (?,?,?,?,?,?,?)");
        $mv->bind_param('iisdssi', $asset_id, $community_id, $move_type, $quantity, $location, $notes, $user_id);
        $mv->execute(); $mv->close();
        $message = 'مال در فهرست اولیه ثبت شد. مالکیت و ارزش آن هنوز به‌صورت مستقل تأیید نشده است.';
      } else { $error = 'ثبت مال انجام نشد؛ لطفاً دوباره تلاش کنید.'; }
      $ins->close();
    }
  } elseif ($action === 'add_request') {
    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $amount_raw = trim((string)($_POST['requested_amount'] ?? ''));
    $amount = filter_var($amount_raw, FILTER_VALIDATE_FLOAT);
    if ($title === '' || mb_strlen($title) > 200 || $description === '' || mb_strlen($description) > 10000 || $amount === false || $amount <= 0 || $amount > 9999999999999999) {
      $error = 'عنوان، توضیحات و مبلغ مثبت و معتبر لازم است.';
    } else {
      $ins = $conn->prepare("INSERT INTO community_budget_requests (community_id,requested_by,title,description,requested_amount,status) VALUES (?,?,?,?,?,'voting')");
      $ins->bind_param('iissd', $community_id, $user_id, $title, $description, $amount);
      if ($ins->execute()) $message = 'درخواست بودجه ثبت شد و برای اعضا قابل مشاهده است. تصویب نهایی و پرداخت در این مرحله خودکار نیست.';
      else $error = 'ثبت درخواست بودجه انجام نشد.';
      $ins->close();
    }
  } elseif ($action === 'vote_request') {
    $request_id = (int)($_POST['request_id'] ?? 0);
    $choice = (string)($_POST['vote_choice'] ?? '');
    if ($request_id < 1 || !in_array($choice, ['approve','reject','abstain'], true)) {
      $error = 'رأی معتبر نیست.';
    } else {
      $rq = $conn->prepare("SELECT id FROM community_budget_requests WHERE id=? AND community_id=? AND status='voting' LIMIT 1");
      $rq->bind_param('ii', $request_id, $community_id); $rq->execute();
      $valid = (bool)$rq->get_result()->fetch_assoc(); $rq->close();
      if (!$valid) $error = 'این درخواست برای رأی‌گیری باز نیست.';
      else {
        $vote = $conn->prepare("INSERT INTO community_budget_request_votes (request_id,community_id,user_id,vote_choice) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE vote_choice=VALUES(vote_choice),voted_at=CURRENT_TIMESTAMP");
        $vote->bind_param('iiis', $request_id, $community_id, $user_id, $choice);
        if ($vote->execute()) $message = 'رأی شما ثبت شد. نتیجه در این مرحله صرفاً شمارش رأی‌هاست و به‌تنهایی مجوز پرداخت محسوب نمی‌شود.';
        else $error = 'ثبت رأی انجام نشد.';
        $vote->close();
      }
    }
  }
}

$settings = $conn->prepare("INSERT IGNORE INTO community_finance_settings (community_id,created_by,updated_by) VALUES (?,?,?)");
$settings->bind_param('iii', $community_id, $user_id, $user_id); $settings->execute(); $settings->close();
$currency = 'IRR';
$curq = $conn->prepare("SELECT currency_code FROM community_finance_settings WHERE community_id=?");
$curq->bind_param('i', $community_id); $curq->execute(); $currency = $curq->get_result()->fetch_assoc()['currency_code'] ?? 'IRR'; $curq->close();

$assets = [];
$aq = $conn->prepare("SELECT * FROM community_assets WHERE community_id=? ORDER BY created_at DESC,id DESC LIMIT 300");
$aq->bind_param('i', $community_id); $aq->execute(); $ar = $aq->get_result(); while ($r=$ar->fetch_assoc()) $assets[]=$r; $aq->close();
$requests = [];
$rq = $conn->prepare("SELECT r.*,COALESCE(u.username,CONCAT('عضو ',r.requested_by)) AS requester,
  (SELECT COUNT(*) FROM community_budget_request_votes v WHERE v.request_id=r.id AND v.vote_choice='approve') AS approvals,
  (SELECT COUNT(*) FROM community_budget_request_votes v WHERE v.request_id=r.id AND v.vote_choice='reject') AS rejections,
  (SELECT COUNT(*) FROM community_budget_request_votes v WHERE v.request_id=r.id AND v.vote_choice='abstain') AS abstentions,
  (SELECT v.vote_choice FROM community_budget_request_votes v WHERE v.request_id=r.id AND v.user_id=? LIMIT 1) AS my_vote
  FROM community_budget_requests r LEFT JOIN users u ON u.id=r.requested_by WHERE r.community_id=? ORDER BY r.created_at DESC,r.id DESC LIMIT 100");
$rq->bind_param('ii', $user_id, $community_id); $rq->execute(); $rr=$rq->get_result(); while ($r=$rr->fetch_assoc()) $requests[]=$r; $rq->close();
$transactions = [];
$tq = $conn->prepare("SELECT * FROM community_finance_transactions WHERE community_id=? ORDER BY transaction_date DESC,id DESC LIMIT 200");
$tq->bind_param('i',$community_id); $tq->execute(); $tr=$tq->get_result(); while($r=$tr->fetch_assoc()) $transactions[]=$r; $tq->close();
$balance = 0.0; $income_total = 0.0; $expense_total = 0.0;
foreach ($transactions as $t) {
  $amt = (float)$t['amount'];
  if ($t['transaction_type']==='income') { $income_total += $amt; $balance += $amt; }
  elseif ($t['transaction_type']==='expense') { $expense_total += $amt; $balance -= $amt; }
  else $balance += $amt;
}
function finance_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function finance_num($v) { return number_format((float)$v, 2, '.', ','); }
$condition_labels = ['good'=>'سالم','needs_repair'=>'نیازمند تعمیر','unusable'=>'غیرقابل استفاده','unknown'=>'بررسی‌نشده'];
$status_labels = ['draft'=>'پیش‌نویس','voting'=>'در حال رأی‌گیری','approved'=>'تصویب‌شده','rejected'=>'ردشده','allocated'=>'بودجه تخصیص‌یافته','completed'=>'تکمیل‌شده','cancelled'=>'لغوشده'];
$vote_labels = ['approve'=>'موافق','reject'=>'مخالف','abstain'=>'ممتنع'];
$txn_labels = ['income'=>'درآمد','expense'=>'هزینه','adjustment'=>'اصلاح موجودی'];
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>بانک مالی و اموال جامعه</title>
<style>
*{box-sizing:border-box}body{margin:0;padding:14px;background:#f1f6fb;color:#243447;font-family:tahoma,Arial,sans-serif;line-height:1.9}.wrap{max-width:1050px;margin:auto}.top{display:flex;gap:8px;flex-wrap:wrap}.btn,button{display:inline-block;border:0;border-radius:8px;background:#2065d1;color:#fff;padding:9px 13px;font:inherit;text-decoration:none;cursor:pointer}.box{background:#fff;border:1px solid #dce5ef;border-radius:12px;padding:15px;margin:13px 0;overflow-wrap:anywhere}.hero{background:#eaf4ff;border-color:#bad8f8}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.stat{padding:12px;border:1px solid #dce5ef;border-radius:9px;background:#fff}.field{display:block;width:100%;font:inherit;padding:9px;border:1px solid #cbd5e1;border-radius:7px;margin:5px 0 12px}.muted{font-size:13px;color:#64748b}.notice{padding:10px;border-radius:8px;background:#e9f8ec;border:1px solid #b7dfbf}.error{padding:10px;border-radius:8px;background:#fff0f0;border:1px solid #efb2b2;color:#9d2020}.item{border-top:1px solid #e5eaf0;padding:12px 0}.item:first-child{border-top:0}.badge{display:inline-block;padding:1px 8px;border-radius:5px;background:#edf3fc;font-size:12px}.vote-buttons{display:flex;gap:6px;flex-wrap:wrap}.vote-buttons button{padding:6px 10px;font-size:13px}.warning{background:#fff7df;border:1px solid #f2d38a;padding:10px;border-radius:8px;font-size:13px}@media(max-width:600px){body{padding:8px}.box{padding:11px}.top .btn{flex:1;text-align:center}}
</style></head><body><main class="wrap">
<div class="top"><a class="btn" href="community.php?id=<?= $community_id ?>">↩ بازگشت به جامعه</a><a class="btn" href="profile.php">پروفایل من</a></div>
<section class="box hero"><div class="muted">جامعه</div><h1 style="margin:0">🏦 بانک مالی و اموال</h1><h2 style="margin:4px 0"><?= finance_h($community['name']) ?></h2><p style="margin-bottom:0">دفتر شفاف منابع جامعه؛ ثبت اولیهٔ اموال، درخواست بودجه و رأی اعضا در یک مکان.</p></section>
<?php if($message!==''): ?><p class="notice"><?= finance_h($message) ?></p><?php endif; ?><?php if($error!==''): ?><p class="error"><?= finance_h($error) ?></p><?php endif; ?>
<div class="warning">نکتهٔ مهم: رأی موافق، به‌تنهایی پرداخت یا برداشت پول را انجام نمی‌دهد. این نسخه درخواست‌ها و شمارش رأی‌ها را ثبت می‌کند؛ ثبت پرداخت واقعی و نهایی‌سازی رسمی رأی‌گیری باید در مرحلهٔ بعد با قواعد روشن اضافه شود. فهرست اموال نیز دفتر ثبت اولیه است و به‌تنهایی اثبات مالکیت یا ارزش کارشناسی نیست.</div>
<section class="box"><h2 style="margin-top:0">نمای کلی مالی</h2><div class="grid"><div class="stat"><div class="muted">موجودی ثبت‌شده</div><strong><?= finance_num($balance) ?> <?= finance_h($currency) ?></strong></div><div class="stat"><div class="muted">مجموع درآمدهای ثبت‌شده</div><strong><?= finance_num($income_total) ?> <?= finance_h($currency) ?></strong></div><div class="stat"><div class="muted">مجموع هزینه‌های ثبت‌شده</div><strong><?= finance_num($expense_total) ?> <?= finance_h($currency) ?></strong></div><div class="stat"><div class="muted">تعداد اموال ثبت‌شده</div><strong><?= count($assets) ?></strong></div></div><p class="muted">مبالغ تا زمانی که تراکنش‌های تأییدشده ثبت نشده‌اند ممکن است صفر باشند؛ صفر به معنی نبود دارایی واقعی نیست.</p></section>
<section class="box"><h2 style="margin-top:0">➕ ثبت اولیهٔ مال جامعه</h2><form method="post"><input type="hidden" name="csrf_token" value="<?= finance_h($csrf) ?>"><input type="hidden" name="community_id" value="<?= $community_id ?>"><input type="hidden" name="action" value="add_asset"><label>نام مال<input class="field" name="asset_name" maxlength="200" required></label><div class="grid"><label>دسته‌بندی<input class="field" name="category" maxlength="100" placeholder="مثلاً ابزار، زمین، ساختمان"></label><label>مقدار<input class="field" name="quantity" type="number" min="0.001" step="0.001" value="1" required></label><label>واحد<input class="field" name="unit" maxlength="50" value="عدد" required></label><label>ارزش تخمینی (اختیاری)<input class="field" name="estimated_value" type="number" min="0" step="0.01"></label><label>محل نگهداری<input class="field" name="location" maxlength="255"></label><label>تاریخ تحصیل<input class="field" name="acquired_on" type="date"></label><label>وضعیت مال<select class="field" name="asset_condition"><option value="unknown">بررسی‌نشده</option><option value="good">سالم</option><option value="needs_repair">نیازمند تعمیر</option><option value="unusable">غیرقابل استفاده</option></select></label></div><label>توضیحات<textarea class="field" name="description" rows="3" maxlength="10000"></textarea></label><button type="submit">ثبت در فهرست اولیهٔ اموال</button></form></section>
<section class="box"><h2 style="margin-top:0">📋 فهرست اموال جامعه (<?= count($assets) ?>)</h2><?php if(!$assets): ?><p class="muted">هنوز مالی ثبت نشده است.</p><?php else: foreach($assets as $a): ?><article class="item"><strong><?= finance_h($a['asset_name']) ?></strong> <span class="badge"><?= finance_h($condition_labels[$a['asset_condition']]??'بررسی‌نشده') ?></span><div class="muted"><?= finance_h($a['category']??'بدون دسته‌بندی') ?> · مقدار: <?= finance_num($a['quantity']) ?> <?= finance_h($a['unit']) ?> · محل: <?= finance_h($a['location']?:'ثبت نشده') ?></div><?php if($a['estimated_value']!==null): ?><div>ارزش تخمینی: <?= finance_num($a['estimated_value']) ?> <?= finance_h($currency) ?></div><?php endif; ?><?php if(trim((string)$a['description'])!==''): ?><div><?= nl2br(finance_h($a['description'])) ?></div><?php endif; ?><div class="muted">ثبت اولیه در <?= finance_h($a['created_at']) ?> — مالکیت/ارزش مستقل تأیید نشده</div></article><?php endforeach; endif; ?></section>
<section class="box"><h2 style="margin-top:0">🧾 درخواست بودجه یا هزینه</h2><p class="muted">درخواست باید هدف، ضرورت و مبلغ مشخص داشته باشد. پرداخت واقعی پس از ثبت و تأیید جداگانه انجام خواهد شد.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= finance_h($csrf) ?>"><input type="hidden" name="community_id" value="<?= $community_id ?>"><input type="hidden" name="action" value="add_request"><label>عنوان درخواست<input class="field" name="title" maxlength="200" required></label><label>مبلغ درخواستی<input class="field" name="requested_amount" type="number" min="0.01" step="0.01" required></label><label>شرح، هدف و دلیل درخواست<textarea class="field" name="description" rows="4" maxlength="10000" required></textarea></label><button type="submit">ثبت درخواست برای رأی اعضا</button></form></section>
<section class="box"><h2 style="margin-top:0">🗳 درخواست‌ها و رأی اعضا (<?= count($requests) ?>)</h2><?php if(!$requests): ?><p class="muted">هنوز درخواست بودجه‌ای ثبت نشده است.</p><?php else: foreach($requests as $r): ?><article class="item"><strong><?= finance_h($r['title']) ?></strong> <span class="badge"><?= finance_h($status_labels[$r['status']]??$r['status']) ?></span><div class="muted">درخواست‌دهنده: <?= finance_h($r['requester']) ?> · مبلغ: <?= finance_num($r['requested_amount']) ?> <?= finance_h($currency) ?> · <?= finance_h($r['created_at']) ?></div><p><?= nl2br(finance_h($r['description'])) ?></p><div>موافق: <strong><?= (int)$r['approvals'] ?></strong> · مخالف: <strong><?= (int)$r['rejections'] ?></strong> · ممتنع: <strong><?= (int)$r['abstentions'] ?></strong></div><?php if($r['status']==='voting'): ?><form method="post" class="vote-buttons" style="margin-top:8px"><input type="hidden" name="csrf_token" value="<?= finance_h($csrf) ?>"><input type="hidden" name="community_id" value="<?= $community_id ?>"><input type="hidden" name="action" value="vote_request"><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>"><?php foreach(['approve'=>'موافق','reject'=>'مخالف','abstain'=>'ممتنع'] as $v=>$label): ?><button name="vote_choice" value="<?= $v ?>" type="submit" style="background:<?= $r['my_vote']===$v?'#167647':'#2065d1' ?>"><?= $r['my_vote']===$v?'✓ رأی شما: ':'رأی ' ?><?= $label ?></button><?php endforeach; ?></form><?php endif; ?><?php if($r['status']==='approved' || $r['status']==='allocated' || $r['status']==='completed'): ?><div class="muted">مبلغ تصویب‌شده: <?= $r['approved_amount']===null?'ثبت نشده':finance_num($r['approved_amount']) ?> · هزینهٔ واقعی: <?= $r['actual_spent']===null?'ثبت نشده':finance_num($r['actual_spent']) ?></div><?php endif; ?><div class="warning" style="margin-top:8px">این رأی‌گیری هنوز به‌صورت خودکار نهایی نمی‌شود و رأی موافق، مجوز پرداخت نیست.</div></article><?php endforeach; endif; ?></section>
<section class="box"><h2 style="margin-top:0">📒 دفتر گردش مالی</h2><?php if(!$transactions): ?><p class="muted">هنوز درآمد یا هزینهٔ تأییدشده‌ای در دفتر گردش مالی ثبت نشده است. در این نسخه ثبت پرداخت مستقیم فعال نیست.</p><?php else: foreach($transactions as $t): ?><article class="item"><strong><?= finance_h($txn_labels[$t['transaction_type']]??$t['transaction_type']) ?> — <?= finance_h($t['title']) ?></strong><div><?= finance_num($t['amount']) ?> <?= finance_h($currency) ?> · تاریخ: <?= finance_h($t['transaction_date']) ?></div><?php if($t['description']): ?><div><?= nl2br(finance_h($t['description'])) ?></div><?php endif; ?></article><?php endforeach; endif; ?></section>
</main></body></html>
