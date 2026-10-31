<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['community_duty_csrf']) ||
    !isset($_POST['csrf_token']) || !is_string($_POST['csrf_token']) ||
    !hash_equals($_SESSION['community_duty_csrf'], $_POST['csrf_token'])) {
    http_response_code(403);
    exit('درخواست معتبر نیست. لطفاً صفحه را تازه‌سازی کنید و دوباره تلاش کنید.');
}
$user_id = (int)$_SESSION['user_id'];
if (!isset($_POST['idea_id'])) {
    http_response_code(400);
    exit("درخواست نامعتبر");
}
$idea_id = (int)$_POST['idea_id'];

$q = $conn->prepare("
    SELECT i.topic_id, t.community_id, t.closed
    FROM ideas i JOIN topics t ON i.topic_id=t.id
    WHERE i.id=?
");
$q->bind_param("i", $idea_id);
$q->execute();
$data = $q->get_result()->fetch_assoc();
$q->close();
if (!$data) die("ایده یافت نشد.");

$topic_id = (int)$data['topic_id'];
$community_id = (int)$data['community_id'];
if ((int)$data['closed'] === 1) {
    header("Location: community.php?id=".$community_id);
    exit;
}

try {
    $conn->begin_transaction();

    // حوزه فقط پس از تأیید جامعه مبنای وزن تخصصی است.
    $weight = 1.00;
    $competency = $conn->prepare("
        SELECT ec.level
        FROM topic_expertise_proposals p
        JOIN expertise_competencies ec
          ON ec.domain_id=p.domain_id AND ec.user_id=?
        WHERE p.topic_id=? AND p.community_id=? AND p.status='approved'
          AND ec.valid_from<=NOW() AND ec.valid_until>NOW()
        LIMIT 1
    ");
    if (!$competency) throw new RuntimeException("ساختار وزن تخصصی هنوز نصب نشده است.");
    $competency->bind_param("iii", $user_id, $topic_id, $community_id);
    $competency->execute();
    $competency_row = $competency->get_result()->fetch_assoc();
    $competency->close();
    if ($competency_row) {
        $weight = match ((int)$competency_row['level']) {
            1 => 1.25,
            2 => 1.50,
            3 => 2.00,
            4 => 2.50,
            default => 1.00
        };
    }

    // رأی قبلی کاربر (در صورت وجود) با وزن ذخیره‌شدهٔ خودش حذف می‌شود.
    $chk = $conn->prepare("SELECT idea_id, vote_weight FROM idea_votes WHERE user_id=? AND topic_id=? FOR UPDATE");
    $chk->bind_param("ii", $user_id, $topic_id);
    $chk->execute();
    $old_vote = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($old_vote) {
        $old_idea = (int)$old_vote['idea_id'];
        $old_weight = (float)($old_vote['vote_weight'] ?? 1.00);
        $del = $conn->prepare("DELETE FROM idea_votes WHERE user_id=? AND topic_id=?");
        $del->bind_param("ii", $user_id, $topic_id);
        $del->execute();
        $del->close();

        $dec = $conn->prepare("
            UPDATE ideas
            SET idea_rationality=GREATEST(0,idea_rationality-1),
                idea_rationality_weighted=GREATEST(0,idea_rationality_weighted-?)
            WHERE id=?
        ");
        $dec->bind_param("di", $old_weight, $old_idea);
        $dec->execute();
        $dec->close();
    }

    $ins = $conn->prepare("INSERT INTO idea_votes (idea_id, topic_id, user_id, vote_weight) VALUES (?,?,?,?)");
    $ins->bind_param("iiid", $idea_id, $topic_id, $user_id, $weight);
    $ins->execute();
    $ins->close();

    // شمارش خام و امتیاز وزن‌دار جدا نگهداری می‌شوند.
    $upd = $conn->prepare("
        UPDATE ideas
        SET idea_rationality=idea_rationality+1,
            idea_rationality_weighted=idea_rationality_weighted+?
        WHERE id=?
    ");
    $upd->bind_param("di", $weight, $idea_id);
    $upd->execute();
    $upd->close();

    $conn->commit();
    header("Location: community.php?id=".$community_id);
    exit;
} catch (Throwable $e) {
    $conn->rollback();
    error_log("Weighted idea vote failed: ".$e->getMessage());
    http_response_code(500);
    exit("ثبت رأی انجام نشد. لطفاً پس از بررسی نصب ساختار جدید دوباره تلاش کنید.");
}
?>
