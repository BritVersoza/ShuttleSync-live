<?php
/**
 * Booking Payment Handler
 * Handles PayPal + PayMongo for a SINGLE court booking (direct pay, no cart)
 */
require_once __DIR__ . '/../includes/session_boot.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_connect.php';

Config::load();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$booking_id = (int)($_POST['booking_id'] ?? 0);

// ==========================================
// HELPERS
// ==========================================
function getBookingWithTax(int $booking_id, int $user_id, PDO $pdo): array {
    $stmt = $pdo->prepare("
        SELECT b.*, c.name as court_name
        FROM Bookings b JOIN Courts c ON b.court_id = c.court_id
        WHERE b.booking_id = ? AND b.user_id = ? AND b.status = 'Pending Payment'
    ");
    $stmt->execute([$booking_id, $user_id]);
    $booking = $stmt->fetch();
    if (!$booking) throw new Exception('Booking not found or already paid.');
    
    $tax_rate = (float)Config::get('PAYMENT_TAX_RATE', 0.08);
    $tax = $booking['total_price'] * $tax_rate;
    $total = $booking['total_price'] + $tax;
    
    return [
        'booking' => $booking,
        'tax' => number_format($tax, 2, '.', ''),
        'total' => number_format($total, 2, '.', ''),
    ];
}

function confirmBookingPayment(int $booking_id, string $method, string $txn_id, PDO $pdo): void {
    $pdo->prepare("UPDATE Bookings SET status = 'Confirmed' WHERE booking_id = ?")->execute([$booking_id]);
}

// ==========================================
// ROUTES
// ==========================================
try {
    switch ($action) {

        // ==========================================
        // PAYPAL: CREATE ORDER
        // ==========================================
        case 'create_paypal_order':
            if (!$booking_id) throw new Exception('Missing booking ID');
            $info = getBookingWithTax($booking_id, $user_id, $pdo);
            $booking = $info['booking'];
            
            $paypal_client_id = Config::get('PAYPAL_CLIENT_ID');
            $paypal_secret = Config::get('PAYPAL_CLIENT_SECRET');
            $paypal_mode = Config::get('PAYPAL_MODE', 'sandbox');
            $base = ($paypal_mode === 'live') ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
            
            // Get access token
            $ch = curl_init("{$base}/v1/oauth2/token");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
                CURLOPT_USERPWD => "{$paypal_client_id}:{$paypal_secret}",
            ]);
            $token_resp = json_decode(curl_exec($ch), true);
            curl_close($ch);
            if (!isset($token_resp['access_token'])) throw new RuntimeException('PayPal auth failed');
            $token = $token_resp['access_token'];
            
            // Create PayPal order
            $payload = [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $booking['booking_reference'],
                    'description' => "HI-POWER Booking: {$booking['court_name']} ({$booking['booking_reference']})",
                    'amount' => [
                        'currency_code' => 'PHP',
                        'value' => $info['total'],
                        'breakdown' => [
                            'item_total' => ['currency_code' => 'PHP', 'value' => $info['booking']['total_price']],
                            'tax_total' => ['currency_code' => 'PHP', 'value' => $info['tax']],
                        ],
                    ],
                    'items' => [[
                        'name' => "{$booking['court_name']} - {$booking['booking_reference']}",
                        'unit_amount' => ['currency_code' => 'PHP', 'value' => $info['booking']['total_price']],
                        'quantity' => '1',
                    ]],
                ]],
                'payment_source' => ['paypal' => ['experience_context' => [
                    'brand_name' => 'HI-POWER', 'locale' => 'en-PH',
                    'landing_page' => 'BILLING', 'shipping_preference' => 'NO_SHIPPING', 'user_action' => 'PAY_NOW',
                ]]],
            ];
            
            $ch = curl_init("{$base}/v2/checkout/orders");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$token}"],
            ]);
            $resp = json_decode(curl_exec($ch), true);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($http !== 201 || !isset($resp['id'])) throw new RuntimeException('PayPal order failed: ' . ($resp['message'] ?? ''));
            
            echo json_encode(['success' => true, 'order_id' => $resp['id'], 'booking_id' => $booking_id]);
            break;

        // ==========================================
        // PAYPAL: CAPTURE ORDER
        // ==========================================
        case 'capture_paypal_order':
            $paypal_order_id = $_POST['order_id'] ?? '';
            $booking_id = (int)($_POST['booking_id'] ?? 0);
            if (!$paypal_order_id || !$booking_id) throw new Exception('Missing order IDs');
            
            $paypal_client_id = Config::get('PAYPAL_CLIENT_ID');
            $paypal_secret = Config::get('PAYPAL_CLIENT_SECRET');
            $paypal_mode = Config::get('PAYPAL_MODE', 'sandbox');
            $base = ($paypal_mode === 'live') ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
            
            $ch = curl_init("{$base}/v1/oauth2/token");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
                CURLOPT_USERPWD => "{$paypal_client_id}:{$paypal_secret}",
            ]);
            $token = json_decode(curl_exec($ch), true)['access_token'];
            curl_close($ch);
            
            $ch = curl_init("{$base}/v2/checkout/orders/{$paypal_order_id}/capture");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$token}"],
            ]);
            $resp = json_decode(curl_exec($ch), true);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($http < 200 || $http >= 300) throw new RuntimeException('PayPal capture failed');
            
            $capture = $resp['purchase_units'][0]['payments']['captures'][0] ?? null;
            if (!$capture || $capture['status'] !== 'COMPLETED') throw new RuntimeException('Payment not completed');
            
            // Verify ownership and that the booking is still pending payment.
            $chk = $pdo->prepare("SELECT booking_id FROM Bookings WHERE booking_id = ? AND user_id = ? AND status = 'Pending Payment'");
            $chk->execute([$booking_id, $user_id]);
            if (!$chk->fetch()) throw new Exception('Invalid or already processed booking.');
            
            $txn_id = $capture['id'];
            
            $pdo->prepare("UPDATE Bookings SET status = 'Confirmed' WHERE booking_id = ? AND status = 'Pending Payment'")->execute([$booking_id]);
            
            echo json_encode(['success' => true, 'transaction_id' => $txn_id]);
            break;

        // ==========================================
        // PAYMONGO: CREATE CHECKOUT SESSION
        // ==========================================
        case 'create_paymongo_session':
            $payment_method = $_POST['payment_method'] ?? 'gcash';
            if (!$booking_id) throw new Exception('Missing booking ID');
            $info = getBookingWithTax($booking_id, $user_id, $pdo);
            $booking = $info['booking'];
            
            $paymongo_secret = Config::get('PAYMONGO_SECRET_KEY');
            $app_url = Config::get('APP_URL', 'https://hipowerbc.xyz');
            
            $paymongo_methods = match($payment_method) {
                'gcash' => [['type' => 'gcash', 'riminator' => (object)['name' => $_SESSION['full_name'] ?? 'Customer', 'email' => $_SESSION['email'] ?? '']]],
                default => throw new Exception('Invalid payment method'),
            };
            
            $payload = ['data' => ['attributes' => [
                'send_email_receipt' => false,
                'show_description' => true,
                'show_line_items' => true,
                'line_items' => [[
                    'currency' => 'PHP',
                    'amount' => (int)round($booking['total_price'] * 100),
                    'name' => "{$booking['court_name']} - {$booking['booking_reference']}",
                    'quantity' => 1,
                ]],
                'payment_method_types' => $paymongo_methods,
                'description' => "HI-POWER Booking {$booking['booking_reference']}",
                'metadata' => ['user_id' => $user_id, 'booking_id' => $booking_id],
                'success_url' => "{$app_url}/payment/booking_handler.php?action=paymongo_success&booking_id={$booking_id}",
                'cancel_url' => "{$app_url}/book_and_pay.php?id={$booking_id}",
            ]]];
            
            $ch = curl_init("https://api.paymongo.com/v1/checkout_sessions");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_USERPWD => "{$paymongo_secret}:",
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            ]);
            $resp = json_decode(curl_exec($ch), true);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($http !== 201 || !isset($resp['data']['data']['attributes']['checkout_url'])) {
                $err = $resp['errors'][0]['detail'] ?? 'PayMongo error';
                throw new RuntimeException($err);
            }
            
            $checkout_url = $resp['data']['data']['attributes']['checkout_url'];
            $session_id = $resp['data']['data']['id'];
            
            echo json_encode(['success' => true, 'checkout_url' => $checkout_url, 'booking_id' => $booking_id]);
            break;

        // ==========================================
        // PAYMONGO: SUCCESS REDIRECT
        // ==========================================
        case 'paymongo_success':
            $booking_id = (int)($_GET['booking_id'] ?? 0);
            $app_url = Config::get('APP_URL', 'https://hipowerbc.xyz');
            // Webhook will confirm, but let's redirect to courtbooking
            header("Location: {$app_url}/courtbooking?payment=success");
            exit;

        // ==========================================
        // PAYMONGO: CHECK STATUS (POLLING)
        // ==========================================
        case 'check_status':
            $booking_id = (int)($_POST['booking_id'] ?? 0);
            if (!$booking_id) throw new Exception('Missing booking ID');
            
            $stmt = $pdo->prepare("SELECT booking_id, status FROM Bookings WHERE booking_id = ? AND user_id = ?");
            $stmt->execute([$booking_id, $user_id]);
            $booking = $stmt->fetch();
            
            if (!$booking) { echo json_encode(['status' => 'not_found']); exit; }
            
            echo json_encode(['status' => $booking['status']]);
            break;

        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}