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
    change_case_apply_sql($db,__DIR__.'/../migrations/20261107_community_law_voting_rules.sql');
    change_case_apply_sql($db,__DIR__.'/../migrations/20261107_community_law_voting_rule_approval.sql');
    change_case_apply_sql($db,__DIR__.'/../migrations/20261107_community_law_direct_voting.sql');
    change_case_apply_sql($db,__DIR__.'/../migrations/20261107_community_law_legal_screening.sql');
    change_case_apply_sql($db,__DIR__.'/../migrations/20261107_community_law_position_discussions.sql');
    change_case_apply_sql($db,__DIR__.'/../migrations/20261107_community_law_authority_review.sql');
    change_case_apply_sql($db,__DIR__.'/../migrations/20261107_community_law_final_results.sql');
    change_case_apply_sql($db,__DIR__.'/../migrations/20261107_community_law_final_review_appeals.sql');
    change_case_apply_sql($db,__DIR__.'/../migrations/20261107_community_charter_foundation.sql');
    change_case_apply_sql($db,__DIR__.'/../migrations/20261108_community_decision_ledger.sql');
    change_case_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_decision_origin.sql');
    change_case_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_change_control.sql');
    change_case_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_change_review.sql');
    change_case_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_change_voting.sql');
    change_case_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_change_effects.sql');
    change_case_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_conformity_workflow.sql');
    change_case_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_z1_amendment_00_workflow.sql');
    change_case_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_z1_amendment_10_submission.sql');
    change_case_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_z1_amendment_20_voting.sql');
    change_case_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_z1_amendment_30_parent_review.sql');
    change_case_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_z2_scope_notices.sql');
    change_case_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_z3_version_notices.sql');
    require_once __DIR__.'/../community_law_helpers.php';
    require_once __DIR__.'/../community_law_amendment_helpers.php';
    require_once __DIR__.'/../community_law_change_helpers.php';
    require_once __DIR__.'/../community_law_conformity_helpers.php';

    $db->query("CREATE TABLE IF NOT EXISTS communities (id INT PRIMARY KEY, parent_community_id INT NULL)");
    $db->query("CREATE TABLE IF NOT EXISTS community_members (community_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY(community_id,user_id))");
    $db->query("INSERT INTO communities (id,parent_community_id) VALUES (90010,NULL),(90020,90010) ON DUPLICATE KEY UPDATE parent_community_id=VALUES(parent_community_id)");
    $db->query("INSERT INTO community_members (community_id,user_id) VALUES (90010,90100),(90020,90200),(90010,90101),(90010,90102),(90010,90103),(90010,90104),(90010,90105),(90010,90106),(90010,90107),(90010,90108),(90020,90201),(90020,90202),(90020,90203),(90020,90204),(90020,90205),(90020,90206),(90020,90207),(90020,90106) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)");
    $db->query("INSERT INTO community_laws (id,law_code,originating_community_id,created_by,status,current_version_id,effective_at) VALUES (9501,'LAW-CHANGE-TEST',90010,90100,'approved',99001,NOW())");
    $db->query("INSERT INTO community_law_versions (id,law_id,version_number,title,law_text,proposed_by,status,approved_at) VALUES (99001,9501,1,'قانون آزمایشی','متن قانون',90100,'approved',NOW())");
    $db->query("INSERT INTO community_law_scopes (law_version_id,scope_community_id,include_descendants) VALUES (99001,90010,1)");
    $db->query("INSERT INTO community_laws (id,law_code,originating_community_id,created_by,status,current_version_id,effective_at) VALUES (9502,'LAW-LOCAL-CONFORMITY-TEST',90020,90200,'approved',99002,NOW())");
    $db->query("INSERT INTO community_law_versions (id,law_id,version_number,title,law_text,proposed_by,status,approved_at) VALUES (99002,9502,1,'قانون محلی آزمایشی','متن قانون محلی',90200,'approved',NOW())");
    $db->query("INSERT INTO community_law_scopes (law_version_id,scope_community_id,include_descendants) VALUES (99002,90020,1)");
    $db->query("INSERT INTO community_law_voting_rules (law_version_id,jurisdiction_level,individual_quorum_percent,individual_approval_percent,community_quorum_percent,community_approval_percent,community_aggregate_quorum_percent,community_aggregate_approval_percent,require_community_approval,individual_vote_basis,community_vote_basis,status,configured_by,configured_at,approved_at) VALUES (99002,'local',50,50.01,NULL,NULL,NULL,NULL,0,'count',NULL,'approved',90200,NOW(),NOW())");
    change_case_check(community_law_register_hierarchy_conformity_reviews($db,[90010=>0,90020=>90010],90020,90100),'a hierarchy change opens a conformity review for an approved local law under an applicable parent law');
    $conformity=$db->query("SELECT community_id,parent_community_id,local_law_id,local_law_version_id,parent_law_id,parent_law_version_id,status FROM community_law_conformity_cases WHERE community_id=90020 AND local_law_version_id=99002 AND parent_law_version_id=99001")->fetch_assoc();
    change_case_check($conformity && (int)$conformity['parent_community_id']===90010 && (int)$conformity['local_law_id']===9502 && $conformity['status']==='review_required','conformity case captures both exact law versions and awaits human review');
    $conflict_count=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_conformity_conflicts WHERE case_id=(SELECT id FROM community_law_conformity_cases WHERE community_id=90020 AND local_law_version_id=99002 AND parent_law_version_id=99001)")->fetch_assoc()['n'];
    change_case_check($conflict_count===0,'opening a conformity review does not falsely declare a semantic conflict');

    // Deep hierarchy regression: a moved parent must trigger reviews for every
    // descendant law against every applicable ancestor law, not only one level.
    $db->query("INSERT INTO communities (id,parent_community_id) VALUES (90030,90020) ON DUPLICATE KEY UPDATE parent_community_id=VALUES(parent_community_id)");
    $db->query("INSERT INTO community_laws (id,law_code,originating_community_id,created_by,status,current_version_id,effective_at) VALUES (9503,'LAW-DEEP-LOCAL-TEST',90030,90200,'approved',99003,NOW())");
    $db->query("INSERT INTO community_law_versions (id,law_id,version_number,title,law_text,proposed_by,status,approved_at) VALUES (99003,9503,1,'قانون جامعهٔ زیرشاخهٔ عمیق','متن قانون زیرشاخه',90200,'approved',NOW())");
    $db->query("INSERT INTO community_law_scopes (law_version_id,scope_community_id,include_descendants) VALUES (99003,90030,1)");
    change_case_check(
        community_law_register_hierarchy_conformity_reviews($db,[90010=>0,90020=>90010,90030=>90020],90020,90100),
        'moving a parent community opens conformity reviews for deep descendants'
    );
    $deep_cases=$db->query("SELECT parent_law_version_id FROM community_law_conformity_cases WHERE community_id=90030 AND local_law_version_id=99003 ORDER BY parent_law_version_id")->fetch_all(MYSQLI_ASSOC);
    $deep_parent_versions=array_map('intval',array_column($deep_cases,'parent_law_version_id'));
    sort($deep_parent_versions,SORT_NUMERIC);
    change_case_check(
        $deep_parent_versions===[99001,99002],
        'deep descendant law is checked against both immediate and higher-level applicable parent laws'
    );
    $conformity_case_id=(int)$db->query("SELECT id FROM community_law_conformity_cases WHERE community_id=90020 AND local_law_version_id=99002 AND parent_law_version_id=99001")->fetch_assoc()['id'];
    $conflict_id=community_law_conformity_add_conflict($db,$conformity_case_id,90200,'ماده ۳','ماده ۷','تعارض احتمالی دربارهٔ دامنهٔ اختیار جامعهٔ محلی','متن و مستندات دو ماده');
    change_case_check(is_int($conflict_id) && $conflict_id>0,'local member can record a conflict with exact article references and evidence');
    change_case_check(!community_law_conformity_review_conflict($db,$conflict_id,90200,90020,'conflict_confirmed','این بررسی نباید توسط ثبت‌کنندهٔ تعارض انجام شود.'),'conflict submitter cannot review their own conflict');
    change_case_check(community_law_conformity_review_conflict($db,$conflict_id,90201,90020,'conflict_confirmed','از منظر جامعهٔ محلی، تعارض با مادهٔ بالادستی مستند است.'),'a separate local-community member can submit the local review');
    $pending_finding=$db->query("SELECT finding FROM community_law_conformity_conflicts WHERE id=".$conflict_id)->fetch_assoc()['finding'];
    change_case_check($pending_finding==='unreviewed','one side alone cannot finalize a conformity conflict');
    change_case_check(community_law_conformity_review_conflict($db,$conflict_id,90102,90010,'conflict_confirmed','از منظر جامعهٔ مادر، تعارض با مادهٔ لازم‌الاجرا تأیید می‌شود.'),'a separate parent-community member can submit the parent review');
    $confirmed=$db->query("SELECT f.finding,c.status FROM community_law_conformity_conflicts f JOIN community_law_conformity_cases c ON c.id=f.case_id WHERE f.id=".$conflict_id)->fetch_assoc();
    change_case_check($confirmed && $confirmed['finding']==='conflict_confirmed' && $confirmed['status']==='amendment_required','two-sided confirmed conflict requires a local amendment and records the finding');
    $review_history=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_history WHERE law_id=9502 AND event_type='conformity_conflict_reviewed'")->fetch_assoc()['n'];
    change_case_check($review_history===2,'both independent reviews are preserved in the local law history');

    // پیشنهاد اصلاح فقط مواد مشخص را تغییر می‌دهد و نسخه مصوب را دست‌نخورده نگه می‌دارد.
    $amendment_id=community_law_create_amendment_draft(
        $db,$conformity_case_id,90020,90201,
        'اصلاح مادهٔ مشخص‌شده برای رفع تعارض تأییدشده با قانون مادر.',
        [[
            'reference'=>'ماده ۳',
            'previous_text'=>'متن قانون محلی',
            'proposed_text'=>'متن اصلاح‌شدهٔ قانون محلی',
            'reason'=>'رفع تعارض مستند با مادهٔ قانون مادر'
        ]],
        'ارجاع به پرونده تعارض و شواهد ثبت‌شده'
    );
    change_case_check(is_int($amendment_id) && $amendment_id>0,'a local member can create a versioned amendment draft for a confirmed conformity conflict');
    $amendment=$db->query("SELECT a.status,a.base_version_id,a.proposed_version_id,v.version_number,v.status AS version_status,v.law_text,l.current_version_id,base.law_text AS base_text,base.status AS base_status FROM community_law_amendment_cases a JOIN community_law_versions v ON v.id=a.proposed_version_id JOIN community_law_versions base ON base.id=a.base_version_id JOIN community_laws l ON l.id=a.law_id WHERE a.id=".$amendment_id)->fetch_assoc();
    change_case_check($amendment && $amendment['status']==='draft' && $amendment['version_status']==='draft' && (int)$amendment['version_number']===2,'amendment creates a separate draft version without approving it');
    change_case_check($amendment && (int)$amendment['current_version_id']===99002 && $amendment['base_status']==='approved' && $amendment['base_text']==='متن قانون محلی','the approved base version remains unchanged and current until the full approval workflow completes');
    change_case_check($amendment && $amendment['law_text']==='متن اصلاح‌شدهٔ قانون محلی','only the explicitly selected article text is replaced in the new draft while the rest of the approved law text is preserved');
    $amendment_articles=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_amendment_articles WHERE case_id=".$amendment_id)->fetch_assoc()['n'];
    change_case_check($amendment_articles===1,'article reference, previous wording, proposed wording and reason are separately preserved');
    $copied_scope=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_scopes WHERE law_version_id=".(int)$amendment['proposed_version_id']." AND scope_community_id=90020 AND include_descendants=1")->fetch_assoc()['n'];
    change_case_check($copied_scope===1,'amendment draft snapshots the exact approved base-law scope');
    change_case_check(!community_law_submit_amendment_for_vote($db,$amendment_id,90020,90201,false),'amendment cannot be submitted without explicit acceptance of the approved base voting rules');
    change_case_check(community_law_submit_amendment_for_vote($db,$amendment_id,90020,90201,true),'local member can submit amendment after explicitly adopting the approved base voting rules');
    $submitted=$db->query("SELECT a.status,a.voting_rules_source_version_id,a.rules_adopted_by,a.rules_adopted_at,a.base_version_id,a.proposed_version_id,v.status AS proposed_status,l.status AS law_status,l.current_version_id,r.status AS copied_rules_status,c.status AS conformity_status FROM community_law_amendment_cases a JOIN community_law_versions v ON v.id=a.proposed_version_id JOIN community_laws l ON l.id=a.law_id JOIN community_law_voting_rules r ON r.law_version_id=a.proposed_version_id JOIN community_law_conformity_cases c ON c.id=a.conformity_case_id WHERE a.id=".(int)$amendment_id)->fetch_assoc();
    change_case_check($submitted && $submitted['status']==='submitted' && (int)$submitted['voting_rules_source_version_id']===99002 && (int)$submitted['rules_adopted_by']===90201 && $submitted['rules_adopted_at']!==null && $submitted['proposed_status']==='proposed' && $submitted['copied_rules_status']==='approved','submission records the exact source and actor for the unchanged approved voting rules');
    change_case_check($submitted && $submitted['law_status']==='approved' && (int)$submitted['current_version_id']===99002,'submitting an amendment never changes the current approved law or its status');
    change_case_check($submitted && $submitted['conformity_status']==='amendment_in_progress','submission moves the conformity case into the amendment workflow without resolving the underlying conflict prematurely');
    $review_setup=$db->query("SELECT rc.status AS screening_status,ac.status AS authority_status,ac.derived_jurisdiction_level,(SELECT COUNT(*) FROM community_law_review_electorate e WHERE e.law_version_id=a.proposed_version_id) AS reviewer_count,(SELECT COUNT(*) FROM community_law_authority_references ar WHERE ar.law_version_id=a.proposed_version_id AND ar.reference_law_id=9501 AND ar.relation_type='higher') AS parent_reference_count FROM community_law_amendment_cases a JOIN community_law_review_cases rc ON rc.law_version_id=a.proposed_version_id JOIN community_law_authority_cases ac ON ac.law_version_id=a.proposed_version_id WHERE a.id=".(int)$amendment_id)->fetch_assoc();
    change_case_check($review_setup && $review_setup['screening_status']==='pending' && $review_setup['authority_status']==='pending' && $review_setup['derived_jurisdiction_level']==='parent_shared' && (int)$review_setup['reviewer_count']>=3 && (int)$review_setup['parent_reference_count']===1,'amendment submission opens fresh independent legal and authority reviews with shared jurisdiction and the active parent law captured as a higher reference');

    $submission_event=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_amendment_events WHERE case_id=".(int)$amendment_id." AND event_type='amendment_submitted'")->fetch_assoc()['n'];
    change_case_check($submission_event===1,'explicit rule adoption and amendment submission are recorded as an immutable event');

    // پس از رأی محلی مثبت، دو عضو مستقل جامعه مادر فقط سازگاری را ارزیابی می‌کنند.
    $proposed_version_id=(int)$submitted['proposed_version_id'];
    $db->query("UPDATE community_law_versions SET status='voting',vote_deadline=DATE_SUB(NOW(),INTERVAL 1 DAY) WHERE id=".$proposed_version_id);
    $db->query("UPDATE community_law_amendment_cases SET status='parent_review' WHERE id=".(int)$amendment_id);
    change_case_check(!community_law_submit_amendment_parent_review($db,$amendment_id,90010,90104,'conflict_remains',['ماده ناموجود'],'ارجاع نامعتبر نباید پذیرفته شود.'),'parent compatibility review cannot cite an article outside the amendment');
    change_case_check(community_law_submit_amendment_parent_review($db,$amendment_id,90010,90104,'conflict_remains',['ماده ۳'],'در مادهٔ اصلاح‌شده هنوز تعارض مشخصی با قانون مادر باقی است.'),'parent reviewer can identify a remaining conflict in an amended article');
    $one_parent_review=$db->query("SELECT status FROM community_law_amendment_cases WHERE id=".(int)$amendment_id)->fetch_assoc();
    change_case_check($one_parent_review && $one_parent_review['status']==='parent_review','one parent reviewer cannot finalize compatibility alone');
    change_case_check(community_law_submit_amendment_parent_review($db,$amendment_id,90010,90105,'compatible',[],'از نظر این ارزیاب، سایر مواد اصلاح‌شده سازگارند.'),'a second independent parent reviewer can submit a separate compatibility finding');
    $parent_result=$db->query("SELECT a.status,c.status AS conformity_status,l.status AS law_status,l.current_version_id,v.law_text FROM community_law_amendment_cases a JOIN community_law_conformity_cases c ON c.id=a.conformity_case_id JOIN community_laws l ON l.id=a.law_id JOIN community_law_versions v ON v.id=a.proposed_version_id WHERE a.id=".(int)$amendment_id)->fetch_assoc();
    change_case_check($parent_result && $parent_result['status']==='revision_required' && $parent_result['conformity_status']==='amendment_required','any independently documented remaining conflict returns only the amendment to the local community');
    change_case_check($parent_result && $parent_result['law_status']==='approved' && (int)$parent_result['current_version_id']===99002 && $parent_result['law_text']==='متن اصلاح‌شدهٔ قانون محلی','parent review cannot replace local text or change the active approved law');
    $parent_history=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_amendment_parent_reviews WHERE case_id=".(int)$amendment_id)->fetch_assoc()['n'];
    change_case_check($parent_history===2,'both independent parent compatibility reviews are preserved');

    // پس از اصلاح دوباره توسط جامعه محلی، دو تأیید سازگاری مرحله را به بررسی نهایی می‌برند.
    $second_amendment_id=community_law_create_amendment_draft(
        $db,$conformity_case_id,90020,90201,
        'اصلاح دوباره پس از اعلام تعارض باقی‌مانده از سوی جامعه مادر.',
        [[
            'reference'=>'ماده ۳',
            'previous_text'=>'متن قانون محلی',
            'proposed_text'=>'متن اصلاح‌شدهٔ دوم قانون محلی',
            'reason'=>'رفع تعارض باقی‌مانده طبق بررسی مستند'
        ]]
    );
    change_case_check(is_int($second_amendment_id) && $second_amendment_id>0,'local community can submit a fresh version after the previous amendment is returned for revision');
    change_case_check(community_law_submit_amendment_for_vote($db,$second_amendment_id,90020,90201,true),'revised amendment can explicitly adopt the same approved base voting rules');
    $second_version=(int)$db->query("SELECT proposed_version_id FROM community_law_amendment_cases WHERE id=".(int)$second_amendment_id)->fetch_assoc()['proposed_version_id'];
    $db->query("UPDATE community_law_versions SET status='voting',vote_deadline=DATE_SUB(NOW(),INTERVAL 1 DAY) WHERE id=".$second_version);
    $db->query("UPDATE community_law_amendment_cases SET status='parent_review' WHERE id=".(int)$second_amendment_id);
    change_case_check(community_law_submit_amendment_parent_review($db,$second_amendment_id,90010,90106,'compatible',[],'نسخه اصلاح‌شده با مواد لازم‌الاجرای قانون مادر سازگار است.'),'first parent reviewer can confirm compatibility of the revised amendment');
    change_case_check(community_law_submit_amendment_parent_review($db,$second_amendment_id,90010,90107,'compatible',[],'بررسی مستقل دوم نیز سازگاری نسخه اصلاح‌شده را تأیید می‌کند.'),'second independent parent reviewer can confirm compatibility');
    $approved_parent_review=$db->query("SELECT a.status,l.status AS law_status,l.current_version_id FROM community_law_amendment_cases a JOIN community_laws l ON l.id=a.law_id WHERE a.id=".(int)$second_amendment_id)->fetch_assoc();
    change_case_check($approved_parent_review && $approved_parent_review['status']==='parent_review_approved' && $approved_parent_review['law_status']==='approved' && (int)$approved_parent_review['current_version_id']===99002,'two compatible parent reviews clear the amendment for independent final review without enacting it');

    // بررسی نهایی، مهلت اعتراض و ابلاغ اتمی؛ نسخه جاری تا پایان این مرحله همان نسخه پایه می‌ماند.
    $db->query("UPDATE community_law_review_cases SET status='screening_clear' WHERE law_version_id=".$second_version);
    $db->query("UPDATE community_law_authority_cases SET status='confirmed' WHERE law_version_id=".$second_version);
    $db->query("INSERT INTO community_law_final_results (law_version_id,individual_vote_basis,final_status) VALUES (".$second_version.",'count','vote_passed')");
    $db->query("INSERT INTO community_law_implementations (law_version_id,community_id,responsible_user_id,local_plan,status,started_at,target_date,progress_report) VALUES (99002,90020,90200,'برنامه اجرای پایه','in_progress',NOW(),DATE_ADD(CURDATE(),INTERVAL 30 DAY),'پیشرفت ثبت‌شده پیش از اصلاحیه'),(99002,90030,90200,'برنامه اجرای زیرشاخه','in_progress',NOW(),DATE_ADD(CURDATE(),INTERVAL 45 DAY),'گزارش اجرای زیرشاخه پیش از اصلاحیه')");
    change_case_check(community_law_open_amendment_final_review($db,9502,$second_version,90020,90201),'approved amendment with positive local vote can open the independent final review');
    $opened_final_review=$db->query("SELECT l.status AS law_status,l.current_version_id,v.status AS version_status,fc.status AS final_status,(SELECT COUNT(*) FROM community_law_final_review_electorate e WHERE e.law_version_id=v.id) AS electorate_count,(SELECT COUNT(*) FROM community_law_final_review_references r WHERE r.law_version_id=v.id AND r.reference_law_id=9501 AND r.reference_law_version_id=99001) AS parent_reference_count,(SELECT COUNT(*) FROM community_law_charter_references cr WHERE cr.law_version_id=v.id) AS charter_snapshot_count FROM community_law_versions v JOIN community_laws l ON l.id=v.law_id JOIN community_law_final_review_cases fc ON fc.law_version_id=v.id WHERE v.id=".$second_version)->fetch_assoc();
    change_case_check($opened_final_review && $opened_final_review['law_status']==='approved' && (int)$opened_final_review['current_version_id']===99002 && $opened_final_review['version_status']==='pending_final_review' && $opened_final_review['final_status']==='pending' && (int)$opened_final_review['electorate_count']>=3 && (int)$opened_final_review['parent_reference_count']===1 && (int)$opened_final_review['charter_snapshot_count']>0,'opening final review snapshots independent assessors, the active parent law and the charter without changing the current law');
    $parent_reviewer_excluded=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_final_review_electorate WHERE law_version_id=".$second_version." AND user_id=90106")->fetch_assoc()['n'];
    change_case_check($parent_reviewer_excluded===0,'a member who reviewed parent compatibility is excluded from the independent final review electorate even if they also belong to the local community');
    foreach([90205,90206,90207] as $final_reviewer) {
        $db->query("INSERT INTO community_law_final_review_assessments
            (law_version_id,reviewer_user_id,verdict,charter_and_rights,non_discrimination,power_concentration,
             human_and_social_impact,environmental_impact,economic_and_resource_impact,
             future_generations_and_other_life,implementation_and_accountability,reasoning)
            VALUES (".$second_version.",".$final_reviewer.",'confirm','pass','pass','pass','pass','pass','pass','pass','pass','بررسی نهایی مستقل و بدون تعارض')");
    }
    $db->query("UPDATE community_law_final_review_cases SET status='appeal_period',appeal_deadline=DATE_SUB(NOW(),INTERVAL 1 DAY),review_completed_at=NOW() WHERE law_version_id=".$second_version);
    $db->query("UPDATE community_law_versions SET status='appeal_period' WHERE id=".$second_version);
    $db->query("INSERT INTO community_law_appeals (law_version_id,community_id,submitted_by,appeal_type,description,status) VALUES (".$second_version.",90020,90200,'new_evidence','اعتراض آزمایشی برای جلوگیری از ابلاغ زودهنگام','submitted')");
    change_case_check(!community_law_enact_amendment($db,9502,$second_version,90020,90201),'an unresolved appeal blocks amendment activation');
    $still_current=(int)$db->query("SELECT current_version_id FROM community_laws WHERE id=9502")->fetch_assoc()['current_version_id'];
    change_case_check($still_current===99002,'a pending appeal leaves the previous approved version active');
    $db->query("UPDATE community_law_appeals SET status='dismissed',decided_at=NOW() WHERE law_version_id=".$second_version);

    // شکست عمدی اعلان جامعه زیرشاخه باید تمام فعال‌سازی اصلاحیه را rollback کند.
    $db->query("CREATE TRIGGER test_fail_amendment_version_notice BEFORE INSERT ON community_law_version_notices
        FOR EACH ROW BEGIN
            IF NEW.law_version_id=".$second_version." AND NEW.community_id=90030 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced amendment notice failure';
            END IF;
        END");
    change_case_check(!community_law_enact_amendment($db,9502,$second_version,90020,90201),'a failed notice for one scoped descendant aborts amendment enactment');
    $db->query("DROP TRIGGER test_fail_amendment_version_notice");
    $rollback_state=$db->query("SELECT l.current_version_id,base.status AS base_status,v.status AS version_status,
        a.status AS amendment_status,fc.status AS final_status,
        (SELECT COUNT(*) FROM community_law_implementations i WHERE i.law_version_id=".$second_version.") AS copied_implementations,
        (SELECT COUNT(*) FROM community_law_version_notices n WHERE n.law_version_id=".$second_version.") AS notices,
        (SELECT COUNT(*) FROM community_law_amendment_events e WHERE e.case_id=".(int)$second_amendment_id." AND e.event_type='amendment_enacted') AS enactment_events,
        (SELECT COUNT(*) FROM community_law_history h WHERE h.law_id=9502 AND h.event_type='law_amendment_enacted') AS enactment_history
        FROM community_laws l JOIN community_law_versions base ON base.id=99002
        JOIN community_law_versions v ON v.id=".$second_version."
        JOIN community_law_amendment_cases a ON a.id=".(int)$second_amendment_id."
        JOIN community_law_final_review_cases fc ON fc.law_version_id=".$second_version."
        WHERE l.id=9502")->fetch_assoc();
    change_case_check($rollback_state && (int)$rollback_state['current_version_id']===99002 &&
        $rollback_state['base_status']==='approved' && $rollback_state['version_status']==='appeal_period' &&
        $rollback_state['amendment_status']==='parent_review_approved' && $rollback_state['final_status']==='appeal_period' &&
        (int)$rollback_state['copied_implementations']===0 && (int)$rollback_state['notices']===0 &&
        (int)$rollback_state['enactment_events']===0 && (int)$rollback_state['enactment_history']===0,
        'failed descendant notice rolls back the version pointer, statuses, copied implementation, event and history');

    change_case_check(community_law_enact_amendment($db,9502,$second_version,90020,90201),'amendment is activated only after the complete final review, expired appeal period, dismissal of all appeals, and successful notices');
    $activated=$db->query("SELECT l.status AS law_status,l.current_version_id,base.status AS base_status,newv.status AS new_status,a.status AS amendment_status,c.status AS conformity_status,fc.status AS final_review_status,impl.status AS implementation_status,impl.progress_report FROM community_laws l JOIN community_law_versions base ON base.id=99002 JOIN community_law_versions newv ON newv.id=".(int)$second_version." JOIN community_law_amendment_cases a ON a.id=".(int)$second_amendment_id." JOIN community_law_conformity_cases c ON c.id=".(int)$conformity_case_id." JOIN community_law_final_review_cases fc ON fc.law_version_id=".(int)$second_version." JOIN community_law_implementations impl ON impl.law_version_id=".(int)$second_version." AND impl.community_id=90020 WHERE l.id=9502")->fetch_assoc();
    change_case_check($activated && $activated['law_status']==='approved' && (int)$activated['current_version_id']===$second_version && $activated['base_status']==='superseded' && $activated['new_status']==='approved' && $activated['amendment_status']==='approved' && $activated['conformity_status']==='resolved' && $activated['final_review_status']==='enacted','amendment activation atomically advances the law pointer and preserves the superseded version');
    change_case_check($activated && $activated['implementation_status']==='in_progress' && $activated['progress_report']==='پیشرفت ثبت‌شده پیش از اصلاحیه','implementation progress and accountability history are carried into the enacted amendment');
    $notice_rows=$db->query("SELECT community_id,previous_version_id,event_type,details FROM community_law_version_notices WHERE law_version_id=".$second_version." ORDER BY community_id")->fetch_all(MYSQLI_ASSOC);
    $notice_communities=array_map('intval',array_column($notice_rows,'community_id'));
    $notice_details_ok=count($notice_rows)===2;
    foreach($notice_rows as $notice_row) {
        $details=json_decode((string)$notice_row['details'],true);
        if ((int)$notice_row['previous_version_id']!==99002 || $notice_row['event_type']!=='version_activated' ||
            !is_array($details) || (int)($details['previous_version_id']??0)!==99002 ||
            (int)($details['current_version_id']??0)!==$second_version ||
            ($details['implementation_state_copied']??false)!==true) $notice_details_ok=false;
    }
    change_case_check($notice_communities===[90020,90030] && $notice_details_ok,
        'successful enactment sends exactly one version notice to the origin community and every descendant in scope with both version identifiers');
    change_case_check(!community_law_enact_amendment($db,9502,$second_version,90020,90201),'an already enacted amendment cannot be activated a second time');



    change_case_check(community_law_create_amendment_draft($db,$conformity_case_id,90020,90200,'درخواست با متن تکراری.',[[
        'reference'=>'ماده ۴','previous_text'=>'متن محلی','proposed_text'=>'متن تازه','reason'=>'دلیل'
    ]])===false,'a second amendment draft is blocked while the conformity case is already in progress');
    change_case_check(community_law_create_amendment_draft($db,$conformity_case_id,90010,90100,'درخواست جامعه مادر.',[[
        'reference'=>'ماده ۳','previous_text'=>'متن قانون محلی','proposed_text'=>'متن دیگر','reason'=>'دلیل'
    ]])===false,'parent-community members cannot author a local law amendment in place of the local community');

    // مسیر مستقل: هر دو جامعه تعارض را رد می‌کنند؛ پرونده باید حل‌شده شود.
    $db->query("INSERT INTO community_laws (id,law_code,originating_community_id,created_by,status,current_version_id,effective_at) VALUES (9504,'LAW-LOCAL-NO-CONFLICT-TEST',90020,90200,'approved',99004,NOW())");
    $db->query("INSERT INTO community_law_versions (id,law_id,version_number,title,law_text,proposed_by,status,approved_at) VALUES (99004,9504,1,'قانون محلی بدون تعارض','متن قانون محلی دوم',90200,'approved',NOW())");
    $db->query("INSERT INTO community_law_scopes (law_version_id,scope_community_id,include_descendants) VALUES (99004,90020,1)");
    $db->query("INSERT INTO community_law_conformity_cases (community_id,parent_community_id,local_law_id,local_law_version_id,parent_law_id,parent_law_version_id,status,trigger_type,opened_by) VALUES (90020,90010,9504,99004,9501,99001,'review_required','manual_review',90100)");
    $no_conflict_case_id=(int)$db->insert_id;
    $no_conflict_id=community_law_conformity_add_conflict($db,$no_conflict_case_id,90200,'ماده ۲','ماده ۵','تعارض احتمالی برای آزمون رد تعارض','مستندات بررسی');
    change_case_check(is_int($no_conflict_id) && $no_conflict_id>0,'second conformity case accepts a separately documented possible conflict');
    change_case_check(community_law_conformity_review_conflict($db,$no_conflict_id,90201,90020,'no_conflict','بررسی متن نشان می‌دهد دو ماده موضوع و دامنهٔ متفاوتی دارند.'),'local community can independently reject the alleged conflict');
    $still_pending=$db->query("SELECT status FROM community_law_conformity_cases WHERE id=".$no_conflict_case_id)->fetch_assoc();
    change_case_check($still_pending && $still_pending['status']==='review_required','one no-conflict review alone cannot resolve the case');
    change_case_check(community_law_conformity_review_conflict($db,$no_conflict_id,90103,90010,'no_conflict','بررسی جامعهٔ مادر نیز نشان می‌دهد میان دو ماده تعارضی وجود ندارد.'),'parent community can independently reject the alleged conflict');
    $resolved_no_conflict=$db->query("SELECT f.finding,c.status,c.resolved_at FROM community_law_conformity_conflicts f JOIN community_law_conformity_cases c ON c.id=f.case_id WHERE f.id=".$no_conflict_id)->fetch_assoc();
    change_case_check($resolved_no_conflict && $resolved_no_conflict['finding']==='no_conflict' && $resolved_no_conflict['status']==='resolved' && $resolved_no_conflict['resolved_at']!==null,'two independent no-conflict reviews resolve and timestamp the conformity case');

    $db->query("INSERT INTO community_law_implementations (law_version_id,community_id,responsible_user_id,local_plan,status,started_at,target_date,progress_report) VALUES (99001,90010,90101,'برنامهٔ جامعهٔ مادر','in_progress',NOW(),DATE_ADD(CURDATE(),INTERVAL 5 DAY),'گزارش پیشرفت مادر'),(99001,90020,90102,'برنامهٔ زیرجامعه','completed',NOW(),DATE_ADD(CURDATE(),INTERVAL 1 DAY),'گزارش تکمیل زیرجامعه')");

    $case_id=community_law_open_change_case($db,9501,90200,90020,'suspend','خطر جدی برای حقوق اعضا','گزارش مستند',true);
    change_case_check(is_int($case_id) && $case_id>0,'a member of a scoped child community can submit an emergency suspension request');
    $case=$db->query("SELECT request_type,status,emergency_requested FROM community_law_change_cases WHERE id=".(int)$case_id)->fetch_assoc();
    change_case_check($case && $case['request_type']==='suspend' && $case['status']==='under_review' && (int)$case['emergency_requested']===1,'request enters independent review without changing law status');
    $reviewers=$db->query('SELECT reviewer_user_id FROM community_law_change_reviewers WHERE case_id='.(int)$case_id)->fetch_all(MYSQLI_ASSOC);
    change_case_check(count($reviewers)===5,'five independent reviewers are assigned');
    foreach ($reviewers as $reviewer) change_case_check(!in_array((int)$reviewer['reviewer_user_id'],[90100,90200],true),'proposer and requester are excluded from review');
    $notice_count=(int)$db->query('SELECT COUNT(*) AS n FROM community_law_community_notices WHERE case_id='.(int)$case_id)->fetch_assoc()['n'];
    change_case_check($notice_count===3,'all communities across the parent, direct child, and deep descendant law scope receive a pending-request notice');
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
    change_case_check(community_law_open_change_case($db,9501,90200,90020,'suspend','درخواست تعلیق تکراری')===false,'an already suspended law cannot receive a second suspension request');
    $implementation_states=$db->query("SELECT status FROM community_law_implementations WHERE law_version_id=99001 ORDER BY community_id")->fetch_all(MYSQLI_ASSOC);
    change_case_check(count($implementation_states)===2 && $implementation_states[0]['status']==='suspended' && $implementation_states[1]['status']==='suspended','all affected community implementation records enter suspended state');
    $snapshot_count=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_change_implementation_snapshots WHERE case_id=".(int)$case_id)->fetch_assoc()['n'];
    change_case_check($snapshot_count===2,'previous implementation plans, owners, progress and statuses are snapshotted before suspension');
    $applied=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_change_cases WHERE id=".(int)$case_id." AND effect_applied_at IS NOT NULL")->fetch_assoc()['n'];
    change_case_check($applied===1,'the applied legal effect is marked idempotently');

    // اجرای دوبارهٔ اثرِ تأییدشده نباید snapshot، تاریخچه یا اعلان تکراری بسازد.
    $before_replay_snapshots=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_change_implementation_snapshots WHERE case_id=".(int)$case_id)->fetch_assoc()['n'];
    $before_replay_notices=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_community_notices WHERE case_id=".(int)$case_id)->fetch_assoc()['n'];
    $before_replay_history=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_history WHERE law_id=9501 AND event_type='law_suspended'")->fetch_assoc()['n'];
    change_case_check(
        community_law_change_apply_approved_case($db,(int)$case_id,null),
        'replaying an already applied approved change returns safely'
    );
    $after_replay_snapshots=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_change_implementation_snapshots WHERE case_id=".(int)$case_id)->fetch_assoc()['n'];
    $after_replay_notices=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_community_notices WHERE case_id=".(int)$case_id)->fetch_assoc()['n'];
    $after_replay_history=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_history WHERE law_id=9501 AND event_type='law_suspended'")->fetch_assoc()['n'];
    change_case_check(
        $before_replay_snapshots===$after_replay_snapshots &&
        $before_replay_notices===$after_replay_notices &&
        $before_replay_history===$after_replay_history,
        'replaying a completed legal effect creates no duplicate snapshots, notices or history'
    );

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

    // خطای عمدی در اعلان بازگردانی باید کل نتیجه رأی، وضعیت قانون، اجرا و تاریخچه را rollback کند.
    $db->query("CREATE TRIGGER fail_reinstatement_notice BEFORE INSERT ON community_law_community_notices FOR EACH ROW BEGIN IF NEW.event_type='reinstated' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced reinstatement notice failure'; END IF; END");
    change_case_check(community_law_change_finalize_vote($db,$reinstate_case)===false,'a failed reinstatement notice aborts the parent vote transaction');
    $db->query('DROP TRIGGER fail_reinstatement_notice');
    $pending_reinstate=$db->query('SELECT status,vote_result,effect_applied_at FROM community_law_change_cases WHERE id='.(int)$reinstate_case)->fetch_assoc();
    $still_suspended=$db->query('SELECT status,suspended_at,suspension_case_id FROM community_laws WHERE id=9501')->fetch_assoc();
    $still_paused=$db->query("SELECT COUNT(*) AS n FROM community_law_implementations WHERE law_version_id=99001 AND status='suspended'")->fetch_assoc();
    $rollback_history=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_history WHERE law_id=9501 AND event_type='law_reinstated'")->fetch_assoc()['n'];
    $rollback_notices=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_community_notices WHERE case_id=".(int)$reinstate_case." AND event_type='reinstated'")->fetch_assoc()['n'];
    change_case_check($pending_reinstate && $pending_reinstate['status']==='awaiting_vote' && $pending_reinstate['vote_result']===null && $pending_reinstate['effect_applied_at']===null,'failed reinstatement leaves the parent vote pending and unapplied');
    change_case_check($still_suspended && $still_suspended['status']==='suspended' && $still_suspended['suspended_at']!==null && (int)$still_suspended['suspension_case_id']===(int)$case_id,'failed reinstatement preserves the exact prior suspension state');
    change_case_check((int)$still_paused['n']===2 && $rollback_history===0 && $rollback_notices===0,'failed reinstatement rolls back implementation restoration, history and notices together');

    change_case_check(community_law_change_finalize_vote($db,$reinstate_case)==='approved','parent vote successfully retries reinstatement after the notice failure is removed');
    $restored_law=$db->query('SELECT status,suspended_at,suspension_case_id FROM community_laws WHERE id=9501')->fetch_assoc();
    change_case_check($restored_law && $restored_law['status']==='approved' && $restored_law['suspended_at']===null && $restored_law['suspension_case_id']===null,'approved reinstatement restores the law to active status');
    $db->query("UPDATE community_laws SET expires_at=DATE_ADD(NOW(),INTERVAL 7 DAY) WHERE id=9501");
    change_case_check(community_law_open_change_case($db,9501,90200,90020,'reinstate','بازگردانی نزدیک به پایان اعتبار')===false,'reinstatement is not opened when the law is within the eight-day expiry safety window');
    $db->query("UPDATE community_laws SET expires_at=NULL WHERE id=9501");
    $restored=$db->query('SELECT community_id,responsible_user_id,local_plan,status,progress_report,started_at,target_date,completed_at FROM community_law_implementations WHERE law_version_id=99001 ORDER BY community_id')->fetch_all(MYSQLI_ASSOC);
    change_case_check(count($restored)===2 && $restored[0]['status']==='in_progress' && $restored[0]['responsible_user_id']==='90101' && $restored[0]['local_plan']==='برنامهٔ جامعهٔ مادر' && $restored[0]['progress_report']==='گزارش پیشرفت مادر' && $restored[0]['started_at']!==null && $restored[0]['target_date']!==null && $restored[0]['completed_at']===null && $restored[1]['status']==='completed' && $restored[1]['responsible_user_id']==='90102' && $restored[1]['local_plan']==='برنامهٔ زیرجامعه' && $restored[1]['progress_report']==='گزارش تکمیل زیرجامعه' && $restored[1]['started_at']!==null && $restored[1]['target_date']!==null,'all implementation owners, plans, statuses, dates and progress reports are restored from suspension snapshots');
    $reinstated_notices=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_community_notices WHERE case_id=".(int)$reinstate_case." AND event_type='reinstated'")->fetch_assoc()['n'];
    $reinstated_history=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_history WHERE law_id=9501 AND event_type='law_reinstated'")->fetch_assoc()['n'];
    change_case_check($reinstated_notices===3 && $reinstated_history===1,'successful reinstatement notifies every scoped community and records one permanent history event');
    $before_reinstate_replay_notices=$reinstated_notices;
    $before_reinstate_replay_history=$reinstated_history;
    change_case_check(community_law_change_apply_approved_case($db,(int)$reinstate_case,null),'replaying an applied reinstatement is safe');
    $after_reinstate_replay_notices=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_community_notices WHERE case_id=".(int)$reinstate_case." AND event_type='reinstated'")->fetch_assoc()['n'];
    $after_reinstate_replay_history=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_history WHERE law_id=9501 AND event_type='law_reinstated'")->fetch_assoc()['n'];
    change_case_check($before_reinstate_replay_notices===$after_reinstate_replay_notices && $before_reinstate_replay_history===$after_reinstate_replay_history,'replaying reinstatement creates no duplicate notices or history');

    // لغو قانون باید همهٔ جوامع مشمول را مطلع کند و وضعیت اجرا را بدون حذف شواهد متوقف سازد.
    $repeal_case=community_law_open_change_case($db,9501,90200,90020,'repeal','لغو قانون پس از رفع نیاز و بررسی پیامدهای آن','گزارش ارزیابی پیامدها');
    change_case_check(is_int($repeal_case) && $repeal_case>0,'an affected child member can request repeal for parent-community review');
    $repeal_reviewers=$db->query('SELECT reviewer_user_id FROM community_law_change_reviewers WHERE case_id='.(int)$repeal_case)->fetch_all(MYSQLI_ASSOC);
    change_case_check(count($repeal_reviewers)===5,'repeal receives five independent reviewers');
    foreach ($repeal_reviewers as $index=>$reviewer) {
        $verdict=$index<4?'uphold':'dismiss';
        change_case_check(community_law_submit_change_assessment($db,$repeal_case,(int)$reviewer['reviewer_user_id'],$verdict,'بررسی مستقل آثار لغو انجام شد.')!==false,'independent repeal assessment is accepted');
    }
    $repeal_electorate=$db->query('SELECT user_id FROM community_law_change_electorate WHERE case_id='.(int)$repeal_case.' ORDER BY user_id')->fetch_all(MYSQLI_ASSOC);
    change_case_check(count($repeal_electorate)===4,'repeal voting uses the parent-community electorate');
    foreach (array_slice($repeal_electorate,0,3) as $voter) {
        change_case_check(community_law_change_cast_vote($db,$repeal_case,(int)$voter['user_id'],'yes'),'parent voter can approve repeal');
    }
    $db->query('UPDATE community_law_change_cases SET vote_closes_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id='.(int)$repeal_case);
    // شکست اعلان لغو باید کل رأی نهایی و اثر حقوقی را rollback کند؛ سپس همان رأی قابل تکرار باشد.
    $db->query("CREATE TRIGGER test_fail_repeal_notice BEFORE INSERT ON community_law_community_notices
        FOR EACH ROW BEGIN
            IF NEW.case_id=".$repeal_case." AND NEW.event_type='repealed' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced repeal notice failure';
            END IF;
        END");
    change_case_check(community_law_change_finalize_vote($db,$repeal_case)===false,'a failed repeal notice aborts the parent vote finalization');
    $db->query("DROP TRIGGER test_fail_repeal_notice");
    $repeal_rollback=$db->query("SELECT c.status,c.vote_result,c.decided_at,c.effect_applied_at,
        l.status AS law_status,l.repealed_at,
        (SELECT COUNT(*) FROM community_law_change_implementation_snapshots s WHERE s.case_id=".$repeal_case.") AS snapshots,
        (SELECT COUNT(*) FROM community_law_community_notices n WHERE n.case_id=".$repeal_case." AND n.event_type='repealed') AS notices,
        (SELECT COUNT(*) FROM community_law_history h WHERE h.law_id=9501 AND h.event_type='law_repealed') AS repeal_history
        FROM community_law_change_cases c JOIN community_laws l ON l.id=c.law_id
        WHERE c.id=".$repeal_case)->fetch_assoc();
    $repeal_rollback_impl=$db->query("SELECT community_id,status,progress_report FROM community_law_implementations WHERE law_version_id=99001 ORDER BY community_id")->fetch_all(MYSQLI_ASSOC);
    change_case_check($repeal_rollback && $repeal_rollback['status']==='awaiting_vote' && $repeal_rollback['vote_result']===null && $repeal_rollback['decided_at']===null && $repeal_rollback['effect_applied_at']===null,'failed repeal notification restores the pending vote without a partial decision');
    change_case_check($repeal_rollback && $repeal_rollback['law_status']==='approved' && $repeal_rollback['repealed_at']===null && (int)$repeal_rollback['snapshots']===0 && (int)$repeal_rollback['notices']===0 && (int)$repeal_rollback['repeal_history']===0,'failed repeal notification rolls back law status, snapshots, notices and permanent history');
    change_case_check(count($repeal_rollback_impl)===2 && $repeal_rollback_impl[0]['status']==='in_progress' && $repeal_rollback_impl[1]['status']==='completed' && $repeal_rollback_impl[0]['progress_report']==='گزارش پیشرفت مادر' && $repeal_rollback_impl[1]['progress_report']==='گزارش تکمیل زیرجامعه','failed repeal preserves all live implementation states and progress evidence');
    change_case_check(community_law_change_finalize_vote($db,$repeal_case)==='approved','parent vote successfully retries repeal after the notice failure is removed');
    $repealed=$db->query('SELECT status,repealed_at,last_status_change_case_id FROM community_laws WHERE id=9501')->fetch_assoc();
    change_case_check($repealed && $repealed['status']==='repealed' && $repealed['repealed_at']!==null && (int)$repealed['last_status_change_case_id']===$repeal_case,'repeal is recorded as the authoritative law status transition');
    $repealed_impl=$db->query('SELECT community_id,status,progress_report FROM community_law_implementations WHERE law_version_id=99001 ORDER BY community_id')->fetch_all(MYSQLI_ASSOC);
    change_case_check(count($repealed_impl)===2 && $repealed_impl[0]['status']==='not_applicable' && $repealed_impl[1]['status']==='not_applicable' && $repealed_impl[0]['progress_report']==='گزارش پیشرفت مادر' && $repealed_impl[1]['progress_report']==='گزارش تکمیل زیرجامعه','repeal ends obligations across the scoped implementations while retaining prior progress reports');
    $repeal_notice_count=(int)$db->query('SELECT COUNT(*) AS n FROM community_law_community_notices WHERE case_id='.(int)$repeal_case." AND event_type='repealed'")->fetch_assoc()['n'];
    change_case_check($repeal_notice_count===3,'parent, direct child and deep descendant receive repeal notices');
    $repeal_snapshot_count=(int)$db->query('SELECT COUNT(*) AS n FROM community_law_change_implementation_snapshots WHERE case_id='.(int)$repeal_case)->fetch_assoc()['n'];
    change_case_check($repeal_snapshot_count===2,'repeal preserves a complete pre-repeal implementation snapshot');
    $repeal_history_before=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_history WHERE law_id=9501 AND event_type='law_repealed'")->fetch_assoc()['n'];
    change_case_check($repeal_history_before===1,'repeal is written to permanent law history');
    change_case_check(community_law_change_apply_approved_case($db,(int)$repeal_case,null),'replaying an applied repeal returns safely');
    $replay_repeal_notices=(int)$db->query('SELECT COUNT(*) AS n FROM community_law_community_notices WHERE case_id='.(int)$repeal_case." AND event_type='repealed'")->fetch_assoc()['n'];
    $replay_repeal_history=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_history WHERE law_id=9501 AND event_type='law_repealed'")->fetch_assoc()['n'];
    change_case_check($replay_repeal_notices===$repeal_notice_count && $replay_repeal_history===$repeal_history_before,'replaying repeal creates no duplicate notices or history');

    change_case_check(community_law_open_change_case($db,9501,999999,90020,'repeal','درخواست نامعتبر')===false,'non-member cannot submit a change request');
    change_case_check(community_law_open_change_case($db,9501,90200,90020,'repeal','')===false,'empty reasons are rejected');
    change_case_check(community_law_open_change_case($db,9501,90200,90020,'repeal','درخواست اضطراری',null,true)===false,'emergency flag is restricted to suspension requests');
    $history=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_history WHERE law_id=9501 AND event_type IN ('change_case_submitted','change_case_independent_review_completed','change_case_parent_vote_opened','change_case_parent_vote_finalized')")->fetch_assoc()['n'];
    change_case_check($history>=4,'request, independent review, vote opening and parent vote are recorded in permanent law history (observed '.$history.')');
    // تغییر دامنهٔ قانون باید برای جامعهٔ متأثر اعلان بسازد و سوابق اجرا را حفظ کند.
    $db->query("INSERT INTO communities (id,parent_community_id) VALUES (91010,NULL),(91020,91010),(91030,91020)");
    $db->query("INSERT INTO community_laws (id,law_code,originating_community_id,created_by,status,current_version_id,effective_at) VALUES (96001,'LAW-SCOPE-NOTICE-TEST',91020,90200,'approved',99601,NOW())");
    $db->query("INSERT INTO community_law_versions (id,law_id,version_number,title,law_text,proposed_by,status,approved_at) VALUES (99601,96001,1,'قانون آزمایش اعلان دامنه','متن قانون',90200,'approved',NOW())");
    $db->query("INSERT INTO community_law_scopes (law_version_id,scope_community_id,include_descendants) VALUES (99601,91020,1)");
    $db->query("INSERT INTO community_law_implementations (law_version_id,community_id,responsible_user_id,local_plan,status,started_at,target_date,progress_report) VALUES (99601,91030,90200,'برنامهٔ جامعهٔ متأثر','in_progress',NOW(),DATE_ADD(CURDATE(),INTERVAL 20 DAY),'گزارش پیشرفت حفظ‌شونده')");
    // Use the complete database hierarchy: scope validation must see every
    // community referenced by active and pending law scopes, not a partial fixture.
    $old_scope_map=community_law_load_parent_map($db);
    change_case_check(is_array($old_scope_map) && ($old_scope_map[91030]??null)===91020,'scope transition fixture loads the complete hierarchy');
    $new_scope_map=$old_scope_map;
    $new_scope_map[91030]=91010;
    $scope_debug=$db->query("SELECT l.id,l.status,l.current_version_id,v.status AS version_status FROM community_laws l JOIN community_law_versions v ON v.id=l.current_version_id ORDER BY l.id")->fetch_all(MYSQLI_ASSOC);
    $pending_debug=community_law_hierarchy_change_has_pending_review_dependency($db,$old_scope_map,$new_scope_map);
    $scope_debug_ids=[];
    foreach ($scope_debug as $debug_law) {
        $scope_debug_ids[]=[
            'law_id'=>(int)$debug_law['id'],
            'law_status'=>$debug_law['status'],
            'version_status'=>$debug_law['version_status'],
            'scope_ids'=>community_law_scope_ids_for_parent_map($db,(int)$debug_law['current_version_id'],$old_scope_map)
        ];
    }
    change_case_check(
        community_law_record_hierarchy_scope_changes($db,$old_scope_map,$new_scope_map,91030,90200),
        'hierarchy scope exit is recorded and notified'
    );
    $scope_left=$db->query("SELECT transition,notice_text FROM community_law_scope_notices WHERE law_id=96001 AND community_id=91030 ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $left_impl=$db->query("SELECT status,responsible_user_id,local_plan,progress_report FROM community_law_implementations WHERE law_version_id=99601 AND community_id=91030")->fetch_assoc();
    change_case_check($scope_left && $scope_left['transition']==='scope_left' && $left_impl && $left_impl['status']==='not_applicable' && $left_impl['progress_report']==='گزارش پیشرفت حفظ‌شونده','leaving the law scope creates a notice and preserves implementation evidence');
    change_case_check(
        community_law_record_hierarchy_scope_changes($db,$new_scope_map,$old_scope_map,91030,90200),
        'hierarchy scope re-entry is recorded and notified'
    );
    $scope_entered=$db->query("SELECT transition FROM community_law_scope_notices WHERE law_id=96001 AND community_id=91030 ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $reentered_impl=$db->query("SELECT status,responsible_user_id,local_plan,progress_report FROM community_law_implementations WHERE law_version_id=99601 AND community_id=91030")->fetch_assoc();
    change_case_check($scope_entered && $scope_entered['transition']==='scope_entered' && $reentered_impl && $reentered_impl['status']==='pending' && $reentered_impl['responsible_user_id']==='90200' && $reentered_impl['local_plan']==='برنامهٔ جامعهٔ متأثر' && $reentered_impl['progress_report']==='گزارش پیشرفت حفظ‌شونده','re-entering scope restores a pending obligation without deleting prior owner, plan or progress');
    $scope_history=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_history WHERE law_id=96001 AND event_type IN ('scope_left','scope_entered')")->fetch_assoc()['n'];
    change_case_check($scope_history===2,'scope entry and exit both remain in permanent law history');
    $scope_unread=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_scope_notices WHERE law_id=96001 AND community_id=91030 AND read_at IS NULL")->fetch_assoc()['n'];
    change_case_check($scope_unread===2,'each scope transition creates a separate unread community notice');

    // اعلان‌ها فقط توسط عضو همان جامعه و از مسیر جدول منبع صحیح خوانده می‌شوند.
    $db->query("INSERT INTO community_members (community_id,user_id) VALUES (91030,90300) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)");
    $scope_notice_id=(int)$db->query("SELECT id FROM community_law_scope_notices WHERE law_id=96001 AND community_id=91030 AND read_at IS NULL ORDER BY id LIMIT 1")->fetch_assoc()['id'];
    change_case_check(
        !community_law_change_mark_notice_read($db,91030,90301,$scope_notice_id,'scope'),
        'a non-member cannot mark a scope notice as read'
    );
    change_case_check(
        !community_law_change_mark_notice_read($db,91020,90300,$scope_notice_id,'scope'),
        'a member of another community cannot mark this community notice as read'
    );
    change_case_check(
        !community_law_change_mark_notice_read($db,91030,90300,$scope_notice_id,'unknown'),
        'an unknown notice source is rejected instead of falling back to change notices'
    );
    change_case_check(
        community_law_change_mark_notice_read($db,91030,90300,$scope_notice_id,'scope'),
        'a member can mark their own community scope notice as read'
    );
    $scope_read=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_scope_notices WHERE id=".$scope_notice_id." AND read_at IS NOT NULL")->fetch_assoc()['n'];
    change_case_check($scope_read===1,'scope notice read status is updated in the scope-notice table');

    // اعلان فعال‌شدن نسخهٔ جدید قانون نیز باید فقط برای عضو همان جامعه قابل خواندن باشد.
    $db->query("INSERT INTO community_law_version_notices
        (law_id,law_version_id,previous_version_id,community_id,actor_user_id,event_type,notice_text)
        VALUES (96001,99601,NULL,91030,90200,'version_activated','نسخهٔ جدید قانون فعال شد')");
    $version_notice_id=(int)$db->insert_id;
    change_case_check($version_notice_id>0,'an enacted law version can create a dedicated community notice');
    change_case_check(
        !community_law_change_mark_notice_read($db,91030,90301,$version_notice_id,'version'),
        'a non-member cannot mark a law version notice as read'
    );
    change_case_check(
        !community_law_change_mark_notice_read($db,91020,90300,$version_notice_id,'version'),
        'a member of another community cannot mark a law version notice as read'
    );
    change_case_check(
        !community_law_change_mark_notice_read($db,91030,90300,$version_notice_id,'unknown'),
        'an unknown source cannot route to the law version notice table'
    );
    change_case_check(
        community_law_change_mark_notice_read($db,91030,90300,$version_notice_id,'version'),
        'a member can mark their own law version notice as read'
    );
    $version_notice_read=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_version_notices WHERE id=".$version_notice_id." AND read_at IS NOT NULL")->fetch_assoc()['n'];
    change_case_check($version_notice_read===1,'law version notice read status is updated only in its dedicated table');

    $change_notice=$db->query("SELECT id,community_id FROM community_law_community_notices WHERE case_id=".(int)$case_id." ORDER BY id LIMIT 1")->fetch_assoc();
    change_case_check($change_notice!==null,'the change workflow has a notice fixture for source-routing checks');
    change_case_check(
        !community_law_change_mark_notice_read($db,(int)$change_notice['community_id'],999999,(int)$change_notice['id'],'change'),
        'a non-member cannot mark a change notice as read'
    );
    $change_member=(int)$db->query("SELECT user_id FROM community_members WHERE community_id=".(int)$change_notice['community_id']." ORDER BY user_id LIMIT 1")->fetch_assoc()['user_id'];
    change_case_check(
        community_law_change_mark_notice_read($db,(int)$change_notice['community_id'],$change_member,(int)$change_notice['id'],'change'),
        'a member can mark a change notice as read through the change-notice table'
    );
    $change_read=(int)$db->query("SELECT COUNT(*) AS n FROM community_law_community_notices WHERE id=".(int)$change_notice['id']." AND read_at IS NOT NULL")->fetch_assoc()['n'];
    change_case_check($change_read===1,'change notice read status is updated in the change-notice table');

    fwrite(STDOUT,"OK: community law change-control integration checks passed.\n");
} finally { $db->close(); }
?>
