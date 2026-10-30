<?php
// mobile_nav.php

// Define dynamic classes for mobile active states
function getMobileNavClass($page_name, $current_page) {
    if ($current_page === $page_name) {
        // Active State: Primary color background and text
        return "flex flex-col items-center justify-center text-primary bg-primary-container/20 rounded-full py-1.5 px-5 transition-transform active:scale-90";
    } else {
        // Inactive State: Muted text that highlights to primary on hover
        return "flex flex-col items-center justify-center text-on-surface-variant hover:text-primary transition-colors";
    }
}

function getMobileIconStyle($page_name, $current_page) {
    // Fill the icon if it's the active page
    return ($current_page === $page_name) ? "font-variation-settings: 'FILL' 1;" : "";
}

$is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$dash_link = $is_logged_in ? (($_SESSION['role'] === 'Admin' || $_SESSION['role'] === 'Head Manager') ? '/admin/dashboard' : '/playerdashboard') : '/authentication/login';
?>

<nav class="md:hidden fixed bottom-0 left-0 w-full z-50 bg-white/95 backdrop-blur-md border-t border-outline-variant/30 shadow-[0_-4px_20px_rgba(0,0,0,0.08)] flex justify-around items-center px-4 py-2 pb-6 transition-all duration-300">
    
    <a href="/" class="<?php echo getMobileNavClass('index.php', $current_page); ?>">
        <span class="material-symbols-outlined" style="<?php echo getMobileIconStyle('index.php', $current_page); ?>">home</span>
        <span class="text-[10px] font-bold tracking-widest uppercase mt-1">Home</span>
    </a>
    
    <a href="/ecommerce" class="<?php echo getMobileNavClass('ecommerce.php', $current_page); ?>">
        <span class="material-symbols-outlined" style="<?php echo getMobileIconStyle('ecommerce.php', $current_page); ?>">shopping_bag</span>
        <span class="text-[10px] font-bold tracking-widest uppercase mt-1">Shop</span>
    </a>
    
    <a href="/courtbooking" class="<?php echo getMobileNavClass('courtbooking.php', $current_page); ?>">
        <span class="material-symbols-outlined" style="<?php echo getMobileIconStyle('courtbooking.php', $current_page); ?>">calendar_today</span>
        <span class="text-[10px] font-bold tracking-widest uppercase mt-1">Book</span>
    </a>
    
    <a href="/aimovementanalysis" class="<?php echo getMobileNavClass('aimovementanalysis.php', $current_page); ?>">
        <span class="material-symbols-outlined" style="<?php echo getMobileIconStyle('aimovementanalysis.php', $current_page); ?>">smart_toy</span>
        <span class="text-[10px] font-bold tracking-widest uppercase mt-1">AI</span>
    </a>
    
    <a href="<?php echo $dash_link; ?>" class="<?php echo getMobileNavClass(basename($dash_link), $current_page); ?>">
        <span class="material-symbols-outlined" style="<?php echo getMobileIconStyle(basename($dash_link), $current_page); ?>">
            <?php echo $is_logged_in ? 'person' : 'login'; ?>
        </span>
        <span class="text-[10px] font-bold tracking-widest uppercase mt-1">
            <?php echo $is_logged_in ? 'Profile' : 'Log In'; ?>
        </span>
    </a>
    
</nav>