<?php
// db.php
$host = "localhost";      // آدرس سرور
$user = "root";           // نام کاربری دیتابیس
$password = "";           // پسورد دیتابیس (اگر ندارد، خالی بگذارید)
$database = "dolate_eshgh"; // نام دیتابیس
$port = 3307;             // پورت MySQL که XAMPP روی آن تنظیم شده

$conn = new mysqli($host, $user, $password, $database, $port);

if ($conn->connect_error) {
    die("خطا در اتصال به دیتابیس: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4"); // UTF-8 برای فارسی
?>
   
   
   
   
   
   