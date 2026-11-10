<?php
session_start();
require __DIR__ . '/db.php';
require_once __DIR__ . '/community_law_change_helpers.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('ابتدا وارد سامانه شوید.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('درخواست نامعتبر است.');
}
$user_id=(int)$_SESSION['user_id'];
$community_id=(int)($_POST['community_id']??0);
$csrf=$_POST['csrf_token']??'';
if ($community_id<=0 || empty($_SESSION['community_law_change_csrf']) ||
    !is_string($csrf) || $csrf==='' ||
    !hash_equals($_SESSION['community_law_change_csrf'],$csrf)) {
    http_response_code(403);
    exit('نشست یا جامعه معتبر نیست؛ صفحه را تازه‌سازی کنید.');
}
function clca_redirect(int $community_id,string $status): void {
    header('Location: community_law_change_cases.php?community_id='.$community_id.'&status='.rawurlencode($status));
    exit;
}
if (!community_law_tables_ready($conn) || !community_law_change_tables_ready($conn)) clca_redirect($community_id,'schema');

$action=(string)($_POST['action']??'');
try {
    if ($action==='open_case') {
        $request_type=(string)($_POST['request_type']??'');
        $reason=trim((string)($_POST['reason']??''));
        $evidence=trim((string)($_POST['evidence']??''));
        $emergency=isset($_POST['emergency_requested']) && (string)$_POST['emergency_requested']==='1';
        $date_raw=trim((string)($_POST['requested_effective_at']??''));
        $requested_date=null;
        if ($date_raw!=='') {
            $date=DateTime::createFromFormat('Y-m-d\TH:i',$date_raw);
            if (!$date || $date->format('Y-m-d\TH:i')!==$date_raw) clca_redirect($community_id,'invalid_date');
            $requested_date=$date->format('Y-m-d H:i:s');
        }
        $case_id=community_law_open_change_case(
            $conn,(int)($_POST['law_id']??0),$user_id,$community_id,
            $request_type,$reason,$evidence!==''?$evidence:null,$emergency,$requested_date
        );
        clca_redirect($community_id,$case_id===false?'request_failed':'request_submitted');
    }

    if ($action==='assess_case') {
        $result=community_law_submit_change_assessment(
            $conn,(int)($_POST['case_id']??0),$user_id,
            (string)($_POST['verdict']??''),trim((string)($_POST['reasoning']??'')),
            trim((string)($_POST['evidence']??''))?:null
        );
        clca_redirect($community_id,$result===false?'assessment_failed':'assessment_saved');
    }

    if ($action==='cast_vote') {
        $ok=community_law_change_cast_vote(
            $conn,(int)($_POST['case_id']??0),$user_id,
            (string)($_POST['vote']??''),trim((string)($_POST['comment']??''))?:null
        );
        clca_redirect($community_id,$ok?'vote_saved':'vote_failed');
    }

    if ($action==='finalize_vote') {
        $case_id=(int)($_POST['case_id']??0);
        $authorized=$conn->prepare(
            "SELECT 1
             FROM community_law_change_cases c
             INNER JOIN community_laws l ON l.id=c.law_id
             INNER JOIN community_members cm
               ON cm.community_id=l.originating_community_id AND cm.user_id=?
             WHERE c.id=? AND l.originating_community_id=?
             LIMIT 1"
        );
        if (!$authorized) clca_redirect($community_id,'finalize_failed');
        $authorized->bind_param('iii',$user_id,$case_id,$community_id);
        $can_finalize=$authorized->execute() && (bool)$authorized->get_result()->fetch_row();
        $authorized->close();
        if (!$can_finalize) clca_redirect($community_id,'finalize_failed');
        $result=community_law_change_finalize_vote($conn,$case_id);
        $status=$result==='approved'?'vote_approved':($result==='rejected'?'vote_rejected':($result==='insufficient_turnout'?'vote_insufficient':'finalize_failed'));
        clca_redirect($community_id,$status);
    }

    if ($action==='mark_notice_read') {
        $notice_id=(int)($_POST['notice_id']??0);
        $notice_source=(string)($_POST['notice_source']??'');
        $ok=community_law_change_mark_notice_read(
            $conn,$community_id,$user_id,$notice_id,$notice_source
        );
        clca_redirect($community_id,$ok?'notice_read':'notice_failed');
    }

    clca_redirect($community_id,'invalid_action');
} catch (Throwable $e) {
    error_log('[Dolate Eshgh law change action] '.$e->getMessage());
    clca_redirect($community_id,'save_error');
}
?>
