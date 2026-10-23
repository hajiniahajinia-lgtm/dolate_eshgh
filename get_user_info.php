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

// میانگین امتیازات و رتبه در میان اعضای همین جامعه
$community_id = isset($_GET['community_id']) ? (int)$_GET['community_id'] : 0;
$avg_scores = [
    'avg_lawfulness' => null,
    'avg_ethics' => null,
    'avg_honesty' => null,
    'avg_executive' => null
];
$community_rank = null;
$community_member_count = 0;
$community_score_count = 0;

if ($community_id > 0) {
    $ranking_stmt = $conn->prepare("
        SELECT
            cm.user_id,
            AVG(us.lawfulness) AS avg_lawfulness,
            AVG(us.ethics) AS avg_ethics,
            AVG(us.honesty) AS avg_honesty,
            AVG(us.executive_skill) AS avg_executive,
            COUNT(us.scorer_user_id) AS score_count
        FROM community_members cm
        LEFT JOIN user_scores us
            ON us.target_user_id = cm.user_id
           AND us.scorer_user_id <> cm.user_id
           AND us.scorer_user_id IN (
               SELECT user_id FROM community_members WHERE community_id = ?
           )
        WHERE cm.community_id = ?
        GROUP BY cm.user_id
    ");
    if ($ranking_stmt) {
        $ranking_stmt->bind_param("ii", $community_id, $community_id);
        $ranking_stmt->execute();
        $ranking_result = $ranking_stmt->get_result();
        $member_scores = [];
        while ($member_score = $ranking_result->fetch_assoc()) {
            $community_member_count++;
            $member_score['score_count'] = (int)$member_score['score_count'];
            $member_score['composite_score'] = null;
            if ($member_score['score_count'] > 0) {
                $criteria_values = [
                    $member_score['avg_lawfulness'],
                    $member_score['avg_ethics'],
                    $member_score['avg_honesty'],
                    $member_score['avg_executive']
                ];
                $valid_values = array_filter($criteria_values, function($v) { return $v !== null; });
                if (count($valid_values) > 0) {
                    $member_score['composite_score'] = array_sum($valid_values) / count($valid_values);
                }
            }
            $member_scores[(int)$member_score['user_id']] = $member_score;
        }
        $ranking_stmt->close();

        if (isset($member_scores[$user_id])) {
            $target_score = $member_scores[$user_id];
            $community_score_count = (int)$target_score['score_count'];
            foreach ([
                'avg_lawfulness' => 'avg_lawfulness',
                'avg_ethics' => 'avg_ethics',
                'avg_honesty' => 'avg_honesty',
                'avg_executive' => 'avg_executive'
            ] as $output_key => $source_key) {
                $avg_scores[$output_key] = $target_score[$source_key] !== null
                    ? round((float)$target_score[$source_key], 1)
                    : null;
            }

            if ($target_score['composite_score'] !== null) {
                $rank = 1;
                foreach ($member_scores as $member_id => $member_score) {
                    if ($member_id !== $user_id
                        && $member_score['composite_score'] !== null
                        && $member_score['composite_score'] > $target_score['composite_score']) {
                        $rank++;
                    }
                }
                $community_rank = $rank;
            }
        }
    }
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
    'avg_executive' => $avg_scores['avg_executive'],
    'community_rank' => $community_rank,
    'community_member_count' => $community_member_count,
    'community_score_count' => $community_score_count
]);

$conn->close();
?>