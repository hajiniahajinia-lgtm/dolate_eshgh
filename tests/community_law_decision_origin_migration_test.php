<?php
declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('DB_HOST') ?: '127.0.0.1';
$name = getenv('DB_NAME') ?: 'dolate_charter_ci';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$port = (int)(getenv('DB_PORT') ?: 3306);
$db = new mysqli($host, $user, $password, $name, $port);
$db->set_charset('utf8mb4');

function law_decision_origin_apply(mysqli $db, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException('Law decision-origin migration is missing or empty.');
    }
    $db->multi_query($sql);
    do {
        $result = $db->store_result();
        if ($result instanceof mysqli_result) $result->free();
    } while ($db->more_results() && $db->next_result());
}

try {
    $db->query(
        "CREATE TABLE IF NOT EXISTS community_law_versions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            source_idea_id INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $migration = __DIR__ . '/../migrations/20261108_community_law_decision_origin.sql';
    law_decision_origin_apply($db, $migration);
    law_decision_origin_apply($db, $migration);

    $column = $db->query(
        "SELECT COUNT(*) AS total FROM information_schema.columns
         WHERE table_schema=DATABASE() AND table_name='community_law_versions'
           AND column_name='source_decision_id'"
    )->fetch_assoc();
    if ((int)$column['total'] !== 1) {
        throw new RuntimeException('The optional source_decision_id column was not created exactly once.');
    }
    $index = $db->query(
        "SELECT COUNT(*) AS total FROM information_schema.statistics
         WHERE table_schema=DATABASE() AND table_name='community_law_versions'
           AND index_name='idx_law_versions_source_decision'"
    )->fetch_assoc();
    if ((int)$index['total'] !== 1) {
        throw new RuntimeException('The source-decision lookup index was not created exactly once.');
    }
    fwrite(STDOUT, "OK: law decision-origin migration is idempotent.\n");
} finally {
    $db->close();
}
?>
