<?php
declare(strict_types=1);
require_once __DIR__ . '/../migrations/recovery_guard.php';

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('DB_PORT') ?: 3306);
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$dbName = 'dolate_recovery_guard_ci';

$admin = new mysqli($host, $user, $password, '', $port);
if ($admin->connect_errno) {
    fwrite(STDERR, "FAIL: MySQL connection: " . $admin->connect_error . "\n");
    exit(1);
}
$admin->query("DROP DATABASE IF EXISTS " . $dbName);
if (!$admin->query("CREATE DATABASE " . $dbName . " CHARACTER SET utf8mb4")) {
    fwrite(STDERR, "FAIL: create isolated database: " . $admin->error . "\n");
    exit(1);
}

try {
    $conn = new mysqli($host, $user, $password, $dbName, $port);
    if ($conn->connect_errno) throw new RuntimeException($conn->connect_error);
    $conn->set_charset('utf8mb4');
    $conn->query("CREATE TABLE app_schema_migrations (
        migration_name VARCHAR(190) NOT NULL PRIMARY KEY,
        checksum CHAR(64) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE sample_before (
        id INT NOT NULL PRIMARY KEY,
        title VARCHAR(80) NOT NULL
    ) ENGINE=InnoDB");
    $conn->query("INSERT INTO app_schema_migrations (migration_name, checksum) VALUES ('20260101_seed.sql', REPEAT('a', 64))");

    migration_recovery_ensure_table($conn);
    migration_recovery_begin($conn, '20260102_partial.sql', str_repeat('b', 64));
    $marker = migration_recovery_active($conn);
    if (!$marker || $marker['migration_name'] !== '20260102_partial.sql') {
        throw new RuntimeException('durable marker was not persisted');
    }
    $snapshot = json_decode((string)$marker['snapshot_json'], true);
    if (!is_array($snapshot) || !isset($snapshot['tables'], $snapshot['columns'], $snapshot['indexes'], $snapshot['registry'])) {
        throw new RuntimeException('pre-migration snapshot is incomplete');
    }
    if (!in_array('sample_before', array_column($snapshot['tables'], 'TABLE_NAME'), true)) {
        throw new RuntimeException('snapshot does not contain the pre-existing table');
    }
    if (count($snapshot['registry']) !== 1 || $snapshot['registry'][0]['migration_name'] !== '20260101_seed.sql') {
        throw new RuntimeException('snapshot does not contain the migration registry state');
    }

    $blocked = false;
    try {
        migration_recovery_begin($conn, '20260103_next.sql', str_repeat('c', 64));
    } catch (RuntimeException $expected) {
        $blocked = true;
    }
    if (!$blocked) throw new RuntimeException('second migration was not blocked by active recovery marker');

    migration_recovery_complete($conn, '20260102_partial.sql');
    if (migration_recovery_active($conn) !== null) {
        throw new RuntimeException('completed marker was not cleared');
    }
    $conn->close();
    echo "PASS: durable recovery marker, schema/registry snapshot, blocking, and completion\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    $admin->query("DROP DATABASE IF EXISTS " . $dbName);
    $admin->close();
}
