<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: ../index.php");
    exit();
}

// Add cache control headers
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

try {
    // Get student's data
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $student = $stmt->fetch();

    // Fetch current semester and its date range
    $current_semester = $pdo->query("SELECT * FROM semester_settings WHERE is_current = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $semester_start = $current_semester['start_date'] ?? null;
    $semester_end = $current_semester['end_date'] ?? null;

    // Get today's classes (using timetable)
    $dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    $todayDayName = $dayNames[date('N')];
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as today_classes 
        FROM timetable t
        JOIN classes c ON t.class_id = c.id
        JOIN class_students cs ON c.id = cs.class_id
        WHERE cs.student_id = ?
          AND LOWER(t.day_of_week) = LOWER(?)
          AND t.start_time IS NOT NULL
          AND t.end_time IS NOT NULL
          AND c.status = 'active'
          AND cs.status = 'active'
          AND ? BETWEEN ? AND ?
    ");
    $stmt->execute([
        $_SESSION['user_id'],
        $todayDayName,
        $today,
        $semester_start,
        $semester_end
    ]);
    $today_classes = (int)($stmt->fetch()['today_classes'] ?? 0);

    // Get total scheduled classes for the semester
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total_scheduled
        FROM timetable t
        JOIN classes c ON t.class_id = c.id
        JOIN class_students cs ON c.id = cs.class_id
        WHERE cs.student_id = ?
          AND t.start_time IS NOT NULL
          AND t.end_time IS NOT NULL
          AND c.status = 'active'
          AND cs.status = 'active'
          AND ? BETWEEN ? AND ?
    ");
    $stmt->execute([
        $_SESSION['user_id'],
        $semester_start,
        $semester_start,
        $semester_end
    ]);
    $total_scheduled = (int)($stmt->fetch()['total_scheduled'] ?? 0);

    // Get attendance records for the semester
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as attended_days
        FROM attendance 
        WHERE student_id = ?
          AND date BETWEEN ? AND ?
    ");
    $stmt->execute([
        $_SESSION['user_id'],
        $semester_start,
        $semester_end
    ]);
    $attended_days = (int)($stmt->fetch()['attended_days'] ?? 0);

    $attendance_rate = $total_scheduled > 0
        ? round(($attended_days / $total_scheduled) * 100)
        : 0;

    // Get average grade
    $stmt = $pdo->prepare("
        SELECT AVG(mark) as avg_grade 
        FROM marks 
        WHERE student_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $avg_grade = $stmt->fetch()['avg_grade'];
    $grade_letter = 'N/A';
    if ($avg_grade !== null) {
        if ($avg_grade >= 90) $grade_letter = 'A';
        elseif ($avg_grade >= 80) $grade_letter = 'B+';
        elseif ($avg_grade >= 70) $grade_letter = 'B';
        elseif ($avg_grade >= 60) $grade_letter = 'C';
        else $grade_letter = 'D';
    }

    // Get upcoming tests
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as upcoming_tests 
        FROM tests 
        WHERE class_id IN (
            SELECT class_id FROM class_students WHERE student_id = ?
        ) AND test_date > CURDATE()
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $upcoming_tests = $stmt->fetch()['upcoming_tests'];

} catch (PDOException $e) {
    error_log("Dashboard Data Error: " . $e->getMessage());
    $_SESSION['error'] = "There was a problem loading your dashboard data. Please try again later.";
    $today_classes = 0;
    $attendance_rate = 0;
    $grade_letter = 'N/A';
    $upcoming_tests = 0;
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
    <title>Student Dashboard - Attendance Management System</title>
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
        .dashboard-cards-row {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 32px;
            margin-bottom: 36px;
        }
        .stat-card {
            min-width: 260px;
            max-width: 340px;
            flex: 1 1 260px;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(60,60,100,0.07);
            background: #fff;
            border: none;
            transition: box-shadow 0.2s;
        }
        .stat-card:hover {
            box-shadow: 0 4px 18px rgba(60,60,100,0.13);
        }
        .stat-card .card-body {
            padding: 28px 22px 22px 22px;
        }
        .stat-card .text-xs {
            font-size: 1.08em;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .stat-card .h5 {
            font-size: 2.1em;
            font-weight: 800;
            margin-top: 8px;
        }
        .stat-card .col-auto i {
            font-size: 2.2rem;
        }
        .features-row {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 32px;
        }
        .feature-card {
            min-width: 320px;
            max-width: 400px;
            flex: 1 1 320px;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(60,60,100,0.07);
            background: #fff;
            border: none;
            margin-bottom: 24px;
            display: flex;
            flex-direction: column;
            align-items: stretch;
        }
        .feature-card .card-header {
            border-radius: 16px 16px 0 0;
            background: #f4f7ff;
            font-weight: 700;
            font-size: 1.1em;
            border-bottom: 1px solid #e0e6ed;
        }
        .feature-card .card-body {
            padding: 28px 22px 22px 22px;
        }
        .feature-card .card-icon {
            font-size: 2.5em;
            margin-bottom: 12px;
        }
        .feature-card .btn {
            margin-top: 18px;
            font-weight: 600;
            border-radius: 8px;
            padding: 8px 24px;
        }
        @media (max-width: 900px) {
            .dashboard-cards-row, .features-row {
                flex-direction: column;
                gap: 18px;
            }
            .stat-card, .feature-card {
                max-width: 100%;
                min-width: 0;
            }
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
                <a class="nav-link active" href="dashboard.php">
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

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success fade show" id="loginAlert" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php 
                echo $_SESSION['success_message'];
                unset($_SESSION['success_message']);
                ?>
            </div>
        <?php endif; ?>

        <!-- Welcome Section -->
        <div class="row animate-fadeIn">
            <div class="col-12">
                <h1 class="mb-4">Welcome, <?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?>!</h1>
            </div>
        </div>
        
        <!-- Features Section -->
        <div class="features-row">
            <div class="feature-card animate-fadeIn delay-2">
                    <div class="card-header py-3">
                    My Attendance
                    </div>
                    <div class="card-body text-center">
                        <div class="card-icon mb-3">
                            <i class="bi bi-calendar-check text-primary"></i>
                        </div>
                        <h5 class="card-title">Attendance Records</h5>
                        <p class="card-text">View and track your attendance records across all classes.</p>
                        <a href="view_attendance.php" class="btn btn-primary">View Attendance</a>
                    </div>
                </div>
            <div class="feature-card animate-fadeIn delay-4">
                    <div class="card-header py-3">
                    Timetable
                    </div>
                    <div class="card-body text-center">
                        <div class="card-icon mb-3">
                            <i class="bi bi-clock text-info"></i>
                        </div>
                        <h5 class="card-title">Class Schedule</h5>
                        <p class="card-text">View your weekly class schedule and room assignments.</p>
                        <a href="view_timetable.php" class="btn btn-info">View Timetable</a>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <footer class="sticky-footer mt-5">
            <div class="container">
                <div class="copyright text-center">
                    <span>© <?php echo date('Y'); ?> iAttendance System</span>
        </div>
    </div>
        </footer>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>
</body>
</html> 