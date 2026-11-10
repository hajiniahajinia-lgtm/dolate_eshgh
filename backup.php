<?php
// backup.php
date_default_timezone_set('Asia/Tehran');

$projectName = 'dolate_eshgh';
$backupDir = __DIR__ . '/backups';
if (!file_exists($backupDir)) mkdir($backupDir, 0777, true);

$timestamp = date('Ymd_His');
$zipFile = "$backupDir/{$projectName}backup$timestamp.zip";

// --- تنظیمات دیتابیس ---
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = ''; // پسورد XAMPP
$dbName = 'dolate_eshgh';

// --- بکاپ دیتابیس ---
$dumpFile = "$backupDir/{$dbName}_$timestamp.sql";
$command = "mysqldump -h $dbHost -u $dbUser " . ($dbPass ? "-p$dbPass" : "") . " $dbName > \"$dumpFile\"";
exec($command, $output, $returnVar);

if ($returnVar !== 0) {
    die("❌ خطا در بکاپ دیتابیس. مطمئن شوید mysqldump نصب و در PATH است.");
}

// --- بکاپ فایل‌ها ---
$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE) !== TRUE) {
    die("❌ خطا در ساخت فایل ZIP");
}

// فولدر پروژه را اضافه می‌کنیم به جز خود فولدر بکاپ
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(_DIR_, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($files as $file) {
    $filePath = $file->getRealPath();
    if (strpos($filePath, $backupDir) === 0) continue; // skip backup folder
    $relativePath = substr($filePath, strlen(_DIR_) + 1);
    $zip->addFile($filePath, $relativePath);
}

$zip->close();

// --- اضافه کردن دیتابیس به ZIP ---
$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE) === TRUE) {
    $zip->addFile($dumpFile, basename($dumpFile));
    $zip->close();
}

// حذف فایل SQL بعد از اضافه شدن به ZIP
unlink($dumpFile);

echo "✅ بکاپ با موفقیت ساخته شد: <a href='" . basename($zipFile) . "'>دانلود ZIP</a>";
?>