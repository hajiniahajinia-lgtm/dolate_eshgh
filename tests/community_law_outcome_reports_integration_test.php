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
    outcome_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_decision_origin.sql');
    outcome_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_outcome_reports.sql');
    outcome_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_outcome_reviews.sql');
    outcome_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_outcome_followups.sql');
    outcome_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_outcome_reassessments.sql');
    outcome_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_outcome_corrective_actions.sql');
    outcome_apply_sql($db,__DIR__.'/../migrations/20261109_community_law_corrective_completion_reviews.sql');
    $db->query("CREATE TEMPORARY TABLE community_decision_records (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        community_id INT NOT NULL,
        source_topic_id INT NOT NULL UNIQUE,
        knowledge_entry_id BIGINT UNSIGNED NULL,
        acceptance_status ENUM('accepted','not_accepted','no_votes') NOT NULL DEFAULT 'no_votes',
        enforceability_status ENUM('enforceable','not_enforceable','undecided') NOT NULL DEFAULT 'undecided',
        record_status ENUM('recorded','superseded','voided') NOT NULL DEFAULT 'recorded',
        decided_at DATETIME NOT NULL
    ) ENGINE=InnoDB");
    $db->query("CREATE TEMPORARY TABLE collective_knowledge_entries (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        version_no INT UNSIGNED NOT NULL DEFAULT 1,
        title VARCHAR(180) NOT NULL,
        entry_type VARCHAR(30) NOT NULL,
        summary VARCHAR(1000) NOT NULL,
        content MEDIUMTEXT NOT NULL,
        evidence TEXT NULL,
        visibility VARCHAR(20) NOT NULL DEFAULT 'community',
        community_id INT NULL,
        source_topic_id INT NULL,
        source_idea_id INT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'unreviewed',
        created_by INT NOT NULL
    ) ENGINE=InnoDB");
    $db->query("CREATE TEMPORARY TABLE community_members (community_id INT NOT NULL,user_id INT NOT NULL,PRIMARY KEY(community_id,user_id)) ENGINE=InnoDB");
    $db->query("INSERT INTO community_members VALUES (920,9700),(920,9701),(920,9702),(920,9703),(920,9704),(920,9706),(920,9707),(920,9708)");
    $db->query("INSERT INTO collective_knowledge_entries
                (title,entry_type,summary,content,visibility,community_id,source_topic_id,created_by)
                VALUES ('دانش منشأ آزمایشی','lesson','خلاصهٔ دانش منشأ','متن دانش منشأ','community',920,990099,9700)");
    $source_knowledge_entry_id=(int)$db->insert_id;
    $db->query("INSERT INTO community_decision_records
                (community_id,source_topic_id,acceptance_status,enforceability_status,decided_at)
                VALUES (920,990099,'accepted','enforceable',NOW())");
    $source_decision_id=(int)$db->insert_id;
    require_once __DIR__.'/../community_decision_ledger.php';
    outcome_check(community_decision_attach_knowledge_entry($db,990099,$source_knowledge_entry_id),
        'source decision can retain its separate knowledge reference');
    $db->query("INSERT INTO community_law_versions
                (id,law_id,version_number,title,law_text,source_decision_id,proposed_by,status)
                VALUES (990001,990000,1,'قانون آزمایشی','متن قانون',".(int)$source_decision_id.",9700,'approved')");
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
        $db,990000,990001,920,9705,'outcome','2026-01-01','2026-01-31','گزارش نامعتبر'
    )===false,'non-member cannot submit a report');
    outcome_check(community_law_submit_outcome_report(
        $db,990000,990001,920,9700,'progress','2026-02-01','2026-01-01','بازهٔ معکوس'
    )===false,'invalid reporting period is rejected');
    outcome_check(community_law_submit_outcome_report(
        $db,990000,990001,920,9700,'outcome','2099-01-01','2099-01-31','گزارش با بازهٔ آینده'
    )===false,'future-dated outcome reports are rejected');
    outcome_check(community_law_submit_outcome_report(
        $db,990000,990001,920,9700,'outcome','2026-01-01','2026-01-31','JSON نامعتبر',null,'{bad'
    )===false,'invalid metrics JSON is rejected');
    outcome_check(community_law_submit_outcome_report(
        $db,990000,999999,920,9700,'outcome','2026-01-01','2026-01-31','نسخهٔ نامرتبط'
    )===false,'report cannot be attached to an unrelated implementation');

    outcome_check(community_law_review_outcome_report(
        $db,$report_id,9700,'confirm','گزارش‌دهنده نمی‌تواند گزارش خود را ارزیابی کند'
    )===false,'report author cannot review own report');
    $db->query("UPDATE community_law_implementations SET responsible_user_id=9704 WHERE law_version_id=990001 AND community_id=920");
    outcome_check(community_law_review_outcome_report(
        $db,$report_id,9704,'confirm','مسئول اجرا نمی‌تواند گزارش اجرای خود را ارزیابی کند'
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

    // پروندهٔ دوم برای آزمون رسیدگی تکمیلی به گزارش مورد اختلاف.
    $db->begin_transaction();
    $disputed_report=community_law_submit_outcome_report(
        $db,990000,990001,920,9700,'outcome','2026-02-01','2026-02-28',
        'گزارش دوم برای آزمون رسیدگی تکمیلی','شواهد اولیه'
    );
    outcome_check(is_int($disputed_report) && $disputed_report>0,'second outcome report is created');
    $db->commit();
    foreach ([9701,9702,9703] as $reviewer_id) {
        $challenge=community_law_review_outcome_report(
            $db,$disputed_report,$reviewer_id,'challenge','شواهد برای تأیید گزارش کافی نیست'
        );
        outcome_check(is_array($challenge),'independent challenge review is accepted');
    }
    $disputed_status=$db->query("SELECT review_status FROM community_law_outcome_reports WHERE id=".(int)$disputed_report)->fetch_assoc();
    outcome_check($disputed_status && $disputed_status['review_status']==='disputed','three reviews with majority challenge mark report disputed');

    outcome_check(community_law_review_disputed_outcome_followups(
        $db,$disputed_report,9706,'confirm_report','نباید پیش از ثبت پاسخ و شاهد آغاز شود'
    )===false,'second-stage review is blocked until both required follow-ups exist');

    $db->begin_transaction();
    $response_id=community_law_submit_outcome_followup(
        $db,$disputed_report,9700,'executor_response',
        'پاسخ گزارش‌دهنده به ایرادهای مطرح‌شده','اسناد تکمیلی پاسخ'
    );
    outcome_check(is_int($response_id) && $response_id>0,'report author can submit an immutable response');
    $db->commit();

    outcome_check(community_law_review_disputed_outcome_followups(
        $db,$disputed_report,9706,'confirm_report','شواهد تکمیلی هنوز ثبت نشده است'
    )===false,'second-stage review requires supplemental evidence as well as executor response');

    $db->begin_transaction();
    $evidence_id=community_law_submit_outcome_followup(
        $db,$disputed_report,9701,'additional_evidence',
        'شاهد تکمیلی برای بررسی اختلاف','ارجاع به سند تازه'
    );
    outcome_check(is_int($evidence_id) && $evidence_id>0,'community member can add supplemental evidence');
    $db->commit();

    outcome_check(community_law_submit_outcome_followup(
        $db,$disputed_report,9705,'additional_evidence','عضو نامعتبر'
    )===false,'non-member cannot add follow-up evidence');
    outcome_check(community_law_submit_outcome_followup(
        $db,$disputed_report,9701,'executor_response','پاسخ غیرمجاز'
    )===false,'ordinary member cannot impersonate executor or report author');
    outcome_check(community_law_submit_outcome_followup(
        $db,$report_id,9701,'additional_evidence','گزارش تأییدشده مورد اختلاف نیست'
    )===false,'follow-up is rejected for a report that is not disputed');
    $still_disputed=$db->query("SELECT review_status FROM community_law_outcome_reports WHERE id=".(int)$disputed_report)->fetch_assoc();
    outcome_check($still_disputed && $still_disputed['review_status']==='disputed','follow-up does not alter report verdict');
    $followup_history=(int)$db->query("SELECT COUNT(*) total FROM community_law_history WHERE law_id=990000 AND event_type='outcome_report_followup_added'")->fetch_assoc()['total'];
    outcome_check($followup_history===2,'each follow-up entry is recorded in immutable law history');

    // رسیدگی دوم فقط با پاسخ مجری و شواهد تازه و ارزیابان کاملاً جدید آغاز می‌شود.
    outcome_check(community_law_review_disputed_outcome_followups(
        $db,$disputed_report,9701,'confirm_report','ارزیاب مرحله اول نباید دوباره ارزیابی کند'
    )===false,'original reviewer is excluded from second-stage review');
    outcome_check(community_law_review_disputed_outcome_followups(
        $db,$disputed_report,9700,'confirm_report','گزارش‌دهنده تعارض منافع دارد'
    )===false,'report author is excluded from second-stage review');
    outcome_check(community_law_review_disputed_outcome_followups(
        $db,$disputed_report,9704,'confirm_report','مسئول اجرا تعارض منافع دارد'
    )===false,'implementation owner is excluded from second-stage review');

    $reassess_one=community_law_review_disputed_outcome_followups(
        $db,$disputed_report,9706,'revise_execution_plan','برنامه اجرا باید اصلاح و زمان‌بندی شود'
    );
    outcome_check(is_array($reassess_one) && $reassess_one['review_count']===1 && $reassess_one['status']==='open',
        'first new independent reviewer records a pending reassessment');
    outcome_check(community_law_review_disputed_outcome_followups(
        $db,$disputed_report,9706,'confirm_report','تغییر رأی'
    )===false,'second-stage reviewer cannot vote twice');
    $reassess_two=community_law_review_disputed_outcome_followups(
        $db,$disputed_report,9707,'revise_execution_plan','شواهد تازه اصلاح برنامه اجرا را توجیه می‌کند'
    );
    outcome_check(is_array($reassess_two) && $reassess_two['review_count']===2 && $reassess_two['status']==='open',
        'two second-stage reviews do not finalize the case');
    $reassess_three=community_law_review_disputed_outcome_followups(
        $db,$disputed_report,9708,'recommend_law_review','احتمال نیاز به بازنگری قانون نیز باید بررسی شود'
    );
    outcome_check(is_array($reassess_three) && $reassess_three['review_count']===3 &&
        $reassess_three['status']==='finalized' && $reassess_three['final_outcome']==='revise_execution_plan',
        'two of three fresh reviewers determine the final reassessment outcome');
    outcome_check(community_law_review_disputed_outcome_followups(
        $db,$disputed_report,9706,'needs_more_review','پس از نهایی شدن'
    )===false,'finalized reassessment cannot accept further reviews');
    $case=$db->query("SELECT status,final_outcome FROM community_law_outcome_reassessments WHERE report_id=".(int)$disputed_report)->fetch_assoc();
    outcome_check($case && $case['status']==='finalized' && $case['final_outcome']==='revise_execution_plan',
        'final reassessment result is persisted separately from the original report');
    $unchanged_report=$db->query("SELECT review_status FROM community_law_outcome_reports WHERE id=".(int)$disputed_report)->fetch_assoc();
    outcome_check($unchanged_report && $unchanged_report['review_status']==='disputed',
        'second-stage outcome does not rewrite the original disputed report or suspend the law');
    $reassessment_history=(int)$db->query("SELECT COUNT(*) total FROM community_law_history WHERE law_id=990000 AND event_type='outcome_report_reassessed'")->fetch_assoc()['total'];
    outcome_check($reassessment_history===3,'each second-stage review is preserved in law history');


    // اقدام اصلاحی فقط پس از نتیجهٔ نهایی «اصلاح برنامهٔ اجرا» و با مسئول فعلی مجاز است.
    outcome_check(community_law_create_outcome_corrective_action(
        $db,$report_id,9701,'اقدام نامعتبر','نباید برای گزارش تأییدشده ایجاد شود',
        'معیار آزمون','2026-12-15'
    )===false,'corrective action cannot be created without a finalized revise-plan reassessment');
    $db->begin_transaction();
    $corrective_action_id=community_law_create_outcome_corrective_action(
        $db,$disputed_report,9701,'رفع مانع اجرایی',
        'بازبینی گام‌های اجرایی و رفع مانع شناسایی‌شده',
        'گزارش مستند رفع مانع و تکمیل گام‌های برنامه','2026-12-15'
    );
    outcome_check(is_int($corrective_action_id) && $corrective_action_id>0,
        'community member can create a corrective action after revise-plan outcome');
    $db->commit();
    outcome_check(community_law_create_outcome_corrective_action(
        $db,$disputed_report,9705,'اقدام عضو غیرمجاز','شرح اقدام','معیار موفقیت','2026-12-15'
    )===false,'non-member cannot create a corrective action');
    outcome_check(community_law_submit_outcome_corrective_update(
        $db,$corrective_action_id,9701,'in_progress','گزارش از سوی عضو عادی'
    )===false,'only assigned executor can report corrective action progress');

    $db->begin_transaction();
    $progress_id=community_law_submit_outcome_corrective_update(
        $db,$corrective_action_id,9704,'in_progress','بررسی مانع آغاز شد'
    );
    outcome_check(is_int($progress_id) && $progress_id>0,'assigned executor can report progress');
    $db->commit();
    $db->begin_transaction();
    $blocked_id=community_law_submit_outcome_corrective_update(
        $db,$corrective_action_id,9704,'blocked','برای تکمیل کار به دادهٔ زیرشاخه نیاز است'
    );
    outcome_check(is_int($blocked_id) && $blocked_id>0,'assigned executor can record a blocker');
    $db->commit();
    $db->begin_transaction();
    $completed_id=community_law_submit_outcome_corrective_update(
        $db,$corrective_action_id,9704,'completed','اقدام تکمیل شد و معیار موفقیت مستند است','سند رفع مانع'
    );
    outcome_check(is_int($completed_id) && $completed_id>0,'assigned executor can report completion with evidence');
    $db->commit();
    outcome_check(community_law_submit_outcome_corrective_update(
        $db,$corrective_action_id,9704,'in_progress','نباید پس از تکمیل دوباره باز شود'
    )===false,'completed corrective action cannot be reopened through progress updates');
    $corrective_updates=(int)$db->query(
        "SELECT COUNT(*) total FROM community_law_outcome_corrective_updates WHERE action_id=".(int)$corrective_action_id
    )->fetch_assoc()['total'];
    outcome_check($corrective_updates===3,'all corrective progress entries remain as immutable history');
    $claim_status=$db->query("SELECT status FROM community_law_outcome_corrective_updates WHERE id=".(int)$completed_id)->fetch_assoc();
    $action_status=$db->query("SELECT completion_status FROM community_law_outcome_corrective_actions WHERE id=".(int)$corrective_action_id)->fetch_assoc();
    outcome_check($claim_status && $claim_status['status']==='completion_claimed' &&
        $action_status && $action_status['completion_status']==='pending',
        'executor completion is only a claim pending independent verification');
    outcome_check(community_law_review_corrective_completion(
        $db,$corrective_action_id,9704,'confirm','مسئول اجرا نمی‌تواند تکمیل کار خود را تأیید کند'
    )===false,'assigned executor cannot verify own completion claim');
    outcome_check(community_law_review_corrective_completion(
        $db,$corrective_action_id,9701,'confirm','ایجادکننده اقدام تعارض منافع دارد'
    )===false,'corrective action creator cannot verify completion');
    $completion_one=community_law_review_corrective_completion(
        $db,$corrective_action_id,9706,'confirm','شواهد تکمیل اقدام را تأیید می‌کند'
    );
    outcome_check(is_array($completion_one) && $completion_one['review_count']===1 &&
        $completion_one['completion_status']==='pending','one review cannot finalize completion');
    $completion_two=community_law_review_corrective_completion(
        $db,$corrective_action_id,9707,'confirm','معیار موفقیت به‌طور مستقل احراز شد'
    );
    outcome_check(is_array($completion_two) && $completion_two['review_count']===2 &&
        $completion_two['completion_status']==='pending','two reviews do not bypass the three-review threshold');
    $completion_three=community_law_review_corrective_completion(
        $db,$corrective_action_id,9708,'reject','یک بخش از معیار موفقیت هنوز احراز نشده است'
    );
    outcome_check(is_array($completion_three) && $completion_three['review_count']===3 &&
        $completion_three['completion_status']==='verified',
        'two of three independent reviewers verify completion');
    outcome_check(community_law_submit_outcome_corrective_update(
        $db,$corrective_action_id,9704,'in_progress','پس از تأیید مستقل نباید تغییر کند'
    )===false,'verified corrective action cannot be reopened');
    $completion_history=(int)$db->query(
        "SELECT COUNT(*) total FROM community_law_history
         WHERE law_id=990000 AND event_type='outcome_corrective_completion_reviewed'"
    )->fetch_assoc()['total'];
    outcome_check($completion_history===3,'all independent completion reviews are preserved in law history');
    $corrective_history=(int)$db->query(
        "SELECT COUNT(*) total FROM community_law_history
         WHERE law_id=990000 AND event_type='outcome_corrective_action_updated'"
    )->fetch_assoc()['total'];
    outcome_check($corrective_history===3,'each corrective progress update is recorded in law history');

    // خروج جامعه از دامنه قانون، گزارش تازه و اقدام اصلاحی تازه را متوقف می‌کند؛
    // گزارش‌ها و اقدامات قبلی همچنان در تاریخچه باقی می‌مانند.
    $db->query("UPDATE community_law_implementations
                SET status='not_applicable'
                WHERE law_version_id=990001 AND community_id=920");
    outcome_check(community_law_submit_outcome_report(
        $db,990000,990001,920,9700,'progress','2026-03-01','2026-03-31',
        'گزارش پس از خروج از دامنه'
    )===false,'community outside the law scope cannot submit a new execution report');
    outcome_check(community_law_create_outcome_corrective_action(
        $db,$disputed_report,9701,'اقدام خارج از دامنه','نباید پس از خروج جامعه ایجاد شود',
        'معیار موفقیت','2026-12-20'
    )===false,'new corrective action cannot be created after implementation leaves the law scope');

    $provenance=$db->query(
        "SELECT v.source_decision_id,d.knowledge_entry_id,d.acceptance_status,d.record_status,
                ke.status AS knowledge_status,ke.version_no,ke.title
         FROM community_law_versions v
         JOIN community_decision_records d ON d.id=v.source_decision_id
         JOIN collective_knowledge_entries ke ON ke.id=d.knowledge_entry_id
         WHERE v.id=990001"
    )->fetch_assoc();
    outcome_check($provenance &&
        (int)$provenance['source_decision_id']===$source_decision_id &&
        (int)$provenance['knowledge_entry_id']===$source_knowledge_entry_id,
        'law remains traceably linked to its source decision and related knowledge entry');
    outcome_check($provenance && $provenance['acceptance_status']==='accepted' &&
        $provenance['record_status']==='recorded',
        'outcome and corrective workflows do not rewrite the source collective decision');
    outcome_check($provenance && $provenance['knowledge_status']==='unreviewed' &&
        (int)$provenance['version_no']===1 && $provenance['title']==='دانش منشأ آزمایشی',
        'execution evidence does not automatically promote or rewrite a knowledge entry');


    fwrite(STDOUT,"OK: community law outcome report integration checks passed.\n");
} finally { $db->close(); }
?>
