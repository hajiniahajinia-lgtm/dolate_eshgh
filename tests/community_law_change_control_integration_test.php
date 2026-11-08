<?php
declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function change_case_check(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
function change_case_apply_sql(mysqli $db, string $path): void {
    $sql=file_get_contents($path);
    if (!is_string($sql) || trim($sql)==='') throw new RuntimeException("Missing SQL: {$path}");
    $db->multi_query($sql);
    do { $result=$db->store_result(); if ($result instanceof mysqli_result) $result->free(); }
    while ($db->more_results() && $db->next_result());
}
$host=getenv('DB_HOST')?:'127.0.0.1'; $name=getenv('DB_NAME')?:'dolate_charter_ci';
$user=getenv('DB_USER')?:'root'; $password=getenv('DB_PASSWORD')?:''; $port=(int)(getenv('DB_PORT')?:3306);
$db=new mysqli($host,$user,$password,$name,$port); $db->set_charset('utf8mb4');
try {
    change_case_apply_sql($db,__DIR__.'/../migrations/20261106_community_law_objections_schema_repair.sql');
    change_case_apply_sql($db,__DIR__.'/../migrations/20261107_community_law_framework.sql');
    change_case_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_change_control.sql');
    require_once __DIR__.'/../community_law_change_helpers.php';

    $db->query("CREATE TABLE IF NOT EXISTS communities (id INT PRIMARY KEY, parent_community_id INT NULL)");
    $db->query("CREATE TABLE IF NOT EXISTS community_members (community_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY(community_id,user_id))");
    $db->query("INSERT INTO communities (id,parent_community_id) VALUES (10,NULL),(20,10) ON DUPLICATE KEY UPDATE parent_community_id=VALUES(parent_community_id)");
    $db->query("INSERT INTO community_members (community_id,user_id) VALUES (10,100),(20,200) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)");
    $db->query("INSERT INTO community_laws (id,law_code,originating_community_id,created_by,status,current_version_id,effective_at) VALUES (501,'LAW-CHANGE-TEST',10,100,'approved',9001,NOW())");
    $db->query("INSERT INTO community_law_versions (id,law_id,version_number,title,law_text,proposed_by,status,approved_at) VALUES (9001,501,1,'قانون آزمایشی','متن قانون',100,'approved',NOW())");
    $db->query("INSERT INTO community_law_scopes (law_version_id,scope_community_id,include_descendants) VALUES (9001,10,1)");

    $case_id=community_law_open_change_case($db,501,200,20,'suspend','خطر جدی برای حقوق اعضا','گزارش مستند',true);
    change_case_check(is_int($case_id) && $case_id>0,'a member of a scoped child community can submit an emergency suspension request');
    $case=$db->query("SELECT request_type,status,emergency_requested FROM community_law_change_cases WHERE id=".(int)$case_id)->fetch_assoc();
    change_case_check($case && $case['request_type']==='suspend' && $case['status']==='submitted' && (int)$case['emergency_requested']===1,'request is recorded without skipping independent review');
    $law=$db->query("SELECT status,repealed_at FROM community_laws WHERE id=501")->fetch_assoc();
    change_case_check($law && $law['status']==='approved' && $law['repealed_at']===null,'submitting an emergency request never suspends or repeals the law automatically');

    change_case_check(community_law_open_change_case($db,501,999,20,'repeal','درخواست نامعتبر')===false,'non-member cannot submit a change request');
    change_case_check(community_law_open_change_case($db,501,200,20,'repeal','')===false,'empty reasons are rejected');
    change_case_check(community_law_open_change_case($db,501,200,20,'repeal','درخواست اضطراری',null,true)===false,'emergency flag is restricted to suspension requests');
    $history=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_history WHERE law_id=501 AND event_type='change_case_submitted'")->fetch_assoc()['n'];
    change_case_check($history===1,'case submission is recorded in the permanent law history');
    fwrite(STDOUT,"OK: community law change-control integration checks passed.\n");
} finally { $db->close(); }
?>
