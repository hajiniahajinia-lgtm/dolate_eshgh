<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function law_origin_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$name = getenv('DB_NAME') ?: 'dolate_charter_ci';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$port = (int)(getenv('DB_PORT') ?: 3306);
$db = new mysqli($host, $user, $password, $name, $port);
$db->set_charset('utf8mb4');

try {
    $db->query('DROP TABLE IF EXISTS community_decision_records');
    $db->query('DROP TABLE IF EXISTS ideas');
    $db->query('DROP TABLE IF EXISTS topics');
    $db->query("CREATE TABLE topics (
        id INT NOT NULL PRIMARY KEY,
        community_id INT NOT NULL,
        topic_intent ENUM('knowledge','decision','law') NOT NULL
    ) ENGINE=InnoDB");
    $db->query("CREATE TABLE ideas (
        id INT NOT NULL PRIMARY KEY,
        topic_id INT NOT NULL
    ) ENGINE=InnoDB");
    $db->query("CREATE TABLE community_decision_records (
        id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        community_id INT NOT NULL,
        source_topic_id INT NOT NULL,
        source_idea_id INT NULL,
        topic_intent_snapshot ENUM('knowledge','decision','law') NOT NULL,
        acceptance_status ENUM('accepted','not_accepted','no_votes') NOT NULL,
        record_status ENUM('recorded','superseded','voided') NOT NULL
    ) ENGINE=InnoDB");

    $db->query("INSERT INTO topics VALUES
        (990301,77,'law'),(990302,77,'law'),(990303,78,'law'),(990304,77,'decision')");
    $db->query("INSERT INTO ideas VALUES
        (880301,990301),(880302,990302),(880303,990303),(880304,990304)");
    $db->query("INSERT INTO community_decision_records VALUES
        (770301,77,990301,880301,'law','accepted','recorded'),
        (770302,77,990302,880302,'law','not_accepted','recorded'),
        (770303,78,990303,880303,'law','accepted','recorded'),
        (770304,77,990304,880304,'decision','accepted','recorded'),
        (770305,77,990301,880302,'law','accepted','recorded'),
        (770306,77,990301,NULL,'law','accepted','recorded'),
        (770307,77,990301,880301,'law','accepted','voided'),
        (770308,77,990301,880301,'knowledge','accepted','recorded')");

    require_once __DIR__ . '/../community_law_helpers.php';

    $valid = community_law_resolve_accepted_lawmaking_origin($db, 77, 770301);
    law_origin_check(
        $valid !== null &&
        (int)$valid['source_topic_id'] === 990301 &&
        (int)$valid['source_idea_id'] === 880301,
        'a matching accepted lawmaking decision resolves to its own topic and selected idea'
    );

    foreach ([770302,770303,770304,770305,770306,770307,770308,999999] as $invalidId) {
        law_origin_check(
            community_law_resolve_accepted_lawmaking_origin($db, 77, $invalidId) === null,
            "invalid or mismatched provenance is rejected for decision {$invalidId}"
        );
    }
    law_origin_check(
        community_law_resolve_accepted_lawmaking_origin($db, 78, 770301) === null,
        'a decision from another community cannot be reused'
    );
    law_origin_check(
        community_law_resolve_accepted_lawmaking_origin($db, 0, 770301) === null,
        'invalid community identifiers are rejected'
    );

    fwrite(STDOUT, "OK: accepted lawmaking provenance integration checks passed.\n");
} finally {
    $db->query('DROP TABLE IF EXISTS community_decision_records');
    $db->query('DROP TABLE IF EXISTS ideas');
    $db->query('DROP TABLE IF EXISTS topics');
    $db->close();
}
?>
