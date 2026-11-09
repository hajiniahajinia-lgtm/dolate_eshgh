<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../community_law_change_helpers.php');
if (!is_string($source)) {
    fwrite(STDERR, "FAIL: unable to read law change helper source\n");
    exit(1);
}
$start = strpos($source, 'function community_law_change_cast_vote(');
$end = strpos($source, "\n}\n", $start === false ? 0 : $start);
$voteFunction = $start === false || $end === false ? '' : substr($source, $start, $end - $start + 2);
$checks = [];
$checks['vote case is tied to its originating parent community'] =
    strpos($voteFunction, 'JOIN community_laws l ON l.id=c.law_id') !== false &&
    strpos($voteFunction, 'l.originating_community_id') !== false;
$checks['voter must be in the frozen electorate'] =
    strpos($voteFunction, 'community_law_change_electorate') !== false;
$checks['voter must still be an active parent-community member'] =
    strpos($voteFunction, 'FROM community_members WHERE community_id=? AND user_id=? LIMIT 1 FOR UPDATE') !== false &&
    strpos($voteFunction, "voter is no longer a member of the parent community") !== false;
$checks['membership check and vote write are protected by one transaction'] =
    strpos($voteFunction, '$conn->begin_transaction()') !== false &&
    strpos($voteFunction, '$conn->commit()') !== false &&
    strpos($voteFunction, '$conn->rollback()') !== false;
$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? "PASS: " : "FAIL: ") . $label . PHP_EOL;
    if (!$passed) $failed++;
}
if ($failed > 0) exit(1);
echo "PASS: parent law change voter eligibility contract (" . count($checks) . " checks)\n";
?>