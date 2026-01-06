<?php
$servername = "127.0.0.1";
$username = "root";
$password = "";
$dbname = "isp_infrastructure";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die('<option value="">Error connecting to database</option>');
}

$type = $_GET['type'] ?? '';

if ($type === 'core') {
    $sql = "SELECT c.id, CONCAT('Box: ', b.box_name, ', Core: ', c.core_number, ' (', c.color, ') - Power: ', COALESCE(c.power_level, 'N/A'), ' dBm') as name 
            FROM cores c 
            JOIN boxes b ON c.box_id = b.id 
            WHERE (c.is_connected = 0 OR c.is_connected IS NULL) AND c.connection_status = 'available'";
    $result = $conn->query($sql);
    
    echo '<option value="">Select Core Connection</option>';
    while ($row = $result->fetch_assoc()) {
        echo "<option value='{$row['id']}'>{$row['name']}</option>";
    }
} else if ($type === 'splitter') {
    $sql = "SELECT sc.id, 
                   CONCAT('Splitter: ', s.splitter_name, ' (', s.splitter_type, '), Port: ', sc.splitter_core_number, 
                   ' - Power: ', COALESCE(sc.power, 'N/A'), ' dBm') as name 
            FROM splitter_cores sc 
            JOIN splitters s ON sc.splitter_id = s.id 
            WHERE sc.status = 'available' OR sc.customer_name = ''";
    $result = $conn->query($sql);
    
    echo '<option value="">Select Splitter Port</option>';
    while ($row = $result->fetch_assoc()) {
        echo "<option value='{$row['id']}'>{$row['name']}</option>";
    }
} else {
    echo '<option value="">Select Connection Type First</option>';
}

$conn->close();
?>