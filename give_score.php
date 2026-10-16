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

// ذخیره امتیاز بعد از POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lawfulness = (int)$_POST['lawfulness'];
    $ethics = (int)$_POST['ethics'];
    $honesty = (int)$_POST['honesty'];
    $executive_skill = (int)$_POST['executive_skill'];

    $ins = $conn->prepare("
        INSERT INTO user_scores (target_user_id, scorer_user_id, lawfulness, ethics, honesty, executive_skill)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            lawfulness=VALUES(lawfulness),
            ethics=VALUES(ethics),
            honesty=VALUES(honesty),
            executive_skill=VALUES(executive_skill)
    ");
    $ins->bind_param("iiiiii", $target_id, $scorer_id, $lawfulness, $ethics, $honesty, $executive_skill);
    $ins->execute();
    $ins->close();

    // بازگشت به صفحه قبلی
    header("Location: $redirect");
    exit;
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
.star.selected, .star:hover, .star:hover ~ .star{color:#ffca28;}
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
    ?>
    <label><?= $label ?></label>
    <div class="star-rating" data-field="<?= $key ?>">
        <span class="star" data-value="5">★</span>
        <span class="star" data-value="4">★</span>
        <span class="star" data-value="3">★</span>
        <span class="star" data-value="2">★</span>
        <span class="star" data-value="1">★</span>
    </div>
    <input type="hidden" name="<?= $key ?>" value="0">
    <?php endforeach; ?>

    <button type="submit">✅ ثبت امتیاز</button>
</form>
</div>

<script>
// ستاره‌ها
document.querySelectorAll('.star-rating').forEach(function(container){
    const stars = container.querySelectorAll('.star');
    const input = container.nextElementSibling;

    stars.forEach(star => {
        star.addEventListener('mouseenter', ()=>{
            stars.forEach(s=>s.classList.remove('hover'));
            star.classList.add('hover');
            let prev = star.previousElementSibling;
            while(prev){prev.classList.add('hover'); prev=prev.previousElementSibling;}
        });
        star.addEventListener('mouseleave', ()=>{
            stars.forEach(s=>s.classList.remove('hover'));
        });
        star.addEventListener('click', ()=>{
            stars.forEach(s=>s.classList.remove('selected'));
            star.classList.add('selected');
            let prev = star.previousElementSibling;
            while(prev){prev.classList.add('selected'); prev=prev.previousElementSibling;}
            input.value = star.dataset.value;
        });
    });
});
</script>
</body>
</html>