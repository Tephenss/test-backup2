<?php
require_once '../config/database.php';
require_once '../helpers/functions.php';

// Check if user is logged in and is admin
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['student_id']) || !isset($_GET['class_id'])) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit();
}

$student_id = intval($_GET['student_id']);
$class_id = intval($_GET['class_id']);

try {
    // Get student and class details
    $stmt = $pdo->prepare("
        SELECT 
            s.student_id,
            CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name, 
                   CASE WHEN s.suffix_name IS NOT NULL AND s.suffix_name != '' THEN CONCAT(' ', s.suffix_name) ELSE '' END) as student_name,
            s.deleted_at,
            sub.subject_code,
            sub.subject_name,
            c.semester,
            c.academic_year,
            c.section,
            t.full_name as teacher_name
        FROM students s
        INNER JOIN class_students cs ON s.id = cs.student_id
        INNER JOIN classes c ON cs.class_id = c.id
        INNER JOIN subjects sub ON c.subject_id = sub.id
        LEFT JOIN teachers t ON c.teacher_id = t.id
        WHERE s.id = ? AND c.id = ? AND cs.status = 'dropped'
    ");
    $stmt->execute([$student_id, $class_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        echo json_encode(['success' => false, 'error' => 'Student not found']);
        exit();
    }
    
    // Get attendance statistics for this class
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_days,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count,
            SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused_count
        FROM attendance
        WHERE student_id = ? AND class_id = ?
    ");
    $stmt->execute([$student_id, $class_id]);
    $attendance = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get semester settings to calculate semester completion percentage
    $semester_completion = 0;
    $semester_days = 0;
    $days_attended = 0;
    
    try {
        // Get current semester settings
        // Map semester integer to semester string (0 = "1st Semester", 1 = "2nd Semester")
        $semester_map = [
            0 => '1st Semester',
            1 => '2nd Semester'
        ];
        $semester_string = $semester_map[$student['semester']] ?? '1st Semester';
        
        $stmt = $pdo->prepare("
            SELECT start_date, end_date 
            FROM semester_settings 
            WHERE semester = ? AND is_current = 1
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute([$semester_string]);
        $semester = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If not found, try to get any semester setting
        if (!$semester) {
            $stmt = $pdo->prepare("
                SELECT start_date, end_date 
                FROM semester_settings 
                WHERE semester = ?
                ORDER BY is_current DESC, id DESC 
                LIMIT 1
            ");
            $stmt->execute([$semester_string]);
            $semester = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        if ($semester && !empty($student['deleted_at'])) {
            $start_date = new DateTime($semester['start_date']);
            $end_date = new DateTime($semester['end_date']);
            $dropped_date = new DateTime($student['deleted_at']);
            
            // Calculate total semester days
            $semester_days = $start_date->diff($end_date)->days;
            
            // Calculate days from start to dropped date
            $days_attended = $start_date->diff($dropped_date)->days;
            
            // Calculate completion percentage
            if ($semester_days > 0) {
                $semester_completion = min(100, max(0, ($days_attended / $semester_days) * 100));
            }
        }
    } catch (Exception $e) {
        error_log("Error calculating semester completion: " . $e->getMessage());
    }
    
    // Format dropped date
    $dropped_date = 'N/A';
    if (!empty($student['deleted_at'])) {
        $dropped_date = date('M d, Y H:i', strtotime($student['deleted_at']));
    }
    
    // Calculate attendance rate
    $total_attendance = intval($attendance['total_days'] ?? 0);
    $present = intval($attendance['present_count'] ?? 0);
    $absent = intval($attendance['absent_count'] ?? 0);
    $late = intval($attendance['late_count'] ?? 0);
    $excused = intval($attendance['excused_count'] ?? 0);
    $attendance_rate = $total_attendance > 0 ? (($present + $excused) / $total_attendance * 100) : 0;
    
    echo json_encode([
        'success' => true,
        'student_id' => $student['student_id'],
        'student_name' => $student['student_name'],
        'subject_code' => $student['subject_code'],
        'subject_name' => $student['subject_name'],
        'teacher_name' => $student['teacher_name'] ?? 'N/A',
        'dropped_date' => $dropped_date,
        'semester' => $student['semester'],
        'academic_year' => $student['academic_year'],
        'section' => $student['section'],
        'present_count' => $present,
        'absent_count' => $absent,
        'late_count' => $late,
        'excused_count' => $excused,
        'total_days' => $total_attendance,
        'attendance_rate' => round($attendance_rate, 2),
        'semester_completion' => round($semester_completion, 2),
        'semester_days' => $semester_days,
        'days_attended' => $days_attended
    ]);
} catch (PDOException $e) {
    error_log("Error fetching dropped student details: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>

