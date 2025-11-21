<?php
session_start();
require_once '../config/database.php';
require_once '../config.php';
require_once '../helpers/BackupHooks.php';
require_once '../helpers/RfidHelper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header("Location: ../index.php");
    exit();
}

// Ensure RFID infrastructure exists
ensureRfidInfrastructure($pdo);

// Handle form submission for profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] == 'update_profile') {
            try {
                // Get the current avatar path first
                $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $currentUser = $stmt->fetch();
                $avatar_path = $currentUser['profile_picture']; // Keep existing avatar by default

                // Handle avatar upload if present
                if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] == 0) {
                    $allowed = ['jpg', 'jpeg', 'png', 'gif'];
                    $filename = $_FILES['avatar']['name'];
                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    
                    if (in_array($ext, $allowed)) {
                        $upload_dir = '../uploads/profile_pics/';
                        if (!file_exists($upload_dir)) {
                            if (!mkdir($upload_dir, 0777, true)) {
                                $_SESSION['error'] = "Failed to create upload directory";
                                error_log("Failed to create directory: " . $upload_dir);
                            }
                        }
                        
                        $new_filename = 'student_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
                        $destination = $upload_dir . $new_filename;
                        
                        if (move_uploaded_file($_FILES['avatar']['tmp_name'], $destination)) {
                            $avatar_path = 'uploads/profile_pics/' . $new_filename;
                            
                            // Delete old avatar if exists
                            if (!empty($currentUser['profile_picture']) && file_exists('../' . $currentUser['profile_picture'])) {
                                unlink('../' . $currentUser['profile_picture']);
        }
                        } else {
                            $_SESSION['error'] = "Failed to upload image. Please try again.";
                            error_log("Failed to move uploaded file");
                        }
                    } else {
                        $_SESSION['error'] = "Invalid file type. Allowed types: jpg, jpeg, png, gif";
                    }
                }

                // Update profile information including avatar
        $stmt = $pdo->prepare("
            UPDATE students 
                    SET full_name = ?, 
                        email = ?,
                        profile_picture = ?
            WHERE id = ?
        ");
        
        $stmt->execute([
                    $_POST['full_name'],
                    $_POST['email'],
                    $avatar_path,
            $_SESSION['user_id']
        ]);

        // Update password if provided
        if (!empty($_POST['new_password'])) {
            $stmt = $pdo->prepare("SELECT password FROM students WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $current_hash = $stmt->fetchColumn();
                    if (password_verify($_POST['current_password'], $current_hash)) {
                        $passwordStmt = $pdo->prepare("UPDATE students SET password = ? WHERE id = ?");
                        $hashedPassword = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                        $passwordStmt->execute([$hashedPassword, $_SESSION['user_id']]);
                        
                        // Backup password change to Firebase
                        try {
                            $backupHooks = new BackupHooks();
                            $updatedData = [
                                'password_changed_at' => date('Y-m-d H:i:s')
                            ];
                            $backupHooks->backupStudentPasswordChange($_SESSION['user_id'], $updatedData);
                        } catch (Exception $e) {
                            error_log("Firebase backup failed for student password change: " . $e->getMessage());
                        }
                        
                        $_SESSION['success'] = "Profile, avatar, and password updated successfully";
                    } else {
                        $_SESSION['error'] = "Current password is incorrect";
                    }
                } else {
                    $_SESSION['success'] = "Profile and avatar updated successfully";
                }
        
        // Update session data
                $_SESSION['full_name'] = $_POST['full_name'];
                $_SESSION['email'] = $_POST['email'];
                
                // Refresh user data
                $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch();
                
            header("Location: profile.php");
            exit();
            } catch(PDOException $e) {
                $_SESSION['error'] = "Error updating profile: " . $e->getMessage();
                error_log("Database error: " . $e->getMessage());
            }
        }
    }
}

// Get current user data with avatar at the start of the page
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if ($user === false) {
    die("Failed to fetch user data");
}

// Get RFID tag information if assigned
$rfidTag = null;
if (!empty($user['rfid_uid'])) {
    try {
        $rfidStmt = $pdo->prepare("
            SELECT rt.*, rt.tag_uid, rt.assigned_at, rt.last_seen, rt.status
            FROM rfid_tags rt
            WHERE rt.tag_uid = ? AND rt.student_id = ?
            LIMIT 1
        ");
        $rfidStmt->execute([$user['rfid_uid'], $_SESSION['user_id']]);
        $rfidTag = $rfidStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching RFID tag: " . $e->getMessage());
    }
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
    <title>Profile - Student Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <link href="../assets/css/management.css" rel="stylesheet">
    <style>
        .user-avatar {
            width: 40px;
            height: 40px;
            background: #e9ecef;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: #388e3c;
            overflow: hidden;
        }
        
        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .user-info .user-avatar {
            margin-right: 10px;
        }
        
        .profile-avatar {
            cursor: pointer;
            width: 100px;
            height: 100px;
            background: #e8f5e9;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2em;
            position: relative;
            overflow: hidden;
            border: 2px solid #4caf50;
        }
        .avatar-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .edit-avatar-icon {
            position: absolute;
            bottom: 0;
            right: 0;
            background: #fff;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border: 2px solid #4caf50;
            cursor: pointer;
            margin: 0;
            transition: all 0.3s ease;
        }
        .edit-avatar-icon i {
            color: #4caf50;
            font-size: 16px;
        }
        .profile-avatar:hover .edit-avatar-icon {
            background: #c8e6c9;
        }
        .profile-avatar:hover .edit-avatar-icon i {
            color: #388e3c;
        }
        .management-header h2 {
            color: #388e3c;
            font-weight: 800;
        }
        .form-card {
            border-top: 4px solid #4caf50;
        }
        .form-label {
            color: #388e3c;
            font-weight: 600;
        }
        .form-control[readonly] {
            background: #f4f7ff;
            border-left: 4px solid #4caf50;
            color: #234;
        }
        .btn-primary, .btn-success {
            background: #4caf50;
            border-color: #388e3c;
        }
        .btn-primary:hover, .btn-success:hover {
            background: #388e3c;
            border-color: #2e7031;
        }
        .rfid-assigned {
            border-left: 4px solid #4caf50;
        }
        .rfid-not-assigned {
            border-left: 4px solid #9e9e9e;
        }
        .rfid-uid-display {
            font-family: 'Courier New', monospace;
            letter-spacing: 1px;
        }
    </style>
</head>
<body class="student-page">
    <!-- Sidebar -->
    <aside class="sidebar student-sidebar">
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
                <a class="nav-link" href="view_attendance.php">
                    <i class="bi bi-calendar-check"></i>
                    <span>My Attendance</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="view_timetable.php">
                    <i class="bi bi-clock"></i>
                    <span>Timetable</span>
                </a>
            </li>
        </ul>
        
        <hr class="sidebar-divider">
        
        <div class="sidebar-heading">Account</div>
        
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link active" href="profile.php">
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
                        <?php if (!empty($user['profile_picture']) && file_exists('../' . $user['profile_picture'])): ?>
                            <img src="../<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile Avatar" class="avatar-image">
                        <?php else: ?>
                            <?php echo isset($_SESSION['initials']) ? $_SESSION['initials'] : 'ME'; ?>
                        <?php endif; ?>
                    </div>
                    <span class="user-name ms-2" style="font-weight:600; font-size:1.1em; white-space:nowrap; overflow:visible; text-overflow:unset; max-width:none;">
                        <?php echo $shortName; ?>
                    </span>
                </a>
                <ul class="dropdown-menu user-dropdown" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person"></i> Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="../auth/logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                </ul>
            </div>
        </div>

        <div class="management-header animate-fadeIn">
            <h2>Profile Settings</h2>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show animate-fadeIn" role="alert">
                <?php 
                    echo $_SESSION['success'];
                    unset($_SESSION['success']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show animate-fadeIn" role="alert">
                <?php 
                    echo $_SESSION['error'];
                    unset($_SESSION['error']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Profile Form -->
        <div class="form-card animate-fadeIn delay-1">
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="profile-header">
                            <div class="profile-avatar position-relative">
                                <?php if (!empty($user['profile_picture']) && file_exists('../' . $user['profile_picture'])): ?>
                                    <img src="../<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile Avatar" class="avatar-image">
                        <?php else: ?>
                                <?php echo isset($_SESSION['initials']) ? $_SESSION['initials'] : 'ME'; ?>
            <?php endif; ?>
                                </div>
                            <div class="profile-info">
                            <h3><?php echo htmlspecialchars($user['full_name'] ?? ($user['first_name'] . ' ' . $user['middle_name'] . ' ' . $user['last_name'] . ' ' . $user['suffix_name'])); ?></h3>
                                <p class="text-muted">Student</p>
                        </div>
                    </div>
                </div>
                        </div>
                <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Sex</label>
                    <div class="form-control bg-light" readonly><?php echo !empty($user['sex']) ? htmlspecialchars($user['sex']) : 'N/A'; ?></div>
                        </div>
                <div class="col-md-6">
                    <label class="form-label">Civil Status</label>
                    <div class="form-control bg-light" readonly><?php echo !empty($user['civil_status']) ? htmlspecialchars($user['civil_status']) : 'N/A'; ?></div>
                    </div>
                <div class="col-md-6">
                    <label class="form-label">Birthdate</label>
                    <div class="form-control bg-light" readonly><?php echo !empty($user['birthdate']) ? date('F d, Y', strtotime($user['birthdate'])) : 'N/A'; ?></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Phone Number</label>
                    <div class="form-control bg-light" readonly><?php echo !empty($user['phone_number']) ? htmlspecialchars($user['phone_number']) : 'N/A'; ?></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <div class="form-control bg-light" readonly><?php echo !empty($user['email']) ? htmlspecialchars($user['email']) : 'N/A'; ?></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Course/Department</label>
                    <div class="form-control bg-light" readonly><?php echo !empty($user['course']) ? htmlspecialchars($user['course']) : (!empty($user['department']) ? htmlspecialchars($user['department']) : 'N/A'); ?></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Created At</label>
                    <div class="form-control bg-light" readonly><?php echo !empty($user['created_at']) ? date('F d, Y h:i A', strtotime($user['created_at'])) : 'N/A'; ?></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Student ID</label>
                    <div class="form-control bg-light" readonly><?php echo !empty($user['student_id']) ? htmlspecialchars($user['student_id']) : 'N/A'; ?></div>
                </div>
                <div class="col-md-12">
                    <label class="form-label">
                        <i class="bi bi-upc-scan me-1"></i>RFID Card Status
                    </label>
                    <?php if ($rfidTag && !empty($user['rfid_uid'])): ?>
                        <div class="card rfid-assigned border-success">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <div>
                                        <span class="badge bg-success mb-2">
                                            <i class="bi bi-check-circle me-1"></i>RFID Card Assigned
                                        </span>
                                        <h5 class="mb-0 rfid-uid-display"><?php echo htmlspecialchars($user['rfid_uid']); ?></h5>
                                    </div>
                                    <button class="btn btn-sm btn-outline-primary" type="button" onclick="copyRfidToClipboard('<?php echo htmlspecialchars($user['rfid_uid']); ?>')" title="Copy RFID UID">
                                        <i class="bi bi-clipboard me-1"></i>Copy
                                    </button>
                                </div>
                                <div class="row mt-2">
                                    <?php if ($rfidTag['assigned_at']): ?>
                                        <div class="col-md-6">
                                            <small class="text-muted">
                                                <i class="bi bi-calendar3 me-1"></i><strong>Assigned:</strong> <?php echo date('M d, Y h:i A', strtotime($rfidTag['assigned_at'])); ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($rfidTag['last_seen']): ?>
                                        <div class="col-md-6">
                                            <small class="text-muted">
                                                <i class="bi bi-clock-history me-1"></i><strong>Last Seen:</strong> <?php echo date('M d, Y h:i A', strtotime($rfidTag['last_seen'])); ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="card rfid-not-assigned border-secondary">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <span class="badge bg-secondary me-2">
                                        <i class="bi bi-x-circle me-1"></i>Not Assigned
                                    </span>
                                    <span class="text-muted">No RFID card has been assigned to your account.</span>
                                </div>
                                <small class="text-muted d-block mt-2">
                                    <i class="bi bi-info-circle me-1"></i>Please contact the administrator to request an RFID card assignment.
                                </small>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Profile Picture Modal -->
    <div class="modal fade" id="profilePictureModal" tabindex="-1" aria-labelledby="profilePictureModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
                    <h5 class="modal-title" id="profilePictureModalLabel">Profile Picture</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
                <div class="modal-body text-center">
                    <img id="modalProfileImage" src="" alt="Profile Picture" style="max-width: 100%; max-height: 400px; object-fit: contain;">
          </div>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>
    <script>
        // Copy RFID to clipboard
        function copyRfidToClipboard(rfid) {
            navigator.clipboard.writeText(rfid).then(function() {
                // Show toast notification
                const toast = document.createElement('div');
                toast.className = 'alert alert-success position-fixed top-0 end-0 m-3';
                toast.style.zIndex = '9999';
                toast.innerHTML = '<i class="bi bi-check-circle me-2"></i>RFID UID copied to clipboard!';
                document.body.appendChild(toast);
                setTimeout(function() {
                    toast.classList.add('fade-out');
                    setTimeout(function() {
                        toast.remove();
                    }, 300);
                }, 2000);
            }).catch(function(err) {
                console.error('Failed to copy:', err);
            });
        }
        
        document.addEventListener('DOMContentLoaded', function() {
        // Password confirmation validation (if password fields exist)
        const confirmPasswordField = document.getElementById('confirm_password');
        if (confirmPasswordField) {
            confirmPasswordField.addEventListener('input', function() {
                const newPassword = document.getElementById('new_password').value;
                const confirmPassword = this.value;
                if (newPassword !== confirmPassword) {
                    this.setCustomValidity('Passwords do not match');
                } else {
                    this.setCustomValidity('');
                }
            });
        }

        // Auto-hide alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.classList.add('fade-out');
                setTimeout(function() {
                    alert.remove();
                }, 150);
                }, 5000);
            });

            // Add click handler for profile picture
            const profileAvatar = document.querySelector('.profile-avatar');
            const modalProfileImage = document.getElementById('modalProfileImage');
            if (profileAvatar) {
                profileAvatar.addEventListener('click', function(e) {
                    // Don't show modal if clicking the edit icon
                    if (e.target.closest('.edit-avatar-icon')) return;
                    const avatarImage = this.querySelector('.avatar-image');
                    if (avatarImage && avatarImage.src) {
                        modalProfileImage.src = avatarImage.src;
                        new bootstrap.Modal(document.getElementById('profilePictureModal')).show();
                    }
                });
        }
        });
    </script>
</body>
</html>
