<?php
declare(strict_types=1);

/**
 * Regression contract: a law originating in a parent community must include
 * every descendant community, both on first draft and on later revisions.
 */
$source = file_get_contents(__DIR__ . '/../community_laws.php');
$helpers = file_get_contents(__DIR__ . '/../community_law_helpers.php');
if ($source === false || $helpers === false) {
    fwrite(STDERR, "FAIL: unable to read parent-law scope sources\n");
    exit(1);
}

$checks = [];
$create_start = strpos($source, "// قانون مصوب هر جامعه، در صورت احراز صلاحیت، برای همه زیرشاخه‌های آن نیز لازم‌الرعایه است.");
$create_end = $create_start === false ? false : strpos($source, "if ($title === ''", $create_start);
$create_block = ($create_start !== false && $create_end !== false)
    ? substr($source, $create_start, $create_end - $create_start)
    : '';

$checks['new parent law explicitly enables descendant inclusion'] =
    strpos($create_block, '$include_descendants = 1;') !== false;
$checks['new law scope persists descendant inclusion in the same transaction'] =
    strpos($source, 'INSERT INTO community_law_scopes (law_version_id, scope_community_id, include_descendants)') !== false &&
    strpos($source, '$scope_insert->bind_param(\'iii\', $version_id, $community_id, $include_descendants)') !== false;

$revision_start = strpos($source, '// در نسخه اصلاحی نیز شمول قانون مادر نسبت به تمام زیرشاخه‌ها حفظ می‌شود.');
$revision_end = $revision_start === false ? false : strpos($source, '$next_version =', $revision_start);
$revision_block = ($revision_start !== false && $revision_end !== false)
    ? substr($source, $revision_start, $revision_end - $revision_start)
    : '';

$checks['revisions force the originating community scope to include descendants'] =
    strpos($revision_block, '$scope_row[\'include_descendants\'] = 1;') !== false;
$checks['revisions restore the originating scope if it is missing'] =
    strpos($revision_block, '$scope_rows[] = [') !== false &&
    strpos($revision_block, "'scope_community_id' => $origin_community_id") !== false &&
    strpos($revision_block, "'include_descendants' => 1") !== false;
$checks['scope evaluation applies descendant inclusion through the validated hierarchy'] =
    strpos($helpers, "if ($scope['include_descendants']") !== false &&
    strpos($helpers, 'community_law_is_descendant_or_same(') !== false &&
    strpos($helpers, 'community_law_parent_map_is_valid($parent_map)') !== false;

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? "PASS: " : "FAIL: ") . $label . PHP_EOL;
    if (!$passed) $failed++;
}
if ($failed > 0) exit(1);
echo "PASS: parent-law descendant scope regression contract (" . count($checks) . " checks)\n";
