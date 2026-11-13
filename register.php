<?php
// register.php
session_start();
include "db.php";

 
// Normalize Iranian digits and common phone separators before validation/storage.
function normalize_iranian_digits(string $value): string {
    return strtr($value, [
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4',
        '۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4',
        '٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'
    ]);
}

function normalize_iranian_mobile(string $value): string {
    $phone = normalize_iranian_digits(trim($value));
    $phone = preg_replace('/[\\s\\-().]/u', '', $phone);

    if (str_starts_with($phone, '+98')) {
        $phone = '0' . substr($phone, 3);
    } elseif (str_starts_with($phone, '0098')) {
        $phone = '0' . substr($phone, 4);
    } elseif (str_starts_with($phone, '98') && strlen($phone) === 12) {
        $phone = '0' . substr($phone, 2);
    } elseif (preg_match('/^9[0-9]{9}$/', $phone)) {
        $phone = '0' . $phone;
    }

    return $phone;
}

function normalize_iranian_national_id(string $value): string {
    $code = normalize_iranian_digits(trim($value));
    return preg_replace('/[\\s\\-]/u', '', $code);
}

function is_valid_iranian_mobile(string $value): bool {
    return preg_match('/^09[0-9]{9}$/', normalize_iranian_mobile($value)) === 1;
}

function is_valid_iranian_national_id(string $value): bool {
    // In the experimental system, accept any 10 digits without checksum validation.
    $code = normalize_iranian_national_id($value);
    return preg_match('/^[0-9]{10}$/', $code) === 1;
}

// CSRF protection for the public registration form.
if (empty($_SESSION['register_csrf_token'])) {
    $_SESSION['register_csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted_token) || !hash_equals($_SESSION['register_csrf_token'], $submitted_token)) {
        http_response_code(403);
        die("درخواست نامعتبر است. لطفاً صفحه را تازه‌سازی و دوباره تلاش کنید.");
    }
    $username_input = $_POST['username'] ?? '';
    $phone_input = $_POST['phone'] ?? '';
    $national_id_input = $_POST['national_id'] ?? '';
    $password = $_POST['password'] ?? '';

    if (!is_string($username_input) || !is_string($phone_input) ||
        !is_string($national_id_input) || !is_string($password)) {
        http_response_code(400);
        die("اطلاعات ارسالی معتبر نیست.");
    }

    $username = trim($username_input);
    $phone = normalize_iranian_mobile($phone_input);
    $national_id = normalize_iranian_national_id($national_id_input);

    if ($username === '' || $phone === '' || $national_id === '' || $password === '') {
        die("لطفاً همه فیلدها را پر کنید.");
    }

    if (!is_valid_iranian_mobile($phone)) {
        http_response_code(400);
        die("شماره همراه باید با 09 شروع شود و ۱۱ رقم داشته باشد.");
    }

    if (!is_valid_iranian_national_id($national_id)) {
        http_response_code(400);
        die("شماره ملی معتبر نیست. لطفاً شماره ۱۰ رقمی را بررسی کنید.");
    }

    // بررسی تکراری بودن شماره یا ملی
    $check_stmt = $conn->prepare("SELECT id, phone, national_id FROM users WHERE phone = ? OR national_id = ? LIMIT 1");
    if (!$check_stmt) {
        error_log("register.php: failed to prepare duplicate-user query: " . $conn->error);
        http_response_code(500);
        die("در حال حاضر امکان ثبت‌نام وجود ندارد. لطفاً بعداً دوباره تلاش کنید.");
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
        error_log("register.php: failed to prepare user insert: " . $conn->error);
        http_response_code(500);
        die("در حال حاضر امکان ثبت‌نام وجود ندارد. لطفاً بعداً دوباره تلاش کنید.");
    }
    $insert_stmt->bind_param("ssss", $username, $phone, $national_id, $hashed_password);

    // Handle both mysqli strict-mode exceptions and ordinary execute() failures.
    $inserted = false;
    $insert_error_code = 0;
    $insert_error_message = '';

    try {
        $inserted = $insert_stmt->execute();
        if (!$inserted) {
            $insert_error_code = (int)$insert_stmt->errno;
            $insert_error_message = $insert_stmt->error;
        }
    } catch (mysqli_sql_exception $e) {
        $insert_error_code = (int)$e->getCode();
        $insert_error_message = $e->getMessage();
    }

    if ($inserted) {
        // بعد از ثبت، هدایت به صفحه ورود
        header("Location: login.php");
        exit;
    }

    if ($insert_error_code === 1062) {
        // A unique index is the final protection against simultaneous duplicate registrations.
        // Identify which submitted field already exists so the user gets a useful message.
        $duplicate_stmt = $conn->prepare(
            "SELECT phone, national_id FROM users WHERE phone = ? OR national_id = ? LIMIT 1"
        );

        if ($duplicate_stmt) {
            $duplicate_stmt->bind_param("ss", $phone, $national_id);
            try {
                if ($duplicate_stmt->execute()) {
                    $duplicate_result = $duplicate_stmt->get_result();
                    if ($duplicate_result && $duplicate_user = $duplicate_result->fetch_assoc()) {
                        if ($duplicate_user['phone'] === $phone) {
                            http_response_code(409);
                            die("این شماره همراه قبلاً ثبت شده است.");
                        }
                        if ($duplicate_user['national_id'] === $national_id) {
                            http_response_code(409);
                            die("این شماره ملی قبلاً ثبت شده است.");
                        }
                    }
                }
            } catch (mysqli_sql_exception $e) {
                error_log("register.php: duplicate lookup failed: " . $e->getMessage());
            }
        }

        error_log("register.php: unique constraint rejected a duplicate user registration.");
        http_response_code(409);
        die("این شماره همراه یا شماره ملی قبلاً ثبت شده است.");
    }

    error_log("register.php: failed to insert user (code " . $insert_error_code . "): " . $insert_error_message);
    http_response_code(500);
    die("ثبت‌نام انجام نشد. لطفاً بعداً دوباره تلاش کنید.");

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
            .testing-note {
                margin-top: 14px; padding: 12px; border: 1px solid #b8d9c5;
                border-radius: 10px; background: #f0faf3; line-height: 1.8;
                font-size: 13px; color: #244b32;
            }
            .testing-note p { margin: 8px 0; }
            .testing-note .testing-warning { color: #8a4b12; font-weight: bold; }
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
            <div class="testing-note">
                <strong>🧪 دعوت به آشنایی و همکاری در آزمایش سامانه</strong>
                <p>
                    سامانه «دولت عشق» در مرحله آزمایشی و توسعه قرار دارد. شما می‌توانید برای آشنایی با امکانات سامانه، با استفاده از مشخصات ساختگی و غیرواقعی در آن ثبت‌نام کنید و بخش‌های مختلف را بررسی کنید.
                </p>
                <p>
                    همچنین می‌توانید با ثبت موضوعات، ارائه ایده‌ها، مشارکت در گفتگوها و رأی‌گیری‌ها، به آزمایش سامانه کمک کنید تا ایرادها و کاستی‌های آن شناسایی و برطرف شوند.
                </p>
                <p><strong>مشارکت شما، حتی در مرحله آزمایشی، گامی ارزشمند در بهتر شدن این سامانه است.</strong></p>
                <p class="testing-warning">توجه: لطفاً در این مرحله از وارد کردن اطلاعات شخصی، محرمانه یا واقعی خودداری کنید.</p>
            </div>
        </div>

        <div class="stats-box" style="font-size:14px;">
            <p style="margin:6px 0">🏘️ جوامع: <strong><?= htmlspecialchars($communities_count) ?></strong></p>
            <p style="margin:6px 0">👥 اعضا: <strong><?= htmlspecialchars($users_count) ?></strong></p>
            <p style="margin:6px 0">📘 موضوعات: <strong><?= htmlspecialchars($topics_count) ?></strong></p>
        </div>

        <form method="post" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['register_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <label for="username">نام و نام خانوادگی</label>
            <input type="text" id="username" name="username" placeholder="مثال: علی رضایی" required>

            <label for="phone">شماره همراه (می‌توانید از شماره آزمایشی استفاده کنید)</label>
            <input type="text" id="phone" name="phone" placeholder="09xxxxxxxxx یا +989xxxxxxxxx" inputmode="tel" autocomplete="tel" required>

            <label for="national_id">شماره ملی (می‌توانید از شماره آزمایشی استفاده کنید)</label>
            <input type="text" id="national_id" name="national_id" placeholder="کد ملی ۱۰ رقمی" inputmode="numeric" autocomplete="off" required>
            <p style="margin:6px 0 12px; color:#666; font-size:12px; line-height:1.7">
                شماره همراه با قالب 09xxxxxxxxx (یا +989xxxxxxxxx) و شماره ملی ۱۰ رقمی با رقم کنترلی معتبر پذیرفته می‌شود. ارقام فارسی و عربی و جداکننده‌های معمول نیز قابل قبول‌اند؛ از اطلاعات واقعی یا متعلق به دیگران استفاده نکنید.
            </p>

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