<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function topic_intent_db_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function apply_topic_intent_sql(mysqli $db, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException("Missing migration: {$path}");
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
    $db->query('DROP TABLE IF EXISTS topics');
    $db->query("CREATE TABLE topics (
        id INT NOT NULL PRIMARY KEY,
        community_id INT NOT NULL,
        topic_purpose VARCHAR(20) NOT NULL DEFAULT 'mixed',
        closed TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("INSERT INTO topics (id,community_id,topic_purpose) VALUES
        (990101,77,'knowledge'),
        (990102,77,'execution'),
        (990103,77,'mixed')");

    $migration = __DIR__ . '/../migrations/20261109_topic_intent_classification.sql';
    apply_topic_intent_sql($db, $migration);
    apply_topic_intent_sql($db, $migration);

    $rows = $db->query(
        'SELECT id, topic_intent FROM topics ORDER BY id'
    )->fetch_all(MYSQLI_ASSOC);
    topic_intent_db_check(
        count($rows) === 3 &&
        $rows[0]['topic_intent'] === 'knowledge' &&
        $rows[1]['topic_intent'] === 'decision' &&
        $rows[2]['topic_intent'] === 'decision',
        'legacy topics are mapped to knowledge or decision; no old topic is silently classified as law'
    );

    $columns = $db->query(
        "SELECT COUNT(*) AS total FROM information_schema.columns
         WHERE table_schema=DATABASE() AND table_name='topics' AND column_name='topic_intent'"
    )->fetch_assoc();
    $indexes = $db->query(
        "SELECT COUNT(DISTINCT index_name) AS total FROM information_schema.statistics
         WHERE table_schema=DATABASE() AND table_name='topics' AND index_name='idx_topics_community_intent'"
    )->fetch_assoc();
    topic_intent_db_check((int)$columns['total'] === 1, 'topic intent column exists exactly once');
    topic_intent_db_check((int)$indexes['total'] === 1, 'topic intent index exists exactly once');

    $db->query("INSERT INTO topics (id,community_id,topic_purpose,topic_intent)
                VALUES (990104,77,'execution','law')");
    $law = $db->query("SELECT topic_intent FROM topics WHERE id=990104")->fetch_assoc();
    topic_intent_db_check($law && $law['topic_intent'] === 'law', 'new lawmaking topics persist their explicit track');

    echo "OK: topic intent migration and legacy mapping checks passed.\n";
} finally {
    $db->query('DROP TABLE IF EXISTS topics');
    $db->close();
}
?>
