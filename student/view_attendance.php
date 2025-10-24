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

// Fetch current semester and term ranges
$current_semester = $pdo->query("SELECT * FROM semester_settings WHERE is_current = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$term_periods = [
    'Prelim' => ['start' => $current_semester['prelim_start'] ?? null, 'end' => $current_semester['prelim_end'] ?? null],
    'Midterm' => ['start' => $current_semester['midterm_start'] ?? null, 'end' => $current_semester['midterm_end'] ?? null],
    'Final' => ['start' => $current_semester['final_start'] ?? null, 'end' => $current_semester['final_end'] ?? null],
];
$semester_start = $current_semester['start_date'] ?? null;
$semester_end = $current_semester['end_date'] ?? null;
$selected_term = isset($_GET['term']) ? $_GET['term'] : 'All';

// Determine filter range
if ($selected_term !== 'All' && isset($term_periods[$selected_term])) {
    $start_date = $term_periods[$selected_term]['start'];
    $end_date = $term_periods[$selected_term]['end'];
} else {
    $start_date = $semester_start;
    $end_date = $semester_end;
}

// Add error handling for database queries
try {
    // Get student's attendance records for enrolled classes (latest per class per date)
    $stmt = $pdo->prepare("
        SELECT 
            a.*,
            teachers.full_name as teacher_name,
            CONCAT(s.subject_code, ' - ', c.section) as class_name,
            s.subject_name,
            TIME_FORMAT(a.created_at, '%H:%i') as time
        FROM attendance a 
        JOIN classes c ON a.class_id = c.id
        JOIN subjects s ON c.subject_id = s.id
        JOIN teachers ON c.teacher_id = teachers.id 
        JOIN class_students cs ON (c.id = cs.class_id AND cs.student_id = ?)
        WHERE a.student_id = ? 
        AND a.date BETWEEN ? AND ?
        AND cs.status = 'active'
        AND c.status = 'active'
        AND a.id = (
            SELECT id FROM attendance 
            WHERE student_id = a.student_id AND class_id = a.class_id AND date = a.date
            ORDER BY created_at DESC, id DESC LIMIT 1
        )
        ORDER BY a.date DESC, a.created_at DESC
    ");
    
    $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $start_date, $end_date]);
    $attendance_records = $stmt->fetchAll();

    // Uncomment for debugging: Show number of enrollments and attendance records
    // $debug_stmt = $pdo->prepare("SELECT COUNT(*) FROM class_students WHERE student_id = ? AND status = 'active'");
    // $debug_stmt->execute([$_SESSION['user_id']]);
    // $enrollment_count = $debug_stmt->fetchColumn();
    // error_log('Active enrollments for student: ' . $enrollment_count);
    // error_log('Attendance records fetched: ' . count($attendance_records));

    // Calculate attendance statistics based on filtered records (latest per class per date only)
    $present_days = 0;
    $absent_days = 0;
    $late_days = 0;
    $excused_days = 0;
    foreach ($attendance_records as $record) {
        switch ($record['status']) {
            case 'present':
                $present_days++;
                break;
            case 'absent':
                $absent_days++;
                break;
            case 'late':
                $late_days++;
                break;
            case 'excused':
                $excused_days++;
                break;
        }
    }
    $total_days = count($attendance_records);
    $attendance_percentage = $total_days > 0 ? round((($present_days + $late_days) * 100) / $total_days, 2) : 0;
} catch (PDOException $e) {
    // Log the error and show a user-friendly message
    error_log("Database Query Error: " . $e->getMessage());
    $_SESSION['error'] = "Sorry, there was a problem retrieving your attendance records. Please try again later.";
    $attendance_records = [];
    $total_days = $present_days = $absent_days = $late_days = $excused_days = $attendance_percentage = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Attendance - Student Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <style>
        .attendance-summary {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
            margin-bottom: 12px;
            align-items: center;
        }
        .attendance-summary .summary-item {
            background: #f4f7ff;
            border-radius: 8px;
            padding: 10px 18px;
            font-weight: 600;
            color: #333;
            min-width: 120px;
            text-align: center;
            box-shadow: 0 1px 4px rgba(60,60,100,0.07);
        }
        .attendance-summary .present { color: #198754; }
        .attendance-summary .absent { color: #dc3545; }
        .attendance-summary .late { color: #ffc107; }
        .attendance-summary .percent { color: #0d6efd; }
        .filter-row-sticky {
            position: sticky;
            top: 0;
            z-index: 10;
            background: #fff;
            box-shadow: 0 2px 8px rgba(60,60,100,0.04);
            border-radius: 12px 12px 0 0;
        }
        .badge-status {
            font-size: 1em;
            padding: 0.45em 1.1em;
            border-radius: 1em;
            font-weight: 700;
            letter-spacing: 0.5px;
            display: inline-block;
        }
        .badge-present { background: #e6f9ed; color: #198754; }
        .badge-absent { background: #fde6e9; color: #dc3545; }
        .badge-late { background: #fff8e1; color: #ffc107; }
        .badge-excused { background: #e6f4fa; color: #0dcaf0; }
        .attendance-table tr.today-row { background: #eaf6ff !important; }
        @media (max-width: 700px) {
            .attendance-summary { flex-direction: column; gap: 8px; }
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
                <a class="nav-link active" href="view_attendance.php">
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
            
            <div class="user-info">
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

        <!-- Content -->
        <div class="container-fluid">
            <h1 class="h3 mb-4">My Attendance</h1>

        <!-- Filter Row -->
            <div class="row filter-row-sticky align-items-center mb-3 px-2 py-2">
                <div class="col-auto">
                    <span class="badge bg-success">Current Semester: <?php echo htmlspecialchars($current_semester['semester'] ?? 'N/A'); ?></span>
                    <span class="badge bg-info text-dark ms-2">Date: <?php echo date('M d, Y', strtotime($semester_start)); ?> - <?php echo date('M d, Y', strtotime($semester_end)); ?></span>
                </div>
                <div class="col-auto ms-auto">
                    <form method="get" class="d-flex align-items-center gap-2">
                        <label for="term" class="form-label mb-0 me-2">Term:</label>
                        <select class="form-select" id="term" name="term" onchange="this.form.submit()">
                            <option value="All" <?php if ($selected_term === 'All') echo 'selected'; ?>>All</option>
                            <option value="Prelim" <?php if ($selected_term === 'Prelim') echo 'selected'; ?>>Prelim</option>
                            <option value="Midterm" <?php if ($selected_term === 'Midterm') echo 'selected'; ?>>Midterm</option>
                            <option value="Final" <?php if ($selected_term === 'Final') echo 'selected'; ?>>Final</option>
                        </select>
                    </form>
                </div>
            </div>

            <!-- Date Range Selection -->
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center filter-row-sticky">
                    <div class="attendance-summary">
                        <div class="summary-item present">Present: <?php echo $present_days; ?></div>
                        <div class="summary-item absent">Absent: <?php echo $absent_days; ?></div>
                        <div class="summary-item late">Late: <?php echo $late_days; ?></div>
                        <div class="summary-item percent">Attendance: <?php echo $attendance_percentage; ?>%</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover attendance-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Subject</th>
                                    <th>Status</th>
                                    <th>Marked By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendance_records as $record): ?>
                                <?php $is_today = (date('Y-m-d') === $record['date']); ?>
                                <tr<?php if ($is_today) echo ' class="today-row"'; ?>>
                                    <td><?php echo date('d M Y', strtotime($record['date'])); ?></td>
                                    <td>
                                        <?php 
                                        if ($record['time']) {
                                            echo date('h:i A', strtotime($record['time']));
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($record['class_name']); ?></strong><br>
                                        <small><?php echo htmlspecialchars($record['subject_name']); ?></small>
                                    </td>
                                    <td>
                                        <?php
                                        $status_class = '';
                                        $badge_class = '';
                                        $status_icon = '';
                                        switch ($record['status']) {
                                            case 'present':
                                                $status_class = 'text-success';
                                                $badge_class = 'badge-status badge-present';
                                                $status_icon = 'bi-check-circle-fill';
                                                break;
                                            case 'absent':
                                                $status_class = 'text-danger';
                                                $badge_class = 'badge-status badge-absent';
                                                $status_icon = 'bi-x-circle-fill';
                                                break;
                                            case 'late':
                                                $status_class = 'text-warning';
                                                $badge_class = 'badge-status badge-late';
                                                $status_icon = 'bi-clock-fill';
                                                break;
                                            case 'excused':
                                                $status_class = 'text-info';
                                                $badge_class = 'badge-status badge-excused';
                                                $status_icon = 'bi-info-circle-fill';
                                                break;
                                        }
                                        ?>
                                        <span class="<?php echo $badge_class; ?>">
                                            <i class="bi <?php echo $status_icon; ?> me-1"></i>
                                            <?php echo ucfirst($record['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($record['teacher_name']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($attendance_records)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">No attendance records found for your assigned classes. If you think this is an error, please contact your teacher or admin.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar Toggle
        document.querySelector('.toggle-sidebar').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('toggled');
            document.querySelector('.page-content').classList.toggle('expanded');
        });

        // Auto-hide success message
        const alertElement = document.querySelector('.alert');
        if (alertElement) {
            setTimeout(() => {
                alertElement.classList.add('fade-out');
                setTimeout(() => alertElement.remove(), 500);
            }, 3000);
        }
    </script>
</body>
</html>