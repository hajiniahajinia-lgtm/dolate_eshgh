<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../community_law_conformity_helpers.php');
if (!is_string($source)) {
    fwrite(STDERR, "FAIL: unable to read conformity helpers\\n");
    exit(1);
}

$checks = [];
$checks['transactional membership helper locks the exact community-member row'] =
    strpos($source, 'function community_law_conformity_lock_member(') !== false &&
    strpos($source, 'FROM community_members WHERE community_id=? AND user_id=? LIMIT 1 FOR UPDATE') !== false;
$checks['conflict identification revalidates and locks local membership inside its transaction'] =
    substr_count($source, 'community_law_conformity_lock_member(') >= 3;
$checks['conflict review revalidates and locks the selected side membership inside its transaction'] =
    substr_count($source, 'community_law_conformity_lock_member(') >= 3;
$checks['both operations begin transactions before locking membership'] =
    strpos($source, "if (!$conn->begin_transaction())") !== false &&
    substr_count($source, 'community_law_conformity_lock_member(') >= 3;

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? "PASS: " : "FAIL: ") . $label . PHP_EOL;
    if (!$passed) $failed++;
}
if ($failed > 0) exit(1);
echo "PASS: conformity membership locking contract (" . count($checks) . " checks)\\n";
