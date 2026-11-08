<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function decision_separation_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function apply_decision_separation_sql(mysqli $db, string $path): void
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

$tables = [
    'collective_knowledge_decisions',
    'topic_enforce_votes',
    'topic_voting_ballots',
    'topic_winners',
    'topic_voting_meta',
    'ideas',
    'topics',
];

try {
    apply_decision_separation_sql(
        $db,
        __DIR__ . '/../migrations/20261108_community_decision_ledger.sql'
    );

    // حداقل شِمای لازم برای اجرای مسیر واقعی نهایی‌سازی؛ جدول کتابخانهٔ دانش عمداً ساخته نمی‌شود.
    $db->query("CREATE TABLE topics (
        id INT NOT NULL PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        description TEXT NOT NULL,
        user_id INT NULL,
        community_id INT NOT NULL,
        topic_purpose VARCHAR(20) NOT NULL DEFAULT 'mixed'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("CREATE TABLE ideas (
        id INT NOT NULL PRIMARY KEY,
        topic_id INT NOT NULL,
        content TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("CREATE TABLE topic_voting_meta (
        topic_id INT NOT NULL PRIMARY KEY,
        finalized TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("CREATE TABLE topic_winners (
        topic_id INT NOT NULL PRIMARY KEY,
        idea_id INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("CREATE TABLE topic_voting_ballots (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        topic_id INT NOT NULL,
        idea_id INT NOT NULL,
        vote_type ENUM('yes','no') NOT NULL,
        vote_weight DECIMAL(8,2) NOT NULL DEFAULT 1.00
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("CREATE TABLE topic_enforce_votes (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        topic_id INT NOT NULL,
        idea_id INT NOT NULL,
        enforceable TINYINT(1) NOT NULL
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
        finalized_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("INSERT INTO topics (id,title,description,user_id,community_id,topic_purpose)
                VALUES (990010,'موضوع آزمایشی تصمیم مستقل','شرح آزمایشی',5,77,'knowledge')");
    $db->query("INSERT INTO ideas (id,topic_id,content) VALUES (880010,990010,'ایدهٔ منتخب آزمایشی')");
    $db->query("INSERT INTO topic_voting_meta (topic_id,finalized) VALUES (990010,1)");
    $db->query("INSERT INTO topic_winners (topic_id,idea_id) VALUES (990010,880010)");
    $db->query("INSERT INTO topic_voting_ballots (topic_id,idea_id,vote_type,vote_weight)
                VALUES (990010,880010,'yes',2.00),(990010,880010,'no',1.00)");
    $db->query("INSERT INTO topic_enforce_votes (topic_id,idea_id,enforceable)
                VALUES (990010,880010,0)");

    require_once __DIR__ . '/../collective_knowledge_finalizer.php';

    // چون collective_knowledge_entries عمداً وجود ندارد، ساخت دانش شکست می‌خورد؛
    // اما دفتر تصمیم باید پیش از آن commit شده باشد.
    $result = create_collective_knowledge_decision($db, 990010, 5);
    decision_separation_check(
        $result === false,
        'knowledge creation failure is reported to the caller'
    );

    $stored = $db->query(
        'SELECT community_id, source_topic_id, source_idea_id, acceptance_status, enforceability_status
         FROM community_decision_records WHERE source_topic_id=990010'
    )->fetch_assoc();
    decision_separation_check(
        $stored &&
        (int)$stored['community_id'] === 77 &&
        (int)$stored['source_idea_id'] === 880010 &&
        $stored['acceptance_status'] === 'accepted' &&
        $stored['enforceability_status'] === 'not_enforceable',
        'the collective decision remains committed even when the knowledge library write fails'
    );

    fwrite(STDOUT, "OK: decision and knowledge persistence are transactionally separated.\n");
} finally {
    $db->query('DELETE FROM community_decision_records WHERE source_topic_id=990010');
    foreach ($tables as $table) {
        $db->query("DROP TABLE IF EXISTS `{$table}`");
    }
    $db->close();
}
?>
