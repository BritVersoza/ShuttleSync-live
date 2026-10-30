<?php
require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/database_connect.php';
require_once __DIR__ . '/includes/csrf_helper.php';

$is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$user_id = $is_logged_in ? $_SESSION['user_id'] : null;

$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? trim($_GET['category']) : '';
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'newest';

$sql = "SELECT * FROM Products WHERE is_active = TRUE";
$params = [];

if (!empty($search_query)) {
    $sql .= " AND (name LIKE ? OR description LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}
if (!empty($category_filter)) {
    $sql .= " AND category = ?";
    $params[] = $category_filter;
}

switch ($sort) {
    case 'price_low': $sql .= " ORDER BY price ASC"; break;
    case 'price_high': $sql .= " ORDER BY price DESC"; break;
    case 'name': $sql .= " ORDER BY name ASC"; break;
    default: $sql .= " ORDER BY product_id DESC"; break;
}

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();
} catch (PDOException $e) { $products = []; }

try {
    $cat_stmt = $pdo->query("SELECT DISTINCT category FROM Products WHERE is_active = TRUE ORDER BY category ASC");
    $categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) { $categories = []; }

$cart_count = 0;
if ($is_logged_in) {
    try {
        $cart_stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM Cart_Items WHERE user_id = ?");
        $cart_stmt->execute([$user_id]);
        $cart_count = (int)$cart_stmt->fetchColumn();
    } catch (PDOException $e) {}
}

// Quick add to cart AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_add') {
    verify_csrf_token();
    header('Content-Type: application/json');
    if (!$is_logged_in) { echo json_encode(['ok'=>false,'error'=>'login_required']); exit; }
    $pid = (int)($_POST['product_id'] ?? 0);
    $qty = max(1, (int)($_POST['quantity'] ?? 1));
    try {
        $pdo->prepare("INSERT INTO Cart_Items (user_id, product_id, quantity) VALUES (?, ?, ?)")->execute([$user_id, $pid, $qty]);
        echo json_encode(['ok'=>true,'cart_count'=>$cart_count+$qty]);
    } catch (Exception $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}

$cat_icons = ['Rackets'=>'sports_tennis','Shuttlecocks'=>'sports_baseball','Shoes'=>'footprint','Jersey'=>'checkroom','Apparel'=>'checkroom','Bags'=>'backpack','Accessories'=>'diamond','Other'=>'inventory_2'];
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>Shop | HI-POWER</title>
    <?php include __DIR__ . '/includes/tailwind_config.php'; ?>
    <style>
        .shop-body {
            margin: 0;
            background: linear-gradient(120deg, #f9fbfe, #eef5fc);
            color: #10243a;
        }
        .shop-page {
            max-width: 1500px;
            margin: 0 auto;
            padding: 96px 2.6% 40px;
        }
        .shop-hero {
            min-height: 300px;
            border-radius: 16px;
            overflow: hidden;
            display: flex;
            align-items: center;
            padding: 40px;
            background: linear-gradient(90deg, #061823 0%, #061823e8 35%, #06182320 100%),
                        url('https://images.unsplash.com/photo-1626224583764-f87db24ac4ea?auto=format&fit=crop&w=1800&q=85') center 48% / cover;
            color: #fff;
        }
        .shop-eyebrow {
            color: #ff521c;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 1.8px;
            text-transform: uppercase;
        }
        .shop-hero h1 {
            font-size: clamp(32px, 4vw, 48px);
            line-height: 1.08;
            margin: 10px 0;
            color: #fff;
        }
        .shop-hero h1 span { color: #ff521c; }
        .shop-hero p {
            color: #e0eaf3;
            max-width: 420px;
            line-height: 1.6;
            margin: 0;
        }
        .shop-sectionhead { margin: 26px 4px 16px; }
        .shop-sectionhead h2 {
            font-size: 28px;
            margin: 3px 0;
            color: #10243a;
        }
        .shop-categories {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 12px;
        }
        .shop-category {
            background: #ffffffc9;
            border: 1px solid #dfe8f3;
            border-radius: 12px;
            min-height: 95px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: #10243a;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            transition: all .2s ease;
            text-decoration: none;
            text-align: center;
            padding: 10px 6px;
        }
        .shop-category .material-symbols-outlined {
            font-size: 27px;
            color: #7386a0;
            transition: color .2s ease;
        }
        .shop-cat-icon {
            font-size: 27px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #7386a0;
            transition: color .2s ease;
        }
        .shop-category.active,
        .shop-category:hover {
            border-color: #ff521c;
            box-shadow: 0 4px 16px #ff521c12;
            color: #2675c7;
        }
        .shop-category.active .material-symbols-outlined,
        .shop-category:hover .material-symbols-outlined { color: #ff521c; }
        .shop-category.active .shop-cat-icon,
        .shop-category:hover .shop-cat-icon { color: #ff521c; }
        .shop-toolbar {
            display: flex;
            gap: 14px;
            align-items: center;
            margin: 28px 4px 22px;
        }
        .shop-search {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 10px;
            background: #fff;
            border: 1px solid #dfe8f3;
            border-radius: 24px;
            padding: 13px 17px;
            max-width: 600px;
            position: relative;
        }
        .shop-search .material-symbols-outlined { color: #8295ad; font-size: 20px; }
        .shop-search input {
            border: 0;
            outline: 0;
            width: 100%;
            font-size: 13px;
            color: #10243a;
            background: transparent;
            font-family: inherit;
        }
        .shop-search input::placeholder { color: #8295ad; }
        .shop-search .shop-clear {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            width: 26px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: #f1f5f9;
            color: #64748b;
            text-decoration: none;
        }
        .shop-search .shop-clear:hover { background: #fee2e2; color: #dc2626; }
        .shop-search .shop-clear .material-symbols-outlined { font-size: 15px; color: inherit; }
        .shop-sort {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 12px;
            color: #10243a;
        }
        .shop-sort select {
            border: 1px solid #79a8ec;
            background: #fff;
            border-radius: 22px;
            padding: 12px 16px;
            color: #2675c7;
            cursor: pointer;
            font: inherit;
            font-size: 12px;
        }
        .shop-apply {
            border: 1px solid #79a8ec;
            background: #fff;
            border-radius: 22px;
            padding: 10px 14px;
            color: #2675c7;
            cursor: pointer;
            font: inherit;
            font-size: 12px;
        }
        .shop-count {
            color: #7386a0;
            font-size: 12px;
            white-space: nowrap;
        }
        .shop-products {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
        }
        .shop-card {
            background: #fff;
            border: 1px solid #e6edf5;
            border-radius: 12px;
            padding: 9px;
            box-shadow: 0 6px 20px #193a5608;
            min-width: 0;
            display: flex;
            flex-direction: column;
            transition: box-shadow .3s ease, transform .3s ease;
        }
        .shop-card:hover {
            box-shadow: 0 12px 34px #193a5614;
            transform: translateY(-3px);
        }
        .shop-photo {
            height: 190px;
            border-radius: 7px;
            background: #edf2f6;
            position: relative;
            overflow: hidden;
        }
        .shop-photo-link { display: block; width: 100%; height: 100%; }
        .shop-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            mix-blend-mode: multiply;
            transition: transform .45s ease;
        }
        .shop-card:hover .shop-photo img { transform: scale(1.05); }
        .shop-stock {
            position: absolute;
            top: 9px;
            left: 9px;
            background: #f1fff8;
            border: 1px solid #c8edda;
            color: #14623f;
            border-radius: 20px;
            padding: 5px 9px;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .03em;
            box-shadow: 0 2px 8px #0001;
            z-index: 2;
        }
        .shop-stock::before {
            content: '';
            display: inline-block;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #18b56a;
            margin-right: 5px;
        }
        .shop-stock.low { background: #fffbeb; border-color: #fde68a; color: #b45309; }
        .shop-stock.low::before { background: #f59e0b; }
        .shop-stock.out { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
        .shop-stock.out::before { background: #ef4444; }
        .shop-out-overlay {
            position: absolute;
            inset: 0;
            background: #ffffffb8;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1;
            pointer-events: none;
        }
        .shop-out-overlay span {
            background: #1f2937;
            color: #fff;
            font-size: 10px;
            font-weight: 800;
            padding: 8px 14px;
            border-radius: 8px;
            text-transform: uppercase;
            letter-spacing: .1em;
        }
        .shop-info {
            padding: 11px 8px 6px;
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        .shop-info h3 {
            font-size: 14px;
            margin: 0 0 5px;
            color: #10243a;
            line-height: 1.35;
            transition: color .2s ease;
        }
        .shop-name-link { text-decoration: none; }
        .shop-name-link:hover h3 { color: #ff521c; }
        .shop-categoryname { color: #8295ad; font-size: 11px; }
        .shop-price-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: auto;
            padding-top: 13px;
            gap: 5px;
        }
        .shop-price { font-size: 17px; font-weight: 800; color: #ff521c; }
        .shop-stocktag {
            font-size: 10px;
            color: #137247;
            background: #e0f8eb;
            padding: 6px 9px;
            border-radius: 8px;
            white-space: nowrap;
            font-weight: 600;
        }
        .shop-stocktag.low { color: #b45309; background: #fef3c7; }
        .shop-stocktag.out { color: #b91c1c; background: #fee2e2; }
        .shop-buy {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            width: 100%;
            margin-top: 10px;
            border: 0;
            border-radius: 8px;
            background: #ff521c;
            color: #fff;
            padding: 10px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            font-size: 13px;
            transition: background .2s ease;
        }
        .shop-buy:hover { background: #ed4817; }
        .shop-buy.is-disabled { background: #e5e7eb; color: #9ca3af; cursor: not-allowed; }
        .shop-buy .material-symbols-outlined { font-size: 17px; }
        .shop-empty {
            grid-column: 1 / -1;
            padding: 70px 20px;
            text-align: center;
            background: #ffffffb8;
            border: 1px solid #dfe8f3;
            border-radius: 14px;
        }
        .shop-empty .material-symbols-outlined { font-size: 44px; color: #94a3b8; }
        .shop-empty h3 { font-size: 20px; margin: 12px 0 6px; color: #10243a; }
        .shop-empty p {
            color: #64748b;
            font-size: 13px;
            margin: 0 auto 20px;
            max-width: 460px;
            line-height: 1.6;
        }
        .shop-empty a {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #ff521c;
            color: #fff;
            padding: 11px 18px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 13px;
            text-decoration: none;
        }
        .shop-empty a:hover { background: #ed4817; }
        @media (max-width: 900px) {
            .shop-products { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .shop-categories { grid-template-columns: repeat(3, 1fr); }
            .shop-toolbar { flex-wrap: wrap; }
            .shop-sort { margin-left: 0; width: 100%; flex-wrap: wrap; }
        }
        @media (max-width: 600px) {
            .shop-page { padding: 84px 15px 28px; }
            .shop-hero { min-height: 250px; padding: 25px; background-position: 60% center; }
            .shop-hero h1 { font-size: 33px; }
            .shop-categories { gap: 8px; }
            .shop-category { min-height: 82px; font-size: 12px; }
            .shop-category .material-symbols-outlined { font-size: 22px; }
            .shop-category .shop-cat-icon { font-size: 22px; }
            .shop-products { gap: 10px; }
            .shop-photo { height: 145px; }
            .shop-price { font-size: 14px; }
            .shop-toolbar { margin: 20px 0 16px; }
            .shop-search { max-width: none; }
            .shop-sort { gap: 8px; }
            .shop-sectionhead h2 { font-size: 23px; }
        }
    </style>
</head>
<body class="shop-body">

<?php include __DIR__ . '/includes/nav_bar.php'; ?>

<main class="shop-page">

    <!-- Hero -->
    <section class="shop-hero">
        <div>
            <div class="shop-eyebrow">HI-POWER SHOP</div>
            <h1>Premium Badminton<br><span>Gear &amp; Accessories</span></h1>
            <p>Quality products for better performance<br>on and off the court.</p>
        </div>
    </section>

    <!-- Categories -->
    <div class="shop-sectionhead" id="shop">
        <div class="shop-eyebrow">Browse by Category</div>
        <h2>Shop Categories</h2>
    </div>
    <nav class="shop-categories" aria-label="Shop categories">
        <a href="ecommerce" class="shop-category <?php echo empty($category_filter) ? 'active' : ''; ?>" <?php echo empty($category_filter) ? 'aria-current="page"' : ''; ?>>
            <span class="material-symbols-outlined" aria-hidden="true">apps</span>
            <span>All</span>
        </a>
        <?php foreach ($categories as $cat): ?>
            <a href="ecommerce?category=<?php echo urlencode($cat); ?>"
               class="shop-category <?php echo $category_filter === $cat ? 'active' : ''; ?>"
               <?php echo $category_filter === $cat ? 'aria-current="page"' : ''; ?>>
                <?php if ($cat === 'Shuttlecocks'): ?>
                    <span class="shop-cat-icon" aria-hidden="true"><?php echo navBadmintonIcon(); ?></span>
                <?php else: ?>
                    <span class="material-symbols-outlined" aria-hidden="true"><?php echo $cat_icons[$cat] ?? 'inventory_2'; ?></span>
                <?php endif; ?>
                <span><?php echo htmlspecialchars($cat); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- Search + Sort -->
    <div class="shop-toolbar">
        <form method="GET" action="ecommerce" class="shop-search" role="search">
            <?php if (!empty($category_filter)): ?>
                <input type="hidden" name="category" value="<?php echo htmlspecialchars($category_filter); ?>">
            <?php endif; ?>
            <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
            <span class="material-symbols-outlined" aria-hidden="true">search</span>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>"
                   placeholder="Search products, brands, or category..."
                   aria-label="Search products">
            <?php if (!empty($search_query) || !empty($category_filter)): ?>
                <a href="ecommerce" class="shop-clear" aria-label="Clear filters">
                    <span class="material-symbols-outlined" aria-hidden="true">close</span>
                </a>
            <?php endif; ?>
        </form>

        <form method="GET" action="ecommerce" class="shop-sort">
            <?php if (!empty($search_query)): ?>
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search_query); ?>">
            <?php endif; ?>
            <?php if (!empty($category_filter)): ?>
                <input type="hidden" name="category" value="<?php echo htmlspecialchars($category_filter); ?>">
            <?php endif; ?>
            <label for="shopSort">Sort by:</label>
            <select id="shopSort" name="sort" onchange="this.form.submit()">
                <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest</option>
                <option value="price_low" <?php echo $sort === 'price_low' ? 'selected' : ''; ?>>Price &uarr;</option>
                <option value="price_high" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>>Price &darr;</option>
                <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>A&ndash;Z</option>
            </select>
            <noscript><button type="submit" class="shop-apply">Apply</button></noscript>
            <span class="shop-count"><?php echo count($products); ?> items</span>
        </form>
    </div>

    <!-- Products -->
    <section class="shop-products" aria-label="Products">
        <?php if (count($products) > 0): ?>
            <?php foreach ($products as $product):
                $is_out = $product['stock_quantity'] <= 0;
                $is_low = $product['stock_quantity'] > 0 && $product['stock_quantity'] <= 5;
                $images = json_decode($product['image_url'], true);
                $img = (is_array($images) && count($images) > 0) ? $images[0] : ($product['image_url'] ?? '');
                if (!empty($img) && file_exists($img)) { $img_url = htmlspecialchars($img); }
                else { $img_url = "https://picsum.photos/seed/prod_" . $product['product_id'] . "/400/400"; }

                $stock_class = $is_out ? 'out' : ($is_low ? 'low' : 'ok');
                $stock_label = $is_out ? 'Out of Stock' : ($is_low ? 'Only ' . (int)$product['stock_quantity'] . ' left' : 'In Stock');
                $badge_label = $is_out ? 'SOLD OUT' : ($is_low ? 'LOW STOCK' : 'IN STOCK');
            ?>
            <article class="shop-card">
                <div class="shop-photo">
                    <a href="products/product?id=<?php echo (int)$product['product_id']; ?>" class="shop-photo-link" aria-label="View <?php echo htmlspecialchars($product['name']); ?>">
                        <img loading="lazy" src="<?php echo $img_url; ?>"
                             alt="<?php echo htmlspecialchars($product['name']); ?>"
                             onerror="this.onerror=null;this.src='https://picsum.photos/seed/prod_<?php echo (int)$product['product_id']; ?>/400/400';">
                    </a>
                    <?php if (!$is_out): ?>
                        <span class="shop-stock <?php echo $stock_class; ?>"><?php echo $badge_label; ?></span>
                    <?php endif; ?>
                    <?php if ($is_out): ?>
                        <div class="shop-out-overlay"><span>Out of Stock</span></div>
                    <?php endif; ?>
                </div>

                <div class="shop-info">
                    <a href="products/product?id=<?php echo (int)$product['product_id']; ?>" class="shop-name-link">
                        <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                    </a>
                    <div class="shop-categoryname"><?php echo htmlspecialchars($product['category']); ?></div>
                    <div class="shop-price-row">
                        <span class="shop-price">&#8369;<?php echo number_format($product['price'], 2); ?></span>
                        <span class="shop-stocktag <?php echo $stock_class; ?>"><?php echo $stock_label; ?></span>
                    </div>
                    <button type="button"
                            onclick="event.preventDefault();quickAdd(<?php echo (int)$product['product_id']; ?>,this)"
                            <?php echo $is_out ? 'disabled' : ''; ?>
                            class="shop-buy <?php echo $is_out ? 'is-disabled' : ''; ?>">
                        <span class="material-symbols-outlined" aria-hidden="true">add_shopping_cart</span>
                        <?php echo $is_out ? 'Out of Stock' : 'Add to Cart'; ?>
                    </button>
                </div>
            </article>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="shop-empty">
                <span class="material-symbols-outlined" aria-hidden="true">search_off</span>
                <h3>No products found</h3>
                <p>
                    <?php if (!empty($search_query)): ?>
                        No results for &ldquo;<strong><?php echo htmlspecialchars($search_query); ?></strong>&rdquo;. Try a different search.
                    <?php else: ?>
                        No products available in this category yet.
                    <?php endif; ?>
                </p>
                <a href="ecommerce">
                    <span class="material-symbols-outlined" aria-hidden="true">refresh</span> Browse All Products
                </a>
            </div>
        <?php endif; ?>
    </section>
</main>

<!-- Mini Cart Toast -->
<div id="cartToast" class="fixed bottom-28 md:bottom-8 right-4 md:right-8 z-[100] bg-white rounded-2xl border border-outline-variant/20 shadow-2xl p-4 flex items-center gap-3 transform translate-y-20 opacity-0 transition-all duration-300 pointer-events-none" style="pointer-events:auto;">
    <div class="w-10 h-10 rounded-xl bg-green-50 flex items-center justify-center shrink-0">
        <span class="material-symbols-outlined text-green-600 text-lg">check_circle</span>
    </div>
    <div>
        <p class="text-sm font-bold text-on-surface">Added to cart!</p>
        <a href="cart" class="text-[11px] text-primary font-bold hover:underline">View Cart &rarr;</a>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php include __DIR__ . '/includes/mobile_nav.php'; ?>

<script>
function quickAdd(productId, btn) {
    if (btn.disabled) return;
    const origHTML = btn.innerHTML;
    btn.innerHTML = '<span class="material-symbols-outlined text-[16px] animate-spin">refresh</span>';
    btn.disabled = true;

    fetch('ecommerce', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=quick_add&product_id=' + productId + '&quantity=1&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>'
    }).then(r => r.json()).then(d => {
        btn.innerHTML = origHTML;
        btn.disabled = false;
        if (d.ok) {
            const toast = document.getElementById('cartToast');
            toast.classList.remove('translate-y-20', 'opacity-0');
            toast.classList.add('translate-y-0', 'opacity-100');
            setTimeout(() => { toast.classList.add('translate-y-20', 'opacity-0'); toast.classList.remove('translate-y-0', 'opacity-100'); }, 3000);
        } else if (d.error === 'login_required') {
            window.location.href = '/authentication/login';
        }
    }).catch(() => {
        btn.innerHTML = origHTML;
        btn.disabled = false;
    });
}
</script>

</body>
</html>
