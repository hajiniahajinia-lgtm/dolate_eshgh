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
    change_case_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_change_effects.sql');
    require_once __DIR__.'/../community_law_change_helpers.php';

    $db->query("CREATE TABLE IF NOT EXISTS communities (id INT PRIMARY KEY, parent_community_id INT NULL)");
    $db->query("CREATE TABLE IF NOT EXISTS community_members (community_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY(community_id,user_id))");
    $db->query("INSERT INTO communities (id,parent_community_id) VALUES (90010,NULL),(90020,90010) ON DUPLICATE KEY UPDATE parent_community_id=VALUES(parent_community_id)");
    $db->query("INSERT INTO community_members (community_id,user_id) VALUES (90010,90100),(90020,90200),(90010,90101),(90010,90102),(90010,90103),(90010,90104),(90010,90105),(90010,90106),(90010,90107),(90010,90108) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)");
    $db->query("INSERT INTO community_laws (id,law_code,originating_community_id,created_by,status,current_version_id,effective_at) VALUES (9501,'LAW-CHANGE-TEST',90010,90100,'approved',99001,NOW())");
    $db->query("INSERT INTO community_law_versions (id,law_id,version_number,title,law_text,proposed_by,status,approved_at) VALUES (99001,9501,1,'قانون آزمایشی','متن قانون',90100,'approved',NOW())");
    $db->query("INSERT INTO community_law_scopes (law_version_id,scope_community_id,include_descendants) VALUES (99001,90010,1)");
    $db->query("INSERT INTO community_laws (id,law_code,originating_community_id,created_by,status,current_version_id,effective_at) VALUES (9502,'LAW-LOCAL-CONFORMITY-TEST',90020,90200,'approved',99002,NOW())");
    $db->query("INSERT INTO community_law_versions (id,law_id,version_number,title,law_text,proposed_by,status,approved_at) VALUES (99002,9502,1,'قانون محلی آزمایشی','متن قانون محلی',90200,'approved',NOW())");
    change_case_check(community_law_register_hierarchy_conformity_reviews($db,[90010=>0,90020=>90010],90020,90100),'a hierarchy change opens a conformity review for an approved local law under an applicable parent law');
    $conformity=$db->query("SELECT community_id,parent_community_id,local_law_id,local_law_version_id,parent_law_id,parent_law_version_id,status FROM community_law_conformity_cases WHERE community_id=90020 AND local_law_version_id=99002 AND parent_law_version_id=99001")->fetch_assoc();
    change_case_check($conformity && (int)$conformity['parent_community_id']===90010 && (int)$conformity['local_law_id']===9502 && $conformity['status']==='review_required','conformity case captures both exact law versions and awaits human review');
    $conflict_count=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_conformity_conflicts WHERE case_id=(SELECT id FROM community_law_conformity_cases WHERE community_id=90020 AND local_law_version_id=99002 AND parent_law_version_id=99001)")->fetch_assoc()['n'];
    change_case_check($conflict_count===0,'opening a conformity review does not falsely declare a semantic conflict');
    $db->query("INSERT INTO community_law_implementations (law_version_id,community_id,responsible_user_id,local_plan,status,started_at,target_date,progress_report) VALUES (99001,90010,90101,'برنامهٔ جامعهٔ مادر','in_progress',NOW(),DATE_ADD(CURDATE(),INTERVAL 5 DAY),'گزارش پیشرفت مادر'),(99001,90020,90102,'برنامهٔ زیرجامعه','completed',NOW(),DATE_ADD(CURDATE(),INTERVAL 1 DAY),'گزارش تکمیل زیرجامعه')");

    $case_id=community_law_open_change_case($db,9501,90200,90020,'suspend','خطر جدی برای حقوق اعضا','گزارش مستند',true);
    change_case_check(is_int($case_id) && $case_id>0,'a member of a scoped child community can submit an emergency suspension request');
    $case=$db->query("SELECT request_type,status,emergency_requested FROM community_law_change_cases WHERE id=".(int)$case_id)->fetch_assoc();
    change_case_check($case && $case['request_type']==='suspend' && $case['status']==='under_review' && (int)$case['emergency_requested']===1,'request enters independent review without changing law status');
    $reviewers=$db->query('SELECT reviewer_user_id FROM community_law_change_reviewers WHERE case_id='.(int)$case_id)->fetch_all(MYSQLI_ASSOC);
    change_case_check(count($reviewers)===5,'five independent reviewers are assigned');
    foreach ($reviewers as $reviewer) change_case_check(!in_array((int)$reviewer['reviewer_user_id'],[90100,90200],true),'proposer and requester are excluded from review');
    $notice_count=(int)$db->query('SELECT COUNT(*) AS n FROM community_law_community_notices WHERE case_id='.(int)$case_id)->fetch_assoc()['n'];
    change_case_check($notice_count===2,'all communities in the law scope receive a pending-request notice');
    foreach ($reviewers as $index=>$reviewer) {
        $verdict=$index<4?'uphold':'dismiss';
        $review_status=community_law_submit_change_assessment($db,$case_id,(int)$reviewer['reviewer_user_id'],$verdict,'بررسی مستند و مستقل انجام شد و دلایل کافی ثبت گردید.');
        change_case_check($review_status!==false,'assigned reviewer assessment is accepted');
    }
    $reviewed=$db->query('SELECT status,review_result,electorate_count FROM community_law_change_cases WHERE id='.(int)$case_id)->fetch_assoc();
    change_case_check($reviewed && $reviewed['status']==='awaiting_vote' && $reviewed['review_result']==='supported' && (int)$reviewed['electorate_count']===4,'four of five independent assessments snapshot the parent-community electorate and open voting');
    $electorate=$db->query('SELECT user_id FROM community_law_change_electorate WHERE case_id='.(int)$case_id.' ORDER BY user_id')->fetch_all(MYSQLI_ASSOC);
    change_case_check(count($electorate)===4,'independent reviewers are excluded from the parent voting electorate');
    change_case_check(community_law_change_cast_vote($db,$case_id,(int)$electorate[0]['user_id'],'yes'),'eligible parent voter can vote yes');
    change_case_check(community_law_change_cast_vote($db,$case_id,(int)$electorate[1]['user_id'],'yes'),'second eligible parent voter can vote yes');
    change_case_check(community_law_change_cast_vote($db,$case_id,(int)$electorate[2]['user_id'],'no'),'eligible parent voter can vote no');
    change_case_check(!community_law_change_cast_vote($db,$case_id,90200,'yes'),'a child-community member outside the captured parent electorate cannot vote');
    $db->query('UPDATE community_law_change_cases SET vote_closes_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id='.(int)$case_id);
    change_case_check(community_law_change_finalize_vote($db,$case_id)==='approved','two-thirds turnout and approval thresholds approve the request');
    change_case_check(!community_law_change_cast_vote($db,$case_id,(int)$reviewers[0]['reviewer_user_id'],'no'),'votes are closed after the deadline');
    $vote_case=$db->query('SELECT status,vote_result FROM community_law_change_cases WHERE id='.(int)$case_id)->fetch_assoc();
    change_case_check($vote_case && $vote_case['status']==='approved' && $vote_case['vote_result']==='approved','vote outcome is permanently recorded');
    $law=$db->query("SELECT status,repealed_at,suspended_at FROM community_laws WHERE id=9501")->fetch_assoc();
    change_case_check($law && $law['status']==='suspended' && $law['repealed_at']===null && $law['suspended_at']!==null,'an approved parent-community vote applies suspension and preserves repeal history');
    $implementation_states=$db->query("SELECT status FROM community_law_implementations WHERE law_version_id=99001 ORDER BY community_id")->fetch_all(MYSQLI_ASSOC);
    change_case_check(count($implementation_states)===2 && $implementation_states[0]['status']==='suspended' && $implementation_states[1]['status']==='suspended','all affected community implementation records enter suspended state');
    $snapshot_count=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_change_implementation_snapshots WHERE case_id=".(int)$case_id)->fetch_assoc()['n'];
    change_case_check($snapshot_count===2,'previous implementation plans, owners, progress and statuses are snapshotted before suspension');
    $applied=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_change_cases WHERE id=".(int)$case_id." AND effect_applied_at IS NOT NULL")->fetch_assoc()['n'];
    change_case_check($applied===1,'the applied legal effect is marked idempotently');

    $reinstate_case=community_law_open_change_case($db,9501,90200,90020,'reinstate','درخواست بازگرداندن قانون پس از رفع علت تعلیق','گزارش رفع مانع');
    change_case_check(is_int($reinstate_case) && $reinstate_case>0,'a member of an affected child community can request reinstatement');
    $reinstate_reviewers=$db->query('SELECT reviewer_user_id FROM community_law_change_reviewers WHERE case_id='.(int)$reinstate_case)->fetch_all(MYSQLI_ASSOC);
    change_case_check(count($reinstate_reviewers)===5,'reinstatement also receives five independent reviewers');
    foreach ($reinstate_reviewers as $index=>$reviewer) {
        $verdict=$index<4?'uphold':'dismiss';
        change_case_check(community_law_submit_change_assessment($db,$reinstate_case,(int)$reviewer['reviewer_user_id'],$verdict,'بررسی مستقل درخواست بازگردانی با شواهد انجام شد.')!==false,'reinstatement assessment is accepted');
    }
    $reinstate_electorate=$db->query('SELECT user_id FROM community_law_change_electorate WHERE case_id='.(int)$reinstate_case.' ORDER BY user_id')->fetch_all(MYSQLI_ASSOC);
    change_case_check(count($reinstate_electorate)===4,'reinstatement vote uses a separate parent-community electorate');
    foreach (array_slice($reinstate_electorate,0,3) as $voter) {
        change_case_check(community_law_change_cast_vote($db,$reinstate_case,(int)$voter['user_id'],'yes'),'parent voter can approve reinstatement');
    }
    $db->query('UPDATE community_law_change_cases SET vote_closes_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id='.(int)$reinstate_case);
    change_case_check(community_law_change_finalize_vote($db,$reinstate_case)==='approved','parent vote approves reinstatement');
    $restored_law=$db->query('SELECT status,suspended_at,suspension_case_id FROM community_laws WHERE id=9501')->fetch_assoc();
    change_case_check($restored_law && $restored_law['status']==='approved' && $restored_law['suspended_at']===null && $restored_law['suspension_case_id']===null,'approved reinstatement restores the law to active status');
    $restored=$db->query('SELECT community_id,responsible_user_id,local_plan,status,progress_report FROM community_law_implementations WHERE law_version_id=99001 ORDER BY community_id')->fetch_all(MYSQLI_ASSOC);
    change_case_check(count($restored)===2 && $restored[0]['status']==='in_progress' && $restored[0]['responsible_user_id']==='90101' && $restored[0]['local_plan']==='برنامهٔ جامعهٔ مادر' && $restored[1]['status']==='completed' && $restored[1]['responsible_user_id']==='90102' && $restored[1]['local_plan']==='برنامهٔ زیرجامعه','implementation owners, plans and statuses are restored from suspension snapshots');

    change_case_check(community_law_open_change_case($db,9501,999999,90020,'repeal','درخواست نامعتبر')===false,'non-member cannot submit a change request');
    change_case_check(community_law_open_change_case($db,9501,90200,90020,'repeal','')===false,'empty reasons are rejected');
    change_case_check(community_law_open_change_case($db,9501,90200,90020,'repeal','درخواست اضطراری',null,true)===false,'emergency flag is restricted to suspension requests');
    $history=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_history WHERE law_id=9501 AND event_type IN ('change_case_submitted','change_case_independent_review_completed','change_case_parent_vote_opened','change_case_parent_vote_finalized')")->fetch_assoc()['n'];
    change_case_check($history>=4,'request, independent review, vote opening and parent vote are recorded in permanent law history (observed '.$history.')');
    fwrite(STDOUT,"OK: community law change-control integration checks passed.\n");
} finally { $db->close(); }
?>
