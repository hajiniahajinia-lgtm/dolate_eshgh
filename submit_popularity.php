<?php
include "db.php";
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success'=>false, 'message'=>'کاربر وارد نشده']);
    exit;
}

$from_user = (int)$_SESSION['user_id'];
$user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

if ($user_id <= 0 || $from_user == $user_id) {
    echo json_encode(['success'=>false, 'message'=>'کاربر نامعتبر']);
    exit;
}

// بررسی اینکه کاربر قبلاً به این نفر رای داده است
$check = $conn->prepare("SELECT id FROM user_popularity WHERE user_id=? AND from_user=?");
$check->bind_param("ii", $user_id, $from_user);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    echo json_encode(['success'=>false, 'message'=>'شما قبلاً رای داده‌اید']);
    $check->close();
    exit;
}
$check->close();

// ثبت رای
$ethical = isset($_POST['ethical']) ? intval($_POST['ethical']) : 0;
$lawful = isset($_POST['lawful']) ? intval($_POST['lawful']) : 0;
$responsible = isset($_POST['responsible']) ? intval($_POST['responsible']) : 0;

$ins = $conn->prepare("INSERT INTO user_popularity (user_id, from_user, ethical, lawful, responsible) VALUES (?, ?, ?, ?, ?)");
$ins->bind_param("iiiii", $user_id, $from_user, $ethical, $lawful, $responsible);

if ($ins->execute()) {
    echo json_encode(['success'=>true]);
} else {
    echo json_encode(['success'=>false, 'message'=>'خطا در ثبت رای']);
}
$ins->close();
?>