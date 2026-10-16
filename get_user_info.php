<?php
session_start();
include "db.php";

header('Content-Type: application/json; charset=utf-8');

// بررسی ورود کاربر
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'error' => 'کاربر وارد نشده'
    ]);
    exit;
}

// بررسی شناسه کاربر
if (!isset($_GET['user_id']) || (int)$_GET['user_id'] <= 0) {
    echo json_encode([
        'success' => false,
        'error' => 'شناسه کاربر نامعتبر است'
    ]);
    exit;
}

$user_id = (int)$_GET['user_id'];

// دریافت اطلاعات پایه کاربر
$stmt = $conn->prepare("
    SELECT 
        id,
        COALESCE(age, '-') AS age, 
        COALESCE(gender, '-') AS gender, 
        COALESCE(education, '-') AS education, 
        COALESCE(job, '-') AS job
    FROM users 
    WHERE id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode([
        'success' => false,
        'error' => 'کاربر پیدا نشد'
    ]);
    exit;
}

$user = $result->fetch_assoc();
$stmt->close();

// میانگین امتیازات از جدول user_scores
$avg_scores = [
    'avg_lawfulness' => null,
    'avg_ethics' => null,
    'avg_honesty' => null,
    'avg_executive' => null
];

$score_stmt = $conn->prepare("
    SELECT 
        AVG(lawfulness) AS avg_lawfulness,
        AVG(ethics) AS avg_ethics,
        AVG(honesty) AS avg_honesty,
        AVG(executive_skill) AS avg_executive
    FROM user_scores
    WHERE target_user_id = ?
");
if ($score_stmt) {
    $score_stmt->bind_param("i", $user_id);
    $score_stmt->execute();
    $score_res = $score_stmt->get_result()->fetch_assoc();
    $avg_scores['avg_lawfulness'] = $score_res['avg_lawfulness'] !== null ? round($score_res['avg_lawfulness'], 1) : null;
    $avg_scores['avg_ethics'] = $score_res['avg_ethics'] !== null ? round($score_res['avg_ethics'], 1) : null;
    $avg_scores['avg_honesty'] = $score_res['avg_honesty'] !== null ? round($score_res['avg_honesty'], 1) : null;
    $avg_scores['avg_executive'] = $score_res['avg_executive'] !== null ? round($score_res['avg_executive'], 1) : null;
    $score_stmt->close();
}

// خروجی JSON نهایی
echo json_encode([
    'success' => true,
    'age' => $user['age'],
    'gender' => $user['gender'],
    'education' => $user['education'],
    'job' => $user['job'],
    'avg_lawfulness' => $avg_scores['avg_lawfulness'],
    'avg_ethics' => $avg_scores['avg_ethics'],
    'avg_honesty' => $avg_scores['avg_honesty'],
    'avg_executive' => $avg_scores['avg_executive']
]);

$conn->close();
?>