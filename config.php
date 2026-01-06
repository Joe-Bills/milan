<?php
// Database configuration for InfinityFree hosting
$host = 'sql306.infinityfree.com';
$dbname = 'if0_40837827_milan';
$username = 'if0_40837827';
$password = 'VKVXrOkpkCKB';

// Google Maps API Key
define('GMAPS_API_KEY', 'YOUR_GOOGLE_MAPS_API_KEY_HERE');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

session_start();

function getMapsApiKey() {
    return GMAPS_API_KEY;
}

// Function to get connection types
function getConnectionTypes() {
    return ['direct' => 'Direct Connection', 'splitter' => 'Through Splitter'];
}

// Function to get splitter types
function getSplitterTypes() {
    return ['1x2' => '1x2 Splitter', '1x4' => '1x4 Splitter', '1x8' => '1x8 Splitter', '1x16' => '1x16 Splitter', '1x32' => '1x32 Splitter'];
}
?>