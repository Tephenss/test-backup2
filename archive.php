<?php
session_start();
require_once 'config/database.php';
require_once 'config.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// Get admin info
$admin_id = $_SESSION['user_id'];
$admin_query = "SELECT * FROM teachers WHERE id = ?";
$stmt = $pdo->prepare($admin_query);
$stmt->execute([$admin_id]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

// Function to fetch deleted records
function fetchDeletedRecords($table) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE is_deleted = 1 ORDER BY deleted_at DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        error_log("Error fetching deleted records from $table: " . $e->getMessage());
        return false;
    }
}

// Function to restore a record
function restoreRecord($table, $id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("UPDATE $table SET is_deleted = 0, deleted_at = NULL WHERE id = ?");
        return $stmt->execute([$id]);
    } catch(PDOException $e) {
        error_log("Error restoring record from $table: " . $e->getMessage());
        return false;
    }
}

// Handle restore action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restore') {
    $table = $_POST['table'];
    $id = $_POST['id'];
    if (restoreRecord($table, $id)) {
        $_SESSION['success_message'] = "Record restored successfully.";
    } else {
        $_SESSION['error_message'] = "Error restoring record.";
    }
    header("Location: archive.php");
    exit();
}

// Fetch deleted records
$deletedTeachers = fetchDeletedRecords('teachers');
$deletedStudents = fetchDeletedRecords('students');
$deletedSubjects = fetchDeletedRecords('subjects');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archive Management - iAttendance</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/dashboard.css" rel="stylesheet">
    <style>
        .archive-section {
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 0 20px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
            overflow: hidden;
        }
        .archive-header {
            background: #f8f9fa;
            padding: 1.5rem;
            border-bottom: 1px solid #e9ecef;
        }
        .archive-title {
            color: #344767;
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .archive-title i {
            color: #5e72e4;
        }
        .archive-body {
            padding: 1.5rem;
        }
        .table {
            margin-bottom: 0;
        }
        .table th {
            font-weight: 600;
            color: #344767;
            border-bottom-width: 1px;
        }
        .table td {
            vertical-align: middle;
        }
        .btn-restore {
            background: #5e72e4;
            color: #fff;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.2s;
        }
        .btn-restore:hover {
            background: #4a5cd1;
            color: #fff;
            transform: translateY(-1px);
        }
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #6c757d;
        }
        .empty-state i {
            font-size: 3rem;
            color: #dee2e6;
            margin-bottom: 1rem;
        }
        .empty-state p {
            margin: 0;
            font-size: 1.1rem;
        }
        .badge {
            padding: 0.5em 0.75em;
            font-weight: 500;
        }
        .badge-teacher {
            background: #e3f2fd;
            color: #1976d2;
        }
        .badge-student {
            background: #e8f5e9;
            color: #2e7d32;
        }
        .badge-subject {
            background: #fff3e0;
            color: #f57c00;
        }
    </style>
</head>
<body class="admin-page">
    <!-- Sidebar -->
    <?php include 'admin/sidebar.php'; ?>
    
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
                    <li><a class="dropdown-item" href="admin/profile.php"><i class="bi bi-person me-2"></i> Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>

        <div class="container-fluid">
            <div class="row mb-4">
                <div class="col">
                    <h1 class="h3 mb-0 text-gray-800">Archive Management</h1>
                    <p class="text-muted">View and restore deleted records from the system.</p>
                </div>
            </div>

            <!-- Session Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Teachers Archive -->
            <div class="archive-section">
                <div class="archive-header">
                    <h2 class="archive-title">
                        <i class="bi bi-person-badge"></i>
                        Deleted Teachers
                    </h2>
                </div>
                <div class="archive-body">
                    <?php if (empty($deletedTeachers)): ?>
                        <div class="empty-state">
                            <i class="bi bi-archive"></i>
                            <p>No deleted teachers found</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Teacher ID</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Course</th>
                                        <th>Deleted Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($deletedTeachers as $teacher): ?>
                                        <tr>
                                            <td><span class="badge badge-teacher"><?php echo htmlspecialchars($teacher['teacher_id']); ?></span></td>
                                            <td><?php echo htmlspecialchars($teacher['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($teacher['email']); ?></td>
                                            <td><?php echo htmlspecialchars($teacher['course']); ?></td>
                                            <td><?php echo date('M d, Y h:i A', strtotime($teacher['deleted_at'])); ?></td>
                                            <td>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="restore">
                                                    <input type="hidden" name="table" value="teachers">
                                                    <input type="hidden" name="id" value="<?php echo $teacher['id']; ?>">
                                                    <button type="submit" class="btn btn-restore">
                                                        <i class="bi bi-arrow-counterclockwise me-1"></i> Restore
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Students Archive -->
            <div class="archive-section">
                <div class="archive-header">
                    <h2 class="archive-title">
                        <i class="bi bi-mortarboard"></i>
                        Deleted Students
                    </h2>
                </div>
                <div class="archive-body">
                    <?php if (empty($deletedStudents)): ?>
                        <div class="empty-state">
                            <i class="bi bi-archive"></i>
                            <p>No deleted students found</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Student ID</th>
                                        <th>Name</th>
                                        <th>Course</th>
                                        <th>Section</th>
                                        <th>Deleted Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($deletedStudents as $student): ?>
                                        <tr>
                                            <td><span class="badge badge-student"><?php echo htmlspecialchars($student['student_id']); ?></span></td>
                                            <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($student['course']); ?></td>
                                            <td><?php echo htmlspecialchars($student['section']); ?></td>
                                            <td><?php echo date('M d, Y h:i A', strtotime($student['deleted_at'])); ?></td>
                                            <td>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="restore">
                                                    <input type="hidden" name="table" value="students">
                                                    <input type="hidden" name="id" value="<?php echo $student['id']; ?>">
                                                    <button type="submit" class="btn btn-restore">
                                                        <i class="bi bi-arrow-counterclockwise me-1"></i> Restore
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Subjects Archive -->
            <div class="archive-section">
                <div class="archive-header">
                    <h2 class="archive-title">
                        <i class="bi bi-book"></i>
                        Deleted Subjects
                    </h2>
                </div>
                <div class="archive-body">
                    <?php if (empty($deletedSubjects)): ?>
                        <div class="empty-state">
                            <i class="bi bi-archive"></i>
                            <p>No deleted subjects found</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Subject Code</th>
                                        <th>Subject Name</th>
                                        <th>Units</th>
                                        <th>Year Level</th>
                                        <th>Deleted Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($deletedSubjects as $subject): ?>
                                        <tr>
                                            <td><span class="badge badge-subject"><?php echo htmlspecialchars($subject['subject_code']); ?></span></td>
                                            <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                            <td><?php echo htmlspecialchars($subject['units']); ?></td>
                                            <td><?php echo htmlspecialchars($subject['year_level']); ?></td>
                                            <td><?php echo date('M d, Y h:i A', strtotime($subject['deleted_at'])); ?></td>
                                            <td>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="restore">
                                                    <input type="hidden" name="table" value="subjects">
                                                    <input type="hidden" name="id" value="<?php echo $subject['id']; ?>">
                                                    <button type="submit" class="btn btn-restore">
                                                        <i class="bi bi-arrow-counterclockwise me-1"></i> Restore
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-dismiss alerts
        window.setTimeout(function() {
            let alert = document.querySelector('.alert-dismissible');
            if(alert) {
                new bootstrap.Alert(alert).close();
            }
        }, 5000);
    </script>
</body>
</html> 