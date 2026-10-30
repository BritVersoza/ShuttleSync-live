<?php
// includes/header.php
// Shared page header — call with $page_title set before including

if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/../includes/session_boot.php';
}

// Load database if not already loaded
if (!isset($pdo)) {
    require_once __DIR__ . '/database_connect.php';
}

// Load CSRF helper if not already loaded
if (!function_exists('get_csrf_input')) {
    require_once __DIR__ . '/csrf_helper.php';
}

// Default title
$page_title = $page_title ?? 'HI-POWER Badminton Court';
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title><?php echo htmlspecialchars($page_title); ?> | HI-POWER Badminton Court</title>
    <?php include __DIR__ . '/tailwind_config.php'; ?>
</head>
<body class="bg-background text-on-background min-h-screen flex flex-col selection:bg-primary-container">
<?php include __DIR__ . '/nav_bar.php'; ?>
