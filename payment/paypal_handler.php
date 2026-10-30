<?php
/**
 * PayPal Payment Handler
 * Handles PayPal Checkout integration via REST API
 */
require_once __DIR__ . '/../includes/session_boot.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_connect.php';

Config::load();

// Verify session
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Get PayPal credentials
$paypal_client_id = Config::get('PAYPAL_CLIENT_ID');
$paypal_secret = Config::get('PAYPAL_CLIENT_SECRET');
$paypal_mode = Config::get('PAYPAL_MODE', 'sandbox');
$paypal_base_url = ($paypal_mode === 'live') 
    ? 'https://api-m.paypal.com' 
    : 'https://api-m.sandbox.paypal.com';

// Helper: Get PayPal access token
function getPayPalAccessToken(string $client_id, string $secret, string $base_url): string {
    $ch = curl_init("{$base_url}/v1/oauth2/token");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        CURLOPT_USERPWD => "{$client_id}:{$secret}",
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    if (!isset($response['access_token'])) {
        throw new RuntimeException('Failed to get PayPal access token');
    }
    return $response['access_token'];
}

// Helper: Get cart items and calculate total
function getCartTotal(int $user_id, PDO $pdo): array {
    // Physical products
    $stmt = $pdo->prepare("
        SELECT ci.quantity, p.price, p.name 
        FROM Cart_Items ci 
        JOIN Products p ON ci.product_id = p.product_id 
        WHERE ci.user_id = ?
    ");
    $stmt->execute([$user_id]);
    $items = $stmt->fetchAll();
    
    // Court bookings
    $booking_stmt = $pdo->prepare("
        SELECT b.total_price, c.name 
        FROM Bookings b 
        JOIN Courts c ON b.court_id = c.court_id 
        WHERE b.user_id = ? AND b.status = 'In Cart'
    ");
    $booking_stmt->execute([$user_id]);
    $bookings = $booking_stmt->fetchAll();
    
    $subtotal = 0;
    $paypal_items = [];
    
    foreach ($items as $item) {
        $line_total = $item['price'] * $item['quantity'];
        $subtotal += $line_total;
        $paypal_items[] = [
            'name' => $item['name'],
            'unit_amount' => ['currency_code' => 'PHP', 'value' => number_format($item['price'], 2, '.', '')],
            'quantity' => (string)$item['quantity'],
        ];
    }
    
    foreach ($bookings as $booking) {
        $subtotal += $booking['total_price'];
        $paypal_items[] = [
            'name' => "Court: " . $booking['name'],
            'unit_amount' => ['currency_code' => 'PHP', 'value' => number_format($booking['total_price'], 2, '.', '')],
            'quantity' => '1',
        ];
    }
    
    $tax_rate = (float)Config::get('PAYMENT_TAX_RATE', 0.08);
    $tax = $subtotal * $tax_rate;
    $total = $subtotal + $tax;
    
    return [
        'items' => $paypal_items,
        'subtotal' => number_format($subtotal, 2, '.', ''),
        'tax' => number_format($tax, 2, '.', ''),
        'total' => number_format($total, 2, '.', ''),
    ];
}

// Helper: Generate order number
function generateOrderNumber(): string {
    return 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

// Helper: Generate booking reference
function generateBookingRef(): string {
    return '#B-' . strtoupper(substr(uniqid(), -5));
}

// ==========================================
// ROUTES
// ==========================================
try {
    switch ($action) {
        
        // ==========================================
        // CREATE PAYPAL ORDER
        // ==========================================
        case 'create_order':
            $token = getPayPalAccessToken($paypal_client_id, $paypal_secret, $paypal_base_url);
            $cart = getCartTotal($user_id, $pdo);
            
            if (empty($cart['items'])) {
                throw new Exception('Your cart is empty');
            }
            
            // Create pending order in database
            $order_number = generateOrderNumber();
            
            $pdo->beginTransaction();
            
            // Get booking IDs for this user's cart
            $booking_stmt = $pdo->prepare("SELECT booking_id, total_price FROM Bookings WHERE user_id = ? AND status = 'In Cart'");
            $booking_stmt->execute([$user_id]);
            $cart_bookings = $booking_stmt->fetchAll();
            
            $total_booking_price = 0;
            foreach ($cart_bookings as $b) {
                $total_booking_price += $b['total_price'];
            }
            
            // Insert order
            $insert_order = $pdo->prepare("
                INSERT INTO Orders (order_number, user_id, order_date, total_amount, status, payment_method, payment_reference) 
                VALUES (?, ?, CURRENT_DATE, ?, 'Pending', 'paypal', ?)
            ");
            $insert_order->execute([$order_number, $user_id, $cart['total'], 'PENDING_PAYPAL']);
            $order_id = $pdo->lastInsertId();
            
            $pdo->commit();
            
            // Build PayPal order payload
            $payload = [
                'intent' => 'CAPTURE',
                'purchase_units' => [
                    [
                        'reference_id' => $order_number,
                        'description' => 'HI-POWER Order #' . $order_number,
                        'amount' => [
                            'currency_code' => 'PHP',
                            'value' => $cart['total'],
                            'breakdown' => [
                                'item_total' => [
                                    'currency_code' => 'PHP',
                                    'value' => $cart['subtotal'],
                                ],
                                'tax_total' => [
                                    'currency_code' => 'PHP',
                                    'value' => $cart['tax'],
                                ],
                            ],
                        ],
                        'items' => $cart['items'],
                    ],
                ],
                'payment_source' => [
                    'paypal' => [
                        'experience_context' => [
                            'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED',
                            'brand_name' => 'HI-POWER',
                            'locale' => 'en-PH',
                            'landing_page' => 'BILLING',
                            'shipping_preference' => 'NO_SHIPPING',
                            'user_action' => 'PAY_NOW',
                        ],
                    ],
                ],
            ];
            
            $ch = curl_init("{$paypal_base_url}/v2/checkout/orders");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    "Authorization: Bearer {$token}",
                ],
            ]);
            $response = json_decode(curl_exec($ch), true);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($http_code !== 201 || !isset($response['id'])) {
                throw new RuntimeException('PayPal order creation failed: ' . ($response['message'] ?? 'Unknown error'));
            }
            
            // Store PayPal order ID with our order
            $update_stmt = $pdo->prepare("UPDATE Orders SET payment_reference = ? WHERE order_id = ?");
            $update_stmt->execute([$response['id'], $order_id]);
            
            echo json_encode([
                'success' => true,
                'order_id' => $response['id'],
                'db_order_id' => $order_id,
            ]);
            break;
        
        // ==========================================
        // CAPTURE PAYPAL ORDER
        // ==========================================
        case 'capture_order':
            $paypal_order_id = $_POST['order_id'] ?? '';
            $db_order_id = $_POST['db_order_id'] ?? '';
            
            if (empty($paypal_order_id) || empty($db_order_id)) {
                throw new Exception('Missing order ID');
            }
            
            $token = getPayPalAccessToken($paypal_client_id, $paypal_secret, $paypal_base_url);
            
            // Capture the payment
            $ch = curl_init("{$paypal_base_url}/v2/checkout/orders/{$paypal_order_id}/capture");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    "Authorization: Bearer {$token}",
                ],
            ]);
            $response = json_decode(curl_exec($ch), true);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($http_code !== 201 && $http_code !== 200) {
                throw new RuntimeException('PayPal capture failed: ' . ($response['message'] ?? 'Unknown error'));
            }
            
            // Get transaction details
            $capture = $response['purchase_units'][0]['payments']['captures'][0] ?? null;
            if (!$capture || $capture['status'] !== 'COMPLETED') {
                throw new RuntimeException('Payment not completed. Status: ' . ($capture['status'] ?? 'unknown'));
            }
            
            $txn_id = $capture['id'];
            
            // Update database
            $pdo->beginTransaction();
            
            // Update order status
            $order_stmt = $pdo->prepare("UPDATE Orders SET status = 'Confirmed', payment_reference = ? WHERE order_id = ? AND status = 'Pending'");
            $order_stmt->execute([$txn_id, $db_order_id]);
            
            // Update bookings
            $booking_stmt = $pdo->prepare("UPDATE Bookings SET status = 'Confirmed' WHERE user_id = ? AND status = 'In Cart'");
            $booking_stmt->execute([$user_id]);
            
            // Clear cart
            $pdo->prepare("DELETE FROM Cart_Items WHERE user_id = ?")->execute([$user_id]);
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true,
                'transaction_id' => $txn_id,
                'message' => 'Payment successful!',
            ]);
            break;
        
        // ==========================================
        // VALIDATE PAYMENT (Check if order already paid)
        // ==========================================
        case 'validate_order':
            $db_order_id = $_POST['db_order_id'] ?? '';
            
            $stmt = $pdo->prepare("SELECT order_id, status, payment_reference FROM Orders WHERE order_id = ? AND user_id = ?");
            $stmt->execute([$db_order_id, $user_id]);
            $order = $stmt->fetch();
            
            if (!$order) {
                echo json_encode(['valid' => false, 'error' => 'Order not found']);
                exit;
            }
            
            echo json_encode([
                'valid' => true,
                'status' => $order['status'],
                'is_paid' => $order['status'] === 'Confirmed',
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
