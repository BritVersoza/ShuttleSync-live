<?php
// csrf_helper.php

// 1. Generate the token if it doesn't exist
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 2. Create a function you can easily drop into your HTML forms
function get_csrf_input() {
    return '<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">';
}

// 3. Create a function to check the token on POST requests
function verify_csrf_token() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            // Log the attempt, then kill the script
            die("Security Error: Invalid or missing CSRF token. Request blocked.");
        }
    }
}
?>