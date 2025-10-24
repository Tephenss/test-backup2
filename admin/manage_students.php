<?php
session_start();
require_once '../config/database.php';
require_once '../config.php';
require_once '../helpers/EmailVerification.php';
require_once '../includes/fetch_students.php';

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Get admin data (for topbar)
try {
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $admin = $stmt->fetch();
} catch(PDOException $e) {
    error_log("Error fetching admin data: " . $e->getMessage());
    $admin = null;
}

// Fetch all sections for section buttons
try {
    $sections = $pdo->query("SELECT id, name, year_level FROM sections ORDER BY year_level, name")->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $sections = [];
}
// Fetch unique year levels from sections
try {
    $year_levels = $pdo->query("SELECT DISTINCT year_level FROM sections ORDER BY year_level")->fetchAll(PDO::FETCH_COLUMN);
} catch(PDOException $e) {
    $year_levels = [1,2,3,4];
}

// Fetch students for selected section and year level
$selected_section = isset($_GET['section_id']) ? $_GET['section_id'] : '';
$selected_year = isset($_GET['year_level']) ? $_GET['year_level'] : '';
$students = getStudents(['section' => $selected_section, 'year_level' => $selected_year]);

// Handle POST requests for editing students
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'edit':
                $studentId = $_POST['student_id'];
                $first_name = trim($_POST['first_name']);
                $middle_name = trim($_POST['middle_name']);
                $last_name = trim($_POST['last_name']);
                $suffix_name = trim($_POST['suffix_name']);
                $sex = trim($_POST['sex']);
                $civil_status = trim($_POST['civil_status']);
                $birthdate = trim($_POST['birthdate']);
                $place_of_birth = trim($_POST['place_of_birth']);
                $citizenship = trim($_POST['citizenship']);
                $address = trim($_POST['address']);
                $phone_number = trim($_POST['phone_number']);
                $email = trim($_POST['email']);
                $course = trim($_POST['course']);
                $resetPassword = isset($_POST['reset_password']);

                if (empty($email)) {
                    $_SESSION['error_message'] = "Email is required.";
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $_SESSION['error_message'] = "Invalid Email format.";
                } else {
                    // Check if email exists (excluding current student)
                    $stmt = $pdo->prepare("SELECT id FROM students WHERE email = ? AND id != ?");
                    $stmt->execute([$email, $studentId]);
                    if ($stmt->fetch()) {
                        $_SESSION['error_message'] = "Email already exists for another student.";
                    } else {
                        $params = [
                            $first_name, $middle_name, $last_name, $suffix_name, $sex, $civil_status, $birthdate, $place_of_birth, $citizenship, $address, $phone_number, $email, $course
                        ];
                        $sql = "UPDATE students SET first_name = ?, middle_name = ?, last_name = ?, suffix_name = ?, sex = ?, civil_status = ?, birthdate = ?, place_of_birth = ?, citizenship = ?, address = ?, phone_number = ?, email = ?, course = ?";
                        if ($resetPassword) {
                            $password = bin2hex(random_bytes(4)); // Generate 8 character random hex string
                            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                            $sql .= ", password = ?";
                            $params[] = $hashedPassword;
                            $_SESSION['info_message'] = "Password for student ID {$studentId} reset. New password: {$password}";
                        }
                        $sql .= " WHERE id = ?";
                        $params[] = $studentId;
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                        
                        // Backup password reset to Firebase
                        if ($resetPassword) {
                            try {
                                require_once '../helpers/BackupHooks.php';
                                $backupHooks = new BackupHooks();
                                $updatedData = [
                                    'password_reset_at' => date('Y-m-d H:i:s'),
                                    'reset_method' => 'admin_reset'
                                ];
                                $backupHooks->backupStudentPasswordChange($studentId, $updatedData);
                            } catch (Exception $e) {
                                error_log("Firebase backup failed for admin password reset: " . $e->getMessage());
                            }
                        }
                        
                        $_SESSION['success_message'] = "Student updated successfully.";
                    }
                }
                break;

            case 'delete_student':
                $student_id = $_POST['student_id'];
                try {
                    // Check if student is enrolled in any active classes
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM class_students cs 
                        JOIN classes c ON cs.class_id = c.id 
                        WHERE cs.student_id = ? AND c.status = 'active'
                    ");
                    $stmt->execute([$student_id]);
                    $activeClasses = $stmt->fetchColumn();

                    if ($activeClasses > 0) {
                        $_SESSION['error_message'] = "Cannot delete student: They are currently enrolled in active classes.";
                    } else {
                        // Use soft delete instead of hard delete
                        if (softDelete('students', $student_id)) {
                            $_SESSION['success_message'] = "Student has been moved to archive.";
                        } else {
                            $_SESSION['error_message'] = "Error archiving student. Please try again.";
                            error_log("Failed to soft delete student ID: " . $student_id);
                        }
                    }
                } catch(PDOException $e) {
                    $_SESSION['error_message'] = "Error: " . $e->getMessage();
                    error_log("Error in delete_student: " . $e->getMessage());
                }
                header("Location: manage_students.php");
                exit();
                break;
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
        error_log("Student CRUD error: " . $e->getMessage());
    }
    
    header("Location: manage_students.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students - iAttendance</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <style>
        .table-actions .btn { margin: 0 2px; }
        .modal-body label { margin-top: 10px; }
        .input-group-text {
            border-radius: 0.375rem 0 0 0.375rem;
            border-right: none;
        }
        .input-group .form-control {
            border-radius: 0 0.375rem 0.375rem 0;
            border-left: none;
        }
        .input-group .form-control:focus {
            border-color: #dee2e6;
            box-shadow: none;
        }
        #studentSearchInput {
            padding-left: 0;
        }
        #studentSearchInput::placeholder {
            color: #adb5bd;
        }
        /* Unified Modal Design for Admin */
        .modal-content {
            border-radius: 18px;
            box-shadow: 0 8px 32px rgba(44,62,80,0.13);
            border: none;
            background: #fff;
        }
        .modal-header {
            border-bottom: none;
            padding-bottom: 0.5rem;
            background: none;
        }
        .modal-title {
            font-size: 1.35rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #6c757d;
        }
        .modal-title i {
            color: #adb5bd;
            font-size: 1.4rem;
        }
        .modal-body {
            background: #f1f5f9;
            border-radius: 0 0 18px 18px;
            padding: 2rem 1.5rem 1.5rem 1.5rem;
        }
        .modal-footer {
            background: none;
            border-top: none;
            padding: 1.2rem 1.5rem 1.5rem 1.5rem;
            display: flex;
            gap: 0.7rem;
            justify-content: flex-end;
        }
        .btn-primary {
            font-weight: 700;
            padding: 0.55rem 2.2rem;
            border-radius: 1.5rem;
            font-size: 1.08rem;
            background: #6c757d;
            border: none;
            color: #fff;
            transition: background 0.18s, box-shadow 0.18s;
        }
        .btn-primary:hover {
            background: #495057;
            color: #fff;
        }
        .btn-secondary {
            font-weight: 600;
            padding: 0.55rem 1.5rem;
            border-radius: 1.5rem;
            font-size: 1.08rem;
            background: #e0e7ef !important;
            color: #444 !important;
            border: none;
            transition: background 0.18s, color 0.18s;
        }
        .btn-secondary:hover {
            background: #adb5bd !important;
            color: #222 !important;
        }
        .btn-danger {
            font-weight: 600;
            padding: 0.55rem 1.5rem;
            border-radius: 1.5rem;
            font-size: 1.08rem;
            background: #f87171;
            color: #fff;
            border: none;
            transition: background 0.18s, color 0.18s;
        }
        .btn-danger:hover {
            background: #dc2626;
            color: #fff;
        }
    </style>
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

        <div class="container-fluid px-4">
            <h1 class="mb-4">Manage Students</h1>
            <div class="mb-3 d-flex flex-wrap align-items-center gap-2">
                <select id="year-filter" class="form-select w-auto">
                    <?php foreach ($year_levels as $year): ?>
                        <option value="<?php echo $year; ?>"><?php echo $year; ?><?php echo ($year == 1) ? 'st' : (($year == 2) ? 'nd' : (($year == 3) ? 'rd' : 'th')); ?> Year</option>
                                    <?php endforeach; ?>
                </select>
                <div id="section-buttons" class="btn-group ms-2" role="group" aria-label="Section Buttons"></div>
            </div>
            <!-- Add modern search bar above the student list -->
            <div class="card shadow mb-4">
                <div class="card-body py-2">
                    <div class="d-flex align-items-center">
                        <div class="input-group" style="max-width: 300px;">
                            <span class="input-group-text bg-white border-end-0">
                                <i class="bi bi-search text-muted"></i>
                            </span>
                            <input type="text" class="form-control border-start-0" id="studentSearchInput" placeholder="Search students...">
                        </div>
                    </div>
                </div>
            </div>
            <div id="students-list" class="mt-4"></div>
            <!-- If you have pagination, wrap it like this: -->
            <div id="studentPaginationWrapper">
                <!-- existing pagination code here -->
            </div>
        </div>

        <!-- Edit Student Modal -->
        <div class="modal fade" id="editStudentModal" tabindex="-1" aria-labelledby="editStudentModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form action="manage_students.php" method="POST">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" id="editStudentId" name="student_id">
                        <div class="modal-header">
                            <h5 class="modal-title" id="editStudentModalLabel">Edit Student</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="editStudentIdFieldDisplay" class="form-label">Student ID</label>
                                <input type="text" class="form-control" id="editStudentIdFieldDisplay" name="student_id_display" readonly>
                            </div>
                            <div class="mb-3">
                                <label for="editFirstName" class="form-label">First Name</label>
                                <input type="text" class="form-control" id="editFirstName" name="first_name" required>
                            </div>
                            <div class="mb-3">
                                <label for="editMiddleName" class="form-label">Middle Name</label>
                                <input type="text" class="form-control" id="editMiddleName" name="middle_name">
                            </div>
                            <div class="mb-3">
                                <label for="editLastName" class="form-label">Last Name</label>
                                <input type="text" class="form-control" id="editLastName" name="last_name" required>
                            </div>
                            <div class="mb-3">
                                <label for="editSuffixName" class="form-label">Suffix</label>
                                <input type="text" class="form-control" id="editSuffixName" name="suffix_name">
                            </div>
                            <div class="mb-3">
                                <label for="editSex" class="form-label">Sex</label>
                                <select class="form-select" id="editSex" name="sex" required>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="editCivilStatus" class="form-label">Civil Status</label>
                                <select class="form-select" id="editCivilStatus" name="civil_status" required>
                                    <option value="Single">Single</option>
                                    <option value="Married">Married</option>
                                    <option value="Widowed">Widowed</option>
                                    <option value="Separated">Separated</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="editBirthdate" class="form-label">Birthdate</label>
                                <input type="date" class="form-control" id="editBirthdate" name="birthdate">
                            </div>
                            <div class="mb-3">
                                <label for="editPlaceOfBirth" class="form-label">Place of Birth</label>
                                <input type="text" class="form-control" id="editPlaceOfBirth" name="place_of_birth">
                            </div>
                            <div class="mb-3">
                                <label for="editCitizenship" class="form-label">Citizenship</label>
                                <input type="text" class="form-control" id="editCitizenship" name="citizenship">
                            </div>
                            <div class="mb-3">
                                <label for="editAddress" class="form-label">Address</label>
                                <input type="text" class="form-control" id="editAddress" name="address">
                            </div>
                            <div class="mb-3">
                                <label for="editPhone" class="form-label">Phone Number</label>
                                <input type="text" class="form-control" id="editPhone" name="phone_number">
                            </div>
                            <div class="mb-3">
                                <label for="editEmail" class="form-label">Email address</label>
                                <input type="email" class="form-control" id="editEmail" name="email" required>
                            </div>
                            <div class="mb-3">
                                <label for="editCourse" class="form-label">Course</label>
                                <input type="text" class="form-control" id="editCourse" name="course" required>
                            </div>
                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="editResetPassword" name="reset_password">
                                <label class="form-check-label" for="editResetPassword">Reset Password (auto-generates new)</label>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Delete Student Modal -->
        <div class="modal fade" id="deleteStudentModal" tabindex="-1" aria-labelledby="deleteStudentModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                        <div class="modal-header">
                        <h5 class="modal-title" id="deleteStudentModalLabel">Delete Student</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                        <p>Are you sure you want to delete student: <span id="deleteStudentName" class="fw-bold"></span>?</p>
                        <p class="text-danger">This action cannot be undone.</p>
                        <input type="hidden" id="deleteStudentId">
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger" id="confirmDeleteStudentBtn">Yes, Delete</button>
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
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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
                                    <tr><td><strong>Phone Number:</strong></td><td id="viewStudentPhone"></td></tr>
                                    <tr><td><strong>Email:</strong></td><td id="viewStudentEmail"></td></tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-primary border-bottom border-primary pb-2 mb-3">Academic Information</h6>
                                <table class="table table-borderless mb-3">
                                    <tr><td width="140"><strong>Course:</strong></td><td id="viewStudentCourse"></td></tr>
                                    <tr><td><strong>Year Level:</strong></td><td id="viewStudentYear"></td></tr>
                                    <tr><td><strong>Created At:</strong></td><td id="viewStudentCreated"></td></tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>
    <script src="../assets/js/manage_students_ajax.js"></script>
    <script>
        // Script to handle modal data population for Edit and Delete
        const editStudentModal = document.getElementById('editStudentModal');
        editStudentModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const studentId = button.getAttribute('data-id');
            const studentEmail = button.getAttribute('data-email');
            const studentCourse = button.getAttribute('data-course');
            const studentName = button.getAttribute('data-name');
            
            const modalTitle = editStudentModal.querySelector('.modal-title');
            const studentIdInput = editStudentModal.querySelector('#editStudentId');
            const studentEmailInput = editStudentModal.querySelector('#editEmail');
            const studentCourseInput = editStudentModal.querySelector('#editCourse');
            const resetPasswordCheckbox = editStudentModal.querySelector('#editResetPassword');

            modalTitle.textContent = 'Edit Student: ' + studentName;
            studentIdInput.value = studentId;
            studentEmailInput.value = studentEmail;
            studentCourseInput.value = studentCourse;
            resetPasswordCheckbox.checked = false; // Ensure checkbox is unchecked by default
        });

        // Toggle sidebar
        document.querySelector('.toggle-sidebar').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('collapsed');
            document.querySelector('.page-content').classList.toggle('expanded');
        });

        // Auto-dismiss alerts
        window.setTimeout(function() {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000); // Dismiss after 5 seconds

        const SECTIONS = <?php echo json_encode($sections); ?>;
        const YEAR_LEVELS = <?php echo json_encode($year_levels); ?>;

        // Get section from URL if present
        function getQueryParam(param) {
            const urlParams = new URLSearchParams(window.location.search);
            return urlParams.get(param);
        }
        document.addEventListener('DOMContentLoaded', function() {
            const sectionFromUrl = getQueryParam('section');
            if (sectionFromUrl) {
                // Wait for section buttons to render, then click the correct one
                setTimeout(function() {
                    const sectionButtons = document.querySelectorAll('#section-buttons button');
                    sectionButtons.forEach(btn => {
                        if (btn.textContent.trim() === sectionFromUrl) {
                            btn.click();
                        }
                    });
                }, 300);
            }
        });
    </script>
</body>
</html> 