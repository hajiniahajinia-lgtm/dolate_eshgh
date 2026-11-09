<?php
declare(strict_types=1);

$page = file_get_contents(__DIR__ . '/../community_hierarchy_history.php');
$community_page = file_get_contents(__DIR__ . '/../community.php');
$migration = file_get_contents(__DIR__ . '/../migrations/20261109_community_hierarchy_change_history.sql');

if ($page === false || $community_page === false || $migration === false) {
    fwrite(STDERR, "FAIL: unable to read hierarchy history sources\n");
    exit(1);
}

$checks = [
    'history page requires an authenticated session' =>
        strpos($page, 'if (!isset($_SESSION[\'user_id\']))') !== false,
    'community identifier is validated as a positive integer' =>
        strpos($page, 'FILTER_VALIDATE_INT') !== false && strpos($page, '$community_id <= 0') !== false,
    'community and membership are checked in one exact scoped query' =>
        strpos($page, 'INNER JOIN community_members cm ON cm.community_id=c.id AND cm.user_id=?') !== false &&
        strpos($page, 'WHERE c.id=? LIMIT 1') !== false,
    'nonmembers are denied before any history query is executed' =>
        strpos($page, "http_response_code($community_exists ? 403 : 404)") < strpos($page, 'FROM community_hierarchy_change_history'),
    'history query is scoped to the requested community with bound parameters' =>
        strpos($page, 'WHERE h.community_id=?') !== false &&
        strpos($page, 'bind_param(\'iii\', $community_id, $page_size, $offset)') !== false,
    'history is ordered newest first and paginated' =>
        strpos($page, 'ORDER BY h.created_at DESC, h.id DESC') !== false &&
        strpos($page, 'LIMIT ? OFFSET ?') !== false,
    'all user-controlled and database text is HTML escaped' =>
        strpos($page, 'htmlspecialchars((string)$value, ENT_QUOTES, \'UTF-8\')') !== false &&
        substr_count($page, 'community_hierarchy_history_escape(') >= 6,
    'page handles a missing migration without exposing another data source' =>
        strpos($page, "table_name='community_hierarchy_change_history'") !== false &&
        strpos($page, 'ساختار تاریخچه هنوز در پایگاه داده نصب نشده است') !== false,
    'community hierarchy section links to the history only for its members' =>
        strpos($community_page, 'community_hierarchy_history.php?community_id=') !== false &&
        strpos($community_page, '<?php if ($is_community_member): ?>') !== false,
    'history migration retains one immutable event per finalized vote round' =>
        strpos($migration, 'UNIQUE KEY uq_hierarchy_history_vote_round (vote_round_id)') !== false,
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? "PASS: " : "FAIL: ") . $label . PHP_EOL;
    if (!$passed) $failed++;
}
if ($failed > 0) exit(1);
echo "PASS: community hierarchy history access contract (" . count($checks) . " checks)\n";
