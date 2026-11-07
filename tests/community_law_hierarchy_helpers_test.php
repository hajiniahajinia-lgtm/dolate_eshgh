<?php
require_once __DIR__ . '/../community_law_helpers.php';

$checks = 0;
function check_hierarchy(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$valid = [1 => 0, 2 => 1, 3 => 1, 4 => 2, 5 => 4];
check_hierarchy(community_law_parent_map_is_valid($valid), 'valid tree is accepted');

$cycle = [1 => 2, 2 => 1];
check_hierarchy(!community_law_parent_map_is_valid($cycle), 'cycle is rejected');

$missing_parent = [1 => 0, 2 => 9];
check_hierarchy(!community_law_parent_map_is_valid($missing_parent), 'missing parent is rejected');

$negative_parent = [1 => -2];
check_hierarchy(!community_law_parent_map_is_valid($negative_parent), 'negative parent is rejected');

$invalid_id = [-1 => 0];
check_hierarchy(!community_law_parent_map_is_valid($invalid_id), 'non-positive community id is rejected');

check_hierarchy(
    community_law_is_descendant_or_same($valid, 5, 1),
    'deep descendant is recognized'
);
check_hierarchy(
    community_law_is_descendant_or_same($valid, 3, 3),
    'community is its own ancestor'
);
check_hierarchy(
    !community_law_is_descendant_or_same($valid, 5, 3),
    'unrelated branch is not a descendant'
);
check_hierarchy(
    !community_law_is_descendant_or_same($valid, 5, 9),
    'missing ancestor is rejected'
);

check_hierarchy(
    community_law_hierarchy_path($valid, 5) === [5, 4, 2, 1],
    'hierarchy path is returned from child to root'
);
check_hierarchy(
    community_law_hierarchy_path($valid, 1) === [1],
    'root path contains only the root'
);
check_hierarchy(
    community_law_hierarchy_path($valid, 9) === null,
    'path for missing community is null'
);
check_hierarchy(
    community_law_hierarchy_path($cycle, 1) === null,
    'path traversal rejects cycles'
);

fwrite(STDOUT, "OK: {$checks} community law hierarchy checks passed.\n");
?>