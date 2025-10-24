<?php
session_start();
require_once '../config/database.php';
require_once '../helpers/EmailVerification.php';
require_once '../helpers/BackupHooks.php';

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

// Handle application approval
if (isset($_POST['action']) && $_POST['action'] === 'approve' && isset($_POST['student_id'])) {
    try {
        // Check if transaction is already active
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }
        
        // Get student data
        $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
        $stmt->execute([$_POST['student_id']]);
        $student = $stmt->fetch();
        
        if ($student) {
            $password = $student['birthdate'];
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            // --- Automatic Section Assignment Logic ---
            $yearLevel = $student['year_level'];
            $courseCode = $student['course'];
            // Get course_id
            $stmtCourse = $pdo->prepare("SELECT id FROM courses WHERE code = ? LIMIT 1");
            $stmtCourse->execute([$courseCode]);
            $courseId = $stmtCourse->fetchColumn();
            // Find all sections for this year level and course
            $stmtSections = $pdo->prepare("SELECT id, name FROM sections WHERE year_level = ? AND course_id = ? ORDER BY name ASC");
            $stmtSections->execute([$yearLevel, $courseId]);
            $sections = $stmtSections->fetchAll(PDO::FETCH_ASSOC);
            $assignedSection = null;
            foreach ($sections as $section) {
                $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM students WHERE section = ? AND is_deleted = 0");
                $stmtCount->execute([$section['name']]);
                $studentCount = $stmtCount->fetchColumn();
                if ($studentCount < 5) {
                    $assignedSection = $section['name'];
                    break;
                }
            }
            if (!$assignedSection) {
                // All sections full, create new section with next available letter
                $sectionLetters = array_merge(['A','B','C','D','E'], range('F', 'Z'));
                $existingNames = array_map(function($s) { return $s['name']; }, $sections);
                foreach ($sectionLetters as $letter) {
                    if (!in_array($letter, $existingNames)) {
                        // Create new section
                        $stmtAdd = $pdo->prepare("INSERT INTO sections (name, course_id, year_level) VALUES (?, ?, ?)");
                        $stmtAdd->execute([$letter, $courseId, $yearLevel]);
                        $assignedSection = $letter;
                        break;
                    }
                }
            }
            // Update student record with credentials and assigned section
            $stmt = $pdo->prepare("
                UPDATE students 
                SET password = ?, status = 'approved', section = ?
                WHERE id = ?
            ");
            $stmt->execute([$hashedPassword, $assignedSection, $_POST['student_id']]);
            
            // Backup student approval to Firebase
            try {
                $backupHooks = new BackupHooks();
                $updatedData = [
                    'status' => 'approved',
                    'section' => $assignedSection,
                    'approved_at' => date('Y-m-d H:i:s')
                ];
                $backupHooks->backupStudentApproval($_POST['student_id'], $updatedData);
            } catch (Exception $e) {
                // Log backup error but don't fail approval
                error_log("Firebase backup failed for student approval: " . $e->getMessage());
            }
            // --- End Section Assignment ---
            
            // Get all active classes for the student's section
            $stmt = $pdo->prepare("SELECT id FROM classes WHERE section = ? AND status = 'active'");
            $stmt->execute([$assignedSection]);
            $classes = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Enroll student in all classes for their section
            foreach ($classes as $class_id) {
                $stmt = $pdo->prepare("
                    INSERT INTO class_students (class_id, student_id, status) 
                    VALUES (?, ?, 'active')
                    ON DUPLICATE KEY UPDATE status = 'active'
                ");
                $stmt->execute([$class_id, $_POST['student_id']]);
            }
            
            // Send email with credentials
            $emailVerification = new EmailVerification();
            $subject = "iAttendance Account Approved";
            $message = "Hello " . $student['first_name'] . ",\n\n"
                    . "Congratulations! Your application for the iAttendance Management System has been approved.\n\n"
                    . "You can now log in with these details:\n\n"
                    . "Student ID: " . $student['student_id'] . "\n"
                    . "Password: " . $password . "\n\n"
                    . "Please use the Account Recovery feature on the login page to change your password after your first login for security.\n\n"
                    . "Thank you,\niAttendance Team";
            
            $emailVerification->sendEmail($student['email'], $subject, $message);
            
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            $_SESSION['success_message'] = "Application approved and credentials sent to student. Assigned to section: <strong>" . htmlspecialchars($assignedSection) . "</strong>.";
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error_message'] = "Error approving application: " . $e->getMessage();
    }
    
    header("Location: manage_applications.php");
    exit();
}

// Handle application rejection
if (isset($_POST['action']) && $_POST['action'] === 'reject' && isset($_POST['student_id'])) {
    try {
        // Get student email before deletion
        $stmt = $pdo->prepare("SELECT email, first_name FROM students WHERE id = ?");
        $stmt->execute([$_POST['student_id']]);
        $student = $stmt->fetch();
        
        if ($student) {
            // Soft delete the application (mark as declined)
            $stmt = $pdo->prepare("
                UPDATE students 
                SET is_deleted = 1, 
                    deleted_at = CURRENT_TIMESTAMP,
                    status = 'declined'
                WHERE id = ?
            ");
            $stmt->execute([$_POST['student_id']]);
            
            // Get rejection reason
            $reason = isset($_POST['rejection_reason']) ? trim($_POST['rejection_reason']) : "No specific reason provided.";
            
            // Send rejection email
            $emailVerification = new EmailVerification();
            $subject = "iAttendance Application Status";
            $message = "Dear " . $student['first_name'] . ",\n\n"
                    . "We regret to inform you that your application for the iAttendance Management System has been declined.\n\n"
                    . "Reason: " . $reason . "\n\n"
                    . "If you have any questions, please contact the administration for more information.\n\n"
                    . "Best regards,\niAttendance Team";
            
            $emailVerification->sendEmail($student['email'], $subject, $message);
            
            $_SESSION['success_message'] = "Application rejected and notification sent to student.";
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error rejecting application: " . $e->getMessage();
    }
    
    header("Location: manage_applications.php");
    exit();
}

// Get all pending applications
try {
    $stmt = $pdo->prepare("
        SELECT * FROM students 
        WHERE status = 'pending'
        AND is_deleted = 0
        ORDER BY created_at DESC
    ");
    $stmt->execute();
    $applications = $stmt->fetchAll();
} catch(PDOException $e) {
    $applications = [];
    $_SESSION['error_message'] = "Error fetching applications: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Applications - iAttendance</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <style>
        /* Enhanced table styling */
        .table-container {
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .table thead {
            background-color: #f8f9fa;
        }
        
        .table thead th {
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
            padding: 16px;
            border-bottom: 2px solid #e9ecef;
        }
        
        .table tbody tr {
            transition: all 0.3s ease;
        }
        
        .table tbody tr:hover {
            background-color: rgba(13, 110, 253, 0.04);
            transform: translateY(-2px);
        }
        
        .table tbody td {
            padding: 16px;
            vertical-align: middle;
        }
        
        /* Action buttons */
        .action-buttons .btn {
            width: 36px;
            height: 36px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            margin-right: 8px;
            opacity: 0.9;
        }
        
        .action-buttons .btn:hover {
            transform: scale(1.15);
            opacity: 1;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .action-buttons .btn-info {
            background-color: #0dcaf0;
            border-color: #0dcaf0;
        }
        
        .action-buttons .btn-success {
            background-color: #20c997;
            border-color: #20c997;
        }
        
        .action-buttons .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
        }
        
        /* Card styling */
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        /* Animation classes */
        .fade-in {
            animation: fadeIn 0.5s ease forwards;
        }
        
        .scale-in {
            animation: scaleIn 0.3s ease forwards;
        }
        
        .slide-in {
            animation: slideIn 0.4s ease forwards;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes scaleIn {
            from { transform: scale(0.9); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        
        @keyframes slideIn {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        /* Enhanced modal styling */
        .modal-content {
            border: none;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            overflow: hidden;
        }
        
        .modal-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #eee;
            padding: 1.2rem 1.5rem;
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .modal-footer {
            background-color: #f8f9fa;
            border-top: 1px solid #eee;
            padding: 1rem 1.5rem;
        }
        
        .modal.fade .modal-dialog {
            transition: transform 0.3s ease-out, opacity 0.3s ease;
            transform: scale(0.95);
            opacity: 0;
        }
        
        .modal.show .modal-dialog {
            transform: scale(1);
            opacity: 1;
        }
        
        /* Info section styling */
        .info-section h6 {
            color: #0d6efd;
            font-weight: 700;
            margin-bottom: 1rem;
            position: relative;
            padding-bottom: 8px;
        }
        
        .info-section h6:after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 40px;
            height: 3px;
            background: #0d6efd;
            border-radius: 3px;
        }
        
        .info-section p {
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
        }
        
        .info-section strong {
            min-width: 120px;
            display: inline-block;
            color: #495057;
        }

        /* Empty state styling */
        .empty-state {
            text-align: center;
            padding: 3rem 0;
        }
        
        .empty-state .icon {
            font-size: 4rem;
            color: #dee2e6;
            margin-bottom: 1rem;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        /* Management header styling */
        .management-header {
            margin-bottom: 1.5rem;
        }
        
        .management-header h2 {
            font-weight: 700;
            color: #212529;
            display: inline-block;
            position: relative;
            padding-bottom: 8px;
        }
        
        .management-header h2:after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 60px;
            height: 4px;
            background: #0d6efd;
            border-radius: 4px;
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
                    <span class="user-name"><?php echo htmlspecialchars($admin['full_name'] ?? $_SESSION['username']); ?></span>
                </a>
                <ul class="dropdown-menu user-dropdown" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i> Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="../auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success fade show" id="successAlert" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php 
                echo $_SESSION['success_message'];
                unset($_SESSION['success_message']);
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger fade show" id="errorAlert" role="alert">
                <i class="bi bi-exclamation-circle-fill me-2"></i>
                <?php 
                echo $_SESSION['error_message'];
                unset($_SESSION['error_message']);
                ?>
            </div>
        <?php endif; ?>

        <div class="management-header animate-fadeIn">
            <h2 class="slide-in">Student Applications</h2>
        </div>

        <div class="card animate-fadeIn delay-1 scale-in">
            <div class="card-body">
                <div class="table-responsive table-container">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Application ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Year Level</th>
                                <th>Applied On</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($applications)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4">
                                        <div class="empty-state">
                                            <i class="bi bi-inbox icon"></i>
                                            <h5 class="text-muted mt-3">No pending applications</h5>
                                            <p class="text-muted">New student applications will appear here</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($applications as $application): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($application['student_id']); ?></td>
                                        <td>
                                            <?php 
                                            $firstName = trim($application['first_name'] ?? '');
                                            $middleName = trim($application['middle_name'] ?? '');
                                            $lastName = trim($application['last_name'] ?? '');
                                            $suffix = trim($application['suffix_name'] ?? '');
                                            $middleDisplay = '';
                                            $middleInitial = '';
                                            if ($middleName) {
                                                $middleParts = preg_split('/\s+/', $middleName);
                                                if (count($middleParts) > 1) {
                                                    $middleDisplay = implode(' ', array_slice($middleParts, 0, -1));
                                                    $middleInitial = strtoupper($middleParts[count($middleParts)-1][0]) . '.';
                                                } else {
                                                    $middleInitial = strtoupper($middleParts[0][0]) . '.';
                                                }
                                            }
                                            $displayName = $lastName;
                                            if ($firstName) $displayName .= ', ' . $firstName;
                                            if ($middleDisplay) $displayName .= ' ' . $middleDisplay;
                                            if ($middleInitial) $displayName .= ' ' . $middleInitial;
                                            if ($suffix) $displayName .= ' ' . $suffix;
                                            echo htmlspecialchars($displayName);
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($application['email']); ?></td>
                                        <td><?php echo htmlspecialchars($application['year_level']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($application['created_at'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button type="button" class="btn btn-sm btn-info view-application" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#applicationModal"
                                                        data-id="<?php echo $application['id']; ?>"
                                                        data-student-id="<?php echo htmlspecialchars($application['student_id']); ?>"
                                                        data-name="<?php echo htmlspecialchars($application['first_name'] . ' ' . 
                                                            ($application['middle_name'] ? $application['middle_name'] . ' ' : '') . 
                                                            $application['last_name'] . 
                                                            ($application['suffix_name'] ? ' ' . $application['suffix_name'] : '')); ?>"
                                                        data-sex="<?php echo htmlspecialchars($application['sex']); ?>"
                                                        data-civil-status="<?php echo htmlspecialchars($application['civil_status']); ?>"
                                                        data-birthdate="<?php echo date('M d, Y', strtotime($application['birthdate'])); ?>"
                                                        data-place-of-birth="<?php echo htmlspecialchars($application['place_of_birth']); ?>"
                                                        data-citizenship="<?php echo htmlspecialchars($application['citizenship']); ?>"
                                                        data-address="<?php echo htmlspecialchars($application['address']); ?>"
                                                        data-phone="<?php echo htmlspecialchars($application['phone_number']); ?>"
                                                        data-email="<?php echo htmlspecialchars($application['email']); ?>"
                                                        data-course="<?php echo htmlspecialchars($application['course']); ?>"
                                                        data-last-year="<?php echo htmlspecialchars($application['year_level']); ?>">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Application Modal -->
        <div class="modal fade" id="applicationModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header border-0">
                        <h5 class="modal-title">Application Details</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h6 class="text-primary border-bottom border-primary pb-2 mb-3">Personal Information</h6>
                                <table class="table table-borderless">
                                    <tr>
                                        <td width="140"><strong>Name:</strong></td>
                                        <td id="modal-name"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Sex:</strong></td>
                                        <td id="modal-sex"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Civil Status:</strong></td>
                                        <td id="modal-civil-status"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Birthdate:</strong></td>
                                        <td id="modal-birthdate"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Place of Birth:</strong></td>
                                        <td id="modal-place-of-birth"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Citizenship:</strong></td>
                                        <td id="modal-citizenship"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Form 138:</strong></td>
                                        <td><?php if (!empty($application['form_138'])): ?><button type="button" class="btn btn-link p-0 view-doc-btn" data-bs-toggle="modal" data-bs-target="#viewDocumentModal" data-doc-url="../<?php echo htmlspecialchars($application['form_138']); ?>">View Document</button><?php else: ?>N/A<?php endif; ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Good Moral:</strong></td>
                                        <td><?php if (!empty($application['good_moral'])): ?><button type="button" class="btn btn-link p-0 view-doc-btn" data-bs-toggle="modal" data-bs-target="#viewDocumentModal" data-doc-url="../<?php echo htmlspecialchars($application['good_moral']); ?>">View Document</button><?php else: ?>N/A<?php endif; ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Diploma:</strong></td>
                                        <td><?php if (!empty($application['diploma'])): ?><button type="button" class="btn btn-link p-0 view-doc-btn" data-bs-toggle="modal" data-bs-target="#viewDocumentModal" data-doc-url="../<?php echo htmlspecialchars($application['diploma']); ?>">View Document</button><?php else: ?>N/A<?php endif; ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-primary border-bottom border-primary pb-2 mb-3">Contact Information</h6>
                                <table class="table table-borderless">
                                    <tr>
                                        <td width="140"><strong>Address:</strong></td>
                                        <td id="modal-address"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Phone Number:</strong></td>
                                        <td id="modal-phone"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Email:</strong></td>
                                        <td id="modal-email" style="word-break: break-word;"></td>
                                    </tr>
                                </table>
                                
                                <h6 class="text-primary border-bottom border-primary pb-2 mb-3 mt-4">Academic Information</h6>
                                <table class="table table-borderless">
                                    <tr>
                                        <td width="140"><strong>Course:</strong></td>
                                        <td id="modal-course"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Year Level:</strong></td>
                                        <td id="modal-last-year"></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-success" id="approveBtn">
                            <i class="bi bi-check-lg me-1"></i> Approve
                        </button>
                        <button type="button" class="btn btn-danger" id="rejectBtn" data-bs-toggle="modal" data-bs-target="#rejectModal">
                            <i class="bi bi-x-lg me-1"></i> Reject
                        </button>
                        
                        <!-- Hidden forms for submission -->
                        <form method="POST" id="modal-approve-form" class="d-none">
                            <input type="hidden" name="student_id" id="modal-student-id-approve">
                            <input type="hidden" name="action" value="approve">
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Rejection Reason Modal -->
        <div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Rejection Reason</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="POST" id="modal-reject-form">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Please select a reason for rejection:</label>
                                <div class="rejection-reasons">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" name="rejection_type" id="reason1" value="Incomplete or incorrect information provided" checked>
                                        <label class="form-check-label" for="reason1">
                                            Incomplete or incorrect information provided
                                        </label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" name="rejection_type" id="reason2" value="Application does not meet eligibility requirements">
                                        <label class="form-check-label" for="reason2">
                                            Application does not meet eligibility requirements
                                        </label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" name="rejection_type" id="reason3" value="Duplicate application">
                                        <label class="form-check-label" for="reason3">
                                            Duplicate application
                                        </label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" name="rejection_type" id="reason4" value="Provided contact information is invalid or unreachable">
                                        <label class="form-check-label" for="reason4">
                                            Provided contact information is invalid or unreachable
                                        </label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" name="rejection_type" id="reason5" value="other">
                                        <label class="form-check-label" for="reason5">
                                            Other reason
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3 other-reason-container" style="display: none;">
                                <label for="other_reason" class="form-label">Please specify:</label>
                                <textarea class="form-control" id="other_reason" name="other_reason" rows="4"></textarea>
                            </div>
                            
                            <input type="hidden" name="rejection_reason" id="final_rejection_reason">
                            <input type="hidden" name="student_id" id="modal-student-id-reject">
                            <input type="hidden" name="action" value="reject">
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">
                                <i class="bi bi-x-lg me-1"></i> Confirm Rejection
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- View Document Modal -->
        <div class="modal fade" id="viewDocumentModal" tabindex="-1" aria-labelledby="viewDocumentModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="viewDocumentModalLabel">View Document</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body text-center" id="documentPreviewContainer">
                        <!-- Document preview will be injected here -->
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>
    <script>
        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                alert.classList.add('fade-in');
                setTimeout(function() {
                    alert.classList.remove('show');
                    setTimeout(function() {
                        alert.remove();
                    }, 150);
                }, 5000);
            });
            
            // Handle approve button in modali
            document.getElementById('approveBtn').addEventListener('click', function() {
                if (confirm('Are you sure you want to approve this application?')) {
                    document.getElementById('modal-approve-form').submit();
                }
            });
            
            // Setup for rejection modal
            const applicationModal = document.getElementById('applicationModal');
            const rejectModal = document.getElementById('rejectModal');
            const rejectBtn = document.getElementById('rejectBtn');
            const rejectForm = document.getElementById('modal-reject-form');
            const otherReasonContainer = document.querySelector('.other-reason-container');
            const otherReasonInput = document.getElementById('other_reason');
            const finalReasonInput = document.getElementById('final_rejection_reason');
            
            // Toggle 'Other reason' text area visibility based on radio selection
            document.querySelectorAll('input[name="rejection_type"]').forEach(function(radio) {
                radio.addEventListener('change', function() {
                    if (this.value === 'other') {
                        otherReasonContainer.style.display = 'block';
                        otherReasonInput.setAttribute('required', 'required');
                    } else {
                        otherReasonContainer.style.display = 'none';
                        otherReasonInput.removeAttribute('required');
                    }
                });
            });
            
            // When form is submitted, set the final reason
            rejectForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const selectedType = document.querySelector('input[name="rejection_type"]:checked').value;
                
                if (selectedType === 'other') {
                    if (!otherReasonInput.value.trim()) {
                        alert('Please specify the other reason for rejection.');
                        return;
                    }
                    finalReasonInput.value = otherReasonInput.value.trim();
                } else {
                    finalReasonInput.value = selectedType;
                }
                
                this.submit();
            });
            
            // When application modal is hidden, we need to reset reject modal
            applicationModal.addEventListener('hidden.bs.modal', function () {
                // Reset radio to first option
                document.getElementById('reason1').checked = true;
                // Hide and clear other reason
                otherReasonContainer.style.display = 'none';
                otherReasonInput.value = '';
            });
            
            // When reject button is clicked, transfer the student ID
            rejectBtn.addEventListener('click', function() {
                studentId = document.getElementById('modal-student-id-approve').value;
                document.getElementById('modal-student-id-reject').value = studentId;
            });
            
            // Handle view application button clicks
            document.querySelectorAll('.view-application').forEach(function(button) {
                button.addEventListener('click', function() {
                    // Add a small delay for smooth animation
                    setTimeout(() => {
                        // Set modal values from data attributes
                        document.getElementById('modal-name').textContent = this.getAttribute('data-name');
                        document.getElementById('modal-sex').textContent = this.getAttribute('data-sex');
                        document.getElementById('modal-civil-status').textContent = this.getAttribute('data-civil-status');
                        document.getElementById('modal-birthdate').textContent = this.getAttribute('data-birthdate');
                        document.getElementById('modal-place-of-birth').textContent = this.getAttribute('data-place-of-birth');
                        document.getElementById('modal-citizenship').textContent = this.getAttribute('data-citizenship');
                        document.getElementById('modal-address').textContent = this.getAttribute('data-address');
                        document.getElementById('modal-phone').textContent = this.getAttribute('data-phone');
                        document.getElementById('modal-email').textContent = this.getAttribute('data-email');
                        document.getElementById('modal-course').textContent = this.getAttribute('data-course');
                        document.getElementById('modal-last-year').textContent = this.getAttribute('data-last-year');
                        
                        // Set form values
                        const studentId = this.getAttribute('data-id');
                        document.getElementById('modal-student-id-approve').value = studentId;
                    }, 100);
                });
            });
            
            // Add row animation on page load
            const tableRows = document.querySelectorAll('tbody tr');
            tableRows.forEach(function(row, index) {
                row.classList.add('slide-in');
                row.style.animationDelay = (index * 0.05) + 's';
            });

            // Handle document preview button clicks
            const viewDocBtns = document.querySelectorAll('.view-doc-btn');
            const previewContainer = document.getElementById('documentPreviewContainer');
            const viewDocumentModal = document.getElementById('viewDocumentModal');

            viewDocBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const url = this.getAttribute('data-doc-url');
                    let ext = url.split('.').pop().toLowerCase();
                    let html = '';
                    if(['jpg','jpeg','png'].includes(ext)) {
                        html = `<img src="${url}" alt="Document" class="img-fluid">`;
                    } else if(ext === 'pdf') {
                        html = `<iframe src="${url}" width="100%" height="600px" style="border:none;"></iframe>`;
                    } else {
                        html = `<a href="${url}" target="_blank" class="btn btn-primary">Download Document</a>`;
                    }
                    previewContainer.innerHTML = html;
                });
            });

            // Clear preview on modal close
            viewDocumentModal.addEventListener('hidden.bs.modal', function () {
                previewContainer.innerHTML = '';
                // Reopen the application modal if it was open before
                const appModal = document.getElementById('applicationModal');
                if (appModal && appModal.classList.contains('show')) {
                    // Already open, do nothing
                } else {
                    // Reopen the application modal
                    const modal = new bootstrap.Modal(appModal);
                    modal.show();
                }
            });
        });
    </script>
</body>
</html> 