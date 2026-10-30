<?php
// admin/includes/admin_header.php — HI-POWER admin chrome.
$current_page = basename($_SERVER['PHP_SELF']);
$admin_name_nav = $_SESSION['full_name'] ?? 'Admin';
$admin_role_nav = $_SESSION['role'] ?? 'Manager';

$nav_items = [
    'dashboard.php' => ['icon' => 'dashboard', 'label' => 'Dashboard'],
    'court_status.php' => ['icon' => 'stadium', 'label' => 'Courts'],
    'inventory.php' => ['icon' => 'inventory_2', 'label' => 'Inventory'],
    'analytics.php' => ['icon' => 'analytics', 'label' => 'Analytics'],
    'ai_lab.php' => ['icon' => 'psychology', 'label' => 'AI Lab'],
    'announcements.php' => ['icon' => 'campaign', 'label' => 'Announcements'],
    'user_orders.php' => ['icon' => 'receipt_long', 'label' => 'Orders'],
    'user_bookings.php' => ['icon' => 'calendar_month', 'label' => 'Bookings'],
    'payments.php' => ['icon' => 'account_balance', 'label' => 'Bank Payments'],
    'refunds.php' => ['icon' => 'currency_exchange', 'label' => 'Refunds'],
    'support.php' => ['icon' => 'headset', 'label' => 'Support']
];

$admin_notifications = [];
if (isset($pdo)) {
    try {
        $stock_stmt = $pdo->query("SELECT product_id, name, stock_quantity FROM Products WHERE stock_quantity <= 5 AND is_active = 1 LIMIT 5");
        while ($row = $stock_stmt->fetch()) {
            $admin_notifications[] = ['icon' => 'warning', 'color' => 'text-amber-600 bg-amber-50 border-amber-200', 'title' => 'Low Stock', 'desc' => htmlspecialchars($row['name']) . ' (' . $row['stock_quantity'] . ' left)', 'time' => 'Action Needed', 'url' => 'inventory.php'];
        }
        $order_stmt = $pdo->query("SELECT order_number, order_date FROM Orders WHERE status = 'Pending' ORDER BY order_id DESC LIMIT 4");
        while ($row = $order_stmt->fetch()) {
            $admin_notifications[] = ['icon' => 'shopping_bag', 'color' => 'text-primary bg-primary/5 border-primary/20', 'title' => 'Pending Order', 'desc' => '#' . htmlspecialchars($row['order_number']), 'time' => date('M d', strtotime($row['order_date'])), 'url' => 'user_orders.php'];
        }
        $book_stmt = $pdo->query("SELECT booking_reference, booking_date FROM Bookings WHERE status = 'Pending' ORDER BY booking_id DESC LIMIT 4");
        while ($row = $book_stmt->fetch()) {
            $admin_notifications[] = ['icon' => 'event_available', 'color' => 'text-emerald-600 bg-emerald-50 border-emerald-200', 'title' => 'New Booking', 'desc' => $row['booking_reference'], 'time' => date('M d', strtotime($row['booking_date'])), 'url' => 'user_bookings.php'];
        }
        try {
            $refund_stmt = $pdo->query("SELECT refund_id, amount FROM refunds WHERE status = 'Pending' ORDER BY refund_id DESC LIMIT 4");
            while ($row = $refund_stmt->fetch()) {
                $admin_notifications[] = ['icon' => 'currency_exchange', 'color' => 'text-purple-600 bg-purple-50 border-purple-200', 'title' => 'Refund Request', 'desc' => '₱' . number_format($row['amount'], 2), 'time' => 'Action Needed', 'url' => 'refunds.php'];
            }
        } catch (PDOException $e) { /* refunds table not migrated yet */ }
    } catch (PDOException $e) {}
}
$admin_alert_count = count($admin_notifications);
?>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap');
    .admin-sidebar-link { transition: all 0.15s ease; }
    .admin-sidebar-link:hover { transform: translateX(4px); }
    .admin-sidebar-link.active {
        background: linear-gradient(135deg, rgba(255,87,34,0.18), rgba(255,87,34,0.06));
        box-shadow: inset 3px 0 0 #ff5722;
        color: #ff5722;
        font-weight: 700;
    }
    .admin-sidebar-link:focus-visible { outline: 2px solid #ff5722; outline-offset: 2px; }

    :root { --hp-orange:#FF5722; --hp-charcoal:#080F17; --hp-midnight:#101C29; --hp-white:#FFFFFF; --hp-bg:#F4F6F8; --hp-navy:#14264A; --hp-slate:#64748B; --hp-green:#087F5B; --hp-amber:#F59E0B; }
    body { font-family:'Poppins',sans-serif !important; background:var(--hp-bg) !important; color:var(--hp-navy); }
    main { color:var(--hp-navy); }
    main h1 { letter-spacing:-.035em; }
    main .bg-white, main .bg-surface-container-lowest, main .bg-surface-container-low, main .bg-surface-container { border-radius:16px; }
    main .shadow-sm { box-shadow:0 5px 18px rgba(20,38,74,.055) !important; }
    main button, main a { transition:background-color .18s ease,color .18s ease,border-color .18s ease,transform .18s ease; }
    main button:focus-visible, main a:focus-visible, main input:focus-visible, main select:focus-visible { outline:3px solid rgba(255,87,34,.3); outline-offset:2px; }
    main table thead th { background:#F8FAFC; color:#64748B; font-size:10px; letter-spacing:.09em; }
    main table tbody tr:hover { background:#FFF7F3; }
    .hp-page-actions { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
    .hp-action { display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:40px; padding:9px 15px; border-radius:11px; border:1px solid #DCE3EA; background:#fff; color:#14264A; font-size:12px; font-weight:700; cursor:pointer; text-decoration:none; }
    .hp-action:hover { background:#FFF1EB; border-color:#FFB39A; }
    .hp-action--primary { background:#FF5722; border-color:#FF5722; color:#fff; }
    .hp-action--primary:hover { background:#E64A19; color:#fff; }
    /* HI-POWER admin consistency and contrast fixes */
    *, *::before, *::after { box-sizing:border-box; }
    body { overflow-x:hidden; }
    main { min-width:0; width:auto; max-width:100%; overflow-wrap:anywhere; }
    main section, main article, main .rounded-xl, main .rounded-2xl { min-width:0; }
    main h1, main h2, main h3, main h4 { color:#14264A; }
    main p { line-height:1.65; }
    main .text-white { color:#FFFFFF !important; }
    main .text-on-surface, main .text-on-background { color:#14264A !important; }
    main .text-outline, main .text-slate-500, main .text-gray-500 { color:#64748B !important; }
    main input, main select, main textarea { color:#14264A; background-color:#FFFFFF; border-color:#CBD5E1; }
    main input::placeholder, main textarea::placeholder { color:#94A3B8; opacity:1; }
    main table { width:100%; border-collapse:separate; border-spacing:0; }
    main .overflow-x-auto { max-width:100%; }
    main > footer { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px 24px; width:100%; min-height:64px; padding:20px 0 28px; color:#64748B; line-height:1.5; }
    main > footer p { margin:0; }
    main > footer a { color:#475569; }
    main > footer a:hover { color:#FF5722; }
    .hp-ai-recommendation { color:#FFFFFF !important; background:linear-gradient(120deg,#080F17 0%,#101C29 72%,#14264A 100%) !important; border:1px solid #26384A !important; }
    .hp-ai-recommendation h2, .hp-ai-recommendation h4 { color:#FFFFFF !important; }
    .hp-ai-recommendation .hp-ai-description { color:#D7E0EB !important; }
    .hp-ai-recommendation .hp-ai-muted { color:#B9C7D8 !important; }
    .hp-ai-recommendation .hp-ai-impact { background:rgba(255,255,255,.07) !important; border:1px solid rgba(255,255,255,.16) !important; }
    .hp-ai-recommendation .hp-ai-secondary { color:#FFFFFF !important; border-color:#718096 !important; background:rgba(255,255,255,.04) !important; }
    .hp-ai-recommendation .hp-ai-secondary:hover { background:rgba(255,255,255,.12) !important; }
    @media (max-width: 640px) { main > footer { align-items:flex-start; flex-direction:column; } main > footer > div { flex-wrap:wrap; gap:12px; } }
    @media print { #admin-sidebar, #admin-mobile-overlay, .no-print, .hp-page-actions, button { display:none !important; } main { margin:0 !important; padding:0 !important; max-width:none !important; } body { background:#fff !important; } .hp-ai-recommendation { print-color-adjust:exact; -webkit-print-color-adjust:exact; } }

</style>

<div id="admin-mobile-overlay" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-40 hidden md:hidden transition-opacity opacity-0" onclick="toggleAdminSidebar()" aria-hidden="true"></div>

<aside id="admin-sidebar" class="fixed left-0 top-0 bottom-0 w-[260px] bg-dark border-r border-white/10 flex flex-col z-50 transform -translate-x-full md:translate-x-0 transition-transform duration-300 ease-in-out shadow-xl" aria-label="Admin navigation">

    <!-- Brand -->
    <div class="px-5 pt-5 pb-4">
        <div class="flex items-center justify-between mb-5">
            <a href="/" class="flex items-center gap-2.5 group" aria-label="HI-POWER home">
                <div class="w-9 h-9 rounded-xl bg-primary flex items-center justify-center shadow-md group-hover:scale-105 transition-transform">
                    <i class="fa-solid fa-bolt text-white text-[19px]" style="transform:skew(-12deg);" aria-hidden="true"></i>
                </div>
                <div>
                    <span class="text-base font-extrabold tracking-tight text-white">HI-POWER</span>
                    <p class="text-[9px] text-white/50 font-bold uppercase tracking-[0.2em] -mt-0.5">Admin Panel</p>
                </div>
            </a>
            <button class="md:hidden w-8 h-8 rounded-lg hover:bg-white/10 flex items-center justify-center text-white transition-colors" onclick="toggleAdminSidebar()" aria-label="Close admin menu">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>

        <!-- Admin profile -->
        <div class="bg-white/5 rounded-xl p-3 border border-white/10">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-primary flex items-center justify-center text-white shadow-sm shrink-0">
                    <span class="material-symbols-outlined text-[20px]">person</span>
                </div>
                <div class="overflow-hidden min-w-0">
                    <p class="font-bold text-sm text-white truncate" title="<?php echo htmlspecialchars($admin_name_nav); ?>"><?php echo htmlspecialchars($admin_name_nav); ?></p>
                    <p class="text-[10px] text-primary font-bold uppercase tracking-widest truncate"><?php echo htmlspecialchars($admin_role_nav); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick actions -->
    <div class="px-4 mb-3 flex gap-2">
        <a href="/" class="flex-1 flex items-center justify-center gap-1.5 py-2 bg-white/5 hover:bg-white/10 border border-white/10 hover:border-primary/40 rounded-lg text-[11px] font-bold text-white/70 hover:text-white transition-all">
            <span class="material-symbols-outlined text-[16px]">home</span> Home
        </a>
        <button type="button" onclick="toggleAdminNotifications(event)" id="desktopNotifBtn" class="flex-1 flex items-center justify-center gap-1.5 py-2 bg-white/5 hover:bg-white/10 border border-white/10 hover:border-primary/40 rounded-lg text-[11px] font-bold text-white/70 hover:text-white transition-all relative" aria-label="View admin alerts" aria-haspopup="true" aria-expanded="false" aria-controls="adminNotifPanel">
            <span class="material-symbols-outlined text-[16px]">notifications</span> Alerts
            <?php if ($admin_alert_count > 0): ?>
                <span class="absolute -top-1 -right-1 w-4 h-4 bg-primary text-white text-[9px] font-bold rounded-full flex items-center justify-center shadow-sm"><?php echo (int)$admin_alert_count; ?></span>
            <?php endif; ?>
        </button>
    </div>

    <!-- Navigation -->
    <nav class="flex-grow flex flex-col gap-0.5 px-3 overflow-y-auto custom-scrollbar pb-4">
        <p class="text-[9px] font-bold text-white/40 uppercase tracking-[0.2em] px-3 pt-2 pb-1.5">Navigation</p>
        <?php foreach ($nav_items as $url => $data):
            $is_active = ($current_page === $url);
            $icon_fill = $is_active ? "'FILL' 1" : "'FILL' 0";
        ?>
            <a class="admin-sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-[13px] <?php echo $is_active ? 'active' : 'text-white/60 hover:bg-white/5 hover:text-white'; ?>" href="<?php echo $url; ?>"<?php echo $is_active ? ' aria-current="page"' : ''; ?>>
                <span class="material-symbols-outlined text-[20px]" style="font-variation-settings: <?php echo $icon_fill; ?>;" aria-hidden="true"><?php echo $data['icon']; ?></span>
                <span><?php echo $data['label']; ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- Logout -->
    <div class="mt-auto px-4 pt-3 border-t border-white/10 pb-4">
        <button type="button" onclick="toggleAdminLogoutModal()" class="w-full flex items-center gap-3 text-red-300 hover:text-red-200 hover:bg-red-500/10 px-3 py-2.5 rounded-lg transition-all text-[13px] font-bold">
            <span class="material-symbols-outlined text-[20px]">logout</span> Sign Out
        </button>
    </div>
</aside>

<!-- Mobile header -->
<header class="md:hidden fixed top-0 right-0 left-0 h-14 bg-dark/95 backdrop-blur-xl border-b border-white/10 shadow-sm z-30 flex justify-between items-center px-4">
    <div class="flex items-center gap-3">
        <button class="w-9 h-9 rounded-lg hover:bg-white/10 flex items-center justify-center text-white transition-all" onclick="toggleAdminSidebar()" aria-label="Open admin menu">
            <span class="material-symbols-outlined">menu</span>
        </button>
        <div class="flex items-center gap-2">
            <div class="w-7 h-7 rounded-lg bg-primary flex items-center justify-center">
                <i class="fa-solid fa-bolt text-white text-[14px]" style="transform:skew(-12deg);" aria-hidden="true"></i>
            </div>
            <span class="text-sm font-extrabold text-white">HI-POWER</span>
        </div>
    </div>
    <div class="flex items-center gap-2">
        <a href="/" class="w-9 h-9 rounded-lg hover:bg-white/10 flex items-center justify-center text-white/70 transition-all" aria-label="Go to website">
            <span class="material-symbols-outlined text-[20px]">home</span>
        </a>
        <button type="button" id="mobileNotifBtn" onclick="toggleAdminNotifications(event)" class="w-9 h-9 rounded-lg hover:bg-white/10 flex items-center justify-center text-white/70 relative transition-all" aria-label="View admin alerts" aria-haspopup="true" aria-expanded="false" aria-controls="adminNotifPanel">
            <span class="material-symbols-outlined text-[20px]">notifications</span>
            <?php if ($admin_alert_count > 0): ?>
                <span class="absolute top-1.5 right-1.5 w-2 h-2 bg-primary rounded-full animate-pulse"></span>
            <?php endif; ?>
        </button>
    </div>
</header>

<!-- Notification panel (light card for readability) -->
<div id="adminNotifPanel" class="hidden fixed top-20 left-4 right-4 md:left-[280px] md:right-auto md:w-80 bg-white border border-outline-variant/50 rounded-2xl shadow-2xl z-[150] overflow-hidden flex flex-col max-h-[70vh]">
    <div class="p-4 border-b border-outline-variant/30 bg-surface flex justify-between items-center shrink-0">
        <h2 class="text-sm font-bold text-on-surface flex items-center gap-2">
            <span class="material-symbols-outlined text-primary text-[18px]">notifications_active</span> Alerts
        </h2>
        <?php if ($admin_alert_count > 0): ?>
            <span class="text-[10px] bg-accent text-white px-2 py-0.5 rounded-full font-bold"><?php echo (int)$admin_alert_count; ?> New</span>
        <?php endif; ?>
    </div>
    <div class="overflow-y-auto custom-scrollbar flex-grow">
        <?php if (empty($admin_notifications)): ?>
            <div class="p-8 text-center text-on-surface-variant flex flex-col items-center">
                <span class="material-symbols-outlined text-[40px] text-outline-variant mb-2">check_circle</span>
                <p class="text-sm font-bold">All clear!</p>
                <p class="text-[11px]">No pending items.</p>
            </div>
        <?php else: ?>
            <div class="divide-y divide-outline-variant/20">
                <?php foreach ($admin_notifications as $notif): ?>
                    <a href="<?php echo $notif['url']; ?>" class="p-4 flex gap-3 hover:bg-surface-container-low transition-colors group">
                        <div class="w-9 h-9 rounded-full flex items-center justify-center shrink-0 border <?php echo $notif['color']; ?>">
                            <span class="material-symbols-outlined text-[16px]"><?php echo $notif['icon']; ?></span>
                        </div>
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold tracking-widest uppercase text-outline group-hover:text-primary transition-colors"><?php echo $notif['title']; ?></p>
                            <p class="text-sm font-bold text-on-surface leading-tight mt-0.5 truncate"><?php echo $notif['desc']; ?></p>
                            <p class="text-[11px] text-outline mt-1"><?php echo $notif['time']; ?></p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Logout modal -->
<div id="adminLogoutModal" class="hidden fixed inset-0 z-[200] flex items-center justify-center px-4" role="dialog" aria-modal="true" aria-labelledby="adminLogoutTitle">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="toggleAdminLogoutModal()"></div>
    <div class="relative bg-white w-full max-w-sm rounded-2xl border border-outline-variant/50 shadow-2xl overflow-hidden z-10 scale-95 opacity-0 transition-all duration-300" id="adminLogoutModalContent">
        <div class="p-8 text-center">
            <div class="w-16 h-16 bg-error/10 text-error rounded-2xl flex items-center justify-center mx-auto mb-4">
                <span class="material-symbols-outlined text-3xl">logout</span>
            </div>
            <h2 id="adminLogoutTitle" class="text-xl font-bold text-on-surface mb-2">Sign Out?</h2>
            <p class="text-sm text-on-surface-variant mb-8">You'll be redirected to the homepage.</p>
            <div class="flex gap-3">
                <button type="button" onclick="toggleAdminLogoutModal()" class="flex-1 py-3 bg-surface-container text-on-surface-variant border border-outline-variant/50 rounded-xl font-bold text-xs hover:bg-surface-container-high transition-colors">Cancel</button>
                <a href="/logout.php?type=admin" class="flex-1 py-3 bg-error text-white rounded-xl font-bold text-xs hover:bg-error/90 active:scale-95 transition-all shadow-md text-center">Sign Out</a>
            </div>
        </div>
    </div>
</div>

<script>
    function toggleAdminSidebar() {
        const sidebar = document.getElementById('admin-sidebar');
        const overlay = document.getElementById('admin-mobile-overlay');
        if (!sidebar || !overlay) return;
        if (sidebar.classList.contains('-translate-x-full')) {
            sidebar.classList.remove('-translate-x-full');
            overlay.classList.remove('hidden');
            setTimeout(() => overlay.classList.remove('opacity-0'), 10);
            document.body.style.overflow = 'hidden';
        } else {
            sidebar.classList.add('-translate-x-full');
            overlay.classList.add('opacity-0');
            setTimeout(() => overlay.classList.add('hidden'), 300);
            document.body.style.overflow = '';
        }
    }

    function toggleAdminNotifications(event) {
        if (event) event.stopPropagation();
        const panel = document.getElementById('adminNotifPanel');
        if (!panel) return;
        const open = panel.classList.toggle('hidden') === false;
        const deskBtn = document.getElementById('desktopNotifBtn');
        const mobBtn = document.getElementById('mobileNotifBtn');
        if (deskBtn) deskBtn.setAttribute('aria-expanded', String(open));
        if (mobBtn) mobBtn.setAttribute('aria-expanded', String(open));
    }

    document.addEventListener('click', function (event) {
        const panel = document.getElementById('adminNotifPanel');
        const deskBtn = document.getElementById('desktopNotifBtn');
        const mobBtn = document.getElementById('mobileNotifBtn');
        if (panel && !panel.classList.contains('hidden')) {
            if (!panel.contains(event.target) && (!deskBtn || !deskBtn.contains(event.target)) && (!mobBtn || !mobBtn.contains(event.target))) {
                panel.classList.add('hidden');
                if (deskBtn) deskBtn.setAttribute('aria-expanded', 'false');
                if (mobBtn) mobBtn.setAttribute('aria-expanded', 'false');
            }
        }
    });

    function toggleAdminLogoutModal() {
        const modal = document.getElementById('adminLogoutModal');
        const content = document.getElementById('adminLogoutModalContent');
        if (!modal || !content) return;
        if (modal.classList.contains('hidden')) {
            modal.classList.remove('hidden');
            setTimeout(() => { content.classList.remove('scale-95', 'opacity-0'); content.classList.add('scale-100', 'opacity-100'); }, 10);
        } else {
            content.classList.remove('scale-100', 'opacity-100');
            content.classList.add('scale-95', 'opacity-0');
            setTimeout(() => modal.classList.add('hidden'), 300);
        }
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            const modal = document.getElementById('adminLogoutModal');
            if (modal && !modal.classList.contains('hidden')) toggleAdminLogoutModal();
            const panel = document.getElementById('adminNotifPanel');
            if (panel) panel.classList.add('hidden');
        }
    });
</script>
