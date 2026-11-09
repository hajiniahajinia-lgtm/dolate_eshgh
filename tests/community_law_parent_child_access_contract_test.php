<?php
declare(strict_types=1);

$page = file_get_contents(__DIR__ . '/../community_laws.php');
$helpers = file_get_contents(__DIR__ . '/../community_law_helpers.php');
$endpoint = file_get_contents(__DIR__ . '/../community_law_outcome_action.php');
if (!is_string($page) || !is_string($helpers) || !is_string($endpoint)) {
    fwrite(STDERR, "FAIL: unable to read community law access sources\n");
    exit(1);
}

function access_function(string $source, string $name): ?string {
    $start = strpos($source, 'function ' . $name . '(');
    if ($start === false) return null;
    $next = strpos($source, "\nif (!function_exists(", $start + 10);
    return substr($source, $start, $next === false ? null : $next - $start);
}

$checks = [];
$checks['law page requires authentication and resolves exact community membership'] =
    strpos($page, 'if (!isset($_SESSION[\\'user_id\\']))') !== false &&
    strpos($page, 'community_members WHERE community_id=? AND user_id=? LIMIT 1') !== false;
$checks['law history is fetched and rendered only for exact community members'] =
    strpos($page, 'if ($schema_ready && $is_member)') !== false &&
    strpos($page, '<?php if (!$is_member): ?>') !== false;
$checks['inherited review and appeal records are filtered through the law version scope'] =
    substr_count($page, 'community_law_scope_matches($conn, (int)$row[\\'version_id\\'], $community_id)') >= 1 &&
    strpos($page, 'community_law_scope_matches($conn, (int)$appeal_row[\\'law_version_id\\'], $community_id)') !== false;
$checks['parent members see all implementation reports only for laws originating in their own community'] =
    strpos($page, '$show_all_implementations=$is_member &&') !== false &&
    strpos($page, '(int)$law[\\'originating_community_id\\']===$community_id') !== false;
$checks['other community implementation and outcome reports remain limited to the selected community'] =
    strpos($page, 'if (!$show_all_implementations) $implementation_sql.=" AND i.community_id=?"') !== false &&
    strpos($page, 'if (!$show_all_implementations) $reports_sql.=" AND r.community_id=?"') !== false;

$functions = [
    'community_law_submit_outcome_report',
    'community_law_review_outcome_report',
    'community_law_submit_outcome_followup',
    'community_law_review_disputed_outcome_followups',
    'community_law_create_outcome_corrective_action',
    'community_law_submit_outcome_corrective_update',
    'community_law_review_corrective_completion'
];
$parts = [];
foreach ($functions as $name) {
    $parts[$name] = access_function($helpers, $name);
    $checks[$name . ' exists and enforces resource-community membership'] =
        is_string($parts[$name]) &&
        strpos($parts[$name], 'FROM community_members') !== false;
}
$checks['report reviewers are distinct from submitter and assigned executor'] =
    strpos($parts['community_law_review_outcome_report'] ?? '', 'submitted_by']===$reviewer_user_id') !== false &&
    strpos($parts['community_law_review_outcome_report'] ?? '', 'responsible_user_id']??0)===$reviewer_user_id') !== false;
$checks['follow-up authors cannot impersonate the executor'] =
    strpos($parts['community_law_submit_outcome_followup'] ?? '', 'entry_type===\\'executor_response\\'') !== false &&
    strpos($parts['community_law_submit_outcome_followup'] ?? '', '$actor_user_id!==(int)$row[\\'submitted_by\\']') !== false;
$checks['second-stage reviewers are members, independent, and excluded from the first review round'] =
    strpos($parts['community_law_review_disputed_outcome_followups'] ?? '', '$is_member') !== false &&
    strpos($parts['community_law_review_disputed_outcome_followups'] ?? '', '$was_original_reviewer') !== false;
$checks['corrective action creator and assigned executor must both belong to the action community'] =
    strpos($parts['community_law_create_outcome_corrective_action'] ?? '', 'actor_member') !== false &&
    strpos($parts['community_law_create_outcome_corrective_action'] ?? '', 'executor_member') !== false;
$checks['only the assigned executor can update corrective progress'] =
    strpos($parts['community_law_submit_outcome_corrective_update'] ?? '', 'responsible_user_id']!==$actor_user_id') !== false;
$checks['completion reviewers are members and excluded for role or prior-review conflicts'] =
    strpos($parts['community_law_review_corrective_completion'] ?? '', '$is_member') !== false &&
    strpos($parts['community_law_review_corrective_completion'] ?? '', 'reviewed_report') !== false &&
    strpos($parts['community_law_review_corrective_completion'] ?? '', 'reviewed_reassessment') !== false;

$checks['outcome endpoint accepts POST only and validates CSRF before dispatch'] =
    strpos($endpoint, 'if ($_SERVER[\\'REQUEST_METHOD\\']!==\\'POST\\')') !== false &&
    strpos($endpoint, 'hash_equals($_SESSION[\\'community_law_csrf\\'],$csrf)') !== false &&
    strpos($endpoint, 'if (($_POST[\\'action\\']??\\'\\')===\\'followup\\')') !== false;

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? "PASS: " : "FAIL: ") . $label . PHP_EOL;
    if (!$passed) $failed++;
}
if ($failed > 0) exit(1);
echo "PASS: parent/child community law access contract (" . count($checks) . " checks)\n";
