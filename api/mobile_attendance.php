<?php
/**
 * Mobile Attendance API
 * 
 * API endpoint for Android app to record attendance
 * This saves to MySQL (primary database) and automatically backs up to Firebase
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
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
require_once '../config.php';
require_once '../helpers/RfidHelper.php';
require_once '../helpers/BackupHooks.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Fallback to POST data if JSON is not available
if (!$input) {
    $input = $_POST;
}

// Extract parameters
$teacher_id = $input['teacher_id'] ?? null;
$class_id = isset($input['class_id']) ? (int)$input['class_id'] : 0;
$student_id = isset($input['student_id']) ? (int)$input['student_id'] : 0;
$date = $input['date'] ?? date('Y-m-d');
$status = $input['status'] ?? 'present'; // 'present', 'late', 'absent', 'excused'
$timetable_id = isset($input['timetable_id']) ? (int)$input['timetable_id'] : null; // Session ID

// Validate required parameters
if (!$teacher_id || !$class_id || !$student_id) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing required parameters: teacher_id, class_id, student_id'
    ]);
    exit();
}

// Validate teacher exists and get teacher info
try {
    $stmt = $pdo->prepare("SELECT id FROM teachers WHERE id = ? AND is_deleted = 0");
    $stmt->execute([$teacher_id]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$teacher) {
        echo json_encode([
            'success' => false,
            'message' => 'Teacher not found'
        ]);
        exit();
    }
    
    // Verify class exists and belongs to teacher
    $classStmt = $pdo->prepare("SELECT id, section, year_level FROM classes WHERE id = ? AND teacher_id = ?");
    $classStmt->execute([$class_id, $teacher_id]);
    $classInfo = $classStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$classInfo) {
        echo json_encode([
            'success' => false,
            'message' => 'Class not found or access denied'
        ]);
        exit();
    }
    
    // Verify student exists
    $studentStmt = $pdo->prepare("SELECT id, student_id, section, year_level FROM students WHERE id = ? AND is_deleted = 0");
    $studentStmt->execute([$student_id]);
    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        echo json_encode([
            'success' => false,
            'message' => 'Student not found'
        ]);
        exit();
    }
    
    // Check if timetable_id column exists
    $checkColumnStmt = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'timetable_id'");
    $has_timetable_id_column = ($checkColumnStmt->rowCount() > 0);
    
    // Check if attendance already exists (with or without session ID)
    if ($has_timetable_id_column && $timetable_id) {
        $checkStmt = $pdo->prepare("SELECT id FROM attendance WHERE class_id = ? AND student_id = ? AND date = ? AND timetable_id = ?");
        $checkStmt->execute([$class_id, $student_id, $date, $timetable_id]);
    } else {
        $checkStmt = $pdo->prepare("SELECT id FROM attendance WHERE class_id = ? AND student_id = ? AND date = ?");
        $checkStmt->execute([$class_id, $student_id, $date]);
    }
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    $backupHooks = new BackupHooks();
    
    if ($existing) {
        // Update existing attendance
        $updateStmt = $pdo->prepare("UPDATE attendance SET status = ?, recorded_by = ? WHERE id = ?");
        $updateStmt->execute([$status, $teacher_id, $existing['id']]);
        $attendance_id = $existing['id'];
        
        // Backup update to Firebase
        try {
            $attendanceData = [
                'id' => $attendance_id,
                'class_id' => $class_id,
                'student_id' => $student_id,
                'date' => $date,
                'status' => $status,
                'recorded_by' => $teacher_id,
                'created_at' => date('Y-m-d H:i:s')
            ];
            if ($has_timetable_id_column && $timetable_id) {
                $attendanceData['timetable_id'] = $timetable_id;
            }
            $backupHooks->backupAttendanceRecord($attendanceData);
        } catch (Exception $e) {
            error_log("Firebase backup failed for attendance update: " . $e->getMessage());
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Attendance updated successfully',
            'attendance_id' => $attendance_id,
            'status' => $status,
            'operation' => 'update'
        ]);
    } else {
        // Insert new attendance (with or without timetable_id)
        if ($has_timetable_id_column && $timetable_id) {
            $insertStmt = $pdo->prepare("INSERT INTO attendance (class_id, timetable_id, student_id, date, status, recorded_by) VALUES (?, ?, ?, ?, ?, ?)");
            $insertStmt->execute([$class_id, $timetable_id, $student_id, $date, $status, $teacher_id]);
        } else {
            $insertStmt = $pdo->prepare("INSERT INTO attendance (class_id, student_id, date, status, recorded_by) VALUES (?, ?, ?, ?, ?)");
            $insertStmt->execute([$class_id, $student_id, $date, $status, $teacher_id]);
        }
        $attendance_id = $pdo->lastInsertId();
        
        // Backup to Firebase (automatic via BackupHooks)
        try {
            $attendanceData = [
                'id' => $attendance_id,
                'class_id' => $class_id,
                'student_id' => $student_id,
                'date' => $date,
                'status' => $status,
                'recorded_by' => $teacher_id,
                'created_at' => date('Y-m-d H:i:s')
            ];
            if ($has_timetable_id_column && $timetable_id) {
                $attendanceData['timetable_id'] = $timetable_id;
            }
            $backupHooks->backupAttendanceRecord($attendanceData);
        } catch (Exception $e) {
            error_log("Firebase backup failed for attendance insert: " . $e->getMessage());
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Attendance recorded successfully',
            'attendance_id' => $attendance_id,
            'status' => $status,
            'operation' => 'insert'
        ]);
    }
    
} catch (PDOException $e) {
    error_log("Error recording mobile attendance: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("Error in mobile attendance API: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

