<?php
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit();
}

$core_id = $_GET['core_id'] ?? 0;

$stmt = $pdo->prepare("
    SELECT c.*, 
           GROUP_CONCAT(CONCAT_WS('|', sc.splitter_core_number, sc.customer_name, sc.comment, sc.power) SEPARATOR ';') as splitter_cores_data
    FROM cores c
    LEFT JOIN splitter_cores sc ON c.id = sc.core_id
    WHERE c.id = ?
    GROUP BY c.id
");
$stmt->execute([$core_id]);
$core = $stmt->fetch();

if ($core) {
    // Parse splitter cores data
    $splitter_cores = [];
    if ($core['splitter_cores_data']) {
        $cores_data = explode(';', $core['splitter_cores_data']);
        foreach ($cores_data as $core_data) {
            list($number, $customer, $comment, $power) = explode('|', $core_data);
            $splitter_cores[] = [
                'splitter_core_number' => $number,
                'customer_name' => $customer,
                'comment' => $comment,
                'power' => $power
            ];
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'id' => $core['id'],
        'color' => $core['color'],
        'power_level' => $core['power_level'],
        'connection_status' => $core['connection_status'],
        'connected_to' => $core['connected_to'],
        'technician_name' => $core['technician_name'],
        'customer_location' => $core['customer_location'],
        'technician_notes' => $core['technician_notes'],
        'connected_to_id' => $core['connected_to_id'],
        'notes' => $core['notes'],
        'splitter_type' => '1x2', // Default, you might want to get this from splitters table
        'splitter_cores' => $splitter_cores
    ]);
} else {
    header('HTTP/1.1 404 Not Found');
    echo json_encode(['error' => 'Core not found']);
}
?>