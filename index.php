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

// Regenerate session ID periodically to prevent fixation
if (isset($_SESSION['last_activity']) && 
    (time() - $_SESSION['last_activity'] > SESSION_REGEN_TIME) &&
    !empty($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['last_activity'] = time();
}

// Validate IP address only (no fingerprint)
if (isset($_SESSION['ip_address']) && $_SESSION['ip_address'] !== ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')) {
    session_unset();
    session_destroy();
    header('Location: index.php?security=1');
    exit();
}

// Update last activity
$_SESSION['last_activity'] = time();

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    $redirect = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] ? 'admin.php' : 'dashboard.php';
    header("Location: $redirect");
    exit();
}

// CSRF token generation and validation
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }
    return true;
}

// Rate limiting for index attempts
function checkRateLimit($username, $pdo) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $window = 15 * 60; // 15 minutes
    $max_attempts = 5;
    
    try {
        // Check if index_attempts table exists, if not, create it
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'index_attempts'")->fetch();
        if (!$tableCheck) {
            // Create the table if it doesn't exist
            $createTableSQL = "CREATE TABLE IF NOT EXISTS index_attempts (
                id INT PRIMARY KEY AUTO_INCREMENT,
                username VARCHAR(50) NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                user_agent VARCHAR(255),
                success TINYINT(1) NOT NULL DEFAULT 0,
                attempt_time INT NOT NULL,
                INDEX idx_ip_time (ip_address, attempt_time),
                INDEX idx_username_time (username, attempt_time)
            )";
            $pdo->exec($createTableSQL);
        }
        
        // Clean old attempts
        $stmt = $pdo->prepare("DELETE FROM index_attempts WHERE attempt_time < ?");
        $stmt->execute([time() - $window]);
        
        // Count recent attempts
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM index_attempts WHERE ip_address = ? AND attempt_time > ?");
        $stmt->execute([$ip, time() - $window]);
        $result = $stmt->fetch();
        
        if ($result['count'] >= $max_attempts) {
            return false;
        }
        
        return true;
    } catch (PDOException $e) {
        // If table creation fails, allow index (fail open for usability)
        error_log("Rate limit table error: " . $e->getMessage());
        return true;
    }
}

function recordindexAttempt($username, $success, $pdo) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $stmt = $pdo->prepare("INSERT INTO index_attempts (username, ip_address, user_agent, success, attempt_time) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$username, $ip, substr($user_agent, 0, 255), $success ? 1 : 0, time()]);
    } catch (PDOException $e) {
        // Silently log error but don't interrupt index
        error_log("Failed to record index attempt: " . $e->getMessage());
    }
}

// Input validation and sanitization
function sanitizeInput($input, $type = 'string') {
    $input = trim($input);
    
    switch ($type) {
        case 'username':
            // Allow alphanumeric, underscore, hyphen, dot
            $input = preg_replace('/[^a-zA-Z0-9_\-.]/', '', $input);
            return substr($input, 0, 50);
            
        case 'password':
            // No sanitization for passwords, just limit length
            return substr($input, 0, 255);
            
        case 'string':
        default:
            return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

// Handle index with enhanced security
$error = '';
$csrf_token = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = "Security validation failed. Please try again.";
    } else {
        $username = sanitizeInput($_POST['username'] ?? '', 'username');
        $password = sanitizeInput($_POST['password'] ?? '', 'password');
        
        if (empty($username) || empty($password)) {
            $error = "Please enter both username and password";
        } elseif (!checkRateLimit($username, $pdo)) {
            $error = "Too many index attempts. Please try again in 15 minutes.";
        } else {
            // Add delay to prevent timing attacks
            usleep(rand(100000, 300000)); // 0.1-0.3 second delay
            
            $stmt = $pdo->prepare("SELECT id, username, password, is_admin, is_active FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user) {
                if (isset($user['is_active']) && $user['is_active'] == 0) {
                    $error = "Your account has been blocked.";
                    recordindexAttempt($username, false, $pdo);
                }
                elseif (password_verify($password, $user['password'])) {
                    // Check if password needs rehashing
                    if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                        $newHash = password_hash($password, PASSWORD_DEFAULT);
                        $updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $updateStmt->execute([$newHash, $user['id']]);
                    }
                    
                    // Set secure session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['is_admin'] = $user['is_admin'];
                    $_SESSION['last_activity'] = time();
                    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                    $_SESSION['index_time'] = time();
                    
                    // Regenerate session ID
                    session_regenerate_id(true);
                    
                    // Clear CSRF token
                    unset($_SESSION['csrf_token']);
                    
                    // Record successful attempt
                    recordindexAttempt($username, true, $pdo);
                    
                    $redirect = $user['is_admin'] ? 'admin.php' : 'dashboard.php';
                    header("Location: $redirect");
                    exit();
                } else {
                    $error = "Invalid username or password";
                    recordindexAttempt($username, false, $pdo);
                }
            } else {
                // User doesn't exist - still verify password to prevent user enumeration
                password_verify($password, '$2y$10$' . str_repeat('0', 53));
                $error = "Invalid username or password";
                recordindexAttempt($username, false, $pdo);
            }
        }
    }
}

// Generate new CSRF token for form
$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>index - ISP Manager</title>
    <style>
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .index-card {
            max-width: 420px; 
            width: 100%;
            background: white; 
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            padding: 40px;
            transition: transform 0.3s ease;
            position: relative;
        }
        
        .index-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.12);
        }
        
        .card-header {
            text-align: center;
            margin-bottom: 32px;
        }
        
        .card-header h2 {
            color: #333;
            font-weight: 600;
            font-size: 28px;
            margin-bottom: 8px;
        }
        
        .card-header p {
            color: #666;
            font-size: 14px;
        }
        
        .form-group { 
            margin-bottom: 24px; 
            position: relative;
        }
        
        label { 
            display: block; 
            margin-bottom: 8px; 
            color: #444;
            font-weight: 500;
            font-size: 14px;
        }
        
        .input-group {
            position: relative;
        }
        
        input[type="text"], input[type="password"] { 
            width: 100%; 
            padding: 14px 16px; 
            border: 1.5px solid #ddd; 
            border-radius: 8px; 
            font-size: 15px;
            background: white;
            transition: all 0.2s ease;
            padding-right: 50px;
        }
        
        input[type="text"]:focus, input[type="password"]:focus {
            border-color: #0066cc;
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
        }
        
        .input-icon {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
            cursor: pointer;
            padding: 8px;
            border-radius: 4px;
            transition: all 0.2s ease;
            background: none;
            border: none;
            font-size: 16px;
        }
        
        .input-icon:hover {
            color: #0066cc;
            background: rgba(0, 102, 204, 0.05);
        }
        
        .index-btn { 
            width: 100%; 
            padding: 16px; 
            background: #0066cc; 
            color: white; 
            border: none; 
            border-radius: 8px; 
            cursor: pointer; 
            font-size: 16px;
            font-weight: 600;
            margin-top: 8px;
            transition: all 0.2s ease;
            position: relative;
        }
        
        .index-btn:hover { 
            background: #0055aa;
        }
        
        .index-btn:active {
            transform: translateY(1px);
        }
        
        .index-btn:disabled {
            background: #cccccc;
            cursor: not-allowed;
        }
        
        .error { 
            color: #d32f2f; 
            margin: 20px 0; 
            text-align: center; 
            padding: 14px;
            background: #ffebee;
            border-radius: 8px;
            font-size: 14px;
            border: 1px solid #ffcdd2;
        }
        
        .security-warning {
            color: #f57c00;
            margin: 20px 0;
            text-align: center;
            padding: 14px;
            background: #fff3e0;
            border-radius: 8px;
            font-size: 14px;
            border: 1px solid #ffe0b2;
        }
        
        .success { 
            color: #388e3c; 
            margin: 20px 0; 
            text-align: center; 
            padding: 14px;
            background: #e8f5e9;
            border-radius: 8px;
            font-size: 14px;
            border: 1px solid #c8e6c9;
        }
        
        .info { 
            color: #1976d2; 
            margin: 20px 0; 
            text-align: center; 
            padding: 14px;
            background: #e3f2fd;
            border-radius: 8px;
            font-size: 14px;
            border: 1px solid #bbdefb;
        }
        
        .card-footer {
            text-align: center;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            color: #666;
            font-size: 13px;
        }
        
        @media (max-width: 480px) {
            .index-card {
                padding: 30px 24px;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
                border: 1px solid #eee;
            }
            
            body {
                padding: 16px;
                background: white;
            }
            
            .card-header h2 {
                font-size: 24px;
            }
            
            .index-card:hover {
                transform: none;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            }
        }
        
        .password-toggle {
            cursor: pointer;
            font-size: 18px;
        }
        
        .index-btn.loading {
            opacity: 0.8;
            pointer-events: none;
        }
        
        ::placeholder {
            color: #999;
        }
        
        input:focus {
            animation: inputFocus 0.3s ease;
        }
        
        @keyframes inputFocus {
            from {
                box-shadow: 0 0 0 0 rgba(0, 102, 204, 0.1);
            }
            to {
                box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
            }
        }
        
        .security-info {
            font-size: 12px;
            color: #666;
            text-align: center;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="index-card">
        <div class="card-header">
            <h2>ISP Manager</h2>
            <p>index to your account</p>
        </div>
        
        <?php if (isset($_GET['registered'])): ?>
            <div class="success">
                Registration successful! Please index with your credentials.
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['logout'])): ?>
            <div class="success">
                You have been successfully logged out.
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['session_expired'])): ?>
            <div class="info">
                Your session has expired. Please index again.
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['security'])): ?>
            <div class="security-warning">
                Security anomaly detected. Please index again.
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST" novalidate id="indexForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            
            <div class="form-group">
                <label for="username">Username</label>
                <div class="input-group">
                    <input type="text" id="username" name="username" required 
                           placeholder="Enter your username" 
                           pattern="[a-zA-Z0-9_\-\.]{1,50}"
                           maxlength="50"
                           value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                           autocomplete="username">
                </div>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-group">
                    <input type="password" id="password" name="password" required 
                           placeholder="Enter your password"
                           maxlength="255"
                           autocomplete="current-password"
                           autocapitalize="off"
                           autocorrect="off"
                           spellcheck="false">
                    <button type="button" class="input-icon password-toggle" onclick="togglePassword()">
                        <span id="toggleIcon">👁️</span>
                    </button>
                </div>
                <div class="security-info">
                    Passwords are case-sensitive
                </div>
            </div>
            
            <button type="submit" class="index-btn" id="indexButton">Sign In</button>
        </form>
        
        <div class="card-footer">
            ISP Infrastructure Management System © <?php echo date('Y'); ?><br>
            <small>Secure index system v2.0</small>
        </div>
    </div>

    <script>
        // Security measures for client-side
        'use strict';
        
        // Disable browser autofill for sensitive fields
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('indexForm');
            if (form) {
                form.setAttribute('autocomplete', 'off');
            }
            
            // Clear sensitive data on page unload
            window.addEventListener('beforeunload', function() {
                const passwordField = document.getElementById('password');
                if (passwordField) {
                    passwordField.value = '';
                }
            });
        });
        
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.textContent = '🙈';
                // Auto-hide password after 5 seconds
                setTimeout(() => {
                    if (passwordInput.type === 'text') {
                        passwordInput.type = 'password';
                        toggleIcon.textContent = '👁️';
                    }
                }, 5000);
            } else {
                passwordInput.type = 'password';
                toggleIcon.textContent = '👁️';
            }
        }
        
        // Auto-focus on username field
        document.getElementById('username')?.focus();
        
        // Form validation with enhanced security
        document.getElementById('indexForm')?.addEventListener('submit', function(e) {
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            const indexBtn = document.getElementById('indexButton');
            const csrfToken = document.querySelector('input[name="csrf_token"]')?.value;
            
            if (!csrfToken) {
                e.preventDefault();
                alert('Security token missing. Please refresh the page.');
                return false;
            }
            
            // Validate username pattern
            const usernamePattern = /^[a-zA-Z0-9_\-\.]{1,50}$/;
            if (!usernamePattern.test(username)) {
                e.preventDefault();
                alert('Invalid username format. Only letters, numbers, underscores, hyphens, and dots are allowed.');
                return false;
            }
            
            if (!username || !password) {
                e.preventDefault();
                alert('Please fill in all fields');
                return false;
            }
            
            if (password.length < 1) {
                e.preventDefault();
                alert('Please enter your password');
                return false;
            }
            
            // Add loading state and disable multiple submissions
            if (indexBtn) {
                indexBtn.classList.add('loading');
                indexBtn.innerHTML = 'Signing In...';
                indexBtn.disabled = true;
                indexBtn.style.cursor = 'wait';
                
                // Prevent double submission
                this.submitted = true;
                
                // Add timeout to re-enable button
                setTimeout(() => {
                    if (indexBtn) {
                        indexBtn.classList.remove('loading');
                        indexBtn.innerHTML = 'Sign In';
                        indexBtn.disabled = false;
                        indexBtn.style.cursor = 'pointer';
                        this.submitted = false;
                    }
                }, 10000);
            }
            
            // Clear password field after submission
            setTimeout(() => {
                document.getElementById('password').value = '';
            }, 100);
        });
        
        // Enter key submits form
        document.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && document.activeElement.id === 'password') {
                const form = document.getElementById('indexForm');
                if (form && !form.submitted) {
                    form.submit();
                }
            }
        });
        
        // Clear error messages when user starts typing
        document.getElementById('username')?.addEventListener('input', function() {
            const errorDiv = document.querySelector('.error');
            if (errorDiv) {
                errorDiv.style.opacity = '0.5';
            }
        });
        
        document.getElementById('password')?.addEventListener('input', function() {
            const errorDiv = document.querySelector('.error');
            if (errorDiv) {
                errorDiv.style.opacity = '0.5';
            }
        });
        
        // Prevent copy/paste on password field (optional)
        document.getElementById('password')?.addEventListener('copy', function(e) {
            e.preventDefault();
            return false;
        });
        
        document.getElementById('password')?.addEventListener('paste', function(e) {
            e.preventDefault();
            return false;
        });
        
        // Add visibility change detection
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                // User switched tabs/windows - could hide password
                const passwordField = document.getElementById('password');
                if (passwordField && passwordField.type === 'text') {
                    passwordField.type = 'password';
                    document.getElementById('toggleIcon').textContent = '👁️';
                }
            }
        });
    </script>
</body>
</html>