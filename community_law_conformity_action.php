<?php
session_start();
require __DIR__ . '/db.php';
require_once __DIR__ . '/community_law_conformity_helpers.php';

if (!isset($_SESSION['user_id'])) { http_response_code(401); exit('ابتدا وارد سامانه شوید.'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('درخواست نامعتبر است.'); }
$user_id=(int)$_SESSION['user_id'];
$community_id=(int)($_POST['community_id']??0);
$csrf=$_POST['csrf_token']??'';
if ($community_id<=0 || empty($_SESSION['community_law_conformity_csrf']) ||
    !is_string($csrf) || $csrf==='' || !hash_equals($_SESSION['community_law_conformity_csrf'],$csrf)) {
    http_response_code(403); exit('نشست یا جامعه معتبر نیست؛ صفحه را تازه‌سازی کنید.');
}
function clca_conformity_redirect(int $community_id,string $status): void {
    header('Location: community_law_conformity_cases.php?community_id='.$community_id.'&status='.rawurlencode($status));
    exit;
}
if (!community_law_conformity_tables_ready($conn)) clca_conformity_redirect($community_id,'schema');
if (!community_law_conformity_is_member($conn,$community_id,$user_id)) clca_conformity_redirect($community_id,'not_member');

$action=(string)($_POST['action']??'');
if ($action==='add_conflict') {
    $result=community_law_conformity_add_conflict(
        $conn,(int)($_POST['case_id']??0),$user_id,
        (string)($_POST['local_article_reference']??''),
        (string)($_POST['parent_article_reference']??''),
        (string)($_POST['conflict_description']??''),
        trim((string)($_POST['evidence']??''))?:null
    );
    clca_conformity_redirect($community_id,$result===false?'conflict_failed':'conflict_added');
}
if ($action==='review_conflict') {
    $reviewer_community_id=(int)($_POST['reviewer_community_id']??0);
    if ($reviewer_community_id!==$community_id) clca_conformity_redirect($community_id,'invalid_action');
    $result=community_law_conformity_review_conflict(
        $conn,(int)($_POST['conflict_id']??0),$user_id,$reviewer_community_id,
        (string)($_POST['finding']??''),trim((string)($_POST['reasoning']??'')),
        trim((string)($_POST['evidence']??''))?:null
    );
    clca_conformity_redirect($community_id,$result?'review_saved':'review_failed');
}
clca_conformity_redirect($community_id,'invalid_action');
?>