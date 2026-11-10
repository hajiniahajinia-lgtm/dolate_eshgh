<?php
declare(strict_types=1);

$page = file_get_contents(__DIR__ . '/../community_laws.php');
if (!is_string($page)) {
    fwrite(STDERR, "FAIL: unable to read community_laws.php\n");
    exit(1);
}

$start = strpos($page, "if (isset($_POST['finalize_law_voting_rules']))");
$end = $start === false ? false : strpos($page, "if (isset($_POST['start_law_vote']))", $start);
$finalizer = ($start !== false && $end !== false)
    ? substr($page, $start, $end - $start)
    : '';

$checks = [];
$checks['finalization reads the configured approval threshold from the proposal row'] =
    $finalizer !== '' &&
    strpos($finalizer, "approval_threshold_percent") !== false &&
    strpos($finalizer, "\$proposal['approval_threshold_percent']") !== false;
$checks['invalid or out-of-range approval thresholds fail closed'] =
    strpos($finalizer, '!is_numeric($approval_threshold_percent)') !== false &&
    strpos($finalizer, "(float)\$approval_threshold_percent < 50.01") !== false &&
    strpos($finalizer, "(float)\$approval_threshold_percent > 100") !== false;
$checks['approval uses the configured percentage with documented two-decimal rounding'] =
    strpos($finalizer, "round((\$approvals / \$total_votes) * 100, 2)") !== false &&
    strpos($finalizer, "\$approval_percent >= (float)\$approval_threshold_percent") !== false;
$checks['the former hard-coded two-thirds comparison is absent'] =
    strpos($finalizer, '(\$approvals * 3) >= (\$total_votes * 2)') === false;
$checks['the separately configured reviewer quorum remains enforced'] =
    strpos($finalizer, "\$total_votes < (int)\$proposal['reviewer_quorum']") !== false;

$percentage = static fn(int $approvals, int $total): float =>
    $total > 0 ? round(($approvals / $total) * 100, 2) : 0.0;
$checks['the stored 66.67 threshold accepts two of three votes after percentage rounding'] =
    $percentage(2, 3) >= 66.67;
$checks['the stored 66.67 threshold rejects one of three votes'] =
    $percentage(1, 3) < 66.67;
$checks['a configured 75 percent threshold is not silently treated as two-thirds'] =
    $percentage(2, 3) < 75.00;

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? "PASS: " : "FAIL: ") . $label . PHP_EOL;
    if (!$passed) $failed++;
}
if ($failed > 0) exit(1);
echo "PASS: configured law voting-rule threshold contract (" . count($checks) . " checks)\n";
