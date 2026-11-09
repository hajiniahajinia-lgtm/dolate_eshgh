<?php
declare(strict_types=1);

/** Regression contract: ordinary parent-law enactment must notify every scoped community atomically. */
$source = file_get_contents(__DIR__ . '/../community_laws.php');
$migration = file_get_contents(__DIR__ . '/../migrations/20261108_community_law_z3_version_notices.sql');
if ($source === false || $migration === false) {
    fwrite(STDERR, "FAIL: unable to read parent-law enactment sources\n");
    exit(1);
}
$start = strpos($source, "if (isset(\$_POST['enact_law']))");
$end = strpos($source, "if (isset(\$_POST['create_law_revision']))", $start === false ? 0 : $start);
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "FAIL: ordinary enactment boundary not found\n");
    exit(1);
}
$block = substr($source, $start, $end - $start);
$notice = strpos($block, "INSERT INTO community_law_version_notices");
$commit = strpos($block, '$conn->commit()');
$rollback = strpos($block, '$conn->rollback()');
$checks = [
    'ordinary enactment creates version notices' => $notice !== false,
    'notices are inserted after the version becomes approved' =>
        strpos($block, "UPDATE community_law_versions SET status='approved'") < $notice,
    'every scoped community and the originating parent are notified' =>
        strpos($block, '$implementation_community_ids') < $notice &&
        strpos($block, "[(int)$law['originating_community_id']]") !== false &&
        strpos($block, 'array_unique(array_merge(') !== false,
    'notice explicitly records automatic recognition through parent-law scope' =>
        strpos($block, "'automatic_by_parent_law_scope'") !== false,
    'notice insertion is inside the enactment transaction' =>
        strpos($block, '$conn->begin_transaction()') < $notice && $commit > $notice,
    'notice failure triggers rollback' =>
        strpos($block, "throw new RuntimeException('ordinary enactment version notice insert failed')") !== false &&
        $rollback > $notice,
    'version/community/event uniqueness prevents duplicate activation notices' =>
        strpos($migration, 'UNIQUE KEY uq_law_version_notice_transition (law_version_id, community_id, event_type)') !== false,
];
$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? "PASS: " : "FAIL: ") . $label . PHP_EOL;
    if (!$passed) $failed++;
}
if ($failed > 0) exit(1);
echo "PASS: atomic parent-law enactment notice contract (" . count($checks) . " checks)\n";
?>