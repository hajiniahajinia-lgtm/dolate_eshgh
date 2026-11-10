<?php
declare(strict_types=1);

/**
 * Durable guard for migrations that may partially execute because MySQL DDL
 * is not reliably transactional. A marker is removed only after the migration
 * SQL and its registry row have both completed successfully.
 */
function migration_recovery_ensure_table(mysqli $conn): void
{
    $sql = "CREATE TABLE IF NOT EXISTS app_schema_migration_recovery (
        marker_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        migration_name VARCHAR(190) NOT NULL,
        checksum CHAR(64) NOT NULL,
        snapshot_json LONGTEXT NOT NULL,
        stage VARCHAR(40) NOT NULL DEFAULT 'executing',
        started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_migration_recovery_started (started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!$conn->query($sql)) {
        throw new RuntimeException('Could not create durable migration recovery table: ' . $conn->error);
    }
}

function migration_recovery_active(mysqli $conn): ?array
{
    $result = $conn->query("SELECT migration_name, checksum, snapshot_json, stage, started_at FROM app_schema_migration_recovery WHERE marker_id = 1 LIMIT 1");
    if (!$result) {
        throw new RuntimeException('Could not inspect migration recovery marker: ' . $conn->error);
    }
    $row = $result->fetch_assoc() ?: null;
    $result->free();
    return $row;
}

function migration_recovery_snapshot(mysqli $conn): string
{
    $snapshot = [];
    $queries = [
        'tables' => "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME",
        'columns' => "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION",
        'indexes' => "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX",
        'registry' => "SELECT migration_name, checksum, applied_at FROM app_schema_migrations ORDER BY migration_name"
    ];
    foreach ($queries as $key => $sql) {
        $result = $conn->query($sql);
        if (!$result) {
            throw new RuntimeException('Could not capture pre-migration ' . $key . ' snapshot: ' . $conn->error);
        }
        $snapshot[$key] = [];
        while ($row = $result->fetch_assoc()) {
            $snapshot[$key][] = $row;
        }
        $result->free();
    }
    $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('Could not encode pre-migration schema snapshot');
    }
    return $encoded;
}

function migration_recovery_begin(mysqli $conn, string $name, string $checksum): void
{
    if (migration_recovery_active($conn) !== null) {
        throw new RuntimeException('A previous interrupted migration requires manual recovery');
    }
    $stage = 'executing';
    $snapshot = migration_recovery_snapshot($conn);
    $stmt = $conn->prepare('INSERT INTO app_schema_migration_recovery (marker_id, migration_name, checksum, snapshot_json, stage) VALUES (1, ?, ?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare durable migration marker: ' . $conn->error);
    }
    $stmt->bind_param('ssss', $name, $checksum, $snapshot, $stage);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Could not persist durable migration marker: ' . $error);
    }
    $stmt->close();
}

function migration_recovery_complete(mysqli $conn, string $name): void
{
    $stmt = $conn->prepare('DELETE FROM app_schema_migration_recovery WHERE marker_id = 1 AND migration_name = ?');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare migration marker completion: ' . $conn->error);
    }
    $stmt->bind_param('s', $name);
    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        $error = $stmt->error ?: 'recovery marker was not cleared exactly once';
        $stmt->close();
        throw new RuntimeException('Could not clear durable migration marker: ' . $error);
    }
    $stmt->close();
}
