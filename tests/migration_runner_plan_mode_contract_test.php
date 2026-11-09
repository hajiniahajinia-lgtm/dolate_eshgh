<?php
declare(strict_types=1);

$runner=file_get_contents(__DIR__.'/../migration_runner.php');
$guard=file_get_contents(__DIR__.'/../migrations/recovery_guard.php');
$auto=file_get_contents(__DIR__.'/../migrations/auto_apply.php');
if(!is_string($runner)) {
    fwrite(STDERR,"FAIL: protected migration runner is missing\\n");
    exit(1);
}
$checks=[
    "default mode must be read-only plan" => strpos($runner, "$"."request_payload['mode'] ?? 'plan'")!==false,
    "plan mode must be supported" => strpos($runner, "if ($"."migration_mode === 'plan')")!==false,
    "plan must report that no database changes were made" => strpos($runner, "'database_changes_made' => false")!==false,
    "plan must report pending migrations" => strpos($runner, "'pending' => $"."pending")!==false,
    "plan must detect checksum mismatches" => strpos($runner, "'checksum_mismatches' => $"."checksum_mismatches")!==false,
    "apply mode must be explicit" => strpos($runner, "in_array($"."migration_mode, ['plan', 'apply'], true)")!==false,
    "registry creation must occur after the read-only plan branch" => strpos($runner, "if ($"."migration_mode === 'plan')") < strpos($runner, "CREATE TABLE IF NOT EXISTS app_schema_migrations"),
    "plan must check the exact registry table name" => strpos($runner, "table_name = 'app_schema_migrations'")!==false,
    "apply must acquire the shared migration lock" => strpos($runner, "GET_LOCK('dolate_eshgh_schema_migrations'")!==false,
    "apply must release the shared migration lock" => strpos($runner, "RELEASE_LOCK('dolate_eshgh_schema_migrations'")!==false,
    "runner must write a durable marker before executing SQL" => strpos($runner, "migration_recovery_begin($"."conn, $"."name, $"."checksum)") < strpos($runner, "if (!$"."conn->multi_query($"."sql))"),
    "runner must clear marker only after registry success" => strpos($runner, "migration_recovery_complete($"."conn, $"."name)") > strpos($runner, "$"."save->execute()"),
    "plan must block when an interrupted marker exists" => strpos($runner, "'recovery_required' => true")!==false,
    "runner must use shared recovery guard" => strpos($runner, "recovery_guard.php")!==false,
    "apply must preflight all historical checksums before migration loop" => strpos($runner, "$"."preflight_mismatches") < strpos($runner, "// Only dated migration filenames are executed"),
    "durable marker table must be created before migrations" => is_string($guard) && strpos($guard, "CREATE TABLE IF NOT EXISTS app_schema_migration_recovery")!==false,
    "durable marker must be persisted before migration SQL" => is_string($guard) && strpos($guard, "INSERT INTO app_schema_migration_recovery")!==false,
    "marker must include a pre-migration schema and registry snapshot" => is_string($guard) && strpos($guard, "migration_recovery_snapshot")!==false && strpos($guard, "snapshot_json LONGTEXT NOT NULL")!==false && strpos($guard, "SELECT migration_name, checksum, snapshot_json")!==false,
    "marker must be cleared only by explicit completion helper" => is_string($guard) && strpos($guard, "DELETE FROM app_schema_migration_recovery")!==false,
    "legacy auto-apply must not execute migrations on page requests" => is_string($auto) && strpos($auto, "return;")!==false && strpos($auto, "multi_query")===false
];
foreach($checks as $label=>$passed) {
    if(!$passed) {
        fwrite(STDERR,"FAIL: {$label}\\n");
        exit(1);
    }
}
echo "PASS: protected migration runner defaults to read-only planning and requires explicit apply mode\\n";
