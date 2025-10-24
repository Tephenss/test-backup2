<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'teacher') {
    header("Location: ../index.php");
    exit();
}

// Check if PDO object exists
if (!isset($pdo) || !($pdo instanceof PDO)) {
    die("Database connection error");
}

// Add cache control headers
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

try {
    // Get user's name for display
    $fullName = $_SESSION['full_name'] ?? $_SESSION['username'];
    $initials = '';
    $nameParts = explode(' ', $fullName);
    if (count($nameParts) >= 2) {
        $initials = strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[count($nameParts) - 1], 0, 1));
    } else {
        $initials = strtoupper(substr($fullName, 0, 2));
    }

    // Fetch current user info for avatar display
    $stmt = $pdo->prepare("SELECT * FROM teachers WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $shortName = '';
    if (!empty($user['first_name']) && !empty($user['last_name'])) {
        $shortName = strtoupper(substr(trim($user['first_name']), 0, 1)) . '.' . ucfirst(strtolower(trim($user['last_name'])));
    } else {
        $shortName = htmlspecialchars($user['full_name'] ?? '');
    }

    // Get available classes for the teacher
    $stmt = $pdo->prepare("
        SELECT c.id,
               s.subject_code,
               s.year_level,
               c.section,
               CONCAT(s.subject_code, '-', s.year_level, c.section) as class_name,
               CONCAT(s.subject_code, ' - ', c.section, ' (', s.year_level, 'st Year)') as class_desc
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
    $available_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $selected_class = isset($_GET['class_id']) 
        ? $_GET['class_id'] 
        : (!empty($available_classes) ? $available_classes[0]['id'] : null);
} catch(PDOException $e) {
    $available_classes = [];
    $selected_class = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard - Attendance Management System</title>
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
                <a class="nav-link active" href="dashboard.php">
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
                <h1 class="h3 mb-4">Welcome, <?php echo $shortName; ?>!</h1>
            </div>
        </div>
        
        <!-- Stats Cards -->
        <div class="row mb-4">
            <a href="manage_attendance.php" style="text-decoration:none;" class="col-xl-4 col-md-6 mb-4">
                <div class="card border-left-success shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Subjects</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?php
                                    // Count subjects assigned to teacher (from subject_assignments)
                                    try {
                                        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT sa.subject_id) FROM subject_assignments sa WHERE sa.teacher_id = ?");
                                        $stmt->execute([$_SESSION['user_id']]);
                                        echo $stmt->fetchColumn();
                                    } catch(PDOException $e) { echo "0"; }
                                    ?>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="bi bi-journal-text fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
            <a href="manage_students.php" style="text-decoration:none;" class="col-xl-4 col-md-6 mb-4">
                <div class="card border-left-warning shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Total Sections</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?php
                                    // Count sections assigned to teacher (from subject_assignments)
                                    try {
                                        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT sa.section_id) FROM subject_assignments sa WHERE sa.teacher_id = ?");
                                        $stmt->execute([$_SESSION['user_id']]);
                                        echo $stmt->fetchColumn();
                                    } catch(PDOException $e) { echo "0"; }
                                    ?>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="bi bi-diagram-3 fa-2x text-gray-300"></i>
                    </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        
        <!-- Pending Tasks and Recent Activities -->
        <div class="row">
            <div class="col-lg-6 mb-4">
                <div class="card shadow h-100">
                    <div class="card-header bg-white border-0 pb-0">
                        <h5 class="mb-0">Pending Tasks</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush">
                            <?php
                            // Ungraded Marks (marks with NULL or empty value for teacher's classes)
                            try {
                                $stmt = $pdo->prepare("SELECT COUNT(*) FROM marks m JOIN classes c ON m.class_id = c.id WHERE c.teacher_id = ? AND (m.marks IS NULL OR m.marks = '')");
                                $stmt->execute([$_SESSION['user_id']]);
                                $ungraded = $stmt->fetchColumn();
                                if ($ungraded > 0) {
                                    echo '<li class="list-group-item">'
                                        . '<i class="bi bi-exclamation-circle text-danger me-2"></i>'
                                        . 'Ungraded Marks: <strong>' . $ungraded . '</strong>'
                                        . ' <a href="manage_marks.php" class="btn btn-sm btn-link">Grade Now</a></li>';
                                }
                            } catch(PDOException $e) {}
                            // Unmarked Attendance (classes today with no attendance record)
                            try {
                                $today = date('Y-m-d');
                                $stmt = $pdo->prepare("SELECT COUNT(*) FROM classes c WHERE c.teacher_id = ? AND NOT EXISTS (SELECT 1 FROM attendance a WHERE a.class_id = c.id AND a.date = ?)");
                                $stmt->execute([$_SESSION['user_id'], $today]);
                                $unmarked = $stmt->fetchColumn();
                                if ($unmarked > 0) {
                                    echo '<li class="list-group-item">'
                                        . '<i class="bi bi-calendar-x text-warning me-2"></i>'
                                        . 'Unmarked Attendance Today: <strong>' . $unmarked . '</strong>'
                                        . ' <a href="manage_attendance.php" class="btn btn-sm btn-link">Mark Now</a></li>';
                                }
                            } catch(PDOException $e) {}
                            ?>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-lg-6 mb-4">
                <div class="card shadow h-100">
                    <div class="card-header bg-white border-0 pb-0">
                        <h5 class="mb-0">Recent Activities</h5>
                    </div>
                    <div class="card-body">
                        <!-- You can make this dynamic by fetching from a logs table if available -->
                        <div class="activity-item d-flex">
                            <div class="activity-dot bg-success"></div>
                            <div class="activity-content">
                                <h6 class="mb-1">Attendance marked for a class</h6>
                                <small class="text-muted">Today</small>
                            </div>
                        </div>
                        <div class="activity-item d-flex">
                            <div class="activity-dot bg-primary"></div>
                            <div class="activity-content">
                                <h6 class="mb-1">New student added to a class</h6>
                                <small class="text-muted">Yesterday</small>
                            </div>
                        </div>
                        <div class="activity-item d-flex">
                            <div class="activity-dot bg-info"></div>
                            <div class="activity-content">
                                <h6 class="mb-1">Updated timetable</h6>
                                <small class="text-muted">Yesterday</small>
                            </div>
                        </div>
                        <div class="activity-item d-flex">
                            <div class="activity-dot bg-warning"></div>
                            <div class="activity-content">
                                <h6 class="mb-1">Marks added for an exam</h6>
                                <small class="text-muted">2 days ago</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <footer class="sticky-footer mt-5">
            <div class="container">
                <div class="copyright text-center">
                    <span> 2023 iAttendance System</span>
        </div>
    </div>
        </footer>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>
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