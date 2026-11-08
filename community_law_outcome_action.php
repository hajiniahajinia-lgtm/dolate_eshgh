<?php
session_start();
require __DIR__.'/db.php';
require_once __DIR__.'/community_law_helpers.php';
if (!isset($_SESSION['user_id'])) { http_response_code(401); exit('ابتدا وارد سامانه شوید.'); }
if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); exit('درخواست نامعتبر است.'); }
$user_id=(int)$_SESSION['user_id'];
$community_id=(int)($_POST['community_id']??0);
$csrf=$_POST['csrf_token']??'';
if ($community_id<=0 || empty($_SESSION['community_law_csrf']) ||
    !is_string($csrf) || !hash_equals($_SESSION['community_law_csrf'],$csrf)) {
    http_response_code(403); exit('نشست یا جامعه معتبر نیست.');
}
function clor_redirect(int $community_id,string $status): void {
    header('Location: community_laws.php?community_id='.$community_id.'&status='.rawurlencode($status));
    exit;
}
if (!community_law_tables_ready($conn)) clor_redirect($community_id,'schema');
$outcome_schema_check=$conn->query(
    "SELECT COUNT(*) AS total FROM information_schema.tables
     WHERE table_schema=DATABASE()
       AND table_name IN ('community_law_outcome_reports','community_law_outcome_reviews','community_law_outcome_followups','community_law_outcome_reassessments','community_law_outcome_reassessment_reviews')"
);
$outcome_schema_ready=$outcome_schema_check && (int)$outcome_schema_check->fetch_assoc()['total']===5;
if ($outcome_schema_check) $outcome_schema_check->free();
if (!$outcome_schema_ready) clor_redirect($community_id,'schema');
try {
    if (!$conn->begin_transaction()) throw new RuntimeException('transaction start failed');
    if (($_POST['action']??'')==='followup') {
        $entry_type=(string)($_POST['entry_type']??'');
        $followup_id=community_law_submit_outcome_followup(
            $conn,
            (int)($_POST['report_id']??0),
            $user_id,
            $entry_type,
            trim((string)($_POST['body']??'')),
            trim((string)($_POST['followup_evidence']??''))?:null
        );
        if ($followup_id===false) { $conn->rollback(); clor_redirect($community_id,'outcome_followup_failed'); }
        if (!$conn->commit()) throw new RuntimeException('follow-up transaction commit failed');
        clor_redirect($community_id,'outcome_followup_saved');
    }
    if (($_POST['action']??'')==='reassess') {
        $reassessment_result=community_law_review_disputed_outcome_followups(
            $conn,
            (int)($_POST['report_id']??0),
            $user_id,
            (string)($_POST['reassessment_outcome']??''),
            trim((string)($_POST['reassessment_rationale']??'')),
            trim((string)($_POST['reassessment_evidence']??''))?:null
        );
        if ($reassessment_result===false) { $conn->rollback(); clor_redirect($community_id,'outcome_reassessment_failed'); }
        if (!$conn->commit()) throw new RuntimeException('reassessment transaction commit failed');
        clor_redirect($community_id,$reassessment_result['status']==='finalized'?'outcome_reassessment_finalized':'outcome_reassessment_saved');
    }
    if (($_POST['action']??'')==='review') {
        $review_result=community_law_review_outcome_report(
            $conn,
            (int)($_POST['report_id']??0),
            $user_id,
            (string)($_POST['verdict']??''),
            trim((string)($_POST['rationale']??'')),
            trim((string)($_POST['human_impact']??''))?:null,
            trim((string)($_POST['social_impact']??''))?:null,
            trim((string)($_POST['environmental_impact']??''))?:null,
            trim((string)($_POST['economic_impact']??''))?:null,
            trim((string)($_POST['review_evidence']??''))?:null
        );
        if ($review_result===false) { $conn->rollback(); clor_redirect($community_id,'outcome_review_failed'); }
        if (!$conn->commit()) throw new RuntimeException('review transaction commit failed');
        $review_status=$review_result['review_status'];
        clor_redirect($community_id,$review_status==='verified'?'outcome_review_verified':($review_status==='disputed'?'outcome_review_disputed':'outcome_review_saved'));
    }
    $report_id=community_law_submit_outcome_report(
        $conn,
        (int)($_POST['law_id']??0),
        (int)($_POST['law_version_id']??0),
        $community_id,
        $user_id,
        (string)($_POST['report_type']??''),
        (string)($_POST['period_start']??''),
        (string)($_POST['period_end']??''),
        trim((string)($_POST['summary']??'')),
        trim((string)($_POST['evidence']??''))?:null,
        trim((string)($_POST['metrics_json']??''))?:null
    );
    if ($report_id===false) { $conn->rollback(); clor_redirect($community_id,'outcome_report_failed'); }
    if (!$conn->commit()) throw new RuntimeException('transaction commit failed');
    clor_redirect($community_id,'outcome_report_saved');
} catch (Throwable $e) {
    $conn->rollback();
    error_log('[Dolate Eshgh law outcome report] '.$e->getMessage());
    clor_redirect($community_id,'outcome_report_failed');
}
?>
