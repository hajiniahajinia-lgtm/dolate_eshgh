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
try {
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
    clor_redirect($community_id,$report_id===false?'outcome_report_failed':'outcome_report_saved');
} catch (Throwable $e) {
    error_log('[Dolate Eshgh law outcome report] '.$e->getMessage());
    clor_redirect($community_id,'outcome_report_failed');
}
?>
