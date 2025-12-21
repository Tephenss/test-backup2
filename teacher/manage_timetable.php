<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'teacher') {
    header("Location: ../index.php");
    exit();
}

// Ensure we have access to the database connection
global $pdo;
if (!$pdo) {
    error_log("Database connection not available");
    die("Database connection error occurred");
}

// Fetch current user info for avatar display
$stmt = $pdo->prepare("SELECT * FROM teachers WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get teacher's classes - get ALL classes where this teacher is assigned
// This includes classes directly assigned via teacher_id in classes table
$stmt = $pdo->prepare("
    SELECT DISTINCT c.id,
           s.subject_code,
           s.year_level,
           c.section,
           CONCAT(s.subject_code, '-', s.year_level, c.section) as class_name,
           CONCAT(s.subject_code, ' - ', c.section, ' (', s.year_level, 'st Year)') as class_desc,
           c.academic_year,
           c.semester,
           s.subject_name,
           c.status,
           c.teacher_id
    FROM classes c
    INNER JOIN subjects s ON c.subject_id = s.id
    WHERE c.teacher_id = ? 
      AND c.status = 'active'
    ORDER BY s.subject_code, c.section
");
$stmt->execute([$_SESSION['user_id']]);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Also get classes from subject_assignments (in case some classes are assigned differently)
$stmt2 = $pdo->prepare("
    SELECT DISTINCT c.id,
           s.subject_code,
           s.year_level,
           c.section,
           CONCAT(s.subject_code, '-', s.year_level, c.section) as class_name,
           CONCAT(s.subject_code, ' - ', c.section, ' (', s.year_level, 'st Year)') as class_desc,
           c.academic_year,
           c.semester,
           s.subject_name,
           c.status,
           c.teacher_id
    FROM classes c
    INNER JOIN subjects s ON c.subject_id = s.id
    INNER JOIN sections sec ON c.section = sec.name AND c.year_level = sec.year_level
    INNER JOIN subject_assignments sa ON sa.teacher_id = ? 
        AND sa.subject_id = c.subject_id 
        AND sa.section_id = sec.id
    WHERE c.status = 'active'
      AND c.id NOT IN (SELECT id FROM (SELECT c2.id FROM classes c2 WHERE c2.teacher_id = ?) as temp)
    ORDER BY s.subject_code, c.section
");
$stmt2->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
$additionalClasses = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// Merge both results, removing duplicates
$allClasses = $classes;
$existingIds = array_column($classes, 'id');
foreach ($additionalClasses as $addClass) {
    if (!in_array($addClass['id'], $existingIds)) {
        $allClasses[] = $addClass;
        $existingIds[] = $addClass['id'];
    }
}
$classes = $allClasses;

// Debug: Log classes found
error_log("Teacher ID: {$_SESSION['user_id']}, Total classes found: " . count($classes));
if (count($classes) > 0) {
    error_log("Class IDs: " . implode(', ', array_column($classes, 'id')));
}

// Get unique courses
$courses = array_unique(array_column($classes, 'course'));

// Get unique sections
$sections = array_unique(array_column($classes, 'section'));

// Get unique year levels
$year_levels = array_unique(array_column($classes, 'year_level'));

// Get available courses
$stmt = $pdo->prepare("SELECT DISTINCT course FROM students ORDER BY course");
$stmt->execute();
$available_courses = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get timetable data - use same approach as admin: get ALL schedules, then filter by teacher_id
// This ensures we don't miss any schedules due to join issues
$timetable = [];
$teacher_id = $_SESSION['user_id'];

// METHOD 1: Get all schedules and filter by teacher_id from classes table (same as admin filters by year/section)
$stmt = $pdo->prepare("
    SELECT t.*, 
           s.subject_code,
           c.section,
           c.academic_year,
           c.semester,
           s.subject_name,
           s.year_level,
           c.teacher_id,
           t.id as schedule_id,
           t.room
    FROM timetable t
    INNER JOIN classes c ON t.class_id = c.id
    INNER JOIN subjects s ON c.subject_id = s.id
    WHERE c.teacher_id = ?
    ORDER BY t.day_of_week, t.start_time
");
$stmt->execute([$teacher_id]);
$schedules_method1 = $stmt->fetchAll(PDO::FETCH_ASSOC);

// METHOD 2: Get schedules by class_id list (backup method)
$classIds = array_column($classes, 'id');
$schedules_method2 = [];
if (!empty($classIds)) {
    $placeholders = implode(',', array_fill(0, count($classIds), '?'));
    $backupStmt = $pdo->prepare("
        SELECT t.*, 
               s.subject_code,
               c.section,
               c.academic_year,
               c.semester,
               s.subject_name,
               s.year_level,
               c.teacher_id,
               t.id as schedule_id,
               t.room
        FROM timetable t
        INNER JOIN classes c ON t.class_id = c.id
        INNER JOIN subjects s ON c.subject_id = s.id
        WHERE t.class_id IN ($placeholders)
        ORDER BY t.day_of_week, t.start_time
    ");
    $backupStmt->execute($classIds);
    $schedules_method2 = $backupStmt->fetchAll(PDO::FETCH_ASSOC);
}

// METHOD 3: Get ALL schedules and check teacher_id manually (most comprehensive)
$allSchedulesStmt = $pdo->prepare("
    SELECT t.*, 
           s.subject_code,
           c.section,
           c.academic_year,
           c.semester,
           s.subject_name,
           s.year_level,
           c.teacher_id,
           t.id as schedule_id,
           t.room
    FROM timetable t
    LEFT JOIN classes c ON t.class_id = c.id
    LEFT JOIN subjects s ON c.subject_id = s.id
    ORDER BY t.day_of_week, t.start_time
");
$allSchedulesStmt->execute();
$allSchedules = $allSchedulesStmt->fetchAll(PDO::FETCH_ASSOC);
$schedules_method3 = array_filter($allSchedules, function($s) use ($teacher_id) {
    return isset($s['teacher_id']) && $s['teacher_id'] == $teacher_id;
});

// Use the method that found the most schedules
$schedules = $schedules_method1;
if (count($schedules_method2) > count($schedules)) {
    $schedules = $schedules_method2;
}
if (count($schedules_method3) > count($schedules)) {
    $schedules = array_values($schedules_method3);
}

// Merge all methods to ensure nothing is missed
$scheduleIds = array_column($schedules, 'id');
$allMethodSchedules = array_merge($schedules_method1, $schedules_method2, array_values($schedules_method3));
foreach ($allMethodSchedules as $schedule) {
    $scheduleId = $schedule['id'] ?? $schedule['schedule_id'] ?? null;
    if ($scheduleId && !in_array($scheduleId, $scheduleIds)) {
        $schedules[] = $schedule;
        $scheduleIds[] = $scheduleId;
    }
}

// Remove duplicates based on schedule ID
$uniqueSchedules = [];
$seenIds = [];
foreach ($schedules as $schedule) {
    $scheduleId = $schedule['id'] ?? $schedule['schedule_id'] ?? null;
    if ($scheduleId && !in_array($scheduleId, $seenIds)) {
        $uniqueSchedules[] = $schedule;
        $seenIds[] = $scheduleId;
    } elseif (!$scheduleId) {
        // Include schedules without ID (shouldn't happen, but just in case)
        $uniqueSchedules[] = $schedule;
    }
}
$schedules = $uniqueSchedules; // Update schedules with deduplicated list

// Debug data for console
$debugInfo = [
    'teacher_id' => $teacher_id,
    'classes_count' => count($classes),
    'class_ids' => $classIds,
    'schedules_method1_count' => count($schedules_method1),
    'schedules_method2_count' => count($schedules_method2),
    'schedules_method3_count' => count($schedules_method3),
    'final_schedules_count' => count($schedules),
    'all_schedules_in_db' => count($allSchedules),
    'schedules_method1' => $schedules_method1,
    'schedules_method2' => $schedules_method2,
    'schedules_method3' => array_values($schedules_method3),
    'all_schedules' => $allSchedules
];

// Initialize skipCells array for each day
$skipCells = array_fill(0, 6, []); // 6 days (Monday to Saturday)

// Initialize timetable array with numeric keys (1-6 for Monday-Saturday)
foreach ($schedules as $schedule) {
    // Convert day_of_week to numeric if it's a string
    $dayNum = is_numeric($schedule['day_of_week']) ? (int)$schedule['day_of_week'] : array_search($schedule['day_of_week'], ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']) + 1;
    
    if ($dayNum >= 1 && $dayNum <= 6) { // Only process valid days (Monday-Saturday)
        if (!isset($timetable[$dayNum])) {
            $timetable[$dayNum] = [];
        }
        $timetable[$dayNum][] = $schedule;
    }
}

// Debug information
error_log("Schedules found: " . count($schedules));
foreach ($timetable as $day => $daySchedules) {
    error_log("Day $day has " . count($daySchedules) . " schedules");
}

// DEBUG OUTPUT: Show fetched classes and schedules for troubleshooting
if (isset(
    $_GET['debug']) && $_GET['debug'] == '1') {
    echo '<pre style="background:#fff;color:#000;z-index:9999;position:relative;">';
    echo "Logged-in teacher_id: " . htmlspecialchars($_SESSION['user_id']) . "\n\n";
    echo "CLASSES FETCHED FOR TEACHER:\n";
    print_r($classes);
    echo "\nSCHEDULES FETCHED FOR TEACHER:\n";
    print_r($schedules);
    echo '</pre>';
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
    <title>View Timetable - Attendance Management System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <link href="../assets/css/management.css" rel="stylesheet">
    <style>
        .timetable-cell { 
            min-height: 100px;
            height: 50px;
            vertical-align: top;
            padding: 0 !important;
            width: 14.28%;
            position: relative;
            border: 5px solid #dee2e6;
        }
        .schedule-item {
            background-color: #e3f2fd;
            border-left: 4px solid #1976d2;
            border-radius: 4px;
            padding: 8px;
            position: absolute;
            left: 0;
            right: 0;
            top: 0;
            bottom: 0;
            margin: 1px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.12);
            transition: all 0.3s ease;
            z-index: 1;
            font-size: 0.9rem;
            overflow: hidden;
        }
        .schedule-item:hover {
            background-color: #bbdefb;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            z-index: 2;
        }
        .time-column {
            width: 85px;
            font-weight: normal !important;
            background-color: #ffffff;
            text-align: center !important;
            padding: 15px 4px !important;
            font-size: 0.9rem;
            border: 5px solid #dee2e6;
            white-space: nowrap;
            overflow: hidden;
        }
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
                <a class="nav-link" href="manage_attendance.php">
                    <i class="bi bi-calendar-check"></i>
                    <span>Attendance</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="manage_timetable.php">
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
            
            <div class="user-info">
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
                    <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person"></i> Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="../auth/logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                </ul>
            </div>
        </div>

        <div class="management-header animate-fadeIn">
            <h2>View Timetable</h2>
        </div>

        <div class="card animate-fadeIn delay-1">
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th class="time-column">TIME\DAY</th>
                                <th>Monday</th>
                                <th>Tuesday</th>
                                <th>Wednesday</th>
                                <th>Thursday</th>
                                <th>Friday</th>
                                <th>Saturday</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $timeSlots = [
                                '7:00' => '7:30', '7:30' => '8:00', '8:00' => '8:30', '8:30' => '9:00',
                                '9:00' => '9:30', '9:30' => '10:00', '10:00' => '10:30', '10:30' => '11:00',
                                '11:00' => '11:30', '11:30' => '12:00', '12:00' => '12:30', '12:30' => '13:00',
                                '13:00' => '13:30', '13:30' => '14:00', '14:00' => '14:30', '14:30' => '15:00',
                                '15:00' => '15:30', '15:30' => '16:00', '16:00' => '16:30', '16:30' => '17:00',
                                '17:00' => '17:30', '17:30' => '18:00', '18:00' => '18:30', '18:30' => '19:00'
                            ];
                            $timeKeys = array_keys($timeSlots);
                            $skip = [];
                            for ($row = 0; $row < count($timeSlots); $row++) {
                                $startTime = $timeKeys[$row];
                                $endTime = $timeSlots[$startTime];
                                // Convert time slot to comparable format (HH:MM:SS)
                                $paddedStartTime = str_pad($startTime, 5, '0', STR_PAD_LEFT);
                                $currentTimeFormatted = $paddedStartTime . ':00'; // Add seconds for comparison
                                echo '<tr>';
                                $start = date("g:i", strtotime($startTime));
                                $end = date("g:i", strtotime($endTime));
                                echo '<td class="time-column">' . $start . ' - ' . $end . '</td>';
                                for ($day = 1; $day <= 6; $day++) {
                                    // Skip cell if covered by rowspan
                                    if (isset($skip[$day][$row]) && $skip[$day][$row]) continue;
                                    $cellPrinted = false;
                                    if (isset($timetable[$day])) {
                                        foreach ($timetable[$day] as $schedule) {
                                            // Normalize schedule time format (remove seconds if present, then add :00)
                                            $scheduleStartTime = $schedule['start_time'];
                                            // Handle both "HH:MM:SS" and "HH:MM" formats
                                            if (strlen($scheduleStartTime) == 5) {
                                                $scheduleStartTime = $scheduleStartTime . ':00';
                                            }
                                            // Extract just the time part (HH:MM:SS)
                                            $scheduleStartFormatted = substr($scheduleStartTime, 0, 8);
                                            
                                            // Compare times directly as strings (HH:MM:SS format)
                                            if ($currentTimeFormatted === $scheduleStartFormatted) {
                                                // Calculate duration
                                                $scheduleEndTime = $schedule['end_time'];
                                                if (strlen($scheduleEndTime) == 5) {
                                                    $scheduleEndTime = $scheduleEndTime . ':00';
                                                }
                                                $scheduleEndFormatted = substr($scheduleEndTime, 0, 8);
                                                
                                                // Calculate duration in 30-minute slots
                                                $startParts = explode(':', $scheduleStartFormatted);
                                                $endParts = explode(':', $scheduleEndFormatted);
                                                $startMinutes = (int)$startParts[0] * 60 + (int)$startParts[1];
                                                $endMinutes = (int)$endParts[0] * 60 + (int)$endParts[1];
                                                $duration = ($endMinutes - $startMinutes) / 30;
                                                
                                                // Mark cells to skip
                                                    for ($i = 1; $i < $duration; $i++) {
                                                    $skip[$day][$row + $i] = true;
                                                }
                                                
                                                echo '<td class="timetable-cell" rowspan="' . $duration . '">';
                                                echo '<div class="schedule-item" style="background-color:#e3f2fd;border-left:4px solid #1976d2;box-shadow:0 1px 3px rgba(0,0,0,0.12);font-size:0.95rem;overflow:hidden;height:100%;margin:0;padding:8px 12px 8px 8px;display:flex;flex-direction:column;justify-content:center;position:relative;">';
                                                // Subject code and section only
                                                            $className = htmlspecialchars($schedule['subject_code'] . ' - ' . $schedule['section']);
                                                echo '<div style="font-weight:bold;font-size:1.1em;margin-bottom:2px;">' . $className . '</div>';
                                                echo '<div style="color:#1976d2;font-size:1em;margin-bottom:2px;">' . htmlspecialchars(($schedule['year_level'] ?? '') . '-' . ($schedule['section'] ?? '')) . '</div>';
                                                echo '<div style="font-size:0.95em;color:#333;">' . htmlspecialchars($schedule['room']) . '</div>';
                                                echo '</div>';
                                                echo '</td>';
                                                $cellPrinted = true;
                                                break;
                                            }
                                        }
                                    }
                                    if (!$cellPrinted) {
                                        echo '<td class="timetable-cell"></td>';
                                    }
                                }
                                echo '</tr>';
                            }
                            ?>
                        </tbody>
                    </table>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // ============================================
    // DEBUG TOOL FOR TEACHER TIMETABLE
    // ============================================
    (function() {
        console.log('%c=== TEACHER TIMETABLE DEBUG TOOL ===', 'background: #007bff; color: white; font-size: 16px; font-weight: bold; padding: 5px;');
        
        // Debug data from PHP
        const debugData = {
            teacherId: <?php echo json_encode($_SESSION['user_id']); ?>,
            classes: <?php echo json_encode($classes, JSON_PRETTY_PRINT); ?>,
            schedules: <?php echo json_encode($schedules, JSON_PRETTY_PRINT); ?>,
            timetable: <?php echo json_encode($timetable, JSON_PRETTY_PRINT); ?>
        };
        
        console.log('%c1. TEACHER INFO', 'background: #28a745; color: white; font-size: 14px; font-weight: bold; padding: 3px;');
        console.log('Teacher ID:', debugData.teacherId);
        console.log('Total Classes Found:', debugData.classes.length);
        console.log('Total Schedules Found:', debugData.schedules.length);
        
        console.log('%c2. CLASSES DETAILS', 'background: #17a2b8; color: white; font-size: 14px; font-weight: bold; padding: 3px;');
        if (debugData.classes.length > 0) {
            console.table(debugData.classes.map(c => ({
                'Class ID': c.id,
                'Subject Code': c.subject_code,
                'Subject Name': c.subject_name,
                'Section': c.section,
                'Year Level': c.year_level,
                'Teacher ID': c.teacher_id,
                'Status': c.status
            })));
            console.log('Class IDs:', debugData.classes.map(c => c.id).join(', '));
        } else {
            console.warn('⚠️ NO CLASSES FOUND FOR THIS TEACHER!');
        }
        
        console.log('%c3. SCHEDULES DETAILS', 'background: #ffc107; color: black; font-size: 14px; font-weight: bold; padding: 3px;');
        if (debugData.schedules.length > 0) {
            console.table(debugData.schedules.map(s => ({
                'Schedule ID': s.id || s.schedule_id,
                'Class ID': s.class_id,
                'Day': s.day_of_week,
                'Start Time': s.start_time,
                'End Time': s.end_time,
                'Room': s.room,
                'Subject Code': s.subject_code,
                'Section': s.section,
                'Teacher ID': s.teacher_id
            })));
            
            // Group by day
            const schedulesByDay = {};
            debugData.schedules.forEach(s => {
                const day = s.day_of_week;
                if (!schedulesByDay[day]) schedulesByDay[day] = [];
                schedulesByDay[day].push(s);
            });
            console.log('%cSchedules by Day:', 'font-weight: bold;');
            Object.keys(schedulesByDay).forEach(day => {
                console.log(`  ${day}: ${schedulesByDay[day].length} schedule(s)`);
            });
        } else {
            console.warn('⚠️ NO SCHEDULES FOUND FOR THIS TEACHER!');
        }
        
        console.log('%c4. TIMETABLE GRID DATA', 'background: #6f42c1; color: white; font-size: 14px; font-weight: bold; padding: 3px;');
        if (Object.keys(debugData.timetable).length > 0) {
            Object.keys(debugData.timetable).forEach(dayNum => {
                const dayNames = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                const dayName = dayNames[parseInt(dayNum)] || `Day ${dayNum}`;
                console.log(`${dayName} (${dayNum}): ${debugData.timetable[dayNum].length} schedule(s)`);
                debugData.timetable[dayNum].forEach((sched, idx) => {
                    console.log(`  [${idx + 1}] ${sched.start_time} - ${sched.end_time} | ${sched.subject_code} | Room: ${sched.room}`);
                });
            });
        } else {
            console.warn('⚠️ TIMETABLE GRID IS EMPTY!');
        }
        
        // Check for potential issues
        console.log('%c5. DIAGNOSTICS', 'background: #dc3545; color: white; font-size: 14px; font-weight: bold; padding: 3px;');
        
        // Check if class IDs match
        const classIds = debugData.classes.map(c => c.id);
        const scheduleClassIds = [...new Set(debugData.schedules.map(s => s.class_id))];
        const missingInSchedules = classIds.filter(id => !scheduleClassIds.includes(id));
        const extraInSchedules = scheduleClassIds.filter(id => !classIds.includes(id));
        
        if (missingInSchedules.length > 0) {
            console.warn('⚠️ Classes with NO schedules:', missingInSchedules.join(', '));
        }
        if (extraInSchedules.length > 0) {
            console.warn('⚠️ Schedules for classes NOT in teacher\'s class list:', extraInSchedules.join(', '));
        }
        
        // Check teacher_id consistency
        const mismatchedTeacherIds = debugData.schedules.filter(s => s.teacher_id != debugData.teacherId);
        if (mismatchedTeacherIds.length > 0) {
            console.error('❌ Schedules with MISMATCHED teacher_id:', mismatchedTeacherIds.length);
            console.table(mismatchedTeacherIds.map(s => ({
                'Schedule ID': s.id || s.schedule_id,
                'Class ID': s.class_id,
                'Expected Teacher ID': debugData.teacherId,
                'Actual Teacher ID': s.teacher_id
            })));
        }
        
        // Summary
        console.log('%c6. SUMMARY', 'background: #20c997; color: white; font-size: 14px; font-weight: bold; padding: 3px;');
        console.log(`✅ Classes: ${debugData.classes.length}`);
        console.log(`✅ Schedules: ${debugData.schedules.length}`);
        console.log(`✅ Timetable Days with Data: ${Object.keys(debugData.timetable).length}`);
        console.log(`✅ Total Schedule Blocks in Grid: ${Object.values(debugData.timetable).reduce((sum, day) => sum + day.length, 0)}`);
        
        if (debugData.classes.length > 0 && debugData.schedules.length === 0) {
            console.error('❌ PROBLEM DETECTED: Teacher has classes but NO schedules found!');
            console.log('Possible causes:');
            console.log('  1. Schedules exist but teacher_id mismatch in classes table');
            console.log('  2. Schedules exist but class_id mismatch in timetable table');
            console.log('  3. Schedules not yet created for these classes');
        }
        
        // Make debug data available globally for inspection
        window.teacherTimetableDebug = debugData;
        console.log('%cDebug data available as: window.teacherTimetableDebug', 'font-style: italic; color: #6c757d;');
        console.log('Access it in console: teacherTimetableDebug');
        
        // Advanced debugging
        console.log('%c7. QUERY METHOD COMPARISON', 'background: #fd7e14; color: white; font-size: 14px; font-weight: bold; padding: 3px;');
        const debugInfo = <?php echo json_encode($debugInfo, JSON_PRETTY_PRINT); ?>;
        console.log('Method 1 (by teacher_id):', debugInfo.schedules_method1_count, 'schedules');
        console.log('Method 2 (by class_id list):', debugInfo.schedules_method2_count, 'schedules');
        console.log('Method 3 (all then filter):', debugInfo.schedules_method3_count, 'schedules');
        console.log('Final merged count:', debugInfo.final_schedules_count, 'schedules');
        console.log('Total schedules in DB:', debugInfo.all_schedules_in_db);
        
        // Compare methods
        if (debugInfo.schedules_method1_count !== debugInfo.schedules_method2_count) {
            console.warn('⚠️ Method mismatch detected!');
            console.log('Method 1 schedules:', debugInfo.schedules_method1);
            console.log('Method 2 schedules:', debugInfo.schedules_method2);
        }
        
        // Check for schedules that should be visible but aren't
        const allScheduleClassIds = [...new Set(debugInfo.all_schedules.map(s => s.class_id))];
        const teacherClassIds = debugInfo.class_ids;
        const missingSchedules = debugInfo.all_schedules.filter(s => {
            return teacherClassIds.includes(s.class_id) && 
                   s.teacher_id == debugInfo.teacher_id &&
                   !debugInfo.schedules_method1.some(ms => (ms.id || ms.schedule_id) == (s.id || s.schedule_id));
        });
        
        if (missingSchedules.length > 0) {
            console.error('❌ MISSING SCHEDULES DETECTED:', missingSchedules.length);
            console.table(missingSchedules.map(s => ({
                'Schedule ID': s.id || s.schedule_id,
                'Class ID': s.class_id,
                'Day': s.day_of_week,
                'Time': s.start_time + ' - ' + s.end_time,
                'Room': s.room,
                'Teacher ID': s.teacher_id,
                'Subject': s.subject_code
            })));
        }
        
        // Check time format issues and rendering
        console.log('%c8. TIME FORMAT CHECK & RENDERING STATUS', 'background: #6f42c1; color: white; font-size: 14px; font-weight: bold; padding: 3px;');
        const timeSlots = [
            '7:00', '7:30', '8:00', '8:30', '9:00', '9:30', '10:00', '10:30',
            '11:00', '11:30', '12:00', '12:30', '13:00', '13:30', '14:00', '14:30',
            '15:00', '15:30', '16:00', '16:30', '17:00', '17:30', '18:00', '18:30'
        ];
        debugData.schedules.forEach(s => {
            const startTime = s.start_time;
            const endTime = s.end_time;
            const day = s.day_of_week;
            const dayNum = typeof day === 'number' ? day : ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'].indexOf(day) + 1;
            
            // Normalize time format
            let normalizedStart = startTime;
            if (normalizedStart.length === 5) {
                normalizedStart = normalizedStart + ':00';
            }
            normalizedStart = normalizedStart.substring(0, 8); // Get HH:MM:SS
            
            // Check if time slot exists
            const timeSlotExists = timeSlots.some(slot => {
                const paddedSlot = slot.padStart(5, '0') + ':00';
                return paddedSlot === normalizedStart;
            });
            
            const status = timeSlotExists ? '✅ WILL RENDER' : '❌ NO MATCHING TIME SLOT';
            console.log(`${status} - Schedule ID ${s.id || s.schedule_id}: Day ${dayNum} (${day}), Start: "${startTime}" → "${normalizedStart}", End: "${endTime}"`);
            if (!timeSlotExists) {
                console.warn(`  ⚠️ Time "${normalizedStart}" not found in time slots array!`);
            }
        });
        
        console.log('%c=== END DEBUG TOOL ===', 'background: #007bff; color: white; font-size: 16px; font-weight: bold; padding: 5px;');
    })();
    </script>
    <script>
    function pollSidebarUnreadBadge() {
        fetch('teacher_unread_count.php')
            .then(r => {
                if (!r.ok) return null;
                return r.json();
            })
            .then(data => {
                if (!data) return;
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
                // Silently handle errors
            });
    }
    setInterval(pollSidebarUnreadBadge, 2000);
    pollSidebarUnreadBadge();
    </script>
</body>
</html>