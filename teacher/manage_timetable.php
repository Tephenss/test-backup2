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

// Get teacher's classes
$stmt = $pdo->prepare("
    SELECT c.id,
           s.subject_code,
           s.year_level,
           c.section,
           CONCAT(s.subject_code, '-', s.year_level, c.section) as class_name,
           CONCAT(s.subject_code, ' - ', c.section, ' (', s.year_level, 'st Year)') as class_desc,
           c.academic_year,
           c.semester,
           s.subject_name,
           c.status
    FROM classes c
    JOIN subjects s ON c.subject_id = s.id
    JOIN sections sec ON c.section = sec.name
    JOIN subject_assignments sa ON sa.teacher_id = c.teacher_id 
        AND sa.subject_id = c.subject_id 
        AND sa.section_id = sec.id
    WHERE c.teacher_id = ? AND c.status = 'active'
    ORDER BY s.subject_code, c.section
");
$stmt->execute([$_SESSION['user_id']]);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

// Get timetable data
$timetable = [];
$stmt = $pdo->prepare("
    SELECT t.*, 
           s.subject_code,
           c.section,
           c.academic_year,
           c.semester,
           s.subject_name,
           s.year_level,
           t.id as schedule_id,
           t.room
    FROM timetable t
    JOIN classes c ON t.class_id = c.id
    JOIN subjects s ON c.subject_id = s.id
    WHERE c.teacher_id = ? AND c.status = 'active'
    ORDER BY t.day_of_week, t.start_time
");
$stmt->execute([$_SESSION['user_id']]);
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
                                '11:00' => '11:30', '11:30' => '12:00', '12:00' => '12:30', '12:30' => '1:00',
                                '1:00' => '1:30', '1:30' => '2:00', '2:00' => '2:30', '2:30' => '3:00',
                                '3:00' => '3:30', '3:30' => '4:00', '4:00' => '4:30', '4:30' => '5:00',
                                '5:00' => '5:30', '5:30' => '6:00', '6:00' => '6:30', '6:30' => '7:00'
                            ];
                            $timeKeys = array_keys($timeSlots);
                            $skip = [];
                            for ($row = 0; $row < count($timeSlots); $row++) {
                                $startTime = $timeKeys[$row];
                                $endTime = $timeSlots[$startTime];
                                $currentTime = strtotime(str_pad($startTime, 5, '0', STR_PAD_LEFT));
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
                                                $scheduleStart = strtotime($schedule['start_time']);
                                                $scheduleEnd = strtotime($schedule['end_time']);
                                            if ($currentTime == $scheduleStart) {
                                                $duration = ($scheduleEnd - $scheduleStart) / (30 * 60); // exact number of 30-min slots
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
    function pollSidebarUnreadBadge() {
        fetch('teacher_unread_count.php')
            .then(r => r.json())
            .then(data => {
                const badge = document.getElementById('sidebar-unread-badge');
                if (badge) {
                    if (data.unread > 0) {
                        badge.textContent = data.unread;
                        badge.style.display = 'inline-block';
                    } else {
                        badge.style.display = 'none';
                    }
                }
            });
    }
    setInterval(pollSidebarUnreadBadge, 2000);
    pollSidebarUnreadBadge();
    </script>
</body>
</html>