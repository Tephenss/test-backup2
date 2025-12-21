<?php
/**
 * Get Attendance API
 * 
 * API endpoint to fetch attendance records from MySQL
 * Used for real-time polling by both website and Android app
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Ensure timezone is set
if (!ini_get('date.timezone')) {
    date_default_timezone_set('Asia/Manila');
}

require_once '../config/database.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get parameters
$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$date = $_GET['date'] ?? date('Y-m-d');
$teacher_id = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : null;

// Validate required parameters
if (!$class_id || !$date) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing required parameters: class_id, date'
    ]);
    exit();
}

try {
    // Build query based on teacher_id (if provided, verify access)
    $query = "
        SELECT a.id, a.class_id, a.student_id, a.date, a.status, a.recorded_by, a.created_at,
               s.student_id as student_code, s.first_name, s.last_name, s.section, s.year_level
        FROM attendance a
        JOIN students s ON a.student_id = s.id
        WHERE a.class_id = ? AND a.date = ? AND s.is_deleted = 0
        ORDER BY a.created_at DESC
    ";
    
    $params = [$class_id, $date];
    
    // If teacher_id is provided, verify access
    if ($teacher_id) {
        $verifyStmt = $pdo->prepare("SELECT id FROM classes WHERE id = ? AND teacher_id = ?");
        $verifyStmt->execute([$class_id, $teacher_id]);
        if (!$verifyStmt->fetch()) {
            echo json_encode([
                'success' => false,
                'message' => 'Class not found or access denied'
            ]);
            exit();
        }
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format response
    $attendance_records = [];
    foreach ($records as $record) {
        $attendance_records[] = [
            'id' => (int)$record['id'],
            'class_id' => (int)$record['class_id'],
            'student_id' => (int)$record['student_id'],
            'student_code' => $record['student_code'],
            'student_name' => trim($record['first_name'] . ' ' . $record['last_name']),
            'date' => $record['date'],
            'status' => $record['status'],
            'recorded_by' => (int)$record['recorded_by'],
            'created_at' => $record['created_at']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'class_id' => $class_id,
        'date' => $date,
        'records' => $attendance_records,
        'count' => count($attendance_records)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log("Error fetching attendance: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("Error in get attendance API: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}







