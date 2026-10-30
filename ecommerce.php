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

$cat_icons = ['Rackets'=>'sports_tennis','Shuttlecocks'=>'sports_baseball','Shoes'=>'footprint','Jersey'=>'checkroom','Apparel'=>'checkroom','Bags'=>'backpack','Accessories'=>'diamond','Other'=>'inventory_2'];
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>Shop | HI-POWER</title>
    <!-- Google Fonts Poppins -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <?php include __DIR__ . '/includes/tailwind_config.php'; ?>
    <style>
        body, button, input, select {
            font-family: 'Poppins', sans-serif !important;
        }
        .shop-body {
            margin: 0;
            background: #F4F6F8;
            color: #14264A;
        }
        .shop-page {
            max-width: 1500px;
            margin: 0 auto;
            padding: 96px 2.6% 40px;
        }
        .shop-hero {
            min-height: 300px;
            border-radius: 20px;
            overflow: hidden;
            display: flex;
            align-items: center;
            padding: 40px;
            background: linear-gradient(90deg, #080F17 0%, #080F17e8 40%, #080F1720 100%),
                        url('https://images.unsplash.com/photo-1626224583764-f87db24ac4ea?auto=format&fit=crop&w=1800&q=85') center 48% / cover;
            color: #fff;
            box-shadow: 0 10px 30px rgba(8,15,23,0.1);
        }
        .shop-eyebrow {
            color: #FF5722;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 1.8px;
            text-transform: uppercase;
        }
        .shop-hero h1 {
            font-size: clamp(32px, 4vw, 48px);
            line-height: 1.1;
            margin: 10px 0;
            color: #fff;
            font-weight: 800;
        }
        .shop-hero h1 span { color: #FF5722; }
        .shop-hero p {
            color: #94A3B8;
            max-width: 420px;
            line-height: 1.6;
            margin: 0;
            font-size: 14px;
        }
        .shop-sectionhead { margin: 32px 4px 16px; }
        .shop-sectionhead h2 {
            font-size: 26px;
            font-weight: 700;
            margin: 3px 0;
            color: #14264A;
        }
        .shop-categories {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 12px;
        }
        .shop-category {
            background: #ffffff;
            border: 1px solid #E2E8F0;
            border-radius: 16px;
            min-height: 95px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: #14264A;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            transition: all .2s ease;
            text-decoration: none;
            text-align: center;
            padding: 10px 6px;
            box-shadow: 0 2px 8px rgba(8,15,23,0.02);
        }
        .shop-category .material-symbols-outlined {
            font-size: 27px;
            color: #64748B;
            transition: color .2s ease;
        }
        .shop-cat-icon {
            font-size: 27px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #64748B;
            transition: color .2s ease;
        }
        .shop-category.active,
        .shop-category:hover {
            border-color: #FF5722;
            box-shadow: 0 6px 20px rgba(255,87,34,0.15);
            color: #FF5722;
        }
        .shop-category.active .material-symbols-outlined,
        .shop-category:hover .material-symbols-outlined { color: #FF5722; }
        .shop-category.active .shop-cat-icon,
        .shop-category:hover .shop-cat-icon { color: #FF5722; }
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
            border: 1px solid #E2E8F0;
            border-radius: 24px;
            padding: 12px 18px;
            max-width: 600px;
            position: relative;
            box-shadow: 0 2px 8px rgba(8,15,23,0.02);
        }
        .shop-search .material-symbols-outlined { color: #64748B; font-size: 20px; }
        .shop-search input {
            border: 0;
            outline: 0;
            width: 100%;
            font-size: 13px;
            color: #14264A;
            background: transparent;
            font-family: inherit;
        }
        .shop-search input::placeholder { color: #64748B; }
        .shop-search input:focus::placeholder { color: transparent; }
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
            background: #F1F5F9;
            color: #64748B;
            text-decoration: none;
        }
        .shop-search .shop-clear:hover { background: #FEE2E2; color: #DC2626; }
        .shop-search .shop-clear .material-symbols-outlined { font-size: 15px; color: inherit; }
        .shop-sort {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 12px;
            color: #14264A;
            font-weight: 600;
        }
        .shop-sort select {
            border: 1px solid #E2E8F0;
            background: #fff;
            border-radius: 22px;
            padding: 10px 16px;
            color: #14264A;
            cursor: pointer;
            font: inherit;
            font-size: 12px;
            font-weight: 600;
        }
        .shop-sort select:focus {
            outline: none;
            border-color: #FF5722;
        }
        .shop-apply {
            border: 1px solid #FF5722;
            background: #FF5722;
            border-radius: 22px;
            padding: 10px 16px;
            color: #fff;
            cursor: pointer;
            font: inherit;
            font-size: 12px;
            font-weight: 700;
        }
        .shop-count {
            color: #64748B;
            font-size: 12px;
            white-space: nowrap;
            font-weight: 500;
        }
        .shop-products {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
        }
        .shop-card {
            background: #fff;
            border: 1px solid #E2E8F0;
            border-radius: 16px;
            padding: 12px;
            box-shadow: 0 6px 20px rgba(8,15,23,0.04);
            min-width: 0;
            display: flex;
            flex-direction: column;
            transition: box-shadow .3s ease, transform .3s ease, border-color .3s ease;
        }
        .shop-card:hover {
            box-shadow: 0 12px 30px rgba(8,15,23,0.08);
            transform: translateY(-3px);
            border-color: rgba(255,87,34,0.3);
        }
        .shop-photo {
            height: 190px;
            border-radius: 12px;
            background: #F4F6F8;
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
            background: rgba(8,127,91,0.1);
            border: 1px solid rgba(8,127,91,0.3);
            color: #087F5B;
            border-radius: 20px;
            padding: 4px 10px;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .03em;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            z-index: 2;
        }
        .shop-stock::before {
            content: '';
            display: inline-block;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #087F5B;
            margin-right: 5px;
        }
        .shop-stock.low { background: #FEF3C7; border-color: #FDE68A; color: #B45309; }
        .shop-stock.low::before { background: #F59E0B; }
        .shop-stock.out { background: #FEE2E2; border-color: #FECACA; color: #B91C1C; }
        .shop-stock.out::before { background: #EF4444; }
        .shop-out-overlay {
            position: absolute;
            inset: 0;
            background: rgba(255,255,255,0.85);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1;
            pointer-events: none;
        }
        .shop-out-overlay span {
            background: #080F17;
            color: #fff;
            font-size: 10px;
            font-weight: 800;
            padding: 8px 14px;
            border-radius: 8px;
            text-transform: uppercase;
            letter-spacing: .1em;
        }
        .shop-info {
            padding: 12px 4px 4px;
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        .shop-info h3 {
            font-size: 14px;
            font-weight: 700;
            margin: 0 0 4px;
            color: #14264A;
            line-height: 1.35;
            transition: color .2s ease;
        }
        .shop-name-link { text-decoration: none; }
        .shop-name-link:hover h3 { color: #FF5722; }
        .shop-categoryname { color: #64748B; font-size: 11px; font-weight: 500; }
        .shop-price-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: auto;
            padding-top: 14px;
            gap: 5px;
        }
        .shop-price { font-size: 17px; font-weight: 800; color: #FF5722; }
        .shop-stocktag {
            font-size: 10px;
            color: #087F5B;
            background: rgba(8,127,91,0.1);
            padding: 5px 9px;
            border-radius: 8px;
            white-space: nowrap;
            font-weight: 700;
        }
        .shop-stocktag.low { color: #B45309; background: #FEF3C7; }
        .shop-stocktag.out { color: #B91C1C; background: #FEE2E2; }
        .shop-buy {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            width: 100%;
            margin-top: 12px;
            border: 0;
            border-radius: 12px;
            background: #FF5722;
            color: #fff;
            padding: 10px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            font-size: 12px;
            transition: all .2s ease;
            box-shadow: 0 4px 12px rgba(255,87,34,0.2);
            text-decoration: none;
        }
        .shop-buy:hover:not(.is-disabled) {
            filter: brightness(1.1);
            transform: translateY(-1px);
        }
        .shop-buy.is-disabled { background: #E2E8F0; color: #94A3B8; cursor: not-allowed; box-shadow: none; }
        .shop-buy .material-symbols-outlined { font-size: 17px; }
        .shop-empty {
            grid-column: 1 / -1;
            padding: 70px 20px;
            text-align: center;
            background: #fff;
            border: 1px solid #E2E8F0;
            border-radius: 16px;
        }
        .shop-empty .material-symbols-outlined { font-size: 44px; color: #94a3b8; }
        .shop-empty h3 { font-size: 20px; font-weight: 700; margin: 12px 0 6px; color: #14264A; }
        .shop-empty p {
            color: #64748B;
            font-size: 13px;
            margin: 0 auto 20px;
            max-width: 460px;
            line-height: 1.6;
        }
        .shop-empty a {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #FF5722;
            color: #fff;
            padding: 11px 20px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 13px;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(255,87,34,0.25);
        }
        .shop-empty a:hover { filter: brightness(1.1); }
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

    <!-- Hero Section -->
    <section class="shop-hero">
        <div>
            <div class="shop-eyebrow">HI-POWER SHOP</div>
            <h1>Premium Badminton<br><span>Gear &amp; Accessories</span></h1>
            <p>Quality products for better performance<br>on and off the court.</p>
        </div>
    </section>

    <!-- Categories Section -->
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

    <!-- Toolbar: Search & Sort -->
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

    <!-- Products Grid -->
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
                    <!-- Select Options button directing to product details for variants selection -->
                    <a href="products/product?id=<?php echo (int)$product['product_id']; ?>"
                       class="shop-buy <?php echo $is_out ? 'is-disabled' : ''; ?>"
                       <?php echo $is_out ? 'onclick="return false;"' : ''; ?>>
                        <span class="material-symbols-outlined" aria-hidden="true"><?php echo $is_out ? 'block' : 'visibility'; ?></span>
                        <?php echo $is_out ? 'Out of Stock' : 'Select Options'; ?>
                    </a>
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

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php include __DIR__ . '/includes/mobile_nav.php'; ?>

</body>
</html>