<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function decision_ledger_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function apply_decision_ledger_sql(mysqli $db, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException("Migration is missing or empty: {$path}");
    }
    $db->multi_query($sql);
    do {
        $result = $db->store_result();
        if ($result instanceof mysqli_result) $result->free();
    } while ($db->more_results() && $db->next_result());
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$name = getenv('DB_NAME') ?: 'dolate_charter_ci';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$port = (int)(getenv('DB_PORT') ?: 3306);

$db = new mysqli($host, $user, $password, $name, $port);
$db->set_charset('utf8mb4');

try {
    apply_decision_ledger_sql(
        $db,
        __DIR__ . '/../migrations/20261108_community_decision_ledger.sql'
    );
    require_once __DIR__ . '/../community_decision_ledger.php';

    $db->query('DELETE FROM community_decision_records WHERE source_topic_id IN (990001, 990002, 990003)');

    $columns = $db->query(
        "SELECT COLUMN_NAME FROM information_schema.columns
         WHERE table_schema=DATABASE() AND table_name='community_decision_records'"
    );
    $columnNames = [];
    while ($row = $columns->fetch_assoc()) $columnNames[] = $row['COLUMN_NAME'];
    $columns->free();
    decision_ledger_check(
        in_array('source_topic_id', $columnNames, true) &&
        !in_array('entry_id', $columnNames, true),
        'decision ledger is keyed to its source topic and has no required knowledge-entry link'
    );

    $snapshot = [
        'community_id' => 77,
        'source_topic_id' => 990001,
        'source_idea_id' => 880001,
        'topic_purpose_snapshot' => 'knowledge',
        'selected_idea_content' => 'نمونهٔ ایدهٔ منتخب',
        'acceptance_status' => 'accepted',
        'enforceability_status' => 'not_enforceable',
        'yes_count' => 8,
        'no_count' => 2,
        'yes_weight' => 10.5,
        'no_weight' => 2.0,
        'yes_percent' => 80.0,
        'weighted_yes_percent' => 84.0,
        'enforce_yes_count' => 1,
        'enforce_no_count' => 3,
        'decided_at' => '2026-11-08 12:30:00',
    ];

    decision_ledger_check(
        community_decision_record_snapshot($db, $snapshot),
        'a valid collective decision snapshot is recorded without a knowledge entry'
    );

    $stored = $db->query(
        'SELECT * FROM community_decision_records WHERE source_topic_id=990001'
    )->fetch_assoc();
    decision_ledger_check(
        $stored && (int)$stored['community_id'] === 77 &&
        $stored['topic_purpose_snapshot'] === 'knowledge' &&
        $stored['acceptance_status'] === 'accepted' &&
        $stored['enforceability_status'] === 'not_enforceable',
        'the decision outcome and topic-purpose snapshot are persisted'
    );

    $noIdeaSnapshot = $snapshot;
    $noIdeaSnapshot['source_topic_id'] = 990003;
    $noIdeaSnapshot['source_idea_id'] = null;
    $noIdeaSnapshot['selected_idea_content'] = null;
    $noIdeaSnapshot['acceptance_status'] = 'no_votes';
    $noIdeaSnapshot['enforceability_status'] = 'undecided';
    $noIdeaSnapshot['yes_count'] = 0;
    $noIdeaSnapshot['no_count'] = 0;
    $noIdeaSnapshot['yes_weight'] = 0;
    $noIdeaSnapshot['no_weight'] = 0;
    $noIdeaSnapshot['yes_percent'] = 0;
    $noIdeaSnapshot['weighted_yes_percent'] = 0;
    $noIdeaSnapshot['enforce_yes_count'] = 0;
    $noIdeaSnapshot['enforce_no_count'] = 0;
    decision_ledger_check(
        community_decision_record_snapshot($db, $noIdeaSnapshot),
        'a knowledge-oriented topic with no selected idea can still have an independent outcome record'
    );
    $noIdeaStored = $db->query(
        'SELECT source_idea_id, acceptance_status FROM community_decision_records WHERE source_topic_id=990003'
    )->fetch_assoc();
    decision_ledger_check(
        $noIdeaStored && $noIdeaStored['source_idea_id'] === null &&
        $noIdeaStored['acceptance_status'] === 'no_votes',
        'a decision record preserves a null selected idea and no-vote outcome'
    );

    $changedRetry = $snapshot;
    $changedRetry['acceptance_status'] = 'not_accepted';
    $changedRetry['yes_count'] = 0;
    decision_ledger_check(
        community_decision_record_snapshot($db, $changedRetry),
        'repeated writes are accepted as idempotent retries'
    );
    $afterRetry = $db->query(
        'SELECT acceptance_status, yes_count FROM community_decision_records WHERE source_topic_id=990001'
    )->fetch_assoc();
    decision_ledger_check(
        $afterRetry['acceptance_status'] === 'accepted' && (int)$afterRetry['yes_count'] === 8,
        'an existing decision snapshot is never silently overwritten'
    );

    $invalid = $snapshot;
    $invalid['source_topic_id'] = 990002;
    $invalid['acceptance_status'] = 'law';
    decision_ledger_check(
        !community_decision_record_snapshot($db, $invalid),
        'unsupported outcome values are rejected'
    );
    $invalidRows = (int)$db->query(
        'SELECT COUNT(*) AS total FROM community_decision_records WHERE source_topic_id=990002'
    )->fetch_assoc()['total'];
    decision_ledger_check($invalidRows === 0, 'invalid decisions create no ledger rows');

    $db->query('DELETE FROM community_decision_records WHERE source_topic_id IN (990001, 990002)');
    fwrite(STDOUT, "OK: community decision ledger integration checks passed.\n");
} finally {
    $db->close();
}
?>
