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

// Function to safely prepare and execute statements
function prepareAndExecute($pdo, $query, $params = []) {
    $stmt = $pdo->prepare($query);
    if ($stmt === false) {
        throw new PDOException("Failed to prepare statement");
    }
    if (!$stmt->execute($params)) {
        throw new PDOException("Failed to execute statement");
    }
    return $stmt;
}

try {
// Get available classes for the teacher
    $stmt = prepareAndExecute($pdo, "
    SELECT c.id, CONCAT(s.subject_code, ' - ', c.section, ' (', c.academic_year, ' Sem ', c.semester, ')') as class_name
    FROM classes c 
    JOIN subjects s ON c.subject_id = s.id
    JOIN sections sec ON c.section = sec.name AND s.year_level = sec.year_level
    WHERE c.teacher_id = ? AND c.status = 'active'
    ORDER BY s.subject_code, c.section
    ", [$_SESSION['user_id']]);
$available_classes = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Get available courses
    $stmt = prepareAndExecute($pdo, "SELECT DISTINCT course FROM students ORDER BY course");
$available_courses = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get available sections
    $stmt = prepareAndExecute($pdo, "SELECT DISTINCT section FROM students ORDER BY section");
$available_sections = $stmt->fetchAll(PDO::FETCH_COLUMN);

} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                if (empty($_POST['student_id'])) {
                    $_SESSION['error'] = "Student ID is required";
                } else {
                    try {
                        // Check if transaction is already active
                        if (!$pdo->inTransaction()) {
                            $pdo->beginTransaction();
                        }
                        
                        $defaultPassword = password_hash($_POST['student_id'], PASSWORD_DEFAULT);
                        
                        // Insert student
                        $stmt = prepareAndExecute($pdo, 
                            "INSERT INTO students (username, password, full_name, student_id, email, course, year_level, section) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                            [
                            $_POST['username'],
                            $defaultPassword,
                            $_POST['full_name'],
                            $_POST['student_id'],
                            $_POST['email'],
                            $_POST['course'],
                            $_POST['year_level'],
                            $_POST['section']
                            ]
                        );
                        
                        $student_id = $pdo->lastInsertId();
                        
                        // Enroll student in selected classes
                        if (!empty($_POST['classes']) && is_array($_POST['classes'])) {
                            $enrollStmt = $pdo->prepare("INSERT INTO class_students (class_id, student_id) VALUES (?, ?)");
                            if ($enrollStmt === false) {
                                throw new PDOException("Failed to prepare enrollment statement");
                            }
                            
                            foreach ($_POST['classes'] as $class_id) {
                                if (!$enrollStmt->execute([$class_id, $student_id])) {
                                    throw new PDOException("Failed to enroll student in class");
                                }
                            }
                        }
                        
                        if ($pdo->inTransaction()) {
                            $pdo->commit();
                        }
                        $_SESSION['success'] = "Student added successfully";
                    } catch(PDOException $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $_SESSION['error'] = "Error adding student: " . $e->getMessage();
                    }
                }
                break;

            case 'edit':
                try {
                    // Check if transaction is already active
                    if (!$pdo->inTransaction()) {
                        $pdo->beginTransaction();
                    }

                    // Update student information
                    $stmt = prepareAndExecute($pdo, "
                        UPDATE students 
                        SET full_name = ?,
                            email = ?,
                            course = ?,
                            year_level = ?,
                            section = ?,
                            student_id = ?
                        WHERE id = ?
                    ", [
                        $_POST['full_name'],
                        $_POST['email'],
                        $_POST['course'],
                        $_POST['year_level'],
                        $_POST['section'],
                        $_POST['student_id'],
                        $_POST['id']
                    ]);

                    // Update password if provided
                        if (!empty($_POST['password'])) {
                            $hashedPassword = password_hash($_POST['password'], PASSWORD_DEFAULT);
                        prepareAndExecute($pdo, "UPDATE students SET password = ? WHERE id = ?", 
                            [$hashedPassword, $_POST['id']]
                        );
                        
                        // Backup password change to Firebase
                        try {
                            require_once '../helpers/BackupHooks.php';
                            $backupHooks = new BackupHooks();
                            $updatedData = [
                                'password_changed_at' => date('Y-m-d H:i:s'),
                                'changed_by' => 'teacher'
                            ];
                            $backupHooks->backupStudentPasswordChange($_POST['id'], $updatedData);
                        } catch (Exception $e) {
                            error_log("Firebase backup failed for teacher password change: " . $e->getMessage());
                        }
                    }

                    // Update class enrollments if provided
                    if (isset($_POST['classes'])) {
                        // First remove all existing enrollments
                        prepareAndExecute($pdo, "DELETE FROM class_students WHERE student_id = ?", 
                            [$_POST['id']]
                        );

                        // Then add new enrollments
                        if (!empty($_POST['classes']) && is_array($_POST['classes'])) {
                            $enrollStmt = $pdo->prepare("INSERT INTO class_students (class_id, student_id) VALUES (?, ?)");
                            if ($enrollStmt === false) {
                                throw new PDOException("Failed to prepare enrollment statement");
                            }
                            
                            foreach ($_POST['classes'] as $class_id) {
                                if (!$enrollStmt->execute([$class_id, $_POST['id']])) {
                                    throw new PDOException("Failed to enroll student in class");
                                }
                            }
                        }
                    }

                    if ($pdo->inTransaction()) {
                        $pdo->commit();
                    }
                    $_SESSION['success'] = "Student updated successfully";
                } catch(PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $_SESSION['error'] = "Error updating student: " . $e->getMessage();
                }
                break;

            case 'delete':
                if (!empty($_POST['student_id']) && !empty($_POST['class_id'])) {
                    try {
                        $student_id = intval($_POST['student_id']);
                        $class_id = intval($_POST['class_id']);
                        
                        // Log for debugging
                        error_log("Dropping student - Student ID: {$student_id}, Class ID: {$class_id}");
                        
                        // VALIDATION: Check if student has 5 or more absences
                        $absenceStmt = $pdo->prepare("
                            SELECT COUNT(*) as absence_count
                            FROM attendance
                            WHERE student_id = ? 
                            AND class_id = ? 
                            AND status = 'absent'
                        ");
                        $absenceStmt->execute([$student_id, $class_id]);
                        $absenceResult = $absenceStmt->fetch(PDO::FETCH_ASSOC);
                        $absenceCount = intval($absenceResult['absence_count'] ?? 0);
                        
                        // If student has less than 5 absences, prevent dropping
                        if ($absenceCount < 5) {
                            $_SESSION['error'] = "Cannot drop student. Student must have at least 5 absences. Current absences: {$absenceCount}";
                            $redirect_url = "manage_students.php";
                            if (!empty($_POST['class_id'])) {
                                $redirect_url .= "?class_id=" . intval($_POST['class_id']);
                            }
                            header("Location: " . $redirect_url);
                            exit();
                        }
                        
                        // Check if transaction is already active
                        if (!$pdo->inTransaction()) {
                            $pdo->beginTransaction();
                        }
                        
                        // Check if student is enrolled in this class
                        $checkStmt = $pdo->prepare("
                            SELECT id, status FROM class_students 
                            WHERE student_id = ? AND class_id = ?
                        ");
                        $checkStmt->execute([$student_id, $class_id]);
                        $enrollment = $checkStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$enrollment) {
                            // If not enrolled, create enrollment record with dropped status
                            $insertStmt = $pdo->prepare("
                                INSERT INTO class_students (class_id, student_id, status) 
                                VALUES (?, ?, 'dropped')
                            ");
                            $insertStmt->execute([$class_id, $student_id]);
                            $classStudentsUpdated = $insertStmt->rowCount();
                            error_log("Created new class_students record with dropped status: {$classStudentsUpdated}");
                        } else {
                            // Update existing enrollment to dropped
                            $stmt = $pdo->prepare("
                                UPDATE class_students 
                                SET status = 'dropped' 
                                WHERE student_id = ? AND class_id = ?
                            ");
                            $stmt->execute([$student_id, $class_id]);
                            $classStudentsUpdated = $stmt->rowCount();
                            error_log("Updated class_students rows: {$classStudentsUpdated}");
                        }
                        
                        // Then, soft delete the student (set is_deleted = 1)
                        $stmt = $pdo->prepare("
                            UPDATE students 
                            SET is_deleted = 1, 
                                deleted_at = NOW(),
                                status = 'deleted'
                            WHERE id = ?
                        ");
                        $stmt->execute([$student_id]);
                        $studentsUpdated = $stmt->rowCount();
                        error_log("Updated students rows: {$studentsUpdated} (is_deleted set to 1)");
                        
                        // Backup to Firebase
                        try {
                            require_once '../helpers/BackupHooks.php';
                            $backupHooks = new BackupHooks();
                            
                            // Get student data for backup
                            $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
                            $stmt->execute([$student_id]);
                            $student = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($student) {
                                $updatedData = [
                                    'is_deleted' => 1,
                                    'deleted_at' => date('Y-m-d H:i:s'),
                                    'status' => 'deleted'
                                ];
                                $backupHooks->backupStudentUpdate($student_id, $updatedData);
                                error_log("Firebase backup completed for student {$student_id}");
                            }
                        } catch (Exception $e) {
                            error_log("Firebase backup failed for student drop: " . $e->getMessage());
                        }
                        
                        if ($pdo->inTransaction()) {
                            $pdo->commit();
                        }
                        
                        if ($classStudentsUpdated > 0) {
                            $_SESSION['success'] = "Student has been dropped and archived successfully. Student had {$absenceCount} absences.";
                        } else {
                            $_SESSION['error'] = "Failed to update class enrollment. Student may not be enrolled in this class.";
                        }
                    } catch(PDOException $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log("Error dropping student: " . $e->getMessage());
                        $_SESSION['error'] = "Error dropping student: " . $e->getMessage();
                    }
                } else {
                    error_log("Missing student_id or class_id - student_id: " . (isset($_POST['student_id']) ? $_POST['student_id'] : 'not set') . ", class_id: " . (isset($_POST['class_id']) ? $_POST['class_id'] : 'not set'));
                    $_SESSION['error'] = "Invalid student ID or class ID";
                }
                break;
        }
        // Redirect with class_id to maintain the selected class
        $redirect_url = "manage_students.php";
        if (!empty($_POST['class_id'])) {
            $redirect_url .= "?class_id=" . intval($_POST['class_id']);
        }
        header("Location: " . $redirect_url);
        exit();
    }
}

try {
    // Get sections handled by the teacher
    $stmt = prepareAndExecute($pdo, "SELECT DISTINCT section FROM classes WHERE teacher_id = ?", [$_SESSION['user_id']]);
    $sections = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($sections)) {
        $in = str_repeat('?,', count($sections) - 1) . '?';
        // Exclude dropped/deleted students - only show active students
        $stmt = prepareAndExecute($pdo, "
            SELECT s.* 
            FROM students s
            WHERE s.section IN ($in) 
            AND s.status NOT IN ('graduated', 'promoted')
            AND (s.is_deleted = 0 OR s.is_deleted IS NULL)
            ORDER BY s.course, s.year_level, s.section, s.student_id
        ", $sections);
        $students = $stmt->fetchAll();
    } else {
        $students = [];
    }
} catch(PDOException $e) {
    die("Error fetching students: " . $e->getMessage());
}

// Fetch teacher info for avatar and name (match dashboard.php)
$stmt = $pdo->prepare("SELECT * FROM teachers WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$fullName = $_SESSION['full_name'] ?? $_SESSION['username'];
$initials = '';
$nameParts = explode(' ', $fullName);
if (count($nameParts) >= 2) {
    $initials = strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[count($nameParts) - 1], 0, 1));
} else {
    $initials = strtoupper(substr($fullName, 0, 2));
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
    <title>Manage Students - Teacher Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <link href="../assets/css/management.css" rel="stylesheet">
    <style>
    .table td .bi-eye {
        font-size: 1.4em;
        vertical-align: middle;
        color: #4f8cff;
        cursor: pointer;
        transition: color 0.2s;
    }
    .table td .bi-eye:hover {
        color: #1a5edb;
    }
    .table td .bi-person-x {
        font-size: 1.4em;
        vertical-align: middle;
        color: #dc3545;
        cursor: pointer;
        transition: color 0.2s;
    }
    .table td .bi-person-x:hover {
        color: #bb2d3b;
    }
    .table td .action-buttons {
        display: flex;
        justify-content: center;
        gap: 1rem;
    }
    .table td {
        vertical-align: middle !important;
    }
    .user-info {
        display: flex;
        align-items: center;
        gap: 12px;
    }
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
                <a class="nav-link active" href="manage_students.php">
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

        <div class="management-header animate-fadeIn">
            <h2>Manage Students</h2>
        </div>

        <!-- My Classes Section -->
        <div class="row mb-3">
            <div class="col-md-4">
                <label for="classFilter" class="form-label fw-semibold text-secondary">My Class:</label>
                <select id="classFilter" name="class_id" class="form-select">
                    <?php foreach ($available_classes as $id => $name): ?>
                        <option value="<?php echo $id; ?>" <?php if ((isset($_GET['class_id']) && $_GET['class_id'] == $id) || (!isset($_GET['class_id']) && $id == array_key_first($available_classes))) echo 'selected'; ?>><?php echo htmlspecialchars($name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

                    <!-- Students Table -->
        <div class="table-responsive animate-fadeIn delay-3">
            <table class="table" id="studentsTable">
                <thead>
                    <tr>
                        <th>Student ID</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $selected_class_id = isset($_GET['class_id']) ? $_GET['class_id'] : array_key_first($available_classes);
                    // Get section and year_level of the selected class - use same logic as manage_attendance.php
                    $stmt = $pdo->prepare("SELECT c.section, c.year_level FROM classes c WHERE c.id = ?");
                    $stmt->execute([$selected_class_id]);
                    $classInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                    $selected_section = $classInfo['section'] ?? '';
                    $selected_year_level = $classInfo['year_level'] ?? '';
                    $hasStudent = false;
                    foreach ($students as $student): 
                        // Show only students in the selected section and year level
                        // Also exclude dropped/deleted students
                        if (
                            $student['section'] != $selected_section ||
                            $student['year_level'] != $selected_year_level ||
                            in_array($student['status'], ['graduated', 'promoted']) ||
                            (isset($student['is_deleted']) && $student['is_deleted'] == 1)
                        ) continue;
                        $hasStudent = true;
                    ?>
                        <tr data-class-id="<?php echo $selected_class_id; ?>">
                        <td><?php echo htmlspecialchars($student['student_id']); ?></td>
                        <td><?php echo htmlspecialchars($student['full_name'] ?? (($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''))); ?></td>
                        <td><?php echo htmlspecialchars($student['email']); ?></td>
                        <td><?php echo htmlspecialchars($student['created_at'] ?? ''); ?></td>
                        <td class="text-center">
                            <div class="action-buttons">
                                <a href="#" class="text-info view-student-btn" data-bs-toggle="modal" data-bs-target="#viewStudentModal" data-id="<?php echo $student['id']; ?>" title="View Details">
                                    <i class="bi bi-eye fs-5"></i>
                                </a>
                                <a href="#" class="text-danger drop-student-btn" data-bs-toggle="modal" data-bs-target="#dropStudentModal" data-id="<?php echo $student['id']; ?>" data-class-id="<?php echo $selected_class_id; ?>" data-name="<?php echo htmlspecialchars($student['full_name'] ?? (($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''))); ?>" title="Drop Student">
                                    <i class="bi bi-person-x fs-5"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$hasStudent): ?>
                    <tr><td colspan="5" class="text-center">No students found for this class.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
                    </div>
        <script>
        document.getElementById('classFilter').addEventListener('change', function() {
            const classId = this.value;
            const url = new URL(window.location.href);
            url.searchParams.set('class_id', classId);
            window.location.href = url.toString();
        });
        </script>
    </main>

    <!-- Add Student Modal -->
    <div class="modal fade" id="addStudentModal" tabindex="-1" aria-labelledby="addStudentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addStudentModalLabel">Add New Student</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                <form action="manage_students.php" method="POST">
                        <input type="hidden" name="action" value="add">
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="student_id" class="form-label">Student ID</label>
                                <input type="text" class="form-control" id="student_id" name="student_id" required>
                            </div>
                            <div class="col-md-6">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="full_name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="full_name" name="full_name" required>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-4">
                            <label for="course" class="form-label">Course</label>
                                <input type="text" class="form-control" id="course" name="course" required>
                        </div>
                            <div class="col-md-4">
                            <label for="year_level" class="form-label">Year Level</label>
                                <select class="form-select" id="year_level" name="year_level" required>
                                <option value="">Select Year Level</option>
                                <option value="1">1st Year</option>
                                <option value="2">2nd Year</option>
                                <option value="3">3rd Year</option>
                                <option value="4">4th Year</option>
                            </select>
                        </div>
                            <div class="col-md-4">
                            <label for="section" class="form-label">Section</label>
                                <input type="text" class="form-control" id="section" name="section" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Assign to Classes</label>
                            <div class="row">
                                <?php foreach ($available_classes as $id => $name): ?>
                                <div class="col-md-6">
                                <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="classes[]" value="<?php echo $id; ?>" id="class_<?php echo $id; ?>">
                                        <label class="form-check-label" for="class_<?php echo $id; ?>">
                                            <?php echo htmlspecialchars($name); ?>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                    <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Student</button>
                    </div>
                </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Student Modal -->
    <div class="modal fade" id="editStudentModal" tabindex="-1" aria-labelledby="editStudentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="editStudentModalLabel">Edit Student</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                <form action="manage_students.php" method="POST">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" id="edit_id">
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="edit_student_id" class="form-label">Student ID</label>
                                <input type="text" class="form-control" id="edit_student_id" name="student_id" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="edit_username" name="username" disabled>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_full_name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="edit_full_name" name="full_name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="edit_email" name="email" required>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-4">
                            <label for="edit_course" class="form-label">Course</label>
                                <input type="text" class="form-control" id="edit_course" name="course" required>
                        </div>
                            <div class="col-md-4">
                            <label for="edit_year_level" class="form-label">Year Level</label>
                                <select class="form-select" id="edit_year_level" name="year_level" required>
                                <option value="">Select Year Level</option>
                                <option value="1">1st Year</option>
                                <option value="2">2nd Year</option>
                                <option value="3">3rd Year</option>
                                <option value="4">4th Year</option>
                            </select>
                        </div>
                            <div class="col-md-4">
                            <label for="edit_section" class="form-label">Section</label>
                                <input type="text" class="form-control" id="edit_section" name="section" required>
                        </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_password" class="form-label">New Password (leave blank to keep current)</label>
                            <input type="password" class="form-control" id="edit_password" name="password">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Assign to Classes</label>
                            <div class="row">
                                <?php foreach ($available_classes as $id => $name): ?>
                                <div class="col-md-6">
                                <div class="form-check">
                                        <input class="form-check-input edit-class-checkbox" type="checkbox" name="classes[]" value="<?php echo $id; ?>" id="edit_class_<?php echo $id; ?>">
                                        <label class="form-check-label" for="edit_class_<?php echo $id; ?>">
                                            <?php echo htmlspecialchars($name); ?>
                                    </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                    <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Update Student</button>
                    </div>
                </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Drop Student Modal -->
    <div class="modal fade" id="dropStudentModal" tabindex="-1" aria-labelledby="dropStudentModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="dropStudentModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Drop Student</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="bi bi-person-x text-danger" style="font-size: 3rem;"></i>
                    </div>
                    <p class="text-center">Are you sure you want to drop the student:</p>
                    <p class="text-center fw-bold fs-5" id="delete_student_name"></p>
                    
                    <!-- Student Absence Status -->
                    <div class="card mb-3 border-primary shadow-sm" id="absenceStatusCard">
                        <div class="card-body text-center">
                            <h6 class="card-title mb-3 fw-bold">
                                <i class="bi bi-calendar-x me-2 text-danger"></i>Absence Status
                            </h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="p-4 rounded shadow-sm" style="background-color: #fff5f5; border: 3px solid #dc3545;">
                                        <div id="absenceCount" class="mb-2 fw-bold" style="font-size: 5rem !important; line-height: 1.2 !important; color: #dc3545 !important; text-shadow: 3px 3px 6px rgba(0,0,0,0.2) !important; display: block !important; visibility: visible !important; opacity: 1 !important;">-</div>
                                        <div class="text-dark fw-bold" style="font-size: 1.1rem;">Total Absences</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-4 rounded shadow-sm" style="background-color: #f0f8ff; border: 3px solid #0d6efd;">
                                        <div id="requiredAbsences" class="mb-2 fw-bold" style="font-size: 5rem !important; line-height: 1.2 !important; color: #0d6efd !important; text-shadow: 3px 3px 6px rgba(0,0,0,0.2) !important; display: block !important; visibility: visible !important; opacity: 1 !important;">5</div>
                                        <div class="text-dark fw-bold" style="font-size: 1.1rem;">Required to Drop</div>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3">
                                <div id="absenceStatusBadge" class="badge fs-6 p-3">
                                    <i class="bi bi-hourglass-split me-1"></i>
                                    <span id="absenceStatusText" class="fw-bold">Checking...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-circle me-2"></i>
                        This action will permanently remove:
                        <ul class="mb-0 mt-2">
                            <li>Student's personal information</li>
                            <li>Attendance records</li>
                            <li>Class enrollments</li>
                            <li>All other related data</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <form action="manage_students.php" method="POST" id="dropStudentForm">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="student_id" id="delete_student_id">
                        <input type="hidden" name="class_id" id="delete_class_id">
                        <div id="dropValidationMessage" class="alert alert-warning mb-2" style="display: none;">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <span id="dropValidationText"></span>
                        </div>
                        <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-2"></i>Cancel
                        </button>
                        <button type="submit" class="btn btn-danger" id="dropStudentBtn">
                            <i class="bi bi-person-x me-2"></i>Drop Student
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- View Student Modal -->
    <div class="modal fade" id="viewStudentModal" tabindex="-1" aria-labelledby="viewStudentModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header bg-primary text-white">
            <h5 class="modal-title" id="viewStudentModalLabel"><i class="bi bi-eye me-2"></i>Student Details</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="row">
              <div class="col-md-6">
                <h6 class="text-primary border-bottom border-primary pb-2 mb-3">Personal Information</h6>
                <table class="table table-borderless mb-3">
                  <tr><td width="140"><strong>Student ID:</strong></td><td id="viewStudentId"></td></tr>
                  <tr><td><strong>Full Name:</strong></td><td id="viewStudentFullName"></td></tr>
                  <tr><td><strong>Sex:</strong></td><td id="viewStudentSex"></td></tr>
                  <tr><td><strong>Civil Status:</strong></td><td id="viewStudentCivilStatus"></td></tr>
                  <tr><td><strong>Birthdate:</strong></td><td id="viewStudentBirthdate"></td></tr>
                  <tr><td><strong>Place of Birth:</strong></td><td id="viewStudentPlaceOfBirth"></td></tr>
                  <tr><td><strong>Citizenship:</strong></td><td id="viewStudentCitizenship"></td></tr>
                </table>
                <h6 class="text-primary border-bottom border-primary pb-2 mb-3 mt-4">Contact Information</h6>
                <table class="table table-borderless mb-3">
                  <tr><td width="140"><strong>Address:</strong></td><td id="viewStudentAddress"></td></tr>
                  <tr><td><strong>Phone:</strong></td><td id="viewStudentPhone"></td></tr>
                  <tr><td><strong>Email:</strong></td><td id="viewStudentEmail"></td></tr>
                </table>
              </div>
              <div class="col-md-6">
                <h6 class="text-primary border-bottom border-primary pb-2 mb-3">Academic Information</h6>
                <table class="table table-borderless mb-3">
                  <tr><td width="140"><strong>Course:</strong></td><td id="viewStudentCourse"></td></tr>
                  <tr><td><strong>Year Level:</strong></td><td id="viewStudentYear"></td></tr>
                  <tr><td><strong>Section:</strong></td><td id="viewStudentSection"></td></tr>
                  <tr><td><strong>Created At:</strong></td><td id="viewStudentCreated"></td></tr>
                </table>
              </div>
            </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>
    <script src="../assets/js/students.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.view-student-btn').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var studentId = this.getAttribute('data-id');
                var students = <?php echo json_encode($students); ?>;
                var student = students.find(s => String(s.id) === String(studentId));
                if (student) {
                    document.getElementById('viewStudentId').textContent = student.student_id || '';
                    document.getElementById('viewStudentFullName').textContent = student.full_name || ((student.first_name || '') + ' ' + (student.last_name || ''));
                    document.getElementById('viewStudentSex').textContent = student.sex || '';
                    document.getElementById('viewStudentCivilStatus').textContent = student.civil_status || '';
                    document.getElementById('viewStudentBirthdate').textContent = student.birthdate || '';
                    document.getElementById('viewStudentPlaceOfBirth').textContent = student.place_of_birth || '';
                    document.getElementById('viewStudentCitizenship').textContent = student.citizenship || '';
                    document.getElementById('viewStudentAddress').textContent = student.address || '';
                    document.getElementById('viewStudentPhone').textContent = student.phone_number || '';
                    document.getElementById('viewStudentEmail').textContent = student.email || '';
                    document.getElementById('viewStudentCourse').textContent = student.course || '';
                    document.getElementById('viewStudentYear').textContent = student.year_level || '';
                    document.getElementById('viewStudentSection').textContent = student.section || '';
                    document.getElementById('viewStudentCreated').textContent = student.created_at || '';
                }
            });
        });

        // Flag to prevent multiple concurrent requests
        var isFetchingAbsence = false;
        
        // Function to fetch and display absence data
        function fetchAbsenceData(studentId, classId) {
            // Prevent multiple concurrent requests
            if (isFetchingAbsence) {
                console.log('Already fetching absence data, skipping...');
                return;
            }
            
            var validationMsg = document.getElementById('dropValidationMessage');
            var validationText = document.getElementById('dropValidationText');
            var dropBtn = document.getElementById('dropStudentBtn');
            var absenceCountEl = document.getElementById('absenceCount');
            var absenceStatusText = document.getElementById('absenceStatusText');
            var absenceStatusBadge = document.getElementById('absenceStatusBadge');
            
            // Check if elements exist
            if (!absenceCountEl || !absenceStatusText || !absenceStatusBadge) {
                console.error('Modal elements not found');
                return;
            }
            
            // Set flag
            isFetchingAbsence = true;
            
            // Reset to loading state
            absenceCountEl.innerHTML = '...';
            absenceCountEl.style.display = 'block';
            absenceCountEl.style.visibility = 'visible';
            absenceStatusText.innerHTML = 'Checking...';
            absenceStatusBadge.className = 'badge bg-secondary fs-6 p-2';
            if (validationMsg) validationMsg.style.display = 'none';
            if (dropBtn) {
                dropBtn.disabled = true;
                dropBtn.classList.add('disabled');
            }
            
            // Create abort controller for timeout
            var controller = new AbortController();
            var timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
            
            // Fetch absence count with cache busting and no-cache headers
            var url = 'check_student_absences.php?student_id=' + studentId + '&class_id=' + classId + '&t=' + new Date().getTime();
            console.log('Fetching absence data from:', url);
            
            fetch(url, {
                method: 'GET',
                cache: 'no-cache',
                signal: controller.signal,
                headers: {
                    'Cache-Control': 'no-cache',
                    'Pragma': 'no-cache'
                }
            })
                .then(response => {
                    clearTimeout(timeoutId);
                    console.log('Response status:', response.status);
                    if (!response.ok) {
                        throw new Error('Network response was not ok: ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    isFetchingAbsence = false; // Reset flag
                    clearTimeout(timeoutId);
                    console.log('Received data:', data);
                    if (data && data.success) {
                        var absenceCount = parseInt(data.absence_count || 0);
                        var requiredAbsences = 5;
                        
                        console.log('Absence count:', absenceCount);
                        
                        // Update absence count display
                        if (absenceCountEl) {
                            absenceCountEl.innerHTML = absenceCount;
                            absenceCountEl.style.display = 'block';
                            absenceCountEl.style.visibility = 'visible';
                            absenceCountEl.style.opacity = '1';
                        }
                        
                        // Update status badge based on absence count
                        if (absenceCount < requiredAbsences) {
                            // Not enough absences - show warning
                            var remaining = requiredAbsences - absenceCount;
                            if (absenceStatusText) {
                                absenceStatusText.innerHTML = '<i class="bi bi-x-circle me-1"></i>Cannot Drop - Need ' + remaining + ' more absence(s)';
                            }
                            if (absenceStatusBadge) {
                                absenceStatusBadge.className = 'badge bg-warning text-dark fs-6 p-2';
                            }
                            
                            // Show validation message
                            if (validationMsg) validationMsg.style.display = 'block';
                            if (validationText) {
                                validationText.textContent = 'Cannot drop student. Student must have at least 5 absences. Current absences: ' + absenceCount;
                            }
                            if (dropBtn) {
                                dropBtn.disabled = true;
                                dropBtn.classList.add('disabled');
                            }
                        } else {
                            // Enough absences - allow drop
                            if (absenceStatusText) {
                                absenceStatusText.innerHTML = '<i class="bi bi-check-circle me-1"></i>Eligible to Drop';
                            }
                            if (absenceStatusBadge) {
                                absenceStatusBadge.className = 'badge bg-success fs-6 p-2';
                            }
                            
                            // Hide warning, enable button
                            if (validationMsg) validationMsg.style.display = 'none';
                            if (dropBtn) {
                                dropBtn.disabled = false;
                                dropBtn.classList.remove('disabled');
                            }
                        }
                    } else {
                        // Error fetching data
                        if (absenceCountEl) absenceCountEl.innerHTML = 'Error';
                        if (absenceStatusText) absenceStatusText.innerHTML = 'Unable to fetch absence data';
                        if (absenceStatusBadge) absenceStatusBadge.className = 'badge bg-danger fs-6 p-2';
                        if (validationMsg) validationMsg.style.display = 'none';
                        if (dropBtn) {
                            dropBtn.disabled = false;
                            dropBtn.classList.remove('disabled');
                        }
                    }
                })
                .catch(error => {
                    isFetchingAbsence = false; // Reset flag
                    clearTimeout(timeoutId);
                    console.error('Error checking absences:', error);
                    if (error.name === 'AbortError') {
                        console.error('Request timeout');
                        if (absenceStatusText) {
                            absenceStatusText.innerHTML = 'Request timeout - please try again';
                        }
                    } else {
                        if (absenceStatusText) {
                            absenceStatusText.innerHTML = 'Error loading data: ' + error.message;
                        }
                    }
                    if (absenceCountEl) absenceCountEl.innerHTML = 'Error';
                    if (absenceStatusBadge) absenceStatusBadge.className = 'badge bg-danger fs-6 p-2';
                    if (validationMsg) validationMsg.style.display = 'none';
                    if (dropBtn) {
                        dropBtn.disabled = false;
                        dropBtn.classList.remove('disabled');
                    }
                });
        }
        
        // Reset modal state function
        function resetModalState() {
            // Reset fetch flag
            isFetchingAbsence = false;
            
            var absenceCountEl = document.getElementById('absenceCount');
            var absenceStatusText = document.getElementById('absenceStatusText');
            var absenceStatusBadge = document.getElementById('absenceStatusBadge');
            var dropBtn = document.getElementById('dropStudentBtn');
            var validationMsg = document.getElementById('dropValidationMessage');
            
            if (absenceCountEl) {
                absenceCountEl.innerHTML = '-';
                absenceCountEl.style.display = 'block';
                absenceCountEl.style.visibility = 'visible';
                absenceCountEl.style.opacity = '1';
            }
            if (absenceStatusText) {
                absenceStatusText.innerHTML = 'Click Drop Student button to check';
            }
            if (absenceStatusBadge) {
                absenceStatusBadge.className = 'badge bg-secondary fs-6 p-2';
            }
            if (dropBtn) {
                dropBtn.disabled = false;
                dropBtn.classList.remove('disabled');
            }
            if (validationMsg) {
                validationMsg.style.display = 'none';
            }
        }
        
        // Add drop student functionality with absence validation
        document.querySelectorAll('.drop-student-btn').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                // Reset modal state first
                resetModalState();
                
                var studentId = this.getAttribute('data-id');
                var classId = this.getAttribute('data-class-id');
                var studentName = this.getAttribute('data-name');
                
                if (!studentId || !classId) {
                    console.error('Missing student_id or class_id');
                    return;
                }
                
                document.getElementById('delete_student_id').value = studentId;
                document.getElementById('delete_class_id').value = classId;
                document.getElementById('delete_student_name').textContent = studentName;
                
                // Small delay to ensure modal is fully shown before fetching
                setTimeout(function() {
                    fetchAbsenceData(studentId, classId);
                }, 100);
            });
        });
        
        // Reset modal state when it's shown (for subsequent opens)
        var dropStudentModal = document.getElementById('dropStudentModal');
        if (dropStudentModal) {
            // Remove any existing listeners first
            dropStudentModal.removeEventListener('show.bs.modal', resetModalState);
            dropStudentModal.removeEventListener('shown.bs.modal', resetModalState);
            
            // Add listener for when modal is about to show
            dropStudentModal.addEventListener('show.bs.modal', function(e) {
                resetModalState();
            });
            
            // Also reset when modal is hidden (for cleanup)
            dropStudentModal.addEventListener('hidden.bs.modal', function(e) {
                resetModalState();
            });
        }
    });
    </script>
</body>
</html>