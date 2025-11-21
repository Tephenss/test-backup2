<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../config/database.php';
require_once '../config.php';
require_once '../helpers/RfidHelper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'teacher') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

ensureRfidInfrastructure($pdo);

$action = $_GET['action'] ?? '';

// Helper function to get current day name
function getCurrentDayName() {
    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    return $days[(int)date('w')];
}

// Helper function to determine attendance status based on schedule
function determineAttendanceStatus($startTime, $currentTime) {
    // Convert times to minutes since midnight for easier comparison
    $startParts = explode(':', $startTime);
    $startMinutes = (int)$startParts[0] * 60 + (int)$startParts[1];
    
    $currentParts = explode(':', $currentTime);
    $currentMinutes = (int)$currentParts[0] * 60 + (int)$currentParts[1];
    
    // Calculate time difference
    $minutesDiff = $currentMinutes - $startMinutes;
    
    // Logic:
    // 0 to 15 minutes after start → Present (grace period)
    // 16 to 30 minutes after start → Late
    // After 30 minutes → Too late (cannot tap)
    
    if ($minutesDiff < 0) {
        // Tapped before schedule - too early
        return ['status' => 'too_early', 'message' => 'Too early. Schedule has not started yet.'];
    } elseif ($minutesDiff >= 0 && $minutesDiff <= 15) {
        // Within 15 minutes grace period
        return ['status' => 'present', 'message' => 'On time - Marked as Present'];
    } elseif ($minutesDiff >= 16 && $minutesDiff <= 30) {
        // 16-30 minutes late
        return ['status' => 'late', 'message' => 'Late arrival - Marked as Late'];
    } else {
        // More than 30 minutes late
        return ['status' => 'too_late', 'message' => 'Too late. Attendance window has closed.'];
    }
}

try {
    switch ($action) {
        case 'get_student_by_rfid':
            $rfidUid = trim($_GET['rfid_uid'] ?? '');
            $classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
            $attendanceDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
            
            if (empty($rfidUid)) {
                echo json_encode(['success' => false, 'message' => 'RFID UID is required']);
                exit;
            }
            
            if (!$classId) {
                echo json_encode(['success' => false, 'message' => 'Class ID is required']);
                exit;
            }
            
            // Get current day of week
            $currentDay = getCurrentDayName();
            $currentTime = date('H:i:s');
            
            // Get timetable for this class and day
            $timetableStmt = $pdo->prepare("
                SELECT id, start_time, end_time, day_of_week
                FROM timetable
                WHERE class_id = ? AND day_of_week = ?
                ORDER BY start_time ASC
                LIMIT 1
            ");
            $timetableStmt->execute([$classId, $currentDay]);
            $schedule = $timetableStmt->fetch(PDO::FETCH_ASSOC);
            
            $attendanceStatusInfo = null;
            if ($schedule) {
                // Determine attendance status based on current time vs schedule
                $attendanceStatusInfo = determineAttendanceStatus($schedule['start_time'], $currentTime);
            } else {
                // No schedule found for today - allow manual selection
                $attendanceStatusInfo = ['status' => 'manual', 'message' => 'No schedule found for today. Please select status manually.'];
            }
            
            // Get student by RFID UID
            $stmt = $pdo->prepare("
                SELECT s.id, s.student_id, s.first_name, s.middle_name, s.last_name, s.suffix_name,
                       s.course, s.year_level, s.section, s.profile_picture, s.rfid_uid
                FROM students s
                WHERE s.rfid_uid = ? AND s.is_deleted = 0
                LIMIT 1
            ");
            $stmt->execute([$rfidUid]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$student) {
                echo json_encode(['success' => false, 'message' => 'Student not found with this RFID card.']);
                exit;
            }
            
            // Verify class exists and belongs to teacher - get class details
            $classStmt = $pdo->prepare("SELECT id, section, year_level, subject_id FROM classes WHERE id = ? AND teacher_id = ?");
            $classStmt->execute([$classId, $_SESSION['user_id']]);
            $classInfo = $classStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$classInfo) {
                echo json_encode(['success' => false, 'message' => 'Class not found or access denied.']);
                exit;
            }
            
            // STRICT VALIDATION: 
            // 1. Check if student's section and year_level match the class
            // 2. Check if student is enrolled in this specific class with active status
            $sectionMatch = ($student['section'] === $classInfo['section']);
            $yearMatch = ($student['year_level'] == $classInfo['year_level']);
            
            if (!$sectionMatch || !$yearMatch) {
                // Student's section/year doesn't match the class - reject immediately
                echo json_encode([
                    'success' => false,
                    'message' => 'This student (Year ' . $student['year_level'] . ' - Section ' . $student['section'] . ') is NOT in the selected class (Year ' . $classInfo['year_level'] . ' - Section ' . $classInfo['section'] . '). Please select the correct class.'
                ]);
                exit;
            }
            
            // 3. Verify student is enrolled in this specific class with active status
            $enrollStmt = $pdo->prepare("
                SELECT cs.id 
                FROM class_students cs
                JOIN classes c ON cs.class_id = c.id
                WHERE cs.class_id = ? 
                  AND cs.student_id = ? 
                  AND cs.status = 'active'
                  AND c.teacher_id = ?
            ");
            $enrollStmt->execute([$classId, $student['id'], $_SESSION['user_id']]);
            $enrollment = $enrollStmt->fetch();
            
            if (!$enrollment) {
                // Student not enrolled in this class - reject the scan immediately
                echo json_encode([
                    'success' => false,
                    'message' => 'This student is NOT enrolled in the selected class. Only students enrolled in this class can scan their RFID card.'
                ]);
                exit;
            }
            
            // Check if student already has attendance for today
            $existingAttStmt = $pdo->prepare("
                SELECT id, status 
                FROM attendance 
                WHERE class_id = ? AND student_id = ? AND date = ?
                LIMIT 1
            ");
            $existingAttStmt->execute([$classId, $student['id'], $attendanceDate]);
            $existingAttendance = $existingAttStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'student' => $student,
                'attendance_status' => $attendanceStatusInfo,
                'schedule' => $schedule,
                'current_time' => $currentTime,
                'existing_attendance' => $existingAttendance
            ]);
            break;
            
        case 'mark_absent_students':
            // Mark all students who didn't tap during the attendance window as absent
            $classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
            $attendanceDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
            
            if (!$classId) {
                echo json_encode(['success' => false, 'message' => 'Class ID is required']);
                exit;
            }
            
            // Verify class belongs to teacher
            $classStmt = $pdo->prepare("SELECT id FROM classes WHERE id = ? AND teacher_id = ?");
            $classStmt->execute([$classId, $_SESSION['user_id']]);
            if (!$classStmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Class not found or access denied.']);
                exit;
            }
            
            // Get current day of week
            $currentDay = getCurrentDayName();
            
            // Get timetable for this class and day
            $timetableStmt = $pdo->prepare("
                SELECT id, start_time, end_time, day_of_week
                FROM timetable
                WHERE class_id = ? AND day_of_week = ?
                ORDER BY start_time ASC
                LIMIT 1
            ");
            $timetableStmt->execute([$classId, $currentDay]);
            $schedule = $timetableStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$schedule) {
                echo json_encode(['success' => false, 'message' => 'No schedule found for today.']);
                exit;
            }
            
            // Calculate attendance window end time (30 minutes after start)
            $startParts = explode(':', $schedule['start_time']);
            $startMinutes = (int)$startParts[0] * 60 + (int)$startParts[1];
            $windowEndMinutes = $startMinutes + 30; // 30 minutes after start
            $windowEndHour = floor($windowEndMinutes / 60);
            $windowEndMin = $windowEndMinutes % 60;
            $windowEndTime = sprintf('%02d:%02d:00', $windowEndHour, $windowEndMin);
            
            // Get current time
            $currentTime = date('H:i:s');
            
            // Check if attendance window has closed
            if ($currentTime < $windowEndTime) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Attendance window is still open. Window closes at ' . $windowEndTime . '.',
                    'window_end' => $windowEndTime,
                    'current_time' => $currentTime
                ]);
                exit;
            }
            
            // Get all enrolled students for this class
            $studentsStmt = $pdo->prepare("
                SELECT cs.student_id
                FROM class_students cs
                WHERE cs.class_id = ? AND cs.status = 'active'
            ");
            $studentsStmt->execute([$classId]);
            $allStudents = $studentsStmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Get students who already have attendance for this date
            $attendedStmt = $pdo->prepare("
                SELECT DISTINCT student_id
                FROM attendance
                WHERE class_id = ? AND date = ?
            ");
            $attendedStmt->execute([$classId, $attendanceDate]);
            $attendedStudents = $attendedStmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Find students who didn't tap (not in attended list)
            $absentStudents = array_diff($allStudents, $attendedStudents);
            
            $markedCount = 0;
            $errors = [];
            
            // Mark absent students
            foreach ($absentStudents as $studentId) {
                try {
                    // Check if attendance already exists (shouldn't, but double-check)
                    $checkStmt = $pdo->prepare("SELECT id FROM attendance WHERE class_id = ? AND student_id = ? AND date = ?");
                    $checkStmt->execute([$classId, $studentId, $attendanceDate]);
                    if ($checkStmt->fetch()) {
                        continue; // Already has attendance, skip
                    }
                    
                    // Insert absent attendance
                    $insertStmt = $pdo->prepare("INSERT INTO attendance (class_id, student_id, date, status, recorded_by) VALUES (?, ?, ?, 'absent', ?)");
                    $insertStmt->execute([$classId, $studentId, $attendanceDate, $_SESSION['user_id']]);
                    $attendance_id = $pdo->lastInsertId();
                    
                    // Backup to Firebase
                    try {
                        require_once '../helpers/BackupHooks.php';
                        $backupHooks = new BackupHooks();
                        $attendanceData = [
                            'id' => $attendance_id,
                            'class_id' => $classId,
                            'student_id' => $studentId,
                            'date' => $attendanceDate,
                            'status' => 'absent',
                            'recorded_by' => $_SESSION['user_id']
                        ];
                        $backupHooks->backupAttendanceRecord($attendanceData, 'insert');
                    } catch (Exception $e) {
                        error_log('Firebase backup error for absent attendance: ' . $e->getMessage());
                    }
                    
                    $markedCount++;
                } catch (Exception $e) {
                    $errors[] = "Error marking student ID $studentId: " . $e->getMessage();
                }
            }
            
            echo json_encode([
                'success' => true,
                'message' => "Marked $markedCount students as absent.",
                'marked_count' => $markedCount,
                'total_students' => count($allStudents),
                'attended_count' => count($attendedStudents),
                'errors' => $errors
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (Exception $e) {
    error_log('RFID attendance API error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

