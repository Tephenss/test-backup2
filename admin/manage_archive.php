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
        
        header('Location: manage_archive.php');
        exit();
    }
    
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

// Get statistics
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
                            <p class="text-muted mb-0">View and manage archived student records</p>
                        </div>
                    </div>
                    
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
    </script>
</body>
</html>
