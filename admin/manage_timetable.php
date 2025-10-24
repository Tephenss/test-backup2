<?php
session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    unset($_SESSION['form_data']);
}
require_once '../config/database.php';

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Get admin data
try {
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $admin = $stmt->fetch();
} catch(PDOException $e) {
    error_log("Error fetching admin data: " . $e->getMessage());
    $admin = null;
}

// Get all teachers for dropdown
try {
    $teachers = $pdo->query("SELECT id, full_name FROM teachers ORDER BY full_name")->fetchAll();
} catch(PDOException $e) {
    $teachers = [];
}

// Get all sections for dropdown
try {
    $sections = $pdo->query("SELECT id, section_name FROM sections ORDER BY section_name")->fetchAll();
} catch(PDOException $e) {
    $sections = [];
}

// Get all courses for dropdown
try {
    $courses = $pdo->query("SELECT id, course_name FROM courses ORDER BY course_name")->fetchAll();
} catch(PDOException $e) {
    $courses = [];
}

// Get all timetable entries
try {
    $timetables = $pdo->query("
        SELECT t.*, 
               cl.teacher_id,
               te.full_name as teacher_name,
               cl.section,
               s.id as section_id,
               s.name as section_name,
               c.name as course_name,
               c.code as course_code,
               sub.subject_code,
               sub.subject_name,
               sub.year_level AS subject_year_level,
               t.id as schedule_id
        FROM timetable t
        JOIN classes cl ON t.class_id = cl.id
        JOIN teachers te ON cl.teacher_id = te.id
        JOIN sections s ON cl.section = s.name
        JOIN courses c ON s.course_id = c.id
        JOIN subjects sub ON cl.subject_id = sub.id
        ORDER BY t.day_of_week, t.start_time
    ")->fetchAll();
} catch(PDOException $e) {
    $timetables = [];
}

// Get all unique year levels and sections for filter
$year_levels = $pdo->query("SELECT DISTINCT year_level FROM sections ORDER BY year_level")->fetchAll(PDO::FETCH_COLUMN);
$sections_by_year = [];
foreach ($pdo->query("SELECT id, name, year_level FROM sections ORDER BY year_level, name") as $row) {
    $sections_by_year[$row['year_level']][] = $row;
}

// Get filter from GET
$filter_year = isset($_GET['year']) ? (int)$_GET['year'] : ($year_levels[0] ?? null);
$filter_section = isset($_GET['section']) ? $_GET['section'] : ($sections_by_year[$filter_year][0]['name'] ?? null);

// Filter timetables for display
$filtered_timetable = [];
foreach ($timetables as $schedule) {
    if (
        $schedule['subject_year_level'] == $filter_year &&
        $schedule['section_name'] == $filter_section
    ) {
        $filtered_timetable[] = $schedule;
    }
}
// Rebuild $timetable array for grid rendering
$timetable = [];
foreach ($filtered_timetable as $schedule) {
    $dayNum = is_numeric($schedule['day_of_week']) ? (int)$schedule['day_of_week'] : array_search($schedule['day_of_week'], ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']) + 1;
    if ($dayNum >= 1 && $dayNum <= 6) {
        if (!isset($timetable[$dayNum])) {
            $timetable[$dayNum] = [];
        }
        $timetable[$dayNum][] = $schedule;
    }
}

// Get all subject assignments for teacher-section mapping
try {
    $assignments = $pdo->query("
        SELECT sa.teacher_id, sa.section_id, sec.name AS section_name, sec.year_level, sub.subject_name, sub.subject_code
        FROM subject_assignments sa
        JOIN sections sec ON sa.section_id = sec.id
        JOIN subjects sub ON sa.subject_id = sub.id
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $assignments = [];
}
$teacherSections = [];
foreach ($assignments as $a) {
    $teacherSections[$a['teacher_id']][] = [
        'section_id' => $a['section_id'],
        'section_name' => $a['section_name'],
        'year_level' => $a['year_level'],
        'subject_name' => $a['subject_name'],
        'subject_code' => $a['subject_code']
    ];
}
// DEBUG OUTPUT
if (isset($_GET['debug'])) {
    echo '<pre style="background:#fff;color:#000;z-index:9999;position:relative;">';
    print_r($teacherSections);
    echo '</pre>';
}

// Fetch all active classes for the class dropdown
$all_classes = $pdo->query("SELECT c.id, s.subject_code, s.subject_name, s.year_level, c.section, c.academic_year, c.semester, t.full_name as teacher_name FROM classes c JOIN subjects s ON c.subject_id = s.id JOIN teachers t ON c.teacher_id = t.id WHERE c.status = 'active' ORDER BY s.subject_code, c.section")->fetchAll(PDO::FETCH_ASSOC);

// Handle Add Timetable form submission
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['class_id'], $_POST['day_of_week'], $_POST['start_time'], $_POST['end_time'], $_POST['room'])
) {
    $class_id = $_POST['class_id'];
    $day_of_week = $_POST['day_of_week'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $room = $_POST['room'] === 'other' ? trim($_POST['other_room'] ?? '') : $_POST['room'];
    // PHP validation for 30-minute intervals
    $startMinutes = (int)explode(':', $start_time)[1];
    $endMinutes = (int)explode(':', $end_time)[1];
    $startTimestamp = strtotime($start_time);
    $endTimestamp = strtotime($end_time);
    // NEW: Enforce 7:00 AM to 7:00 PM time range
    if ($startTimestamp < strtotime('07:00') || $endTimestamp > strtotime('19:00')) {
        $_SESSION['error_message'] = 'Start and End Time must be between 7:00 AM and 7:00 PM.';
        $_SESSION['form_data'] = $_POST;
        header('Location: manage_timetable.php');
        exit();
    }
    if (!(($startMinutes === 0 || $startMinutes === 30) && ($endMinutes === 0 || $endMinutes === 30))) {
        $_SESSION['error_message'] = 'Start and end times must be on a 30-minute interval (e.g., 10:00, 10:30, 11:00, etc).';
        $_SESSION['form_data'] = $_POST;
        header('Location: manage_timetable.php');
        exit();
    }
    // Enforce minimum 1 hour duration
    if (($endTimestamp - $startTimestamp) < 3600) {
        $_SESSION['error_message'] = 'The minimum schedule duration is 1 hour.';
        $_SESSION['form_data'] = $_POST;
        header('Location: manage_timetable.php');
        exit();
    }
    // --- NEW: Prevent overlapping schedules for teacher or room (including custom room) ---
    $teacher_id = $teacherSections[$class_id][0]['teacher_id'];
    $day_of_week = $day_of_week;
    $startTime = $start_time;
    $endTime = $end_time;
    $room = $room;
    // 1. Check for overlap (corrected logic)
    $overlapStmt = $pdo->prepare('
        SELECT t.* FROM timetable t
        JOIN classes cl ON t.class_id = cl.id
        WHERE t.day_of_week = ?
          AND (
                (cl.teacher_id = ?)
             OR (t.room = ?)
          )
          AND (
                (t.start_time < ? AND t.end_time > ?)
          )
    ');
    $overlapStmt->execute([
        $day_of_week,
        $teacher_id,
        $room,
        $endTime, $startTime  // existing schedule starts before new ends AND existing ends after new starts
    ]);
    $overlap = $overlapStmt->fetch();
    if ($overlap) {
        $_SESSION['error_message'] = 'Conflict: The selected teacher or room already has a schedule that overlaps with this time on the same day.';
        $_SESSION['form_data'] = $_POST;
        header('Location: manage_timetable.php');
        exit();
    }
    // 2. NEW: Enforce 4-hour per day limit for teacher/subject
    $duration_new = (strtotime($endTime) - strtotime($startTime)) / 3600;
    // Get subject_id from the class
    $subjectStmt = $pdo->prepare('SELECT subject_id FROM classes WHERE id = ?');
    $subjectStmt->execute([$class_id]);
    $subject_id = $subjectStmt->fetchColumn();
    
    $hoursStmt = $pdo->prepare('
        SELECT t.start_time, t.end_time FROM timetable t
        JOIN classes cl ON t.class_id = cl.id
        WHERE t.day_of_week = ? AND cl.teacher_id = ? AND cl.subject_id = ?
    ');
    $hoursStmt->execute([$day_of_week, $teacher_id, $subject_id]);
    $total_hours = 0;
    foreach ($hoursStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $total_hours += (strtotime($row['end_time']) - strtotime($row['start_time'])) / 3600;
    }
    if (($total_hours + $duration_new) > 4) {
        $_SESSION['error_message'] = 'Limit reached: A teacher can only have up to 4 hours per day for the same subject.';
        $_SESSION['form_data'] = $_POST;
        header('Location: manage_timetable.php');
        exit();
    }
    // --- END NEW ---
    try {
            $stmt = $pdo->prepare("INSERT INTO timetable (class_id, day_of_week, start_time, end_time, room) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $class_id,
            $day_of_week,
            $start_time,
            $end_time,
            $room
            ]);
            $_SESSION['success_message'] = 'Schedule added successfully!';
        unset($_SESSION['form_data']);
        header('Location: manage_timetable.php');
            exit();
    } catch (PDOException $e) {
        $_SESSION['error_message'] = 'Error adding schedule: ' . $e->getMessage();
    unset($_SESSION['form_data']);
    header('Location: manage_timetable.php');
    exit();
    }
}
// Handle Delete Timetable form submission
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) && $_POST['action'] === 'delete' &&
    isset($_POST['id'])
) {
    try {
        $stmt = $pdo->prepare("DELETE FROM timetable WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $_SESSION['success_message'] = 'Schedule deleted successfully!';
    } catch (PDOException $e) {
        $_SESSION['error_message'] = 'Error deleting schedule: ' . $e->getMessage();
    }
    header('Location: manage_timetable.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Timetable - iAttendance</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
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
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 8px;
            margin: -1px;
            position: relative;
            overflow: hidden;
            transition: all 0.2s ease;
        }
        .schedule-item:hover {
            background-color: #e9ecef;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .schedule-actions {
            position: absolute;
            top: 8px;
            right: 8px;
            display: none;
        }
        .schedule-item:hover .schedule-actions {
            display: flex;
            gap: 4px;
        }
        .schedule-content {
            padding-top: 24px;
        }
        .schedule-header {
            margin-bottom: 8px;
        }
        .schedule-body {
            font-size: 0.875rem;
        }
        .schedule-body strong {
            color: #495057;
        }
        .schedule-body small {
            color: #6c757d;
        }
        .time-column {
            width: 100px;
            min-width: 100px;
            background-color: #f8f9fa;
            font-weight: 600;
        }
        .timetable-fade {
            opacity: 1;
            transition: opacity 0.4s;
        }
        .timetable-fade.hide {
            opacity: 0;
        }
        .modal-alert {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 1.08em;
            border-left: 7px solid #dc3545;
            background: linear-gradient(90deg, #fff0f1 80%, #ffe3e6 100%);
            color: #b02a37;
            box-shadow: 0 4px 16px rgba(220,53,69,0.10);
            padding: 1.1rem 1.3rem 1.1rem 1.1rem;
            margin-bottom: 1.2rem;
            border-radius: 12px;
            font-weight: 600;
            animation: fadeInAlert 0.4s;
            position: relative;
        }
        .modal-alert .bi {
            font-size: 2em;
            flex-shrink: 0;
            color: #dc3545;
        }
        .modal-alert .close-alert-btn {
            position: absolute;
            top: 10px;
            right: 14px;
            background: none;
            border: none;
            color: #b02a37;
            font-size: 1.3em;
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.2s;
        }
        .modal-alert .close-alert-btn:hover {
            opacity: 1;
        }
        .modal-alert.alert-success {
            border-left-color: #198754;
            background: #e9fbe8;
            color: #198754;
        }
        .modal-alert .bi {
            font-size: 1.3em;
            flex-shrink: 0;
        }
        @keyframes fadeInAlert {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .delete-modal-header {
            background: #fff0f1;
            border-bottom: none;
            border-radius: 12px 12px 0 0;
            padding-top: 1.5rem;
            padding-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .delete-modal-header .bi-exclamation-triangle-fill {
            color: #dc3545;
            font-size: 2.2rem;
            margin-right: 0.5rem;
        }
        .delete-modal-title {
            color: #dc3545;
            font-weight: 700;
            font-size: 1.35rem;
            margin-bottom: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .delete-modal-warning {
            background: #fff8e1;
            color: #b8860b;
            border-left: 5px solid #ffc107;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(255,193,7,0.07);
            padding: 0.85rem 1.1rem;
            font-size: 1.08em;
            margin-bottom: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.7rem;
        }
        .delete-modal-warning .bi {
            font-size: 1.3em;
            margin-right: 0.5rem;
        }
        .delete-modal-footer .btn-danger {
            font-weight: 600;
            font-size: 1.08em;
            padding: 0.6rem 1.4rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(220,53,69,0.07);
        }
        .delete-modal-footer .btn-secondary {
            font-size: 1.08em;
            padding: 0.6rem 1.4rem;
            border-radius: 8px;
        }
        /* Unified Modal Design for Admin */
        .modal-content {
            border-radius: 18px;
            box-shadow: 0 8px 32px rgba(44,62,80,0.13);
            border: none;
            background: #fff;
        }
        .modal-header {
            border-bottom: none;
            padding-bottom: 0.5rem;
            background: none;
        }
        .modal-title {
            font-size: 1.35rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #6c757d;
        }
        .modal-title i {
            color: #adb5bd;
            font-size: 1.4rem;
        }
        .modal-body {
            background: #f1f5f9;
            border-radius: 0 0 18px 18px;
            padding: 2rem 1.5rem 1.5rem 1.5rem;
        }
        .modal-footer {
            background: none;
            border-top: none;
            padding: 1.2rem 1.5rem 1.5rem 1.5rem;
            display: flex;
            gap: 0.7rem;
            justify-content: flex-end;
        }
        .btn-primary {
            font-weight: 700;
            padding: 0.55rem 2.2rem;
            border-radius: 1.5rem;
            font-size: 1.08rem;
            background: #6c757d;
            border: none;
            color: #fff;
            transition: background 0.18s, box-shadow 0.18s;
        }
        .btn-primary:hover {
            background: #495057;
            color: #fff;
        }
        .btn-secondary {
            font-weight: 600;
            padding: 0.55rem 1.5rem;
            border-radius: 1.5rem;
            font-size: 1.08rem;
            background: #e0e7ef !important;
            color: #444 !important;
            border: none;
            transition: background 0.18s, color 0.18s;
        }
        .btn-secondary:hover {
            background: #adb5bd !important;
            color: #222 !important;
        }
        .btn-danger {
            font-weight: 600;
            padding: 0.55rem 1.5rem;
            border-radius: 1.5rem;
            font-size: 1.08rem;
            background: #f87171;
            color: #fff;
            border: none;
            transition: background 0.18s, color 0.18s;
        }
        .btn-danger:hover {
            background: #dc2626;
            color: #fff;
        }
    </style>
</head>
<body class="admin-page">
    <!-- Sidebar -->
    <?php include 'sidebar.php'; ?>

    <main class="page-content">
        <!-- Topbar -->
        <div class="topbar">
            <button class="toggle-sidebar">
                <i class="bi bi-list"></i>
            </button>
            <div class="user-info dropdown">
                <a href="#" class="user-dropdown-toggle" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="user-avatar">AD</div>
                    <span class="user-name"><?php echo htmlspecialchars($admin['full_name'] ?? 'Administrator'); ?></span>
                </a>
                <ul class="dropdown-menu user-dropdown" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i> Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="../auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>
        <div class="container-fluid">
            <h1 class="h3 mb-4">Manage Timetable</h1>
            <div class="row mb-3">
                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <form method="get" id="filterForm" class="d-flex align-items-center gap-2 mb-0">
                            <select class="form-select" name="year" id="yearFilter" onchange="this.form.submit()" style="width:auto;">
                                <?php foreach (
                                    $year_levels as $year): ?>
                                    <option value="<?php echo $year; ?>" <?php if ($filter_year == $year) echo 'selected'; ?>><?php echo $year; ?><?php echo ($year == 1 ? 'st' : ($year == 2 ? 'nd' : ($year == 3 ? 'rd' : 'th'))); ?> Year</option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="section" id="sectionInput" value="<?php echo htmlspecialchars($filter_section); ?>">
                            <div class="btn-group" role="group" aria-label="Section Filter">
                                <?php foreach ($sections_by_year[$filter_year] as $section): ?>
                                    <button type="button" data-section="<?php echo htmlspecialchars($section['name']); ?>" class="btn btn-outline-primary<?php if ($filter_section == $section['name']) echo ' active'; ?> section-btn"><?php echo htmlspecialchars($section['name']); ?></button>
                                <?php endforeach; ?>
                            </div>
                        </form>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTimetableModal">
                            <i class="bi bi-plus-circle me-2"></i>Add Schedule
                        </button>
                    </div>
                </div>
            </div>
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success fade show" id="successAlert" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php 
                echo $_SESSION['success_message'];
                unset($_SESSION['success_message']);
                ?>
            </div>
        <?php endif; ?>
            <div class="card">
                <div class="card-body">
            <div id="timetableWrapper" class="table-responsive timetable-fade">
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
                                                    // Small X button
                                                    echo '<button type="button" class="btn-close btn-close-sm position-absolute top-0 end-0 m-1 delete-timetable" aria-label="Delete" data-id="' . $schedule['id'] . '" data-bs-toggle="modal" data-bs-target="#deleteTimetableModal" style="z-index:2;"></button>';
                                                    // Subject code
                                                    echo '<div style="font-weight:700;font-size:1.1em;">' . htmlspecialchars($schedule['subject_code']) . '</div>';
                                                    // Teacher (initial + last name)
                                                    $teacherNameParts = explode(' ', trim($schedule['teacher_name']));
                                                    $teacherInitial = '';
                                                    $teacherLastName = '';
                                                    if (count($teacherNameParts) > 0) {
                                                        $teacherInitial = strtoupper(substr($teacherNameParts[0], 0, 1));
                                                        $teacherLastName = ucfirst(strtolower(end($teacherNameParts)));
                                                    }
                                                    $shortTeacherName = $teacherInitial . '.' . $teacherLastName;
                                                    echo '<div style="color:#1976d2;font-size:1em;">' . htmlspecialchars($shortTeacherName) . '</div>';
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
                                                </div>
            </div>
        </div>
    </main>

    <!-- Add Timetable Modal -->
    <div class="modal fade" id="addTimetableModal" tabindex="-1" aria-labelledby="addTimetableModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addTimetableModalLabel">Add Schedule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="addTimetableForm" method="POST">
                    <div class="modal-body">
                    <?php if (isset($_SESSION['error_message'])): ?>
                        <div class="alert alert-danger fade show" id="addScheduleErrorAlert" style="font-size:1em;">
                            <?php echo $_SESSION['error_message']; ?>
                        </div>
                    <?php endif; ?>
                        <div class="mb-3">
                            <label for="class_id" class="form-label">Class</label>
                            <select class="form-select" id="class_id" name="class_id" required>
                                <option value="">Select Class</option>
                                <?php foreach ($all_classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>">
                                        <?php echo htmlspecialchars($class['subject_code'] . ' - ' . $class['subject_name'] . ' | Section: ' . $class['section'] . ' | Year: ' . $class['year_level'] . ' | ' . $class['teacher_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="day_of_week" class="form-label">Day</label>
                            <select class="form-select" id="day_of_week" name="day_of_week" required>
                                <option value="">Select Day</option>
                                <?php $days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
                                foreach ($days as $day): ?>
                                    <option value="<?php echo $day; ?>"><?php echo $day; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="start_time" class="form-label">Start Time</label>
                                <input type="time" class="form-control" id="start_time" name="start_time" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="end_time" class="form-label">End Time</label>
                                <input type="time" class="form-control" id="end_time" name="end_time" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="room" class="form-label">Room</label>
                            <select class="form-select" id="room" name="room" required>
                                <option value="">Select Room</option>
                                <option value="303">303</option>
                                <option value="304">304</option>
                                <option value="305">305</option>
                                <option value="306">306</option>
                                <option value="307">307</option>
                                <option value="308">308</option>
                                <option value="other">Other</option>
                            </select>
                            <input type="text" class="form-control mt-2" id="other_room" name="other_room" placeholder="Enter other room" style="display:none;">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Save Schedule</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Timetable Modal -->
    <div class="modal fade" id="editTimetableModal" tabindex="-1" aria-labelledby="editTimetableModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editTimetableModalLabel">Edit Schedule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="editTimetableForm" action="process_timetable.php" method="POST">
                    <input type="hidden" name="timetable_id" id="edit_timetable_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_teacher_id" class="form-label">Teacher</label>
                            <select class="form-select" id="edit_teacher_id" name="teacher_id" required>
                                <option value="">Select Teacher</option>
                                <?php foreach ($teachers as $teacher): ?>
                                    <option value="<?php echo $teacher['id']; ?>">
                                        <?php echo htmlspecialchars($teacher['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_section_id" class="form-label">Section</label>
                            <select class="form-select" id="edit_section_id" name="section_id" required>
                                <option value="">Select Section</option>
                                <?php foreach ($sections as $section): ?>
                                    <option value="<?php echo $section['id']; ?>">
                                        <?php echo htmlspecialchars($section['section_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_day_of_week" class="form-label">Day</label>
                            <select class="form-select" id="edit_day_of_week" name="day_of_week" required>
                                <option value="">Select Day</option>
                                <option value="Monday">Monday</option>
                                <option value="Tuesday">Tuesday</option>
                                <option value="Wednesday">Wednesday</option>
                                <option value="Thursday">Thursday</option>
                                <option value="Friday">Friday</option>
                                <option value="Saturday">Saturday</option>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_start_time" class="form-label">Start Time</label>
                                <input type="time" class="form-control" id="edit_start_time" name="start_time" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_end_time" class="form-label">End Time</label>
                                <input type="time" class="form-control" id="edit_end_time" name="end_time" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="edit_room" class="form-label">Room</label>
                            <select class="form-select" id="edit_room" name="room" required>
                                <option value="">Select Room</option>
                                <option value="303">303</option>
                                <option value="304">304</option>
                                <option value="305">305</option>
                                <option value="306">306</option>
                                <option value="307">307</option>
                                <option value="308">308</option>
                                <option value="other">Other</option>
                            </select>
                            <input type="text" class="form-control mt-2" id="edit_other_room" name="other_room" placeholder="Enter other room" style="display:none;">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Update Schedule</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Timetable Modal -->
    <div class="modal fade" id="deleteTimetableModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header delete-modal-header">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <h5 class="modal-title delete-modal-title">Delete Schedule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="delete_timetable_id">
                    <div class="modal-body">
                        <p class="mb-3">Are you sure you want to delete this schedule?</p>
                        <div class="delete-modal-warning">
                            <i class="bi bi-exclamation-circle"></i>
                            <span>This action cannot be undone.</span>
                        </div>
                    </div>
                    <div class="modal-footer delete-modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash me-2"></i>
                            Delete Schedule
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Move Bootstrap JS to the end of body -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const teacherSections = <?php echo json_encode($teacherSections); ?>;
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Handle edit modal data
            document.querySelectorAll('.edit-timetable').forEach(button => {
                button.addEventListener('click', function() {
                    const id = this.dataset.id;
                    const teacher = this.dataset.teacher;
                    const section = this.dataset.section;
                    const course = this.dataset.course;
                    const day = this.dataset.day;
                    const start = this.dataset.start;
                    const end = this.dataset.end;
                    const room = this.dataset.room;

                    document.getElementById('edit_timetable_id').value = id;
                    document.getElementById('edit_teacher_id').value = teacher;
                    document.getElementById('edit_section_id').value = section;
                    document.getElementById('edit_day_of_week').value = day;
                    document.getElementById('edit_start_time').value = start;
                    document.getElementById('edit_end_time').value = end;
                    document.getElementById('edit_room').value = room;
                });
            });

            // Handle delete modal data
            document.querySelectorAll('.delete-timetable').forEach(button => {
                button.addEventListener('click', function() {
                    const id = this.dataset.id;
                    document.getElementById('delete_timetable_id').value = id;
                });
            });

        // Add Timetable Form validation
        const addForm = document.getElementById('addTimetableForm');
        const roomSelect = document.getElementById('room');
        const otherRoomInput = document.getElementById('other_room');
        const startTimeInput = document.getElementById('start_time');
        const endTimeInput = document.getElementById('end_time');

        function isTimeInRange(timeStr) {
                if (!timeStr) return false;
            const [h, m] = timeStr.split(':').map(Number);
            const minutes = h * 60 + m;
            return minutes >= 420 && minutes <= 1140; // 7:00 (420) to 19:00 (1140)
        }

        function validateTimeRangeFields() {
            const startVal = startTimeInput.value;
            const endVal = endTimeInput.value;
            const validStart = isTimeInRange(startVal);
            const validEnd = isTimeInRange(endVal);
            // Remove any existing note
            let note = endTimeInput.parentElement.querySelector('.invalid-feedback.time-range-note');
            if (note) note.remove();
            if (!validStart || !validEnd) {
                startTimeInput.classList.add('is-invalid');
                endTimeInput.classList.add('is-invalid');
                // Add note only if not present
                note = document.createElement('div');
                note.className = 'invalid-feedback d-block time-range-note';
                note.innerText = 'Start and End Time must be between 7:00 AM and 7:00 PM.';
                endTimeInput.parentElement.appendChild(note);
                    return false;
                } else {
                startTimeInput.classList.remove('is-invalid');
                endTimeInput.classList.remove('is-invalid');
                // Note already removed above
                return true;
            }
        }

            if (addForm) {
                addForm.addEventListener('submit', function(e) {
                if (!validateTimeRangeFields()) {
                        e.preventDefault();
                        return false;
                    }
                // ...existing validation...
            });
            startTimeInput.addEventListener('input', validateTimeRangeFields);
            endTimeInput.addEventListener('input', validateTimeRangeFields);
            }

            // Show PHP session alerts in modal if present
            <?php if (isset($_SESSION['error_message'])): ?>
                // Show the Add Schedule modal automatically
                var addModal = new bootstrap.Modal(document.getElementById('addTimetableModal'));
                addModal.show();
                showModalAlert('addTimetableModal', <?php echo json_encode($_SESSION['error_message']); ?>, 'danger');
            <?php unset($_SESSION['error_message']); unset($_SESSION['form_data']); endif; ?>
            <?php if (isset($_SESSION['success_message'])): ?>
                showModalAlert('addTimetableModal', <?php echo json_encode($_SESSION['success_message']); ?>, 'success');
            <?php unset($_SESSION['success_message']); endif; ?>

            // Auto-dismiss alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert:not(.alert-warning):not(.modal-alert)');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.classList.remove('show');
                    setTimeout(() => alert.remove(), 150);
                }, 5000);
            });

        // Dynamic Section and Subject dropdown for Add modal
        document.getElementById('teacher_id').addEventListener('change', function() {
            const teacherId = this.value;
            const sectionSelect = document.getElementById('section_id');
            const subjectSelect = document.getElementById('subject_id');
            sectionSelect.innerHTML = '<option value="">Select Section</option>';
            subjectSelect.innerHTML = '<option value="">Select Subject</option>';
            if (teacherSections[teacherId]) {
                // Create a Set to store unique sections
                const uniqueSections = new Set();
                teacherSections[teacherId].forEach(sec => {
                    // Only add each section once
                    const sectionKey = `${sec.section_id}-${sec.year_level}-${sec.section_name}`;
                    if (!uniqueSections.has(sectionKey)) {
                        uniqueSections.add(sectionKey);
                        const opt = document.createElement('option');
                        opt.value = sec.section_id;
                        opt.textContent = `Year ${sec.year_level} - ${sec.section_name}`;
                        sectionSelect.appendChild(opt);
                    }
                });
            }
        });
        document.getElementById('section_id').addEventListener('change', function() {
            const teacherId = document.getElementById('teacher_id').value;
            const sectionId = this.value;
            const subjectSelect = document.getElementById('subject_id');
            subjectSelect.innerHTML = '<option value="">Select Subject</option>';
            if (teacherSections[teacherId]) {
                teacherSections[teacherId].forEach(sec => {
                    if (sec.section_id == sectionId) {
                        const opt = document.createElement('option');
                        opt.value = sec.subject_code;
                        opt.textContent = sec.subject_name + ' (' + sec.subject_code + ')';
                        opt.setAttribute('data-subject-id', sec.subject_code);
                        subjectSelect.appendChild(opt);
                    }
                });
            }
        });
        });

        // Smooth transition for filter
        const filterForm = document.getElementById('filterForm');
        const timetableWrapper = document.getElementById('timetableWrapper');
        const sectionInput = document.getElementById('sectionInput');
        document.querySelectorAll('.section-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                if (sectionInput) sectionInput.value = this.getAttribute('data-section');
                if (filterForm && timetableWrapper) {
                    timetableWrapper.classList.add('hide');
                    setTimeout(() => {
                        filterForm.submit();
                    }, 400);
                } else {
                    filterForm.submit();
                }
            });
        });
        if (filterForm && timetableWrapper) {
            filterForm.addEventListener('submit', function(e) {
                // Only fade if not already fading from section button
                if (!filterForm.classList.contains('section-fade')) {
                    e.preventDefault();
                    timetableWrapper.classList.add('hide');
                    setTimeout(() => {
                        filterForm.submit();
                    }, 400);
                }
            });
            // On page load, fade in
            timetableWrapper.classList.add('hide');
            setTimeout(() => {
                timetableWrapper.classList.remove('hide');
            }, 50);
        }

        // Add Timetable Modal: Show/hide other room input
        const roomSelect = document.getElementById('room');
        const otherRoomInput = document.getElementById('other_room');
        if (roomSelect && otherRoomInput) {
            roomSelect.addEventListener('change', function() {
                if (this.value === 'other') {
                    otherRoomInput.style.display = '';
                    otherRoomInput.required = true;
                } else {
                    otherRoomInput.style.display = 'none';
                    otherRoomInput.required = false;
                }
            });
        }
        // Edit Timetable Modal: Show/hide other room input
        const editRoomSelect = document.getElementById('edit_room');
        const editOtherRoomInput = document.getElementById('edit_other_room');
        if (editRoomSelect && editOtherRoomInput) {
            editRoomSelect.addEventListener('change', function() {
                if (this.value === 'other') {
                    editOtherRoomInput.style.display = '';
                    editOtherRoomInput.required = true;
                } else {
                    editOtherRoomInput.style.display = 'none';
                    editOtherRoomInput.required = false;
                }
            });
        }
        // On edit modal show, set the correct room/other value
        const editTimetableModal = document.getElementById('editTimetableModal');
        if (editTimetableModal) {
            editTimetableModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const roomValue = button ? button.getAttribute('data-room') : '';
                if (editRoomSelect && editOtherRoomInput) {
                    if (["303","304","305","306","307","308"].includes(roomValue)) {
                        editRoomSelect.value = roomValue;
                        editOtherRoomInput.style.display = 'none';
                        editOtherRoomInput.value = '';
                    } else if (roomValue) {
                        editRoomSelect.value = 'other';
                        editOtherRoomInput.style.display = '';
                        editOtherRoomInput.value = roomValue;
                    } else {
                        editRoomSelect.value = '';
                        editOtherRoomInput.style.display = 'none';
                        editOtherRoomInput.value = '';
                    }
                }
            });
        }
        // On submit, if 'Other' is selected, copy value to main room input
        const editForm = document.getElementById('editTimetableForm');
        if (editForm) {
            editForm.addEventListener('submit', function(e) {
                if (editRoomSelect.value === 'other') {
                    editRoomSelect.value = editOtherRoomInput.value;
                }
            });
        }

    // Clear Add Schedule form when modal is closed
    const addTimetableModal = document.getElementById('addTimetableModal');
    if (addTimetableModal) {
        addTimetableModal.addEventListener('hidden.bs.modal', function() {
            const form = document.getElementById('addTimetableForm');
            if (form) {
                form.reset();
                // Also clear custom room input
                const otherRoomInput = document.getElementById('other_room');
                if (otherRoomInput) {
                    otherRoomInput.value = '';
                    otherRoomInput.style.display = 'none';
                }
            }
        });
        }

    // Auto-dismiss Add Schedule error alert after 3 seconds
    setTimeout(function() {
        var errorAlert = document.getElementById('addScheduleErrorAlert');
        if (errorAlert) {
            errorAlert.classList.remove('show');
            setTimeout(function() {
                errorAlert.remove();
            }, 300);
        }
    }, 3000);

    // Restrict time pickers to only allow minutes 00 and 30
    function restrictTimePickerMinutes(inputId) {
        const input = document.getElementById(inputId);
        if (!input) return;
        input.addEventListener('input', function() {
            let [h, m] = this.value.split(':');
            if (m !== '00' && m !== '30') {
                // Round to nearest 00 or 30
                if (m < 15) m = '00';
                else if (m < 45) m = '30';
                else {
                    m = '00';
                    h = (parseInt(h, 10) + 1).toString().padStart(2, '0');
                }
                this.value = h + ':' + m;
            }
        });
    }
    restrictTimePickerMinutes('start_time');
    restrictTimePickerMinutes('end_time');
    restrictTimePickerMinutes('edit_start_time');
    restrictTimePickerMinutes('edit_end_time');
    </script>
</body>
</html> 