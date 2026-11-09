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
    $result = $conn->query("SELECT migration_name, checksum, stage, started_at FROM app_schema_migration_recovery WHERE marker_id = 1 LIMIT 1");
    if (!$result) {
        throw new RuntimeException('Could not inspect migration recovery marker: ' . $conn->error);
    }
    $row = $result->fetch_assoc() ?: null;
    $result->free();
    return $row;
}

function migration_recovery_begin(mysqli $conn, string $name, string $checksum): void
{
    if (migration_recovery_active($conn) !== null) {
        throw new RuntimeException('A previous interrupted migration requires manual recovery');
    }
    $stage = 'executing';
    $stmt = $conn->prepare('INSERT INTO app_schema_migration_recovery (marker_id, migration_name, checksum, stage) VALUES (1, ?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare durable migration marker: ' . $conn->error);
    }
    $stmt->bind_param('sss', $name, $checksum, $stage);
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
