<?php
require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database_connect.php';
require_once __DIR__ . '/includes/csrf_helper.php';

Config::load();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: /authentication/login");
    exit;
}

$user_id = $_SESSION['user_id'];
$booking_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$booking_id) { header("Location: courtbooking.php"); exit; }

// Fetch booking
$stmt = $pdo->prepare("
    SELECT b.*, c.name as court_name
    FROM Bookings b
    JOIN Courts c ON b.court_id = c.court_id
    WHERE b.booking_id = ? AND b.user_id = ?
");
$stmt->execute([$booking_id, $user_id]);
$booking = $stmt->fetch();

if (!$booking) { header("Location: courtbooking.php"); exit; }
// Prevent payment for past dates
if ($booking['booking_date'] < date('Y-m-d')) {
    header("Location: courtbooking.php");
    exit;
}
if (!in_array($booking['status'], ['Pending Payment'])) {
    header("Location: courtbooking.php");
    exit;
}

$tax_rate = (float)Config::get('PAYMENT_TAX_RATE', 0.08);
$tax_amount = $booking['total_price'] * $tax_rate;
$grand_total = $booking['total_price'] + $tax_amount;

$success_message = '';
$error_message = '';

// 24-hour rule: check if booking is within 24 hours from now
$booking_datetime = strtotime($booking['booking_date'] . ' ' . $booking['start_time']);
$hours_until = ($booking_datetime - time()) / 3600;
$is_within_24h = $hours_until < 24;

// Handle Cash payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pay_cash') {
    verify_csrf_token();
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
    
    try {
        // Re-check 24-hour rule server-side
        if ($is_within_24h) {
            $error_message = "Pay at Counter is only available for bookings made 24+ hours in advance. Please use an online payment method.";
        } else {
            $conflict = $pdo->prepare("SELECT booking_id FROM Bookings WHERE court_id = ? AND booking_date = ? AND status = 'Confirmed' AND booking_id != ? AND start_time < ? AND end_time > ?");
            $conflict->execute([$booking['court_id'], $booking['booking_date'], $booking_id, $booking['end_time'], $booking['start_time']]);
            if ($conflict->rowCount() > 0) {
                $error_message = "This time slot has been taken by another booking. Please choose a different slot.";
            } else {
                $pdo->prepare("UPDATE Bookings SET status = 'Pending' WHERE booking_id = ? AND user_id = ?")->execute([$booking_id, $user_id]);
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true]);
                    exit;
                }
                header("Location: courtbooking?payment=success");
                exit;
            }
        }
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error_message]);
            exit;
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error_message]);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>Complete Payment | ShuttleSync</title>
    <?php include __DIR__ . '/includes/tailwind_config.php'; ?>
    <style>
        @keyframes fadeUp { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }
        .fade-up { animation: fadeUp 0.5s cubic-bezier(.22,1,.36,1) forwards; }
        .pay-btn {
            transition: all 0.3s ease;
        }
        .pay-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 30px -8px rgba(0,0,0,0.2);
        }
        .pay-btn:active { transform: scale(0.98); }
    </style>
</head>
<body class="bg-surface min-h-screen antialiased">

<?php include __DIR__ . '/includes/nav_bar.php'; ?>

<main class="flex-1 max-w-4xl mx-auto w-full px-6 md:px-12 pt-24 pb-20">

    <!-- Back link -->
    <a href="courtbooking" class="inline-flex items-center gap-1.5 text-sm font-bold text-on-surface-variant hover:text-primary transition-colors mb-8">
        <span class="material-symbols-outlined text-[18px]">arrow_back</span> Back to Courts
    </a>

    <?php if (!empty($error_message)): ?>
        <div class="mb-6 p-4 bg-accent/5 border border-accent/20 rounded-2xl flex items-center gap-3 text-accent">
            <span class="material-symbols-outlined">error</span>
            <p class="font-bold text-sm"><?php echo htmlspecialchars($error_message); ?></p>
        </div>
    <?php endif; ?>

    <!-- Step Indicator -->
    <div class="flex items-center justify-center gap-3 mb-10 fade-up" style="animation-delay:0.1s">
        <div class="flex items-center gap-2">
            <span class="w-8 h-8 rounded-full bg-green-500 text-white flex items-center justify-center text-xs font-bold">
                <span class="material-symbols-outlined text-[16px]">check</span>
            </span>
            <span class="text-sm font-bold text-green-600">Booked</span>
        </div>
        <div class="w-12 h-0.5 bg-primary rounded-full"></div>
        <div class="flex items-center gap-2">
            <span class="w-8 h-8 rounded-full bg-primary text-white flex items-center justify-center text-xs font-bold">2</span>
            <span class="text-sm font-bold text-primary">Pay Now</span>
        </div>
        <div class="w-12 h-0.5 bg-outline-variant/40 rounded-full"></div>
        <div class="flex items-center gap-2">
            <span class="w-8 h-8 rounded-full bg-surface-container text-on-surface-variant flex items-center justify-center text-xs font-bold">3</span>
            <span class="text-sm font-bold text-on-surface-variant/50">Confirmed</span>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-8">

        <!-- Booking Details (Left) -->
        <div class="lg:col-span-2 fade-up" style="animation-delay:0.2s">
            <div class="bg-white rounded-3xl border border-outline-variant/30 overflow-hidden shadow-lg shadow-black/5">
                <!-- Header -->
                <div class="bg-gradient-to-r from-primary to-primary-dark p-6 text-white relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-24 h-24 bg-white/10 rounded-full -translate-y-1/2 translate-x-1/2"></div>
                    <div class="relative z-10">
                        <span class="text-white/50 text-[10px] font-bold uppercase tracking-[0.2em]">Booking Reference</span>
                        <p class="text-lg font-bold mt-1"><?php echo htmlspecialchars($booking['booking_reference']); ?></p>
                    </div>
                </div>

                <div class="p-6 space-y-5">
                    <div class="flex items-start gap-4">
                        <div class="w-11 h-11 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-primary text-xl">stadium</span>
                        </div>
                        <div>
                            <span class="text-[10px] text-on-surface-variant font-bold uppercase tracking-widest">Court</span>
                            <p class="text-base font-bold text-on-surface"><?php echo htmlspecialchars($booking['court_name']); ?></p>
                        </div>
                    </div>

                    <div class="flex items-start gap-4">
                        <div class="w-11 h-11 rounded-xl bg-accent/10 flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-accent text-xl">calendar_today</span>
                        </div>
                        <div>
                            <span class="text-[10px] text-on-surface-variant font-bold uppercase tracking-widest">Date</span>
                            <p class="text-base font-bold text-on-surface"><?php echo date('l, M d, Y', strtotime($booking['booking_date'])); ?></p>
                        </div>
                    </div>

                    <div class="flex items-start gap-4">
                        <div class="w-11 h-11 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-primary text-xl">schedule</span>
                        </div>
                        <div>
                            <span class="text-[10px] text-on-surface-variant font-bold uppercase tracking-widest">Time</span>
                            <p class="text-base font-bold text-on-surface"><?php echo date('h:i A', strtotime($booking['start_time'])) . ' – ' . date('h:i A', strtotime($booking['end_time'])); ?></p>
                        </div>
                    </div>

                    <div class="flex items-start gap-4">
                        <div class="w-11 h-11 rounded-xl bg-accent/10 flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-accent text-xl">groups</span>
                        </div>
                        <div>
                            <span class="text-[10px] text-on-surface-variant font-bold uppercase tracking-widest">Players</span>
                            <p class="text-base font-bold text-on-surface"><?php echo $booking['player_count']; ?> player<?php echo $booking['player_count'] > 1 ? 's' : ''; ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Methods (Right) -->
        <div class="lg:col-span-3 fade-up" style="animation-delay:0.35s">
            <div class="bg-white rounded-3xl border border-outline-variant/30 shadow-lg shadow-black/5 p-8">
                <h2 class="text-2xl font-bold text-on-surface mb-1">Complete Payment</h2>
                <p class="text-sm text-on-surface-variant mb-8">Choose your preferred payment method to confirm your booking.</p>

                <!-- Price Summary -->
                <div class="bg-surface rounded-2xl p-5 mb-8 space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-on-surface-variant">Court Fee (<?php echo date('h:i A', strtotime($booking['start_time'])) . ' – ' . date('h:i A', strtotime($booking['end_time'])); ?>)</span>
                        <span class="text-sm font-bold">₱<?php echo number_format($booking['total_price'], 2); ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-on-surface-variant">Tax (<?php echo ($tax_rate * 100); ?>%)</span>
                        <span class="text-sm font-bold">₱<?php echo number_format($tax_amount, 2); ?></span>
                    </div>
                    <div class="flex justify-between items-center border-t border-outline-variant/30 pt-3">
                        <span class="text-base font-bold text-on-surface">Total Due</span>
                        <span class="text-3xl font-bold text-primary">₱<?php echo number_format($grand_total, 2); ?></span>
                    </div>
                </div>

                <!-- PayPal -->
                <div id="paypal-button-container" class="mb-4"></div>

                <!-- GCash -->
                <div class="mb-4">
                    <button onclick="initPayMongo('gcash')" id="gcashBtn" class="pay-btn w-full py-3.5 bg-[#007DFB] text-white font-bold text-sm rounded-xl flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">account_balance_wallet</span> GCash
                    </button>
                </div>

                <!-- Cash -->
                <div class="border-t border-outline-variant/30 pt-5">
                    <?php if ($is_within_24h): ?>
                        <button disabled class="w-full py-3.5 bg-surface border border-outline-variant/30 text-on-surface-variant/40 font-bold text-sm rounded-xl flex items-center justify-center gap-2 cursor-not-allowed">
                            <span class="material-symbols-outlined text-[18px]">storefront</span> Pay at Counter
                        </button>
                        <div class="mt-3 bg-red-50 border border-red-200 p-3 rounded-xl">
                            <p class="text-[10px] text-red-700 font-bold uppercase tracking-widest mb-1 flex items-center gap-1">
                                <span class="material-symbols-outlined text-[14px]">warning</span> Not Available
                            </p>
                            <p class="text-xs text-red-600 leading-relaxed">This booking is less than 24 hours away. Pay at Counter is only available for advance bookings. Please use GCash or PayPal.</p>
                        </div>
                    <?php else: ?>
                        <button type="button" onclick="openCashModal()" class="w-full py-3.5 bg-surface border border-outline-variant/50 text-on-surface font-bold text-sm rounded-xl flex items-center justify-center gap-2 hover:bg-surface-container hover:border-primary/30 transition-all">
                            <span class="material-symbols-outlined text-[18px]">storefront</span> Pay at Counter
                        </button>
                        <div class="mt-3 bg-amber-50 border border-amber-200 p-3 rounded-xl">
                            <p class="text-[10px] text-amber-700 font-bold uppercase tracking-widest mb-1 flex items-center gap-1">
                                <span class="material-symbols-outlined text-[14px]">info</span> Cash Policy
                            </p>
                            <p class="text-xs text-amber-600 leading-relaxed">Present your booking receipt at the counter within 24 hours to confirm your reservation.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Trust badges -->
                <div class="flex items-center justify-center gap-6 mt-8 opacity-30">
                    <span class="material-symbols-outlined text-2xl">verified_user</span>
                    <span class="material-symbols-outlined text-2xl">bolt</span>
                    <span class="material-symbols-outlined text-2xl">lock</span>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- ==========================================
     CASH PAYMENT TERMS MODAL
========================================== -->
<div id="cashModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden max-h-[90vh] flex flex-col">
        <div class="p-6 border-b border-outline-variant/30">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-bold text-on-surface">Pay at Counter — Terms</h3>
                <button onclick="closeCashModal()" class="w-8 h-8 rounded-full bg-surface-variant/50 flex items-center justify-center hover:bg-error hover:text-white transition-all">
                    <span class="material-symbols-outlined text-[18px]">close</span>
                </button>
            </div>
        </div>
        <div class="p-6 overflow-y-auto flex-grow space-y-4">
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
                <p class="text-sm font-bold text-amber-800 mb-2">Important Rules:</p>
                <ul class="space-y-2 text-xs text-amber-700 leading-relaxed">
                    <li class="flex items-start gap-2">
                        <span class="material-symbols-outlined text-[14px] mt-0.5 shrink-0">check_circle</span>
                        You must present this receipt at the Hi-Power BC counter <strong>within 24 hours</strong> of booking.
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="material-symbols-outlined text-[14px] mt-0.5 shrink-0">check_circle</span>
                        Your booking will be held as <strong>"Pending"</strong> until counter payment is confirmed by staff.
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="material-symbols-outlined text-[14px] mt-0.5 shrink-0">check_circle</span>
                        If not paid within 24 hours, the reservation may be <strong>cancelled automatically</strong>.
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="material-symbols-outlined text-[14px] mt-0.5 shrink-0">check_circle</span>
                        Cash payment at the counter: <strong>₱<?php echo number_format($grand_total, 2); ?></strong> (incl. tax).
                    </li>
                </ul>
            </div>
            <label class="flex items-start gap-3 cursor-pointer group">
                <input type="checkbox" id="termsCheck" class="mt-0.5 w-4 h-4 rounded border-outline-variant text-primary focus:ring-primary/30" onchange="document.getElementById('confirmCashBtn').disabled = !this.checked">
                <span class="text-sm text-on-surface-variant leading-relaxed">I understand and agree to the Pay at Counter terms above. I will present this receipt at the counter within 24 hours.</span>
            </label>
        </div>
        <div class="p-6 border-t border-outline-variant/30 bg-surface">
            <div class="flex gap-3">
                <button onclick="closeCashModal()" class="flex-1 py-3 bg-surface border border-outline-variant/50 text-on-surface-variant font-bold text-sm rounded-xl hover:bg-surface-container transition-all">
                    Cancel
                </button>
                <button id="confirmCashBtn" disabled onclick="confirmCashPayment()" class="flex-1 py-3 bg-primary text-white font-bold text-sm rounded-xl hover:bg-primary-dark transition-all disabled:opacity-40 disabled:cursor-not-allowed">
                    Confirm &amp; Generate Receipt
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     RECEIPT MODAL
========================================== -->
<div id="receiptModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden max-h-[90vh] flex flex-col">
        <div class="p-6 border-b border-outline-variant/30">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-bold text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-green-600 text-[22px]">check_circle</span> Booking Confirmed
                </h3>
                <button onclick="closeReceiptModal()" class="w-8 h-8 rounded-full bg-surface-variant/50 flex items-center justify-center hover:bg-error hover:text-white transition-all">
                    <span class="material-symbols-outlined text-[18px]">close</span>
                </button>
            </div>
        </div>
        <div class="p-6 overflow-y-auto flex-grow" id="receiptScrollArea">
            <!-- Receipt Card -->
            <div id="receiptCard" class="bg-white border-2 border-dashed border-outline-variant/40 rounded-2xl overflow-hidden">
                <!-- Receipt Header -->
                <div class="bg-gradient-to-r from-primary to-primary-dark p-5 text-white text-center relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-16 h-16 bg-white/10 rounded-full -translate-y-1/2 translate-x-1/2"></div>
                    <div class="absolute bottom-0 left-0 w-12 h-12 bg-white/10 rounded-full translate-y-1/2 -translate-x-1/2"></div>
                    <div class="relative z-10">
                        <img src="/img/logo.png" class="w-10 h-10 mx-auto mb-2" onerror="this.style.display='none'">
                        <p class="text-lg font-bold">ShuttleSync</p>
                        <p class="text-[10px] text-white/60 uppercase tracking-[0.2em] mt-1">Pay at Counter Receipt</p>
                    </div>
                </div>

                <div class="p-5 space-y-4">
                    <!-- Status Badge -->
                    <div class="text-center">
                        <span class="inline-flex items-center gap-1.5 px-4 py-1.5 bg-amber-100 text-amber-700 rounded-full text-[10px] font-bold uppercase tracking-widest border border-amber-200">
                            <span class="w-1.5 h-1.5 bg-amber-500 rounded-full animate-pulse"></span> Pending Counter Payment
                        </span>
                    </div>

                    <!-- Booking Reference -->
                    <div class="text-center pb-3 border-b border-dashed border-outline-variant/30">
                        <p class="text-[10px] text-on-surface-variant font-bold uppercase tracking-widest">Booking Reference</p>
                        <p class="text-xl font-bold text-primary font-mono mt-1"><?php echo htmlspecialchars($booking['booking_reference']); ?></p>
                    </div>

                    <!-- Details -->
                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between">
                            <span class="text-on-surface-variant">Court</span>
                            <span class="font-bold text-on-surface"><?php echo htmlspecialchars($booking['court_name']); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-on-surface-variant">Date</span>
                            <span class="font-bold text-on-surface"><?php echo date('M d, Y', strtotime($booking['booking_date'])); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-on-surface-variant">Time</span>
                            <span class="font-bold text-on-surface"><?php echo date('h:i A', strtotime($booking['start_time'])) . ' – ' . date('h:i A', strtotime($booking['end_time'])); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-on-surface-variant">Players</span>
                            <span class="font-bold text-on-surface"><?php echo $booking['player_count']; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-on-surface-variant">Customer</span>
                            <span class="font-bold text-on-surface"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                        </div>
                    </div>

                    <!-- Total -->
                    <div class="bg-surface rounded-xl p-3 space-y-2 border border-outline-variant/20">
                        <div class="flex justify-between text-sm">
                            <span class="text-on-surface-variant">Court Fee</span>
                            <span class="font-semibold">₱<?php echo number_format($booking['total_price'], 2); ?></span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-on-surface-variant">Tax (<?php echo ($tax_rate * 100); ?>%)</span>
                            <span class="font-semibold">₱<?php echo number_format($tax_amount, 2); ?></span>
                        </div>
                        <div class="flex justify-between text-sm border-t border-outline-variant/20 pt-2">
                            <span class="font-bold text-on-surface">Total Due</span>
                            <span class="font-bold text-primary text-lg">₱<?php echo number_format($grand_total, 2); ?></span>
                        </div>
                    </div>

                    <!-- Barcode-style reference -->
                    <div class="text-center pt-2">
                        <div class="font-mono text-[10px] text-on-surface-variant tracking-[0.3em] bg-surface rounded-lg py-2 px-3 inline-block">
                            ||||| <?php echo htmlspecialchars($booking['booking_reference']); ?> |||||
                        </div>
                    </div>

                    <!-- Instructions -->
                    <div class="bg-blue-50 border border-blue-200 rounded-xl p-3 text-center">
                        <p class="text-[10px] text-blue-700 font-bold uppercase tracking-widest mb-1">Instructions</p>
                        <p class="text-xs text-blue-600 leading-relaxed">Present this receipt at the Hi-Power BC counter within 24 hours. Show the reference code to staff for confirmation.</p>
                    </div>

                    <p class="text-[9px] text-on-surface-variant/40 text-center">
                        Generated: <?php echo date('M d, Y h:i A'); ?> &middot; ShuttleSync &copy; <?php echo date('Y'); ?>
                    </p>
                </div>
            </div>
        </div>
        <div class="p-6 border-t border-outline-variant/30 bg-surface">
            <div class="flex gap-3">
                <button onclick="printReceipt()" class="flex-1 py-3 bg-surface border border-outline-variant/50 text-on-surface font-bold text-sm rounded-xl flex items-center justify-center gap-2 hover:bg-surface-container transition-all">
                    <span class="material-symbols-outlined text-[18px]">print</span> Print
                </button>
                <button onclick="downloadReceipt()" class="flex-1 py-3 bg-primary text-white font-bold text-sm rounded-xl flex items-center justify-center gap-2 hover:bg-primary-dark transition-all">
                    <span class="material-symbols-outlined text-[18px]">download</span> Save as PNG
                </button>
            </div>
            <button onclick="closeReceiptModal()" class="w-full mt-3 py-2.5 text-on-surface-variant text-xs font-bold hover:text-primary transition-colors">
                Done
            </button>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/mobile_nav.php'; ?>

<script>
const bookingId = <?php echo $booking_id; ?>;
const grandTotal = '<?php echo number_format($grand_total, 2, '.', ''); ?>';

// ==========================================
// PAYPAL
// ==========================================
const PayPalClientId = '<?php echo htmlspecialchars(Config::get("PAYPAL_CLIENT_ID")); ?>';
let paypalOrderId = null;

if (PayPalClientId && PayPalClientId !== 'YOUR_PAYPAL_CLIENT_ID_HERE') {
    const script = document.createElement('script');
    script.src = 'https://www.paypal.com/sdk/js?client-id=' + PayPalClientId + '&currency=PHP&intent=capture&disable-funding=credit,card';
    script.onload = initPayPalButtons;
    document.head.appendChild(script);
} else {
    const pp = document.getElementById('paypal-button-container');
    if (pp) pp.innerHTML = '<p class="text-xs text-on-surface-variant/40 text-center py-3">PayPal not configured</p>';
}

function initPayPalButtons() {
    if (typeof paypal === 'undefined') return;
    paypal.Buttons({
        style: { layout: 'vertical', color: 'blue', shape: 'rect', label: 'pay', height: 50 },
        createOrder: function() {
            return fetch('/payment/booking_handler.php?action=create_paypal_order', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'booking_id=' + bookingId
            }).then(r => r.json()).then(d => {
                if (d.error) throw new Error(d.error);
                paypalOrderId = d.order_id;
                return d.order_id;
            });
        },
        onApprove: function() {
            return fetch('/payment/booking_handler.php?action=capture_paypal_order', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'order_id=' + encodeURIComponent(paypalOrderId) + '&booking_id=' + encodeURIComponent(bookingId)
            }).then(r => r.json()).then(d => {
                if (d.error) throw new Error(d.error);
                window.location.href = '/courtbooking?payment=success';
            });
        },
        onError: function(err) { console.error(err); alert('Payment failed. Please try again.'); },
        onCancel: function() { console.log('Cancelled'); }
    }).render('#paypal-button-container');
}

// ==========================================
// PAYMONGO
// ==========================================
let paymongoWindow = null;
let pollInterval = null;

function initPayMongo(method) {
    const btn = document.getElementById('gcashBtn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-[18px]">refresh</span>';
    }

    fetch('/payment/booking_handler.php?action=create_paymongo_session', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'payment_method=' + method + '&booking_id=' + bookingId
    }).then(r => r.json()).then(data => {
        if (data.error) throw new Error(data.error);
        const w = 500, h = 700, l = (screen.width-w)/2, t = (screen.height-h)/2;
        paymongoWindow = window.open(data.checkout_url, 'paymongo', `width=${w},height=${h},left=${l},top=${t}`);
        startPaymentPolling(bookingId);
    }).catch(err => {
        alert('Payment failed: ' + err.message);
        resetPayMongoButtons();
    });
}

function startPaymentPolling(bId) {
    if (pollInterval) clearInterval(pollInterval);
    pollInterval = setInterval(() => {
        if (paymongoWindow && paymongoWindow.closed) {
            clearInterval(pollInterval);
            fetch('/payment/booking_handler.php?action=check_status', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'booking_id=' + bId
            }).then(r => r.json()).then(d => {
                if (d.status === 'Confirmed') {
                    window.location.href = '/courtbooking?payment=success';
                } else {
                    resetPayMongoButtons();
                    alert('Payment not completed. Please try again.');
                }
            });
            return;
        }
        fetch('/payment/booking_handler.php?action=check_status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'booking_id=' + bId
        }).then(r => r.json()).then(d => {
            if (d.status === 'Confirmed') {
                clearInterval(pollInterval);
                if (paymongoWindow && !paymongoWindow.closed) paymongoWindow.close();
                window.location.href = '/courtbooking?payment=success';
            }
        });
    }, 3000);
    setTimeout(() => { if (pollInterval) clearInterval(pollInterval); resetPayMongoButtons(); }, 600000);
}

function resetPayMongoButtons() {
    const btn = document.getElementById('gcashBtn');
    if (btn) { btn.disabled = false; btn.innerHTML = '<span class="material-symbols-outlined text-[18px]">account_balance_wallet</span> GCash'; }
}

// ==========================================
// PAY AT COUNTER FLOW
// ==========================================
function openCashModal() {
    const modal = document.getElementById('cashModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.getElementById('termsCheck').checked = false;
    document.getElementById('confirmCashBtn').disabled = true;
}

function closeCashModal() {
    const modal = document.getElementById('cashModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function confirmCashPayment() {
    closeCashModal();
    
    fetch('/book_and_pay.php?id=' + bookingId, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: 'action=pay_cash&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>'
    }).then(r => r.json()).then(data => {
        if (data.success) {
            openReceiptModal();
        } else {
            alert(data.error || 'Something went wrong. Please try again.');
        }
    }).catch(() => {
        alert('Network error. Please try again.');
    });
}

function openReceiptModal() {
    const modal = document.getElementById('receiptModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeReceiptModal() {
    const modal = document.getElementById('receiptModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    window.location.href = '/courtbooking?payment=success';
}

function printReceipt() {
    const card = document.getElementById('receiptCard');
    const printWindow = window.open('', '_blank', 'width=400,height=700');
    printWindow.document.write(`
        <html><head><title>Receipt - <?php echo htmlspecialchars($booking['booking_reference']); ?></title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 20px; background: #fff; }
            * { box-sizing: border-box; }
        </style>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
        </head><body></body></html>
    `);
    printWindow.document.close();
    printWindow.document.body.innerHTML = card.outerHTML;
    // Apply styles inline
    const style = printWindow.document.createElement('style');
    style.textContent = `
        body { font-family: 'Inter', sans-serif; padding: 10px; }
        .bg-gradient-to-r { background: linear-gradient(to right, #3145e6, #2535b8) !important; }
        .text-white { color: #fff !important; }
        .text-primary { color: #3145e6 !important; }
        .text-on-surface { color: #1a1a2e !important; }
        .text-on-surface-variant { color: #6b7280 !important; }
        .text-amber-700 { color: #b45309 !important; }
        .text-amber-800 { color: #92400e !important; }
        .bg-amber-100 { background: #fef3c7 !important; }
        .bg-surface { background: #f5f5f5 !important; }
        .bg-blue-50 { background: #eff6ff !important; }
        .border-blue-200 { border-color: #bfdbfe !important; }
        .text-blue-700 { color: #1d4ed8 !important; }
        .text-blue-600 { color: #2563eb !important; }
        .bg-white { background: #fff !important; }
        .border-dashed { border-style: dashed !important; }
        .border-2 { border-width: 2px !important; }
        .border-outline-variant\\/30 { border-color: #e5e7eb !important; }
        .border-outline-variant\\/20 { border-color: #e5e7eb !important; }
        .rounded-2xl { border-radius: 1rem !important; }
        .rounded-xl { border-radius: 0.75rem !important; }
        .rounded-full { border-radius: 9999px !important; }
        .rounded-lg { border-radius: 0.5rem !important; }
        .p-5 { padding: 1.25rem !important; }
        .p-3 { padding: 0.75rem !important; }
        .p-2 { padding: 0.5rem !important; }
        .px-3 { padding-left: 0.75rem; padding-right: 0.75rem; }
        .px-4 { padding-left: 1rem; padding-right: 1rem; }
        .py-1\\.5 { padding-top: 0.375rem; padding-bottom: 0.375rem; }
        .py-2 { padding-top: 0.5rem; padding-bottom: 0.5rem; }
        .mb-1 { margin-bottom: 0.25rem; }
        .mb-2 { margin-bottom: 0.5rem; }
        .mt-1 { margin-top: 0.25rem; }
        .pb-3 { padding-bottom: 0.75rem; }
        .pt-2 { padding-top: 0.5rem; }
        .text-center { text-align: center; }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .flex { display: flex; }
        .justify-between { justify-content: space-between; }
        .items-center { align-items: center; }
        .gap-1\\.5 { gap: 0.375rem; }
        .inline-block { display: inline-block; }
        .space-y-2 > * + * { margin-top: 0.5rem; }
        .space-y-3 > * + * { margin-top: 0.75rem; }
        .space-y-4 > * + * { margin-top: 1rem; }
        .text-xs { font-size: 0.75rem; }
        .text-sm { font-size: 0.875rem; }
        .text-lg { font-size: 1.125rem; }
        .text-xl { font-size: 1.25rem; }
        .text-lg { font-size: 1.125rem; }
        .text-xl { font-size: 1.25rem; }
        .text-2xl { font-size: 1.5rem; }
        .text-\\[9px\\] { font-size: 9px; }
        .text-\\[10px\\] { font-size: 10px; }
        .font-bold { font-weight: 700; }
        .font-semibold { font-weight: 600; }
        .font-mono { font-family: monospace !important; }
        .uppercase { text-transform: uppercase; }
        .tracking-widest { letter-spacing: 0.1em; }
        .tracking-\\[0\\.2em\\] { letter-spacing: 0.2em; }
        .tracking-\\[0\\.3em\\] { letter-spacing: 0.3em; }
        .leading-relaxed { line-height: 1.625; }
        .w-1\\.5 { width: 0.375rem; }
        .h-1\\.5 { height: 0.375rem; }
        .w-10 { width: 2.5rem; }
        .h-10 { height: 2.5rem; }
        .mx-auto { margin-left: auto; margin-right: auto; }
        .overflow-hidden { overflow: hidden; }
        .relative { position: relative; }
        .absolute { position: absolute; }
        .inset-0 { top: 0; right: 0; bottom: 0; left: 0; }
        .top-0 { top: 0; }
        .right-0 { right: 0; }
        .bottom-0 { bottom: 0; }
        .left-0 { left: 0; }
        .z-10 { z-index: 10; }
        .-translate-y-1\\/2 { transform: translateY(-50%); }
        .translate-x-1\\/2 { transform: translateX(50%); }
        .translate-y-1\\/2 { transform: translateY(50%); }
        .-translate-x-1\\/2 { transform: translateX(-50%); }
        .\\[0\\.3em\\] { letter-spacing: 0.3em; }
        .inline-flex { display: inline-flex; }
        .animate-pulse { animation: pulse 2s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        img { max-width: 100%; }
    `;
    printWindow.document.head.appendChild(style);
    setTimeout(() => { printWindow.print(); }, 500);
}

async function downloadReceipt() {
    const card = document.getElementById('receiptCard');
    const btn = event.target.closest('button');
    const origText = btn.innerHTML;
    btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-[18px]">refresh</span> Generating...';
    btn.disabled = true;

    try {
        // Dynamically load html2canvas
        if (typeof html2canvas === 'undefined') {
            await new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
                script.onload = resolve;
                script.onerror = reject;
                document.head.appendChild(script);
            });
        }

        const canvas = await html2canvas(card, {
            scale: 2,
            useCORS: true,
            backgroundColor: '#ffffff',
            logging: false,
        });

        const link = document.createElement('a');
        link.download = 'ShuttleSync-Receipt-<?php echo htmlspecialchars($booking['booking_reference']); ?>.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
    } catch (err) {
        alert('Failed to generate image. Please try again.');
        console.error(err);
    } finally {
        btn.innerHTML = origText;
        btn.disabled = false;
    }
}
</script>

</body>
</html>