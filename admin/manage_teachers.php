<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once '../config/database.php';
require_once '../config.php';
require_once '../helpers/EmailVerification.php';
require_once '../helpers/BackupHooks.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// Get admin info
$admin_id = $_SESSION['user_id'];
$admin_query = "SELECT * FROM teachers WHERE id = ?";
$stmt = $pdo->prepare($admin_query);
$stmt->execute([$admin_id]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

// Pagination setup
$perPage = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $perPage;
$totalStmt = $pdo->query("SELECT COUNT(*) FROM teachers");
$totalTeachers = $totalStmt->fetchColumn();
$totalPages = ceil($totalTeachers / $perPage);

// Update the query to handle search and exclude deleted teachers
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$whereClause = 'WHERE is_deleted = 0';
$params = [];

if (!empty($search)) {
    $whereClause .= " AND (full_name LIKE :search OR teacher_id LIKE :search)";
    $params[':search'] = "%$search%";
}

// Get paginated teachers with search
$query = "SELECT * FROM teachers $whereClause ORDER BY SUBSTRING_INDEX(full_name, ' ', -1) ASC, full_name ASC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($query);

// Bind all parameters
if (!empty($params)) {
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Update total count for pagination with search
$countQuery = "SELECT COUNT(*) FROM teachers $whereClause";
$totalStmt = $pdo->prepare($countQuery);
if (!empty($params)) {
    foreach ($params as $key => $value) {
        $totalStmt->bindValue($key, $value, PDO::PARAM_STR);
    }
}
$totalStmt->execute();
$totalTeachers = $totalStmt->fetchColumn();
$totalPages = ceil($totalTeachers / $perPage);

// Handle POST requests for CRUD actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'add':
                $firstName = ucwords(strtolower(trim($_POST['first_name'])));
                $middleName = $_POST['middle_name'] ? ucwords(strtolower(trim($_POST['middle_name']))) : '';
                $lastName = ucwords(strtolower(trim($_POST['last_name'])));
                $suffixName = trim($_POST['suffix_name']);
                $sex = trim($_POST['sex']);
                $civilStatus = trim($_POST['civil_status']);
                $birthDate = trim($_POST['birth_date']);
                $phoneNumber = trim($_POST['phone_number']);
                $course = trim($_POST['course'] ?? '');
                if (empty($course)) {
                    $course = 'BSIT';
                }
                $email = strtolower(trim($_POST['email']));

                $errors = [];

                // Validate required fields
                if (empty($firstName)) $errors[] = "First name is required";
                if (empty($lastName)) $errors[] = "Last name is required";
                if (empty($sex)) $errors[] = "Sex is required";
                if (empty($civilStatus)) $errors[] = "Civil status is required";
                if (empty($birthDate)) $errors[] = "Birth date is required";
                if (empty($phoneNumber)) $errors[] = "Phone number is required";
                if (empty($email)) $errors[] = "Email is required";

                // Validate name formats
                if (!empty($firstName) && !preg_match("/^[A-Za-z]+([\s\-'][A-Za-z]+)*$/", $firstName)) {
                    $errors[] = "First name must only contain letters, single spaces, hyphens, and apostrophes";
                }
                if (!empty($middleName) && !preg_match("/^[A-Za-z]+([\s\-'][A-Za-z]+)*$/", $middleName)) {
                    $errors[] = "Middle name must only contain letters, single spaces, hyphens, and apostrophes";
                }
                if (!empty($lastName) && !preg_match("/^[A-Za-z]+([\s\-'][A-Za-z]+)*$/", $lastName)) {
                    $errors[] = "Last name must only contain letters, single spaces, hyphens, and apostrophes";
                }

                // Validate email format and domain
                if (!empty($email)) {
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = "Invalid email format";
                    } elseif (!preg_match('/^[^@\s]+@gmail\.com$/i', $email)) {
                        $errors[] = "Only Gmail addresses (@gmail.com) are accepted";
                    }
                }

                // Validate phone number format
                if (!empty($phoneNumber) && !preg_match('/^09[0-9]{9}$/', $phoneNumber)) {
                    $errors[] = "Phone number must be exactly 11 digits and start with 09 (e.g., 09XXXXXXXXX)";
                }

                // Validate birth date (age 23-80)
                if (!empty($birthDate)) {
                    $birth_timestamp = strtotime($birthDate);
                    $today = strtotime(date('Y-m-d'));
                    $age = floor(($today - $birth_timestamp) / (365.25 * 24 * 60 * 60));
                    if ($age < 23 || $age > 80) {
                        $errors[] = "You must be at least 23 years old and not older than 80 years old";
                    }
                }

                // Check if email already exists
                if (empty($errors)) {
                    $stmt = $pdo->prepare("SELECT id FROM teachers WHERE email = ?");
                    $stmt->execute([$email]);
                    if ($stmt->rowCount() > 0) {
                        $errors[] = "Email already exists";
                    }
                }

                if (empty($errors)) {
                    try {
                        $username = $email; // Use email as username
                        $password = $birthDate; // Use birthdate as password
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                        // Insert teacher with only full_name
                        $fullName = $firstName . ' ' . ($middleName ? $middleName . ' ' : '') . $lastName . ($suffixName ? ' ' . $suffixName : '');
                        $stmt = $pdo->prepare("INSERT INTO teachers (full_name, sex, civil_status, birth_date, phone_number, course, email, password, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        $stmt->execute([$fullName, $sex, $civilStatus, $birthDate, $phoneNumber, $course, $email, $hashedPassword]);
                        $newTeacherId = $pdo->lastInsertId();

                        // Generate Teacher ID (T + zero-padded id)
                        $teacherIdFormatted = 'T' . str_pad($newTeacherId, 4, '0', STR_PAD_LEFT);
                        $stmt = $pdo->prepare("UPDATE teachers SET teacher_id = ? WHERE id = ?");
                        $stmt->execute([$teacherIdFormatted, $newTeacherId]);
                        
                        // Backup to Firebase
                        try {
                            $backupHooks = new BackupHooks();
                            $teacherData = [
                                'id' => $newTeacherId,
                                'teacher_id' => $teacherIdFormatted,
                                'full_name' => $fullName,
                                'sex' => $sex,
                                'civil_status' => $civilStatus,
                                'birth_date' => $birthDate,
                                'phone_number' => $phoneNumber,
                                'course' => $course,
                                'email' => $email,
                                'created_at' => date('Y-m-d H:i:s')
                            ];
                            $backupHooks->backupTeacherCreation($teacherData);
                        } catch (Exception $e) {
                            // Log backup error but don't fail teacher creation
                            error_log("Firebase backup failed for teacher creation: " . $e->getMessage());
                        }

                        // Determine salutation
                        $salutation = ($sex === 'Female') ? 'Ms.' : 'Mr.';
                        $displayName = $fullName;

                        // Send email with credentials using PHPMailer
                        $emailHelper = new EmailVerification();
                        $email_subject = "Your Teacher Account Created";
                        $email_body = "Hello $salutation $displayName,\n\nYour teacher account has been created. Here are your login credentials:\n\nTeacher ID: $teacherIdFormatted\nPassword: $birthDate\n\nFor your security, please use the Account Recovery feature on the login page to change your auto-generated password or default password.\n\nBest regards,\niAttendance Team";
                        $emailSent = $emailHelper->sendEmail($email, $email_subject, $email_body);
                        if ($emailSent) {
                            $_SESSION['success_message'] = "Teacher added successfully. Credentials sent to email.";
                        } else {
                            $_SESSION['success_message'] = "Teacher added successfully. <span class='text-danger'>Failed to send email.</span>";
                        }
                    } catch (PDOException $e) {
                        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
                        error_log("Teacher add error: " . $e->getMessage());
                    }
                } else {
                    $_SESSION['error_message'] = implode("<br>", $errors);
                }
                break;

            case 'edit':
                $teacherId = $_POST['teacher_id'];
                $firstName = ucwords(strtolower(trim($_POST['first_name'])));
                $middleName = $_POST['middle_name'] ? ucwords(strtolower(trim($_POST['middle_name']))) : '';
                $lastName = ucwords(strtolower(trim($_POST['last_name'])));
                $suffixName = trim($_POST['suffix_name']);
                $sex = trim($_POST['sex']);
                $civilStatus = trim($_POST['civil_status']);
                $birthDate = trim($_POST['birth_date']);
                $phoneNumber = trim($_POST['phone_number']);
                $email = trim($_POST['email']);

                if (empty($firstName) || empty($lastName) || empty($sex) || empty($civilStatus) || empty($birthDate) || empty($phoneNumber) || empty($email)) {
                    $_SESSION['error_message'] = "All fields are required.";
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $_SESSION['error_message'] = "Invalid Email format.";
                } else {
                    // Check if email exists (excluding current teacher)
                    $stmt = $pdo->prepare("SELECT id FROM teachers WHERE email = ? AND id != ?");
                    $stmt->execute([$email, $teacherId]);
                    if ($stmt->fetch()) {
                        $_SESSION['error_message'] = "Email already exists for another teacher.";
                    } else {
                        // Combine names for full_name
                        $fullName = $firstName;
                        if ($middleName) $fullName .= ' ' . $middleName;
                        $fullName .= ' ' . $lastName;
                        if ($suffixName) $fullName .= ' ' . $suffixName;
                        $params = [
                            $fullName, $sex, $civilStatus, $birthDate, $phoneNumber, $email, $teacherId
                        ];
                        $sql = "UPDATE teachers SET 
                            full_name = ?, 
                            sex = ?, 
                            civil_status = ?, 
                            birth_date = ?, 
                            phone_number = ?, 
                            email = ? 
                            WHERE id = ?";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                        $_SESSION['success_message'] = "Teacher updated successfully.";
                    }
                }
                break;

            case 'delete_teacher':
                $teacher_id = $_POST['teacher_id'];
                try {
                    // Check if teacher has any active classes
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE teacher_id = ? AND status = 'active'");
                    $stmt->execute([$teacher_id]);
                    $activeClasses = $stmt->fetchColumn();

                    if ($activeClasses > 0) {
                        $_SESSION['error_message'] = "Cannot delete teacher: They have active classes assigned.";
                    } else {
                        // Use soft delete instead of hard delete
                        if (softDelete('teachers', $teacher_id)) {
                            $_SESSION['success_message'] = "Teacher has been moved to archive.";
                        } else {
                            $_SESSION['error_message'] = "Error archiving teacher. Please try again.";
                            error_log("Failed to soft delete teacher ID: " . $teacher_id);
                        }
                    }
                } catch(PDOException $e) {
                    $_SESSION['error_message'] = "Error: " . $e->getMessage();
                    error_log("Error in delete_teacher: " . $e->getMessage());
                }
                header("Location: manage_teachers.php");
                exit();
                break;
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Database error: " . $e->getMessage();
        error_log("Teacher CRUD error: " . $e->getMessage());
    }
    
    header("Location: manage_teachers.php");
    exit();
}

// AJAX endpoint for finding the page of the first matching teacher
if (isset($_GET['ajax']) && $_GET['ajax'] === 'find_page' && !empty($_GET['search'])) {
    $search = trim($_GET['search']);
    $whereClause = "WHERE (full_name LIKE :search OR teacher_id LIKE :search)";
    $params = [':search' => "%$search%"];
    $query = "SELECT COUNT(*) as row_num FROM teachers $whereClause ORDER BY created_at DESC";
    $stmt = $pdo->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->execute();
    $rowNum = $stmt->fetchColumn();
    $page = ($rowNum > 0) ? 1 : 1; // Always show first page with results
    echo json_encode(['page' => $page]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Teachers - iAttendance</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <style>
        .table-actions .btn { margin: 0 2px; }
        .modal-body label { margin-top: 10px; }
        .pagination {
            --bs-pagination-padding-x: 1.2rem;
            --bs-pagination-padding-y: 0.6rem;
            --bs-pagination-font-size: 1.1rem;
            --bs-pagination-color: #6c757d;
            --bs-pagination-bg: #fff;
            --bs-pagination-border-radius: 12px;
            --bs-pagination-border-width: 0;
            --bs-pagination-box-shadow: 0 2px 8px rgba(44,62,80,0.08);
            box-shadow: var(--bs-pagination-box-shadow);
            gap: 0.5rem;
        }
        .pagination .page-item .page-link {
            border-radius: 12px !important;
            margin: 0 2px;
            color: #6c757d;
            font-weight: 600;
            border: none;
            background: #f8fafc;
            transition: background 0.2s, color 0.2s;
            box-shadow: none;
        }
        .pagination .page-item.active .page-link {
            background: #e9ecef;
            color: #6c757d;
            border: none;
            box-shadow: 0 2px 8px rgba(44,62,80,0.10);
        }
        .pagination .page-link:hover {
            background: #e0e7ef;
            color: #495057;
        }
        .pagination .page-item.disabled .page-link {
            color: #b0b8c9;
            background: #f4f6fb;
        }
        .required-field::after {
            content: "*";
            color: #dc3545;
            margin-left: 4px;
        }
        
        /* Hide asterisk when field is filled */
        .form-control:not(:placeholder-shown) + .required-field::after,
        .form-select:not([value=""]) + .required-field::after {
            opacity: 0;
        }

        /* Style for invalid fields */
        .form-control.is-invalid,
        .form-select.is-invalid {
            border-color: #dc3545 !important;
            padding-right: calc(1.5em + 0.75rem);
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right calc(0.375em + 0.1875rem) center;
            background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
        }

        .invalid-feedback {
            display: none;
            width: 100%;
            margin-top: 0.25rem;
            font-size: 0.875em;
            color: #dc3545;
        }

        .is-invalid ~ .invalid-feedback {
            display: block;
        }

        #addSex, #addCivilStatus {
            color:rgb(84, 91, 102);
            font-weight: 600;
        }

        #addSex option[value=""], #addCivilStatus option[value=""] {
            color: #b0b0b0;
            font-weight: 400;
        }

        .suffix-select-truncate {
            color:rgb(70, 76, 87);
            font-weight: 600;
            text-overflow: ellipsis;
            white-space: nowrap;
            overflow: hidden;
            max-width: 180px;
            display: block;
        }

        .suffix-select-truncate option[value=""] {
            color: #b0b0b0;
            font-weight: 400;
        }

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

        .input-group .form-control:focus + .input-group-text {
            border-color: #dee2e6;
        }

        #searchInput {
            padding-left: 0;
        }

        #searchInput::placeholder {
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
                    <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i> Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="../auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>

        <div class="container-fluid">
            <div class="row mb-3">
                <div class="col">
                    <h1 class="h3 mb-0 text-gray-800">Manage Teachers</h1>
                </div>
                <div class="col text-end d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTeacherModal">
                        <i class="bi bi-plus-circle me-2"></i>Add Teacher
                    </button>
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
             <?php if (isset($_SESSION['info_message'])): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($_SESSION['info_message']); unset($_SESSION['info_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Replace the search card with this more compact version -->
            <div class="card shadow mb-4">
                <div class="card-body py-2">
                    <div class="d-flex align-items-center">
                        <div class="input-group" style="max-width: 300px;">
                            <span class="input-group-text bg-white border-end-0">
                                <i class="bi bi-search text-muted"></i>
                            </span>
                            <input type="text" class="form-control border-start-0" id="searchInput" placeholder="Search teachers..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Teachers Table -->
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">Teacher List</h6>
                    <!-- Wrap the pagination in a div with id 'paginationWrapper' -->
                    <div id="paginationWrapper">
                    <?php if ($totalPages > 1): ?>
                    <div>
                      <nav aria-label="Teachers pagination">
                        <ul class="pagination mb-0">
                          <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>">
                                    <a class="page-link" href="?page=<?= $page - 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" aria-label="Previous">
                              <span aria-hidden="true">&laquo;</span>
                            </a>
                          </li>
                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);
                                
                                if ($startPage > 1) {
                                    echo '<li class="page-item"><a class="page-link" href="?page=1' . (!empty($search) ? '&search=' . urlencode($search) : '') . '">1</a></li>';
                                    if ($startPage > 2) {
                                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                    }
                                }
                                
                                for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <li class="page-item<?= $i == $page ? ' active' : '' ?>">
                                        <a class="page-link" href="?page=<?= $i ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"><?= $i ?></a>
                            </li>
                                <?php endfor;
                                
                                if ($endPage < $totalPages) {
                                    if ($endPage < $totalPages - 1) {
                                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                    }
                                    echo '<li class="page-item"><a class="page-link" href="?page=' . $totalPages . (!empty($search) ? '&search=' . urlencode($search) : '') . '">' . $totalPages . '</a></li>';
                                }
                                ?>
                          <li class="page-item<?= $page >= $totalPages ? ' disabled' : '' ?>">
                                    <a class="page-link" href="?page=<?= $page + 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" aria-label="Next">
                              <span aria-hidden="true">&raquo;</span>
                            </a>
                          </li>
                        </ul>
                      </nav>
                    </div>
                    <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="teachersTable" width="100%" cellspacing="0">
                            <thead>
                                <tr>
                                    <th>Teacher ID</th>
                                    <th>Full Name</th>
                                    <th>Email</th>
                                    <th>Created At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($teachers)): ?>
                                    <tr><td colspan="5" class="text-center">No teachers found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($teachers as $teacher): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($teacher['teacher_id']); ?></td>
                                            <td>
                                                <?php
                                                // Split full_name into first, middle, last for autofill
                                                $fullName = trim($teacher['full_name']);
                                                $firstName = '';
                                                $middleName = '';
                                                $lastName = '';
                                                $parts = preg_split('/\s+/', $fullName);
                                                if (count($parts) == 1) {
                                                    $firstName = $parts[0];
                                                } elseif (count($parts) == 2) {
                                                    $firstName = $parts[0];
                                                    $lastName = $parts[1];
                                                } elseif (count($parts) > 2) {
                                                    $firstName = $parts[0];
                                                    $lastName = $parts[count($parts) - 1];
                                                    $middleName = implode(' ', array_slice($parts, 1, -1));
                                                }
                                                $suffixName = trim($teacher['suffix_name'] ?? '');
                                                $sex = trim($teacher['sex'] ?? '');
                                                $civilStatus = trim($teacher['civil_status'] ?? '');
                                                $birthDate = trim($teacher['birth_date'] ?? '');
                                                $phoneNumber = trim($teacher['phone_number'] ?? '');
                                                $email = trim($teacher['email'] ?? '');
                                                $createdAt = trim($teacher['created_at'] ?? '');

                                                if ($lastName && $firstName) {
                                                    $middleInitial = '';
                                                    $middleDisplay = '';
                                                    if ($middleName) {
                                                        $middleParts = preg_split('/\s+/', $middleName);
                                                        if (count($middleParts) > 1) {
                                                            // All but last part as middle name(s)
                                                            $middleDisplay = implode(' ', array_slice($middleParts, 0, -1));
                                                            // Last part as middle initial
                                                            $middleInitial = strtoupper($middleParts[count($middleParts)-1][0]) . '.';
                                                        } else {
                                                            $middleInitial = strtoupper($middleParts[0][0]) . '.';
                                                        }
                                                    }
                                                    $displayName = $lastName . ', ' . $firstName . ($middleDisplay ? ' ' . $middleDisplay : '') . ($middleInitial ? ' ' . $middleInitial : '');
                                                } elseif (!empty($teacher['full_name'])) {
                                                    // Fallback: try to split full_name
                                                    $parts = preg_split('/\s+/', trim($teacher['full_name']));
                                                    if (count($parts) >= 3) {
                                                        $first = $parts[0];
                                                        $last = $parts[count($parts) - 1];
                                                        $middleParts = array_slice($parts, 1, -1);
                                                        $middleDisplay = '';
                                                        $middleInitial = '';
                                                        if (count($middleParts) > 1) {
                                                            $middleDisplay = implode(' ', array_slice($middleParts, 0, -1));
                                                            $middleInitial = strtoupper($middleParts[count($middleParts)-1][0]) . '.';
                                                        } elseif (count($middleParts) == 1) {
                                                            $middleInitial = strtoupper($middleParts[0][0]) . '.';
                                                        }
                                                        $displayName = $last . ', ' . $first . ($middleDisplay ? ' ' . $middleDisplay : '') . ($middleInitial ? ' ' . $middleInitial : '');
                                                    } elseif (count($parts) == 2) {
                                                        $displayName = $parts[1] . ', ' . $parts[0];
                                                    } else {
                                                        $displayName = $teacher['full_name'];
                                                    }
                                                } else {
                                                    $displayName = '-';
                                                }
                                                echo htmlspecialchars($displayName);
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($teacher['email']); ?></td>
                                            <td><?php echo date("M d, Y h:i A", strtotime($teacher['created_at'])); ?></td>
                                            <td class="text-end">
                                                <div class="d-flex gap-2 justify-content-end">
                                                    <a href="#" class="text-info" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#viewTeacherModal"
                                                        data-id="<?php echo $teacher['id']; ?>"
                                                        data-first_name="<?php echo htmlspecialchars($firstName); ?>"
                                                        data-middle_name="<?php echo htmlspecialchars($middleName); ?>"
                                                        data-last_name="<?php echo htmlspecialchars($lastName); ?>"
                                                        data-suffix_name="<?php echo htmlspecialchars($suffixName); ?>"
                                                        data-sex="<?php echo htmlspecialchars($sex); ?>"
                                                        data-civil_status="<?php echo htmlspecialchars($civilStatus); ?>"
                                                        data-birth_date="<?php echo htmlspecialchars($birthDate); ?>"
                                                        data-phone_number="<?php echo htmlspecialchars($phoneNumber); ?>"
                                                        data-email="<?php echo htmlspecialchars($email); ?>"
                                                        data-created_at="<?php echo htmlspecialchars($createdAt); ?>"
                                                        data-name="<?php echo htmlspecialchars($teacher['full_name']); ?>"
                                                        title="View Details">
                                                        <i class="bi bi-eye fs-5"></i>
                                                    </a>
                                                    <a href="#" class="text-warning" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editTeacherModal"
                                                            data-id="<?php echo $teacher['id']; ?>"
                                                        data-first_name="<?php echo htmlspecialchars($firstName); ?>"
                                                        data-middle_name="<?php echo htmlspecialchars($middleName); ?>"
                                                        data-last_name="<?php echo htmlspecialchars($lastName); ?>"
                                                        data-suffix_name="<?php echo htmlspecialchars($suffixName); ?>"
                                                        data-sex="<?php echo htmlspecialchars($sex); ?>"
                                                        data-civil_status="<?php echo htmlspecialchars($civilStatus); ?>"
                                                        data-birth_date="<?php echo htmlspecialchars($birthDate); ?>"
                                                        data-phone_number="<?php echo htmlspecialchars($phoneNumber); ?>"
                                                        data-email="<?php echo htmlspecialchars($email); ?>"
                                                        title="Edit Teacher">
                                                        <i class="bi bi-pencil-square fs-5"></i>
                                                    </a>
                                                    <a href="#" class="text-danger"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#deleteTeacherModal"
                                                            data-id="<?php echo $teacher['id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($teacher['full_name']); ?>"
                                                            title="Delete Teacher">
                                                        <i class="bi bi-trash fs-5"></i>
                                                    </a>
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
        </div>

        <!-- Add Teacher Modal -->
        <div class="modal fade" id="addTeacherModal" tabindex="-1" aria-labelledby="addTeacherModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form action="manage_teachers.php" method="POST">
                        <input type="hidden" name="action" value="add">
                        <div class="modal-header">
                            <h5 class="modal-title" id="addTeacherModalLabel">Add New Teacher</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="addFirstName" class="form-label required-field">First Name</label>
                                    <input type="text" class="form-control" id="addFirstName" name="first_name" required>
                                    <div class="invalid-feedback" id="addFirstName-error">First name must only contain letters and spaces.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="addMiddleName" class="form-label">Middle Name</label>
                                    <input type="text" class="form-control" id="addMiddleName" name="middle_name">
                                    <div class="invalid-feedback" id="addMiddleName-error">Middle name must only contain letters, single spaces, hyphens, and apostrophes.</div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="addLastName" class="form-label required-field">Last Name</label>
                                    <input type="text" class="form-control" id="addLastName" name="last_name" required>
                                    <div class="invalid-feedback" id="addLastName-error">Last name must only contain letters, single spaces, hyphens, and apostrophes.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="addSuffixName" class="form-label">Suffix Name</label>
                                    <select class="form-select suffix-select-truncate" id="addSuffixName" name="suffix_name">
                                        <option value="">Select suffix</option>
                                        <option value="Sr">Sr</option>
                                        <option value="Jr">Jr</option>
                                        <option value="I">I</option>
                                        <option value="II">II</option>
                                        <option value="III">III</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="addSex" class="form-label required-field">Sex</label>
                                    <select class="form-select" id="addSex" name="sex" required>
                                        <option value="">Select Sex</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                    </select>
                                    <div class="invalid-feedback" id="addSex-error">Sex is required.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="addCivilStatus" class="form-label required-field">Civil Status</label>
                                    <select class="form-select" id="addCivilStatus" name="civil_status" required>
                                        <option value="">Select Status</option>
                                        <option value="Single">Single</option>
                                        <option value="Married">Married</option>
                                        <option value="Widowed">Widowed</option>
                                        <option value="Separated">Separated</option>
                                    </select>
                                    <div class="invalid-feedback" id="addCivilStatus-error">Civil status is required.</div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="addBirthDate" class="form-label required-field">Birth Date</label>
                                    <input type="date" class="form-control" id="addBirthDate" name="birth_date" required min="<?php echo date('Y-m-d', strtotime('-80 years')); ?>" max="<?php echo date('Y-m-d', strtotime('-23 years')); ?>" value="">
                                    <div class="invalid-feedback" id="addBirthDate-error">You must be at least 23 years old and not older than 80 years old.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="addPhoneNumber" class="form-label required-field">Phone Number</label>
                                    <input type="tel" class="form-control" id="addPhoneNumber" name="phone_number" required placeholder="09XXXXXXXXX" maxlength="11">
                                    <div class="invalid-feedback" id="addPhoneNumber-error">Phone number must be exactly 11 digits and start with 09 (e.g., 09XXXXXXXXX).</div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="addEmail" class="form-label required-field">Gmail Address</label>
                                <input type="email" class="form-control" id="addEmail" name="email" required placeholder="example@gmail.com">
                                <div class="invalid-feedback" id="addEmail-error">Only Gmail addresses (@gmail.com) are accepted.</div>
                            </div>
                            <div class="alert alert-info" id="credentialsInfo" style="display:none;"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary">Add Teacher</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Edit Teacher Modal -->
        <div class="modal fade" id="editTeacherModal" tabindex="-1" aria-labelledby="editTeacherModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form action="manage_teachers.php" method="POST">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" id="editTeacherId" name="teacher_id">
                        <div class="modal-header">
                            <h5 class="modal-title" id="editTeacherModalLabel">Edit Teacher</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="editFirstName" class="form-label">First Name</label>
                                    <input type="text" class="form-control" id="editFirstName" name="first_name" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="editMiddleName" class="form-label">Middle Name</label>
                                    <input type="text" class="form-control" id="editMiddleName" name="middle_name">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="editLastName" class="form-label">Last Name</label>
                                    <input type="text" class="form-control" id="editLastName" name="last_name" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="editSuffixName" class="form-label">Suffix Name</label>
                                    <select class="form-select suffix-select-truncate" id="editSuffixName" name="suffix_name">
                                        <option value="">Select suffix</option>
                                        <option value="Sr">Sr</option>
                                        <option value="Jr">Jr</option>
                                        <option value="I">I</option>
                                        <option value="II">II</option>
                                        <option value="III">III</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="editSex" class="form-label">Sex</label>
                                    <select class="form-select" id="editSex" name="sex" required>
                                        <option value="">Select Sex</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="editCivilStatus" class="form-label">Civil Status</label>
                                    <select class="form-select" id="editCivilStatus" name="civil_status" required>
                                        <option value="">Select Status</option>
                                        <option value="Single">Single</option>
                                        <option value="Married">Married</option>
                                        <option value="Widowed">Widowed</option>
                                        <option value="Separated">Separated</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="editBirthDate" class="form-label">Birth Date</label>
                                    <input type="date" class="form-control" id="editBirthDate" name="birth_date" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="editPhoneNumber" class="form-label">Phone Number</label>
                                    <input type="text" class="form-control" id="editPhoneNumber" name="phone_number" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="editEmail" class="form-label">Email address</label>
                                <input type="email" class="form-control" id="editEmail" name="email" required>
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
        
        <!-- Delete Teacher Modal -->
        <div class="modal fade" id="deleteTeacherModal" tabindex="-1" aria-labelledby="deleteTeacherModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form action="manage_teachers.php" method="POST">
                        <input type="hidden" name="action" value="delete_teacher">
                        <input type="hidden" id="deleteTeacherId" name="teacher_id">
                        <div class="modal-header">
                            <h5 class="modal-title" id="deleteTeacherModalLabel">Confirm Deletion</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            Are you sure you want to delete the teacher <strong id="deleteTeacherName"></strong>? This action cannot be undone.
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">Delete Teacher</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- View Teacher Modal -->
        <div class="modal fade" id="viewTeacherModal" tabindex="-1" aria-labelledby="viewTeacherModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header border-0">
                        <h5 class="modal-title" id="viewTeacherModalLabel">Teacher Details</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row mb-3">
                                <div class="col-md-6">
                                <h6 class="text-primary border-bottom border-primary pb-2 mb-3">Personal Information</h6>
                                <table class="table table-borderless align-middle">
                                    <tr>
                                        <td class="align-middle" width="140"><strong>Name:</strong></td>
                                        <td class="align-middle" id="modal-t-name"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Sex:</strong></td>
                                        <td id="modal-t-sex"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Civil Status:</strong></td>
                                        <td id="modal-t-civil-status"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Birthdate:</strong></td>
                                        <td id="modal-t-birthdate"></td>
                                    </tr>
                                </table>
                                </div>
                                <div class="col-md-6">
                                <h6 class="text-primary border-bottom border-primary pb-2 mb-3">Contact Information</h6>
                                <table class="table table-borderless align-middle">
                                    <tr>
                                        <td class="align-middle" width="140"><strong>Phone Number:</strong></td>
                                        <td class="align-middle" id="modal-t-phone"></td>
                                    </tr>
                                    <tr>
                                        <td><strong>Email:</strong></td>
                                        <td id="modal-t-email" style="word-break: break-word;"></td>
                                    </tr>
                                </table>
                                <h6 class="text-primary border-bottom border-primary pb-2 mb-3 mt-4">Academic Information</h6>
                                <table class="table table-borderless align-middle">
                                    <tr>
                                        <td><strong>Date Created:</strong></td>
                                        <td id="modal-t-created"></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <!-- <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button> -->
                    </div>
                </div>
            </div>
        </div>

    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Script to handle modal data population for Edit and Delete
        const editTeacherModal = document.getElementById('editTeacherModal');
        editTeacherModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const teacherId = button.getAttribute('data-id');
            const firstName = button.getAttribute('data-first_name') || '';
            const middleName = button.getAttribute('data-middle_name') || '';
            const lastName = button.getAttribute('data-last_name') || '';
            const suffixName = button.getAttribute('data-suffix_name') || '';
            const sex = button.getAttribute('data-sex') || '';
            const civilStatus = button.getAttribute('data-civil_status') || '';
            const birthDate = button.getAttribute('data-birth_date') || '';
            const phoneNumber = button.getAttribute('data-phone_number') || '';
            const email = button.getAttribute('data-email') || '';

            editTeacherModal.querySelector('#editTeacherId').value = teacherId;
            editTeacherModal.querySelector('#editFirstName').value = firstName;
            editTeacherModal.querySelector('#editMiddleName').value = middleName;
            editTeacherModal.querySelector('#editLastName').value = lastName;
            editTeacherModal.querySelector('#editSuffixName').value = suffixName;
            editTeacherModal.querySelector('#editSex').value = sex;
            editTeacherModal.querySelector('#editCivilStatus').value = civilStatus;
            editTeacherModal.querySelector('#editBirthDate').value = birthDate;
            editTeacherModal.querySelector('#editPhoneNumber').value = phoneNumber;
            editTeacherModal.querySelector('#editEmail').value = email;

            // Set modal title with full name
            const modalTitle = editTeacherModal.querySelector('.modal-title');
            let fullName = [firstName, middleName, lastName, suffixName].filter(Boolean).join(' ');
            modalTitle.textContent = 'Edit Teacher: ' + (fullName || '');
        });

        // View Teacher Modal population (update to use new IDs)
        const viewTeacherModal = document.getElementById('viewTeacherModal');
        viewTeacherModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const firstName = button.getAttribute('data-first_name') || '';
            const middleName = button.getAttribute('data-middle_name') || '';
            const lastName = button.getAttribute('data-last_name') || '';
            const suffixName = button.getAttribute('data-suffix_name') || '';
            let fullName = [firstName, middleName, lastName, suffixName].filter(Boolean).join(' ');
            if (!fullName.trim()) {
                fullName = button.getAttribute('data-name') || '';
            }
            document.getElementById('modal-t-name').textContent = fullName;
            document.getElementById('modal-t-sex').textContent = button.getAttribute('data-sex') || '';
            document.getElementById('modal-t-civil-status').textContent = button.getAttribute('data-civil_status') || '';
            document.getElementById('modal-t-birthdate').textContent = button.getAttribute('data-birth_date') || '';
            document.getElementById('modal-t-phone').textContent = button.getAttribute('data-phone_number') || '';
            document.getElementById('modal-t-email').textContent = button.getAttribute('data-email') || '';
            document.getElementById('modal-t-created').textContent = button.getAttribute('data-created_at') || '';
        });

        // Auto-dismiss alerts
        window.setTimeout(function() {
            let alert = document.querySelector('.alert-dismissible');
            if(alert) {
                new bootstrap.Alert(alert).close();
            }
        }, 5000); // Dismiss after 5 seconds

        <?php if (isset($_SESSION['success_message'])): ?>
            document.addEventListener('DOMContentLoaded', function() {
                var msg = <?php echo json_encode($_SESSION['success_message']); ?>;
                if (msg.match(/Username: (\w+), Password: (\w+)/)) {
                    var creds = msg.match(/Username: (\w+), Password: (\w+)/);
                    var info = document.getElementById('credentialsInfo');
                    if (info) {
                        info.style.display = '';
                        info.innerHTML = '<b>Username:</b> ' + creds[1] + '<br><b>Temporary Password:</b> ' + creds[2];
                    }
                }
            });
        <?php endif; ?>

        // Delete Teacher Modal population
        const deleteTeacherModal = document.getElementById('deleteTeacherModal');
        deleteTeacherModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const teacherId = button.getAttribute('data-id');
            const teacherName = button.getAttribute('data-name');
            deleteTeacherModal.querySelector('#deleteTeacherId').value = teacherId;
            deleteTeacherModal.querySelector('#deleteTeacherName').textContent = teacherName;
        });

        document.addEventListener('DOMContentLoaded', function() {
            // Name validation
            function allowOnlyNameChars(e) {
                const char = String.fromCharCode(e.which);
                if (!/^[a-zA-Z\s\-']$/.test(char) && ![8,9,13,37,39,46].includes(e.keyCode)) {
                    e.preventDefault();
                }
            }

            ['addFirstName', 'addMiddleName', 'addLastName'].forEach(function(id) {
                const field = document.getElementById(id);
                field.addEventListener('keypress', allowOnlyNameChars);
                field.addEventListener('input', validateNameField);
                field.addEventListener('blur', validateNameField);
                field.addEventListener('change', validateNameField);
            });
            function validateNameField(e) {
                const field = e.target;
                const regex = /^[A-Za-z]+([\s\-'][A-Za-z]+)*$/;
                if (field.value && !regex.test(field.value)) {
                    field.classList.add('is-invalid');
                } else {
                    field.classList.remove('is-invalid');
                }
            }

            // Birthdate validation
            const birthdateField = document.getElementById('addBirthDate');
            ['input', 'blur', 'change'].forEach(evt => {
                birthdateField.addEventListener(evt, function() {
                    const today = new Date();
                    const birthDate = new Date(this.value);
                    let age = today.getFullYear() - birthDate.getFullYear();
                    const monthDiff = today.getMonth() - birthDate.getMonth();
                    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                        age--;
                    }
                    if (!this.value || age < 23 || age > 80 || birthDate > today) {
                        birthdateField.classList.add('is-invalid');
                        document.getElementById('addBirthDate-error').style.display = 'block';
                    } else {
                        birthdateField.classList.remove('is-invalid');
                        document.getElementById('addBirthDate-error').style.display = 'none';
                    }
                });
            });

            // Phone number validation
            const phoneField = document.getElementById('addPhoneNumber');
            phoneField.addEventListener('keypress', function(e) {
                const char = String.fromCharCode(e.which);
                if (!/[0-9]/.test(char) && ![8,9,13,37,39,46].includes(e.keyCode)) {
                    e.preventDefault();
                }
                if (phoneField.value.length >= 11 && ![8,9,13,37,39,46].includes(e.keyCode)) {
                    e.preventDefault();
                }
            });
            ['input', 'blur', 'change'].forEach(evt => {
                phoneField.addEventListener(evt, function() {
                    phoneField.value = phoneField.value.replace(/[^0-9]/g, '').slice(0, 11);
                    if (phoneField.value.length !== 11 || !/^09[0-9]{9}$/.test(phoneField.value)) {
                        phoneField.classList.add('is-invalid');
                        document.getElementById('addPhoneNumber-error').style.display = 'block';
                    } else {
                        phoneField.classList.remove('is-invalid');
                        document.getElementById('addPhoneNumber-error').style.display = 'none';
                    }
                });
            });

            // Email validation
            const emailField = document.getElementById('addEmail');
            ['input', 'blur', 'change'].forEach(evt => {
                emailField.addEventListener(evt, function() {
                    const email = this.value.toLowerCase();
                    if (!/^[^@\s]+@gmail\.com$/i.test(email)) {
                        emailField.classList.add('is-invalid');
                        document.getElementById('addEmail-error').style.display = 'block';
                    } else {
                        emailField.classList.remove('is-invalid');
                        document.getElementById('addEmail-error').style.display = 'none';
                    }
                });
            });

            // Form submission validation
            const addTeacherForm = document.querySelector('#addTeacherModal form');
            if (addTeacherForm) {
                addTeacherForm.addEventListener('submit', function(e) {
                    let isValid = true;

                    // Validate birth date
                    const today = new Date();
                    const birthDate = new Date(birthdateField.value);
                    let age = today.getFullYear() - birthDate.getFullYear();
                    const monthDiff = today.getMonth() - birthDate.getMonth();
                    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                        age--;
                    }
                    if (!birthdateField.value || age < 23 || age > 80 || birthDate > today) {
                        birthdateField.classList.add('is-invalid');
                        document.getElementById('addBirthDate-error').style.display = 'block';
                        isValid = false;
                    }

                    // Validate phone number
                    if (phoneField.value.length !== 11 || !/^09[0-9]{9}$/.test(phoneField.value)) {
                        phoneField.classList.add('is-invalid');
                        document.getElementById('addPhoneNumber-error').style.display = 'block';
                        isValid = false;
                    }

                    // Validate email
                    const email = emailField.value.toLowerCase();
                    if (!/^[^@\s]+@gmail\.com$/i.test(email)) {
                        emailField.classList.add('is-invalid');
                        document.getElementById('addEmail-error').style.display = 'block';
                        isValid = false;
                    }

                    if (!isValid) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                });
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            let searchTimeout;
            let lastSearchValue = searchInput.value;

            searchInput.addEventListener('input', function() {
                const searchValue = this.value.trim();
                const paginationWrapper = document.getElementById('paginationWrapper');
                if (searchValue) {
                    paginationWrapper.style.display = 'none';
                } else {
                    paginationWrapper.style.display = '';
                }
                if (searchValue !== lastSearchValue) {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        if (searchValue) {
                            // AJAX to find the first page with results
                            fetch(`manage_teachers.php?ajax=find_page&search=${encodeURIComponent(searchValue)}`)
                                .then(response => response.json())
                                .then(data => {
                                    const page = data.page || 1;
                                    const currentUrl = new URL(window.location.href);
                                    currentUrl.searchParams.set('search', searchValue);
                                    currentUrl.searchParams.set('page', page);
                                    fetchAndUpdateTable(currentUrl);
                                });
                        } else {
                            const currentUrl = new URL(window.location.href);
                            currentUrl.searchParams.delete('search');
                            currentUrl.searchParams.set('page', '1');
                            fetchAndUpdateTable(currentUrl);
                        }
                        lastSearchValue = searchValue;
                    }, 300);
                }
            });

            function fetchAndUpdateTable(url) {
                fetch(url.toString())
                    .then(response => response.text())
                    .then(html => {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        // Update the table content
                        const newTable = doc.querySelector('#teachersTable tbody');
                        document.querySelector('#teachersTable tbody').innerHTML = newTable.innerHTML;
                        // Update pagination if it exists
                        const newPagination = doc.querySelector('.pagination');
                        const currentPagination = document.querySelector('.pagination');
                        if (newPagination && currentPagination) {
                            currentPagination.innerHTML = newPagination.innerHTML;
                        }
                        // Update URL without reload
                        window.history.pushState({}, '', url.toString());
                    });
            }
        });
    </script>
</body>
</html> 