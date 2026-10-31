<?php
// TEMPORARY, READ-ONLY migration diagnostics.
// Does not include db.php, connect to MySQL, acquire locks, or execute SQL.
header('Content-Type: text/plain; charset=utf-8');

$dir = __DIR__ . '/migrations';
$target = $dir . '/20261030_expertise_weighting_core.sql';

echo "Migration diagnostics (filesystem only)\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "Current directory: " . __DIR__ . "\n";
echo "Migrations directory exists: " . (is_dir($dir) ? 'YES' : 'NO') . "\n";
echo "Target migration exists: " . (is_file($target) ? 'YES' : 'NO') . "\n";
echo "Target migration readable: " . (is_readable($target) ? 'YES' : 'NO') . "\n";

$files = glob($dir . '/[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]_*.sql');
if ($files === false) {
    echo "Migration glob: FAILED\n";
} else {
    sort($files, SORT_STRING);
    echo "Migration files found: " . count($files) . "\n";
    echo "Target in migration list: " . (in_array($target, $files, true) ? 'YES' : 'NO') . "\n";
}

echo "\nIMPORTANT: Delete this file immediately after the test.\n";
