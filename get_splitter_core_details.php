<?php
include 'config.php';

// Set security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline';");

// Start session with enhanced secure settings
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);
    ini_set('session.sid_length', 128);
    ini_set('session.sid_bits_per_character', 6);
    
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'],
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

// Regenerate session ID with additional security
if (empty($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
    $_SESSION['last_activity'] = time();
    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// 3 MINUTE SESSION TIMEOUT
define('SESSION_TIMEOUT', 180);
define('SESSION_REGEN_TIME', 180); // Regenerate session every 3 minutes

// Check session timeout
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
    session_unset();
    session_destroy();
    header('Location: index.php?session_expired=1');
    exit();
}

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit();
}

$core_id = $_GET['core_id'] ?? 0;

$stmt = $pdo->prepare("
    SELECT 
        sc.*,
        c.core_number,
        c.color,
        b.box_name
    FROM splitter_cores sc
    LEFT JOIN cores c ON sc.core_id = c.id
    LEFT JOIN boxes b ON c.box_id = b.id
    WHERE sc.id = ?
");
$stmt->execute([$core_id]);
$core = $stmt->fetch(PDO::FETCH_ASSOC);

if ($core) {
    header('Content-Type: application/json');
    echo json_encode($core);
} else {
    header('HTTP/1.1 404 Not Found');
    echo json_encode(['error' => 'Splitter core not found']);
}
?>