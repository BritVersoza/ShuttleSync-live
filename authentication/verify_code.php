<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../includes/database_connect.php';
require_once __DIR__ . '/../includes/csrf_helper.php';

if (!isset($_SESSION['reset_email'])) {
    header("Location: /authentication/forgot_password");
    exit;
}

$error_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    verify_csrf_token();
    $entered_code = trim($_POST['code']);
    $email = $_SESSION['reset_email'];
    $current_time = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("SELECT reset_code, code_expires FROM Users WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if ($user && $user['reset_code'] === $entered_code && $current_time <= $user['code_expires']) {
        $_SESSION['code_verified'] = true;
        header("Location: /authentication/reset_password");
        exit;
    } else {
        $error_message = "Invalid or expired verification code. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Verify Code | ShuttleSync</title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: { brand: { blue: '#3145e6', red: '#ed4a30', dark: '#0f172a' } }
                }
            }
        }
    </script>
    <style>
        * { font-family: 'Inter', sans-serif; }
        .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }

        @keyframes slide-up { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes gradient-shift { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        @keyframes pop { 0% { transform: scale(1); } 50% { transform: scale(1.08); } 100% { transform: scale(1); } }

        .anim-slide-up { animation: slide-up 0.6s ease-out both; }
        .anim-slide-up-1 { animation-delay: 0.1s; }
        .anim-slide-up-2 { animation-delay: 0.2s; }
        .anim-slide-up-3 { animation-delay: 0.3s; }
        .anim-slide-up-4 { animation-delay: 0.4s; }
        .anim-gradient { background-size: 200% 200%; animation: gradient-shift 8s ease infinite; }
        .court-grid { background-image: linear-gradient(rgba(255,255,255,0.07) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.07) 1px, transparent 1px); background-size: 40px 40px; }

        .btn-primary { background: linear-gradient(135deg, #3145e6, #4f6eff); transition: all 0.3s ease; }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 8px 25px -5px rgba(49,69,230,0.4); }
        .btn-primary:active { transform: translateY(0); }

        .code-input {
            width: 56px; height: 64px; text-align: center;
            font-size: 24px; font-weight: 700; border: 2px solid #e2e8f0;
            border-radius: 14px; background: #f8fafc; color: #0f172a;
            transition: all 0.2s ease; outline: none;
            caret-color: transparent;
        }
        .code-input:focus { border-color: #3145e6; box-shadow: 0 0 0 3px rgba(49,69,230,0.1); background: white; }
        .code-input.filled { border-color: #3145e6; background: rgba(49,69,230,0.04); }
        .code-input.pop { animation: pop 0.2s ease; }

        @media (max-width: 400px) {
            .code-input { width: 44px; height: 54px; font-size: 20px; border-radius: 12px; }
        }
    </style>
</head>
<body class="bg-white min-h-screen">
<div class="flex min-h-screen">

    <!-- Left Branding Panel -->
    <div class="hidden lg:flex lg:w-[55%] relative overflow-hidden bg-gradient-to-br from-brand-dark via-[#1a2550] to-[#0d1b3e] anim-gradient" style="background-size: 200% 200%;">
        <div class="court-grid absolute inset-0"></div>

        <div class="relative z-10 flex flex-col justify-center px-16 xl:px-20 max-w-2xl">
            <div class="anim-slide-up anim-slide-up-1">
                <div class="flex items-center gap-3 mb-8">
                    <img src="/img/logo.png" alt="Logo" class="w-12 h-12 rounded-xl shadow-lg shadow-black/20">
                    <div>
                        <span class="text-xl font-bold text-white tracking-tight">Shuttle</span><span class="text-xl font-bold text-brand-red tracking-tight">Sync</span>
                    </div>
                </div>
            </div>

            <h1 class="text-4xl xl:text-5xl font-extrabold text-white leading-tight mb-6 anim-slide-up anim-slide-up-2">
                Check your<br>
                <span class="bg-gradient-to-r from-cyan-400 to-blue-400 bg-clip-text text-transparent">inbox.</span>
            </h1>

            <p class="text-lg text-blue-100/70 leading-relaxed mb-10 max-w-md anim-slide-up anim-slide-up-3">
                We've sent a 6-digit verification code to your email. Enter it below to verify your identity.
            </p>

            <div class="flex gap-6 anim-slide-up anim-slide-up-4">
                <div class="flex items-center gap-2 text-blue-100/50 text-sm">
                    <span class="material-symbols-outlined text-cyan-400 text-lg">schedule</span>
                    Expires in 15 min
                </div>
                <div class="flex items-center gap-2 text-blue-100/50 text-sm">
                    <span class="material-symbols-outlined text-cyan-400 text-lg">enhanced_encryption</span>
                    Encrypted
                </div>
            </div>
        </div>

        <div class="absolute bottom-0 left-0 right-0 h-32 bg-gradient-to-t from-brand-dark/50 to-transparent"></div>
    </div>

    <!-- Right Form Panel -->
    <div class="w-full lg:w-[45%] flex items-center justify-center p-6 sm:p-8 lg:p-12 bg-white">
        <div class="w-full max-w-[420px]">

            <div class="lg:hidden flex items-center gap-2 mb-8 anim-slide-up anim-slide-up-1">
                <img src="/img/logo.png" alt="Logo" class="w-10 h-10 rounded-xl">
                <span class="text-lg font-bold"><span class="text-brand-blue">Shuttle</span><span class="text-brand-red">Sync</span></span>
            </div>

            <a href="/authentication/forgot_password" class="inline-flex items-center gap-1.5 text-sm text-gray-400 hover:text-brand-blue transition-colors mb-6 group anim-slide-up anim-slide-up-1">
                <span class="material-symbols-outlined text-[18px] transition-transform group-hover:-translate-x-1">arrow_back</span>
                Change email
            </a>

            <div class="anim-slide-up anim-slide-up-2">
                <div class="w-14 h-14 rounded-2xl bg-brand-blue/10 flex items-center justify-center mb-6">
                    <span class="material-symbols-outlined text-brand-blue text-[28px]">pin</span>
                </div>
                <h2 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-2">Enter verification code</h2>
                <p class="text-gray-400 text-[15px] mb-8">Sent to <span class="font-semibold text-gray-600"><?php echo htmlspecialchars($_SESSION['reset_email']); ?></span></p>
            </div>

            <?php if ($error_message): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl flex items-center gap-3 anim-slide-up anim-slide-up-2">
                    <div class="w-8 h-8 rounded-full bg-red-100 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-red-600 text-lg">error</span>
                    </div>
                    <p class="text-sm text-red-600 font-medium"><?php echo htmlspecialchars($error_message); ?></p>
                </div>
            <?php endif; ?>

            <form class="space-y-6" method="POST" action="verify_code" id="codeForm">
                <?php echo get_csrf_input(); ?>
                <!-- Code Inputs -->
                <div class="flex justify-center gap-3 anim-slide-up anim-slide-up-3">
                    <input class="code-input" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code" id="c1" oninput="handleCode(this, 'c2')" onkeydown="handleBack(event, null, 'c1')" onfocus="this.select()">
                    <input class="code-input" maxlength="1" inputmode="numeric" pattern="[0-9]" id="c2" oninput="handleCode(this, 'c3')" onkeydown="handleBack(event, 'c1', 'c2')" onfocus="this.select()">
                    <input class="code-input" maxlength="1" inputmode="numeric" pattern="[0-9]" id="c3" oninput="handleCode(this, 'c4')" onkeydown="handleBack(event, 'c2', 'c3')" onfocus="this.select()">
                    <input class="code-input" maxlength="1" inputmode="numeric" pattern="[0-9]" id="c4" oninput="handleCode(this, 'c5')" onkeydown="handleBack(event, 'c3', 'c4')" onfocus="this.select()">
                    <input class="code-input" maxlength="1" inputmode="numeric" pattern="[0-9]" id="c5" oninput="handleCode(this, 'c6')" onkeydown="handleBack(event, 'c4', 'c5')" onfocus="this.select()">
                    <input class="code-input" maxlength="1" inputmode="numeric" pattern="[0-9]" id="c6" oninput="handleCode(this, null)" onkeydown="handleBack(event, 'c5', 'c6')" onfocus="this.select()">
                </div>
                <input type="hidden" name="code" id="fullCode">

                <button class="btn-primary w-full h-12 rounded-xl text-white text-sm font-bold tracking-wide flex items-center justify-center gap-2 anim-slide-up anim-slide-up-4" type="submit" id="submitBtn" disabled>
                    Verify & Continue
                    <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
                </button>
            </form>

            <p class="text-center text-sm text-gray-400 mt-8 anim-slide-up anim-slide-up-4">
                Didn't receive the code? <a href="/authentication/forgot_password" class="font-bold text-brand-blue hover:text-blue-700 transition-colors">Try again</a>
            </p>
        </div>
    </div>
</div>

<script>
    function handleCode(el, nextId) {
        el.value = el.value.replace(/[^0-9]/g, '');
        if (el.value) {
            el.classList.add('filled', 'pop');
            setTimeout(() => el.classList.remove('pop'), 200);
            if (nextId) document.getElementById(nextId).focus();
        } else {
            el.classList.remove('filled');
        }
        assembleCode();
    }

    function handleBack(e, prevId, currId) {
        if (e.key === 'Backspace' && !document.getElementById(currId).value && prevId) {
            document.getElementById(prevId).value = '';
            document.getElementById(prevId).classList.remove('filled');
            document.getElementById(prevId).focus();
            assembleCode();
        }
    }

    function assembleCode() {
        let code = '';
        for (let i = 1; i <= 6; i++) {
            code += document.getElementById('c' + i).value;
        }
        document.getElementById('fullCode').value = code;
        document.getElementById('submitBtn').disabled = code.length !== 6;
    }

    // Handle paste
    document.addEventListener('paste', function(e) {
        const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '').slice(0, 6);
        if (pasted) {
            for (let i = 0; i < pasted.length; i++) {
                const el = document.getElementById('c' + (i + 1));
                el.value = pasted[i];
                el.classList.add('filled', 'pop');
                setTimeout(() => el.classList.remove('pop'), 200);
            }
            const focusIdx = Math.min(pasted.length + 1, 6);
            document.getElementById('c' + focusIdx).focus();
            assembleCode();
        }
    });

    // Focus first input on load
    document.getElementById('c1').focus();
</script>
</body>
</html>
