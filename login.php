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

    if (!is_string($submitted_token) ||
        !hash_equals($_SESSION['login_csrf_token'], $submitted_token)) {
        http_response_code(400);
        $message = "درخواست معتبر نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.";
    } else {
        $phone_input = $_POST['phone'] ?? '';
        $national_id_input = $_POST['national_id'] ?? '';
        $password = $_POST['password'] ?? '';

        if (!is_string($phone_input) || !is_string($national_id_input) || !is_string($password)) {
            http_response_code(400);
            $message = "اطلاعات ارسالی معتبر نیست.";
        } else {
            $phone = trim($phone_input);
            $national_id = trim($national_id_input);

            if ($phone === '' || $national_id === '' || $password === '') {
                $message = "لطفاً همه فیلدها را پر کنید.";
            } else {
                // Use REMOTE_ADDR only; client-supplied forwarding headers are not trusted.
                $ip_binary = @inet_pton($_SERVER['REMOTE_ADDR'] ?? '');
                $rate_limit_ready = false;
                $login_blocked = false;

                if ($ip_binary !== false) {
                    $rate_insert = $conn->prepare(
                        "INSERT IGNORE INTO login_rate_limits (ip_address, failed_attempts, window_started_at) VALUES (?, 0, NOW())"
                    );

                    if ($rate_insert) {
                        $rate_insert->bind_param("s", $ip_binary);
                        try {
                            $rate_limit_ready = $rate_insert->execute();
                        } catch (Throwable $e) {
                            error_log("login.php: rate-limit initialization failed: " . $e->getMessage());
                        }
                        $rate_insert->close();
                    } else {
                        error_log("login.php: rate-limit table unavailable: " . $conn->error);
                    }

                    if ($rate_limit_ready) {
                        $rate_check = $conn->prepare(
                            "SELECT blocked_until FROM login_rate_limits WHERE ip_address = ? LIMIT 1"
                        );
                        if ($rate_check) {
                            $rate_check->bind_param("s", $ip_binary);
                            try {
                                if ($rate_check->execute()) {
                                    $rate_result = $rate_check->get_result();
                                    $rate_row = $rate_result ? $rate_result->fetch_assoc() : null;
                                    $login_blocked = !empty($rate_row['blocked_until']) &&
                                        strtotime($rate_row['blocked_until']) > time();
                                } else {
                                    error_log("login.php: rate-limit check failed: " . $rate_check->error);
                                    $rate_limit_ready = false;
                                }
                            } catch (Throwable $e) {
                                error_log("login.php: rate-limit check failed: " . $e->getMessage());
                                $rate_limit_ready = false;
                            }
                            $rate_check->close();
                        } else {
                            error_log("login.php: rate-limit check prepare failed: " . $conn->error);
                            $rate_limit_ready = false;
                        }
                    }
                } else {
                    error_log("login.php: could not parse client IP for rate limiting.");
                }

                if ($login_blocked) {
                    http_response_code(429);
                    $message = "تلاش‌های ناموفق زیاد بوده است. لطفاً ۱۵ دقیقه بعد دوباره تلاش کنید.";
                } else {
                    $stmt = $conn->prepare("SELECT * FROM users WHERE phone=? AND national_id=? LIMIT 1");

                    if (!$stmt) {
                        error_log("login.php: user lookup prepare failed: " . $conn->error);
                        http_response_code(500);
                        $message = "در حال حاضر امکان ورود وجود ندارد. لطفاً بعداً تلاش کنید.";
                    } else {
                        $stmt->bind_param("ss", $phone, $national_id);

                        try {
                            $query_ok = $stmt->execute();
                        } catch (Throwable $e) {
                            $query_ok = false;
                            error_log("login.php: user lookup failed: " . $e->getMessage());
                        }

                        $result = $query_ok ? $stmt->get_result() : false;
                        $user = $result ? $result->fetch_assoc() : null;
                        $stmt->close();

                        if ($user && password_verify($password, $user['password'])) {
                            if ($rate_limit_ready && $ip_binary !== false) {
                                $rate_reset = $conn->prepare(
                                    "UPDATE login_rate_limits SET failed_attempts = 0, blocked_until = NULL, window_started_at = NOW() WHERE ip_address = ?"
                                );
                                if ($rate_reset) {
                                    $rate_reset->bind_param("s", $ip_binary);
                                    try {
                                        $rate_reset->execute();
                                    } catch (Throwable $e) {
                                        error_log("login.php: rate-limit reset failed: " . $e->getMessage());
                                    }
                                    $rate_reset->close();
                                }
                            }

                            // Prevent session fixation by issuing a new session ID after successful authentication.
                            session_regenerate_id(true);
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['username'] = $user['username'];
                            header("Location: profile.php");
                            exit;
                        }

                        if ($rate_limit_ready && $ip_binary !== false) {
                            $rate_failure = $conn->prepare(
                                "UPDATE login_rate_limits
                                 SET failed_attempts = IF(window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE), 1, LEAST(failed_attempts + 1, 255)),
                                     blocked_until = IF(
                                         IF(window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE), 1, failed_attempts) >= 5,
                                         DATE_ADD(NOW(), INTERVAL 15 MINUTE),
                                         blocked_until
                                     ),
                                     window_started_at = IF(window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE), NOW(), window_started_at)
                                 WHERE ip_address = ?"
                            );
                            if ($rate_failure) {
                                $rate_failure->bind_param("s", $ip_binary);
                                try {
                                    $rate_failure->execute();
                                    $attempts_check = $conn->prepare(
                                        "SELECT blocked_until FROM login_rate_limits WHERE ip_address = ? LIMIT 1"
                                    );
                                    $attempts_row = null;
                                    if ($attempts_check) {
                                        $attempts_check->bind_param("s", $ip_binary);
                                        if ($attempts_check->execute()) {
                                            $attempts_result = $attempts_check->get_result();
                                            $attempts_row = $attempts_result ? $attempts_result->fetch_assoc() : null;
                                        }
                                        $attempts_check->close();
                                    }
                                    if (!empty($attempts_row['blocked_until']) &&
                                        strtotime($attempts_row['blocked_until']) > time()) {
                                        http_response_code(429);
                                        $message = "تلاش‌های ناموفق زیاد بوده است. لطفاً ۱۵ دقیقه بعد دوباره تلاش کنید.";
                                    }
                                } catch (Throwable $e) {
                                    error_log("login.php: failed-attempt recording failed: " . $e->getMessage());
                                }
                                $rate_failure->close();
                            } else {
                                error_log("login.php: failed-attempt prepare failed: " . $conn->error);
                            }
                        }

                        if ($message === '') {
                            $message = "شماره همراه، شماره ملی یا رمز عبور اشتباه است.";
                        }
                    }
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