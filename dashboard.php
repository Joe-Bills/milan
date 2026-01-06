<?php 
include 'config.php';
// session_start();


// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['box_name'])) {
    try {
        // Start transaction for data consistency
        $pdo->beginTransaction();
        
        // Validate power levels before insertion
        $totalCores = $_POST['total_cores'];
        $validPower = true;
        $errorMessage = "";
        
        for ($i = 1; $i <= $totalCores; $i++) {
            $powerLevel = $_POST['power_level_' . $i] ?? 0;
            if ($powerLevel > 25) {
                $validPower = false;
                $errorMessage = "Error: Power level for Core $i cannot be greater than 25 dBm";
                break;
            }
        }
        
        if (!$validPower) {
            echo "<script>alert('$errorMessage');</script>";
        } else {
            // Insert box data
            $stmt = $pdo->prepare("INSERT INTO boxes (box_name, total_cores, location_lat, location_lng, address, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['box_name'],
                $_POST['total_cores'],
                $_POST['location_lat'],
                $_POST['location_lng'],
                $_POST['address'],
                $_SESSION['user_id']
            ]);
            
            $box_id = $pdo->lastInsertId();
            
            // Insert core data
            for ($i = 1; $i <= $_POST['total_cores']; $i++) {
                $connection_type = $_POST['connection_type_' . $i] ?? 'available';
                $customer_name = $_POST['customer_name_' . $i] ?? null;
                
                // Determine connection status based on your database structure
                $connection_status = 'available';
                $is_connected = 0;
                $connected_to = null;
                $connected_to_type = null;
                
                if ($connection_type === 'direct' && !empty($customer_name)) {
                    $connection_status = 'connected';
                    $is_connected = 1;
                    $connected_to = $customer_name;
                    $connected_to_type = 'customer';
                } elseif ($connection_type === 'splitter') {
                    $connection_status = 'split';
                    $is_connected = 1;
                    $connected_to_type = 'splitter';
                }
                
                // Insert core data using correct column names from your database
                $stmt = $pdo->prepare("INSERT INTO cores (box_id, core_number, color, power_level, connection_status, is_connected, connected_to, connected_to_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $box_id,
                    $i,
                    $_POST['color_' . $i],
                    $_POST['power_level_' . $i],
                    $connection_status,
                    $is_connected,
                    $connected_to,
                    $connected_to_type
                ]);
                
                $core_id = $pdo->lastInsertId();
                
                // Handle splitter cores if this is a splitter connection
                if ($connection_type === 'splitter') {
                    $splitter_cores_count = $_POST['splitter_cores_count_' . $i] ?? 0;
                    
                    if ($splitter_cores_count > 0) {
                        for ($j = 1; $j <= $splitter_cores_count; $j++) {
                            $splitter_customer_name = $_POST['splitter_customer_name_' . $i . '_' . $j] ?? null;
                            $splitter_comment = $_POST['splitter_core_comment_' . $i . '_' . $j] ?? null;
                            
                            $status = !empty($splitter_customer_name) ? 'connected' : 'available';
                            
                            $stmt = $pdo->prepare("INSERT INTO splitter_cores (core_id, splitter_core_number, customer_name, comment, status) VALUES (?, ?, ?, ?, ?)");
                            $stmt->execute([
                                $core_id,
                                $j,
                                $splitter_customer_name,
                                $splitter_comment,
                                $status
                            ]);
                        }
                    }
                }
            }
            
            // Commit transaction
            $pdo->commit();
            echo "<script>alert('Box added successfully!'); window.location.href = 'dashboard.php';</script>";
        }
    } catch(PDOException $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "');</script>";
        error_log("Database Error: " . $e->getMessage());
    }
}

// Get all boxes for map and management
$boxes = $pdo->query("SELECT * FROM boxes ORDER BY created_at DESC")->fetchAll();

// Get statistics
$totalBoxes = count($boxes);
$totalCores = $pdo->query("SELECT COUNT(*) as total FROM cores")->fetch()['total'] ?: 0;
$connectedCores = $pdo->query("SELECT COUNT(*) as total FROM cores WHERE connection_status != 'available'")->fetch()['total'] ?: 0;
$availableCores = $pdo->query("SELECT COUNT(*) as total FROM cores WHERE connection_status = 'available'")->fetch()['total'] ?: 0;

// Get splitter information from splitter_cores table
$splitterInfo = $pdo->query("
    SELECT 
        sc.id,
        sc.core_id,
        sc.splitter_core_number as port_number,
        sc.customer_name,
        sc.power,
        sc.status,
        sc.comment,
        sc.connected_at,
        sc.updated_at,
        c.core_number,
        c.color,
        b.box_name,
        b.id as box_id
    FROM splitter_cores sc
    LEFT JOIN cores c ON sc.core_id = c.id
    LEFT JOIN boxes b ON c.box_id = b.id
    ORDER BY sc.core_id, sc.splitter_core_number
")->fetchAll();

// Group splitter info by core for display
$splitterByCore = [];
foreach ($splitterInfo as $splitter) {
    $core_id = $splitter['core_id'];
    if (!isset($splitterByCore[$core_id])) {
        $splitterByCore[$core_id] = [
            'box_name' => $splitter['box_name'],
            'box_id' => $splitter['box_id'],
            'core_number' => $splitter['core_number'],
            'color' => $splitter['color'],
            'ports' => []
        ];
    }
    $splitterByCore[$core_id]['ports'][] = $splitter;
}

// Calculate statistics for each splitter core
foreach ($splitterByCore as $core_id => &$coreInfo) {
    $totalPorts = count($coreInfo['ports']);
    $usedPorts = 0;
    $availablePorts = 0;
    
    foreach ($coreInfo['ports'] as $port) {
        if ($port['status'] === 'connected' || !empty($port['customer_name'])) {
            $usedPorts++;
        } else {
            $availablePorts++;
        }
    }
    
    $coreInfo['total_ports'] = $totalPorts;
    $coreInfo['used_ports'] = $usedPorts;
    $coreInfo['available_ports'] = $availablePorts;
}

// Handle search functionality
$searchTerm = '';
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = $_GET['search'];
    $searchQuery = "%$searchTerm%";
    $boxes = $pdo->prepare("SELECT * FROM boxes WHERE box_name LIKE ? OR address LIKE ? ORDER BY created_at DESC");
    $boxes->execute([$searchQuery, $searchQuery]);
    $boxes = $boxes->fetchAll();
}

// Calculate average coordinates for map centering
$avgLat = -1.2921; // Default Nairobi coordinates
$avgLng = 36.8219;
$hasValidLocations = false;

if ($boxes) {
    $validLocations = array_filter($boxes, function($box) {
        return !empty($box['location_lat']) && !empty($box['location_lng']);
    });
    
    if (count($validLocations) > 0) {
        $totalLat = 0;
        $totalLng = 0;
        $count = 0;
        
        foreach ($validLocations as $box) {
            $totalLat += floatval($box['location_lat']);
            $totalLng += floatval($box['location_lng']);
            $count++;
        }
        
        $avgLat = $totalLat / $count;
        $avgLng = $totalLng / $count;
        $hasValidLocations = true;
    }
}

// Set the number of boxes and splitters to show initially
$initialBoxesToShow = 6;
$initialSplittersToShow = 4;
$totalBoxesCount = count($boxes);
$totalSplittersCount = count($splitterByCore);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Milan Network Management</title>
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
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
            display: flex; 
            flex-direction: column;
            min-height: calc(100vh - 60px); 
        }
        
        .sidebar { 
            width: 100%; 
            background: white; 
            padding: 20px; 
            border-bottom: 1px solid #dee2e6;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .main-content { 
            flex: 1; 
            padding: 20px; 
            display: flex;
            flex-direction: column;
        }
        
        .form-group { 
            margin-bottom: 15px; 
        }
        
        label { 
            display: block; 
            margin-bottom: 5px; 
            font-weight: 600; 
            color: #495057; 
        }
        
        input, select, textarea { 
            width: 100%; 
            padding: 10px; 
            border: 2px solid #e9ecef; 
            border-radius: 6px; 
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        input:focus, select:focus, textarea:focus {
            border-color: #007bff;
            outline: none;
            box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
        }
        
        button { 
            padding: 12px 25px; 
            background: #007bff; 
            color: white; 
            border: none; 
            border-radius: 6px; 
            cursor: pointer; 
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        button:hover { 
            background: #0056b3; 
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,123,255,0.3);
        }
        
        .core-entry { 
            background: #f8f9fa; 
            padding: 15px; 
            margin: 10px 0; 
            border-radius: 6px; 
            border-left: 4px solid #007bff;
            transition: all 0.3s;
        }
        
        .core-entry:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }
        
        #map { 
            height: 400px; 
            width: 100%; 
            border: 2px solid #dee2e6; 
            border-radius: 8px; 
            margin-top: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .logout-btn { 
            background: #dc3545; 
            padding: 8px 15px;
            border-radius: 4px;
            text-decoration: none;
            color: white;
            font-size: 14px;
            transition: background 0.3s;
            margin-left: 10px;
        }
        
        .logout-btn:hover { 
            background: #c82333;
            transform: none;
            box-shadow: none;
        }

         .customer-btn { 
            background: #94c217ff; 
            padding: 8px 15px;
            border-radius: 4px;
            text-decoration: none;
            color: white;
            font-size: 14px;
            transition: background 0.3s;
            margin-left: 10px;
        }

        .customer-btn:hover { 
            background: #c28317ff;
            transform: none;
            box-shadow: none;
        }
        
        .location-picker { 
            background: #e7f3ff; 
            padding: 15px; 
            border-radius: 6px; 
            margin-bottom: 15px; 
            border: 2px dashed #007bff;
        }
        
        .power-error { 
            color: #dc3545; 
            font-size: 12px; 
            margin-top: 5px; 
            display: none;
            font-weight: 600;
        }
        
        .map-instruction { 
            background: #d4edda; 
            color: #155724; 
            padding: 10px; 
            border-radius: 4px; 
            margin-bottom: 10px; 
            font-size: 14px;
            text-align: center;
        }

        .stats-box {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
        }
        
        .stat-item {
            text-align: center;
            padding: 15px;
            border-radius: 6px;
            background: #f8f9fa;
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: bold;
            display: block;
        }
        
        .stat-boxes { color: #007bff; }
        .stat-cores { color: #28a745; }
        .stat-connected { color: #ffc107; }
        .stat-available { color: #17a2b8; }
        
        .stat-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            font-weight: 600;
        }
        
        #submitBtn:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        #submitBtn:disabled:hover {
            background: #6c757d;
            transform: none;
        }
        
        .management-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .boxes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .box-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid #007bff;
            transition: all 0.3s;
        }
        
        .box-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .box-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .box-name {
            font-weight: bold;
            color: #007bff;
            font-size: 16px;
        }
        
        .box-cores {
            background: #6c757d;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
        }
        
        .box-actions {
            display: flex;
            gap: 8px;
            margin-top: 10px;
        }
        
        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            text-align: center;
            flex: 1;
        }
        
        .btn-primary {
            background: #007bff;
            color: white;
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        
        .content-row {
            display: flex;
            gap: 20px;
            flex: 1;
        }
        
        .left-column {
            flex: 2;
        }
        
        .right-column {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        .section-title {
            color: #495057;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e9ecef;
        }
        
        .no-boxes {
            text-align: center;
            padding: 40px;
            color: #6c757d;
            background: #f8f9fa;
            border-radius: 6px;
            margin-top: 15px;
        }
        
        .connection-fields {
            background: #e7f3ff;
            padding: 12px;
            border-radius: 6px;
            margin-top: 10px;
            border: 1px solid #b8daff;
        }
        
        .connection-type {
            background: #fff3cd;
            padding: 8px;
            border-radius: 4px;
            margin-bottom: 10px;
            border: 1px solid #ffeaa7;
        }
        
        .splitter-info {
            background: #d1ecf1;
            padding: 8px;
            border-radius: 4px;
            margin-top: 5px;
            font-size: 12px;
            color: #0c5460;
        }
        
        .customer-info {
            background: #d4edda;
            padding: 8px;
            border-radius: 4px;
            margin-top: 5px;
            font-size: 12px;
            color: #155724;
        }
        
        .splitter-cores-section {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 6px;
            margin-top: 10px;
            border: 1px solid #dee2e6;
        }
        
        .splitter-core-entry {
            background: white;
            padding: 10px;
            margin: 8px 0;
            border-radius: 4px;
            border-left: 3px solid #17a2b8;
        }
        
        .splitter-core-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .splitter-core-number {
            font-weight: bold;
            color: #17a2b8;
            font-size: 14px;
        }
        
        .cores-counter {
            background: #17a2b8;
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 11px;
            margin-left: 5px;
        }

        .leaflet-popup-content {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 300px;
        }

        .leaflet-popup-content h4 {
            margin: 0 0 10px 0;
            color: #007bff;
        }

        .map-success {
            background: #d4edda;
            color: #155724;
            padding: 10px;
            border-radius: 4px;
            text-align: center;
            margin: 10px 0;
        }

        .gps-search-container {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }

        .gps-btn {
            padding: 10px 15px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .gps-btn:hover {
            background: #218838;
            transform: translateY(-1px);
        }

        .gps-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
        }

        .gps-loading {
            background: #ffc107 !important;
        }

        .address-input-group {
            display: flex;
            gap: 10px;
        }

        .address-input {
            flex: 1;
        }

        .gps-status {
            font-size: 12px;
            color: #6c757d;
            margin-top: 5px;
            text-align: center;
        }

        .gps-success {
            color: #28a745;
            font-weight: 600;
        }

        .gps-error {
            color: #dc3545;
            font-weight: 600;
        }
        
        .search-container {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .search-input {
            flex: 1;
        }
        
        .search-btn {
            padding: 10px 20px;
            background: #17a2b8;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
        }
        
        .search-btn:hover {
            background: #138496;
        }
        
        .clear-search {
            background: #6c757d;
        }
        
        .clear-search:hover {
            background: #5a6268;
        }
        
        .available-cores-info {
            background: #e7f3ff;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 15px;
            border-left: 4px solid #17a2b8;
        }
        
        .core-config-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .core-number {
            font-weight: bold;
            color: #495057;
            font-size: 16px;
        }
        
        .splitter-availability {
            background: #fff3cd;
            padding: 8px;
            border-radius: 4px;
            margin: 5px 0;
            font-size: 12px;
            border-left: 3px solid #ffc107;
        }
        
        .splitter-availability.available {
            background: #d4edda;
            border-left-color: #28a745;
        }
        
        .splitter-availability.full {
            background: #f8d7da;
            border-left-color: #dc3545;
        }
        
        #map-container {
            margin-top: 20px;
            order: 3;
        }

        .splitter-port-info {
            background: #e7f3ff;
            padding: 8px;
            border-radius: 4px;
            margin: 5px 0;
            font-size: 12px;
        }

        .port-details {
            font-size: 11px;
            color: #6c757d;
            margin-top: 5px;
        }

        .port-customer {
            font-weight: bold;
            color: #28a745;
        }

        .port-power {
            color: #007bff;
        }

        .port-status-connected {
            color: #28a745;
            font-weight: bold;
        }

        .port-status-available {
            color: #6c757d;
        }

        /* New styles for collapsed/expanded details */
        .box-summary {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 8px;
            line-height: 1.4;
        }

        .box-details {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
            margin-top: 10px;
        }

        .box-details.expanded {
            max-height: 500px;
        }

        .toggle-details {
            background: none;
            border: none;
            color: #007bff;
            cursor: pointer;
            font-size: 11px;
            padding: 2px 5px;
            text-decoration: underline;
            margin-top: 5px;
        }

        .toggle-details:hover {
            color: #0056b3;
        }

        .details-content {
            background: white;
            padding: 10px;
            border-radius: 4px;
            margin-top: 8px;
            border: 1px solid #dee2e6;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
            font-size: 11px;
            border-bottom: 1px solid #f8f9fa;
        }

        .detail-item:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-weight: 600;
            color: #495057;
        }

        .detail-value {
            color: #6c757d;
        }

        .port-list {
            max-height: 150px;
            overflow-y: auto;
            margin-top: 5px;
        }

        .port-item {
            display: flex;
            justify-content: space-between;
            padding: 2px 0;
            font-size: 10px;
            border-bottom: 1px solid #f8f9fa;
        }

        .port-item:last-child {
            border-bottom: none;
        }

        /* Map positioning styles */
        .map-position-container {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }

        .map-section {
            flex: 2;
        }

        .boxes-list-section {
            flex: 1;
            max-height: 400px;
            overflow-y: auto;
        }

        .boxes-list {
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .boxes-list-title {
            font-size: 16px;
            font-weight: 600;
            color: #495057;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e9ecef;
        }

        .box-list-item {
            padding: 10px;
            border-bottom: 1px solid #f8f9fa;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .box-list-item:hover {
            background: #f8f9fa;
        }

        .box-list-item:last-child {
            border-bottom: none;
        }

        .box-list-name {
            font-weight: 600;
            color: #007bff;
            font-size: 14px;
        }

        .box-list-location {
            font-size: 11px;
            color: #6c757d;
            margin-top: 2px;
        }

        .box-list-cores {
            font-size: 11px;
            color: #28a745;
            margin-top: 2px;
        }

        .active-box {
            background: #e7f3ff;
            border-left: 3px solid #007bff;
        }

        /* Show More/Less styles */
        .show-more-container {
            text-align: center;
            margin-top: 20px;
            padding: 15px;
        }

        .show-more-btn {
            background: #007bff;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .show-more-btn:hover {
            background: #0056b3;
            transform: translateY(-2px);
        }

        .show-more-btn.splitter {
            background: #17a2b8;
        }

        .show-more-btn.splitter:hover {
            background: #138496;
        }

        .items-count {
            font-size: 12px;
            color: #6c757d;
            margin-top: 8px;
        }

        .box-card.hidden {
            display: none;
        }

        .box-card.visible {
            display: block;
            animation: fadeIn 0.5s ease-in;
        }

        .splitter-card.hidden {
            display: none;
        }

        .splitter-card.visible {
            display: block;
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 768px) {
            .map-position-container {
                flex-direction: column;
            }
            
            .map-section, .boxes-list-section {
                flex: none;
            }
            
            .boxes-list-section {
                max-height: 200px;
            }
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .container {
                max-width: 100%;
                padding: 15px;
            }
            
            .form-container {
                max-width: 100%;
                padding: 20px;
            }
            
            .boxes-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 15px;
            }
        }

        @media (max-width: 992px) {
            .header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
                padding: 20px 15px;
            }
            
            .header div {
                flex-direction: column;
                gap: 10px;
                width: 100%;
            }
            
            .header a {
                width: 100%;
                text-align: center;
                padding: 12px;
            }
            
            .form-container {
                padding: 15px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .boxes-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 12px;
            }
            
            .box-card {
                padding: 15px;
            }
            
            .box-title {
                font-size: 16px;
            }
            
            .box-info {
                font-size: 12px;
            }
            
            .cores-list {
                font-size: 11px;
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .header {
                padding: 15px 10px;
            }
            
            .header h1 {
                font-size: 20px;
            }
            
            .form-container {
                padding: 12px;
            }
            
            .form-title {
                font-size: 18px;
            }
            
            .form-group {
                margin-bottom: 15px;
            }
            
            .form-group label {
                font-size: 14px;
            }
            
            .form-control {
                padding: 10px;
                font-size: 14px;
            }
            
            .btn {
                padding: 10px 15px;
                font-size: 14px;
            }
            
            .boxes-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .box-card {
                padding: 12px;
            }
            
            .box-title {
                font-size: 15px;
            }
            
            .box-info {
                font-size: 11px;
            }
            
            .cores-list {
                font-size: 10px;
            }
            
            .section-title {
                font-size: 18px;
            }
            
            .search-box {
                max-width: 100%;
            }
            
            .search-box input {
                font-size: 14px;
                padding: 10px;
            }
            
            .filter-buttons {
                flex-direction: column;
                gap: 8px;
            }
            
            .filter-btn {
                width: 100%;
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 5px;
            }
            
            .header {
                padding: 10px 5px;
            }
            
            .header h1 {
                font-size: 18px;
            }
            
            .header span {
                font-size: 14px;
            }
            
            .form-container {
                padding: 10px;
            }
            
            .form-title {
                font-size: 16px;
            }
            
            .form-group {
                margin-bottom: 12px;
            }
            
            .form-group label {
                font-size: 13px;
            }
            
            .form-control {
                padding: 8px;
                font-size: 13px;
            }
            
            .btn {
                padding: 8px 12px;
                font-size: 13px;
            }
            
            .box-card {
                padding: 10px;
            }
            
            .box-title {
                font-size: 14px;
            }
            
            .box-info {
                font-size: 10px;
            }
            
            .cores-list {
                font-size: 9px;
            }
            
            .section-title {
                font-size: 16px;
            }
            
            .search-box input {
                font-size: 13px;
                padding: 8px;
            }
            
            .filter-btn {
                padding: 8px 12px;
                font-size: 12px;
            }
            
            .alert {
                padding: 10px;
                font-size: 13px;
            }
            
            .box-actions {
                flex-direction: column;
                gap: 5px;
            }
            
            .box-actions .btn {
                width: 100%;
                text-align: center;
            }
        }

        @media (max-width: 320px) {
            .header h1 {
                font-size: 16px;
            }
            
            .form-title {
                font-size: 15px;
            }
            
            .box-title {
                font-size: 13px;
            }
            
            .section-title {
                font-size: 15px;
            }
            
            .form-control {
                padding: 6px;
                font-size: 12px;
            }
            
            .btn {
                padding: 6px 10px;
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Milan Network Management System</h1>
        <div>
            <span>Welcome, <strong><?php echo $_SESSION['username']; ?></strong></span>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </div>

    <div class="container">
        <!-- Sidebar - Add New Box -->
        <div class="sidebar">
            <h3>Add New Fiber Box</h3>
            <form method="POST" id="boxForm">
                <div class="form-group">
                    <label>Box Name:</label>
                    <input type="text" name="box_name" id="box_name" placeholder="e.g., Junction-B" required>
                </div>
                
                <div class="form-group">
                    <label>Total Cores:</label>
                    <input type="number" id="totalCores" name="total_cores" min="1" max="144" 
                           placeholder="Enter number of fiber cores" required onchange="generateCoreForm()">
                </div>

                <div id="coreFormContainer"></div>

                <div class="form-group">
                    <label>Address:</label>
                    <div class="address-input-group">
                        <input type="text" id="address" name="address" class="address-input" 
                               placeholder="Click 'Use My Location' or click on map" required>
                        <button type="button" id="gpsBtn" class="gps-btn">Use My Location</button>
                    </div>
                    <div id="gpsStatus" class="gps-status"></div>
                </div>

                <div class="location-picker">
                    <div class="map-instruction">
                         <strong>Click on the map to select location or use GPS button above</strong>
                    </div>
                    <div class="form-group">
                        <label>Selected Location:</label>
                        <input type="text" id="locationDisplay" readonly placeholder="Location will appear here">
                        <input type="hidden" id="location_lat" name="location_lat" required>
                        <input type="hidden" id="location_lng" name="location_lng" required>
                    </div>
                </div>

                <button type="submit" id="submitBtn">Save Box</button>
            </form>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Statistics -->
            <div class="stats-box">
                <div class="stat-item">
                    <span class="stat-number stat-boxes"><?php echo $totalBoxes; ?></span>
                    <span class="stat-label">Total Boxes</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number stat-cores"><?php echo $totalCores; ?></span>
                    <span class="stat-label">Total Cores</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number stat-connected"><?php echo $connectedCores; ?></span>
                    <span class="stat-label">Connected Cores</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number stat-available"><?php echo $availableCores; ?></span>
                    <span class="stat-label">Available Cores</span>
                </div>
            </div>

            <!-- Map and Boxes List Section -->
            <div class="map-position-container">
                <!-- Map Section -->
                <div class="map-section">
                    <div class="management-section">
                        <h3 class="section-title">Infrastructure Map</h3>
                        <div class="map-success">
                            Map centered near your boxes - Click on markers for details
                        </div>
                        <div id="map"></div>
                    </div>
                </div>

                <!-- Boxes List Section -->
                <div class="boxes-list-section">
                    <div class="boxes-list">
                        <div class="boxes-list-title">Nearby Boxes</div>
                        <?php if ($boxes): ?>
                            <?php foreach($boxes as $index => $box): ?>
                                <div class="box-list-item <?php echo $index === 0 ? 'active-box' : ''; ?>" 
                                     data-lat="<?php echo $box['location_lat'] ?? ''; ?>" 
                                     data-lng="<?php echo $box['location_lng'] ?? ''; ?>"
                                     onclick="focusOnBox(<?php echo $box['id']; ?>, <?php echo $box['location_lat'] ?? 'null'; ?>, <?php echo $box['location_lng'] ?? 'null'; ?>, this)">
                                    <div class="box-list-name"><?php echo htmlspecialchars($box['box_name']); ?></div>
                                    <div class="box-list-location">
                                        <?php 
                                        $address = $box['address'] ?? 'No address';
                                        echo htmlspecialchars(strlen($address) > 30 ? substr($address, 0, 30) . '...' : $address);
                                        ?>
                                    </div>
                                    <div class="box-list-cores"><?php echo $box['total_cores']; ?> cores</div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 20px; color: #6c757d;">
                                No boxes found
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="content-row">
                <!-- Left Column: Box Management -->
                <div class="left-column">
                    <!-- Box Management -->
                    <div class="management-section">
                        <h3 class="section-title">Fiber Box Management</h3>
                        
                        <!-- Search Box -->
                        <div class="search-container">
                            <form method="GET" action="dashboard.php" style="display: flex; width: 100%; gap: 10px;">
                                <input type="text" name="search" class="search-input" placeholder="Search boxes by name or address..." value="<?php echo htmlspecialchars($searchTerm); ?>">
                                <button type="submit" class="search-btn">Search</button>
                                <?php if (!empty($searchTerm)): ?>
                                    <a href="dashboard.php" class="btn clear-search" style="padding: 10px 20px;">Clear</a>
                                <?php endif; ?>
                            </form>
                        </div>
                        
                        <?php if ($boxes): ?>
                            <div class="boxes-grid" id="boxesGrid">
                                <?php foreach($boxes as $index => $box): ?>
                                    <?php 
                                    // Get core statistics for this box
                                    $coreStats = $pdo->prepare("
                                        SELECT 
                                            COUNT(*) as total,
                                            SUM(CASE WHEN connection_status != 'available' THEN 1 ELSE 0 END) as connected,
                                            SUM(CASE WHEN connection_status = 'available' THEN 1 ELSE 0 END) as available
                                        FROM cores 
                                        WHERE box_id = ?
                                    ");
                                    $coreStats->execute([$box['id']]);
                                    $stats = $coreStats->fetch();
                                    ?>
                                    <div class="box-card <?php echo $index < $initialBoxesToShow ? 'visible' : 'hidden'; ?>" 
                                         data-index="<?php echo $index; ?>">
                                        <div class="box-header">
                                            <span class="box-name"><?php echo htmlspecialchars($box['box_name']); ?></span>
                                            <span class="box-cores"><?php echo $box['total_cores']; ?> cores</span>
                                        </div>
                                        
                                        <div class="box-summary">
                                            <strong>Location:</strong> <?php echo htmlspecialchars(substr($box['address'] ?? 'No address', 0, 30) . '...'); ?><br>
                                            <strong>Status:</strong> <?php echo $stats['connected']; ?> connected, <?php echo $stats['available']; ?> available
                                        </div>

                                        <div class="box-details" id="details-<?php echo $box['id']; ?>">
                                            <div class="details-content">
                                                <div class="detail-item">
                                                    <span class="detail-label">Created:</span>
                                                    <span class="detail-value"><?php echo date('M j, Y', strtotime($box['created_at'])); ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <span class="detail-label">Coordinates:</span>
                                                    <span class="detail-value">
                                                        <?php echo $box['location_lat'] ?? 'N/A'; ?>, <?php echo $box['location_lng'] ?? 'N/A'; ?>
                                                    </span>
                                                </div>
                                                <div class="detail-item">
                                                    <span class="detail-label">Full Address:</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($box['address'] ?? 'Not specified'); ?></span>
                                                </div>
                                            </div>
                                        </div>

                                        <button class="toggle-details" onclick="toggleDetails(<?php echo $box['id']; ?>)">
                                            Show Details
                                        </button>
                                        
                                        <div class="box-actions">
                                            <a href="box_details.php?box_id=<?php echo $box['id']; ?>" class="btn btn-info">
                                                box details
                                            </a>
        
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Show More/Less Button for Boxes -->
                            <?php if ($totalBoxesCount > $initialBoxesToShow): ?>
                                <div class="show-more-container">
                                    <button class="show-more-btn" onclick="toggleShowMoreBoxes()" id="showMoreBoxesBtn">
                                        Show More Boxes (<?php echo $totalBoxesCount - $initialBoxesToShow; ?> more)
                                    </button>
                                    <div class="items-count" id="boxesCount">
                                        Showing <?php echo min($initialBoxesToShow, $totalBoxesCount); ?> of <?php echo $totalBoxesCount; ?> boxes
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="items-count" style="text-align: center; margin-top: 15px; color: #6c757d;">
                                    Showing all <?php echo $totalBoxesCount; ?> boxes
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="no-boxes">
                                <?php if (!empty($searchTerm)): ?>
                                    <h4>No Boxes Found</h4>
                                    <p>No boxes match your search for "<?php echo htmlspecialchars($searchTerm); ?>".</p>
                                    <a href="dashboard.php" class="btn btn-primary" style="margin-top: 10px;">View All Boxes</a>
                                <?php else: ?>
                                    <h4>No Fiber Boxes Added Yet</h4>
                                    <p>Start by adding your first fiber box using the form above.</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Right Column: Splitter Info -->
                <div class="right-column">
                    <!-- Splitter Management -->
                    <div class="management-section">
                        <h3 class="section-title">Splitter Connections</h3>
                        
                        <?php if ($splitterByCore): ?>
                            <div class="boxes-grid" id="splittersGrid">
                                <?php $splitterIndex = 0; ?>
                                <?php foreach($splitterByCore as $core_id => $splitter): ?>
                                    <?php 
                                    $availablePorts = $splitter['available_ports'] ?? 0;
                                    $usedPorts = $splitter['used_ports'] ?? 0;
                                    $totalPorts = $splitter['total_ports'] ?? 0;
                                    
                                    // Determine availability status
                                    $availabilityClass = 'available';
                                    $availabilityText = "$availablePorts ports available";
                                    if ($availablePorts == 0) {
                                        $availabilityClass = 'full';
                                        $availabilityText = "No ports available";
                                    } elseif ($availablePorts < 3) {
                                        $availabilityClass = 'warning';
                                        $availabilityText = "Only $availablePorts ports left";
                                    }
                                    ?>
                                    <div class="box-card splitter-card <?php echo $splitterIndex < $initialSplittersToShow ? 'visible' : 'hidden'; ?>" 
                                         data-index="<?php echo $splitterIndex; ?>">
                                        <div class="box-header">
                                            <span class="box-name">Core <?php echo $splitter['core_number']; ?> (<?php echo $splitter['color']; ?>)</span>
                                            <span class="box-cores"><?php echo $totalPorts; ?> ports</span>
                                        </div>
                                        
                                        <div class="box-summary">
                                            <strong>Box:</strong> <?php echo htmlspecialchars($splitter['box_name']); ?><br>
                                            <strong>Status:</strong> <?php echo $usedPorts; ?> used, <?php echo $availablePorts; ?> available
                                        </div>

                                        <div class="splitter-availability <?php echo $availabilityClass; ?>">
                                            <strong>Availability:</strong> <?php echo $availabilityText; ?>
                                        </div>

                                        <div class="box-details" id="splitter-details-<?php echo $core_id; ?>">
                                            <div class="details-content">
                                                <div class="port-list">
                                                    <?php foreach($splitter['ports'] as $port): ?>
                                                        <div class="port-item">
                                                            <span>Port <?php echo $port['port_number']; ?>:</span>
                                                            <span>
                                                                <?php if (!empty($port['customer_name'])): ?>
                                                                    <span style="color: #28a745;"><?php echo htmlspecialchars($port['customer_name']); ?></span>
                                                                <?php else: ?>
                                                                    <span style="color: #6c757d;">Available</span>
                                                                <?php endif; ?>
                                                            </span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <button class="toggle-details" onclick="toggleSplitterDetails(<?php echo $core_id; ?>)">
                                            Show Ports
                                        </button>
                                        
                                        <div class="box-actions">
                                            <a href="splitter_details.php?core_id=<?php echo $core_id; ?>" class="btn btn-info">
                                                Manage
                                            </a>
                                        </div>
                                    </div>
                                    <?php $splitterIndex++; ?>
                                <?php endforeach; ?>
                            </div>

                            <!-- Show More/Less Button for Splitters -->
                            <?php if ($totalSplittersCount > $initialSplittersToShow): ?>
                                <div class="show-more-container">
                                    <button class="show-more-btn splitter" onclick="toggleShowMoreSplitters()" id="showMoreSplittersBtn">
                                        Show More Splitters (<?php echo $totalSplittersCount - $initialSplittersToShow; ?> more)
                                    </button>
                                    <div class="items-count" id="splittersCount">
                                        Showing <?php echo min($initialSplittersToShow, $totalSplittersCount); ?> of <?php echo $totalSplittersCount; ?> splitters
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="items-count" style="text-align: center; margin-top: 15px; color: #6c757d;">
                                    Showing all <?php echo $totalSplittersCount; ?> splitters
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="no-boxes">
                                <h4>No Splitter Connections</h4>
                                <p>No splitter connections have been configured yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Leaflet JavaScript -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <script>
        // Standard fiber colors
        const fiberColors = [
            'Blue', 'Orange', 'Green', 'Brown', 'Slate', 'White',
            'Red', 'Black', 'Yellow', 'Violet', 'Rose', 'Aqua'
        ];

        let map;
        let marker;
        let userLocationMarker;
        let boxMarkers = [];
        let isShowingAllBoxes = false;
        let isShowingAllSplitters = false;
        const initialBoxesToShow = <?php echo $initialBoxesToShow; ?>;
        const initialSplittersToShow = <?php echo $initialSplittersToShow; ?>;
        const totalBoxesCount = <?php echo $totalBoxesCount; ?>;
        const totalSplittersCount = <?php echo $totalSplittersCount; ?>;

        // Show More/Less functionality for Boxes
        function toggleShowMoreBoxes() {
            const boxesGrid = document.getElementById('boxesGrid');
            const boxCards = boxesGrid.querySelectorAll('.box-card');
            const showMoreBtn = document.getElementById('showMoreBoxesBtn');
            const boxesCount = document.getElementById('boxesCount');

            if (!isShowingAllBoxes) {
                // Show all boxes
                boxCards.forEach(card => {
                    card.classList.remove('hidden');
                    card.classList.add('visible');
                });
                showMoreBtn.textContent = 'Show Less';
                boxesCount.textContent = `Showing all ${totalBoxesCount} boxes`;
                isShowingAllBoxes = true;
            } else {
                // Show only initial boxes
                boxCards.forEach((card, index) => {
                    if (index >= initialBoxesToShow) {
                        card.classList.remove('visible');
                        card.classList.add('hidden');
                    } else {
                        card.classList.remove('hidden');
                        card.classList.add('visible');
                    }
                });
                showMoreBtn.textContent = `Show More Boxes (${totalBoxesCount - initialBoxesToShow} more)`;
                boxesCount.textContent = `Showing ${initialBoxesToShow} of ${totalBoxesCount} boxes`;
                isShowingAllBoxes = false;
            }
        }

        // Show More/Less functionality for Splitters
        function toggleShowMoreSplitters() {
            const splittersGrid = document.getElementById('splittersGrid');
            const splitterCards = splittersGrid.querySelectorAll('.splitter-card');
            const showMoreBtn = document.getElementById('showMoreSplittersBtn');
            const splittersCount = document.getElementById('splittersCount');

            if (!isShowingAllSplitters) {
                // Show all splitters
                splitterCards.forEach(card => {
                    card.classList.remove('hidden');
                    card.classList.add('visible');
                });
                showMoreBtn.textContent = 'Show Less';
                splittersCount.textContent = `Showing all ${totalSplittersCount} splitters`;
                isShowingAllSplitters = true;
            } else {
                // Show only initial splitters
                splitterCards.forEach((card, index) => {
                    if (index >= initialSplittersToShow) {
                        card.classList.remove('visible');
                        card.classList.add('hidden');
                    } else {
                        card.classList.remove('hidden');
                        card.classList.add('visible');
                    }
                });
                showMoreBtn.textContent = `Show More Splitters (${totalSplittersCount - initialSplittersToShow} more)`;
                splittersCount.textContent = `Showing ${initialSplittersToShow} of ${totalSplittersCount} splitters`;
                isShowingAllSplitters = false;
            }
        }

        // Initialize OpenStreetMap centered near boxes
        function initMap() {
            console.log("Initializing OpenStreetMap near boxes...");
            
            // Use calculated average coordinates or default to Nairobi
            const centerLat = <?php echo $avgLat; ?>;
            const centerLng = <?php echo $avgLng; ?>;
            const hasBoxes = <?php echo $hasValidLocations ? 'true' : 'false'; ?>;
            
            // Create map centered on average box location or default
            const zoomLevel = hasBoxes ? 12 : 10;
            map = L.map('map').setView([centerLat, centerLng], zoomLevel);

            // Add OpenStreetMap tiles
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                maxZoom: 18
            }).addTo(map);

            // Clear existing markers
            boxMarkers.forEach(marker => map.removeLayer(marker));
            boxMarkers = [];

            // Add existing boxes to map with improved popups
            <?php foreach($boxes as $box): ?>
                <?php if ($box['location_lat'] && $box['location_lng']): ?>
                    const marker<?php echo $box['id']; ?> = L.marker([<?php echo $box['location_lat']; ?>, <?php echo $box['location_lng']; ?>])
                        .addTo(map)
                        .bindPopup(`
                            <div style="padding: 10px; min-width: 250px; max-width: 300px;">
                                <h4 style="margin: 0 0 8px 0; color: #007bff; font-size: 16px;"><?php echo addslashes($box['box_name']); ?></h4>
                                <div style="margin-bottom: 8px;">
                                    <strong>Cores:</strong> <?php echo $box['total_cores']; ?> total<br>
                                    <strong>Address:</strong> <?php echo addslashes(substr($box['address'], 0, 50) . (strlen($box['address']) > 50 ? '...' : '')); ?>
                                </div>
                                <div style="font-size: 12px; color: #666; margin-bottom: 10px;">
                                    <strong>Position:</strong> <?php echo $box['location_lat']; ?>, <?php echo $box['location_lng']; ?>
                                </div>
                                <div style="display: flex; gap: 5px;">
                                    <a href="box_details.php?box_id=<?php echo $box['id']; ?>" 
                                       style="background: #007bff; color: white; padding: 5px 10px; text-decoration: none; border-radius: 3px; font-size: 12px; flex: 1; text-align: center;">
                                        Details
                            </div>
                        `);
                    
                    boxMarkers.push(marker<?php echo $box['id']; ?>);
                    
                    // Add click event to focus on box
                    marker<?php echo $box['id']; ?>.on('click', function() {
                        focusOnBox(<?php echo $box['id']; ?>, <?php echo $box['location_lat']; ?>, <?php echo $box['location_lng']; ?>);
                    });
                <?php endif; ?>
            <?php endforeach; ?>

            // Add click listener for new marker
            map.on('click', function(e) {
                setLocationMarker(e.latlng);
            });

            console.log("OpenStreetMap initialized successfully near boxes!");
        }

        function focusOnBox(boxId, lat, lng, listItem = null) {
            if (!lat || !lng) {
                alert('This box does not have location data.');
                return;
            }

            // Center map on the box
            map.setView([lat, lng], 15);

            // Highlight in boxes list
            if (listItem) {
                document.querySelectorAll('.box-list-item').forEach(item => {
                    item.classList.remove('active-box');
                });
                listItem.classList.add('active-box');
            }

            // Open popup if marker exists
            const marker = boxMarkers.find(m => {
                const markerLatLng = m.getLatLng();
                return markerLatLng.lat === lat && markerLatLng.lng === lng;
            });
            
            if (marker) {
                marker.openPopup();
            }
        }

        function setLocationMarker(latlng) {
            // Remove existing marker
            if (marker) {
                map.removeLayer(marker);
            }
            
            // Add new marker
            marker = L.marker(latlng, { 
                draggable: true,
                title: 'Selected Location'
            }).addTo(map);
            
            // Update form fields
            updateLocationFields(latlng.lat, latlng.lng);

            // Make marker draggable
            marker.on('dragend', function(e) {
                const newLatLng = e.target.getLatLng();
                updateLocationFields(newLatLng.lat, newLatLng.lng);
            });
        }

        function updateLocationFields(lat, lng) {
            document.getElementById('location_lat').value = lat;
            document.getElementById('location_lng').value = lng;
            document.getElementById('locationDisplay').value = 
                lat.toFixed(6) + ', ' + lng.toFixed(6);

            // Reverse geocoding using Nominatim (OpenStreetMap)
            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`)
                .then(response => response.json())
                .then(data => {
                    if (data.display_name) {
                        document.getElementById('address').value = data.display_name;
                        updateGPSStatus('Address updated from map click', 'gps-success');
                    } else {
                        document.getElementById('address').value = 'Address not found';
                        updateGPSStatus('Address not found for this location', 'gps-error');
                    }
                })
                .catch(error => {
                    console.error('Geocoding error:', error);
                    document.getElementById('address').value = 'Address lookup failed';
                    updateGPSStatus('Failed to get address', 'gps-error');
                });
        }

        // GPS Location Functions
        function getCurrentLocation() {
            const gpsBtn = document.getElementById('gpsBtn');
            const gpsStatus = document.getElementById('gpsStatus');
            
            if (!navigator.geolocation) {
                updateGPSStatus('Geolocation is not supported by this browser', 'gps-error');
                return;
            }

            // Show loading state
            gpsBtn.disabled = true;
            gpsBtn.classList.add('gps-loading');
            gpsBtn.textContent = 'Getting Location...';
            updateGPSStatus('Getting your current location...', '');

            navigator.geolocation.getCurrentPosition(
                // Success callback
                function(position) {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    
                    // Update form fields
                    updateLocationFields(lat, lng);
                    
                    // Center map on user location
                    map.setView([lat, lng], 16);
                    
                    // Add user location marker
                    if (userLocationMarker) {
                        map.removeLayer(userLocationMarker);
                    }
                    
                    userLocationMarker = L.marker([lat, lng], {
                        icon: L.divIcon({
                            className: 'user-location-marker',
                            html: '📍',
                            iconSize: [30, 30],
                            iconAnchor: [15, 30]
                        })
                    }).addTo(map)
                    .bindPopup('Your Current Location')
                    .openPopup();
                    
                    // Reset button
                    gpsBtn.disabled = false;
                    gpsBtn.classList.remove('gps-loading');
                    gpsBtn.textContent = 'Use My Location';
                    
                    updateGPSStatus('Location found! Address updated automatically', 'gps-success');
                },
                // Error callback
                function(error) {
                    gpsBtn.disabled = false;
                    gpsBtn.classList.remove('gps-loading');
                    gpsBtn.textContent = 'Use My Location';
                    
                    let errorMessage = ' ';
                    switch(error.code) {
                        case error.PERMISSION_DENIED:
                            errorMessage += 'Location access denied. Please allow location access.';
                            break;
                        case error.POSITION_UNAVAILABLE:
                            errorMessage += 'Location information unavailable.';
                            break;
                        case error.TIMEOUT:
                            errorMessage += 'Location request timed out.';
                            break;
                        default:
                            errorMessage += 'An unknown error occurred.';
                            break;
                    }
                    updateGPSStatus(errorMessage, 'gps-error');
                },
                // Options
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 60000
                }
            );
        }

        function updateGPSStatus(message, className) {
            const gpsStatus = document.getElementById('gpsStatus');
            gpsStatus.textContent = message;
            gpsStatus.className = 'gps-status ' + (className || '');
        }

        // Toggle details functions
        function toggleDetails(boxId) {
            const details = document.getElementById('details-' + boxId);
            const button = details.previousElementSibling;
            
            if (details.classList.contains('expanded')) {
                details.classList.remove('expanded');
                button.textContent = 'Show Details';
            } else {
                details.classList.add('expanded');
                button.textContent = 'Hide Details';
            }
        }

        function toggleSplitterDetails(coreId) {
            const details = document.getElementById('splitter-details-' + coreId);
            const button = details.previousElementSibling;
            
            if (details.classList.contains('expanded')) {
                details.classList.remove('expanded');
                button.textContent = 'Show Ports';
            } else {
                details.classList.add('expanded');
                button.textContent = 'Hide Ports';
            }
        }

        function generateCoreForm() {
            const totalCores = parseInt(document.getElementById('totalCores').value);
            const container = document.getElementById('coreFormContainer');
            
            if (totalCores > 0) {
                let html = '<h4 style="margin: 15px 0 10px 0; color: #495057;">Core Configuration:</h4>';
                
                // Show available cores information
                html += `
                    <div class="available-cores-info">
                        <strong>Available Cores:</strong> All ${totalCores} cores will be initially set as available. 
                        You can configure connections for each core below.
                    </div>
                `;
                
                for (let i = 1; i <= totalCores; i++) {
                    const colorIndex = (i - 1) % fiberColors.length;
                    html += `
                        <div class="core-entry">
                            <div class="core-config-header">
                                <span class="core-number">Core ${i}</span>
                            </div>
                            
                            <div class="form-group">
                                <label>Color:</label>
                                <select name="color_${i}" required>
                                    ${fiberColors.map(color => 
                                        `<option value="${color}" ${colorIndex === i-1 ? 'selected' : ''}>${color}</option>`
                                    ).join('')}
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Power Level (dBm):</label>
                                <input type="number" step="0.01" name="power_level_${i}" id="power_level_${i}" 
                                       placeholder="-25.50" required max="25" min="-50"
                                       oninput="validatePowerLevel(${i})">
                                <div class="power-error" id="power_error_${i}">
                                    Power level cannot exceed 25 dBm
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Connection Type:</label>
                                <select name="connection_type_${i}" id="connection_type_${i}" onchange="toggleConnectionFields(${i})">
                                    <option value="available">Available (Not Connected)</option>
                                    <option value="direct">Direct to Customer</option>
                                    <option value="splitter">Through Splitter</option>
                                </select>
                            </div>
                            
                            <div id="connection_fields_${i}" class="connection-fields" style="display: none;">
                                <div class="connection-type">
                                    <strong>Connection Details:</strong>
                                </div>
                                
                                <div id="direct_connection_${i}">
                                    <div class="form-group">
                                        <label>Customer Name:</label>
                                        <input type="text" name="customer_name_${i}" placeholder="Enter customer name">
                                    </div>
                                    <div class="customer-info">
                                        This core is directly connected to a customer
                                    </div>
                                </div>
                                
                                <div id="splitter_connection_${i}">
                                    <div class="form-group">
                                        <label>Number of Splitter Ports:</label>
                                        <input type="number" name="splitter_cores_count_${i}" id="splitter_cores_count_${i}" 
                                               min="1" max="50" placeholder="e.g., 8, 16, 32 , 50" 
                                               onchange="generateSplitterCoresForm(${i})">
                                    </div>
                                    
                                    <div id="splitter_cores_container_${i}" class="splitter-cores-section" style="display: none;">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                            <strong>Splitter Port Configuration:</strong>
                                            <span id="splitter_cores_counter_${i}" class="cores-counter">0 ports</span>
                                        </div>
                                        <div id="splitter_cores_form_${i}">
                                            <!-- Splitter cores will be generated here -->
                                        </div>
                                    </div>
                                    
                                    <div class="splitter-info">
                                        This core connects to a splitter which then serves multiple customers
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                }
                
                container.innerHTML = html;
            } else {
                container.innerHTML = '';
            }
        }

        function toggleConnectionFields(coreNumber) {
            const connectionType = document.getElementById(`connection_type_${coreNumber}`).value;
            const connectionFields = document.getElementById(`connection_fields_${coreNumber}`);
            const directConnection = document.getElementById(`direct_connection_${coreNumber}`);
            const splitterConnection = document.getElementById(`splitter_connection_${coreNumber}`);
            
            if (connectionType === 'available') {
                connectionFields.style.display = 'none';
            } else {
                connectionFields.style.display = 'block';
                
                if (connectionType === 'direct') {
                    directConnection.style.display = 'block';
                    splitterConnection.style.display = 'none';
                } else if (connectionType === 'splitter') {
                    directConnection.style.display = 'none';
                    splitterConnection.style.display = 'block';
                }
            }
        }

        function generateSplitterCoresForm(coreNumber) {
            const coresCount = parseInt(document.getElementById(`splitter_cores_count_${coreNumber}`).value);
            const container = document.getElementById(`splitter_cores_form_${coreNumber}`);
            const section = document.getElementById(`splitter_cores_container_${coreNumber}`);
            const counter = document.getElementById(`splitter_cores_counter_${coreNumber}`);
            
            if (coresCount > 0) {
                section.style.display = 'block';
                counter.textContent = `${coresCount} ports`;
                
                let html = '';
                for (let j = 1; j <= coresCount; j++) {
                    html += `
                        <div class="splitter-core-entry">
                            <div class="splitter-core-header">
                                <span class="splitter-core-number">Splitter Port ${j}</span>
                            </div>
                            <div class="form-group">
                                <label>Customer Name:</label>
                                <input type="text" name="splitter_customer_name_${coreNumber}_${j}" 
                                       placeholder="Enter customer name for this splitter port">
                            </div>
                            <div class="form-group">
                                <label>Comment/Notes:</label>
                                <textarea name="splitter_core_comment_${coreNumber}_${j}" 
                                          placeholder="Add notes about this splitter port connection" 
                                          rows="2"></textarea>
                            </div>
                        </div>
                    `;
                }
                
                container.innerHTML = html;
            } else {
                section.style.display = 'none';
                container.innerHTML = '';
            }
        }

        function validatePowerLevel(coreNumber) {
            const powerInput = document.getElementById(`power_level_${coreNumber}`);
            const errorDiv = document.getElementById(`power_error_${coreNumber}`);
            const submitBtn = document.getElementById('submitBtn');
            
            if (powerInput && parseFloat(powerInput.value) > 25) {
                errorDiv.style.display = 'block';
                powerInput.style.borderColor = '#dc3545';
                submitBtn.disabled = true;
                submitBtn.style.backgroundColor = '#6c757d';
                return false;
            } else {
                if (errorDiv) errorDiv.style.display = 'none';
                if (powerInput) powerInput.style.borderColor = '#e9ecef';
                submitBtn.disabled = false;
                submitBtn.style.backgroundColor = '#007bff';
                return true;
            }
        }

        // Form validation
        document.getElementById('boxForm')?.addEventListener('submit', function(e) {
            const totalCores = parseInt(document.getElementById('totalCores').value);
            let valid = true;

            // Check power levels
            for (let i = 1; i <= totalCores; i++) {
                const powerInput = document.getElementById(`power_level_${i}`);
                if (powerInput && parseFloat(powerInput.value) > 25) {
                    valid = false;
                    validatePowerLevel(i);
                    break;
                }
            }

            // Check location is selected
            const lat = document.getElementById('location_lat').value;
            const lng = document.getElementById('location_lng').value;
            if (!lat || !lng) {
                alert('Please select a location by clicking on the map or using GPS');
                valid = false;
            }

            if (!valid) {
                e.preventDefault();
                alert('Please fix the errors before submitting');
            }
        });

        // Auto-focus on box name
        document.getElementById('box_name')?.focus();

        // Initialize core form if total cores is already set
        document.addEventListener('DOMContentLoaded', function() {
            const totalCores = document.getElementById('totalCores').value;
            if (totalCores > 0) {
                generateCoreForm();
            }
            
            // Initialize the map
            initMap();
            
            // Add event listener for GPS button
            document.getElementById('gpsBtn').addEventListener('click', getCurrentLocation);
        });
    </script>
</body>
</html>