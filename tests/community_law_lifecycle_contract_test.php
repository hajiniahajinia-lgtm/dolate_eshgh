<?php
declare(strict_types=1);

/** Contract audit connecting the production law lifecycle gates. */
$law_source = file_get_contents(__DIR__ . '/../community_laws.php');
$change_source = file_get_contents(__DIR__ . '/../community_law_change_helpers.php');
if (!is_string($law_source) || !is_string($change_source)) {
    fwrite(STDERR, "FAIL: unable to read law lifecycle sources\n");
    exit(1);
}

function lifecycle_handler(string $source, string $key): ?string {
    $needle = "if (isset(\$_POST['" . $key . "']))";
    $start = strpos($source, $needle);
    if ($start === false) return null;
    $next = strpos($source, "if (isset(\$_POST['", $start + strlen($needle));
    return substr($source, $start, $next === false ? null : $next - $start);
}

$draft = lifecycle_handler($law_source, 'create_law_draft');
$proposal = lifecycle_handler($law_source, 'submit_law_for_review');
$vote_start = lifecycle_handler($law_source, 'start_law_vote');
$vote_tally = lifecycle_handler($law_source, 'finalize_law_tally');
$final_open = lifecycle_handler($law_source, 'open_law_final_review');
$final_assess = lifecycle_handler($law_source, 'submit_law_final_assessment');
$appeal_submit = lifecycle_handler($law_source, 'submit_law_appeal');
$appeal_assess = lifecycle_handler($law_source, 'submit_law_appeal_assessment');
$enact = lifecycle_handler($law_source, 'enact_law');

$checks = [];
$checks['draft requires an accepted lawmaking decision and stores its full provenance'] =
    $draft !== null &&
    strpos($draft, 'community_law_resolve_accepted_lawmaking_origin(') !== false &&
    strpos($draft, 'source_topic_id, source_idea_id, source_decision_id') !== false &&
    strpos($draft, '$conn->begin_transaction()') !== false &&
    strpos($draft, '$conn->rollback()') !== false;
$checks['proposal is restricted to the author and the current draft version'] =
    $proposal !== null &&
    strpos($proposal, "\$record['status'] !== 'draft'") !== false &&
    strpos($proposal, "\$record['version_status'] !== 'draft'") !== false &&
    strpos($proposal, "\$record['current_version_id']") !== false &&
    strpos($proposal, "\$record['proposed_by']") !== false &&
    strpos($proposal, "SET status='proposed'") !== false;
$checks['voting starts only from proposed and snapshots a bounded voting period'] =
    $vote_start !== null &&
    strpos($vote_start, "SET status='voting', vote_started_at=NOW(), vote_deadline=?") !== false &&
    strpos($vote_start, "WHERE id=? AND status='proposed'") !== false &&
    strpos($vote_start, '$conn->commit()') !== false;
$checks['tally waits for the deadline and records the formal decision result'] =
    $vote_tally !== null &&
    strpos($vote_tally, "\$record['vote_deadline']") !== false &&
    strpos($vote_tally, "'vote_passed'") !== false &&
    strpos($vote_tally, 'INSERT INTO community_law_final_results') !== false &&
    strpos($vote_tally, 'community_law_record_history(') !== false;
$checks['final review requires a passed vote and snapshots charter and parent references'] =
    $final_open !== null &&
    strpos($final_open, "\$law['final_status']!=='vote_passed'") !== false &&
    strpos($final_open, 'community_law_capture_charter_snapshot(') !== false &&
    strpos($final_open, 'community_law_final_review_references') !== false &&
    strpos($final_open, "SET status='pending_final_review'") !== false;
$checks['final assessment requires independent quorum and no unresolved conflict or uncertainty'] =
    $final_assess !== null &&
    strpos($final_assess, "\$case['reviewer_quorum']") !== false &&
    strpos($final_assess, "\$totals['conflicts']") !== false &&
    strpos($final_assess, "\$totals['unclear_count']") !== false &&
    strpos($final_assess, "\$new_status = 'appeal_period'") !== false;
$checks['appeal submission is time-bounded and places enactment on hold'] =
    $appeal_submit !== null &&
    strpos($appeal_submit, "\$law['appeal_deadline']") !== false &&
    strpos($appeal_submit, 'INSERT INTO community_law_appeals') !== false &&
    strpos($appeal_submit, "status='appeal_hold'") !== false &&
    strpos($appeal_submit, 'community_law_appeal_electorate') !== false;
$checks['appeal outcomes are aggregated across every appeal before resuming or blocking enactment'] =
    $appeal_assess !== null &&
    strpos($appeal_assess, "'upheld'") !== false &&
    strpos($appeal_assess, "'dismissed'") !== false &&
    strpos($appeal_assess, 'open_appeals') !== false &&
    strpos($appeal_assess, 'upheld_appeals') !== false &&
    strpos($appeal_assess, 'needs_revision') !== false;
$checks['enactment requires an expired appeal window, current charter and no unresolved or upheld appeals'] =
    $enact !== null &&
    strpos($enact, 'community_law_charter_snapshot_is_current(') !== false &&
    strpos($enact, "\$law['appeal_deadline']") !== false &&
    strpos($enact, 'enactment_appeals_pending') !== false &&
    strpos($enact, "SET status='approved', effective_at=NOW()") !== false &&
    strpos($enact, 'community_law_version_notices') !== false &&
    strpos($enact, '$conn->commit()') !== false &&
    strpos($enact, '$conn->rollback()') !== false;
$checks['change requests require five independent reviewers and four supporting assessments'] =
    strpos($change_source, 'count($candidates)<5') !== false &&
    strpos($change_source, 'if ((int)$summary[\'total\']>=5)') !== false &&
    strpos($change_source, 'if ((int)$summary[\'uphold_count\']>=4)') !== false &&
    strpos($change_source, "FROM community_law_voting_rules") !== false &&
    strpos($change_source, "status='approved'") !== false &&
    strpos($change_source, '\''turnout_required'\'=>((int)$case['turnout_numerator'])') !== false &&
    strpos($change_source, '\''approval_required'\'=>((int)$case['approval_numerator'])') !== false;
$checks['approved suspension, reinstatement and repeal effects are idempotent and audited'] =
    strpos($change_source, "\$row['effect_applied_at']!==null") !== false &&
    strpos($change_source, 'community_law_record_history(') !== false &&
    strpos($change_source, 'community_law_community_notices') !== false &&
    strpos($change_source, 'effect_applied_at=NOW()') !== false;

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? "PASS: " : "FAIL: ") . $label . PHP_EOL;
    if (!$passed) $failed++;
}
if ($failed > 0) exit(1);
echo "PASS: complete community law lifecycle contract (" . count($checks) . " checks)\n";
