<?php
session_start();
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - iAttendance</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
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
                    <span class="user-name">System Administrator</span>
                </a>
                <ul class="dropdown-menu user-dropdown" aria-labelledby="userDropdown">
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
                <h1 class="mb-4">Welcome, System Administrator!</h1>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="row">
            <div class="col-xl-3 col-md-6 mb-4">
                <a href="manage_teachers.php" style="text-decoration:none;color:inherit;">
                    <div class="card border-left-primary shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                        Total Teachers</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php
                                        try {
                                            $stmt = $pdo->query("SELECT COUNT(*) FROM teachers WHERE is_deleted = 0");
                                            echo $stmt->fetchColumn();
                                        } catch(PDOException $e) {
                                            echo "0";
                                        }
                                        ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="bi bi-person-badge fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-xl-3 col-md-6 mb-4">
                <a href="manage_students.php" style="text-decoration:none;color:inherit;">
                    <div class="card border-left-success shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                        Total Students</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php
                                        try {
                                            $stmt = $pdo->query("SELECT COUNT(*) FROM students WHERE section IS NOT NULL AND section != '' AND status = 'approved'");
                                            echo $stmt->fetchColumn();
                                        } catch(PDOException $e) {
                                            echo "0";
                                        }
                                        ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="bi bi-people fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-xl-3 col-md-6 mb-4">
                <a href="manage_sections.php" style="text-decoration:none;color:inherit;">
                    <div class="card border-left-warning shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                        Total Sections</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php
                                        try {
                                            $stmt = $pdo->query("SELECT COUNT(*) FROM sections");
                                            echo $stmt->fetchColumn();
                                        } catch(PDOException $e) {
                                            echo "0";
                                        }
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
        </div>

        <!-- Quick Links and Recent Activity -->
        <div class="row">
            <div class="col-lg-6 mb-4">
                <div class="card shadow h-100">
                    <div class="card-header bg-white border-0 pb-0">
                        <h5 class="mb-0">Recent Logins</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush">
                                    <?php
                            // Fetch last 5 logins from teachers and students only
                            try {
                                $logins = [];
                                $stmt = $pdo->query("SELECT teacher_id as username, 'Teacher' as type, last_login FROM teachers WHERE last_login IS NOT NULL");
                                foreach ($stmt->fetchAll() as $row) $logins[] = $row;
                                $stmt = $pdo->query("SELECT student_id as username, 'Student' as type, last_login FROM students WHERE last_login IS NOT NULL");
                                foreach ($stmt->fetchAll() as $row) $logins[] = $row;
                                usort($logins, function($a, $b) { return strtotime($b['last_login']) - strtotime($a['last_login']); });
                                $logins = array_slice($logins, 0, 5);
                                if (empty($logins)) {
                                    echo '<li class="list-group-item text-muted">No recent logins.</li>';
                                } else {
                                    foreach ($logins as $login) {
                                        echo '<li class="list-group-item">'
                                            . '<i class="bi bi-person-circle me-2"></i>'
                                            . htmlspecialchars($login['username']) . ' <span class="badge bg-secondary ms-2">' . $login['type'] . '</span>'
                                            . ' <span class="text-muted small">(' . date('M d, Y h:i A', strtotime($login['last_login'])) . ')</span>'
                                            . '</li>';
                                    }
                                }
                            } catch(PDOException $e) {
                                echo '<li class="list-group-item text-danger">Error loading logins: ' . htmlspecialchars($e->getMessage()) . '</li>';
                            }
                            ?>
                        </ul>
                                </div>
                            </div>
                            </div>
            <div class="col-lg-6 mb-4">
                <div class="card shadow h-100">
                    <div class="card-header bg-white border-0 pb-0">
                        <h5 class="mb-0">Pending Tasks</h5>
                        </div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush">
                            <?php
                            // Pending Applications
                            try {
                                $stmt = $pdo->query("SELECT COUNT(*) FROM students WHERE status = 'pending'");
                                $pendingApps = $stmt->fetchColumn();
                                if ($pendingApps > 0) {
                                    echo '<li class="list-group-item">'
                                        . '<i class="bi bi-hourglass-split text-info me-2"></i>'
                                        . 'Pending Applications: <strong>' . $pendingApps . '</strong>'
                                        . ' <a href="manage_applications.php" class="btn btn-sm btn-link">View</a></li>';
                                }
                            } catch(PDOException $e) {}
                            // Unassigned Subjects (subjects not assigned to any teacher)
                            try {
                                $stmt = $pdo->query("SELECT COUNT(*) FROM subjects s LEFT JOIN subject_assignments sa ON s.id = sa.subject_id WHERE sa.subject_id IS NULL");
                                $unassigned = $stmt->fetchColumn();
                                echo '<li class="list-group-item">'
                                    . '<i class="bi bi-journal-x text-warning me-2"></i>'
                                    . 'Unassigned Subjects: <strong>' . $unassigned . '</strong>'
                                    . ' <a href="manage_subjects.php" class="btn btn-sm btn-link">Assign</a></li>';
                            } catch(PDOException $e) {}
                            // You can add more pending tasks here
                            ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>
</body>
</html> 