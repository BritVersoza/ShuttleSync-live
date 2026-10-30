<?php
// logout.php

// 1. Initialize the session
session_start();

// 2. Unset all of the session variables
$_SESSION = array();

// 3. Destroy the session cookie if it exists
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 4. Destroy the session
session_destroy();

// 5. Redirect back to the login page
if (isset($_GET['type']) && $_GET['type'] == 'admin') {
    header("Location: admin/index.php");
} else {
    header("Location: index.php");
}
exit;
?>