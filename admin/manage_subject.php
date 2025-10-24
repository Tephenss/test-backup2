<?php
require_once '../config/database.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: login.php');
    exit();
}

// Fetch all subjects
$subjects = $pdo->query("SELECT * FROM subjects ORDER BY subject_code") ->fetchAll();
// Fetch all teachers
$teachers = $pdo->query("SELECT id, full_name FROM teachers ORDER BY full_name")->fetchAll();

// Handle assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_subject'])) {
    $subject_id = $_POST['subject_id'];
    $teacher_id = $_POST['teacher_id'];
    $stmt = $pdo->prepare("UPDATE subjects SET teacher_id = ? WHERE id = ?");
    $stmt->execute([$teacher_id, $subject_id]);
    $_SESSION['success_message'] = "Subject assigned to teacher successfully!";
    header('Location: manage_subject.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Subjects - iAttendance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .page-content { min-height: 100vh; }
        .card { border-radius: 15px; box-shadow: 0 0 20px rgba(0,0,0,0.07); }
        .table thead th { background: #f5f8fa; }
    </style>
</head>
<body class="admin-page">
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
            <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a></li>
            <li class="nav-item"><a class="nav-link" href="manage_teachers.php"><i class="bi bi-person-badge"></i><span>Teachers</span></a></li>
            <li class="nav-item"><a class="nav-link" href="manage_students.php"><i class="bi bi-people"></i><span>Students</span></a></li>
            <li class="nav-item"><a class="nav-link" href="manage_applications.php"><i class="bi bi-file-earmark-text"></i><span>Applications</span></a></li>
            <li class="nav-item"><a class="nav-link active" href="manage_subject.php"><i class="bi bi-journal-bookmark"></i><span>Subjects</span></a></li>
            <li class="nav-item"><a class="nav-link" href="manage_sections.php"><i class="bi bi-diagram-3"></i><span>Sections</span></a></li>
            <li class="nav-item"><a class="nav-link" href="manage_timetable.php"><i class="bi bi-calendar-week"></i><span>Timetable</span></a></li>
        </ul>
    </aside>
    <main class="page-content">
        <div class="topbar">
            <button class="toggle-sidebar"><i class="bi bi-list"></i></button>
            <div class="user-info dropdown">
                <a href="#" class="user-dropdown-toggle" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="user-avatar">AD</div>
                    <span class="user-name">System Administrator</span>
                </a>
                <ul class="dropdown-menu user-dropdown" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i> Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="../auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>
        <div class="container-fluid px-4">
            <h2 class="mb-4 mt-4">Manage Subjects</h2>
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"> <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?> </div>
            <?php endif; ?>
            <div class="card p-4">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Subject Code</th>
                                <th>Subject Name</th>
                                <th>Assigned Teacher</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $subject): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($subject['subject_code']); ?></td>
                                <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                <td>
                                    <?php
                                    $stmt = $pdo->prepare("SELECT full_name FROM teachers WHERE id = ?");
                                    $stmt->execute([$subject['teacher_id'] ?? 0]);
                                    $teacher = $stmt->fetchColumn();
                                    echo $teacher ? htmlspecialchars($teacher) : '<span class="text-muted">Unassigned</span>';
                                    ?>
                                </td>
                                <td>
                                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#assignModal<?php echo $subject['id']; ?>">Assign</button>
                                    <!-- Modal -->
                                    <div class="modal fade" id="assignModal<?php echo $subject['id']; ?>" tabindex="-1" aria-labelledby="assignModalLabel<?php echo $subject['id']; ?>" aria-hidden="true">
                                      <div class="modal-dialog">
                                        <div class="modal-content">
                                          <form method="POST">
                                            <div class="modal-header">
                                              <h5 class="modal-title" id="assignModalLabel<?php echo $subject['id']; ?>">Assign Teacher to <?php echo htmlspecialchars($subject['subject_code']); ?></h5>
                                              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body">
                                              <input type="hidden" name="subject_id" value="<?php echo $subject['id']; ?>">
                                              <div class="mb-3">
                                                <label for="teacher_id<?php echo $subject['id']; ?>" class="form-label">Select Teacher</label>
                                                <select class="form-select" name="teacher_id" id="teacher_id<?php echo $subject['id']; ?>" required>
                                                  <option value="">-- Select Teacher --</option>
                                                  <?php foreach ($teachers as $teacher): ?>
                                                    <option value="<?php echo $teacher['id']; ?>" <?php if (($subject['teacher_id'] ?? 0) == $teacher['id']) echo 'selected'; ?>><?php echo htmlspecialchars($teacher['full_name']); ?></option>
                                                  <?php endforeach; ?>
                                                </select>
                                              </div>
                                            </div>
                                            <div class="modal-footer">
                                              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                              <button type="submit" name="assign_subject" class="btn btn-primary">Assign</button>
                                            </div>
                                          </form>
                                        </div>
                                      </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>
</body>
</html> 