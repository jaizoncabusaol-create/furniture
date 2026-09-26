<?php
session_start();
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db.php';

$notice = '';
$mysqli = appDb();
$noticeType = 'info';
$showRegisterModal = false;
$loginIdentifier = '';
$registerName = '';
$registerEmail = '';
$demoUser = [
    'name' => 'user',
    'email' => 'user@demo.local',
    'password' => '123',
];
$googleDemoUser = [
    'name' => 'Google User',
    'email' => 'google.user@demo.local',
];

if (isset($_SESSION['admin'])) {
    header('Location: admin.php');
    exit;
}

if (isset($_SESSION['user'])) {
    header('Location: user.php');
    exit;
}
$store = appReadStoreFromDatabase($mysqli);
$users = $store['users'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'login';

    if ($action === 'register') {
        $showRegisterModal = true;
        $registerName = trim($_POST['register_name'] ?? '');
        $registerEmail = trim($_POST['register_email'] ?? '');
        $registerPassword = $_POST['register_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($registerName === '' || $registerEmail === '' || $registerPassword === '' || $confirmPassword === '') {
            $notice = 'Complete all registration fields.';
            $noticeType = 'error';
        } elseif (!filter_var($registerEmail, FILTER_VALIDATE_EMAIL)) {
            $notice = 'Enter a valid email address.';
            $noticeType = 'error';
        } elseif ($registerPassword !== $confirmPassword) {
            $notice = 'Passwords do not match.';
            $noticeType = 'error';
        } else {
            $emailExists = false;

            foreach ($users as $user) {
                if (($user['email'] ?? '') === $registerEmail) {
                    $emailExists = true;
                    break;
                }
            }

            if ($emailExists) {
                $notice = 'That email is already registered.';
                $noticeType = 'error';
            } else {
                $users[] = [
                    'name' => $registerName,
                    'email' => $registerEmail,
                    'password' => password_hash($registerPassword, PASSWORD_DEFAULT),
                    'role' => 'user',
                    'created_at' => date('c'),
                ];
                $store['users'] = $users;
                appWriteStoreToDatabase($mysqli, $store);
                $notice = 'Account created successfully. You can log in now.';
                $noticeType = 'success';
                $showRegisterModal = false;
                $loginIdentifier = $registerEmail;
                $registerName = '';
                $registerEmail = '';
            }
        }
    } elseif ($action === 'google_login') {
        $matchedGoogleUser = null;

        foreach ($users as $user) {
            if (($user['email'] ?? '') === $googleDemoUser['email']) {
                $matchedGoogleUser = $user;
                break;
            }
        }

        if ($matchedGoogleUser === null) {
            $matchedGoogleUser = [
                'name' => $googleDemoUser['name'],
                'email' => $googleDemoUser['email'],
                'password' => '',
                'role' => 'user',
                'google_auth' => true,
                'created_at' => date('c'),
            ];
            $users[] = $matchedGoogleUser;
            $store['users'] = $users;
            appWriteStoreToDatabase($mysqli, $store);
        }

        $_SESSION['user'] = [
            'name' => $matchedGoogleUser['name'] ?? $googleDemoUser['name'],
            'email' => $matchedGoogleUser['email'] ?? $googleDemoUser['email'],
        ];

        header('Location: user.php');
        exit;
    } else {
        $loginIdentifier = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $matchedUser = null;

        foreach ($users as $user) {
            if (($user['email'] ?? '') === $loginIdentifier || ($user['name'] ?? '') === $loginIdentifier) {
                $matchedUser = $user;
                break;
            }
        }

        if ($loginIdentifier === '' || $password === '') {
            $notice = 'Enter your username or email and password.';
            $noticeType = 'error';
        } elseif ($loginIdentifier === $demoUser['name'] && $password === $demoUser['password']) {
            $_SESSION['user'] = [
                'name' => $demoUser['name'],
                'email' => $demoUser['email'],
            ];

            header('Location: user.php');
            exit;
        } else {
            if ($matchedUser === null || !password_verify($password, $matchedUser['password'] ?? '')) {
                $notice = 'Invalid username, email, or password.';
                $noticeType = 'error';
            } else {
                if (($matchedUser['role'] ?? 'user') === 'admin') {
                    $_SESSION['admin'] = [
                        'name' => $matchedUser['name'] ?? 'admin',
                        'role' => 'admin',
                        'email' => $matchedUser['email'] ?? '',
                    ];
                    header('Location: admin.php');
                    exit;
                }

                $_SESSION['user'] = [
                    'name' => $matchedUser['name'] ?? 'User',
                    'email' => $matchedUser['email'] ?? $loginIdentifier,
                    'role' => $matchedUser['role'] ?? 'user',
                ];

                header('Location: user.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Furniture System Login</title>
    <style>
        :root {
            --text: #1f2937;
            --muted: #6b7280;
            --field-border: #d9dee8;
            --card-border: #93b6f3;
            --accent: #2563eb;
            --accent-hover: #1d4ed8;
            --brand: #1e40af;
            --success-bg: #ecfdf5;
            --success-border: #86efac;
            --success-text: #166534;
            --error-bg: #fef2f2;
            --error-border: #fca5a5;
            --error-text: #991b1b;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: radial-gradient(circle at top left, #ffffff 0, #eef4ff 42%, #dbeafe 100%);
            color: var(--text);
        }

        .login-shell {
            width: 100%;
            max-width: 420px;
        }

        .login-card {
            background: #fff;
            border: 1px solid var(--card-border);
            border-radius: 12px;
            box-shadow: 0 22px 50px rgba(37, 59, 107, 0.08);
            padding: 28px 22px 18px;
        }

        .brand,
        .welcome,
        .register {
            text-align: center;
        }

        .brand {
            margin-bottom: 22px;
        }

        .brand svg {
            width: 52px;
            height: 52px;
            display: block;
            margin: 0 auto 10px;
            color: var(--brand);
        }

        .brand h1,
        .welcome h2,
        .modal-title {
            margin: 0;
            letter-spacing: -0.04em;
            font-weight: 800;
        }

        .brand h1 {
            font-size: 30px;
            line-height: 1;
        }

        .brand p,
        .welcome p,
        .modal-subtitle {
            color: var(--muted);
        }

        .brand p {
            margin: 8px auto 0;
            max-width: 230px;
            font-size: 13px;
            line-height: 1.35;
        }

        .welcome {
            margin-bottom: 18px;
        }

        .welcome h2 {
            margin-bottom: 4px;
            font-size: 28px;
        }

        .welcome p,
        .modal-subtitle {
            margin: 0;
            font-size: 14px;
        }

        .notice {
            margin-bottom: 14px;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 13px;
            border: 1px solid transparent;
        }

        .notice.success {
            background: var(--success-bg);
            border-color: var(--success-border);
            color: var(--success-text);
        }

        .notice.error {
            background: var(--error-bg);
            border-color: var(--error-border);
            color: var(--error-text);
        }

        .field {
            position: relative;
            margin-bottom: 12px;
        }

        .field input {
            width: 100%;
            height: 46px;
            border: 1px solid var(--field-border);
            border-radius: 8px;
            background: #fff;
            padding: 0 42px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease;
        }

        .field input:focus {
            border-color: #9db4df;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.08);
        }

        .field .left-icon,
        .field .right-icon {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            color: #7b8698;
            width: 18px;
            height: 18px;
            pointer-events: none;
        }

        .field .left-icon {
            left: 14px;
        }

        .field .right-icon {
            right: 14px;
        }

        .submit-btn,
        .google-btn,
        .register-submit,
        .modal-close {
            width: 100%;
            height: 42px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.16s ease, background-color 0.16s ease, box-shadow 0.16s ease;
        }

        .submit-btn,
        .register-submit {
            border: 0;
            background: var(--accent);
            color: #fff;
        }

        .submit-btn:hover,
        .register-submit:hover {
            background: var(--accent-hover);
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(37, 99, 235, 0.22);
        }

        .submit-btn {
            margin-top: 4px;
        }

        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 16px 0;
            color: #8c95a7;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .divider::before,
        .divider::after {
            content: "";
            height: 1px;
            flex: 1;
            background: #e4e8f1;
        }

        .google-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: #fff;
            border: 1px solid var(--field-border);
            color: var(--text);
            text-decoration: none;
        }

        .google-form {
            margin: 0;
        }

        .google-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
        }

        .google-mark {
            width: 18px;
            height: 18px;
            display: block;
        }

        .register {
            margin: 14px 0 0;
            font-size: 14px;
            color: #374151;
        }

        .register button {
            background: none;
            border: 0;
            padding: 0;
            color: #2563eb;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        .register button:hover {
            text-decoration: underline;
        }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(15, 23, 42, 0.45);
        }

        .modal-backdrop.is-open {
            display: flex;
        }

        .modal {
            width: 100%;
            max-width: 430px;
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 22px 60px rgba(15, 23, 42, 0.2);
            padding: 22px;
        }

        .modal-head {
            display: flex;
            align-items: start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
        }

        .modal-title {
            font-size: 26px;
        }

        .modal-close {
            width: 40px;
            height: 40px;
            border: 1px solid var(--field-border);
            background: #fff;
            color: #475569;
            flex: 0 0 auto;
        }

        .modal-close:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
        }

        @media (max-width: 480px) {
            .login-card,
            .modal {
                padding: 20px 16px 16px;
            }

            .brand h1,
            .welcome h2,
            .modal-title {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <main class="login-shell">
        <section class="login-card">
            <header class="brand">
                <svg viewBox="0 0 64 64" fill="none" aria-hidden="true">
                    <path d="M16 27a4 4 0 0 1 4-4h24a4 4 0 0 1 4 4v15H16V27Z" fill="currentColor"/>
                    <path d="M20 19a4 4 0 0 1 4-4h16a4 4 0 0 1 4 4v4H20v-4Z" fill="currentColor" opacity=".9"/>
                    <path d="M18 42h4v7h-4zm24 0h4v7h-4zm10-18h5l-3-10h-8l-2 10h8Z" stroke="currentColor" stroke-width="2.4" stroke-linejoin="round"/>
                </svg>
                <h1>Furniture System</h1>
                <p>Personalized Furniture Creation and Order Tracking System</p>
            </header>

            <div class="welcome">
                <h2>Welcome Back!</h2>
                <p>Log in to continue</p>
            </div>

            <?php if ($notice !== ''): ?>
                <div class="notice <?= htmlspecialchars($noticeType, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="action" value="login">
                <div class="field">
                    <span class="left-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M4 7.5A2.5 2.5 0 0 1 6.5 5h11A2.5 2.5 0 0 1 20 7.5v9A2.5 2.5 0 0 1 17.5 19h-11A2.5 2.5 0 0 1 4 16.5v-9Z" stroke="currentColor" stroke-width="1.8"/>
                            <path d="m6 8 6 5 6-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <input type="text" name="email" placeholder="Username or email" value="<?= htmlspecialchars($loginIdentifier, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <div class="field">
                    <span class="left-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.8"/>
                            <path d="M8 10V8a4 4 0 1 1 8 0v2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        </svg>
                    </span>
                    <input type="password" name="password" placeholder="Password" required>
                    <span class="right-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" stroke="currentColor" stroke-width="1.8"/>
                            <circle cx="12" cy="12" r="2.8" stroke="currentColor" stroke-width="1.8"/>
                        </svg>
                    </span>
                </div>

                <button class="submit-btn" type="submit">Login</button>
            </form>

            <div class="divider">OR</div>

            <form class="google-form" method="post">
                <input type="hidden" name="action" value="google_login">
                <button class="google-btn" type="submit">
                    <svg class="google-mark" viewBox="0 0 48 48" aria-hidden="true">
                        <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.3 36 24 36c-6.6 0-12-5.4-12-12S17.4 12 24 12c3 0 5.8 1.1 7.9 3l5.7-5.7C34.1 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.3-.4-3.5Z"/>
                        <path fill="#FF3D00" d="M6.3 14.7 12.9 19C14.7 14.8 19 12 24 12c3 0 5.8 1.1 7.9 3l5.7-5.7C34.1 6.1 29.3 4 24 4c-7.7 0-14.4 4.3-17.7 10.7Z"/>
                        <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2c-2 1.5-4.5 2.4-7.2 2.4-5.2 0-9.6-3.3-11.2-8l-6.6 5.1C9.5 39.6 16.2 44 24 44Z"/>
                        <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-1 2.7-2.8 4.8-5.1 6.3l6.2 5.2C39.4 36.8 44 31 44 24c0-1.3-.1-2.3-.4-3.5Z"/>
                    </svg>
                    Continue with Google
                </button>
            </form>

            <p class="register">Don't have an account? <button type="button" id="openRegister">Register here</button></p>
        </section>
    </main>

    <div class="modal-backdrop<?= $showRegisterModal ? ' is-open' : '' ?>" id="registerModal">
        <div class="modal">
            <div class="modal-head">
                <div>
                    <h3 class="modal-title">Create Account</h3>
                    <p class="modal-subtitle">Register your furniture system account here.</p>
                </div>
                <button class="modal-close" type="button" id="closeRegister" aria-label="Close registration form">&times;</button>
            </div>

            <form method="post">
                <input type="hidden" name="action" value="register">
                <div class="field">
                    <span class="left-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" stroke="currentColor" stroke-width="1.8"/>
                            <path d="M4 20a8 8 0 0 1 16 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        </svg>
                    </span>
                    <input type="text" name="register_name" placeholder="Full name" value="<?= htmlspecialchars($registerName, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <div class="field">
                    <span class="left-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M4 7.5A2.5 2.5 0 0 1 6.5 5h11A2.5 2.5 0 0 1 20 7.5v9A2.5 2.5 0 0 1 17.5 19h-11A2.5 2.5 0 0 1 4 16.5v-9Z" stroke="currentColor" stroke-width="1.8"/>
                            <path d="m6 8 6 5 6-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <input type="email" name="register_email" placeholder="Email address" value="<?= htmlspecialchars($registerEmail, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <div class="field">
                    <span class="left-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.8"/>
                            <path d="M8 10V8a4 4 0 1 1 8 0v2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        </svg>
                    </span>
                    <input type="password" name="register_password" placeholder="Password" required>
                </div>

                <div class="field">
                    <span class="left-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.8"/>
                            <path d="M8 10V8a4 4 0 1 1 8 0v2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        </svg>
                    </span>
                    <input type="password" name="confirm_password" placeholder="Confirm password" required>
                </div>

                <button class="register-submit" type="submit">Create Account</button>
            </form>
        </div>
    </div>

    <script>
        const registerModal = document.getElementById('registerModal');
        const openRegister = document.getElementById('openRegister');
        const closeRegister = document.getElementById('closeRegister');

        function openModal() {
            registerModal.classList.add('is-open');
        }

        openRegister.addEventListener('click', openModal);

        closeRegister.addEventListener('click', function () {
            registerModal.classList.remove('is-open');
        });

        registerModal.addEventListener('click', function (event) {
            if (event.target === registerModal) {
                registerModal.classList.remove('is-open');
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                registerModal.classList.remove('is-open');
            }
        });
    </script>
</body>
</html>
