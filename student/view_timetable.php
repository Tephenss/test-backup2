<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: ../index.php");
    exit();
}

// Fetch student profile for avatar
$stmt = $pdo->prepare("SELECT first_name, last_name, profile_picture FROM students WHERE id = ? LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);
$initials = strtoupper(substr($student['first_name'] ?? '', 0, 1) . substr($student['last_name'] ?? '', 0, 1));

// Get timetable data for the student's enrolled classes
$timetable = [];
$stmt = $pdo->prepare("
    SELECT t.*, 
           CONCAT(s.subject_code, ' - ', c.section) as class_name,
           s.subject_name,
           c.section,
           t.id as schedule_id,
           teachers.full_name as teacher_name
    FROM timetable t
    JOIN classes c ON t.class_id = c.id
    JOIN subjects s ON c.subject_id = s.id
    JOIN teachers ON c.teacher_id = teachers.id
    JOIN class_students cs ON c.id = cs.class_id
    WHERE cs.student_id = ?
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
error_log("Student Schedules found: " . count($schedules));
foreach ($timetable as $day => $daySchedules) {
    error_log("Day $day has " . count($daySchedules) . " schedules");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Timetable - Attendance Management System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <style>
        .timetable-cell {
            min-height: 50px;
            height: 50px;
            vertical-align: top;
            padding: 0 !important;
            width: 14.28%;
            position: relative;
            border: 5px solid #dee2e6;
        }
        .schedule-item {
            background-color: #e8f5e9;
            border-left: 4px solid #4caf50;
            border-radius: 4px;
            padding: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.12);
            transition: all 0.3s ease;
            z-index: 1;
            font-size: 0.9rem;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-start;
            text-align: left;
            height: 100%;
        }
        .schedule-item:hover {
            background-color: #c8e6c9;
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
    </style>
</head>
<body class="student-page">
    <!-- Sidebar -->
    <aside class="sidebar student-sidebar">
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
                <a class="nav-link" href="view_attendance.php">
                    <i class="bi bi-calendar-check"></i>
                    <span>My Attendance</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="view_timetable.php">
                    <i class="bi bi-clock"></i>
                    <span>Timetable</span>
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
<?php if (!empty($student['profile_picture']) && file_exists(__DIR__ . '/../uploads/profile_pics/' . $student['profile_picture'])): ?>
    <img src="../uploads/profile_pics/<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="Profile Picture" style="width:32px;height:32px;object-fit:cover;border-radius:50%;display:block;">
<?php else: ?>
    <?php echo htmlspecialchars($initials); ?>
<?php endif; ?>
                    </div>
                    <span class="user-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?></span>
                </a>
                <ul class="dropdown-menu user-dropdown" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i> Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="../auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>

        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3">My Timetable</h1>
            </div>

            <div class="card animate-fadeIn delay-1">
                <div class="card-body">
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
                                    '7:00' => '7:30',
                                    '7:30' => '8:00',
                                    '8:00' => '8:30',
                                    '8:30' => '9:00',
                                    '9:00' => '9:30',
                                    '9:30' => '10:00',
                                    '10:00' => '10:30',
                                    '10:30' => '11:00',
                                    '11:00' => '11:30',
                                    '11:30' => '12:00',
                                    '12:00' => '12:30',
                                    '12:30' => '1:00',
                                    '1:00' => '1:30',
                                    '1:30' => '2:00',
                                    '2:00' => '2:30',
                                    '2:30' => '3:00',
                                    '3:00' => '3:30',
                                    '3:30' => '4:00',
                                    '4:00' => '4:30',
                                    '4:30' => '5:00',
                                    '5:00' => '5:30',
                                    '5:30' => '6:00',
                                    '6:00' => '6:30',
                                    '6:30' => '7:00'
                                ];
                                $timeSlotKeys = array_keys($timeSlots);
                                $slotCount = count($timeSlotKeys);

                                // Prepare a map to track which cells to skip due to rowspan
                                $skip = [];
                                for ($day = 1; $day <= 6; $day++) {
                                    $skip[$day] = array_fill(0, $slotCount, false);
                                }

                                for ($row = 0; $row < $slotCount; $row++) {
                                    $startTime = $timeSlotKeys[$row];
                                    $endTime = $timeSlots[$startTime];
                                    $currentTime = strtotime(str_pad($startTime, 5, '0', STR_PAD_LEFT));
                                    echo '<tr>';
                                    // Time column
                                            $displayStart = str_replace(':30', '.30', $startTime);
                                            $displayEnd = str_replace(':30', '.30', $endTime);
                                    echo '<td class="time-column">' . $displayStart . '-' . $displayEnd . '</td>';
                                    for ($day = 1; $day <= 6; $day++) {
                                        if ($skip[$day][$row]) continue;
                                        $cellRendered = false;
                                        if (isset($timetable[$day])) {
                                            foreach ($timetable[$day] as $schedule) {
                                                    $scheduleStart = strtotime($schedule['start_time']);
                                                    $scheduleEnd = strtotime($schedule['end_time']);
                                                if ($currentTime == $scheduleStart) {
                                                        $duration = ceil(($scheduleEnd - $scheduleStart) / (30 * 60));
                                                    // Mark the next (duration-1) slots to be skipped
                                                        for ($i = 1; $i < $duration; $i++) {
                                                        if (isset($skip[$day][$row + $i])) {
                                                            $skip[$day][$row + $i] = true;
                                                        }
                                                    }
                                                    // Format teacher name as A.Bautista
                                                    $teacherNameParts = explode(' ', trim($schedule['teacher_name']));
                                                    $teacherInitial = '';
                                                    $teacherLastName = '';
                                                    if (count($teacherNameParts) > 0) {
                                                        $teacherInitial = strtoupper(substr($teacherNameParts[0], 0, 1));
                                                        $teacherLastName = ucfirst(strtolower(end($teacherNameParts)));
                                                    }
                                                    $shortTeacherName = $teacherInitial . '.' . $teacherLastName;
                                                    echo '<td class="timetable-cell" rowspan="' . $duration . '">';
                                                    echo '<div class="schedule-item">';
                                                    echo '<div style="font-weight: bold; font-size: 1.1em; margin-bottom: 2px;">' . htmlspecialchars(explode(' ', $schedule['class_name'])[0]) . '</div>';
                                                    echo '<div style="color: #4caf50; font-size: 1em; margin-bottom: 2px;">' . htmlspecialchars($shortTeacherName) . '</div>';
                                                    echo '<div style="font-size: 0.95em; color: #333;">' . htmlspecialchars($schedule['room']) . '</div>';
                                                    echo '</div>';
                                                    echo '</td>';
                                                    $cellRendered = true;
                                                    break;
                                                }
                                            }
                                        }
                                        if (!$cellRendered) {
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
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle sidebar
        document.querySelector('.toggle-sidebar').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('collapsed');
            document.querySelector('.page-content').classList.toggle('expanded');
        });

        // Auto-hide success alerts
        window.setTimeout(function() {
            const alerts = document.getElementsByClassName('alert');
            for(let alert of alerts) {
                alert.classList.add('fade');
                setTimeout(function() {
                    alert.remove();
                }, 150);
            }
        }, 3000);
    </script>
</body>
</html>
