<?php
/**
 * RFID Scanner Heartbeat/Status API
 * 
 * The ESP scanner should call this endpoint every 3 seconds to indicate it's online.
 * When ESP stops calling (turned off), the system detects it's offline.
 * 
 * Firebase Path: attendance_system/rfid_scanner/status
 * 
 * Usage:
 * - GET: Check current scanner status
 * - POST: Update heartbeat (ESP sends this every 3 seconds)
 * - DELETE: Mark scanner as offline (optional, for clean shutdown)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Load Firebase config
try {
    $firebaseConfig = require '../config/firebase.php';
    $firebaseUrl = rtrim($firebaseConfig['database_url'], '/');
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Firebase config not found']);
    exit;
}

$statusPath = $firebaseUrl . '/attendance_system/rfid_scanner/status.json';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Check current scanner status
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    
    $response = @file_get_contents($statusPath, false, $context);
    
    if ($response === false || $response === 'null') {
        echo json_encode([
            'success' => true,
            'online' => false,
            'status' => 'offline',
            'message' => 'No scanner status data'
        ]);
        exit;
    }
    
    $data = json_decode($response, true);
    
    if (!$data || !isset($data['last_heartbeat'])) {
        echo json_encode([
            'success' => true,
            'online' => false,
            'status' => 'offline',
            'message' => 'Invalid status data'
        ]);
        exit;
    }
    
    // Check if heartbeat is recent (within 10 seconds)
    $currentTime = round(microtime(true) * 1000); // Current time in milliseconds
    $lastHeartbeat = $data['last_heartbeat'];
    
    // Convert to milliseconds if in seconds
    if ($lastHeartbeat < 10000000000) {
        $lastHeartbeat = $lastHeartbeat * 1000;
    }
    
    $timeDiff = $currentTime - $lastHeartbeat;
    $isOnline = $data['online'] === true && $timeDiff < 10000; // 10 seconds
    
    echo json_encode([
        'success' => true,
        'online' => $isOnline,
        'status' => $isOnline ? 'online' : 'offline',
        'last_heartbeat' => $lastHeartbeat,
        'current_time' => $currentTime,
        'time_diff_ms' => $timeDiff,
        'scanner_id' => $data['scanner_id'] ?? null
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update heartbeat - ESP sends this every 3 seconds
    $input = json_decode(file_get_contents('php://input'), true);
    
    $statusData = [
        'online' => true,
        'last_heartbeat' => round(microtime(true) * 1000), // Current time in milliseconds
        'scanner_id' => $input['scanner_id'] ?? 'esp_scanner_01',
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    $context = stream_context_create([
        'http' => [
            'method' => 'PUT',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($statusData)
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    
    $result = @file_get_contents($statusPath, false, $context);
    
    if ($result === false) {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to update status'
        ]);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Heartbeat updated',
        'online' => true,
        'timestamp' => $statusData['last_heartbeat']
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // Mark scanner as offline (clean shutdown)
    $statusData = [
        'online' => false,
        'last_heartbeat' => round(microtime(true) * 1000),
        'scanner_id' => $_GET['scanner_id'] ?? 'esp_scanner_01',
        'updated_at' => date('Y-m-d H:i:s'),
        'shutdown_reason' => 'manual'
    ];
    
    $context = stream_context_create([
        'http' => [
            'method' => 'PUT',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($statusData)
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    
    $result = @file_get_contents($statusPath, false, $context);
    
    echo json_encode([
        'success' => true,
        'message' => 'Scanner marked as offline',
        'online' => false
    ]);
    exit;
}

// Invalid method
http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
