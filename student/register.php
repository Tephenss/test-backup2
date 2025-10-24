<?php
session_start();
require_once '../config/database.php';
require_once '../helpers/EmailVerification.php';
require_once '../helpers/BackupHooks.php';

$success_message = '';
$errors = [];
$registration_success = false;
$registered_student_id = '';
$show_success_modal = false;

if (isset($_SESSION['success_message'])) {
    $show_success_modal = true;
    // Extract student ID from the message if possible
    if (preg_match('/Application ID is (\d{4}-\d{3})/', $_SESSION['success_message'], $matches)) {
        $registered_student_id = $matches[1];
    }
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Function to generate student ID
function generateStudentId($pdo) {
    // Get the current year
    $year = date('Y');
    
    // Get the last student ID for the current year
    $stmt = $pdo->prepare("SELECT student_id FROM students WHERE student_id LIKE ? ORDER BY student_id DESC LIMIT 1");
    $stmt->execute([$year . '%']);
    $lastId = $stmt->fetchColumn();
    
    if ($lastId) {
        // Remove dash if present, then extract the sequence number and increment it
        $lastIdNumeric = str_replace('-', '', $lastId);
        $sequence = intval(substr($lastIdNumeric, -3)) + 1;
    } else {
        // If no student ID exists for this year, start with 1
        $sequence = 1;
    }
    // Format: YYYY-NNN (where NNN is a 3-digit sequence number)
    return $year . '-' . str_pad($sequence, 3, '0', STR_PAD_LEFT);
}

// Helper for file upload
function handle_upload($file, $prefix, $upload_dir, $allowed_types, $max_size, &$file_errors) {
    if ($file['error'] === UPLOAD_ERR_OK) {
        if (!in_array($file['type'], $allowed_types)) {
            $file_errors[] = "$prefix: Invalid file type.";
            return '';
        }
        if ($file['size'] > $max_size) {
            $file_errors[] = "$prefix: File too large (max 5MB).";
            return '';
        }
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = $prefix . '_' . time() . '_' . uniqid() . '.' . $ext;
        $target = $upload_dir . $filename;
        if (move_uploaded_file($file['tmp_name'], $target)) {
            return 'uploads/requirements/' . $filename;
        } else {
            $file_errors[] = "$prefix: Failed to upload.";
            return '';
        }
    } else {
        $file_errors[] = "$prefix: File upload error.";
        return '';
    }
}

// Helper to uppercase and trim (for place_of_birth, citizenship, suffix_name)
function sanitize_and_upper($str) {
    return mb_strtoupper(trim($str));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Personal Information
    $first_name = ucwords(strtolower(trim($_POST['first_name'] ?? '')));
    $middle_name = isset($_POST['middle_name']) ? ucwords(strtolower(trim($_POST['middle_name']))) : '';
    $last_name = ucwords(strtolower(trim($_POST['last_name'] ?? '')));
    $suffix_name = isset($_POST['suffix_name']) ? ucwords(strtolower(trim($_POST['suffix_name']))) : '';
    $sex = $_POST['sex'];
    $civil_status = $_POST['civil_status'];
    $birthdate = $_POST['birthdate'];
    $place_of_birth = sanitize_and_upper($_POST['place_of_birth'] ?? '');
    $citizenship = sanitize_and_upper($_POST['citizenship'] ?? '');
    
    // Contact Information
    $address = trim($_POST['address']);
    $phone_number = trim($_POST['phone_number']);
    $email = trim($_POST['email']);

    // Validate inputs
    if (empty($first_name)) $errors[] = "First name is required";
    if (empty($last_name)) $errors[] = "Last name is required";
    if (empty($sex)) $errors[] = "Sex is required";
    if (empty($civil_status)) $errors[] = "Civil status is required";
    if (empty($birthdate)) $errors[] = "Birthdate is required";
    if (empty($place_of_birth)) $errors[] = "Place of birth is required";
    if (empty($citizenship)) $errors[] = "Citizenship is required";
    if (empty($address)) $errors[] = "Address is required";
    if (empty($phone_number)) $errors[] = "Phone number is required";
    if (empty($email)) $errors[] = "Email is required";

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }

    // Check if email already exists
    $stmt = $pdo->prepare("SELECT id FROM students WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->rowCount() > 0) {
        $errors[] = "Email already exists";
    }

    // SERVER-SIDE VALIDATION for names (allow only letters, single spaces, hyphens, apostrophes, and auto-uppercase)
    $first_name = ucwords(strtolower(trim($_POST['first_name'] ?? '')));
    $middle_name = isset($_POST['middle_name']) ? ucwords(strtolower(trim($_POST['middle_name']))) : '';
    $last_name = ucwords(strtolower(trim($_POST['last_name'] ?? '')));
    $suffix_name = isset($_POST['suffix_name']) ? sanitize_and_upper($_POST['suffix_name']) : '';
    $place_of_birth = sanitize_and_upper($_POST['place_of_birth'] ?? '');
    $citizenship = sanitize_and_upper($_POST['citizenship'] ?? '');

    if (!empty($first_name) && !preg_match("/^[A-Za-z]+([\s\-'][A-Za-z]+)*$/", $first_name)) {
        $errors[] = "First name must only contain letters, single spaces, hyphens, and apostrophes (no numbers or special characters, and no leading/trailing/double spaces, hyphens, or apostrophes).";
    }
    if (!empty($middle_name) && !preg_match("/^[A-Za-z]+([\s\-'][A-Za-z]+)*$/", $middle_name)) {
        $errors[] = "Middle name must only contain letters, single spaces, hyphens, and apostrophes (no numbers or special characters, and no leading/trailing/double spaces, hyphens, or apostrophes).";
    }
    if (!empty($last_name) && !preg_match("/^[A-Za-z]+([\s\-'][A-Za-z]+)*$/", $last_name)) {
        $errors[] = "Last name must only contain letters, single spaces, hyphens, and apostrophes (no numbers or special characters, and no leading/trailing/double spaces, hyphens, or apostrophes).";
    }

    // File upload handling
    $upload_dir = __DIR__ . '/../uploads/requirements/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    $form_138_path = $good_moral_path = $diploma_path = '';
    $allowed_types = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
    $max_size = 5 * 1024 * 1024; // 5MB
    $file_errors = [];
    $form_138_path = isset($_FILES['form_138']) ? handle_upload($_FILES['form_138'], 'form138', $upload_dir, $allowed_types, $max_size, $file_errors) : '';
    $good_moral_path = isset($_FILES['good_moral']) ? handle_upload($_FILES['good_moral'], 'goodmoral', $upload_dir, $allowed_types, $max_size, $file_errors) : '';
    $diploma_path = isset($_FILES['diploma']) ? handle_upload($_FILES['diploma'], 'diploma', $upload_dir, $allowed_types, $max_size, $file_errors) : '';
    if (!empty($file_errors)) {
        $errors = array_merge($errors, $file_errors);
    }

    // SERVER-SIDE: citizenship must only allow letters and spaces
    if (!empty($citizenship) && !preg_match('/^[A-Za-z ]+$/', $citizenship)) {
        $errors[] = "Citizenship must only contain letters and spaces (no numbers or special characters).";
    }

    // SERVER-SIDE: phone number must be exactly 11 digits and only numbers
    if (!empty($phone_number) && !preg_match('/^09[0-9]{9}$/', $phone_number)) {
        $errors[] = "Phone number must be exactly 11 digits and start with 09 (e.g., 09XXXXXXXXX).";
    }

    // SERVER-SIDE: Validate birthdate (must be 18-80 years old)
    if (!empty($birthdate)) {
        $birth_timestamp = strtotime($birthdate);
        $today = strtotime(date('Y-m-d'));
        $age = floor(($today - $birth_timestamp) / (365.25 * 24 * 60 * 60));
        if ($age < 18 || $age > 80) {
            $errors[] = "You must be at least 18 years old and not older than 80 years old to register.";
        }
    }

    // SERVER-SIDE: Only accept Gmail addresses (must end with @gmail.com)
    if (!empty($email) && !preg_match('/^[^@\s]+@gmail\.com$/i', $email)) {
        $errors[] = "Only Gmail addresses (@gmail.com) are accepted.";
    }

    if (empty($errors)) {
        try {
            // Generate a unique student ID (find the next available for the year)
            $year = date('Y');
            $sequence = 1;
            do {
                $student_id = $year . '-' . str_pad($sequence, 3, '0', STR_PAD_LEFT);
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE student_id = ?");
                $stmt->execute([$student_id]);
                $exists = $stmt->fetchColumn() > 0;
                $sequence++;
            } while ($exists);
            // Insert the student data
            $stmt = $pdo->prepare("
                INSERT INTO students (
                    student_id, first_name, middle_name, last_name, suffix_name,
                    sex, civil_status, birthdate, place_of_birth, citizenship,
                    address, phone_number, email, course, year_level,
                    status, created_at, form_138, good_moral, diploma
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'BSIT', 1,
                    'pending', NOW(), ?, ?, ?
                )
            ");
            $stmt->execute([
                $student_id, $first_name, $middle_name, $last_name, $suffix_name,
                $sex, $civil_status, $birthdate, $place_of_birth, $citizenship,
                $address, $phone_number, $email,
                $form_138_path, $good_moral_path, $diploma_path
            ]);
            
            // Get the inserted student ID
            $inserted_student_id = $pdo->lastInsertId();
            
            // Do NOT back up to Firebase on registration; only upon admin approval
            
            $registration_success = true;
            $registered_student_id = $student_id;
            // Set success message in session and redirect
            $_SESSION['success_message'] = 'Registration successful! Your Application ID is ' . $student_id . '.';
            header('Location: register.php');
            exit();
        } catch (PDOException $e) {
            $errors[] = "Registration failed: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration - iAttendance</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/register.css" rel="stylesheet">
    <style>
        body {
            background-color: #f5f8fa;
            font-family: 'Nunito', sans-serif;
            min-height: 100vh;
            padding: 40px 0;
        }

        .container {
            max-width: 1000px;
        }

        .registration-card {
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 40px;
        }

        .card-header {
            background: #198754;
            color: white;
            padding: 25px 30px;
            text-align: center;
        }

        .card-header h2 {
            margin: 0;
            font-weight: 700;
            font-size: 28px;
        }

        .card-header p {
            opacity: 0.9;
            margin-top: 5px;
            font-size: 16px;
        }

        .card-body {
            padding: 30px;
        }

        .section-card {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .section-header {
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 20px;
            padding-bottom: 10px;
        }

        .section-header h4 {
            color: #198754;
            font-weight: 600;
            margin: 0;
            font-size: 20px;
        }

        .form-label {
            font-weight: 600;
            color: #344767;
            margin-bottom: 8px;
        }

        .form-control, .form-select {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 10px 15px;
            height: auto;
            font-size: 15px;
        }

        .form-control:focus, .form-select:focus {
            border-color: #198754;
            box-shadow: 0 0 0 0.2rem rgba(25, 135, 84, 0.15);
        }

        .btn-primary {
            padding: 12px 30px;
            font-weight: 600;
            border-radius: 8px;
            font-size: 16px;
            background-color: #198754;
            border: none;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            background-color: #157347;
            transform: translateY(-1px);
        }

        .text-danger {
            color: #dc3545;
        }

        .alert {
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 25px;
        }

        .required-field::after {
            content: "*";
            color: #dc3545;
            margin-left: 4px;
            transition: opacity 0.3s ease;
        }
        
        /* Hide asterisk when field is filled */
        .form-control:not(:placeholder-shown) + .required-field::after,
        .form-select:not([value=""]) + .required-field::after {
            opacity: 0;
        }

        /* Style for invalid fields */
        .form-control.is-invalid,
        .form-select.is-invalid {
            border-color: #dc3545;
            padding-right: calc(1.5em + 0.75rem);
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right calc(0.375em + 0.1875rem) center;
            background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
        }

        /* Fix for calendar icon overlapping year in invalid date input */
        .form-control.is-invalid[type='date'] {
            background-image: none !important;
            padding-right: 15px !important;
            background-position: right 15px center !important;
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

        /* Improve alignment for upload required documents */
        @media (min-width: 768px) {
            .upload-row {
                display: flex;
                justify-content: space-between;
                gap: 24px;
            }
            .upload-col {
                flex: 1 1 0;
                min-width: 0;
                max-width: 100%;
                display: flex;
                flex-direction: column;
                align-items: stretch;
            }
        }
        @media (max-width: 767.98px) {
            .upload-row {
                display: block;
            }
            .upload-col {
                margin-bottom: 18px;
            }
        }
        .upload-col label {
            font-weight: 600;
            margin-bottom: 8px;
        }
        .upload-col input[type="file"] {
            width: 100%;
        }
        .upload-row-custom {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }
        @media (min-width: 768px) {
            .upload-row-custom {
                flex-direction: column;
            }
            .upload-item-custom {
                display: flex;
                align-items: center;
                gap: 18px;
            }
            .upload-item-custom input[type="file"] {
                width: 180px;
                margin-right: 10px;
            }
            .upload-item-custom label {
                margin-bottom: 0;
                font-weight: 600;
                white-space: nowrap;
            }
        }
        @media (max-width: 767.98px) {
            .upload-item-custom {
                flex-direction: column;
                align-items: flex-start;
                gap: 6px;
            }
            .upload-item-custom input[type="file"] {
                width: 100%;
                margin-right: 0;
            }
            .upload-item-custom label {
                margin-bottom: 0;
            }
        }
        #sex, #civil_status {
            color: #344767;
            font-weight: 600;
        }
        #sex option[value=""], #civil_status option[value=""] {
            color: #b0b0b0;
            font-weight: 400;
        }
        .suffix-select-truncate {
            color: #344767;
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
    </style>
</head>
<body>
    <div class="container">
        <div class="registration-card">
            <div class="card-header">
                <h2>Student Registration</h2>
                <p class="mb-0">iAttendance Management System</p>
            </div>
            <div class="card-body">
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success" role="alert">
                        <div class="d-flex align-items-center">
                        <i class="bi bi-check-circle-fill me-2"></i>
                            <div><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger" role="alert">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" class="row g-3" enctype="multipart/form-data">
                    <!-- Personal Information -->
                    <div class="col-12">
                        <div class="section-card">
                            <div class="section-header">
                                <h4><i class="bi bi-person-fill me-2"></i>Personal Information</h4>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label for="first_name" class="form-label required-field">First Name</label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" required value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
                                    <div class="invalid-feedback" id="first_name-error">First name must only contain letters and spaces.</div>
                                </div>
                                <div class="col-md-4">
                                    <label for="middle_name" class="form-label">Middle Name</label>
                                    <input type="text" class="form-control" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($_POST['middle_name'] ?? ''); ?>">
                                    <div class="invalid-feedback" id="middle_name-error">Middle name must only contain letters, single spaces, hyphens, and apostrophes.</div>
                                </div>
                                <div class="col-md-4">
                                    <label for="last_name" class="form-label required-field">Last Name</label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" required value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
                                    <div class="invalid-feedback" id="last_name-error">Last name must only contain letters, single spaces, hyphens, and apostrophes.</div>
                                </div>
                                <div class="col-md-3">
                                    <label for="suffix_name" class="form-label">Suffix Name</label>
                                    <select class="form-select suffix-select-truncate" id="suffix_name" name="suffix_name">
                                        <option value="" <?php if(($_POST['suffix_name'] ?? '') == '') echo 'selected'; ?>>Select suffix</option>
                                        <option value="Sr" <?php if(($_POST['suffix_name'] ?? '') == 'Sr') echo 'selected'; ?>>Sr</option>
                                        <option value="Jr" <?php if(($_POST['suffix_name'] ?? '') == 'Jr') echo 'selected'; ?>>Jr</option>
                                        <option value="I" <?php if(($_POST['suffix_name'] ?? '') == 'I') echo 'selected'; ?>>I</option>
                                        <option value="II" <?php if(($_POST['suffix_name'] ?? '') == 'II') echo 'selected'; ?>>II</option>
                                        <option value="III" <?php if(($_POST['suffix_name'] ?? '') == 'III') echo 'selected'; ?>>III</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="sex" class="form-label required-field">Sex</label>
                                    <select class="form-select" id="sex" name="sex" required>
                                        <option value="" <?php if(($_POST['sex'] ?? '') == '') echo 'selected'; ?>>Select sex...</option>
                                        <option value="Male" <?php if(($_POST['sex'] ?? '') == 'Male') echo 'selected'; ?>>Male</option>
                                        <option value="Female" <?php if(($_POST['sex'] ?? '') == 'Female') echo 'selected'; ?>>Female</option>
                                    </select>
                                    <div class="invalid-feedback" id="sex-error">Sex is required.</div>
                                </div>
                                <div class="col-md-3">
                                    <label for="civil_status" class="form-label required-field">Civil Status</label>
                                    <select class="form-select" id="civil_status" name="civil_status" required>
                                        <option value="" <?php if(($_POST['civil_status'] ?? '') == '') echo 'selected'; ?>>Select status...</option>
                                        <option value="Single" <?php if(($_POST['civil_status'] ?? '') == 'Single') echo 'selected'; ?>>Single</option>
                                        <option value="Married" <?php if(($_POST['civil_status'] ?? '') == 'Married') echo 'selected'; ?>>Married</option>
                                        <option value="Widowed" <?php if(($_POST['civil_status'] ?? '') == 'Widowed') echo 'selected'; ?>>Widowed</option>
                                        <option value="Separated" <?php if(($_POST['civil_status'] ?? '') == 'Separated') echo 'selected'; ?>>Separated</option>
                                    </select>
                                    <div class="invalid-feedback" id="civil_status-error">Civil status is required.</div>
                                </div>
                                <div class="col-md-3">
                                    <label for="birthdate" class="form-label required-field">Birthdate</label>
                                    <input type="date" class="form-control" id="birthdate" name="birthdate" required min="<?php echo date('Y-m-d', strtotime('-80 years')); ?>" max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>" value="<?php echo htmlspecialchars($_POST['birthdate'] ?? ''); ?>">
                                    <div class="invalid-feedback" id="birthdate-error">You must be at least 18 years old and not older than 80 years old.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="place_of_birth" class="form-label required-field">Place of Birth</label>
                                    <input type="text" class="form-control" id="place_of_birth" name="place_of_birth" required value="<?php echo htmlspecialchars($_POST['place_of_birth'] ?? ''); ?>">
                                    <div class="invalid-feedback" id="place_of_birth-error">Place of birth must not contain numbers.</div>
                                </div>
                    <div class="col-md-6">
                                    <label for="citizenship" class="form-label required-field">Citizenship</label>
                                    <input type="text" class="form-control" id="citizenship" name="citizenship" required value="<?php echo htmlspecialchars($_POST['citizenship'] ?? ''); ?>">
                                    <div class="invalid-feedback" id="citizenship-error">Citizenship must only contain letters and spaces (no numbers or special characters).</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Contact Information -->
                    <div class="col-12">
                        <div class="section-card">
                            <div class="section-header">
                                <h4><i class="bi bi-telephone-fill me-2"></i>Contact Information</h4>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="phone_number" class="form-label required-field">Phone Number</label>
                                    <input type="tel" class="form-control" id="phone_number" name="phone_number" required placeholder="09XXXXXXXXX" maxlength="11" value="<?php echo htmlspecialchars($_POST['phone_number'] ?? ''); ?>">
                                    <div class="invalid-feedback" id="phone_number-error">Phone number must be exactly 11 digits and start with 09 (e.g., 09XXXXXXXXX).</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="email" class="form-label required-field">Gmail Address</label>
                                    <input type="email" class="form-control" id="email" name="email" required placeholder="example@gmail.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                                    <div class="invalid-feedback" id="email-error">
                                        Only Gmail addresses (@gmail.com) are accepted.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Address Information -->
                    <div class="col-12">
                        <div class="section-card">
                            <div class="section-header">
                                <h4><i class="bi bi-geo-alt-fill me-2"></i>Address Information</h4>
                            </div>
                            <div class="row g-3">
                    <div class="col-md-6">
                                    <label for="province" class="form-label required-field">Province</label>
                                    <select class="form-select" id="province" name="province" required>
                                        <option value="">Select Province</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                                    <label for="city" class="form-label required-field">City/Municipality</label>
                                    <select class="form-select" id="city" name="city" required disabled>
                                        <option value="">Select City/Municipality</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                                    <label for="barangay" class="form-label required-field">Barangay</label>
                                    <select class="form-select" id="barangay" name="barangay" required disabled>
                                        <option value="">Select Barangay</option>
                                    </select>
                    </div>
                    <div class="col-md-6">
                                    <label for="street_address" class="form-label required-field">Street Address / House Number</label>
                                    <textarea class="form-control" id="street_address" name="street_address" rows="2" required placeholder="Enter detailed street address, house/building number"><?php echo htmlspecialchars($_POST['street_address'] ?? ''); ?></textarea>
                                </div>
                                <input type="hidden" id="complete_address" name="address">
                            </div>
                        </div>
                    </div>

                    <!-- File Uploads -->
                    <div class="col-12">
                        <div class="section-card">
                            <div class="section-header text-center">
                                <h4 class="mb-0"><i class="bi bi-upload me-2"></i>Upload Required Documents</h4>
                            </div>
                            <p class="text-muted small mb-3" style="margin-top: 8px;">
                                <i class="bi bi-info-circle me-1"></i>
                                Accepted file types: <strong>PDF, JPG, JPEG, PNG</strong>
                            </p>
                            <div class="upload-row-custom mt-2">
                                <div class="upload-item-custom">
                                    <input type="file" class="form-control" id="form_138" name="form_138" accept=".pdf,.jpg,.jpeg,.png" required>
                                    <label for="form_138" class="form-label required-field">High School Report Card (Form 138)</label>
                                </div>
                                <div class="upload-item-custom">
                                    <input type="file" class="form-control" id="good_moral" name="good_moral" accept=".pdf,.jpg,.jpeg,.png" required>
                                    <label for="good_moral" class="form-label required-field">Good Moral Certificate</label>
                                </div>
                                <div class="upload-item-custom">
                                    <input type="file" class="form-control" id="diploma" name="diploma" accept=".pdf,.jpg,.jpeg,.png" required>
                                    <label for="diploma" class="form-label required-field">Certificate of Completion / Diploma</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-check-circle me-2"></i>Register
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="modal fade" id="successModal" tabindex="-1" aria-labelledby="successModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-4">
                <div class="modal-body text-center">
                    <div class="icon-container mb-3">
                        <svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" fill="currentColor" class="bi bi-check-circle-fill text-success" viewBox="0 0 16 16">
                            <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
                        </svg>
                    </div>
                    <h2 class="text-success mb-3">Application Received!</h2>
                    <p>We have received your application for the<br>iAttendance Management System</p>
                    
                    <div class="application-id bg-light p-2 rounded mb-3 d-inline-block">
                        Application ID: <strong><?php echo htmlspecialchars($registered_student_id); ?></strong>
                    </div>
                    <p class="small text-muted mb-4">Please save your Application ID for future reference.</p>
                    
                    <div class="next-steps text-start bg-light p-3 rounded">
                        <h5 class="text-center mb-3">Next Steps:</h5>
                        <ol class="ps-4">
                            <li>Your application will be reviewed by the admin.</li>
                            <li>Once approved, you will receive an email with your login credentials.</li>
                            <li>Your application will be assigned to a section by the admin. Please wait for your section assignment. (If Approved)</li>
                            <li>You can then log in to the system using those credentials.</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        .success-icon {
            width: 80px;
            height: 80px;
            background-color: #d1e7dd;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
        }
        .success-icon i {
            font-size: 40px;
            color: #0f5132;
        }
        .student-id {
            display: inline-block;
            font-size: 1.1rem;
        }
        .next-steps ol {
            padding-left: 1.2rem;
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($show_success_modal): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var successModal = new bootstrap.Modal(document.getElementById('successModal'), {});
            successModal.show();
        });
    </script>
    <?php endif; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Cache DOM elements
        const provinceSelect = document.getElementById('province');
        const citySelect = document.getElementById('city');
        const barangaySelect = document.getElementById('barangay');
        const streetAddressInput = document.getElementById('street_address');
        const completeAddressInput = document.getElementById('complete_address');

        // Sticky values from PHP POST
        const stickyProvince = <?php echo isset($_POST['province']) ? json_encode($_POST['province']) : 'null'; ?>;
        const stickyCity = <?php echo isset($_POST['city']) ? json_encode($_POST['city']) : 'null'; ?>;
        const stickyBarangay = <?php echo isset($_POST['barangay']) ? json_encode($_POST['barangay']) : 'null'; ?>;
        const stickyStreet = <?php echo isset($_POST['street_address']) ? json_encode($_POST['street_address']) : 'null'; ?>;
        const stickyAddress = <?php echo isset($_POST['address']) ? json_encode($_POST['address']) : 'null'; ?>;

        // Function to load provinces for CALABARZON (Region IV-A)
        async function loadProvinces() {
            try {
                const response = await fetch('https://psgc.gitlab.io/api/regions/040000000/provinces/');
                const provinces = await response.json();
                provinces.sort((a, b) => a.name.localeCompare(b.name));
                provinces.forEach(province => {
                    const option = new Option(province.name, province.code);
                    provinceSelect.add(option);
                });
                // Set sticky province
                if (stickyProvince) {
                    provinceSelect.value = stickyProvince;
                    provinceSelect.dispatchEvent(new Event('change'));
                }
            } catch (error) {
                console.error('Error loading provinces:', error);
            }
        }

        // Function to load cities
        async function loadCities(provinceCode) {
            try {
                citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
                barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
                const response = await fetch(`https://psgc.gitlab.io/api/provinces/${provinceCode}/cities-municipalities/`);
                const cities = await response.json();
                cities.sort((a, b) => a.name.localeCompare(b.name));
                cities.forEach(city => {
                    const option = new Option(city.name, city.code);
                    citySelect.add(option);
                });
                citySelect.disabled = false;
                barangaySelect.disabled = true;
                // Set sticky city
                if (stickyCity) {
                    citySelect.value = stickyCity;
                    citySelect.dispatchEvent(new Event('change'));
                }
            } catch (error) {
                console.error('Error loading cities:', error);
            }
        }

        // Function to load barangays
        async function loadBarangays(cityCode) {
            try {
                barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
                const response = await fetch(`https://psgc.gitlab.io/api/cities-municipalities/${cityCode}/barangays/`);
                const barangays = await response.json();
                barangays.sort((a, b) => a.name.localeCompare(b.name));
                barangays.forEach(barangay => {
                    const option = new Option(barangay.name, barangay.code);
                    barangaySelect.add(option);
                });
                barangaySelect.disabled = false;
                // Set sticky barangay
                if (stickyBarangay) {
                    barangaySelect.value = stickyBarangay;
                }
            } catch (error) {
                console.error('Error loading barangays:', error);
            }
        }

        // Function to update complete address
        function updateCompleteAddress() {
            const provinceText = provinceSelect.options[provinceSelect.selectedIndex]?.text || '';
            const cityText = citySelect.options[citySelect.selectedIndex]?.text || '';
            const barangayText = barangaySelect.options[barangaySelect.selectedIndex]?.text || '';
            const streetAddress = streetAddressInput.value.trim();
            // Remove 'Barangay' prefix if it exists in the barangayText
            const cleanedBarangayText = barangayText.replace(/^Barangay\s+/i, '');
            const addressParts = [
                streetAddress,
                cleanedBarangayText && `Barangay ${cleanedBarangayText}`,
                cityText,
                provinceText,
                'CALABARZON'
            ].filter(Boolean);
            completeAddressInput.value = addressParts.join(', ');
        }

        // Event Listeners
        provinceSelect.addEventListener('change', function() {
            if (this.value) {
                loadCities(this.value);
            } else {
                citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
                citySelect.disabled = true;
            }
            updateCompleteAddress();
        });

        citySelect.addEventListener('change', function() {
            if (this.value) {
                loadBarangays(this.value);
            } else {
                barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
                barangaySelect.disabled = true;
            }
            updateCompleteAddress();
        });

        barangaySelect.addEventListener('change', updateCompleteAddress);
        streetAddressInput.addEventListener('input', updateCompleteAddress);

        // Load provinces on page load
        loadProvinces();

        // Set sticky street address
        if (stickyStreet) {
            streetAddressInput.value = stickyStreet;
        }
        // Set sticky complete address if available
        if (stickyAddress) {
            completeAddressInput.value = stickyAddress;
        }
    });
    </script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        function allowOnlyNameChars(e) {
            const char = String.fromCharCode(e.which);
            // Allow: letters, space, hyphen, apostrophe, navigation keys
            if (!/^[a-zA-Z\s\-']$/.test(char) && ![8,9,13,37,39,46].includes(e.keyCode)) {
                e.preventDefault();
            }
        }
        function toUpperInput(e) {
            e.target.value = e.target.value.toUpperCase();
        }
        ['first_name','middle_name','last_name'].forEach(function(id) {
            const field = document.getElementById(id);
            field.addEventListener('keypress', allowOnlyNameChars);
            field.addEventListener('input', function(e) {
                if (id === 'first_name') {
                    // Remove invalid chars and double spaces for first name
                    field.value = field.value.replace(/[^A-Za-z\s\-']/g, '');
                    field.value = field.value.replace(/\s+/g, ' '); // Replace multiple spaces with single space
                } else {
                    // Keep existing validation for middle and last name
                    field.value = field.value.replace(/[^A-Za-z\s\-']/g, '');
                    field.value = field.value.replace(/([\s\-'])\1+/g, '$1');
                    field.value = field.value.replace(/^[\s\-']+|[\s\-']+$/g, '');
                }
            });
        });
        ['place_of_birth'].forEach(function(id) {
            const field = document.getElementById(id);
            field.addEventListener('keypress', function(e) {
                const char = String.fromCharCode(e.which);
                // Block numbers
                if (/[0-9]/.test(char) && ![8,9,13,37,39,46].includes(e.keyCode)) {
                    e.preventDefault();
                }
            });
            field.addEventListener('input', function(e) {
                // Remove any numbers pasted in
                field.value = field.value.replace(/[0-9]/g, '');
                toUpperInput(e);
            });
        });
        ['citizenship'].forEach(function(id) {
            const field = document.getElementById(id);
            field.addEventListener('keypress', function(e) {
                const char = String.fromCharCode(e.which);
                // Block numbers and special characters (allow only letters and spaces)
                if (!/[a-zA-Z\s]/.test(char) && ![8,9,13,37,39,46].includes(e.keyCode)) {
                    e.preventDefault();
                }
            });
            field.addEventListener('input', function(e) {
                // Remove any numbers or special characters pasted in
                field.value = field.value.replace(/[^A-Za-z\s]/g, '');
                toUpperInput(e);
            });
        });
        // Existing validation for custom validity
        function validateNameField(fieldId, fieldLabel) {
            const field = document.getElementById(fieldId);
            field.addEventListener('input', function() {
                const value = field.value;
                const regex = /^[A-Za-z]+([\s\-'][A-Za-z]+)*$/;
                if (value && !regex.test(value)) {
                    field.setCustomValidity(fieldLabel + " must only contain letters, single spaces, hyphens, and apostrophes. No numbers, special characters, or double/leading/trailing spaces, hyphens, or apostrophes allowed.");
                } else {
                    field.setCustomValidity('');
                }
            });
        }
        validateNameField('middle_name', 'Middle name');
        validateNameField('last_name', 'Last name');
        setupNameValidation('first_name', 'First name');
    });
    </script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Phone number validation
        const phoneField = document.getElementById('phone_number');
        phoneField.addEventListener('keypress', function(e) {
            const char = String.fromCharCode(e.which);
            // Allow only numbers
            if (!/[0-9]/.test(char) && ![8,9,13,37,39,46].includes(e.keyCode)) {
                e.preventDefault();
            }
            // Limit to 11 digits
            if (phoneField.value.length >= 11 && ![8,9,13,37,39,46].includes(e.keyCode)) {
                e.preventDefault();
            }
        });
        phoneField.addEventListener('input', function(e) {
            // Remove non-numeric characters and limit to 11 digits
            phoneField.value = phoneField.value.replace(/[^0-9]/g, '').slice(0, 11);
        });
        phoneField.addEventListener('blur', function() {
            if (phoneField.value.length !== 11 || !/^09[0-9]{9}$/.test(phoneField.value)) {
                phoneField.setCustomValidity('Phone number must be exactly 11 digits and start with 09 (e.g., 09XXXXXXXXX).');
            } else {
                phoneField.setCustomValidity('');
            }
        });
        phoneField.addEventListener('input', function() {
            if (phoneField.value.length === 11 && /^09[0-9]{9}$/.test(phoneField.value)) {
                phoneField.setCustomValidity('');
            }
        });
    });
    </script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Auto-dismiss error alert after 10 seconds
        const errorAlert = document.querySelector('.alert-danger');
        if (errorAlert) {
            setTimeout(function() {
                errorAlert.classList.add('fade');
                errorAlert.style.transition = 'opacity 0.5s';
                errorAlert.style.opacity = '0';
                setTimeout(function() {
                    errorAlert.remove();
                }, 500);
            }, 10000); // 10 seconds
        }
    });
    </script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Ensure select color matches label and placeholder is gray for suffix_name
        function updateSelectColor(selectId) {
            const select = document.getElementById(selectId);
            select.addEventListener('change', function() {
                if (select.value === "") {
                    select.style.color = "#b0b0b0";
                } else {
                    select.style.color = "#344767";
                }
            });
            // Initial color
            if (select.value === "") {
                select.style.color = "#b0b0b0";
            } else {
                select.style.color = "#344767";
            }
        }
        updateSelectColor('sex');
        updateSelectColor('civil_status');
        updateSelectColor('suffix_name');
    });
    </script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Real-time validation for Middle Name and Last Name
        function setupNameValidation(id, label) {
            const field = document.getElementById(id);
            const error = document.getElementById(id+'-error');
            field.addEventListener('input', function() {
                const value = field.value;
                const regex = /^[A-Za-z]+([\s\-'][A-Za-z]+)*$/;
                if (value && !regex.test(value)) {
                    field.classList.add('is-invalid');
                    error.style.display = 'block';
                    field.setCustomValidity(label + ' must only contain letters, single spaces, hyphens, and apostrophes.');
                } else {
                    field.classList.remove('is-invalid');
                    error.style.display = 'none';
                    field.setCustomValidity('');
                }
            });
        }
        setupNameValidation('middle_name', 'Middle name');
        setupNameValidation('last_name', 'Last name');

        // Real-time validation for Place of Birth
        const pobField = document.getElementById('place_of_birth');
        const pobError = document.getElementById('place_of_birth-error');
        pobField.addEventListener('input', function() {
            if (/[0-9]/.test(pobField.value)) {
                pobField.classList.add('is-invalid');
                pobError.style.display = 'block';
                pobField.setCustomValidity('Place of birth must not contain numbers.');
            } else {
                pobField.classList.remove('is-invalid');
                pobError.style.display = 'none';
                pobField.setCustomValidity('');
            }
        });

        // Real-time validation for Citizenship
        const citizenshipField = document.getElementById('citizenship');
        const citizenshipError = document.getElementById('citizenship-error');
        citizenshipField.addEventListener('input', function() {
            if (/[^A-Za-z\s]/.test(citizenshipField.value)) {
                citizenshipField.classList.add('is-invalid');
                citizenshipError.style.display = 'block';
                citizenshipField.setCustomValidity('Citizenship must only contain letters and spaces (no numbers or special characters).');
            } else {
                citizenshipField.classList.remove('is-invalid');
                citizenshipError.style.display = 'none';
                citizenshipField.setCustomValidity('');
            }
        });

        // Real-time validation for Phone Number
        const phoneField = document.getElementById('phone_number');
        const phoneError = document.getElementById('phone_number-error');
        function validatePhone() {
            if (phoneField.value.length !== 11 || !/^09[0-9]{9}$/.test(phoneField.value)) {
                phoneField.classList.add('is-invalid');
                phoneError.style.display = 'block';
                phoneField.setCustomValidity('Phone number must be exactly 11 digits and start with 09 (e.g., 09XXXXXXXXX).');
            } else {
                phoneField.classList.remove('is-invalid');
                phoneError.style.display = 'none';
                phoneField.setCustomValidity('');
            }
        }
        phoneField.addEventListener('input', validatePhone);
        phoneField.addEventListener('blur', validatePhone);

        // Real-time validation for Birthdate
        const birthdateField = document.getElementById('birthdate');
        const birthdateError = document.getElementById('birthdate-error');
        function validateBirthdateField() {
            const today = new Date();
            const yyyy = today.getFullYear();
            const mm = String(today.getMonth() + 1).padStart(2, '0');
            const dd = String(today.getDate()).padStart(2, '0');
            const value = birthdateField.value;
            if (value) {
                const birth = new Date(value);
                let age = yyyy - birth.getFullYear();
                if (mm < String(birth.getMonth() + 1).padStart(2, '0') || (mm === String(birth.getMonth() + 1).padStart(2, '0') && dd < String(birth.getDate()).padStart(2, '0'))) {
                    age--;
                }
                if (age < 18 || age > 80) {
                    birthdateField.classList.add('is-invalid');
                    birthdateError.style.display = 'block';
                    birthdateField.setCustomValidity('You must be at least 18 years old and not older than 80 years old.');
                } else {
                    birthdateField.classList.remove('is-invalid');
                    birthdateError.style.display = 'none';
                    birthdateField.setCustomValidity('');
                }
            } else {
                birthdateField.classList.add('is-invalid');
                birthdateError.style.display = 'block';
                birthdateField.setCustomValidity('This field is required.');
            }
        }
        birthdateField.addEventListener('input', validateBirthdateField);
        birthdateField.addEventListener('change', validateBirthdateField);
        birthdateField.addEventListener('blur', validateBirthdateField);
    });
    </script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Function to validate required fields
        function validateRequiredField(field) {
            const isSelect = field.tagName === 'SELECT';
            const isEmpty = isSelect ? field.value === "" : field.value.trim() === "";
            
            if (isEmpty && field.dataset.touched === 'true') {
                field.classList.add('is-invalid');
                const errorElement = document.getElementById(field.id + '-error');
                if (errorElement) {
                    errorElement.style.display = 'block';
                }
                field.setCustomValidity('This field is required.');
            } else {
                field.classList.remove('is-invalid');
                const errorElement = document.getElementById(field.id + '-error');
                if (errorElement) {
                    errorElement.style.display = 'none';
                }
                field.setCustomValidity('');
            }
        }

        // Add validation to all required fields
        const requiredFields = document.querySelectorAll('[required]');
        requiredFields.forEach(field => {
            // Skip birthdate, handled by its own validation
            if (field.id === 'birthdate') return;
            // Mark field as touched on focus
            field.addEventListener('focus', function() {
                this.dataset.touched = 'true';
            });

            // Validate on blur
            field.addEventListener('blur', function() {
                validateRequiredField(this);
            });

            // Validate on input only if field has been touched
            field.addEventListener('input', function() {
                if (this.dataset.touched === 'true') {
                    validateRequiredField(this);
                }
            });
        });

        // Special handling for select elements
        const requiredSelects = document.querySelectorAll('select[required]');
        requiredSelects.forEach(select => {
            select.addEventListener('focus', function() {
                this.dataset.touched = 'true';
            });
            
            select.addEventListener('change', function() {
                if (this.dataset.touched === 'true') {
                    validateRequiredField(this);
                }
            });
        });
        });
    </script>
</body>
</html>     