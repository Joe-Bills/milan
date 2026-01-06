<?php
session_start();

// Clear all session data
$_SESSION = array();

// Delete session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

// If not silent logout, redirect to login
if (!isset($_GET['silent'])) {
    header('Location: login.php?logout=1');
    exit();
}
?>