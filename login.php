<?php
// login.php
session_start();
include "db.php";

$message = '';

// Create a per-session CSRF token for the login form.
if (empty($_SESSION['login_csrf_token']) || !is_string($_SESSION['login_csrf_token'])) {
    $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted_token) || !hash_equals($_SESSION['login_csrf_token'], $submitted_token)) {
        http_response_code(400);
        $message = "درخواست معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.";
    } else {
    $phone_input = $_POST['phone'] ?? '';
    $national_id_input = $_POST['national_id'] ?? '';
    if (!is_string($phone_input) || !is_string($national_id_input)) {
        http_response_code(400);
        $message = "اطلاعات ارسالی معتبر نیست.";
    } else {
    $phone = trim($phone_input);
    $national_id = trim($national_id_input);
    // Preserve the exact password entered at registration; spaces may be intentional.
    $password = (string)($_POST['password'] ?? '');

    if ($phone === '' || $national_id === '' || $password === '') {
        $message = "لطفاً همه فیلدها را پر کنید.";
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE phone=? AND national_id=? LIMIT 1");
        $stmt->bind_param("ss", $phone, $national_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $user = $result->fetch_assoc()) {
            if (password_verify($password, $user['password'])) {
                // Prevent session fixation by issuing a new session ID after successful authentication.
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                header("Location: profile.php");
                exit;
            } else {
                $message = "رمز عبور اشتباه است.";
            }
        } else {
            $message = "کاربر یافت نشد. لطفاً ثبت‌نام کنید.";
        }
    }
    }
    }
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<title>ورود به سامانه</title>

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
body {
    font-family: "Vazirmatn", "IRANSans", "Segoe UI", Tahoma, sans-serif;
    background:#eef1f4;
    padding:20px;
    margin:0;
}

.container {
    max-width: 430px;
    margin: 40px auto;
    background: #fff;
    border-radius: 14px;
    border:1px solid #ddd;
    box-shadow: 0 3px 10px rgba(0,0,0,0.08);
    padding: 25px;
}

/* بهبود خوانایی در موبایل؛ اندازه‌های دسکتاپ بدون تغییر می‌مانند */
@media (max-width: 480px) {
    body {
        padding: 12px;
    }

    .container {
        width: auto;
        max-width: 430px;
        margin: 20px auto;
        padding: 22px 18px;
    }

    h2 {
        font-size: 26px;
    }

    label {
        font-size: 16px;
        margin-top: 10px;
    }

    input[type="text"], input[type="password"] {
        padding: 15px 12px;
        font-size: 17px;
        margin-bottom: 16px;
    }

    button {
        padding: 15px 12px;
        font-size: 18px;
    }
}

h2 {
    text-align:center;
    color:#1f5ad1;
    font-size:25px;
    font-weight:700;
    letter-spacing:-0.4px;
    margin-bottom:24px;
}

label {
    color:#344054;
    font-weight:600;
    font-size:14px;
    margin-top:8px;
    display:block;
}

input[type="text"], input[type="password"] {
    width:100%;
    padding:12px;
    margin-top:6px;
    margin-bottom:14px;
    border-radius:8px;
    border:1px solid #bbb;
    box-sizing:border-box;
    font-family:inherit;
    font-size:15px;
}

button {
    width:100%;
    padding:12px;
    background:#1f5ad1;
    color:#fff;
    border:none;
    border-radius:8px;
    font-family:inherit;
    font-size:16px;
    font-weight:600;
    cursor:pointer;
    margin-top:10px;
}

button:hover {
    background:#164ab0;
}

.message {
    color:red;
    text-align:center;
    margin-bottom:12px;
    font-size:14px;
}

a {
    color:#1f5ad1;
    text-decoration:none;
}

a:hover {
    text-decoration:underline;
}
</style>
</head>

<body>


<div class="container">
    <h2>ورود به سامانه</h2>

    <?php if($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['login_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <label>شماره همراه:</label>
        <input type="text" name="phone" placeholder="0912xxxxxxx" required>

        <label>شماره ملی:</label>
        <input type="text" name="national_id" placeholder="کد ملی" required>

        <label>رمز عبور:</label>
        <input type="password" name="password" placeholder="رمز عبور" required>

        <button type="submit">ورود</button>
    </form>

    <p style="text-align:center;margin-top:15px;">
        حساب ندارید؟
        <a href="register.php">ثبت‌نام</a>
    </p>
</div>

</body>
</html>