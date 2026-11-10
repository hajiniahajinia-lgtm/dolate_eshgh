<?php
// --- تنظیمات اتصال به دیتابیس ---
$db_host = 'localhost';
$db_user = 'root';       // نام کاربری دیتابیس
$db_pass = '';           // پسورد دیتابیس (مثلا '' یا '1234')
$db_name = 'dolate_eshgh';

// --- مسیر ذخیره بکاپ ---
$backup_dir = __DIR__ . '/backups';
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0777, true);
}

$date = date('Y-m-d_H-i-s');
$zip_file = $backup_dir . "/full_backup_{$date}.zip";

// --- ایجاد فایل ZIP ---
$zip = new ZipArchive();
if ($zip->open($zip_file, ZipArchive::CREATE) !== TRUE) {
    die("❌ خطا در ایجاد فایل ZIP");
}

// --- بکاپ فایل‌ها ---
$root_dir = __DIR__; // کل پروژه
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root_dir),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($files as $name => $file) {
    if ($file->isDir()) continue;
    $filePath = $file->getRealPath();
    $relativePath = substr($filePath, strlen($root_dir) + 1);
    $zip->addFile($filePath, $relativePath);
}

// --- بکاپ دیتابیس ---
$mysqli = @new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_error) {
    $zip->close();
    die("❌ خطا در اتصال به دیتابیس: " . $mysqli->connect_error);
}

// نام فایل SQL برای ذخیره در ZIP
$sql_file = "db_backup_{$date}.sql";
$sql_path = $backup_dir . '/' . $sql_file;

// استفاده از mysqldump اگر نصب شده
$mysqldump = "mysqldump -h {$db_host} -u {$db_user} " . ($db_pass ? "-p{$db_pass}" : "") . " {$db_name} > " . escapeshellarg($sql_path);
exec($mysqldump, $output, $return_var);

if ($return_var !== 0) {
    // اگر mysqldump کار نکرد، از روش دستی استفاده می‌کنیم
    $tables = $mysqli->query("SHOW TABLES");
    $sql_content = "";
    while ($row = $tables->fetch_row()) {
        $table = $row[0];
        $create = $mysqli->query("SHOW CREATE TABLE $table")->fetch_assoc();
        $sql_content .= $create['Create Table'] . ";\n\n";
        $data = $mysqli->query("SELECT * FROM $table");
        while ($r = $data->fetch_assoc()) {
            $vals = array_map([$mysqli, 'real_escape_string'], array_values($r));
            $sql_content .= "INSERT INTO $table (" . implode(',', array_keys($r)) . ") VALUES ('" . implode("','", $vals) . "');\n";
        }
        $sql_content .= "\n\n";
    }
    file_put_contents($sql_path, $sql_content);
}

// اضافه کردن فایل SQL به ZIP
$zip->addFile($sql_path, $sql_file);
$zip->close();

// پاک کردن فایل SQL جداگانه
@unlink($sql_path);

echo "✅ بکاپ کامل ایجاد شد: " . basename($zip_file);
?>