<?php
require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database_connect.php';
require_once __DIR__ . '/includes/csrf_helper.php';
require_once __DIR__ . '/includes/bank_details.php';

Config::load();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: /authentication/login");
    exit;
}

$user_id = $_SESSION['user_id'];

// No pending checkout -> nothing to complete.
$pending = $_SESSION['pending_checkout'] ?? null;
if (!$pending || !in_array($pending['type'], ['checkout_shop', 'checkout_booking', 'checkout_both'])) {
    header("Location: /cart");
    exit;
}

$type          = $pending['type'];
$cart_ids      = array_values(array_filter(array_map('intval', $pending['cart_ids'] ?? [])));
$booking_ids   = array_values(array_filter(array_map('intval', $pending['booking_ids'] ?? [])));
$has_shop      = in_array($type, ['checkout_shop', 'checkout_both']);
$has_bookings  = in_array($type, ['checkout_booking', 'checkout_both']);

// ==========================================
// FETCH selected shop lines (authoritative)
// ==========================================
$shop_lines = [];
if ($has_shop && !empty($cart_ids)) {
    $place = implode(',', array_fill(0, count($cart_ids), '?'));
    $stmt = $pdo->prepare("SELECT ci.cart_item_id, ci.quantity, ci.customization, p.product_id, p.name, p.price
                           FROM Cart_Items ci JOIN Products p ON ci.product_id = p.product_id
                           WHERE ci.user_id = ? AND ci.cart_item_id IN ($place)");
    $stmt->execute(array_merge([$user_id], $cart_ids));
    $rows = $stmt->fetchAll();
    if (count($rows) !== count($cart_ids)) { invalidate_checkout($pdo); }
    foreach ($rows as $r) {
        $shop_lines[] = [
            'product_id' => (int)$r['product_id'],
            'name'       => $r['name'],
            'quantity'   => (int)$r['quantity'],
            'price'      => (float)$r['price'],
            'amount'     => round($r['price'] * $r['quantity'], 2),
            'customization' => $r['customization'],
        ];
    }
}

// ==========================================
// FETCH selected bookings (authoritative)
// ==========================================
$booking_lines = [];
if ($has_bookings && !empty($booking_ids)) {
    $place = implode(',', array_fill(0, count($booking_ids), '?'));
    $stmt = $pdo->prepare("SELECT b.booking_id, b.booking_reference, b.booking_date, b.start_time, b.end_time, b.total_price, c.name AS court_name
                           FROM Bookings b JOIN Courts c ON b.court_id = c.court_id
                           WHERE b.user_id = ? AND b.status = 'In Cart' AND b.booking_id IN ($place)");
    $stmt->execute(array_merge([$user_id], $booking_ids));
    $rows = $stmt->fetchAll();
    if (count($rows) !== count($booking_ids)) { invalidate_checkout($pdo); }
    foreach ($rows as $r) {
        $booking_lines[] = [
            'booking_id'     => (int)$r['booking_id'],
            'booking_reference' => $r['booking_reference'],
            'court_name'     => $r['court_name'],
            'booking_date'   => $r['booking_date'],
            'start_time'     => $r['start_time'],
            'end_time'       => $r['end_time'],
            'amount'         => round((float)$r['total_price'], 2),
        ];
    }
}

if ($has_shop && empty($shop_lines)) { invalidate_checkout($pdo); }
if ($has_bookings && empty($booking_lines)) { invalidate_checkout($pdo); }
if (empty($shop_lines) && empty($booking_lines)) { invalidate_checkout($pdo); }

// ==========================================
// TOTALS (this panel's checkout only)
// ==========================================
function invalidate_checkout($pdo): void {
    unset($_SESSION['pending_checkout']);
    header("Location: /cart");
    exit;
}

$subtotal_shop     = array_sum(array_column($shop_lines, 'amount'));
$subtotal_booking  = array_sum(array_column($booking_lines, 'amount'));
$subtotal          = round($subtotal_shop + $subtotal_booking, 2);
$tax_rate          = (float)Config::get('PAYMENT_TAX_RATE', 0.08);
$tax               = round($subtotal * $tax_rate, 2);
$grand_total       = round($subtotal + $tax, 2);
$shop_tax          = round($subtotal_shop * $tax_rate, 2);

$banks      = bankAccountDetails();
$pickup     = storePickupInfo();
$step       = $pending['step'] ?? 'step1';
$notes      = $pending['notes'] ?? '';
$errors     = [];
$success    = null;

// ==========================================
// POST HANDLING
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $post_action = $_POST['action'] ?? '';

    if ($post_action === 'cancel') {
        unset($_SESSION['pending_checkout']);
        header("Location: /cart");
        exit;
    }

    if ($post_action === 'back_to_step1') {
        $_SESSION['pending_checkout']['step'] = 'step1';
        header("Location: /checkout");
        exit;
    }

    if ($post_action === 'step1') {
        $notes = mb_substr(trim($_POST['notes'] ?? ''), 0, 500);

        if ($has_bookings && empty($_POST['confirm_schedule'])) {
            $errors[] = "Please verify your court schedule to continue.";
        }
        if (mb_strlen($notes) > 500) {
            $errors[] = "Order notes cannot exceed 500 characters.";
        }

        if (empty($errors)) {
            $_SESSION['pending_checkout']['notes'] = $notes;
            $_SESSION['pending_checkout']['step']  = 'step2';
            header("Location: /checkout");
            exit;
        }
    }

    if ($post_action === 'submit_payment') {
        // ---- Validate receipt upload ----
        if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Please attach a screenshot of your bank transfer receipt.";
        } else {
            $size = (int)$_FILES['receipt']['size'];
            if ($size <= 0 || $size > 5 * 1024 * 1024) {
                $errors[] = "Receipt must be a JPG or PNG under 5 MB.";
            } else {
                $img = @getimagesize($_FILES['receipt']['tmp_name']);
                if (!$img || !in_array($img['mime'], ['image/jpeg', 'image/png'])) {
                    $errors[] = "Receipt must be a valid JPG or PNG image.";
                }
            }
        }

        if (empty($errors)) {
            $ref = generatePaymentReference();
            $ext = ($img['mime'] === 'image/png') ? 'png' : 'jpg';
            $dir = __DIR__ . '/uploads/receipts';
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $filename = $ref . '.' . $ext;
            $dest     = $dir . '/' . $filename;

            if (!move_uploaded_file($_FILES['receipt']['tmp_name'], $dest)) {
                $errors[] = "Sorry, we couldn't save your receipt. Please try again.";
            } else {
                $pdo->beginTransaction();
                try {
                    // 1. Payment row
                    $ins = $pdo->prepare("INSERT INTO payments (payment_reference, user_id, total_amount, status, receipt_path, notes) VALUES (?, ?, ?, 'Pending Payment', ?, ?)");
                    $ins->execute([$ref, $user_id, $grand_total, '/uploads/receipts/' . $filename, $notes]);
                    $payment_id = (int)$pdo->lastInsertId();
                    $order_id = null;

                    // 2. Shop portion -> one Orders row + item lines
                    if (!empty($shop_lines)) {
                        $order_number = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
                        $order_total  = round($subtotal_shop + $shop_tax, 2);
                        $ins_order = $pdo->prepare("INSERT INTO Orders (order_number, user_id, order_date, total_amount, status, payment_method, payment_reference, payment_id) VALUES (?, ?, CURRENT_DATE, ?, 'Pending Payment', 'bank_transfer', ?, ?)");
                        $ins_order->execute([$order_number, $user_id, $order_total, $ref, $payment_id]);
                        $order_id = (int)$pdo->lastInsertId();

                        $ins_item = $pdo->prepare("INSERT INTO payment_items (payment_id, item_type, product_id, order_id, quantity, amount, description) VALUES (?, 'shop', ?, ?, ?, ?, ?)");
                        foreach ($shop_lines as $line) {
                            $desc = $line['name'] . (empty($line['customization']) ? '' : ' (' . $line['customization'] . ')');
                            $ins_item->execute([$payment_id, $line['product_id'], $order_id, $line['quantity'], $line['amount'], $desc]);
                        }
                    }

                    // 3. Booking portion -> move to Pending Payment + item lines
                    $ins_item = $pdo->prepare("INSERT INTO payment_items (payment_id, item_type, booking_id, quantity, amount, description) VALUES (?, 'booking', ?, 1, ?, ?)");
                    foreach ($booking_lines as $line) {
                        $desc = $line['court_name'] . ' · ' . date('M d, Y', strtotime($line['booking_date'])) . ' ' . date('h:i A', strtotime($line['start_time'])) . '–' . date('h:i A', strtotime($line['end_time'])) . ' · ' . $line['booking_reference'];
                        $upd = $pdo->prepare("UPDATE Bookings SET status = 'Pending Payment', payment_id = ? WHERE booking_id = ? AND user_id = ? AND status = 'In Cart'");
                        $upd->execute([$payment_id, $line['booking_id'], $user_id]);
                        $ins_item->execute([$payment_id, $line['booking_id'], $line['amount'], $desc]);
                    }

                    // 4. Clear shop cart items
                    if (!empty($cart_ids)) {
                        $place = implode(',', array_fill(0, count($cart_ids), '?'));
                        $pdo->prepare("DELETE FROM Cart_Items WHERE user_id = ? AND cart_item_id IN ($place)")->execute(array_merge([$user_id], $cart_ids));
                    }

                    $pdo->commit();
                    unset($_SESSION['pending_checkout']);

                    $success = [
                        'ref'      => $ref,
                        'amount'   => $grand_total,
                        'receipt'  => '/uploads/receipts/' . $filename,
                    ];
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    @unlink($dest);
                    $errors[] = "Checkout failed: " . $e->getMessage();
                }
            }
        }
    }
}

function fmt_php($n): string {
    return '&#8369;' . number_format($n, 2);
}
$step = $success ? 'done' : $step;
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Bank Transfer Checkout | HI-POWER</title>
    <?php include __DIR__ . '/includes/tailwind_config.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
    :root{
      --navy:#08111d;--text:#142747;--muted:#7c90b0;--blue:#435bff;
      --orange:#ff4d1c;--page:#f3f5f8;--border:#dfe6ef;--white:#fff;
      --shadow:0 10px 28px rgba(23,43,77,.06);
    }
    *{box-sizing:border-box}
    body{background:var(--page);color:var(--text);font-family:"Manrope",sans-serif;font-size:14px}
    button,input,textarea{font:inherit}
    button{cursor:pointer}
    main.checkout-main{width:min(890px,calc(100% - 40px));margin:30px auto 54px}
    .page-title-row{display:flex;align-items:center;gap:12px;margin-bottom:14px}
    .back-btn{width:38px;height:38px;border:1px solid var(--border);border-radius:10px;background:#fff;color:#6e83a4;font-size:23px;display:grid;place-items:center;text-decoration:none;cursor:pointer}
    h1{margin:0;font-size:27px;line-height:1.25;letter-spacing:-.7px;font-weight:600}
    .steps{display:flex;align-items:center;gap:10px;margin:0 0 25px}
    .step{display:flex;align-items:center;gap:8px;color:#7d91b0;font-size:12px;font-weight:500}
    .step-number{width:26px;height:26px;border-radius:50%;display:grid;place-items:center;background:#e6ebf2;color:#8193ad;font-weight:600}
    .step.active{color:#24375a}
    .step.active .step-number{background:var(--blue);color:#fff;box-shadow:0 4px 9px rgba(67,91,255,.2)}
    .step.done .step-number{background:var(--blue);color:#fff}
    .step-line{height:1px;width:46px;background:#e0e6ee}
    .checkout-grid{display:grid;grid-template-columns:minmax(0,1fr) 250px;gap:25px;align-items:start}
    .left-column{display:grid;gap:19px}
    .card{background:var(--white);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow)}
    .card-pad{padding:19px}
    .card-heading{display:flex;align-items:center;gap:9px;margin:0 0 15px;font-size:15px;font-weight:600;color:#142747}
    .heading-symbol{color:var(--orange);font-size:18px}
    .optional{font-size:11px;color:var(--muted);font-weight:400}
    .reservation{padding:14px;background:#f5f7fa;border:1px solid #e4eaf2;border-radius:11px;display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:10px}
    .reservation-name{font-size:13px;font-weight:600;margin:0 0 3px;color:#172b4b}
    .reservation-time{font-size:11px;color:#7185a5;margin:0 0 4px}
    .reservation-code{font-size:9px;color:#8da0bc;letter-spacing:.7px;margin:0}
    .reservation-price{font-size:13px;font-weight:600;color:var(--orange);white-space:nowrap;align-self:flex-start}
    .confirmation{border-top:1px solid #e9edf3;margin-top:13px;padding-top:12px;display:flex;align-items:flex-start;gap:9px;color:#7589a8;font-size:11px;line-height:1.65}
    .confirmation input{margin:3px 0 0;accent-color:var(--blue);width:14px;height:14px;flex-shrink:0}
    .summary-label{font-size:11px;letter-spacing:.7px;text-transform:uppercase;font-weight:600;margin:0 0 14px}
    .summary-line{display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:11px;color:#7c90b0;margin:0 0 10px}
    .summary-line strong{font-size:11px;color:#243754;font-weight:600;white-space:nowrap}
    .summary-divider{border:0;border-top:1px solid #e9edf3;margin:13px 0}
    .amount-due{display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:12px;font-weight:600}
    .amount-due strong{font-size:20px;color:var(--orange);white-space:nowrap}
    textarea{width:100%;min-height:88px;resize:vertical;background:#f5f7fa;border:1px solid var(--border);border-radius:11px;padding:12px 13px;color:var(--text);font-size:11px;line-height:1.7;outline:none}
    textarea:focus{border-color:#9aa6ff;box-shadow:0 0 0 3px rgba(67,91,255,.08)}
    textarea::placeholder{color:#8295b2}
    .notes-heading{display:flex;justify-content:space-between;align-items:center;gap:12px}
    .counter{font-size:10px;color:#8194b3;font-weight:400}
    .actions{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-top:19px}
    .actions .cancel{order:-1}
    .cancel{color:#627a9d;text-decoration:none;font-size:12px;background:none;border:0;padding:0;cursor:pointer;font-family:inherit}
    .continue{display:flex;align-items:center;justify-content:center;gap:12px;border:0;border-radius:10px;background:var(--orange);color:#fff;padding:0 23px;height:43px;font-size:12px;font-weight:600;box-shadow:0 5px 12px rgba(255,77,28,.14);transition:transform .15s,opacity .15s;text-decoration:none}
    .continue:hover{transform:translateY(-1px)}
    .continue:disabled{opacity:.45;cursor:not-allowed;transform:none}
    .notice{display:none;margin-top:13px;padding:11px 13px;border-radius:9px;background:#fff0eb;color:#bd3a16;font-size:11px;line-height:1.6}
    .amount-card{padding:17px 19px;color:#fff;background:linear-gradient(120deg,#3548e6,#202fc8);border:0;border-radius:12px;box-shadow:0 6px 14px #3447df25}
    .eyebrow{font-size:9px;letter-spacing:.7px;text-transform:uppercase;font-weight:600;opacity:.9;margin:0 0 4px}
    .amount{font-size:29px;line-height:1.25;font-weight:700;letter-spacing:.2px;margin:0 0 4px}
    .amount-help{font-size:10px;line-height:1.55;margin:0;color:#e2e7ff}
    .section-title{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:600;margin:0 0 12px}
    .section-icon{color:var(--orange);font-size:16px}
    .bank-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .bank-card{border:1px solid var(--border);border-radius:12px;padding:13px;background:#fff;min-width:0}
    .bank-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
    .bank-icon{width:27px;height:27px;display:grid;place-items:center;border-radius:8px;background:#fff0eb;color:var(--orange);font-size:15px}
    .account-type{font-size:8px;color:#8da0bc;letter-spacing:.5px;text-transform:uppercase}
    .bank-name{font-size:12px;font-weight:600;line-height:1.45;margin:0 0 3px}
    .bank-owner{font-size:9px;line-height:1.4;color:#8194b3;margin:0 0 9px;min-height:13px}
    .account-box{display:flex;align-items:center;justify-content:space-between;gap:5px;background:#f4f6f9;border:1px solid #e8edf3;border-radius:8px;padding:6px 7px}
    .account-number{font-size:10px;font-weight:600;letter-spacing:.5px;color:#203452;overflow-wrap:anywhere}
    .copy-btn{width:24px;height:24px;flex-shrink:0;border:0;border-radius:6px;background:var(--orange);color:#fff;font-size:12px;display:grid;place-items:center;}
    .safety-note{font-size:9px;line-height:1.6;color:#8496b2;margin:8px 0 0}
    .upload-card{padding:17px}
    .upload-title{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;margin:0 0 12px}
    .dropzone{border:1.5px dashed #d7e0ed;border-radius:10px;min-height:160px;padding:20px 12px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;transition:background .15s,border-color .15s;cursor:pointer}
    .dropzone.dragover{background:#f0f3ff;border-color:var(--blue)}
    .upload-icon{width:38px;height:38px;border-radius:11px;background:#e9eef5;color:#8296b5;display:grid;place-items:center;font-size:20px;margin-bottom:10px}
    .upload-main{font-size:11px;font-weight:600;margin:0 0 4px}
    .browse{color:var(--orange);cursor:pointer;font-size:10px}
    .file-rules{font-size:8px;color:#91a1b9;background:#f3f5f8;border:1px solid #e6ebf2;border-radius:20px;padding:4px 9px;margin-top:10px}
    .drop-preview{display:none;align-items:center;gap:10px;width:100%;max-width:320px;text-align:left;padding:10px;background:#fff;border:1px solid var(--border);border-radius:9px;margin-top:12px}
    .drop-preview.show{display:flex}
    .file-type{width:32px;height:36px;display:grid;place-items:center;background:#fff0eb;color:var(--orange);border-radius:7px;font-size:10px;font-weight:700}
    .file-details{min-width:0;flex:1}.file-name{font-size:10px;font-weight:600;overflow-wrap:anywhere;margin:0 0 3px}.file-size{font-size:9px;color:var(--muted);margin:0}
    .remove-file{border:0;background:transparent;color:#e24a2b;font-size:17px}
    .schedule-label{font-size:9px;color:#8a9bb5;text-transform:uppercase;letter-spacing:.5px;margin:0 0 7px}
    .schedule-box{background:#f5f7fa;border:1px solid #e6ebf2;border-radius:8px;padding:10px;margin-bottom:13px}
    .schedule-name{font-size:10px;font-weight:600;margin:0 0 3px}
    .schedule-time{font-size:9px;color:#7d91b0;margin:0}
    .summary-due{border-top:1px solid #e8edf3;padding-top:13px;display:flex;justify-content:space-between;gap:8px;align-items:center;font-size:10px;color:#8194b3}
    .summary-due strong{font-size:14px;color:var(--orange);white-space:nowrap}
    .back-link{color:#617a9c;font-size:11px;text-decoration:none;background:none;border:none;cursor:pointer}
    .submit-btn{height:42px;padding:0 18px;display:flex;align-items:center;justify-content:center;gap:9px;border:0;border-radius:9px;background:var(--orange);color:#fff;font-size:11px;font-weight:600;box-shadow:0 4px 12px #ff4d1c20;transition:transform .15s,opacity .15s}
    .submit-btn:hover:not(:disabled){transform:translateY(-1px)}
    .submit-btn:disabled{opacity:.45;cursor:not-allowed;box-shadow:none}
    .message{display:none;margin-top:12px;padding:10px 12px;border-radius:8px;font-size:10px;line-height:1.6}
    .message.error{display:block;background:#fff0eb;color:#b93c1d}
    .message.success{display:block;background:#eaf8ef;color:#267446}
    .store-pickup-details p{margin:0 0 2px;font-size:11px;color:#7185a5;line-height:1.6}
    .store-pickup-details p:first-child{font-weight:600;color:#172b4b}
    @media(max-width:760px){
      main.checkout-main{margin-top:24px}.checkout-grid{grid-template-columns:1fr;gap:17px}.summary-card{grid-row:1}
      .summary-card .summary-content{display:grid;grid-template-columns:1fr 1fr;gap:0 18px}
      .summary-label{grid-column:1/-1}.amount-due{grid-column:1/-1}.summary-due{grid-column:1/-1}
      .bank-grid{grid-template-columns:1fr}
    }
    @media(max-width:480px){
      main.checkout-main{width:calc(100% - 24px);margin-top:19px}
      h1{font-size:21px}.back-btn{width:34px;height:34px}
      .steps{gap:7px;margin-bottom:18px}.step{font-size:10px;gap:6px}.step-number{width:23px;height:23px}.step-line{width:18px}
      .card-pad{padding:15px}.card-heading{font-size:13px}
      .actions{align-items:stretch}.continue{padding:0 13px;font-size:11px}.cancel{align-self:center}
    }
    </style>
</head>
<body class="bg-background text-on-background min-h-screen flex flex-col pb-20 md:pb-0">

<?php include __DIR__ . '/includes/nav_bar.php'; ?>

<main class="checkout-main">

<?php if ($success): ?>
    <div class="page-title-row">
        <a class="back-btn" href="/playerdashboard" aria-label="Dashboard">‹</a>
        <h1>Payment Submitted!</h1>
    </div>
    <div class="card card-pad" style="text-align:center;">
        <div style="width:80px;height:80px;margin:0 auto 24px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:16px;display:grid;place-items:center;">
            <span class="material-symbols-outlined" style="color:#059669;font-size:40px;">verified</span>
        </div>
        <h2 style="font-size:24px;font-weight:700;margin:0 0 8px;">Payment Submitted!</h2>
        <p style="color:#7c90b0;font-size:12px;margin:0 0 24px;max-width:420px;margin-left:auto;margin-right:auto;">Your bank transfer has been recorded and is awaiting verification by our team. We'll confirm your <?php echo $has_bookings ? 'court schedule' : 'order'; ?> once the transfer is verified.</p>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;max-width:520px;margin:0 auto 24px;">
            <div style="background:#f5f7fa;padding:12px;border-radius:10px;border:1px solid var(--border);">
                <p style="font-size:9px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.7px;margin:0 0 4px;">Reference No.</p>
                <p style="font-weight:700;font-size:12px;word-break:break-all;margin:0;"><?php echo htmlspecialchars($success['ref']); ?></p>
            </div>
            <div style="background:#f5f7fa;padding:12px;border-radius:10px;border:1px solid var(--border);">
                <p style="font-size:9px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.7px;margin:0 0 4px;">Amount Transferred</p>
                <p style="font-weight:700;font-size:12px;color:var(--orange);margin:0;"><?php echo fmt_php($success['amount']); ?></p>
            </div>
            <div style="background:#f5f7fa;padding:12px;border-radius:10px;border:1px solid var(--border);">
                <p style="font-size:9px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.7px;margin:0 0 4px;">Status</p>
                <p style="font-weight:700;font-size:12px;color:#d97706;margin:0;">Pending Verification</p>
            </div>
        </div>
        <a href="/playerdashboard" class="continue" style="display:inline-flex;text-decoration:none;">Go to Dashboard</a>
    </div>

<?php else: ?>

    <div class="page-title-row">
        <a href="/cart" class="back-btn" aria-label="Back to cart" onclick="return confirm('Leave checkout? Your selection will be kept in the cart.')">‹</a>
        <h1>Bank Transfer Checkout</h1>
    </div>

    <div class="steps" aria-label="Checkout progress">
        <div class="step <?php echo $step === 'step1' ? 'active' : 'done'; ?>"><span class="step-number">1</span><span>Fulfillment &amp; Verification</span></div>
        <span class="step-line"></span>
        <div class="step <?php echo $step === 'step2' ? 'active' : ''; ?>"><span class="step-number">2</span><span>Bank Transfer &amp; Upload</span></div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="notice" style="display:block;margin-bottom:16px;" role="alert">
            <?php foreach ($errors as $e): ?>• <?php echo htmlspecialchars($e); ?><br><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($step === 'step1'): ?>
        <form method="POST" action="/checkout">
            <?php echo get_csrf_input(); ?>

            <div class="checkout-grid">
                <div class="left-column">

                    <?php if ($has_shop): ?>
                    <section class="card card-pad">
                        <h2 class="card-heading"><span class="heading-symbol material-symbols-outlined text-[18px]">storefront</span> Store Pickup</h2>
                        <div class="store-pickup-details">
                            <p><?php echo htmlspecialchars($pickup['name']); ?></p>
                            <p><?php echo htmlspecialchars($pickup['address']); ?></p>
                            <p><?php echo htmlspecialchars($pickup['hours']); ?></p>
                            <p><?php echo htmlspecialchars($pickup['requirements']); ?></p>
                        </div>
                    </section>
                    <?php endif; ?>

                    <?php if ($has_bookings): ?>
                    <section class="card card-pad">
                        <h2 class="card-heading"><span class="heading-symbol material-symbols-outlined text-[18px]">event_available</span> Reservation Summary</h2>
                        <?php foreach ($booking_lines as $bl): ?>
                        <div class="reservation">
                            <div>
                                <p class="reservation-name"><?php echo htmlspecialchars($bl['court_name']); ?></p>
                                <p class="reservation-time"><?php echo date('M d, Y', strtotime($bl['booking_date'])); ?> · <?php echo date('h:i A', strtotime($bl['start_time'])); ?> – <?php echo date('h:i A', strtotime($bl['end_time'])); ?></p>
                                <p class="reservation-code">#<?php echo htmlspecialchars($bl['booking_reference']); ?></p>
                            </div>
                            <div class="reservation-price"><?php echo fmt_php($bl['amount']); ?></div>
                        </div>
                        <?php endforeach; ?>
                        <label class="confirmation">
                            <input type="checkbox" name="confirm_schedule" value="1" id="confirmDetails" required>
                            <span>I confirm that all dates and time slots above are correct. No-shows or reschedules may be subject to fees.</span>
                        </label>
                    </section>
                    <?php endif; ?>

                    <section class="card card-pad">
                        <div class="notes-heading">
                            <h2 class="card-heading" style="margin-bottom:13px;"><span class="heading-symbol">☰</span> Order Notes <span class="optional">(optional)</span></h2>
                            <span class="counter" id="notesCounter">0 / 500</span>
                        </div>
                        <textarea id="orderNotes" name="notes" maxlength="500" placeholder="E.g., pickup instructions, court setup requests (birdie tubes, net height check), or anything our staff should know."><?php echo htmlspecialchars($notes); ?></textarea>
                    </section>
                </div>

                <aside class="card card-pad summary-card">
                    <div class="summary-content">
                        <h2 class="summary-label">This Checkout</h2>
                        <?php if ($has_shop): ?>
                        <div class="summary-line"><span>Shop items</span><strong><?php echo count($shop_lines); ?></strong></div>
                        <div class="summary-line"><span>Shop subtotal</span><strong><?php echo fmt_php($subtotal_shop); ?></strong></div>
                        <?php endif; ?>
                        <?php if ($has_bookings): ?>
                        <div class="summary-line"><span>Bookings</span><strong><?php echo count($booking_lines); ?></strong></div>
                        <div class="summary-line"><span>Bookings subtotal</span><strong><?php echo fmt_php($subtotal_booking); ?></strong></div>
                        <?php endif; ?>
                        <hr class="summary-divider">
                        <div class="summary-line"><span>Subtotal</span><strong><?php echo fmt_php($subtotal); ?></strong></div>
                        <div class="summary-line"><span>Tax (8%)</span><strong><?php echo fmt_php($tax); ?></strong></div>
                        <hr class="summary-divider">
                        <div class="amount-due"><span>Amount Due</span><strong><?php echo fmt_php($grand_total); ?></strong></div>
                    </div>
                </aside>
            </div>

            <div class="actions">
                <button type="submit" name="action" value="step1" class="continue" id="continueBtn">Continue to Bank Transfer <span class="material-symbols-outlined text-[16px]">arrow_forward</span></button>
                <button type="submit" name="action" value="cancel" class="cancel">Cancel</button>
            </div>
            <div class="notice" id="notice" role="status"></div>
        </form>

    <?php elseif ($step === 'step2'): ?>
        <form method="POST" action="/checkout" enctype="multipart/form-data">
            <?php echo get_csrf_input(); ?>

            <div class="checkout-grid">
                <div class="left-column">
                    <section class="amount-card">
                        <p class="eyebrow">Exact amount to transfer</p>
                        <p class="amount"><?php echo fmt_php($grand_total); ?></p>
                        <p class="amount-help">Transfer this exact amount to either bank account below, then upload your receipt.</p>
                    </section>

                    <section>
                        <h2 class="section-title"><span class="section-icon material-symbols-outlined text-[18px]">account_balance</span> Where to Transfer</h2>
                        <div class="bank-grid">
                            <?php foreach ($banks as $i => $bank): ?>
                            <?php $btn_id = 'copyBtn' . $i; ?>
                            <article class="bank-card">
                                <div class="bank-top">
                                    <span class="bank-icon">▤</span>
                                    <span class="account-type"><?php echo htmlspecialchars($bank['type']); ?></span>
                                </div>
                                <p class="bank-name"><?php echo htmlspecialchars($bank['bank']); ?></p>
                                <p class="bank-owner"><?php echo htmlspecialchars($bank['account_name']); ?></p>
                                <div class="account-box">
                                    <span class="account-number"><?php echo htmlspecialchars($bank['account_number']); ?></span>
                                    <button type="button" data-account="<?php echo htmlspecialchars($bank['account_number']); ?>" class="copy-btn <?php echo $btn_id; ?>" title="Copy account number"><span class="material-symbols-outlined text-[14px]">content_copy</span></button>
                                </div>
                            </article>
                            <?php endforeach; ?>
                        </div>
                        <p class="safety-note">ⓘ &nbsp; Double-check the account name before transferring. We will verify every payment manually.</p>
                    </section>

                    <section class="card upload-card">
                        <h2 class="upload-title"><span class="section-icon material-symbols-outlined text-[18px]">upload_file</span> Upload Proof of Transfer</h2>
                        <div id="dropzone" class="dropzone">
                            <input type="file" name="receipt" id="receiptInput" accept="image/png,image/jpeg" class="hidden">
                            <div id="dropEmpty">
                                <div class="upload-icon"><span class="material-symbols-outlined text-[20px]">cloud_upload</span></div>
                                <p class="upload-main">Drag &amp; drop your receipt here</p>
                                <label class="browse" for="receiptInput">or browse files</label>
                                <span class="file-rules">JPG or PNG only · max 5 MB</span>
                            </div>
                            <div id="dropPreview" class="drop-preview">
                                <div class="file-type">IMG</div>
                                <div class="file-details">
                                    <p class="file-name" id="previewName"></p>
                                    <p class="file-size">Ready to upload</p>
                                </div>
                                <button type="button" onclick="clearReceipt()" class="remove-file" aria-label="Remove"><span class="material-symbols-outlined text-[18px]">close</span></button>
                            </div>
                        </div>
                    </section>
                </div>

                <aside class="card card-pad summary-card">
                    <div class="summary-content">
                        <h2 class="summary-label">Summary</h2>

                        <?php if ($has_shop): ?>
                        <div style="margin-bottom:12px;">
                            <p class="schedule-label">Store Pickup</p>
                            <div class="schedule-box">
                                <p class="schedule-name"><?php echo htmlspecialchars($pickup['name']); ?></p>
                                <p class="schedule-time"><?php echo htmlspecialchars($pickup['address']); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($has_bookings): ?>
                        <div style="margin-bottom:12px;">
                            <p class="schedule-label">Schedule</p>
                            <div>
                                <?php foreach ($booking_lines as $bl): ?>
                                <div class="schedule-box">
                                    <p class="schedule-name"><?php echo htmlspecialchars($bl['court_name']); ?></p>
                                    <p class="schedule-time"><?php echo date('M d', strtotime($bl['booking_date'])); ?> · <?php echo date('h:i A', strtotime($bl['start_time'])); ?></p>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($notes)): ?>
                        <div style="margin-bottom:12px;">
                            <p class="schedule-label">Notes</p>
                            <div class="schedule-box">
                                <p class="schedule-time" style="color:#142747;"><?php echo nl2br(htmlspecialchars($notes)); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="summary-due"><span>Amount Due</span><strong><?php echo fmt_php($grand_total); ?></strong></div>
                    </div>
                </aside>
            </div>

            <div class="actions">
                <div style="display:flex;align-items:center;gap:15px;flex:1;justify-content:flex-end">
                    <button type="button" class="back-link" onclick="goBackToStep1()"><span class="material-symbols-outlined text-[16px]">arrow_back</span> Back</button>
                    <button type="submit" name="action" value="submit_payment" class="submit-btn" id="submitBtn" disabled><span class="material-symbols-outlined text-[16px]">send</span> &nbsp; I've Sent the Transfer <span class="material-symbols-outlined text-[16px]">arrow_forward</span></button>
                </div>
                <button type="submit" name="action" value="cancel" class="cancel">Cancel</button>
            </div>
            <div class="message" id="message" role="status"></div>
        </form>

    <?php endif; ?>
<?php endif; ?>

</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php include __DIR__ . '/includes/mobile_nav.php'; ?>

<script>
    // ---- Notes counter (step 1) ----
    const notesField = document.getElementById('orderNotes');
    const notesCounter = document.getElementById('notesCounter');
    if (notesField && notesCounter) {
        const updateNotes = () => { notesCounter.textContent = notesField.value.length + ' / 500'; };
        notesField.addEventListener('input', updateNotes);
        updateNotes();
    }

    // ---- Copy account number (step 2) ----
    document.querySelectorAll('[data-account]').forEach(btn => {
        btn.addEventListener('click', () => {
            const account = btn.dataset.account;
            const fallback = () => {
                const ta = document.createElement('textarea');
                ta.value = account;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(account).then(() => toast('Account number copied!')).catch(fallback);
            } else {
                fallback(); toast('Account number copied!');
            }
        });
    });

    // ---- Drag & drop upload (step 2) ----
    const dropzone = document.getElementById('dropzone');
    const receiptInput = document.getElementById('receiptInput');
    const submitBtn = document.getElementById('submitBtn');
    if (dropzone && receiptInput) {
        dropzone.addEventListener('click', (e) => {
            const t = e.target;
            if (t === dropzone || t.classList.contains('upload-icon') || t.classList.contains('upload-main') || t.classList.contains('browse') || t.classList.contains('file-rules')) {
                receiptInput.click();
            }
        });
        ['dragenter', 'dragover'].forEach(ev => dropzone.addEventListener(ev, e => { e.preventDefault(); dropzone.classList.add('dragover'); }));
        ['dragleave', 'drop'].forEach(ev => dropzone.addEventListener(ev, e => { e.preventDefault(); dropzone.classList.remove('dragover'); }));
        dropzone.addEventListener('drop', e => {
            if (e.dataTransfer.files.length) { receiptInput.files = e.dataTransfer.files; handleReceipt(); }
        });
        receiptInput.addEventListener('change', handleReceipt);
    }

    function handleReceipt() {
        const file = receiptInput.files[0];
        if (!file) return;
        const okTypes = ['image/jpeg', 'image/png'];
        if (!okTypes.includes(file.type)) { alert('Please upload a JPG or PNG image.'); clearReceipt(); return; }
        if (file.size > 5 * 1024 * 1024) { alert('Receipt must be under 5 MB.'); clearReceipt(); return; }
        document.getElementById('previewName').textContent = file.name;
        document.getElementById('dropEmpty').classList.add('hidden');
        const preview = document.getElementById('dropPreview');
        preview.classList.remove('hidden');
        preview.classList.add('show');
        if (submitBtn) submitBtn.disabled = false;
    }

    function clearReceipt() {
        if (receiptInput) receiptInput.value = '';
        const preview = document.getElementById('dropPreview');
        if (preview) { preview.classList.add('hidden'); preview.classList.remove('show'); }
        const empty = document.getElementById('dropEmpty');
        if (empty) empty.classList.remove('hidden');
        if (submitBtn) submitBtn.disabled = true;
    }

    // ---- Back to step 1 ----
    function goBackToStep1() {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/checkout';
        const csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = 'csrf_token';
        csrf.value = '<?php echo $_SESSION['csrf_token'] ?? ''; ?>';
        const action = document.createElement('input');
        action.type = 'hidden';
        action.name = 'action';
        action.value = 'back_to_step1';
        form.appendChild(csrf);
        form.appendChild(action);
        document.body.appendChild(form);
        form.submit();
    }

    // ---- Step1 confirm gating ----
    const confirmDetails = document.getElementById('confirmDetails');
    const continueBtn = document.getElementById('continueBtn');
    const notice = document.getElementById('notice');
    if (confirmDetails && continueBtn) {
        const syncContinue = () => { continueBtn.disabled = !confirmDetails.checked; };
        confirmDetails.addEventListener('change', () => {
            syncContinue();
            if (notice) notice.style.display = 'none';
        });
        syncContinue();
        continueBtn.addEventListener('click', (e) => {
            if (!confirmDetails.checked) {
                e.preventDefault();
                if (notice) { notice.textContent = 'Please confirm your reservation details before continuing.'; notice.style.display = 'block'; }
            }
        });
    }

    let toastTimer;
    function toast(msg) {
        let el = document.getElementById('toast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'toast';
            el.className = 'fixed bottom-24 left-1/2 -translate-x-1/2 bg-on-surface text-surface px-5 py-3 rounded-xl shadow-2xl text-sm font-bold z-[300] transition-opacity duration-300';
            document.body.appendChild(el);
        }
        el.textContent = msg;
        el.style.opacity = '1';
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => el.style.opacity = '0', 2200);
    }
</script>
</body>
</html>