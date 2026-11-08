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
    change_case_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_change_review.sql');
    change_case_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_change_voting.sql');
    require_once __DIR__.'/../community_law_change_helpers.php';

    $db->query("CREATE TABLE IF NOT EXISTS communities (id INT PRIMARY KEY, parent_community_id INT NULL)");
    $db->query("CREATE TABLE IF NOT EXISTS community_members (community_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY(community_id,user_id))");
    $db->query("INSERT INTO communities (id,parent_community_id) VALUES (10,NULL),(20,10) ON DUPLICATE KEY UPDATE parent_community_id=VALUES(parent_community_id)");
    $db->query("INSERT INTO community_members (community_id,user_id) VALUES (10,100),(20,200),(10,101),(10,102),(10,103),(10,104),(10,105) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)");
    $db->query("INSERT INTO community_laws (id,law_code,originating_community_id,created_by,status,current_version_id,effective_at) VALUES (501,'LAW-CHANGE-TEST',10,100,'approved',9001,NOW())");
    $db->query("INSERT INTO community_law_versions (id,law_id,version_number,title,law_text,proposed_by,status,approved_at) VALUES (9001,501,1,'قانون آزمایشی','متن قانون',100,'approved',NOW())");
    $db->query("INSERT INTO community_law_scopes (law_version_id,scope_community_id,include_descendants) VALUES (9001,10,1)");

    $case_id=community_law_open_change_case($db,501,200,20,'suspend','خطر جدی برای حقوق اعضا','گزارش مستند',true);
    change_case_check(is_int($case_id) && $case_id>0,'a member of a scoped child community can submit an emergency suspension request');
    $case=$db->query("SELECT request_type,status,emergency_requested FROM community_law_change_cases WHERE id=".(int)$case_id)->fetch_assoc();
    change_case_check($case && $case['request_type']==='suspend' && $case['status']==='under_review' && (int)$case['emergency_requested']===1,'request enters independent review without changing law status');
    $reviewers=$db->query('SELECT reviewer_user_id FROM community_law_change_reviewers WHERE case_id='.(int)$case_id)->fetch_all(MYSQLI_ASSOC);
    change_case_check(count($reviewers)===5,'five independent reviewers are assigned');
    foreach ($reviewers as $reviewer) change_case_check(!in_array((int)$reviewer['reviewer_user_id'],[100,200],true),'proposer and requester are excluded from review');
    $notice_count=(int)$db->query('SELECT COUNT(*) AS n FROM community_law_community_notices WHERE case_id='.(int)$case_id)->fetch_assoc()['n'];
    change_case_check($notice_count===2,'all communities in the law scope receive a pending-request notice');
    foreach ($reviewers as $index=>$reviewer) {
        $verdict=$index<4?'uphold':'dismiss';
        $review_status=community_law_submit_change_assessment($db,$case_id,(int)$reviewer['reviewer_user_id'],$verdict,'بررسی مستند و مستقل انجام شد و دلایل کافی ثبت گردید.');
        change_case_check($review_status!==false,'assigned reviewer assessment is accepted');
    }
    $reviewed=$db->query('SELECT status,review_result,electorate_count FROM community_law_change_cases WHERE id='.(int)$case_id)->fetch_assoc();
    change_case_check($reviewed && $reviewed['status']==='awaiting_vote' && $reviewed['review_result']==='supported' && (int)$reviewed['electorate_count']===6,'four of five independent assessments snapshot the parent-community electorate and open voting');
    foreach (array_slice($reviewers,0,4) as $reviewer) {
        change_case_check(community_law_change_cast_vote($db,$case_id,(int)$reviewer['reviewer_user_id'],'yes'),'eligible parent-community voter can vote yes');
    }
    change_case_check(community_law_change_cast_vote($db,$case_id,(int)$reviewers[4]['reviewer_user_id'],'no'),'eligible parent-community voter can vote no');
    change_case_check(!community_law_change_cast_vote($db,$case_id,200,'yes'),'a child-community member outside the captured parent electorate cannot vote');
    $db->query('UPDATE community_law_change_cases SET vote_closes_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id='.(int)$case_id);
    change_case_check(community_law_change_finalize_vote($db,$case_id)==='approved','two-thirds turnout and approval thresholds approve the request');
    change_case_check(!community_law_change_cast_vote($db,$case_id,(int)$reviewers[0]['reviewer_user_id'],'no'),'votes are closed after the deadline');
    $vote_case=$db->query('SELECT status,vote_result FROM community_law_change_cases WHERE id='.(int)$case_id)->fetch_assoc();
    change_case_check($vote_case && $vote_case['status']==='approved' && $vote_case['vote_result']==='approved','vote outcome is permanently recorded');
    $law=$db->query("SELECT status,repealed_at FROM community_laws WHERE id=501")->fetch_assoc();
    change_case_check($law && $law['status']==='approved' && $law['repealed_at']===null,'submitting an emergency request never suspends or repeals the law automatically');

    change_case_check(community_law_open_change_case($db,501,999,20,'repeal','درخواست نامعتبر')===false,'non-member cannot submit a change request');
    change_case_check(community_law_open_change_case($db,501,200,20,'repeal','')===false,'empty reasons are rejected');
    change_case_check(community_law_open_change_case($db,501,200,20,'repeal','درخواست اضطراری',null,true)===false,'emergency flag is restricted to suspension requests');
    $history=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_history WHERE law_id=501 AND event_type IN ('change_case_submitted','change_case_independent_review_completed','change_case_parent_vote_finalized')")->fetch_assoc()['n'];
    change_case_check($history===3,'request, independent review and parent vote are recorded in permanent law history');
    fwrite(STDOUT,"OK: community law change-control integration checks passed.\n");
} finally { $db->close(); }
?>
