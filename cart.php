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
$success_message = '';
$error_message = '';

// ==========================================
// 1. PROCESS CART ACTIONS (POST)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    verify_csrf_token();
    $action = $_POST['action'];

    try {
        if ($action == 'remove_item') {
            $cart_item_id = (int)$_POST['cart_item_id'];
            $stmt = $pdo->prepare("DELETE FROM Cart_Items WHERE cart_item_id = ? AND user_id = ?");
            $stmt->execute([$cart_item_id, $user_id]);
            $success_message = "Item removed from cart.";
        }
        elseif ($action == 'remove_booking') {
            $booking_id = (int)$_POST['booking_id'];
            $stmt = $pdo->prepare("DELETE FROM Bookings WHERE booking_id = ? AND user_id = ? AND status = 'In Cart'");
            $stmt->execute([$booking_id, $user_id]);
            $success_message = "Court booking removed from cart.";
        }
        elseif ($action == 'update_quantity') {
            $cart_item_id = (int)$_POST['cart_item_id'];
            $quantity     = max(1, (int)($_POST['quantity'] ?? 1));
            $quantity     = min($quantity, 99);
            $stmt = $pdo->prepare("UPDATE Cart_Items SET quantity = ? WHERE cart_item_id = ? AND user_id = ?");
            $stmt->execute([$quantity, $cart_item_id, $user_id]);
            $success_message = "Quantity updated.";
        }
        elseif (in_array($action, ['checkout_shop', 'checkout_booking', 'checkout_both'])) {
            $cart_ids    = array_values(array_filter(array_map('intval', $_POST['cart_item_ids'] ?? [])));
            $booking_ids = array_values(array_filter(array_map('intval', $_POST['booking_ids'] ?? [])));

            // Validation depends on which panel(s) are being checked out.
            if ($action == 'checkout_shop' && empty($cart_ids)) {
                throw new Exception("Select at least one shop item to check out.");
            }
            if ($action == 'checkout_booking' && empty($booking_ids)) {
                throw new Exception("Select at least one court booking to check out.");
            }
            if ($action == 'checkout_both' && (empty($cart_ids) || empty($booking_ids))) {
                throw new Exception("Select at least one shop item and one booking to check out together.");
            }

            // Verify ownership of selected cart items.
            if (!empty($cart_ids)) {
                $place = implode(',', array_fill(0, count($cart_ids), '?'));
                $chk = $pdo->prepare("SELECT COUNT(*) FROM Cart_Items WHERE user_id = ? AND cart_item_id IN ($place)");
                $chk->execute(array_merge([$user_id], $cart_ids));
                if ((int)$chk->fetchColumn() !== count($cart_ids)) {
                    throw new Exception("One or more shop items are no longer in your cart.");
                }
            }

            // Verify ownership + In Cart status of selected bookings.
            if (!empty($booking_ids)) {
                $place = implode(',', array_fill(0, count($booking_ids), '?'));
                $chk = $pdo->prepare("SELECT COUNT(*) FROM Bookings WHERE user_id = ? AND status = 'In Cart' AND booking_id IN ($place)");
                $chk->execute(array_merge([$user_id], $booking_ids));
                if ((int)$chk->fetchColumn() !== count($booking_ids)) {
                    throw new Exception("One or more court bookings are no longer in your cart.");
                }
            }

            // Store the selection, then hand off to the dedicated checkout page.
            $_SESSION['pending_checkout'] = [
                'type'        => $action, // checkout_shop | checkout_booking | checkout_both
                'cart_ids'    => $cart_ids,
                'booking_ids' => $booking_ids,
            ];
            header("Location: /checkout");
            exit;
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// ==========================================
// 2. FETCH CART DATA (Products + Bookings)
// ==========================================
$stmt = $pdo->prepare("SELECT ci.cart_item_id, ci.quantity, ci.customization, p.product_id, p.name, p.price, p.category, p.image_url, p.stock_quantity FROM Cart_Items ci JOIN Products p ON ci.product_id = p.product_id WHERE ci.user_id = ? ORDER BY ci.added_at DESC");
$stmt->execute([$user_id]);
$cart_items = $stmt->fetchAll();

$book_stmt = $pdo->prepare("SELECT b.booking_id, b.booking_reference, b.booking_date, b.start_time, b.end_time, b.total_price, c.name as court_name FROM Bookings b JOIN Courts c ON b.court_id = c.court_id WHERE b.user_id = ? AND b.status = 'In Cart' ORDER BY b.booking_date ASC, b.start_time ASC");
$book_stmt->execute([$user_id]);
$cart_bookings = $book_stmt->fetchAll();

$is_cart_empty = (empty($cart_items) && empty($cart_bookings));
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>My Cart | HI-POWER</title>
    <?php include __DIR__ . '/includes/tailwind_config.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ===== My Cart (ShuttleSync UI / mockup) ===== */
        body, h1, h2, h3, h4, h5, h6 { font-family: "Manrope", sans-serif; }
        .hp-cart{--text:#142747;--muted:#8194b3;--blue:#435bff;--blue-light:#b4baff;--orange:#ff5722;--page:#f3f5f8;--border:#dce4ef;--white:#fff;--shadow:0 10px 25px rgba(20,39,71,.06);background:var(--page);color:var(--text);font-family:"Manrope",sans-serif;font-size:14px}
        .hp-cart *{box-sizing:border-box}
        .hp-cart button,.hp-cart input{font:inherit}
        .hp-cart button{cursor:pointer}
        .hp-cart .container{width:min(980px,calc(100% - 40px));margin:32px auto 60px}
        .hp-cart .page-heading{display:flex;align-items:center;gap:12px;margin-bottom:22px}
        .hp-cart .heading-icon{width:44px;height:44px;display:grid;place-items:center;border-radius:13px;color:var(--orange);background:#ffebe5;font-size:23px}
        .hp-cart h1{font-size:28px;line-height:1.2;margin:0 0 2px;letter-spacing:-1px;font-weight:700;color:var(--text)}
        .hp-cart .subtitle{margin:0;color:#6f83a5;font-size:11px}
        .hp-cart .hp-flash{display:flex;align-items:center;gap:10px;margin-bottom:16px;padding:11px 13px;border-radius:11px;font-size:12px;font-weight:600;border:1px solid}
        .hp-cart .hp-flash.ok{background:#eaf8ef;color:#267446;border-color:#bfe9d1}
        .hp-cart .hp-flash.bad{background:#fff0eb;color:#b93c1d;border-color:#ffd3c4}
        .hp-cart .card{background:var(--white);border:1px solid var(--border);border-radius:13px;box-shadow:var(--shadow)}
        .hp-cart .layout{display:grid;grid-template-columns:minmax(0,640px) minmax(270px,300px);gap:20px;align-items:start}
        .hp-cart .tabs{display:inline-flex;background:var(--white);border:1px solid var(--border);padding:3px;border-radius:10px;margin-bottom:34px;box-shadow:0 4px 12px rgba(20,39,71,.025)}
        .hp-cart .tab{border:0;background:transparent;color:var(--text);padding:10px 16px;border-radius:7px;font-size:11px;font-weight:600;display:flex;align-items:center;gap:8px}
        .hp-cart .tab.active{color:#fff;background:var(--blue);box-shadow:0 4px 9px rgba(67,91,255,.22)}
        .hp-cart .count{min-width:15px;height:17px;padding:0 4px;display:inline-grid;place-items:center;border-radius:5px;background:rgba(125,145,170,.15);font-size:9px}
        .hp-cart .tab.active .count{background:rgba(255,255,255,.2)}
        .hp-cart .section-title{display:flex;align-items:center;justify-content:space-between;margin:0 0 10px;color:#8498b8;font-size:11px;letter-spacing:1px;font-weight:600;text-transform:uppercase}
        .hp-cart .select-all{border:0;background:none;color:var(--orange);font-size:10px;padding:4px 0}
        .hp-cart .product-card{display:grid;grid-template-columns:20px 76px minmax(120px,1fr) auto;gap:12px;align-items:center;padding:15px 13px;margin:0 0 10px;cursor:pointer;transition:border-color .2s,background .2s}
        .hp-cart .product-card:hover{border-color:rgba(255,77,28,.35)}
        .hp-cart .product-card.selected{border-color:rgba(255,77,28,.5);background:rgba(255,77,28,.03)}
        .hp-cart .check{width:16px;height:16px;accent-color:var(--blue);cursor:pointer;margin:0}
        .hp-cart .product-image{width:76px;height:76px;background:#f1f6fb;border-radius:10px;display:grid;place-items:center;overflow:hidden;color:#90a4bf;font-size:38px}
        .hp-cart .product-image img{width:100%;height:100%;object-fit:contain}
        .hp-cart .product-name{font-size:13px;font-weight:600;margin:0 0 2px;color:var(--text)}
        .hp-cart .product-category{margin:0;color:var(--muted);font-size:10px}
        .hp-cart .tag{display:inline-block;margin-top:6px;font-size:9px;letter-spacing:.6px;text-transform:uppercase;font-weight:700;color:var(--orange);background:#ffebe5;border-radius:5px;padding:3px 7px}
        .hp-cart .quantity-row{display:flex;align-items:center;gap:10px;margin-top:17px;color:#7c91b2;font-size:10px}
        .hp-cart .qty-controls{display:flex;align-items:center;border-radius:8px;overflow:hidden;background:#f0f3f7}
        .hp-cart .qty-controls button{width:29px;height:28px;border:0;background:#e9eef5;color:#516989;font-size:16px;font-weight:600;line-height:1}
        .hp-cart .qty-value{width:34px;text-align:center;color:var(--text);font-weight:600;font-size:11px}
        .hp-cart .price-col{text-align:right;display:flex;flex-direction:column;align-items:flex-end;justify-content:space-between;align-self:stretch}
        .hp-cart .price{color:var(--orange);font-size:15px;font-weight:700;white-space:nowrap}
        .hp-cart .each-price{color:var(--muted);font-size:9px;white-space:nowrap}
        .hp-cart .remove{color:var(--orange);background:none;border:0;font-size:10px;padding:0;font-weight:500}
        .hp-cart .subtotal-card{display:flex;justify-content:space-between;align-items:center;padding:14px 13px;margin-top:10px}
        .hp-cart .subtotal-title{margin:0 0 2px;font-size:12px;font-weight:600}
        .hp-cart .subtotal-note{margin:0;color:var(--muted);font-size:9px}
        .hp-cart .subtotal-amount{color:var(--orange);font-size:16px;font-weight:700}
        .hp-cart .checkout{padding:18px;position:sticky;top:84px}
        .hp-cart .checkout-heading{display:flex;align-items:center;gap:9px;margin:0 0 20px;font-size:15px;font-weight:600;color:var(--text)}
        .hp-cart .checkout-icon{color:var(--orange);font-size:19px}
        .hp-cart .summary-row{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:11px 0;color:#7185a5;font-size:11px}
        .hp-cart .summary-row strong{color:var(--text);font-weight:600}
        .hp-cart .divider{border:0;border-top:1px solid #edf0f5;margin:14px 0}
        .hp-cart .combined{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0 0 12px;font-size:12px;font-weight:600;color:var(--text)}
        .hp-cart .combined strong{color:var(--orange);font-size:19px}
        .hp-cart .checkout-note{color:#8799b6;font-size:9px;line-height:1.7;margin:0 0 14px}
        .hp-cart .checkout-btn{width:100%;border-radius:9px;min-height:39px;padding:9px 10px;font-size:10px;font-weight:600;display:flex;justify-content:center;align-items:center;gap:8px;transition:transform .15s,opacity .15s}
        .hp-cart .checkout-btn:hover:not(:disabled){transform:translateY(-1px)}
        .hp-cart .checkout-btn:disabled{cursor:not-allowed;opacity:.48}
        .hp-cart .primary{background:var(--blue);border:1px solid var(--blue);color:#fff;box-shadow:0 4px 9px rgba(67,91,255,.16)}
        .hp-cart .outline{background:var(--white);color:var(--blue);border:1px solid #8794ff;margin-top:9px}
        .hp-cart .combine-divider{display:flex;align-items:center;gap:9px;margin:13px 0 10px;color:#8b9bb5;font-size:8px;font-weight:500;white-space:nowrap}
        .hp-cart .combine-divider::before,.hp-cart .combine-divider::after{content:"";height:1px;background:#e3e8f0;flex:1}
        .hp-cart .dark-btn{background:#9da1aa;color:#fff;border:1px solid #9da1aa}
        .hp-cart .checkout-foot{text-align:center;margin:10px 0 0;color:#8a9bb6;font-size:8px;line-height:1.6}
        .hp-cart .empty{padding:30px 18px;text-align:center;color:var(--muted);font-size:12px}
        .hp-cart .empty strong{display:block;color:var(--text);font-size:15px;margin-bottom:6px}
        .hp-cart .empty a{color:var(--orange);font-weight:700;text-decoration:none}
        .hp-cart .empty-lg{padding:52px 20px}
        .hp-cart .empty-lg .empty-icon{font-size:40px;color:#c3cfdd;margin-bottom:10px}
        .hp-cart .empty-lg .empty-actions{display:flex;flex-wrap:wrap;justify-content:center;gap:10px;margin-top:18px}
        .hp-cart .empty-lg .empty-actions a{border-radius:9px;padding:11px 18px;font-size:12px;font-weight:700;text-decoration:none}
        .hp-cart .empty-lg .empty-actions .solid{background:var(--orange);color:#fff}
        .hp-cart .empty-lg .empty-actions .ghost{border:1px solid var(--border);color:#5b6f8f;background:#fff}
        @media (max-width:820px){
            .hp-cart .container{width:calc(100% - 32px);margin:22px auto 44px}
            .hp-cart .layout{grid-template-columns:1fr;gap:16px}
            .hp-cart .checkout{grid-row:1;position:static;top:auto}
            .hp-cart .tabs{margin-bottom:22px}
        }
        @media (max-width:520px){
            .hp-cart .container{width:calc(100% - 24px);margin-top:22px}
            .hp-cart h1{font-size:24px}
            .hp-cart .subtitle{font-size:10px}
            .hp-cart .product-card{grid-template-columns:18px 62px minmax(90px,1fr);gap:9px;padding:12px 10px}
            .hp-cart .product-image{width:62px;height:70px}
            .hp-cart .price-col{grid-column:3;flex-direction:row;align-items:center;margin-top:7px}
            .hp-cart .quantity-row{margin-top:12px}
            .hp-cart .price{font-size:13px}
            .hp-cart .tab{padding:9px 11px;font-size:10px}
            .hp-cart .checkout{padding:15px}
        }
    </style>
</head>
<body class="bg-background text-on-background min-h-screen flex flex-col pb-20 md:pb-0">

<form id="actionForm" method="POST" action="cart" style="display: none;">
    <input type="hidden" name="action" id="actionName">
    <input type="hidden" name="cart_item_id" id="actionCartItemId">
    <input type="hidden" name="booking_id" id="actionBookingId">
    <input type="hidden" name="quantity" id="actionQuantity">
    <?php echo get_csrf_input(); ?>
</form>

<form id="checkoutForm" method="POST" action="cart" style="display: none;">
    <input type="hidden" name="action" id="checkoutActionName">
    <input type="hidden" name="cart_item_ids[]" id="checkoutCartIds">
    <input type="hidden" name="booking_ids[]" id="checkoutBookingIds">
    <?php echo get_csrf_input(); ?>
</form>

<?php include __DIR__ . '/includes/nav_bar.php'; ?>

<main class="hp-cart flex-grow w-full">
    <div class="container">

        <!-- Page heading -->
        <div class="page-heading">
            <div class="heading-icon" aria-hidden="true">&#128722;</div>
            <div>
                <h1>My Cart</h1>
                <p class="subtitle">Shop items and court bookings are checked out separately</p>
            </div>
        </div>

        <!-- Flash messages -->
        <?php if (!empty($success_message)): ?>
            <div class="hp-flash ok"><span aria-hidden="true">&#10003;</span> <?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="hp-flash bad"><span aria-hidden="true">!</span> <?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <?php if (!$is_cart_empty): ?>

            <!-- Split-panel tabs -->
            <div class="tabs" role="tablist" aria-label="Cart sections">
                <button type="button" class="tab active" onclick="switchTab('shop')" id="tabShopBtn" role="tab" aria-selected="true">
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">shopping_bag</span> Shop Items
                    <span class="count" id="shopCountPill"><?php echo count($cart_items); ?></span>
                </button>
                <button type="button" class="tab" onclick="switchTab('booking')" id="tabBookingBtn" role="tab" aria-selected="false">
                    <span class="material-symbols-outlined text-[16px]" aria-hidden="true">calendar_month</span> Court Bookings
                    <span class="count" id="bookingCountPill"><?php echo count($cart_bookings); ?></span>
                </button>
            </div>

            <div class="layout">

                <!-- ============ LEFT: CART PANELS ============ -->
                <div>

                    <!-- SHOP PANEL -->
                    <section id="shopPanel">
                        <div class="section-title">
                            <span>&#9636; Shop Items (<?php echo count($cart_items); ?>)</span>
                            <?php if (!empty($cart_items)): ?>
                                <button type="button" onclick="toggleAll('shop')" class="select-all" id="shopToggleAll">Select all</button>
                            <?php endif; ?>
                        </div>

                        <?php if (empty($cart_items)): ?>
                            <div class="card empty">
                                <strong>No shop items in your cart</strong>
                                Your rackets and gear will appear here. <a href="ecommerce">Browse gear &rarr;</a>
                            </div>
                        <?php else: ?>
                            <?php foreach ($cart_items as $item):
                                $images = json_decode($item['image_url'], true);
                                $img = (is_array($images) && count($images) > 0) ? $images[0] : ($item['image_url'] ?? '');
                                $img_url = (!empty($img) && file_exists($img)) ? htmlspecialchars($img) : "https://picsum.photos/seed/prod_" . $item['product_id'] . "/300/300";
                                $line_total = $item['price'] * $item['quantity'];
                            ?>
                            <article class="card product-card item-card cart-item-row" data-type="shop" data-cart-id="<?php echo $item['cart_item_id']; ?>" data-amount="<?php echo number_format($line_total, 2, '.', ''); ?>" data-stock="<?php echo (int)$item['stock_quantity']; ?>">
                                <input type="checkbox" class="check shop-check" aria-label="Select <?php echo htmlspecialchars($item['name']); ?>" data-cart-id="<?php echo $item['cart_item_id']; ?>" onchange="refreshTotals()">
                                <div class="product-image">
                                    <img alt="<?php echo htmlspecialchars($item['name']); ?>" src="<?php echo $img_url; ?>" onerror="this.onerror=null;this.src='https://picsum.photos/seed/prod_<?php echo (int)$item['product_id']; ?>/300/300';"/>
                                </div>
                                <div class="product-info">
                                    <p class="product-name"><?php echo htmlspecialchars($item['name']); ?></p>
                                    <p class="product-category"><?php echo htmlspecialchars($item['category']); ?></p>
                                    <?php if (!empty($item['customization'])): ?>
                                        <span class="tag"><?php echo htmlspecialchars($item['customization']); ?></span>
                                    <?php endif; ?>
                                    <div class="quantity-row">
                                        <span>QTY</span>
                                        <div class="qty-controls">
                                            <button type="button" class="qty-btn" onclick="updateQty(<?php echo $item['cart_item_id']; ?>, -1)" aria-label="Decrease quantity">&#8722;</button>
                                            <span class="qty-value" id="qty-<?php echo $item['cart_item_id']; ?>"><?php echo (int)$item['quantity']; ?></span>
                                            <button type="button" class="qty-btn" onclick="updateQty(<?php echo $item['cart_item_id']; ?>, 1)" aria-label="Increase quantity">+</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="price-col">
                                    <div>
                                        <div class="price" data-line-total="<?php echo $item['cart_item_id']; ?>">&#8369;<?php echo number_format($line_total, 2); ?></div>
                                        <div class="each-price">&#8369;<?php echo number_format($item['price'], 2); ?> each</div>
                                    </div>
                                    <button type="button" class="remove" onclick="removeCart('remove_item', <?php echo $item['cart_item_id']; ?>, null)"><span class="material-symbols-outlined text-[14px]">delete</span> &nbsp;REMOVE</button>
                                </div>
                            </article>
                            <?php endforeach; ?>

                            <div class="card subtotal-card">
                                <div>
                                    <p class="subtotal-title">Selected Shop Subtotal</p>
                                    <p class="subtotal-note">8% tax is applied at checkout.</p>
                                </div>
                                <div class="subtotal-amount" id="shopSubtotal">&#8369;0.00</div>
                            </div>
                        <?php endif; ?>
                    </section>

                    <!-- BOOKING PANEL -->
                    <section id="bookingPanel" class="hidden">
                        <div class="section-title">
                            <span>&#9638; Court Bookings (<?php echo count($cart_bookings); ?>)</span>
                            <?php if (!empty($cart_bookings)): ?>
                                <button type="button" onclick="toggleAll('booking')" class="select-all" id="bookingToggleAll">Select all</button>
                            <?php endif; ?>
                        </div>

                        <?php if (empty($cart_bookings)): ?>
                            <div class="card empty">
                                <strong>No court bookings in your cart</strong>
                                Pick a court and a time slot to start. <a href="courtbooking">Book a court &rarr;</a>
                            </div>
                        <?php else: ?>
                            <?php foreach ($cart_bookings as $booking): ?>
                            <article class="card product-card item-card cart-item-row" data-type="booking" data-booking-id="<?php echo $booking['booking_id']; ?>" data-amount="<?php echo number_format($booking['total_price'], 2, '.', ''); ?>">
                                <input type="checkbox" class="check booking-check" aria-label="Select <?php echo htmlspecialchars($booking['court_name']); ?>" data-booking-id="<?php echo $booking['booking_id']; ?>" onchange="refreshTotals()">
                                <div class="product-image">&#9638;</div>
                                <div class="product-info">
                                    <p class="product-name"><?php echo htmlspecialchars($booking['court_name']); ?></p>
                                    <p class="product-category">
                                        <?php echo date('M d, Y', strtotime($booking['booking_date'])); ?>
                                        &middot; <?php echo date('h:i A', strtotime($booking['start_time'])) . ' &ndash; ' . date('h:i A', strtotime($booking['end_time'])); ?>
                                    </p>
                                    <div class="quantity-row">
                                        <span>REF</span>
                                        <span class="qty-value" style="width:auto;font-size:11px;"><?php echo htmlspecialchars($booking['booking_reference']); ?></span>
                                    </div>
                                </div>
                                <div class="price-col">
                                    <div>
                                        <div class="price">&#8369;<?php echo number_format($booking['total_price'], 2); ?></div>
                                        <div class="each-price">1 court slot</div>
                                    </div>
                                    <button type="button" class="remove" onclick="removeCart('remove_booking', null, <?php echo $booking['booking_id']; ?>)"><span class="material-symbols-outlined text-[14px]">delete</span> &nbsp;REMOVE</button>
                                </div>
                            </article>
                            <?php endforeach; ?>

                            <div class="card subtotal-card">
                                <div>
                                    <p class="subtotal-title">Selected Bookings Subtotal</p>
                                    <p class="subtotal-note">8% tax is applied at checkout.</p>
                                </div>
                                <div class="subtotal-amount" id="bookingSubtotal">&#8369;0.00</div>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>

                <!-- ============ RIGHT: CHECKOUT ============ -->
                <aside class="card checkout" aria-label="Checkout summary">
                    <h2 class="checkout-heading"><span class="checkout-icon material-symbols-outlined text-[18px]" aria-hidden="true">shopping_cart</span> Checkout</h2>

                    <div class="summary-row"><span>Shop items selected</span><strong id="shopSelCount">0</strong></div>
                    <div class="summary-row"><span>Bookings selected</span><strong id="bookingSelCount">0</strong></div>
                    <hr class="divider">
                    <div class="combined"><span>Combined Subtotal</span><strong id="grandSelected">&#8369;0.00</strong></div>
                    <p class="checkout-note">Checkout is done through bank transfer. Shop items are store pickup; bookings are confirmed only after payment verification.</p>

                    <button type="button" onclick="doCheckout('checkout_shop')" id="btnShopCheckout" disabled class="checkout-btn primary"><span class="material-symbols-outlined text-[16px]">shopping_cart</span> &nbsp; Checkout Shop Items</button>
                    <button type="button" onclick="doCheckout('checkout_booking')" id="btnBookingCheckout" disabled class="checkout-btn outline"><span class="material-symbols-outlined text-[16px]">calendar_month</span> &nbsp; Checkout Bookings</button>

                    <div id="bothWrapper" class="hidden">
                        <div class="combine-divider">OR COMBINE</div>
                        <button type="button" onclick="doCheckout('checkout_both')" id="btnBothCheckout" disabled class="checkout-btn dark-btn"><span class="material-symbols-outlined text-[16px]">call_merge</span> &nbsp; Checkout Both Together</button>
                        <p class="checkout-foot">One bank transfer, one receipt for your shop items &amp; court bookings.</p>
                    </div>
                </aside>
            </div>

        <?php else: ?>
            <!-- Empty cart -->
            <div class="card empty empty-lg">
                <div class="empty-icon" aria-hidden="true">&#128722;</div>
                <strong>Your cart is empty</strong>
                Add court reservations or gear to get started with your order.
                <div class="empty-actions">
                    <a href="ecommerce" class="solid"><span class="material-symbols-outlined text-[14px]">shopping_bag</span> Shop Gear>
                    <a href="courtbooking" class="ghost"><span class="material-symbols-outlined text-[14px]">calendar_month</span> Book Court>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php include __DIR__ . '/includes/mobile_nav.php'; ?>
<script>
    let shopIds = new Set();
    let bookingIds = new Set();
    let currentTab = 'shop';

    function switchTab(tab) {
        currentTab = tab;
        const shopPanel = document.getElementById('shopPanel');
        const bookingPanel = document.getElementById('bookingPanel');
        const tabShopBtn = document.getElementById('tabShopBtn');
        const tabBookingBtn = document.getElementById('tabBookingBtn');
        if (shopPanel) shopPanel.classList.toggle('hidden', tab !== 'shop');
        if (bookingPanel) bookingPanel.classList.toggle('hidden', tab !== 'booking');
        if (tabShopBtn) tabShopBtn.classList.toggle('active', tab === 'shop');
        if (tabBookingBtn) tabBookingBtn.classList.toggle('active', tab === 'booking');
    }

    function refreshTotals() {
        shopIds.clear();
        bookingIds.clear();
        let shopSub = 0, bookingSub = 0;

        document.querySelectorAll('.shop-check:checked').forEach(cb => {
            shopIds.add(Number(cb.dataset.cartId));
            const row = cb.closest('.cart-item-row');
            shopSub += parseFloat(row.dataset.amount || 0);
        });
        document.querySelectorAll('.booking-check:checked').forEach(cb => {
            bookingIds.add(Number(cb.dataset.bookingId));
            const row = cb.closest('.cart-item-row');
            bookingSub += parseFloat(row.dataset.amount || 0);
        });

        const setHtml = (id, val) => { const el = document.getElementById(id); if (el) el.innerHTML = val; };
        const setDis = (id, dis) => { const el = document.getElementById(id); if (el) el.disabled = dis; };

        setHtml('shopSubtotal', '&#8369;' + shopSub.toFixed(2));
        setHtml('bookingSubtotal', '&#8369;' + bookingSub.toFixed(2));
        setHtml('grandSelected', '&#8369;' + (shopSub + bookingSub).toFixed(2));
        const sc = document.getElementById('shopSelCount'); if (sc) sc.textContent = shopIds.size;
        const bc = document.getElementById('bookingSelCount'); if (bc) bc.textContent = bookingIds.size;

        setDis('btnShopCheckout', shopIds.size === 0);
        setDis('btnBookingCheckout', bookingIds.size === 0);

        const hasBoth = document.querySelectorAll('.shop-check').length > 0 && document.querySelectorAll('.booking-check').length > 0;
        const bothWrapper = document.getElementById('bothWrapper');
        if (bothWrapper) bothWrapper.classList.toggle('hidden', !hasBoth);
        setDis('btnBothCheckout', shopIds.size === 0 || bookingIds.size === 0);

        syncRowHighlight();
        syncSelectAllLabels();
    }

    function syncRowHighlight() {
        document.querySelectorAll('.shop-check').forEach(cb => {
            cb.closest('.cart-item-row').classList.toggle('selected', cb.checked);
        });
        document.querySelectorAll('.booking-check').forEach(cb => {
            cb.closest('.cart-item-row').classList.toggle('selected', cb.checked);
        });
    }

    function syncSelectAllLabels() {
        const shopCbs = document.querySelectorAll('.shop-check');
        const bookCbs = document.querySelectorAll('.booking-check');
        const shopAll = shopCbs.length > 0 && shopIds.size === shopCbs.length;
        const bookAll = bookCbs.length > 0 && bookingIds.size === bookCbs.length;
        const shopBtn = document.getElementById('shopToggleAll');
        const bookBtn = document.getElementById('bookingToggleAll');
        if (shopBtn) shopBtn.textContent = shopAll ? 'Deselect all' : 'Select all';
        if (bookBtn) bookBtn.textContent = bookAll ? 'Deselect all' : 'Select all';
    }

    function toggleAll(type) {
        const allChecked = type === 'shop'
            ? (shopIds.size === document.querySelectorAll('.shop-check').length && shopIds.size > 0)
            : (bookingIds.size === document.querySelectorAll('.booking-check').length && bookingIds.size > 0);
        const state = !allChecked;
        (type === 'shop' ? document.querySelectorAll('.shop-check') : document.querySelectorAll('.booking-check'))
            .forEach(cb => { cb.checked = state; });
        refreshTotals();
    }

    function updateQty(cartItemId, delta) {
        const el = document.getElementById('qty-' + cartItemId);
        let qty = (parseInt(el.textContent) || 1) + delta;
        const row = document.querySelector('.cart-item-row[data-cart-id="' + cartItemId + '"]');
        const maxStock = parseInt(row ? row.dataset.stock : 99);
        if (qty < 1) qty = 1;
        if (qty > maxStock) { alert('Only ' + maxStock + ' in stock.'); qty = maxStock; }
        if (qty > 99) qty = 99;
        document.getElementById('actionName').value = 'update_quantity';
        document.getElementById('actionCartItemId').value = cartItemId;
        document.getElementById('actionQuantity').value = qty;
        document.getElementById('actionForm').submit();
    }

    function removeCart(action, cartItemId, bookingId) {
        const label = action === 'remove_item' ? 'Remove this product from your cart?' : 'Remove this court booking from your cart?';
        if (confirm(label)) {
            document.getElementById('actionName').value = action;
            document.getElementById('actionCartItemId').value = cartItemId || '';
            document.getElementById('actionBookingId').value = bookingId || '';
            document.getElementById('actionForm').submit();
        }
    }

    function doCheckout(action) {
        const cartIds = Array.from(shopIds);
        const bookingIdsArr = Array.from(bookingIds);

        if (action === 'checkout_shop' && cartIds.length === 0) { alert('Select at least one shop item.'); return; }
        if (action === 'checkout_booking' && bookingIdsArr.length === 0) { alert('Select at least one court booking.'); return; }
        if (action === 'checkout_both' && (cartIds.length === 0 || bookingIdsArr.length === 0)) { alert('Select at least one shop item and one booking.'); return; }

        document.getElementById('checkoutActionName').value = action;
        document.getElementById('checkoutCartIds').value = cartIds.join(',');
        document.getElementById('checkoutBookingIds').value = bookingIdsArr.join(',');
        document.getElementById('checkoutForm').submit();
    }

    // Clicking anywhere on an item row toggles its selection checkbox
    function wireRowClick() {
        document.querySelectorAll('.cart-item-row').forEach(row => {
            row.addEventListener('click', (e) => {
                if (e.target.closest('button, input, a, select, .qty-btn')) return;
                const cb = row.querySelector('input[type=checkbox]');
                if (cb) { cb.checked = !cb.checked; refreshTotals(); }
            });
        });
    }

    // Initial tab state + totals
    document.addEventListener('DOMContentLoaded', () => {
        if (document.getElementById('shopPanel')) switchTab('shop');
        wireRowClick();
        refreshTotals();
    });

    // Belt-and-suspenders: recalculate on any checkbox change
    document.addEventListener('change', (e) => {
        if (e.target && e.target.matches && e.target.matches('.shop-check, .booking-check')) refreshTotals();
    });
</script>
</body>
</html>
