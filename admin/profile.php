<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

// Handle form submission for profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action']) && $_POST['action'] == 'update_profile') {
        try {
            // Update profile information
            $stmt = $pdo->prepare("
                UPDATE admins 
                SET full_name = ?, 
                    email = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $_POST['full_name'],
                $_POST['email'],
                $_SESSION['user_id']
            ]);

            // Update password if provided
            if (!empty($_POST['new_password'])) {
                // Get current password from database
                $stmt = $pdo->prepare("SELECT password FROM admins WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $current_hash = $stmt->fetchColumn();
                
                if (password_verify($_POST['current_password'], $current_hash)) {
                    $passwordStmt = $pdo->prepare("UPDATE admins SET password = ? WHERE id = ?");
                    $hashedPassword = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                    $passwordStmt->execute([$hashedPassword, $_SESSION['user_id']]);
                    
                    $_SESSION['success'] = "Profile and password updated successfully";
                } else {
                    $_SESSION['error'] = "Current password is incorrect";
                }
            } else {
                $_SESSION['success'] = "Profile updated successfully";
            }

            // Update session data
            $_SESSION['full_name'] = $_POST['full_name'];
            $_SESSION['email'] = $_POST['email'];
                
            // Refresh user data
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $admin = $stmt->fetch();
            
            header("Location: profile.php");
            exit();
        } catch(PDOException $e) {
            $_SESSION['error'] = "Error updating profile: " . $e->getMessage();
            error_log("Database error: " . $e->getMessage());
        }
    }
}

// Get current admin data
try {
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $admin = $stmt->fetch();
} catch(PDOException $e) {
    error_log("Error fetching admin data: " . $e->getMessage());
    $admin = null;
}

if ($admin === false || $admin === null) {
    die("Failed to fetch admin data");
}

$shortName = '';
if (!empty($admin['full_name'])) {
    $nameParts = explode(' ', $admin['full_name']);
    if (count($nameParts) >= 2) {
        $shortName = strtoupper(substr($nameParts[0], 0, 1)) . '.' . ucfirst(strtolower($nameParts[count($nameParts) - 1]));
    } else {
        $shortName = htmlspecialchars($admin['full_name']);
    }
} else {
    $shortName = 'AD';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Admin Dashboard</title>
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
            color: #495057;
            overflow: hidden;
        }
        
        .user-info .user-avatar {
            margin-right: 10px;
        }
        
        .profile-avatar {
            width: 100px;
            height: 100px;
            background: #e9ecef;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2em;
            font-weight: 600;
            color: #495057;
        }
    </style>
</head>
<body class="admin-page">
    <!-- Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content -->
    <main class="page-content">
        <!-- Topbar -->
        <div class="topbar">
            <button class="toggle-sidebar">
                <i class="bi bi-list"></i>
            </button>
            
            <div class="user-info dropdown">
                <a href="#" class="user-dropdown-toggle" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="user-avatar">AD</div>
                    <span class="user-name"><?php echo htmlspecialchars($admin['full_name'] ?? 'System Administrator'); ?></span>
                </a>
                <ul class="dropdown-menu user-dropdown" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i> Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="../auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
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
            <form action="profile.php" method="POST">
                <input type="hidden" name="action" value="update_profile">
                
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="profile-header d-flex align-items-center">
                            <div class="profile-avatar me-3">
                                AD
                            </div>
                            <div class="profile-info">
                                <h3><?php echo htmlspecialchars($admin['full_name'] ?? 'System Administrator'); ?></h3>
                                <p class="text-muted mb-0">Administrator</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Admin ID</label>
                        <div class="form-control bg-light" readonly><?php echo htmlspecialchars($admin['admin_id'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Full Name</label>
                        <input type="text" class="form-control" name="full_name" value="<?php echo htmlspecialchars($admin['full_name'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($admin['email'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Created At</label>
                        <div class="form-control bg-light" readonly><?php echo !empty($admin['created_at']) ? date('F d, Y h:i A', strtotime($admin['created_at'])) : 'N/A'; ?></div>
                    </div>
                </div>

                <hr class="my-4">

                <h5 class="mb-3">Change Password</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Current Password</label>
                        <input type="password" class="form-control" name="current_password" placeholder="Leave blank to keep current password">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">New Password</label>
                        <input type="password" class="form-control" name="new_password" id="new_password" placeholder="Leave blank to keep current password">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" name="confirm_password" id="confirm_password" placeholder="Confirm new password">
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-2"></i>Save Changes
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="bi bi-x-circle me-2"></i>Cancel
                    </a>
                </div>
            </form>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Password confirmation validation
            const confirmPasswordField = document.getElementById('confirm_password');
            const newPasswordField = document.getElementById('new_password');
            
            if (confirmPasswordField && newPasswordField) {
                confirmPasswordField.addEventListener('input', function() {
                    const newPassword = newPasswordField.value;
                    const confirmPassword = this.value;
                    
                    if (newPassword && confirmPassword) {
                        if (newPassword !== confirmPassword) {
                            this.setCustomValidity('Passwords do not match');
                            this.classList.add('is-invalid');
                        } else {
                            this.setCustomValidity('');
                            this.classList.remove('is-invalid');
                        }
                    } else {
                        this.setCustomValidity('');
                        this.classList.remove('is-invalid');
                    }
                });

                newPasswordField.addEventListener('input', function() {
                    const confirmPassword = confirmPasswordField.value;
                    if (confirmPassword && this.value !== confirmPassword) {
                        confirmPasswordField.setCustomValidity('Passwords do not match');
                        confirmPasswordField.classList.add('is-invalid');
                    } else {
                        confirmPasswordField.setCustomValidity('');
                        confirmPasswordField.classList.remove('is-invalid');
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
        });
    </script>
</body>
</html>
