<?php

require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/../includes/database_connect.php';
require_once __DIR__ . '/../includes/csrf_helper.php';

$VALID_ROLES = ['Player', 'Admin'];
$VALID_STATUSES = ['Active', 'Disabled'];

$self_id = (int)$_SESSION['sa_user_id'];

$success_message = '';
$error_message = '';

// ---- Handle Form Submissions (Create Admin or Bulk Update) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    // 1. Handle Create Admin
    if (isset($_POST['action']) && $_POST['action'] === 'create_admin') {
        try {
            $full_name = trim($_POST['full_name'] ?? '');
            $email     = trim($_POST['email'] ?? '');
            $password  = $_POST['password'] ?? '';
            $role      = 'Admin'; 

            if ($full_name === '' || $email === '' || $password === '') {
                throw new Exception("Please fill in all required fields for the admin account.");
            }

            $chk = $pdo->prepare("SELECT COUNT(*) FROM Users WHERE email = ?");
            $chk->execute([$email]);
            if ($chk->fetchColumn() > 0) {
                throw new Exception("An account with this email address already exists.");
            }

            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("INSERT INTO Users (full_name, email, password, password_hash, role, account_status, created_at) VALUES (?, ?, ?, ?, 'Active', NOW())");
            $stmt->execute([$full_name, $email, $hashed_password, $hashed_password, $role]);

            $success_message = "New admin account successfully created for " . htmlspecialchars($full_name) . "!";
        } catch (Exception $e) {
            $error_message = "Failed to create admin: " . $e->getMessage();
        }
    }

    // 2. Handle Bulk Update Users (Role and Status only; skill level is read-only for SuperAdmin)
    if (isset($_POST['action']) && $_POST['action'] === 'update_all_users') {
        $users_data = $_POST['users'] ?? [];
        $updated_count = 0;

        $stmt = $pdo->prepare(
            "UPDATE Users
             SET role = :role, account_status = :account_status
             WHERE user_id = :user_id AND user_id != :self_id"
        );

        foreach ($users_data as $target_id => $data) {
            $target_id = (int)$target_id;
            if ($target_id <= 0 || $target_id === $self_id) {
                continue;
            }

            $role   = $data['role'] ?? '';
            $status = $data['account_status'] ?? '';

            if (!in_array($role, $VALID_ROLES, true)) continue;
            if (!in_array($status, $VALID_STATUSES, true)) continue;

            $stmt->execute([
                'role'           => $role,
                'account_status' => $status,
                'user_id'        => $target_id,
                'self_id'        => $self_id,
            ]);
            $updated_count++;
        }

        if ($updated_count > 0) {
            $success_message = 'All user accounts updated successfully.';
        } else {
            $error_message = 'No changes were made or invalid data was submitted.';
        }
    }
}

// ---- Fetch users with created_at and last_login ----
$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $stmt = $pdo->prepare(
        "SELECT user_id, full_name, email, role, account_status, skill_level, created_at, last_login
         FROM Users
         WHERE (full_name LIKE :q1 OR email LIKE :q2) AND user_id != :self_id
         ORDER BY user_id DESC"
    );
    $stmt->execute(['q1' => "%{$search}%", 'q2' => "%{$search}%", 'self_id' => $self_id]);
} else {
    $stmt = $pdo->prepare(
        "SELECT user_id, full_name, email, role, account_status, skill_level, created_at, last_login
         FROM Users WHERE user_id != :self_id ORDER BY user_id DESC"
    );
    $stmt->execute(['self_id' => $self_id]);
}
$users = $stmt->fetchAll();

$role_badge = [
    'Admin'  => 'bg-gradient-to-r from-blue-50 to-cyan-50 text-blue-700 border border-blue-200',
    'Player' => 'bg-slate-50 text-slate-700 border border-slate-200',
];
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>User Management | ShuttleSync SuperAdmin</title>
    <?php include __DIR__ . '/../includes/tailwind_config.php'; ?>
</head>
<body class="bg-white font-body-md text-slate-900 selection:bg-purple-100 selection:text-purple-900">

<?php include __DIR__ . '/includes/superadmin_header.php'; ?>

<main class="flex-1 ml-0 md:ml-64 p-4 md:p-8 pt-20 md:pt-8 min-h-screen pb-24">
    <div class="max-w-[1380px] mx-auto space-y-6">

        <!-- Header Banner -->
        <div class="bg-gradient-to-r from-indigo-50 via-purple-50 to-pink-50 p-6 rounded-3xl border border-purple-100 shadow-sm">
            <span class="text-xs font-extrabold uppercase tracking-widest text-purple-600 bg-purple-100/80 px-3 py-1 rounded-full">Super Admin Portal</span>
            <h1 class="text-xl md:text-2xl font-extrabold text-slate-900 mt-2">User Management & System Directory</h1>
            <p class="text-sm text-slate-600 mt-0.5">Manage roles, monitor live player skill levels, and view activity timestamps.</p>
        </div>

        <?php if ($success_message): ?>
            <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-2xl flex items-center gap-3 text-emerald-800 shadow-sm">
                <span class="material-symbols-outlined text-emerald-600">check_circle</span>
                <p class="font-bold text-sm"><?php echo htmlspecialchars($success_message); ?></p>
            </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="p-4 bg-rose-50 border border-rose-200 rounded-2xl flex items-center gap-3 text-rose-800 shadow-sm">
                <span class="material-symbols-outlined text-rose-600">error</span>
                <p class="font-bold text-sm"><?php echo htmlspecialchars($error_message); ?></p>
            </div>
        <?php endif; ?>

        <!-- Search Bar and Role Filters -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <form method="GET" class="flex gap-2 w-full md:w-auto">
                <div class="relative w-full max-w-sm">
                    <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-[20px]">search</span>
                    <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>"
                           placeholder="Search by name or email…"
                           class="w-full rounded-2xl border border-slate-200 bg-slate-50/50 pl-10 pr-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-purple-600/20 focus:border-purple-600 transition-all">
                </div>
                <button type="submit" class="bg-slate-900 text-white text-sm font-bold rounded-2xl px-6 py-3 hover:bg-slate-800 transition-colors shrink-0 shadow-sm">
                    Search
                </button>
                <?php if ($search !== ''): ?>
                <a href="index.php" class="text-sm text-slate-500 self-center hover:underline font-semibold shrink-0 ml-2">Clear</a>
                <?php endif; ?>
            </form>

            <!-- Filter Buttons: All, Admin, Players -->
            <div class="flex items-center gap-1.5 bg-slate-100 p-1.5 rounded-2xl border border-slate-200/60">
                <button type="button" class="filter-btn active px-4 py-2 text-xs font-bold bg-purple-600 text-white rounded-xl shadow-sm transition-all" data-filter="all">All</button>
                <button type="button" class="filter-btn px-4 py-2 text-xs font-bold text-slate-600 hover:text-slate-900 rounded-xl transition-all" data-filter="Admin">Admin</button>
                <button type="button" class="filter-btn px-4 py-2 text-xs font-bold text-slate-600 hover:text-slate-900 rounded-xl transition-all" data-filter="Player">Players</button>
            </div>
        </div>

        <!-- Bulk User Form -->
        <form method="POST" id="bulk-user-form">
            <input type="hidden" name="action" value="update_all_users">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-slate-50/70 text-slate-400 text-[10px] font-extrabold uppercase tracking-widest border-b border-slate-100">
                            <tr>
                                <th class="px-5 py-4">User Details</th>
                                <th class="px-5 py-4">Role</th>
                                <th class="px-5 py-4">Status</th>
                                <th class="px-5 py-4">Skill Level</th>
                                <th class="px-5 py-4">Date Created</th>
                                <th class="px-5 py-4">Last Logged In</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($users as $u):
                                $uid = (int)$u['user_id'];
                                $badge = $role_badge[$u['role']] ?? 'bg-slate-50 text-slate-700 border border-slate-200';
                                $skill = trim($u['skill_level'] ?? '');
                            ?>
                            <tr class="hover:bg-slate-50/60 transition-colors align-middle" data-role="<?php echo htmlspecialchars($u['role']); ?>">
                                <td class="px-5 py-4">
                                    <p class="font-bold text-slate-900"><?php echo htmlspecialchars($u['full_name']); ?></p>
                                    <p class="text-xs text-slate-500 font-medium mt-0.5"><?php echo htmlspecialchars($u['email']); ?></p>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="relative inline-block">
                                        <select name="users[<?php echo $uid; ?>][role]" data-uid="<?php echo $uid; ?>"
                                                class="role-select appearance-none pr-8 pl-3 py-2 rounded-xl border border-slate-200 text-xs font-extrabold <?php echo $badge; ?> focus:outline-none focus:ring-2 focus:ring-purple-600/20 cursor-pointer">
                                            <?php foreach ($VALID_ROLES as $r): ?>
                                                <option value="<?php echo $r; ?>" <?php echo $u['role'] === $r ? 'selected' : ''; ?>><?php echo $r; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <span class="material-symbols-outlined pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-[18px] text-slate-500">expand_more</span>
                                    </div>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="relative inline-block">
                                        <select name="users[<?php echo $uid; ?>][account_status]"
                                                class="appearance-none pr-8 pl-3 py-2 rounded-xl border border-slate-200 text-xs font-extrabold <?php echo $u['account_status'] === 'Active' ? 'text-emerald-700 bg-emerald-50 border-emerald-200' : 'text-rose-700 bg-rose-50 border-rose-200'; ?> focus:outline-none focus:ring-2 focus:ring-purple-600/20 cursor-pointer">
                                            <?php foreach ($VALID_STATUSES as $s): ?>
                                                <option value="<?php echo $s; ?>" <?php echo $u['account_status'] === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <span class="material-symbols-outlined pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-[18px] <?php echo $u['account_status'] === 'Active' ? 'text-emerald-600' : 'text-rose-600'; ?>">expand_more</span>
                                    </div>
                                </td>
                                <td class="px-5 py-4">
                                    <?php if ($u['role'] === 'Admin'): ?>
                                        <span class="text-xs font-semibold text-slate-400 italic">— N/A (Admin) —</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-3 py-1 rounded-xl text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200">
                                            <?php echo $skill !== '' ? htmlspecialchars($skill) : '— Unspecified —'; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-4 text-xs font-medium text-slate-600">
                                    <?php echo !empty($u['created_at']) ? date('M d, Y h:i A', strtotime($u['created_at'])) : 'N/A'; ?>
                                </td>
                                <td class="px-5 py-4 text-xs font-medium text-slate-600">
                                    <?php echo !empty($u['last_login']) ? date('M d, Y h:i A', strtotime($u['last_login'])) : '<span class="text-amber-600 font-bold">Never</span>'; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$users): ?>
                            <tr><td colspan="6" class="px-5 py-12 text-center text-slate-400 italic">
                                <span class="material-symbols-outlined text-[40px] text-slate-300 block mb-2">search_off</span>
                                No users found matching your search.
                            </td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Floating Save Button -->
            <?php if ($users): ?>
            <div class="fixed bottom-6 right-6 z-50">
                <button type="submit"
                        class="flex items-center gap-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white text-sm font-bold rounded-full px-7 py-4 shadow-xl hover:brightness-110 active:scale-95 transition-all">
                    <span class="material-symbols-outlined text-[20px]">save</span> Save All Changes
                </button>
            </div>
            <?php endif; ?>
        </form>
    </div>
</main>

<!-- Create Admin Modal -->
<div id="createAdminModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm">
    <div class="bg-white w-full max-w-md rounded-3xl shadow-2xl overflow-hidden border border-slate-100 p-6 space-y-5">
        <div class="flex justify-between items-center">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-2xl bg-purple-100 text-purple-600 flex items-center justify-center">
                    <span class="material-symbols-outlined text-[22px]">person_add</span>
                </div>
                <div>
                    <h3 class="font-bold text-slate-900 text-lg">Create New Admin</h3>
                    <p class="text-xs text-slate-500">Provide credentials for the new administrator.</p>
                </div>
            </div>
            <button onclick="closeCreateAdminModal()" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 flex items-center justify-center text-slate-600 font-bold">✕</button>
        </div>

        <!-- Form with confirmation prompt -->
        <form method="POST" action="index.php" class="space-y-4" onsubmit="return confirm('Are you sure you want to create this new Admin account?');">
            <input type="hidden" name="action" value="create_admin">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
            <div>
                <label class="block text-xs font-bold text-slate-600 uppercase tracking-widest mb-1.5">Full Name</label>
                <input type="text" name="full_name" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:border-purple-600 focus:ring-2 focus:ring-purple-600/20" placeholder="e.g. John Manager">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-600 uppercase tracking-widest mb-1.5">Email Address</label>
                <input type="email" name="email" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:border-purple-600 focus:ring-2 focus:ring-purple-600/20" placeholder="admin@shuttlesync.com">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-600 uppercase tracking-widest mb-1.5">Temporary Password</label>
                <input type="password" name="password" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:border-purple-600 focus:ring-2 focus:ring-purple-600/20" placeholder="••••••••">
            </div>
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeCreateAdminModal()" class="flex-1 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-sm transition-all">Cancel</button>
                <button type="submit" class="flex-1 py-3 bg-purple-600 hover:bg-purple-700 text-white font-bold rounded-xl text-sm shadow-lg shadow-purple-600/20 transition-all">Save Admin</button>
            </div>
        </form>
    </div>
</div>

<!-- JavaScript for Filtering and Modal -->
<script>
    function openCreateAdminModal() {
        const m = document.getElementById('createAdminModal');
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
    function closeCreateAdminModal() {
        const m = document.getElementById('createAdminModal');
        m.classList.add('hidden');
        m.classList.remove('flex');
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Filter logic
        const filterBtns = document.querySelectorAll('.filter-btn');
        filterBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                filterBtns.forEach(b => {
                    b.classList.remove('bg-purple-600', 'text-white', 'shadow-sm');
                    b.classList.add('text-slate-600', 'hover:text-slate-900');
                });
                btn.classList.remove('text-slate-600', 'hover:text-slate-900');
                btn.classList.add('bg-purple-600', 'text-white', 'shadow-sm');

                const filterVal = btn.dataset.filter;
                const rows = document.querySelectorAll('tbody tr[data-role]');

                rows.forEach(row => {
                    if (filterVal === 'all' || row.dataset.role === filterVal) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
        });
    });
</script>
</body>
</html>