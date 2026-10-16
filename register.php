<?php
// register.php
session_start();
include "db.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $national_id = trim($_POST['national_id'] ?? '');
    $password = $_POST['password'] ?? '';

    // ساده‌سازی اعتبارسنجی (می‌تونی بعداً قوی‌ترش کنی)
    if ($username === '' || $phone === '' || $national_id === '' || $password === '') {
        die("لطفاً همه فیلدها را پر کنید.");
    }

    // بررسی تکراری بودن شماره یا ملی
    $check_stmt = $conn->prepare("SELECT id, phone, national_id FROM users WHERE phone = ? OR national_id = ? LIMIT 1");
    if (!$check_stmt) {
        die("خطا در آماده‌سازی کوئری: " . $conn->error);
    }
    $check_stmt->bind_param("ss", $phone, $national_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $existing_user = $result->fetch_assoc();
        if ($existing_user['phone'] === $phone) {
            die("این شماره همراه قبلاً ثبت شده است.");
        }
        if ($existing_user['national_id'] === $national_id) {
            die("این شماره ملی قبلاً ثبت شده است.");
        }
    }

    // هش کردن رمز عبور
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // درج کاربر جدید
    $insert_stmt = $conn->prepare("INSERT INTO users (username, phone, national_id, password) VALUES (?, ?, ?, ?)");
    if (!$insert_stmt) {
        die("خطا در آماده‌سازی کوئری درج: " . $conn->error);
    }
    $insert_stmt->bind_param("ssss", $username, $phone, $national_id, $hashed_password);

    if ($insert_stmt->execute()) {
        // بعد از ثبت، هدایت به صفحه ورود
        header("Location: login.php");
        exit;
    } else {
        die("خطا در ثبت کاربر: " . $conn->error);
    }

} else {
    // نمایش فرم — قبل از خروج از بلوک PHP تکه‌ی HTML را چاپ می‌کنیم
    // گرفتن آمار ساده سایت
    $communities_count = 0;
    $users_count = 0;
    $topics_count = 0;

    $r = $conn->query("SELECT COUNT(*) AS total FROM communities");
    if ($r) { $communities_count = (int)($r->fetch_assoc()['total'] ?? 0); }

    $r = $conn->query("SELECT COUNT(*) AS total FROM users");
    if ($r) { $users_count = (int)($r->fetch_assoc()['total'] ?? 0); }

    $r = $conn->query("SELECT COUNT(*) AS total FROM topics");
    if ($r) { $topics_count = (int)($r->fetch_assoc()['total'] ?? 0); }

    ?>
    <!DOCTYPE html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>ثبت نام — دولت عشق</title>
        <style>
            /* ساده و ریسپانسیو برای موبایل */
            :root { --accent: #2065d1; --muted: #bbb; --card: #fff; }
            body {
                font-family: Tahoma, sans-serif;
                background: #f2f5f8;
                margin: 0;
                padding: 14px;
                -webkit-text-size-adjust: 100%;
            }
            h2 { text-align:center; color: var(--accent); margin: 18px 0; }
            .wrap { max-width: 480px; margin: 0 auto; }
            .intro-box, .stats-box, form {
                background: var(--card);
                border-radius: 12px;
                box-shadow: 0 1px 6px rgba(0,0,0,0.04);
                padding: 14px;
                border: 1px solid #e0e6ee;
                margin-bottom: 14px;
            }
            .intro-box { border-color: #cfe7ff; background:#eaf4ff; }
            .stats-box { border-color: #ffe9a8; background:#fff8e1; }
            label { display:block; margin:6px 0 4px; font-size:14px; color:#333; }
            input[type="text"], input[type="password"] {
                width:100%; padding:12px; border-radius:8px; border:1px solid var(--muted);
                box-sizing:border-box; font-size:15px;
            }
            button {
                width:100%; padding:12px; margin-top:10px; border:none; border-radius:8px;
                background:var(--accent); color:#fff; font-size:16px; cursor:pointer;
            }
            button:active { transform: translateY(1px); }
            ul { padding-left: 20px; margin: 8px 0; }
            @media (max-width:420px){
                body { padding: 10px; }
                .wrap { padding: 0 6px; }
            }
        </style>
    </head>
    <body>
    <div class="wrap">
        <h2>ثبت نام</h2>

        <div class="intro-box">
            <h3 style="margin:0 0 8px 0">🌸 سامانه دولت عشق</h3>
            <p style="margin:0 0 8px 0; font-size:14px; line-height:1.5">
                پلتفرمی برای ثبت و اولویت‌بندی ایده‌ها. کاربران می‌توانند ایده ثبت کنند، رأی دهند و موضوعات مهم را دنبال کنند.
            </p>
            <ul>
                <li>ثبت ایده و مشارکت در بحث</li>
                <li>رأی‌دادن و تعیین اولویت</li>
                <li>گزارش آماری و شفافیت تصمیم‌گیری</li>
            </ul>
        </div>

        <div class="stats-box" style="font-size:14px;">
            <p style="margin:6px 0">🏘️ جوامع: <strong><?= htmlspecialchars($communities_count) ?></strong></p>
            <p style="margin:6px 0">👥 اعضا: <strong><?= htmlspecialchars($users_count) ?></strong></p>
            <p style="margin:6px 0">📘 موضوعات: <strong><?= htmlspecialchars($topics_count) ?></strong></p>
        </div>

        <form method="post" action="">
            <label for="username">نام و نام خانوادگی</label>
            <input type="text" id="username" name="username" placeholder="مثال: علی رضایی" required>

            <label for="phone">شماره همراه</label>
            <input type="text" id="phone" name="phone" placeholder="مثال: 09121234567" inputmode="tel" required>

            <label for="national_id">شماره ملی</label>
            <input type="text" id="national_id" name="national_id" placeholder="مثال: 1378205308" required>

            <label for="password">رمز عبور</label>
            <input type="password" id="password" name="password" placeholder="رمز عبور" required>

            <button type="submit">ثبت نام</button>
        </form>
    </div>
    </body>
    </html>

    <?php
    // پایان شاخه else و پایان کل فایل
}
?>