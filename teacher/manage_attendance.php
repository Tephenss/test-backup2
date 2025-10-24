<?php
session_start();
// Set the timezone to Asia/Manila
date_default_timezone_set('Asia/Manila');
require_once '../config/database.php';
require_once '../helpers/BackupHooks.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'teacher') {
    header("Location: ../index.php");
    exit();
}

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
                $attendance_data = $_POST['attendance'] ?? [];

                try {
                    // First delete existing attendance for this date and class
                    $stmt = $pdo->prepare("DELETE FROM attendance WHERE date = ? AND class_id = ?");
                    $stmt->execute([$date, $class_id]);

                    if (!empty($attendance_data)) {
                        // Auto-enroll students in class_students as 'active'
                        $enrollStmt = $pdo->prepare("INSERT INTO class_students (class_id, student_id, status) VALUES (?, ?, 'active') ON DUPLICATE KEY UPDATE status = 'active'");
                        // Insert new attendance records
                        $stmt = $pdo->prepare("
                            INSERT INTO attendance (class_id, student_id, date, status, recorded_by) 
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        foreach ($attendance_data as $student_id => $status) {
                            $enrollStmt->execute([$class_id, $student_id]);
                            $stmt->execute([
                                $class_id,
                                $student_id,
                                $date,
                                $status,
                                $_SESSION['user_id']
                            ]);
                            
                            // Backup attendance record to Firebase
                            try {
                                $backupHooks = new BackupHooks();
                                $attendanceData = [
                                    'id' => $pdo->lastInsertId(),
                                    'class_id' => $class_id,
                                    'student_id' => $student_id,
                                    'date' => $date,
                                    'status' => $status,
                                    'recorded_by' => $_SESSION['user_id'],
                                    'created_at' => date('Y-m-d H:i:s')
                                ];
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
                // Force a full reload to ensure real-time accuracy and default date to today
                echo '<meta http-equiv="refresh" content="0;url=manage_attendance.php?class_id=' . urlencode($class_id) . '&date=' . urlencode($date) . '">';
                exit();
        }
    }
    // Force a full reload to ensure real-time accuracy
    echo '<meta http-equiv="refresh" content="0;url=manage_attendance.php">';
    exit();
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
    // Get section and year_level of the selected class
    $stmt = $pdo->prepare("SELECT c.section, s.year_level FROM classes c JOIN sections s ON c.section = s.name WHERE c.id = ?");
    $stmt->execute([$selected_class]);
    $classInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    $selected_section = $classInfo['section'] ?? '';
    $selected_year_level = $classInfo['year_level'] ?? '';
    if ($selected_section && $selected_year_level) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM students
            WHERE section = ? AND year_level = ? AND status NOT IN ('graduated', 'promoted')
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

    // Get existing attendance for selected date and class, filtered by term if set
    $attendance_query = "SELECT student_id, status, date FROM attendance WHERE class_id = ?";
    $attendance_params = [$selected_class];
    if ($filter_start && $filter_end) {
        $attendance_query .= " AND date BETWEEN ? AND ?";
        $attendance_params[] = $filter_start;
        $attendance_params[] = $filter_end;
    } else {
        $attendance_query .= " AND date = ?";
        $attendance_params[] = $selected_date;
    }
    $stmt = $pdo->prepare($attendance_query);
    $stmt->execute($attendance_params);
    $existing_attendance = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existing_attendance[$row['student_id']] = $row['status'];
    }

    // Calculate attendance summary for the selected date only (per day)
    $summary_query = "SELECT status, COUNT(*) as count FROM attendance WHERE class_id = ? AND date = ? GROUP BY status";
    $stmt = $pdo->prepare($summary_query);
    $stmt->execute([$selected_class, $selected_date]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $attendance_summary[$row['status']] = (int)$row['count'];
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
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE class_id = ? AND date = ?");
    $stmt->execute([$selected_class, $selected_date]);
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
                        <!-- Right: Date Picker -->
                        <div class="d-flex align-items-center">
                        <form class="d-flex align-items-center">
                            <label for="date" class="me-2">Date:</label>
                            <input type="date" class="form-control form-control-sm" id="date" name="date" 
                                value="<?php echo $selected_date; ?>" 
                                min="<?php echo $current_semester ? $current_semester['start_date'] : ''; ?>"
                                max="<?php echo ($current_semester && $today < $current_semester['end_date']) ? $today : $current_semester['end_date']; ?>"
                                onchange="this.form.submit()">
                            <input type="hidden" name="class_id" value="<?php echo $selected_class; ?>">
                            <button type="button" class="btn btn-primary btn-sm ms-2" id="todayBtn" <?php if (!$current_semester || $today < $current_semester['start_date'] || $today > $current_semester['end_date']) echo 'disabled'; ?>>Today</button>
                        </form>
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
                <button type="button" class="badge bg-success border-0" data-bs-toggle="modal" data-bs-target="#presentModal" style="cursor:pointer;">Present: <?php echo $attendance_summary['present']; ?></button>
                <button type="button" class="badge bg-danger border-0" data-bs-toggle="modal" data-bs-target="#absentModal" style="cursor:pointer;">Absent: <?php echo $attendance_summary['absent']; ?></button>
                <button type="button" class="badge bg-warning text-dark border-0" data-bs-toggle="modal" data-bs-target="#lateModal" style="cursor:pointer;">Late: <?php echo $attendance_summary['late']; ?></button>
                <button type="button" class="badge bg-info text-dark border-0" data-bs-toggle="modal" data-bs-target="#excusedModal" style="cursor:pointer;">Excused: <?php echo $attendance_summary['excused']; ?></button>
            </div>
        </div>
        <!-- End Search and Summary Row -->

        <!-- Attendance Form (POST) -->
        <form action="manage_attendance.php" method="POST">
            <input type="hidden" name="action" value="mark_attendance">
            <input type="hidden" name="class_id" value="<?php echo $selected_class; ?>">
            <input type="hidden" name="date" value="<?php echo $selected_date; ?>">
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
                                <select class="form-select attendance-select" name="attendance[<?php echo $student['id']; ?>]" required>
                                    <option value="" <?php echo !isset($existing_attendance[$student['id']]) ? 'selected' : ''; ?> disabled hidden>Select status</option>
                                    <option value="present" <?php echo (isset($existing_attendance[$student['id']]) && $existing_attendance[$student['id']] == 'present') ? 'selected' : ''; ?>>Present</option>
                                    <option value="absent" <?php echo (isset($existing_attendance[$student['id']]) && $existing_attendance[$student['id']] == 'absent') ? 'selected' : ''; ?>>Absent</option>
                                    <option value="late" <?php echo (isset($existing_attendance[$student['id']]) && $existing_attendance[$student['id']] == 'late') ? 'selected' : ''; ?>>Late</option>
                                    <option value="excused" <?php echo (isset($existing_attendance[$student['id']]) && $existing_attendance[$student['id']] == 'excused') ? 'selected' : ''; ?>>Excused</option>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="text-end mt-3">
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
    document.addEventListener('DOMContentLoaded', function() {
        // Color-code attendance status
        document.querySelectorAll('.attendance-select').forEach(select => {
            updateSelectStyle(select);
            select.addEventListener('change', function() {
                updateSelectStyle(this);
            });
        });
        
        function updateSelectStyle(select) {
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

        // Today button logic
        const todayBtn = document.getElementById('todayBtn');
        if (todayBtn) {
            todayBtn.addEventListener('click', function() {
                const dateInput = document.getElementById('date');
                const min = dateInput.min;
                const max = dateInput.max;
                const today = new Date().toISOString().split('T')[0];
                if (today >= min && today <= max) {
                    dateInput.value = today;
                    // Submit the form
                    dateInput.form.submit();
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
    <!-- End Modals for each status -->
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