<?php
/*
 * Host-side, idempotent SQL migration executor.
 * Include this file from the HOSTING copy of db.php after $conn is connected.
 * SQL files are executed only when their dated migration name has not been applied.
 */
if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
}

$migration_lock_acquired = false;

try {
    // Keep concurrent page requests from applying the same migration simultaneously.
    $lock_result = $conn->query("SELECT GET_LOCK('dolate_eshgh_schema_migrations', 5) AS got_lock");
    $lock_row = $lock_result ? $lock_result->fetch_assoc() : null;
    if ($lock_result instanceof mysqli_result) {
        $lock_result->free();
    }

    if ((int)($lock_row['got_lock'] ?? 0) !== 1) {
        return;
    }
    $migration_lock_acquired = true;

    $conn->query("
        CREATE TABLE IF NOT EXISTS app_schema_migrations (
            migration_name VARCHAR(190) NOT NULL PRIMARY KEY,
            checksum CHAR(64) NOT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $migration_dir = __DIR__;
    $migration_files = glob($migration_dir . '/[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]_*.sql') ?: [];
    sort($migration_files, SORT_STRING);

    foreach ($migration_files as $migration_file) {
        $migration_name = basename($migration_file);

        // Never execute full database exports or files with an unexpected name.
        if (!preg_match('/^[0-9]{8}_[A-Za-z0-9_-]+\.sql$/', $migration_name)) {
            continue;
        }

        $sql = file_get_contents($migration_file);
        if ($sql === false) {
            throw new RuntimeException('Could not read migration: ' . $migration_name);
        }

        $sql = trim($sql);
        if ($sql === '') {
            continue;
        }

        $checksum = hash('sha256', $sql);

        $check = $conn->prepare('SELECT checksum FROM app_schema_migrations WHERE migration_name = ? LIMIT 1');
        $check->bind_param('s', $migration_name);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();

        if ($existing) {
            if (!hash_equals((string)$existing['checksum'], $checksum)) {
                // Applied migration files are immutable. Make a new dated file for any correction.
                error_log('[Dolate Eshgh migrations] Applied migration was edited: ' . $migration_name);
                break;
            }
            continue;
        }

        if (!$conn->multi_query($sql)) {
            throw new RuntimeException('SQL execution failed for ' . $migration_name . ': ' . $conn->error);
        }

        do {
            $result = $conn->store_result();
            if ($result instanceof mysqli_result) {
                $result->free();
            }

            if ($conn->errno) {
                throw new RuntimeException('SQL execution failed for ' . $migration_name . ': ' . $conn->error);
            }

            if (!$conn->more_results()) {
                break;
            }

            if (!$conn->next_result()) {
                throw new RuntimeException('SQL execution failed for ' . $migration_name . ': ' . $conn->error);
            }
        } while (true);

        $save = $conn->prepare('INSERT INTO app_schema_migrations (migration_name, checksum) VALUES (?, ?)');
        $save->bind_param('ss', $migration_name, $checksum);
        $save->execute();
        $save->close();

        error_log('[Dolate Eshgh migrations] Applied: ' . $migration_name);
    }
} catch (Throwable $e) {
    // Do not expose database details or break the user's page; record details in the PHP error log.
    error_log('[Dolate Eshgh migrations] ' . $e->getMessage());
} finally {
    if ($migration_lock_acquired) {
        try {
            $conn->query("SELECT RELEASE_LOCK('dolate_eshgh_schema_migrations')");
        } catch (Throwable $ignored) {
            // Connection may have closed; nothing else to do.
        }
    }
}
