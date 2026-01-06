<?php
// admin_logs.php
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
    header("Location: index.php");
    exit();
}

// Check session timeout
if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
    session_unset();
    session_destroy();
    header('Location: index.php?session_expired=1');
    exit();
}

// Update last activity
$_SESSION['last_activity'] = time();

// Validate IP address (prevent session hijacking)
if (isset($_SESSION['ip_address']) && $_SESSION['ip_address'] !== ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')) {
    session_unset();
    session_destroy();
    header('Location: index.php?security=1');
    exit();
}

// Check if user is admin
$user = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$user->execute([$_SESSION['user_id']]);
$user = $user->fetch();

// Check if user exists and is admin
if (!$user || $user['is_admin'] != 1) {
    header("Location: dashboard.php");
    exit();
}

// Create necessary tables if they don't exist
function createLogTables($pdo) {
    try {
        // Create system_logs table
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_logs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            log_type VARCHAR(50) NOT NULL,
            user_id INT,
            username VARCHAR(100),
            ip_address VARCHAR(45),
            user_agent TEXT,
            action VARCHAR(255) NOT NULL,
            details TEXT,
            severity ENUM('info', 'warning', 'error', 'critical') DEFAULT 'info',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_log_type (log_type),
            INDEX idx_user_id (user_id),
            INDEX idx_created_at (created_at),
            INDEX idx_severity (severity)
        )");
        
        // Create admin_actions table if it doesn't exist
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_actions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            admin_id INT NOT NULL,
            admin_username VARCHAR(100),
            action_type VARCHAR(50) NOT NULL,
            target_user_id INT,
            target_username VARCHAR(100),
            ip_address VARCHAR(45) NOT NULL,
            user_agent TEXT,
            action_time INT NOT NULL,
            details TEXT,
            INDEX idx_admin_time (admin_id, action_time),
            INDEX idx_ip_time (ip_address, action_time),
            INDEX idx_action_type (action_type)
        )");
        
        // Create index_attempts table if it doesn't exist
        $pdo->exec("CREATE TABLE IF NOT EXISTS index_attempts (
            id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(50) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            user_agent VARCHAR(255),
            success TINYINT(1) NOT NULL DEFAULT 0,
            attempt_time INT NOT NULL,
            INDEX idx_ip_time (ip_address, attempt_time),
            INDEX idx_username_time (username, attempt_time)
        )");
        
        // Create security_logs table
        $pdo->exec("CREATE TABLE IF NOT EXISTS security_logs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            event_type VARCHAR(50) NOT NULL,
            user_id INT,
            username VARCHAR(100),
            ip_address VARCHAR(45),
            description TEXT,
            severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event_type (event_type),
            INDEX idx_severity_created (severity, created_at)
        )");
        
        return true;
    } catch (PDOException $e) {
        error_log("Failed to create log tables: " . $e->getMessage());
        return false;
    }
}

// Initialize tables
createLogTables($pdo);

// Handle log clearing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_logs'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $error = "Security validation failed!";
    } else {
        $log_type = $_POST['log_type'] ?? 'all';
        $days = (int) ($_POST['days'] ?? 30);
        
        try {
            $timestamp = time() - ($days * 24 * 60 * 60);
            
            switch ($log_type) {
                case 'system':
                    $pdo->prepare("DELETE FROM system_logs WHERE created_at < FROM_UNIXTIME(?)")->execute([$timestamp]);
                    $message = "System logs older than $days days cleared successfully!";
                    break;
                case 'admin':
                    $pdo->prepare("DELETE FROM admin_actions WHERE action_time < ?")->execute([$timestamp]);
                    $message = "Admin actions older than $days days cleared successfully!";
                    break;
                case 'index':
                    $pdo->prepare("DELETE FROM index_attempts WHERE attempt_time < ?")->execute([$timestamp]);
                    $message = "index attempts older than $days days cleared successfully!";
                    break;
                case 'security':
                    $pdo->prepare("DELETE FROM security_logs WHERE created_at < FROM_UNIXTIME(?)")->execute([$timestamp]);
                    $message = "Security logs older than $days days cleared successfully!";
                    break;
                case 'all':
                    $pdo->prepare("DELETE FROM system_logs WHERE created_at < FROM_UNIXTIME(?)")->execute([$timestamp]);
                    $pdo->prepare("DELETE FROM admin_actions WHERE action_time < ?")->execute([$timestamp]);
                    $pdo->prepare("DELETE FROM index_attempts WHERE attempt_time < ?")->execute([$timestamp]);
                    $pdo->prepare("DELETE FROM security_logs WHERE created_at < FROM_UNIXTIME(?)")->execute([$timestamp]);
                    $message = "All logs older than $days days cleared successfully!";
                    break;
                default:
                    $error = "Invalid log type specified!";
                    break;
            }
            
            if (!isset($error)) {
                // Log the clearing action
                $stmt = $pdo->prepare("INSERT INTO admin_actions (admin_id, admin_username, action_type, ip_address, user_agent, action_time, details) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $_SESSION['user_id'],
                    $user['username'],
                    'clear_logs',
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT'] ?? '',
                    time(),
                    "Cleared $log_type logs older than $days days"
                ]);
                
                $success = $message;
            }
        } catch (PDOException $e) {
            $error = "Failed to clear logs: " . $e->getMessage();
        }
    }
}

// Handle export logs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_logs'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $error = "Security validation failed!";
    } else {
        $log_type = $_POST['export_type'] ?? 'system';
        $format = $_POST['export_format'] ?? 'csv';
        
        // Generate export data
        try {
            switch ($log_type) {
                case 'system':
                    $logs = $pdo->query("SELECT * FROM system_logs ORDER BY created_at DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
                    $filename = "system_logs_" . date('Y-m-d') . ".$format";
                    break;
                case 'admin':
                    $logs = $pdo->query("SELECT * FROM admin_actions ORDER BY action_time DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
                    $filename = "admin_actions_" . date('Y-m-d') . ".$format";
                    break;
                case 'index':
                    $logs = $pdo->query("SELECT * FROM index_attempts ORDER BY attempt_time DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
                    $filename = "index_attempts_" . date('Y-m-d') . ".$format";
                    break;
                case 'security':
                    $logs = $pdo->query("SELECT * FROM security_logs ORDER BY created_at DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
                    $filename = "security_logs_" . date('Y-m-d') . ".$format";
                    break;
                default:
                    $error = "Invalid log type for export!";
                    break;
            }
            
            if (!isset($error) && !empty($logs)) {
                if ($format === 'csv') {
                    header('Content-Type: text/csv');
                    header('Content-Disposition: attachment; filename="' . $filename . '"');
                    
                    $output = fopen('php://output', 'w');
                    
                    // Add headers
                    if (!empty($logs)) {
                        fputcsv($output, array_keys($logs[0]));
                        
                        // Add data
                        foreach ($logs as $log) {
                            fputcsv($output, $log);
                        }
                    }
                    
                    fclose($output);
                    exit;
                } else {
                    // JSON format
                    header('Content-Type: application/json');
                    header('Content-Disposition: attachment; filename="' . $filename . '"');
                    echo json_encode($logs, JSON_PRETTY_PRINT);
                    exit;
                }
            } else {
                $error = "No logs found to export!";
            }
        } catch (PDOException $e) {
            $error = "Failed to export logs: " . $e->getMessage();
        }
    }
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Get statistics
function getLogStats($pdo) {
    $stats = [];
    
    try {
        // System logs stats
        $stats['system_total'] = $pdo->query("SELECT COUNT(*) as count FROM system_logs")->fetch()['count'];
        $stats['system_today'] = $pdo->query("SELECT COUNT(*) as count FROM system_logs WHERE DATE(created_at) = CURDATE()")->fetch()['count'];
        $stats['system_errors'] = $pdo->query("SELECT COUNT(*) as count FROM system_logs WHERE severity = 'error' OR severity = 'critical'")->fetch()['count'];
        
        // Admin actions stats
        $stats['admin_total'] = $pdo->query("SELECT COUNT(*) as count FROM admin_actions")->fetch()['count'];
        $stats['admin_today'] = $pdo->query("SELECT COUNT(*) as count FROM admin_actions WHERE DATE(FROM_UNIXTIME(action_time)) = CURDATE()")->fetch()['count'];
        
        // index attempts stats
        $stats['index_total'] = $pdo->query("SELECT COUNT(*) as count FROM index_attempts")->fetch()['count'];
        $stats['index_failed'] = $pdo->query("SELECT COUNT(*) as count FROM index_attempts WHERE success = 0")->fetch()['count'];
        $stats['index_today'] = $pdo->query("SELECT COUNT(*) as count FROM index_attempts WHERE DATE(FROM_UNIXTIME(attempt_time)) = CURDATE()")->fetch()['count'];
        
        // Security logs stats
        $stats['security_total'] = $pdo->query("SELECT COUNT(*) as count FROM security_logs")->fetch()['count'];
        $stats['security_critical'] = $pdo->query("SELECT COUNT(*) as count FROM security_logs WHERE severity = 'critical'")->fetch()['count'];
        $stats['security_today'] = $pdo->query("SELECT COUNT(*) as count FROM security_logs WHERE DATE(created_at) = CURDATE()")->fetch()['count'];
        
    } catch (PDOException $e) {
        error_log("Failed to get log stats: " . $e->getMessage());
    }
    
    return $stats;
}

$log_stats = getLogStats($pdo);

// Get filter parameters
$filter_type = $_GET['type'] ?? 'all';
$filter_date = $_GET['date'] ?? '';
$filter_user = $_GET['user'] ?? '';
$filter_severity = $_GET['severity'] ?? '';
$filter_ip = $_GET['ip'] ?? '';
$limit = (int) ($_GET['limit'] ?? 100);
$page = (int) ($_GET['page'] ?? 1);
$offset = ($page - 1) * $limit;

// Build filter query
function buildFilterQuery($type, $filters) {
    $where = [];
    $params = [];
    
    if (!empty($filters['date'])) {
        if ($type === 'admin') {
            $where[] = "DATE(FROM_UNIXTIME(action_time)) = ?";
        } elseif ($type === 'index') {
            $where[] = "DATE(FROM_UNIXTIME(attempt_time)) = ?";
        } else {
            $where[] = "DATE(created_at) = ?";
        }
        $params[] = $filters['date'];
    }
    
    if (!empty($filters['user'])) {
        if ($type === 'system' || $type === 'security') {
            $where[] = "(username LIKE ? OR user_id = ?)";
            $params[] = "%" . $filters['user'] . "%";
            $params[] = (int) $filters['user'];
        } elseif ($type === 'admin') {
            $where[] = "(admin_username LIKE ? OR admin_id = ? OR target_username LIKE ? OR target_user_id = ?)";
            $params[] = "%" . $filters['user'] . "%";
            $params[] = (int) $filters['user'];
            $params[] = "%" . $filters['user'] . "%";
            $params[] = (int) $filters['user'];
        } elseif ($type === 'index') {
            $where[] = "username LIKE ?";
            $params[] = "%" . $filters['user'] . "%";
        }
    }
    
    if (!empty($filters['severity']) && in_array($type, ['system', 'security'])) {
        $where[] = "severity = ?";
        $params[] = $filters['severity'];
    }
    
    if (!empty($filters['ip'])) {
        $where[] = "ip_address LIKE ?";
        $params[] = "%" . $filters['ip'] . "%";
    }
    
    $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";
    
    return ['where' => $whereClause, 'params' => $params];
}

// Get filtered logs
function getFilteredLogs($pdo, $type, $filters, $limit, $offset) {
    $logs = [];
    $total = 0;
    
    try {
        switch ($type) {
            case 'system':
                $filter = buildFilterQuery($type, $filters);
                $query = "SELECT SQL_CALC_FOUND_ROWS * FROM system_logs {$filter['where']} ORDER BY created_at DESC LIMIT ? OFFSET ?";
                $params = array_merge($filter['params'], [$limit, $offset]);
                
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $total = $pdo->query("SELECT FOUND_ROWS()")->fetch()[0];
                break;
                
            case 'admin':
                $filter = buildFilterQuery($type, $filters);
                $query = "SELECT SQL_CALC_FOUND_ROWS * FROM admin_actions {$filter['where']} ORDER BY action_time DESC LIMIT ? OFFSET ?";
                $params = array_merge($filter['params'], [$limit, $offset]);
                
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $total = $pdo->query("SELECT FOUND_ROWS()")->fetch()[0];
                break;
                
            case 'index':
                $filter = buildFilterQuery($type, $filters);
                $query = "SELECT SQL_CALC_FOUND_ROWS * FROM index_attempts {$filter['where']} ORDER BY attempt_time DESC LIMIT ? OFFSET ?";
                $params = array_merge($filter['params'], [$limit, $offset]);
                
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $total = $pdo->query("SELECT FOUND_ROWS()")->fetch()[0];
                break;
                
            case 'security':
                $filter = buildFilterQuery($type, $filters);
                $query = "SELECT SQL_CALC_FOUND_ROWS * FROM security_logs {$filter['where']} ORDER BY created_at DESC LIMIT ? OFFSET ?";
                $params = array_merge($filter['params'], [$limit, $offset]);
                
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $total = $pdo->query("SELECT FOUND_ROWS()")->fetch()[0];
                break;
                
            case 'all':
                // Get mixed recent logs from all tables
                $mixed_logs = [];
                
                // System logs
                $system_query = "SELECT *, 'system' as log_type, created_at as timestamp FROM system_logs";
                if (!empty($filters['date']) || !empty($filters['user']) || !empty($filters['severity']) || !empty($filters['ip'])) {
                    $system_filter = buildFilterQuery('system', $filters);
                    $system_query .= " {$system_filter['where']}";
                    $system_params = $system_filter['params'];
                } else {
                    $system_params = [];
                }
                $system_query .= " ORDER BY created_at DESC LIMIT 20";
                
                $system_stmt = $pdo->prepare($system_query);
                $system_stmt->execute($system_params);
                $system_logs = $system_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Admin actions
                $admin_query = "SELECT *, 'admin' as log_type, action_time as timestamp FROM admin_actions";
                if (!empty($filters['date']) || !empty($filters['user']) || !empty($filters['ip'])) {
                    $admin_filter = buildFilterQuery('admin', $filters);
                    $admin_query .= " {$admin_filter['where']}";
                    $admin_params = $admin_filter['params'];
                } else {
                    $admin_params = [];
                }
                $admin_query .= " ORDER BY action_time DESC LIMIT 20";
                
                $admin_stmt = $pdo->prepare($admin_query);
                $admin_stmt->execute($admin_params);
                $admin_logs = $admin_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // index attempts
                $index_query = "SELECT *, 'index' as log_type, attempt_time as timestamp FROM index_attempts";
                if (!empty($filters['date']) || !empty($filters['user']) || !empty($filters['ip'])) {
                    $index_filter = buildFilterQuery('index', $filters);
                    $index_query .= " {$index_filter['where']}";
                    $index_params = $index_filter['params'];
                } else {
                    $index_params = [];
                }
                $index_query .= " ORDER BY attempt_time DESC LIMIT 20";
                
                $index_stmt = $pdo->prepare($index_query);
                $index_stmt->execute($index_params);
                $index_logs = $index_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Security logs
                $security_query = "SELECT *, 'security' as log_type, created_at as timestamp FROM security_logs";
                if (!empty($filters['date']) || !empty($filters['user']) || !empty($filters['severity']) || !empty($filters['ip'])) {
                    $security_filter = buildFilterQuery('security', $filters);
                    $security_query .= " {$security_filter['where']}";
                    $security_params = $security_filter['params'];
                } else {
                    $security_params = [];
                }
                $security_query .= " ORDER BY created_at DESC LIMIT 20";
                
                $security_stmt = $pdo->prepare($security_query);
                $security_stmt->execute($security_params);
                $security_logs = $security_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Combine all logs
                $mixed_logs = array_merge($system_logs, $admin_logs, $index_logs, $security_logs);
                
                // Sort by timestamp
                usort($mixed_logs, function($a, $b) {
                    $timeA = $a['timestamp'] ?? ($a['action_time'] ?? $a['attempt_time'] ?? $a['created_at'] ?? 0);
                    $timeB = $b['timestamp'] ?? ($b['action_time'] ?? $b['attempt_time'] ?? $b['created_at'] ?? 0);
                    
                    // Convert to timestamp if not already
                    if (!is_numeric($timeA)) {
                        $timeA = strtotime($timeA);
                    }
                    if (!is_numeric($timeB)) {
                        $timeB = strtotime($timeB);
                    }
                    
                    return $timeB - $timeA;
                });
                
                $logs = array_slice($mixed_logs, $offset, $limit);
                $total = count($mixed_logs);
                break;
                
            default:
                return ['logs' => [], 'total' => 0];
        }
        
    } catch (PDOException $e) {
        error_log("Failed to get filtered logs: " . $e->getMessage());
        return ['logs' => [], 'total' => 0];
    }
    
    return ['logs' => $logs, 'total' => $total];
}

$filters = [
    'date' => $filter_date,
    'user' => $filter_user,
    'severity' => $filter_severity,
    'ip' => $filter_ip
];

$filtered_data = getFilteredLogs($pdo, $filter_type, $filters, $limit, $offset);
$logs = $filtered_data['logs'];
$total_logs = $filtered_data['total'];
$total_pages = ceil($total_logs / $limit);

// Get unique values for filter dropdowns
function getUniqueValues($pdo, $table, $column) {
    try {
        $result = $pdo->query("SELECT DISTINCT $column FROM $table WHERE $column IS NOT NULL ORDER BY $column")->fetchAll(PDO::FETCH_COLUMN);
        return $result;
    } catch (PDOException $e) {
        return [];
    }
}

$unique_severities = getUniqueValues($pdo, 'system_logs', 'severity');
$unique_users = array_merge(
    getUniqueValues($pdo, 'admin_actions', 'admin_username'),
    getUniqueValues($pdo, 'admin_actions', 'target_username'),
    getUniqueValues($pdo, 'index_attempts', 'username')
);
$unique_users = array_unique($unique_users);
sort($unique_users);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Logs & Monitoring - Admin Panel</title>
    <style>
        /* Reset and Base Styles */
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f5f7fa; 
            color: #2c3e50;
            line-height: 1.6;
            min-height: 100vh;
        }
        
        /* Header Styles */
        .header { 
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%); 
            color: white; 
            padding: 18px 25px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header h1 {
            font-size: 24px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        
        .header div {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .header span {
            font-size: 14px;
            color: #ecf0f1;
            margin-right: 10px;
        }
        
        /* Container */
        .container { 
            max-width: 1400px; 
            margin: 0 auto; 
            padding: 25px; 
        }
        
        /* ==================== BUTTON STYLES ==================== */
        
        /* Base Button Styles */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.3s ease;
            text-align: center;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .btn i {
            font-size: 16px;
        }
        
        /* Button Sizes */
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
            gap: 4px;
        }
        
        .btn-lg {
            padding: 14px 28px;
            font-size: 16px;
            gap: 8px;
        }
        
        /* Button Variants */
        .btn-primary {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
            border: 1px solid #2980b9;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #2980b9 0%, #1f639e 100%);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
            border: 1px solid #c0392b;
        }
        
        .btn-danger:hover {
            background: linear-gradient(135deg, #c0392b 0%, #a93226 100%);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #27ae60 0%, #219653 100%);
            color: white;
            border: 1px solid #219653;
        }
        
        .btn-success:hover {
            background: linear-gradient(135deg, #219653 0%, #1e8449 100%);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #f39c12 0%, #d68910 100%);
            color: white;
            border: 1px solid #d68910;
        }
        
        .btn-warning:hover {
            background: linear-gradient(135deg, #d68910 0%, #b9770e 100%);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #95a5a6 0%, #7f8c8d 100%);
            color: white;
            border: 1px solid #7f8c8d;
        }
        
        .btn-secondary:hover {
            background: linear-gradient(135deg, #7f8c8d 0%, #6c7b7d 100%);
        }
        
        .btn-info {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            border: 1px solid #138496;
        }
        
        .btn-info:hover {
            background: linear-gradient(135deg, #138496 0%, #117a8b 100%);
        }
        
        /* Header Buttons */
        .logout-btn { 
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            padding: 10px 18px;
            border-radius: 6px;
            text-decoration: none;
            color: white;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: 1px solid #c0392b;
        }
        
        .logout-btn:hover { 
            background: linear-gradient(135deg, #c0392b 0%, #a93226 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(192, 57, 43, 0.2);
        }
        
        .back-btn {
            background: linear-gradient(135deg, #95a5a6 0%, #7f8c8d 100%);
            padding: 10px 18px;
            border-radius: 6px;
            text-decoration: none;
            color: white;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: 1px solid #7f8c8d;
        }
        
        .back-btn:hover {
            background: linear-gradient(135deg, #7f8c8d 0%, #6c7b7d 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(127, 140, 141, 0.2);
        }
        
        .logs-btn {
            background: linear-gradient(135deg, #9b59b6 0%, #8e44ad 100%);
            padding: 10px 18px;
            border-radius: 6px;
            text-decoration: none;
            color: white;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: 1px solid #8e44ad;
        }
        
        .logs-btn:hover {
            background: linear-gradient(135deg, #8e44ad 0%, #7d3c98 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(142, 68, 173, 0.2);
        }
        
        /* Action Buttons Container */
        .action-buttons {
            display: flex;
            gap: 12px;
            margin: 20px 0;
            flex-wrap: wrap;
        }
        
        /* ==================== END BUTTON STYLES ==================== */
        
        /* Statistics Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border-left: 5px solid #3498db;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, transparent, rgba(52, 152, 219, 0.3), transparent);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        
        .stat-number {
            font-size: 36px;
            font-weight: 700;
            display: block;
            margin-bottom: 8px;
            background: linear-gradient(135deg, #2c3e50, #3498db);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stat-system { border-color: #3498db; }
        .stat-admin { border-color: #9b59b6; }
        .stat-index { border-color: #e74c3c; }
        .stat-security { border-color: #f39c12; }
        .stat-error { border-color: #e74c3c; }
        .stat-critical { border-color: #c0392b; }
        
        .stat-label {
            font-size: 14px;
            color: #7f8c8d;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        
        .stat-card small {
            font-size: 12px;
            color: #95a5a6;
            display: block;
            margin-top: 8px;
        }
        
        /* Section Titles */
        .section-title {
            color: #2c3e50;
            margin: 30px 0 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
            font-size: 24px;
            font-weight: 700;
            position: relative;
        }
        
        .section-title::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 100px;
            height: 2px;
            background: linear-gradient(90deg, #3498db, transparent);
        }
        
        .section-title small {
            font-size: 14px;
            color: #7f8c8d;
            font-weight: 400;
            margin-left: 10px;
        }
        
        /* Filter Panel */
        .filter-panel {
            background: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border: 1px solid #e9ecef;
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            align-items: end;
        }
        
        /* Form Elements */
        .form-group { 
            margin-bottom: 0; 
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
            padding: 12px 15px; 
            border: 2px solid #e9ecef; 
            border-radius: 6px; 
            font-size: 14px;
            transition: all 0.3s ease;
            background: #fff;
            font-family: inherit;
        }
        
        input:focus, select:focus, textarea:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        
        /* Logs Table */
        .logs-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 20px;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        
        .logs-table th,
        .logs-table td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        
        .logs-table th {
            background: linear-gradient(135deg, #34495e 0%, #2c3e50 100%);
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.5px;
            position: sticky;
            top: 0;
            white-space: nowrap;
        }
        
        .logs-table tr:last-child td {
            border-bottom: none;
        }
        
        .logs-table tr:hover {
            background: #f8fafc;
        }
        
        /* Badges */
        .badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .badge-info { background: linear-gradient(135deg, #3498db, #2980b9); color: white; }
        .badge-warning { background: linear-gradient(135deg, #f39c12, #d68910); color: white; }
        .badge-danger { background: linear-gradient(135deg, #e74c3c, #c0392b); color: white; }
        .badge-success { background: linear-gradient(135deg, #27ae60, #219653); color: white; }
        .badge-critical { background: linear-gradient(135deg, #c0392b, #a93226); color: white; }
        .badge-error { background: linear-gradient(135deg, #e74c3c, #c0392b); color: white; }
        .badge-low { background: linear-gradient(135deg, #95a5a6, #7f8c8d); color: white; }
        .badge-medium { background: linear-gradient(135deg, #f39c12, #d68910); color: white; }
        .badge-high { background: linear-gradient(135deg, #e74c3c, #c0392b); color: white; }
        
        /* Alerts */
        .alert {
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert-success {
            background: linear-gradient(135deg, #d5f4e6, #e8f6f3);
            color: #27ae60;
            border-left: 4px solid #27ae60;
        }
        
        .alert-error {
            background: linear-gradient(135deg, #fdeaea, #fdf2f2);
            color: #e74c3c;
            border-left: 4px solid #e74c3c;
        }
        
        .alert i {
            font-size: 20px;
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 30px;
            flex-wrap: wrap;
        }
        
        .page-link {
            padding: 8px 14px;
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            text-decoration: none;
            color: #3498db;
            font-weight: 600;
            transition: all 0.3s ease;
            min-width: 40px;
            text-align: center;
        }
        
        .page-link:hover, .page-link.active {
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            border-color: #3498db;
            transform: translateY(-2px);
        }
        
        /* Modals */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(3px);
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            animation: slideUp 0.3s ease;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .modal-header {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
            padding: 18px 25px;
            border-radius: 12px 12px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }
        
        .close:hover {
            background: rgba(255,255,255,0.1);
            transform: rotate(90deg);
        }
        
        .modal-body {
            padding: 25px;
            max-height: 60vh;
            overflow-y: auto;
        }
        
        .modal-footer {
            padding: 18px 25px;
            background: #f8f9fa;
            border-radius: 0 0 12px 12px;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            border-top: 1px solid #e9ecef;
        }
        
        /* Log Details */
        .log-details {
            background: #f8fafc;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 20px;
            margin-top: 10px;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            font-size: 13px;
            white-space: pre-wrap;
            max-height: 300px;
            overflow-y: auto;
            line-height: 1.5;
        }
        
        .log-details h4 {
            margin: 0 0 15px 0;
            color: #2c3e50;
            font-size: 16px;
            font-weight: 600;
            padding-bottom: 10px;
            border-bottom: 2px solid #3498db;
        }
        
        .log-details hr {
            margin: 15px 0;
            border: none;
            border-top: 1px solid #e9ecef;
        }
        
        .log-details strong {
            color: #3498db;
            display: inline-block;
            min-width: 150px;
            margin-right: 10px;
        }
        
        .log-details pre {
            background: white;
            padding: 10px;
            border-radius: 6px;
            margin: 5px 0;
            border-left: 3px solid #3498db;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 40px;
            color: #95a5a6;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin: 20px 0;
        }
        
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            display: block;
            opacity: 0.6;
        }
        
        .empty-state h3 {
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 10px;
            color: #7f8c8d;
        }
        
        .empty-state p {
            font-size: 15px;
            max-width: 400px;
            margin: 0 auto;
            line-height: 1.6;
        }
        
        /* Severity Backgrounds */
        .severity-info { background: linear-gradient(135deg, #d1ecf1, #e8f6f3); }
        .severity-warning { background: linear-gradient(135deg, #fff3cd, #fef9e7); }
        .severity-error { background: linear-gradient(135deg, #f8d7da, #fdf2f2); }
        .severity-critical { background: linear-gradient(135deg, #f5c6cb, #fdeaea); }
        
        /* Responsive Design */
        @media (max-width: 1024px) {
            .container {
                padding: 20px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
                padding: 15px;
            }
            
            .header div {
                justify-content: center;
                gap: 8px;
            }
            
            .logout-btn,
            .back-btn,
            .logs-btn {
                padding: 8px 14px;
                font-size: 13px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: stretch;
            }
            
            .action-buttons .btn {
                width: 100%;
                margin-bottom: 8px;
            }
            
            .logs-table {
                font-size: 13px;
            }
            
            .logs-table th,
            .logs-table td {
                padding: 12px 8px;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .header h1 {
                font-size: 20px;
            }
            
            .section-title {
                font-size: 20px;
            }
            
            .modal-content {
                width: 95%;
                margin: 10% auto;
            }
        }
        
        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #3498db, #2980b9);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #2980b9, #1f639e);
        }
        
        /* Loading Animation */
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .loading {
            animation: pulse 1.5s infinite;
        }
        
        /* Status Indicators */
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 6px;
        }
        
        .status-success { background: #27ae60; }
        .status-failed { background: #e74c3c; }
        .status-pending { background: #f39c12; }
        
        /* Tooltip */
        .tooltip {
            position: relative;
            display: inline-block;
        }
        
        .tooltip .tooltip-text {
            visibility: hidden;
            width: 200px;
            background: #2c3e50;
            color: white;
            text-align: center;
            padding: 8px;
            border-radius: 6px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 12px;
            font-weight: normal;
            white-space: normal;
        }
        
        .tooltip:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1> System Logs & Monitoring</h1>
        <div>
            <span>Welcome, <strong><?php echo htmlspecialchars($user['username']); ?></strong></span>
            <a href="admin.php" class="back-btn">← Back to Admin</a>
            <a href="dashboard.php" class="back-btn"> Dashboard</a>
            <a href="logout.php" class="logout-btn"> Logout</a>
        </div>
    </div>

    <div class="container">
        <!-- Success/Error Messages -->
        <?php if (isset($success)): ?>
            <div class="alert alert-success">
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-error">
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <!-- Statistics Overview -->
        <h2 class="section-title">System Monitoring Dashboard</h2>
        <div class="stats-grid">
            <div class="stat-card stat-system">
                <span class="stat-number"><?php echo $log_stats['system_total'] ?? 0; ?></span>
                <span class="stat-label">System Logs</span>
                <small><?php echo $log_stats['system_today'] ?? 0; ?> logged today</small>
            </div>
            <div class="stat-card stat-admin">
                <span class="stat-number"><?php echo $log_stats['admin_total'] ?? 0; ?></span>
                <span class="stat-label">Admin Actions</span>
                <small><?php echo $log_stats['admin_today'] ?? 0; ?> performed today</small>
            </div>
            <div class="stat-card stat-index">
                <span class="stat-number"><?php echo $log_stats['index_total'] ?? 0; ?></span>
                <span class="stat-label">index Attempts</span>
                <small><?php echo $log_stats['index_today'] ?? 0; ?> today • <?php echo $log_stats['index_failed'] ?? 0; ?> failed</small>
            </div>
            <div class="stat-card stat-security">
                <span class="stat-number"><?php echo $log_stats['security_total'] ?? 0; ?></span>
                <span class="stat-label">Security Events</span>
                <small><?php echo $log_stats['security_today'] ?? 0; ?> detected today</small>
            </div>
            <div class="stat-card stat-error">
                <span class="stat-number"><?php echo $log_stats['system_errors'] ?? 0; ?></span>
                <span class="stat-label">System Errors</span>
                <small>Requires attention</small>
            </div>
            <div class="stat-card stat-critical">
                <span class="stat-number"><?php echo $log_stats['security_critical'] ?? 0; ?></span>
                <span class="stat-label">Critical Events</span>
                <small>High priority alerts</small>
            </div>
        </div>

        <!-- Filter Panel -->
        <div class="filter-panel">
            <h3 class="section-title" style="margin-top: 0; border-bottom: none;"> Filter Logs</h3>
            <form method="GET" class="filter-form">
                <div class="form-group">
                    <label for="type"> Log Type:</label>
                    <select id="type" name="type">
                        <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Logs</option>
                        <option value="system" <?php echo $filter_type === 'system' ? 'selected' : ''; ?>>System Logs</option>
                        <option value="admin" <?php echo $filter_type === 'admin' ? 'selected' : ''; ?>>Admin Actions</option>
                        <option value="index" <?php echo $filter_type === 'index' ? 'selected' : ''; ?>>index Attempts</option>
                        <option value="security" <?php echo $filter_type === 'security' ? 'selected' : ''; ?>>Security Logs</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="date"> Date:</label>
                    <input type="date" id="date" name="date" value="<?php echo htmlspecialchars($filter_date); ?>">
                </div>
                
                <div class="form-group">
                    <label for="user"> Username/IP:</label>
                    <input type="text" id="user" name="user" placeholder="Enter username or IP address" value="<?php echo htmlspecialchars($filter_user); ?>">
                </div>
                
                <?php if ($filter_type === 'system' || $filter_type === 'security' || $filter_type === 'all'): ?>
                <div class="form-group">
                    <label for="severity"> Severity:</label>
                    <select id="severity" name="severity">
                        <option value="">All Severities</option>
                        <?php foreach ($unique_severities as $severity): ?>
                            <option value="<?php echo $severity; ?>" <?php echo $filter_severity === $severity ? 'selected' : ''; ?>>
                                <?php echo ucfirst($severity); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="limit"> Results per page:</label>
                    <select id="limit" name="limit">
                        <option value="50" <?php echo $limit === 50 ? 'selected' : ''; ?>>50 records</option>
                        <option value="100" <?php echo $limit === 100 ? 'selected' : ''; ?>>100 records</option>
                        <option value="200" <?php echo $limit === 200 ? 'selected' : ''; ?>>200 records</option>
                        <option value="500" <?php echo $limit === 500 ? 'selected' : ''; ?>>500 records</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-primary"> Filter Logs</button>
                    <a href="admin_logs.php" class="btn btn-secondary"> Clear Filters</a>
                </div>
            </form>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons">
            <button class="btn btn-warning" onclick="openClearLogsModal()">
                 Clear Old Logs
            </button>
            <button class="btn btn-success" onclick="openExportModal()">
                 Export Logs
            </button>
            <button class="btn btn-info" onclick="refreshLogs()">
                 Refresh
            </button>
        </div>

        <!-- Logs Table -->
        <h2 class="section-title">
            <?php echo ucfirst($filter_type); ?> Logs 
            <small>(Total: <?php echo $total_logs; ?> records)</small>
        </h2>
        
        <?php if (empty($logs)): ?>
            <div class="empty-state">
                
                <h3>No Logs Found</h3>
                <p>Try adjusting your filters or check back later for new activity.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.08);">
                <table class="logs-table">
                    <thead>
                        <tr>
                            <?php if ($filter_type === 'all'): ?>
                                <th>Type</th>
                            <?php endif; ?>
                            <th>Timestamp</th>
                            <?php if ($filter_type === 'system' || $filter_type === 'security' || $filter_type === 'all'): ?>
                                <th>Severity</th>
                            <?php endif; ?>
                            <th>User / IP Address</th>
                            <th>Action / Event</th>
                            <th>Status</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): 
                            $log_type = $log['log_type'] ?? $filter_type;
                            $timestamp = '';
                            $severity = '';
                            $user_info = '';
                            $action = '';
                            $status = '';
                            $details = '';
                            
                            if ($log_type === 'system' || ($filter_type === 'system' && !isset($log['log_type']))) {
                                $timestamp = $log['created_at'];
                                $severity = $log['severity'] ?? 'info';
                                $user_info = ($log['username'] ?: 'N/A') . '<br><small>' . ($log['ip_address'] ?? '') . '</small>';
                                $action = $log['action'] ?? '';
                                $status = '';
                                $details = $log['details'] ?? '';
                            } elseif ($log_type === 'admin' || ($filter_type === 'admin' && !isset($log['log_type']))) {
                                $timestamp = date('Y-m-d H:i:s', $log['action_time']);
                                $severity = 'info';
                                $user_info = ($log['admin_username'] ?? 'N/A') . '<br><small>' . ($log['ip_address'] ?? '') . '</small>';
                                $action = $log['action_type'] ?? '';
                                $status = $log['target_username'] ? 'Target: ' . $log['target_username'] : '';
                                $details = $log['details'] ?? '';
                            } elseif ($log_type === 'index' || ($filter_type === 'index' && !isset($log['log_type']))) {
                                $timestamp = date('Y-m-d H:i:s', $log['attempt_time']);
                                $severity = $log['success'] ? 'info' : 'warning';
                                $user_info = $log['username'] . '<br><small>' . $log['ip_address'] . '</small>';
                                $action = 'index Attempt';
                                $status = $log['success'] ? '<span class="badge badge-success">✅ Success</span>' : '<span class="badge badge-danger">❌ Failed</span>';
                                $details = 'User Agent: ' . ($log['user_agent'] ?? 'N/A');
                            } elseif ($log_type === 'security' || ($filter_type === 'security' && !isset($log['log_type']))) {
                                $timestamp = $log['created_at'];
                                $severity = $log['severity'] ?? 'medium';
                                $user_info = ($log['username'] ?: 'N/A') . '<br><small>' . ($log['ip_address'] ?? '') . '</small>';
                                $action = $log['event_type'] ?? '';
                                $status = '';
                                $details = $log['description'] ?? '';
                            }
                        ?>
                            <tr class="severity-<?php echo $severity; ?>">
                                <?php if ($filter_type === 'all'): ?>
                                    <td>
                                        <span class="badge badge-info"><?php echo ucfirst($log_type); ?></span>
                                    </td>
                                <?php endif; ?>
                                <td>
                                    <small><?php echo date('M d, Y', strtotime($timestamp)); ?></small><br>
                                    <strong><?php echo date('H:i:s', strtotime($timestamp)); ?></strong>
                                </td>
                                <?php if ($filter_type === 'system' || $filter_type === 'security' || $filter_type === 'all'): ?>
                                    <td>
                                        <span class="badge badge-<?php echo $severity; ?>">
                                            <?php echo ucfirst($severity); ?>
                                        </span>
                                    </td>
                                <?php endif; ?>
                                <td><?php echo $user_info; ?></td>
                                <td><strong><?php echo htmlspecialchars($action); ?></strong></td>
                                <td><?php echo $status; ?></td>
                                <td>
                                    <small><?php echo htmlspecialchars(substr($details, 0, 100)); ?></small>
                                    <?php if (strlen($details) > 100): ?>
                                        <button class="btn btn-sm btn-secondary" onclick="showDetails(<?php echo htmlspecialchars(json_encode($log), ENT_QUOTES); ?>, '<?php echo $log_type; ?>')">
                                            View Full
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">← Previous</a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                           class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">Next →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Clear Logs Modal -->
    <div id="clearLogsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Clear Old Logs</h3>
                <button class="close" onclick="closeClearLogsModal()">&times;</button>
            </div>
            <form method="POST" id="clearLogsForm">
                <input type="hidden" name="clear_logs" value="1">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="modal-body">
                    <div class="form-group">
                        <label> Clear logs older than:</label>
                        <select name="days" required>
                            <option value="7">7 days</option>
                            <option value="30" selected>30 days</option>
                            <option value="60">60 days</option>
                            <option value="90">90 days</option>
                            <option value="180">180 days</option>
                            <option value="365">1 year</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label> Log type to clear:</label>
                        <select name="log_type" required>
                            <option value="all">All Logs</option>
                            <option value="system">System Logs Only</option>
                            <option value="admin">Admin Actions Only</option>
                            <option value="index">index Attempts Only</option>
                            <option value="security">Security Logs Only</option>
                        </select>
                    </div>
                    <div class="alert alert-error" style="margin-top: 15px;">
                        <div>
                            <strong>Warning:</strong> This action cannot be undone. Cleared logs will be permanently deleted from the database.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeClearLogsModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Clear Logs</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Export Logs Modal -->
    <div id="exportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3> Export Logs</h3>
                <button class="close" onclick="closeExportModal()">&times;</button>
            </div>
            <form method="POST" id="exportForm">
                <input type="hidden" name="export_logs" value="1">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="modal-body">
                    <div class="form-group">
                        <label> Log type to export:</label>
                        <select name="export_type" required>
                            <option value="system">System Logs</option>
                            <option value="admin">Admin Actions</option>
                            <option value="index">index Attempts</option>
                            <option value="security">Security Logs</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label> Export format:</label>
                        <select name="export_format" required>
                            <option value="csv">CSV (Excel compatible)</option>
                            <option value="json">JSON</option>
                        </select>
                    </div>
                    <div class="alert alert-success" style="margin-top: 15px;">
                        
                        <div>
                            <strong>Note:</strong> Exports will include up to 1000 most recent records. The download will start immediately after clicking "Export Logs".
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeExportModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Export Logs</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Log Details Modal -->
    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i></i> Log Details</h3>
                <button class="close" onclick="closeDetailsModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="detailsContent"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDetailsModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        // Modal functions
        function openClearLogsModal() {
            document.getElementById('clearLogsModal').style.display = 'block';
        }

        function closeClearLogsModal() {
            document.getElementById('clearLogsModal').style.display = 'none';
        }

        function openExportModal() {
            document.getElementById('exportModal').style.display = 'block';
        }

        function closeExportModal() {
            document.getElementById('exportModal').style.display = 'none';
        }

        function showDetails(log, logType) {
            const content = document.getElementById('detailsContent');
            let html = '<div class="log-details">';
            html += `<h4>${logType.charAt(0).toUpperCase() + logType.slice(1)} Log Details</h4>`;
            html += '<hr>';
            
            // Format timestamp based on log type
            let timestamp = '';
            if (logType === 'admin' && log.action_time) {
                timestamp = new Date(log.action_time * 1000).toLocaleString();
            } else if (logType === 'index' && log.attempt_time) {
                timestamp = new Date(log.attempt_time * 1000).toLocaleString();
            } else if (log.created_at) {
                timestamp = new Date(log.created_at).toLocaleString();
            }
            
            if (timestamp) {
                html += `<strong>TIMESTAMP:</strong> ${timestamp}<br><br>`;
            }
            
            // Display all fields
            for (const [key, value] of Object.entries(log)) {
                if (key === 'log_type' || key === 'timestamp' || key === 'created_at' || key === 'action_time' || key === 'attempt_time') continue;
                
                const formattedKey = key.replace(/_/g, ' ').toUpperCase();
                
                html += `<strong>${formattedKey}:</strong> `;
                
                if (value === null || value === '') {
                    html += '<em>Not specified</em>';
                } else if (key === 'success') {
                    html += value == 1 ? '<span class="badge badge-success">SUCCESS</span>' : '<span class="badge badge-danger">FAILED</span>';
                } else if (key === 'severity') {
                    html += `<span class="badge badge-${value}">${value.toUpperCase()}</span>`;
                } else if (typeof value === 'object') {
                    html += `<pre>${JSON.stringify(value, null, 2)}</pre>`;
                } else if (key === 'user_agent' || key === 'details' || key === 'description') {
                    html += `<pre style="white-space: pre-wrap; word-break: break-all;">${value}</pre>`;
                } else {
                    html += `${value}`;
                }
                html += '<br><br>';
            }
            
            html += '</div>';
            content.innerHTML = html;
            document.getElementById('detailsModal').style.display = 'block';
        }

        function closeDetailsModal() {
            document.getElementById('detailsModal').style.display = 'none';
        }

        function refreshLogs() {
            const btn = event.target;
            btn.classList.add('loading');
            btn.innerHTML = '<i></i> Refreshing...';
            
            setTimeout(() => {
                window.location.reload();
            }, 500);
        }

        // Auto-refresh every 30 seconds if no filters are applied
        <?php if (empty($filter_date) && empty($filter_user) && empty($filter_severity) && empty($filter_ip)): ?>
        setTimeout(function() {
            if (!document.hidden) {
                refreshLogs();
            }
        }, 30000);
        <?php endif; ?>

        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = ['clearLogsModal', 'exportModal', 'detailsModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        // Add confirmation for clear logs
        document.getElementById('clearLogsForm').addEventListener('submit', function(e) {
            if (!confirm(' Are you sure you want to clear these logs? This action cannot be undone and all selected logs will be permanently deleted.')) {
                e.preventDefault();
            }
        });

        // Auto-focus on first input in modals
        document.addEventListener('DOMContentLoaded', function() {
            const modals = ['clearLogsModal', 'exportModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.addEventListener('shown', function() {
                        const firstInput = modal.querySelector('input:not([type="hidden"]), select');
                        if (firstInput) {
                            firstInput.focus();
                        }
                    });
                }
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+R or F5 to refresh
            if ((e.ctrlKey && e.key === 'r') || e.key === 'F5') {
                e.preventDefault();
                refreshLogs();
            }
            
            // Escape to close modals
            if (e.key === 'Escape') {
                closeClearLogsModal();
                closeExportModal();
                closeDetailsModal();
            }
            
            // Ctrl+E to export
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                openExportModal();
            }
        });

        // Add hover effects to table rows
        document.addEventListener('DOMContentLoaded', function() {
            const tableRows = document.querySelectorAll('.logs-table tbody tr');
            tableRows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateX(5px)';
                    this.style.transition = 'transform 0.2s ease';
                });
                
                row.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateX(0)';
                });
            });
        });

        // Add tooltip to view buttons
        document.addEventListener('DOMContentLoaded', function() {
            const viewButtons = document.querySelectorAll('.btn-secondary');
            viewButtons.forEach(btn => {
                btn.title = 'Click to view full log details';
            });
        });
    </script>
</body>
</html>