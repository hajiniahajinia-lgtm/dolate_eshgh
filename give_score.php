<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$scorer_id = (int)$_SESSION['user_id'];
$target_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

// آدرس بازگشت
$redirect = isset($_GET['redirect']) ? $_GET['redirect'] : 'community.php';

$user_q = $conn->prepare("SELECT username FROM users WHERE id=?");
$user_q->bind_param("i", $target_id);
$user_q->execute();
$target_user = $user_q->get_result()->fetch_assoc();
$user_q->close();

if (!$target_user) {
    echo "<p>کاربر مورد نظر یافت نشد.</p>";
    exit;
}

// امتیازدهی به خود مجاز نیست.
if ($target_id <= 0 || $target_id === $scorer_id) {
    http_response_code(400);
    echo "<p>امتیازدهی به خود امکان‌پذیر نیست.</p>";
    exit;
}

// بازیابی امتیاز قبلی برای اینکه امکان دیدن و اصلاح آن وجود داشته باشد.
$existing_scores = ['lawfulness' => 0, 'ethics' => 0, 'honesty' => 0, 'executive_skill' => 0];
$existing_q = $conn->prepare("SELECT lawfulness, ethics, honesty, executive_skill FROM user_scores WHERE target_user_id=? AND scorer_user_id=?");
if ($existing_q) {
    $existing_q->bind_param("ii", $target_id, $scorer_id);
    $existing_q->execute();
    $existing_row = $existing_q->get_result()->fetch_assoc();
    if ($existing_row) {
        foreach ($existing_scores as $key => $unused) {
            $existing_scores[$key] = (int)$existing_row[$key];
        }
    }
    $existing_q->close();
}

// ثبت فقط وقتی هر چهار معیار امتیاز معتبر از ۱ تا ۵ داشته باشند.
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ratings = [];
    foreach (['lawfulness', 'ethics', 'honesty', 'executive_skill'] as $key) {
        $value = filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT);
        if ($value === false || $value === null || $value < 1 || $value > 5) {
            $error_message = 'لطفاً برای هر چهار معیار، از ۱ تا ۵ ستاره انتخاب کنید.';
            break;
        }
        $ratings[$key] = $value;
    }

    if ($error_message === '') {
        $lawfulness = $ratings['lawfulness'];
        $ethics = $ratings['ethics'];
        $honesty = $ratings['honesty'];
        $executive_skill = $ratings['executive_skill'];

        $ins = $conn->prepare("
            INSERT INTO user_scores (target_user_id, scorer_user_id, lawfulness, ethics, honesty, executive_skill)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                lawfulness=VALUES(lawfulness),
                ethics=VALUES(ethics),
                honesty=VALUES(honesty),
                executive_skill=VALUES(executive_skill)
        ");
        if ($ins && $ins->bind_param("iiiiii", $target_id, $scorer_id, $lawfulness, $ethics, $honesty, $executive_skill) && $ins->execute()) {
            $ins->close();
            // بازگشت فقط به مسیر محلی سایت
            if (!is_string($redirect) || $redirect === '' || preg_match('/^(https?:)?\\/\\//i', $redirect)) {
                $redirect = 'community.php';
            }
            header("Location: " . $redirect);
            exit;
        }
        if ($ins) $ins->close();
        $error_message = 'ذخیره امتیاز انجام نشد. لطفاً دوباره تلاش کنید.';
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<title>امتیازدهی به <?= htmlspecialchars($target_user['username']) ?></title>
<style>
/* همان استایل قبلی */
body{font-family:tahoma, Arial, sans-serif;background:#eef3f8;padding:20px}
.container{max-width:500px;margin:0 auto;background:#fff;padding:20px;border-radius:10px;box-shadow:0 2px 6px rgba(0,0,0,0.1)}
h2{text-align:center;color:#2065d1}
.star-rating{display:flex;flex-direction:row-reverse;justify-content:flex-start;gap:5px;font-size:30px;cursor:pointer}
.star{color:#ccc;transition:color 0.2s;}
.star.selected, .star.hover, .star:hover, .star:hover ~ .star{color:#ffca28;}
label{display:block;margin-top:15px;font-weight:bold;font-size:14px}
button{margin-top:20px;width:100%;padding:10px;background:#27ae60;color:#fff;border:none;border-radius:6px;font-size:16px;cursor:pointer;}
</style>
</head>
<body>
<div class="container">
<h2>امتیازدهی به <?= htmlspecialchars($target_user['username']) ?></h2>

<form method="post">
    <?php
    $criteria = [
        'lawfulness'=>'قانون‌مداری',
        'ethics'=>'اخلاق‌مندی',
        'honesty'=>'درست‌کاری',
        'executive_skill'=>'توانایی در کار اجرایی'
    ];
    foreach($criteria as $key=>$label):
        $saved_value = (int)$existing_scores[$key];
    ?>
    <label><?= $label ?></label>
    <div class="star-rating" data-field="<?= $key ?>" data-selected="<?= $saved_value ?>">
        <?php for ($star_value = 1; $star_value <= 5; $star_value++): ?>
            <span class="star<?= $star_value <= $saved_value ? ' selected' : '' ?>" data-value="<?= $star_value ?>">★</span>
        <?php endfor; ?>
    </div>
    <input type="hidden" name="<?= $key ?>" value="<?= $saved_value ?>">
    <?php endforeach; ?>

    <?php if ($error_message !== ''): ?>
        <p style="color:#b42318;background:#fff0f0;padding:8px;border-radius:6px;line-height:1.8;"><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <p style="font-size:12px;color:#555;line-height:1.8;">برای هر چهار معیار، از ۱ تا ۵ ستاره انتخاب کنید. امتیاز قبلی شما، در صورت وجود، نمایش داده می‌شود و با ثبت دوباره جایگزین خواهد شد.</p>
    <button type="submit">✅ ثبت امتیاز</button>
</form>
</div>

<script>
// ستاره‌ها؛ مقدار عددی و نمایش ستاره‌ها هماهنگ می‌مانند.
document.querySelectorAll('.star-rating').forEach(function(container){
    const stars = Array.from(container.querySelectorAll('.star'));
    const input = container.nextElementSibling;

    function paint(value, className) {
        stars.forEach(function(star) {
            star.classList.toggle(className, Number(star.dataset.value) <= value);
        });
    }

    stars.forEach(function(star) {
        star.addEventListener('mouseenter', function() {
            paint(Number(star.dataset.value), 'hover');
        });
        star.addEventListener('click', function() {
            const value = Number(star.dataset.value);
            input.value = String(value);
            container.dataset.selected = String(value);
            stars.forEach(s => s.classList.remove('selected'));
            paint(value, 'selected');
        });
    });

    container.addEventListener('mouseleave', function() {
        stars.forEach(s => s.classList.remove('hover'));
    });
});
</script>
</body>
</html>