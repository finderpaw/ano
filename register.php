<?php
session_start();
require_once 'config.php';
require_once 'otp_mail.php';

const OTP_TTL          = 600;   // code valid for 10 minutes
const OTP_MAX_ATTEMPTS = 5;     // wrong tries allowed per code
const OTP_RESEND_WAIT  = 60;    // seconds before a new code can be sent

function reg_redirect(string $to = 'register.php'): void
{
    header("Location: $to");
    exit();
}

/* Password rule: at least 8 characters, 1 uppercase, 1 special character */
function is_strong_password(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[^A-Za-z0-9]/', $password);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST['action'] ?? 'send';

    /* =====================================================
       1) SEND VERIFICATION CODE
    ===================================================== */
    if ($action === 'send') {

        $fullname = trim($_POST["fullname"] ?? "");
        $email    = trim($_POST["email"] ?? "");
        $address  = trim($_POST["address"] ?? "");
        $contact  = trim($_POST["contact"] ?? "");
        $username = trim($_POST["username"] ?? "");
        $password = $_POST["password"] ?? "";

        // Everyone who registers here is a Local resident.
        $role = "Local";

        if (
            $fullname === "" || $email === "" ||
            $address === "" || $contact === "" ||
            $username === "" || $password === ""
        ) {
            $_SESSION['error'] = "Please complete all fields.";
            reg_redirect();
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = "Invalid email address.";
            reg_redirect();
        }

        if (!preg_match("/^09\d{9}$/", $contact)) {
            $_SESSION['error'] = "Enter a valid 11-digit Philippine contact number.";
            reg_redirect();
        }

        if (!is_strong_password($password)) {
            $_SESSION['error'] = "Password must be at least 8 characters and include an uppercase letter and a special character.";
            reg_redirect();
        }

        if (
            strlen($fullname) > 100 ||
            strlen($username) > 50 ||
            strlen($address) > 255 ||
            strlen($contact) > 20
        ) {
            $_SESSION['error'] = "One or more fields are too long.";
            reg_redirect();
        }

        // Check duplicate username or email
        $check = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $check->bind_param("ss", $username, $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $check->close();
            $_SESSION['error'] = "Username or email already exists.";
            reg_redirect();
        }
        $check->close();

        // The account is NOT created yet. Details stay in the session
        // until the user enters the correct code from their email.
        $code = (string)random_int(100000, 999999);

        $_SESSION['pending_reg'] = [
            'fullname'  => $fullname,
            'email'     => $email,
            'address'   => $address,
            'contact'   => $contact,
            'role'      => $role,
            'username'  => $username,
            'password'  => password_hash($password, PASSWORD_DEFAULT),
            'otp_hash'  => password_hash($code, PASSWORD_DEFAULT),
            'expires'   => time() + OTP_TTL,
            'attempts'  => 0,
            'last_sent' => time(),
        ];

        if (!send_otp_email($email, $fullname, $code)) {
            unset($_SESSION['pending_reg']);
            $_SESSION['error'] = "We could not send the verification email. Please check your email address and try again.";
            reg_redirect();
        }

        $_SESSION['otp_info'] = "We sent a 6-digit code to your email.";
        reg_redirect();   // page reloads and shows the verification popup
    }

    /* =====================================================
       2) VERIFY THE CODE AND CREATE THE ACCOUNT
    ===================================================== */
    if ($action === 'verify') {

        $p = $_SESSION['pending_reg'] ?? null;

        if (!$p) {
            $_SESSION['error'] = "Your session expired. Please register again.";
            reg_redirect();
        }

        if (time() > $p['expires']) {
            unset($_SESSION['pending_reg']);
            $_SESSION['error'] = "The code expired. Please register again.";
            reg_redirect();
        }

        if ($p['attempts'] >= OTP_MAX_ATTEMPTS) {
            unset($_SESSION['pending_reg']);
            $_SESSION['error'] = "Too many wrong attempts. Please register again.";
            reg_redirect();
        }

        $code = preg_replace('/\D/', '', $_POST['otp'] ?? '');

        if (strlen($code) !== 6 || !password_verify($code, $p['otp_hash'])) {
            $_SESSION['pending_reg']['attempts']++;
            $left = OTP_MAX_ATTEMPTS - $_SESSION['pending_reg']['attempts'];

            if ($left <= 0) {
                unset($_SESSION['pending_reg']);
                $_SESSION['error'] = "Too many wrong attempts. Please register again.";
            } else {
                $_SESSION['otp_error'] = "Incorrect code. You have $left attempt(s) left.";
            }
            reg_redirect();
        }

        // Code is correct: create the account
        $status = "Approved";

        $stmt = $conn->prepare(
            "INSERT INTO users
                (fullname, email, address, contact, role, username, password, account_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        if (!$stmt) {
            error_log($conn->error);
            $_SESSION['otp_error'] = "Registration failed. Please try again.";
            reg_redirect();
        }

        $stmt->bind_param(
            "ssssssss",
            $p['fullname'], $p['email'], $p['address'], $p['contact'],
            $p['role'], $p['username'], $p['password'], $status
        );

        if ($stmt->execute()) {
            $stmt->close();
            unset($_SESSION['pending_reg']);
            $_SESSION['success'] = "Account created successfully! You can now log in.";
            reg_redirect('login.php');
        }

        if ($stmt->errno == 1062) {
            $_SESSION['error'] = "Username or email already exists.";
        } else {
            error_log($stmt->error);
            $_SESSION['error'] = "Registration failed. Please try again.";
        }
        $stmt->close();
        unset($_SESSION['pending_reg']);
        reg_redirect();
    }

    /* =====================================================
       3) RESEND CODE
    ===================================================== */
    if ($action === 'resend') {

        $p = $_SESSION['pending_reg'] ?? null;

        if (!$p) {
            $_SESSION['error'] = "Your session expired. Please register again.";
            reg_redirect();
        }

        $wait = OTP_RESEND_WAIT - (time() - $p['last_sent']);
        if ($wait > 0) {
            $_SESSION['otp_error'] = "Please wait $wait second(s) before requesting a new code.";
            reg_redirect();
        }

        $code = (string)random_int(100000, 999999);

        $_SESSION['pending_reg']['otp_hash']  = password_hash($code, PASSWORD_DEFAULT);
        $_SESSION['pending_reg']['expires']   = time() + OTP_TTL;
        $_SESSION['pending_reg']['attempts']  = 0;
        $_SESSION['pending_reg']['last_sent'] = time();

        if (!send_otp_email($p['email'], $p['fullname'], $code)) {
            $_SESSION['otp_error'] = "We could not send the email. Please try again.";
        } else {
            $_SESSION['otp_info'] = "A new code was sent to your email.";
        }
        reg_redirect();
    }

    /* =====================================================
       4) CANCEL (use a different email)
    ===================================================== */
    if ($action === 'cancel') {
        unset($_SESSION['pending_reg']);
        reg_redirect();
    }

    reg_redirect();
}

/* ---------- Page state (GET) ---------- */
$error    = $_SESSION['error'] ?? '';
$otpError = $_SESSION['otp_error'] ?? '';
$otpInfo  = $_SESSION['otp_info'] ?? '';
unset($_SESSION['error'], $_SESSION['otp_error'], $_SESSION['otp_info']);

$pending = $_SESSION['pending_reg'] ?? null;
if ($pending && time() > $pending['expires']) {
    unset($_SESSION['pending_reg']);
    $pending = null;
    if ($error === '') { $error = "Your verification code expired. Please register again."; }
}

$showOtp    = $pending !== null;
$maskedMail = '';
$resendWait = 0;
if ($showOtp) {
    [$local, $domain] = explode('@', $pending['email'], 2) + ['', ''];
    $maskedMail = substr($local, 0, 2) . str_repeat('*', max(1, strlen($local) - 2)) . '@' . $domain;
    $resendWait = max(0, OTP_RESEND_WAIT - (time() - $pending['last_sent']));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pawfinder - Account Registration</title>
    <link rel="stylesheet" href="css/register.css">
    <style>
        .otp-overlay{position:fixed;inset:0;background:rgba(15,23,42,.6);display:flex;align-items:center;justify-content:center;padding:16px;z-index:9999}
        .otp-box{background:#fff;border-radius:14px;max-width:400px;width:100%;padding:28px 24px;text-align:center;box-shadow:0 20px 50px rgba(0,0,0,.3);font-family:inherit}
        .otp-box h3{margin:0 0 8px;font-size:22px;color:#0f172a}
        .otp-box p{margin:0 0 14px;font-size:14px;color:#475569;line-height:1.5}
        .otp-input{width:100%;box-sizing:border-box;text-align:center;font-size:26px;letter-spacing:10px;padding:12px;border:1px solid #cbd5e1;border-radius:10px;margin-bottom:12px}
        .otp-btn{width:100%;background:#0d9488;color:#fff;border:0;border-radius:10px;padding:12px;font:inherit;font-weight:700;cursor:pointer}
        .otp-btn:hover{background:#0b7c72}
        .otp-link{background:none;border:0;color:#0d9488;font:inherit;font-size:13px;font-weight:600;cursor:pointer;padding:6px}
        .otp-link:disabled{color:#94a3b8;cursor:not-allowed}
        .otp-link.muted{color:#64748b;font-weight:500}
        .otp-msg{border-radius:8px;padding:9px 12px;font-size:13px;margin-bottom:12px}
        .otp-msg.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
        .otp-msg.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
        .otp-row{display:flex;justify-content:center;gap:6px;flex-wrap:wrap;margin-top:6px}

        /* Password strength indicator */
        .pw-meter{display:none;margin:4px 0 16px}
        .pw-meter.show{display:block}
        .pw-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
        .pw-title{font-size:13px;color:#64748b}
        .pw-label{font-size:13px;font-weight:700}
        .pw-bar{height:8px;border-radius:999px;background:#e2e8f0;overflow:hidden}
        .pw-bar-fill{height:100%;width:0;border-radius:999px;background:#dc2626;transition:width .25s ease,background-color .25s ease}
        .pw-rules{list-style:none;margin:12px 0 0;padding:0;display:flex;flex-wrap:wrap;gap:8px}
        .pw-rules li{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:#f1f5f9;color:#64748b;font-size:13px;font-weight:600;transition:background-color .2s ease,color .2s ease}
        .pw-rules li::before{content:"";display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;box-sizing:border-box;border-radius:50%;border:2px solid #94a3b8;font-size:10px;line-height:1;color:#fff}
        .pw-rules li.ok{background:#e0f2f1;color:#0f766e}
        .pw-rules li.ok::before{content:"✓";background:#0d9488;border-color:#0d9488}
        .pw-meter.weak   .pw-bar-fill{background:#dc2626}
        .pw-meter.weak   .pw-label{color:#dc2626}
        .pw-meter.medium .pw-bar-fill{background:#f59e0b}
        .pw-meter.medium .pw-label{color:#d97706}
        .pw-meter.strong .pw-bar-fill{background:#0d9488}
        .pw-meter.strong .pw-label{color:#0d9488}
    </style>
</head>

<body>

<div class="register-card">

    <div class="sidebar-left">
        <div>
            <div class="brand-logo">🐾 PAWFINDER</div>

            <ul class="step-list">
                <li class="step-item <?= $showOtp ? '' : 'active' ?>">○ Account Setup</li>
                <li class="step-item <?= $showOtp ? 'active' : '' ?>">○ Verify Email</li>
                <li class="step-item">○ Resident Details</li>
                <li class="step-item">○ Pet Preferences</li>
                <li class="step-item">○ Confirm</li>
            </ul>
        </div>

        <div>
            <div class="sidebar-img-container">
                <img src="image/animal.jpg" alt="Animal">
            </div>

            <div class="back-to-login">
                <a href="homepage.php">← Back to Homepage</a>
            </div>
        </div>
    </div>

    <div class="form-right">

        <h2>Welcome to Pawfinder!</h2>

        <p>Please provide your basic information to get started. We will send a verification code to your email.</p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form action="register.php" method="POST" id="regForm">
            <input type="hidden" name="action" value="send">

            <div class="row">
                <div class="col">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="fullname"
                            maxlength="100"
                            placeholder="Juan Dela Cruz" required>
                    </div>
                </div>

                <div class="col">
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email"
                            maxlength="100"
                            placeholder="juan@gmail.com" required>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col">
                    <div class="form-group">
                        <label>Address (Mabalacat City)</label>
                        <input type="text" name="address"
                            maxlength="255"
                            placeholder="e.g., Dau, Mabalacat City" required>
                    </div>
                </div>

                <div class="col">
                    <div class="form-group">
                        <label>Contact Number</label>
                        <input type="tel" name="contact"
                            pattern="09[0-9]{9}"
                            maxlength="11"
                            placeholder="09123456789" required>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username"
                            maxlength="50"
                            placeholder="Username" required>
                    </div>
                </div>

                <div class="col">
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" id="password"
                            minlength="8"
                            autocomplete="new-password"
                            placeholder="8+ chars, 1 uppercase, 1 special" required>
                    </div>
                </div>
            </div>

            <!-- Live password strength (full width, below the fields) -->
            <div class="pw-meter" id="pwMeter" aria-live="polite">
                <div class="pw-head">
                    <span class="pw-title">Password strength</span>
                    <span class="pw-label" id="pwLabel"></span>
                </div>
                <div class="pw-bar"><div class="pw-bar-fill" id="pwFill"></div></div>
                <ul class="pw-rules">
                    <li id="ruleLen">8+ characters</li>
                    <li id="ruleUpper">Uppercase (e.g. A, B, C)</li>
                    <li id="ruleSpecial">Special character (e.g. ! @ # $ %)</li>
                </ul>
            </div>

            <button type="submit" class="btn-submit">
                Send Verification Code
            </button>

        </form>
    </div>
</div>

<?php if ($showOtp): ?>
<!-- Verification popup -->
<div class="otp-overlay" id="otpOverlay" role="dialog" aria-modal="true" aria-labelledby="otpTitle">
    <div class="otp-box">
        <h3 id="otpTitle">📧 Check your email</h3>
        <p>We sent a 6-digit verification code to <b><?= htmlspecialchars($maskedMail) ?></b>. Enter it below to finish creating your account. The code is valid for 10 minutes.</p>

        <?php if ($otpInfo !== ''): ?><div class="otp-msg ok"><?= htmlspecialchars($otpInfo) ?></div><?php endif; ?>
        <?php if ($otpError !== ''): ?><div class="otp-msg err"><?= htmlspecialchars($otpError) ?></div><?php endif; ?>

        <form action="register.php" method="POST">
            <input type="hidden" name="action" value="verify">
            <input class="otp-input" type="text" name="otp" id="otpInput"
                   inputmode="numeric" autocomplete="one-time-code"
                   pattern="[0-9]{6}" maxlength="6" placeholder="••••••" required autofocus>
            <button type="submit" class="otp-btn">Verify &amp; Create Account</button>
        </form>

        <div class="otp-row">
            <form action="register.php" method="POST" style="margin:0">
                <input type="hidden" name="action" value="resend">
                <button type="submit" class="otp-link" id="resendBtn" <?= $resendWait > 0 ? 'disabled' : '' ?>>
                    <?= $resendWait > 0 ? 'Resend code in ' . (int)$resendWait . 's' : 'Resend code' ?>
                </button>
            </form>
            <form action="register.php" method="POST" style="margin:0">
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="otp-link muted">Use a different email</button>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var left = <?= (int)$resendWait ?>;
    var btn = document.getElementById('resendBtn');
    if (btn && left > 0) {
        var t = setInterval(function () {
            left--;
            if (left <= 0) {
                clearInterval(t);
                btn.disabled = false;
                btn.textContent = 'Resend code';
            } else {
                btn.textContent = 'Resend code in ' + left + 's';
            }
        }, 1000);
    }
    var input = document.getElementById('otpInput');
    if (input) {
        input.addEventListener('input', function () {
            input.value = input.value.replace(/\D/g, '').slice(0, 6);
        });
    }
})();
</script>
<?php endif; ?>

<script>
(function () {
    var form    = document.getElementById("regForm");
    var pwInput = document.getElementById("password");
    var meter   = document.getElementById("pwMeter");
    var label   = document.getElementById("pwLabel");

    var rules = {
        len:     { el: document.getElementById("ruleLen"),     text: "At least 8 characters",                     test: function (p) { return p.length >= 8; } },
        upper:   { el: document.getElementById("ruleUpper"),   text: "One uppercase letter",                      test: function (p) { return /[A-Z]/.test(p); } },
        special: { el: document.getElementById("ruleSpecial"), text: "One special character (e.g. ! @ # $ %)",    test: function (p) { return /[^A-Za-z0-9]/.test(p); } }
    };

    var fill = document.getElementById("pwFill");
    var metCount = 0;

    function checkPassword(p) {
        var allOk = true;
        metCount = 0;
        Object.keys(rules).forEach(function (key) {
            var ok = rules[key].test(p);
            rules[key].el.classList.toggle("ok", ok);
            if (ok) { metCount++; } else { allOk = false; }
        });
        return allOk;
    }

    function renderMeter(p) {
        var allOk = checkPassword(p);
        var level = allOk ? "strong" : (metCount === 2 ? "medium" : "weak");
        var names = { weak: "Weak", medium: "Medium", strong: "Strong" };
        meter.classList.add("show");
        meter.classList.remove("weak", "medium", "strong");
        meter.classList.add(level);
        fill.style.width = Math.max(metCount, 1) / 3 * 100 + "%";
        label.textContent = names[level];
        return allOk;
    }

    pwInput.addEventListener("input", function () {
        var p = pwInput.value;

        if (p === "") {
            meter.classList.remove("show", "weak", "medium", "strong");
            return;
        }

        renderMeter(p);
    });

    form.addEventListener("submit", function (event) {
        var p = pwInput.value;

        if (!renderMeter(p)) {
            event.preventDefault();
            pwInput.focus();
            alert("Password must be at least 8 characters and include an uppercase letter and a special character.");
        }
    });
})();
</script>

</body>
</html>