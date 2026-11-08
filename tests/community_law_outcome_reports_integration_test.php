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
    $db->query("CREATE TABLE community_members (community_id INT NOT NULL,user_id INT NOT NULL,PRIMARY KEY(community_id,user_id)) ENGINE=InnoDB");
    $db->query("INSERT INTO community_members VALUES (20,700)");
    $db->query("INSERT INTO community_law_versions (id,law_id,version_number,title,law_text,proposed_by,status)
                VALUES (9001,501,1,'قانون آزمایشی','متن قانون',700,'approved')");
    $db->query("INSERT INTO community_law_implementations (law_version_id,community_id,status)
                VALUES (9001,20,'in_progress')");
    require_once __DIR__.'/../community_law_helpers.php';

    $db->begin_transaction();
    $report_id=community_law_submit_outcome_report(
        $db,501,9001,20,700,'outcome','2026-01-01','2026-01-31',
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

    $history=(int)$db->query("SELECT COUNT(*) total FROM community_law_history WHERE event_type='outcome_report_submitted'")->fetch_assoc()['total'];
    outcome_check($history===1,'report submission is recorded in law history');

    outcome_check(community_law_submit_outcome_report(
        $db,501,9001,20,701,'outcome','2026-01-01','2026-01-31','گزارش نامعتبر'
    )===false,'non-member cannot submit a report');
    outcome_check(community_law_submit_outcome_report(
        $db,501,9001,20,700,'progress','2026-02-01','2026-01-01','بازهٔ معکوس'
    )===false,'invalid reporting period is rejected');
    outcome_check(community_law_submit_outcome_report(
        $db,501,9001,20,700,'outcome','2026-01-01','2026-01-31','JSON نامعتبر',null,'{bad'
    )===false,'invalid metrics JSON is rejected');
    outcome_check(community_law_submit_outcome_report(
        $db,501,9999,20,700,'outcome','2026-01-01','2026-01-31','نسخهٔ نامرتبط'
    )===false,'report cannot be attached to an unrelated implementation');
    fwrite(STDOUT,"OK: community law outcome report integration checks passed.\n");
} finally { $db->close(); }
?>
