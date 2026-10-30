<?php
// includes/nav_bar.php — shared HI-POWER navigation.
// One header for every public page. Active state comes from the current route.

$current_page = basename($_SERVER['PHP_SELF']);

if (!function_exists('getNavClass')) {
    function getNavClass($page_name, $current_page) {
        $base = 'text-sm font-semibold transition-colors ';
        return $current_page === $page_name
            ? $base . 'text-[#ff5722]'
            : $base . 'text-white/70 hover:text-[#ff5722]';
    }
}

if (!function_exists('navCurrent')) {
    function navCurrent($page_name, $current_page) {
        return $current_page === $page_name ? ' aria-current="page"' : '';
    }
}

if (!function_exists('navBadmintonIcon')) {
    // Badminton shuttlecock (Material Design Icons "badminton"), inlined so it
    // inherits the link colour and needs no extra icon font.
    function navBadmintonIcon($class = '') {
        return '<svg class="' . htmlspecialchars($class) . '" viewBox="0 0 24 24" width="1em" height="1em" fill="currentColor" aria-hidden="true" focusable="false" style="flex:none;vertical-align:-0.125em;"><path d="M12.3,2C11.33,2.03 10.58,2.84 10.61,3.8C10.62,4.04 10.67,4.27 10.77,4.5L11.06,5.14C11.1,5.27 11.03,5.41 10.89,5.45C10.8,5.5 10.7,5.45 10.63,5.37L10.21,4.82C9.88,4.4 9.38,4.14 8.85,4.13C7.88,4.11 7.08,4.88 7.06,5.84C7.05,6.26 7.19,6.66 7.45,7L7.87,7.5C7.96,7.63 7.93,7.79 7.82,7.87C7.73,7.94 7.61,7.94 7.53,7.87L7,7.45C6.66,7.19 6.25,7.05 5.84,7.06C4.88,7.08 4.11,7.88 4.13,8.85C4.14,9.38 4.4,9.88 4.82,10.21L5.39,10.65C5.5,10.75 5.5,10.91 5.38,11C5.31,11.07 5.21,11.09 5.12,11.05L4.5,10.77C4.27,10.68 4.04,10.62 3.8,10.61C2.84,10.58 2.03,11.34 2,12.31C2,13.03 2.4,13.69 3.06,13.97L14.45,19.04L19.04,14.45L13.97,3.06C13.69,2.39 13,1.97 12.3,2M13.13,6.1C13.55,6.09 13.93,6.33 14.09,6.71L17.14,13.55L13.19,9.61L12.26,7.5C11.96,6.87 12.42,6.12 13.13,6.1M9.85,8.85C10.12,8.85 10.37,8.95 10.56,9.15L15.37,13.96C15.77,14.34 15.78,14.97 15.4,15.37C15,15.77 14.38,15.78 13.96,15.37L9.15,10.56C8.75,10.18 8.74,9.54 9.13,9.15C9.32,8.95 9.58,8.85 9.85,8.85M7.13,12.17C7.26,12.17 7.4,12.21 7.5,12.26L9.63,13.2L13.57,17.14L6.71,14.09C5.69,13.65 6.03,12.14 7.13,12.17M20.28,16.04L16.04,20.28L16.89,21.13C17.65,21.88 18.75,22.17 19.78,21.9C20.81,21.62 21.62,20.81 21.9,19.78C22.17,18.75 21.88,17.65 21.13,16.89L20.28,16.04Z"/></svg>';
    }
}

$is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$role = $_SESSION['role'] ?? '';
$is_admin = in_array($role, ['Admin', 'Head Manager'], true);
$dash_link = $is_logged_in ? ($is_admin ? '/admin/dashboard' : '/playerdashboard') : '/authentication/login';

if (!isset($cart_count) && isset($pdo) && isset($_SESSION['user_id'])) {
    try {
        $cart_stmt = $pdo->prepare("SELECT SUM(quantity) FROM Cart_Items WHERE user_id = ?");
        $cart_stmt->execute([$_SESSION['user_id']]);
        $cart_count = $cart_stmt->fetchColumn() ?: 0;
    } catch (PDOException $e) {
        $cart_count = 0;
    }
} elseif (!isset($cart_count)) {
    $cart_count = 0;
}

$announcements = [];
$announcement_count = 0;

if (isset($pdo)) {
    try {
        $user_audiences = ['All Users'];
        if ($is_logged_in) {
            if ($is_admin) {
                $user_audiences[] = 'Facility Staff';
            } elseif ($role === 'Player') {
                $user_audiences[] = 'Registered Players';
                $skill = $_SESSION['skill_level'] ?? $_SESSION['skill'] ?? 'Beginner';
                if (in_array($skill, ['Pro', 'Advanced'], true)) {
                    $user_audiences[] = 'Pro Athletes';
                }
            }
        }
        $placeholders = implode(',', array_fill(0, count($user_audiences), '?'));
        $today = date('Y-m-d');
        try {
            $stmt = $pdo->prepare("SELECT * FROM Announcements WHERE status != 'Scheduled' AND target_audience IN ($placeholders) AND (start_date IS NULL OR start_date <= ?) AND (end_date IS NULL OR end_date >= ?) ORDER BY created_at DESC LIMIT 5");
            $stmt->execute(array_merge($user_audiences, [$today, $today]));
        } catch (PDOException $e) {
            $stmt = $pdo->prepare("SELECT * FROM Announcements WHERE status != 'Scheduled' AND target_audience IN ($placeholders) ORDER BY created_at DESC LIMIT 5");
            $stmt->execute($user_audiences);
        }
        $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $announcement_count = count($announcements);
        $db_last_read = null;
        if ($is_logged_in) {
            try {
                $read_stmt = $pdo->prepare("SELECT last_notif_read_at FROM Users WHERE user_id = ?");
                $read_stmt->execute([$_SESSION['user_id']]);
                $db_last_read = $read_stmt->fetchColumn();
            } catch (PDOException $e) {
                $db_last_read = null;
            }
        }
        $last_read = $db_last_read ?: ($_SESSION['notif_read_at'] ?? null);
        if ($last_read) {
            $unread = 0;
            foreach ($announcements as $ann) {
                if (strtotime($ann['created_at']) > strtotime($last_read)) {
                    $unread++;
                }
            }
            $announcement_count = $unread;
        }
    } catch (PDOException $e) {
        $announcements = [];
    }
}
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="/assets/css/hipower.css">

<header class="sticky top-0 z-50 bg-[#080f17] backdrop-blur-xl border-b border-white/10" id="mainHeader">
    <nav class="flex justify-between items-center h-16 px-4 md:px-8 max-w-7xl mx-auto" aria-label="Main navigation">

        <!-- Brand -->
        <a href="/" class="flex items-center gap-2.5 shrink-0" aria-label="HI-POWER Badminton Court home">
            <i class="fa-solid fa-bolt text-[#ff5722] text-3xl" style="transform:skew(-12deg);" aria-hidden="true"></i>
            <span class="leading-none">
                <strong class="block text-[17px] font-extrabold tracking-tight text-white">HI-POWER</strong>
                <small class="block text-[6px] font-bold tracking-[2px] text-white/70 mt-1">BADMINTON COURT</small>
            </span>
        </a>

        <!-- Desktop links -->
        <div class="hidden md:flex items-center gap-8">
            <a class="<?php echo getNavClass('index.php', $current_page); ?> inline-flex items-center gap-2" href="/"<?php echo navCurrent('index.php', $current_page); ?>><i class="fa-solid fa-house text-sm" aria-hidden="true"></i>Home</a>
            <a class="<?php echo getNavClass('courtbooking.php', $current_page); ?> inline-flex items-center gap-2" href="/courtbooking"<?php echo navCurrent('courtbooking.php', $current_page); ?>><?php echo navBadmintonIcon(); ?>Courts</a>
            <a class="<?php echo getNavClass('ecommerce.php', $current_page); ?> inline-flex items-center gap-2" href="/ecommerce"<?php echo navCurrent('ecommerce.php', $current_page); ?>><i class="fa-solid fa-bag-shopping text-sm" aria-hidden="true"></i>Shop</a>
            <a class="<?php echo getNavClass('aimovementanalysis.php', $current_page); ?> inline-flex items-center gap-2" href="/aimovementanalysis"<?php echo navCurrent('aimovementanalysis.php', $current_page); ?>><i class="fa-solid fa-robot text-sm" aria-hidden="true"></i>AI Coach</a>
        </div>

        <!-- Right actions -->
        <div class="flex items-center gap-3 md:gap-5">

            <a href="/support.php?tab=contact" class="text-white/70 hover:text-[#ff5722] transition-colors hidden md:inline-flex" title="Help and live support" aria-label="Help and live support">
                <i class="fa-solid fa-headset text-xl" aria-hidden="true"></i>
            </a>

            <!-- Notifications -->
            <div class="relative">
                <button type="button" class="relative text-white/70 hover:text-white transition-colors active:scale-95" id="notifButton" onclick="toggleNotifications(event)" aria-label="View announcements" aria-haspopup="true" aria-expanded="false" aria-controls="notifDropdown">
                    <i class="fa-regular fa-bell text-xl" aria-hidden="true"></i>
                    <?php if ($announcement_count > 0): ?>
                        <span id="notifDot" class="absolute -top-1 -right-1 flex h-3 w-3">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-[#ff5722] opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-3 w-3 bg-[#ff5722] border-2 border-[#080f17]"></span>
                        </span>
                    <?php endif; ?>
                </button>

                <div id="notifDropdown" class="absolute right-0 mt-2 w-80 max-w-[calc(100vw-2rem)] bg-[#101c29] border border-white/10 rounded-xl shadow-xl overflow-hidden z-[60] origin-top-right hidden">
                    <div class="p-4 border-b border-white/10 bg-[#080f17] flex justify-between items-center gap-3">
                        <h2 class="text-sm font-bold text-white">Announcements</h2>
                        <div class="flex items-center gap-2">
                            <span id="notifNewCount" class="<?php echo $announcement_count > 0 ? '' : 'hidden'; ?> text-[10px] bg-[#ff5722] text-white px-2 py-0.5 rounded-full font-bold"><?php echo (int)$announcement_count; ?> New</span>
                            <button type="button" id="markAllReadBtn" onclick="markNotificationsRead(true)" class="<?php echo $announcement_count > 0 ? '' : 'hidden'; ?> text-[11px] text-[#ff5722] font-bold hover:underline">Mark all as read</button>
                        </div>
                    </div>
                    <div class="max-h-[300px] overflow-y-auto">
                        <?php if (empty($announcements)): ?>
                            <div class="p-6 text-center text-white/50 text-sm">You're all caught up!</div>
                        <?php else: ?>
                            <div class="divide-y divide-white/5">
                                <?php foreach ($announcements as $ann): ?>
                                    <?php $isHigh = (($ann['urgency'] ?? '') === 'High'); ?>
                                    <div class="p-4 hover:bg-white/5 transition-colors">
                                        <div class="flex gap-3">
                                            <div class="w-8 h-8 rounded-full flex items-center justify-center shrink-0 <?php echo $isHigh ? 'text-red-400 bg-red-500/10' : 'text-[#ff5722] bg-[#ff5722]/10'; ?>">
                                                <i class="fa-solid <?php echo $isHigh ? 'fa-bullhorn' : 'fa-circle-info'; ?> text-sm" aria-hidden="true"></i>
                                            </div>
                                            <div class="min-w-0">
                                                <?php if (!empty($ann['title'])): ?>
                                                    <p class="text-sm font-bold text-white leading-snug mb-0.5"><?php echo htmlspecialchars($ann['title']); ?></p>
                                                <?php endif; ?>
                                                <p class="text-sm text-white/60 leading-snug"><?php echo htmlspecialchars($ann['message']); ?></p>
                                                <div class="flex items-center gap-2 mt-2">
                                                    <span class="text-[10px] font-bold text-white/40 uppercase tracking-wider"><?php echo date('M d, h:i A', strtotime($ann['created_at'])); ?></span>
                                                    <?php if ($isHigh): ?>
                                                        <span class="text-[9px] text-red-400 font-bold uppercase tracking-widest border border-red-400/30 px-1.5 rounded">Urgent</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($is_logged_in): ?>
                <a href="/cart" class="relative text-white/70 hover:text-white transition-colors active:scale-95" aria-label="Shopping cart">
                    <i class="fa-solid fa-cart-shopping text-xl" aria-hidden="true"></i>
                    <?php if ($cart_count > 0): ?>
                        <span class="absolute -top-1.5 -right-2 bg-[#ff5722] text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full"><?php echo (int)$cart_count; ?></span>
                    <?php endif; ?>
                </a>

                <div class="relative ml-1">
                    <button type="button" class="text-white/70 hover:text-white transition-colors active:scale-95" id="profileButton" onclick="toggleProfileMenu(event)" aria-label="Open account menu" aria-haspopup="true" aria-expanded="false" aria-controls="profileDropdown">
                        <i class="fa-regular fa-circle-user text-2xl" aria-hidden="true"></i>
                    </button>
                    <div id="profileDropdown" class="absolute right-0 mt-2 w-56 bg-[#101c29] border border-white/10 rounded-xl shadow-xl overflow-hidden z-[60] origin-top-right hidden">
                        <div class="p-3 border-b border-white/10 bg-[#080f17]">
                            <p class="text-sm font-bold text-white truncate"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></p>
                            <p class="text-[11px] text-white/50 truncate"><?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?></p>
                        </div>
                        <div class="py-1">
                            <a href="/playerdashboard" class="flex items-center gap-3 px-4 py-2.5 text-sm text-white/70 hover:bg-white/5 hover:text-white transition-colors">
                                <i class="fa-solid fa-gauge-high text-sm" aria-hidden="true"></i> My Dashboard
                            </a>
                            <?php if ($is_admin): ?>
                                <a href="/admin/dashboard" class="flex items-center gap-3 px-4 py-2.5 text-sm text-white/70 hover:bg-white/5 hover:text-white transition-colors">
                                    <i class="fa-solid fa-shield-halved text-sm" aria-hidden="true"></i> Admin Panel
                                </a>
                            <?php endif; ?>
                            <div class="border-t border-white/10 my-1"></div>
                            <a href="/logout.php" class="flex items-center gap-3 px-4 py-2.5 text-sm text-red-400 hover:bg-red-500/10 transition-colors">
                                <i class="fa-solid fa-right-from-bracket text-sm" aria-hidden="true"></i> Sign Out
                            </a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <a href="/authentication/login" class="hidden md:inline-block font-bold text-sm text-white/70 hover:text-white transition-colors">Log In</a>
                <a href="/authentication/register" class="hidden md:inline-block bg-[#ff5722] hover:bg-[#ed4817] text-white px-5 py-2 rounded-lg text-sm font-bold active:scale-95 transition-colors">Register</a>
            <?php endif; ?>

            <button type="button" class="md:hidden text-white/70 hover:text-white transition-colors" id="mobileMenuToggle" onclick="toggleMobileMenu()" aria-label="Open menu" aria-controls="mobile-menu" aria-expanded="false">
                <i class="fa-solid fa-bars text-xl" aria-hidden="true"></i>
            </button>
        </div>
    </nav>

    <!-- Mobile menu -->
    <div id="mobile-menu" class="hidden md:hidden bg-[#080f17] border-t border-white/10 px-4 pb-5 pt-2">
        <div class="flex flex-col">
            <a class="py-3 text-sm font-semibold flex items-center gap-2 <?php echo $current_page === 'index.php' ? 'text-[#ff5722]' : 'text-white/80'; ?>" href="/"<?php echo navCurrent('index.php', $current_page); ?>><i class="fa-solid fa-house" aria-hidden="true"></i>Home</a>
            <a class="py-3 text-sm font-semibold flex items-center gap-2 <?php echo $current_page === 'courtbooking.php' ? 'text-[#ff5722]' : 'text-white/80'; ?>" href="/courtbooking"<?php echo navCurrent('courtbooking.php', $current_page); ?>><?php echo navBadmintonIcon(); ?>Courts</a>
            <a class="py-3 text-sm font-semibold flex items-center gap-2 <?php echo $current_page === 'ecommerce.php' ? 'text-[#ff5722]' : 'text-white/80'; ?>" href="/ecommerce"<?php echo navCurrent('ecommerce.php', $current_page); ?>><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i>Shop</a>
            <a class="py-3 text-sm font-semibold flex items-center gap-2 <?php echo $current_page === 'aimovementanalysis.php' ? 'text-[#ff5722]' : 'text-white/80'; ?>" href="/aimovementanalysis"<?php echo navCurrent('aimovementanalysis.php', $current_page); ?>><i class="fa-solid fa-robot" aria-hidden="true"></i>AI Coach</a>
            <a class="py-3 text-sm font-semibold text-white/80" href="/support.php">Help Center</a>
            <div class="border-t border-white/10 mt-2 pt-3 flex items-center gap-3">
                <?php if ($is_logged_in): ?>
                    <a href="<?php echo htmlspecialchars($dash_link); ?>" class="flex-1 text-center bg-white/10 hover:bg-white/15 text-white py-2.5 rounded-lg text-sm font-bold">My Dashboard</a>
                    <a href="/logout.php" class="flex-1 text-center border border-red-400/40 text-red-300 py-2.5 rounded-lg text-sm font-bold">Sign Out</a>
                <?php else: ?>
                    <a href="/authentication/login" class="flex-1 text-center border border-white/25 text-white py-2.5 rounded-lg text-sm font-bold">Log In</a>
                    <a href="/authentication/register" class="flex-1 text-center bg-[#ff5722] text-white py-2.5 rounded-lg text-sm font-bold">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>

<script>
    (function () {
        const header = document.getElementById('mainHeader');
        if (header) {
            window.addEventListener('scroll', function () {
                header.classList.toggle('shadow-lg', window.scrollY > 10);
            }, { passive: true });
        }
    })();

    function toggleMobileMenu() {
        const menu = document.getElementById('mobile-menu');
        const btn = document.getElementById('mobileMenuToggle');
        if (!menu || !btn) return;
        const open = menu.classList.toggle('hidden') === false;
        btn.setAttribute('aria-expanded', String(open));
        btn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        const icon = btn.querySelector('i');
        if (icon) icon.className = open ? 'fa-solid fa-xmark text-xl' : 'fa-solid fa-bars text-xl';
    }

    function toggleNotifications(e) {
        if (e) e.stopPropagation();
        const dropdown = document.getElementById('notifDropdown');
        const profileDrop = document.getElementById('profileDropdown');
        const btn = document.getElementById('notifButton');
        if (profileDrop && !profileDrop.classList.contains('hidden')) profileDrop.classList.add('hidden');
        const open = dropdown.classList.toggle('hidden') === false;
        if (btn) btn.setAttribute('aria-expanded', String(open));
        if (open) markNotificationsRead(false);
    }

    function toggleProfileMenu(e) {
        if (e) e.stopPropagation();
        const dropdown = document.getElementById('profileDropdown');
        const notifDrop = document.getElementById('notifDropdown');
        const btn = document.getElementById('profileButton');
        if (notifDrop && !notifDrop.classList.contains('hidden')) notifDrop.classList.add('hidden');
        const open = dropdown.classList.toggle('hidden') === false;
        if (btn) btn.setAttribute('aria-expanded', String(open));
    }

    function markNotificationsRead() {
        fetch('/includes/mark_notifications_read.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d && d.success) {
                ['notifDot', 'notifNewCount', 'markAllReadBtn'].forEach(function (id) {
                    const el = document.getElementById(id);
                    if (el) el.classList.add('hidden');
                });
            }
        })
        .catch(function () {});
    }

    document.addEventListener('click', function (event) {
        ['notifDropdown', 'profileDropdown'].forEach(function (id) {
            const el = document.getElementById(id);
            if (el && !el.classList.contains('hidden') && !el.contains(event.target)) {
                el.classList.add('hidden');
            }
        });
        const notifBtn = document.getElementById('notifButton');
        if (notifBtn && !notifBtn.contains(event.target)) notifBtn.setAttribute('aria-expanded', 'false');
        const profileBtn = document.getElementById('profileButton');
        if (profileBtn && !profileBtn.contains(event.target)) profileBtn.setAttribute('aria-expanded', 'false');
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        ['notifDropdown', 'profileDropdown', 'mobile-menu'].forEach(function (id) {
            const el = document.getElementById(id);
            if (el) el.classList.add('hidden');
        });
    });
</script>
