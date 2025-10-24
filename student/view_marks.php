<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: ../index.php");
    exit();
}

// Get student's marks
$stmt = $pdo->prepare("
    SELECT 
        m.*,
        CONCAT(s.subject_code, ' - ', c.section) as class_name,
        s.subject_name,
        at.name as assessment_type,
        teachers.full_name as teacher_name,
        m.date as assessment_date
    FROM marks m
    JOIN classes c ON m.class_id = c.id
    JOIN subjects s ON c.subject_id = s.id
    JOIN assessment_types at ON m.assessment_type_id = at.id
    JOIN teachers ON c.teacher_id = teachers.id
    WHERE m.student_id = ?
    ORDER BY m.date DESC, s.subject_name
");
$stmt->execute([$_SESSION['user_id']]);
$marks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate overall statistics
$totalMarks = 0;
$totalPossibleMarks = 0;
$subjectStats = [];

foreach ($marks as $mark) {
    $totalMarks += $mark['marks'];
    $totalPossibleMarks += $mark['total_marks'];
    
    // Calculate per subject statistics
    $subjectKey = $mark['subject_name'];
    if (!isset($subjectStats[$subjectKey])) {
        $subjectStats[$subjectKey] = [
            'total_marks' => 0,
            'total_possible' => 0,
            'count' => 0
        ];
    }
    $subjectStats[$subjectKey]['total_marks'] += $mark['marks'];
    $subjectStats[$subjectKey]['total_possible'] += $mark['total_marks'];
    $subjectStats[$subjectKey]['count']++;
}

$overallPercentage = $totalPossibleMarks > 0 ? ($totalMarks / $totalPossibleMarks) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Marks - Attendance Management System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
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
                <a class="nav-link active" href="view_marks.php">
                    <i class="bi bi-journal-text"></i>
                    <span>My Marks</span>
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
                    <div class="user-avatar"><?php echo htmlspecialchars(substr($_SESSION['full_name'] ?? $_SESSION['username'], 0, 2)); ?></div>
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
                <h1 class="h3">My Marks</h1>
            </div>

            <!-- Overall Performance Card -->
            <div class="row mb-4">
                <div class="col-lg-4 mb-4">
                    <div class="card shadow h-100 animate-fadeIn delay-1">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold">Overall Performance</h6>
                        </div>
                        <div class="card-body">
                            <div class="chart-pie pt-4 pb-2">
                                <div class="d-flex justify-content-center align-items-center">
                                    <div class="display-4 text-primary">
                                        <?php echo number_format($overallPercentage, 1); ?>%
                                    </div>
                                </div>
                            </div>
                            <div class="mt-4 text-center small">
                                <span class="mr-2">
                                    Total Marks: <?php echo $totalMarks; ?> / <?php echo $totalPossibleMarks; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Subject-wise Performance -->
                <div class="col-lg-8 mb-4">
                    <div class="card shadow h-100 animate-fadeIn delay-1">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold">Subject-wise Performance</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Subject</th>
                                            <th>Average</th>
                                            <th>Total Marks</th>
                                            <th>Assessments</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($subjectStats as $subject => $stats): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($subject); ?></td>
                                                <td>
                                                    <?php 
                                                    $avg = ($stats['total_marks'] / $stats['total_possible']) * 100;
                                                    echo number_format($avg, 1) . '%';
                                                    ?>
                                                </td>
                                                <td><?php echo $stats['total_marks']; ?> / <?php echo $stats['total_possible']; ?></td>
                                                <td><?php echo $stats['count']; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Detailed Marks Table -->
            <div class="card shadow mb-4 animate-fadeIn delay-2">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold">Detailed Marks History</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Subject</th>
                                    <th>Assessment Type</th>
                                    <th>Marks</th>
                                    <th>Percentage</th>
                                    <th>Teacher</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($marks as $mark): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($mark['assessment_date'])); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($mark['class_name']); ?></strong><br>
                                            <small><?php echo htmlspecialchars($mark['subject_name']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($mark['assessment_type']); ?></td>
                                        <td><?php echo $mark['marks']; ?> / <?php echo $mark['total_marks']; ?></td>
                                        <td>
                                            <?php 
                                            $percentage = ($mark['marks'] / $mark['total_marks']) * 100;
                                            echo number_format($percentage, 1) . '%';
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($mark['teacher_name']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
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
