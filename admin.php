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

// Session timeout (3 minutes)
define('SESSION_TIMEOUT', 180);

// Check if user is logged in and session is valid
if (!isset($_SESSION['user_id']) || !isset($_SESSION['last_activity'])) {
    header("Location: login.php");
    exit();
}

// Check session timeout
if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
    session_unset();
    session_destroy();
    header('Location: login.php?session_expired=1');
    exit();
}

// Update last activity
$_SESSION['last_activity'] = time();

// Validate IP address (prevent session hijacking)
if (isset($_SESSION['ip_address']) && $_SESSION['ip_address'] !== ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')) {
    session_unset();
    session_destroy();
    header('Location: login.php?security=1');
    exit();
}

// CSRF protection functions
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Input validation and sanitization
function sanitizeInput($input, $type = 'string') {
    $input = trim($input);
    
    switch ($type) {
        case 'username':
            // Allow alphanumeric, underscore, hyphen, dot
            $input = preg_replace('/[^a-zA-Z0-9_\-.]/', '', $input);
            return substr($input, 0, 50);
            
        case 'email':
            $input = filter_var($input, FILTER_SANITIZE_EMAIL);
            return substr($input, 0, 100);
            
        case 'password':
            return substr($input, 0, 255);
            
        case 'id':
            return (int) $input;
            
        case 'string':
        default:
            return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

// Check if user exists and is admin
$user_id = $_SESSION['user_id'] ?? 0;
$user = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$user->execute([$user_id]);
$user = $user->fetch();

// Check if user exists and is admin
if (!$user || $user['is_admin'] != 1) {
    header("Location: dashboard.php");
    exit();
}

// Check if is_active column exists, if not add it
try {
    $check_column = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_active'");
    if (!$check_column->fetch()) {
        // Add is_active column if it doesn't exist
        $pdo->query("ALTER TABLE users ADD COLUMN is_active TINYINT(1) DEFAULT 1");
        // Set all existing users as active
        $pdo->query("UPDATE users SET is_active = 1");
    }
} catch(PDOException $e) {
    // Column might already exist
    error_log("Database column check error: " . $e->getMessage());
}

// Initialize variables
$error = '';
$success = '';
$csrf_token = generateCSRFToken();

// Rate limiting for admin actions
function checkAdminActionRateLimit($action, $pdo) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $window = 60; // 1 minute
    $max_attempts = 10;
    
    try {
        // Create admin_actions table if it doesn't exist
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'admin_actions'")->fetch();
        if (!$tableCheck) {
            $createTableSQL = "CREATE TABLE IF NOT EXISTS admin_actions (
                id INT PRIMARY KEY AUTO_INCREMENT,
                admin_id INT NOT NULL,
                action_type VARCHAR(50) NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                action_time INT NOT NULL,
                INDEX idx_admin_time (admin_id, action_time),
                INDEX idx_ip_time (ip_address, action_time)
            )";
            $pdo->exec($createTableSQL);
        }
        
        // Clean old attempts
        $stmt = $pdo->prepare("DELETE FROM admin_actions WHERE action_time < ?");
        $stmt->execute([time() - $window]);
        
        // Count recent attempts for this IP
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM admin_actions WHERE ip_address = ? AND action_time > ?");
        $stmt->execute([$ip, time() - $window]);
        $result = $stmt->fetch();
        
        return $result['count'] < $max_attempts;
    } catch (PDOException $e) {
        error_log("Rate limit check error: " . $e->getMessage());
        return true;
    }
}

function recordAdminAction($admin_id, $action_type, $pdo) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $stmt = $pdo->prepare("INSERT INTO admin_actions (admin_id, action_type, ip_address, action_time) VALUES (?, ?, ?, ?)");
        $stmt->execute([$admin_id, $action_type, $ip, time()]);
    } catch (PDOException $e) {
        error_log("Failed to record admin action: " . $e->getMessage());
    }
}

// Handle user registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_user'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = "Security validation failed. Please try again.";
    } elseif (!checkAdminActionRateLimit('register_user', $pdo)) {
        $error = "Too many actions. Please wait a moment and try again.";
    } else {
        $username = sanitizeInput($_POST['username'] ?? '', 'username');
        $email = sanitizeInput($_POST['email'] ?? '', 'email');
        $password = $_POST['password'] ?? '';
        $is_admin = isset($_POST['is_admin']) ? 1 : 0;
        
        // Validate inputs
        if (empty($username) || empty($email) || empty($password)) {
            $error = "All fields are required!";
        } elseif (strlen($password) < 6) {
            $error = "Password must be at least 6 characters long!";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email format!";
        } else {
            try {
                // Check if username or email already exists
                $check_stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                $check_stmt->execute([$username, $email]);
                
                if ($check_stmt->fetch()) {
                    $error = "Username or email already exists!";
                } else {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (username, email, password, is_admin, created_at) VALUES (?, ?, ?, ?, NOW())");
                    $stmt->execute([$username, $email, $hashed_password, $is_admin]);
                    
                    // Record admin action
                    recordAdminAction($_SESSION['user_id'], 'register_user', $pdo);
                    
                    // Generate new CSRF token
                    unset($_SESSION['csrf_token']);
                    $csrf_token = generateCSRFToken();
                    
                    $success = "User registered successfully!";
                }
            } catch(PDOException $e) {
                $error = "Database error. Please try again.";
                error_log("User registration error: " . $e->getMessage());
            }
        }
    }
}

// Handle user blocking/unblocking
if (isset($_GET['toggle_block'])) {
    // Validate CSRF token for GET requests (using referrer check)
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (strpos($referer, $_SERVER['HTTP_HOST']) === false) {
        $error = "Invalid request source!";
    } elseif (!checkAdminActionRateLimit('toggle_block', $pdo)) {
        $error = "Too many actions. Please wait a moment and try again.";
    } else {
        $user_id = sanitizeInput($_GET['toggle_block'], 'id');
        
        // Prevent admin from blocking themselves
        if ($user_id == $_SESSION['user_id']) {
            $error = "You cannot block your own account!";
        } else {
            try {
                // Check if is_active column exists
                $status_stmt = $pdo->prepare("SELECT is_active FROM users WHERE id = ?");
                $status_stmt->execute([$user_id]);
                $status = $status_stmt->fetch();
                
                if ($status) {
                    // Toggle the status
                    $new_status = isset($status['is_active']) ? ($status['is_active'] ? 0 : 1) : 0;
                    $action = $new_status ? 'unblocked' : 'blocked';
                    
                    $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
                    $stmt->execute([$new_status, $user_id]);
                    
                    // Record admin action
                    recordAdminAction($_SESSION['user_id'], 'toggle_block', $pdo);
                    
                    $success = "User $action successfully!";
                }
            } catch(PDOException $e) {
                $error = "Database error. Please try again.";
                error_log("User block toggle error: " . $e->getMessage());
            }
        }
    }
}

// Handle user role update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_role'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = "Security validation failed. Please try again.";
    } elseif (!checkAdminActionRateLimit('update_role', $pdo)) {
        $error = "Too many actions. Please wait a moment and try again.";
    } else {
        $user_id = sanitizeInput($_POST['user_id'] ?? '', 'id');
        $is_admin = isset($_POST['is_admin']) ? 1 : 0;
        
        // Prevent admin from removing their own admin privileges
        if ($user_id == $_SESSION['user_id'] && $is_admin == 0) {
            $error = "You cannot remove your own admin privileges!";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE users SET is_admin = ? WHERE id = ?");
                $stmt->execute([$is_admin, $user_id]);
                
                // Record admin action
                recordAdminAction($_SESSION['user_id'], 'update_role', $pdo);
                
                // Generate new CSRF token
                unset($_SESSION['csrf_token']);
                $csrf_token = generateCSRFToken();
                
                $success = "User role updated successfully!";
            } catch(PDOException $e) {
                $error = "Database error. Please try again.";
                error_log("User role update error: " . $e->getMessage());
            }
        }
    }
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = "Security validation failed. Please try again.";
    } elseif (!checkAdminActionRateLimit('reset_password', $pdo)) {
        $error = "Too many actions. Please wait a moment and try again.";
    } else {
        $user_id = sanitizeInput($_POST['user_id'] ?? '', 'id');
        $new_password = $_POST['new_password'] ?? '';
        
        // Validate password length
        if (strlen($new_password) < 6) {
            $error = "Password must be at least 6 characters long!";
        } else {
            try {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashed_password, $user_id]);
                
                // Record admin action
                recordAdminAction($_SESSION['user_id'], 'reset_password', $pdo);
                
                // Generate new CSRF token
                unset($_SESSION['csrf_token']);
                $csrf_token = generateCSRFToken();
                
                $success = "Password reset successfully!";
            } catch(PDOException $e) {
                $error = "Database error. Please try again.";
                error_log("Password reset error: " . $e->getMessage());
            }
        }
    }
}

// Handle user edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = "Security validation failed. Please try again.";
    } elseif (!checkAdminActionRateLimit('edit_user', $pdo)) {
        $error = "Too many actions. Please wait a moment and try again.";
    } else {
        $user_id = sanitizeInput($_POST['user_id'] ?? '', 'id');
        $username = sanitizeInput($_POST['edit_username'] ?? '', 'username');
        $email = sanitizeInput($_POST['edit_email'] ?? '', 'email');
        
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email format!";
        } else {
            try {
                // Check if username or email already exists (excluding current user)
                $check_stmt = $pdo->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
                $check_stmt->execute([$username, $email, $user_id]);
                
                if ($check_stmt->fetch()) {
                    $error = "Username or email already exists!";
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
                    $stmt->execute([$username, $email, $user_id]);
                    
                    // Record admin action
                    recordAdminAction($_SESSION['user_id'], 'edit_user', $pdo);
                    
                    // Generate new CSRF token
                    unset($_SESSION['csrf_token']);
                    $csrf_token = generateCSRFToken();
                    
                    $success = "User information updated successfully!";
                }
            } catch(PDOException $e) {
                $error = "Database error. Please try again.";
                error_log("User edit error: " . $e->getMessage());
            }
        }
    }
}

// Get all users (with proper error handling)
try {
    $users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();
} catch(PDOException $e) {
    $error = "Error loading user data. Please try again.";
    error_log("User fetch error: " . $e->getMessage());
    $users = [];
}

// Get system statistics with error handling
function getStat($pdo, $query, $default = 0) {
    try {
        $result = $pdo->query($query)->fetch();
        return $result ? (int) $result[0] : $default;
    } catch(PDOException $e) {
        error_log("Stat query error: " . $e->getMessage());
        return $default;
    }
}

$totalBoxes = getStat($pdo, "SELECT COUNT(*) as total FROM boxes", 0);
$totalCores = getStat($pdo, "SELECT SUM(total_cores) as total FROM boxes", 0);
$totalUsers = getStat($pdo, "SELECT COUNT(*) as total FROM users", 0);

try {
    $check_active = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_active'");
    $active_column_exists = $check_active->fetch();
    
    if ($active_column_exists) {
        $activeUsers = getStat($pdo, "SELECT COUNT(*) as total FROM users WHERE is_active = 1", 0);
        $blockedUsers = getStat($pdo, "SELECT COUNT(*) as total FROM users WHERE is_active = 0", 0);
    } else {
        $activeUsers = $totalUsers;
        $blockedUsers = 0;
    }
} catch(PDOException $e) {
    $activeUsers = $totalUsers;
    $blockedUsers = 0;
}

$connectedCores = getStat($pdo, "SELECT COUNT(*) as total FROM cores WHERE connection_status != 'available' AND connection_status IS NOT NULL", 0);
$availableCores = getStat($pdo, "SELECT COUNT(*) as total FROM cores WHERE connection_status = 'available'", 0);

// Add logging for sensitive admin actions
function logAdminAction($action, $details) {
    $log_file = 'admin_actions.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $user_id = $_SESSION['user_id'] ?? 'unknown';
    $log_entry = "[$timestamp] [IP: $ip] [UserID: $user_id] $action: $details\n";
    
    // Secure logging - ensure file permissions are set properly
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - ISP Management System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f8f9fa; 
            color: #333;
        }
        
        .header { 
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%); 
            color: white; 
            padding: 15px 20px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .container { 
            max-width: 1200px; 
            margin: 0 auto; 
            padding: 20px; 
        }
        
        .admin-nav {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .nav-tabs {
            display: flex;
            gap: 10px;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 15px;
            flex-wrap: wrap;
        }
        
        .nav-tab {
            padding: 10px 20px;
            background: #f8f9fa;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .nav-tab.active {
            background: #3498db;
            color: white;
        }
        
        .tab-content {
            display: none;
            background: white;
            padding: 25px;
            border-radius: 8px;
            margin-top: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .tab-content.active {
            display: block;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-left: 4px solid #3498db;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            display: block;
            margin-bottom: 8px;
        }
        
        .stat-boxes { color: #3498db; border-color: #3498db; }
        .stat-cores { color: #27ae60; border-color: #27ae60; }
        .stat-users { color: #e74c3c; border-color: #e74c3c; }
        .stat-active-users { color: #2ecc71; border-color: #2ecc71; }
        .stat-blocked-users { color: #f39c12; border-color: #f39c12; }
        .stat-connected { color: #9b59b6; border-color: #9b59b6; }
        .stat-available { color: #1abc9c; border-color: #1abc9c; }
        
        .stat-label {
            font-size: 14px;
            color: #7f8c8d;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        
        .form-group { 
            margin-bottom: 20px; 
        }
        
        label { 
            display: block; 
            margin-bottom: 8px; 
            font-weight: 600; 
            color: #2c3e50; 
            font-size: 14px;
        }
        
        input, select, textarea { 
            width: 100%; 
            padding: 12px; 
            border: 2px solid #e9ecef; 
            border-radius: 6px; 
            font-size: 14px;
            transition: all 0.3s ease;
            background: #fff;
        }
        
        input:focus, select:focus, textarea:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: #3498db;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-1px);
        }
        
        .btn-danger {
            background: #e74c3c;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c0392b;
            transform: translateY(-1px);
        }
        
        .btn-success {
            background: #27ae60;
            color: white;
        }
        
        .btn-warning {
            background: #f39c12;
            color: white;
        }
        
        .btn-secondary {
            background: #95a5a6;
            color: white;
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .users-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .users-table th,
        .users-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        
        .users-table th {
            background: #34495e;
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.5px;
        }
        
        .users-table tr:hover {
            background: #f8f9fa;
        }
        
        .admin-badge {
            background: #e74c3c;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .user-badge {
            background: #27ae60;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .active-badge {
            background: #2ecc71;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .blocked-badge {
            background: #e74c3c;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        
        .alert-success {
            background: #d5f4e6;
            color: #27ae60;
            border-left: 4px solid #27ae60;
        }
        
        .alert-error {
            background: #fdeaea;
            color: #e74c3c;
            border-left: 4px solid #e74c3c;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: auto;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .section-title {
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
            font-size: 22px;
            font-weight: 700;
        }
        
        .logout-btn { 
            background: #e74c3c; 
            padding: 5px 10px;
            border-radius: 6px;
            text-decoration: none;
            color: white;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            margin-left: 10px;
        }
        
        .logout-btn:hover { 
            background: #c0392b;
            transform: translateY(-1px);
        }
        
        .back-btn {
            background: #95a5a6;
            padding: 5px 10px;
            border-radius: 6px;
            text-decoration: none;
            color: white;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            margin-left: 10px;
        }

        .bac-btn {
            background: #53e9f3ff;
            padding: 5px 10px;
            border-radius: 6px;
            text-decoration: none;
            color: white;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            margin-left: 10px;
        }
        
        .back-btn:hover {
            background: #7f8c8d;
            transform: translateY(-1px);
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
        }
        
        .modal-header {
            background: #3498db;
            color: white;
            padding: 15px 20px;
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 18px;
        }
        
        .close {
            color: white;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
        }
        
        .close:hover {
            color: #ffcccb;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .modal-footer {
            padding: 15px 20px;
            background: #f8f9fa;
            border-radius: 0 0 8px 8px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        /* Security warning */
        .security-warning {
            background: #fff3cd;
            border: 1px solid #ffecb5;
            color: #856404;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .security-warning i {
            font-size: 18px;
        }
        
        /* Password strength indicator */
        .password-strength {
            margin-top: 5px;
            height: 5px;
            border-radius: 2px;
            background: #e9ecef;
            overflow: hidden;
        }
        
        .strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease, background-color 0.3s ease;
        }
        
        .strength-weak { background-color: #e74c3c; }
        .strength-medium { background-color: #f39c12; }
        .strength-strong { background-color: #27ae60; }
        
        .strength-text {
            font-size: 12px;
            margin-top: 3px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Admin Panel - ISP Management System</h1>
        <div>
            <span>Welcome, <strong><?php echo htmlspecialchars($user['username']); ?> (Admin)</strong></span>
            <a href="dashboard.php" class="back-btn">Back to Dashboard</a>
            <a href="view_boxes.php" class="bac-btn">View Boxes</a>
            <a href="log.php" class="bac-btn">logs</a>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </div>

    <div class="container">
        <!-- Security Warning -->
        <div class="security-warning">
            <i>⚠️</i>
            <span>Admin Panel Access: All actions are logged and monitored.</span>
        </div>

        <!-- Success/Error Messages -->
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- System Statistics -->
        <div class="stats-grid">
            <div class="stat-card stat-boxes">
                <span class="stat-number"><?php echo $totalBoxes; ?></span>
                <span class="stat-label">Total Boxes</span>
            </div>
            <div class="stat-card stat-cores">
                <span class="stat-number"><?php echo $totalCores; ?></span>
                <span class="stat-label">Total Cores</span>
            </div>
            <div class="stat-card stat-users">
                <span class="stat-number"><?php echo $totalUsers; ?></span>
                <span class="stat-label">Total Users</span>
            </div>
            <div class="stat-card stat-active-users">
                <span class="stat-number"><?php echo $activeUsers; ?></span>
                <span class="stat-label">Active Users</span>
            </div>
            <div class="stat-card stat-blocked-users">
                <span class="stat-number"><?php echo $blockedUsers; ?></span>
                <span class="stat-label">Blocked Users</span>
            </div>
            <div class="stat-card stat-connected">
                <span class="stat-number"><?php echo $connectedCores; ?></span>
                <span class="stat-label">Connected Cores</span>
            </div>
            <div class="stat-card stat-available">
                <span class="stat-number"><?php echo $availableCores; ?></span>
                <span class="stat-label">Available Cores</span>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div class="admin-nav">
            <div class="nav-tabs">
                <button class="nav-tab active" onclick="openTab('user-management')">User Management</button>
                <button class="nav-tab" onclick="openTab('register-user')">Register New User</button>
            </div>

            <!-- User Management Tab -->
            <div id="user-management" class="tab-content active">
                <h3 class="section-title">User Management</h3>

                <table class="users-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($users as $user_row): 
                            $is_active = isset($user_row['is_active']) ? $user_row['is_active'] : 1;
                        ?>
                            <tr>
                                <td><?php echo $user_row['id']; ?></td>
                                <td><?php echo htmlspecialchars($user_row['username']); ?></td>
                                <td><?php echo htmlspecialchars($user_row['email']); ?></td>
                                <td>
                                    <?php if ($user_row['is_admin']): ?>
                                        <span class="admin-badge">Administrator</span>
                                    <?php else: ?>
                                        <span class="user-badge">User</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($is_active): ?>
                                        <span class="active-badge">Active</span>
                                    <?php else: ?>
                                        <span class="blocked-badge">Blocked</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('M j, Y g:i A', strtotime($user_row['created_at'])); ?></td>
                                <td class="action-buttons">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="user_id" value="<?php echo $user_row['id']; ?>">
                                        <input type="hidden" name="update_role" value="1">
                                        <input type="checkbox" name="is_admin" <?php echo $user_row['is_admin'] ? 'checked' : ''; ?> 
                                               onchange="this.form.submit()" <?php echo $user_row['id'] == $_SESSION['user_id'] ? 'disabled' : ''; ?>>
                                        <label style="display: inline; margin-left: 5px; font-size: 11px;">Admin</label>
                                    </form>
                                    
                                    <button class="btn btn-sm btn-warning" onclick="openEditUserModal(<?php echo $user_row['id']; ?>, '<?php echo htmlspecialchars($user_row['username'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user_row['email'], ENT_QUOTES); ?>')">
                                        Edit
                                    </button>
                                    
                                    <button class="btn btn-sm btn-info" onclick="openResetPasswordModal(<?php echo $user_row['id']; ?>, '<?php echo htmlspecialchars($user_row['username'], ENT_QUOTES); ?>')">
                                        Reset Password
                                    </button>
                                    
                                    <?php if ($user_row['id'] != $_SESSION['user_id']): ?>
                                        <a href="admin.php?toggle_block=<?php echo $user_row['id']; ?>&csrf=<?php echo urlencode($csrf_token); ?>" 
                                           class="btn btn-sm <?php echo $is_active ? 'btn-danger' : 'btn-success'; ?>"
                                           onclick="return confirm('Are you sure you want to <?php echo $is_active ? 'block' : 'unblock'; ?> this user?')">
                                            <?php echo $is_active ? 'Block' : 'Unblock'; ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Register User Tab -->
            <div id="register-user" class="tab-content">
                <h3 class="section-title">Register New User</h3>

                <form method="POST" id="registerForm">
                    <input type="hidden" name="register_user" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div class="form-group">
                        <label for="username">Username:</label>
                        <input type="text" id="username" name="username" required 
                               placeholder="Enter username" pattern="[a-zA-Z0-9_\-\.]{1,50}"
                               oninput="validateUsername(this)">
                        <div id="username-error" style="color: #e74c3c; font-size: 12px; margin-top: 5px; display: none;"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email:</label>
                        <input type="email" id="email" name="email" required 
                               placeholder="Enter email address" oninput="validateEmail(this)">
                        <div id="email-error" style="color: #e74c3c; font-size: 12px; margin-top: 5px; display: none;"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password:</label>
                        <input type="password" id="password" name="password" required 
                               placeholder="Enter password (minimum 6 characters)" minlength="6"
                               oninput="checkPasswordStrength(this.value)">
                        <div class="password-strength">
                            <div class="strength-bar" id="password-strength-bar"></div>
                        </div>
                        <div class="strength-text" id="password-strength-text">Password strength: </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" id="is_admin" name="is_admin">
                            <label for="is_admin">Grant Administrator Privileges</label>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" id="registerBtn">Register User</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="bi bi-pencil"></i> Edit User</h3>
                <button class="close" onclick="closeEditUserModal()">&times;</button>
            </div>
            <form method="POST" id="editUserForm">
                <input type="hidden" name="edit_user" value="1">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Username:</label>
                        <input type="text" name="edit_username" id="edit_username" required 
                               pattern="[a-zA-Z0-9_\-\.]{1,50}">
                    </div>
                    <div class="form-group">
                        <label>Email:</label>
                        <input type="email" name="edit_email" id="edit_email" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeEditUserModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div id="resetPasswordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="bi bi-key"></i> Reset Password</h3>
                <button class="close" onclick="closeResetPasswordModal()">&times;</button>
            </div>
            <form method="POST" id="resetPasswordForm">
                <input type="hidden" name="reset_password" value="1">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="user_id" id="reset_user_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Username:</label>
                        <input type="text" id="reset_username" readonly disabled style="background: #f8f9fa;">
                    </div>
                    <div class="form-group">
                        <label>New Password:</label>
                        <input type="password" name="new_password" id="new_password" required 
                               placeholder="Enter new password (minimum 6 characters)" minlength="6"
                               oninput="checkResetPasswordStrength(this.value)">
                        <div class="password-strength">
                            <div class="strength-bar" id="reset-password-strength-bar"></div>
                        </div>
                        <div class="strength-text" id="reset-password-strength-text">Password strength: </div>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password:</label>
                        <input type="password" id="confirm_password" required 
                               placeholder="Confirm new password" minlength="6">
                    </div>
                    <div id="passwordError" style="color: #e74c3c; font-size: 14px; display: none;">
                        Passwords do not match!
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeResetPasswordModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="resetPasswordBtn">Reset Password</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Security features
        'use strict';

        function openTab(tabName) {
            // Hide all tab contents
            const tabContents = document.getElementsByClassName('tab-content');
            for (let i = 0; i < tabContents.length; i++) {
                tabContents[i].classList.remove('active');
            }
            
            // Remove active class from all tabs
            const tabs = document.getElementsByClassName('nav-tab');
            for (let i = 0; i < tabs.length; i++) {
                tabs[i].classList.remove('active');
            }
            
            // Show the specific tab content and activate the tab
            document.getElementById(tabName).classList.add('active');
            event.currentTarget.classList.add('active');
        }

        function openEditUserModal(userId, username, email) {
            document.getElementById('edit_user_id').value = userId;
            document.getElementById('edit_username').value = username;
            document.getElementById('edit_email').value = email;
            document.getElementById('editUserModal').style.display = 'block';
        }

        function closeEditUserModal() {
            document.getElementById('editUserModal').style.display = 'none';
        }

        function openResetPasswordModal(userId, username) {
            document.getElementById('reset_user_id').value = userId;
            document.getElementById('reset_username').value = username;
            document.getElementById('new_password').value = '';
            document.getElementById('confirm_password').value = '';
            document.getElementById('passwordError').style.display = 'none';
            document.getElementById('resetPasswordModal').style.display = 'block';
        }

        function closeResetPasswordModal() {
            document.getElementById('resetPasswordModal').style.display = 'none';
        }

        // Password validation
        document.getElementById('resetPasswordForm').addEventListener('submit', function(e) {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const passwordError = document.getElementById('passwordError');
            
            if (newPassword !== confirmPassword) {
                passwordError.style.display = 'block';
                e.preventDefault();
            } else {
                passwordError.style.display = 'none';
            }
        });

        // Input validation
        function validateUsername(input) {
            const errorDiv = document.getElementById('username-error');
            const username = input.value.trim();
            const pattern = /^[a-zA-Z0-9_\-\.]{1,50}$/;
            
            if (!pattern.test(username)) {
                errorDiv.textContent = 'Only letters, numbers, underscores, hyphens, and dots are allowed (max 50 chars)';
                errorDiv.style.display = 'block';
                return false;
            } else {
                errorDiv.style.display = 'none';
                return true;
            }
        }

        function validateEmail(input) {
            const errorDiv = document.getElementById('email-error');
            const email = input.value.trim();
            const pattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            
            if (!pattern.test(email)) {
                errorDiv.textContent = 'Please enter a valid email address';
                errorDiv.style.display = 'block';
                return false;
            } else {
                errorDiv.style.display = 'none';
                return true;
            }
        }

        // Password strength checker
        function checkPasswordStrength(password) {
            const bar = document.getElementById('password-strength-bar');
            const text = document.getElementById('password-strength-text');
            let strength = 0;
            
            if (password.length >= 6) strength++;
            if (password.length >= 8) strength++;
            if (/[a-z]/.test(password)) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^a-zA-Z0-9]/.test(password)) strength++;
            
            let strengthLevel = '';
            let width = 0;
            
            if (strength <= 2) {
                strengthLevel = 'Weak';
                width = 33;
                bar.className = 'strength-bar strength-weak';
            } else if (strength <= 4) {
                strengthLevel = 'Medium';
                width = 66;
                bar.className = 'strength-bar strength-medium';
            } else {
                strengthLevel = 'Strong';
                width = 100;
                bar.className = 'strength-bar strength-strong';
            }
            
            bar.style.width = width + '%';
            text.textContent = 'Password strength: ' + strengthLevel;
        }

        function checkResetPasswordStrength(password) {
            const bar = document.getElementById('reset-password-strength-bar');
            const text = document.getElementById('reset-password-strength-text');
            let strength = 0;
            
            if (password.length >= 6) strength++;
            if (password.length >= 8) strength++;
            if (/[a-z]/.test(password)) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^a-zA-Z0-9]/.test(password)) strength++;
            
            let strengthLevel = '';
            let width = 0;
            
            if (strength <= 2) {
                strengthLevel = 'Weak';
                width = 33;
                bar.className = 'strength-bar strength-weak';
            } else if (strength <= 4) {
                strengthLevel = 'Medium';
                width = 66;
                bar.className = 'strength-bar strength-medium';
            } else {
                strengthLevel = 'Strong';
                width = 100;
                bar.className = 'strength-bar strength-strong';
            }
            
            bar.style.width = width + '%';
            text.textContent = 'Password strength: ' + strengthLevel;
        }

        // Form validation for registration
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const username = document.getElementById('username');
            const email = document.getElementById('email');
            const password = document.getElementById('password');
            
            if (!validateUsername(username) || !validateEmail(email) || password.value.length < 6) {
                e.preventDefault();
                alert('Please fix the form errors before submitting.');
            }
        });

        // Close modals when clicking outside
        window.onclick = function(event) {
            const editUserModal = document.getElementById('editUserModal');
            const resetPasswordModal = document.getElementById('resetPasswordModal');
            
            if (event.target === editUserModal) {
                closeEditUserModal();
            }
            if (event.target === resetPasswordModal) {
                closeResetPasswordModal();
            }
        }

        // Auto-focus on first input in modals
        function focusFirstInput(modalId) {
            const modal = document.getElementById(modalId);
            const firstInput = modal.querySelector('input:not([type="hidden"])');
            if (firstInput) {
                firstInput.focus();
            }
        }

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        // Add confirmation for dangerous actions
        document.addEventListener('click', function(e) {
            if (e.target && e.target.classList.contains('btn-danger')) {
                if (!confirm('Are you sure you want to perform this action? This cannot be undone.')) {
                    e.preventDefault();
                }
            }
        });
    </script>
</body>
</html>