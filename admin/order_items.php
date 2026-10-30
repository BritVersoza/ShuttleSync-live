<?php
// Admin: return line items for a single order as JSON (TC-AS-034)
require_once __DIR__ . '/../includes/session_boot.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true
    || ($_SESSION['role'] !== 'Admin' && $_SESSION['role'] !== 'Head Manager')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../includes/database_connect.php';

$order_id = (int)($_GET['order_id'] ?? 0);
if ($order_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid order id']);
    exit;
}

try {
    $ost = $pdo->prepare("
        SELECT o.order_id, o.order_number, o.order_date, o.total_amount, o.status, u.full_name
        FROM Orders o JOIN Users u ON o.user_id = u.user_id
        WHERE o.order_id = ?
    ");
    $ost->execute([$order_id]);
    $order = $ost->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['ok' => false, 'error' => 'Order not found']);
        exit;
    }

    // fulfillment date (column added by database_admin_upgrade.sql; tolerate absence)
    $order['fulfilled_at'] = null;
    try {
        $fst = $pdo->prepare("SELECT fulfilled_at FROM Orders WHERE order_id = ?");
        $fst->execute([$order_id]);
        $order['fulfilled_at'] = $fst->fetchColumn();
    } catch (PDOException $e) { /* column not migrated yet */ }

    $items = [];
    try {
        $ist = $pdo->prepare("SELECT description, quantity, amount FROM payment_items WHERE order_id = ? AND item_type = 'shop' ORDER BY payment_item_id ASC");
        $ist->execute([$order_id]);
        $items = $ist->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $items = [];
    }

    echo json_encode([
        'ok'    => true,
        'order' => [
            'id'           => (int)$order['order_id'],
            'number'       => 'ORD-' . str_pad($order['order_id'], 5, '0', STR_PAD_LEFT),
            'ref'          => $order['order_number'],
            'customer'     => $order['full_name'],
            'date'         => $order['order_date'],
            'status'       => $order['status'],
            'fulfilled_at' => $order['fulfilled_at'],
            'total'        => (float)$order['total_amount'],
        ],
        'items' => array_map(function ($it) {
            $qty = max(1, (int)$it['quantity']);
            return [
                'description' => $it['description'],
                'quantity'    => (int)$it['quantity'],
                'unit_price'  => round((float)$it['amount'] / $qty, 2),
                'amount'      => (float)$it['amount'],
            ];
        }, $items),
    ]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Failed to load order.']);
}
