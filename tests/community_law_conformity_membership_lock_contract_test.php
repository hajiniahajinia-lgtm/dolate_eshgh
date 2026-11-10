<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../community_law_conformity_helpers.php');
if (!is_string($source)) {
    fwrite(STDERR, "FAIL: unable to read conformity helpers\\n");
    exit(1);
}

$addStart = strpos($source, 'function community_law_conformity_add_conflict(');
$reviewStart = strpos($source, 'function community_law_conformity_review_conflict(');
$addTxn = $addStart === false ? false : strpos($source, '$conn->begin_transaction()', $addStart);
$addLock = $addStart === false ? false : strpos($source, 'community_law_conformity_lock_member(', $addStart);
$reviewTxn = $reviewStart === false ? false : strpos($source, '$conn->begin_transaction()', $reviewStart);
$reviewLock = $reviewStart === false ? false : strpos($source, 'community_law_conformity_lock_member(', $reviewStart);

$checks = [];
$checks['membership helper locks the exact community-member row'] =
    strpos($source, 'function community_law_conformity_lock_member(') !== false &&
    strpos($source, 'FROM community_members WHERE community_id=? AND user_id=? LIMIT 1 FOR UPDATE') !== false;
$checks['conflict identification locks membership after opening its transaction'] =
    $addTxn !== false && $addLock !== false && $addTxn < $addLock;
$checks['conflict review locks membership after opening its transaction'] =
    $reviewTxn !== false && $reviewLock !== false && $reviewTxn < $reviewLock;
$checks['both transactional paths use the locking helper'] =
    substr_count($source, 'community_law_conformity_lock_member(') === 3;

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? "PASS: " : "FAIL: ") . $label . PHP_EOL;
    if (!$passed) $failed++;
}
if ($failed > 0) exit(1);
echo "PASS: conformity membership locking contract (" . count($checks) . " checks)\\n";
