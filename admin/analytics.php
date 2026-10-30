<?php
require_once __DIR__ . '/../includes/session_boot.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header("Location: /authentication/login"); exit; }
if ($_SESSION['role'] !== 'Admin' && $_SESSION['role'] !== 'Head Manager') { header("Location: ../playerdashboard.php"); exit; }

require_once __DIR__ . '/../includes/database_connect.php';

// ==========================================
// DATE RANGE + PERIOD (TC-AS-044 / TC-AS-046)
// ==========================================
$today = date('Y-m-d');
$from  = $_GET['from']  ?? date('Y-m-d', strtotime('-29 days'));
$to    = $_GET['to']    ?? $today;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-29 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = $today;
if (strtotime($to) < strtotime($from)) { $t = $from; $from = $to; $to = $t; }

$range_days = (int)floor((strtotime($to) - strtotime($from)) / 86400) + 1;
$group = $_GET['group'] ?? 'auto';
if ($group === 'auto') { $group = $range_days <= 62 ? 'day' : 'month'; }
if (!in_array($group, ['day', 'week', 'month'], true)) $group = 'day';

// ==========================================
// REVENUE (settled shop orders + paid court bookings)
// ==========================================
$q = $pdo->prepare("SELECT SUM(total_amount) FROM Orders WHERE status NOT IN ('Cancelled','Pending Payment') AND order_date BETWEEN ? AND ?");
$q->execute([$from, $to]);
$shop_revenue = (float)($q->fetchColumn() ?? 0);

$q = $pdo->prepare("SELECT SUM(total_price) FROM Bookings WHERE status IN ('Confirmed','Completed','Paid') AND booking_date BETWEEN ? AND ?");
$q->execute([$from, $to]);
$court_revenue = (float)($q->fetchColumn() ?? 0);

$total_revenue = $shop_revenue + $court_revenue;
$court_pct = $total_revenue > 0 ? round(($court_revenue / $total_revenue) * 100) : 0;
$shop_pct  = 100 - $court_pct;

// ==========================================
// METRICS
// ==========================================
$q = $pdo->prepare("SELECT AVG(total_price) FROM Bookings WHERE status IN ('Confirmed','Completed','Paid') AND booking_date BETWEEN ? AND ?");
$q->execute([$from, $to]);
$avg_booking_value = (float)($q->fetchColumn() ?? 0);

$q = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM Bookings WHERE booking_date BETWEEN ? AND ? AND status <> 'In Cart'");
$q->execute([$from, $to]);
$active_athletes = (int)($q->fetchColumn() ?? 0);

$total_players = (int)$pdo->query("SELECT COUNT(*) FROM Users WHERE role = 'Player'")->fetchColumn();
$conversion_rate = $total_players > 0 ? ($active_athletes / $total_players) * 100 : 0;

$q = $pdo->prepare("SELECT COUNT(*) FROM Bookings WHERE status <> 'In Cart' AND booking_date BETWEEN ? AND ?");
$q->execute([$from, $to]);
$total_bookings = (int)($q->fetchColumn() ?? 0);

// ==========================================
// REVENUE SERIES BY PERIOD (day / week / month) - TC-AS-044
// ==========================================
$shopMap = [];
$courtMap = [];
$bucketKeys = [];
$bucketLabels = [];

if ($group === 'day') {
    $s = $pdo->prepare("SELECT DATE(order_date) k, SUM(total_amount) v FROM Orders WHERE status NOT IN ('Cancelled','Pending Payment') AND order_date BETWEEN ? AND ? GROUP BY DATE(order_date)");
    $s->execute([$from, $to]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { if ($r['v'] !== null) $shopMap[$r['k']] = (float)$r['v']; }

    $s = $pdo->prepare("SELECT DATE(booking_date) k, SUM(total_price) v FROM Bookings WHERE status IN ('Confirmed','Completed','Paid') AND booking_date BETWEEN ? AND ? GROUP BY DATE(booking_date)");
    $s->execute([$from, $to]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { if ($r['v'] !== null) $courtMap[$r['k']] = (float)$r['v']; }

    $cur = strtotime($from);
    while ($cur <= strtotime($to)) {
        $k = date('Y-m-d', $cur);
        $bucketKeys[]   = $k;
        $bucketLabels[] = date('M d', $cur);
        $cur = strtotime('+1 day', $cur);
    }
} else {
    $fmt = $group === 'week' ? '%x-W%v' : '%Y-%m';

    $s = $pdo->prepare("SELECT DATE_FORMAT(order_date, '$fmt') k, SUM(total_amount) v FROM Orders WHERE status NOT IN ('Cancelled','Pending Payment') AND order_date BETWEEN ? AND ? GROUP BY k");
    $s->execute([$from, $to]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $shopMap[$r['k']] = (float)$r['v']; }

    $s = $pdo->prepare("SELECT DATE_FORMAT(booking_date, '$fmt') k, SUM(total_price) v FROM Bookings WHERE status IN ('Confirmed','Completed','Paid') AND booking_date BETWEEN ? AND ? GROUP BY k");
    $s->execute([$from, $to]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) { $courtMap[$r['k']] = (float)$r['v']; }

    $bucketKeys = array_values(array_unique(array_merge(array_keys($shopMap), array_keys($courtMap))));
    sort($bucketKeys);
    foreach ($bucketKeys as $k) {
        $bucketLabels[] = $group === 'week' ? $k : date('M Y', strtotime($k . '-01'));
    }
}

$seriesTotals = [];
$max_series = 1;
foreach ($bucketKeys as $k) {
    $val = ($shopMap[$k] ?? 0) + ($courtMap[$k] ?? 0);
    $seriesTotals[] = $val;
    if ($val > $max_series) $max_series = $val;
}

// ==========================================
// COURT UTILIZATION (TC-AS-045)
// ==========================================
$u = $pdo->prepare("
    SELECT c.name, COUNT(b.booking_id) AS cnt
    FROM Courts c
    LEFT JOIN Bookings b ON b.court_id = c.court_id
        AND b.status IN ('Confirmed','Completed','Paid')
        AND b.booking_date BETWEEN ? AND ?
    GROUP BY c.court_id, c.name
    ORDER BY cnt DESC, c.name ASC
");
$u->execute([$from, $to]);
$court_util = $u->fetchAll(PDO::FETCH_ASSOC);
$max_util = 1;
$util_bookings = 0;
foreach ($court_util as $cu) {
    if ((int)$cu['cnt'] > $max_util) $max_util = (int)$cu['cnt'];
    $util_bookings += (int)$cu['cnt'];
}

// ==========================================
// PEAK HOURS
// ==========================================
$ph = $pdo->prepare("SELECT HOUR(start_time) as hr, COUNT(*) as cnt FROM Bookings WHERE status IN ('Confirmed','Completed','Paid') AND booking_date BETWEEN ? AND ? GROUP BY hr");
$ph->execute([$from, $to]);
$peak_hours = array_fill(8, 12, 0);
foreach ($ph->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $hr = (int)$row['hr'];
    if ($hr >= 8 && $hr <= 19) $peak_hours[$hr] = (int)$row['cnt'];
}
$max_peak = max($peak_hours) ?: 1;

$has_data = ($total_revenue > 0 || $total_bookings > 0);

// ==========================================
// CSV EXPORT (TC-AS-048)
// ==========================================
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="hipower_analytics_' . $from . '_to_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['HI-POWER Analytics Report']);
    fputcsv($out, ['Range', $from . ' to ' . $to]);
    fputcsv($out, ['Grouping', $group]);
    fputcsv($out, []);
    fputcsv($out, ['Summary']);
    fputcsv($out, ['Total Revenue', number_format($total_revenue, 2)]);
    fputcsv($out, ['Court Revenue', number_format($court_revenue, 2)]);
    fputcsv($out, ['Shop Revenue', number_format($shop_revenue, 2)]);
    fputcsv($out, ['Total Bookings', $total_bookings]);
    fputcsv($out, ['Avg Booking Value', number_format($avg_booking_value, 2)]);
    fputcsv($out, ['Active Athletes', $active_athletes]);
    fputcsv($out, ['Player Conversion %', number_format($conversion_rate, 1)]);
    fputcsv($out, []);
    fputcsv($out, ['Revenue by ' . ucfirst($group), 'Period', 'Revenue']);
    foreach ($bucketKeys as $i => $k) {
        fputcsv($out, ['', $bucketLabels[$i], number_format($seriesTotals[$i], 2)]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Court Utilization', 'Bookings']);
    foreach ($court_util as $cu) {
        fputcsv($out, [$cu['name'], (int)$cu['cnt']]);
    }
    fclose($out);
    exit;
}

$period_label = ['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly'][$group];
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>Analytics | HI-POWER Admin</title>
    <?php include __DIR__ . '/../includes/tailwind_config.php'; ?>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: #fff; }
            main { margin-left: 0 !important; }
        }
    </style>
</head>
<body class="bg-surface text-on-background min-h-screen">

<?php include __DIR__ . '/includes/admin_header.php'; ?>

<main class="md:ml-[260px] p-4 md:p-8 min-h-screen">

    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-6 no-print">
        <div>
            <h1 class="text-2xl md:text-3xl font-bold text-on-surface">Analytics</h1>
            <p class="text-sm text-on-surface-variant mt-1">Revenue intelligence &amp; facility performance metrics.</p>
        </div>
        <div class="flex gap-2">
            <a href="analytics?from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>&group=<?php echo urlencode($group); ?>&export=csv" class="h-10 px-4 bg-white border border-outline-variant/50 rounded-xl font-bold text-xs text-on-surface flex items-center gap-2 hover:bg-surface-container-high transition">
                <span class="material-symbols-outlined text-[18px]">download</span> Export CSV
            </a>
            <button onclick="window.print()" class="h-10 px-4 bg-white border border-outline-variant/50 rounded-xl font-bold text-xs text-on-surface flex items-center gap-2 hover:bg-surface-container-high transition">
                <span class="material-symbols-outlined text-[18px]">print</span> Print
            </button>
        </div>
    </div>

    <!-- Date Range Filter (TC-AS-046) -->
    <form method="GET" action="analytics" class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-4 mb-8 flex flex-col md:flex-row md:items-end gap-4 no-print">
        <div>
            <label class="block text-[10px] font-bold text-outline uppercase tracking-widest mb-1.5">From</label>
            <input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>" class="h-10 px-3 bg-surface border border-outline-variant/50 rounded-xl text-sm outline-none focus:border-primary">
        </div>
        <div>
            <label class="block text-[10px] font-bold text-outline uppercase tracking-widest mb-1.5">To</label>
            <input type="date" name="to" value="<?php echo htmlspecialchars($to); ?>" class="h-10 px-3 bg-surface border border-outline-variant/50 rounded-xl text-sm outline-none focus:border-primary">
        </div>
        <div>
            <label class="block text-[10px] font-bold text-outline uppercase tracking-widest mb-1.5">Group By</label>
            <select name="group" class="h-10 px-3 bg-surface border border-outline-variant/50 rounded-xl text-sm outline-none focus:border-primary">
                <?php foreach (['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly'] as $val => $lbl): ?>
                    <option value="<?php echo $val; ?>" <?php echo $group === $val ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="h-10 px-5 bg-primary text-white rounded-xl font-bold text-sm hover:brightness-110 transition flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px]">filter_alt</span> Apply
        </button>
        <div class="flex gap-2 md:ml-auto">
            <a href="analytics?from=<?php echo date('Y-m-d'); ?>&to=<?php echo date('Y-m-d'); ?>&group=day" class="h-10 px-3 rounded-xl text-xs font-bold text-on-surface-variant hover:bg-surface-container border border-outline-variant/40 flex items-center">Today</a>
            <a href="analytics?from=<?php echo date('Y-m-d', strtotime('-6 days')); ?>&to=<?php echo date('Y-m-d'); ?>&group=day" class="h-10 px-3 rounded-xl text-xs font-bold text-on-surface-variant hover:bg-surface-container border border-outline-variant/40 flex items-center">7 Days</a>
            <a href="analytics?from=<?php echo date('Y-m-d', strtotime('-29 days')); ?>&to=<?php echo date('Y-m-d'); ?>&group=day" class="h-10 px-3 rounded-xl text-xs font-bold text-on-surface-variant hover:bg-surface-container border border-outline-variant/40 flex items-center">30 Days</a>
            <a href="analytics?from=<?php echo date('Y-01-01'); ?>&to=<?php echo date('Y-m-d'); ?>&group=month" class="h-10 px-3 rounded-xl text-xs font-bold text-on-surface-variant hover:bg-surface-container border border-outline-variant/40 flex items-center">This Year</a>
        </div>
    </form>

    <?php if (!$has_data): ?>
        <!-- No data state (TC-AS-047) -->
        <div class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-12 text-center mb-8">
            <span class="material-symbols-outlined text-outline-variant text-[56px] block mb-3">insights</span>
            <h3 class="text-lg font-bold text-on-surface mb-1">No data for this period</h3>
            <p class="text-sm text-on-surface-variant">There was no revenue or booking activity between <?php echo date('M d, Y', strtotime($from)); ?> and <?php echo date('M d, Y', strtotime($to)); ?>. Try widening the date range.</p>
        </div>
    <?php endif; ?>

    <!-- Top Metrics -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-2xl p-5 border border-outline-variant/30 shadow-sm">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center"><span class="material-symbols-outlined text-primary text-[22px]">payments</span></div>
                <span class="text-[10px] font-bold text-green-600 bg-green-50 px-2 py-0.5 rounded-full">In Range</span>
            </div>
            <p class="text-2xl font-bold text-on-surface">₱<?php echo number_format($total_revenue, 2); ?></p>
            <p class="text-[11px] text-outline mt-1">Total Revenue</p>
        </div>
        <div class="bg-white rounded-2xl p-5 border border-outline-variant/30 shadow-sm">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center"><span class="material-symbols-outlined text-emerald-600 text-[22px]">trending_up</span></div>
                <span class="text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full">Rate</span>
            </div>
            <p class="text-2xl font-bold text-on-surface"><?php echo number_format($conversion_rate, 1); ?>%</p>
            <p class="text-[11px] text-outline mt-1">Player Conversion</p>
        </div>
        <div class="bg-white rounded-2xl p-5 border border-outline-variant/30 shadow-sm">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-10 h-10 rounded-xl bg-violet-50 flex items-center justify-center"><span class="material-symbols-outlined text-violet-600 text-[22px]">receipt</span></div>
                <span class="text-[10px] font-bold text-violet-600 bg-violet-50 px-2 py-0.5 rounded-full">Avg</span>
            </div>
            <p class="text-2xl font-bold text-on-surface">₱<?php echo number_format($avg_booking_value, 2); ?></p>
            <p class="text-[11px] text-outline mt-1">Avg Booking Value</p>
        </div>
        <div class="bg-white rounded-2xl p-5 border border-outline-variant/30 shadow-sm">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center"><span class="material-symbols-outlined text-amber-600 text-[22px]">groups</span></div>
                <span class="text-[10px] font-bold text-amber-600 bg-amber-50 px-2 py-0.5 rounded-full">Unique</span>
            </div>
            <p class="text-2xl font-bold text-on-surface"><?php echo number_format($active_athletes); ?></p>
            <p class="text-[11px] text-outline mt-1">Active Athletes</p>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">

        <!-- Revenue by period (real data) - TC-AS-044 -->
        <div class="lg:col-span-2 bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-6">
            <div class="flex items-center justify-between mb-1">
                <h3 class="text-base font-bold text-on-surface"><?php echo $period_label; ?> Revenue</h3>
                <span class="text-[11px] font-bold text-outline"><?php echo date('M d, Y', strtotime($from)); ?> – <?php echo date('M d, Y', strtotime($to)); ?></span>
            </div>
            <p class="text-xs text-outline mb-6">Shop + court revenue grouped by <?php echo $group; ?>.</p>
            <?php if (empty($bucketKeys)): ?>
                <div class="h-56 flex items-center justify-center text-outline text-sm italic">No revenue recorded in this period.</div>
            <?php else: ?>
                <div class="h-56 w-full flex items-end gap-1.5">
                    <?php foreach ($seriesTotals as $i => $val):
                        $h = max(2, round(($val / $max_series) * 100));
                    ?>
                        <div class="flex-1 flex flex-col items-center justify-end h-full group relative">
                            <div class="absolute -top-9 left-1/2 -translate-x-1/2 bg-on-surface text-white text-[10px] font-bold py-1 px-2 rounded-lg opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap z-10 pointer-events-none">
                                ₱<?php echo number_format($val, 2); ?>
                            </div>
                            <div class="w-full rounded-t-md bg-primary/80 group-hover:bg-primary transition-all" style="height:<?php echo $h; ?>%;"></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="flex justify-between mt-3 text-[10px] font-bold text-outline gap-1 overflow-hidden">
                    <?php foreach ($bucketLabels as $lbl): ?>
                        <span class="truncate text-center flex-1"><?php echo htmlspecialchars($lbl); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Revenue Split Donut -->
        <div class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-6 flex flex-col">
            <h3 class="text-base font-bold text-on-surface mb-1">Revenue Split</h3>
            <p class="text-xs text-outline mb-4">Courts vs Shop</p>
            <div class="flex-1 flex flex-col items-center justify-center">
                <div class="relative w-40 h-40 rounded-full flex items-center justify-center" style="background: conic-gradient(#ff5722 <?php echo $court_pct; ?>%, #e8eaf0 <?php echo $court_pct; ?>%);">
                    <div class="absolute inset-0 m-6 bg-white rounded-full flex flex-col items-center justify-center shadow-inner">
                        <p class="text-xl font-bold text-on-surface"><?php echo $court_pct; ?>%</p>
                        <p class="text-[10px] text-outline uppercase tracking-widest font-bold">Courts</p>
                    </div>
                </div>
                <div class="mt-6 w-full space-y-2">
                    <div class="flex justify-between items-center p-2 rounded-lg hover:bg-surface-container-low transition-colors">
                        <div class="flex items-center gap-2"><div class="w-3 h-3 rounded-full bg-primary"></div><span class="text-sm text-on-surface">Court Bookings</span></div>
                        <span class="text-sm font-bold">₱<?php echo number_format($court_revenue, 2); ?></span>
                    </div>
                    <div class="flex justify-between items-center p-2 rounded-lg hover:bg-surface-container-low transition-colors">
                        <div class="flex items-center gap-2"><div class="w-3 h-3 rounded-full bg-surface-container-high"></div><span class="text-sm text-on-surface">Shop Orders</span></div>
                        <span class="text-sm font-bold">₱<?php echo number_format($shop_revenue, 2); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Court Utilization (TC-AS-045) -->
    <div class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-6 mb-8">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h3 class="text-base font-bold text-on-surface">Court Utilization</h3>
                <p class="text-xs text-outline mt-0.5">Bookings per court within the selected period</p>
            </div>
            <span class="text-[11px] font-bold text-outline"><?php echo number_format($util_bookings); ?> total bookings</span>
        </div>
        <?php if (empty($court_util)): ?>
            <p class="text-sm text-outline italic py-6 text-center">No courts configured.</p>
        <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($court_util as $cu):
                    $cnt = (int)$cu['cnt'];
                    $pct = round(($cnt / $max_util) * 100);
                ?>
                    <div>
                        <div class="flex justify-between items-center mb-1.5">
                            <span class="text-sm font-semibold text-on-surface"><?php echo htmlspecialchars($cu['name']); ?></span>
                            <span class="text-xs font-bold text-on-surface-variant"><?php echo $cnt; ?> booking<?php echo $cnt === 1 ? '' : 's'; ?></span>
                        </div>
                        <div class="h-3 w-full bg-surface-container-low rounded-full overflow-hidden">
                            <div class="h-full rounded-full bg-gradient-to-r from-primary to-primary-dark transition-all" style="width:<?php echo $cnt > 0 ? max(4, $pct) : 0; ?>%;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Peak Hours -->
    <div class="bg-white rounded-2xl border border-outline-variant/30 shadow-sm p-6 mb-8">
        <div class="flex justify-between items-center mb-6">
            <div>
                <h3 class="text-base font-bold text-on-surface">Peak Court Hours</h3>
                <p class="text-xs text-outline mt-0.5">Booking distribution across business hours (8 AM – 7 PM)</p>
            </div>
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-1.5"><div class="w-3 h-3 rounded-sm bg-primary/20"></div><span class="text-[11px] text-outline font-bold">Low</span></div>
                <div class="flex items-center gap-1.5"><div class="w-3 h-3 rounded-sm bg-primary"></div><span class="text-[11px] text-outline font-bold">Peak</span></div>
            </div>
        </div>
        <div class="h-48 flex items-end justify-between gap-1 sm:gap-2 border-b border-outline-variant/50 pb-2">
            <?php foreach($peak_hours as $hr => $cnt):
                $pct = ($cnt / $max_peak) * 100;
                $bar_h = max(4, $pct);
                $opacity = max(0.15, min(1.0, $pct / 100));
            ?>
                <div class="flex-1 rounded-t-md transition-all group relative cursor-pointer hover:opacity-80" style="height:<?php echo $bar_h; ?>%; background-color: rgba(255,87,34,<?php echo $opacity; ?>);">
                    <div class="absolute -top-10 left-1/2 -translate-x-1/2 bg-on-surface text-white text-[11px] font-bold py-1.5 px-3 rounded-lg opacity-0 group-hover:opacity-100 transition-opacity z-10 pointer-events-none whitespace-nowrap shadow-lg">
                        <?php echo $cnt; ?> bookings
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="flex justify-between mt-3 text-[11px] font-bold text-outline px-1">
            <span>8AM</span><span>10AM</span><span>12PM</span><span>2PM</span><span>4PM</span><span>6PM</span>
        </div>
    </div>

    <!-- Insight Banner -->
    <div class="bg-gradient-to-r from-primary to-primary-dark rounded-2xl p-8 text-white relative overflow-hidden shadow-lg">
        <div class="absolute right-0 top-0 w-64 h-64 bg-white/5 rounded-full -mr-20 -mt-20 blur-3xl"></div>
        <div class="relative z-10 flex flex-col md:flex-row items-start md:items-center gap-6">
            <div class="w-14 h-14 rounded-2xl bg-white/15 flex items-center justify-center shrink-0 backdrop-blur-sm">
                <span class="material-symbols-outlined text-[28px]">lightbulb</span>
            </div>
            <div class="flex-1">
                <h4 class="text-lg font-bold mb-1">Smart Insight</h4>
                <?php
                    $peak_hour = array_search($max_peak, $peak_hours, true);
                    $peak_text = ($peak_hour !== false && $max_peak > 1) ? date('g A', mktime($peak_hour)) : null;
                ?>
                <p class="text-sm text-white/80 leading-relaxed">
                    <?php if ($peak_text): ?>
                        Bookings peak around <strong class="text-white"><?php echo $peak_text; ?></strong> in this period. Consider dynamic "Prime Time" pricing to optimise facility yield.
                    <?php elseif ($has_data): ?>
                        Overall booking volume is evenly distributed in this period with no dominant peak hour.
                    <?php else: ?>
                        No activity recorded for the selected period — adjust the date range to analyse performance.
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>
</main>

</body>
</html>
