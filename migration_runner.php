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

$request_payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($request_payload)) {
    $request_payload = [];
}
$migration_mode = (string)($request_payload['mode'] ?? 'plan');
if (!in_array($migration_mode, ['plan', 'apply'], true)) {
    migration_response(400, ['success' => false, 'message' => 'Invalid migration mode']);
}

try {
    require __DIR__ . '/db.php';

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection is unavailable');
    }

    $directory = __DIR__ . '/migrations';
    $files = is_dir($directory) ? (glob($directory . '/*.sql') ?: []) : [];
    sort($files, SORT_STRING);

    // Respect explicit schema prerequisites where filename order is insufficient.
    $migration_dependencies = [
        '20261108_community_decision_ledger.sql' => [
            '20261108_community_decision_knowledge_link.sql'
        ],
        '20261109_community_law_corrective_completion_reviews.sql' => [
            '20261109_community_law_corrective_completion_retries.sql'
        ],
        '20261031_topic_expertise_proposals.sql' => [
            '20261031_topic_expertise_proposal_voting.sql'
        ]
    ];
    foreach ($migration_dependencies as $prerequisite => $dependents) {
        $prerequisite_path = null;
        foreach ($files as $candidate_path) {
            if (basename($candidate_path) === $prerequisite) {
                $prerequisite_path = $candidate_path;
                break;
            }
        }
        if ($prerequisite_path === null) continue;
        foreach ($dependents as $dependent) {
            $dependent_index = null;
            foreach ($files as $index => $candidate_path) {
                if (basename($candidate_path) === $dependent) {
                    $dependent_index = $index;
                    break;
                }
            }
            if ($dependent_index === null) continue;
            $dependent_path = $files[$dependent_index];
            array_splice($files, $dependent_index, 1);
            $prerequisite_index = array_search($prerequisite_path, $files, true);
            if ($prerequisite_index !== false) {
                array_splice($files, $prerequisite_index + 1, 0, [$dependent_path]);
            }
        }
    }

    if ($migration_mode === 'plan') {
        $registry_result = $conn->query("SHOW TABLES LIKE 'app_schema_migrations'");
        if (!$registry_result || $registry_result->num_rows === 0) {
            migration_response(409, [
                'success' => false,
                'mode' => 'plan',
                'message' => 'Migration registry is missing; no database changes were made.',
                'database_changes_made' => false
            ]);
        }

        $registry_result->free();
        $registry_rows = $conn->query('SELECT migration_name, checksum FROM app_schema_migrations');
        if (!$registry_rows) {
            throw new RuntimeException('Could not read migration registry');
        }
        $registry = [];
        while ($registry_row = $registry_rows->fetch_assoc()) {
            $registry[(string)$registry_row['migration_name']] = (string)$registry_row['checksum'];
        }
        $registry_rows->free();

        $pending = [];
        $already_applied = [];
        $checksum_mismatches = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (!preg_match('/^[0-9]{8}_[A-Za-z0-9_-]+\\.sql$/', $name)) continue;
            $sql = file_get_contents($file);
            if ($sql === false) throw new RuntimeException('Could not read migration: ' . $name);
            $sql = trim($sql);
            if ($sql === '') continue;
            $checksum = hash('sha256', $sql);
            if (!array_key_exists($name, $registry)) {
                $pending[] = $name;
            } elseif (!hash_equals($registry[$name], $checksum)) {
                $checksum_mismatches[] = $name;
            } else {
                $already_applied[] = $name;
            }
        }

        migration_response($checksum_mismatches ? 409 : 200, [
            'success' => count($checksum_mismatches) === 0,
            'mode' => 'plan',
            'pending' => $pending,
            'already_applied' => $already_applied,
            'checksum_mismatches' => $checksum_mismatches,
            'database_changes_made' => false
        ]);
    }

    // Registry creation is allowed only in explicit apply mode.
    $conn->query("
        CREATE TABLE IF NOT EXISTS app_schema_migrations (
            migration_name VARCHAR(190) NOT NULL PRIMARY KEY,
            checksum CHAR(64) NOT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

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
