<?php
declare(strict_types=1);

$workflowPath = dirname(__DIR__) . '/.github/workflows/deploy.yml';
$workflow = file_get_contents($workflowPath);
if (!is_string($workflow) || $workflow === '') {
    fwrite(STDERR, "FAIL: deployment workflow is unavailable\n");
    exit(1);
}

$allowedMigrationBranch = strpos($workflow, "migrations/*.sql)");
$genericSqlExclusion = strpos($workflow, "|*.sql|");
if (
    $allowedMigrationBranch === false
    || $genericSqlExclusion === false
    || $allowedMigrationBranch >= $genericSqlExclusion
) {
    fwrite(STDERR, "FAIL: migration scripts must be explicitly allowed before generic SQL exclusions\n");
    exit(1);
}

foreach (['db.php', 'migration_config.php', '.env', '.env.*'] as $protectedPath) {
    if (strpos($workflow, $protectedPath) === false) {
        fwrite(STDERR, "FAIL: protected path is no longer excluded: {$protectedPath}\n");
        exit(1);
    }
}

fwrite(STDOUT, "OK: migration scripts are deployable; database credentials, runner token config, and environment files remain excluded\n");
