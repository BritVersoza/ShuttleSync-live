<?php
/**
 * marks all notifications as read for the current visitor
 * session marker works for everyone; DB (last_notif_read_at) is best-effort for logged-in users
 */
require_once __DIR__ . '/../includes/session_boot.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/database_connect.php';

// Read state always persists in the session (works for guests too).
$_SESSION['notif_read_at'] = date('Y-m-d H:i:s');

// Best-effort: also save to the DB when logged in (create column if missing).
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $user_id = (int)$_SESSION['user_id'];
    try {
        $pdo->query("ALTER TABLE Users ADD COLUMN last_notif_read_at DATETIME NULL");
    } catch (PDOException $e) {
        // Column already exists or no ALTER privilege — ignore.
    }
    try {
        $stmt = $pdo->prepare("UPDATE Users SET last_notif_read_at = NOW() WHERE user_id = ?");
        $stmt->execute([$user_id]);
    } catch (PDOException $e) {
        // DB unavailable — session marker still holds.
    }
}

echo json_encode(['success' => true]);