<?php
include "db.php";

$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $conn->prepare("SELECT username, age, gender, job, education FROM users WHERE id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if($user){
    echo json_encode($user);
} else {
    echo json_encode(['error' => 'کاربر یافت نشد']);
}
?>