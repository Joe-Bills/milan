<?php
// splitter_cores_list.php
// session_start();
require_once 'config.php'; // Database connection

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
    header('Location: index.php?session_expired=1');
    exit();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Initialize variables
$splitter_cores = [];
$error = '';
$search_term = '';
$status_filter = '';
$success_message = '';

// Handle inline updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_core'])) {
    $core_id = intval($_POST['core_id']);
    $customer_name = trim($_POST['customer_name']);
    $customer_location = trim($_POST['customer_location']);
    $power = $_POST['power'] !== '' ? $_POST['power'] : null;
    $status = $_POST['status'];
    $comment = trim($_POST['comment']);
    
    // Handle image upload
    $customer_image = null;
    if (isset($_FILES['customer_image']) && $_FILES['customer_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/splitter_customers/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_extension = pathinfo($_FILES['customer_image']['name'], PATHINFO_EXTENSION);
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        
        if (in_array(strtolower($file_extension), $allowed_extensions)) {
            $filename = 'customer_' . $core_id . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['customer_image']['tmp_name'], $upload_path)) {
                $customer_image = $upload_path;
                
                // Delete old image if exists
                $stmt = $pdo->prepare("SELECT customer_image FROM splitter_cores WHERE id = ?");
                $stmt->execute([$core_id]);
                $old_image = $stmt->fetchColumn();
                
                if ($old_image && file_exists($old_image)) {
                    unlink($old_image);
                }
            }
        }
    }
    
    try {
        if ($customer_image) {
            $stmt = $pdo->prepare("
                UPDATE splitter_cores 
                SET customer_name = ?, customer_location = ?, power = ?, status = ?, 
                    comment = ?, customer_image = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $customer_name,
                $customer_location,
                $power,
                $status,
                $comment,
                $customer_image,
                $core_id
            ]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE splitter_cores 
                SET customer_name = ?, customer_location = ?, power = ?, status = ?, 
                    comment = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $customer_name,
                $customer_location,
                $power,
                $status,
                $comment,
                $core_id
            ]);
        }
        
        $success_message = "Splitter core updated successfully!";
        
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Handle view details
if (isset($_GET['view_id'])) {
    $view_id = intval($_GET['view_id']);
    try {
        $stmt = $pdo->prepare("
            SELECT sc.*, c.core_number, c.color, b.box_name
            FROM splitter_cores sc
            LEFT JOIN cores c ON sc.core_id = c.id
            LEFT JOIN boxes b ON c.box_id = b.id
            WHERE sc.id = ?
        ");
        $stmt->execute([$view_id]);
        $view_core = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Build query with optional filters
try {
    $sql = "
        SELECT 
            sc.*,
            c.core_number,
            c.color,
            c.power_level as core_power,
            b.box_name,
            b.address as box_address
        FROM splitter_cores sc
        LEFT JOIN cores c ON sc.core_id = c.id
        LEFT JOIN boxes b ON c.box_id = b.id
        WHERE 1=1
    ";
    
    $params = [];
    
    // Search filter
    if (isset($_GET['search']) && !empty($_GET['search'])) {
        $search_term = trim($_GET['search']);
        $sql .= " AND (sc.customer_name LIKE ? OR sc.customer_location LIKE ? OR sc.comment LIKE ? OR b.box_name LIKE ?)";
        $search_param = "%$search_term%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    // Status filter
    if (isset($_GET['status']) && !empty($_GET['status'])) {
        $status_filter = $_GET['status'];
        $sql .= " AND sc.status = ?";
        $params[] = $status_filter;
    }
    
    // Order by
    $sql .= " ORDER BY sc.core_id, sc.splitter_core_number";
    
    // Prepare and execute query
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $splitter_cores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    error_log("Database error in splitter_cores_list.php: " . $e->getMessage());
}

// Get statistics
$total_ports = count($splitter_cores);
$connected_ports = 0;
$available_ports = 0;
$faulty_ports = 0;

foreach ($splitter_cores as $core) {
    switch ($core['status']) {
        case 'connected':
            $connected_ports++;
            break;
        case 'available':
            $available_ports++;
            break;
        case 'faulty':
            $faulty_ports++;
            break;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Splitter Cores - ISP Infrastructure</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        header {
            background: linear-gradient(135deg, #2c3e50, #4a6491);
            color: white;
            padding: 1rem 0;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .logo {
            font-size: 1.8rem;
            font-weight: bold;
        }

        .nav-links a {
            color: white;
            text-decoration: none;
            margin-left: 20px;
            padding: 5px 10px;
            border-radius: 4px;
            transition: background 0.3s;
        }

        .nav-links a:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .page-title {
            margin: 20px 0;
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 20px;
            margin-bottom: 20px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .stat-card.total { border-left: 4px solid #3498db; }
        .stat-card.connected { border-left: 4px solid #27ae60; }
        .stat-card.available { border-left: 4px solid #f39c12; }
        .stat-card.faulty { border-left: 4px solid #e74c3c; }

        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #7f8c8d;
        }

        .filters {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .filter-form {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 15px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: bold;
            color: #2c3e50;
        }

        .form-control {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            width: 100%;
        }

        .form-control-sm {
            padding: 4px 8px;
            font-size: 13px;
        }

        .btn {
            display: inline-block;
            padding: 8px 15px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.3s;
            font-size: 14px;
            text-align: center;
        }

        .btn:hover {
            background: #2980b9;
        }

        .btn-sm {
            padding: 4px 8px;
            font-size: 12px;
        }

        .btn-save {
            background: #27ae60;
        }

        .btn-save:hover {
            background: #219653;
        }

        .btn-cancel {
            background: #7f8c8d;
        }

        .btn-cancel:hover {
            background: #636e72;
        }

        .btn-edit {
            background: #f39c12;
        }

        .btn-edit:hover {
            background: #e67e22;
        }

        .btn-view {
            background: #17a2b8;
        }

        .btn-view:hover {
            background: #138496;
        }

        .btn-delete {
            background: #e74c3c;
        }

        .btn-delete:hover {
            background: #c0392b;
        }

        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .data-table th, .data-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }

        .data-table th {
            background-color: #3498db;
            color: white;
            position: sticky;
            top: 0;
        }

        .data-table tr:hover {
            background-color: #f8f9fa;
        }

        .editing-row {
            background-color: #fff3cd !important;
        }

        .status-connected {
            color: #27ae60;
            font-weight: bold;
            padding: 4px 8px;
            border-radius: 4px;
            background: #d4edda;
        }

        .status-available {
            color: #3498db;
            font-weight: bold;
            padding: 4px 8px;
            border-radius: 4px;
            background: #d1ecf1;
        }

        .status-faulty {
            color: #e74c3c;
            font-weight: bold;
            padding: 4px 8px;
            border-radius: 4px;
            background: #f8d7da;
        }

        .color-badge {
            padding: 4px 8px;
            border-radius: 4px;
            color: white;
            font-weight: bold;
            font-size: 12px;
            display: inline-block;
            min-width: 60px;
            text-align: center;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #7f8c8d;
            background: #f8f9fa;
            border-radius: 8px;
            margin: 20px 0;
        }

        .error-message {
            background: #e74c3c;
            color: white;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 5px solid #c0392b;
        }

        .success-message {
            background: #27ae60;
            color: white;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 5px solid #219653;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .edit-form {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .customer-image {
            width: 60px;
            height: 60px;
            border-radius: 6px;
            object-fit: cover;
            border: 2px solid #ddd;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .customer-image:hover {
            transform: scale(1.1);
            border-color: #3498db;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }

        .image-preview {
            max-width: 150px;
            max-height: 150px;
            border-radius: 6px;
            border: 2px solid #dee2e6;
            margin-top: 5px;
        }

        .file-input-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
            width: 100%;
        }

        .file-input-label {
            display: block;
            padding: 8px 12px;
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 4px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 12px;
        }

        .file-input-label:hover {
            border-color: #3498db;
            background: #e7f3ff;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.8);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background-color: white;
            margin: 2% auto;
            padding: 0;
            border-radius: 12px;
            width: 95%;
            max-width: 900px;
            max-height: 95vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.3s ease-out;
        }

        @keyframes modalSlideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-header {
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            padding: 20px;
            border-radius: 12px 12px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .modal-header h3 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
        }

        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s;
        }

        .close:hover {
            background: rgba(255,255,255,0.2);
        }

        .modal-body {
            padding: 30px;
        }

        .customer-details-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 30px;
            align-items: start;
        }

        .image-section {
            text-align: center;
        }

        .customer-image-large {
            width: 100%;
            max-width: 400px;
            height: auto;
            max-height: 500px;
            border-radius: 12px;
            border: 3px solid #3498db;
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
            object-fit: contain;
            background: #f8f9fa;
            padding: 5px;
        }

        .customer-image-full {
            width: 100%;
            height: auto;
            border-radius: 8px;
            cursor: zoom-in;
            transition: transform 0.3s ease;
        }

        .customer-image-full:hover {
            transform: scale(1.02);
        }

        .image-actions {
            margin-top: 15px;
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .customer-info {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        .info-row {
            display: flex;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #dee2e6;
            align-items: flex-start;
        }

        .info-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .info-label {
            font-weight: 700;
            width: 140px;
            color: #2c3e50;
            flex-shrink: 0;
            font-size: 14px;
        }

        .info-value {
            flex: 1;
            color: #34495e;
            font-size: 15px;
            line-height: 1.5;
        }

        .no-image {
            padding: 60px 30px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 12px;
            text-align: center;
            color: #6c757d;
            border: 3px dashed #dee2e6;
        }

        .no-image i {
            font-size: 4rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        .image-meta {
            margin-top: 10px;
            font-size: 12px;
            color: #6c757d;
            text-align: center;
        }

        /* Fullscreen Image Modal */
        .image-modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.95);
            backdrop-filter: blur(10px);
        }

        .image-modal-content {
            position: relative;
            margin: auto;
            display: block;
            width: auto;
            height: auto;
            max-width: 95%;
            max-height: 95%;
            top: 50%;
            transform: translateY(-50%);
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.5);
        }

        .image-modal-close {
            position: absolute;
            top: 20px;
            right: 30px;
            color: white;
            font-size: 40px;
            font-weight: bold;
            cursor: pointer;
            background: rgba(0,0,0,0.5);
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
            border: 2px solid white;
        }

        .image-modal-close:hover {
            background: rgba(255,255,255,0.2);
            transform: scale(1.1);
        }

        .image-modal-caption {
            position: absolute;
            bottom: 20px;
            left: 0;
            right: 0;
            text-align: center;
            color: white;
            font-size: 16px;
            background: rgba(0,0,0,0.7);
            padding: 15px;
            margin: 0 20px;
            border-radius: 8px;
        }

        @media (max-width: 768px) {
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .header-content {
                flex-direction: column;
                text-align: center;
            }
            
            .nav-links {
                margin-top: 15px;
            }
            
            .nav-links a {
                margin: 0 5px;
            }
            
            .data-table {
                font-size: 14px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                padding: 6px 10px;
                font-size: 12px;
            }
            
            .customer-details-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .modal-content {
                width: 98%;
                margin: 1% auto;
            }
            
            .customer-image-large {
                max-width: 100%;
            }
            
            .info-row {
                flex-direction: column;
                gap: 5px;
            }
            
            .info-label {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .modal-body {
                padding: 20px;
            }
            
            .customer-info {
                padding: 15px;
            }
            
            .image-actions {
                flex-direction: column;
            }
        }

        .image-quality-badge {
            background: #28a745;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            margin-left: 10px;
        }

        .zoom-hint {
            color: #6c757d;
            font-size: 12px;
            margin-top: 5px;
            font-style: italic;
        }
    </style>
</head>
<body>
    <header>
        <div class="header-content">
            <div class="logo">ISP Infrastructure</div>
            <div class="nav-links">
                <a href="dashboard.php">Dashboard</a>
                <a href="logout.php">Logout</a>
            </div>
        </div>
    </header>

    <div class="container">
        <div class="page-title">
            <h1>All Splitter Cores</h1>
        </div>
        
        <?php if ($error): ?>
            <div class="error-message">
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="success-message">
                <strong>Success:</strong> <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-value"><?php echo $total_ports; ?></div>
                <div class="stat-label">Total Ports</div>
            </div>
            <div class="stat-card connected">
                <div class="stat-value"><?php echo $connected_ports; ?></div>
                <div class="stat-label">Connected</div>
            </div>
            <div class="stat-card available">
                <div class="stat-value"><?php echo $available_ports; ?></div>
                <div class="stat-label">Available</div>
            </div>
            <div class="stat-card faulty">
                <div class="stat-value"><?php echo $faulty_ports; ?></div>
                <div class="stat-label">Faulty</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters">
            <form method="GET" class="filter-form">
                <div class="form-group">
                    <label for="search">Search:</label>
                    <input type="text" id="search" name="search" class="form-control" 
                           placeholder="Search by customer, location, comment, or box name..." 
                           value="<?php echo htmlspecialchars($search_term); ?>">
                </div>
                <div class="form-group">
                    <label for="status">Status:</label>
                    <select id="status" name="status" class="form-control">
                        <option value="">All Status</option>
                        <option value="connected" <?php echo $status_filter === 'connected' ? 'selected' : ''; ?>>Connected</option>
                        <option value="available" <?php echo $status_filter === 'available' ? 'selected' : ''; ?>>Available</option>
                        <option value="faulty" <?php echo $status_filter === 'faulty' ? 'selected' : ''; ?>>Faulty</option>
                    </select>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn">Apply Filters</button>
                    <a href="splitter_cores_list.php" class="btn btn-cancel" style="margin-top: 5px;">Reset</a>
                </div>
            </form>
        </div>

        <!-- Splitter Cores Table -->
        <div class="card">
            <?php if (empty($splitter_cores)): ?>
                <div class="empty-state">
                    <p>No splitter cores found.</p>
                    <?php if ($search_term || $status_filter): ?>
                        <p>Try adjusting your search criteria.</p>
                        <a href="splitter_cores_list.php" class="btn" style="margin-top: 15px;">Clear Filters</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Box Name</th>
                                <th>Core Info</th>
                                <th>Port #</th>
                                <th>Customer Name</th>
                                <th>Location</th>
                                <th>Image</th>
                                <th>Power (dB)</th>
                                <th>Status</th>
                                <th>Comment</th>
                                <th>Updated At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($splitter_cores as $core): ?>
                                <tr id="row-<?php echo $core['id']; ?>">
                                    <td><?php echo htmlspecialchars($core['id']); ?></td>
                                    <td>
                                        <?php if ($core['box_name']): ?>
                                            <strong><?php echo htmlspecialchars($core['box_name']); ?></strong>
                                            <?php if ($core['box_address']): ?>
                                                <br><small style="color: #7f8c8d;"><?php echo htmlspecialchars(substr($core['box_address'], 0, 30)) . '...'; ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color: #7f8c8d;">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($core['core_number']): ?>
                                            Core <?php echo htmlspecialchars($core['core_number']); ?>
                                            <br>
                                            <span class="color-badge" style="background: <?php echo htmlspecialchars($core['color']); ?>;">
                                                <?php echo htmlspecialchars($core['color']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #7f8c8d;">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong>Port <?php echo htmlspecialchars($core['splitter_core_number']); ?></strong></td>
                                    
                                    <!-- Editable Fields -->
                                    <td>
                                        <span class="view-mode" id="customer-name-<?php echo $core['id']; ?>">
                                            <?php if (!empty($core['customer_name'])): ?>
                                                <?php echo htmlspecialchars($core['customer_name']); ?>
                                            <?php else: ?>
                                                <span style="color: #7f8c8d;">Not assigned</span>
                                            <?php endif; ?>
                                        </span>
                                        <input type="text" 
                                               id="edit-customer-name-<?php echo $core['id']; ?>" 
                                               class="form-control form-control-sm edit-mode" 
                                               style="display: none;"
                                               value="<?php echo htmlspecialchars($core['customer_name'] ?? ''); ?>"
                                               placeholder="Customer name">
                                    </td>
                                    
                                    <td>
                                        <span class="view-mode" id="customer-location-<?php echo $core['id']; ?>">
                                            <?php if (!empty($core['customer_location'])): ?>
                                                <?php echo htmlspecialchars(substr($core['customer_location'], 0, 20)); ?>
                                                <?php if (strlen($core['customer_location']) > 20): ?>...<?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: #7f8c8d;">Not set</span>
                                            <?php endif; ?>
                                        </span>
                                        <input type="text" 
                                               id="edit-customer-location-<?php echo $core['id']; ?>" 
                                               class="form-control form-control-sm edit-mode" 
                                               style="display: none;"
                                               value="<?php echo htmlspecialchars($core['customer_location'] ?? ''); ?>"
                                               placeholder="Customer location">
                                    </td>
                                    
                                    <td>
                                        <div class="view-mode">
                                            <?php if (!empty($core['customer_image'])): ?>
                                                <img src="<?php echo htmlspecialchars($core['customer_image']); ?>" 
                                                     alt="Customer Image" 
                                                     class="customer-image"
                                                     onclick="viewCustomerDetails(<?php echo $core['id']; ?>)"
                                                     title="Click to view details">
                                            <?php else: ?>
                                                <span style="color: #7f8c8d; font-size: 11px;">No image</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="edit-mode" style="display: none;">
                                            <div class="file-input-wrapper">
                                                <label class="file-input-label" id="fileInputLabel-<?php echo $core['id']; ?>">
                                                    <i class="bi bi-cloud-upload"></i> Change image
                                                </label>
                                                <input type="file" 
                                                       id="edit-customer-image-<?php echo $core['id']; ?>" 
                                                       name="customer_image" 
                                                       accept="image/*" 
                                                       onchange="previewImage(this, <?php echo $core['id']; ?>)"
                                                       style="display: none;">
                                            </div>
                                            <div id="imagePreview-<?php echo $core['id']; ?>">
                                                <?php if (!empty($core['customer_image'])): ?>
                                                    <img src="<?php echo htmlspecialchars($core['customer_image']); ?>" 
                                                         class="image-preview" 
                                                         alt="Current image">
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    
                                    <td>
                                        <span class="view-mode" id="power-<?php echo $core['id']; ?>">
                                            <?php if ($core['power']): ?>
                                                <?php echo htmlspecialchars($core['power']); ?>
                                            <?php else: ?>
                                                <span style="color: #7f8c8d;">N/A</span>
                                            <?php endif; ?>
                                        </span>
                                        <input type="number" 
                                               id="edit-power-<?php echo $core['id']; ?>" 
                                               class="form-control form-control-sm edit-mode" 
                                               style="display: none;"
                                               value="<?php echo htmlspecialchars($core['power'] ?? ''); ?>"
                                               placeholder="Power level"
                                               step="0.01">
                                    </td>
                                    
                                    <td>
                                        <span class="view-mode" id="status-<?php echo $core['id']; ?>">
                                            <span class="status-<?php echo htmlspecialchars($core['status']); ?>">
                                                <?php echo ucfirst(htmlspecialchars($core['status'])); ?>
                                            </span>
                                        </span>
                                        <select id="edit-status-<?php echo $core['id']; ?>" 
                                                class="form-control form-control-sm edit-mode" 
                                                style="display: none;">
                                            <option value="available" <?php echo ($core['status'] === 'available') ? 'selected' : ''; ?>>Available</option>
                                            <option value="connected" <?php echo ($core['status'] === 'connected') ? 'selected' : ''; ?>>Connected</option>
                                            <option value="faulty" <?php echo ($core['status'] === 'faulty') ? 'selected' : ''; ?>>Faulty</option>
                                        </select>
                                    </td>
                                    
                                    <td>
                                        <span class="view-mode" id="comment-<?php echo $core['id']; ?>">
                                            <?php 
                                            $comment = $core['comment'] ?? '';
                                            if (!empty($comment)) {
                                                echo htmlspecialchars(substr($comment, 0, 30));
                                                if (strlen($comment) > 30) echo '...';
                                            } else {
                                                echo '<span style="color: #7f8c8d;">No comment</span>';
                                            }
                                            ?>
                                        </span>
                                        <textarea id="edit-comment-<?php echo $core['id']; ?>" 
                                                  class="form-control form-control-sm edit-mode" 
                                                  style="display: none; height: 60px;"
                                                  placeholder="Comment"><?php echo htmlspecialchars($core['comment'] ?? ''); ?></textarea>
                                    </td>
                                    
                                    <td>
                                        <?php 
                                        if ($core['updated_at'] && $core['updated_at'] != '0000-00-00 00:00:00') {
                                            echo date('M j, Y', strtotime($core['updated_at']));
                                        } else {
                                            echo '<span style="color: #7f8c8d;">N/A</span>';
                                        }
                                        ?>
                                    </td>
                                    
                                    <td>
                                        <div class="action-buttons">
                                            <!-- View Mode Buttons -->
                                            <div class="view-mode">
                                                <button onclick="enableEdit(<?php echo $core['id']; ?>)" 
                                                        class="btn btn-edit btn-sm" 
                                                        title="Edit">
                                                    Edit
                                                </button>
                                                <button onclick="viewCustomerDetails(<?php echo $core['id']; ?>)" 
                                                        class="btn btn-view btn-sm" 
                                                        title="View Details">
                                                    View
                                                </button>
                                            </div>
                                            
                                            <!-- Edit Mode Buttons -->
                                            <div class="edit-mode" style="display: none;">
                                                <button onclick="saveChanges(<?php echo $core['id']; ?>)" 
                                                        class="btn btn-save btn-sm" 
                                                        title="Save">
                                                    Save
                                                </button>
                                                <button onclick="cancelEdit(<?php echo $core['id']; ?>)" 
                                                        class="btn btn-cancel btn-sm" 
                                                        title="Cancel">
                                                    Cancel
                                                </button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Customer Details Modal -->
    <div id="customerDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="bi bi-person-badge"></i> Customer Connection Details</h3>
                <button class="close" onclick="closeCustomerDetailsModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="customerDetailsContent">
                    <!-- Customer details will be loaded here -->
                </div>
            </div>
            <div class="modal-footer" style="padding: 20px; background: #f8f9fa; border-radius: 0 0 12px 12px; text-align: right; border-top: 1px solid #dee2e6;">
                <button type="button" class="btn btn-cancel" onclick="closeCustomerDetailsModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Fullscreen Image Modal -->
    <div id="imageModal" class="image-modal">
        <span class="image-modal-close" onclick="closeImageModal()">&times;</span>
        <img class="image-modal-content" id="fullscreenImage">
        <div id="imageModalCaption" class="image-modal-caption"></div>
    </div>

    <!-- Hidden form for image uploads -->
    <form id="hiddenUploadForm" method="POST" enctype="multipart/form-data" style="display: none;">
        <input type="hidden" name="update_core" value="1">
        <input type="hidden" name="core_id" id="hiddenCoreId">
        <input type="hidden" name="customer_name" id="hiddenCustomerName">
        <input type="hidden" name="customer_location" id="hiddenCustomerLocation">
        <input type="hidden" name="power" id="hiddenPower">
        <input type="hidden" name="status" id="hiddenStatus">
        <input type="hidden" name="comment" id="hiddenComment">
        <input type="file" name="customer_image" id="hiddenCustomerImage">
    </form>

    <script>
        // Enable editing for a specific row
        function enableEdit(coreId) {
            const row = document.getElementById('row-' + coreId);
            const viewElements = row.querySelectorAll('.view-mode');
            const editElements = row.querySelectorAll('.edit-mode');
            
            // Switch to edit mode
            viewElements.forEach(el => el.style.display = 'none');
            editElements.forEach(el => el.style.display = 'block');
            
            // Highlight the row
            row.classList.add('editing-row');
            
            // Add click event to file input label
            const fileInputLabel = document.getElementById('fileInputLabel-' + coreId);
            const fileInput = document.getElementById('edit-customer-image-' + coreId);
            
            fileInputLabel.addEventListener('click', function() {
                fileInput.click();
            });
        }

        // Cancel editing
        function cancelEdit(coreId) {
            const row = document.getElementById('row-' + coreId);
            const viewElements = row.querySelectorAll('.view-mode');
            const editElements = row.querySelectorAll('.edit-mode');
            
            // Switch back to view mode
            viewElements.forEach(el => el.style.display = '');
            editElements.forEach(el => el.style.display = 'none');
            
            // Remove highlight
            row.classList.remove('editing-row');
            
            // Reset image preview
            const preview = document.getElementById('imagePreview-' + coreId);
            const currentImage = preview.querySelector('img');
            if (currentImage) {
                preview.innerHTML = '<img src="' + currentImage.src + '" class="image-preview" alt="Current image">';
            } else {
                preview.innerHTML = '';
            }
            
            // Reset file input
            const fileInput = document.getElementById('edit-customer-image-' + coreId);
            fileInput.value = '';
            
            const fileLabel = document.getElementById('fileInputLabel-' + coreId);
            fileLabel.innerHTML = '<i class="bi bi-cloud-upload"></i> Change image';
        }

        // Preview image before upload
        function previewImage(input, coreId) {
            const preview = document.getElementById('imagePreview-' + coreId);
            const fileLabel = document.getElementById('fileInputLabel-' + coreId);
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    preview.innerHTML = '<img src="' + e.target.result + '" class="image-preview" alt="Image preview">';
                    fileLabel.innerHTML = '<i class="bi bi-check-circle"></i> ' + input.files[0].name;
                }
                
                reader.readAsDataURL(input.files[0]);
            } else {
                preview.innerHTML = '';
                fileLabel.innerHTML = '<i class="bi bi-cloud-upload"></i> Change image';
            }
        }

        // Save changes - FIXED VERSION
        function saveChanges(coreId) {
            const customerName = document.getElementById('edit-customer-name-' + coreId).value;
            const customerLocation = document.getElementById('edit-customer-location-' + coreId).value;
            const power = document.getElementById('edit-power-' + coreId).value;
            const status = document.getElementById('edit-status-' + coreId).value;
            const comment = document.getElementById('edit-comment-' + coreId).value;
            const imageInput = document.getElementById('edit-customer-image-' + coreId);
            
            // Validate required fields
            if (status === 'connected' && !customerName.trim()) {
                alert('Customer name is required for connected ports.');
                return;
            }
            
            // Get the hidden form elements
            const hiddenForm = document.getElementById('hiddenUploadForm');
            const hiddenCoreId = document.getElementById('hiddenCoreId');
            const hiddenCustomerName = document.getElementById('hiddenCustomerName');
            const hiddenCustomerLocation = document.getElementById('hiddenCustomerLocation');
            const hiddenPower = document.getElementById('hiddenPower');
            const hiddenStatus = document.getElementById('hiddenStatus');
            const hiddenComment = document.getElementById('hiddenComment');
            const hiddenCustomerImage = document.getElementById('hiddenCustomerImage');
            
            // Set values in hidden form
            hiddenCoreId.value = coreId;
            hiddenCustomerName.value = customerName;
            hiddenCustomerLocation.value = customerLocation;
            hiddenPower.value = power;
            hiddenStatus.value = status;
            hiddenComment.value = comment;
            
            // Handle image file transfer
            if (imageInput.files.length > 0) {
                // Create a new DataTransfer object to transfer files
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(imageInput.files[0]);
                hiddenCustomerImage.files = dataTransfer.files;
            } else {
                // Clear the file input if no file selected
                hiddenCustomerImage.value = '';
            }
            
            // Submit the form
            hiddenForm.submit();
        }

        // View customer details with enhanced image viewing
        function viewCustomerDetails(coreId) {
            fetch('get_splitter_core_details.php?core_id=' + coreId)
                .then(response => response.json())
                .then(coreData => {
                    const content = document.getElementById('customerDetailsContent');
                    
                    let imageHtml = '';
                    let imageMeta = '';
                    
                    if (coreData.customer_image) {
                        // Get image dimensions for quality info
                        const img = new Image();
                        img.src = coreData.customer_image;
                        img.onload = function() {
                            const width = this.width;
                            const height = this.height;
                            const fileSize = (this.src.length * 0.75) / 1024; // Approximate KB
                            
                            document.getElementById('imageQuality').innerHTML = 
                                `${width} × ${height} pixels | ${fileSize.toFixed(1)} KB`;
                        };
                        
                        imageHtml = `
                            <div class="image-section">
                                <img src="${coreData.customer_image}" 
                                     class="customer-image-large" 
                                     alt="Customer Connection Image"
                                     onclick="openFullscreenImage('${coreData.customer_image}', '${coreData.customer_name || 'Customer Connection'}')"
                                     style="cursor: zoom-in;">
                                <div class="zoom-hint">Click image to zoom</div>
                                <div class="image-actions">
                                    <button class="btn btn-sm" onclick="openFullscreenImage('${coreData.customer_image}', '${coreData.customer_name || 'Customer Connection'}')">
                                        <i class="bi bi-zoom-in"></i> Full Screen
                                    </button>
                                    <button class="btn btn-sm" onclick="downloadImage('${coreData.customer_image}', '${coreData.customer_name || 'customer_image'}')">
                                        <i class="bi bi-download"></i> Download
                                    </button>
                                </div>
                                <div class="image-meta" id="imageQuality">
                                    Loading image info...
                                </div>
                            </div>
                        `;
                    } else {
                        imageHtml = `
                            <div class="image-section">
                                <div class="no-image">
                                    <i class="bi bi-camera"></i>
                                    <h4>No Image Available</h4>
                                    <p>No connection image has been uploaded for this customer.</p>
                                </div>
                            </div>
                        `;
                    }
                    
                    content.innerHTML = `
                        <div class="customer-details-grid">
                            ${imageHtml}
                            <div class="customer-info">
                                <div class="info-row">
                                    <div class="info-label">Customer Name:</div>
                                    <div class="info-value">
                                        ${coreData.customer_name || '<span style="color: #6c757d;">Not assigned</span>'}
                                        ${coreData.customer_name ? '<span class="image-quality-badge">Connected</span>' : ''}
                                    </div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Location:</div>
                                    <div class="info-value">${coreData.customer_location || '<span style="color: #6c757d;">Not specified</span>'}</div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Box Name:</div>
                                    <div class="info-value">${coreData.box_name || '<span style="color: #6c757d;">N/A</span>'}</div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Core Information:</div>
                                    <div class="info-value">
                                        <strong>Core ${coreData.core_number}</strong> 
                                        <span style="background: ${coreData.color}; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px; margin-left: 8px;">
                                            ${coreData.color}
                                        </span>
                                    </div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Port Number:</div>
                                    <div class="info-value">
                                        <strong style="font-size: 16px; color: #2c3e50;">Port ${coreData.splitter_core_number}</strong>
                                    </div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Power Level:</div>
                                    <div class="info-value">
                                        ${coreData.power ? `<strong style="color: #e74c3c;">${coreData.power} dB</strong>` : '<span style="color: #6c757d;">N/A</span>'}
                                    </div>
                                </div>
                                <div class="info-row">
                                    <div class="info-label">Connection Status:</div>
                                    <div class="info-value">
                                        <span class="status-${coreData.status}" style="font-size: 14px; padding: 6px 12px;">
                                            <i class="bi ${coreData.status === 'connected' ? 'bi-check-circle' : coreData.status === 'available' ? 'bi-circle' : 'bi-exclamation-triangle'}"></i>
                                            ${coreData.status.charAt(0).toUpperCase() + coreData.status.slice(1)}
                                        </span>
                                    </div>
                                </div>
                                ${coreData.comment ? `
                                <div class="info-row">
                                    <div class="info-label">Technician Notes:</div>
                                    <div class="info-value" style="background: #fff3cd; padding: 10px; border-radius: 6px; border-left: 4px solid #ffc107;">
                                        <i class="bi bi-chat-quote"></i> ${coreData.comment}
                                    </div>
                                </div>` : ''}
                                <div class="info-row">
                                    <div class="info-label">Last Updated:</div>
                                    <div class="info-value">
                                        <i class="bi bi-clock"></i> 
                                        ${coreData.updated_at ? new Date(coreData.updated_at).toLocaleString() : '<span style="color: #6c757d;">N/A</span>'}
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    document.getElementById('customerDetailsModal').style.display = 'block';
                })
                .catch(error => {
                    console.error('Error fetching customer details:', error);
                    alert('Error loading customer details');
                });
        }

        // Open fullscreen image view
        function openFullscreenImage(imageSrc, caption) {
            const modal = document.getElementById('imageModal');
            const modalImg = document.getElementById('fullscreenImage');
            const captionText = document.getElementById('imageModalCaption');
            
            modal.style.display = 'block';
            modalImg.src = imageSrc;
            captionText.innerHTML = caption || 'Customer Connection Image';
            
            // Prevent background scrolling
            document.body.style.overflow = 'hidden';
        }

        // Close fullscreen image view
        function closeImageModal() {
            document.getElementById('imageModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Download image
        function downloadImage(imageSrc, fileName) {
            const link = document.createElement('a');
            link.href = imageSrc;
            link.download = fileName + '.jpg';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Close customer details modal
        function closeCustomerDetailsModal() {
            document.getElementById('customerDetailsModal').style.display = 'none';
        }

        // Auto-submit form when status changes
        document.getElementById('status').addEventListener('change', function() {
            if (this.value) {
                this.form.submit();
            }
        });

        // Close modals when clicking outside
        window.onclick = function(event) {
            const customerModal = document.getElementById('customerDetailsModal');
            const imageModal = document.getElementById('imageModal');
            
            if (event.target === customerModal) {
                closeCustomerDetailsModal();
            }
            if (event.target === imageModal) {
                closeImageModal();
            }
        }

        // Close modals with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeCustomerDetailsModal();
                closeImageModal();
            }
        });

        // Auto-refresh every 60 seconds
        setTimeout(function() {
            window.location.reload();
        }, 60000);
    </script>
</body>
</html>