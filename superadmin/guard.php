<?php
session_start();

if (empty($_SESSION['sa_logged_in']) || $_SESSION['sa_role'] !== 'SuperAdmin') {
    header('Location: login.php');
    exit();
}

if (isset($_SESSION['sa_user_id'])) {
    global $pdo;
    if (!isset($pdo)) {
        require_once __DIR__ . '/../includes/database_connect.php';
    }

    $stmt = $pdo->prepare("SELECT account_status FROM Users WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $_SESSION['sa_user_id']]);
    $user_status = $stmt->fetchColumn();

    if ($user_status === 'Disabled') {
        session_unset();
        session_destroy();
        header("Location: login.php?error=account_disabled");
        exit();
    }
}