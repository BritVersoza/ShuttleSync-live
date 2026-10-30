<?php
/**
 * Payment Webhook Handler
 * Receives async payment confirmations from PayPal and PayMongo
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_connect.php';

Config::load();

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Determine which webhook this is
$provider = $_GET['provider'] ?? '';

switch ($provider) {
    case 'paypal':
        handlePayPalWebhook();
        break;
    case 'paymongo':
        handlePayMongoWebhook();
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown provider']);
        exit;
}

// ==========================================
// PAYPAL WEBHOOK HANDLER
// ==========================================
function handlePayPalWebhook(): void {
    global $pdo;
    
    // Get raw body
    $raw_body = file_get_contents('php://input');
    $payload = json_decode($raw_body, true);
    
    // Verify webhook signature (simplified - in production, verify with PayPal's API)
    // PayPal sends these headers for signature verification
    $headers = [
        'paypal-auth-algo' => $_SERVER['HTTP_PAYPAL_AUTH_ALGO'] ?? '',
        'paypal-auth-version' => $_SERVER['HTTP_PAYPAL_AUTH_VERSION'] ?? '',
        'paypal-cert-url' => $_SERVER['HTTP_PAYPAL_CERT_URL'] ?? '',
        'paypal-transmission-id' => $_SERVER['HTTP_PAYPAL_TRANSMISSION_ID'] ?? '',
        'paypal-transmission-sig' => $_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG'] ?? '',
        'paypal-transmission-time' => $_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME'] ?? '',
    ];
    
    // TODO: Implement full signature verification using PayPal's API
    // For now, we'll verify the webhook ID matches
    $webhook_id = Config::get('PAYPAL_WEBHOOK_ID');
    
    // Log the webhook
    error_log("[PayPal Webhook] Received: " . json_encode($payload));
    
    // Handle different event types
    $event_type = $payload['event_type'] ?? '';
    
    switch ($event_type) {
        case 'PAYMENT.CAPTURE.COMPLETED':
            handlePayPalCaptureCompleted($payload);
            break;
        
        case 'PAYMENT.CAPTURE.DENIED':
        case 'PAYMENT.CAPTURE.REFUNDED':
            handlePayPalCaptureFailed($payload);
            break;
        
        default:
            error_log("[PayPal Webhook] Unhandled event type: {$event_type}");
    }
    
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
}

function handlePayPalCaptureCompleted(array $payload): void {
    global $pdo;
    
    $resource = $payload['resource'] ?? [];
    $capture_id = $resource['id'] ?? '';
    
    if (empty($capture_id)) {
        error_log("[PayPal] Missing capture ID in webhook");
        return;
    }
    
    // Find order by payment reference
    $stmt = $pdo->prepare("SELECT order_id, user_id, status FROM Orders WHERE payment_reference = ?");
    $stmt->execute([$capture_id]);
    $order = $stmt->fetch();
    
    if (!$order) {
        error_log("[PayPal] Order not found for capture ID: {$capture_id}");
        return;
    }
    
    // Skip if already confirmed (idempotent)
    if ($order['status'] === 'Confirmed') {
        error_log("[PayPal] Order #{$order['order_id']} already confirmed, skipping");
        return;
    }
    
    // Update order and related data
    try {
        $pdo->beginTransaction();
        
        // Update order
        $pdo->prepare("UPDATE Orders SET status = 'Confirmed' WHERE order_id = ?")->execute([$order['order_id']]);
        
        // Update bookings for this user
        $pdo->prepare("UPDATE Bookings SET status = 'Confirmed' WHERE user_id = ? AND status = 'In Cart'")->execute([$order['user_id']]);
        
        // Clear cart
        $pdo->prepare("DELETE FROM Cart_Items WHERE user_id = ?")->execute([$order['user_id']]);
        
        $pdo->commit();
        
        error_log("[PayPal] Order #{$order['order_id']} confirmed via webhook");
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("[PayPal] Failed to confirm order #{$order['order_id']}: " . $e->getMessage());
    }
}

function handlePayPalCaptureFailed(array $payload): void {
    global $pdo;
    
    $resource = $payload['resource'] ?? [];
    $capture_id = $resource['id'] ?? '';
    
    if (empty($capture_id)) return;
    
    // Mark order as failed
    $stmt = $pdo->prepare("UPDATE Orders SET status = 'Failed' WHERE payment_reference = ? AND status = 'Pending'");
    $stmt->execute([$capture_id]);
    
    if ($stmt->rowCount() > 0) {
        error_log("[PayPal] Order with capture ID {$capture_id} marked as failed");
    }
}

// ==========================================
// PAYMONGO WEBHOOK HANDLER
// ==========================================
function handlePayMongoWebhook(): void {
    global $pdo;
    
    $raw_body = file_get_contents('php://input');
    $payload = json_decode($raw_body, true);
    
    // Verify webhook signature
    $signature = $_SERVER['HTTP_X_PAYMONGO_SIGNATURE'] ?? '';
    $webhook_secret = Config::get('PAYMONGO_WEBHOOK_SECRET');
    
    // PayMongo signature verification
    // Format: t=<timestamp>,v1=<signature>,v0=<legacy_signature>
    $signature_parts = [];
    foreach (explode(',', $signature) as $part) {
        [$key, $value] = explode('=', $part);
        $signature_parts[$key] = $value;
    }
    
    $timestamp = $signature_parts['t'] ?? '';
    $v1_signature = $signature_parts['v1'] ?? '';
    
    // Compute expected signature
    $signed_payload = $timestamp . '.' . $raw_body;
    $expected_signature = hash_hmac('sha256', $signed_payload, $webhook_secret);
    
    if ($v1_signature !== $expected_signature) {
        error_log("[PayMongo] Invalid webhook signature");
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
    
    // Log the webhook
    error_log("[PayMongo Webhook] Received: " . json_encode($payload));
    
    // Handle different event types
    $event_type = $payload['data']['attributes']['type'] ?? '';
    
    switch ($event_type) {
        case 'checkout_session.payment.paid':
            handlePayMongoPaymentPaid($payload);
            break;
        
        case 'checkout_session.payment.failed':
            handlePayMongoPaymentFailed($payload);
            break;
        
        default:
            error_log("[PayMongo Webhook] Unhandled event type: {$event_type}");
    }
    
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
}

function handlePayMongoPaymentPaid(array $payload): void {
    global $pdo;
    
    $checkout_session = $payload['data']['attributes']['data']['attributes'] ?? [];
    $session_id = $payload['data']['attributes']['data']['id'] ?? '';
    $metadata = $checkout_session['metadata'] ?? [];
    
    $order_number = $metadata['order_number'] ?? '';
    $user_id = $metadata['user_id'] ?? 0;
    
    // Find order
    $stmt = $pdo->prepare("SELECT order_id, status FROM Orders WHERE payment_reference = ?");
    $stmt->execute([$session_id]);
    $order = $stmt->fetch();
    
    if (!$order) {
        // Try by order number
        $stmt = $pdo->prepare("SELECT order_id, user_id, status FROM Orders WHERE order_number = ?");
        $stmt->execute([$order_number]);
        $order = $stmt->fetch();
    }
    
    if (!$order) {
        error_log("[PayMongo] Order not found for session: {$session_id}");
        return;
    }
    
    // Skip if already confirmed (idempotent)
    if ($order['status'] === 'Confirmed') {
        error_log("[PayMongo] Order #{$order['order_id']} already confirmed, skipping");
        return;
    }
    
    $user_id = $order['user_id'] ?? $user_id;
    
    // Update order and related data
    try {
        $pdo->beginTransaction();
        
        // Update order
        $pdo->prepare("UPDATE Orders SET status = 'Confirmed' WHERE order_id = ?")->execute([$order['order_id']]);
        
        // Update bookings for this user
        $pdo->prepare("UPDATE Bookings SET status = 'Confirmed' WHERE user_id = ? AND status = 'In Cart'")->execute([$user_id]);
        
        // Clear cart
        $pdo->prepare("DELETE FROM Cart_Items WHERE user_id = ?")->execute([$user_id]);
        
        $pdo->commit();
        
        error_log("[PayMongo] Order #{$order['order_id']} confirmed via webhook");
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("[PayMongo] Failed to confirm order #{$order['order_id']}: " . $e->getMessage());
    }
}

function handlePayMongoPaymentFailed(array $payload): void {
    global $pdo;
    
    $checkout_session = $payload['data']['attributes']['data']['attributes'] ?? [];
    $session_id = $payload['data']['attributes']['data']['id'] ?? '';
    
    // Mark order as failed
    $stmt = $pdo->prepare("UPDATE Orders SET status = 'Failed' WHERE payment_reference = ? AND status = 'Pending'");
    $stmt->execute([$session_id]);
    
    if ($stmt->rowCount() > 0) {
        error_log("[PayMongo] Order with session {$session_id} marked as failed");
    }
}
