<?php
declare(strict_types=1);

/**
 * Regression contract for atomic amendment enactment and version notices.
 * The full legal workflow has many independent review prerequisites; this
 * focused contract protects the transaction boundary and notice coverage.
 */
$source = file_get_contents(__DIR__ . '/../community_law_amendment_helpers.php');
$migration = file_get_contents(__DIR__ . '/../migrations/20261108_community_law_z3_version_notices.sql');
$cases_page = file_get_contents(__DIR__ . '/../community_law_change_cases.php');

if ($source === false || $migration === false || $cases_page === false) {
    fwrite(STDERR, "FAIL: unable to read amendment enactment sources\n");
    exit(1);
}

$start = strpos($source, "function community_law_enact_amendment(");
$end = strpos($source, "if (!function_exists('community_law_open_amendment_final_review'))", $start === false ? 0 : $start);
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "FAIL: amendment enactment function boundary not found\n");
    exit(1);
}
$enactment = substr($source, $start, $end - $start);

$position = static function (string $needle) use ($enactment): int {
    $found = strpos($enactment, $needle);
    return $found === false ? -1 : $found;
};

$begin = $position('$conn->begin_transaction()');
$scope = $position('community_law_scope_community_ids($conn,$version_id)');
$copy = $position('INSERT INTO community_law_implementations');
$activate = $position('UPDATE community_laws SET current_version_id=?');
$notice = $position('INSERT INTO community_law_version_notices');
$commit = $position('$conn->commit()');
$rollback = $position('$conn->rollback()');

$checks = [
    'enactment runs inside an explicit database transaction' => $begin >= 0 && $commit > $begin,
    'the full enacted-version scope is resolved before notifications' => $scope >= 0 && $notice > $scope,
    'implementation state is copied before the current version advances' => $copy >= 0 && $activate > $copy,
    'version notices are inserted before the transaction commits' => $notice >= 0 && $commit > $notice,
    'every scoped community and the originating community are notified' =>
        strpos($enactment, 'array_merge($scope_ids,[$community_id])') !== false,
    'notice records retain both previous and newly enacted version identifiers' =>
        strpos($enactment, "'previous_version_id'=>$base_version_id") !== false &&
        strpos($enactment, "'current_version_id'=>$version_id") !== false,
    'a failed notice insert throws and reaches transaction rollback' =>
        strpos($enactment, "throw new RuntimeException('amendment version notice insert failed')") !== false &&
        strpos($enactment, 'if ($started) $conn->rollback()') !== false &&
        $rollback > $notice,
    'the database prevents duplicate notices for the same version/community/event' =>
        strpos($migration, 'UNIQUE KEY uq_law_version_notice_transition (law_version_id, community_id, event_type)') !== false,
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? "PASS: " : "FAIL: ") . $label . PHP_EOL;
    if (!$passed) $failed++;
}
if ($failed > 0) exit(1);
echo "PASS: atomic amendment enactment notice contract (" . count($checks) . " checks)\n";
