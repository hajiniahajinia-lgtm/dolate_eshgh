<?php
declare(strict_types=1);
require_once __DIR__ . '/../community_law_helpers.php';

$source = file_get_contents(__DIR__ . '/../community_law_helpers.php');
if (!is_string($source)) {
    fwrite(STDERR, "FAIL: unable to read law scope helper source\n");
    exit(1);
}

$checks = [];
$checks['scope match validates the complete hierarchy before granting inclusion'] =
    strpos($source, '$parent_map === null || !community_law_parent_map_is_valid($parent_map)') !== false;
$checks['scope match always includes the explicitly named community'] =
    strpos($source, '$target_community_id === $scope[\'community_id\']') !== false;
$checks['descendants are included only when include_descendants is enabled'] =
    strpos($source, '$scope[\'include_descendants\'] &&') !== false &&
    strpos($source, 'community_law_is_descendant_or_same(') !== false;
$checks['scope match rejects invalid include_descendants values'] =
    strpos($source, '!in_array((int)$scope[\'include_descendants\'], [0, 1], true)') !== false;
$checks['scope match rejects a scope root missing from the hierarchy'] =
    strpos($source, '!array_key_exists($scope[\'community_id\'], $parent_map)') !== false;

$hierarchy = [10 => 0, 11 => 10, 12 => 10, 13 => 11, 14 => 13, 20 => 0, 21 => 20];
$checks['deep child is recognized under its mother community'] =
    community_law_is_descendant_or_same($hierarchy, 14, 10);
$checks['sibling branch is recognized under the same mother'] =
    community_law_is_descendant_or_same($hierarchy, 12, 10);
$checks['unrelated mother branch is excluded'] =
    !community_law_is_descendant_or_same($hierarchy, 21, 10);
$checks['parent map with a cycle is rejected'] =
    !community_law_parent_map_is_valid([10 => 11, 11 => 10]);

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? "PASS: " : "FAIL: ") . $label . PHP_EOL;
    if (!$passed) $failed++;
}
if ($failed > 0) exit(1);
echo "PASS: law scope hierarchy contract (" . count($checks) . " checks)\n";
