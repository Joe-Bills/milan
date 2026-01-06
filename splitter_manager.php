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
define('SESSION_TIMEOUT', 1200);
define('SESSION_REGEN_TIME', 1200); // Regenerate session every 3 minutes

// Check session timeout
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
    session_unset();
    session_destroy();
    header('Location: login.php?session_expired=1');
    exit();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Handle splitter creation
if ($_POST && isset($_POST['add_splitter'])) {
    try {
        $stmt = $pdo->prepare("INSERT INTO splitters 
            (splitter_name, splitter_type, location_lat, location_lng, address, input_power, output_power, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $_POST['splitter_name'],
            $_POST['splitter_type'],
            $_POST['location_lat'],
            $_POST['location_lng'],
            $_POST['address'],
            $_POST['input_power'],
            $_POST['output_power'],
            $_SESSION['user_id']
        ]);
        
        echo "<script>alert('Splitter added successfully!'); window.location.href = 'splitter_manager.php';</script>";
    } catch(PDOException $e) {
        echo "<script>alert('Error: " . $e->getMessage() . "');</script>";
    }
}

// Get all splitters
$splitters = $pdo->query("SELECT s.*, u.username as created_by_name 
                         FROM splitters s 
                         LEFT JOIN users u ON s.created_by = u.id 
                         ORDER BY s.created_at DESC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Splitter Management</title>
    <style>
        /* Add similar styles as core_manager.php */
        .splitter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .splitter-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #17a2b8;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🔀 Splitter Management</h1>
        <div>
            <a href="dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
            <a href="logout.php" class="btn btn-secondary">🚪 Logout</a>
        </div>
    </div>

    <div class="container">
        <!-- Splitter creation form and list -->
        <!-- Similar structure to core manager -->
    </div>
</body>
</html>