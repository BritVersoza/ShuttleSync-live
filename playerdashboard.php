<?php
require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/csrf_helper.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: /authentication/login");
    exit;
}

require_once __DIR__ . '/includes/database_connect.php';

/**
 * A refund may only be requested once the payment has been accepted.
 * Bank transfers are accepted when an admin verifies the payment record.
 * Cash / PayPal / PayMongo payments have no payments row, so a confirmed or
 * completed item is treated as paid (matching the admin "Paid" display).
 */
if (!function_exists('refund_payment_accepted')) {
    function refund_payment_accepted(?string $payment_status, string $item_status): bool {
        if ($payment_status !== null) {
            return $payment_status === 'Verified';
        }
        return in_array($item_status, ['Confirmed', 'Completed', 'Paid', 'Delivered'], true);
    }
}

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$role = $_SESSION['role'];
$name_parts = explode(' ', trim($full_name));
$first_name = $name_parts[0];

// Handle AJAX skill level update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_skill') {
    verify_csrf_token();
    header('Content-Type: application/json');
    $new_skill = $_POST['skill_level'] ?? '';
    if (in_array($new_skill, ['Beginner', 'Intermediate', 'Advanced', 'Pro'])) {
        $stmt = $pdo->prepare("UPDATE Users SET skill_level = ? WHERE user_id = ?");
        $stmt->execute([$new_skill, $user_id]);
        echo json_encode(['ok' => true, 'skill' => $new_skill]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Invalid skill level']);
    }
    exit;
}

// Handle AJAX cancel booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_booking') {
    verify_csrf_token();
    header('Content-Type: application/json');
    $bid = (int)($_POST['booking_id'] ?? 0);
    if ($bid > 0) {
        // Fetch the booking to enforce ownership and cancellation policy.
        $q = $pdo->prepare("SELECT booking_id, user_id, status, booking_date, start_time, end_time FROM Bookings WHERE booking_id = ? AND user_id = ?");
        $q->execute([$bid, $user_id]);
        $b = $q->fetch();
        if (!$b) {
            echo json_encode(['ok' => false, 'error' => 'Booking not found.']);
            exit;
        }
        if (!in_array($b['status'], ['Confirmed', 'Pending Payment', 'Pending'], true)) {
            echo json_encode(['ok' => false, 'error' => 'This booking cannot be cancelled.']);
            exit;
        }
        $start_ts = strtotime($b['booking_date'] . ' ' . $b['start_time']);
        $end_ts   = strtotime($b['booking_date'] . ' ' . $b['end_time']);
        // Confirmed (paid) bookings: at least 2 hours before the scheduled time.
        if ($b['status'] === 'Confirmed' && $start_ts <= time() + (2 * 3600)) {
            echo json_encode(['ok' => false, 'error' => 'Cancellations must be made at least 2 hours before the scheduled time.']);
            exit;
        }
        // Never cancel a booking that has already ended.
        if ($end_ts <= time() && $b['status'] !== 'Pending Payment' && $b['status'] !== 'Pending') {
            echo json_encode(['ok' => false, 'error' => 'This booking has already ended.']);
            exit;
        }
        $stmt = $pdo->prepare("UPDATE Bookings SET status = 'Cancelled' WHERE booking_id = ? AND user_id = ? AND status IN ('Confirmed','Pending Payment','Pending')");
        $stmt->execute([$bid, $user_id]);
        echo json_encode(['ok' => $stmt->rowCount() > 0]);
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}

// Handle AJAX refund request (TC-AS-023 - customer side)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_refund') {
    verify_csrf_token();
    header('Content-Type: application/json');
    $order_id   = (int)($_POST['order_id'] ?? 0);
    $booking_id = (int)($_POST['booking_id'] ?? 0);
    $reason     = trim($_POST['reason'] ?? '');
    try {
        if ($reason === '') throw new Exception('Please describe why you are requesting a refund.');

        // Ensure the proof_path column exists (migration-safe).
        try {
            if (!$pdo->query("SHOW COLUMNS FROM refunds LIKE 'proof_path'")->fetch()) {
                $pdo->exec("ALTER TABLE refunds ADD COLUMN proof_path VARCHAR(255) NULL AFTER reason");
            }
        } catch (PDOException $e) { /* ignore */ }

        $amount = 0.0;
        $payment_id = null;

        if ($order_id > 0) {
            $q = $pdo->prepare("
                SELECT o.total_amount, o.payment_id, o.status, p.status AS payment_status
                FROM Orders o
                LEFT JOIN payments p ON p.payment_id = o.payment_id
                WHERE o.order_id = ? AND o.user_id = ?
            ");
            $q->execute([$order_id, $user_id]);
            $row = $q->fetch();
            if (!$row) throw new Exception('Order not found.');
            if (!in_array($row['status'], ['Completed', 'Cancelled', 'Confirmed', 'Delivered'], true)) {
                throw new Exception('This order is not eligible for a refund yet.');
            }
            if (!refund_payment_accepted($row['payment_status'] ?? null, $row['status'])) {
                throw new Exception('Refunds can only be requested for payments that have been accepted.');
            }
            $amount = (float)$row['total_amount'];
            $payment_id = $row['payment_id'] ?? null;
        } elseif ($booking_id > 0) {
            $q = $pdo->prepare("
                SELECT b.total_price, b.payment_id, b.status, p.status AS payment_status
                FROM Bookings b
                LEFT JOIN payments p ON p.payment_id = b.payment_id
                WHERE b.booking_id = ? AND b.user_id = ?
            ");
            $q->execute([$booking_id, $user_id]);
            $row = $q->fetch();
            if (!$row) throw new Exception('Booking not found.');
            if (!in_array($row['status'], ['Cancelled', 'Confirmed', 'Completed', 'Paid'], true)) {
                throw new Exception('This booking is not eligible for a refund yet.');
            }
            if (!refund_payment_accepted($row['payment_status'] ?? null, $row['status'])) {
                throw new Exception('Refunds can only be requested for payments that have been accepted.');
            }
            $amount = (float)$row['total_price'];
            $payment_id = $row['payment_id'] ?? null;
        } else {
            throw new Exception('Nothing selected for refund.');
        }

        if ($amount <= 0) throw new Exception('There is no amount to refund.');

        // Handle optional proof-of-reason upload (receipt, screenshot, etc.).
        $proof_path = null;
        if (isset($_FILES['proof']) && $_FILES['proof']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Failed to upload proof file.');
            }
            $size = (int)$_FILES['proof']['size'];
            if ($size <= 0 || $size > 5 * 1024 * 1024) {
                throw new Exception('Proof file must be under 5 MB.');
            }
            $ext = null;
            $img = @getimagesize($_FILES['proof']['tmp_name']);
            if ($img) {
                if ($img['mime'] === 'image/jpeg') $ext = 'jpg';
                elseif ($img['mime'] === 'image/png') $ext = 'png';
                elseif ($img['mime'] === 'image/gif') $ext = 'gif';
            } else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = $finfo ? finfo_file($finfo, $_FILES['proof']['tmp_name']) : '';
                if ($finfo) finfo_close($finfo);
                if ($mime === 'application/pdf') $ext = 'pdf';
            }
            if (!$ext) throw new Exception('Proof must be JPG, PNG, GIF, or PDF.');
            $dir = __DIR__ . '/uploads/refunds';
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $filename = 'refund_' . $user_id . '_' . time() . '.' . $ext;
            $dest = $dir . '/' . $filename;
            if (!move_uploaded_file($_FILES['proof']['tmp_name'], $dest)) {
                throw new Exception('Could not save proof file.');
            }
            $proof_path = '/uploads/refunds/' . $filename;
        }

        // Prevent duplicate open requests for the same item.
        $dup = $pdo->prepare("SELECT COUNT(*) FROM refunds WHERE user_id = ? AND status IN ('Pending','Approved','Refunded') AND ((order_id = ? AND ? > 0) OR (booking_id = ? AND ? > 0))");
        $dup->execute([$user_id, $order_id, $order_id, $booking_id, $booking_id]);
        if ((int)$dup->fetchColumn() > 0) {
            throw new Exception('A refund request for this item is already in progress.');
        }

        $pdo->prepare("INSERT INTO refunds (user_id, payment_id, order_id, booking_id, amount, reason, proof_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending')")
            ->execute([$user_id, $payment_id, $order_id ?: null, $booking_id ?: null, $amount, $reason, $proof_path]);

        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Fetch user data
$stmt = $pdo->prepare("SELECT skill_level, email FROM Users WHERE user_id = :id");
$stmt->execute(['id' => $user_id]);
$user_data = $stmt->fetch();
$skill_level = $user_data['skill_level'] ?? 'Beginner';
$email = $user_data['email'] ?? '';

// Fetch bookings (with created_at for timer display)
$booking_stmt = $pdo->prepare("
    SELECT b.booking_id, b.booking_reference, b.booking_date, b.start_time, b.end_time, b.status, b.total_price, b.player_count, b.created_at, c.name as court_name,
           p.status as payment_status
    FROM Bookings b
    JOIN Courts c ON b.court_id = c.court_id
    LEFT JOIN payments p ON b.payment_id = p.payment_id
    WHERE b.user_id = :id AND b.status != 'In Cart'
    ORDER BY b.booking_date DESC, b.start_time DESC
");
$booking_stmt->execute(['id' => $user_id]);
$all_bookings = $booking_stmt->fetchAll();

// Fetch orders
$order_stmt = $pdo->prepare("
    SELECT o.order_id, o.order_date, o.total_amount, o.status, p.status as payment_status
    FROM Orders o
    LEFT JOIN payments p ON o.payment_id = p.payment_id
    WHERE o.user_id = :id
    ORDER BY o.order_date DESC
");
$order_stmt->execute(['id' => $user_id]);
$all_orders = $order_stmt->fetchAll();

// Fetch this customer's refund requests (table added by database_admin_upgrade.sql)
$user_refunds = ['order' => [], 'booking' => []];
try {
    $rs = $pdo->prepare("SELECT order_id, booking_id, status, amount FROM refunds WHERE user_id = ? ORDER BY refund_id DESC");
    $rs->execute([$user_id]);
    foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!empty($r['order_id']))   $user_refunds['order'][(int)$r['order_id']]     = $r;
        if (!empty($r['booking_id'])) $user_refunds['booking'][(int)$r['booking_id']] = $r;
    }
} catch (PDOException $e) { $user_refunds = ['order' => [], 'booking' => []]; }

// Cancellation reasons for this customer's bookings (column added by database_admin_upgrade.sql)
$booking_cancel_reasons = [];
try {
    $cr = $pdo->prepare("SELECT booking_id, cancel_reason FROM Bookings WHERE user_id = ? AND cancel_reason IS NOT NULL AND cancel_reason <> ''");
    $cr->execute([$user_id]);
    foreach ($cr->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $booking_cancel_reasons[(int)$row['booking_id']] = $row['cancel_reason'];
    }
} catch (PDOException $e) { $booking_cancel_reasons = []; }

// Normalize order status into a badge class + label (keeps customer view
// consistent with the admin order workflow).
if (!function_exists('customer_order_badge')) {
    function customer_order_badge(string $status): array {
        switch ($status) {
            case 'Completed':
            case 'Delivered':       return ['bg-green-50 text-green-700 border border-green-200', 'bg-green-500', 'Completed'];
            case 'Processing':
            case 'Confirmed':       return ['bg-blue-50 text-blue-700 border border-blue-200', 'bg-blue-500', 'Processing'];
            case 'Pending':
            case 'Pending Payment': return ['bg-amber-50 text-amber-700 border border-amber-200', 'bg-amber-500', $status === 'Pending Payment' ? 'Awaiting Payment' : 'Pending'];
            case 'Cancelled':       return ['bg-red-50 text-red-700 border border-red-200', 'bg-red-500', 'Cancelled'];
            default:                return ['bg-amber-50 text-amber-700 border border-amber-200', 'bg-amber-500', ucfirst($status)];
        }
    }
}

// Stats
$confirmed_count = 0;
$pending_count = 0;
$total_hours = 0;
$total_spent = 0;
foreach ($all_bookings as $b) {
    if ($b['status'] === 'Confirmed') {
        $confirmed_count++;
        $sh = (int)date('H', strtotime($b['start_time']));
        $eh = (int)date('H', strtotime($b['end_time']));
        $total_hours += max(0, $eh - $sh);
        $total_spent += (float)$b['total_price'];
    } elseif ($b['status'] === 'Pending Payment') $pending_count++;
}

// Fetch AI analysis history (gracefully handle missing table)
$ai_analyses = [];
try {
    $ai_stmt = $pdo->prepare("SELECT log_id, stroke_type, accuracy_score, feedback, analyzed_at FROM AI_Analysis_Logs WHERE user_id = ? ORDER BY analyzed_at DESC LIMIT 20");
    $ai_stmt->execute([$user_id]);
    $ai_analyses = $ai_stmt->fetchAll();
} catch (Exception $e) {}
$ai_count = count($ai_analyses);
$ai_avg = 0;
if ($ai_count > 0) {
    $sum = 0;
    foreach ($ai_analyses as $a) $sum += (int)$a['accuracy_score'];
    $ai_avg = round($sum / $ai_count);
}

// Court availability right now (for widget)
$today_date = date('Y-m-d');
$current_hour_int = (int)date('H');
$availability = [];
try {
    $court_list = $pdo->query("SELECT court_id, name, status FROM Courts ORDER BY court_id ASC")->fetchAll();
    foreach ($court_list as $c) {
        $cstatus = $c['status'] ?? 'Available';
        if ($cstatus === 'Maintenance') { $availability[] = ['name'=>$c['name'],'status'=>'Maintenance','icon'=>'<span class="material-symbols-outlined text-gray-400 text-lg">construction</span>']; continue; }
        $cs = $pdo->prepare("SELECT COUNT(*) FROM Bookings WHERE court_id = ? AND booking_date = ? AND status IN ('Confirmed','Pending Payment') AND start_time <= ? AND end_time > ?");
        $time_now = date('H:i:s');
        $cs->execute([$c['court_id'], $today_date, $time_now, $time_now]);
        $is_booked = $cs->fetchColumn() > 0;
        $availability[] = ['name'=>$c['name'], 'status'=>$is_booked ? 'Occupied' : 'Available', 'icon'=>$is_booked ? '<span class="material-symbols-outlined text-accent text-lg">lock</span>' : '<span class="material-symbols-outlined text-green-500 text-lg">check_circle</span>'];
    }
} catch (Exception $e) {}

// Refundable items for the refund page selector (real data)
$refundable_items = [];
foreach ($all_bookings as $b) {
    if (in_array($b['status'], ['Cancelled', 'Expired'], true) && refund_payment_accepted($b['payment_status'] ?? null, $b['status'])) {
        $refundable_items[] = [
            'type' => 'booking',
            'id' => (int)$b['booking_id'],
            'label' => 'Booking #' . (int)$b['booking_id'] . ' — ' . htmlspecialchars($b['court_name']) . ' (' . date('M d, Y', strtotime($b['booking_date'])) . ')',
            'court' => htmlspecialchars($b['court_name']),
            'date' => date('M d, Y', strtotime($b['booking_date'])),
            'amount' => '₱' . number_format($b['total_price'], 2),
        ];
    }
}
foreach ($all_orders as $o) {
    if (in_array($o['status'], ['Completed', 'Cancelled', 'Confirmed', 'Delivered'], true) && refund_payment_accepted($o['payment_status'] ?? null, $o['status'])) {
        $refundable_items[] = [
            'type' => 'order',
            'id' => (int)$o['order_id'],
            'label' => 'Order #ORD-' . str_pad($o['order_id'], 5, '0', STR_PAD_LEFT) . ' — ₱' . number_format($o['total_amount'], 2),
            'court' => 'Shop Order',
            'date' => date('M d, Y', strtotime($o['order_date'])),
            'amount' => '₱' . number_format($o['total_amount'], 2),
        ];
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>My Dashboard | HI-POWER</title>
    <?php include __DIR__ . '/includes/tailwind_config.php'; ?>
    <style>
        @keyframes fadeSlideUp{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:translateY(0);}}
        .anim-in{animation:fadeSlideUp .5s cubic-bezier(.22,1,.36,1) forwards;}
        .anim-d1{animation-delay:.05s;opacity:0;}
        .anim-d2{animation-delay:.1s;opacity:0;}
        .anim-d3{animation-delay:.15s;opacity:0;}
        .sidebar-btn{transition:all .2s ease;border-left:3px solid transparent;}
        .sidebar-btn.active{background:rgba(255,87,34,0.08);color:#ff5722;border-left-color:#ff5722;font-weight:700;}
        .sidebar-btn.active .material-symbols-outlined{font-variation-settings:'FILL' 1;}
        .tab-card{background:#fff;border-radius:20px;border:1px solid rgba(0,0,0,0.06);overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.04);}
        .booking-row{transition:all .15s ease;border-bottom:1px solid #f0f1f5;}
        .booking-row:last-child{border-bottom:none;}
        .booking-row:hover{background:#f8f9fc;}
        .booking-row:hover .booking-actions{opacity:1;}
        .booking-actions{opacity:0;transition:opacity .2s;}
        @keyframes modalIn{from{opacity:0;transform:scale(0.95) translateY(10px);}to{opacity:1;transform:scale(1) translateY(0);}}
        .modal-animate{animation:modalIn .3s cubic-bezier(.22,1,.36,1) forwards;}
        .skill-chip{padding:10px 16px;border-radius:14px;border:2px solid #e8eaf0;cursor:pointer;transition:all .2s;text-align:center;font-weight:600;font-size:13px;}
        .skill-chip:hover{border-color:#ff5722;background:rgba(255,87,34,0.04);}
        .skill-chip.selected{border-color:#ff5722;background:rgba(255,87,34,0.08);color:#ff5722;}
        /* Scoped mockup styles */
        .ssd { --navy:#0b1521; --navy-2:#111e2c; --orange:#ff4b17; --orange-dark:#d9360c; --ink:#142542; --muted:#70819b; --bg:#f3f7fb; --line:#e2e9f2; --green:#15975b; }
        .ssd * { box-sizing: border-box; }
        .ssd .layout { display: grid; grid-template-columns: 334px minmax(0,1fr); min-height: calc(100vh - 74px); }
        .ssd .sidebar { background: linear-gradient(160deg, #0d1926, #101e2d); color: #d8e4f5; padding: 24px 22px; display: flex; flex-direction: column; gap: 17px; }
        .ssd .profile { display: flex; align-items: center; gap: 16px; border: 1px solid #29384a; border-radius: 17px; padding: 13px; }
        .ssd .avatar { width: 68px; height: 68px; flex-shrink: 0; border-radius: 21px; display: grid; place-items: center; color: white; font-size: 34px; font-weight: 750; background: var(--orange); box-shadow: 0 8px 20px #ff4b172b; }
        .ssd .profile strong { display: block; color: white; font-size: 16px; }
        .ssd .profile small { display: block; color: #9db8d9; font-size: 12px; margin-top: 6px; letter-spacing: .6px; }
        .ssd .stats { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; background: transparent; border: 0; }
.ssd .stat::after { display: none; }
        .ssd .stat { text-align: center; border: 1px solid #29384a; background: #111e2b; border-radius: 15px; padding: 14px 8px 12px; }
        .ssd .stat .symbol { display: block; color: var(--orange); font-size: 23px; font-weight: 750; margin-bottom: 5px; }
        .ssd .stat strong { display: block; color: white; font-size: 19px; }
        .ssd .stat small { display: block; color: #a9bfdc; font-size: 10px; letter-spacing: .4px; margin-top: 4px; }
        .ssd .side-menu { border-top: 1px solid #253448; padding-top: 15px; margin-top: 7px; display: grid; gap: 7px; }
        .ssd .side-menu a, .ssd .logout { display: flex; align-items: center; gap: 15px; padding: 13px 16px; border-radius: 13px; color: #b8cce7; text-decoration: none; font-size: 15px; transition: .2s; }
        .ssd .side-menu a span, .ssd .logout span { font-size: 21px; width: 22px; text-align: center; }
        .ssd .side-menu a:hover { background: #1b2a3c; color: white; }
        .ssd .side-menu a.active { color: white; font-weight: 700; background: linear-gradient(100deg, var(--orange), #c83210); box-shadow: 0 8px 18px #ff4b1726; }
        .ssd .logout { margin-top: auto; border-top: 1px solid #253448; border-radius: 0; padding-top: 23px; color: var(--orange); }
        .ssd main { min-width: 0; padding: 28px clamp(20px, 3vw, 40px) 36px; background: radial-gradient(ellipse at 80% 0%, #e4eff7 0, transparent 33%), var(--bg); }
        .ssd .page-heading { display: flex; justify-content: space-between; align-items: center; gap: 20px; margin-bottom: 24px; }
        .ssd .eyebrow { color: var(--orange); letter-spacing: 3px; font-size: 11px; font-weight: 800; margin-bottom: 8px; }
        .ssd h1 { margin: 0; font-size: clamp(28px, 3vw, 42px); letter-spacing: -1.2px; color: var(--ink); }
        .ssd .primary-btn { border: 0; border-radius: 13px; background: linear-gradient(100deg, var(--orange), #df3b0d); color: white; padding: 15px 23px; font-weight: 750; box-shadow: 0 8px 17px #ff4b172b; transition: transform .2s, box-shadow .2s; cursor: pointer; }
        .ssd .primary-btn:hover { transform: translateY(-2px); box-shadow: 0 12px 22px #ff4b173a; }
        .ssd .panel { background: #fff; border: 1px solid #edf1f6; border-radius: 22px; padding: 24px 27px; margin-bottom: 25px; box-shadow: 0 8px 28px #1a34520a; }
        .ssd .panel-title { display: flex; align-items: flex-start; gap: 14px; margin-bottom: 26px; }
        .ssd .panel-icon { color: var(--orange); font-size: 31px; }
        .ssd .panel-title h2 { font-size: 17px; margin: 2px 0 5px; color: var(--ink); }
        .ssd .panel-title p { margin: 0; color: var(--muted); font-size: 14px; }
        .ssd .text-link { margin-left: auto; color: var(--orange); font-weight: 750; text-decoration: none; font-size: 12px; white-space: nowrap; }
        .ssd .court-list { display: flex; flex-wrap: wrap; gap: 14px; }
        .ssd .court { border: 1px solid #8de8b8; color: #087546; background: #edfcf4; padding: 14px 16px; border-radius: 13px; font-weight: 650; font-size: 14px; }
        .ssd .court:before { content: "✓"; display: inline-grid; place-items: center; width: 21px; height: 21px; border-radius: 50%; background: #35b77b; color: white; margin-right: 10px; font-weight: 900; }
        .ssd .court.unavailable { border-color: #dce4ed; color: #8190a5; background: #eff3f7; }
        .ssd .court.unavailable:before { content: "🔧"; background: transparent; }
        .ssd .empty-state { min-height: 275px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; position: relative; overflow: hidden; background: linear-gradient(180deg, #fff 55%, #fff8f4); }
        .ssd .empty-state:before, .ssd .empty-state:after { content: ""; position: absolute; bottom: -65px; width: 230px; height: 150px; background: #ffede5; opacity: .55; transform: rotate(28deg); border-radius: 50%; }
        .ssd .empty-state:before { left: -80px; }
        .ssd .empty-state:after { right: -90px; }
        .ssd .empty-icon { font-size: 53px; color: #9aaec7; margin-bottom: 13px; z-index: 1; }
        .ssd .empty-state h2 { margin: 0 0 8px; font-size: 21px; z-index: 1; color: var(--ink); }
        .ssd .empty-state p { margin: 0 0 24px; color: var(--muted); z-index: 1; }
        .ssd .empty-state .primary-btn { z-index: 1; }
        .ssd .quick-links { display: grid; grid-template-columns: 1fr 1.1fr; gap: 17px; }
        .ssd .quick-card { border: 1px solid var(--line); background: white; border-radius: 17px; padding: 17px 20px; display: flex; align-items: center; gap: 16px; text-align: left; width: 100%; color: inherit; box-shadow: 0 5px 20px #1a34520a; cursor: pointer; }
        .ssd .quick-card.refund { border-color: #ffd3bf; background: linear-gradient(110deg, #fff9f6, #fff); }
        .ssd .quick-icon { display: grid; place-items: center; width: 67px; height: 67px; flex-shrink: 0; border-radius: 50%; background: #eaf1ff; color: #3178f6; font-size: 29px; }
        .ssd .refund .quick-icon { color: var(--orange); background: #ffede4; }
        .ssd .quick-copy { flex: 1; }
        .ssd .quick-copy strong { display: block; margin-bottom: 5px; font-size: 15px; color: var(--ink); }
        .ssd .quick-copy small { color: var(--muted); line-height: 1.5; font-size: 13px; }
        .ssd .chevron { color: #8194ad; font-size: 26px; }
        .ssd .refund .chevron { color: var(--orange); }
        .ssd .back-btn { border: 0; background: transparent; color: var(--muted); padding: 0 0 18px; font-weight: 650; cursor: pointer; }
        .ssd .refund-layout { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(250px, .8fr); gap: 22px; align-items: start; }
        .ssd .form-panel h2, .ssd .history-panel h2 { margin: 0 0 7px; font-size: 21px; color: var(--ink); }
        .ssd .subtext { color: var(--muted); margin: 0 0 24px; font-size: 14px; line-height: 1.5; }
        .ssd .field { display: grid; gap: 8px; margin-bottom: 18px; }
        .ssd .field label { font-size: 13px; font-weight: 750; color: var(--ink); }
        .ssd .field input, .ssd .field select, .ssd .field textarea { width: 100%; border: 1px solid #dce4ee; border-radius: 11px; background: #fbfcfe; color: var(--ink); padding: 13px 14px; outline: none; }
        .ssd .field input:focus, .ssd .field select:focus, .ssd .field textarea:focus { border-color: var(--orange); box-shadow: 0 0 0 3px #ff4b1717; }
        .ssd .field textarea { min-height: 95px; resize: vertical; }
        .ssd .booking-summary { border: 1px solid var(--line); border-radius: 14px; padding: 17px; margin-bottom: 21px; background: #fbfcfe; }
        .ssd .summary-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 17px; }
        .ssd .summary-head strong { font-size: 15px; color: var(--ink); }
        .ssd .status { display: inline-flex; align-items: center; border-radius: 100px; background: #fff2d9; color: #9b6500; padding: 6px 10px; font-size: 11px; font-weight: 800; white-space: nowrap; }
        .ssd .status.pending { background: #fff2d9; color: #9b6500; }
        .ssd .summary-row { display: flex; justify-content: space-between; gap: 16px; padding: 9px 0; font-size: 13px; }
        .ssd .summary-row span:first-child { color: var(--muted); }
        .ssd .summary-row strong { text-align: right; color: var(--ink); }
        .ssd .policy { display: flex; gap: 11px; align-items: flex-start; background: #fff7e9; border: 1px solid #ffe6b5; border-radius: 12px; padding: 14px; margin: 6px 0 19px; color: #835a13; font-size: 13px; line-height: 1.5; }
        .ssd .policy input { margin-top: 3px; accent-color: var(--orange); }
        .ssd .policy strong { display: block; margin-bottom: 3px; }
        .ssd .form-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; }
        .ssd .secondary-btn { border: 1px solid #dce4ee; color: #43546b; background: white; border-radius: 12px; padding: 14px 19px; font-weight: 700; cursor: pointer; }
        .ssd .notice-box { border-radius: 13px; padding: 15px; background: #f5f8fc; color: #60728a; font-size: 13px; line-height: 1.6; margin-top: 18px; }
        .ssd .history-item { border: 1px solid var(--line); border-radius: 14px; padding: 16px; margin-top: 14px; }
        .ssd .history-item p { margin: 7px 0 13px; color: var(--muted); font-size: 13px; }
        .ssd .history-item .row { display: flex; justify-content: space-between; gap: 10px; align-items: center; }
        .ssd .empty-history { border: 1px dashed #d7e0eb; border-radius: 14px; padding: 22px 15px; color: var(--muted); text-align: center; font-size: 13px; margin-top: 15px; }
        .ssd .success-banner { display: none; border: 1px solid #a6e8c2; background: #effcf4; color: #116c40; border-radius: 12px; padding: 13px 15px; margin-bottom: 18px; font-size: 14px; line-height: 1.5; }
        .ssd .success-banner.show { display: block; }
        .ssd .hidden { display: none !important; }
        @media (max-width: 1000px) {
            .ssd .layout { grid-template-columns: 270px minmax(0,1fr); }
            .ssd .quick-links, .ssd .refund-layout { grid-template-columns: 1fr; }
        }
        @media (max-width: 720px) {
            .ssd .layout { display: block; }
            .ssd .stats { grid-template-columns: repeat(4,1fr); gap: 6px; background: transparent; border: 0; }
            .ssd .stat { padding: 10px 4px; }
            .ssd .stat small { font-size: 8px; }
            .ssd .side-menu { grid-template-columns: repeat(3,1fr); gap: 5px; }
            .ssd .side-menu a { justify-content: center; flex-direction: column; gap: 4px; padding: 9px 4px; font-size: 11px; text-align: center; }
            .ssd .logout { display: none; }
            .ssd main { padding: 20px 13px; }
            .ssd .page-heading { align-items: flex-start; }
            .ssd .page-heading .primary-btn { padding: 12px; font-size: 12px; }
            .ssd .panel { padding: 18px 15px; border-radius: 17px; }
            .ssd .court-list { gap: 8px; }
            .ssd .court { font-size: 12px; padding: 11px 10px; }
            .ssd .court:before { margin-right: 6px; }
            .ssd .quick-card { padding: 13px; }
            .ssd .quick-icon { width: 52px; height: 52px; }
        }
    </style>
</head>
<body class="bg-surface min-h-screen antialiased">

<?php include __DIR__ . '/includes/nav_bar.php'; ?>

<div class="ssd">
    <div class="layout">
        <!-- Desktop Sidebar (mockup style) -->
        <aside class="sidebar">
            <div class="profile">
                <div class="avatar"><?php echo strtoupper(substr($first_name, 0, 1)); ?></div>
                <div>
                    <strong><?php echo htmlspecialchars($full_name); ?></strong>
                    <small id="sidebarSkill"><?php echo htmlspecialchars(strtoupper($skill_level)); ?></small>
                </div>
            </div>
            <div class="stats">
                <div class="stat"><span class="symbol material-symbols-outlined">schedule</span><strong><?php echo $total_hours; ?>h</strong><small>HOURS PLAYED</small></div>
                <div class="stat"><span class="symbol material-symbols-outlined">payments</span><strong>₱<?php echo number_format($total_spent, 0); ?></strong><small>TOTAL SPENT</small></div>
                <div class="stat"><span class="symbol material-symbols-outlined">event</span><strong><?php echo $confirmed_count; ?></strong><small>BOOKINGS</small></div>
                <div class="stat"><span class="symbol material-symbols-outlined">analytics</span><strong><?php echo $ai_count > 0 ? $ai_avg . '%' : '—'; ?></strong><small>AI AVG SCORE</small></div>
            </div>
            <nav class="side-menu" aria-label="Account navigation">
                <a href="#" class="active" id="bookingsNav" onclick="showDashboard(); switchTab('bookings'); return false;"><span class="material-symbols-outlined">calendar_month</span>My Bookings</a>
                <a href="#" id="ordersNav" onclick="showDashboard(); switchTab('orders'); return false;"><span class="material-symbols-outlined">receipt_long</span>My Orders</a>
                <a href="#" id="aiNav" onclick="showDashboard(); switchTab('ai'); return false;"><span class="material-symbols-outlined">smart_toy</span>AI Coach</a>
                <a href="#" id="tipsNav" onclick="showDashboard(); switchTab('tips'); return false;"><span class="material-symbols-outlined">menu_book</span>Tips &amp; Guide</a>
                <a href="#" id="supportNav" onclick="showDashboard(); switchTab('support'); return false;"><span class="material-symbols-outlined">support_agent</span>Support</a>
                <a href="#" id="settingsNav" onclick="showDashboard(); switchTab('settings'); return false;"><span class="material-symbols-outlined">settings</span>Settings</a>
            </nav>
            <a class="logout" href="logout"><span class="material-symbols-outlined">logout</span>Log Out</a>
        </aside>

        <main>
            <!-- DASHBOARD PAGE -->
            <section id="dashboardPage">
                <!-- BOOKINGS TAB -->
                <div id="panel-bookings" class="db-panel block">
                    <div class="flex justify-between items-end mb-6 anim-in anim-d1">
                        <div>
                            <span class="text-accent text-[11px] font-bold tracking-[0.25em] uppercase mb-1 block">Reservations</span>
                            <h1 class="text-2xl md:text-3xl font-bold">My Bookings</h1>
                        </div>
                        <a href="courtbooking" class="bg-gradient-to-r from-accent to-accent-dark text-white px-5 py-2.5 rounded-xl font-bold text-sm flex items-center gap-2 shadow-lg shadow-accent/20 hover:shadow-xl transition active:scale-95">
                            <span class="material-symbols-outlined text-[18px]">add</span> Book Court
                        </a>
                    </div>

                    <?php if (!empty($availability)): ?>
                    <div class="bg-white rounded-2xl border border-outline-variant/20 p-5 mb-6 shadow-sm anim-in anim-d1">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="font-bold text-sm flex items-center gap-2">
                                <span class="material-symbols-outlined text-primary text-lg">stadium</span> Court Availability — Now
                            </h3>
                            <a href="courtbooking" class="text-primary text-[11px] font-bold uppercase tracking-wider hover:underline">Full Schedule →</a>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($availability as $av):
                                $av_cls = $av['status'] === 'Available' ? 'bg-green-50 text-green-700 border-green-200' : ($av['status'] === 'Maintenance' ? 'bg-gray-100 text-gray-500 border-gray-200' : 'bg-red-50 text-accent border-red-200');
                            ?>
                            <div class="flex items-center gap-2 px-3 py-2 rounded-xl border text-xs font-bold <?php echo $av_cls; ?>">
                                <?php echo $av['icon']; ?> <?php echo htmlspecialchars($av['name']); ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (count($all_bookings) > 0): ?>
                    <div class="tab-card hidden md:block anim-in anim-d2">
                        <?php foreach ($all_bookings as $i => $booking):
                            $bookDate = date("M d, Y", strtotime($booking['booking_date']));
                            $timeStr = date("h:i A", strtotime($booking['start_time'])) . ' – ' . date("h:i A", strtotime($booking['end_time']));
                            $isPending = $booking['status'] === 'Pending Payment';
                            $isConfirmed = $booking['status'] === 'Confirmed';
                            $isCancelled = $booking['status'] === 'Cancelled' || $booking['status'] === 'Expired';
                            if ($isConfirmed) { $sb = 'bg-green-50 text-green-700 border border-green-200'; $dot = 'bg-green-500'; }
                            elseif ($isPending) { $sb = 'bg-amber-50 text-amber-700 border border-amber-200'; $dot = 'bg-amber-500'; }
                            elseif ($isCancelled) { $sb = 'bg-gray-100 text-gray-500 border border-gray-200'; $dot = 'bg-gray-400'; }
                            else { $sb = 'bg-blue-50 text-blue-700 border border-blue-200'; $dot = 'bg-blue-500'; }
                            $canCancel = false;
                            if (in_array($booking['status'], ['Pending', 'Pending Payment'], true)) {
                                $canCancel = true;
                            } elseif ($booking['status'] === 'Confirmed') {
                                $canCancel = strtotime($booking['booking_date'] . ' ' . $booking['start_time']) > time() + (2 * 3600);
                            }
                        ?>
                        <div class="booking-row flex items-center gap-4 px-6 py-4">
                            <div class="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-primary text-lg">stadium</span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <p class="font-bold text-sm text-on-surface truncate"><?php echo htmlspecialchars($booking['court_name']); ?></p>
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider <?php echo $sb; ?>">
                                        <?php echo htmlspecialchars($booking['status']); ?>
                                    </span>
                                </div>
                                <p class="text-xs text-on-surface-variant mt-0.5"><?php echo $bookDate; ?> • <?php echo $timeStr; ?> • ₱<?php echo number_format($booking['total_price'], 2); ?></p>
                                <?php if ($isCancelled && !empty($booking_cancel_reasons[(int)$booking['booking_id']])): ?>
                                    <p class="text-[11px] text-accent mt-0.5"><span class="material-symbols-outlined text-[13px] align-middle mr-0.5">info</span>Cancelled — <?php echo htmlspecialchars($booking_cancel_reasons[(int)$booking['booking_id']]); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="booking-actions flex items-center gap-2">
                                <?php if ($isPending): ?>
                                    <a href="book_and_pay?id=<?php echo $booking['booking_id']; ?>" class="px-3 py-1.5 bg-primary text-white rounded-lg text-[11px] font-bold uppercase tracking-wider hover:shadow-md transition">Pay Now</a>
                                <?php endif; ?>
                                <?php if ($canCancel): ?>
                                    <button onclick="cancelBooking(<?php echo $booking['booking_id']; ?>)" class="px-3 py-1.5 text-accent hover:bg-red-50 rounded-lg text-[11px] font-bold uppercase tracking-wider transition">Cancel</button>
                                <?php endif; ?>
                                <?php
                                    $bid = (int)$booking['booking_id'];
                                    $brefund = $user_refunds['booking'][$bid] ?? null;
                                    $refundEligible = $isCancelled && refund_payment_accepted($booking['payment_status'] ?? null, $booking['status']);
                                ?>
                                <?php if ($brefund): ?>
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-purple-50 text-purple-700 border border-purple-200">Refund: <?php echo htmlspecialchars($brefund['status']); ?></span>
                                <?php elseif ($refundEligible): ?>
                                    <button onclick='openRefundModal({booking_id: <?php echo $bid; ?>})' class="px-3 py-1.5 text-primary hover:bg-primary/5 rounded-lg text-[11px] font-bold uppercase tracking-wider transition">Request Refund</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="md:hidden space-y-3 anim-in anim-d2">
                        <?php foreach ($all_bookings as $booking):
                            $bookDate = date("D, M d, Y", strtotime($booking['booking_date']));
                            $timeStr = date("h:i A", strtotime($booking['start_time'])) . ' – ' . date("h:i A", strtotime($booking['end_time']));
                            $isPending = $booking['status'] === 'Pending Payment';
                            $isConfirmed = $booking['status'] === 'Confirmed';
                            $isCancelled = $booking['status'] === 'Cancelled' || $booking['status'] === 'Expired';
                            if ($isConfirmed) { $sb = 'bg-green-50 text-green-700 border border-green-200'; }
                            elseif ($isPending) { $sb = 'bg-amber-50 text-amber-700 border border-amber-200'; }
                            elseif ($isCancelled) { $sb = 'bg-gray-100 text-gray-500 border border-gray-200'; }
                            else { $sb = 'bg-blue-50 text-blue-700 border border-blue-200'; }
                            $canCancel = false;
                            if (in_array($booking['status'], ['Pending', 'Pending Payment'], true)) {
                                $canCancel = true;
                            } elseif ($booking['status'] === 'Confirmed') {
                                $canCancel = strtotime($booking['booking_date'] . ' ' . $booking['start_time']) > time() + (2 * 3600);
                            }
                        ?>
                        <div class="bg-white rounded-2xl border border-outline-variant/20 p-4 shadow-sm">
                            <div class="flex items-start justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center">
                                        <span class="material-symbols-outlined text-primary text-lg">stadium</span>
                                    </div>
                                    <div>
                                        <p class="font-bold text-sm"><?php echo htmlspecialchars($booking['court_name']); ?></p>
                                        <p class="text-[11px] text-on-surface-variant"><?php echo $bookDate; ?></p>
                                    </div>
                                </div>
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?php echo $sb; ?>"><?php echo htmlspecialchars($booking['status']); ?></span>
                            </div>
                            <div class="flex items-center justify-between mt-3 pt-3 border-t border-outline-variant/10">
                                <div class="text-xs text-on-surface-variant">
                                    <span class="font-semibold"><?php echo $timeStr; ?></span> • ₱<?php echo number_format($booking['total_price'], 2); ?>
                                </div>
                                <div class="flex gap-2">
                                    <?php if ($isPending): ?>
                                        <a href="book_and_pay?id=<?php echo $booking['booking_id']; ?>" class="px-3 py-1 bg-primary text-white rounded-lg text-[11px] font-bold">Pay Now</a>
                                    <?php endif; ?>
                                    <?php if ($canCancel): ?>
                                        <button onclick="cancelBooking(<?php echo $booking['booking_id']; ?>)" class="px-3 py-1 text-accent hover:bg-red-50 rounded-lg text-[11px] font-bold">Cancel</button>
                                    <?php endif; ?>
                                    <?php
                                        $bid = (int)$booking['booking_id'];
                                        $brefund = $user_refunds['booking'][$bid] ?? null;
                                        $refundEligible = $isCancelled && refund_payment_accepted($booking['payment_status'] ?? null, $booking['status']);
                                    ?>
                                    <?php if ($brefund): ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-purple-50 text-purple-700 border border-purple-200">Refund: <?php echo htmlspecialchars($brefund['status']); ?></span>
                                    <?php elseif ($refundEligible): ?>
                                        <button onclick='openRefundModal({booking_id: <?php echo $bid; ?>})' class="px-3 py-1 text-primary rounded-lg text-[11px] font-bold">Refund</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="tab-card p-12 text-center anim-in anim-d2">
                        <span class="material-symbols-outlined text-outline-variant text-5xl mb-3 block">event_busy</span>
                        <p class="text-on-surface-variant mb-4 font-medium">No bookings yet</p>
                        <a href="courtbooking" class="inline-flex items-center gap-2 px-6 py-3 bg-primary text-white rounded-xl font-bold text-sm shadow-lg shadow-primary/20 hover:shadow-xl transition">
                            <span class="material-symbols-outlined text-[18px]">add</span> Book Your First Court
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- Quick Links -->
                    <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-3 anim-in anim-d3">
                        <button class="bg-white rounded-2xl border border-outline-variant/20 p-5 shadow-sm hover:shadow-md transition-all group text-center" onclick="switchTab('tips')">
                            <span class="material-symbols-outlined text-primary text-3xl mb-2 block group-hover:scale-110 transition-transform">school</span>
                            <p class="font-bold text-sm">Tips &amp; Guide</p>
                            <p class="text-[10px] text-on-surface-variant mt-1">Learn how to book, play and improve</p>
                        </button>
                        <button class="bg-white rounded-2xl border border-outline-variant/20 p-5 shadow-sm hover:shadow-md transition-all group text-center" onclick="showRefund()">
                            <span class="material-symbols-outlined text-accent text-3xl mb-2 block group-hover:scale-110 transition-transform">currency_exchange</span>
                            <p class="font-bold text-sm">Refund</p>
                            <p class="text-[10px] text-on-surface-variant mt-1">Need to cancel? Request a refund here.</p>
                        </button>
                    </div>
                </div>

                <!-- ORDERS TAB -->
                <div id="panel-orders" class="db-panel hidden">
                    <div class="flex justify-between items-end mb-6 anim-in anim-d1">
                        <div>
                            <span class="text-accent text-[11px] font-bold tracking-[0.25em] uppercase mb-1 block">Pro Shop</span>
                            <h1 class="text-2xl md:text-3xl font-bold">My Orders</h1>
                        </div>
                        <a href="ecommerce" class="bg-gradient-to-r from-primary to-primary-dark text-white px-5 py-2.5 rounded-xl font-bold text-sm flex items-center gap-2 shadow-lg shadow-primary/20 hover:shadow-xl transition active:scale-95">
                            <span class="material-symbols-outlined text-[18px]">shopping_bag</span> Shop Gear
                        </a>
                    </div>

                    <?php if (count($all_orders) > 0): ?>
                    <div class="tab-card hidden md:block anim-in anim-d2">
                        <?php foreach ($all_orders as $order):
                            $orderDate = date("M d, Y", strtotime($order['order_date']));
                            [$sb, $dot, $orderLabel] = customer_order_badge($order['status']);
                            $oid = (int)$order['order_id'];
                            $refund = $user_refunds['order'][$oid] ?? null;
                            $refundEligible = in_array($order['status'], ['Completed', 'Cancelled', 'Confirmed', 'Delivered'], true)
                                && refund_payment_accepted($order['payment_status'] ?? null, $order['status']);
                        ?>
                        <div class="booking-row flex items-center gap-4 px-6 py-4">
                            <div class="w-10 h-10 rounded-xl bg-surface flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-on-surface-variant text-lg">receipt_long</span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="font-bold text-sm text-on-surface">#ORD-<?php echo str_pad($order['order_id'], 5, '0', STR_PAD_LEFT); ?></p>
                                <p class="text-xs text-on-surface-variant mt-0.5"><?php echo $orderDate; ?></p>
                            </div>
                            <div class="shrink-0">
                                <?php if ($refund): ?>
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-purple-50 text-purple-700 border border-purple-200">Refund: <?php echo htmlspecialchars($refund['status']); ?></span>
                                <?php elseif ($refundEligible): ?>
                                    <button onclick='openRefundModal({order_id: <?php echo $oid; ?>})' class="text-[11px] font-bold text-primary hover:underline">Request Refund</button>
                                <?php endif; ?>
                            </div>
                            <div class="text-right shrink-0">
                                <p class="font-bold text-sm">₱<?php echo number_format($order['total_amount'], 2); ?></p>
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider <?php echo $sb; ?>">
                                    <span class="w-1.5 h-1.5 rounded-full <?php echo $dot; ?>"></span>
                                    <?php echo htmlspecialchars($orderLabel); ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="md:hidden space-y-3 anim-in anim-d2">
                        <?php foreach ($all_orders as $order):
                            $orderDate = date("M d, Y", strtotime($order['order_date']));
                            [$sb, $dot, $orderLabel] = customer_order_badge($order['status']);
                            $oid = (int)$order['order_id'];
                            $refund = $user_refunds['order'][$oid] ?? null;
                            $refundEligible = in_array($order['status'], ['Completed', 'Cancelled', 'Confirmed', 'Delivered'], true)
                                && refund_payment_accepted($order['payment_status'] ?? null, $order['status']);
                        ?>
                        <div class="bg-white rounded-2xl border border-outline-variant/20 p-4 shadow-sm flex items-center gap-4">
                            <div class="w-10 h-10 rounded-xl bg-surface flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-on-surface-variant">receipt_long</span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="font-bold text-sm">#ORD-<?php echo str_pad($order['order_id'], 5, '0', STR_PAD_LEFT); ?></p>
                                <p class="text-[11px] text-on-surface-variant"><?php echo $orderDate; ?></p>
                                <?php if ($refund): ?>
                                    <span class="inline-block mt-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-purple-50 text-purple-700 border border-purple-200">Refund: <?php echo htmlspecialchars($refund['status']); ?></span>
                                <?php elseif ($refundEligible): ?>
                                    <button onclick='openRefundModal({order_id: <?php echo $oid; ?>})' class="block mt-1 text-[11px] font-bold text-primary">Request Refund</button>
                                <?php endif; ?>
                            </div>
                            <div class="text-right">
                                <p class="font-bold text-sm">₱<?php echo number_format($order['total_amount'], 2); ?></p>
                                <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold uppercase <?php echo $sb; ?>"><?php echo htmlspecialchars($orderLabel); ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="tab-card p-12 text-center anim-in anim-d2">
                        <span class="material-symbols-outlined text-outline-variant text-5xl mb-3 block">inventory_2</span>
                        <p class="text-on-surface-variant mb-4 font-medium">No orders yet</p>
                        <a href="ecommerce" class="inline-flex items-center gap-2 px-6 py-3 bg-primary text-white rounded-xl font-bold text-sm shadow-lg shadow-primary/20 hover:shadow-xl transition">
                            <span class="material-symbols-outlined text-[18px]">shopping_bag</span> Browse the Shop
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- AI COACH TAB -->
                <div id="panel-ai" class="db-panel hidden">
                    <div class="flex justify-between items-end mb-6 anim-in anim-d1">
                        <div>
                            <span class="text-accent text-[11px] font-bold tracking-[0.25em] uppercase mb-1 block">Biomechanics</span>
                            <h1 class="text-2xl md:text-3xl font-bold">AI Coach</h1>
                        </div>
                        <a href="aimovementanalysis" class="bg-gradient-to-r from-primary to-primary-dark text-white px-5 py-2.5 rounded-xl font-bold text-sm flex items-center gap-2 shadow-lg shadow-primary/20 hover:shadow-xl transition active:scale-95">
                            <span class="material-symbols-outlined text-[18px]">add_a_photo</span> New Analysis
                        </a>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6 anim-in anim-d1">
                        <div class="bg-white rounded-2xl border border-outline-variant/20 p-4 text-center shadow-sm">
                            <p class="text-2xl font-bold text-primary"><?php echo $ai_count; ?></p>
                            <p class="text-[10px] font-bold text-on-surface-variant uppercase tracking-wider mt-1">Total Scans</p>
                        </div>
                        <div class="bg-white rounded-2xl border border-outline-variant/20 p-4 text-center shadow-sm">
                            <p class="text-2xl font-bold <?php echo $ai_avg >= 70 ? 'text-green-600' : ($ai_avg >= 50 ? 'text-amber-600' : 'text-accent'); ?>"><?php echo $ai_count > 0 ? $ai_avg.'%' : '—'; ?></p>
                            <p class="text-[10px] font-bold text-on-surface-variant uppercase tracking-wider mt-1">Avg Accuracy</p>
                        </div>
                        <?php
                        $best_score = 0; $best_stroke = '—';
                        foreach ($ai_analyses as $a) { if ((int)$a['accuracy_score'] > $best_score) { $best_score = (int)$a['accuracy_score']; $best_stroke = $a['stroke_type']; } }
                        ?>
                        <div class="bg-white rounded-2xl border border-outline-variant/20 p-4 text-center shadow-sm">
                            <p class="text-2xl font-bold text-primary"><?php echo $best_score > 0 ? $best_score.'%' : '—'; ?></p>
                            <p class="text-[10px] font-bold text-on-surface-variant uppercase tracking-wider mt-1">Best Score</p>
                        </div>
                        <div class="bg-white rounded-2xl border border-outline-variant/20 p-4 text-center shadow-sm">
                            <p class="text-lg font-bold text-on-surface"><?php echo $best_stroke; ?></p>
                            <p class="text-[10px] font-bold text-on-surface-variant uppercase tracking-wider mt-1">Top Stroke</p>
                        </div>
                    </div>

                    <?php if ($ai_count > 0): ?>
                    <div class="tab-card anim-in anim-d2">
                        <div class="px-6 py-4 border-b border-outline-variant/10 flex items-center justify-between">
                            <h3 class="font-bold text-sm">Recent Analyses</h3>
                            <a href="aimovementanalysis" class="text-primary text-[11px] font-bold uppercase tracking-wider hover:underline">Analyze New →</a>
                        </div>
                        <?php foreach ($ai_analyses as $i => $a):
                            $acc = (int)$a['accuracy_score'];
                            $color = $acc >= 75 ? 'text-green-600 bg-green-50 border-green-200' : ($acc >= 50 ? 'text-amber-600 bg-amber-50 border-amber-200' : 'text-accent bg-red-50 border-red-200');
                            $date = $a['analyzed_at'] ? date('M d, Y h:i A', strtotime($a['analyzed_at'])) : '—';
                        ?>
                        <div class="booking-row flex items-center gap-4 px-6 py-4">
                            <div class="w-10 h-10 rounded-xl bg-primary/10 flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-primary text-lg">smart_toy</span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="font-bold text-sm text-on-surface"><?php echo htmlspecialchars($a['stroke_type']); ?></p>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold border <?php echo $color; ?>"><?php echo $acc; ?>%</span>
                                </div>
                                <p class="text-xs text-on-surface-variant mt-0.5 truncate"><?php echo htmlspecialchars(mb_strimwidth($a['feedback'] ?? '', 0, 80, '...')); ?></p>
                            </div>
                            <span class="text-[11px] text-on-surface-variant/60 font-medium shrink-0 hidden sm:block"><?php echo $date; ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="tab-card p-12 text-center anim-in anim-d2">
                        <span class="material-symbols-outlined text-outline-variant text-5xl mb-3 block">smart_toy</span>
                        <p class="text-on-surface-variant mb-2 font-medium">No AI analyses yet</p>
                        <p class="text-xs text-on-surface-variant/60 mb-4">Upload a badminton photo to get personalized biomechanical feedback</p>
                        <a href="aimovementanalysis" class="inline-flex items-center gap-2 px-6 py-3 bg-primary text-white rounded-xl font-bold text-sm shadow-lg shadow-primary/20 hover:shadow-xl transition">
                            <span class="material-symbols-outlined text-[18px]">add_a_photo</span> Start Analyzing
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- TIPS & GUIDE TAB -->
                <div id="panel-tips" class="db-panel hidden">
                    <div class="mb-6 anim-in anim-d1">
                        <span class="text-accent text-[11px] font-bold tracking-[0.25em] uppercase mb-1 block">Learn & Improve</span>
                        <h1 class="text-2xl md:text-3xl font-bold">Tips & Guide</h1>
                    </div>

                    <div class="bg-gradient-to-br from-primary to-primary-dark rounded-2xl p-6 text-white mb-6 anim-in anim-d1 relative overflow-hidden">
                        <div class="absolute top-0 right-0 w-32 h-32 bg-white/10 rounded-full -translate-y-1/2 translate-x-1/2"></div>
                        <div class="absolute bottom-0 left-0 w-20 h-20 bg-white/5 rounded-full translate-y-1/2 -translate-x-1/2"></div>
                        <div class="relative z-10">
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-white/20 rounded-full text-[10px] font-bold uppercase tracking-widest mb-3">
                                <span class="material-symbols-outlined text-[14px]">sports_tennis</span> <?php echo htmlspecialchars($skill_level); ?> Level
                            </span>
                            <h2 class="text-xl font-bold mb-2" id="tipTitle">Welcome to Your Training Hub</h2>
                            <p class="text-white/80 text-sm leading-relaxed" id="tipDesc">Personalized tips and techniques based on your skill level to help you improve your game.</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 anim-in anim-d2" id="tipsGrid">
                        <?php
                        $tips = [
                            'Beginner' => [
                                ['icon' => 'sports', 'title' => 'Grip Fundamentals', 'desc' => 'Master the forehand and backhand grip. Hold the racket like you\'re shaking hands — relaxed but firm. The V between thumb and index finger should align with the racket frame.', 'color' => 'bg-blue-50 text-blue-700'],
                                ['icon' => 'directions_run', 'title' => 'Basic Footwork', 'desc' => 'Start with the split step: a small hop as your opponent hits the shuttle. Always return to center court after each shot. Move with small, quick steps — never cross your feet.', 'color' => 'bg-green-50 text-green-700'],
                                ['icon' => 'sports_tennis', 'title' => 'The Clear Shot', 'desc' => 'The most important beginner stroke. Hit the shuttle high and deep to the back of your opponent\'s court. This gives you time to recover position. Focus on a full arm swing with wrist snap.', 'color' => 'bg-purple-50 text-purple-700'],
                                ['icon' => 'fitness_center', 'title' => 'Build Your Base', 'desc' => 'Badminton demands explosive legs and quick direction changes. Start with lunges, calf raises, and shadow footwork drills. 15 minutes daily builds the foundation for advanced play.', 'color' => 'bg-amber-50 text-amber-700'],
                            ],
                            'Intermediate' => [
                                ['icon' => 'sports_martial_arts', 'title' => 'Smash Technique', 'desc' => 'Generate power from your core, not just your arm. Rotate your hips and shoulders, snap your wrist at contact point, and follow through downward. Practice with a high shuttle feed.', 'color' => 'bg-red-50 text-red-700'],
                                ['icon' => 'swap_horiz', 'title' => 'Deception Shots', 'desc' => 'Use identical preparation for different shots. Show a clear, play a drop. Show a drop, play a net lift. The key is maintaining the same arm speed and body position until the last moment.', 'color' => 'bg-indigo-50 text-indigo-700'],
                                ['icon' => 'speed', 'title' => 'Speed & Agility', 'desc' => 'Add interval sprint training: 30 seconds full speed, 30 seconds rest, repeat 10x. Ladder drills improve foot speed. Shadow badminton (without shuttle) builds muscle memory.', 'color' => 'bg-cyan-50 text-cyan-700'],
                                ['icon' => 'psychology', 'title' => 'Match Strategy', 'desc' => 'Attack your opponent\'s backhand. Use clears to push them deep, then drop shots to bring them forward. Change pace often — don\'t let them settle into a rhythm.', 'color' => 'bg-teal-50 text-teal-700'],
                            ],
                            'Advanced' => [
                                ['icon' => 'precision_manufacturing', 'title' => 'Net Kill Precision', 'desc' => 'Train your net kills with a shuttle on the tape. Practice hitting downward at sharp angles. The key is a firm wrist with minimal backswing — snap and recover instantly.', 'color' => 'bg-orange-50 text-orange-700'],
                                ['icon' => 'groups', 'title' => 'Doubles Tactics', 'desc' => 'In doubles, maintain front-back attack formation when smashing, side-by-side when defending. Communicate with your partner. The server should follow the serve to the net immediately.', 'color' => 'bg-violet-50 text-violet-700'],
                                ['icon' => 'monitor_heart', 'title' => 'Recovery & Endurance', 'desc' => 'At this level, matches are won on endurance. Incorporate court sprints (6 corners), resistance band training, and yoga for flexibility. Sleep 7-8 hours minimum on training days.', 'color' => 'bg-rose-50 text-rose-700'],
                                ['icon' => 'psychology_alt', 'title' => 'Mental Game', 'desc' => 'Develop a between-point routine: breathe, bounce the shuttle, plan the next rally. Stay present — don\'t dwell on errors. Visualize your shots before playing them in pressure situations.', 'color' => 'bg-emerald-50 text-emerald-700'],
                            ],
                            'Pro' => [
                                ['icon' => 'emoji_events', 'title' => 'Game Reading', 'desc' => 'At pro level, anticipate before your opponent strikes. Study their body language, racket angle, and weight distribution. Train by watching matches and predicting shot selection.', 'color' => 'bg-yellow-50 text-yellow-700'],
                                ['icon' => 'tune', 'title' => 'Equipment Optimization', 'desc' => 'Racket string tension, grip size, and shuttle speed all matter at this level. Experiment with higher tensions (28-32 lbs) for control, lower for power. Use BG65 or BG66 strings.', 'color' => 'bg-pink-50 text-pink-700'],
                                ['icon' => 'science', 'title' => 'Periodized Training', 'desc' => 'Structure your training in cycles: off-season (volume), pre-season (intensity), competition (maintenance). Each phase has different load, recovery, and nutrition requirements.', 'color' => 'bg-sky-50 text-sky-700'],
                                ['icon' => 'biotech', 'title' => 'Injury Prevention', 'desc' => 'At pro intensity, injuries are the biggest threat. Warm up 15+ minutes, use ankle braces if needed, ice after sessions, and get regular sports massage. Don\'t play through pain.', 'color' => 'bg-lime-50 text-lime-700'],
                            ],
                        ];
                        $level_tips = $tips[$skill_level] ?? $tips['Beginner'];
                        foreach ($level_tips as $tip):
                        ?>
                        <div class="bg-white rounded-2xl border border-outline-variant/20 p-5 shadow-sm hover:shadow-md transition-shadow">
                            <div class="flex items-start gap-3 mb-3">
                                <div class="w-10 h-10 rounded-xl <?php echo $tip['color']; ?> flex items-center justify-center shrink-0">
                                    <span class="material-symbols-outlined text-lg"><?php echo $tip['icon']; ?></span>
                                </div>
                                <div>
                                    <h3 class="font-bold text-sm text-on-surface"><?php echo $tip['title']; ?></h3>
                                </div>
                            </div>
                            <p class="text-xs text-on-surface-variant leading-relaxed"><?php echo $tip['desc']; ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-6 grid grid-cols-1 sm:grid-cols-3 gap-3 anim-in anim-d3">
                        <a href="aimovementanalysis" class="bg-white rounded-2xl border border-outline-variant/20 p-5 shadow-sm hover:shadow-md transition-all group text-center">
                            <span class="material-symbols-outlined text-primary text-3xl mb-2 block group-hover:scale-110 transition-transform">smart_toy</span>
                            <p class="font-bold text-sm">AI Swing Analysis</p>
                            <p class="text-[10px] text-on-surface-variant mt-1">Get real-time biomechanical feedback</p>
                        </a>
                        <a href="courtbooking" class="bg-white rounded-2xl border border-outline-variant/20 p-5 shadow-sm hover:shadow-md transition-all group text-center">
                            <span class="material-symbols-outlined text-accent text-3xl mb-2 block group-hover:scale-110 transition-transform">stadium</span>
                            <p class="font-bold text-sm">Book a Court</p>
                            <p class="text-[10px] text-on-surface-variant mt-1">Reserve your training slot</p>
                        </a>
                        <a href="ecommerce" class="bg-white rounded-2xl border border-outline-variant/20 p-5 shadow-sm hover:shadow-md transition-all group text-center">
                            <span class="material-symbols-outlined text-green-600 text-3xl mb-2 block group-hover:scale-110 transition-transform">shopping_bag</span>
                            <p class="font-bold text-sm">Pro Shop</p>
                            <p class="text-[10px] text-on-surface-variant mt-1">Browse rackets, shoes & gear</p>
                        </a>
                    </div>
                </div>

                <!-- SETTINGS TAB -->
                <div id="panel-settings" class="db-panel hidden">
                    <div class="mb-6 anim-in anim-d1">
                        <span class="text-accent text-[11px] font-bold tracking-[0.25em] uppercase mb-1 block">Account</span>
                        <h1 class="text-2xl md:text-3xl font-bold">Settings</h1>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 anim-in anim-d2">
                        <div class="bg-white rounded-2xl border border-outline-variant/20 p-6 shadow-sm">
                            <h3 class="font-bold text-sm mb-5 flex items-center gap-2">
                                <span class="material-symbols-outlined text-primary text-lg">person</span> Profile Information
                            </h3>
                            <div class="space-y-4">
                                <div>
                                    <label class="text-[10px] font-bold text-on-surface-variant uppercase tracking-widest block mb-1.5">Full Name</label>
                                    <input type="text" value="<?php echo htmlspecialchars($full_name); ?>" class="w-full px-4 py-3 bg-surface rounded-xl border border-outline-variant/30 text-sm font-semibold" readonly>
                                </div>
                                <div>
                                    <label class="text-[10px] font-bold text-on-surface-variant uppercase tracking-widest block mb-1.5">Email Address</label>
                                    <input type="email" value="<?php echo htmlspecialchars($email); ?>" class="w-full px-4 py-3 bg-surface rounded-xl border border-outline-variant/30 text-sm font-semibold opacity-60 cursor-not-allowed" disabled>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white rounded-2xl border border-outline-variant/20 p-6 shadow-sm">
                            <h3 class="font-bold text-sm mb-5 flex items-center gap-2">
                                <span class="material-symbols-outlined text-primary text-lg">sports_tennis</span> Skill Level
                            </h3>
                            <div class="grid grid-cols-2 gap-3" id="skillGrid">
                                <?php foreach (['Beginner', 'Intermediate', 'Advanced', 'Pro'] as $level):
                                    $icons = ['Beginner' => 'sports', 'Intermediate' => 'sports_soccer', 'Advanced' => 'sports_martial_arts', 'Pro' => 'emoji_events'];
                                ?>
                                <div class="skill-chip <?php echo $skill_level === $level ? 'selected' : ''; ?>" onclick="setSkill('<?php echo $level; ?>')" data-skill="<?php echo $level; ?>">
                                    <span class="material-symbols-outlined text-lg block mb-1 <?php echo $skill_level === $level ? 'text-primary' : 'text-on-surface-variant'; ?>"><?php echo $icons[$level]; ?></span>
                                    <?php echo $level; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <p class="text-[10px] text-on-surface-variant mt-4 font-medium" id="skillMsg"></p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- SUPPORT TAB -->
            <div id="panel-support" class="db-panel hidden">
                <div class="mb-6 anim-in anim-d1">
                    <span class="text-accent text-[11px] font-bold tracking-[0.25em] uppercase mb-1 block">Support</span>
                    <h1 class="text-2xl md:text-3xl font-bold">Support &amp; Refunds</h1>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 anim-in anim-d2">
                    <div class="bg-white rounded-2xl border border-outline-variant/20 p-6 shadow-sm">
                        <h3 class="font-bold text-sm mb-2 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary text-lg">currency_exchange</span> Refund Requests
                        </h3>
                        <p class="text-xs text-on-surface-variant mb-4">Need to cancel or request a refund? Open the refund request flow and our team will review it.</p>
                        <button class="bg-gradient-to-r from-accent to-accent-dark text-white px-5 py-2.5 rounded-xl font-bold text-sm flex items-center gap-2 shadow-lg shadow-accent/20 hover:shadow-xl transition active:scale-95" onclick="showRefund()">
                            <span class="material-symbols-outlined text-[18px]">currency_exchange</span> Request a Refund
                        </button>
                    </div>

                    <div class="bg-white rounded-2xl border border-outline-variant/20 p-6 shadow-sm">
                        <h3 class="font-bold text-sm mb-2 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary text-lg">headset</span> Contact Support
                        </h3>
                        <p class="text-xs text-on-surface-variant mb-4">Send us a message or email us directly at <a href="mailto:support@hipowerbc.xyz" class="text-primary font-semibold hover:underline">support@hipowerbc.xyz</a>. For urgent booking or payment issues, include your booking or order reference.</p>
                        <a href="support.php?tab=contact" class="bg-white rounded-2xl border border-outline-variant/20 px-5 py-2.5 shadow-sm hover:shadow-md transition active:scale-95 text-primary font-bold text-sm flex items-center gap-2">
                            <span class="material-symbols-outlined text-[18px]">mail</span> Contact Support Center
                        </a>
                    </div>
                </div>
            </div>

            <!-- REFUND PAGE (mockup style) -->
            <section id="refundPage" class="hidden">
                        <button class="back-btn" onclick="showDashboard()"><span class="material-symbols-outlined text-[16px]">arrow_back</span> Back to My Bookings</button>
                <div class="page-heading">
                    <div><div class="eyebrow">PAYMENTS &amp; CANCELLATIONS</div><h1>Request a Refund</h1></div>
                </div>
                <div class="refund-layout">
                    <section class="panel form-panel">
                        <h2>Refund Request Details</h2>
                        <p class="subtext">Select the booking and tell us why you're requesting a refund. Your request will be reviewed by the admin.</p>
                        <div id="successBanner" class="success-banner" role="status"></div>
                        <div class="booking-summary">
                            <div class="summary-head"><strong>Selected Booking</strong><span class="status">Paid</span></div>
                            <?php $firstRefundable = !empty($refundable_items) ? $refundable_items[0] : null; ?>
                            <div class="summary-row"><span>Booking ID</span><strong id="summaryBooking"><?php echo $firstRefundable ? htmlspecialchars($firstRefundable['label']) : '—'; ?></strong></div>
                            <div class="summary-row"><span>Court</span><strong id="summaryCourt"><?php echo $firstRefundable ? htmlspecialchars($firstRefundable['court']) : (count($all_bookings) > 0 ? htmlspecialchars($all_bookings[0]['court_name']) : '—'); ?></strong></div>
                            <div class="summary-row"><span>Booking date</span><strong id="summaryDate"><?php echo $firstRefundable ? htmlspecialchars($firstRefundable['date']) : (count($all_bookings) > 0 ? date('M d, Y', strtotime($all_bookings[0]['booking_date'])) : '—'); ?></strong></div>
                            <div class="summary-row"><span>Amount paid</span><strong id="summaryAmount"><?php echo $firstRefundable ? htmlspecialchars($firstRefundable['amount']) : (count($all_bookings) > 0 ? '₱' . number_format($all_bookings[0]['total_price'], 2) : '—'); ?></strong></div>
                        </div>
                        <form id="refundForm" enctype="multipart/form-data">
                            <?php echo get_csrf_input(); ?>
                            <input type="hidden" name="order_id" id="refund_order_id" value="0">
                            <input type="hidden" name="booking_id" id="refund_booking_id" value="0">
                            <div class="field">
                                <label for="bookingId">Booking ID</label>
                                <select id="bookingId" name="bookingId" required>
                                    <?php if (empty($refundable_items)): ?>
                                        <option value="">No eligible items</option>
                                    <?php else: ?>
                                        <?php foreach ($refundable_items as $it): ?>
                                            <option value="<?php echo $it['type'] . ':' . $it['id']; ?>" data-type="<?php echo $it['type']; ?>" data-id="<?php echo $it['id']; ?>" data-court="<?php echo htmlspecialchars($it['court']); ?>" data-date="<?php echo htmlspecialchars($it['date']); ?>" data-amount="<?php echo htmlspecialchars($it['amount']); ?>"><?php echo $it['label']; ?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="reason">Reason for refund</label>
                                <select id="reason" name="reason" required>
                                    <option value="Change of plans" selected>Change of plans</option>
                                    <option value="Court unavailable">Court unavailable</option>
                                    <option value="Payment issue">Payment issue</option>
                                    <option value="Duplicate payment">Duplicate payment</option>
                                    <option value="Other reason">Other reason</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="details">Additional details <span style="font-weight:400;color:#8190a5">(optional)</span></label>
                                <textarea id="details" name="details" placeholder="Explain your refund request..."></textarea>
                            </div>
                            <div class="field">
                                <label for="refund_proof">Proof (optional)</label>
                                <input type="file" id="refund_proof" name="proof" accept="image/*,application/pdf">
                                <p class="text-[10px] text-outline mt-1">JPG, PNG, GIF or PDF, max 5 MB (receipt/screenshot).</p>
                            </div>
                            <label class="policy">
                                <input type="checkbox" id="policyCheck" required>
                                <span><strong>Refund Policy Confirmation</strong>I understand that refund requests are subject to Hi-Power's cancellation and refund policy. Submitting a request does not guarantee approval.</span>
                            </label>
                            <div class="form-actions">
                                <button class="primary-btn" type="button" onclick="submitRefund()">Submit Refund Request</button>
                                <button class="secondary-btn" type="button" onclick="showDashboard()">Cancel</button>
                            </div>
                        </form>
                        <div class="notice-box">*Connect this interface to your actual booking database and payment provider before using it for real refunds.</div>
                    </section>

                    <aside class="panel history-panel">
                        <h2>My Refund Requests</h2>
                        <p class="subtext">Track the status of refund requests you have submitted.</p>
                        <div id="refundHistory">
                            <?php
                            $any_refund = false;
                            foreach (['order' => 'Order', 'booking' => 'Booking'] as $k => $label) {
                                foreach ($user_refunds[$k] as $id => $r) {
                                    $any_refund = true;
                                    echo '<div class="history-item"><div class="row"><strong>' . htmlspecialchars($label . ' #' . $id) . '</strong><span class="status pending">' . htmlspecialchars($r['status']) . '</span></div><p>Amount: ₱' . number_format((float)$r['amount'], 2) . '</p><div class="row"><span style="color:#70819b;font-size:12px">Waiting for admin review</span><strong style="font-size:12px;color:#9b6500">' . htmlspecialchars($r['status']) . '</strong></div></div>';
                                }
                            }
                            if (!$any_refund) {
                                echo '<div class="empty-history">No refund requests submitted yet.</div>';
                            }
                            ?>
                        </div>
                        <div class="notice-box"><strong>What happens next?</strong><br>1. Your request is submitted.<br>2. The admin reviews the reason and booking.<br>3. The status is updated after review.</div>
                    </aside>
                </div>
            </section>
        </main>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php include __DIR__ . '/includes/mobile_nav.php'; ?>

<!-- Logout Modal -->
<div id="logoutModal" class="hidden fixed inset-0 z-[200] flex items-center justify-center px-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="toggleLogout()"></div>
    <div class="relative bg-white w-full max-w-sm rounded-3xl shadow-2xl overflow-hidden modal-animate" id="logoutContent">
        <div class="p-8 text-center">
            <div class="w-16 h-16 bg-red-50 text-accent rounded-2xl flex items-center justify-center mx-auto mb-4 border border-red-100">
                <span class="material-symbols-outlined text-3xl">logout</span>
            </div>
            <h3 class="text-xl font-bold mb-2">Ready to leave?</h3>
            <p class="text-sm text-on-surface-variant mb-8">You'll need to log in again to access your dashboard.</p>
            <div class="flex gap-3">
                <button onclick="toggleLogout()" class="flex-1 py-3 bg-surface border border-outline-variant/30 text-on-surface-variant rounded-xl font-bold text-sm hover:bg-surface-container-low transition">Cancel</button>
                <a href="logout" class="flex-1 py-3 bg-accent text-white rounded-xl font-bold text-sm hover:shadow-lg transition text-center">Log Out</a>
            </div>
        </div>
    </div>
</div>

<script>
// Tab Switching
const allTabs = ['bookings', 'orders', 'ai', 'tips', 'support', 'settings'];
function switchTab(tab) {
    document.querySelectorAll('.db-panel').forEach(p => p.classList.add('hidden'));
    const panel = document.getElementById('panel-' + tab);
    if (panel) panel.classList.remove('hidden');
    document.querySelectorAll('.side-menu a').forEach(a => a.classList.remove('active'));
    const navMap = { bookings: 'bookingsNav', orders: 'ordersNav', ai: 'aiNav', tips: 'tipsNav', support: 'supportNav', settings: 'settingsNav' };
    const sideNav = document.getElementById(navMap[tab] || '');
    if (sideNav) sideNav.classList.add('active');
    document.querySelectorAll('.mob-tab').forEach(b => {
        b.classList.remove('active', 'border-primary', 'text-primary');
        b.classList.add('border-transparent', 'text-on-surface-variant');
    });
    const mobTab = document.getElementById('mob-tab-' + tab);
    if (mobTab) {
        mobTab.classList.add('active', 'border-primary', 'text-primary');
        mobTab.classList.remove('border-transparent', 'text-on-surface-variant');
    }
}

// Dashboard / Refund page toggle (mockup)
const dashboardPage = document.getElementById('dashboardPage');
const refundPage = document.getElementById('refundPage');
function showDashboard() {
    document.querySelectorAll('.side-menu a').forEach(a => a.classList.remove('active'));
    if (dashboardPage) dashboardPage.classList.remove('hidden');
    if (refundPage) refundPage.classList.add('hidden');
    const bookingsNav = document.getElementById('bookingsNav');
    if (bookingsNav) bookingsNav.classList.add('active');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function showRefund() {
    document.querySelectorAll('.side-menu a').forEach(a => a.classList.remove('active'));
    if (dashboardPage) dashboardPage.classList.add('hidden');
    if (refundPage) refundPage.classList.remove('hidden');
    const sb = document.getElementById('summaryBooking');
    const bi = document.getElementById('bookingId');
    if (sb && bi) sb.textContent = bi.options[bi.selectedIndex] ? bi.options[bi.selectedIndex].textContent.trim() : '';
    const sbanner = document.getElementById('successBanner');
    if (sbanner) sbanner.classList.remove('show');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
// Refund form elements
const refundForm = document.getElementById('refundForm');
const bookingId = document.getElementById('bookingId');
const reason = document.getElementById('reason');
const details = document.getElementById('details');
const policyCheck = document.getElementById('policyCheck');
const refundHistory = document.getElementById('refundHistory');
const successBanner = document.getElementById('successBanner');
const summaryBooking = document.getElementById('summaryBooking');

let submittedRequests = [];

function renderRequests() {
    if (!refundHistory) return;
    refundHistory.innerHTML = '';
    if (submittedRequests.length === 0) {
        refundHistory.innerHTML = '<div class="empty-history">No refund requests submitted yet.</div>';
        return;
    }
    submittedRequests.forEach(request => {
        const item = document.createElement('div');
        item.className = 'history-item';
        item.innerHTML = `
            <div class="row"><strong>${escapeHtml(request.id)}</strong><span class="status pending">${escapeHtml(request.status)}</span></div>
            <p>Booking: ${escapeHtml(request.booking)}<br>Reason: ${escapeHtml(request.reason)}<br>Submitted: ${escapeHtml(request.date)}</p>
            <div class="row"><span style="color:#70819b;font-size:12px">Waiting for admin review</span><strong style="font-size:12px;color:#9b6500">Pending</strong></div>`;
        refundHistory.appendChild(item);
    });
}
function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, char => ({
        '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'
    }[char]));
}

function updateRefundSummary() {
    if (!bookingId) return;
    const opt = bookingId.options[bookingId.selectedIndex];
    const type = opt ? (opt.getAttribute('data-type') || '') : '';
    const id = opt ? (opt.getAttribute('data-id') || '') : '';
    const ro = document.getElementById('refund_order_id');
    const rb = document.getElementById('refund_booking_id');
    if (ro) ro.value = (type === 'order') ? (id || 0) : 0;
    if (rb) rb.value = (type === 'booking') ? (id || 0) : 0;
    if (summaryBooking && opt) summaryBooking.textContent = opt.textContent.trim();
    const sc = document.getElementById('summaryCourt');
    const sd = document.getElementById('summaryDate');
    const sa = document.getElementById('summaryAmount');
    if (sc && opt) sc.textContent = opt.getAttribute('data-court') || '—';
    if (sd && opt) sd.textContent = opt.getAttribute('data-date') || '—';
    if (sa && opt) sa.textContent = opt.getAttribute('data-amount') || '—';
}
if (bookingId) {
    bookingId.addEventListener('change', updateRefundSummary);
    updateRefundSummary();
}

// Skill Level Update
function setSkill(level) {
    document.querySelectorAll('.skill-chip').forEach(c => c.classList.remove('selected'));
    const chip = document.querySelector('.skill-chip[data-skill="'+level+'"]');
    if (chip) chip.classList.add('selected');
    const msg = document.getElementById('skillMsg');
    if (msg) msg.textContent = '';
    const fd = new FormData();
    fd.append('action', 'update_skill');
    fd.append('skill_level', level);
    fd.append('csrf_token', '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>');
    fetch('/playerdashboard', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                if (msg) { msg.textContent = '✓ Skill level updated'; msg.className = 'text-[10px] text-green-600 font-bold mt-4'; }
                const ss = document.getElementById('sidebarSkill');
                if (ss) ss.textContent = level;
                setTimeout(() => { if (msg) msg.textContent = ''; }, 3000);
            }
        });
}

// Cancel Booking
function cancelBooking(bid) {
    if (!confirm('Are you sure you want to cancel this booking?')) return;
    const fd = new FormData();
    fd.append('action', 'cancel_booking');
    fd.append('booking_id', bid);
    fd.append('csrf_token', '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>');
    fetch('/playerdashboard', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) location.reload();
            else alert(d.error || 'Could not cancel booking.');
        });
}

// Logout Modal
function toggleLogout() {
    const m = document.getElementById('logoutModal');
    if (!m) return;
    if (m.classList.contains('hidden')) { m.classList.remove('hidden'); }
    else { m.classList.add('hidden'); }
}

// Open refund (from booking rows) -> refund page
function openRefundModal(payload) {
    const ro = document.getElementById('refund_order_id');
    const rb = document.getElementById('refund_booking_id');
    if (ro) ro.value = payload.order_id || 0;
    if (rb) rb.value = payload.booking_id || 0;
    // try to select matching option
    if (bookingId) {
        const targetType = payload.order_id ? 'order' : 'booking';
        const targetId = payload.order_id || payload.booking_id;
        for (let i = 0; i < bookingId.options.length; i++) {
            const o = bookingId.options[i];
            if (o.getAttribute('data-type') === targetType && o.getAttribute('data-id') == targetId) {
                bookingId.selectedIndex = i;
                break;
            }
        }
        updateRefundSummary();
    }
    showRefund();
}

function closeRefundModal() {
    showDashboard();
}

function submitRefund() {
    if (policyCheck && !policyCheck.checked) {
        policyCheck.focus();
        alert('Please confirm the refund policy before submitting.');
        return;
    }
    const r = reason ? reason.value.trim() : '';
    if (r === '') { alert('Please describe why you are requesting a refund.'); return; }
    // combine details if provided
    const d = details ? details.value.trim() : '';
    const fd = new FormData(refundForm);
    if (d) fd.set('reason', r + (d ? ' — ' + d : ''));
    fd.append('action', 'request_refund');
    fetch('/playerdashboard', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                const req = { id: 'RF-' + String(Date.now()).slice(-6), booking: (bookingId ? bookingId.value : ''), reason: (reason ? reason.value : ''), details: (details && details.value.trim()) ? details.value.trim() : 'None provided', status: 'Pending', date: new Date().toLocaleDateString() };
                submittedRequests.unshift(req);
                renderRequests();
                if (successBanner) {
                    successBanner.textContent = 'Refund request submitted successfully. Status: Pending review.';
                    successBanner.classList.add('show');
                }
                if (refundForm) refundForm.reset();
                if (bookingId) {
                    // restore first
                }
                setTimeout(() => location.reload(), 1200);
            } else {
                alert(d.error || 'Could not submit refund request.');
            }
        })
        .catch(() => alert('Network error. Please try again.'));
}
</script>

</html>
