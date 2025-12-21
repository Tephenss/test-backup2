<?php
session_start();
// Set the timezone to Asia/Manila
date_default_timezone_set('Asia/Manila');
require_once '../config/database.php';
require_once '../config.php';
require_once '../helpers/BackupHooks.php';
require_once '../helpers/RfidHelper.php';

if (!function_exists('normalizeDateString')) {
    function normalizeDateString($dateString) {
        if (!$dateString) {
            return '';
        }
        $dateString = trim($dateString);
        if (preg_match('/^\d{8}$/', $dateString)) {
            return substr($dateString, 0, 4) . '-' . substr($dateString, 4, 2) . '-' . substr($dateString, 6, 2);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $dateString)) {
            return substr($dateString, 0, 10);
        }
        if (preg_match('/^\d{4}\/\d{2}\/\d{2}/', $dateString)) {
            return substr($dateString, 0, 4) . '-' . substr($dateString, 5, 2) . '-' . substr($dateString, 8, 2);
        }
        return substr($dateString, 0, 10);
    }
}

if (!function_exists('deleteFirebaseAttendanceForClassDate')) {
    function deleteFirebaseAttendanceForClassDate($classId, $date) {
        try {
            $firebaseConfig = require '../config/firebase.php';
            $firebaseUrl = isset($firebaseConfig['database_url']) ? rtrim($firebaseConfig['database_url'], '/') : '';
            if (!$firebaseUrl) {
                return;
            }
            $attendanceUrl = $firebaseUrl . '/attendance_system/attendance.json';
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]);
            $response = @file_get_contents($attendanceUrl, false, $context);
            if ($response === false) {
                return;
            }
            $records = json_decode($response, true);
            if (!is_array($records) || empty($records)) {
                return;
            }
            // Normalize the input date to ensure consistent format
            $normalizedDate = normalizeDateString($date);
            $normalizedClassId = (string)$classId;
            
            foreach ($records as $key => $record) {
                $data = isset($record['data']) && is_array($record['data']) ? $record['data'] : $record;
                $recordClassId = isset($data['class_id']) ? (string)$data['class_id'] : (isset($data['classId']) ? (string)$data['classId'] : '');
                $recordDate = normalizeDateString($data['date'] ?? '');
                
                // STRICT matching: both class_id AND date must match EXACTLY
                if ($recordClassId === $normalizedClassId && $recordDate === $normalizedDate) {
                    $deleteUrl = $firebaseUrl . '/attendance_system/attendance/' . urlencode($key) . '.json';
                    $deleteContext = stream_context_create([
                        'http' => [
                            'method' => 'DELETE',
                            'header' => 'Content-Type: application/json'
                        ],
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false
                        ]
                    ]);
                    @file_get_contents($deleteUrl, false, $deleteContext);
                }
            }
        } catch (Exception $e) {
            error_log('Error deleting Firebase attendance for class/date: ' . $e->getMessage());
        }
    }
}

if (!function_exists('deleteFirebaseAttendanceForClassDateSession')) {
    function deleteFirebaseAttendanceForClassDateSession($classId, $date, $sessionId) {
        try {
            $firebaseConfig = require '../config/firebase.php';
            $firebaseUrl = isset($firebaseConfig['database_url']) ? rtrim($firebaseConfig['database_url'], '/') : '';
            if (!$firebaseUrl) {
                return;
            }
            
            // Normalize the input date to ensure consistent format
            $normalizedDate = normalizeDateString($date);
            $normalizedDateKey = str_replace('-', '', $normalizedDate); // Format: YYYYMMDD for key matching
            $normalizedClassId = (string)$classId;
            $normalizedSessionId = (string)$sessionId;
            
            $attendanceUrl = $firebaseUrl . '/attendance_system/attendance.json';
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]);
            $response = @file_get_contents($attendanceUrl, false, $context);
            if ($response === false) {
                error_log('Failed to fetch Firebase attendance records for deletion');
                
                return;
            }
            
            $records = json_decode($response, true);
            if (!is_array($records) || empty($records)) {
                return;
            }
            
            $deletedCount = 0;
            $deleteUrls = []; // Collect all keys to delete to avoid modifying array during iteration
            
            error_log("Searching Firebase for attendance records to delete: class={$normalizedClassId}, date={$normalizedDate}, session={$normalizedSessionId}");
            
            foreach ($records as $key => $record) {
                $shouldDelete = false;
                
                // METHOD 1: Check by key format first (faster and more reliable for session-specific records)
                // Expected key format: attendance_{classId}_{studentId}_{date}_{sessionId}
                if (strpos($key, 'attendance_') === 0) {
                    $keyParts = explode('_', $key);
                    if (count($keyParts) >= 5) {
                        // Has session ID in key format
                        $keyClassId = $keyParts[1] ?? '';
                        $keyDate = $keyParts[3] ?? '';
                        $keySessionId = $keyParts[4] ?? '';
                        
                        // Check class, date, and session ID match
                        $keyClassMatches = ($keyClassId === $normalizedClassId || (int)$keyClassId === (int)$normalizedClassId);
                        $keyDateMatches = ($keyDate === $normalizedDateKey);
                        
                        // Handle numeric vs string comparison for session ID
                        $keySessionMatches = ($keySessionId === $normalizedSessionId || (int)$keySessionId === (int)$normalizedSessionId);
                        
                        if ($keyClassMatches && $keyDateMatches && $keySessionMatches) {
                            $shouldDelete = true;
                            error_log("Matched record by key format: {$key}");
                        }
                    }
                }
                
                // METHOD 2: Check by data content (fallback for records with different key formats)
                if (!$shouldDelete) {
                    $data = isset($record['data']) && is_array($record['data']) ? $record['data'] : $record;
                    
                    $recordClassId = isset($data['class_id']) ? (string)$data['class_id'] : (isset($data['classId']) ? (string)$data['classId'] : '');
                    $recordDate = normalizeDateString($data['date'] ?? '');
                    $recordTimetableId = isset($data['timetable_id']) ? (string)$data['timetable_id'] : '';
                    
                    // STRICT matching: class_id, date, AND timetable_id must match EXACTLY
                    $classMatches = ($recordClassId === $normalizedClassId || (int)$recordClassId === (int)$normalizedClassId);
                    $dateMatches = ($recordDate === $normalizedDate);
                    
                    // Handle numeric vs string comparison for session ID
                    $sessionMatches = false;
                    if ($recordTimetableId !== '' && $recordTimetableId !== 'null' && $normalizedSessionId !== '') {
                        $sessionMatches = ($recordTimetableId === $normalizedSessionId);
                        if (!$sessionMatches) {
                            // Try numeric comparison
                            if (is_numeric($recordTimetableId) && is_numeric($normalizedSessionId)) {
                                $sessionMatches = ((int)$recordTimetableId === (int)$normalizedSessionId);
                            }
                        }
                    }
                    
                    if ($classMatches && $dateMatches && $sessionMatches) {
                        $shouldDelete = true;
                        error_log("Matched record by data content: {$key} (timetable_id: {$recordTimetableId})");
                    }
                }
                
                if ($shouldDelete) {
                    $deleteUrls[] = $key;
                }
            }
            
            error_log("Found " . count($deleteUrls) . " Firebase record(s) to delete for session {$normalizedSessionId}");
            
            // Delete all matched records
            foreach ($deleteUrls as $key) {
                $deleteUrl = $firebaseUrl . '/attendance_system/attendance/' . urlencode($key) . '.json';
                $deleteContext = stream_context_create([
                    'http' => [
                        'method' => 'DELETE',
                        'header' => 'Content-Type: application/json'
                    ],
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false
                    ]
                ]);
                $deleteResponse = @file_get_contents($deleteUrl, false, $deleteContext);
                if ($deleteResponse !== false) {
                    $deletedCount++;
                }
            }
            
            error_log("Deleted {$deletedCount} Firebase attendance record(s) for class {$classId}, date {$date}, session {$sessionId}");
            
        } catch (Exception $e) {
            error_log('Error deleting Firebase attendance for class/date/session: ' . $e->getMessage());
        }
    }
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'teacher') {
    header("Location: ../index.php");
    exit();
}

// Ensure RFID infrastructure exists
ensureRfidInfrastructure($pdo);

// Get teacher's classes
$stmt = $pdo->prepare("
    SELECT DISTINCT c.id,
           s.subject_code,
           s.year_level,
           c.section,
           CONCAT(s.subject_code, '-', s.year_level, c.section) as class_name,
           CONCAT(s.subject_code, ' - ', c.section, ' (', s.year_level, 'st Year)') as class_desc
    FROM classes c
    JOIN subjects s ON c.subject_id = s.id
    JOIN sections sec ON c.section = sec.name
    WHERE c.teacher_id = ? AND c.status = 'active'
    ORDER BY s.subject_code, c.section
");
$stmt->execute([$_SESSION['user_id']]);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch current user info for avatar display
$stmt = $pdo->prepare("SELECT * FROM teachers WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch current semester settings (always use is_current = 1)
try {
    $stmt = $pdo->query("SELECT * FROM semester_settings WHERE is_current = 1 ORDER BY id DESC LIMIT 1");
    $current_semester = $stmt->fetch(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $current_semester = null;
}

$selected_class = isset($_GET['class_id']) 
    ? $_GET['class_id'] 
    : (!empty($classes) ? $classes[0]['id'] : null);
$today = date('Y-m-d');
$selected_date = isset($_GET['date']) ? $_GET['date'] : $today;

// Determine the current term (Prelim/Midterm/Final) based on selected date within current semester
$current_term = null;
if ($current_semester && $selected_date >= $current_semester['start_date'] && $selected_date <= $current_semester['end_date']) {
    if ($selected_date >= $current_semester['prelim_start'] && $selected_date <= $current_semester['prelim_end']) {
        $current_term = 'Prelim';
    } elseif ($selected_date >= $current_semester['midterm_start'] && $selected_date <= $current_semester['midterm_end']) {
        $current_term = 'Midterm';
    } elseif ($selected_date >= $current_semester['final_start'] && $selected_date <= $current_semester['final_end']) {
        $current_term = 'Final';
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'mark_attendance':
                if (!isset($_POST['class_id'])) {
                    $_SESSION['error'] = "Please select a class";
                    break;
                }
                
                $class_id = $_POST['class_id'];
                $date = $_POST['date'];
                $session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : null;
                $attendance_data = $_POST['attendance'] ?? [];

                try {
                    // Check if timetable_id column exists
                    $has_timetable_id_column = false;
                    try {
                        $check_column = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'timetable_id'");
                        $has_timetable_id_column = ($check_column->rowCount() > 0);
                    } catch (Exception $e) {
                        // Column doesn't exist yet
                    }
                    
                    // Don't delete all records - use INSERT...ON DUPLICATE KEY UPDATE to update per-student records
                    // This ensures each session's records remain separate
                    if (!empty($attendance_data)) {
                        // Initialize BackupHooks for Firebase backup
                        $backupHooks = new BackupHooks();
                        
                        // Auto-enroll students in class_students as 'active'
                        $enrollStmt = $pdo->prepare("INSERT INTO class_students (class_id, student_id, status) VALUES (?, ?, 'active') ON DUPLICATE KEY UPDATE status = 'active'");
                        
                        // Use INSERT...ON DUPLICATE KEY UPDATE to update existing records or insert new ones
                        // This ensures records are per session (timetable_id)
                        if ($has_timetable_id_column && $session_id) {
                            $stmt = $pdo->prepare("
                                INSERT INTO attendance (class_id, timetable_id, student_id, date, status, recorded_by) 
                                VALUES (?, ?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE 
                                    status = VALUES(status),
                                    recorded_by = VALUES(recorded_by)
                            ");
                        } else {
                            $stmt = $pdo->prepare("
                                INSERT INTO attendance (class_id, student_id, date, status, recorded_by) 
                                VALUES (?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE 
                                    status = VALUES(status),
                                    recorded_by = VALUES(recorded_by)
                            ");
                        }
                        
                        foreach ($attendance_data as $student_id => $status) {
                            if (empty($status)) {
                                // If status is empty, delete the record for this session only
                                if ($has_timetable_id_column && $session_id) {
                                    $deleteStmt = $pdo->prepare("DELETE FROM attendance WHERE class_id = ? AND timetable_id = ? AND student_id = ? AND date = ?");
                                    $deleteStmt->execute([$class_id, $session_id, $student_id, $date]);
                                } else {
                                    $deleteStmt = $pdo->prepare("DELETE FROM attendance WHERE class_id = ? AND student_id = ? AND date = ?");
                                    $deleteStmt->execute([$class_id, $student_id, $date]);
                                }
                                continue;
                            }
                            
                            $enrollStmt->execute([$class_id, $student_id]);
                            
                            // Backup enrollment to Firebase
                            try {
                                $enrollmentData = [
                                    'class_id' => (string)$class_id,
                                    'student_id' => (string)$student_id,
                                    'status' => 'active',
                                    'enrolled_at' => date('Y-m-d H:i:s')
                                ];
                                $backupHooks->backupClassEnrollment($enrollmentData);
                            } catch (Exception $e) {
                                error_log("Firebase backup failed for enrollment: " . $e->getMessage());
                            }
                            
                            // Check if record exists to get ID for Firebase backup
                            $existingCheckStmt = null;
                            $attendance_id = null;
                            
                            if ($has_timetable_id_column && $session_id) {
                                $existingCheckStmt = $pdo->prepare("SELECT id FROM attendance WHERE class_id = ? AND timetable_id = ? AND student_id = ? AND date = ?");
                                $existingCheckStmt->execute([$class_id, $session_id, $student_id, $date]);
                                $existing = $existingCheckStmt->fetch();
                                if ($existing) {
                                    $attendance_id = $existing['id'];
                                }
                                
                                $stmt->execute([
                                    $class_id,
                                    $session_id,
                                    $student_id,
                                    $date,
                                    $status,
                                    $_SESSION['user_id']
                                ]);
                            } else {
                                $existingCheckStmt = $pdo->prepare("SELECT id FROM attendance WHERE class_id = ? AND student_id = ? AND date = ?");
                                $existingCheckStmt->execute([$class_id, $student_id, $date]);
                                $existing = $existingCheckStmt->fetch();
                                if ($existing) {
                                    $attendance_id = $existing['id'];
                                }
                                
                                $stmt->execute([
                                    $class_id,
                                    $student_id,
                                    $date,
                                    $status,
                                    $_SESSION['user_id']
                                ]);
                            }
                            
                            // Get the attendance ID (either existing or newly inserted)
                            if (!$attendance_id) {
                                $attendance_id = $pdo->lastInsertId();
                            }
                            
                            // Backup attendance record to Firebase
                            try {
                                $attendanceData = [
                                    'id' => $attendance_id,
                                    'class_id' => $class_id,
                                    'student_id' => $student_id,
                                    'date' => $date,
                                    'status' => $status,
                                    'recorded_by' => $_SESSION['user_id'],
                                    'created_at' => date('Y-m-d H:i:s')
                                ];
                                if ($has_timetable_id_column && $session_id) {
                                    $attendanceData['timetable_id'] = $session_id;
                                }
                                $backupHooks->backupAttendanceRecord($attendanceData);
                            } catch (Exception $e) {
                                // Log backup error but don't fail attendance recording
                                error_log("Firebase backup failed for attendance: " . $e->getMessage());
                            }
                        }
                    }
                    $_SESSION['success'] = "Attendance marked successfully";
                } catch(PDOException $e) {
                    $_SESSION['error'] = "Error marking attendance: " . $e->getMessage();
                }
                // Redirect with session_id if provided
                $redirect_url = "manage_attendance.php?class_id=" . urlencode($class_id) . "&date=" . urlencode($date);
                if ($session_id) {
                    $redirect_url .= "&session_id=" . urlencode($session_id);
                }
                header("Location: " . $redirect_url);
                exit();
                
            case 'record_rfid_attendance':
                header('Content-Type: application/json; charset=utf-8');
                
                if (!isset($_POST['class_id']) || !isset($_POST['student_id']) || !isset($_POST['date']) || !isset($_POST['status'])) {
                    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
                    exit;
                }
                
                $class_id = (int)$_POST['class_id'];
                $student_id = (int)$_POST['student_id'];
                $date = $_POST['date'];
                $status = $_POST['status']; // 'present', 'late', etc.
                
                try {
                    // STEP 1: Verify student exists and get student info
                    $studentStmt = $pdo->prepare("
                        SELECT s.id, s.student_id, s.section, s.year_level
                        FROM students s
                        WHERE s.id = ? AND s.is_deleted = 0
                    ");
                    $studentStmt->execute([$student_id]);
                    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$student) {
                        echo json_encode([
                            'success' => false,
                            'message' => 'Student not found.'
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    
                    // STEP 2: Verify class exists and belongs to teacher - get class details
                    $classStmt = $pdo->prepare("SELECT id, section, year_level FROM classes WHERE id = ? AND teacher_id = ?");
                    $classStmt->execute([$class_id, $_SESSION['user_id']]);
                    $classInfo = $classStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$classInfo) {
                        echo json_encode([
                            'success' => false,
                            'message' => 'Class not found or access denied.'
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    
                    // STEP 3: Check if student belongs to the selected class
                    $sectionMatch = ($student['section'] === $classInfo['section']);
                    $yearMatch = ($student['year_level'] == $classInfo['year_level']);
                    
                    if (!$sectionMatch || !$yearMatch) {
                        echo json_encode([
                            'success' => false,
                            'message' => 'This student (Year ' . $student['year_level'] . ' - Section ' . $student['section'] . ') does NOT belong to the selected class (Year ' . $classInfo['year_level'] . ' - Section ' . $classInfo['section'] . ').'
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    
                    // STEP 4: Check if there's an ONGOING schedule for this class today
                    // Get current day name (Monday, Tuesday, etc.) - must match database enum format
                    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                    $currentDay = $days[(int)date('w')];
                    $currentTime = date('H:i:s');
                    
                    // Debug logging
                    error_log("Record Attendance - Day: {$currentDay}, Current Time: {$currentTime}, Class ID: {$class_id}");
                    
                    // Get ALL timetables for this class and day (multiple sessions possible)
                    $timetableStmt = $pdo->prepare("
                        SELECT id, start_time, end_time, day_of_week
                        FROM timetable
                        WHERE class_id = ? AND day_of_week = ?
                        ORDER BY start_time ASC
                    ");
                    $timetableStmt->execute([$class_id, $currentDay]);
                    $schedules = $timetableStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (empty($schedules)) {
                        error_log("No schedule found for Class ID: {$class_id}, Day: {$currentDay}");
                        echo json_encode([
                            'success' => false,
                            'message' => 'No schedule found for today. Attendance can only be recorded during scheduled class time.'
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    
                    // Parse current time once
                    $currentDateTime = DateTime::createFromFormat('H:i:s', $currentTime);
                    if (!$currentDateTime) {
                        $currentDateTime = DateTime::createFromFormat('H:i', $currentTime);
                    }
                    
                    if (!$currentDateTime) {
                        error_log("Error parsing current time: {$currentTime}");
                        echo json_encode([
                            'success' => false,
                            'message' => 'Error parsing current time.'
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    
                    $currentMinutes = (int)$currentDateTime->format('H') * 60 + (int)$currentDateTime->format('i');
                    
                    // Check if current time falls within ANY ONGOING schedule for this day
                    $matchedSchedule = null;
                    $isTooEarly = true;
                    $earliestStartTime = null;
                    $latestEndTime = null;
                    
                    foreach ($schedules as $schedule) {
                        // Parse schedule times
                        $startDateTime = DateTime::createFromFormat('H:i:s', $schedule['start_time']);
                        if (!$startDateTime) {
                            $startDateTime = DateTime::createFromFormat('H:i', $schedule['start_time']);
                        }
                        
                        $endDateTime = DateTime::createFromFormat('H:i:s', $schedule['end_time']);
                        if (!$endDateTime) {
                            $endDateTime = DateTime::createFromFormat('H:i', $schedule['end_time']);
                        }
                        
                        if (!$startDateTime || !$endDateTime) {
                            error_log("Error parsing schedule times - Start: {$schedule['start_time']}, End: {$schedule['end_time']}");
                            continue; // Skip invalid schedule
                        }
                        
                        $startMinutes = (int)$startDateTime->format('H') * 60 + (int)$startDateTime->format('i');
                        $endMinutes = (int)$endDateTime->format('H') * 60 + (int)$endDateTime->format('i');
                        
                        // Track earliest start and latest end for error messages
                        if ($earliestStartTime === null || $startMinutes < $earliestStartTime) {
                            $earliestStartTime = $startMinutes;
                        }
                        if ($latestEndTime === null || $endMinutes > $latestEndTime) {
                            $latestEndTime = $endMinutes;
                        }
                        
                        // Check if current time is within this schedule's window (ONGOING schedule)
                        if ($currentMinutes >= $startMinutes && $currentMinutes <= $endMinutes) {
                            $matchedSchedule = $schedule;
                            $isTooEarly = false;
                            break; // Found an ongoing schedule
                        }
                        
                        // Check if we're before any schedule starts
                        if ($currentMinutes < $startMinutes) {
                            $isTooEarly = true;
                        } else {
                            $isTooEarly = false; // We're past at least one schedule start
                        }
                    }
                    
                    // If no ongoing schedule found, show error
                    if (!$matchedSchedule) {
                        if ($isTooEarly && $earliestStartTime !== null) {
                            // Too early for all schedules
                            $earliestStart = date('g:i A', mktime(0, $earliestStartTime, 0));
                            error_log("NO ONGOING SCHEDULE - Current: {$currentMinutes} min < Earliest Start: {$earliestStartTime} min");
                            echo json_encode([
                                'success' => false,
                                'message' => 'No ongoing schedule. Class starts at ' . $earliestStart . '.'
                            ], JSON_UNESCAPED_UNICODE);
                            exit;
                        } else {
                            // Past all schedules
                            $latestEnd = date('g:i A', mktime(0, $latestEndTime, 0));
                            error_log("NO ONGOING SCHEDULE - Current: {$currentMinutes} min > Latest End: {$latestEndTime} min");
                            echo json_encode([
                                'success' => false,
                                'message' => 'No ongoing schedule. Class ended at ' . $latestEnd . '.'
                            ], JSON_UNESCAPED_UNICODE);
                            exit;
                        }
                    }
                    
                    // Use the matched ongoing schedule for status determination
                    $schedule = $matchedSchedule;
                    $startDateTime = DateTime::createFromFormat('H:i:s', $schedule['start_time']);
                    if (!$startDateTime) {
                        $startDateTime = DateTime::createFromFormat('H:i', $schedule['start_time']);
                    }
                    $startMinutes = (int)$startDateTime->format('H') * 60 + (int)$startDateTime->format('i');
                    
                    // Debug logging
                    error_log("Record Attendance - Matched Ongoing Schedule: Start: {$schedule['start_time']} ({$startMinutes} min), End: {$schedule['end_time']}, Current: {$currentTime} ({$currentMinutes} min)");
                    
                    // Validate status matches the time window (0-15 min = present, 16-30 min = late)
                    $minutesDiff = $currentMinutes - $startMinutes;
                    if ($minutesDiff > 30) {
                        echo json_encode([
                            'success' => false,
                            'message' => 'Too late. Attendance window has closed. You can only scan within 30 minutes after class starts.'
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    
                    // STEP 5: Verify student is enrolled in this class (auto-enroll if not)
                    $checkStmt = $pdo->prepare("
                        SELECT cs.id FROM class_students cs
                        JOIN classes c ON cs.class_id = c.id
                        WHERE cs.class_id = ? AND cs.student_id = ? AND c.teacher_id = ?
                    ");
                    $checkStmt->execute([$class_id, $student_id, $_SESSION['user_id']]);
                    
                    if (!$checkStmt->fetch()) {
                        // Auto-enroll student if not enrolled
                        $enrollStmt = $pdo->prepare("INSERT INTO class_students (class_id, student_id, status) VALUES (?, ?, 'active') ON DUPLICATE KEY UPDATE status = 'active'");
                        $enrollStmt->execute([$class_id, $student_id]);
                    }
                    
                    // Get the matched schedule's timetable_id (session ID) for this attendance record
                    $timetable_id = $matchedSchedule['id'] ?? null;
                    
                    // Check if timetable_id column exists
                    $has_timetable_id_column = false;
                    try {
                        $check_column = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'timetable_id'");
                        $has_timetable_id_column = ($check_column->rowCount() > 0);
                    } catch (Exception $e) {
                        // Column doesn't exist yet
                    }
                    
                    // Check if attendance already exists for this date AND session (timetable_id)
                    // This ensures each session has separate attendance records
                    if ($has_timetable_id_column && $timetable_id) {
                        $existingStmt = $pdo->prepare("SELECT id FROM attendance WHERE class_id = ? AND student_id = ? AND date = ? AND timetable_id = ?");
                        $existingStmt->execute([$class_id, $student_id, $date, $timetable_id]);
                    } else {
                        $existingStmt = $pdo->prepare("SELECT id FROM attendance WHERE class_id = ? AND student_id = ? AND date = ?");
                        $existingStmt->execute([$class_id, $student_id, $date]);
                    }
                    $existing = $existingStmt->fetch();
                    
                    if ($existing) {
                        // Update existing attendance for this specific session only
                        $updateStmt = $pdo->prepare("UPDATE attendance SET status = ?, recorded_by = ? WHERE id = ?");
                        $updateStmt->execute([$status, $_SESSION['user_id'], $existing['id']]);
                        $attendance_id = $existing['id'];
                    } else {
                        // Insert new attendance with timetable_id (session ID)
                        if ($has_timetable_id_column && $timetable_id) {
                            $insertStmt = $pdo->prepare("INSERT INTO attendance (class_id, timetable_id, student_id, date, status, recorded_by) VALUES (?, ?, ?, ?, ?, ?)");
                            $insertStmt->execute([$class_id, $timetable_id, $student_id, $date, $status, $_SESSION['user_id']]);
                        } else {
                            $insertStmt = $pdo->prepare("INSERT INTO attendance (class_id, student_id, date, status, recorded_by) VALUES (?, ?, ?, ?, ?)");
                            $insertStmt->execute([$class_id, $student_id, $date, $status, $_SESSION['user_id']]);
                        }
                        $attendance_id = $pdo->lastInsertId();
                    }
                    
                    // Backup to Firebase (non-blocking - don't fail if backup fails)
                    try {
                        $backupHooks = new BackupHooks();
                        $attendanceData = [
                            'id' => $attendance_id,
                            'class_id' => $class_id,
                            'student_id' => $student_id,
                            'date' => $date,
                            'status' => $status,
                            'recorded_by' => $_SESSION['user_id'],
                            'created_at' => date('Y-m-d H:i:s')
                        ];
                        if ($has_timetable_id_column && $timetable_id) {
                            $attendanceData['timetable_id'] = $timetable_id;
                        }
                        $backupHooks->backupAttendanceRecord($attendanceData);
                    } catch (Exception $e) {
                        // Log error but don't fail the request
                        error_log("Firebase backup failed for RFID attendance: " . $e->getMessage());
                    }
                    
                    // Return success response
                    echo json_encode([
                        'success' => true,
                        'message' => 'Attendance recorded successfully',
                        'attendance_id' => $attendance_id,
                        'status' => $status
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                } catch(PDOException $e) {
                    error_log("Database error in record_rfid_attendance: " . $e->getMessage());
                    echo json_encode([
                        'success' => false, 
                        'message' => 'Database error: ' . $e->getMessage()
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                } catch(Exception $e) {
                    error_log("General error in record_rfid_attendance: " . $e->getMessage());
                    echo json_encode([
                        'success' => false, 
                        'message' => 'Error: ' . $e->getMessage()
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                
            case 'delete_rfid_attendance':
                header('Content-Type: application/json');
                
                if (!isset($_POST['class_id']) || !isset($_POST['student_id']) || !isset($_POST['date'])) {
                    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
                    exit;
                }
                
                $class_id = (int)$_POST['class_id'];
                $student_id = (int)$_POST['student_id'];
                $date = $_POST['date'];
                $session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : null;
                
                try {
                    // Verify class belongs to teacher
                    $classStmt = $pdo->prepare("SELECT id FROM classes WHERE id = ? AND teacher_id = ?");
                    $classStmt->execute([$class_id, $_SESSION['user_id']]);
                    if (!$classStmt->fetch()) {
                        echo json_encode(['success' => false, 'message' => 'Class not found or access denied.']);
                        exit;
                    }
                    
                    // Check if timetable_id column exists
                    $has_timetable_id_column = false;
                    try {
                        $check_column = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'timetable_id'");
                        $has_timetable_id_column = ($check_column->rowCount() > 0);
                    } catch (Exception $e) {
                        // Column doesn't exist yet
                    }
                    
                    // Get attendance record ID before deleting (for Firebase backup)
                    // Filter by session if available
                    if ($has_timetable_id_column && $session_id) {
                        $getStmt = $pdo->prepare("SELECT id FROM attendance WHERE class_id = ? AND student_id = ? AND date = ? AND timetable_id = ?");
                        $getStmt->execute([$class_id, $student_id, $date, $session_id]);
                    } else {
                        $getStmt = $pdo->prepare("SELECT id FROM attendance WHERE class_id = ? AND student_id = ? AND date = ?");
                        $getStmt->execute([$class_id, $student_id, $date]);
                    }
                    $attendanceRecord = $getStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($attendanceRecord) {
                        // Delete attendance record for this specific session only
                        if ($has_timetable_id_column && $session_id) {
                            $deleteStmt = $pdo->prepare("DELETE FROM attendance WHERE class_id = ? AND student_id = ? AND date = ? AND timetable_id = ?");
                            $deleteStmt->execute([$class_id, $student_id, $date, $session_id]);
                        } else {
                            $deleteStmt = $pdo->prepare("DELETE FROM attendance WHERE class_id = ? AND student_id = ? AND date = ?");
                            $deleteStmt->execute([$class_id, $student_id, $date]);
                        }
                        
                        // Backup deletion to Firebase
                        try {
                            $backupHooks = new BackupHooks();
                            $attendanceData = [
                                'id' => $attendanceRecord['id'],
                                'class_id' => $class_id,
                                'student_id' => $student_id,
                                'date' => $date
                            ];
                            $backupHooks->backupGenericRecord('attendance', $attendanceData, 'delete');
                        } catch (Exception $e) {
                            error_log("Firebase backup failed for attendance deletion: " . $e->getMessage());
                        }
                    }
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Attendance record removed successfully'
                    ]);
                } catch(PDOException $e) {
                    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
                }
                    exit;
                    
            case 'reset_attendance':
                if (!isset($_POST['class_id']) || !isset($_POST['date'])) {
                    $_SESSION['error'] = "Missing required parameters";
                    header("Location: manage_attendance.php?class_id=" . urlencode($_POST['class_id'] ?? $selected_class) . "&date=" . urlencode($_POST['date'] ?? $selected_date));
                    exit;
                }
                
                $class_id = (int)$_POST['class_id'];
                $date = $_POST['date'];
                $session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : null;
                
                try {
                    // Verify class belongs to teacher
                    $classStmt = $pdo->prepare("SELECT id FROM classes WHERE id = ? AND teacher_id = ?");
                    $classStmt->execute([$class_id, $_SESSION['user_id']]);
                    if (!$classStmt->fetch()) {
                        $_SESSION['error'] = "Class not found or access denied.";
                        header("Location: manage_attendance.php?class_id=" . urlencode($class_id) . "&date=" . urlencode($date));
                        exit;
                    }
                    
                    // Validate and normalize date to ensure exact matching
                    $normalizedDate = normalizeDateString($date);
                    if (!$normalizedDate) {
                        $_SESSION['error'] = "Invalid date format.";
                        header("Location: manage_attendance.php?class_id=" . urlencode($class_id) . "&date=" . urlencode($date));
                        exit;
                    }
                    
                    // Check if timetable_id column exists
                    $has_timetable_id_column = false;
                    try {
                        $check_column = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'timetable_id'");
                        $has_timetable_id_column = ($check_column->rowCount() > 0);
                    } catch (Exception $e) {
                        // Column doesn't exist yet
                    }
                    
                    // Get all attendance records for this EXACT date, class, and session before deleting (for Firebase backup)
                    if ($has_timetable_id_column && $session_id) {
                        // Reset only the selected session's attendance
                        $getStmt = $pdo->prepare("SELECT id, class_id, student_id, date, timetable_id FROM attendance WHERE class_id = ? AND date = ? AND timetable_id = ?");
                        $getStmt->execute([$class_id, $normalizedDate, $session_id]);
                        $attendanceRecords = $getStmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        // Delete only records for this specific session
                        $deleteStmt = $pdo->prepare("DELETE FROM attendance WHERE class_id = ? AND date = ? AND timetable_id = ?");
                        $deleteStmt->execute([$class_id, $normalizedDate, $session_id]);
                    } else {
                        // Fallback: delete all attendance for this date and class (old behavior)
                        $getStmt = $pdo->prepare("SELECT id, class_id, student_id, date FROM attendance WHERE class_id = ? AND date = ?");
                        $getStmt->execute([$class_id, $normalizedDate]);
                        $attendanceRecords = $getStmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        $deleteStmt = $pdo->prepare("DELETE FROM attendance WHERE class_id = ? AND date = ?");
                        $deleteStmt->execute([$class_id, $normalizedDate]);
                    }
                    $deletedCount = $deleteStmt->rowCount();
                    
                    // Backup deletions to Firebase
                    if (!empty($attendanceRecords)) {
                        try {
                            $backupHooks = new BackupHooks();
                            foreach ($attendanceRecords as $record) {
                                $attendanceData = [
                                    'id' => $record['id'],
                                    'class_id' => $record['class_id'],
                                    'student_id' => $record['student_id'],
                                    'date' => $record['date']
                                ];
                                if (isset($record['timetable_id'])) {
                                    $attendanceData['timetable_id'] = $record['timetable_id'];
                                }
                                $backupHooks->backupGenericRecord('attendance', $attendanceData, 'delete');
                            }
                        } catch (Exception $e) {
                            error_log("Firebase backup failed for attendance reset: " . $e->getMessage());
                        }
                    }
                    
                    // Clear Firebase records for this EXACT class/date/session
                    // IMPORTANT: Delete from Firebase AFTER MySQL deletion to ensure consistency
                    if ($has_timetable_id_column && $session_id) {
                        error_log("Resetting attendance - Deleting Firebase records for class {$class_id}, date {$normalizedDate}, session {$session_id}");
                        deleteFirebaseAttendanceForClassDateSession($class_id, $normalizedDate, $session_id);
                    } else {
                        error_log("Resetting attendance - Deleting Firebase records for class {$class_id}, date {$normalizedDate} (no session)");
                        deleteFirebaseAttendanceForClassDate($class_id, $normalizedDate);
                    }
                    
                    $sessionInfo = ($has_timetable_id_column && $session_id) ? " for the selected session" : "";
                    $_SESSION['success'] = "Successfully reset attendance{$sessionInfo} for " . date('F d, Y', strtotime($normalizedDate)) . ". Deleted {$deletedCount} record(s).";
                } catch(PDOException $e) {
                    $_SESSION['error'] = "Error resetting attendance: " . $e->getMessage();
                }
                
                // Stay on the selected date and session after reset
                $redirect_url = "manage_attendance.php?class_id=" . urlencode($class_id) . "&date=" . urlencode($normalizedDate);
                if ($session_id) {
                    $redirect_url .= "&session_id=" . urlencode($session_id);
                }
                header("Location: " . $redirect_url);
                exit;
                break;
                
            case 'sync_firebase_attendance':
                header('Content-Type: application/json; charset=utf-8');
                
                if (!isset($_POST['class_id']) || !isset($_POST['student_id']) || !isset($_POST['date']) || !isset($_POST['status'])) {
                    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
                    exit;
                }
                
                $class_id = (int)$_POST['class_id'];
                $student_id = (int)$_POST['student_id'];
                $date = $_POST['date'];
                $status = $_POST['status'];
                $recorded_by = isset($_POST['recorded_by']) ? (int)$_POST['recorded_by'] : $_SESSION['user_id'];
                $created_at = isset($_POST['created_at']) ? $_POST['created_at'] : date('Y-m-d H:i:s');
                $timetable_id = isset($_POST['timetable_id']) ? (int)$_POST['timetable_id'] : null;
                
                try {
                    // Verify class belongs to teacher
                    $classStmt = $pdo->prepare("SELECT id FROM classes WHERE id = ? AND teacher_id = ?");
                    $classStmt->execute([$class_id, $_SESSION['user_id']]);
                    if (!$classStmt->fetch()) {
                        echo json_encode(['success' => false, 'message' => 'Class not found or access denied.']);
                        exit;
                    }
                    
                    // Check if timetable_id column exists
                    $has_timetable_id_column = false;
                    try {
                        $check_column = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'timetable_id'");
                        $has_timetable_id_column = ($check_column->rowCount() > 0);
                    } catch (Exception $e) {
                        // Column doesn't exist yet
                    }
                    
                    // Check if attendance already exists for this date AND session
                    if ($has_timetable_id_column && $timetable_id) {
                        $checkStmt = $pdo->prepare("SELECT id FROM attendance WHERE class_id = ? AND student_id = ? AND date = ? AND timetable_id = ?");
                        $checkStmt->execute([$class_id, $student_id, $date, $timetable_id]);
                    } else {
                        $checkStmt = $pdo->prepare("SELECT id FROM attendance WHERE class_id = ? AND student_id = ? AND date = ?");
                        $checkStmt->execute([$class_id, $student_id, $date]);
                    }
                    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing) {
                        // Update existing attendance for this specific session only
                        $updateStmt = $pdo->prepare("UPDATE attendance SET status = ?, recorded_by = ? WHERE id = ?");
                        $updateStmt->execute([$status, $recorded_by, $existing['id']]);
                        echo json_encode(['success' => true, 'message' => 'Attendance updated', 'attendance_id' => $existing['id']]);
                    } else {
                        // Insert new attendance with timetable_id (session ID)
                        if ($has_timetable_id_column && $timetable_id) {
                            $insertStmt = $pdo->prepare("INSERT INTO attendance (class_id, timetable_id, student_id, date, status, recorded_by) VALUES (?, ?, ?, ?, ?, ?)");
                            $insertStmt->execute([$class_id, $timetable_id, $student_id, $date, $status, $recorded_by]);
                        } else {
                            $insertStmt = $pdo->prepare("INSERT INTO attendance (class_id, student_id, date, status, recorded_by) VALUES (?, ?, ?, ?, ?)");
                            $insertStmt->execute([$class_id, $student_id, $date, $status, $recorded_by]);
                        }
                        $attendance_id = $pdo->lastInsertId();
                        echo json_encode(['success' => true, 'message' => 'Attendance synced', 'attendance_id' => $attendance_id]);
                    }
                } catch(PDOException $e) {
                    error_log("Error syncing Firebase attendance: " . $e->getMessage());
                    echo json_encode(['success' => false, 'message' => 'Error syncing attendance: ' . $e->getMessage()]);
                }
                exit();
                break;
                
            default:
                $_SESSION['error'] = "Unknown action";
        }
    }
    
    // Only redirect if it's not an AJAX request (check for JSON content type)
    // Preserve GET parameters when redirecting
    if (!isset($_SERVER['HTTP_ACCEPT']) || strpos($_SERVER['HTTP_ACCEPT'], 'application/json') === false) {
        // Preserve class_id and date from GET or POST
        $preserve_class_id = isset($_GET['class_id']) ? $_GET['class_id'] : (isset($_POST['class_id']) ? $_POST['class_id'] : '');
        $preserve_date = isset($_GET['date']) ? $_GET['date'] : (isset($_POST['date']) ? $_POST['date'] : date('Y-m-d'));
        
        $redirect_url = 'manage_attendance.php';
        $params = [];
        if ($preserve_class_id) $params[] = 'class_id=' . urlencode($preserve_class_id);
        if ($preserve_date) $params[] = 'date=' . urlencode($preserve_date);
        if (!empty($params)) {
            $redirect_url .= '?' . implode('&', $params);
        }
        
        header("Location: " . $redirect_url);
        exit();
    }
}

// Debug information
error_log("Current date: " . $today);
error_log("Selected date: " . $selected_date);

// If a future date is selected, force it back to today
if ($selected_date > $today) {
    $selected_date = $today;
    // Redirect to ensure the date is updated in the URL
    header("Location: manage_attendance.php?class_id=" . urlencode($selected_class) . "&date=" . $today);
    exit();
}

// Get search term from GET
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Term filter logic
$term = isset($_GET['term']) ? $_GET['term'] : '';

// Get term ranges from semester settings
$term_ranges = [];
if ($current_semester) {
$term_ranges = [
        'prelim' => [
            'start' => $current_semester['prelim_start'],
            'end' => $current_semester['prelim_end']
        ],
        'midterm' => [
            'start' => $current_semester['midterm_start'],
            'end' => $current_semester['midterm_end']
        ],
        'final' => [
            'start' => $current_semester['final_start'],
            'end' => $current_semester['final_end']
        ]
    ];
}

$filter_start = '';
$filter_end = '';
if (isset($term_ranges[$term])) {
    $filter_start = $term_ranges[$term]['start'];
    $filter_end = $term_ranges[$term]['end'];
}

// Get the semester/term for the selected date
$active_semester = null;
$semester_stmt = $pdo->prepare("
    SELECT *, 
    CASE 
        WHEN ? BETWEEN prelim_start AND prelim_end THEN 'Prelim'
        WHEN ? BETWEEN midterm_start AND midterm_end THEN 'Midterm'
        WHEN ? BETWEEN final_start AND final_end THEN 'Final'
        ELSE NULL
    END as current_term
    FROM semester_settings 
    WHERE ? BETWEEN start_date AND end_date 
    LIMIT 1
");
$semester_stmt->execute([$selected_date, $selected_date, $selected_date, $selected_date]);
$active_semester = $semester_stmt->fetch();

// Get students for selected class
$students = [];
$attendance_summary = [
    'present' => 0,
    'absent' => 0,
    'late' => 0,
    'excused' => 0
];
if ($selected_class) {
    // Get section and year_level of the selected class - use same logic as manage_students.php
    $stmt = $pdo->prepare("SELECT c.section, c.year_level FROM classes c WHERE c.id = ?");
    $stmt->execute([$selected_class]);
    $classInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    $selected_section = $classInfo['section'] ?? '';
    $selected_year_level = $classInfo['year_level'] ?? '';
    if ($selected_section && $selected_year_level) {
        // Use same filtering logic as manage_students.php - exclude dropped/deleted students
        $stmt = $pdo->prepare("
            SELECT *
            FROM students
            WHERE section = ? 
            AND year_level = ? 
            AND status NOT IN ('graduated', 'promoted')
            AND (is_deleted = 0 OR is_deleted IS NULL)
            ORDER BY last_name, first_name
        ");
        $stmt->execute([$selected_section, $selected_year_level]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Debug: Log the number of students found
    error_log("Number of students found: " . count($students));
    
    // Debug: Check if the class exists
    $check_class = $pdo->prepare("SELECT * FROM classes WHERE id = ?");
    $check_class->execute([$selected_class]);
    $class_info = $check_class->fetch(PDO::FETCH_ASSOC);
    error_log("Class info: " . print_r($class_info, true));
    
    // Debug: Check class_students entries
    $check_enrollments = $pdo->prepare("SELECT COUNT(*) FROM class_students WHERE class_id = ?");
    $check_enrollments->execute([$selected_class]);
    $enrollment_count = $check_enrollments->fetchColumn();
    error_log("Number of enrollments in class: " . $enrollment_count);

    // Get all sessions (timetable entries) for the selected class and day
    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $selected_day_of_week = $days[(int)date('w', strtotime($selected_date))];
    
    $sessions_stmt = $pdo->prepare("
        SELECT id, start_time, end_time, room
        FROM timetable
        WHERE class_id = ? AND day_of_week = ?
        ORDER BY start_time ASC
    ");
    $sessions_stmt->execute([$selected_class, $selected_day_of_week]);
    $available_sessions = $sessions_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get selected session from GET parameter
    $selected_session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : null;
    
    // If multiple sessions exist but none selected, select the first one
    // IMPORTANT: Always select a session if multiple sessions exist to ensure strict filtering
    if (empty($selected_session_id) && !empty($available_sessions)) {
        $selected_session_id = $available_sessions[0]['id'];
    }
    
    // Get existing attendance for selected date, class, and session (if session selected)
    // If timetable_id column doesn't exist yet, fallback to old query
    $has_timetable_id_column = false;
    try {
        $check_column = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'timetable_id'");
        $has_timetable_id_column = ($check_column->rowCount() > 0);
    } catch (Exception $e) {
        // Column doesn't exist yet
    }
    
    // CRITICAL: STRICT FILTERING - Only show attendance records for the SPECIFIC session
    // This ensures records from one session don't appear in another session
    // OLD records with NULL timetable_id must be EXCLUDED when a session is selected
    $existing_attendance = [];
    
    // If timetable_id column exists and we have sessions, we MUST filter by session
    if ($has_timetable_id_column && !empty($available_sessions)) {
        // If a session is selected, ONLY show records for that exact session
        // IMPORTANT: Exclude NULL timetable_id records (old records without session)
        if ($selected_session_id) {
            $attendance_query = "SELECT student_id, status, date, timetable_id 
                                 FROM attendance 
                                 WHERE class_id = ? 
                                 AND date = ? 
                                 AND timetable_id = ? 
                                 AND timetable_id IS NOT NULL";
            $stmt = $pdo->prepare($attendance_query);
            $stmt->execute([$selected_class, $selected_date, $selected_session_id]);
            
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                // Double-check: Only include records with matching timetable_id (not NULL)
                if ($row['date'] == $selected_date && 
                    isset($row['timetable_id']) && 
                    !is_null($row['timetable_id']) &&
                    (int)$row['timetable_id'] === (int)$selected_session_id) {
                    $existing_attendance[$row['student_id']] = $row['status'];
                }
            }
        } else {
            // No session selected but sessions exist - show NOTHING (wait for session selection)
            // This prevents mixing records from different sessions or showing NULL records
            $existing_attendance = [];
        }
    } else {
        // Old behavior: no timetable_id column yet - show all attendance for date
        $attendance_query = "SELECT student_id, status, date FROM attendance WHERE class_id = ? AND date = ?";
        $stmt = $pdo->prepare($attendance_query);
        $stmt->execute([$selected_class, $selected_date]);
        
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['date'] == $selected_date) {
                $existing_attendance[$row['student_id']] = $row['status'];
            }
        }
    }

    // Calculate attendance summary for the selected date and session only (per session)
    // Only count records for students who are currently in the class (to avoid counting duplicates or old records)
    if (!empty($selected_section) && !empty($selected_year_level)) {
        if ($has_timetable_id_column && $selected_session_id) {
            // Filter by session - EXCLUDE NULL timetable_id records (old records)
            $summary_query = "
                SELECT a.status, COUNT(DISTINCT a.student_id) as count 
                FROM attendance a
                INNER JOIN students s ON a.student_id = s.id
                WHERE a.class_id = ? 
                AND a.date = ? 
                AND a.timetable_id = ?
                AND a.timetable_id IS NOT NULL
                AND s.section = ?
                AND s.year_level = ?
                AND (s.is_deleted = 0 OR s.is_deleted IS NULL)
                AND s.status NOT IN ('graduated', 'promoted')
                GROUP BY a.status
            ";
            $stmt = $pdo->prepare($summary_query);
            $stmt->execute([$selected_class, $selected_date, $selected_session_id, $selected_section, $selected_year_level]);
        } else {
            // Fallback: filter by date only (old behavior)
            $summary_query = "
                SELECT a.status, COUNT(DISTINCT a.student_id) as count 
                FROM attendance a
                INNER JOIN students s ON a.student_id = s.id
                WHERE a.class_id = ? 
                AND a.date = ? 
                AND s.section = ?
                AND s.year_level = ?
                AND (s.is_deleted = 0 OR s.is_deleted IS NULL)
                AND s.status NOT IN ('graduated', 'promoted')
                GROUP BY a.status
            ";
            $stmt = $pdo->prepare($summary_query);
            $stmt->execute([$selected_class, $selected_date, $selected_section, $selected_year_level]);
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $attendance_summary[$row['status']] = (int)$row['count'];
        }
    } else {
        // Fallback: if section/year_level not available, use simple count but with DISTINCT to avoid duplicates
        if ($has_timetable_id_column && $selected_session_id) {
            // EXCLUDE NULL timetable_id records (old records without session)
            $summary_query = "
                SELECT a.status, COUNT(DISTINCT a.student_id) as count 
                FROM attendance a
                WHERE a.class_id = ? 
                AND a.date = ? 
                AND a.timetable_id = ?
                AND a.timetable_id IS NOT NULL
                GROUP BY a.status
            ";
            $stmt = $pdo->prepare($summary_query);
            $stmt->execute([$selected_class, $selected_date, $selected_session_id]);
        } else {
            $summary_query = "
                SELECT a.status, COUNT(DISTINCT a.student_id) as count 
                FROM attendance a
                WHERE a.class_id = ? 
                AND a.date = ? 
                GROUP BY a.status
            ";
            $stmt = $pdo->prepare($summary_query);
            $stmt->execute([$selected_class, $selected_date]);
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $attendance_summary[$row['status']] = (int)$row['count'];
        }
    }

    // Fallback: if no attendance found in MySQL, attempt to pull from Firebase backup
    // ONLY if we have NO records for the selected date (don't mix Firebase with MySQL)
    // CRITICAL: Must filter by session to prevent showing records from other sessions
    if (empty($existing_attendance)) {
        try {
            $firebaseConfig = require '../config/firebase.php';
            $firebaseUrl = isset($firebaseConfig['database_url']) ? rtrim($firebaseConfig['database_url'], '/') : '';
            if ($firebaseUrl) {
                $firebaseAttendanceUrl = $firebaseUrl . '/attendance_system/attendance.json';
                $firebaseResponse = @file_get_contents($firebaseAttendanceUrl);
                if ($firebaseResponse !== false) {
                    $firebaseRecords = json_decode($firebaseResponse, true);
                    if (is_array($firebaseRecords)) {
                        foreach ($firebaseRecords as $key => $record) {
                            $data = null;
                            if (isset($record['data']) && is_array($record['data'])) {
                                $data = $record['data'];
                            } elseif (is_array($record)) {
                                $data = $record;
                            }
                            if (!$data) {
                                continue;
                            }
                            
                            $recordClassId = isset($data['class_id']) ? (string)$data['class_id'] : (isset($data['classId']) ? (string)$data['classId'] : '');
                            $recordStudentId = isset($data['student_id']) ? (string)$data['student_id'] : (isset($data['studentId']) ? (string)$data['studentId'] : '');
                            $recordDate = normalizeDateString($data['date'] ?? '');
                            $recordStatus = isset($data['status']) ? strtolower((string)$data['status']) : '';
                            $recordTimetableId = isset($data['timetable_id']) ? (string)$data['timetable_id'] : null;
                            
                            // Strict validation - must have all required fields
                            if (!$recordClassId || !$recordStudentId || !$recordDate || !$recordStatus) {
                                continue;
                            }
                            
                            // STRICT date and class matching - must match EXACTLY
                            if ($recordClassId !== (string)$selected_class || $recordDate !== $selected_date) {
                                continue; // Not for this class or date
                            }
                            
                            // CRITICAL: Session filtering - only show records for the selected session
                            if ($has_timetable_id_column && !empty($available_sessions)) {
                                if ($selected_session_id) {
                                    // Session selected - ONLY show records with matching timetable_id
                                    if (!$recordTimetableId || $recordTimetableId === 'null' || $recordTimetableId === '') {
                                        continue; // Skip old records without session
                                    }
                                    // Must match session ID exactly
                                    if ((int)$recordTimetableId !== (int)$selected_session_id) {
                                        continue; // Skip records from other sessions
                                    }
                                } else {
                                    // No session selected but sessions exist - skip session-specific records
                                    if ($recordTimetableId && $recordTimetableId !== 'null' && $recordTimetableId !== '') {
                                        continue; // Skip session-specific records when no session selected
                                    }
                                }
                            }
                            
                            // Also check key format for session ID (backup check)
                            if (strpos($key, 'attendance_') === 0) {
                                $keyParts = explode('_', $key);
                                if (count($keyParts) >= 5 && $has_timetable_id_column && !empty($available_sessions)) {
                                    $keySessionId = $keyParts[4] ?? null;
                                    if ($selected_session_id && $keySessionId) {
                                        // Must match session ID in key
                                        if ((int)$keySessionId !== (int)$selected_session_id) {
                                            continue; // Skip records with different session in key
                                        }
                                    }
                                }
                            }
                            
                            // Convert student_id to database ID if needed (Firebase might store formatted ID)
                            // Try to match by database ID first
                            $dbStudentId = null;
                            foreach ($students as $student) {
                                if ((string)$student['id'] === (string)$recordStudentId || 
                                    (string)$student['student_id'] === (string)$recordStudentId) {
                                    $dbStudentId = $student['id'];
                                    break;
                                }
                            }
                            
                            if ($dbStudentId && !isset($existing_attendance[$dbStudentId])) {
                                $existing_attendance[$dbStudentId] = $recordStatus;
                                if (isset($attendance_summary[$recordStatus])) {
                                    $attendance_summary[$recordStatus]++;
                                }
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Firebase attendance fallback error: ' . $e->getMessage());
        }
    }
}

// Get first letter of first and last name for avatar
$fullName = $_SESSION['full_name'] ?? $_SESSION['username'];
$initials = '';
$nameParts = explode(' ', $fullName);
if (count($nameParts) >= 2) {
    $initials = strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[count($nameParts) - 1], 0, 1));
} else {
    $initials = strtoupper(substr($fullName, 0, 2));
}

// Check if attendance exists for the selected class and date
$is_update = false;
if ($selected_class && $selected_date) {
    // Check if attendance exists for the selected session only
    // EXCLUDE NULL timetable_id records (old records without session)
    if ($has_timetable_id_column && $selected_session_id) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE class_id = ? AND date = ? AND timetable_id = ? AND timetable_id IS NOT NULL");
        $stmt->execute([$selected_class, $selected_date, $selected_session_id]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE class_id = ? AND date = ?");
        $stmt->execute([$selected_class, $selected_date]);
    }
    $is_update = $stmt->fetchColumn() > 0;
}

// Prepare lists of students by status for modals
$status_lists = [
    'present' => [],
    'absent' => [],
    'late' => [],
    'excused' => []
];
foreach ($students as $student) {
    $sid = $student['id'];
    if (isset($existing_attendance[$sid])) {
        $status = $existing_attendance[$sid];
        $status_lists[$status][] = $student;
    }
}

$shortName = '';
if (!empty($user['first_name']) && !empty($user['last_name'])) {
    $shortName = strtoupper(substr(trim($user['first_name']), 0, 1)) . '.' . ucfirst(strtolower(trim($user['last_name'])));
} else {
    $shortName = htmlspecialchars($user['full_name'] ?? '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Attendance - Attendance Management System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <style>
        .user-avatar {
            width: 40px;
            height: 40px;
            background: #e9ecef;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: #495057;
            overflow: hidden;
        }
        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }
        .sidebar-unread-badge {
            background: #dc3545;
            color: #fff;
            border-radius: 50%;
            font-size: 0.85em;
            font-weight: bold;
            padding: 2px 8px;
            margin-left: 8px;
            box-shadow: 0 2px 8px rgba(220,53,69,0.15);
            display: inline-block;
            vertical-align: middle;
            animation: pulseUnread 1.2s infinite alternate;
            position: relative;
            top: -2px;
        }
        @keyframes pulseUnread {
            0% { box-shadow: 0 0 0 0 rgba(220,53,69,0.4); }
            100% { box-shadow: 0 0 0 8px rgba(220,53,69,0.0); }
        }
        .sidebar .nav-link.active,
        .sidebar .nav-link[aria-expanded="true"] {
            background: #7da6fa !important;
            color: #fff !important;
            border-radius: 10px;
            font-weight: 600;
        }
        .sidebar .collapse,
        .sidebar .collapse.show {
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            margin-top: 0;
            padding-top: 0;
        }
        .sidebar .collapse .nav-link {
            color: #fff;
            font-size: 1.08rem;
            border-radius: 8px;
            margin-bottom: 0.3rem;
            padding-left: 2.5rem;
            transition: background 0.2s, color 0.2s;
        }
        .sidebar .collapse .nav-link.active,
        .sidebar .collapse .nav-link:hover {
            background: rgba(255,255,255,0.12);
            color: #fff;
        }
        .sidebar .nav-link[aria-expanded="true"] .bi-chevron-down {
            transform: rotate(180deg);
            transition: transform 0.2s;
        }
        .sidebar .nav-link .bi-chevron-down {
            transition: transform 0.2s;
        }
        /* Force Start Scanner button to be BLUE - override ANY purple/violet colors */
        /* Multiple selectors to ensure highest specificity */
        #startRfidScannerBtn,
        button#startRfidScannerBtn,
        .btn#startRfidScannerBtn,
        .btn-primary#startRfidScannerBtn,
        .btn.btn-primary#startRfidScannerBtn,
        .btn-sm#startRfidScannerBtn {
            background-color: #2196F3 !important;
            border-color: #2196F3 !important;
            color: #FFFFFF !important;
            background: #2196F3 !important;
        }
        #startRfidScannerBtn:hover,
        #startRfidScannerBtn:focus,
        #startRfidScannerBtn:active,
        button#startRfidScannerBtn:hover,
        .btn#startRfidScannerBtn:hover {
            background-color: #1976D2 !important;
            border-color: #1976D2 !important;
            color: #FFFFFF !important;
            background: #1976D2 !important;
        }
        #startRfidScannerBtn.btn-primary,
        .btn-primary#startRfidScannerBtn {
            background-color: #2196F3 !important;
            border-color: #2196F3 !important;
            color: #FFFFFF !important;
            background: #2196F3 !important;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <a href="dashboard.php" class="sidebar-brand">
                <i class="bi bi-calendar2-check"></i>
                <span class="sidebar-brand-text">iAttendance</span>
            </a>
        </div>
        
        <hr class="sidebar-divider">
        
        <div class="sidebar-heading">Main</div>
        
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" href="dashboard.php">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="manage_students.php">
                    <i class="bi bi-people"></i>
                    <span>Students</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="manage_attendance.php">
                    <i class="bi bi-calendar-check"></i>
                    <span>Attendance</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="manage_timetable.php">
                    <i class="bi bi-clock"></i>
                    <span>Timetable</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="reports.php">
                    <i class="bi bi-bar-chart"></i>
                    <span>Reports</span>
                </a>
            </li>
            
        </ul>
        
        <hr class="sidebar-divider">
        
        <div class="sidebar-heading">Account</div>
        
                <ul class="navbar-nav">
                    <li class="nav-item">
                <a class="nav-link" href="profile.php">
                    <i class="bi bi-person"></i>
                    <span>Profile</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="../auth/logout.php">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
                    </li>
        </ul>
    </aside>

    <!-- Main Content -->
    <main class="page-content">
        <!-- Topbar -->
        <div class="topbar">
            <button class="toggle-sidebar">
                <i class="bi bi-list"></i>
            </button>
            
            <div class="user-info dropdown">
                <a href="#" class="user-dropdown-toggle" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="user-avatar">
                        <?php if (!empty($user['avatar']) && file_exists('../' . $user['avatar'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['avatar']); ?>" alt="Profile Avatar" class="avatar-image">
                        <?php else: ?>
                            <?php echo isset($_SESSION['initials']) ? $_SESSION['initials'] : 'ME'; ?>
                        <?php endif; ?>
                    </div>
                    <span class="user-name ms-2" style="font-weight:600; font-size:1.1em; white-space:nowrap; overflow:visible; text-overflow:unset; max-width:none;">
                        <?php echo $shortName; ?>
                    </span>
                </a>
                <ul class="dropdown-menu user-dropdown" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i> Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="../auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>

        <!-- Page Content -->
        <div class="container-fluid animate-fadeIn">
            <div class="d-sm-flex align-items-center justify-content-between mb-4">
                <h1 class="h3 mb-0 text-gray-800">Manage Attendance</h1>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                <?php 
                echo $_SESSION['success'];
                unset($_SESSION['success']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?php 
                echo $_SESSION['error'];
                unset($_SESSION['error']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

            <div class="card shadow mb-4 animate-fadeIn delay-1">
                <div class="card-header py-3">
                    <div class="d-flex justify-content-between align-items-center w-100">
                        <!-- Left: Class Selector -->
                        <div class="d-flex align-items-center">
                            <form method="get" class="d-flex align-items-center" style="min-width: 250px;">
                                <label for="class_id" class="me-2 fw-bold text-secondary" style="white-space: nowrap;">My Class:</label>
                                <select class="form-select form-select-sm" id="class_id" name="class_id" onchange="this.form.submit()" style="min-width: 160px; max-width: 220px;">
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?= $class['id'] ?>" title="<?= htmlspecialchars($class['class_desc'] ?? $class['class_name']) ?>"
                                            <?= $class['id'] == $selected_class ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($class['class_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="date" value="<?php echo $selected_date; ?>">
                            </form>
                        </div>
                        <!-- Center: Semester Indicator -->
                        <div class="d-flex flex-column align-items-center">
                            <?php if ($current_semester): ?>
                                <span class="badge bg-secondary mb-1" style="height:32px;display:flex;align-items:center;">
                                    <?php echo htmlspecialchars($current_semester['semester']); ?>
                                    <?php if ($current_term): ?>
                                        | <span class="badge bg-info ms-1"><?php echo htmlspecialchars($current_term); ?></span>
                                    <?php endif; ?>
                                </span>
                                <span class="badge bg-light text-dark border" style="height:32px;display:flex;align-items:center;">
                                    <?php echo date('M d, Y', strtotime($current_semester['start_date'])); ?> -
                                    <?php echo date('M d, Y', strtotime($current_semester['end_date'])); ?>
                                </span>
                        <?php endif; ?>
                        </div>
                        <!-- Right: Date Picker and Session Selector -->
                        <div class="d-flex align-items-center gap-2">
                        <form method="GET" class="d-flex align-items-center" id="dateForm">
                            <label for="date" class="me-2">Date:</label>
                            <input type="date" class="form-control form-control-sm" id="date" name="date" 
                                value="<?php echo $selected_date; ?>" 
                                min="<?php echo $current_semester ? $current_semester['start_date'] : ''; ?>"
                                max="<?php echo ($current_semester && $today < $current_semester['end_date']) ? $today : $current_semester['end_date']; ?>">
                            <input type="hidden" name="class_id" value="<?php echo $selected_class; ?>">
                            <?php if ($selected_session_id): ?>
                                <input type="hidden" name="session_id" value="<?php echo $selected_session_id; ?>">
                            <?php endif; ?>
                            <button type="button" class="btn btn-primary btn-sm ms-2" id="todayBtn" <?php if (!$current_semester || $today < $current_semester['start_date'] || $today > $current_semester['end_date']) echo 'disabled'; ?>>Today</button>
                        </form>
                        
                        <?php if ($selected_class && !empty($available_sessions)): ?>
                        <form method="GET" class="d-flex align-items-center" id="sessionForm">
                            <label for="session_id" class="me-2">Session:</label>
                            <select class="form-select form-select-sm" id="session_id" name="session_id" onchange="this.form.submit()" style="min-width: 180px;">
                                <?php foreach ($available_sessions as $session): ?>
                                    <option value="<?php echo $session['id']; ?>" 
                                        <?php echo ($selected_session_id == $session['id']) ? 'selected' : ''; ?>>
                                        <?php echo date('g:i A', strtotime($session['start_time'])); ?> - <?php echo date('g:i A', strtotime($session['end_time'])); ?>
                                        <?php if ($session['room']): ?>
                                            (Room: <?php echo htmlspecialchars($session['room']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="class_id" value="<?php echo $selected_class; ?>">
                            <input type="hidden" name="date" value="<?php echo $selected_date; ?>">
                        </form>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="card-body">
        <?php if ($selected_class && !empty($students)): ?>
        <!-- Search and Summary Row -->
        <div class="mb-3 position-relative">
            <form method="get" class="" style="max-width: 350px;">
                <input type="hidden" name="class_id" value="<?php echo $selected_class; ?>">
                <input type="hidden" name="date" value="<?php echo $selected_date; ?>">
                <input type="text" class="form-control rounded" name="search" placeholder="Search by name or student ID..." value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
            </form>
            <div class="d-flex gap-2 flex-wrap position-absolute top-0 end-0">
                <button type="button" class="badge bg-success border-0" data-bs-toggle="modal" data-bs-target="#presentModal" style="cursor:pointer;" id="presentBadge">Present: <span id="presentCount"><?php echo $attendance_summary['present']; ?></span></button>
                <button type="button" class="badge bg-danger border-0" data-bs-toggle="modal" data-bs-target="#absentModal" style="cursor:pointer;" id="absentBadge">Absent: <span id="absentCount"><?php echo $attendance_summary['absent']; ?></span></button>
                <button type="button" class="badge bg-warning text-dark border-0" data-bs-toggle="modal" data-bs-target="#lateModal" style="cursor:pointer;" id="lateBadge">Late: <span id="lateCount"><?php echo $attendance_summary['late']; ?></span></button>
                <button type="button" class="badge bg-info text-dark border-0" data-bs-toggle="modal" data-bs-target="#excusedModal" style="cursor:pointer;" id="excusedBadge">Excused: <span id="excusedCount"><?php echo $attendance_summary['excused']; ?></span></button>
            </div>
        </div>
        <!-- End Search and Summary Row -->
        
        <!-- Take Attendance via RFID Button - Under search bar -->
        <div class="mb-3 d-flex gap-2">
            <button type="button" class="btn btn-success" 
                    data-bs-toggle="modal" 
                    data-bs-target="#rfidAttendanceModal" 
                    id="rfidAttendanceBtn"
                    <?php if (!$selected_class): ?>disabled title="Please select a class first"<?php endif; ?>>
                <i class="bi bi-upc-scan me-1"></i> Take Attendance via RFID
            </button>
            <button type="button" class="btn btn-danger" 
                    data-bs-toggle="modal" 
                    data-bs-target="#resetAttendanceModal"
                    id="resetAttendanceBtn"
                    <?php if (!$selected_class): ?>disabled title="Please select a class first"<?php endif; ?>>
                <i class="bi bi-arrow-counterclockwise me-1"></i> Reset Attendance
            </button>
        </div>

        <!-- Attendance Form (POST) -->
        <form action="manage_attendance.php" method="POST" id="attendanceForm">
            <input type="hidden" name="action" value="mark_attendance">
            <input type="hidden" name="class_id" value="<?php echo $selected_class; ?>">
            <input type="hidden" name="date" value="<?php echo $selected_date; ?>">
            <?php if (!empty($available_sessions) && $selected_session_id): ?>
                <!-- CRITICAL: Always include session_id when sessions exist to ensure strict session separation -->
                <input type="hidden" name="session_id" value="<?php echo $selected_session_id; ?>">
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Course</th>
                            <th>Year & Section</th>
                            <th>Attendance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(preg_replace('/^(\\d{4})(\\d+)$/', '$1-$2', $student['student_id'])); ?></td>
                            <td>
                                <?php
                                    $lname = strtoupper($student['last_name']);
                                    $fname = ucwords(strtolower($student['first_name']));
                                    $mname = isset($student['middle_name']) && $student['middle_name'] ? strtoupper(substr($student['middle_name'], 0, 1)) . '.' : '';
                                    echo htmlspecialchars("$lname, $fname" . ($mname ? " $mname" : ""));
                                ?>
                            </td>
                            <td><?php echo htmlspecialchars($student['course']); ?></td>
                            <td><?php echo htmlspecialchars($student['year_level']) . ' - ' . htmlspecialchars($student['section']); ?></td>
                            <td>
                                <select class="form-select attendance-select"
                                        name="attendance[<?php echo $student['id']; ?>]"
                                        data-student-id="<?php echo $student['id']; ?>"
                                        data-student-school-id="<?php echo htmlspecialchars($student['student_id']); ?>">
                                    <?php 
                                    $hasAttendance = isset($existing_attendance[$student['id']]);
                                    $currentStatus = $hasAttendance ? $existing_attendance[$student['id']] : '';
                                    ?>
                                    <option value="" <?php echo !$hasAttendance ? 'selected' : ''; ?> disabled>Select status</option>
                                    <option value="present" <?php echo ($currentStatus === 'present') ? 'selected' : ''; ?>>Present</option>
                                    <option value="absent" <?php echo ($currentStatus === 'absent') ? 'selected' : ''; ?>>Absent</option>
                                    <option value="late" <?php echo ($currentStatus === 'late') ? 'selected' : ''; ?>>Late</option>
                                    <option value="excused" <?php echo ($currentStatus === 'excused') ? 'selected' : ''; ?>>Excused</option>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-end align-items-center mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i> <?php echo $is_update ? 'Update Attendance' : 'Save Attendance'; ?>
                </button>
            </div>
        </form>
        <?php elseif ($selected_class): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle-fill me-2"></i> No students found in this class.
                        </div>
        <?php else: ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle-fill me-2"></i> No classes found. Please create a class first.
                        </div>
        <?php endif; ?>
                </div>
            </div>
    </div>
        
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>
    <script>
    const manualOverrideStudents = new Set();
    // Global function for updating select style (accessible from RFID scanner)
        function updateSelectStyle(select) {
        if (!select) return;
            select.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'bg-info', 'text-white');
            
            switch(select.value) {
                case 'present':
                    select.classList.add('bg-success', 'text-white');
                    break;
                case 'absent':
                    select.classList.add('bg-danger', 'text-white');
                    break;
                case 'late':
                    select.classList.add('bg-warning', 'text-white');
                    break;
                case 'excused':
                    select.classList.add('bg-info', 'text-white');
                    break;
            }
        }
    
        document.addEventListener('DOMContentLoaded', function() {
        const attendanceForm = document.getElementById('attendanceForm');

        // Color-code attendance status and track manual changes
        document.querySelectorAll('.attendance-select').forEach(select => {
            updateSelectStyle(select);
            select.addEventListener('change', function(e) {
                updateSelectStyle(this);
                
                // Only track as manual edit if this was a user action (not programmatic)
                // Check if the event was triggered by user interaction
                if (e.isTrusted) {
                    const studentId = this.dataset.studentId || '';
                    const schoolId = this.dataset.studentSchoolId || '';
                    
                    // Add both IDs to prevent any auto-sync from overwriting
                    if (studentId) {
                        manualOverrideStudents.add(studentId.trim());
                        // Also add numeric version for better matching
                        const numericId = studentId.replace(/[^0-9]/g, '');
                        if (numericId) {
                            manualOverrideStudents.add(numericId);
                        }
                    }
                    if (schoolId) {
                        manualOverrideStudents.add(schoolId.trim());
                        // Also add numeric version
                        const numericSchoolId = schoolId.replace(/[^0-9]/g, '');
                        if (numericSchoolId) {
                            manualOverrideStudents.add(numericSchoolId);
                        }
                    }
                    
                    // Add visual indicator that this field has unsaved changes
                    this.style.boxShadow = '0 0 0 2px #ffc107';
                    this.title = 'Unsaved change - click Save to apply';
                    
                    console.log('📝 Manual edit detected for student:', studentId, '- blocking auto-sync');
                }
            });
        });
        
        if (attendanceForm) {
            attendanceForm.addEventListener('submit', function() {
                console.log('📤 Form submitted - clearing manual override list');
                manualOverrideStudents.clear();
                // Remove visual indicators
                document.querySelectorAll('.attendance-select').forEach(select => {
                    select.style.boxShadow = '';
                    select.title = '';
                });
            });
        }

        const searchInput = document.querySelector('input[name="search"]');
        const tableRows = document.querySelectorAll('table tbody tr');

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const searchValue = this.value.trim().toLowerCase();
                tableRows.forEach(row => {
                    const idCell = row.querySelector('td:nth-child(1)'); // Student ID
                    const nameCell = row.querySelector('td:nth-child(2)'); // Name
                    const idText = idCell ? idCell.textContent.trim().toLowerCase() : '';
                    const nameText = nameCell ? nameCell.textContent.trim().toLowerCase() : '';
                    if (idText.includes(searchValue) || nameText.includes(searchValue)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
        }

        // Date input change handler - submit form when date changes
        const dateInput = document.getElementById('date');
        const dateForm = document.getElementById('dateForm');
        if (dateInput && dateForm) {
            // Store original date to detect changes
            let originalDate = dateInput.value;
            
            dateInput.addEventListener('change', function(e) {
                const newDate = this.value;
                
                // Only navigate if date actually changed
                if (newDate !== originalDate) {
                    console.log('📅 Date changed from', originalDate, 'to', newDate);
                    
                    // Navigate directly to new date using URL (more reliable than form submit)
                    const classId = new URLSearchParams(window.location.search).get('class_id');
                    const url = `manage_attendance.php?class_id=${encodeURIComponent(classId || '')}&date=${encodeURIComponent(newDate)}`;
                    console.log('📅 Navigating to:', url);
                    window.location.href = url;
                }
            });
        }
        
        // Today button logic - navigate to today's date
        const todayBtn = document.getElementById('todayBtn');
        if (todayBtn) {
            todayBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const dateInput = document.getElementById('date');
                const dateForm = document.getElementById('dateForm');
                if (!dateInput || !dateForm) return;
                
                const min = dateInput.min;
                const max = dateInput.max;
                const today = new Date().toISOString().split('T')[0];
                
                if (today >= min && today <= max) {
                    // Navigate directly to today's date using URL (more reliable)
                    const classId = new URLSearchParams(window.location.search).get('class_id');
                    const url = `manage_attendance.php?class_id=${encodeURIComponent(classId || '')}&date=${encodeURIComponent(today)}`;
                    console.log('📅 Navigating to today:', url);
                    window.location.href = url;
                }
            });
        }
    });
    </script>
    <!-- Modals for each status (moved here for proper Bootstrap display) -->
    <?php foreach ([
        'present' => 'success',
        'absent' => 'danger',
        'late' => 'warning',
        'excused' => 'info'
    ] as $status => $color): ?>
    <div class="modal fade" id="<?php echo $status; ?>Modal" tabindex="-1" aria-labelledby="<?php echo $status; ?>ModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header bg-<?php echo $color; ?><?php echo $status === 'late' || $status === 'excused' ? ' text-dark' : ' text-white'; ?>">
            <h5 class="modal-title" id="<?php echo $status; ?>ModalLabel">
              <?php echo ucfirst($status); ?> Students (<?php echo $attendance_summary[$status]; ?>)
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <?php if (!empty($status_lists[$status])): ?>
            <div class="table-responsive">
              <table class="table table-bordered table-hover">
                <thead>
                  <tr>
                    <th>Student ID</th>
                    <th>Name</th>
                    <th>Course</th>
                    <th>Year & Section</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($status_lists[$status] as $student): ?>
                  <tr>
                    <td><?php echo htmlspecialchars(preg_replace('/^(\\d{4})(\\d+)$/', '$1-$2', $student['student_id'])); ?></td>
                    <td>
                        <?php
                            $lname = strtoupper($student['last_name']);
                            $fname = ucwords(strtolower($student['first_name']));
                            $mname = isset($student['middle_name']) && $student['middle_name'] ? strtoupper(substr($student['middle_name'], 0, 1)) . '.' : '';
                            echo htmlspecialchars("$lname, $fname" . ($mname ? " $mname" : ""));
                        ?>
                    </td>
                    <td><?php echo htmlspecialchars($student['course']); ?></td>
                    <td><?php echo htmlspecialchars($student['year_level']) . ' - ' . htmlspecialchars($student['section']); ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php else: ?>
            <div class="alert alert-info mb-0">No students marked as <?php echo $status; ?>.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    
    <!-- Reset Attendance Confirmation Modal -->
    <div class="modal fade" id="resetAttendanceModal" tabindex="-1" aria-labelledby="resetAttendanceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="resetAttendanceModalLabel">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>Reset Attendance
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">
                        <strong>Are you sure you want to reset attendance records<?php echo ($selected_session_id && !empty($available_sessions)) ? ' for this session' : ' for this date'; ?>?</strong>
                    </p>
                    <div class="alert alert-warning mb-3">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>This action will:</strong>
                        <ul class="mb-0 mt-2">
                            <?php if ($selected_session_id && !empty($available_sessions)): ?>
                                <?php 
                                $sessionInfo = '';
                                foreach ($available_sessions as $sess) {
                                    if ($sess['id'] == $selected_session_id) {
                                        $sessionInfo = date('g:i A', strtotime($sess['start_time'])) . ' - ' . date('g:i A', strtotime($sess['end_time']));
                                        break;
                                    }
                                }
                                ?>
                                <li>Delete attendance records for <strong><?php echo date('F d, Y', strtotime($selected_date)); ?></strong> - Session: <strong><?php echo htmlspecialchars($sessionInfo); ?></strong></li>
                                <li>Only records for this specific session will be deleted</li>
                            <?php else: ?>
                                <li>Delete all attendance records for <strong><?php echo date('F d, Y', strtotime($selected_date)); ?></strong></li>
                            <?php endif; ?>
                            <li>Remove records from both MySQL database and Firebase</li>
                            <li>This action cannot be undone</li>
                        </ul>
                    </div>
                    <p class="text-muted small mb-0">
                        Class: <strong><?php 
                            if ($selected_class) {
                                foreach ($classes as $class) {
                                    if ($class['id'] == $selected_class) {
                                        echo htmlspecialchars($class['class_desc'] ?? $class['class_name']);
                                        break;
                                    }
                                }
                            }
                        ?></strong>
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Cancel
                    </button>
                    <form action="manage_attendance.php" method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="reset_attendance">
                        <input type="hidden" name="class_id" value="<?php echo htmlspecialchars($selected_class); ?>">
                        <input type="hidden" name="date" value="<?php echo htmlspecialchars($selected_date); ?>">
                        <?php if ($selected_session_id): ?>
                            <input type="hidden" name="session_id" value="<?php echo htmlspecialchars($selected_session_id); ?>">
                        <?php endif; ?>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Reset Attendance
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!-- End Modals for each status -->
    
    <!-- RFID Attendance Modal -->
    <?php
    $firebaseConfig = require '../config/firebase.php';
    $firebaseBaseUrl = rtrim($firebaseConfig['database_url'], '/');
    $scannerPath = 'attendance_system/rfid_scans/latest';
    $scannerUrl = $firebaseBaseUrl . '/' . $scannerPath . '.json';
    ?>
    <div class="modal fade" id="rfidAttendanceModal" tabindex="-1" aria-labelledby="rfidAttendanceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="rfidAttendanceModalLabel">
                        <i class="bi bi-upc-scan me-2"></i>RFID Attendance Scanner
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Instructions:</strong> I-click ang "Start Scanner" at maghintay ng RFID tap. Kapag may student na nag-tap, lalabas ang student info para sa verification.
                    </div>
                    
                    <div class="d-flex justify-content-end mb-3">
                        <button type="button" class="btn btn-sm" id="startRfidScannerBtn" style="background-color: #2196F3 !important; background: #2196F3 !important; border-color: #2196F3 !important; color: #FFFFFF !important; background-image: none !important;">
                            <i class="bi bi-broadcast-pin me-1"></i>Start Scanner
                        </button>
                    </div>
                    
                    <div id="rfidWaitingArea" class="text-center py-5 border rounded bg-light">
                        <i class="bi bi-upc-scan" style="font-size: 4rem; color: #ccc;"></i>
                        <p id="rfidWaitingText" class="text-muted mt-3 mb-0">Click "Start Scanner" to begin</p>
                        <small id="rfidWaitingSubtext" class="text-muted">I-click ang button sa taas para magsimula</small>
                    </div>
                    
                    <div id="rfidStudentInfo" style="display: none;" class="border rounded p-4 bg-white">
                        <div class="text-center mb-4">
                            <div class="d-flex justify-content-center align-items-center mb-3" style="position: relative;">
                                <img id="rfidStudentPhoto" src="" alt="Student Photo" 
                                     class="rounded-circle" 
                                     style="width: 200px; height: 200px; object-fit: cover; display: none; border: 3px solid #dee2e6;">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12">
                                <h4 class="mb-3 text-center" id="rfidStudentName">Student Name</h4>
                                <table class="table table-borderless">
                                    <tr>
                                        <td width="120"><strong>Student ID:</strong></td>
                                        <td id="rfidStudentId">-</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Course:</strong></td>
                                        <td id="rfidStudentCourse">-</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Year & Section:</strong></td>
                                        <td id="rfidStudentYearSection">-</td>
                                    </tr>
                                    <tr>
                                        <td><strong>RFID UID:</strong></td>
                                        <td><code id="rfidStudentRfidUid">-</code></td>
                                    </tr>
                                </table>
                                
                                <div class="d-flex gap-2 mt-3">
                                    <button type="button" class="btn btn-success flex-fill" id="acceptRfidAttendanceBtn">
                                        <i class="bi bi-check-circle me-1"></i>Accept
                                    </button>
                                    <button type="button" class="btn btn-danger flex-fill" id="declineRfidAttendanceBtn">
                                        <i class="bi bi-x-circle me-1"></i>Decline
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div id="rfidAlert" class="mt-3"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    // TEST: Verify JavaScript is running
    console.log('✅✅✅ SCRIPT LOADED - JavaScript is working!');
    
    function pollSidebarUnreadBadge() {
        fetch('teacher_unread_count.php')
            .then(r => {
                if (!r.ok) {
                    // If file not found or error, just return silently
                    return null;
                }
                return r.json();
            })
            .then(data => {
                if (!data) return; // Skip if no data
                const badge = document.getElementById('sidebar-unread-badge');
                if (badge) {
                    if (data.unread > 0) {
                        badge.textContent = data.unread;
                        badge.style.display = 'inline-block';
                    } else {
                        badge.style.display = 'none';
                    }
                }
            })
            .catch(err => {
                // Silently handle errors - don't spam console
                // console.error('Error fetching unread count:', err);
            });
    }
    setInterval(pollSidebarUnreadBadge, 2000);
    pollSidebarUnreadBadge();
    
    // Real-time Attendance Sync from Firebase
    // Both website and app read/write to Firebase for real-time synchronization
    
    // Make updateAttendanceTable available globally for Firebase sync
    function updateAttendanceTable(studentId, status) {
        // Normalize the student ID for comparison
        const normalizedStudentId = String(studentId).trim();
        const numericStudentId = normalizedStudentId.replace(/[^0-9]/g, '');
        
        // Check if this student was manually edited - skip auto-update if so
        // Check both the exact ID and numeric version
        if (manualOverrideStudents.has(normalizedStudentId) || 
            manualOverrideStudents.has(numericStudentId)) {
            console.log('⏸️ Skipping Firebase sync for manually edited student:', normalizedStudentId);
            return;
        }
        
        // Also check if any student ID in the set matches numerically
        let isManuallyEdited = false;
        manualOverrideStudents.forEach(editedId => {
            const editedNumeric = String(editedId).replace(/[^0-9]/g, '');
            if (editedNumeric && numericStudentId && editedNumeric === numericStudentId) {
                isManuallyEdited = true;
            }
        });
        
        if (isManuallyEdited) {
            console.log('⏸️ Skipping Firebase sync for manually edited student (numeric match):', normalizedStudentId);
            return;
        }
        
        // Find the attendance select dropdown for this student
        let selectElement = document.querySelector(`select.attendance-select[data-student-id="${normalizedStudentId}"]`);
        let oldStatus = null;
        
        // If not found with data attribute, try alternative selector
        if (!selectElement) {
            selectElement = document.querySelector(`select[name="attendance[${normalizedStudentId}]"]`);
        }
        
        // Try numeric matching as fallback
        if (!selectElement) {
            document.querySelectorAll('select.attendance-select').forEach(select => {
                if (selectElement) return;
                const dataId = (select.dataset.studentId || '').trim();
                const dataIdNumeric = dataId.replace(/[^0-9]/g, '');
                if (dataIdNumeric && numericStudentId && dataIdNumeric === numericStudentId) {
                    selectElement = select;
                }
            });
        }
        
        if (selectElement) {
            // Get old status BEFORE updating
            oldStatus = selectElement.value && selectElement.value !== '' ? selectElement.value : null;
            
            // Only update if status is different (avoid unnecessary updates)
            if (oldStatus === status) {
                console.log('ℹ️ Status unchanged for student:', normalizedStudentId, '- skipping update');
                return;
            }
            
            // Update the dropdown value
            if (!status || status === '') {
                selectElement.value = '';
            } else {
                selectElement.value = status;
            }
            
            // Force update the visual style immediately
            setTimeout(() => {
                if (typeof updateSelectStyle === 'function') {
                    updateSelectStyle(selectElement);
                }
            }, 0);
            
            // Also update immediately
            if (typeof updateSelectStyle === 'function') {
                updateSelectStyle(selectElement);
            }
            
            // DON'T trigger change event to avoid adding to manualOverrideStudents
            // The change event listener adds to manualOverrideStudents which we don't want for auto-sync
            
            // Update attendance summary if function exists
            if (typeof updateAttendanceSummary === 'function') {
                updateAttendanceSummary(status, oldStatus);
            }
            
            console.log('✅ Updated attendance for student:', normalizedStudentId, 'from', oldStatus, 'to', status);
        } else {
            console.warn('⚠️ Select element not found for student ID:', studentId);
        }
    }
    
    console.log('🔧 About to initialize Firebase sync...');
    (function() {
        try {
            console.log('🔧 Firebase sync script starting...');
            
            const classId = <?php echo json_encode($selected_class); ?>;
            const attendanceDate = <?php echo json_encode($selected_date); ?>;
            const sessionId = <?php echo json_encode($selected_session_id); ?>;
            
            console.log('📋 Initial values:', { classId, attendanceDate, sessionId });
            
            <?php
            try {
                $firebaseConfig = require("../config/firebase.php");
                $firebaseUrl = rtrim($firebaseConfig["database_url"], "/");
            } catch (Exception $e) {
                $firebaseUrl = '';
                error_log("Error loading Firebase config: " . $e->getMessage());
            }
            ?>
            const firebaseBaseUrl = <?php echo json_encode($firebaseUrl); ?>;
            const attendancePath = firebaseBaseUrl + '/attendance_system/attendance.json';
            
            console.log('🔗 Firebase URL:', firebaseBaseUrl);
            console.log('🔗 Full path:', attendancePath);
            
            if (!firebaseBaseUrl) {
                console.error('❌ Firebase URL is empty! Check config/firebase.php');
                return;
            }
            
            if (!classId || !attendanceDate) {
                console.warn('⚠️ Missing classId or attendanceDate:', { classId, attendanceDate });
                return;
            }
            
            let isPolling = false;
            // Removed pendingDateSwitch - user controls date manually
        
        async function syncAttendanceFromFirebase() {
            if (!classId || !attendanceDate || isPolling) return;
            
            // CRITICAL: If we have a selected session, make sure it's properly set
            const currentSessionId = sessionId !== null && sessionId !== undefined && sessionId !== '' ? String(sessionId) : null;
            if (currentSessionId) {
                console.log('🔍 Firebase sync: Filtering by session ID:', currentSessionId);
            } else {
                console.log('⚠️ Firebase sync: No session selected - will only show old records without session');
            }
            
            isPolling = true;
            try {
                console.log('🔄 Firebase sync: Starting fetch from:', attendancePath);
                console.log('🔍 Looking for class_id:', classId, 'date:', attendanceDate);
                
                // Fetch all attendance from Firebase
                const response = await fetch(`${attendancePath}?${Date.now()}`);
                if (!response.ok) {
                    if (response.status === 404) {
                        console.log('⚠️ Firebase sync: No data found (404)');
                        return; // No data yet
                    }
                    const errorText = await response.text();
                    console.error('❌ Firebase sync error:', response.status, errorText);
                    return;
                }
                
                const firebaseData = await response.json();
                if (!firebaseData || typeof firebaseData !== 'object') {
                    console.warn('⚠️ Firebase sync: Invalid data format');
                    return;
                }
                
                const recordCount = Object.keys(firebaseData).length;
                console.log('📦 Firebase sync: Fetched', recordCount, 'total attendance records');
                console.log('📋 Firebase data structure:', firebaseData);
                
                let matchedCount = 0;
                const latestRecordsByStudent = {};
                const currentDateNormalized = String(attendanceDate).substring(0, 10);
                const todayIso = new Date().toISOString().split('T')[0];
                const currentClassIdNormalized = String(classId);
                
                // Helper to extract best timestamp
                const getRecordTimestamp = (record, data) => {
                    if (typeof record.timestamp === 'number') return record.timestamp;
                    if (typeof data.timestamp === 'number') return data.timestamp;
                    if (record.timestamp && !isNaN(Date.parse(record.timestamp))) return Date.parse(record.timestamp);
                    if (data.timestamp && !isNaN(Date.parse(data.timestamp))) return Date.parse(data.timestamp);
                    if (data.created_at && !isNaN(Date.parse(data.created_at))) return Date.parse(data.created_at);
                    return Date.now();
                };
                
                // Process each attendance record
                for (const [key, record] of Object.entries(firebaseData)) {
                    
                    console.log('🔎 Checking Firebase record key:', key);
                    console.log('📄 Full record:', JSON.stringify(record, null, 2));
                    
                    // Extract data from Firebase structure - handle both nested and direct
                    let data = null;
                    if (record && typeof record === 'object') {
                        if (record.data && typeof record.data === 'object') {
                            data = record.data; // Android app structure: {data: {...}}
                        } else {
                            data = record; // Direct structure
                        }
                    }
                    
                    if (!data || typeof data !== 'object') {
                        console.warn('⚠️ Skipping record - invalid data structure:', key);
                        continue;
                    }
                    
                    console.log('📊 Extracted data:', JSON.stringify(data, null, 2));
                    console.log('🔍 All data keys:', Object.keys(data));
                    
                    // Get record details - handle both string and number types
                    const recordClassId = String(data.class_id || data.classId || '');
                    let recordDateRaw = String(data.date || '');
                    const recordStudentId = String(data.student_id || data.studentId || '');
                    const recordStatusRaw = data.status || data.Status || '';
                    const recordStatus = recordStatusRaw !== null && recordStatusRaw !== undefined
                        ? String(recordStatusRaw).toLowerCase()
                        : '';
                    const recordOperation = (record.operation || '').toLowerCase();
                    const recordTimetableId = data.timetable_id ? String(data.timetable_id) : null;
                    
                    console.log('🔍 Raw values from Firebase:', {
                        rawDate: recordDateRaw,
                        rawClassId: data.class_id || data.classId,
                        rawStudentId: data.student_id || data.studentId,
                        rawStatus: data.status
                    });
                    
                    // Normalize date - handle multiple formats
                    let recordDate = '';
                    if (recordDateRaw) {
                        if (recordDateRaw.length === 8 && /^\d{8}$/.test(recordDateRaw)) {
                            // Format: YYYYMMDD -> YYYY-MM-DD
                            recordDate = recordDateRaw.substring(0, 4) + '-' + recordDateRaw.substring(4, 6) + '-' + recordDateRaw.substring(6, 8);
                        } else if (recordDateRaw.includes('-')) {
                            recordDate = recordDateRaw.substring(0, 10); // Take first 10 chars (YYYY-MM-DD)
                        } else {
                            recordDate = recordDateRaw.substring(0, 10);
                        }
                    }
                    
                    console.log('🔍 After normalization:', {
                        recordClassId: recordClassId,
                        currentClassId: currentClassIdNormalized,
                        recordDate: recordDate,
                        currentDate: currentDateNormalized,
                        recordStudentId: recordStudentId,
                        recordStatus: recordStatus
                    });
                    
                    console.log('🔍 Match check:', {
                        classIdMatch: recordClassId === currentClassIdNormalized,
                        classIdNumericMatch: Number(recordClassId) === Number(currentClassIdNormalized),
                        dateMatch: recordDate === currentDateNormalized,
                        hasStudentId: !!recordStudentId,
                        hasStatus: !!recordStatus
                    });
                    
                    // Filter by class_id - compare both as strings and numbers
                    const classIdMatch = recordClassId === currentClassIdNormalized || 
                                        String(recordClassId) === String(currentClassIdNormalized) ||
                                        Number(recordClassId) === Number(currentClassIdNormalized) ||
                                        parseInt(recordClassId) === parseInt(currentClassIdNormalized);
                    
                    if (!classIdMatch) {
                        console.log('❌ Class ID mismatch:', {
                            record: recordClassId,
                            current: currentClassIdNormalized,
                            recordType: typeof recordClassId,
                            currentType: typeof currentClassIdNormalized
                        });
                        continue; // Not for this class
                    }
                    
                    // Filter by date - normalize both dates and compare
                    if (!recordDate || recordDate !== currentDateNormalized) {
                        console.log('❌ Date mismatch:', {
                            record: recordDate,
                            current: currentDateNormalized,
                            rawRecordDate: recordDateRaw
                        });
                        // Don't track or auto-switch dates - user controls date manually
                        continue; // Not for this date
                    }
                    
                    // Filter by session (timetable_id) if session is selected
                    // This ensures records from one session don't appear in another session
                    if (sessionId !== null && sessionId !== undefined && sessionId !== '') {
                        const currentSessionId = String(sessionId);
                        const recordSessionId = recordTimetableId ? String(recordTimetableId) : null;
                        
                        // CRITICAL: If session is selected, ONLY show records for that exact session
                        // Skip records with no timetable_id (old records without session)
                        // Skip records with different timetable_id (other sessions)
                        if (!recordSessionId || recordSessionId === 'null' || recordSessionId === '') {
                            console.log('❌ Record has no timetable_id (old record) - skipping when session is selected:', {
                                record: recordSessionId,
                                current: currentSessionId,
                                recordTimetableId: recordTimetableId
                            });
                            continue; // Skip old records without session
                        }
                        
                        // Strict session ID matching - must match exactly
                        const sessionMatches = (recordSessionId === currentSessionId) || 
                                             (Number(recordSessionId) === Number(currentSessionId));
                        
                        if (!sessionMatches) {
                            console.log('❌ Session mismatch:', {
                                record: recordSessionId,
                                current: currentSessionId,
                                recordTimetableId: recordTimetableId,
                                recordType: typeof recordSessionId,
                                currentType: typeof currentSessionId
                            });
                            continue; // Not for this session
                        }
                    } else if (sessionId === null || sessionId === undefined || sessionId === '') {
                        // No session selected - skip records WITH timetable_id (they belong to specific sessions)
                        // Only show old records without session info
                        if (recordTimetableId && recordTimetableId !== 'null' && recordTimetableId !== '') {
                            console.log('❌ Record has timetable_id but no session selected - skipping:', {
                                recordTimetableId: recordTimetableId
                            });
                            continue; // Skip session-specific records when no session is selected
                        }
                    }
                    
                    // Handle delete operations even without status
                    if (recordOperation === 'delete') {
                        if (!recordStudentId) {
                            console.log('❌ Delete operation missing student_id - skipping');
                            continue;
                        }
                        const recordTimestamp = getRecordTimestamp(record, data);
                        const existing = latestRecordsByStudent[recordStudentId];
                        if (!existing || recordTimestamp >= existing.timestamp) {
                            latestRecordsByStudent[recordStudentId] = {
                                status: null,
                                timestamp: recordTimestamp,
                                firebaseKey: key,
                                operation: 'delete'
                            };
                            matchedCount++;
                        }
                        continue;
                    }
                    
                    // Skip if no student ID or status for insert/update
                    if (!recordStudentId || !recordStatus) {
                        console.log('❌ Missing student_id or status - skipping');
                        continue;
                    }
                    
                    const recordTimestamp = getRecordTimestamp(record, data);
                    
                    const existing = latestRecordsByStudent[recordStudentId];
                    if (!existing || recordTimestamp >= existing.timestamp) {
                        latestRecordsByStudent[recordStudentId] = {
                            status: recordStatus,
                            timestamp: recordTimestamp,
                            firebaseKey: key,
                            operation: recordOperation || 'insert'
                        };
                        matchedCount++;
                    }
                }
                
                // Apply latest statuses to UI
                Object.entries(latestRecordsByStudent).forEach(([studentId, info]) => {
                    const statusLog = info.operation === 'delete' ? 'CLEARED' : info.status;
                    console.log('✅ MATCH (latest) student:', studentId, 'status:', statusLog, 'ts:', info.timestamp, 'op:', info.operation);
                    if (typeof updateAttendanceTable === 'function') {
                        updateAttendanceTable(studentId, info.status || '');
                    } else {
                        console.error('❌ updateAttendanceTable function not found!');
                    }
                });
                
                console.log('✅ Firebase sync complete. Matched', matchedCount, 'records for class', classId, 'on', attendanceDate);
                
                // If no records matched but we saw a different date (e.g. user viewing old date while new records are for today),
                // automatically switch the date picker to the Firebase record date once.
                // REMOVED: Auto-switch date behavior - let user manually control the date
                // This was causing issues where users couldn't change dates manually
                // if (matchedCount === 0 && pendingDateSwitch && !dateMismatchHandled && pendingDateSwitch !== currentDateNormalized) {
                //     dateMismatchHandled = true;
                //     console.log('ℹ️ Switching page date to Firebase record date:', pendingDateSwitch);
                //     const dateInput = document.getElementById('date');
                //     if (dateInput) {
                //         dateInput.value = pendingDateSwitch;
                //         if (dateInput.form) {
                //             dateInput.form.submit();
                //             return;
                //         }
                //     }
                //     window.location.href = `manage_attendance.php?class_id=${encodeURIComponent(classId)}&date=${encodeURIComponent(pendingDateSwitch)}`;
                //     return;
                // }
                
            } catch (error) {
                console.error('❌ Error syncing from Firebase:', error);
                console.error('Error details:', error.stack);
            } finally {
                isPolling = false;
            }
        }
        
            console.log('🚀 Firebase real-time sync initialized:', {
                classId: classId,
                date: attendanceDate,
                sessionId: sessionId,
                firebasePath: attendancePath,
                classIdType: typeof classId,
                dateType: typeof attendanceDate,
                sessionIdType: typeof sessionId
            });
            
            // Poll Firebase every 2 seconds for real-time updates
            setInterval(syncAttendanceFromFirebase, 2000);
            // Initial sync immediately (don't wait)
            syncAttendanceFromFirebase();
            
            console.log('✅ Firebase sync setup complete!');
        } catch (error) {
            console.error('❌❌❌ ERROR in Firebase sync initialization:', error);
            console.error('Error stack:', error.stack);
        }
    })();
    
    // RFID Attendance Scanner
    (function() {
        const scannerUrl = '<?php echo htmlspecialchars($scannerUrl); ?>';
        const classId = <?php echo $selected_class ?: 'null'; ?>;
        const attendanceDate = '<?php echo htmlspecialchars($selected_date); ?>';
        const sessionId = <?php echo $selected_session_id ?: 'null'; ?>;
        const todayIso = new Date().toISOString().split('T')[0];
        const isTodaySelected = attendanceDate === todayIso;
        
        let isScanning = false;
        let pollTimer = null;
        let lastScanId = null;
        let lastScanTimestamp = null;
        let modalOpenTime = null;
        let currentStudent = null;
        
        const refs = {
            modal: document.getElementById('rfidAttendanceModal'),
            startBtn: document.getElementById('startRfidScannerBtn'),
            waitingArea: document.getElementById('rfidWaitingArea'),
            waitingText: document.getElementById('rfidWaitingText'),
            waitingSubtext: document.getElementById('rfidWaitingSubtext'),
            studentInfo: document.getElementById('rfidStudentInfo'),
            studentPhoto: document.getElementById('rfidStudentPhoto'),
            studentName: document.getElementById('rfidStudentName'),
            studentId: document.getElementById('rfidStudentId'),
            studentCourse: document.getElementById('rfidStudentCourse'),
            studentYearSection: document.getElementById('rfidStudentYearSection'),
            studentRfidUid: document.getElementById('rfidStudentRfidUid'),
            acceptBtn: document.getElementById('acceptRfidAttendanceBtn'),
            declineBtn: document.getElementById('declineRfidAttendanceBtn'),
            alertContainer: document.getElementById('rfidAlert')
        };

        // Sound effect functions using Web Audio API
        let audioContext = null;
        let masterGainNode = null;
        let audioInitialized = false;
        
        function initAudioContext() {
            if (!audioContext) {
                try {
                    audioContext = new (window.AudioContext || window.webkitAudioContext)();
                    console.log('Audio context created, state:', audioContext.state);
                    
                    // Create a master gain node with maximum amplification
                    // This ensures sound plays at maximum volume regardless of system settings
                    masterGainNode = audioContext.createGain();
                    masterGainNode.gain.value = 1.0; // Maximum gain
                    masterGainNode.connect(audioContext.destination);
                    console.log('Master gain node created');
                } catch (e) {
                    console.error('Web Audio API not supported:', e);
                    return null;
                }
            }
            
            // Resume audio context if suspended (required by browser autoplay policies)
            if (audioContext && audioContext.state === 'suspended') {
                audioContext.resume().then(() => {
                    console.log('Audio context resumed, state:', audioContext.state);
                    audioInitialized = true;
                }).catch(err => {
                    console.error('Could not resume audio context:', err);
                });
            } else if (audioContext && audioContext.state === 'running') {
                audioInitialized = true;
            }
            
            return audioContext;
        }

        function playSuccessSound() {
            console.log('playSuccessSound called');
            const ctx = initAudioContext();
            if (!ctx) {
                console.error('Audio context not available');
                return;
            }
            
            if (!masterGainNode) {
                console.error('Master gain node not available');
                return;
            }
            
            // Ensure audio context is running
            if (ctx.state === 'suspended') {
                console.log('Resuming audio context for success sound...');
                ctx.resume().then(() => {
                    console.log('Audio context resumed, playing success sound');
                    playSuccessSoundInternal(ctx);
                }).catch(err => {
                    console.error('Could not resume audio context for success sound:', err);
                });
            } else {
                console.log('Audio context is running, playing success sound');
                playSuccessSoundInternal(ctx);
            }
        }
        
        function playSuccessSoundInternal(ctx) {
            try {
                console.log('Playing success sound internally');
                // Create a pleasant success beep (two-tone ascending)
                const oscillator1 = ctx.createOscillator();
                const oscillator2 = ctx.createOscillator();
                const gainNode = ctx.createGain();
                
                oscillator1.type = 'sine';
                oscillator1.frequency.setValueAtTime(523.25, ctx.currentTime); // C5
                oscillator2.type = 'sine';
                oscillator2.frequency.setValueAtTime(659.25, ctx.currentTime); // E5
                
                // Set volume to maximum (1.0 = 100%) and amplify further
                // Using 1.0 ensures maximum volume within audio context
                gainNode.gain.setValueAtTime(1.0, ctx.currentTime);
                gainNode.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.2);
                
                // Connect through master gain node for maximum amplification
                oscillator1.connect(gainNode);
                oscillator2.connect(gainNode);
                gainNode.connect(masterGainNode);
                
                oscillator1.start(ctx.currentTime);
                oscillator2.start(ctx.currentTime);
                oscillator1.stop(ctx.currentTime + 0.2);
                oscillator2.stop(ctx.currentTime + 0.2);
                
                console.log('Success sound played successfully');
            } catch (e) {
                console.error('Could not play success sound:', e);
            }
        }

        function playErrorSound() {
            console.log('playErrorSound called');
            const ctx = initAudioContext();
            if (!ctx) {
                console.error('Audio context not available');
                return;
            }
            
            if (!masterGainNode) {
                console.error('Master gain node not available');
                return;
            }
            
            // Ensure audio context is running
            if (ctx.state === 'suspended') {
                console.log('Resuming audio context for error sound...');
                ctx.resume().then(() => {
                    console.log('Audio context resumed, playing error sound');
                    playErrorSoundInternal(ctx);
                }).catch(err => {
                    console.error('Could not resume audio context for error sound:', err);
                });
            } else {
                console.log('Audio context is running, playing error sound');
                playErrorSoundInternal(ctx);
            }
        }
        
        function playErrorSoundInternal(ctx) {
            try {
                console.log('Playing error sound internally');
                // Create an error beep (low descending tone)
                const oscillator = ctx.createOscillator();
                const gainNode = ctx.createGain();
                
                oscillator.type = 'sawtooth';
                oscillator.frequency.setValueAtTime(200, ctx.currentTime);
                oscillator.frequency.exponentialRampToValueAtTime(150, ctx.currentTime + 0.3);
                
                // Set volume to maximum (1.0 = 100%) and amplify further
                // Using 1.0 ensures maximum volume within audio context
                gainNode.gain.setValueAtTime(1.0, ctx.currentTime);
                gainNode.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.3);
                
                // Connect through master gain node for maximum amplification
                oscillator.connect(gainNode);
                gainNode.connect(masterGainNode);
                
                oscillator.start(ctx.currentTime);
                oscillator.stop(ctx.currentTime + 0.3);
                
                console.log('Error sound played successfully');
            } catch (e) {
                console.error('Could not play error sound:', e);
            }
        }
        
        function showAlert(type, message) {
            if (!refs.alertContainer) return;
            refs.alertContainer.innerHTML = `<div class="alert alert-${type} fade show">
                ${message}
            </div>`;
            setTimeout(() => {
                const alert = refs.alertContainer.querySelector('.alert');
                if (alert) alert.remove();
            }, 5000);
        }
        
        function startScanner() {
            if (!classId || classId === 'null' || classId === 0) {
                showAlert('danger', 'Please select a class first before starting the scanner.');
                stopScanner();
                return;
            }
            
            // Reset all tracking variables when starting
            // Set scanner start time to NOW - this is critical to ignore old scans
            const scannerStartTime = Date.now();
            modalOpenTime = scannerStartTime;
            lastScanId = null;
            lastScanTimestamp = null;
            currentStudent = null;
            resetScanner();
            
            console.log('Scanner started at:', scannerStartTime);
            
            isScanning = true;
            refs.startBtn.innerHTML = '<i class="bi bi-stop-circle me-1"></i>Stop Scanner';
            refs.startBtn.classList.remove('btn-primary');
            refs.startBtn.classList.add('btn-danger');
            
            // Update waiting area text
            if (refs.waitingText) {
                refs.waitingText.textContent = 'Waiting for RFID tap...';
            }
            if (refs.waitingSubtext) {
                refs.waitingSubtext.textContent = 'I-tap ang RFID card sa scanner';
            }
            
            // Wait 1 second before starting to poll - this ensures we don't pick up old data
            // Also gives time for any cached Firebase responses to clear
            setTimeout(() => {
                if (isScanning) {
                    // Update modalOpenTime again to account for the delay
                    modalOpenTime = Date.now();
                    console.log('Starting to poll at:', modalOpenTime);
                    fetchLatestScan();
                    pollTimer = setInterval(fetchLatestScan, 1000);
                }
            }, 1000);
        }
        
        function stopScanner() {
            isScanning = false;
            refs.startBtn.innerHTML = '<i class="bi bi-broadcast-pin me-1"></i>Start Scanner';
            refs.startBtn.classList.remove('btn-danger');
            refs.startBtn.classList.add('btn-primary');
            refs.startBtn.style.backgroundColor = '#2196F3';
            refs.startBtn.style.borderColor = '#2196F3';
            
            // Reset waiting area text
            if (refs.waitingText) {
                refs.waitingText.textContent = 'Click "Start Scanner" to begin';
            }
            if (refs.waitingSubtext) {
                refs.waitingSubtext.textContent = 'I-click ang button sa taas para magsimula';
            }
            
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
        }
        
        function fetchLatestScan() {
            if (!isScanning) return;
            
            fetch(`${scannerUrl}?t=${Date.now()}`)
                .then(res => {
                    if (res.status === 404) {
                        // No data in Firebase - this is normal, just return
                        return null;
                    }
                    if (!res.ok) throw new Error(`HTTP ${res.status}`);
                    return res.json();
                })
                .then(payload => {
                    // If no payload, just return (waiting for scan)
                    if (!payload || (typeof payload === 'object' && Object.keys(payload).length === 0)) {
                        return;
                    }
                    
                    // Extract scan information
                    const scanId = payload.scan_id || payload.scanId || null;
                    const timestamp = payload.timestamp || payload.server_time || payload.scan_time || null;
                    const currentTime = Date.now();
                    
                    // Ignore if same scan ID (already processed or declined)
                    if (scanId && scanId === lastScanId) {
                        return;
                    }
                    
                    // Also ignore if currentStudent exists (prevent re-display after decline)
                    if (currentStudent) {
                        return;
                    }
                    
                    // CRITICAL: Only accept scans that happened AFTER the scanner was started
                    // This prevents old data from appearing
                    if (modalOpenTime) {
                        let scanTime = null;
                        
                        if (timestamp) {
                            // Convert timestamp to milliseconds
                            if (typeof timestamp === 'number') {
                                // If timestamp is in seconds (less than year 2000 in ms), multiply by 1000
                                scanTime = timestamp < 946684800000 ? timestamp * 1000 : timestamp;
                            } else {
                                scanTime = new Date(timestamp).getTime();
                            }
                            
                            // Only accept if scan happened AFTER modal/scanner was opened
                            // Add 2 second buffer to account for timing differences
                            if (scanTime && (scanTime + 2000) < modalOpenTime) {
                                // This is an old scan from before we opened the modal - IGNORE IT
                                console.log('Ignoring old scan:', scanTime, 'Modal opened:', modalOpenTime);
                                return;
                            }
                        } else {
                            // No timestamp available - be more conservative
                            // Only accept if we haven't processed any scan yet OR if enough time has passed
                            if (lastScanTimestamp) {
                                const timeSinceLastScan = currentTime - lastScanTimestamp;
                                if (timeSinceLastScan < 3000) {
                                    // Too soon after last scan, might be duplicate
                                    return;
                                }
                            }
                            // Use current time as scan time for tracking
                            lastScanTimestamp = currentTime;
                        }
                    }
                    
                    const uid = extractUid(payload);
                    if (!uid || uid.length < 4) {
                        // Invalid UID
                        return;
                    }
                    
                    // Validate class is selected before processing scan
                    if (!classId || classId === 'null' || classId === 0) {
                        console.log('No class selected, ignoring scan');
                        showAlert('warning', 'Please select a class first before scanning.');
                        playErrorSound(); // Play error sound
                        return;
                    }
                    
                    // Update tracking BEFORE fetching student (to prevent duplicate processing)
                    lastScanId = scanId;
                    if (timestamp) {
                        if (typeof timestamp === 'number') {
                            lastScanTimestamp = timestamp < 946684800000 ? timestamp * 1000 : timestamp;
                        } else {
                            lastScanTimestamp = new Date(timestamp).getTime();
                        }
                    } else {
                        lastScanTimestamp = currentTime;
                    }
                    
                    console.log('New RFID scan detected:', uid, 'Scan ID:', scanId, 'Class ID:', classId);
                    
                    // Initialize audio before fetching student
                    initAudioOnInteraction();
                    
                    // Fetch student info (will validate enrollment in backend)
                    fetchStudentByRfid(uid);
                })
                .catch(err => {
                    // Silently handle errors - don't show alerts for network issues
                    console.error('RFID scan error:', err);
                });
        }
        
        function extractUid(payload) {
            if (!payload) return '';
            const candidates = [payload.uid, payload.UID, payload.card, payload.tag, payload.value, payload.rfid];
            const found = candidates.find(val => val && String(val).trim().length > 0);
            if (!found) return '';
            return String(found).replace(/[^A-Za-z0-9]/g, '').toUpperCase();
        }
        
        function fetchStudentByRfid(rfidUid) {
            // Validate that a class is selected
            if (!classId || classId === 'null' || classId === 0) {
                showAlert('warning', 'Please select a class first before scanning.');
                playErrorSound(); // Play error sound
                return;
            }
            
            // Initialize audio on scan
            initAudioOnInteraction();
            
            fetch(`rfid_attendance_api.php?action=get_student_by_rfid&rfid_uid=${encodeURIComponent(rfidUid)}&class_id=${classId}&date=${attendanceDate}`, {
                credentials: 'same-origin'
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.student) {
                    // Double-check: Verify student is enrolled in the selected class
                    // The backend should handle this, but we add extra validation here
                    if (data.warning && data.warning.includes('not enrolled')) {
                        showAlert('warning', 'This student is not enrolled in the selected class. Please select the correct class.');
                        playErrorSound(); // Play error sound
                        // Reset to waiting state
                        currentStudent = null;
                        refs.waitingArea.style.display = 'block';
                        refs.studentInfo.style.display = 'none';
                        return;
                    }
                    
                    // Student found successfully - play success sound
                    playSuccessSound(); // Play success sound when student is found
                    displayStudentInfo(data.student, data.attendance_status, data.schedule, data.current_time, data.existing_attendance, data.warning);
                } else {
                    // Show clean error message (no debug info)
                    const errorMsg = data.message || 'Student not found or not enrolled in this class.';
                    showAlert('warning', errorMsg);
                    playErrorSound(); // Play error sound
                    // Reset to waiting state on error
                    currentStudent = null;
                    refs.waitingArea.style.display = 'block';
                    refs.studentInfo.style.display = 'none';
                }
            })
            .catch(err => {
                console.error('Error fetching student:', err);
                showAlert('danger', 'Error fetching student information.');
                playErrorSound(); // Play error sound
                // Reset to waiting state on error
                currentStudent = null;
                refs.waitingArea.style.display = 'block';
                refs.studentInfo.style.display = 'none';
            });
        }
        
        function displayStudentInfo(student, attendanceStatus, schedule, currentTime, existingAttendance, warning) {
            currentStudent = student;
            currentStudent.attendanceStatus = attendanceStatus;
            
            // Hide waiting area, show student info
            refs.waitingArea.style.display = 'none';
            refs.studentInfo.style.display = 'block';
            
            // Set student information
            const lname = (student.last_name || '').toUpperCase();
            const fname = (student.first_name || '').charAt(0).toUpperCase() + (student.first_name || '').slice(1).toLowerCase();
            const mname = student.middle_name ? student.middle_name.charAt(0).toUpperCase() + '.' : '';
            refs.studentName.textContent = `${lname}, ${fname}${mname ? ' ' + mname : ''}`;
            refs.studentId.textContent = student.student_id || '-';
            refs.studentCourse.textContent = student.course || '-';
            refs.studentYearSection.textContent = `${student.year_level || '-'} - ${student.section || '-'}`;
            refs.studentRfidUid.textContent = student.rfid_uid || '-';
            
            // Set photo - only show if available
            refs.studentPhoto.style.display = 'none';
            
            if (student.profile_picture && student.profile_picture.trim() !== '') {
                refs.studentPhoto.src = '../' + student.profile_picture;
                refs.studentPhoto.onload = function() {
                    refs.studentPhoto.style.display = 'block';
                };
                refs.studentPhoto.onerror = function() {
                    refs.studentPhoto.style.display = 'none';
                };
            }
            
            // Handle attendance status for button enable/disable
            let canAccept = true;
            if (attendanceStatus) {
                const status = attendanceStatus.status;
                
                if (status === 'too_early' || status === 'too_late') {
                    canAccept = false;
                }
                
                // Check if already has attendance
                if (existingAttendance) {
                    canAccept = false;
                    showAlert('info', `Student already has attendance recorded as: ${existingAttendance.status.toUpperCase()}`);
                }
                
                // Enable/disable accept button based on status
                refs.acceptBtn.disabled = !canAccept;
                if (!canAccept && (status === 'too_early' || status === 'too_late')) {
                    refs.acceptBtn.innerHTML = '<i class="bi bi-lock me-1"></i>Cannot Accept';
                } else {
                    refs.acceptBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Accept';
                }
            }
            
            // Show warning if student not enrolled
            if (warning) {
                showAlert('warning', warning);
            }
            
            // Reset decline button
            refs.declineBtn.disabled = false;
        }
        
        function resetScanner() {
            currentStudent = null;
            lastScanId = null;
            lastScanTimestamp = null;
            refs.waitingArea.style.display = 'block';
            refs.studentInfo.style.display = 'none';
            refs.studentPhoto.style.display = 'none';
            refs.studentPhoto.src = '';
            refs.alertContainer.innerHTML = '';
        }
        
        // Function to update attendance table in real-time
        function updateAttendanceTable(studentId, status) {
            // Find the attendance select dropdown for this student
            const normalizedStudentId = String(studentId).trim();
            const numericStudentId = normalizedStudentId.replace(/[^0-9]/g, '');
            let selectElement = document.querySelector(`select.attendance-select[data-student-id="${normalizedStudentId}"]`);
            let oldStatus = null;

            if (manualOverrideStudents.has(normalizedStudentId)) {
                console.log('⏸️ Skipping auto update for manually edited student:', normalizedStudentId);
                return;
            }
            
            // If not found with data attribute, try alternative selector (name attribute)
            if (!selectElement) {
                selectElement = document.querySelector(`select[name="attendance[${normalizedStudentId}]"]`);
            }
            
            // As a fallback, loop through all selects and try to match by dataset values
            if (!selectElement) {
                document.querySelectorAll('select.attendance-select').forEach(select => {
                    if (selectElement) return;
                    
                    const dataId = (select.dataset.studentId || '').trim();
                    const dataSchoolId = (select.dataset.studentSchoolId || '').trim();
                    
                    if (dataId && dataId === normalizedStudentId) {
                        selectElement = select;
                        return;
                    }
                    
                    if (dataSchoolId) {
                        const normalizedSchoolId = dataSchoolId.replace(/[^0-9]/g, '');
                        if (numericStudentId && normalizedSchoolId && normalizedSchoolId === numericStudentId) {
                            selectElement = select;
                        }
                    }
                });
            }
            
            if (selectElement) {
                // Get old status BEFORE updating
                oldStatus = selectElement.value && selectElement.value !== '' ? selectElement.value : null;
                
                // Update the dropdown value (allow clearing when status empty)
                if (!status) {
                    selectElement.value = '';
                } else {
                    selectElement.value = status;
                }
                
                // Force update the visual style immediately
                setTimeout(() => {
                    updateSelectStyle(selectElement);
                }, 0);
                
                // Also update immediately
                updateSelectStyle(selectElement);
                
                // Trigger change event to ensure all listeners are notified
                const changeEvent = new Event('change', { bubbles: true, cancelable: true });
                selectElement.dispatchEvent(changeEvent);
            } else {
                console.warn('Select element not found for student ID:', studentId);
            }
            
            // Update attendance summary badges (pass old status for accurate counting)
            updateAttendanceSummary(status, oldStatus);
        }
        
        // Function to update attendance summary counts
        function updateAttendanceSummary(newStatus, oldStatus) {
            // Get current counts
            const presentCountEl = document.getElementById('presentCount');
            const absentCountEl = document.getElementById('absentCount');
            const lateCountEl = document.getElementById('lateCount');
            const excusedCountEl = document.getElementById('excusedCount');
            
            if (!presentCountEl || !absentCountEl || !lateCountEl || !excusedCountEl) {
                return;
            }
            
            // Get current values
            let presentCount = parseInt(presentCountEl.textContent) || 0;
            let absentCount = parseInt(absentCountEl.textContent) || 0;
            let lateCount = parseInt(lateCountEl.textContent) || 0;
            let excusedCount = parseInt(excusedCountEl.textContent) || 0;
            
            // Decrement old status if it exists and is different from new
            if (oldStatus && oldStatus !== newStatus) {
                switch(oldStatus) {
                    case 'present':
                        presentCount = Math.max(0, presentCount - 1);
                        break;
                    case 'absent':
                        absentCount = Math.max(0, absentCount - 1);
                        break;
                    case 'late':
                        lateCount = Math.max(0, lateCount - 1);
                        break;
                    case 'excused':
                        excusedCount = Math.max(0, excusedCount - 1);
                        break;
                }
            }
            
            // Increment the new status (only if it's different from old or no old status)
            if (newStatus && (!oldStatus || oldStatus !== newStatus)) {
                switch(newStatus) {
                    case 'present':
                        presentCount++;
                        break;
                    case 'absent':
                        absentCount++;
                        break;
                    case 'late':
                        lateCount++;
                        break;
                    case 'excused':
                        excusedCount++;
                        break;
                }
            } else if (!newStatus && oldStatus) {
                // Clearing status: only decrement old status (already done above)
                // Nothing to increment
            }
            
            // Update the counts
            presentCountEl.textContent = presentCount;
            absentCountEl.textContent = absentCount;
            lateCountEl.textContent = lateCount;
            excusedCountEl.textContent = excusedCount;
        }
        
        function recordAttendance() {
            if (!currentStudent || !classId) {
                showAlert('danger', 'Invalid student or class information.');
                playErrorSound(); // Play error sound
                return;
            }
            
            // Get automatic status from student data
            const status = currentStudent.attendanceStatus?.status;
            if (!status || status === 'too_early' || status === 'too_late' || status === 'manual') {
                // For manual or invalid status, use 'present' as fallback
                const finalStatus = (status === 'manual') ? 'present' : status;
                if (finalStatus === 'too_early' || finalStatus === 'too_late') {
                    showAlert('danger', 'Cannot record attendance: ' + (currentStudent.attendanceStatus?.message || 'Invalid status'));
                    playErrorSound(); // Play error sound
                    return;
                }
            }
            
            // Use the automatic status (present, late) or fallback to present
            const finalStatus = (status === 'present' || status === 'late') ? status : 'present';
            
            refs.acceptBtn.disabled = true;
            refs.declineBtn.disabled = true;
            
            const formData = new FormData();
            formData.append('action', 'record_rfid_attendance');
            formData.append('class_id', classId);
            formData.append('student_id', currentStudent.id);
            formData.append('date', attendanceDate);
            formData.append('status', finalStatus);
            if (sessionId) {
                formData.append('session_id', sessionId);
            }
            
            fetch('manage_attendance.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(res => {
                // Check if response is ok
                if (!res.ok) {
                    throw new Error(`HTTP error! status: ${res.status}`);
                }
                // Check content type
                const contentType = res.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    return res.text().then(text => {
                        console.error('Non-JSON response:', text);
                        throw new Error('Server returned non-JSON response');
                    });
                }
                return res.json();
            })
            .then(data => {
                if (data && data.success) {
                    // Save student ID and status before resetting
                    const savedStudentId = currentStudent.id;
                    const savedStatus = finalStatus;
                    
                    // Update attendance table in real-time IMMEDIATELY (before resetting)
                    // This ensures the status is saved and visible in the table
                    updateAttendanceTable(savedStudentId, savedStatus);
                    
                    showAlert('success', `Attendance recorded as ${savedStatus.toUpperCase()}!`);
                    playSuccessSound(); // Play success sound
                    
                    // Immediately reset to waiting state for next scan
                    setTimeout(() => {
                        // Reset scanner state
                        currentStudent = null;
                        refs.waitingArea.style.display = 'block';
                        refs.studentInfo.style.display = 'none';
                        refs.studentPhoto.style.display = 'none';
                        refs.studentPhoto.src = '';
                        refs.alertContainer.innerHTML = '';
                        
                        // Show message that we're ready for next scan
                        if (isScanning) {
                            showAlert('info', 'Attendance saved. Waiting for next scan...');
                        }
                    }, 1000);
                } else {
                    const errorMsg = (data && data.message) ? data.message : 'Failed to record attendance.';
                    showAlert('danger', errorMsg);
                    playErrorSound(); // Play error sound
                    refs.acceptBtn.disabled = false;
                    refs.declineBtn.disabled = false;
                }
            })
            .catch(err => {
                console.error('Error recording attendance:', err);
                // Check if it's a JSON parse error
                if (err instanceof SyntaxError) {
                    showAlert('warning', 'Invalid response from server. Attendance may have been saved. Please refresh to verify.');
                } else {
                    showAlert('danger', 'Error recording attendance: ' + (err.message || 'Unknown error'));
                    playErrorSound(); // Play error sound
                }
                refs.acceptBtn.disabled = false;
                refs.declineBtn.disabled = false;
            });
        }
        
        // Event listeners
        // Initialize audio context on first user interaction (required by browsers)
        function initAudioOnInteraction() {
            console.log('Initializing audio on user interaction');
            const ctx = initAudioContext();
            if (ctx && ctx.state === 'suspended') {
                ctx.resume().then(() => {
                    console.log('Audio context initialized on user interaction, state:', ctx.state);
                    audioInitialized = true;
                }).catch(err => {
                    console.error('Could not initialize audio context:', err);
                });
            } else if (ctx && ctx.state === 'running') {
                audioInitialized = true;
                console.log('Audio context already running');
            }
        }
        
        // Initialize audio on any user interaction - use multiple events
        document.addEventListener('click', initAudioOnInteraction, { once: false });
        document.addEventListener('keydown', initAudioOnInteraction, { once: false });
        document.addEventListener('touchstart', initAudioOnInteraction, { once: false });
        
        // Test sound functions (for debugging - can be removed later)
        window.testSuccessSound = function() {
            console.log('Testing success sound...');
            playSuccessSound();
        };
        window.testErrorSound = function() {
            console.log('Testing error sound...');
            playErrorSound();
        };
        
        if (refs.startBtn) {
            refs.startBtn.addEventListener('click', function() {
                initAudioOnInteraction(); // Ensure audio is ready
                if (isScanning) {
                    stopScanner();
                } else {
                    startScanner();
                }
            });
        }
        
        // Also initialize audio when Accept/Decline buttons are clicked
        if (refs.acceptBtn) {
            refs.acceptBtn.addEventListener('click', function() {
                initAudioOnInteraction();
            });
        }
        if (refs.declineBtn) {
            refs.declineBtn.addEventListener('click', function() {
                initAudioOnInteraction();
            });
        }
        
        if (refs.acceptBtn) {
            refs.acceptBtn.addEventListener('click', function() {
                recordAttendance();
            });
        }
        
        // Function to automatically mark absent students after attendance window closes
        // This only marks students who didn't tap within the 30-minute grace period
        function autoMarkAbsentStudents() {
            if (!isTodaySelected) {
                return;
            }
            if (!classId || classId === 'null' || classId === 0) {
                return;
            }
            
            fetch(`rfid_attendance_api.php?action=mark_absent_students&class_id=${classId}&date=${attendanceDate}`, {
                credentials: 'same-origin'
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.marked_count > 0) {
                    console.log(`✅ Automatically marked ${data.marked_count} students as absent after 30-minute grace period.`);
                    showAlert('info', `Automatically marked ${data.marked_count} students as absent after attendance window closed.`);
                    // Refresh the page after 2 seconds to show updated attendance
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else if (!data.success) {
                    // Handle different scenarios silently (these are normal conditions)
                    if (data.message && data.message.includes('still open')) {
                        // Window is still open - this is expected, don't do anything
                        // console.log('⏳ Attendance window still open. Auto-mark will trigger after 30 minutes.');
                    } else if (data.message && data.message.includes('already ended')) {
                        // Class has ended - stop checking (this is final)
                        console.log('📍 Class has ended. Auto-mark absent window closed.');
                    } else if (data.message && data.message.includes('No active schedule')) {
                        // Between schedules or before class - normal condition
                        // console.log('⏳ No active schedule for auto-mark.');
                    } else if (data.message && !data.message.includes('No schedule')) {
                        // Other errors (but not "no schedule" since that's also normal)
                        console.log('Auto-mark absent:', data.message);
                    }
                }
            })
            .catch(err => {
                console.error('Error auto-marking absent students:', err);
            });
        }
        
        // Function to check schedule and set up automatic absent marking
        function setupAutoMarkAbsent() {
            if (!isTodaySelected) {
                console.log('⏳ Auto-mark absent skipped (selected date is not today)');
                return;
            }
            if (!classId || classId === 'null' || classId === 0) {
                return;
            }
            
            console.log('⏰ Setting up automatic absent marking (will check after 30-minute grace period)');
            
            // Check every 30 seconds if the attendance window has closed
            // Backend will verify if 30 minutes have passed before marking
            const checkInterval = setInterval(() => {
                if (!classId || classId === 'null' || classId === 0) {
                    clearInterval(checkInterval);
                    return;
                }
                
                // Check if attendance window has closed (30 minutes after start_time)
                // Backend API will verify timing before marking students as absent
                autoMarkAbsentStudents();
            }, 30000); // Check every 30 seconds (more frequent but backend validates timing)
            
            // Also check when the page becomes visible (user comes back to tab)
            document.addEventListener('visibilitychange', function() {
                if (!document.hidden) {
                    // Only check if page becomes visible again (user might have been away)
                    autoMarkAbsentStudents();
                }
            });
        }
        
        // Setup auto-mark absent when page loads (if class is selected)
        // IMPORTANT: Only checks AFTER 30 minutes grace period has passed
        // The backend API will validate timing before marking students as absent
        if (classId && classId !== 'null' && classId !== 0 && isTodaySelected) {
            // Wait 15 seconds after page load before setting up auto-mark checks
            // This gives time for page to fully load
            setTimeout(() => {
                setupAutoMarkAbsent();
                // Also do an initial check (backend will validate if 30 minutes have passed)
                autoMarkAbsentStudents();
            }, 15000);
        }
        
        if (refs.declineBtn) {
            refs.declineBtn.addEventListener('click', function() {
                // Save student data and scan ID before resetting
                const studentToDelete = currentStudent;
                const declinedScanId = lastScanId; // Keep track of declined scan
                
                // Immediately reset UI to waiting state
                currentStudent = null;
                refs.waitingArea.style.display = 'block';
                refs.studentInfo.style.display = 'none';
                refs.studentPhoto.style.display = 'none';
                refs.studentPhoto.src = '';
                refs.alertContainer.innerHTML = '';
                
                // IMPORTANT: Keep lastScanId so the same scan won't be processed again
                // Only reset if we want to allow the same scan to be processed again
                // For now, we keep it to prevent the same scan from reappearing
                
                // If we have student data, delete attendance record in background
                if (studentToDelete && classId) {
                    const formData = new FormData();
                    formData.append('action', 'delete_rfid_attendance');
                    formData.append('class_id', classId);
                    formData.append('student_id', studentToDelete.id);
                    formData.append('date', attendanceDate);
                    
                    // Delete in background (don't wait for response)
                    fetch('manage_attendance.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            showAlert('success', 'Attendance declined and removed. Waiting for next scan...');
                        } else {
                            showAlert('info', 'Attendance declined. Waiting for next scan...');
                        }
                    })
                    .catch(err => {
                        console.error('Error declining attendance:', err);
                        showAlert('info', 'Attendance declined. Waiting for next scan...');
                    });
                } else {
                    // No student data, just show message
                    showAlert('info', 'Waiting for next scan...');
                }
            });
        }
        
        
        // Reset when modal is opened
        if (refs.modal) {
            refs.modal.addEventListener('show.bs.modal', function() {
                // Reset everything when modal opens
                stopScanner();
                resetScanner();
                // Set modal open time to NOW - this ensures we only accept NEW scans
                modalOpenTime = Date.now();
                lastScanId = null;
                lastScanTimestamp = null;
                console.log('Modal opened at:', modalOpenTime);
            });
            
            // Reset when modal is closed
            refs.modal.addEventListener('hidden.bs.modal', function() {
                stopScanner();
                resetScanner();
                modalOpenTime = null;
                lastScanId = null;
                lastScanTimestamp = null;
            });
        }
    })();
    </script>
</body>
</html> 
</html> 