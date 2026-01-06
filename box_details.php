<?php
include 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Get box ID from URL
$box_id = $_GET['box_id'] ?? 0;

// Handle form submissions
if ($_POST) {
    try {
        if (isset($_POST['update_box'])) {
            // Update box information
            $stmt = $pdo->prepare("UPDATE boxes SET box_name = ?, address = ?, location_lat = ?, location_lng = ? WHERE id = ?");
            $stmt->execute([
                $_POST['box_name'],
                $_POST['address'],
                $_POST['location_lat'],
                $_POST['location_lng'],
                $box_id
            ]);
            $success_message = "Box information updated successfully!";
        }
        elseif (isset($_POST['update_core'])) {
            // Update core information
            $core_id = $_POST['core_id'];
            
            // FIX: Check if connection_type exists in POST data, default to 'available'
            $connection_type = $_POST['connection_type'] ?? 'available';
            
            $customer_name = $_POST['customer_name'] ?? null;
            $technician_name = $_POST['technician_name'] ?? null;
            $customer_location = $_POST['customer_location'] ?? null;
            $technician_notes = $_POST['technician_notes'] ?? null;
            $splitter_id = $_POST['splitter_id'] ?? null;
            $splitter_type = $_POST['splitter_type'] ?? null;
            
            // Handle image upload
            $customer_image = null;
            if (isset($_FILES['customer_image']) && $_FILES['customer_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/customer_images/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = pathinfo($_FILES['customer_image']['name'], PATHINFO_EXTENSION);
                $filename = 'customer_' . $core_id . '_' . time() . '.' . $file_extension;
                $upload_path = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['customer_image']['tmp_name'], $upload_path)) {
                    $customer_image = $upload_path;
                }
            }
            
            // Determine connection status
            $connection_status = 'available';
            $is_connected = 0;
            $connected_to = null;
            $connected_to_type = null;
            $connected_to_id = null;
            $connection_date = null;
            
            if ($connection_type === 'direct') {
                $connection_status = 'connected';
                $is_connected = 1;
                $connected_to = $customer_name;
                $connected_to_type = 'customer';
                $connection_date = date('Y-m-d H:i:s');
            } elseif ($connection_type === 'splitter') {
                $connection_status = 'split';
                $is_connected = 1;
                $connected_to_type = 'splitter';
                $connected_to_id = $splitter_id;
                
                // Handle splitter cores
                if (isset($_POST['splitter_cores'])) {
                    // Delete existing splitter cores for this core
                    $pdo->prepare("DELETE FROM splitter_cores WHERE core_id = ?")->execute([$core_id]);
                    
                    // Insert new splitter cores
                    foreach ($_POST['splitter_cores'] as $core_data) {
                        if (!empty($core_data['customer_name']) || !empty($core_data['comment']) || !empty($core_data['power'])) {
                            $stmt = $pdo->prepare("INSERT INTO splitter_cores (core_id, splitter_core_number, customer_name, comment, power, status, connected_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                            $stmt->execute([
                                $core_id,
                                $core_data['core_number'],
                                $core_data['customer_name'],
                                $core_data['comment'],
                                $core_data['power'],
                                !empty($core_data['customer_name']) ? 'connected' : 'available'
                            ]);
                        }
                    }
                }
            }
            
            // FIX: Also handle the case when connection_type is 'available'
            if ($connection_type === 'available') {
                $connection_status = 'available';
                $is_connected = 0;
                $connected_to = null;
                $connected_to_type = null;
                $connected_to_id = null;
                $connection_date = null;
                
                // Also clear any splitter cores when setting to available
                $pdo->prepare("DELETE FROM splitter_cores WHERE core_id = ?")->execute([$core_id]);
            }
            
            $stmt = $pdo->prepare("UPDATE cores SET 
                color = ?, 
                power_level = ?, 
                connection_status = ?, 
                is_connected = ?, 
                connected_to = ?, 
                connected_to_type = ?, 
                connected_to_id = ?,
                technician_name = ?,
                customer_location = ?,
                technician_notes = ?,
                connection_date = ?
                " . ($customer_image ? ", customer_image = ?" : "") . "
                WHERE id = ?");
            
            $params = [
                $_POST['color'],
                $_POST['power_level'],
                $connection_status,
                $is_connected,
                $connected_to,
                $connected_to_type,
                $connected_to_id,
                $technician_name,
                $customer_location,
                $technician_notes,
                $connection_date
            ];
            
            if ($customer_image) {
                $params[] = $customer_image;
            }
            
            $params[] = $core_id;
            
            $stmt->execute($params);
            $success_message = "Core updated successfully!";
        }
    } catch(PDOException $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}

// Get box details
$stmt = $pdo->prepare("SELECT b.*, u.username as created_by_name 
                      FROM boxes b 
                      LEFT JOIN users u ON b.created_by = u.id 
                      WHERE b.id = ?");
$stmt->execute([$box_id]);
$box = $stmt->fetch();

if (!$box) {
    die("Box not found!");
}

// Get cores for this box with complete details
$cores = $pdo->prepare("
    SELECT c.*, 
           s.splitter_name,
           s.splitter_type,
           sc.customer_name as splitter_customer_name,
           sc.splitter_core_number,
           sc.comment as splitter_comment,
           sc.power as splitter_power,
           sc.status as splitter_status
    FROM cores c 
    LEFT JOIN splitters s ON c.connected_to_id = s.id
    LEFT JOIN splitter_cores sc ON c.id = sc.core_id
    WHERE c.box_id = ? 
    ORDER BY c.core_number, sc.splitter_core_number
");
$cores->execute([$box_id]);
$cores = $cores->fetchAll();

// Group cores by core number to handle multiple splitter cores
$grouped_cores = [];
foreach ($cores as $core) {
    $core_number = $core['core_number'];
    if (!isset($grouped_cores[$core_number])) {
        $grouped_cores[$core_number] = [
            'core_info' => $core,
            'splitter_cores' => []
        ];
    }
    if ($core['splitter_core_number']) {
        $grouped_cores[$core_number]['splitter_cores'][] = [
            'splitter_core_number' => $core['splitter_core_number'],
            'customer_name' => $core['splitter_customer_name'],
            'comment' => $core['splitter_comment'],
            'power' => $core['splitter_power'],
            'status' => $core['splitter_status']
        ];
    }
}

// Get all splitters for dropdown
$splitters = $pdo->query("SELECT * FROM splitters ORDER BY splitter_name")->fetchAll();

// Get connection statistics
$connection_stats = $pdo->prepare("
    SELECT 
        COUNT(*) as total_cores,
        SUM(CASE WHEN connection_status = 'available' THEN 1 ELSE 0 END) as available_cores,
        SUM(CASE WHEN connection_status = 'connected' THEN 1 ELSE 0 END) as direct_connections,
        SUM(CASE WHEN connection_status = 'split' THEN 1 ELSE 0 END) as splitter_connections
    FROM cores 
    WHERE box_id = ?
");
$connection_stats->execute([$box_id]);
$stats = $connection_stats->fetch();

// Get direct customer connections with enhanced information
$customer_connections = $pdo->prepare("
    SELECT core_number, color, connected_to as customer_name, power_level,
           technician_name, customer_location, customer_image, connection_date, technician_notes
    FROM cores 
    WHERE box_id = ? AND connection_status = 'connected' AND connected_to IS NOT NULL
    ORDER BY core_number
");
$customer_connections->execute([$box_id]);
$customers = $customer_connections->fetchAll();

// Get available cores
$available_cores = $pdo->prepare("
    SELECT core_number, color, power_level
    FROM cores 
    WHERE box_id = ? AND connection_status = 'available'
    ORDER BY core_number
");
$available_cores->execute([$box_id]);
$available = $available_cores->fetchAll();

// Get recent activity
$recent_activity = $pdo->prepare("
    SELECT 
        c.core_number,
        c.connection_status,
        c.connected_to,
        c.connected_to_type,
        c.connection_date,
        c.technician_name
    FROM cores c 
    WHERE c.box_id = ?
    ORDER BY c.connection_date DESC, c.id DESC
    LIMIT 10
");
$recent_activity->execute([$box_id]);
$activities = $recent_activity->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Box Details - <?php echo $box['box_name']; ?></title>
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f8f9fa; }
        
        .header { 
            background: linear-gradient(135deg, #343a40 0%, #495057 100%); 
            color: white; 
            padding: 15px 20px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .container { 
            max-width: 1400px; 
            margin: 0 auto; 
            padding: 20px; 
        }
        
        .back-btn {
            background: #6c757d;
            color: white;
            padding: 8px 15px;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
            transition: background 0.3s;
        }
        
        .back-btn:hover {
            background: #5a6268;
        }
        
        .box-header {
            background: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.1);
        }
        
        .box-title {
            color: #007bff;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .box-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .meta-item {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 6px;
            border-left: 4px solid #007bff;
        }
        
        .meta-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .meta-value {
            font-size: 16px;
            font-weight: 600;
            color: #495057;
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
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            display: block;
            margin-bottom: 5px;
        }
        
        .stat-available { color: #28a745; }
        .stat-direct { color: #007bff; }
        .stat-splitter { color: #ffc107; }
        .stat-total { color: #6c757d; }
        
        .stat-label {
            font-size: 14px;
            color: #6c757d;
            font-weight: 600;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .main-content {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .section-title {
            color: #495057;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e9ecef;
            font-size: 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        #map { 
            height: 300px; 
            width: 100%; 
            border-radius: 6px;
            border: 1px solid #dee2e6;
        }
        
        .cores-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 15px;
        }
        
        .core-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            transition: all 0.3s;
            border-left: 4px solid #007bff;
            position: relative;
        }
        
        .core-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .core-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .core-number {
            font-weight: bold;
            color: #007bff;
            font-size: 16px;
        }
        
        .core-color {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            color: white;
        }
        
        .core-details {
            font-size: 13px;
            color: #6c757d;
            margin-bottom: 8px;
        }
        
        .connection-status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .status-available { background: #28a745; color: white; }
        .status-connected { background: #007bff; color: white; }
        .status-split { background: #ffc107; color: #212529; }
        
        .customer-info {
            background: #e7f3ff;
            padding: 8px;
            border-radius: 4px;
            margin-top: 8px;
            font-size: 12px;
        }
        
        .customer-details-grid {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 8px;
            margin-top: 8px;
        }
        
        .customer-image {
            width: 60px;
            height: 60px;
            border-radius: 6px;
            object-fit: cover;
            border: 2px solid #007bff;
        }
        
        .customer-meta {
            font-size: 11px;
        }
        
        .customer-meta-item {
            margin-bottom: 3px;
        }
        
        .customer-meta-label {
            font-weight: bold;
            color: #495057;
        }
        
        .splitter-info {
            background: #fff3cd;
            padding: 8px;
            border-radius: 4px;
            margin-top: 8px;
            font-size: 12px;
        }
        
        .splitter-cores-list {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            margin-top: 8px;
            font-size: 11px;
        }
        
        .splitter-core-item {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            border-bottom: 1px solid #dee2e6;
        }
        
        .splitter-core-item:last-child {
            border-bottom: none;
        }
        
        .activity-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .activity-item {
            padding: 10px;
            border-left: 3px solid #007bff;
            background: #f8f9fa;
            margin-bottom: 8px;
            border-radius: 0 4px 4px 0;
        }
        
        .activity-core {
            font-weight: bold;
            color: #007bff;
        }
        
        .activity-action {
            font-size: 12px;
            color: #6c757d;
            margin: 2px 0;
        }
        
        .activity-time {
            font-size: 11px;
            color: #999;
        }
        
        .no-cores, .no-activity {
            text-align: center;
            padding: 30px;
            color: #6c757d;
            background: #f8f9fa;
            border-radius: 6px;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #007bff;
            color: white;
        }
        
        .btn-primary:hover {
            background: #0056b3;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        
        .btn-warning:hover {
            background: #e0a800;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        
        .btn-info:hover {
            background: #138496;
        }
        
        .btn-sm {
            padding: 4px 8px;
            font-size: 12px;
        }
        
        .power-level {
            font-weight: bold;
            color: #495057;
        }
        
        .power-good { color: #28a745; }
        .power-warning { color: #ffc107; }
        .power-danger { color: #dc3545; }
        
        .connection-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: bold;
            margin-left: 5px;
        }
        
        .badge-connected { background: #17a2b8; color: white; }
        .badge-disconnected { background: #6c757d; color: white; }
        
        /* Modal Styles */
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
            margin: 2% auto;
            padding: 0;
            border-radius: 8px;
            width: 95%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            animation: modalSlideIn 0.3s ease-out;
        }
        
        @keyframes modalSlideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .modal-header {
            background: #007bff;
            color: white;
            padding: 15px 20px;
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 10;
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
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #495057;
        }
        
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border: 2px solid #e9ecef;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: #007bff;
            outline: none;
        }
        
        .modal-footer {
            padding: 15px 20px;
            background: #f8f9fa;
            border-radius: 0 0 8px 8px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            position: sticky;
            bottom: 0;
        }
        
        .edit-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 4px 8px;
            cursor: pointer;
            font-size: 12px;
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .core-card:hover .edit-btn {
            opacity: 1;
        }
        
        .edit-btn:hover {
            background: #5a6268;
        }
        
        .alert {
            padding: 12px 15px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .connection-fields {
            background: #e7f3ff;
            padding: 12px;
            border-radius: 6px;
            margin-top: 10px;
            border: 1px solid #b8daff;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        .data-table th,
        .data-table td {
            padding: 8px 12px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        
        .data-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }
        
        .data-table tr:hover {
            background: #f8f9fa;
        }
        
        .customer-image-cell {
            width: 80px;
        }
        
        .customer-image-table {
            width: 50px;
            height: 50px;
            border-radius: 4px;
            object-fit: cover;
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
        
        .file-input-wrapper input[type=file] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
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
        }
        
        .file-input-label:hover {
            border-color: #007bff;
            background: #e7f3ff;
        }
        
        /* UPDATED: Wider customer details modal */
        .customer-details-modal {
            max-width: 700px; /* Increased from 500px to 700px */
            width: 90%;
        }
        
        .customer-details-header {
            background: #007bff;
            color: white;
            padding: 15px 20px;
            border-radius: 8px 8px 0 0;
        }
        
        .customer-details-body {
            padding: 20px;
        }
        
        .customer-details-image {
            text-align: center;
            margin-bottom: 20px;
            width: 100%;
        }
        
        /* UPDATED: Wider image styling */
        .customer-details-image img {
            max-width: 100%; /* Changed from 200px to 100% */
            max-height: 400px; /* Increased max height */
            width: auto;
            height: auto;
            border-radius: 8px;
            border: 3px solid #007bff;
            object-fit: contain; /* Ensure image maintains aspect ratio */
        }
        
        .customer-details-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 6px;
            margin-top: 15px;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .info-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        
        .info-label {
            font-weight: bold;
            width: 150px; /* Slightly wider for better alignment */
            color: #495057;
            flex-shrink: 0;
        }
        
        .info-value {
            flex: 1;
            color: #6c757d;
            word-break: break-word; /* Prevent long text from breaking layout */
        }

        /* Splitter Styles */
        .splitter-type-buttons {
            display: flex;
            gap: 5px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        
        .splitter-type-btn {
            padding: 8px 12px;
            border: 1px solid #dee2e6;
            background: #f8f9fa;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s;
        }
        
        .splitter-type-btn.active {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }
        
        .splitter-type-btn:hover {
            background: #e9ecef;
        }
        
        .splitter-type-btn.active:hover {
            background: #0056b3;
        }
        
        .splitter-core-row {
            display: grid;
            grid-template-columns: 80px 1fr 1fr 100px;
            gap: 10px;
            padding: 10px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            margin-bottom: 10px;
            background: #f8f9fa;
            align-items: start;
        }
        
        .splitter-core-number {
            font-weight: bold;
            color: #007bff;
            display: flex;
            align-items: center;
            height: 100%;
        }
        
        .form-group-sm {
            margin-bottom: 0;
        }
        
        .form-group-sm label {
            font-size: 11px;
            margin-bottom: 2px;
            color: #6c757d;
        }
        
        .form-group-sm input,
        .form-group-sm textarea {
            font-size: 12px;
            padding: 4px 8px;
        }
        
        .splitter-cores-header {
            background: #007bff;
            color: white;
            padding: 8px 12px;
            border-radius: 4px;
            margin-bottom: 10px;
            font-weight: bold;
            text-align: center;
        }
        
        .splitter-cores-container {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 10px;
            background: white;
        }

        /* NEW: Full screen image viewer */
        .image-viewer-modal {
            max-width: 95%;
            width: 95%;
            max-height: 95vh;
        }
        
        .image-viewer-content {
            text-align: center;
            padding: 0;
            background: rgba(0,0,0,0.9);
        }
        
        .image-viewer-content img {
            max-width: 100%;
            max-height: 85vh;
            width: auto;
            height: auto;
            object-fit: contain;
        }
        
        .image-viewer-controls {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 1001;
        }
        
        .image-viewer-controls button {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            font-size: 20px;
            cursor: pointer;
            margin-left: 10px;
            transition: background 0.3s;
        }
        
        .image-viewer-controls button:hover {
            background: rgba(255,255,255,0.4);
        }
    </style>
</head>
<body>
    <div class="header">
        <h1> Fiber Box Details - <?php echo $box['box_name']; ?></h1>
        <div>
            <span>Welcome, <strong><?php echo $_SESSION['username']; ?></strong></span>
            <a href="dashboard.php" class="back-btn">← Back to Dashboard</a>
        </div>
    </div>
    <div class="container">
        <!-- Success/Error Messages -->
        <?php if (isset($success_message)): ?>
            <div class="alert alert-success">
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            <div class="alert alert-error">
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <!-- Box Header -->
        <div class="box-header">
            <div class="box-title">
                <h1><?php echo $box['box_name']; ?></h1>
                <div class="action-buttons">
                    <button onclick="openEditBoxModal()" class="btn btn-warning">
                        <i class="bi bi-pencil"></i> Edit Box
                    </button>

                   <a href="export_excel.php?box_id=<?php echo $box['id']; ?>" 
   style="background: #007bff; color: white; padding: 5px 10px; text-decoration: none; border-radius: 3px; font-size: 12px;">
    Export to Excel
</a>
                </div>
            </div>
            <div class="box-meta">
                <div class="meta-item">
                    <div class="meta-label">Total Cores</div>
                    <div class="meta-value"><?php echo $box['total_cores']; ?> cores</div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Created By</div>
                    <div class="meta-value"><?php echo $box['created_by_name']; ?></div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Created Date</div>
                    <div class="meta-value"><?php echo date('M j, Y g:i A', strtotime($box['created_at'])); ?></div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Location</div>
                    <div class="meta-value">
                        <?php if ($box['location_lat'] && $box['location_lng']): ?>
                            <?php echo $box['location_lat']; ?>, <?php echo $box['location_lng']; ?>
                        <?php else: ?>
                            Not set
                        <?php endif; ?>
                    </div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">Address</div>
                    <div class="meta-value"><?php echo $box['address'] ?: 'Not specified'; ?></div>
                </div>
            </div>
        </div>

        <!-- Connection Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-number stat-total"><?php echo $stats['total_cores']; ?></span>
                <span class="stat-label">Total Cores</span>
            </div>
            <div class="stat-card">
                <span class="stat-number stat-available"><?php echo $stats['available_cores']; ?></span>
                <span class="stat-label">Available Cores</span>
            </div>
            <div class="stat-card">
                <span class="stat-number stat-direct"><?php echo $stats['direct_connections']; ?></span>
                <span class="stat-label">Direct Connections</span>
            </div>
            <div class="stat-card">
                <span class="stat-number stat-splitter"><?php echo $stats['splitter_connections']; ?></span>
                <span class="stat-label">Splitter Connections</span>
            </div>
        </div>

        <div class="content-grid">
            <!-- Main Content -->
            <div class="main-content">
                <!-- All Cores Overview -->
                <div class="section">
                    <h3 class="section-title">
                        <span>All Core Connections</span>
                        <span style="font-size: 14px; color: #6c757d;">Total: <?php echo count($grouped_cores); ?> cores</span>
                    </h3>
                    <?php if ($grouped_cores): ?>
                        <div class="cores-grid">
                            <?php foreach($grouped_cores as $core_number => $core_data): 
                                $core = $core_data['core_info'];
                                $splitter_cores = $core_data['splitter_cores'];
                            ?>
                                <div class="core-card">
                                    <button class="edit-btn" onclick="openEditCoreModal(<?php echo $core['id']; ?>)">
                                         Edit
                                    </button>
                                    
                                    <div class="core-header">
                                        <span class="core-number">Core <?php echo $core['core_number']; ?></span>
                                        <span class="core-color" style="background-color: <?php echo getColorHex($core['color']); ?>">
                                            <?php echo $core['color']; ?>
                                        </span>
                                    </div>
                                    
                                    <div class="core-details">
                                        <strong>Power Level:</strong> 
                                        <span class="power-level <?php echo getPowerClass($core['power_level']); ?>">
                                            <?php echo $core['power_level']; ?> dBm
                                        </span>
                                    </div>
                                    
                                    <div class="core-details">
                                        <strong>Status:</strong> 
                                        <span class="connection-status status-<?php echo $core['connection_status']; ?>">
                                            <?php echo ucfirst($core['connection_status']); ?>
                                        </span>
                                        <span class="connection-badge <?php echo $core['is_connected'] ? 'badge-connected' : 'badge-disconnected'; ?>">
                                            <?php echo $core['is_connected'] ? 'CONNECTED' : 'DISCONNECTED'; ?>
                                        </span>
                                    </div>
                                    
                                    <?php if ($core['connection_status'] == 'connected' && $core['connected_to']): ?>
                                        <div class="customer-info">
                                            <strong> Customer:</strong> <?php echo $core['connected_to']; ?>
                                            
                                            <?php if ($core['technician_name'] || $core['customer_location'] || $core['customer_image']): ?>
                                                <div class="customer-details-grid">
                                                    <?php if ($core['customer_image']): ?>
                                                        <div>
                                                            <img src="<?php echo $core['customer_image']; ?>" alt="Customer Location" class="customer-image" 
                                                                 onclick="viewCustomerDetails(<?php echo $core['id']; ?>)"
                                                                 style="cursor: pointer;">
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="customer-meta">
                                                        <?php if ($core['technician_name']): ?>
                                                            <div class="customer-meta-item">
                                                                <span class="customer-meta-label">Technician:</span> <?php echo $core['technician_name']; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($core['customer_location']): ?>
                                                            <div class="customer-meta-item">
                                                                <span class="customer-meta-label">Location:</span> <?php echo $core['customer_location']; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($core['connection_date']): ?>
                                                            <div class="customer-meta-item">
                                                                <span class="customer-meta-label">Connected:</span> <?php echo date('M j, Y', strtotime($core['connection_date'])); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($core['connection_status'] == 'split' && $core['splitter_name']): ?>
                                        <div class="splitter-info">
                                            <strong> Splitter:</strong> <?php echo $core['splitter_name']; ?> (<?php echo $core['splitter_type'] ?? 'N/A'; ?>)
                                        </div>
                                        <?php if ($splitter_cores): ?>
                                            <div class="splitter-cores-list">
                                                <strong>Splitter Cores (<?php echo count($splitter_cores); ?>):</strong>
                                                <?php foreach($splitter_cores as $sc): ?>
                                                    <div class="splitter-core-item">
                                                        <span>Core <?php echo $sc['splitter_core_number']; ?></span>
                                                        <span>
                                                            <?php if ($sc['customer_name']): ?>
                                                                → <?php echo $sc['customer_name']; ?> (<?php echo $sc['power']; ?> dBm)
                                                            <?php else: ?>
                                                                <em style="color: #6c757d;">Available</em>
                                                            <?php endif; ?>
                                                        </span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php elseif ($core['is_connected'] && !$core['connected_to']): ?>
                                        <div class="customer-info">
                                            <strong>Connected</strong> (No destination specified)
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($core['technician_notes']): ?>
                                        <div class="core-details" style="margin-top: 8px;">
                                            <strong>Notes:</strong> <?php echo $core['technician_notes']; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-cores">
                            <h4>No cores configured for this box</h4>
                            <p>Add cores to start managing connections</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Detailed Summary -->
                <div class="section">
                    <h3 class="section-title">
                        <span>Detailed Summary</span>
                    </h3>
                    
                    <!-- Customer Connections -->
                    <?php if ($customers): ?>
                        <div style="margin-bottom: 20px;">
                            <h4 style="color: #007bff; margin-bottom: 10px;">
                             Direct Customer Connections (<?php echo count($customers); ?>)
                            </h4>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Core</th>
                                        <th>Color</th>
                                        <th>Customer Name</th>
                                        <th>Technician</th>
                                        <th>Location</th>
                                        <th>Power Level</th>
                                        <th>Image</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($customers as $customer): ?>
                                        <tr>
                                            <td><strong><?php echo $customer['core_number']; ?></strong></td>
                                            <td>
                                                <span style="background-color: <?php echo getColorHex($customer['color']); ?>; 
                                                          color: white; padding: 2px 6px; border-radius: 8px; font-size: 10px;">
                                                    <?php echo $customer['color']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo $customer['customer_name']; ?></td>
                                            <td><?php echo $customer['technician_name'] ?: 'N/A'; ?></td>
                                            <td><?php echo $customer['customer_location'] ?: 'N/A'; ?></td>
                                            <td class="power-level <?php echo getPowerClass($customer['power_level']); ?>">
                                                <?php echo $customer['power_level']; ?> dBm
                                            </td>
                                            <td class="customer-image-cell">
                                                <?php if ($customer['customer_image']): ?>
                                                    <img src="<?php echo $customer['customer_image']; ?>" 
                                                         alt="Customer Location" 
                                                         class="customer-image-table"
                                                         onclick="viewCustomerDetailsByCore(<?php echo $customer['core_number']; ?>)"
                                                         style="cursor: pointer;">
                                                <?php else: ?>
                                                    <span style="color: #6c757d; font-size: 11px;">No image</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-info" 
                                                        onclick="viewCustomerDetailsByCore(<?php echo $customer['core_number']; ?>)">
                                                    <i class="bi bi-eye"></i> View
                                                </button>
                                                <button class="btn btn-sm btn-warning" 
                                                        onclick="openEditCoreByNumber(<?php echo $customer['core_number']; ?>)">
                                                    <i class="bi bi-pencil"></i> Edit
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <!-- Available Cores -->
                    <?php if ($available): ?>
                        <div>
                            <h4 style="color: #28a745; margin-bottom: 10px;">
                                 Available Cores (<?php echo count($available); ?>)
                            </h4>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Core</th>
                                        <th>Color</th>
                                        <th>Power Level</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($available as $avail): ?>
                                        <tr>
                                            <td><strong><?php echo $avail['core_number']; ?></strong></td>
                                            <td>
                                                <span style="background-color: <?php echo getColorHex($avail['color']); ?>; 
                                                          color: white; padding: 2px 6px; border-radius: 8px; font-size: 10px;">
                                                    <?php echo $avail['color']; ?>
                                                </span>
                                            </td>
                                            <td class="power-level <?php echo getPowerClass($avail['power_level']); ?>">
                                                <?php echo $avail['power_level']; ?> dBm
                                            </td>
                                            <td>
                                                <span class="connection-status status-available">Available</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="sidebar">
                <!-- Location Map -->
                <?php if ($box['location_lat'] && $box['location_lng']): ?>
                    <div class="section">
                        <h3 class="section-title"> 
                            <span><i class="bi bi-geo-alt"></i> Box Location</span>
                        </h3>
                        <div id="map"></div>
                        <div style="margin-top: 10px; font-size: 12px; color: #6c757d;">
                            <strong>Address:</strong><br>
                            <?php echo $box['address']; ?>
                        </div>
                        <div style="margin-top: 5px; font-size: 11px; color: #999;">
                            <strong>Coordinates:</strong> 
                            <?php echo $box['location_lat']; ?>, <?php echo $box['location_lng']; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Recent Activity -->
                <div class="section">
                    <h3 class="section-title">
                        <span><i class="bi bi-clock-history"></i> Recent Activity</span>
                    </h3>
                    <?php if ($activities): ?>
                        <div class="activity-list">
                            <?php foreach($activities as $activity): ?>
                                <div class="activity-item">
                                    <div class="activity-core">
                                        Core <?php echo $activity['core_number']; ?>
                                    </div>
                                    <div class="activity-action">
                                        <?php if ($activity['connection_status'] == 'connected'): ?>
                                            Connected to <?php echo $activity['connected_to']; ?>
                                            <?php if ($activity['technician_name']): ?>
                                                by <?php echo $activity['technician_name']; ?>
                                            <?php endif; ?>
                                        <?php elseif ($activity['connection_status'] == 'split'): ?>
                                            Connected to splitter
                                        <?php else: ?>
                                            Set as available
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($activity['connection_date']): ?>
                                        <div class="activity-time">
                                            <?php echo date('M j, g:i A', strtotime($activity['connection_date'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="no-activity">
                            <p>No recent activity</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Box Modal -->
    <div id="editBoxModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="bi bi-pencil"></i> Edit Box Information</h3>
                <button class="close" onclick="closeEditBoxModal()">&times;</button>
            </div>
            <form method="POST" id="editBoxForm">
                <input type="hidden" name="update_box" value="1">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Box Name:</label>
                        <input type="text" name="box_name" value="<?php echo htmlspecialchars($box['box_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Address:</label>
                        <textarea name="address" rows="3" required><?php echo htmlspecialchars($box['address']); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Latitude:</label>
                        <input type="text" name="location_lat" value="<?php echo $box['location_lat']; ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Longitude:</label>
                        <input type="text" name="location_lng" value="<?php echo $box['location_lng']; ?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeEditBoxModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Core Modal -->
    <div id="editCoreModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="bi bi-pencil"></i> Edit Core Configuration</h3>
                <button class="close" onclick="closeEditCoreModal()">&times;</button>
            </div>
            <form method="POST" id="editCoreForm" enctype="multipart/form-data">
                <input type="hidden" name="update_core" value="1">
                <input type="hidden" name="core_id" id="edit_core_id">
                <input type="hidden" name="splitter_type" id="edit_splitter_type" value="1x2">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Core Color:</label>
                        <select name="color" id="edit_core_color" required>
                            <?php foreach(getFiberColors() as $color): ?>
                                <option value="<?php echo $color; ?>"><?php echo $color; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Power Level (dBm):</label>
                        <input type="number" step="0.01" name="power_level" id="edit_power_level" required max="25" min="-50">
                    </div>
                    <div class="form-group">
                        <label>Connection Type:</label>
                        <select name="connection_type" id="edit_connection_type" onchange="toggleConnectionFields()">
                            <option value="available">Available (Not Connected)</option>
                            <option value="direct">Direct to Customer</option>
                            <option value="splitter">Through Splitter</option>
                        </select>
                    </div>
                    
                    <div id="connection_fields" class="connection-fields" style="display: none;">
                        <div id="direct_fields">
                            <div class="form-group">
                                <label>Customer Name:</label>
                                <input type="text" name="customer_name" id="edit_customer_name" placeholder="Enter customer name">
                            </div>
                            <div class="form-group">
                                <label>Technician Name:</label>
                                <input type="text" name="technician_name" id="edit_technician_name" placeholder="Enter technician name">
                            </div>
                            <div class="form-group">
                                <label>Customer Location:</label>
                                <input type="text" name="customer_location" id="edit_customer_location" placeholder="Enter customer location">
                            </div>
                            <div class="form-group">
                                <label>Customer Image:</label>
                                <div class="file-input-wrapper">
                                    <label class="file-input-label" id="fileInputLabel">
                                        <i class="bi bi-cloud-upload"></i> Choose customer image...
                                    </label>
                                    <input type="file" name="customer_image" id="edit_customer_image" accept="image/*" onchange="previewImage(this)">
                                </div>
                                <div id="imagePreview"></div>
                            </div>
                            <div class="form-group">
                                <label>Technician Notes:</label>
                                <textarea name="technician_notes" id="edit_technician_notes" placeholder="Add technician notes" rows="3"></textarea>
                            </div>
                        </div>
                        <div id="splitter_fields">
                            <div class="form-group">
                                <label>Select Splitter:</label>
                                <select name="splitter_id" id="edit_splitter_id">
                                    <option value="">-- Select Splitter --</option>
                                    <?php foreach($splitters as $splitter): ?>
                                        <option value="<?php echo $splitter['id']; ?>">
                                            <?php echo $splitter['splitter_name'] . ' (' . $splitter['splitter_type'] . ')'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="splitter-type-selector">
                                <label>Splitter Type:</label>
                                <div class="splitter-type-buttons">
                                    <button type="button" class="splitter-type-btn active" data-type="1x2" onclick="selectSplitterType('1x2')">1x2 (2 Cores)</button>
                                    <button type="button" class="splitter-type-btn" data-type="1x4" onclick="selectSplitterType('1x4')">1x4 (4 Cores)</button>
                                    <button type="button" class="splitter-type-btn" data-type="1x8" onclick="selectSplitterType('1x8')">1x8 (8 Cores)</button>
                                    <button type="button" class="splitter-type-btn" data-type="1x16" onclick="selectSplitterType('1x16')">1x16 (16 Cores)</button>
                                    <button type="button" class="splitter-type-btn" data-type="1x32" onclick="selectSplitterType('1x32')">1x32 (32 Cores)</button>
                                </div>
                            </div>
                            
                            <div class="splitter-cores-container">
                                <div class="splitter-cores-header">
                                    Splitter Cores Configuration
                                </div>
                                <div id="splitter-cores-list">
                                    <!-- Splitter cores will be dynamically added here -->
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Notes:</label>
                        <textarea name="notes" id="edit_notes" placeholder="Add any notes about this core" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeEditCoreModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Customer Details Modal -->
    <div id="customerDetailsModal" class="modal">
        <div class="modal-content customer-details-modal">
            <div class="customer-details-header">
                <h3> Customer Details</h3>
                <button class="close" onclick="closeCustomerDetailsModal()">&times;</button>
            </div>
            <div class="customer-details-body">
                <div id="customerDetailsContent">
                    <!-- Customer details will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeCustomerDetailsModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- NEW: Full Screen Image Viewer Modal -->
    <div id="imageViewerModal" class="modal">
        <div class="modal-content image-viewer-modal">
            <div class="image-viewer-controls">
                <button onclick="closeImageViewer()" title="Close">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div class="image-viewer-content">
                <img id="fullSizeImage" src="" alt="Full size customer image">
            </div>
        </div>
    </div>

    <!-- Leaflet JavaScript -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <script>
        // Initialize Map if location exists
        <?php if ($box['location_lat'] && $box['location_lng']): ?>
        function initMap() {
            const map = L.map('map').setView([<?php echo $box['location_lat']; ?>, <?php echo $box['location_lng']; ?>], 15);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                maxZoom: 18
            }).addTo(map);

            L.marker([<?php echo $box['location_lat']; ?>, <?php echo $box['location_lng']; ?>])
                .addTo(map)
                .bindPopup(`
                    <div style="padding: 10px;">
                        <h4 style="margin: 0 0 10px 0; color: #007bff;"><?php echo addslashes($box['box_name']); ?></h4>
                        <p style="margin: 5px 0;"><strong> Cores:</strong> <?php echo $box['total_cores']; ?> cores</p>
                        <p style="margin: 5px 0;"><strong> Address:</strong> <?php echo addslashes($box['address']); ?></p>
                        <p style="margin: 5px 0;"><strong> Available:</strong> <?php echo $stats['available_cores']; ?> cores</p>
                    </div>
                `)
                .openPopup();
        }

        // Initialize map when page loads
        document.addEventListener('DOMContentLoaded', function() {
            initMap();
        });
        <?php endif; ?>

        // Modal Functions
        function openEditBoxModal() {
            document.getElementById('editBoxModal').style.display = 'block';
        }

        function closeEditBoxModal() {
            document.getElementById('editBoxModal').style.display = 'none';
        }

        let currentCoreData = null;

        function openEditCoreModal(coreId) {
            // Fetch core data from server
            fetch(`get_core_data.php?core_id=${coreId}`)
                .then(response => response.json())
                .then(coreData => {
                    document.getElementById('edit_core_id').value = coreData.id;
                    document.getElementById('edit_core_color').value = coreData.color;
                    document.getElementById('edit_power_level').value = coreData.power_level;
                    
                    // FIX: Map connection status to connection type
                    let connectionType = 'available';
                    if (coreData.connection_status === 'connected') {
                        connectionType = 'direct';
                    } else if (coreData.connection_status === 'split') {
                        connectionType = 'splitter';
                    }
                    
                    document.getElementById('edit_connection_type').value = connectionType;
                    document.getElementById('edit_customer_name').value = coreData.connected_to || '';
                    document.getElementById('edit_technician_name').value = coreData.technician_name || '';
                    document.getElementById('edit_customer_location').value = coreData.customer_location || '';
                    document.getElementById('edit_technician_notes').value = coreData.technician_notes || '';
                    document.getElementById('edit_splitter_id').value = coreData.connected_to_id || '';
                    document.getElementById('edit_notes').value = coreData.notes || '';
                    
                    // Set splitter type
                    selectSplitterType(coreData.splitter_type || '1x2');
                    
                    // Load splitter cores
                    if (coreData.splitter_cores && coreData.splitter_cores.length > 0) {
                        loadSplitterCores(coreData.splitter_cores);
                    } else {
                        generateSplitterCores();
                    }
                    
                    // Show connection fields based on type
                    toggleConnectionFields();
                    
                    document.getElementById('editCoreModal').style.display = 'block';
                })
                .catch(error => {
                    console.error('Error fetching core data:', error);
                    alert('Error loading core data');
                });
        }

        function openEditCoreByNumber(coreNumber) {
            // Find core ID by core number
            const core = <?php echo json_encode($grouped_cores); ?>[coreNumber];
            if (core && core.core_info) {
                openEditCoreModal(core.core_info.id);
            }
        }

        function closeEditCoreModal() {
            document.getElementById('editCoreModal').style.display = 'none';
            currentCoreData = null;
            // Reset image preview
            document.getElementById('imagePreview').innerHTML = '';
            document.getElementById('fileInputLabel').innerHTML = '<i class="bi bi-cloud-upload"></i> Choose customer image...';
        }

        function toggleConnectionFields() {
            const connectionType = document.getElementById('edit_connection_type').value;
            const connectionFields = document.getElementById('connection_fields');
            const directFields = document.getElementById('direct_fields');
            const splitterFields = document.getElementById('splitter_fields');
            
            if (connectionType === 'available') {
                connectionFields.style.display = 'none';
            } else {
                connectionFields.style.display = 'block';
                
                if (connectionType === 'direct') {
                    directFields.style.display = 'block';
                    splitterFields.style.display = 'none';
                } else if (connectionType === 'splitter') {
                    directFields.style.display = 'none';
                    splitterFields.style.display = 'block';
                }
            }
        }

        function selectSplitterType(type) {
            // Update active button
            document.querySelectorAll('.splitter-type-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelector(`.splitter-type-btn[data-type="${type}"]`).classList.add('active');
            
            // Update hidden field
            document.getElementById('edit_splitter_type').value = type;
            
            // Generate cores based on type
            generateSplitterCores();
        }

        function generateSplitterCores() {
            const type = document.getElementById('edit_splitter_type').value;
            const coresList = document.getElementById('splitter-cores-list');
            coresList.innerHTML = '';
            
            let coreCount = 2;
            switch(type) {
                case '1x4': coreCount = 4; break;
                case '1x8': coreCount = 8; break;
                case '1x16': coreCount = 16; break;
                case '1x32': coreCount = 32; break;
            }
            
            for (let i = 1; i <= coreCount; i++) {
                const coreRow = document.createElement('div');
                coreRow.className = 'splitter-core-row';
                coreRow.innerHTML = `
                    <div class="splitter-core-number">Core ${i}</div>
                    <div class="form-group-sm">
                        <label>Customer Name</label>
                        <input type="text" name="splitter_cores[${i}][customer_name]" placeholder="Customer name (optional)">
                    </div>
                    <div class="form-group-sm">
                        <label>Comment</label>
                        <textarea name="splitter_cores[${i}][comment]" placeholder="Additional notes" rows="2"></textarea>
                    </div>
                    <div class="form-group-sm">
                        <label>Power (dBm)</label>
                        <input type="number" step="0.01" name="splitter_cores[${i}][power]" placeholder="-20.00" max="25" min="-50">
                    </div>
                    <input type="hidden" name="splitter_cores[${i}][core_number]" value="${i}">
                `;
                coresList.appendChild(coreRow);
            }
        }

        function loadSplitterCores(coresData) {
            const coresList = document.getElementById('splitter-cores-list');
            coresList.innerHTML = '';
            
            coresData.forEach(core => {
                const coreRow = document.createElement('div');
                coreRow.className = 'splitter-core-row';
                coreRow.innerHTML = `
                    <div class="splitter-core-number">Core ${core.splitter_core_number}</div>
                    <div class="form-group-sm">
                        <label>Customer Name</label>
                        <input type="text" name="splitter_cores[${core.splitter_core_number}][customer_name]" 
                               value="${core.customer_name || ''}" placeholder="Customer name (optional)">
                    </div>
                    <div class="form-group-sm">
                        <label>Comment</label>
                        <textarea name="splitter_cores[${core.splitter_core_number}][comment]" 
                                  placeholder="Additional notes" rows="2">${core.comment || ''}</textarea>
                    </div>
                    <div class="form-group-sm">
                        <label>Power (dBm)</label>
                        <input type="number" step="0.01" name="splitter_cores[${core.splitter_core_number}][power]" 
                               value="${core.power || ''}" placeholder="-20.00" max="25" min="-50">
                    </div>
                    <input type="hidden" name="splitter_cores[${core.splitter_core_number}][core_number]" value="${core.splitter_core_number}">
                `;
                coresList.appendChild(coreRow);
            });
        }

        function previewImage(input) {
            const preview = document.getElementById('imagePreview');
            const fileLabel = document.getElementById('fileInputLabel');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    preview.innerHTML = `<img src="${e.target.result}" class="image-preview" alt="Image preview">`;
                    fileLabel.innerHTML = `<i class="bi bi-check-circle"></i> ${input.files[0].name}`;
                }
                
                reader.readAsDataURL(input.files[0]);
            } else {
                preview.innerHTML = '';
                fileLabel.innerHTML = '<i class="bi bi-cloud-upload"></i> Choose customer image...';
            }
        }

        function viewCustomerDetails(coreId) {
            fetch(`get_customer_details.php?core_id=${coreId}`)
                .then(response => response.json())
                .then(customerData => {
                    const content = document.getElementById('customerDetailsContent');
                    content.innerHTML = `
                        <div class="customer-details-image">
                            ${customerData.customer_image ? 
                                `<img src="${customerData.customer_image}" alt="Customer Location" 
                                      onclick="openFullSizeImage('${customerData.customer_image}')"
                                      style="cursor: zoom-in;">` : 
                                '<div style="padding: 40px; background: #f8f9fa; border-radius: 8px; text-align: center; color: #6c757d;">No Image Available</div>'
                            }
                        </div>
                        <div class="customer-details-info">
                            <div class="info-row">
                                <div class="info-label">Customer Name:</div>
                                <div class="info-value">${customerData.connected_to || 'N/A'}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Core Number:</div>
                                <div class="info-value">${customerData.core_number}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Core Color:</div>
                                <div class="info-value">${customerData.color}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Technician:</div>
                                <div class="info-value">${customerData.technician_name || 'N/A'}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Location:</div>
                                <div class="info-value">${customerData.customer_location || 'N/A'}</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Power Level:</div>
                                <div class="info-value">${customerData.power_level} dBm</div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Connection Date:</div>
                                <div class="info-value">${customerData.connection_date ? new Date(customerData.connection_date).toLocaleDateString() : 'N/A'}</div>
                            </div>
                            ${customerData.technician_notes ? `
                            <div class="info-row">
                                <div class="info-label">Technician Notes:</div>
                                <div class="info-value">${customerData.technician_notes}</div>
                            </div>` : ''}
                        </div>
                    `;
                    document.getElementById('customerDetailsModal').style.display = 'block';
                })
                .catch(error => {
                    console.error('Error fetching customer details:', error);
                    alert('Error loading customer details');
                });
        }

        // NEW: Full screen image viewer function
        function openFullSizeImage(imageSrc) {
            document.getElementById('fullSizeImage').src = imageSrc;
            document.getElementById('imageViewerModal').style.display = 'block';
        }

        function closeImageViewer() {
            document.getElementById('imageViewerModal').style.display = 'none';
        }

        function viewCustomerDetailsByCore(coreNumber) {
            const core = <?php echo json_encode($grouped_cores); ?>[coreNumber];
            if (core && core.core_info) {
                viewCustomerDetails(core.core_info.id);
            }
        }

        function closeCustomerDetailsModal() {
            document.getElementById('customerDetailsModal').style.display = 'none';
        }

        function exportBoxData() {
            const boxName = '<?php echo $box['box_name']; ?>';
            const exportData = {
                box: <?php echo json_encode($box); ?>,
                statistics: <?php echo json_encode($stats); ?>,
                cores: <?php echo json_encode($grouped_cores); ?>,
                customers: <?php echo json_encode($customers); ?>,
                availableCores: <?php echo json_encode($available); ?>,
                exportDate: new Date().toISOString(),
                exportedBy: '<?php echo $_SESSION['username']; ?>'
            };
            
            const dataStr = JSON.stringify(exportData, null, 2);
            const dataBlob = new Blob([dataStr], {type: 'application/json'});
            const url = URL.createObjectURL(dataBlob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `box_${boxName}_export_<?php echo date('Y-m-d'); ?>.json`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
            
            alert(`Data exported for ${boxName}`);
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const editBoxModal = document.getElementById('editBoxModal');
            const editCoreModal = document.getElementById('editCoreModal');
            const customerDetailsModal = document.getElementById('customerDetailsModal');
            const imageViewerModal = document.getElementById('imageViewerModal');
            
            if (event.target === editBoxModal) {
                closeEditBoxModal();
            }
            if (event.target === editCoreModal) {
                closeEditCoreModal();
            }
            if (event.target === customerDetailsModal) {
                closeCustomerDetailsModal();
            }
            if (event.target === imageViewerModal) {
                closeImageViewer();
            }
        }

        // Initialize splitter cores on page load
        document.addEventListener('DOMContentLoaded', function() {
            generateSplitterCores();
        });
    </script>
</body>
</html>

<?php
// Helper functions
function getColorHex($color) {
    $colors = [
        'Blue' => '#007bff',
        'Orange' => '#fd7e14',
        'Green' => '#28a745',
        'Brown' => '#795548',
        'Slate' => '#6c757d',
        'White' => '#ffffff',
        'Red' => '#dc3545',
        'Black' => '#000000',
        'Yellow' => '#ffc107',
        'Violet' => '#6f42c1',
        'Rose' => '#e83e8c',
        'Aqua' => '#17a2b8'
    ];
    return $colors[$color] ?? '#6c757d';
}

function getFiberColors() {
    return ['Blue', 'Orange', 'Green', 'Brown', 'Slate', 'White', 'Red', 'Black', 'Yellow', 'Violet', 'Rose', 'Aqua'];
}

function getPowerClass($powerLevel) {
    if ($powerLevel <= -20) return 'power-good';
    if ($powerLevel <= -15) return 'power-warning';
    return 'power-danger';
}