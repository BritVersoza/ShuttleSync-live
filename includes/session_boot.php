<?php
// includes/session_boot.php
// Central session bootstrap for HI-POWER.
// - Hardens the session cookie (HttpOnly, SameSite).
// - Enforces a 30-minute inactivity timeout (BUG-004).
// - Keeps server-side session files alive long enough for "Remember Me".
//
// Every page that needs a session should require this file instead of calling session_start() directly.

// The venue operates in the Philippines; all date/time math must use this zone.
date_default_timezone_set('Asia/Manila');

if (!defined('SESSION_INACTIVITY_SECONDS')) {
    define('SESSION_INACTIVITY_SECONDS', 1800);   // 30 minutes
    define('SESSION_REMEMBER_SECONDS',  60 * 60 * 24 * 30); // 30 days
}

// Keep server-side session files alive long enough for "Remember Me" logins.
if ((int) ini_get('session.gc_maxlifetime') < SESSION_REMEMBER_SECONDS) {
    ini_set('session.gc_maxlifetime', SESSION_REMEMBER_SECONDS);
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0, // browser-session cookie by default; "Remember Me" re-issues a long-lived cookie
        'path'     => '/',
        'domain'   => '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// BUG-004: terminate sessions idle for more than 30 minutes.
// "Remember Me" sessions are intentionally exempt so users stay signed in.
if (!empty($_SESSION['logged_in']) && empty($_SESSION['remember_me'])) {
    $last_activity = $_SESSION['last_activity'] ?? time();
    if (time() - $last_activity > SESSION_INACTIVITY_SECONDS) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    } else {
        $_SESSION['last_activity'] = time();
    }
}