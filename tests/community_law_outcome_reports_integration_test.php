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
    outcome_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_outcome_followups.sql');
    outcome_apply_sql($db,__DIR__.'/../migrations/20261108_community_law_outcome_reassessments.sql');
    $db->query("CREATE TEMPORARY TABLE community_members (community_id INT NOT NULL,user_id INT NOT NULL,PRIMARY KEY(community_id,user_id)) ENGINE=InnoDB");
    $db->query("INSERT INTO community_members VALUES (920,9700),(920,9701),(920,9702),(920,9703),(920,9704),(920,9706),(920,9707),(920,9708)");
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
        $db,990000,990001,920,9705,'outcome','2026-01-01','2026-01-31','گزارش نامعتبر'
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

    fwrite(STDOUT,"OK: community law outcome report integration checks passed.\n");
} finally { $db->close(); }
?>
