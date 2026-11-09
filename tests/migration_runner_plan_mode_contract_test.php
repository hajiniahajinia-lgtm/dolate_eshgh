<?php
declare(strict_types=1);

$runner=file_get_contents(__DIR__.'/../migration_runner.php');
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
    "apply must release the shared migration lock" => strpos($runner, "RELEASE_LOCK('dolate_eshgh_schema_migrations'")!==false
];
foreach($checks as $label=>$passed) {
    if(!$passed) {
        fwrite(STDERR,"FAIL: {$label}\\n");
        exit(1);
    }
}
echo "PASS: protected migration runner defaults to read-only planning and requires explicit apply mode\\n";
