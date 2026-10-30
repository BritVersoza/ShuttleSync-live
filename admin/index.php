<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../includes/database_connect.php';
require_once __DIR__ . '/../includes/csrf_helper.php';


$error_message = '';

// ==========================================
// REDIRECT IF ALREADY LOGGED IN
// ==========================================
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    if ($_SESSION['role'] === 'Admin' || $_SESSION['role'] === 'Head Manager') {
        header("Location: dashboard.php");
    } else {
        // If a regular player somehow lands on the admin portal while logged in
        header("Location: ../playerdashboard.php");
    }
    exit;
}

// ==========================================
// HANDLE ADMIN LOGIN
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    verify_csrf_token();
    $login_input    = trim($_POST['email']);
    $password_input = trim($_POST['password']);

    if (empty($login_input) || empty($password_input)) {
        $error_message = "Please enter both your email and password.";
    } else {
        $stmt = $pdo->prepare("SELECT user_id, full_name, email, password_hash, role FROM Users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $login_input]);
        $user = $stmt->fetch();

        if ($user && !empty($user['password_hash']) && password_verify($password_input, $user['password_hash'])) {
            
            // STRICT ROLE CHECK: Only allow Admins and Head Managers
            if ($user['role'] === 'Admin' || $user['role'] === 'Head Manager') {
                $_SESSION['user_id']   = $user['user_id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role']      = $user['role'];
                $_SESSION['logged_in'] = true;

                header("Location: dashboard.php");
                exit;
            } else {
                // Correct password, but not an admin
                $error_message = "Access Denied: You do not have administrative privileges.";
            }

        } else {
            $error_message = "Invalid credentials. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Admin Portal | HI-POWER</title>
    <?php include __DIR__ . '/../includes/tailwind_config.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Manrope', sans-serif; }
        .brand-gradient {
            background: linear-gradient(135deg, #ff5722 0%, #1e2fa0 40%, #152078 100%);
        }
        .brand-gradient::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle at 30% 70%, rgba(237,74,48,0.15) 0%, transparent 50%),
                        radial-gradient(circle at 70% 20%, rgba(255,255,255,0.08) 0%, transparent 40%);
            pointer-events: none;
        }
        .brand-gradient::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 200px;
            background: linear-gradient(to top, rgba(0,0,0,0.15), transparent);
            pointer-events: none;
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-in {
            animation: slideUp 0.5s ease-out forwards;
        }
        .input-field {
            transition: all 0.2s ease;
        }
        .input-field:focus {
            border-color: #ff5722;
            box-shadow: 0 0 0 3px rgba(255,87,34,0.12);
        }
        .submit-btn {
            background: #ff5722;
            transition: all 0.2s ease;
        }
        .submit-btn:hover {
            background: #2637c0;
            box-shadow: 0 4px 14px rgba(255,87,34,0.35);
            transform: translateY(-1px);
        }
        .submit-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 6px rgba(255,87,34,0.25);
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">

    <div class="flex min-h-screen">

        <!-- Left Brand Panel -->
        <div class="brand-gradient relative hidden lg:flex lg:w-[480px] xl:w-[540px] flex-col justify-between p-10 text-white overflow-hidden flex-shrink-0">
            <div class="relative z-10">
                <div class="flex items-center gap-3 mb-2">
                    <img src="../img/logo.png" alt="HI-POWER" class="h-10 w-10 rounded-xl object-contain bg-white/10 p-1">
                    <span class="text-lg font-bold tracking-tight">HI-POWER</span>
                </div>
            </div>

            <div class="relative z-10">
                <div class="mb-6 inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-white/10 backdrop-blur-sm border border-white/15">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                    </svg>
                </div>
                <h1 class="text-3xl font-extrabold tracking-tight mb-3 leading-tight">Admin<br>Portal</h1>
                <p class="text-white/60 text-sm leading-relaxed max-w-xs">
                    Secure access to the HI-POWER management dashboard. Authorized personnel only.
                </p>
            </div>

            <div class="relative z-10">
                <div class="flex items-center gap-3 text-white/40 text-xs font-medium">
                    <div class="w-8 h-px bg-white/20"></div>
                    <span class="uppercase tracking-widest text-[10px]">System Access</span>
                </div>
            </div>
        </div>

        <!-- Right Login Panel -->
        <div class="flex-1 flex items-center justify-center p-6 sm:p-10">

            <div class="w-full max-w-md animate-in">

                <!-- Mobile Logo -->
                <div class="lg:hidden mb-10 text-center">
                    <div class="inline-flex items-center gap-3">
                        <img src="../img/logo.png" alt="HI-POWER" class="h-9 w-9 rounded-xl object-contain">
                        <span class="text-lg font-bold text-gray-900 tracking-tight">HI-POWER</span>
                    </div>
                </div>

                <!-- Heading -->
                <div class="mb-8">
                    <h2 class="text-2xl font-extrabold text-gray-900 tracking-tight mb-1">Sign in to Admin</h2>
                    <p class="text-sm text-gray-500">Enter your credentials to access the dashboard.</p>
                </div>

                <!-- Error Message -->
                <?php if (!empty($error_message)): ?>
                    <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 flex items-start gap-3">
                        <div class="flex-shrink-0 w-5 h-5 mt-0.5">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="#ed4817" class="w-5 h-5">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-8-5a.75.75 0 0 1 .75.75v4.5a.75.75 0 0 1-1.5 0v-4.5A.75.75 0 0 1 10 5Zm0 10a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <p class="text-sm font-semibold text-red-700 leading-snug"><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                <?php endif; ?>

                <!-- Login Form -->
                <form method="POST" action="index.php" class="space-y-5" id="adminLoginForm">
                    <?php echo get_csrf_input(); ?>

                    <div>
                        <label for="email" class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Email Address</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            required
                            placeholder="admin@hipowerbc.xyz"
                            class="input-field w-full h-12 px-4 rounded-xl border border-gray-200 bg-white text-sm text-gray-900 placeholder-gray-400 outline-none"
                        />
                    </div>

                    <div>
                        <label for="password" class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Password</label>
                        <div class="relative">
                            <input
                                type="password"
                                id="password"
                                name="password"
                                required
                                placeholder="Enter your password"
                                class="input-field w-full h-12 px-4 pr-12 rounded-xl border border-gray-200 bg-white text-sm text-gray-900 placeholder-gray-400 outline-none"
                            />
                            <button
                                type="button"
                                onclick="togglePassword()"
                                tabindex="-1"
                                class="absolute right-3 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600 transition-colors rounded-lg"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" id="eyeOpen" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                </svg>
                                <svg xmlns="http://www.w3.org/2000/svg" id="eyeClosed" class="w-5 h-5 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <button
                        type="submit"
                        class="submit-btn w-full h-12 rounded-xl text-white text-sm font-bold tracking-wide flex items-center justify-center gap-2 shadow-lg shadow-primary/20"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                        </svg>
                        Sign In
                    </button>
                </form>

                <!-- Footer -->
                <div class="mt-10 pt-6 border-t border-gray-100 text-center">
                    <a href="../index.php" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-400 hover:text-primary transition-colors group">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 group-hover:-translate-x-0.5 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                        </svg>
                        Back to Public Site
                    </a>
                </div>

                <p class="mt-6 text-center text-[10px] font-semibold uppercase tracking-widest text-gray-300">
                    All actions are logged and monitored
                </p>

            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const eyeOpen = document.getElementById('eyeOpen');
            const eyeClosed = document.getElementById('eyeClosed');
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeOpen.classList.add('hidden');
                eyeClosed.classList.remove('hidden');
            } else {
                passwordInput.type = 'password';
                eyeOpen.classList.remove('hidden');
                eyeClosed.classList.add('hidden');
            }
        }
    </script>
</body>
</html>
