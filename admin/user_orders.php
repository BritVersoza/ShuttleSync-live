<?php
// Start session
require_once __DIR__ . '/../includes/session_boot.php';

// Access Control
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: /authentication/login");
    exit;
}
if ($_SESSION['role'] !== 'Admin' && $_SESSION['role'] !== 'Head Manager') {
    header("Location: ../playerdashboard.php");
    exit;
}

require_once __DIR__ . '/../includes/database_connect.php';
require_once __DIR__ . '/../includes/csrf_helper.php';

$admin_name = $_SESSION['full_name'];
$admin_role = $_SESSION['role'];
$success_message = '';
$error_message = '';

// Normalize the various order statuses the app can produce into one
// display key/label so the list, filters and customer page stay consistent.
function order_status_info(string $s): array {
    switch ($s) {
        case 'Pending Payment': return ['key' => 'awaiting',   'label' => 'Awaiting Payment'];
        case 'Pending':         return ['key' => 'pending',    'label' => 'Pending'];
        case 'Processing':
        case 'Confirmed':       return ['key' => 'processing', 'label' => 'Processing'];
        case 'Completed':
        case 'Delivered':       return ['key' => 'completed',  'label' => 'Completed'];
        case 'Cancelled':       return ['key' => 'cancelled',  'label' => 'Cancelled'];
        default:                return ['key' => 'pending',    'label' => ucfirst($s)];
    }
}

// ==========================================
// HANDLE ORDER ACTIONS
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    verify_csrf_token();
    if ($_POST['action'] == 'update_order_status') {
        try {
            $order_id   = (int)$_POST['order_id'];
            $new_status = $_POST['status'];

            $allowed = ['Processing', 'Completed', 'Cancelled'];
            if (!in_array($new_status, $allowed, true)) {
                throw new Exception("Invalid status.");
            }

            $cur = $pdo->prepare("SELECT status FROM Orders WHERE order_id = ?");
            $cur->execute([$order_id]);
            $current = $cur->fetchColumn();
            if ($current === false) throw new Exception("Order not found.");

            if (in_array($current, ['Completed', 'Cancelled', 'Delivered'], true)) {
                throw new Exception("Order is already finalised.");
            }

            $pdo->beginTransaction();

            if ($new_status === 'Completed') {
                // Set fulfillment date when a column exists (migration-aware).
                try {
                    $pdo->prepare("UPDATE Orders SET status = 'Completed', fulfilled_at = NOW() WHERE order_id = ?")->execute([$order_id]);
                } catch (PDOException $e) {
                    $pdo->prepare("UPDATE Orders SET status = 'Completed' WHERE order_id = ?")->execute([$order_id]);
                }
            } else {
                $pdo->prepare("UPDATE Orders SET status = ? WHERE order_id = ?")->execute([$new_status, $order_id]);
            }

            // Restore stock when an order is cancelled and its payment was already
            // verified (stock is deducted at payment verification).
            if ($new_status === 'Cancelled') {
                $paid = false;
                try {
                    $pst = $pdo->prepare("SELECT p.status FROM payments p JOIN Orders o ON o.payment_id = p.payment_id WHERE o.order_id = ?");
                    $pst->execute([$order_id]);
                    $paid = ($pst->fetchColumn() === 'Verified');
                } catch (PDOException $e) {
                    $paid = false;
                }

                if ($paid) {
                    $items = $pdo->prepare("SELECT product_id, quantity FROM payment_items WHERE order_id = ? AND item_type = 'shop' AND product_id IS NOT NULL");
                    $items->execute([$order_id]);
                    $restock = $pdo->prepare("UPDATE Products SET stock_quantity = stock_quantity + ? WHERE product_id = ?");
                    while ($row = $items->fetch()) {
                        $restock->execute([(int)$row['quantity'], $row['product_id']]);
                    }
                }
            }

            $pdo->commit();

            $label = order_status_info($new_status)['label'];
            $success_message = "Order #ORD-" . str_pad($order_id, 5, '0', STR_PAD_LEFT) . " marked as " . $label . ".";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_message = "Failed to update order status: " . $e->getMessage();
        }
    }
}

// ==========================================
// FETCH ORDER DATA & METRICS
// ==========================================
$pending_count = $pdo->query("SELECT COUNT(*) FROM Orders WHERE status IN ('Pending', 'Pending Payment', 'Confirmed')")->fetchColumn() ?? 0;
$processing_count = $pdo->query("SELECT COUNT(*) FROM Orders WHERE status = 'Processing'")->fetchColumn() ?? 0;
$completed_count = $pdo->query("SELECT COUNT(*) FROM Orders WHERE status IN ('Completed', 'Delivered')")->fetchColumn() ?? 0;

// Fetch All Orders
$orders_stmt = $pdo->query("
    SELECT o.order_id, o.order_date, o.total_amount, o.status, u.full_name 
    FROM Orders o 
    JOIN Users u ON u.user_id = o.user_id 
    ORDER BY o.order_date DESC, o.order_id DESC
");
$all_orders = $orders_stmt->fetchAll();
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>User Orders Queue | HI-POWER Admin</title>
    <?php include __DIR__ . '/../includes/tailwind_config.php'; ?>
    <style>
        .order-filter.active { background:#ff5722; color:#fff; border-color:#ff5722; }
        .order-filter:not(.active):hover { background:#f2f3f7; border-color:#ff5722; color:#ff5722; }
        .modal-overlay { background: rgba(15,23,42,0.55); backdrop-filter: blur(2px); }
        .modal-card { animation: modalIn 0.22s ease-out; }
        @keyframes modalIn { from { opacity:0; transform:translateY(14px) scale(0.98); } to { opacity:1; transform:none; } }
    </style>
</head>
<body class="bg-background min-h-screen">

<form id="orderActionForm" method="POST" action="user_orders" style="display:none;">
    <?php echo get_csrf_input(); ?>
    <input type="hidden" name="action" value="update_order_status">
    <input type="hidden" name="order_id" id="action_order_id">
    <input type="hidden" name="status" id="action_status">
</form>

<?php include __DIR__ . '/includes/admin_header.php'; ?>


    <main class="flex-1 ml-0 md:ml-64 p-margin-md min-h-screen">
        
        <?php if (!empty($success_message)): ?>
            <div class="mb-6 p-4 bg-primary-container/20 border border-primary/50 rounded-lg flex items-center gap-2 shadow-sm">
                <span class="material-symbols-outlined text-primary">check_circle</span>
                <p class="text-primary font-body-md text-sm font-bold"><?php echo htmlspecialchars($success_message); ?></p>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="mb-6 p-4 bg-error-container/20 border border-error/50 rounded-lg flex items-center gap-2 shadow-sm">
                <span class="material-symbols-outlined text-error">error</span>
                <p class="text-error font-body-md text-sm font-bold"><?php echo htmlspecialchars($error_message); ?></p>
            </div>
        <?php endif; ?>

        <div class="mb-gutter flex flex-col md:flex-row md:items-end justify-between gap-4 mt-4">
            <div>
                <h1 class="font-headline-lg text-headline-lg text-on-surface mb-2">User Orders Queue</h1>
                <p class="text-body-md text-on-surface-variant max-w-2xl">Manage and track customer purchases, gear rentals, and merchandise sales in real-time.</p>
            </div>
            
            <div class="flex gap-4">
                <div class="bg-surface-container-lowest border border-outline-variant kinetic-shadow p-4 rounded-xl flex items-center gap-4 min-w-[150px]">
                    <div class="w-12 h-12 rounded-full bg-error-container/20 flex items-center justify-center text-error border border-error/20">
                        <span class="material-symbols-outlined">pending_actions</span>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-wider text-outline">Pending</p>
                        <p class="text-headline-md font-bold text-on-surface"><?php echo number_format($pending_count); ?></p>
                    </div>
                </div>
                <div class="bg-surface-container-lowest border border-outline-variant kinetic-shadow p-4 rounded-xl flex items-center gap-4 min-w-[150px]">
                    <div class="w-12 h-12 rounded-full bg-amber-100 flex items-center justify-center text-amber-600 border border-amber-200">
                        <span class="material-symbols-outlined">autorenew</span>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-wider text-outline">Processing</p>
                        <p class="text-headline-md font-bold text-on-surface"><?php echo number_format($processing_count); ?></p>
                    </div>
                </div>
                <div class="bg-surface-container-lowest border border-outline-variant kinetic-shadow p-4 rounded-xl flex items-center gap-4 min-w-[150px]">
                    <div class="w-12 h-12 rounded-full bg-primary-container/20 flex items-center justify-center text-primary border border-primary/20">
                        <span class="material-symbols-outlined">check_circle</span>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-wider text-outline">Completed</p>
                        <p class="text-headline-md font-bold text-on-surface"><?php echo number_format($completed_count); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-surface-container-lowest border border-outline-variant rounded-xl kinetic-shadow overflow-hidden flex flex-col">
            
            <div class="px-gutter py-4 border-b border-outline-variant flex flex-wrap items-center justify-between gap-4 bg-surface-bright">
                <div class="relative w-full md:w-80">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-outline">search</span>
                    <input id="searchInput" class="w-full pl-10 pr-4 py-2.5 bg-surface border border-outline-variant/50 rounded-lg focus:ring-1 focus:ring-primary focus:border-primary text-sm outline-none transition-all" placeholder="Search orders by ID or Customer..." type="text">
                </div>
                <div class="flex flex-wrap gap-2">
                    <button class="order-filter active px-4 py-1.5 text-xs font-bold border border-outline-variant/40 rounded-full" data-filter="all">All</button>
                    <button class="order-filter px-4 py-1.5 text-xs font-bold border border-outline-variant/40 rounded-full text-on-surface-variant" data-filter="awaiting">Awaiting Payment</button>
                    <button class="order-filter px-4 py-1.5 text-xs font-bold border border-outline-variant/40 rounded-full text-on-surface-variant" data-filter="pending">Pending</button>
                    <button class="order-filter px-4 py-1.5 text-xs font-bold border border-outline-variant/40 rounded-full text-on-surface-variant" data-filter="processing">Processing</button>
                    <button class="order-filter px-4 py-1.5 text-xs font-bold border border-outline-variant/40 rounded-full text-on-surface-variant" data-filter="completed">Completed</button>
                    <button class="order-filter px-4 py-1.5 text-xs font-bold border border-outline-variant/40 rounded-full text-on-surface-variant" data-filter="cancelled">Cancelled</button>
                </div>
            </div>

            <div class="overflow-x-auto flex-grow">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-surface-container-low text-on-surface-variant border-b border-outline-variant/50">
                        <tr>
                            <th class="px-6 py-4 font-bold text-[10px] uppercase tracking-widest">Order ID</th>
                            <th class="px-6 py-4 font-bold text-[10px] uppercase tracking-widest">Customer Name</th>
                            <th class="px-6 py-4 font-bold text-[10px] uppercase tracking-widest">Date</th>
                            <th class="px-6 py-4 font-bold text-[10px] uppercase tracking-widest text-right">Total Price</th>
                            <th class="px-6 py-4 font-bold text-[10px] uppercase tracking-widest text-center">Status</th>
                            <th class="px-6 py-4 font-bold text-[10px] uppercase tracking-widest text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/30">
                        <?php if(empty($all_orders)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-outline italic">No orders found in the system.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($all_orders as $order):
                                $info = order_status_info($order['status']);
                                $key = $info['key'];
                                $label = $info['label'];
                                $is_final = in_array($key, ['completed', 'cancelled'], true);
                                if ($key === 'awaiting' || $key === 'pending') { $badge = 'bg-amber-50 text-amber-700 border border-amber-200'; }
                                elseif ($key === 'processing') { $badge = 'bg-blue-50 text-blue-700 border border-blue-200'; }
                                elseif ($key === 'completed') { $badge = 'bg-emerald-50 text-emerald-700 border border-emerald-200'; }
                                else { $badge = 'bg-red-50 text-red-700 border border-red-200'; }
                            ?>
                            <tr class="hover:bg-surface-bright transition-colors group" data-status="<?php echo $key; ?>">
                                <td class="px-6 py-4 text-sm font-bold font-mono text-primary">#ORD-<?php echo str_pad($order['order_id'], 5, '0', STR_PAD_LEFT); ?></td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-full bg-surface-container-highest text-primary flex items-center justify-center text-xs font-bold border border-outline-variant/50">
                                            <?php 
                                            $words = explode(" ", trim($order['full_name']));
                                            echo strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
                                            ?>
                                        </div>
                                        <span class="text-sm font-bold text-on-surface"><?php echo htmlspecialchars($order['full_name']); ?></span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-on-surface-variant font-medium"><?php echo date("M d, Y", strtotime($order['order_date'])); ?></td>
                                <td class="px-6 py-4 font-bold text-sm text-right text-on-surface">₱<?php echo number_format($order['total_amount'], 2); ?></td>
                                <td class="px-6 py-4 text-center">
                                    <span class="<?php echo $badge; ?> px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-widest">
                                        <?php echo htmlspecialchars($label); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex justify-center gap-1">
                                        <button onclick="openOrderModal(<?php echo $order['order_id']; ?>)" class="p-2 rounded-lg text-on-surface-variant hover:bg-surface-container transition-colors" title="View items">
                                            <span class="material-symbols-outlined text-[20px]">visibility</span>
                                        </button>
                                        <?php if (!$is_final && in_array($key, ['awaiting', 'pending'], true)): ?>
                                        <button onclick="updateOrderStatus(<?php echo $order['order_id']; ?>, 'Processing')" class="p-2 rounded-lg text-blue-600 hover:bg-blue-50 transition-colors" title="Start processing">
                                            <span class="material-symbols-outlined text-[20px]">autorenew</span>
                                        </button>
                                        <?php endif; ?>
                                        <?php if (!$is_final && in_array($key, ['processing', 'awaiting', 'pending'], true)): ?>
                                        <button onclick="updateOrderStatus(<?php echo $order['order_id']; ?>, 'Completed')" class="p-2 rounded-lg text-green-600 hover:bg-green-50 transition-colors" title="Mark completed">
                                            <span class="material-symbols-outlined text-[20px]">check_circle</span>
                                        </button>
                                        <?php endif; ?>
                                        <?php if (!$is_final): ?>
                                        <button onclick="updateOrderStatus(<?php echo $order['order_id']; ?>, 'Cancelled')" class="p-2 rounded-lg text-red-600 hover:bg-red-50 transition-colors" title="Cancel order">
                                            <span class="material-symbols-outlined text-[20px]">cancel</span>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <tr id="noFilterMatch" style="display:none;">
                                <td colspan="6" class="px-6 py-12 text-center text-outline">
                                    <span class="material-symbols-outlined text-outline-variant text-[40px] block mb-2">search_off</span>
                                    No orders match the selected filter.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-margin-md grid grid-cols-1 lg:grid-cols-3 gap-gutter">
            <div class="lg:col-span-2 bg-surface-container-lowest border border-outline-variant rounded-xl p-gutter relative overflow-hidden group shadow-sm">
                <div class="relative z-10">
                    <h3 class="text-headline-md font-bold mb-4 text-on-surface">Inventory Insights</h3>
                    <div class="flex items-end gap-2 h-32">
                        <div class="flex-1 bg-primary rounded-t-lg transition-all group-hover:h-[80%] h-[60%]"></div>
                        <div class="flex-1 bg-primary/60 rounded-t-lg transition-all group-hover:h-[95%] h-[75%]"></div>
                        <div class="flex-1 bg-primary rounded-t-lg transition-all group-hover:h-[65%] h-[45%]"></div>
                        <div class="flex-1 bg-primary/40 rounded-t-lg transition-all group-hover:h-[40%] h-[30%]"></div>
                        <div class="flex-1 bg-primary rounded-t-lg transition-all group-hover:h-[70%] h-[55%]"></div>
                        <div class="flex-1 bg-primary/70 rounded-t-lg transition-all group-hover:h-[85%] h-[65%]"></div>
                    </div>
                    <p class="mt-4 text-sm text-on-surface-variant font-medium">Cancelling a paid order automatically returns its reserved stock to inventory.</p>
                </div>
                <div class="absolute -right-16 -top-16 w-64 h-64 bg-primary/5 rounded-full blur-3xl group-hover:bg-primary/10 transition-colors pointer-events-none"></div>
            </div>
            
            <div class="bg-primary text-white rounded-xl p-gutter shadow-lg relative overflow-hidden flex flex-col justify-between">
                <div class="relative z-10">
                    <span class="material-symbols-outlined text-[48px] opacity-50 mb-4">bolt</span>
                    <h3 class="text-xl font-bold mb-2">Fulfillment Flow</h3>
                    <p class="text-sm text-white/80 leading-relaxed">Pending → Processing → Completed. Completing an order stamps its fulfillment date automatically.</p>
                </div>
                <div class="absolute inset-0 bg-gradient-to-br from-primary via-primary to-primary-container opacity-50 pointer-events-none"></div>
            </div>
        </div>
        
        <footer class="mt-margin-md pt-8 border-t border-outline-variant flex justify-between text-[12px] font-bold tracking-widest text-outline uppercase pb-8">
            <p>© 2024 HI-POWER Technologies.</p>
            <div class="flex gap-6">
                <a class="hover:text-primary transition-colors" href="analytics.php">System Status</a>
                <a class="hover:text-primary transition-colors" href="/support.php?tab=contact">Support</a>
            </div>
        </footer>
    </main>
</div>

<!-- Order detail modal -->
<div id="orderModal" class="fixed inset-0 z-[200] hidden items-center justify-center p-4 modal-overlay">
    <div class="bg-white w-full max-w-lg rounded-2xl shadow-2xl modal-card overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-outline-variant/40">
            <div>
                <h3 class="font-bold text-on-surface" id="omTitle">Order details</h3>
                <p class="text-xs text-outline" id="omSub">&nbsp;</p>
            </div>
            <button onclick="closeOrderModal()" class="w-9 h-9 rounded-lg hover:bg-surface-container flex items-center justify-center text-on-surface-variant">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="p-6 max-h-[60vh] overflow-y-auto" id="omBody">
            <div class="flex items-center justify-center py-10 text-outline">
                <span class="material-symbols-outlined animate-spin mr-2">progress_activity</span> Loading…
            </div>
        </div>
        <div class="px-6 py-4 border-t border-outline-variant/40 flex justify-between items-center bg-surface-bright">
            <span class="text-sm font-bold text-on-surface-variant">Total</span>
            <span class="text-lg font-bold text-primary" id="omTotal">₱0.00</span>
        </div>
    </div>
</div>

<script>
    // Handle status updates via hidden form
    function updateOrderStatus(orderId, newStatus) {
        if(confirm('Are you sure you want to mark Order #ORD-' + String(orderId).padStart(5, '0') + ' as ' + newStatus + '?')) {
            document.getElementById('action_order_id').value = orderId;
            document.getElementById('action_status').value = newStatus;
            document.getElementById('orderActionForm').submit();
        }
    }

    // Order detail modal
    function openOrderModal(orderId) {
        const modal = document.getElementById('orderModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.getElementById('omBody').innerHTML = '<div class="flex items-center justify-center py-10 text-outline"><span class="material-symbols-outlined animate-spin mr-2">progress_activity</span> Loading…</div>';
        document.getElementById('omTitle').textContent = 'Order #ORD-' + String(orderId).padStart(5, '0');
        document.getElementById('omSub').innerHTML = '&nbsp;';
        document.getElementById('omTotal').textContent = '₱0.00';

        fetch('order_items?order_id=' + encodeURIComponent(orderId))
            .then(r => r.json())
            .then(d => {
                if (!d.ok) { document.getElementById('omBody').innerHTML = '<p class="text-error text-sm font-bold">' + (d.error || 'Failed to load') + '</p>'; return; }
                const o = d.order;
                document.getElementById('omTitle').textContent = 'Order #' + o.number;
                document.getElementById('omSub').textContent = o.customer + ' • ' + o.date + (o.fulfilled_at ? (' • fulfilled ' + o.fulfilled_at) : '');
                document.getElementById('omTotal').textContent = '₱' + Number(o.total).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
                if (!d.items.length) {
                    document.getElementById('omBody').innerHTML = '<p class="text-outline italic text-sm">No line items recorded for this order.</p>';
                    return;
                }
                let rows = '';
                d.items.forEach(it => {
                    rows += '<tr class="border-b border-outline-variant/30">' +
                        '<td class="py-2.5 pr-3 text-sm text-on-surface">' + it.description + '</td>' +
                        '<td class="py-2.5 px-3 text-sm text-center text-on-surface-variant">' + it.quantity + '</td>' +
                        '<td class="py-2.5 px-3 text-sm text-right text-on-surface-variant">₱' + Number(it.unit_price).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}) + '</td>' +
                        '<td class="py-2.5 pl-3 text-sm text-right font-bold text-on-surface">₱' + Number(it.amount).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}) + '</td>' +
                    '</tr>';
                });
                document.getElementById('omBody').innerHTML =
                    '<table class="w-full"><thead><tr class="text-[10px] uppercase tracking-widest text-outline">' +
                    '<th class="text-left py-2 pr-3">Item</th><th class="text-center py-2 px-3">Qty</th>' +
                    '<th class="text-right py-2 px-3">Unit</th><th class="text-right py-2 pl-3">Amount</th>' +
                    '</tr></thead><tbody>' + rows + '</tbody></table>';
            })
            .catch(() => { document.getElementById('omBody').innerHTML = '<p class="text-error text-sm font-bold">Network error.</p>'; });
    }
    function closeOrderModal() {
        const modal = document.getElementById('orderModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    // Search + status filter
    document.addEventListener('DOMContentLoaded', () => {
        const searchInput = document.getElementById('searchInput');
        const filterBtns = document.querySelectorAll('.order-filter');
        let activeFilter = 'all';

        function applyFilters() {
            const term = (searchInput.value || '').toLowerCase();
            let visible = 0;
            document.querySelectorAll('tbody tr[data-status]').forEach(row => {
                const matchesTerm = row.textContent.toLowerCase().includes(term);
                const matchesFilter = (activeFilter === 'all' || row.dataset.status === activeFilter);
                const show = matchesTerm && matchesFilter;
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            const none = document.getElementById('noFilterMatch');
            if (none) none.style.display = (visible === 0 && document.querySelectorAll('tbody tr[data-status]').length > 0) ? '' : 'none';
        }

        if (searchInput) searchInput.addEventListener('input', applyFilters);
        filterBtns.forEach(btn => btn.addEventListener('click', () => {
            filterBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            activeFilter = btn.dataset.filter;
            applyFilters();
        }));

        // Close modal on overlay click
        const modal = document.getElementById('orderModal');
        if (modal) modal.addEventListener('click', e => { if (e.target === modal) closeOrderModal(); });
    });
</script>

</body>
</html>
