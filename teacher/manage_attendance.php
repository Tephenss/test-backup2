<?php
session_start();
// Set the timezone to Asia/Manila
date_default_timezone_set('Asia/Manila');
require_once '../config/database.php';
require_once '../config.php';
require_once '../helpers/BackupHooks.php';
require_once '../helpers/RfidHelper.php';

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
                    // Verify student is enrolled in this class
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
                    
                    // Check if attendance already exists for this date
                    $existingStmt = $pdo->prepare("SELECT id FROM attendance WHERE class_id = ? AND student_id = ? AND date = ?");
                    $existingStmt->execute([$class_id, $student_id, $date]);
                    $existing = $existingStmt->fetch();
                    
                    if ($existing) {
                        // Update existing attendance
                        $updateStmt = $pdo->prepare("UPDATE attendance SET status = ?, recorded_by = ? WHERE id = ?");
                        $updateStmt->execute([$status, $_SESSION['user_id'], $existing['id']]);
                        $attendance_id = $existing['id'];
                    } else {
                        // Insert new attendance
                        $insertStmt = $pdo->prepare("INSERT INTO attendance (class_id, student_id, date, status, recorded_by) VALUES (?, ?, ?, ?, ?)");
                        $insertStmt->execute([$class_id, $student_id, $date, $status, $_SESSION['user_id']]);
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
                
                try {
                    // Verify class belongs to teacher
                    $classStmt = $pdo->prepare("SELECT id FROM classes WHERE id = ? AND teacher_id = ?");
                    $classStmt->execute([$class_id, $_SESSION['user_id']]);
                    if (!$classStmt->fetch()) {
                        echo json_encode(['success' => false, 'message' => 'Class not found or access denied.']);
                        exit;
                    }
                    
                    // Get attendance record ID before deleting (for Firebase backup)
                    $getStmt = $pdo->prepare("SELECT id FROM attendance WHERE class_id = ? AND student_id = ? AND date = ?");
                    $getStmt->execute([$class_id, $student_id, $date]);
                    $attendanceRecord = $getStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($attendanceRecord) {
                        // Delete attendance record
                        $deleteStmt = $pdo->prepare("DELETE FROM attendance WHERE class_id = ? AND student_id = ? AND date = ?");
                        $deleteStmt->execute([$class_id, $student_id, $date]);
                        
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
        }
    }
    
    // Only redirect if it's not an AJAX request (check for JSON content type)
    if (!isset($_SERVER['HTTP_ACCEPT']) || strpos($_SERVER['HTTP_ACCEPT'], 'application/json') === false) {
        // Force a full reload to ensure real-time accuracy
        echo '<meta http-equiv="refresh" content="0;url=manage_attendance.php">';
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
                <button type="button" class="badge bg-success border-0" data-bs-toggle="modal" data-bs-target="#presentModal" style="cursor:pointer;" id="presentBadge">Present: <span id="presentCount"><?php echo $attendance_summary['present']; ?></span></button>
                <button type="button" class="badge bg-danger border-0" data-bs-toggle="modal" data-bs-target="#absentModal" style="cursor:pointer;" id="absentBadge">Absent: <span id="absentCount"><?php echo $attendance_summary['absent']; ?></span></button>
                <button type="button" class="badge bg-warning text-dark border-0" data-bs-toggle="modal" data-bs-target="#lateModal" style="cursor:pointer;" id="lateBadge">Late: <span id="lateCount"><?php echo $attendance_summary['late']; ?></span></button>
                <button type="button" class="badge bg-info text-dark border-0" data-bs-toggle="modal" data-bs-target="#excusedModal" style="cursor:pointer;" id="excusedBadge">Excused: <span id="excusedCount"><?php echo $attendance_summary['excused']; ?></span></button>
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
                                <select class="form-select attendance-select" name="attendance[<?php echo $student['id']; ?>]" data-student-id="<?php echo $student['id']; ?>" required>
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
            <div class="d-flex justify-content-between align-items-center mt-3">
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-success" 
                            data-bs-toggle="modal" 
                            data-bs-target="#rfidAttendanceModal" 
                            id="rfidAttendanceBtn"
                            <?php if (!$selected_class): ?>disabled title="Please select a class first"<?php endif; ?>>
                        <i class="bi bi-upc-scan me-1"></i> Take Attendance via RFID
                    </button>
                    <button type="button" class="btn btn-warning" 
                            id="markAbsentBtn"
                            <?php if (!$selected_class): ?>disabled title="Please select a class first"<?php endif; ?>>
                        <i class="bi bi-x-circle me-1"></i> Mark Absent Students
                    </button>
                </div>
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
        // Color-code attendance status
        document.querySelectorAll('.attendance-select').forEach(select => {
            updateSelectStyle(select);
            select.addEventListener('change', function() {
                updateSelectStyle(this);
            });
        });

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
                    
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <span class="text-muted">Scanner Status:</span>
                            <span class="badge bg-secondary ms-2" id="rfidScannerStatus">Idle</span>
                        </div>
                        <button type="button" class="btn btn-success btn-sm" id="startRfidScannerBtn">
                            <i class="bi bi-broadcast-pin me-1"></i>Start Scanner
                        </button>
                    </div>
                    
                    <div id="rfidWaitingArea" class="text-center py-5 border rounded bg-light">
                        <i class="bi bi-upc-scan" style="font-size: 4rem; color: #ccc;"></i>
                        <p class="text-muted mt-3 mb-0">Waiting for RFID tap...</p>
                        <small class="text-muted">I-tap ang RFID card sa scanner</small>
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
    
    // RFID Attendance Scanner
    (function() {
        const scannerUrl = '<?php echo htmlspecialchars($scannerUrl); ?>';
        const classId = <?php echo $selected_class ?: 'null'; ?>;
        const attendanceDate = '<?php echo htmlspecialchars($selected_date); ?>';
        
        let isScanning = false;
        let pollTimer = null;
        let lastScanId = null;
        let lastScanTimestamp = null;
        let modalOpenTime = null;
        let currentStudent = null;
        
        const refs = {
            modal: document.getElementById('rfidAttendanceModal'),
            startBtn: document.getElementById('startRfidScannerBtn'),
            statusBadge: document.getElementById('rfidScannerStatus'),
            waitingArea: document.getElementById('rfidWaitingArea'),
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
        
        function showAlert(type, message) {
            if (!refs.alertContainer) return;
            refs.alertContainer.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
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
            refs.startBtn.classList.remove('btn-success');
            refs.startBtn.classList.add('btn-danger');
            refs.statusBadge.textContent = 'Scanning...';
            refs.statusBadge.classList.remove('bg-secondary');
            refs.statusBadge.classList.add('bg-success');
            
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
            refs.startBtn.classList.add('btn-success');
            refs.statusBadge.textContent = 'Idle';
            refs.statusBadge.classList.remove('bg-success');
            refs.statusBadge.classList.add('bg-secondary');
            
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
                return;
            }
            
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
                        // Reset to waiting state
                        currentStudent = null;
                        refs.waitingArea.style.display = 'block';
                        refs.studentInfo.style.display = 'none';
                        return;
                    }
                    
                    displayStudentInfo(data.student, data.attendance_status, data.schedule, data.current_time, data.existing_attendance, data.warning);
                } else {
                    showAlert('warning', data.message || 'Student not found or not enrolled in this class.');
                    // Reset to waiting state on error
                    currentStudent = null;
                    refs.waitingArea.style.display = 'block';
                    refs.studentInfo.style.display = 'none';
                }
            })
            .catch(err => {
                console.error('Error fetching student:', err);
                showAlert('danger', 'Error fetching student information.');
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
            let selectElement = document.querySelector(`select.attendance-select[data-student-id="${studentId}"]`);
            let oldStatus = null;
            
            // If not found with data attribute, try alternative selector
            if (!selectElement) {
                selectElement = document.querySelector(`select[name="attendance[${studentId}]"]`);
            }
            
            if (selectElement) {
                // Get old status BEFORE updating
                oldStatus = selectElement.value && selectElement.value !== '' ? selectElement.value : null;
                
                // Update the dropdown value
                selectElement.value = status;
                
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
            if (!oldStatus || oldStatus !== newStatus) {
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
                return;
            }
            
            // Get automatic status from student data
            const status = currentStudent.attendanceStatus?.status;
            if (!status || status === 'too_early' || status === 'too_late' || status === 'manual') {
                // For manual or invalid status, use 'present' as fallback
                const finalStatus = (status === 'manual') ? 'present' : status;
                if (finalStatus === 'too_early' || finalStatus === 'too_late') {
                    showAlert('danger', 'Cannot record attendance: ' + (currentStudent.attendanceStatus?.message || 'Invalid status'));
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
                }
                refs.acceptBtn.disabled = false;
                refs.declineBtn.disabled = false;
            });
        }
        
        // Event listeners
        if (refs.startBtn) {
            refs.startBtn.addEventListener('click', function() {
                if (isScanning) {
                    stopScanner();
                } else {
                    startScanner();
                }
            });
        }
        
        if (refs.acceptBtn) {
            refs.acceptBtn.addEventListener('click', function() {
                recordAttendance();
            });
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
        
        // Mark Absent Students button
        const markAbsentBtn = document.getElementById('markAbsentBtn');
        if (markAbsentBtn) {
            markAbsentBtn.addEventListener('click', function() {
                if (!confirm('Mark all students who did not tap during the attendance window as absent? This will only mark students who have not recorded attendance yet.')) {
                    return;
                }
                
                markAbsentBtn.disabled = true;
                markAbsentBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Marking...';
                
                fetch(`rfid_attendance_api.php?action=mark_absent_students&class_id=${classId}&date=${attendanceDate}`, {
                    credentials: 'same-origin'
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert(`Successfully marked ${data.marked_count} students as absent.`);
                        window.location.reload();
                    } else {
                        alert(data.message || 'Failed to mark absent students.');
                        markAbsentBtn.disabled = false;
                        markAbsentBtn.innerHTML = '<i class="bi bi-x-circle me-1"></i> Mark Absent Students';
                    }
                })
                .catch(err => {
                    console.error('Error marking absent students:', err);
                    alert('Error marking absent students.');
                    markAbsentBtn.disabled = false;
                    markAbsentBtn.innerHTML = '<i class="bi bi-x-circle me-1"></i> Mark Absent Students';
                });
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