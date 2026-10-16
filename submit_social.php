<?php
session_start();
include __DIR__ . "/db.php";

// بررسی لاگین
if(!isset($_SESSION['user_id'])){
    echo json_encode(['success'=>false, 'message'=>'ابتدا وارد شوید']);
    exit;
}

// بررسی پارامترهای لازم
if(!isset($_POST['user_id'], $_POST['ethical'], $_POST['lawful'], $_POST['responsible'])){
    echo json_encode(['success'=>false, 'message'=>'پارامترهای لازم ارسال نشده']);
    exit;
}

$user_id = (int)$_POST['user_id'];
$ethical = (int)$_POST['ethical'];
$lawful = (int)$_POST['lawful'];
$responsible = (int)$_POST['responsible'];

// بررسی معتبر بودن امتیازها
if($ethical<1 || $ethical>5 || $lawful<1 || $lawful>5 || $responsible<1 || $responsible>5){
    echo json_encode(['success'=>false, 'message'=>'مقادیر وارد شده معتبر نیست']);
    exit;
}

// ثبت در دیتابیس
$stmt = $conn->prepare("
    INSERT INTO user_social_ratings (rated_user_id, rater_user_id, ethical, lawful, responsible, created_at)
    VALUES (?, ?, ?, ?, ?, NOW())
");
$stmt->bind_param("iiiii", $user_id, $_SESSION['user_id'], $ethical, $lawful, $responsible);

if($stmt->execute()){
    echo json_encode(['success'=>true]);
} else {
    echo json_encode(['success'=>false, 'message'=>'خطا در ثبت محبوبیت']);
}
$stmt->close();
?>