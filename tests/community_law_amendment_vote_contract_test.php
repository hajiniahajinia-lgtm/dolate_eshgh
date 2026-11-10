<?php
declare(strict_types=1);

/**
 * Regression contract for the amendment branch of the existing law vote engine.
 * The HTTP handler is intentionally kept in community_laws.php; these checks
 * ensure its amendment-specific guards cannot silently disappear from CI.
 */
$lawSource = file_get_contents(__DIR__ . '/../community_laws.php');
$amendmentSource = file_get_contents(__DIR__ . '/../community_law_amendment_helpers.php');

if ($lawSource === false || $amendmentSource === false) {
    fwrite(STDERR, "FAIL: unable to read law workflow sources\n");
    exit(1);
}

$checks = [
    'vote start accepts only a submitted amendment on the approved base law' =>
        strpos($lawSource, "\$record['amendment_status'] === 'submitted'") !== false &&
        strpos($lawSource, "(int)\$record['current_version_id'] === (int)\$record['amendment_base_version_id']") !== false,
    'amendment vote tally requires local_voting while base version remains current' =>
        strpos($lawSource, "\$record['amendment_status']==='local_voting'") !== false &&
        strpos($lawSource, "(int)\$record['current_version_id']===(int)\$record['amendment_base_version_id']") !== false,
    'a passed local vote moves the amendment to parent compatibility review' =>
        strpos($lawSource, "\$final_status==='vote_passed'") !== false &&
        strpos($lawSource, "? 'parent_review'") !== false,
    'parent review requires two independent reviews before a decision' =>
        strpos($amendmentSource, "if ((int)\$totals['total']>=2)") !== false,
    'parent review explicitly cannot edit the local law text' =>
        strpos($amendmentSource, "'parent_can_edit_local_text'=>false") !== false,
    'activation atomically advances only from the approved base version' =>
        strpos($amendmentSource, "WHERE id=? AND status='approved' AND current_version_id=?") !== false,
    'activation preserves the prior approved version as superseded' =>
        strpos($amendmentSource, "SET status='superseded'") !== false,
    'unresolved and upheld appeals prevent amendment activation' =>
        strpos($amendmentSource, "status IN ('submitted','under_review')") !== false &&
        strpos($amendmentSource, "status='upheld'") !== false,
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? "PASS: " : "FAIL: ") . $label . PHP_EOL;
    if (!$passed) $failed++;
}
if ($failed > 0) exit(1);
echo "PASS: amendment vote-engine regression contract (" . count($checks) . " checks)\n";
