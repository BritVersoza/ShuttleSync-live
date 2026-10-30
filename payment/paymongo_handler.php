<?php
/**
 * PayMongo Payment Handler
 * Handles GCash payments via PayMongo Checkout Sessions API
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

// Get PayMongo credentials
$paymongo_secret = Config::get('PAYMONGO_SECRET_KEY');
$paymongo_public = Config::get('PAYMONGO_PUBLIC_KEY');
$app_url = Config::get('APP_URL', 'https://hipowerbc.xyz');

// Helper: PayMongo API request
function payMongoRequest(string $method, string $endpoint, $data = null, string $secret_key = ''): array {
    $ch = curl_init("https://api.paymongo.com/v1{$endpoint}");
    
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    
    // PayMongo uses Basic Auth with secret key
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => "{$secret_key}:",
        CURLOPT_HTTPHEADER => $headers,
    ]);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'GET') {
        // Nothing special needed
    }
    
    $response = json_decode(curl_exec($ch), true);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ['status' => $http_code, 'data' => $response];
}

// Helper: Get cart total
function getCartTotal(int $user_id, PDO $pdo): array {
    // Products
    $stmt = $pdo->prepare("
        SELECT ci.quantity, p.price, p.name, p.product_id
        FROM Cart_Items ci 
        JOIN Products p ON ci.product_id = p.product_id 
        WHERE ci.user_id = ?
    ");
    $stmt->execute([$user_id]);
    $items = $stmt->fetchAll();
    
    // Bookings
    $booking_stmt = $pdo->prepare("
        SELECT b.total_price, c.name 
        FROM Bookings b 
        JOIN Courts c ON b.court_id = c.court_id 
        WHERE b.user_id = ? AND b.status = 'In Cart'
    ");
    $booking_stmt->execute([$user_id]);
    $bookings = $booking_stmt->fetchAll();
    
    $subtotal = 0;
    $line_items = [];
    
    foreach ($items as $item) {
        $line_total = $item['price'] * $item['quantity'];
        $subtotal += $line_total;
        $line_items[] = [
            'currency' => 'PHP',
            'amount' => (int)round($item['price'] * 100), // PayMongo uses centavos
            'name' => $item['name'],
            'quantity' => (int)$item['quantity'],
        ];
    }
    
    foreach ($bookings as $booking) {
        $subtotal += $booking['total_price'];
        $line_items[] = [
            'currency' => 'PHP',
            'amount' => (int)round($booking['total_price'] * 100), // PayMongo uses centavos
            'name' => "Court: " . $booking['name'],
            'quantity' => 1,
        ];
    }
    
    $tax_rate = (float)Config::get('PAYMENT_TAX_RATE', 0.08);
    $tax = $subtotal * $tax_rate;
    $total = $subtotal + $tax;
    
    return [
        'line_items' => $line_items,
        'subtotal' => (int)round($subtotal * 100),
        'tax' => (int)round($tax * 100),
        'total' => (int)round($total * 100),
        'total_display' => number_format($total, 2, '.', ''),
    ];
}

// Helper: Generate order number
function generateOrderNumber(): string {
    return 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

// ==========================================
// ROUTES
// ==========================================
try {
    switch ($action) {
        
        // ==========================================
        // CREATE CHECKOUT SESSION
        // ==========================================
        case 'create_session':
            $payment_method = $_POST['payment_method'] ?? 'gcash'; // gcash only
            
            $cart = getCartTotal($user_id, $pdo);
            
            if (empty($cart['line_items'])) {
                throw new Exception('Your cart is empty');
            }
            
            // Create pending order in database
            $order_number = generateOrderNumber();
            
            $pdo->beginTransaction();
            
            $insert_order = $pdo->prepare("
                INSERT INTO Orders (order_number, user_id, order_date, total_amount, status, payment_method, payment_reference) 
                VALUES (?, ?, CURRENT_DATE, ?, 'Pending', ?, 'PENDING_PAYMONGO')
            ");
            $insert_order->execute([$order_number, $user_id, $cart['total'] / 100, $payment_method]);
            $order_id = $pdo->lastInsertId();
            
            $pdo->commit();
            
            // Determine payment method type for PayMongo
            if ($payment_method === 'gcash') {
                $paymongo_methods = [
                    ['type' => 'gcash', 'riminator' => (object)[
                        'name' => $_SESSION['full_name'] ?? 'Customer',
                        'email' => $_SESSION['email'] ?? '',
                        'phone' => '',
                    ]],
                ];
            } else {
                throw new Exception('Invalid payment method');
            }
            
            // Build PayMongo Checkout Session payload
            $payload = [
                'data' => [
                    'attributes' => [
                        'send_email_receipt' => false,
                        'show_description' => true,
                        'show_line_items' => true,
                        'line_items' => $cart['line_items'],
                        'payment_method_types' => $paymongo_methods,
                        'description' => "HI-POWER Order #" . $order_number,
                        'metadata' => [
                            'order_number' => $order_number,
                            'user_id' => $user_id,
                        ],
                        'success_url' => "{$app_url}/payment/paymongo_handler.php?action=payment_success&order_id={$order_id}&order_number={$order_number}",
                        'cancel_url' => "{$app_url}/cart.php?cancelled=1",
                    ],
                ],
            ];
            
            $result = payMongoRequest('POST', '/checkout_sessions', $payload, $paymongo_secret);
            
            if ($result['status'] !== 201 || !isset($result['data']['data']['attributes']['checkout_url'])) {
                $error_msg = $result['data']['errors'][0]['detail'] ?? 'Unknown PayMongo error';
                throw new RuntimeException('PayMongo session creation failed: ' . $error_msg);
            }
            
            $checkout_url = $result['data']['data']['attributes']['checkout_url'];
            $session_id = $result['data']['data']['id'];
            
            // Update order with session ID
            $update_stmt = $pdo->prepare("UPDATE Orders SET payment_reference = ? WHERE order_id = ?");
            $update_stmt->execute([$session_id, $order_id]);
            
            echo json_encode([
                'success' => true,
                'checkout_url' => $checkout_url,
                'session_id' => $session_id,
                'db_order_id' => $order_id,
                'order_number' => $order_number,
            ]);
            break;
        
        // ==========================================
        // PAYMENT SUCCESS REDIRECT
        // ==========================================
        case 'payment_success':
            $order_id = $_GET['order_id'] ?? 0;
            $order_number = $_GET['order_number'] ?? '';
            
            // Redirect to a success page
            header("Location: {$app_url}/cart.php?payment=success&order=" . urlencode($order_number));
            exit;
        
        // ==========================================
        // CHECK PAYMENT STATUS (Polling)
        // ==========================================
        case 'check_status':
            $db_order_id = $_POST['db_order_id'] ?? '';
            
            if (empty($db_order_id)) {
                throw new Exception('Missing order ID');
            }
            
            $stmt = $pdo->prepare("SELECT order_id, status, payment_reference FROM Orders WHERE order_id = ? AND user_id = ?");
            $stmt->execute([$db_order_id, $user_id]);
            $order = $stmt->fetch();
            
            if (!$order) {
                echo json_encode(['status' => 'not_found']);
                exit;
            }
            
            // If order is still pending, check PayMongo
            if ($order['status'] === 'Pending' && $order['payment_reference'] !== 'PENDING_PAYMONGO') {
                $result = payMongoRequest('GET', "/checkout_sessions/{$order['payment_reference']}", null, $paymongo_secret);
                
                if (isset($result['data']['data']['attributes']['payment_status'])) {
                    $pm_status = $result['data']['data']['attributes']['payment_status'];
                    
                    if ($pm_status === 'paid') {
                        // Update order and bookings
                        $pdo->beginTransaction();
                        
                        $pdo->prepare("UPDATE Orders SET status = 'Confirmed' WHERE order_id = ?")->execute([$db_order_id]);
                        $pdo->prepare("UPDATE Bookings SET status = 'Confirmed' WHERE user_id = ? AND status = 'In Cart'")->execute([$user_id]);
                        $pdo->prepare("DELETE FROM Cart_Items WHERE user_id = ?")->execute([$user_id]);
                        
                        $pdo->commit();
                        
                        echo json_encode(['status' => 'Confirmed']);
                        exit;
                    }
                }
            }
            
            echo json_encode(['status' => $order['status']]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
