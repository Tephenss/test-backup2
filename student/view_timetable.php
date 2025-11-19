<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: ../index.php");
    exit();
}

// Clear any previous error messages from other pages (like dashboard)
$timetableError = null;
if (isset($_SESSION['error'])) {
    // Clear old errors from other pages
    unset($_SESSION['error']);
}

// Get student's data
try {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $student = $stmt->fetch();
    
    if (!$student) {
        $_SESSION['error'] = "Student data not found.";
        header("Location: dashboard.php");
        exit();
    }
    
    $studentCourse = $student['course'] ?? null;
    $studentSection = $student['section'] ?? null;
    $studentYearLevel = $student['year_level'] ?? null;
    
    // Get course_id from courses table
    $courseId = null;
    if ($studentCourse) {
        $stmt = $pdo->prepare("SELECT id FROM courses WHERE code = ? LIMIT 1");
        $stmt->execute([$studentCourse]);
        $course = $stmt->fetch();
        $courseId = $course['id'] ?? null;
    }
    
    // Get timetable entries filtered by course_id, section, and year_level
    $timetable = [];
    $schedules = [];
    
    if ($courseId && $studentSection && $studentYearLevel) {
        $stmt = $pdo->prepare("
            SELECT t.*, 
                   cl.teacher_id,
                   cl.section,
                   cl.year_level,
                   te.full_name as teacher_name,
                   sub.subject_code,
                   sub.subject_name,
                   t.id as schedule_id,
                   t.room
            FROM timetable t
            JOIN classes cl ON t.class_id = cl.id
            JOIN teachers te ON cl.teacher_id = te.id
            JOIN subjects sub ON cl.subject_id = sub.id
            WHERE t.course_id = ?
              AND cl.section = ?
              AND cl.year_level = ?
              AND cl.status = 'active'
            ORDER BY 
              CASE t.day_of_week
                WHEN 'Monday' THEN 1
                WHEN 'Tuesday' THEN 2
                WHEN 'Wednesday' THEN 3
                WHEN 'Thursday' THEN 4
                WHEN 'Friday' THEN 5
                WHEN 'Saturday' THEN 6
                ELSE 7
              END,
              t.start_time
        ");
        $stmt->execute([$courseId, $studentSection, $studentYearLevel]);
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Build timetable array for grid rendering
    foreach ($schedules as $schedule) {
        $dayNum = is_numeric($schedule['day_of_week']) ? (int)$schedule['day_of_week'] : array_search($schedule['day_of_week'], ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']) + 1;
        if ($dayNum >= 1 && $dayNum <= 6) {
            if (!isset($timetable[$dayNum])) {
                $timetable[$dayNum] = [];
            }
            $timetable[$dayNum][] = $schedule;
        }
    }
    
} catch (PDOException $e) {
    error_log("Timetable Error: " . $e->getMessage());
    $timetableError = "There was a problem loading your timetable. Please try again later.";
    $timetable = [];
    $schedules = [];
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
        body.student-page {
            background: #f7f9fb;
        }
        .timetable-cell {
            min-height: 100px;
            height: 50px;
            vertical-align: top;
            padding: 0.5rem !important;
            width: 14.28%;
            position: relative;
            border: 1px solid #e3e6f0;
            background: var(--light-color);
        }
        .schedule-item {
            background-color: #e8f5e9;
            border-left: 4px solid #4caf50;
            border-radius: 4px;
            padding: 8px 12px 8px 8px;
            position: absolute;
            left: 2px;
            right: 2px;
            top: 2px;
            bottom: 2px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.12);
            transition: all 0.3s ease;
            z-index: 1;
            font-size: 0.95rem;
            overflow: hidden;
            height: 100%;
            margin: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .schedule-item:hover {
            transform: scale(1.02);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
            z-index: 2;
        }
        .time-column {
            width: 100px;
            font-weight: 600 !important;
            background-color: #f5f5f5 !important;
            text-align: center !important;
            padding: 1rem 0.5rem !important;
            font-size: 0.875rem !important;
            border: 1px solid #e3e6f0;
        }
        .timetable-wrapper {
            overflow-x: auto;
        }
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
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
                <a class="nav-link active" href="view_timetable.php">
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

        <?php if ($timetableError): ?>
            <div class="alert alert-danger fade show" role="alert">
                <i class="bi bi-exclamation-circle-fill me-2"></i>
                <?php echo htmlspecialchars($timetableError); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php 
                echo $_SESSION['success_message'];
                unset($_SESSION['success_message']);
                ?>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="row animate-fadeIn">
            <div class="col-12">
                <h1 class="mb-4">My Timetable</h1>
                <?php if ($studentCourse && $studentSection && $studentYearLevel): ?>
                    <p class="text-muted mb-4">
                        <strong>Course:</strong> <?php echo htmlspecialchars($studentCourse); ?> | 
                        <strong>Section:</strong> <?php echo htmlspecialchars($studentSection); ?> | 
                        <strong>Year Level:</strong> <?php echo htmlspecialchars($studentYearLevel); ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Timetable -->
        <div class="card animate-fadeIn delay-2">
            <div class="card-body">
                <?php if (empty($schedules)): ?>
                    <div class="empty-state">
                        <i class="bi bi-calendar-x"></i>
                        <h4>No Schedule Found</h4>
                        <p>No timetable entries found for your course, section, and year level.</p>
                    </div>
                <?php else: ?>
                    <div class="timetable-wrapper">
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
                                    '12:30' => '13:00',
                                    '13:00' => '13:30',
                                    '13:30' => '14:00',
                                    '14:00' => '14:30',
                                    '14:30' => '15:00',
                                    '15:00' => '15:30',
                                    '15:30' => '16:00',
                                    '16:00' => '16:30',
                                    '16:30' => '17:00',
                                    '17:00' => '17:30',
                                    '17:30' => '18:00',
                                    '18:00' => '18:30',
                                    '18:30' => '19:00',
                                ];
                                $timeKeys = array_keys($timeSlots);
                                $skip = [];
                                for ($row = 0; $row < count($timeSlots); $row++) {
                                    $startTime = $timeKeys[$row];
                                    $endTime = $timeSlots[$startTime];
                                    $currentTime = strtotime(str_pad($startTime, 5, '0', STR_PAD_LEFT));
                                    echo '<tr>';
                                    $start = date("g:i A", strtotime($startTime));
                                    $end = date("g:i A", strtotime($endTime));
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
                                                    $duration = ($scheduleEnd - $scheduleStart) / (30 * 60);
                                                    for ($i = 1; $i < $duration; $i++) {
                                                        $skip[$day][$row + $i] = true;
                                                    }
                                                    echo '<td class="timetable-cell" rowspan="' . $duration . '">';
                                                    echo '<div class="schedule-item">';
                                                    // Subject code
                                                    echo '<div style="font-weight:700;font-size:1.1em;margin-bottom:2px;">' . htmlspecialchars($schedule['subject_code']) . '</div>';
                                                    // Teacher (initial + last name)
                                                    $teacherNameParts = explode(' ', trim($schedule['teacher_name']));
                                                    $teacherInitial = '';
                                                    $teacherLastName = '';
                                                    if (count($teacherNameParts) > 0) {
                                                        $teacherInitial = strtoupper(substr($teacherNameParts[0], 0, 1));
                                                        $teacherLastName = ucfirst(strtolower(end($teacherNameParts)));
                                                    }
                                                    $shortTeacherName = $teacherInitial . '.' . $teacherLastName;
                                                    echo '<div style="color:#4caf50;font-size:1em;margin-bottom:2px;">' . htmlspecialchars($shortTeacherName) . '</div>';
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
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>
</body>
</html>

