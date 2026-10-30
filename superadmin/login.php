<?php
// superadmin/login.php
// Standalone login for the SuperAdmin portal subdomain.
// Reuses the same Users table as the main app, but only accounts with
// role = 'SuperAdmin' are allowed in here — any other role is rejected
// even if the email/password is correct.

session_start();

require_once __DIR__ . '/../includes/database_connect.php';
require_once __DIR__ . '/../includes/csrf_helper.php';

// Already logged in as SuperAdmin? Skip straight to the dashboard.
if (!empty($_SESSION['sa_logged_in']) && $_SESSION['sa_role'] === 'SuperAdmin') {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } else {
        $stmt = $pdo->prepare(
            "SELECT user_id, full_name, email, password_hash, role, account_status
             FROM Users WHERE email = :email LIMIT 1"
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Invalid email or password.';
        } elseif ($user['role'] !== 'SuperAdmin') {
            // Deliberately vague — don't reveal that the account exists
            // but lacks SuperAdmin access.
            $error = 'Invalid email or password.';
        } elseif ($user['account_status'] === 'Disabled') {
            $error = 'This account has been disabled.';
        } else {
            // Update the last_login timestamp when SuperAdmin logs in successfully
            $update_stmt = $pdo->prepare("UPDATE Users SET last_login = NOW() WHERE user_id = ?");
            $update_stmt->execute([$user['user_id']]);

            session_regenerate_id(true);
            $_SESSION['sa_user_id']   = $user['user_id'];
            $_SESSION['sa_full_name'] = $user['full_name'];
            $_SESSION['sa_role']      = $user['role'];
            $_SESSION['sa_logged_in'] = true;
            header('Location: index.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SuperAdmin Login | ShuttleSync</title>
<?php include __DIR__ . '/../includes/tailwind_config.php'; ?>
<style>
    .form-input { border: 1px solid transparent; transition: all 0.2s ease; }
    .form-input:focus { outline: none; background: white; border-color: #3145e6; box-shadow: 0 0 0 3px rgba(49,69,230,0.1); }
    .btn-primary { background: linear-gradient(135deg, #3145e6, #4f6eff); transition: all 0.3s ease; }
    .btn-primary:hover { box-shadow: 0 8px 20px -6px rgba(49,69,230,0.5); transform: translateY(-1px); }
</style>
</head>
<body class="bg-surface-container-low min-h-screen flex items-center justify-center p-6">
    <div class="w-full max-w-[400px]">
        <div class="flex flex-col items-center mb-6">
            <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-primary to-accent flex items-center justify-center shadow-lg mb-3">
                <span class="material-symbols-outlined text-white text-[28px]" style="font-variation-settings:'FILL' 1;">shield_person</span>
            </div>
            <h1 class="text-lg font-bold"><span class="text-primary">Shuttle</span><span class="text-accent">Sync</span></h1>
            <p class="text-[11px] text-outline font-bold uppercase tracking-[0.2em] mt-0.5">SuperAdmin Portal</p>
        </div>

        <div class="bg-white rounded-2xl border border-outline-variant/50 shadow-xl p-8">
            <?php if ($error): ?>
                <div class="mb-5 p-4 bg-red-50 border border-red-200 rounded-xl flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-red-100 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-red-600 text-[18px]">error</span>
                    </div>
                    <p class="text-sm font-medium text-red-700"><?php echo htmlspecialchars($error); ?></p>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4">
                <?php echo get_csrf_input(); ?>
                <div>
                    <label class="block text-xs font-bold text-on-surface-variant uppercase tracking-wider mb-1.5" for="email">Email</label>
                    <input type="email" name="email" id="email" required
                           class="form-input w-full h-12 px-4 rounded-xl bg-surface-container-low text-sm font-medium text-on-surface">
                </div>
                <div>
                    <label class="block text-xs font-bold text-on-surface-variant uppercase tracking-wider mb-1.5" for="password">Password</label>
                    <input type="password" name="password" id="password" required
                           class="form-input w-full h-12 px-4 rounded-xl bg-surface-container-low text-sm font-medium text-on-surface">
                </div>
                <button type="submit"
                        class="btn-primary w-full h-12 rounded-xl text-white text-sm font-bold tracking-wide flex items-center justify-center gap-2 mt-2">
                    <span class="material-symbols-outlined text-[18px]">login</span> Log in
                </button>
            </form>
        </div>

        <p class="text-center text-[11px] text-outline mt-6">Restricted access — SuperAdmin accounts only.</p>
    </div>
</body>
</html>