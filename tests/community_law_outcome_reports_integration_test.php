<?php
declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function outcome_check(bool $condition,string $message): void {
    if (!$condition) { fwrite(STDERR,"FAIL: {$message}\n"); exit(1); }
}
function outcome_apply_sql(mysqli $db,string $path): void {
    $sql=file_get_contents($path);
    if (!is_string($sql) || trim($sql)==='') throw new RuntimeException("Missing migration: {$path}");
    $db->multi_query($sql);
    do { $result=$db->store_result(); if ($result instanceof mysqli_result) $result->free(); }
    while ($db->more_results() && $db->next_result());
}
$host=getenv('DB_HOST')?:'127.0.0.1';
$name=getenv('DB_NAME')?:'dolate_charter_ci';
$user=getenv('DB_USER')?:'root';
$password=getenv('DB_PASSWORD')?:'';
$port=(int)(getenv('DB_PORT')?:3306);
$db=new mysqli($host,$user,$password,$name,$port);
$db->set_charset('utf8mb4');
try {
    outcome_apply_sql($db,__DIR__.'/../migrations/20261106_community_law_objections_schema_repair.sql');
    outcome_apply_sql($db,__DIR__.'/../migrations/20261107_community_law_framework.sql');
    outcome_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_outcome_reports.sql');
    outcome_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_outcome_reviews.sql');
    $db->query("CREATE TEMPORARY TABLE community_members (community_id INT NOT NULL,user_id INT NOT NULL,PRIMARY KEY(community_id,user_id)) ENGINE=InnoDB");
    $db->query("INSERT INTO community_members VALUES (920,9700),(920,9701),(920,9702),(920,9703)");
    $db->query("INSERT INTO community_law_versions (id,law_id,version_number,title,law_text,proposed_by,status)
                VALUES (990001,990000,1,'قانون آزمایشی','متن قانون',9700,'approved')");
    $db->query("INSERT INTO community_law_implementations (law_version_id,community_id,status)
                VALUES (990001,920,'in_progress')");
    require_once __DIR__.'/../community_law_helpers.php';

    $db->begin_transaction();
    $report_id=community_law_submit_outcome_report(
        $db,990000,990001,920,9700,'outcome','2026-01-01','2026-01-31',
        'نتیجهٔ اولیهٔ اجرای قانون و آثار مشاهده‌شده',
        'اسناد و شواهد پیوست‌شده',
        json_encode(['beneficiaries'=>120,'incidents'=>3],JSON_UNESCAPED_UNICODE)
    );
    outcome_check(is_int($report_id) && $report_id>0,'valid member can submit an outcome report');
    $db->commit();

    $row=$db->query("SELECT review_status,report_type,metrics_json FROM community_law_outcome_reports WHERE id=".(int)$report_id)->fetch_assoc();
    outcome_check($row && $row['review_status']==='submitted','new reports remain unverified until independent review');
    outcome_check($row && $row['report_type']==='outcome','report type is stored');
    outcome_check($row && json_decode($row['metrics_json'],true)['beneficiaries']===120,'structured metrics are preserved');

    $history=(int)$db->query("SELECT COUNT(*) total FROM community_law_history WHERE law_id=990000 AND event_type='outcome_report_submitted'")->fetch_assoc()['total'];
    outcome_check($history===1,'report submission is recorded in law history');

    outcome_check(community_law_submit_outcome_report(
        $db,990000,990001,920,9701,'outcome','2026-01-01','2026-01-31','گزارش نامعتبر'
    )===false,'non-member cannot submit a report');
    outcome_check(community_law_submit_outcome_report(
        $db,990000,990001,920,9700,'progress','2026-02-01','2026-01-01','بازهٔ معکوس'
    )===false,'invalid reporting period is rejected');
    outcome_check(community_law_submit_outcome_report(
        $db,990000,990001,920,9700,'outcome','2026-01-01','2026-01-31','JSON نامعتبر',null,'{bad'
    )===false,'invalid metrics JSON is rejected');
    outcome_check(community_law_submit_outcome_report(
        $db,990000,999999,920,9700,'outcome','2026-01-01','2026-01-31','نسخهٔ نامرتبط'
    )===false,'report cannot be attached to an unrelated implementation');

    outcome_check(community_law_review_outcome_report(
        $db,$report_id,9700,'confirm','گزارش‌دهنده نمی‌تواند گزارش خود را ارزیابی کند'
    )===false,'report author cannot review own report');
    $db->query("UPDATE community_law_implementations SET responsible_user_id=9703 WHERE law_version_id=990001 AND community_id=920");
    outcome_check(community_law_review_outcome_report(
        $db,$report_id,9703,'confirm','مسئول اجرا نمی‌تواند گزارش اجرای خود را ارزیابی کند'
    )===false,'implementation owner cannot review the report');
    $review_one=community_law_review_outcome_report($db,$report_id,9701,'confirm','شواهد با گزارش سازگار است','اثر انسانی مثبت');
    outcome_check(is_array($review_one) && $review_one['review_count']===1 && $review_one['review_status']==='under_review','first independent review keeps report under review');
    outcome_check(community_law_review_outcome_report($db,$report_id,9701,'challenge','تغییر رأی')===false,'reviewer cannot submit a second review');
    $review_two=community_law_review_outcome_report($db,$report_id,9702,'confirm','شواهد تکمیلی نیز گزارش را تأیید می‌کند');
    outcome_check(is_array($review_two) && $review_two['review_count']===2 && $review_two['review_status']==='under_review','two reviews do not finalize the report');
    $review_three=community_law_review_outcome_report($db,$report_id,9703,'challenge','بخشی از آثار منفی هنوز اندازه‌گیری نشده است');
    outcome_check(is_array($review_three) && $review_three['review_count']===3 && $review_three['review_status']==='verified','two of three independent reviews confirm the report');
    outcome_check(community_law_review_outcome_report($db,$report_id,9700,'challenge','پس از نهایی شدن')===false,'report author remains excluded after finalization');
    $final_status=$db->query("SELECT review_status FROM community_law_outcome_reports WHERE id=".(int)$report_id)->fetch_assoc();
    outcome_check($final_status && $final_status['review_status']==='verified','majority outcome is persisted');
    $review_history=(int)$db->query("SELECT COUNT(*) total FROM community_law_history WHERE law_id=990000 AND event_type='outcome_report_reviewed'")->fetch_assoc()['total'];
    outcome_check($review_history===3,'each independent review is recorded in law history');
    fwrite(STDOUT,"OK: community law outcome report integration checks passed.\n");
} finally { $db->close(); }
?>
