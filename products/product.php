<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../includes/database_connect.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/csrf_helper.php';

$is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$user_id = $is_logged_in ? $_SESSION['user_id'] : null;
$success_message = '';
$error_message = '';

if (!isset($_GET['id']) || empty($_GET['id'])) { header("Location: ../ecommerce.php"); exit; }
$product_id = (int)$_GET['id'];

// Handle Add to Cart / Buy Now
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    verify_csrf_token();
    $quantity = max(1, (int)($_POST['quantity'] ?? 1));
    $custom_string = null;
    
    if (isset($_POST['variant'])) {
        $custom_string = "Variant: " . trim($_POST['variant']);
        if (!empty($_POST['custom_name'])) {
            $custom_string .= " | Name: " . strtoupper(htmlspecialchars(trim($_POST['custom_name'])));
        }
    }

    $selected_image = $_POST['selected_image'] ?? null;
    if (!empty($selected_image)) {
        $custom_string .= " | Image: " . $selected_image;
    }

    if ($is_logged_in) {
        try {
            $pdo->prepare("INSERT INTO Cart_Items (user_id, product_id, quantity, customization) VALUES (?, ?, ?, ?)")->execute([$user_id, $product_id, $quantity, $custom_string]);
        } catch (PDOException $e) { $error_message = "Could not add item to cart."; }
    } else {
        if (!isset($_SESSION['guest_cart_items'])) $_SESSION['guest_cart_items'] = [];
        $_SESSION['guest_cart_items'][] = ['product_id'=>$product_id, 'quantity'=>$quantity, 'customization'=>$custom_string, 'image'=>$selected_image];
    }
    
    if (empty($error_message)) {
        if ($_POST['action'] === 'buy_now') { header("Location: ../cart.php"); exit; }
        else { $success_message = "Item added to your cart!"; }
    }
}

// Fetch product
try {
    $stmt = $pdo->prepare("SELECT * FROM Products WHERE product_id = ? AND is_active = TRUE");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();
    if (!$product) { header("Location: ../ecommerce.php"); exit; }
} catch (PDOException $e) { header("Location: ../ecommerce.php"); exit; }

$is_out_of_stock = $product['stock_quantity'] <= 0;
$is_low_stock = $product['stock_quantity'] > 0 && $product['stock_quantity'] <= 5;
$max_stock = max(1, (int)$product['stock_quantity']);

// Parse dynamic variants from database if available
$product_variants = json_decode($product['variants'] ?? '', true);

// Parse images (Gawing default ang variant image tulad ng Sky Blue kung meron man sa variants)
$images = [];
$decoded = json_decode($product['image_url'], true);
if (is_array($decoded) && count($decoded) > 0) { $images = $decoded; }
elseif (!empty($product['image_url'])) { $images[] = $product['image_url']; }
else { $images[] = "https://picsum.photos/seed/prod_".$product_id."/800/800"; }

// Kung may variants options at may naka-set na image sa unang variant (o kaya hanapin ang Sky Blue), i-unahin ito sa images array
if (!empty($product_variants) && isset($product_variants['options']) && is_array($product_variants['options'])) {
    foreach ($product_variants['options'] as $opt) {
        if (is_array($opt) && isset($opt['name'], $opt['image']) && stripos($opt['name'], 'Sky Blue') !== false) {
            // Ilagay ang Sky Blue image sa unahan ng array para maging default
            array_unshift($images, $opt['image']);
            $images = array_unique($images); // Iwasan ang duplicate
            break;
        }
    }
}

// Related products
$related = [];
try {
    $rstmt = $pdo->prepare("SELECT * FROM Products WHERE category = ? AND product_id != ? AND is_active = TRUE ORDER BY RAND() LIMIT 4");
    $rstmt->execute([$product['category'], $product_id]);
    $related = $rstmt->fetchAll();
} catch (Exception $e) {}

// Cart count
$cart_count = 0;
if ($is_logged_in) {
    try { $cs = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM Cart_Items WHERE user_id = ?"); $cs->execute([$user_id]); $cart_count = (int)$cs->fetchColumn(); } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title><?php echo htmlspecialchars($product['name']); ?> | ShuttleSync Shop</title>
    <?php include __DIR__ . '/../includes/tailwind_config.php'; ?>
    <style>
        @keyframes fadeSlideUp{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:translateY(0);}}
        .anim-in{animation:fadeSlideUp .5s cubic-bezier(.22,1,.36,1) forwards;}
        .anim-d1{animation-delay:.05s;opacity:0;}
        .anim-d2{animation-delay:.1s;opacity:0;}
        .anim-d3{animation-delay:.15s;opacity:0;}
        .variant-radio:checked + label{border-color:#ff510b;background:#fff5f0;color:#ff510b;}
        .thumb-btn{border:2px solid transparent;transition:all .2s;}
        .thumb-btn.active{border-color:#ff510b;}
        .thumb-btn:hover{border-color:rgba(255,81,11,0.3);}
        .main-img-wrap{background:#f8f9fc;border-radius:20px;position:relative;overflow:hidden;}
        .main-img-wrap img{transition:opacity .3s;}
        .related-card{background:#fff;border-radius:16px;border:1px solid rgba(0,0,0,0.06);overflow:hidden;transition:all .3s;box-shadow:0 2px 8px rgba(0,0,0,0.03);}
        .related-card:hover{box-shadow:0 8px 30px rgba(0,0,0,0.08);transform:translateY(-2px);}
        .stock-bar{height:6px;border-radius:3px;background:#e8eaf0;overflow:hidden;}
        .stock-bar-fill{height:100%;border-radius:3px;transition:width .5s ease;}
    </style>
</head>
<body class="bg-surface min-h-screen antialiased">

<?php include __DIR__ . '/../includes/nav_bar.php'; ?>

<main class="max-w-6xl mx-auto w-full px-4 md:px-8 pt-24 pb-20">

    <!-- Breadcrumb -->
    <nav class="flex items-center gap-2 text-xs font-medium text-on-surface-variant/60 mb-6 anim-in">
        <a href="../index.php" class="hover:text-primary transition-colors">Home</a>
        <span class="material-symbols-outlined text-[14px]">chevron_right</span>
        <a href="../ecommerce.php" class="hover:text-primary transition-colors">Shop</a>
        <span class="material-symbols-outlined text-[14px]">chevron_right</span>
        <a href="../ecommerce.php?category=<?php echo urlencode($product['category']); ?>" class="hover:text-primary transition-colors"><?php echo htmlspecialchars($product['category']); ?></a>
        <span class="material-symbols-outlined text-[14px]">chevron_right</span>
        <span class="text-on-surface font-semibold truncate max-w-[200px]"><?php echo htmlspecialchars($product['name']); ?></span>
    </nav>

    <?php if (!empty($success_message)): ?>
        <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-2xl flex items-center gap-3 text-green-700 anim-in" id="successAlert">
            <span class="material-symbols-outlined text-lg">check_circle</span>
            <p class="font-bold text-sm flex-1"><?php echo $success_message; ?></p>
            <a href="../cart.php" class="text-xs font-bold text-primary hover:underline">View Cart →</a>
        </div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-2xl flex items-center gap-3 text-accent anim-in">
            <span class="material-symbols-outlined text-lg">error</span>
            <p class="font-bold text-sm"><?php echo $error_message; ?></p>
        </div>
    <?php endif; ?>

    <!-- Product Section -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 lg:gap-12">

        <!-- Image Gallery -->
        <div class="anim-in anim-d1">
            <div class="main-img-wrap aspect-square flex items-center justify-center p-6 mb-4">
                <?php if ($is_out_of_stock): ?>
                    <div class="absolute top-4 left-4 z-10 bg-gray-800 text-white px-3 py-1.5 rounded-lg text-[10px] font-bold uppercase tracking-widest">Out of Stock</div>
                <?php endif; ?>
                <?php if ($is_low_stock): ?>
                    <div class="absolute top-4 left-4 z-10 bg-amber-500 text-white px-3 py-1.5 rounded-lg text-[10px] font-bold uppercase tracking-widest">Only <?php echo $product['stock_quantity']; ?> Left</div>
                <?php endif; ?>
                <img id="mainImage" src="<?php echo (strpos($images[0], 'http') === 0) ? $images[0] : '../' . htmlspecialchars($images[0]); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" onerror="this.onerror=null;this.src='https://picsum.photos/seed/prod_<?php echo (int)$product_id; ?>/800/800';" class="max-w-full max-h-full object-contain mix-blend-multiply">
            </div>
            <?php if (count($images) > 1): ?>
            <div class="flex gap-2 overflow-x-auto pb-1">
                <?php foreach ($images as $idx => $img_path):
                    $fp = (strpos($img_path, 'http') === 0) ? $img_path : '../' . htmlspecialchars($img_path);
                ?>
                <button type="button" onclick="swapImage('<?php echo $fp; ?>', this)" class="thumb-btn shrink-0 w-16 h-16 rounded-xl bg-white border-2 <?php echo $idx===0 ? 'active border-primary' : 'border-outline-variant/20'; ?> overflow-hidden p-1">
                    <img src="<?php echo $fp; ?>" class="w-full h-full object-contain mix-blend-multiply">
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Product Info -->
        <div class="flex flex-col anim-in anim-d2">
            <div class="mb-1">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 bg-primary/8 text-primary rounded-md text-[10px] font-bold uppercase tracking-widest"><?php echo htmlspecialchars($product['category']); ?></span>
            </div>

            <h1 id="productName" class="text-2xl md:text-3xl font-bold text-on-surface mb-3 leading-tight"><?php echo htmlspecialchars($product['name']); ?></h1>

            <!-- Price -->
            <div class="flex items-baseline gap-3 mb-4">
                <span id="productPrice" class="text-3xl font-bold text-primary">₱<?php echo number_format($product['price'], 2); ?></span>
            </div>

            <!-- Stock Bar -->
            <div class="mb-6">
                <div class="flex items-center justify-between mb-1.5">
                    <span class="text-xs font-bold text-on-surface-variant">Availability</span>
                    <?php if ($is_out_of_stock): ?>
                        <span class="text-xs font-bold text-accent">Out of Stock</span>
                    <?php elseif ($is_low_stock): ?>
                        <span class="text-xs font-bold text-amber-600">Only <?php echo $product['stock_quantity']; ?> remaining</span>
                    <?php else: ?>
                        <span class="text-xs font-bold text-green-600">In Stock (<?php echo $product['stock_quantity']; ?>)</span>
                    <?php endif; ?>
                </div>
                <div class="stock-bar">
                    <div class="stock-bar-fill <?php echo $is_out_of_stock ? 'bg-accent' : ($is_low_stock ? 'bg-amber-500' : 'bg-green-500'); ?>" style="width:<?php echo min(100, ($product['stock_quantity'] / 50) * 100); ?>%"></div>
                </div>
            </div>

            <!-- Info Cards -->
            <div class="grid grid-cols-2 gap-3 mb-6">
                <div class="bg-surface rounded-xl p-3 flex items-start gap-2.5">
                    <span class="material-symbols-outlined text-primary text-lg mt-0.5">storefront</span>
                    <div>
                        <p class="text-[11px] font-bold text-on-surface">Pickup Only</p>
                        <p class="text-[10px] text-on-surface-variant">Collect at facility</p>
                    </div>
                </div>
                <div class="bg-surface rounded-xl p-3 flex items-start gap-2.5">
                    <span class="material-symbols-outlined text-primary text-lg mt-0.5">payments</span>
                    <div>
                        <p class="text-[11px] font-bold text-on-surface">Flexible Payment</p>
                        <p class="text-[10px] text-on-surface-variant">GCash, PayPal, Card</p>
                    </div>
                </div>
            </div>

            <!-- Main Action Buttons -->
            <div class="pt-4 border-t border-outline-variant/20 space-y-3 flex-grow flex flex-col justify-end">
                <?php if ($is_out_of_stock): ?>
                    <button type="button" disabled class="w-full h-12 bg-surface border border-outline-variant/30 text-on-surface-variant/50 rounded-xl font-bold cursor-not-allowed">Out of Stock</button>
                <?php else: ?>
                    <div class="flex gap-3">
                        <button type="button" onclick="openModal('add_to_cart')" class="flex-1 h-12 bg-surface border-2 border-primary/30 text-primary rounded-xl font-bold text-sm hover:bg-primary/10 active:scale-[0.98] transition-all flex items-center justify-center gap-2">
                            <span class="material-symbols-outlined text-[18px]">add_shopping_cart</span> Add to Cart
                        </button>
                        <button type="button" onclick="openModal('buy_now')" class="flex-1 h-12 bg-gradient-to-r from-primary to-primary-dark text-white rounded-xl font-bold text-sm shadow-lg shadow-primary/20 hover:shadow-xl active:scale-[0.98] transition-all flex items-center justify-center gap-2">
                            <span class="material-symbols-outlined text-[18px]">bolt</span> Buy Now
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Description -->
    <div class="mt-12 bg-white rounded-2xl border border-outline-variant/10 p-6 md:p-8 shadow-sm anim-in anim-d3">
        <h3 class="text-base font-bold text-on-surface mb-4 flex items-center gap-2">
            <span class="material-symbols-outlined text-primary text-lg">description</span> Product Description
        </h3>
        <div class="text-sm text-on-surface-variant leading-relaxed max-w-4xl">
            <?php echo nl2br(htmlspecialchars($product['description'] ?: 'High quality badminton gear designed for professional performance and durability on the court.')); ?>
        </div>
    </div>

    <!-- Related Products -->
    <?php if (count($related) > 0): ?>
    <div class="mt-12 anim-in anim-d4">
        <div class="flex items-center justify-between mb-5">
            <div>
                <span class="text-accent text-[11px] font-bold tracking-[0.25em] uppercase mb-1 block">You May Also Like</span>
                <h2 class="text-xl font-bold">Related Products</h2>
            </div>
            <a href="../ecommerce.php?category=<?php echo urlencode($product['category']); ?>" class="text-primary text-xs font-bold hover:underline">View All →</a>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <?php foreach ($related as $rp):
                $ri = json_decode($rp['image_url'], true);
                $rimg = (is_array($ri) && count($ri) > 0) ? $ri[0] : ($rp['image_url'] ?? '');
                if (!empty($rimg) && strpos($rimg, 'http') === 0) { $rimg_url = htmlspecialchars($rimg); }
                elseif (!empty($rimg) && file_exists(__DIR__ . '/../' . $rimg)) { $rimg_url = '../' . htmlspecialchars($rimg); }
                else { $rimg_url = "https://picsum.photos/seed/prod_".$rp['product_id']."/400/400"; }
                $r_out = $rp['stock_quantity'] <= 0;
            ?>
            <a href="product.php?id=<?php echo $rp['product_id']; ?>" class="related-card group">
                <div class="aspect-square bg-surface/50 flex items-center justify-center p-4 relative">
                    <img loading="lazy" class="w-full h-full object-contain mix-blend-multiply group-hover:scale-105 transition-transform duration-500" src="<?php echo $rimg_url; ?>" alt="<?php echo htmlspecialchars($rp['name']); ?>" onerror="this.onerror=null;this.src='https://picsum.photos/seed/prod_<?php echo (int)$rp['product_id']; ?>/400/400';">
                    <?php if ($r_out): ?>
                        <div class="absolute inset-0 bg-white/60 flex items-center justify-center"><span class="bg-gray-800 text-white text-[9px] font-bold px-3 py-1 rounded uppercase">Out of Stock</span></div>
                    <?php endif; ?>
                </div>
                <div class="p-3">
                    <p class="text-xs font-bold text-on-surface line-clamp-2 mb-1"><?php echo htmlspecialchars($rp['name']); ?></p>
                    <p class="text-sm font-bold text-primary">₱<?php echo number_format($rp['price'], 2); ?></p>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</main>

<!-- ShuttleSync Confirmation Modal -->
<div id="cartModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div class="bg-white w-full max-w-lg rounded-3xl shadow-2xl p-6 md:p-8 space-y-6 relative border border-outline-variant/20 anim-in">
        
        <!-- Close Button -->
        <button type="button" onclick="closeModal()" class="absolute right-5 top-5 w-9 h-9 rounded-full bg-surface hover:bg-outline-variant/20 flex items-center justify-center text-on-surface font-bold transition-colors">✕</button>

        <!-- Product Preview Header -->
        <div class="flex items-center gap-4 border-b border-outline-variant/20 pb-5">
            <img id="modalImg" src="" alt="Product" class="w-20 h-20 object-contain bg-surface rounded-2xl border border-outline-variant/20 p-2">
            <div>
                <h3 id="modalTitle" class="font-bold text-on-surface text-base md:text-lg">Product Name</h3>
                <p id="modalPrice" class="text-primary font-extrabold text-xl mt-1">₱0.00</p>
            </div>
        </div>

        <!-- Submission Form -->
        <form method="POST" action="product.php?id=<?php echo $product_id; ?>" class="space-y-5">
            <?php echo get_csrf_input(); ?>
            <input type="hidden" id="formAction" name="action" value="add_to_cart">
            <input type="hidden" id="modalSelectedImage" name="selected_image" value="">

            <!-- Dynamic Variant / Size / Color Selection from Database JSON -->
            <?php if (!empty($product_variants) && isset($product_variants['options'])): ?>
            <div>
                <label class="text-[11px] font-bold text-on-surface uppercase tracking-widest mb-2.5 block">Select <?php echo htmlspecialchars($product_variants['type'] ?? 'Variant'); ?></label>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($product_variants['options'] as $i => $opt): 
                        $optName = is_array($opt) ? $opt['name'] : $opt;
                        $optImg = is_array($opt) && isset($opt['image']) ? $opt['image'] : '';
                    ?>
                    <div>
                        <input type="radio" id="mopt<?php echo $i; ?>" name="variant" value="<?php echo htmlspecialchars($optName); ?>" data-img="<?php echo htmlspecialchars($optImg); ?>" class="sr-only variant-radio" required <?php echo $i===0?'checked':''; ?> onchange="updateModalImage(this)">
                        <label for="mopt<?php echo $i; ?>" class="px-4 h-11 flex items-center justify-center rounded-xl border-2 border-outline-variant/30 text-sm font-bold cursor-pointer hover:border-primary/50 transition-all"><?php echo htmlspecialchars($optName); ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php elseif ($product['category'] == 'Jersey' || stripos($product['category'], 'shoe') !== false): ?>
            <div>
                <label class="text-[11px] font-bold text-on-surface uppercase tracking-widest mb-2.5 block">Select Size / Variant</label>
                <div class="flex flex-wrap gap-2">
                    <?php
                    $sizes = ($product['category'] == 'Jersey') ? ['S','M','L','XL'] : ['US 7','US 8','US 9','US 10','US 11'];
                    foreach ($sizes as $i => $size): ?>
                    <div>
                        <input type="radio" id="msz<?php echo $i; ?>" name="variant" value="<?php echo $size; ?>" class="sr-only variant-radio" required <?php echo $i===0?'checked':''; ?>>
                        <label for="msz<?php echo $i; ?>" class="w-14 h-11 flex items-center justify-center rounded-xl border-2 border-outline-variant/30 text-sm font-bold cursor-pointer hover:border-primary/50 transition-all"><?php echo $size; ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($product['category'] == 'Jersey'): ?>
                <input type="text" name="custom_name" placeholder="Custom back name (optional, max 15 chars)" maxlength="15" class="mt-3 w-full bg-surface border border-outline-variant/30 rounded-xl px-4 py-3 text-sm focus:border-primary focus:ring-2 focus:ring-primary/10 outline-none transition-all uppercase placeholder:normal-case">
                <?php endif; ?>
            </div>
            <?php elseif ($product['category'] == 'Shuttlecock' || $product['category'] == 'Shuttlecocks'): ?>
            <div>
                <label class="text-[11px] font-bold text-on-surface uppercase tracking-widest mb-2.5 block">Select Speed / Type</label>
                <div class="flex flex-wrap gap-2">
                    <?php foreach (['Speed 76','Speed 77','Tournament'] as $i => $speed): ?>
                    <div>
                        <input type="radio" id="msp<?php echo $i; ?>" name="variant" value="<?php echo $speed; ?>" class="sr-only variant-radio" required <?php echo $i===0?'checked':''; ?>>
                        <label for="msp<?php echo $i; ?>" class="px-5 h-11 flex items-center justify-center rounded-xl border-2 border-outline-variant/30 text-sm font-bold cursor-pointer hover:border-primary/50 transition-all whitespace-nowrap"><?php echo $speed; ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div>
                <label class="text-[11px] font-bold text-on-surface uppercase tracking-widest mb-2.5 block">Select Option</label>
                <div class="flex flex-wrap gap-2">
                    <?php foreach (['Standard Edition'] as $i => $opt): ?>
                    <div>
                        <input type="radio" id="mopt<?php echo $i; ?>" name="variant" value="<?php echo $opt; ?>" class="sr-only variant-radio" required checked>
                        <label for="mopt<?php echo $i; ?>" class="px-5 h-11 flex items-center justify-center rounded-xl border-2 border-outline-variant/30 text-sm font-bold cursor-pointer hover:border-primary/50 transition-all"><?php echo $opt; ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Quantity Selector -->
            <div>
                <label class="text-[11px] font-bold text-on-surface uppercase tracking-widest mb-2.5 block">Quantity</label>
                <div class="flex items-center gap-3">
                    <div class="flex items-center bg-surface border border-outline-variant/30 rounded-xl overflow-hidden">
                        <button type="button" onclick="modalUpdateQty(-1)" class="w-11 h-11 flex items-center justify-center text-on-surface-variant hover:text-primary transition" id="modalBtnMinus">
                            <span class="material-symbols-outlined text-lg">remove</span>
                        </button>
                        <input type="number" id="modalQtyInput" name="quantity" value="1" min="1" max="<?php echo $max_stock; ?>" class="w-14 text-center bg-transparent border-none text-sm font-bold focus:ring-0 p-0" readonly>
                        <button type="button" onclick="modalUpdateQty(1)" class="w-11 h-11 flex items-center justify-center text-on-surface-variant hover:text-primary transition" id="modalBtnPlus">
                            <span class="material-symbols-outlined text-lg">add</span>
                        </button>
                    </div>
                    <span class="text-xs text-on-surface-variant/60 font-medium"><?php echo $max_stock; ?> available</span>
                </div>
            </div>

            <!-- Confirm Submit Button -->
            <button type="submit" id="modalSubmitBtn" class="w-full h-14 bg-primary hover:bg-primary-dark text-white font-bold rounded-2xl shadow-lg shadow-primary/20 transition-all flex items-center justify-center gap-2 text-base">
                <span class="material-symbols-outlined text-[22px]">shopping_cart</span> Confirm & Proceed
            </button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/mobile_nav.php'; ?>

<script>
const maxStock = <?php echo $max_stock; ?>;

function swapImage(src, btn) {
    const main = document.getElementById('mainImage');
    main.style.opacity = '0.5';
    setTimeout(() => { main.src = src; main.style.opacity = '1'; }, 150);
    document.querySelectorAll('.thumb-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}

// Modal Controls
function openModal(actionType) {
    const modal = document.getElementById('cartModal');
    const formAction = document.getElementById('formAction');
    const submitBtn = document.getElementById('modalSubmitBtn');
    
    formAction.value = actionType;
    if (actionType === 'buy_now') {
        submitBtn.innerHTML = '<span class="material-symbols-outlined text-[22px]">bolt</span> Proceed to Buy Now';
    } else {
        submitBtn.innerHTML = '<span class="material-symbols-outlined text-[22px]">shopping_cart</span> Confirm & Add to Cart';
    }

    const currentMainImageSrc = document.getElementById('mainImage').src;
    document.getElementById('modalImg').src = currentMainImageSrc;
    document.getElementById('modalSelectedImage').value = currentMainImageSrc;

    const checkedVariant = document.querySelector('.variant-radio:checked');
    if (checkedVariant && checkedVariant.dataset.img) {
        let imgPath = checkedVariant.dataset.img;
    if (!imgPath.startsWith('http')) imgPath = '../' + imgPath;
        document.getElementById('modalImg').src = imgPath;
        document.getElementById('modalSelectedImage').value = imgPath;
    }

    document.getElementById('modalTitle').textContent = document.getElementById('productName').textContent;
    document.getElementById('modalPrice').textContent = document.getElementById('productPrice').textContent;

    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function updateModalImage(radio) {
    if (radio.dataset.img) {
        let imgPath = radio.dataset.img;
        if (!imgPath.startsWith('http')) imgPath = '../' + imgPath;
        document.getElementById('modalImg').src = imgPath;
        document.getElementById('modalSelectedImage').value = imgPath;
    }
}

function closeModal() {
    const modal = document.getElementById('cartModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function modalUpdateQty(change) {
    const qtyInput = document.getElementById('modalQtyInput');
    const btnMinus = document.getElementById('modalBtnMinus');
    const btnPlus = document.getElementById('modalBtnPlus');
    
    let v = parseInt(qtyInput.value) + change;
    if (v >= 1 && v <= maxStock) {
        qtyInput.value = v;
    }
    btnMinus.disabled = qtyInput.value <= 1;
    btnPlus.disabled = qtyInput.value >= maxStock;
}

setTimeout(() => {
    const a = document.getElementById('successAlert');
    if (a) { a.style.opacity = '0'; a.style.transition = 'opacity .5s'; setTimeout(() => a.remove(), 500); }
}, 4000);
</script>

</body>
</html>