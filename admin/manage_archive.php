<?php
require_once '../config/database.php';
require_once '../helpers/functions.php';
require_once '../helpers/BackupHooks.php';

// Check if user is logged in and is admin
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// Handle restore action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Student restore
    if (isset($_POST['action']) && $_POST['action'] === 'restore' && isset($_POST['student_id'])) {
        try {
            $student_id = $_POST['student_id'];
            
            // Restore student (soft delete reversal)
            $stmt = $pdo->prepare("
                UPDATE students 
                SET is_deleted = 0, 
                    deleted_at = NULL,
                    status = 'approved'
                WHERE id = ? AND is_deleted = 1
            ");
            $stmt->execute([$student_id]);
            
            if ($stmt->rowCount() > 0) {
                // Also restore class_students status to 'active' if it was 'dropped'
                $stmt = $pdo->prepare("
                    UPDATE class_students 
                    SET status = 'active' 
                    WHERE student_id = ? AND status = 'dropped'
                ");
                $stmt->execute([$student_id]);
                
                // Get the restored student data for Firebase backup
                $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
                $stmt->execute([$student_id]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Backup restored student to Firebase
                try {
                    $backupHooks = new BackupHooks();
                    $updatedData = [
                        'is_deleted' => 0,
                        'deleted_at' => null,
                        'status' => 'approved',
                        'restored_at' => date('Y-m-d H:i:s')
                    ];
                    $backupHooks->backupStudentUpdate($student['id'], $updatedData);
                } catch (Exception $e) {
                    error_log("Firebase backup failed for student restore: " . $e->getMessage());
                }
                
                $_SESSION['success'] = "Student has been restored successfully.";
            } else {
                $_SESSION['error'] = "Student not found or already active.";
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error restoring student: " . $e->getMessage();
        }
        
        $redirect_tab = isset($_POST['tab']) ? $_POST['tab'] : '';
        if ($redirect_tab === 'dropped') {
            header('Location: manage_archive.php?tab=dropped');
        } else {
            header('Location: manage_archive.php');
        }
        exit();
    }
    
    // Teacher restore
    if (isset($_POST['action']) && $_POST['action'] === 'restore_teacher' && isset($_POST['teacher_id'])) {
        try {
            $teacher_id = $_POST['teacher_id'];
            
            // Restore teacher (soft delete reversal)
            $stmt = $pdo->prepare("
                UPDATE teachers 
                SET is_deleted = 0, 
                    deleted_at = NULL
                WHERE id = ? AND is_deleted = 1
            ");
            $stmt->execute([$teacher_id]);
            
            if ($stmt->rowCount() > 0) {
                // Get the restored teacher data for Firebase backup
                $stmt = $pdo->prepare("SELECT * FROM teachers WHERE id = ?");
                $stmt->execute([$teacher_id]);
                $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Backup restored teacher to Firebase
                try {
                    $backupHooks = new BackupHooks();
                    $updatedData = [
                        'is_deleted' => 0,
                        'deleted_at' => null,
                        'restored_at' => date('Y-m-d H:i:s')
                    ];
                    $backupHooks->backupTeacherUpdate($teacher['id'], $updatedData);
                } catch (Exception $e) {
                    error_log("Firebase backup failed for teacher restore: " . $e->getMessage());
                }
                
                $_SESSION['success'] = "Teacher has been restored successfully.";
            } else {
                $_SESSION['error'] = "Teacher not found or already active.";
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error restoring teacher: " . $e->getMessage();
        }
        
        header('Location: manage_archive.php?tab=teachers');
        exit();
    }
    
    // Student permanent delete
    if (isset($_POST['action']) && $_POST['action'] === 'permanent_delete' && isset($_POST['student_id'])) {
        try {
            $student_id = $_POST['student_id'];
            
            $pdo->beginTransaction();
            
            // Delete related records first
            $stmt = $pdo->prepare("DELETE FROM class_students WHERE student_id = ?");
            $stmt->execute([$student_id]);
            
            $stmt = $pdo->prepare("DELETE FROM attendance WHERE student_id = ?");
            $stmt->execute([$student_id]);
            
            // Finally delete the student permanently
            $stmt = $pdo->prepare("DELETE FROM students WHERE id = ? AND is_deleted = 1");
            $stmt->execute([$student_id]);
            
            $pdo->commit();
            
            if ($stmt->rowCount() > 0) {
                $_SESSION['success'] = "Student has been permanently deleted.";
            } else {
                $_SESSION['error'] = "Student not found or already active.";
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Error permanently deleting student: " . $e->getMessage();
        }
        
        header('Location: manage_archive.php');
        exit();
    }
    
    // Teacher permanent delete
    if (isset($_POST['action']) && $_POST['action'] === 'permanent_delete_teacher' && isset($_POST['teacher_id'])) {
        try {
            $teacher_id = $_POST['teacher_id'];
            
            $pdo->beginTransaction();
            
            // Delete related records first
            $stmt = $pdo->prepare("DELETE FROM classes WHERE teacher_id = ?");
            $stmt->execute([$teacher_id]);
            
            // Finally delete the teacher permanently
            $stmt = $pdo->prepare("DELETE FROM teachers WHERE id = ? AND is_deleted = 1");
            $stmt->execute([$teacher_id]);
            
            $pdo->commit();
            
            if ($stmt->rowCount() > 0) {
                $_SESSION['success'] = "Teacher has been permanently deleted.";
            } else {
                $_SESSION['error'] = "Teacher not found or already active.";
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Error permanently deleting teacher: " . $e->getMessage();
        }
        
        header('Location: manage_archive.php?tab=teachers');
        exit();
    }
}

// Get current tab (students, teachers, or dropped)
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'students';
if (!in_array($current_tab, ['students', 'teachers', 'dropped'])) {
    $current_tab = 'students';
}

// Get archived students
try {
    $stmt = $pdo->query("
        SELECT id, student_id, first_name, middle_name, last_name, suffix_name, 
               course, year_level, section, status, deleted_at, created_at
        FROM students 
        WHERE is_deleted = 1
        ORDER BY deleted_at DESC
    ");
    $archived_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $archived_students = [];
    $_SESSION['error'] = "Error fetching archived students: " . $e->getMessage();
}

// Get archived teachers
try {
    $stmt = $pdo->query("
        SELECT id, teacher_id, full_name, email, course, deleted_at, created_at
        FROM teachers 
        WHERE is_deleted = 1
        ORDER BY deleted_at DESC
    ");
    $archived_teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $archived_teachers = [];
    $_SESSION['error'] = "Error fetching archived teachers: " . $e->getMessage();
}

// Get statistics for students
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM students WHERE is_deleted = 1");
    $total_archived = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM students WHERE is_deleted = 1 AND status = 'deleted'");
    $deleted_count = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM students WHERE is_deleted = 1 AND status = 'graduated'");
    $graduated_count = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM students WHERE is_deleted = 1 AND status = 'declined'");
    $declined_count = $stmt->fetchColumn();
} catch (PDOException $e) {
    $total_archived = $deleted_count = $graduated_count = $declined_count = 0;
}

// Get statistics for teachers
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM teachers WHERE is_deleted = 1");
    $total_archived_teachers = $stmt->fetchColumn();
} catch (PDOException $e) {
    $total_archived_teachers = 0;
}

// Get dropped students (from class_students where status = 'dropped' AND students is_deleted = 1)
try {
    $stmt = $pdo->query("
        SELECT 
            cs.id as class_student_id,
            cs.class_id,
            cs.student_id,
            cs.status,
            cs.enrolled_at,
            s.student_id as student_id_code,
            CONCAT(s.first_name, ' ', COALESCE(s.middle_name, ''), ' ', s.last_name, 
                   CASE WHEN s.suffix_name IS NOT NULL AND s.suffix_name != '' THEN CONCAT(' ', s.suffix_name) ELSE '' END) as student_name,
            sub.subject_code,
            sub.subject_name,
            c.teacher_id,
            t.full_name as teacher_name,
            c.section,
            c.academic_year,
            c.semester,
            s.deleted_at,
            s.is_deleted
        FROM class_students cs
        INNER JOIN students s ON cs.student_id = s.id
        INNER JOIN classes c ON cs.class_id = c.id
        INNER JOIN subjects sub ON c.subject_id = sub.id
        LEFT JOIN teachers t ON c.teacher_id = t.id
        WHERE cs.status = 'dropped' AND s.is_deleted = 1
        ORDER BY COALESCE(s.deleted_at, cs.enrolled_at) DESC
    ");
    $dropped_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Fetched " . count($dropped_students) . " dropped students from archive");
} catch (PDOException $e) {
    $dropped_students = [];
    error_log("Error fetching dropped students: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Archive - iAttendance</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <style>
        /* Archive Tabs Styling */
        #archiveTabs {
            border-bottom: 2px solid #dee2e6 !important;
        }
        #archiveTabs .nav-item {
            margin-bottom: -2px;
        }
        #archiveTabs .nav-link {
            border: 1px solid transparent;
            border-top-left-radius: 0.375rem;
            border-top-right-radius: 0.375rem;
            padding: 0.75rem 1.5rem;
            color: #495057;
            background-color: transparent;
            transition: all 0.2s;
        }
        #archiveTabs .nav-link:hover {
            border-color: #dee2e6 #dee2e6 transparent;
            background-color: #f8f9fa;
            color: #495057;
        }
        #archiveTabs .nav-link.active {
            color: #495057;
            background-color: #fff;
            border-color: #dee2e6 #dee2e6 #fff;
            border-bottom-color: #fff;
            font-weight: 600;
        }
        #archiveTabs .nav-link:not(.active) {
            color: #6c757d;
        }
        .tab-content {
            background-color: transparent;
        }
        .tab-pane {
            display: none;
        }
        .tab-pane.active {
            display: block !important;
        }
        .tab-pane.show {
            display: block !important;
        }
    </style>
</head>
<body class="admin-page">
    <?php include 'sidebar.php'; ?>
    
    <main class="page-content">
        <?php include 'topbar.php'; ?>
        
        <div class="container-fluid px-4">
            <div class="row">
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h1 class="mb-0">Manage Archive</h1>
                            <p class="text-muted mb-0">View and manage archived records</p>
                        </div>
                    </div>
                    
                    <!-- Tab Navigation -->
                    <ul class="nav nav-tabs mb-4" id="archiveTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo $current_tab === 'students' ? 'active' : ''; ?>" 
                                    id="students-tab" 
                                    type="button" role="tab">
                                <i class="bi bi-people me-2"></i>Students
                                <span class="badge bg-secondary ms-2"><?php echo count($archived_students); ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo $current_tab === 'teachers' ? 'active' : ''; ?>" 
                                    id="teachers-tab" 
                                    type="button" role="tab">
                                <i class="bi bi-person-badge me-2"></i>Teachers
                                <span class="badge bg-secondary ms-2"><?php echo count($archived_teachers); ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo $current_tab === 'dropped' ? 'active' : ''; ?>" 
                                    id="dropped-tab" 
                                    type="button" role="tab">
                                <i class="bi bi-person-x me-2"></i>Drop Student
                                <span class="badge bg-danger ms-2"><?php echo count($dropped_students); ?></span>
                            </button>
                        </li>
                    </ul>
                    
                </div>
            </div>
            
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php 
                    echo $_SESSION['success'];
                    unset($_SESSION['success']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php 
                    echo $_SESSION['error'];
                    unset($_SESSION['error']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            
            <!-- Tab Content -->
            <div class="tab-content" id="archiveTabContent" style="min-height: 400px;">
                <!-- Students Tab -->
                <div class="tab-pane <?php echo $current_tab === 'students' ? 'active show' : ''; ?>" id="students" role="tabpanel" aria-labelledby="students-tab">
                    <!-- Filter Buttons -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card shadow-sm">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-lg-8">
                                            <div class="d-flex flex-wrap gap-2 justify-content-center justify-content-lg-start">
                                                <button type="button" class="btn btn-outline-danger filter-btn active" data-status="deleted">
                                                    <i class="bi bi-trash me-2"></i>Deleted Students
                                                    <span class="badge bg-danger ms-2"><?php echo $deleted_count; ?></span>
                                                </button>
                                                <button type="button" class="btn btn-outline-warning filter-btn" data-status="declined">
                                                    <i class="bi bi-x-circle me-2"></i>Declined Applications
                                                    <span class="badge bg-warning ms-2"><?php echo $declined_count; ?></span>
                                                </button>
                                                <button type="button" class="btn btn-outline-success filter-btn" data-status="graduated">
                                                    <i class="bi bi-mortarboard me-2"></i>Graduated Students
                                                    <span class="badge bg-success ms-2"><?php echo $graduated_count; ?></span>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="col-lg-4 mt-3 mt-lg-0">
                                            <div class="input-group">
                                                <span class="input-group-text bg-light border-end-0">
                                                    <i class="bi bi-search text-muted"></i>
                                                </span>
                                                <input type="text" class="form-control border-start-0" id="searchInput" placeholder="Search by name or student ID...">
                                                <button class="btn btn-outline-secondary border-start-0" type="button" id="clearSearch">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Archived Students Table -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card shadow-sm">
                                <div class="card-header bg-light border-0">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h5 class="card-title mb-0">
                                            <i class="bi bi-list-ul me-2"></i>
                                            <span id="current-filter-title">Deleted Students</span>
                                        </h5>
                                        <span class="badge bg-secondary ms-3" id="result-count">0 results</span>
                                    </div>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($archived_students)): ?>
                                        <div class="text-center py-5">
                                            <i class="bi bi-archive fs-1 text-muted"></i>
                                            <h5 class="text-muted mt-3">No archived students found</h5>
                                            <p class="text-muted">There are no archived student records to display.</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th class="border-0">Student ID</th>
                                                        <th class="border-0">Name</th>
                                                        <th class="border-0">Course</th>
                                                        <th class="border-0">Year Level</th>
                                                        <th class="border-0 section-column">Section</th>
                                                        <th class="border-0">Archived Date</th>
                                                        <th class="border-0 text-center">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="students-table-body">
                                                    <?php foreach ($archived_students as $student): ?>
                                                        <tr class="student-row" data-status="<?php echo $student['status']; ?>">
                                                            <td class="fw-medium"><?php echo htmlspecialchars($student['student_id']); ?></td>
                                                            <td>
                                                                <?php 
                                                                $name = $student['first_name'] . ' ' . $student['middle_name'] . ' ' . $student['last_name'];
                                                                if ($student['suffix_name']) {
                                                                    $name .= ' ' . $student['suffix_name'];
                                                                }
                                                                echo htmlspecialchars($name);
                                                                ?>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($student['course']); ?></td>
                                                            <td><?php echo $student['year_level']; ?><?php echo ($student['year_level'] == 1 ? 'st' : (($student['year_level'] == 2 ? 'nd' : (($student['year_level'] == 3 ? 'rd' : 'th'))))); ?> Year</td>
                                                            <td class="section-column"><?php echo htmlspecialchars($student['section']); ?></td>
                                                            <td class="text-muted"><?php echo date('M d, Y H:i', strtotime($student['deleted_at'])); ?></td>
                                                            <td class="text-center">
                                                                <div class="btn-group" role="group">
                                                                    <button type="button" class="btn btn-sm btn-outline-success" 
                                                                            onclick="restoreStudent(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars($name); ?>')"
                                                                            title="Restore Student">
                                                                        <i class="bi bi-arrow-clockwise"></i>
                                                                    </button>
                                                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                                                            onclick="permanentDeleteStudent(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars($name); ?>')"
                                                                            title="Delete Permanently">
                                                                        <i class="bi bi-trash"></i>
                                                                    </button>
                                                                </div>
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
                    </div>
                </div>
                
                <!-- Teachers Tab -->
                <div class="tab-pane <?php echo $current_tab === 'teachers' ? 'active show' : ''; ?>" id="teachers" role="tabpanel" aria-labelledby="teachers-tab">
                    <!-- Search Bar for Teachers -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card shadow-sm">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-lg-12">
                                            <div class="input-group">
                                                <span class="input-group-text bg-light border-end-0">
                                                    <i class="bi bi-search text-muted"></i>
                                                </span>
                                                <input type="text" class="form-control border-start-0" id="teacherSearchInput" placeholder="Search by name, teacher ID, or email...">
                                                <button class="btn btn-outline-secondary border-start-0" type="button" id="clearTeacherSearch">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Archived Teachers Table -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card shadow-sm">
                                <div class="card-header bg-light border-0">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h5 class="card-title mb-0">
                                            <i class="bi bi-list-ul me-2"></i>Deleted Teachers
                                        </h5>
                                        <span class="badge bg-secondary ms-3" id="teacher-result-count"><?php echo count($archived_teachers); ?> result<?php echo count($archived_teachers) !== 1 ? 's' : ''; ?></span>
                                    </div>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($archived_teachers)): ?>
                                        <div class="text-center py-5">
                                            <i class="bi bi-archive fs-1 text-muted"></i>
                                            <h5 class="text-muted mt-3">No archived teachers found</h5>
                                            <p class="text-muted">There are no archived teacher records to display.</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive" style="min-height: 200px;">
                                            <table class="table table-hover mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th class="border-0">Teacher ID</th>
                                                        <th class="border-0">Name</th>
                                                        <th class="border-0">Email</th>
                                                        <th class="border-0">Course</th>
                                                        <th class="border-0">Archived Date</th>
                                                        <th class="border-0 text-center">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="teachers-table-body">
                                                    <?php foreach ($archived_teachers as $teacher): ?>
                                                        <tr class="teacher-row" style="display: table-row;">
                                                            <td class="fw-medium"><?php echo htmlspecialchars($teacher['teacher_id'] ?? ''); ?></td>
                                                            <td><?php echo htmlspecialchars($teacher['full_name'] ?? ''); ?></td>
                                                            <td><?php echo htmlspecialchars($teacher['email'] ?? ''); ?></td>
                                                            <td><?php echo htmlspecialchars($teacher['course'] ?? ''); ?></td>
                                                            <td class="text-muted"><?php echo $teacher['deleted_at'] ? date('M d, Y H:i', strtotime($teacher['deleted_at'])) : 'N/A'; ?></td>
                                                            <td class="text-center">
                                                                <div class="btn-group" role="group">
                                                                    <button type="button" class="btn btn-sm btn-outline-success" 
                                                                            onclick="restoreTeacher(<?php echo $teacher['id']; ?>, '<?php echo htmlspecialchars(addslashes($teacher['full_name'] ?? '')); ?>')"
                                                                            title="Restore Teacher">
                                                                        <i class="bi bi-arrow-clockwise"></i>
                                                                    </button>
                                                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                                                            onclick="permanentDeleteTeacher(<?php echo $teacher['id']; ?>, '<?php echo htmlspecialchars(addslashes($teacher['full_name'] ?? '')); ?>')"
                                                                            title="Delete Permanently">
                                                                        <i class="bi bi-trash"></i>
                                                                    </button>
                                                                </div>
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
                    </div>
                </div>
                
                <!-- Dropped Students Tab -->
                <div class="tab-pane <?php echo $current_tab === 'dropped' ? 'active show' : ''; ?>" id="dropped" role="tabpanel" aria-labelledby="dropped-tab">
                    <!-- Search Bar for Dropped Students -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card shadow-sm">
                                <div class="card-body">
                                    <div class="row align-items-center">
                                        <div class="col-lg-12">
                                            <div class="input-group">
                                                <span class="input-group-text bg-light border-end-0">
                                                    <i class="bi bi-search text-muted"></i>
                                                </span>
                                                <input type="text" class="form-control border-start-0" id="droppedSearchInput" placeholder="Search by name, student ID, subject, or teacher...">
                                                <button class="btn btn-outline-secondary border-start-0" type="button" id="clearDroppedSearch">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Dropped Students Table -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card shadow-sm">
                                <div class="card-header bg-light border-0">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h5 class="card-title mb-0">
                                            <i class="bi bi-list-ul me-2"></i>Dropped Students
                                        </h5>
                                        <span class="badge bg-danger ms-3" id="dropped-result-count"><?php echo count($dropped_students); ?> result<?php echo count($dropped_students) !== 1 ? 's' : ''; ?></span>
                                    </div>
                                </div>
                                <div class="card-body p-0">
                                    <?php if (empty($dropped_students)): ?>
                                        <div class="text-center py-5">
                                            <i class="bi bi-person-x fs-1 text-muted"></i>
                                            <h5 class="text-muted mt-3">No dropped students found</h5>
                                            <p class="text-muted">There are no dropped student records to display.</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive" style="min-height: 200px;">
                                            <table class="table table-hover mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th class="border-0">Student ID</th>
                                                        <th class="border-0">Name</th>
                                                        <th class="border-0">Subject</th>
                                                        <th class="border-0">Teacher</th>
                                                        <th class="border-0">Dropped Date</th>
                                                        <th class="border-0 text-center">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="dropped-table-body">
                                                    <?php foreach ($dropped_students as $dropped): ?>
                                                        <tr class="dropped-row" style="display: table-row;">
                                                            <td class="fw-medium"><?php echo htmlspecialchars($dropped['student_id_code'] ?? ''); ?></td>
                                                            <td><?php echo htmlspecialchars($dropped['student_name'] ?? ''); ?></td>
                                                            <td>
                                                                <span class="badge bg-info">
                                                                    <?php echo htmlspecialchars($dropped['subject_code'] ?? ''); ?>
                                                                </span>
                                                                <br>
                                                                <small class="text-muted"><?php echo htmlspecialchars($dropped['subject_name'] ?? ''); ?></small>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($dropped['teacher_name'] ?? 'N/A'); ?></td>
                                                            <td class="text-muted">
                                                                <?php 
                                                                // Use deleted_at if available, otherwise use enrolled_at
                                                                if (!empty($dropped['deleted_at'])) {
                                                                    echo date('M d, Y H:i', strtotime($dropped['deleted_at']));
                                                                } elseif (!empty($dropped['enrolled_at'])) {
                                                                    echo date('M d, Y H:i', strtotime($dropped['enrolled_at']));
                                                                } else {
                                                                    echo 'N/A';
                                                                }
                                                                ?>
                                                            </td>
                                                            <td class="text-center">
                                                                <div class="btn-group" role="group">
                                                                    <button type="button" class="btn btn-sm btn-outline-info" 
                                                                            onclick="viewDroppedStudent(<?php echo $dropped['student_id']; ?>, <?php echo $dropped['class_id']; ?>)"
                                                                            title="View Details">
                                                                        <i class="bi bi-eye"></i>
                                                                    </button>
                                                                    <button type="button" class="btn btn-sm btn-outline-success" 
                                                                            onclick="restoreDroppedStudent(<?php echo $dropped['student_id']; ?>, '<?php echo htmlspecialchars(addslashes($dropped['student_name'] ?? '')); ?>')"
                                                                            title="Restore Student">
                                                                        <i class="bi bi-arrow-clockwise"></i>
                                                                    </button>
                                                                </div>
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
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <!-- Restore Confirmation Modal -->
    <div class="modal fade" id="restoreModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Restore Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to restore student: <strong id="restoreStudentName"></strong>?</p>
                    <p class="text-muted">This will make the student active again and they will appear in the student list.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="restore">
                        <input type="hidden" name="student_id" id="restoreStudentId">
                        <button type="submit" class="btn btn-success">Restore</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Permanent Delete Confirmation Modal -->
    <div class="modal fade" id="permanentDeleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Permanently Delete Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> This action cannot be undone!
                    </div>
                    <p>Are you sure you want to permanently delete student: <strong id="permanentDeleteStudentName"></strong>?</p>
                    <p class="text-muted">This will permanently remove the student and all related data from the system.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="permanent_delete">
                        <input type="hidden" name="student_id" id="permanentDeleteStudentId">
                        <button type="submit" class="btn btn-danger">Delete Permanently</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Restore Teacher Confirmation Modal -->
    <div class="modal fade" id="restoreTeacherModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Restore Teacher</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to restore teacher: <strong id="restoreTeacherName"></strong>?</p>
                    <p class="text-muted">This will make the teacher active again and they will appear in the teacher list.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="restore_teacher">
                        <input type="hidden" name="teacher_id" id="restoreTeacherId">
                        <button type="submit" class="btn btn-success">Restore</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Permanent Delete Teacher Confirmation Modal -->
    <div class="modal fade" id="permanentDeleteTeacherModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Permanently Delete Teacher</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> This action cannot be undone!
                    </div>
                    <p>Are you sure you want to permanently delete teacher: <strong id="permanentDeleteTeacherName"></strong>?</p>
                    <p class="text-muted">This will permanently remove the teacher and all related data (including classes) from the system.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="permanent_delete_teacher">
                        <input type="hidden" name="teacher_id" id="permanentDeleteTeacherId">
                        <button type="submit" class="btn btn-danger">Delete Permanently</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- View Dropped Student Details Modal -->
    <div class="modal fade" id="viewDroppedStudentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-person-x me-2"></i>Dropped Student Overview
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="droppedStudentLoading" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading student details...</p>
                    </div>
                    <div id="droppedStudentContent" style="display: none;">
                        <!-- Student Basic Info -->
                        <div class="card mb-3">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="bi bi-person me-2"></i>Student Information</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <p class="mb-2"><strong>Student ID:</strong> <span id="viewDroppedStudentId"></span></p>
                                        <p class="mb-2"><strong>Name:</strong> <span id="viewDroppedStudentName"></span></p>
                                        <p class="mb-2"><strong>Subject:</strong> <span id="viewDroppedSubject"></span></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="mb-2"><strong>Teacher:</strong> <span id="viewDroppedTeacher"></span></p>
                                        <p class="mb-2"><strong>Dropped Date:</strong> <span id="viewDroppedDate"></span></p>
                                        <p class="mb-2"><strong>Reason:</strong> <span class="badge bg-danger">Dropped by Teacher</span></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Attendance Statistics -->
                        <div class="card mb-3">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Attendance Statistics</h6>
                            </div>
                            <div class="card-body">
                                <div class="row text-center mb-3">
                                    <div class="col-md-3">
                                        <div class="p-3 bg-success bg-opacity-10 rounded">
                                            <h3 class="text-success mb-0" id="viewDroppedPresent">0</h3>
                                            <small class="text-muted">Present Days</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="p-3 bg-danger bg-opacity-10 rounded">
                                            <h3 class="text-danger mb-0" id="viewDroppedAbsent">0</h3>
                                            <small class="text-muted">Absent Days</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="p-3 bg-warning bg-opacity-10 rounded">
                                            <h3 class="text-warning mb-0" id="viewDroppedLate">0</h3>
                                            <small class="text-muted">Late Days</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="p-3 bg-info bg-opacity-10 rounded">
                                            <h3 class="text-info mb-0" id="viewDroppedTotal">0</h3>
                                            <small class="text-muted">Total Days</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="progress mb-2" style="height: 30px;">
                                    <div class="progress-bar bg-success" role="progressbar" id="viewDroppedProgressBar" style="width: 0%">
                                        <span id="viewDroppedPercentage">0%</span>
                                    </div>
                                </div>
                                <div class="row text-center">
                                    <div class="col-md-6">
                                        <p class="mb-1">
                                            <strong>Attendance Rate:</strong> 
                                            <span id="viewDroppedAttendanceRate" class="fw-bold text-primary">0%</span>
                                        </p>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="mb-1">
                                            <strong>Semester Completion:</strong> 
                                            <span id="viewDroppedSemesterCompletion" class="fw-bold text-info">0%</span>
                                        </p>
                                        <small class="text-muted" id="viewDroppedSemesterDays">0 of 0 days</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Additional Details -->
                        <div class="card">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Additional Information</h6>
                            </div>
                            <div class="card-body">
                                <p class="mb-2"><strong>Semester:</strong> <span id="viewDroppedSemester"></span></p>
                                <p class="mb-2"><strong>Academic Year:</strong> <span id="viewDroppedAcademicYear"></span></p>
                                <p class="mb-0"><strong>Section:</strong> <span id="viewDroppedSection"></span></p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Restore Dropped Student Confirmation Modal -->
    <div class="modal fade" id="restoreDroppedStudentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Restore Dropped Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to restore student: <strong id="restoreDroppedStudentName"></strong>?</p>
                    <p class="text-muted">This will make the student active again and they will appear in the student list.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="restore">
                        <input type="hidden" name="student_id" id="restoreDroppedStudentId">
                        <input type="hidden" name="tab" value="dropped">
                        <button type="submit" class="btn btn-success">Restore</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Simple tab switching
        document.addEventListener('DOMContentLoaded', function() {
            const studentsTab = document.getElementById('students-tab');
            const teachersTab = document.getElementById('teachers-tab');
            const droppedTab = document.getElementById('dropped-tab');
            const studentsPane = document.getElementById('students');
            const teachersPane = document.getElementById('teachers');
            const droppedPane = document.getElementById('dropped');
            
            function showStudentsTab() {
                // Update tab buttons
                if (studentsTab) {
                    studentsTab.classList.add('active');
                    studentsTab.setAttribute('aria-selected', 'true');
                }
                if (teachersTab) {
                    teachersTab.classList.remove('active');
                    teachersTab.setAttribute('aria-selected', 'false');
                }
                if (droppedTab) {
                    droppedTab.classList.remove('active');
                    droppedTab.setAttribute('aria-selected', 'false');
                }
                
                // Update panes
                if (studentsPane) {
                    studentsPane.classList.add('show', 'active');
                    studentsPane.style.display = 'block';
                    studentsPane.style.visibility = 'visible';
                    studentsPane.style.opacity = '1';
                }
                if (teachersPane) {
                    teachersPane.classList.remove('show', 'active');
                    teachersPane.style.display = 'none';
                    teachersPane.style.visibility = 'hidden';
                }
                if (droppedPane) {
                    droppedPane.classList.remove('show', 'active');
                    droppedPane.style.display = 'none';
                    droppedPane.style.visibility = 'hidden';
                }
            }
            
            function showTeachersTab() {
                // Update tab buttons
                if (teachersTab) {
                    teachersTab.classList.add('active');
                    teachersTab.setAttribute('aria-selected', 'true');
                }
                if (studentsTab) {
                    studentsTab.classList.remove('active');
                    studentsTab.setAttribute('aria-selected', 'false');
                }
                if (droppedTab) {
                    droppedTab.classList.remove('active');
                    droppedTab.setAttribute('aria-selected', 'false');
                }
                
                // Update panes
                if (teachersPane) {
                    teachersPane.classList.add('show', 'active');
                    teachersPane.style.display = 'block';
                    teachersPane.style.visibility = 'visible';
                    teachersPane.style.opacity = '1';
                }
                if (studentsPane) {
                    studentsPane.classList.remove('show', 'active');
                    studentsPane.style.display = 'none';
                    studentsPane.style.visibility = 'hidden';
                }
                if (droppedPane) {
                    droppedPane.classList.remove('show', 'active');
                    droppedPane.style.display = 'none';
                    droppedPane.style.visibility = 'hidden';
                }
                
                // Initialize teacher search
                setTimeout(function() {
                    if (typeof initTeacherSearch === 'function') {
                        initTeacherSearch();
                    }
                }, 100);
            }
            
            function showDroppedTab() {
                // Update tab buttons
                if (droppedTab) {
                    droppedTab.classList.add('active');
                    droppedTab.setAttribute('aria-selected', 'true');
                }
                if (studentsTab) {
                    studentsTab.classList.remove('active');
                    studentsTab.setAttribute('aria-selected', 'false');
                }
                if (teachersTab) {
                    teachersTab.classList.remove('active');
                    teachersTab.setAttribute('aria-selected', 'false');
                }
                
                // Update panes
                if (droppedPane) {
                    droppedPane.classList.add('show', 'active');
                    droppedPane.style.display = 'block';
                    droppedPane.style.visibility = 'visible';
                    droppedPane.style.opacity = '1';
                }
                if (studentsPane) {
                    studentsPane.classList.remove('show', 'active');
                    studentsPane.style.display = 'none';
                    studentsPane.style.visibility = 'hidden';
                }
                if (teachersPane) {
                    teachersPane.classList.remove('show', 'active');
                    teachersPane.style.display = 'none';
                    teachersPane.style.visibility = 'hidden';
                }
                
                // Initialize dropped search
                setTimeout(function() {
                    if (typeof initDroppedSearch === 'function') {
                        initDroppedSearch();
                    }
                }, 100);
            }
            
            // Add click handlers
            if (studentsTab) {
                studentsTab.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    showStudentsTab();
                });
            }
            
            if (teachersTab) {
                teachersTab.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    showTeachersTab();
                });
            }
            
            if (droppedTab) {
                droppedTab.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    showDroppedTab();
                });
            }
            
            // Initialize based on current tab
            const currentTab = '<?php echo $current_tab; ?>';
            if (currentTab === 'teachers') {
                showTeachersTab();
            } else if (currentTab === 'dropped') {
                showDroppedTab();
            } else {
                showStudentsTab();
            }
            
            // Force visibility check
            setTimeout(function() {
                const activePane = document.querySelector('.tab-pane.active');
                if (activePane) {
                    activePane.style.display = 'block';
                    activePane.style.visibility = 'visible';
                }
            }, 50);
        });
        
        // Filter and search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const filterButtons = document.querySelectorAll('.filter-btn');
            const studentRows = document.querySelectorAll('.student-row');
            const filterTitle = document.getElementById('current-filter-title');
            const searchInput = document.getElementById('searchInput');
            const clearSearchBtn = document.getElementById('clearSearch');
            const resultCount = document.getElementById('result-count');
            
            let currentStatus = 'deleted';
            let currentSearchTerm = '';
            
            // Status labels and search hints
            const statusLabels = {
                'deleted': 'Deleted Students',
                'declined': 'Declined Applications',
                'graduated': 'Graduated Students'
            };
            
            
            const searchPlaceholders = {
                'deleted': 'Search by name or student ID...',
                'declined': 'Search by name...',
                'graduated': 'Search by name or student ID...'
            };
            
            // Function to filter and search rows
            function filterAndSearch() {
                let visibleCount = 0;
                
                studentRows.forEach(row => {
                    const rowStatus = row.getAttribute('data-status');
                    const studentId = row.cells[0].textContent.toLowerCase();
                    const studentName = row.cells[1].textContent.toLowerCase();
                    
                    // Check if row matches current status
                    const statusMatch = rowStatus === currentStatus;
                    
                    // Check if row matches search term
                    let searchMatch = true;
                    if (currentSearchTerm) {
                        if (currentStatus === 'declined') {
                            // For declined applications, search by name only
                            searchMatch = studentName.includes(currentSearchTerm.toLowerCase());
                        } else {
                            // For deleted and graduated, search by name or student ID
                            searchMatch = studentName.includes(currentSearchTerm.toLowerCase()) || 
                                         studentId.includes(currentSearchTerm.toLowerCase());
                        }
                    }
                    
                    // Show/hide row based on both filters
                    if (statusMatch && searchMatch) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });
                
                // Update result count
                resultCount.textContent = `${visibleCount} result${visibleCount !== 1 ? 's' : ''}`;
            }
            
            // Filter button click handler
            filterButtons.forEach(button => {
                button.addEventListener('click', function() {
                    currentStatus = this.getAttribute('data-status');
                    
                    // Update active button
                    filterButtons.forEach(btn => btn.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Update title
                    filterTitle.textContent = statusLabels[currentStatus];
                    
                    // Update search placeholder
                    searchInput.placeholder = searchPlaceholders[currentStatus];
                    
                    // Show/hide section column based on status
                    const sectionColumns = document.querySelectorAll('.section-column');
                    if (currentStatus === 'declined') {
                        sectionColumns.forEach(col => col.style.display = 'none');
                    } else {
                        sectionColumns.forEach(col => col.style.display = '');
                    }
                    
                    // Clear search when switching filters
                    searchInput.value = '';
                    currentSearchTerm = '';
                    
                    // Apply filters
                    filterAndSearch();
                });
            });
            
            // Search input handler
            searchInput.addEventListener('input', function() {
                currentSearchTerm = this.value.trim();
                filterAndSearch();
            });
            
            // Clear search button handler
            clearSearchBtn.addEventListener('click', function() {
                searchInput.value = '';
                currentSearchTerm = '';
                filterAndSearch();
            });
            
            // Initialize with deleted students (active button)
            const activeButton = document.querySelector('.filter-btn.active');
            if (activeButton) {
                activeButton.click();
            }
        });
        
        function restoreStudent(studentId, studentName) {
            document.getElementById('restoreStudentId').value = studentId;
            document.getElementById('restoreStudentName').textContent = studentName;
            new bootstrap.Modal(document.getElementById('restoreModal')).show();
        }
        
        function permanentDeleteStudent(studentId, studentName) {
            document.getElementById('permanentDeleteStudentId').value = studentId;
            document.getElementById('permanentDeleteStudentName').textContent = studentName;
            new bootstrap.Modal(document.getElementById('permanentDeleteModal')).show();
        }
        
        // Teacher functions
        function restoreTeacher(teacherId, teacherName) {
            document.getElementById('restoreTeacherId').value = teacherId;
            document.getElementById('restoreTeacherName').textContent = teacherName;
            new bootstrap.Modal(document.getElementById('restoreTeacherModal')).show();
        }
        
        function permanentDeleteTeacher(teacherId, teacherName) {
            document.getElementById('permanentDeleteTeacherId').value = teacherId;
            document.getElementById('permanentDeleteTeacherName').textContent = teacherName;
            new bootstrap.Modal(document.getElementById('permanentDeleteTeacherModal')).show();
        }
        
        // Teacher search functionality - initialize when Teachers tab is shown
        function initTeacherSearch() {
            const teacherSearchInput = document.getElementById('teacherSearchInput');
            const clearTeacherSearchBtn = document.getElementById('clearTeacherSearch');
            const teacherRows = document.querySelectorAll('.teacher-row');
            const teacherResultCount = document.getElementById('teacher-result-count');
            
            if (!teacherSearchInput) return;
            
            let currentTeacherSearchTerm = '';
            
            function filterTeachers() {
                let visibleCount = 0;
                
                teacherRows.forEach(row => {
                    // Ensure row is visible by default
                    if (row.cells.length < 3) return;
                    
                    const teacherId = row.cells[0].textContent.toLowerCase();
                    const teacherName = row.cells[1].textContent.toLowerCase();
                    const teacherEmail = row.cells[2].textContent.toLowerCase();
                    
                    // Check if row matches search term
                    let searchMatch = true;
                    if (currentTeacherSearchTerm) {
                        searchMatch = teacherName.includes(currentTeacherSearchTerm.toLowerCase()) || 
                                     teacherId.includes(currentTeacherSearchTerm.toLowerCase()) ||
                                     teacherEmail.includes(currentTeacherSearchTerm.toLowerCase());
                    }
                    
                    // Show/hide row based on search
                    if (searchMatch) {
                        row.style.display = 'table-row';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });
                
                // Update result count
                if (teacherResultCount) {
                    teacherResultCount.textContent = `${visibleCount} result${visibleCount !== 1 ? 's' : ''}`;
                }
            }
            
            // Make sure all rows are visible initially
            teacherRows.forEach(row => {
                row.style.display = 'table-row';
            });
            
            // Search input handler
            teacherSearchInput.addEventListener('input', function() {
                currentTeacherSearchTerm = this.value.trim();
                filterTeachers();
            });
            
            // Clear search button handler
            if (clearTeacherSearchBtn) {
                clearTeacherSearchBtn.addEventListener('click', function() {
                    teacherSearchInput.value = '';
                    currentTeacherSearchTerm = '';
                    filterTeachers();
                });
            }
            
            // Initial filter to set correct count
            filterTeachers();
        }
        
        // Initialize teacher search when Teachers tab is shown
        const teachersTab = document.getElementById('teachers-tab');
        if (teachersTab) {
            teachersTab.addEventListener('shown.bs.tab', function() {
                initTeacherSearch();
            });
            
            // Also initialize if Teachers tab is already active
            if (teachersTab.classList.contains('active')) {
                setTimeout(initTeacherSearch, 100);
            }
        }
        
        // Also try to initialize on page load
        setTimeout(function() {
            const teachersTabPane = document.getElementById('teachers');
            if (teachersTabPane && teachersTabPane.classList.contains('active')) {
                initTeacherSearch();
            }
        }, 200);
        
        // Dropped students search functionality
        function initDroppedSearch() {
            const droppedSearchInput = document.getElementById('droppedSearchInput');
            const clearDroppedSearchBtn = document.getElementById('clearDroppedSearch');
            const droppedRows = document.querySelectorAll('.dropped-row');
            const droppedResultCount = document.getElementById('dropped-result-count');
            
            if (!droppedSearchInput) return;
            
            let currentDroppedSearchTerm = '';
            
            function filterDropped() {
                let visibleCount = 0;
                
                droppedRows.forEach(row => {
                    if (row.cells.length < 5) return;
                    
                    const studentId = row.cells[0].textContent.toLowerCase();
                    const studentName = row.cells[1].textContent.toLowerCase();
                    const subject = row.cells[2].textContent.toLowerCase();
                    const teacher = row.cells[3].textContent.toLowerCase();
                    
                    // Check if row matches search term
                    let searchMatch = true;
                    if (currentDroppedSearchTerm) {
                        searchMatch = studentName.includes(currentDroppedSearchTerm.toLowerCase()) || 
                                     studentId.includes(currentDroppedSearchTerm.toLowerCase()) ||
                                     subject.includes(currentDroppedSearchTerm.toLowerCase()) ||
                                     teacher.includes(currentDroppedSearchTerm.toLowerCase());
                    }
                    
                    // Show/hide row based on search
                    if (searchMatch) {
                        row.style.display = 'table-row';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });
                
                // Update result count
                if (droppedResultCount) {
                    droppedResultCount.textContent = `${visibleCount} result${visibleCount !== 1 ? 's' : ''}`;
                }
            }
            
            // Make sure all rows are visible initially
            droppedRows.forEach(row => {
                row.style.display = 'table-row';
            });
            
            // Search input handler
            droppedSearchInput.addEventListener('input', function() {
                currentDroppedSearchTerm = this.value.trim();
                filterDropped();
            });
            
            // Clear search button handler
            if (clearDroppedSearchBtn) {
                clearDroppedSearchBtn.addEventListener('click', function() {
                    droppedSearchInput.value = '';
                    currentDroppedSearchTerm = '';
                    filterDropped();
                });
            }
            
            // Initial filter to set correct count
            filterDropped();
        }
        
        // Initialize dropped search when Dropped tab is shown
        const droppedTab = document.getElementById('dropped-tab');
        if (droppedTab) {
            droppedTab.addEventListener('click', function() {
                setTimeout(initDroppedSearch, 100);
            });
            
            // Also initialize if Dropped tab is already active
            if (droppedTab.classList.contains('active')) {
                setTimeout(initDroppedSearch, 100);
            }
        }
        
        // Also try to initialize on page load
        setTimeout(function() {
            const droppedTabPane = document.getElementById('dropped');
            if (droppedTabPane && droppedTabPane.classList.contains('active')) {
                initDroppedSearch();
            }
        }, 200);
        
        // View dropped student details
        function viewDroppedStudent(studentId, classId) {
            const modal = new bootstrap.Modal(document.getElementById('viewDroppedStudentModal'));
            const loadingDiv = document.getElementById('droppedStudentLoading');
            const contentDiv = document.getElementById('droppedStudentContent');
            
            // Show loading, hide content
            loadingDiv.style.display = 'block';
            contentDiv.style.display = 'none';
            
            // Show modal
            modal.show();
            
            // Fetch student details and attendance
            fetch(`fetch_dropped_student_details.php?student_id=${studentId}&class_id=${classId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Populate student info
                        document.getElementById('viewDroppedStudentId').textContent = data.student_id || 'N/A';
                        document.getElementById('viewDroppedStudentName').textContent = data.student_name || 'N/A';
                        document.getElementById('viewDroppedSubject').textContent = data.subject_code + ' - ' + data.subject_name;
                        document.getElementById('viewDroppedTeacher').textContent = data.teacher_name || 'N/A';
                        document.getElementById('viewDroppedDate').textContent = data.dropped_date || 'N/A';
                        document.getElementById('viewDroppedSemester').textContent = data.semester || 'N/A';
                        document.getElementById('viewDroppedAcademicYear').textContent = data.academic_year || 'N/A';
                        document.getElementById('viewDroppedSection').textContent = data.section || 'N/A';
                        
                        // Populate attendance statistics
                        const present = parseInt(data.present_count || 0);
                        const absent = parseInt(data.absent_count || 0);
                        const late = parseInt(data.late_count || 0);
                        const excused = parseInt(data.excused_count || 0);
                        const total = present + absent + late + excused;
                        const attendanceRate = parseFloat(data.attendance_rate || 0);
                        const semesterCompletion = parseFloat(data.semester_completion || 0);
                        const semesterDays = parseInt(data.semester_days || 0);
                        const daysAttended = parseInt(data.days_attended || 0);
                        
                        document.getElementById('viewDroppedPresent').textContent = present;
                        document.getElementById('viewDroppedAbsent').textContent = absent;
                        document.getElementById('viewDroppedLate').textContent = late;
                        document.getElementById('viewDroppedTotal').textContent = total;
                        document.getElementById('viewDroppedPercentage').textContent = attendanceRate.toFixed(2) + '%';
                        document.getElementById('viewDroppedAttendanceRate').textContent = attendanceRate.toFixed(2) + '%';
                        document.getElementById('viewDroppedProgressBar').style.width = attendanceRate + '%';
                        document.getElementById('viewDroppedSemesterCompletion').textContent = semesterCompletion.toFixed(2) + '%';
                        document.getElementById('viewDroppedSemesterDays').textContent = daysAttended + ' of ' + semesterDays + ' days';
                        
                        // Hide loading, show content
                        loadingDiv.style.display = 'none';
                        contentDiv.style.display = 'block';
                    } else {
                        loadingDiv.innerHTML = '<p class="text-danger">Error loading student details: ' + (data.error || 'Unknown error') + '</p>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    loadingDiv.innerHTML = '<p class="text-danger">Error loading student details. Please try again.</p>';
                });
        }
        
        // Restore dropped student
        function restoreDroppedStudent(studentId, studentName) {
            document.getElementById('restoreDroppedStudentId').value = studentId;
            document.getElementById('restoreDroppedStudentName').textContent = studentName;
            new bootstrap.Modal(document.getElementById('restoreDroppedStudentModal')).show();
        }
    </script>
</body>
</html>
