<?php
// Start session
require_once __DIR__ . '/../includes/session_boot.php';

// Access Control
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: /authentication/login");
    exit;
}
if ($_SESSION['role'] !== 'Admin' && $_SESSION['role'] !== 'Head Manager') {
    header("Location: ../playerdashboard.php");
    exit;
}

require_once __DIR__ . '/../includes/database_connect.php';
require_once __DIR__ . '/../includes/csrf_helper.php';

$admin_name = $_SESSION['full_name'];
$admin_role = $_SESSION['role'];
$success_message = '';
$error_message = '';

// ==========================================
// HANDLE POST REQUESTS (ADD, EDIT, DELETE)
// ==========================================
verify_csrf_token(); 

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];

    try {
        // --- ADD PRODUCT ---
        if ($action == 'add_product') {
            $name = trim($_POST['name'] ?? '');
            // The fix is here: ensuring category values are short enough for the DB column
            $category = trim($_POST['category'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            // --- Server-side validation (TC-AS-025 / TC-AS-026) ---
            if ($name === '') throw new Exception("Item name is required.");
            if ($category === '') throw new Exception("Category is required.");
            if (!is_numeric($_POST['price'] ?? '') || (float)$_POST['price'] < 0) {
                throw new Exception("Price must be a non-negative number.");
            }
            $stock_val = filter_var($_POST['stock'] ?? '', FILTER_VALIDATE_INT);
            if ($stock_val === false || $stock_val < 0) {
                throw new Exception("Stock quantity must be a non-negative whole number.");
            }
            $price = (float)$_POST['price'];
            $stock = (int)$stock_val;

            
            // Handle MULTIPLE Image Uploads
            $image_urls = [];
            if (isset($_FILES['images']) && $_FILES['images']['error'][0] != UPLOAD_ERR_NO_FILE) {
                $upload_dir = '../img/products/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                
                $total_files = count($_FILES['images']['name']);
                for ($i = 0; $i < $total_files; $i++) {
                    if ($_FILES['images']['error'][$i] == UPLOAD_ERR_OK) {
                        $ext = strtolower(pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                            $new_filename = uniqid('prod_') . '_' . $i . '.' . $ext;
                            if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $upload_dir . $new_filename)) {
                                $image_urls[] = 'img/products/' . $new_filename; 
                            }
                        }
                    }
                }
            }
            
            // Encode the array into a JSON string to store in the single database column
            $final_image_string = !empty($image_urls) ? json_encode($image_urls) : '';

            $stmt = $pdo->prepare("INSERT INTO Products (name, description, price, stock_quantity, category, image_url, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $description, $price, $stock, $category, $final_image_string, $is_active]);
            $success_message = htmlspecialchars($name) . " added successfully.";
        }

        // --- EDIT PRODUCT ---
        elseif ($action == 'edit_product') {
            $product_id = (int)$_POST['product_id'];
            $name = trim($_POST['name'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            // --- Server-side validation (TC-AS-025 / TC-AS-026) ---
            if ($name === '') throw new Exception("Item name is required.");
            if ($category === '') throw new Exception("Category is required.");
            if (!is_numeric($_POST['price'] ?? '') || (float)$_POST['price'] < 0) {
                throw new Exception("Price must be a non-negative number.");
            }
            $stock_val = filter_var($_POST['stock'] ?? '', FILTER_VALIDATE_INT);
            if ($stock_val === false || $stock_val < 0) {
                throw new Exception("Stock quantity must be a non-negative whole number.");
            }
            $price = (float)$_POST['price'];
            $stock = (int)$stock_val;

            
            $update_images = false;
            $image_urls = [];

            if (isset($_FILES['images']) && $_FILES['images']['error'][0] != UPLOAD_ERR_NO_FILE) {
                $update_images = true;
                $upload_dir = '../img/products/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                
                $total_files = count($_FILES['images']['name']);
                for ($i = 0; $i < $total_files; $i++) {
                    if ($_FILES['images']['error'][$i] == UPLOAD_ERR_OK) {
                        $ext = strtolower(pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                            $new_filename = uniqid('prod_') . '_' . $i . '.' . $ext;
                            if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $upload_dir . $new_filename)) {
                                $image_urls[] = 'img/products/' . $new_filename; 
                            }
                        }
                    }
                }
            }

            if ($update_images) {
                $final_image_string = json_encode($image_urls);
                $stmt = $pdo->prepare("UPDATE Products SET name=?, description=?, price=?, stock_quantity=?, category=?, is_active=?, image_url=? WHERE product_id=?");
                $stmt->execute([$name, $description, $price, $stock, $category, $is_active, $final_image_string, $product_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE Products SET name=?, description=?, price=?, stock_quantity=?, category=?, is_active=? WHERE product_id=?");
                $stmt->execute([$name, $description, $price, $stock, $category, $is_active, $product_id]);
            }
            $success_message = htmlspecialchars($name) . " updated successfully.";
        }

        // --- DELETE PRODUCT ---
        elseif ($action == 'delete_product') {
            $product_id = (int)$_POST['product_id'];
            $stmt = $pdo->prepare("DELETE FROM Products WHERE product_id = ?");
            $stmt->execute([$product_id]);
            $success_message = "Product deleted successfully.";
        }
        
    } catch (Exception $e) {
        $error_message = "Action failed: " . $e->getMessage();
    }
}

// ==========================================
// FETCH INVENTORY DATA & STATS
// ==========================================
$total_items = $pdo->query("SELECT COUNT(*) FROM Products")->fetchColumn() ?? 0;
$low_stock_alerts = $pdo->query("SELECT COUNT(*) FROM Products WHERE stock_quantity <= 5 AND stock_quantity > 0")->fetchColumn() ?? 0;

$cat_stmt = $pdo->query("SELECT category, COUNT(*) as count FROM Products GROUP BY category ORDER BY count DESC");
$all_categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);

$top_categories = array_slice($all_categories, 0, 3);
$other_count = 0;
foreach(array_slice($all_categories, 3) as $c) { $other_count += $c['count']; }
if ($other_count > 0) { $top_categories[] = ['category' => 'Other', 'count' => $other_count]; }

$cat_colors = [
    ['bg' => 'bg-primary', 'dot' => 'bg-primary'],
    ['bg' => 'bg-primary-container', 'dot' => 'bg-primary-container'],
    ['bg' => 'bg-secondary', 'dot' => 'bg-secondary'],
    ['bg' => 'bg-outline-variant', 'dot' => 'bg-outline-variant']
];

$products = $pdo->query("SELECT * FROM Products ORDER BY product_id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>Shop Inventory | HI-POWER Admin</title>
    <?php include __DIR__ . '/../includes/tailwind_config.php'; ?>
</head>
<body class="bg-background font-body-md text-on-background selection:bg-primary-container">

<?php include __DIR__ . '/includes/admin_header.php'; ?>

    <main class="flex-1 ml-0 md:ml-64 p-4 md:p-8 pt-20 min-h-screen">
        <div class="max-w-[1280px] mx-auto space-y-6">
            
            <?php if (!empty($success_message)): ?>
                <div class="p-4 bg-primary-container/20 border border-primary/50 rounded-lg flex items-center gap-2 text-primary shadow-sm animate-fade-in">
                    <span class="material-symbols-outlined">check_circle</span>
                    <p class="font-bold text-sm"><?php echo $success_message; ?></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <div class="p-4 bg-error-container/20 border border-error/50 rounded-lg flex items-center gap-2 text-error shadow-sm animate-fade-in">
                    <span class="material-symbols-outlined">error</span>
                    <p class="font-bold text-sm"><?php echo $error_message; ?></p>
                </div>
            <?php endif; ?>

            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div>
                    <h1 class="text-3xl font-bold text-on-surface tracking-tight">Shop Inventory</h1>
                    <p class="text-on-surface-variant mt-1">Manage retail performance and stock levels.</p>
                </div>
                <button onclick="openModal('addItemModal')" class="bg-primary hover:brightness-110 text-white px-6 py-3 rounded-lg font-bold flex items-center gap-2 shadow-md active:scale-95 transition-all w-full md:w-auto justify-center">
                    <span class="material-symbols-outlined text-[20px]">add</span> Add New Item
                </button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                <div class="bg-surface-container-lowest border border-outline-variant/50 p-6 rounded-xl shadow-[0px_4px_20px_rgba(0,0,0,0.05)]">
                    <p class="text-[10px] font-bold text-outline uppercase tracking-wider">Total Items</p>
                    <p class="text-3xl font-bold mt-2 text-on-surface"><?php echo number_format($total_items); ?></p>
                </div>
                
                <div class="bg-surface-container-lowest border border-outline-variant/50 p-6 rounded-xl shadow-[0px_4px_20px_rgba(0,0,0,0.05)]">
                    <p class="text-[10px] font-bold text-outline uppercase tracking-wider">Low Stock Alerts</p>
                    <p class="text-3xl font-bold mt-2 <?php echo $low_stock_alerts > 0 ? 'text-error' : 'text-primary'; ?>"><?php echo number_format($low_stock_alerts); ?></p>
                </div>
                
                <div class="bg-surface-container-lowest border border-outline-variant/50 p-6 rounded-xl shadow-[0px_4px_20px_rgba(0,0,0,0.05)] md:col-span-2">
                    <p class="text-[10px] font-bold text-outline uppercase tracking-wider mb-4">Category Distribution</p>
                    
                    <?php if($total_items > 0): ?>
                        <div class="flex items-center gap-4 mb-4">
                            <div class="flex-1 bg-surface-container rounded-full h-3 overflow-hidden flex">
                                <?php foreach($top_categories as $idx => $cat): 
                                    $pct = ($cat['count'] / $total_items) * 100;
                                    $color = $cat_colors[$idx]['bg'];
                                ?>
                                    <div class="<?php echo $color; ?> h-full" style="width: <?php echo $pct; ?>%" title="<?php echo htmlspecialchars($cat['category']); ?>"></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                            <?php foreach($top_categories as $idx => $cat): 
                                $pct = ($cat['count'] / $total_items) * 100;
                                $dot = $cat_colors[$idx]['dot'];
                            ?>
                                <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full <?php echo $dot; ?>"></span><span class="text-xs font-bold text-on-surface-variant truncate"><?php echo htmlspecialchars($cat['category']); ?> (<?php echo round($pct); ?>%)</span></div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-sm text-outline italic">No items yet.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="bg-surface-container-lowest border border-outline-variant/50 rounded-xl shadow-[0px_4px_20px_rgba(0,0,0,0.05)] overflow-hidden">
                <div class="p-4 md:p-6 border-b border-outline-variant/50 bg-surface-bright flex flex-col md:flex-row justify-between items-center gap-4">
                    <div class="relative w-full md:w-96">
                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-outline">search</span>
                        <input id="searchInput" class="w-full pl-10 pr-4 py-2 bg-surface border border-outline-variant/50 rounded-lg focus:ring-1 focus:ring-primary focus:border-primary text-sm outline-none transition-all" placeholder="Search inventory..." type="text">
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse min-w-[800px]">
                        <thead>
                            <tr class="bg-surface-container text-on-surface-variant">
                                <th class="px-6 py-4 text-[10px] font-bold uppercase tracking-widest border-b border-outline-variant/30">Item Name</th>
                                <th class="px-6 py-4 text-[10px] font-bold uppercase tracking-widest border-b border-outline-variant/30">Category</th>
                                <th class="px-6 py-4 text-[10px] font-bold uppercase tracking-widest border-b border-outline-variant/30 text-right">Price</th>
                                <th class="px-6 py-4 text-[10px] font-bold uppercase tracking-widest border-b border-outline-variant/30 text-center">Stock</th>
                                <th class="px-6 py-4 text-[10px] font-bold uppercase tracking-widest border-b border-outline-variant/30">Status</th>
                                <th class="px-6 py-4 text-[10px] font-bold uppercase tracking-widest border-b border-outline-variant/30 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-outline-variant/30">
                            <?php if (empty($products)): ?>
                                <tr><td colspan="6" class="px-6 py-12 text-center text-outline italic text-sm">No products found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($products as $prod): 
                                    $stock = (int)$prod['stock_quantity'];
                                    $is_active = $prod['is_active'];
                                    
                                    if (!$is_active) { $status = "Hidden"; $badge = "bg-surface-variant text-outline"; $bar = "bg-outline-variant w-0"; }
                                    elseif ($stock == 0) { $status = "Out of Stock"; $badge = "bg-secondary-container/50 text-secondary"; $bar = "bg-outline-variant w-0"; }
                                    elseif ($stock <= 5) { $status = "Low Stock"; $badge = "bg-error-container/30 text-error"; $bar = "bg-error w-1/4 animate-pulse"; }
                                    else { $status = "In Stock"; $badge = "bg-primary-container/30 text-primary"; $bar = "bg-primary w-2/3"; }

                                    $cat = strtolower($prod['category']);
                                    $icon = "category";
                                    if (strpos($cat, 'racket') !== false) $icon = "sports_tennis";
                                    elseif (strpos($cat, 'shuttle') !== false) $icon = "shopping_bag";
                                    elseif (strpos($cat, 'shoe') !== false) $icon = "footprint";
                                    elseif (strpos($cat, 'apparel') !== false || strpos($cat, 'jersey') !== false) $icon = "apparel";
                                    
                                    $prod_json = htmlspecialchars(json_encode($prod), ENT_QUOTES, 'UTF-8');
                                ?>
                                <tr class="hover:bg-surface-bright transition-colors group">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-lg bg-surface border border-outline-variant/50 flex items-center justify-center shrink-0">
                                                <span class="material-symbols-outlined text-primary"><?php echo $icon; ?></span>
                                            </div>
                                            <div>
                                                <p class="font-bold text-on-surface text-sm leading-tight"><?php echo htmlspecialchars($prod['name']); ?></p>
                                                <p class="text-[10px] font-bold tracking-widest text-outline uppercase">SKU: SS-PROD-<?php echo str_pad($prod['product_id'], 3, '0', STR_PAD_LEFT); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-sm font-medium text-on-surface-variant"><?php echo htmlspecialchars($prod['category']); ?></td>
                                    <td class="px-6 py-4 text-sm text-right font-bold text-on-surface">₱<?php echo number_format($prod['price'], 2); ?></td>
                                    <td class="px-6 py-4">
                                        <div class="flex flex-col items-center gap-1">
                                            <span class="text-sm font-bold <?php echo ($stock<=5 && $is_active) ? 'text-error' : 'text-on-surface'; ?>"><?php echo $stock; ?></span>
                                            <div class="w-20 bg-surface-container rounded-full h-1.5 overflow-hidden"><div class="<?php echo $bar; ?> h-full"></div></div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="<?php echo $badge; ?> px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-widest border border-current/20"><?php echo $status; ?></span>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex justify-end gap-1 opacity-50 group-hover:opacity-100 transition-opacity">
                                            <button onclick="openViewModal(<?php echo $prod_json; ?>)" class="p-1.5 bg-surface-container hover:bg-surface-variant rounded text-on-surface-variant transition-colors" title="View"><span class="material-symbols-outlined text-[18px]">visibility</span></button>
                                            <button onclick="openEditModal(<?php echo $prod_json; ?>)" class="p-1.5 bg-surface-container hover:bg-primary-container hover:text-primary rounded text-on-surface-variant transition-colors" title="Edit"><span class="material-symbols-outlined text-[18px]">edit</span></button>
                                            <button onclick="confirmDelete(<?php echo $prod['product_id']; ?>)" class="p-1.5 bg-surface-container hover:bg-error-container hover:text-error rounded text-on-surface-variant transition-colors" title="Delete"><span class="material-symbols-outlined text-[18px]">delete</span></button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        </div>
    </main>

<form id="deleteForm" method="POST" action="inventory" class="hidden">
    <?php echo get_csrf_input(); ?>
    <input type="hidden" name="action" value="delete_product">
    <input type="hidden" name="product_id" id="delete_product_id">
</form>

<div id="modalOverlay" class="fixed inset-0 bg-on-background/40 backdrop-blur-sm z-[100] hidden transition-opacity opacity-0" onclick="closeAllModals()"></div>

<div id="addItemModal" class="hidden fixed inset-0 z-[110] flex items-center justify-center p-4 pointer-events-none">
    <div class="bg-surface-container-lowest w-full max-w-2xl rounded-2xl shadow-2xl border border-outline-variant overflow-hidden flex flex-col max-h-[90vh] scale-95 opacity-0 transition-all duration-300 transform pointer-events-auto">
        
        <div class="p-6 border-b border-outline-variant/50 flex justify-between items-center bg-surface-bright shrink-0">
            <h2 id="modalTitle" class="text-xl font-bold text-on-surface flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">add_circle</span> Add New Product
            </h2>
            <button type="button" onclick="closeAllModals()" class="text-outline hover:text-error p-1.5 rounded-full hover:bg-error-container/30 transition-colors">
                <span class="material-symbols-outlined text-[20px]">close</span>
            </button>
        </div>
        
        <form method="POST" action="inventory" enctype="multipart/form-data" class="p-6 overflow-y-auto custom-scrollbar flex-grow space-y-5">
            <?php echo get_csrf_input(); ?>
            <input type="hidden" name="action" id="formAction" value="add_product">
            <input type="hidden" name="product_id" id="formProductId" value="">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="space-y-1.5">
                    <label class="text-[11px] font-bold text-outline uppercase tracking-wider block ml-1">Product Name *</label>
                    <input type="text" name="name" id="formName" required class="w-full px-4 py-2.5 bg-surface border border-outline-variant/50 rounded-lg focus:border-primary focus:ring-1 outline-none text-sm">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[11px] font-bold text-outline uppercase tracking-wider block ml-1">Category *</label>
                    <select name="category" id="formCategory" required class="w-full px-4 py-2.5 bg-surface border border-outline-variant/50 rounded-lg focus:border-primary focus:ring-1 outline-none text-sm">
                        <option value="" disabled selected>Select a category</option>
                        <option value="Rackets">Rackets</option>
                        <option value="Shuttlecocks">Shuttlecocks</option>
                        <option value="Shoes">Shoes</option>
                        <option value="Jersey">Jersey</option>
                        <option value="Accessories">Accessories</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="space-y-1.5">
                    <label class="text-[11px] font-bold text-outline uppercase tracking-wider block ml-1">Price (₱) *</label>
                    <input type="number" name="price" id="formPrice" step="0.01" min="0" required class="w-full px-4 py-2.5 bg-surface border border-outline-variant/50 rounded-lg focus:border-primary focus:ring-1 outline-none text-sm">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[11px] font-bold text-outline uppercase tracking-wider block ml-1">Stock Quantity *</label>
                    <input type="number" name="stock" id="formStock" min="0" required class="w-full px-4 py-2.5 bg-surface border border-outline-variant/50 rounded-lg focus:border-primary focus:ring-1 outline-none text-sm">
                </div>
            </div>

            <div class="space-y-1.5">
                <label class="text-[11px] font-bold text-outline uppercase tracking-wider block ml-1">Description</label>
                <textarea name="description" id="formDesc" rows="3" class="w-full px-4 py-2.5 bg-surface border border-outline-variant/50 rounded-lg focus:border-primary focus:ring-1 outline-none text-sm resize-none"></textarea>
            </div>
            
            <div class="space-y-1.5">
                <label class="text-[11px] font-bold text-outline uppercase tracking-wider block ml-1">Product Images (Hold CTRL to select multiple)</label>
                <input type="file" name="images[]" multiple accept="image/jpeg, image/png, image/webp" class="w-full bg-surface border border-outline-variant/50 rounded-lg text-sm file:mr-4 file:py-2.5 file:px-4 file:border-0 file:bg-primary-container file:text-primary cursor-pointer text-on-surface-variant">
            </div>
            
            <div class="flex items-center gap-2 pt-2">
                <input type="checkbox" name="is_active" id="formActive" checked class="w-4 h-4 rounded text-primary focus:ring-primary border-outline-variant">
                <label for="formActive" class="text-sm font-bold text-on-surface select-none cursor-pointer">Active / Visible in Shop</label>
            </div>
            
            <div class="pt-4 border-t border-outline-variant/50 flex justify-end gap-3">
                <button type="button" onclick="closeAllModals()" class="px-6 py-2.5 bg-surface-variant text-on-surface-variant rounded-lg font-bold text-sm">Cancel</button>
                <button type="submit" id="formSubmitBtn" class="px-6 py-2.5 bg-primary text-white rounded-lg font-bold text-sm shadow-md">Save Product</button>
            </div>
        </form>
    </div>
</div>

<div id="viewItemModal" class="hidden fixed inset-0 z-[110] flex items-center justify-center p-4 pointer-events-none">
    <div class="bg-surface-container-lowest w-full max-w-lg rounded-2xl shadow-2xl border border-outline-variant overflow-hidden flex flex-col scale-95 opacity-0 transition-all duration-300 transform pointer-events-auto">
        <div class="p-6 border-b border-outline-variant/50 flex justify-between items-start bg-surface-bright">
            <div>
                <h2 id="viewName" class="text-2xl font-bold text-on-surface leading-tight mb-1">Product Name</h2>
                <span id="viewCat" class="text-[10px] font-bold uppercase tracking-widest text-primary bg-primary-container/20 px-2 py-0.5 rounded border border-primary/20">Category</span>
            </div>
            <button onclick="closeAllModals()" class="text-outline hover:text-error p-1 bg-surface-container rounded-full"><span class="material-symbols-outlined text-[20px]">close</span></button>
        </div>
        <div class="p-6 space-y-6">
            <div class="w-full h-48 bg-surface-variant rounded-xl overflow-hidden flex items-center justify-center border border-outline-variant/30 p-2">
                <img id="viewImg" src="" alt="Product" class="w-full h-full object-contain mix-blend-multiply">
            </div>
            <div class="grid grid-cols-2 gap-4 border-b border-outline-variant/30 pb-6">
                <div><p class="text-[10px] uppercase tracking-widest text-outline font-bold">Price</p><p id="viewPrice" class="text-2xl font-bold text-primary">₱0.00</p></div>
                <div><p class="text-[10px] uppercase tracking-widest text-outline font-bold">Stock</p><p id="viewStock" class="text-xl font-bold text-on-surface">0 units</p></div>
            </div>
            <div>
                <p class="text-[10px] uppercase tracking-widest text-outline font-bold mb-1">Description</p>
                <p id="viewDesc" class="text-sm text-on-surface-variant leading-relaxed"></p>
            </div>
        </div>
    </div>
</div>

<script>
    document.getElementById('searchInput')?.addEventListener('input', (e) => {
        const term = e.target.value.toLowerCase();
        document.querySelectorAll('tbody tr').forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
        });
    });

    setTimeout(() => {
        document.querySelectorAll('.animate-fade-in').forEach(alert => {
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        });
    }, 4000);

    const overlay = document.getElementById('modalOverlay');

    function animateModalOpen(modalId) {
        document.getElementById(modalId).classList.remove('hidden');
        overlay.classList.remove('hidden');
        setTimeout(() => {
            overlay.classList.remove('opacity-0');
            document.querySelector(`#${modalId} > div`).classList.remove('scale-95', 'opacity-0');
        }, 10);
        document.body.style.overflow = 'hidden';
    }

    function closeAllModals() {
        overlay.classList.add('opacity-0');
        document.querySelectorAll('.fixed > div.scale-100').forEach(el => el.classList.add('scale-95', 'opacity-0'));
        setTimeout(() => {
            overlay.classList.add('hidden');
            document.getElementById('addItemModal').classList.add('hidden');
            document.getElementById('viewItemModal').classList.add('hidden');
            document.body.style.overflow = '';
            
            document.getElementById('formAction').value = 'add_product';
            document.getElementById('modalTitle').innerHTML = '<span class="material-symbols-outlined text-primary">add_circle</span> Add New Product';
            document.querySelector('form[action="inventory"]').reset();
        }, 300);
    }

    function openModal(modalId) { animateModalOpen(modalId); }

    function openEditModal(prod) {
        document.getElementById('formAction').value = 'edit_product';
        document.getElementById('formProductId').value = prod.product_id;
        document.getElementById('modalTitle').innerHTML = '<span class="material-symbols-outlined text-primary">edit</span> Edit Product';
        
        document.getElementById('formName').value = prod.name;
        document.getElementById('formCategory').value = prod.category;
        document.getElementById('formPrice').value = prod.price;
        document.getElementById('formStock').value = prod.stock_quantity;
        document.getElementById('formDesc').value = prod.description;
        document.getElementById('formActive').checked = prod.is_active == 1;
        
        animateModalOpen('addItemModal');
    }

    function openViewModal(prod) {
        document.getElementById('viewName').textContent = prod.name;
        document.getElementById('viewCat').textContent = prod.category;
        document.getElementById('viewPrice').textContent = '₱' + parseFloat(prod.price).toFixed(2);
        document.getElementById('viewStock').textContent = prod.stock_quantity + ' units';
        document.getElementById('viewDesc').textContent = prod.description || 'No description provided.';
        
        // Parse the JSON array to get the first image safely
        let images;
        try { images = JSON.parse(prod.image_url); } catch(e) { images = [prod.image_url]; }
        let firstImg = (Array.isArray(images) && images.length > 0) ? '../' + images[0] : (prod.image_url ? '../' + prod.image_url : `https://picsum.photos/seed/prod_${prod.product_id}/400/400`);
        
        document.getElementById('viewImg').src = firstImg;
        
        animateModalOpen('viewItemModal');
    }

    function confirmDelete(id) {
        if(confirm('Are you sure you want to permanently delete this product?')) {
            document.getElementById('delete_product_id').value = id;
            document.getElementById('deleteForm').submit();
        }
    }
</script>

</body>
</html>