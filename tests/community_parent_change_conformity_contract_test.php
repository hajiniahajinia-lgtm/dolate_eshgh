<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../set_community_parent.php');
if (!is_string($source)) {
    fwrite(STDERR, "FAIL: unable to read hierarchy transition source\n");
    exit(1);
}

$checks = [];
$checks['hierarchy transition is transaction-scoped'] =
    strpos($source, '$conn->begin_transaction()') !== false &&
    strpos($source, '$conn->rollback()') !== false;
$update = strpos($source, "if (!\$apply->execute() || \$apply->affected_rows > 1)");
$review = strpos($source, 'community_law_register_hierarchy_conformity_reviews(');
$finalize = strpos($source, 'UPDATE community_parent_vote_rounds');
$commit = strpos($source, '$conn->commit()');
$checks['conformity review runs after the actual parent update'] =
    $update !== false && $review !== false && $update < $review;
$checks['review receives the exact new hierarchy, moved community and actor'] =
    $review !== false &&
    strpos(substr($source, $review, 500), '$conn, $new_parents, $community_id, $user_id') !== false;
$checks['a failed conformity review aborts finalization'] =
    $review !== false &&
    strpos(substr($source, $review, 700), 'throw new Exception') !== false &&
    $finalize !== false && $review < $finalize;
$checks['conformity review remains inside the same transaction through commit'] =
    $review !== false && $commit !== false && $review < $commit;

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? "PASS: " : "FAIL: ") . $label . PHP_EOL;
    if (!$passed) $failed++;
}
if ($failed > 0) exit(1);
echo "PASS: hierarchy conformity transition contract (" . count($checks) . " checks)\n";
