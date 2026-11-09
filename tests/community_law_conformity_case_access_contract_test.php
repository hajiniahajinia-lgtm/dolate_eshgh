<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../community_law_conformity_cases.php');
if (!is_string($source)) {
    fwrite(STDERR, "FAIL: unable to read conformity cases page\n");
    exit(1);
}

$checks = [];
$membershipQuery = strpos($source, 'INNER JOIN community_members cm ON cm.community_id=c.id AND cm.user_id=?');
$membershipBind = strpos($source, '$community_stmt->bind_param(\'ii\',$user_id,$community_id)');
$checks['community details and membership are checked atomically'] =
    $membershipQuery !== false && $membershipBind !== false &&
    strpos($source, 'SELECT c.id,c.name') !== false;
$checks['page does not perform a separate membership check after loading community'] =
    strpos($source, 'community_law_conformity_is_member(') === false;
$checks['missing community and nonmember are distinguished'] =
    strpos($source, 'http_response_code(404)') !== false &&
    strpos($source, 'http_response_code(403)') !== false;
$checks['membership database failures return service unavailable'] =
    substr_count($source, 'http_response_code(503)') >= 6;
$checks['case query failure cannot be presented as an empty case list'] =
    strpos($source, 'امکان دریافت پرونده‌های هم‌ترازی وجود ندارد.') !== false;
$checks['conflict and review query failures cannot silently hide records'] =
    strpos($source, 'امکان دریافت موارد اختلاف وجود ندارد.') !== false &&
    strpos($source, 'امکان دریافت سوابق بررسی وجود ندارد.') !== false;
$checks['latest review round is displayed and governs current-side eligibility'] =
    strpos($source, 'AS latest_review_round') !== false &&
    strpos($source, 'r.review_round=(SELECT COALESCE(MAX(r2.review_round),0)') !== false;
$checks['reviewers cannot submit a second opinion in later rounds'] =
    strpos($source, 'r.reviewer_user_id=?') !== false;
$checks['all case data remains scoped to the requested local or parent community'] =
    strpos($source, 'WHERE c.community_id=? OR c.parent_community_id=?') !== false;

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? "PASS: " : "FAIL: ") . $label . PHP_EOL;
    if (!$passed) $failed++;
}
if ($failed > 0) exit(1);
echo "PASS: conformity case access contract (" . count($checks) . " checks)\n";
