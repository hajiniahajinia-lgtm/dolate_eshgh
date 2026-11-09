<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function implementation_check(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function apply_implementation_sql(mysqli $db, string $path): void {
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
    apply_implementation_sql(
        $db,
        __DIR__ . '/../migrations/20261106_community_law_objections_schema_repair.sql'
    );
    apply_implementation_sql(
        $db,
        __DIR__ . '/../migrations/20261107_community_law_framework.sql'
    );
    require_once __DIR__ . '/../community_law_helpers.php';

    $db->begin_transaction();
    implementation_check(
        community_law_initialize_implementations($db, 501, 9001, 77, [10, 20, 20, 30]),
        'implementation records are initialized for every unique scoped community'
    );
    $db->commit();

    $rows = $db->query(
        "SELECT community_id, status
         FROM community_law_implementations
         WHERE law_version_id=9001 ORDER BY community_id"
    );
    $actual = [];
    while ($row = $rows->fetch_assoc()) $actual[] = $row;
    $rows->free();
    implementation_check(
        array_map('intval', array_column($actual, 'community_id')) === [10, 20, 30],
        'duplicate community ids do not create duplicate implementation records'
    );
    implementation_check(
        count(array_filter($actual, static fn($row) => $row['status'] === 'pending')) === 3,
        'new implementation records start in pending status'
    );

    $historyCount = (int)$db->query(
        "SELECT COUNT(*) AS total FROM community_law_history
         WHERE law_version_id=9001 AND event_type='implementation_initialized'"
    )->fetch_assoc()['total'];
    implementation_check($historyCount === 3, 'each new implementation record has an audit history event');

    $db->query(
        "UPDATE community_law_implementations SET status='completed'
         WHERE law_version_id=9001 AND community_id=20"
    );
    $db->begin_transaction();
    implementation_check(
        community_law_initialize_implementations($db, 501, 9001, 77, [10, 20, 30]),
        're-initialization is safe and idempotent'
    );
    $db->commit();

    $preserved = $db->query(
        "SELECT status FROM community_law_implementations
         WHERE law_version_id=9001 AND community_id=20"
    )->fetch_assoc();
    implementation_check(
        $preserved && $preserved['status'] === 'completed',
        're-initialization never overwrites an existing implementation status'
    );
    $historyAfterRetry = (int)$db->query(
        "SELECT COUNT(*) AS total FROM community_law_history
         WHERE law_version_id=9001 AND event_type='implementation_initialized'"
    )->fetch_assoc()['total'];
    implementation_check($historyAfterRetry === 3, 'idempotent retry does not duplicate initialization history');

    $db->begin_transaction();
    implementation_check(
        !community_law_initialize_implementations($db, 501, 9001, 77, [40, 0]),
        'invalid community ids fail closed'
    );
    $db->rollback();
    $invalidRows = (int)$db->query(
        "SELECT COUNT(*) AS total FROM community_law_implementations
         WHERE law_version_id=9001 AND community_id=40"
    )->fetch_assoc()['total'];
    implementation_check($invalidRows === 0, 'invalid input creates no partial implementation records');

    fwrite(STDOUT, "OK: community law implementation integration checks passed.\n");
} finally {
    $db->close();
}
?>
