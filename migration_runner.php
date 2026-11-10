<?php
declare(strict_types=1);

/*
 * Protected database migration runner.
 * This endpoint only accepts POST requests authenticated with a host-only token.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
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
$provided_token = '';
$encoded_token = (string)($_SERVER['HTTP_X_MIGRATION_TOKEN_BASE64'] ?? '');
if ($encoded_token !== '') {
    $decoded_token = base64_decode($encoded_token, true);
    if ($decoded_token !== false) {
        $provided_token = $decoded_token;
    }
} else {
    // Backward compatibility for existing ASCII-only clients.
    $provided_token = (string)($_SERVER['HTTP_X_MIGRATION_TOKEN'] ?? '');
}

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

$migration_lock_acquired = false;
require_once __DIR__ . '/migrations/recovery_guard.php';

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
        '20261106_community_law_objections_schema_repair.sql' => [
            '20261107_community_law_framework.sql'
        ],
        '20261107_community_law_framework.sql' => [
            '20261107_community_law_final_review_appeals.sql'
        ],
        '20261107_community_charter_foundation.sql' => [
            '20261107_community_charter_amendments.sql'
        ],
        '20261107_community_charter_amendments.sql' => [
            '20261110_community_charter_initial_adoption.sql'
        ],
        '20261107_community_law_voting_rules.sql' => [
            '20261107_community_law_voting_rule_approval.sql'
        ],
        '20261108_community_decision_ledger.sql' => [
            '20261108_community_decision_knowledge_link.sql'
        ],
        '20261108_community_law_change_control.sql' => [
            '20261108_community_law_change_review.sql'
        ],
        '20261108_community_law_change_review.sql' => [
            '20261108_community_law_change_voting.sql'
        ],
        '20261108_community_law_change_voting.sql' => [
            '20261108_community_law_change_effects.sql'
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
        $recovery_table_result = $conn->query("SELECT COUNT(*) AS table_count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'app_schema_migration_recovery'");
        $recovery_table_row = $recovery_table_result ? $recovery_table_result->fetch_assoc() : null;
        if ($recovery_table_result instanceof mysqli_result) $recovery_table_result->free();
        $recovery_marker = null;
        if ((int)($recovery_table_row['table_count'] ?? 0) === 1) {
            $recovery_marker = migration_recovery_active($conn);
        }
        if ($recovery_marker !== null) {
            migration_response(409, [
                'success' => false,
                'mode' => 'plan',
                'message' => 'A previous migration was interrupted. Manual recovery is required; no migration will be retried.',
                'recovery_required' => true,
                'recovery_marker' => $recovery_marker,
                'database_changes_made' => false
            ]);
        }

        $registry_result = $conn->query("SELECT COUNT(*) AS table_count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'app_schema_migrations'");
        $registry_row = $registry_result ? $registry_result->fetch_assoc() : null;
        if ($registry_result instanceof mysqli_result) $registry_result->free();
        if ((int)($registry_row['table_count'] ?? 0) !== 1) {
            migration_response(409, [
                'success' => false,
                'mode' => 'plan',
                'message' => 'Migration registry is missing; no database changes were made.',
                'database_changes_made' => false
            ]);
        }

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

    // Serialize apply requests; MySQL DDL cannot be rolled back reliably.
    $lock_result = $conn->query("SELECT GET_LOCK('dolate_eshgh_schema_migrations', 5) AS got_lock");
    $lock_row = $lock_result ? $lock_result->fetch_assoc() : null;
    if ($lock_result instanceof mysqli_result) $lock_result->free();
    if ((int)($lock_row['got_lock'] ?? 0) !== 1) {
        throw new RuntimeException('Could not acquire migration lock; another migration may be running');
    }
    $migration_lock_acquired = true;

    // Registry creation is allowed only in explicit apply mode.
    if (!$conn->query("
        CREATE TABLE IF NOT EXISTS app_schema_migrations (
            migration_name VARCHAR(190) NOT NULL PRIMARY KEY,
            checksum CHAR(64) NOT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ")) {
        throw new RuntimeException('Could not create migration registry: ' . $conn->error);
    }
    migration_recovery_ensure_table($conn);
    $existing_recovery = migration_recovery_active($conn);
    if ($existing_recovery !== null) {
        throw new RuntimeException('Interrupted migration requires documented manual recovery: ' . (string)$existing_recovery['migration_name']);
    }

    // Validate every already-applied checksum before executing ANY pending migration.
    // This prevents an explicit apply request from partially progressing before
    // discovering a changed historical migration later in the list.
    $preflight_rows = $conn->query('SELECT migration_name, checksum FROM app_schema_migrations');
    if (!$preflight_rows) {
        throw new RuntimeException('Could not preflight migration registry');
    }
    $preflight_registry = [];
    while ($preflight_row = $preflight_rows->fetch_assoc()) {
        $preflight_registry[(string)$preflight_row['migration_name']] = (string)$preflight_row['checksum'];
    }
    $preflight_rows->free();

    $preflight_mismatches = [];
    foreach ($files as $preflight_file) {
        $preflight_name = basename($preflight_file);
        if (!preg_match('/^[0-9]{8}_[A-Za-z0-9_-]+\\.sql$/', $preflight_name)) continue;
        $preflight_sql = file_get_contents($preflight_file);
        if ($preflight_sql === false) {
            throw new RuntimeException('Could not read migration during preflight: ' . $preflight_name);
        }
        $preflight_sql = trim($preflight_sql);
        if ($preflight_sql === '' || !array_key_exists($preflight_name, $preflight_registry)) continue;
        $preflight_checksum = hash('sha256', $preflight_sql);
        if (!hash_equals($preflight_registry[$preflight_name], $preflight_checksum)) {
            $preflight_mismatches[] = $preflight_name;
        }
    }
    if ($preflight_mismatches) {
        throw new RuntimeException('Preflight blocked: applied migration checksum mismatch: ' . implode(', ', $preflight_mismatches));
    }

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

        // Persist a recovery marker BEFORE the first statement. MySQL DDL may auto-commit.
        migration_recovery_begin($conn, $name, $checksum);
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
        migration_recovery_complete($conn, $name);

        $applied[] = $name;
    }

    if ($migration_lock_acquired) {
        $conn->query("SELECT RELEASE_LOCK('dolate_eshgh_schema_migrations')");
        $migration_lock_acquired = false;
    }

    migration_response(200, [
        'success' => true,
        'applied' => $applied,
        'already_applied' => $skipped
    ]);
} catch (Throwable $e) {
    if ($migration_lock_acquired && isset($conn) && $conn instanceof mysqli) {
        try { $conn->query("SELECT RELEASE_LOCK('dolate_eshgh_schema_migrations')"); } catch (Throwable $ignored) {}
    }
    error_log('[database migrations] ' . $e->getMessage());
    migration_response(500, [
        'success' => false,
        'message' => 'Database migration failed. Check the hosting PHP error log.'
    ]);
}
