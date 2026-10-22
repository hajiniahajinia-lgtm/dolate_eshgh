<?php
declare(strict_types=1);

/*
 * Protected database migration runner.
 * This endpoint only accepts POST requests authenticated with a host-only token.
 */
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

function migration_response(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    migration_response(405, ['success' => false, 'message' => 'POST required']);
}

$config_path = __DIR__ . '/migration_config.php';
if (!is_file($config_path)) {
    migration_response(503, ['success' => false, 'message' => 'Migration runner is not configured on the host']);
}

$config = require $config_path;
$expected_token = is_array($config) ? (string)($config['token'] ?? '') : '';
$provided_token = (string)($_SERVER['HTTP_X_MIGRATION_TOKEN'] ?? '');

if (strlen($expected_token) < 32 || $provided_token === '' || !hash_equals($expected_token, $provided_token)) {
    migration_response(403, ['success' => false, 'message' => 'Forbidden']);
}

try {
    require __DIR__ . '/db.php';

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection is unavailable');
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS app_schema_migrations (
            migration_name VARCHAR(190) NOT NULL PRIMARY KEY,
            checksum CHAR(64) NOT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $directory = __DIR__ . '/migrations';
    $files = is_dir($directory) ? (glob($directory . '/*.sql') ?: []) : [];
    sort($files, SORT_STRING);

    $applied = [];
    $skipped = [];

    foreach ($files as $file) {
        $name = basename($file);

        // Only dated migration filenames are executed; full database dumps are not allowed here.
        if (!preg_match('/^[0-9]{8}_[A-Za-z0-9_-]+\.sql$/', $name)) {
            continue;
        }

        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('Could not read migration: ' . $name);
        }

        $sql = trim($sql);
        if ($sql === '') {
            continue;
        }

        $checksum = hash('sha256', $sql);
        $check = $conn->prepare('SELECT checksum FROM app_schema_migrations WHERE migration_name = ? LIMIT 1');
        $check->bind_param('s', $name);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();

        if ($existing) {
            if (!hash_equals((string)$existing['checksum'], $checksum)) {
                throw new RuntimeException('An already-applied migration file was changed: ' . $name);
            }
            $skipped[] = $name;
            continue;
        }

        // MySQL DDL is not generally transactional; migration files should be safe to retry.
        if (!$conn->multi_query($sql)) {
            throw new RuntimeException('SQL execution failed for migration: ' . $name . ' (' . $conn->error . ')');
        }

        do {
            $result = $conn->store_result();
            if ($result instanceof mysqli_result) {
                $result->free();
            }

            if ($conn->errno) {
                throw new RuntimeException('SQL execution failed for migration: ' . $name . ' (' . $conn->error . ')');
            }

            if ($conn->more_results()) {
                if (!$conn->next_result()) {
                    throw new RuntimeException('SQL execution failed for migration: ' . $name . ' (' . $conn->error . ')');
                }
            } else {
                break;
            }
        } while (true);

        $save = $conn->prepare('INSERT INTO app_schema_migrations (migration_name, checksum) VALUES (?, ?)');
        $save->bind_param('ss', $name, $checksum);
        $save->execute();
        $save->close();

        $applied[] = $name;
    }

    migration_response(200, [
        'success' => true,
        'applied' => $applied,
        'already_applied' => $skipped
    ]);
} catch (Throwable $e) {
    error_log('[database migrations] ' . $e->getMessage());
    migration_response(500, [
        'success' => false,
        'message' => 'Database migration failed. Check the hosting PHP error log.'
    ]);
}
