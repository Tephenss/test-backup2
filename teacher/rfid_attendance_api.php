<?php
// Ensure timezone is set (in case database.php wasn't loaded first)
if (!ini_get('date.timezone')) {
    date_default_timezone_set('Asia/Manila');
}

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

if (!function_exists('normalizeAttendanceDate')) {
    function normalizeAttendanceDate($dateString) {
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

// Helper function to get current day name
function getCurrentDayName() {
    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    return $days[(int)date('w')];
}

// Helper function to determine attendance status based on schedule
function determineAttendanceStatus($startTime, $endTime, $currentTime) {
    // Use DateTime objects for accurate time comparison
    try {
        // Parse times - handle both H:i:s and H:i formats
        $startDateTime = DateTime::createFromFormat('H:i:s', $startTime);
        if (!$startDateTime) {
            $startDateTime = DateTime::createFromFormat('H:i', $startTime);
        }
        
        $endDateTime = DateTime::createFromFormat('H:i:s', $endTime);
        if (!$endDateTime) {
            $endDateTime = DateTime::createFromFormat('H:i', $endTime);
        }
        
        $currentDateTime = DateTime::createFromFormat('H:i:s', $currentTime);
        if (!$currentDateTime) {
            $currentDateTime = DateTime::createFromFormat('H:i', $currentTime);
        }
        
        if (!$startDateTime || !$endDateTime || !$currentDateTime) {
            error_log("Error parsing times - Start: {$startTime}, End: {$endTime}, Current: {$currentTime}");
            return ['status' => 'error', 'message' => 'Error parsing schedule times.'];
        }
        
        // Get time in minutes since midnight for comparison
        $startMinutes = (int)$startDateTime->format('H') * 60 + (int)$startDateTime->format('i');
        $endMinutes = (int)$endDateTime->format('H') * 60 + (int)$endDateTime->format('i');
        $currentMinutes = (int)$currentDateTime->format('H') * 60 + (int)$currentDateTime->format('i');
        
        // Debug logging
        error_log("Time Comparison - Start: {$startTime} ({$startMinutes} min), End: {$endTime} ({$endMinutes} min), Current: {$currentTime} ({$currentMinutes} min)");
        
        // Calculate time difference from start
        $minutesDiff = $currentMinutes - $startMinutes;
        
        // VALIDATION: Check if current time is within the scheduled time window
        if ($currentMinutes < $startMinutes) {
            // Tapped before schedule - too early
            $startTimeFormatted = $startDateTime->format('g:i A');
            error_log("TOO EARLY - Current: {$currentMinutes} min < Start: {$startMinutes} min");
            return ['status' => 'too_early', 'message' => 'Too early. Schedule has not started yet. Class starts at ' . $startTimeFormatted . '.'];
        } elseif ($currentMinutes > $endMinutes) {
            // Tapped after schedule - class has ended
            $endTimeFormatted = $endDateTime->format('g:i A');
            error_log("CLASS ENDED - Current: {$currentMinutes} min > End: {$endMinutes} min");
            return ['status' => 'class_ended', 'message' => 'Class has already ended. Class ended at ' . $endTimeFormatted . '.'];
        }
        
        // STATUS DETERMINATION (within scheduled time window):
        // 0 to 15 minutes after start → Present
        // 16 to 30 minutes after start → Late
        // After 30 minutes but before end_time → Too late (attendance window closed)
        
        if ($minutesDiff >= 0 && $minutesDiff <= 15) {
            // Within 15 minutes grace period → Present
            error_log("PRESENT - Minutes diff: {$minutesDiff}");
            return ['status' => 'present', 'message' => 'On time - Marked as Present'];
        } elseif ($minutesDiff >= 16 && $minutesDiff <= 30) {
            // 16-30 minutes late → Late
            error_log("LATE - Minutes diff: {$minutesDiff}");
            return ['status' => 'late', 'message' => 'Late arrival - Marked as Late'];
        } else {
            // More than 30 minutes late but still within class time → Too late to scan
            error_log("TOO LATE - Minutes diff: {$minutesDiff}");
            return ['status' => 'too_late', 'message' => 'Too late. Attendance window has closed. You can only scan within 30 minutes after class starts.'];
        }
    } catch (Exception $e) {
        error_log("Error in determineAttendanceStatus: " . $e->getMessage());
        return ['status' => 'error', 'message' => 'Error determining attendance status.'];
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
            
            // STEP 1: Check if RFID card is registered to a student
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
                // RFID card not registered to any student
                echo json_encode(['success' => false, 'message' => 'Student not found with this RFID card.']);
                exit;
            }
            
            // STEP 2: Verify class exists and belongs to teacher - get class details
            $classStmt = $pdo->prepare("SELECT id, section, year_level, subject_id FROM classes WHERE id = ? AND teacher_id = ?");
            $classStmt->execute([$classId, $_SESSION['user_id']]);
            $classInfo = $classStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$classInfo) {
                echo json_encode(['success' => false, 'message' => 'Class not found or access denied.']);
                exit;
            }
            
            // STEP 3: Check if student belongs to the selected class
            // Check if student's section and year_level match the class
            $sectionMatch = ($student['section'] === $classInfo['section']);
            $yearMatch = ($student['year_level'] == $classInfo['year_level']);
            
            if (!$sectionMatch || !$yearMatch) {
                // Student doesn't belong to this class - reject immediately
                echo json_encode([
                    'success' => false,
                    'message' => 'This student (Year ' . $student['year_level'] . ' - Section ' . $student['section'] . ') does NOT belong to the selected class (Year ' . $classInfo['year_level'] . ' - Section ' . $classInfo['section'] . '). Please select the correct class.'
                ]);
                exit;
            }
            
            // STEP 4: Check if there's an ONGOING schedule for this class today
            // Get current day of week
            $currentDay = getCurrentDayName();
            // Get current time in 24-hour format (HH:MM:SS)
            $currentTime = date('H:i:s');
            
            // Debug: Log current day and time
            error_log("=== RFID Attendance Check ===");
            error_log("Current Day: {$currentDay} (numeric: " . date('w') . ")");
            error_log("Current Time: {$currentTime}");
            error_log("Class ID: {$classId}");
            error_log("Date: {$attendanceDate}");
            
            // Get ALL timetables for this class and day (multiple sessions possible)
            $timetableStmt = $pdo->prepare("
                SELECT id, start_time, end_time, day_of_week
                FROM timetable
                WHERE class_id = ? AND day_of_week = ?
                ORDER BY start_time ASC
            ");
            $timetableStmt->execute([$classId, $currentDay]);
            $schedules = $timetableStmt->fetchAll(PDO::FETCH_ASSOC);
            
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
                ]);
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
                
                // Debug: Log each schedule
                error_log("Schedule - Start: {$schedule['start_time']} ({$startMinutes} min), End: {$schedule['end_time']} ({$endMinutes} min)");
                
                // Check if current time is within this schedule's window (ONGOING schedule)
                if ($currentMinutes >= $startMinutes && $currentMinutes <= $endMinutes) {
                    $matchedSchedule = $schedule;
                    $isTooEarly = false;
                    error_log("MATCHED Ongoing Schedule - Start: {$schedule['start_time']}, End: {$schedule['end_time']}");
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
                if (empty($schedules)) {
                    // No schedule found for today - reject attendance (must have schedule)
                    echo json_encode([
                        'success' => false,
                        'message' => 'No schedule found for today. Attendance can only be recorded during scheduled class time.'
                    ]);
                    exit;
                } else {
                    // Schedules exist but no ongoing schedule (current time not within any schedule window)
                    if ($isTooEarly && $earliestStartTime !== null) {
                        $earliestStart = date('g:i A', mktime(0, $earliestStartTime, 0));
                        echo json_encode([
                            'success' => false,
                            'message' => 'No ongoing schedule. Class starts at ' . $earliestStart . '.'
                        ]);
                        exit;
                    } else {
                        $latestEnd = date('g:i A', mktime(0, $latestEndTime, 0));
                        echo json_encode([
                            'success' => false,
                            'message' => 'No ongoing schedule. Class ended at ' . $latestEnd . '.'
                        ]);
                        exit;
                    }
                }
            }
            
            // Determine attendance status based on current time vs matched ongoing schedule
            $attendanceStatusInfo = determineAttendanceStatus($matchedSchedule['start_time'], $matchedSchedule['end_time'], $currentTime);
            
            // VALIDATION: Only allow attendance if status is 'present' or 'late'
            // Reject 'too_early', 'too_late', or 'class_ended' statuses
            if (in_array($attendanceStatusInfo['status'], ['too_early', 'too_late', 'class_ended'])) {
                echo json_encode([
                    'success' => false,
                    'message' => $attendanceStatusInfo['message']
                ]);
                exit;
            }
            
            // STEP 5: Verify student is enrolled in this specific class with active status
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
                // Auto-enroll student if not enrolled (since they belong to the class by section/year)
                $enrollStmt = $pdo->prepare("INSERT INTO class_students (class_id, student_id, status) VALUES (?, ?, 'active') ON DUPLICATE KEY UPDATE status = 'active'");
                $enrollStmt->execute([$classId, $student['id']]);
                
                // Backup auto-enrollment to Firebase
                try {
                    require_once '../helpers/BackupHooks.php';
                    $backupHooks = new BackupHooks();
                    
                    // Get the enrollment record that was just created/updated
                    $enrollCheckStmt = $pdo->prepare("SELECT * FROM class_students WHERE class_id = ? AND student_id = ?");
                    $enrollCheckStmt->execute([$classId, $student['id']]);
                    $newEnrollment = $enrollCheckStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($newEnrollment) {
                        $enrollmentData = [
                            'class_id' => (string)$classId,
                            'student_id' => (string)$student['id'],
                            'status' => 'active',
                            'enrolled_at' => date('Y-m-d H:i:s')
                        ];
                        $backupHooks->backupClassEnrollment($enrollmentData);
                        error_log("Backed up auto-enrollment to Firebase: class_id={$classId}, student_id={$student['id']}");
                    }
                } catch (Exception $e) {
                    error_log("Firebase backup failed for auto-enrollment: " . $e->getMessage());
                    // Don't fail the scan if backup fails
                }
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
                'schedule' => $matchedSchedule,
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
            
            // Only allow automatic absent marking for today's attendance to avoid retroactive changes
            if ($attendanceDate !== date('Y-m-d')) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Auto-mark absent is only available for today\'s date.'
                ]);
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
            $attendanceTimestamp = strtotime($attendanceDate);
            if ($attendanceTimestamp === false) {
                echo json_encode(['success' => false, 'message' => 'Invalid attendance date.']);
                exit;
            }
            
            $attendanceDay = date('l', $attendanceTimestamp);
            $currentDateTime = new DateTime('now', new DateTimeZone('Asia/Manila'));
            
            // Get ALL timetables for this class and day (handle multiple sessions)
            $timetableStmt = $pdo->prepare("
                SELECT id, start_time, end_time, day_of_week
                FROM timetable
                WHERE class_id = ? AND day_of_week = ?
                ORDER BY start_time ASC
            ");
            $timetableStmt->execute([$classId, $attendanceDay]);
            $schedules = $timetableStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($schedules)) {
                echo json_encode(['success' => false, 'message' => 'No schedule found for today.']);
                exit;
            }
            
            // Find the schedule that applies to auto-marking absent
            // Auto-mark should only happen:
            // 1. AFTER 30 minutes from start time (grace period passed)
            // 2. BEFORE the schedule end time (still within class time)
            $validScheduleForAutoMark = null;
            $latestScheduleEndDateTime = null;
            $earliestWindowEndDateTime = null;
            
            foreach ($schedules as $schedule) {
                $startDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $attendanceDate . ' ' . $schedule['start_time']);
                if (!$startDateTime) {
                    $startDateTime = DateTime::createFromFormat('Y-m-d H:i', $attendanceDate . ' ' . $schedule['start_time']);
                }
                
                $endDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $attendanceDate . ' ' . $schedule['end_time']);
                if (!$endDateTime) {
                    $endDateTime = DateTime::createFromFormat('Y-m-d H:i', $attendanceDate . ' ' . $schedule['end_time']);
                }
                
                if (!$startDateTime || !$endDateTime) {
                    error_log("Auto-mark absent: invalid schedule times for class {$classId}");
                    continue;
                }
                
                // 30-minute grace period window end
                $windowEndDateTime = clone $startDateTime;
                $windowEndDateTime->modify('+30 minutes');
                
                // Track the latest schedule end time (for error messages)
                if (!$latestScheduleEndDateTime || $endDateTime > $latestScheduleEndDateTime) {
                    $latestScheduleEndDateTime = $endDateTime;
                }
                
                // Track earliest window end (for waiting message)
                if (!$earliestWindowEndDateTime || $windowEndDateTime < $earliestWindowEndDateTime) {
                    $earliestWindowEndDateTime = $windowEndDateTime;
                }
                
                // Check if current time is:
                // 1. After 30-minute grace period (windowEndDateTime)
                // 2. Before or at schedule end time (endDateTime)
                if ($currentDateTime >= $windowEndDateTime && $currentDateTime <= $endDateTime) {
                    $validScheduleForAutoMark = $schedule;
                    $validScheduleForAutoMark['window_end'] = $windowEndDateTime;
                    $validScheduleForAutoMark['schedule_end'] = $endDateTime;
                    break; // Found a valid schedule
                }
            }
            
            // Check if we're past ALL schedules (class already ended)
            if ($latestScheduleEndDateTime && $currentDateTime > $latestScheduleEndDateTime) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Class has already ended. Auto-mark absent only works during class time.',
                    'schedule_ended_at' => $latestScheduleEndDateTime->format('H:i:s'),
                    'current_time' => $currentDateTime->format('H:i:s')
                ]);
                exit;
            }
            
            // Check if we're still within 30-minute grace period
            if (!$validScheduleForAutoMark && $earliestWindowEndDateTime && $currentDateTime < $earliestWindowEndDateTime) {
                $minutesRemaining = ($earliestWindowEndDateTime->getTimestamp() - $currentDateTime->getTimestamp()) / 60;
                echo json_encode([
                    'success' => false,
                    'message' => 'Attendance window is still open. Window closes at ' . $earliestWindowEndDateTime->format('H:i:s') . ' (' . round($minutesRemaining) . ' minutes remaining).',
                    'window_end' => $earliestWindowEndDateTime->format('H:i:s'),
                    'current_time' => $currentDateTime->format('H:i:s'),
                    'minutes_remaining' => round($minutesRemaining),
                    'schedules_count' => count($schedules)
                ]);
                exit;
            }
            
            // No valid schedule found (we're in between schedules or before any schedule)
            if (!$validScheduleForAutoMark) {
                echo json_encode([
                    'success' => false,
                    'message' => 'No active schedule for auto-mark absent. Auto-mark only works after 30-minute grace period and before class ends.',
                    'current_time' => $currentDateTime->format('H:i:s')
                ]);
                exit;
            }
            
            $windowEndTime = $validScheduleForAutoMark['window_end']->format('H:i:s');
            $scheduleEndTime = $validScheduleForAutoMark['schedule_end']->format('H:i:s');
            $currentTime = $currentDateTime->format('H:i:s');
            
            // Log that we're about to mark absent (for debugging)
            error_log("Auto-mark absent: Valid window found. Grace period ended at {$windowEndTime}, Schedule ends at {$scheduleEndTime}, Current: {$currentTime}");
            
            // Get class details (section and year_level) to match website's student filtering
            $classInfoStmt = $pdo->prepare("SELECT section, year_level FROM classes WHERE id = ?");
            $classInfoStmt->execute([$classId]);
            $classInfo = $classInfoStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$classInfo || !$classInfo['section'] || !$classInfo['year_level']) {
                echo json_encode(['success' => false, 'message' => 'Class information not found.']);
                exit;
            }
            
            $classSection = $classInfo['section'];
            $classYearLevel = $classInfo['year_level'];
            
            // Get all students matching the class's section and year_level (same logic as website)
            // This ensures consistency with the website's student list
            $studentsStmt = $pdo->prepare("
                SELECT id
                FROM students
                WHERE section = ? 
                AND year_level = ? 
                AND status NOT IN ('graduated', 'promoted', 'deleted')
                AND (is_deleted = 0 OR is_deleted IS NULL)
            ");
            $studentsStmt->execute([$classSection, $classYearLevel]);
            $allStudents = $studentsStmt->fetchAll(PDO::FETCH_COLUMN);
            
            error_log("Auto-mark absent: Found " . count($allStudents) . " students for section {$classSection}, year {$classYearLevel}");
            
            // Get the valid schedule's timetable_id (session ID) for marking absent
            $timetable_id = $validScheduleForAutoMark['id'] ?? null;
            
            // Check if timetable_id column exists
            $has_timetable_id_column = false;
            try {
                $check_column = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'timetable_id'");
                $has_timetable_id_column = ($check_column->rowCount() > 0);
            } catch (Exception $e) {
                // Column doesn't exist yet
            }
            
            // Get students who already have attendance (present/late) for this date AND session
            // Only students who tapped and got present/late for this specific session should be excluded
            if ($has_timetable_id_column && $timetable_id) {
                $attendedStmt = $pdo->prepare("
                    SELECT DISTINCT student_id
                    FROM attendance
                    WHERE class_id = ? AND date = ? AND timetable_id = ? AND status IN ('present', 'late')
                ");
                $attendedStmt->execute([$classId, $attendanceDate, $timetable_id]);
            } else {
                $attendedStmt = $pdo->prepare("
                    SELECT DISTINCT student_id
                    FROM attendance
                    WHERE class_id = ? AND date = ? AND status IN ('present', 'late')
                ");
                $attendedStmt->execute([$classId, $attendanceDate]);
            }
            $attendedStudents = $attendedStmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Include Firebase attendance records (present/late) so app entries are honored
            $firebaseAttendedStudents = [];
            try {
                $firebaseConfig = require '../config/firebase.php';
                $firebaseBaseUrl = isset($firebaseConfig['database_url']) ? rtrim($firebaseConfig['database_url'], '/') : '';
                if ($firebaseBaseUrl) {
                    $attendanceEndpoint = $firebaseBaseUrl . '/attendance_system/attendance.json';
                    $firebaseResponse = @file_get_contents($attendanceEndpoint);
                    if ($firebaseResponse !== false) {
                        $firebaseData = json_decode($firebaseResponse, true);
                        if (is_array($firebaseData)) {
                            foreach ($firebaseData as $record) {
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
                                $recordDate = isset($data['date']) ? (string)$data['date'] : '';
                                $recordStatus = isset($data['status']) ? strtolower((string)$data['status']) : '';
                                $recordTimetableId = isset($data['timetable_id']) ? (string)$data['timetable_id'] : null;
                                
                                if (!$recordClassId || !$recordStudentId || !$recordDate || !$recordStatus) {
                                    continue;
                                }
                                
                                $normalizedRecordDate = normalizeAttendanceDate($recordDate);
                                
                                // Filter by class, date, status, and session (timetable_id)
                                $classMatch = (string)$classId === trim($recordClassId);
                                $dateMatch = $normalizedRecordDate === $attendanceDate;
                                $statusMatch = in_array($recordStatus, ['present', 'late'], true);
                                
                                // If timetable_id column exists and we have a session, filter by it
                                if ($has_timetable_id_column && $timetable_id) {
                                    $sessionMatch = $recordTimetableId && (string)$timetable_id === trim($recordTimetableId);
                                    if ($classMatch && $dateMatch && $statusMatch && $sessionMatch) {
                                        $firebaseAttendedStudents[] = $recordStudentId;
                                    }
                                } else {
                                    // No session filtering (old behavior)
                                    if ($classMatch && $dateMatch && $statusMatch) {
                                        $firebaseAttendedStudents[] = $recordStudentId;
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                error_log('Firebase attendance fetch error (mark_absent_students): ' . $e->getMessage());
            }
            
            // Normalize IDs as strings for consistent comparison
            $allStudents = array_map('strval', $allStudents);
            $attendedStudents = array_map('strval', $attendedStudents);
            $firebaseAttendedStudents = array_map('strval', $firebaseAttendedStudents);
            $combinedAttended = array_unique(array_merge($attendedStudents, $firebaseAttendedStudents));
            
            // Find students who didn't tap within 30 minutes (not in attended list)
            // These are students who didn't tap at all OR tapped but were rejected (no present/late record)
            $absentStudents = array_diff($allStudents, $combinedAttended);
            
            $markedCount = 0;
            $errors = [];
            
            // Mark absent students for this specific session only
            foreach ($absentStudents as $studentId) {
                try {
                    // Check if student already has present/late attendance for this session (shouldn't be in absent list, but double-check)
                    if ($has_timetable_id_column && $timetable_id) {
                        $checkStmt = $pdo->prepare("SELECT id, status FROM attendance WHERE class_id = ? AND student_id = ? AND date = ? AND timetable_id = ? AND status IN ('present', 'late')");
                        $checkStmt->execute([$classId, $studentId, $attendanceDate, $timetable_id]);
                    } else {
                        $checkStmt = $pdo->prepare("SELECT id, status FROM attendance WHERE class_id = ? AND student_id = ? AND date = ? AND status IN ('present', 'late')");
                        $checkStmt->execute([$classId, $studentId, $attendanceDate]);
                    }
                    if ($checkStmt->fetch()) {
                        continue; // Already has present/late attendance for this session, skip
                    }
                    
                    // If student has 'absent' status already for this session, skip (in case of multiple calls)
                    if ($has_timetable_id_column && $timetable_id) {
                        $existingAbsentStmt = $pdo->prepare("SELECT id FROM attendance WHERE class_id = ? AND student_id = ? AND date = ? AND timetable_id = ? AND status = 'absent'");
                        $existingAbsentStmt->execute([$classId, $studentId, $attendanceDate, $timetable_id]);
                    } else {
                        $existingAbsentStmt = $pdo->prepare("SELECT id FROM attendance WHERE class_id = ? AND student_id = ? AND date = ? AND status = 'absent'");
                        $existingAbsentStmt->execute([$classId, $studentId, $attendanceDate]);
                    }
                    $existingAbsent = $existingAbsentStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existingAbsent) {
                        // Already marked as absent for this session - skip to avoid duplicates
                        continue;
                    }
                    
                    // Insert absent attendance with timetable_id (session ID)
                    if ($has_timetable_id_column && $timetable_id) {
                        $insertStmt = $pdo->prepare("INSERT INTO attendance (class_id, timetable_id, student_id, date, status, recorded_by) VALUES (?, ?, ?, ?, 'absent', ?)");
                        $insertStmt->execute([$classId, $timetable_id, $studentId, $attendanceDate, $_SESSION['user_id']]);
                    } else {
                        $insertStmt = $pdo->prepare("INSERT INTO attendance (class_id, student_id, date, status, recorded_by) VALUES (?, ?, ?, 'absent', ?)");
                        $insertStmt->execute([$classId, $studentId, $attendanceDate, $_SESSION['user_id']]);
                    }
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
                        if ($has_timetable_id_column && $timetable_id) {
                            $attendanceData['timetable_id'] = $timetable_id;
                        }
                        $backupHooks->backupAttendanceRecord($attendanceData);
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

