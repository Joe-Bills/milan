<?php
// export_excel.php
session_start();
include 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get box ID from URL
$box_id = $_GET['box_id'] ?? 0;

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

// Get all cores with complete details
$cores_query = $pdo->prepare("
    SELECT 
        c.id,
        c.core_number,
        c.color,
        c.power_level,
        c.connection_status,
        c.is_connected,
        c.connected_to,
        c.connected_to_type,
        c.technician_name,
        c.customer_location,
        c.technician_notes,
        c.connection_date,
        c.customer_image,
        s.splitter_name,
        s.splitter_type,
        GROUP_CONCAT(
            CONCAT(
                'Core ', sc.splitter_core_number, ': ',
                COALESCE(sc.customer_name, 'Available'), 
                ' (', COALESCE(sc.power, 'N/A'), ' dBm)',
                IF(sc.comment IS NOT NULL, CONCAT(' - ', sc.comment), '')
            ) SEPARATOR '; '
        ) as splitter_details
    FROM cores c 
    LEFT JOIN splitters s ON c.connected_to_id = s.id
    LEFT JOIN splitter_cores sc ON c.id = sc.core_id
    WHERE c.box_id = ? 
    GROUP BY c.id, c.core_number, c.color, c.power_level, c.connection_status, 
             c.is_connected, c.connected_to, c.connected_to_type, 
             c.technician_name, c.customer_location, c.technician_notes,
             c.connection_date, c.customer_image, s.splitter_name, s.splitter_type
    ORDER BY c.core_number
");
$cores_query->execute([$box_id]);
$cores = $cores_query->fetchAll();

// Get connection statistics
$stats_query = $pdo->prepare("
    SELECT 
        COUNT(*) as total_cores,
        SUM(CASE WHEN connection_status = 'available' THEN 1 ELSE 0 END) as available_cores,
        SUM(CASE WHEN connection_status = 'connected' THEN 1 ELSE 0 END) as direct_connections,
        SUM(CASE WHEN connection_status = 'split' THEN 1 ELSE 0 END) as splitter_connections
    FROM cores 
    WHERE box_id = ?
");
$stats_query->execute([$box_id]);
$stats = $stats_query->fetch();

// Get direct customer connections
$customers_query = $pdo->prepare("
    SELECT core_number, color, connected_to as customer_name, power_level,
           technician_name, customer_location, customer_image, connection_date, technician_notes
    FROM cores 
    WHERE box_id = ? AND connection_status = 'connected' AND connected_to IS NOT NULL
    ORDER BY core_number
");
$customers_query->execute([$box_id]);
$customers = $customers_query->fetchAll();

// Get available cores
$available_query = $pdo->prepare("
    SELECT core_number, color, power_level
    FROM cores 
    WHERE box_id = ? AND connection_status = 'available'
    ORDER BY core_number
");
$available_query->execute([$box_id]);
$available = $available_query->fetchAll();

// Get recent activity
$activity_query = $pdo->prepare("
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
$activity_query->execute([$box_id]);
$activities = $activity_query->fetchAll();

// Set filename
$filename = "box_" . $box['box_name'] . "_export_" . date('Y-m-d_H-i-s') . ".xls";

// Set headers for Excel download
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Cache-Control: max-age=0");
header("Expires: 0");
header("Pragma: public");

// Start HTML output for Excel
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .header { background-color: #4CAF50; color: white; }
        .section-title { background-color: #e7e7e7; font-weight: bold; }
        .stats { background-color: #f9f9f9; }
        .connected { background-color: #d4edda; }
        .available { background-color: #fff3cd; }
        .splitter { background-color: #cce5ff; }
    </style>
</head>
<body>
    <table>
        <!-- BOX INFORMATION -->
        <tr class="header">
            <th colspan="6" style="text-align: center; font-size: 16px;">
                FIBER BOX EXPORT - <?php echo strtoupper($box['box_name']); ?>
            </th>
        </tr>
        <tr>
            <td colspan="6">
                <strong>Export Date:</strong> <?php echo date('Y-m-d H:i:s'); ?><br>
                <strong>Exported By:</strong> <?php echo $_SESSION['username']; ?>
            </td>
        </tr>
        <tr class="section-title">
            <td colspan="6">BOX INFORMATION</td>
        </tr>
        <tr>
            <td><strong>Box Name</strong></td>
            <td><?php echo $box['box_name']; ?></td>
            <td><strong>Total Cores</strong></td>
            <td><?php echo $box['total_cores']; ?></td>
            <td><strong>Created By</strong></td>
            <td><?php echo $box['created_by_name']; ?></td>
        </tr>
        <tr>
            <td><strong>Address</strong></td>
            <td colspan="2"><?php echo $box['address'] ?: 'N/A'; ?></td>
            <td><strong>Location</strong></td>
            <td colspan="2">
                <?php if ($box['location_lat'] && $box['location_lng']): ?>
                    <?php echo $box['location_lat']; ?>, <?php echo $box['location_lng']; ?>
                <?php else: ?>
                    Not set
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td><strong>Created Date</strong></td>
            <td colspan="2"><?php echo date('Y-m-d H:i:s', strtotime($box['created_at'])); ?></td>
            <td><strong>Status</strong></td>
            <td colspan="2">
                <?php 
                $active_cores = $stats['direct_connections'] + $stats['splitter_connections'];
                $utilization = ($box['total_cores'] > 0) ? round(($active_cores / $box['total_cores']) * 100, 1) : 0;
                echo "Utilization: " . $utilization . "%";
                ?>
            </td>
        </tr>

        <!-- STATISTICS -->
        <tr class="section-title">
            <td colspan="6">CONNECTION STATISTICS</td>
        </tr>
        <tr class="stats">
            <td><strong>Total Cores</strong></td>
            <td><?php echo $stats['total_cores']; ?></td>
            <td><strong>Available Cores</strong></td>
            <td><?php echo $stats['available_cores']; ?></td>
            <td><strong>Utilization Rate</strong></td>
            <td>
                <?php 
                if ($stats['total_cores'] > 0) {
                    $used = $stats['total_cores'] - $stats['available_cores'];
                    echo round(($used / $stats['total_cores']) * 100, 1) . "%";
                } else {
                    echo "0%";
                }
                ?>
            </td>
        </tr>
        <tr class="stats">
            <td><strong>Direct Connections</strong></td>
            <td><?php echo $stats['direct_connections']; ?></td>
            <td><strong>Splitter Connections</strong></td>
            <td><?php echo $stats['splitter_connections']; ?></td>
            <td><strong>Available Cores</strong></td>
            <td><?php echo $stats['available_cores']; ?></td>
        </tr>

        <!-- ALL CORES DETAILS -->
        <tr class="section-title">
            <td colspan="10">ALL CORE CONNECTIONS (<?php echo count($cores); ?> cores)</td>
        </tr>
        <tr>
            <th>Core #</th>
            <th>Color</th>
            <th>Power Level (dBm)</th>
            <th>Status</th>
            <th>Connection Type</th>
            <th>Connected To</th>
            <th>Technician</th>
            <th>Location</th>
            <th>Connection Date</th>
            <th>Notes</th>
        </tr>
        <?php foreach($cores as $core): ?>
        <tr class="<?php 
            if ($core['connection_status'] == 'connected') echo 'connected';
            elseif ($core['connection_status'] == 'available') echo 'available';
            elseif ($core['connection_status'] == 'split') echo 'splitter';
        ?>">
            <td><?php echo $core['core_number']; ?></td>
            <td><?php echo $core['color']; ?></td>
            <td><?php echo $core['power_level']; ?> dBm</td>
            <td><?php echo ucfirst($core['connection_status']); ?></td>
            <td>
                <?php 
                if ($core['connection_status'] == 'split' && $core['splitter_name']) {
                    echo "Splitter: " . $core['splitter_name'] . " (" . ($core['splitter_type'] ?? 'N/A') . ")";
                } elseif ($core['connection_status'] == 'connected') {
                    echo "Direct Connection";
                } else {
                    echo "N/A";
                }
                ?>
            </td>
            <td>
                <?php 
                if ($core['connection_status'] == 'connected') {
                    echo $core['connected_to'];
                } elseif ($core['connection_status'] == 'split' && $core['splitter_details']) {
                    echo $core['splitter_details'];
                } else {
                    echo "N/A";
                }
                ?>
            </td>
            <td><?php echo $core['technician_name'] ?: 'N/A'; ?></td>
            <td><?php echo $core['customer_location'] ?: 'N/A'; ?></td>
            <td>
                <?php 
                if ($core['connection_date']) {
                    echo date('Y-m-d', strtotime($core['connection_date']));
                } else {
                    echo 'N/A';
                }
                ?>
            </td>
            <td><?php echo $core['technician_notes'] ?: 'N/A'; ?></td>
        </tr>
        <?php endforeach; ?>

        <!-- DIRECT CUSTOMER CONNECTIONS -->
        <tr class="section-title">
            <td colspan="9">DIRECT CUSTOMER CONNECTIONS (<?php echo count($customers); ?> customers)</td>
        </tr>
        <tr>
            <th>Core #</th>
            <th>Color</th>
            <th>Customer Name</th>
            <th>Technician</th>
            <th>Customer Location</th>
            <th>Power Level (dBm)</th>
            <th>Connection Date</th>
            <th>Image Available</th>
            <th>Notes</th>
        </tr>
        <?php foreach($customers as $customer): ?>
        <tr class="connected">
            <td><?php echo $customer['core_number']; ?></td>
            <td><?php echo $customer['color']; ?></td>
            <td><?php echo $customer['customer_name']; ?></td>
            <td><?php echo $customer['technician_name'] ?: 'N/A'; ?></td>
            <td><?php echo $customer['customer_location'] ?: 'N/A'; ?></td>
            <td><?php echo $customer['power_level']; ?> dBm</td>
            <td><?php echo date('Y-m-d', strtotime($customer['connection_date'])); ?></td>
            <td><?php echo $customer['customer_image'] ? 'Yes' : 'No'; ?></td>
            <td><?php echo $customer['technician_notes'] ?: 'N/A'; ?></td>
        </tr>
        <?php endforeach; ?>

        <!-- AVAILABLE CORES -->
        <tr class="section-title">
            <td colspan="4">AVAILABLE CORES (<?php echo count($available); ?> cores)</td>
        </tr>
        <tr>
            <th>Core #</th>
            <th>Color</th>
            <th>Power Level (dBm)</th>
            <th>Status</th>
        </tr>
        <?php foreach($available as $avail): ?>
        <tr class="available">
            <td><?php echo $avail['core_number']; ?></td>
            <td><?php echo $avail['color']; ?></td>
            <td><?php echo $avail['power_level']; ?> dBm</td>
            <td>Available</td>
        </tr>
        <?php endforeach; ?>

        <!-- RECENT ACTIVITY -->
        <tr class="section-title">
            <td colspan="6">RECENT ACTIVITY (Last 10 activities)</td>
        </tr>
        <tr>
            <th>Core #</th>
            <th>Activity</th>
            <th>Connected To</th>
            <th>Technician</th>
            <th>Date & Time</th>
            <th>Type</th>
        </tr>
        <?php foreach($activities as $activity): ?>
        <tr>
            <td><?php echo $activity['core_number']; ?></td>
            <td>
                <?php 
                if ($activity['connection_status'] == 'connected') {
                    echo "Connected to customer";
                } elseif ($activity['connection_status'] == 'split') {
                    echo "Connected to splitter";
                } else {
                    echo "Set as available";
                }
                ?>
            </td>
            <td><?php echo $activity['connected_to'] ?: 'N/A'; ?></td>
            <td><?php echo $activity['technician_name'] ?: 'N/A'; ?></td>
            <td>
                <?php 
                if ($activity['connection_date']) {
                    echo date('Y-m-d H:i', strtotime($activity['connection_date']));
                } else {
                    echo 'N/A';
                }
                ?>
            </td>
            <td><?php echo $activity['connected_to_type'] ?: 'N/A'; ?></td>
        </tr>
        <?php endforeach; ?>

        <!-- SUMMARY -->
        <tr class="section-title">
            <td colspan="6">EXPORT SUMMARY</td>
        </tr>
        <tr>
            <td colspan="6">
                <strong>Total Records Exported:</strong> <?php echo count($cores); ?> cores<br>
                <strong>Direct Customers:</strong> <?php echo count($customers); ?><br>
                <strong>Available for Use:</strong> <?php echo count($available); ?> cores<br>
                <strong>Splitter Connections:</strong> <?php echo $stats['splitter_connections']; ?><br>
                <strong>Data Accuracy:</strong> As of <?php echo date('Y-m-d H:i:s'); ?><br>
                <strong>Note:</strong> This is an automated export from Fiber Management System
            </td>
        </tr>
    </table>
</body>
</html>