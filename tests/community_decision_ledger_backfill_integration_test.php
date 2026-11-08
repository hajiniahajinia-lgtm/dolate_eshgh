<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function decision_backfill_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function apply_decision_backfill_sql(mysqli $db, string $path): void
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
    apply_decision_backfill_sql(
        $db,
        __DIR__ . '/../migrations/20261108_community_decision_ledger.sql'
    );

    $db->query("CREATE TABLE topics (
        id INT NOT NULL PRIMARY KEY,
        community_id INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("CREATE TABLE collective_knowledge_decisions (
        topic_id INT NOT NULL PRIMARY KEY,
        entry_id BIGINT NOT NULL,
        selected_idea_id INT NULL,
        selected_idea_content MEDIUMTEXT NULL,
        topic_purpose_snapshot VARCHAR(20) NOT NULL,
        yes_count INT NOT NULL,
        no_count INT NOT NULL,
        yes_weight DECIMAL(14,4) NOT NULL,
        no_weight DECIMAL(14,4) NOT NULL,
        yes_percent DECIMAL(7,2) NOT NULL,
        weighted_yes_percent DECIMAL(7,2) NOT NULL,
        enforce_yes_count INT NOT NULL,
        enforce_no_count INT NOT NULL,
        acceptance_status VARCHAR(30) NOT NULL,
        enforceability_status VARCHAR(30) NOT NULL,
        finalized_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("INSERT INTO topics (id, community_id) VALUES (990020, 77)");
    $db->query("INSERT INTO collective_knowledge_decisions
        (topic_id, entry_id, selected_idea_id, selected_idea_content, topic_purpose_snapshot,
         yes_count, no_count, yes_weight, no_weight, yes_percent, weighted_yes_percent,
         enforce_yes_count, enforce_no_count, acceptance_status, enforceability_status, finalized_at)
        VALUES
        (990020, 990120, 880020, 'محتوای ایدهٔ تاریخی', 'knowledge',
         7, 3, 8.5000, 2.0000, 70.00, 80.00,
         1, 2, 'accepted', 'not_enforceable', '2026-11-07 14:25:00')");

    $backfillPath = __DIR__ . '/../migrations/20261108_community_decision_ledger_backfill.sql';
    apply_decision_backfill_sql($db, $backfillPath);

    $stored = $db->query(
        'SELECT community_id, source_idea_id, topic_purpose_snapshot, acceptance_status,
                enforceability_status, yes_count, weighted_yes_percent, decided_at
         FROM community_decision_records WHERE source_topic_id=990020'
    )->fetch_assoc();
    decision_backfill_check(
        $stored &&
        (int)$stored['community_id'] === 77 &&
        (int)$stored['source_idea_id'] === 880020 &&
        $stored['topic_purpose_snapshot'] === 'knowledge' &&
        $stored['acceptance_status'] === 'accepted' &&
        $stored['enforceability_status'] === 'not_enforceable' &&
        (int)$stored['yes_count'] === 7 &&
        (float)$stored['weighted_yes_percent'] === 80.0 &&
        $stored['decided_at'] === '2026-11-07 14:25:00',
        'historical decision fields and their original decision time are copied'
    );

    $db->query(
        "UPDATE collective_knowledge_decisions
         SET acceptance_status='not_accepted', yes_count=0
         WHERE topic_id=990020"
    );
    apply_decision_backfill_sql($db, $backfillPath);
    $retry = $db->query(
        'SELECT acceptance_status, yes_count FROM community_decision_records WHERE source_topic_id=990020'
    )->fetch_assoc();
    decision_backfill_check(
        $retry && $retry['acceptance_status'] === 'accepted' && (int)$retry['yes_count'] === 7,
        're-running the backfill never overwrites an existing decision snapshot'
    );

    $db->query('DROP TABLE collective_knowledge_decisions');
    apply_decision_backfill_sql($db, $backfillPath);
    decision_backfill_check(
        (int)$db->query(
            'SELECT COUNT(*) AS total FROM community_decision_records WHERE source_topic_id=990020'
        )->fetch_assoc()['total'] === 1,
        'backfill safely skips installations without the legacy decision table'
    );

    $db->query('DELETE FROM community_decision_records WHERE source_topic_id=990020');
    $db->query('DROP TABLE IF EXISTS topics');
    fwrite(STDOUT, "OK: historical community decision backfill checks passed.\n");
} finally {
    $db->query('DROP TABLE IF EXISTS collective_knowledge_decisions');
    $db->query('DROP TABLE IF EXISTS topics');
    $db->query('DELETE FROM community_decision_records WHERE source_topic_id=990020');
    $db->close();
}
?>
