<?php
require_once __DIR__ . '/../includes/session_boot.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header("Location: /authentication/login"); exit; }
if ($_SESSION['role'] !== 'Admin' && $_SESSION['role'] !== 'Head Manager') { header("Location: ../playerdashboard.php"); exit; }

require_once __DIR__ . '/../includes/database_connect.php';
$admin_name = $_SESSION['full_name'];
$admin_role = $_SESSION['role'];

// Metrics
// Total revenue = settled shop orders (paid, not cancelled) + paid court bookings (TC-AS-042)
$shop_revenue  = (float)($pdo->query("SELECT SUM(total_amount) FROM Orders WHERE status NOT IN ('Cancelled', 'Pending Payment')")->fetchColumn() ?? 0);
$court_revenue = (float)($pdo->query("SELECT SUM(total_price) FROM Bookings WHERE status IN ('Confirmed', 'Completed', 'Paid')")->fetchColumn() ?? 0);
$total_revenue = $shop_revenue + $court_revenue;

// Total booking records (TC-AS-041)
$total_bookings = $pdo->query("SELECT COUNT(*) FROM Bookings WHERE status != 'In Cart'")->fetchColumn() ?? 0;
$pending_orders = $pdo->query("SELECT COUNT(*) FROM Orders WHERE status IN ('Pending', 'Pending Payment', 'Confirmed', 'Processing')")->fetchColumn() ?? 0;
$total_users    = $pdo->query("SELECT COUNT(*) FROM Users WHERE role = 'Player'")->fetchColumn() ?? 0;

// Pending action counters (TC-AS-043)
$pending_bookings = $pdo->query("SELECT COUNT(*) FROM Bookings WHERE status IN ('Pending', 'Pending Payment')")->fetchColumn() ?? 0;
$pending_payments = 0;
try { $pending_payments = $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'Pending Payment'")->fetchColumn() ?? 0; } catch (PDOException $e) {}
$pending_refunds = 0;
try { $pending_refunds = $pdo->query("SELECT COUNT(*) FROM refunds WHERE status = 'Pending'")->fetchColumn() ?? 0; } catch (PDOException $e) {}

$courts = $pdo->query("SELECT court_id, name, status FROM Courts ORDER BY court_id ASC")->fetchAll();

try { $recent_products = $pdo->query("SELECT product_id, name, category, price, stock_quantity, is_active FROM Products ORDER BY product_id DESC LIMIT 5")->fetchAll(); } catch(PDOException $e) { $recent_products = []; }
try { $recent_orders = $pdo->query("SELECT o.order_id, o.order_date, o.total_amount, o.status, u.full_name FROM Orders o JOIN Users u ON o.user_id = u.user_id ORDER BY o.order_date DESC LIMIT 5")->fetchAll(); } catch(PDOException $e) { $recent_orders = []; }
try { $recent_bookings = $pdo->query("SELECT b.booking_reference, b.booking_date, b.start_time, b.end_time, b.status, b.total_price, c.name as court_name, u.full_name FROM Bookings b JOIN Courts c ON b.court_id = c.court_id JOIN Users u ON b.user_id = u.user_id ORDER BY b.booking_date DESC, b.start_time DESC LIMIT 5")->fetchAll(); } catch(PDOException $e) { $recent_bookings = []; }

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="hi-power-admin-summary-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Metric', 'Value']);
    fputcsv($out, ['Report date', date('Y-m-d')]);
    fputcsv($out, ['Total revenue (PHP)', number_format($total_revenue, 2, '.', '')]);
    fputcsv($out, ['Total bookings', $total_bookings]);
    fputcsv($out, ['Pending orders', $pending_orders]);
    fputcsv($out, ['Registered players', $total_users]);
    fputcsv($out, ['Pending bookings', $pending_bookings]);
    fputcsv($out, ['Pending payments', $pending_payments]);
    fputcsv($out, ['Pending refunds', $pending_refunds]);
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Dashboard | HI-POWER Admin</title>
    <?php include __DIR__ . '/../includes/tailwind_config.php'; ?>
    <style>
        @keyframes countUp { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        .stat-card { animation: countUp 0.4s ease-out forwards; }
        .stat-card:nth-child(2) { animation-delay: 0.05s; }
        .stat-card:nth-child(3) { animation-delay: 0.1s; }
        .stat-card:nth-child(4) { animation-delay: 0.15s; }
    </style>
</head>
<body class="bg-surface text-on-background min-h-screen">

<?php include __DIR__ . '/includes/admin_header.php'; ?>

<main class="md:ml-[260px] p-4 md:p-8 min-h-screen">
    
    <!-- Header -->
    <div class="mb-8">
        <p class="text-xs font-bold text-primary uppercase tracking-[0.2em] mb-1">Welcome back</p>
        <h1 class="text-2xl md:text-3xl font-bold text-on-surface"><?php echo htmlspecialchars($admin_name); ?></h1>
        <p class="text-sm text-on-surface-variant mt-1">Here's your facility overview for today.</p>
        <div class="hp-page-actions mt-4 no-print">
            <a href="dashboard.php?export=csv" class="hp-action hp-action--primary"><span class="material-symbols-outlined">download</span> Export CSV</a>
            <button type="button" onclick="window.print()" class="hp-action"><span class="material-symbols-outlined">print</span> Print Dashboard</button>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="stat-card bg-white rounded-2xl p-5 border border-outline-variant/30 shadow-sm hover:shadow-md transition-shadow group">
            <div class="flex items-center justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center">
                    <span class="material-symbols-outlined text-primary text-[22px]">payments</span>
                </div>
                <span class="text-[10px] font-bold text-green-600 bg-green-50 px-2 py-0.5 rounded-full uppercase tracking-wider">Revenue</span>
            </div>
            <p class="text-2xl font-bold text-on-surface">₱<?php echo number_format($total_revenue, 2); ?></p>
            <p class="text-[11px] text-outline mt-1">Total Revenue (Shop + Courts)</p>
        </div>

        <div class="stat-card bg-white rounded-2xl p-5 border border-outline-variant/30 shadow-sm hover:shadow-md transition-shadow group">
            <div class="flex items-center justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center">
                    <span class="material-symbols-outlined text-emerald-600 text-[22px]">stadium</span>
                </div>
                <span class="text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full uppercase tracking-wider">Bookings</span>
            </div>
            <p class="text-2xl font-bold text-on-surface"><?php echo $total_bookings; ?></p>
            <p class="text-[11px] text-outline mt-1">Total Bookings</p>
        </div>

        <div class="stat-card bg-white rounded-2xl p-5 border border-outline-variant/30 shadow-sm hover:shadow-md transition-shadow group">
            <div class="flex items-center justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center">
                    <span class="material-symbols-outlined text-amber-600 text-[22px]">shopping_bag</span>
                </div>
                <span class="text-[10px] font-bold text-amber-600 bg-amber-50 px-2 py-0.5 rounded-full uppercase tracking-wider">Pending</span>
            </div>
            <p class="text-2xl font-bold text-on-surface"><?php echo $pending_orders; ?></p>
            <p class="text-[11px] text-outline mt-1">Pending Orders</p>
        </div>

        <div class="stat-card bg-white rounded-2xl p-5 border border-outline-variant/30 shadow-sm hover:shadow-md transition-shadow group">
            <div class="flex items-center justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-violet-50 flex items-center justify-center">
                    <span class="material-symbols-outlined text-violet-600 text-[22px]">group</span>
                </div>
                <span class="text-[10px] font-bold text-violet-600 bg-violet-50 px-2 py-0.5 rounded-full uppercase tracking-wider">Players</span>
            </div>
            <p class="text-2xl font-bold text-on-surface"><?php echo $total_users; ?></p>
            <p class="text-[11px] text-outline mt-1">Registered Players</p>
        </div>
    </div>

    <!-- Pending Actions (TC-AS-043) -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
        <a href="user_bookings.php" class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-4 flex items-center gap-4 hover:shadow-md transition-shadow">
            <div class="w-11 h-11 rounded-xl bg-emerald-50 flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-emerald-600">event_available</span>
            </div>
            <div class="min-w-0">
                <p class="text-[11px] font-bold text-outline uppercase tracking-widest">Pending Bookings</p>
                <p class="text-xl font-bold text-on-surface"><?php echo number_format($pending_bookings); ?></p>
            </div>
        </a>
        <a href="payments.php" class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-4 flex items-center gap-4 hover:shadow-md transition-shadow">
            <div class="w-11 h-11 rounded-xl bg-amber-50 flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-amber-600">account_balance</span>
            </div>
            <div class="min-w-0">
                <p class="text-[11px] font-bold text-outline uppercase tracking-widest">Payments to Verify</p>
                <p class="text-xl font-bold text-on-surface"><?php echo number_format($pending_payments); ?></p>
            </div>
        </a>
        <a href="refunds.php" class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-4 flex items-center gap-4 hover:shadow-md transition-shadow">
            <div class="w-11 h-11 rounded-xl bg-purple-50 flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-purple-600">currency_exchange</span>
            </div>
            <div class="min-w-0">
                <p class="text-[11px] font-bold text-outline uppercase tracking-widest">Refund Requests</p>
                <p class="text-xl font-bold text-on-surface"><?php echo number_format($pending_refunds); ?></p>
            </div>
        </a>
    </div>

    <!-- Court Status + AI Lab -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <section class="lg:col-span-2 bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-6">
            <div class="flex justify-between items-center mb-5">
                <div>
                    <h2 class="text-lg font-bold text-on-surface">Court Status</h2>
                    <p class="text-xs text-outline mt-0.5">Real-time facility overview</p>
                </div>
                <a href="court_status" class="text-xs font-bold text-primary hover:underline flex items-center gap-1">
                    Manage <span class="material-symbols-outlined text-[14px]">arrow_forward</span>
                </a>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <?php 
                $dashboard_courts = array_slice($courts, 0, 8);
                foreach($dashboard_courts as $court): 
                    if ($court['status'] == 'Available') { $dot = 'bg-emerald-500'; $label = 'text-emerald-600 bg-emerald-50'; }
                    elseif ($court['status'] == 'Maintenance') { $dot = 'bg-red-500'; $label = 'text-red-600 bg-red-50'; }
                    else { $dot = 'bg-amber-500'; $label = 'text-amber-600 bg-amber-50'; }
                ?>
                <div class="p-4 rounded-xl border border-outline-variant/30 hover:border-primary/30 hover:shadow-sm transition-all bg-surface">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-bold text-on-surface truncate"><?php echo htmlspecialchars($court['name']); ?></span>
                        <div class="w-2.5 h-2.5 rounded-full <?php echo $dot; ?> shrink-0"></div>
                    </div>
                    <span class="inline-flex text-[10px] font-bold uppercase tracking-widest px-2 py-0.5 rounded-full <?php echo $label; ?>"><?php echo htmlspecialchars($court['status']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="bg-gradient-to-br from-primary to-primary-dark rounded-2xl shadow-sm p-6 text-white flex flex-col">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-12 h-12 rounded-xl bg-white/15 flex items-center justify-center backdrop-blur-sm">
                    <span class="material-symbols-outlined text-[28px]">psychology</span>
                </div>
                <div>
                    <h3 class="font-bold text-base">AI Engine</h3>
                    <p class="text-[11px] text-white/70">MediaPipe Pose Detection</p>
                </div>
            </div>
            <div class="flex-1 flex flex-col justify-center">
                <div class="flex items-center gap-2 mb-3">
                    <div class="w-3 h-3 bg-green-400 rounded-full animate-pulse"></div>
                    <span class="text-sm font-bold">System Online</span>
                </div>
                <div class="w-full bg-white/20 rounded-full h-2 overflow-hidden">
                    <div class="bg-white h-2 rounded-full" style="width:100%"></div>
                </div>
                <p class="text-[10px] text-white/60 mt-2 uppercase tracking-widest font-bold">Uptime: 100%</p>
            </div>
            <a href="ai_lab" class="mt-4 w-full py-2.5 bg-white/15 hover:bg-white/25 backdrop-blur-sm rounded-xl text-sm font-bold text-center transition-colors">View AI Lab</a>
        </section>
    </div>

    <!-- Tables -->
    <div class="space-y-6">
        
        <!-- Inventory -->
        <section class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm overflow-hidden">
            <div class="p-5 border-b border-outline-variant/30 flex justify-between items-center">
                <div>
                    <h2 class="text-base font-bold text-on-surface">Recent Inventory</h2>
                    <p class="text-xs text-outline mt-0.5">Shop items & stock levels</p>
                </div>
                <a href="inventory" class="text-xs font-bold text-primary hover:underline flex items-center gap-1">View All <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-surface text-[10px] font-bold uppercase tracking-widest text-outline border-b border-outline-variant/30">
                        <tr><th class="px-5 py-3">Item</th><th class="px-5 py-3">Category</th><th class="px-5 py-3">Price</th><th class="px-5 py-3">Stock</th><th class="px-5 py-3">Status</th></tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/20">
                        <?php if(empty($recent_products)): ?>
                            <tr><td colspan="5" class="px-5 py-8 text-center text-outline text-sm italic">No products found.</td></tr>
                        <?php else: foreach($recent_products as $prod): ?>
                        <tr class="hover:bg-surface-container-low/50 transition-colors">
                            <td class="px-5 py-3 font-bold text-on-surface"><?php echo htmlspecialchars($prod['name']); ?></td>
                            <td class="px-5 py-3 text-outline"><?php echo htmlspecialchars($prod['category']); ?></td>
                            <td class="px-5 py-3 font-bold text-on-surface">₱<?php echo number_format($prod['price'], 2); ?></td>
                            <td class="px-5 py-3"><?php echo (int)$prod['stock_quantity']; ?> units</td>
                            <td class="px-5 py-3">
                                <?php if($prod['stock_quantity'] > 0 && $prod['is_active']): ?>
                                    <span class="px-2.5 py-0.5 bg-emerald-50 text-emerald-600 text-[10px] font-bold uppercase tracking-widest rounded-full border border-emerald-200">Active</span>
                                <?php else: ?>
                                    <span class="px-2.5 py-0.5 bg-red-50 text-red-600 text-[10px] font-bold uppercase tracking-widest rounded-full border border-red-200">Out</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Orders -->
        <section class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm overflow-hidden">
            <div class="p-5 border-b border-outline-variant/30 flex justify-between items-center">
                <div>
                    <h2 class="text-base font-bold text-on-surface">Recent Orders</h2>
                    <p class="text-xs text-outline mt-0.5">Latest customer purchases</p>
                </div>
                <a href="user_orders" class="text-xs font-bold text-primary hover:underline flex items-center gap-1">View All <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-surface text-[10px] font-bold uppercase tracking-widest text-outline border-b border-outline-variant/30">
                        <tr><th class="px-5 py-3">Order</th><th class="px-5 py-3">Customer</th><th class="px-5 py-3">Date</th><th class="px-5 py-3">Total</th><th class="px-5 py-3">Status</th></tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/20">
                        <?php if(empty($recent_orders)): ?>
                            <tr><td colspan="5" class="px-5 py-8 text-center text-outline text-sm italic">No orders yet.</td></tr>
                        <?php else: foreach($recent_orders as $order): 
                            $sc = ($order['status'] == 'Pending') ? 'text-amber-600 bg-amber-50 border-amber-200' : 'text-emerald-600 bg-emerald-50 border-emerald-200';
                        ?>
                        <tr class="hover:bg-surface-container-low/50 transition-colors">
                            <td class="px-5 py-3 font-mono font-bold text-primary">#<?php echo str_pad($order['order_id'], 5, '0', STR_PAD_LEFT); ?></td>
                            <td class="px-5 py-3 font-bold text-on-surface"><?php echo htmlspecialchars($order['full_name']); ?></td>
                            <td class="px-5 py-3 text-outline"><?php echo date("M d, Y", strtotime($order['order_date'])); ?></td>
                            <td class="px-5 py-3 font-bold text-on-surface">₱<?php echo number_format($order['total_amount'], 2); ?></td>
                            <td class="px-5 py-3"><span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-widest border <?php echo $sc; ?>"><?php echo htmlspecialchars($order['status']); ?></span></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Bookings -->
        <section class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm overflow-hidden">
            <div class="p-5 border-b border-outline-variant/30 flex justify-between items-center">
                <div>
                    <h2 class="text-base font-bold text-on-surface">Recent Bookings</h2>
                    <p class="text-xs text-outline mt-0.5">Court reservations</p>
                </div>
                <a href="user_bookings" class="text-xs font-bold text-primary hover:underline flex items-center gap-1">View All <span class="material-symbols-outlined text-[14px]">arrow_forward</span></a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-surface text-[10px] font-bold uppercase tracking-widest text-outline border-b border-outline-variant/30">
                        <tr><th class="px-5 py-3">Ref</th><th class="px-5 py-3">Customer</th><th class="px-5 py-3">Court</th><th class="px-5 py-3">Date & Time</th><th class="px-5 py-3">Status</th></tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/20">
                        <?php if(empty($recent_bookings)): ?>
                            <tr><td colspan="5" class="px-5 py-8 text-center text-outline text-sm italic">No bookings yet.</td></tr>
                        <?php else: foreach($recent_bookings as $booking): 
                            $sc = ($booking['status'] == 'Pending') ? 'text-amber-600 bg-amber-50 border-amber-200' : (($booking['status'] == 'Cancelled') ? 'text-outline bg-surface border-outline-variant/30' : 'text-emerald-600 bg-emerald-50 border-emerald-200');
                        ?>
                        <tr class="hover:bg-surface-container-low/50 transition-colors">
                            <td class="px-5 py-3 font-mono font-bold text-on-surface"><?php echo htmlspecialchars($booking['booking_reference']); ?></td>
                            <td class="px-5 py-3 font-bold text-on-surface"><?php echo htmlspecialchars($booking['full_name']); ?></td>
                            <td class="px-5 py-3 text-outline"><?php echo htmlspecialchars($booking['court_name']); ?></td>
                            <td class="px-5 py-3 text-on-surface">
                                <?php echo date("M d, Y", strtotime($booking['booking_date'])); ?>
                                <br><span class="text-[10px] text-outline font-bold tracking-wider"><?php echo date("h:i A", strtotime($booking['start_time'])) . ' – ' . date("h:i A", strtotime($booking['end_time'])); ?></span>
                            </td>
                            <td class="px-5 py-3"><span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-widest border <?php echo $sc; ?>"><?php echo htmlspecialchars($booking['status']); ?></span></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <footer class="mt-8 pt-6 border-t border-outline-variant/30 flex justify-between text-[11px] font-bold tracking-widest text-outline uppercase pb-8">
        <p>&copy; 2024 HI-POWER</p>
        <div class="flex gap-4">
            <a class="hover:text-primary transition-colors" href="analytics.php">Status</a>
            <a class="hover:text-primary transition-colors" href="/support.php?tab=contact">Support</a>
        </div>
    </footer>
</main>

</body>
</html>