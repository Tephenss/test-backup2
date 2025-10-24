<?php
session_start();
require_once 'config/database.php';
require_once 'helpers/EmailVerification.php';

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    $redirect_path = match($_SESSION['user_type']) {
        'teacher' => 'teacher/dashboard.php',
        'admin' => 'admin/dashboard.php',
        default => 'student/dashboard.php'
    };
    header("Location: " . $redirect_path);
    exit();
}

// Add cache control headers
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Handle login form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['action'])) {
    $id = $_POST['id'];
    $password = $_POST['password'];
    
    try {
        // Try to find user in each table
        $tables = [
            'student' => ['table' => 'students', 'id_field' => 'student_id'],
            'teacher' => ['table' => 'teachers', 'id_field' => 'teacher_id'],
            'admin' => ['table' => 'admins', 'id_field' => 'admin_id']
        ];
        
        $user = null;
        $user_type = null;
        
        foreach ($tables as $type => $config) {
            $stmt = $pdo->prepare("SELECT * FROM {$config['table']} WHERE {$config['id_field']} = ?");
            $stmt->execute([$id]);
            $result = $stmt->fetch();
            
            if ($result && password_verify($password, $result['password'])) {
                $user = $result;
                $user_type = $type;
                break;
            }
        }
        
        if ($user) {
            // Store user data temporarily for verification
            $_SESSION['temp_user_data'] = [
                'id' => $user['id'],
                'username' => $user['student_id'] ?? $user['teacher_id'] ?? $user['admin_id'], // Use appropriate ID field
                'user_type' => $user_type,
                'email' => $user['email'],
                'full_name' => $user['first_name'] . ' ' . $user['last_name']
            ];
            
            // Generate and send verification code
            $verification = new EmailVerification();
            if ($verification->generateCode($user['id'], $user_type, $user['email'])) {
                $_SESSION['pending_verification'] = true;
                header("Location: auth/verify.php");
                exit();
            } else {
                $_SESSION['login_error'] = "Failed to send verification code. Please try again.";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
        } else {
            $_SESSION['login_error'] = "Invalid ID or password";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    } catch(PDOException $e) {
        $_SESSION['login_error'] = "Error: " . $e->getMessage();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Display error message if exists and clear it
if (isset($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Login - Attendance Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/login.css?v=<?php echo time(); ?>" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <style>
    .alert-fixed-top {
        z-index: 20000 !important;
        position: fixed;
        top: 30px;
        left: 50%;
        transform: translateX(-50%);
        width: auto;
        max-width: 90vw;
        min-width: 320px;
        padding: 16px 32px 16px 20px;
        border-radius: 12px;
        box-shadow: 0 4px 24px rgba(0,0,0,0.12);
        font-size: 1.08rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 12px;
        border: none;
        opacity: 0.98;
    }
    .alert-fixed-top.alert-danger {
        background: linear-gradient(90deg, #ff4e4e 0%, #ff7b7b 100%);
        color: #fff;
    }
    .alert-fixed-top.alert-success {
        background: linear-gradient(90deg, #28a745 0%, #5be584 100%);
        color: #fff;
    }
    .alert-fixed-top .alert-icon {
        font-size: 1.5em;
        margin-right: 8px;
        display: flex;
        align-items: center;
    }
    .pw-strength-wrap {
        margin-bottom: 0.5rem;
    }
    #pw-strength-bar {
        transition: width 0.3s, background 0.3s;
    }
    #pw-strength-text {
        font-size: 1.05em;
        margin-top: 0.3em;
        letter-spacing: 0.5px;
    }
    #pw-requirements li {
        display: flex;
        align-items: center;
        gap: 7px;
        font-size: 0.98em;
        color: #b02a37;
        transition: color 0.3s;
        margin-bottom: 2px;
    }
    #pw-requirements li.passed {
        color: #198754;
    }
    .pw-icon {
        font-size: 1.1em;
        transition: color 0.3s;
    }
    </style>
</head>
<body>
    <!-- Loading Screen -->
    <div class="loading-container show">
        <div class="loader">
            <div>
                <ul>
                    <li>
                        <svg viewBox="0 0 90 120" fill="currentColor">
                            <path d="M90,0 L90,120 L11,120 C4.92486775,120 0,115.075132 0,109 L0,11 C0,4.92486775 4.92486775,0 11,0 L90,0 Z M71.5,81 L18.5,81 C17.1192881,81 16,82.1192881 16,83.5 C16,84.8254834 17.0315359,85.9100387 18.3356243,85.9946823 L18.5,86 L71.5,86 C72.8807119,86 74,84.8807119 74,83.5 C74,82.1745166 72.9684641,81.0899613 71.6643757,81.0053177 L71.5,81 Z M71.5,57 L18.5,57 C17.1192881,57 16,58.1192881 16,59.5 C16,60.8254834 17.0315359,61.9100387 18.3356243,61.9946823 L18.5,62 L71.5,62 C72.8807119,62 74,60.8807119 74,59.5 C74,58.1192881 72.8807119,57 71.5,57 Z M71.5,33 L18.5,33 C17.1192881,33 16,34.1192881 16,35.5 C16,36.8254834 17.0315359,37.9100387 18.3356243,37.9946823 L18.5,38 L71.5,38 C72.8807119,38 74,36.8807119 74,35.5 C74,34.1192881 72.8807119,33 71.5,33 Z"></path>
                        </svg>
                    </li>
                    <li>
                        <svg viewBox="0 0 90 120" fill="currentColor">
                            <path d="M90,0 L90,120 L11,120 C4.92486775,120 0,115.075132 0,109 L0,11 C0,4.92486775 4.92486775,0 11,0 L90,0 Z M71.5,81 L18.5,81 C17.1192881,81 16,82.1192881 16,83.5 C16,84.8254834 17.0315359,85.9100387 18.3356243,85.9946823 L18.5,86 L71.5,86 C72.8807119,86 74,84.8807119 74,83.5 C74,82.1745166 72.9684641,81.0899613 71.6643757,81.0053177 L71.5,81 Z M71.5,57 L18.5,57 C17.1192881,57 16,58.1192881 16,59.5 C16,60.8254834 17.0315359,61.9100387 18.3356243,61.9946823 L18.5,62 L71.5,62 C72.8807119,62 74,60.8807119 74,59.5 C74,58.1192881 72.8807119,57 71.5,57 Z M71.5,33 L18.5,33 C17.1192881,33 16,34.1192881 16,35.5 C16,36.8254834 17.0315359,37.9100387 18.3356243,37.9946823 L18.5,38 L71.5,38 C72.8807119,38 74,36.8807119 74,35.5 C74,34.1192881 72.8807119,33 71.5,33 Z"></path>
                        </svg>
                    </li>
                    <li>
                        <svg viewBox="0 0 90 120" fill="currentColor">
                            <path d="M90,0 L90,120 L11,120 C4.92486775,120 0,115.075132 0,109 L0,11 C0,4.92486775 4.92486775,0 11,0 L90,0 Z M71.5,81 L18.5,81 C17.1192881,81 16,82.1192881 16,83.5 C16,84.8254834 17.0315359,85.9100387 18.3356243,85.9946823 L18.5,86 L71.5,86 C72.8807119,86 74,84.8807119 74,83.5 C74,82.1745166 72.9684641,81.0899613 71.6643757,81.0053177 L71.5,81 Z M71.5,57 L18.5,57 C17.1192881,57 16,58.1192881 16,59.5 C16,60.8254834 17.0315359,61.9100387 18.3356243,61.9946823 L18.5,62 L71.5,62 C72.8807119,62 74,60.8807119 74,59.5 C74,58.1192881 72.8807119,57 71.5,57 Z M71.5,33 L18.5,33 C17.1192881,33 16,34.1192881 16,35.5 C16,36.8254834 17.0315359,37.9100387 18.3356243,37.9946823 L18.5,38 L71.5,38 C72.8807119,38 74,36.8807119 74,35.5 C74,34.1192881 72.8807119,33 71.5,33 Z"></path>
                        </svg>
                    </li>
                    <li>
                        <svg viewBox="0 0 90 120" fill="currentColor">
                            <path d="M90,0 L90,120 L11,120 C4.92486775,120 0,115.075132 0,109 L0,11 C0,4.92486775 4.92486775,0 11,0 L90,0 Z M71.5,81 L18.5,81 C17.1192881,81 16,82.1192881 16,83.5 C16,84.8254834 17.0315359,85.9100387 18.3356243,85.9946823 L18.5,86 L71.5,86 C72.8807119,86 74,84.8807119 74,83.5 C74,82.1745166 72.9684641,81.0899613 71.6643757,81.0053177 L71.5,81 Z M71.5,57 L18.5,57 C17.1192881,57 16,58.1192881 16,59.5 C16,60.8254834 17.0315359,61.9100387 18.3356243,61.9946823 L18.5,62 L71.5,62 C72.8807119,62 74,60.8807119 74,59.5 C74,58.1192881 72.8807119,57 71.5,57 Z M71.5,33 L18.5,33 C17.1192881,33 16,34.1192881 16,35.5 C16,36.8254834 17.0315359,37.9100387 18.3356243,37.9946823 L18.5,38 L71.5,38 C72.8807119,38 74,36.8807119 74,35.5 C74,34.1192881 72.8807119,33 71.5,33 Z"></path>
                        </svg>
                    </li>
                    <li>
                        <svg viewBox="0 0 90 120" fill="currentColor">
                            <path d="M90,0 L90,120 L11,120 C4.92486775,120 0,115.075132 0,109 L0,11 C0,4.92486775 4.92486775,0 11,0 L90,0 Z M71.5,81 L18.5,81 C17.1192881,81 16,82.1192881 16,83.5 C16,84.8254834 17.0315359,85.9100387 18.3356243,85.9946823 L18.5,86 L71.5,86 C72.8807119,86 74,84.8807119 74,83.5 C74,82.1745166 72.9684641,81.0899613 71.6643757,81.0053177 L71.5,81 Z M71.5,57 L18.5,57 C17.1192881,57 16,58.1192881 16,59.5 C16,60.8254834 17.0315359,61.9100387 18.3356243,61.9946823 L18.5,62 L71.5,62 C72.8807119,62 74,60.8807119 74,59.5 C74,58.1192881 72.8807119,57 71.5,57 Z M71.5,33 L18.5,33 C17.1192881,33 16,34.1192881 16,35.5 C16,36.8254834 17.0315359,37.9100387 18.3356243,37.9946823 L18.5,38 L71.5,38 C72.8807119,38 74,36.8807119 74,35.5 C74,34.1192881 72.8807119,33 71.5,33 Z"></path>
                        </svg>
                    </li>
                </ul>
            </div>
            <span>Loading...</span>
        </div>
    </div>

    <div class="container">
        <!-- Error Alert Always on Top -->
        <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-fixed-top">
                <span class="alert-icon">&#9888;</span>
                <span><?php echo $error; ?></span>
            </div>
        <?php endif; ?>
        <div class="login-container">
            <h2>Welcome to<br><span>iAttendance</span></h2>
            <div class="login-subtitle">Laguna University Attendance Management System</div>
            <?php if (isset($_SESSION['logout_success'])): ?>
                <div class="alert alert-success fade show" id="logoutAlert" role="alert">
                    <?php 
                    echo $_SESSION['logout_success'];
                    unset($_SESSION['logout_success']);
                    ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['login_errors'])): ?>
                <div class="alert alert-danger">
                    <ul class="error-list">
                        <?php 
                        foreach ($_SESSION['login_errors'] as $error) {
                            echo "<li>{$error}</li>";
                        }
                        unset($_SESSION['login_errors']);
                        ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php
            // Clear old form data
            unset($_SESSION['old_id']);
            ?>

            <div class="login-form">
                <form action="" method="POST" autocomplete="off">
                    <div class="input__container" data-label="ID">
                        <div class="input__button__shadow">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm2-3a2 2 0 1 1-4 0 2 2 0 0 1 4 0zm4 8c0 1-1 1-1 1H3s-1 0-1-1 1-4 6-4 6 3 6 4zm-1-.004c-.001-.246-.154-.986-.832-1.664C11.516 10.68 10.289 10 8 10c-2.29 0-3.516.68-4.168 1.332-.678.678-.83 1.418-.832 1.664h10z"/>
                            </svg>
                        </div>
                        <input type="text" 
                               class="input__search" 
                               id="id" 
                               name="id" 
                               placeholder="ID"
                               value="<?php echo htmlspecialchars($_SESSION['old_id'] ?? ''); ?>"
                               autocomplete="off"
                               spellcheck="false">
                        <div class="shadow__input"></div>
                    </div>

                    <div class="input__container" data-label="PASSWORD">
                        <div class="input__button__shadow">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2zm3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/>
                            </svg>
                        </div>
                        <input type="password" 
                               class="input__search" 
                               id="password" 
                               name="password"
                               placeholder="Password"
                               autocomplete="off"
                               data-lpignore="true"
                               data-form-type="other">
                        <div class="password-toggle" id="togglePassword">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-eye" viewBox="0 0 16 16">
                                <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/>
                                <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>
                            </svg>
                        </div>
                        <div class="shadow__input"></div>
                    </div>

                    <div class="mb-3">
                        <button type="submit" class="button">
                            <span class="button__text">Login</span>
                        </button>
                    </div>
                    <div class="text-center">
                        <button type="button" class="recover-button" data-bs-toggle="modal" data-bs-target="#recoveryModal">
                            Recover My Account
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Recovery Account Modal -->
    <div class="modal fade" id="recoveryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Account Recovery</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="verifyForm">
                        <div class="input__container mb-3" data-label="ID NUMBER">
                    <div class="input__button__shadow">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                            <path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm2-3a2 2 0 1 1-4 0 2 2 0 0 1 4 0zm4 8c0 1-1 1-1 1H3s-1 0-1-1 1-4 6-4 6 3 6 4zm-1-.004c-.001-.246-.154-.986-.832-1.664C11.516 10.68 10.289 10 8 10c-2.29 0-3.516.68-4.168 1.332-.678.678-.83 1.418-.832 1.664h10z"/>
                        </svg>
                    </div>
                            <input type="text" class="input__search" id="recovery_id" placeholder="ID" required>
                    <div class="shadow__input"></div>
                </div>
                        <div class="input__container mb-3" data-label="BIRTHDATE">
                    <div class="input__button__shadow">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                    <path d="M4 .5a.5.5 0 0 0-1 0V1H2a2 2 0 0 0-2 2v1h16V3a2 2 0 0 0-2-2h-1V.5a.5.5 0 0 0-1 0V1H4V.5zM16 14V5H0v9a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2zm-6.664-1.21c-1.11 0-1.656-.767-1.703-1.407h.683c.043.37.387.82 1.051.82.844 0 1.301-.848 1.305-2.164h-.027c-.153.414-.637.79-1.383.79-.852 0-1.676-.61-1.676-1.77 0-1.137.871-1.809 1.797-1.809 1.172 0 1.953.734 1.953 2.668 0 1.805-.742 2.871-2 2.871zm.066-2.544c.625 0 1.184-.484 1.184-1.18 0-.832-.527-1.23-1.16-1.23-.586 0-1.168.387-1.168 1.21 0 .817.543 1.2 1.144 1.2zm-2.957-2.89v5.332H5.77v-4.61h-.012c-.29.156-.883.52-1.258.777V8.16a12.6 12.6 0 0 1 1.313-.805h.632z"/>
                        </svg>
                    </div>
                            <input type="text" class="input__search" id="birthdate" placeholder="yyyy/mm/dd" inputmode="numeric" required>
                    <div class="shadow__input"></div>
                </div>
                        <div class="d-grid">
                            <button type="submit" class="button">
                                <span class="button__text">Verify Account</span>
                            </button>
                        </div>
                    </form>

                    <!-- Email Verification Modal (Initially Hidden) -->
                    <div id="emailVerificationForm" style="display: none;">
                        <div class="verification-status text-center mb-4">
                            <i class="bi bi-shield-lock" style="font-size: 3em; color: #198754; margin-bottom: 0.5rem;"></i>
                        </div>
                        <div class="text-center mb-4">
                            <p class="mb-2">A verification code has been sent to your email address:</p>
                            <p class="fw-bold mb-3" style="color: #198754;" id="userEmailDisplay">Loading...</p>
                            <p class="text-muted">Please enter the 6-digit code to proceed with password reset.</p>
                        </div>
                        
                        <form id="codeVerificationForm">
                            <div class="code-input-group mb-3">
                                <input type="text" class="code-input" maxlength="1" pattern="\d" required>
                                <input type="text" class="code-input" maxlength="1" pattern="\d" required>
                                <input type="text" class="code-input" maxlength="1" pattern="\d" required>
                                <input type="text" class="code-input" maxlength="1" pattern="\d" required>
                                <input type="text" class="code-input" maxlength="1" pattern="\d" required>
                                <input type="text" class="code-input" maxlength="1" pattern="\d" required>
                                <input type="hidden" name="code" id="codeInput">
                            </div>
                            <div class="d-grid gap-2 mb-3">
                                <button type="submit" class="button" id="verifyCodeButton">
                                    <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                                    <span class="btn-text">Verify Code</span>
                                </button>
                            </div>
                        </form>

                        <div class="text-center mt-4">
                            <p class="mb-2">Didn't receive the code?</p>
                            <a href="javascript:void(0)" class="resend-link" id="resendCodeLink">Resend Code</a>
                            <div class="timer d-none" id="timerText">Resend available in <span id="timer">60</span>s</div>
                            <p class="text-muted mt-2">The code will expire in 30 minutes</p>
                        </div>
                    </div>

                    <!-- Password Reset Form (Initially Hidden) -->
                    <form id="resetForm" style="display: none;">
                        <input type="hidden" id="verified_id" name="verified_id">
                        <div class="mb-3">
                            <label for="new_password" class="form-label">NEW PASSWORD</label>
                            <div class="position-relative">
                                <input type="password" class="input__search" id="new_password" required>
                                <div class="password-toggle" id="toggleNewPassword">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-eye" viewBox="0 0 16 16">
                                        <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/>
                                        <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>
                                    </svg>
                                </div>
                            </div>
                            <div class="pw-strength-wrap my-2">
                                <div class="progress" style="height: 12px; border-radius: 8px;">
                                    <div id="pw-strength-bar" class="progress-bar" role="progressbar" style="border-radius: 8px;"></div>
                                </div>
                                <div id="pw-strength-text" class="mt-1 fw-bold"></div>
                            </div>
                            <ul class="list-unstyled small mt-2" id="pw-requirements" style="margin-bottom:0;">
                                <li id="pw-length"><span class="pw-icon">❌</span> At least 8 characters</li>
                                <li id="pw-upper"><span class="pw-icon">❌</span> Uppercase letter</li>
                                <li id="pw-lower"><span class="pw-icon">❌</span> Lowercase letter</li>
                                <li id="pw-number"><span class="pw-icon">❌</span> Number</li>
                                <li id="pw-special"><span class="pw-icon">❌</span> Special character</li>
                            </ul>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">CONFIRM PASSWORD</label>
                            <div class="position-relative">
                                <input type="password" class="input__search" id="confirm_password" required>
                                <div class="password-toggle" id="toggleConfirmPassword">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-eye" viewBox="0 0 16 16">
                                        <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/>
                                        <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>
                                    </svg>
                                </div>
                            </div>
                            <div class="invalid-feedback">Passwords do not match</div>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="showPasswordCheck">
                            <label class="form-check-label" for="showPasswordCheck">
                                Show Password
                            </label>
                        </div>
                        <button type="submit" class="button">
                            <span class="button__text">Reset Password</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <style>
    .recover-button {
        background: #fff;
        border: 1px solid #dee2e6;
        color: #333;
        font-size: 14px;
        padding: 6px 16px;
        border-radius: 4px;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    
    .recover-button:hover {
        background-color: #f8f9fa;
        border-color: #ced4da;
    }

    .modal-content {
        background: #fff;
        border-radius: 15px;
        border: none;
        box-shadow: 0 0 20px rgba(0,0,0,0.1);
    }

    .modal-header {
        background: #f8f9fa;
        border-radius: 15px 15px 0 0;
        border-bottom: 1px solid #eee;
        padding: 1rem 1.5rem;
    }

    .modal-title {
        color: #333;
        font-weight: 600;
        font-size: 1.25rem;
    }

    .modal-body {
        padding: 1.5rem;
    }

    .button {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        font-family: inherit;
        font-size: 13px;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        color: #fff;
        background-color: #28a745;
        border-style: solid;
        border-width: 2px;
        border-color: transparent;
        border-radius: 6px;
        padding: 0.75rem 1.5rem;
        width: 100%;
        transition: all 0.3s ease;
    }

    .button:hover {
        background-color: #218838;
    }

    .code-input-group {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin-bottom: 1.5rem;
    }

    .code-input {
        width: 40px;
        height: 50px;
        text-align: center;
        font-size: 1.5em;
        border: 2px solid #ddd;
        border-radius: 8px;
        margin: 0 2px;
        transition: all 0.3s ease;
    }

    .code-input:focus {
        border-color: #198754;
        box-shadow: 0 0 0 0.2rem rgba(25, 135, 84, 0.25);
        outline: none;
    }

    .resend-link {
        color: #0f5132;
        text-decoration: none;
        cursor: pointer;
        transition: color 0.3s ease;
    }

    .resend-link:hover {
        color: #198754;
    }

    .resend-link.disabled {
        color: #858796;
        cursor: not-allowed;
        pointer-events: none;
    }

    .timer {
        color: #858796;
        font-size: 0.9em;
        margin-top: 5px;
    }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/login.js?v=<?php echo time(); ?>"></script>
    <script>
        // Function to create and show alert
        function showAlert(message, type = 'danger') {
            // Remove any existing alerts
            const existingAlerts = document.querySelectorAll('.alert-fixed-top');
            existingAlerts.forEach(alert => alert.remove());

            // Create new alert
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-fixed-top fade show`;
            alertDiv.style.position = 'fixed';
            alertDiv.style.top = '30px';
            alertDiv.style.left = '50%';
            alertDiv.style.transform = 'translateX(-50%)';
            alertDiv.style.zIndex = '20000';
            alertDiv.style.minWidth = '320px';
            alertDiv.style.maxWidth = '90vw';
            alertDiv.style.padding = '16px 32px 16px 20px';
            alertDiv.style.borderRadius = '12px';
            alertDiv.style.boxShadow = '0 4px 24px rgba(0,0,0,0.12)';
            alertDiv.style.fontSize = '1.08rem';
            alertDiv.style.fontWeight = '600';
            alertDiv.style.display = 'flex';
            alertDiv.style.alignItems = 'center';
            alertDiv.style.gap = '12px';
            alertDiv.style.border = 'none';
            alertDiv.style.opacity = '0.98';
            if (type === 'danger') {
                alertDiv.style.background = 'linear-gradient(90deg, #ff4e4e 0%, #ff7b7b 100%)';
                alertDiv.style.color = '#fff';
                alertDiv.innerHTML = `<span class='alert-icon'>&#9888;</span><span>${message}</span>`;
            } else {
                alertDiv.style.background = 'linear-gradient(90deg, #28a745 0%, #5be584 100%)';
                alertDiv.style.color = '#fff';
                alertDiv.innerHTML = `<span class='alert-icon'>&#10003;</span><span>${message}</span>`;
            }
            document.body.appendChild(alertDiv);
            // Auto dismiss after 3 seconds
            setTimeout(() => {
                alertDiv.classList.remove('show');
                setTimeout(() => alertDiv.remove(), 150);
            }, 3000);
        }

        // Hide loading screen when page is loaded
        document.addEventListener('DOMContentLoaded', function() {
            const loadingContainer = document.querySelector('.loading-container');
            loadingContainer.classList.remove('show');

            // Add event listener for modal close
            const recoveryModal = document.getElementById('recoveryModal');
            recoveryModal.addEventListener('hidden.bs.modal', function () {
                // Reset verify form
                document.getElementById('verifyForm').style.display = 'block';
                document.getElementById('emailVerificationForm').style.display = 'none';
                document.getElementById('resetForm').style.display = 'none';
                
                // Clear form inputs
                document.getElementById('recovery_id').value = '';
                document.getElementById('birthdate').value = '';
                document.getElementById('new_password').value = '';
                document.getElementById('confirm_password').value = '';
                document.getElementById('confirm_password').classList.remove('is-invalid');
                
                // Clear code inputs
                document.querySelectorAll('.code-input').forEach(input => input.value = '');
                
                // Reset verification button state
                const verifyButton = document.getElementById('verifyCodeButton');
                if (verifyButton) {
                    const spinner = verifyButton.querySelector('.spinner-border');
                    const btnText = verifyButton.querySelector('.btn-text');
                    verifyButton.disabled = false;
                    if (spinner) spinner.classList.add('d-none');
                    if (btnText) btnText.textContent = 'Verify Code';
                }
                
                // Clear any timers
                if (cooldownTimer) {
                    clearInterval(cooldownTimer);
                    cooldownTimer = null;
                }
                
                // Reset recovery user data
                recoveryUserData = null;
            });

            // Auto-dismiss PHP-generated alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.classList.remove('show');
                    setTimeout(() => alert.remove(), 150);
                }, 3000);
            });
        });

        // Show loading screen before unload
        window.addEventListener('beforeunload', function() {
            const loadingContainer = document.querySelector('.loading-container');
            loadingContainer.classList.add('show');
        });

        // Show loading screen before form submission
        document.querySelector('form').addEventListener('submit', function() {
            const loadingContainer = document.querySelector('.loading-container');
            loadingContainer.classList.add('show');
        });

        document.getElementById('verifyForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const id = document.getElementById('recovery_id').value;
            let birthdate = document.getElementById('birthdate').value.trim();
            // Accept yyyy/mm/dd or yyyy-mm-dd; normalize to yyyy-mm-dd
            birthdate = birthdate.replace(/\//g, '-');
            // Basic validation for yyyy-mm-dd
            if (!/^\d{4}-\d{2}-\d{2}$/.test(birthdate)) {
                showAlert('Please use date format: yyyy/mm/dd');
                return;
            }

            // Send verification request
            fetch('auth/verify_account.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    id: id,
                    birthdate: birthdate
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Store the verified ID
                    document.getElementById('verified_id').value = document.getElementById('recovery_id').value;
                    // Store user data for code sending
                    recoveryUserData = data.user_data;
                    // Send email code and show verification form
                    fetch('auth/recovery_send_code_direct.php', { 
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            user_data: data.user_data
                        })
                    })
                        .then(r => r.json())
                        .then(s => {
                            if (s.success) {
                                // Update email indicator
                                document.getElementById('userEmailDisplay').textContent = s.email || 'your email';
                                // Hide verify form and show email verification form
                                document.getElementById('verifyForm').style.display = 'none';
                                document.getElementById('emailVerificationForm').style.display = 'block';
                                
                                // Reset button state
                                const button = document.getElementById('verifyCodeButton');
                                const spinner = button.querySelector('.spinner-border');
                                const btnText = button.querySelector('.btn-text');
                                button.disabled = false;
                                spinner.classList.add('d-none');
                                btnText.textContent = 'Verify Code';
                                
                                // Clear code inputs
                                document.querySelectorAll('.code-input').forEach(input => input.value = '');
                                
                                startCooldown(60);
                            } else {
                                showAlert(s.message || 'Failed to send verification code.');
                            }
                        })
                        .catch(() => showAlert('Failed to send verification code.'));
                } else {
                    showAlert('Invalid ID or birthdate. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('An error occurred. Please try again.');
            });
        });

        // Email verification code handling
        const codeInputs = document.querySelectorAll('.code-input');
        const codeInput = document.getElementById('codeInput');
        let cooldownTimer = null;
        let recoveryUserData = null;

        // Handle code inputs
        codeInputs.forEach((input, index) => {
            input.addEventListener('input', function() {
                if (this.value.length === 1) {
                    if (index < codeInputs.length - 1) {
                        codeInputs[index + 1].focus();
                    }
                }
                updateHiddenInput();
            });

            input.addEventListener('keydown', function(e) {
                if (e.key === 'Backspace' && !this.value && index > 0) {
                    codeInputs[index - 1].focus();
                }
            });
        });

        function updateHiddenInput() {
            const code = Array.from(codeInputs).map(input => input.value).join('');
            codeInput.value = code;
        }

        // Function to start cooldown timer
        function startCooldown(seconds) {
            const resendLink = document.getElementById('resendCodeLink');
            const timerText = document.getElementById('timerText');
            
            resendLink.classList.add('disabled');
            timerText.classList.remove('d-none');
            
            let timeLeft = seconds;
            
            // Update timer immediately
            updateTimerDisplay(timeLeft);
            
            if (cooldownTimer) clearInterval(cooldownTimer);
            
            cooldownTimer = setInterval(() => {
                timeLeft--;
                updateTimerDisplay(timeLeft);
                
                if (timeLeft <= 0) {
                    clearInterval(cooldownTimer);
                    resendLink.classList.remove('disabled');
                    timerText.classList.add('d-none');
                }
            }, 1000);
        }

        // Function to update timer display
        function updateTimerDisplay(timeLeft) {
            const timerText = document.getElementById('timerText');
            timerText.textContent = `Resend available in ${timeLeft}s`;
        }

        // Handle resend code
        document.getElementById('resendCodeLink').addEventListener('click', function(e) {
            e.preventDefault();
            
            if (this.classList.contains('disabled')) return;
            
            if (!recoveryUserData) {
                showAlert('Session expired. Please verify your account again.');
                return;
            }
            
            // Disable the resend link immediately
            this.classList.add('disabled');
            document.getElementById('timerText').classList.remove('d-none');
            
            // Send request to resend code
            fetch('auth/recovery_send_code_direct.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    user_data: recoveryUserData,
                    is_resend: true
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Start cooldown only after successful send
                    startCooldown(60);
                    showAlert('A new verification code has been sent to your email.', 'success');
                } else {
                    // If there's an error, re-enable the resend link
                    this.classList.remove('disabled');
                    document.getElementById('timerText').classList.add('d-none');
                    showAlert(data.message || 'Failed to send verification code. Please try again.');
                }
            })
            .catch(error => {
                // If there's an error, re-enable the resend link
                this.classList.remove('disabled');
                document.getElementById('timerText').classList.add('d-none');
                showAlert('Failed to send verification code. Please try again.');
            });
        });

        // Handle code verification form submission
        document.getElementById('codeVerificationForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const button = document.getElementById('verifyCodeButton');
            const spinner = button.querySelector('.spinner-border');
            const btnText = button.querySelector('.btn-text');
            
            // Show loading state
            button.disabled = true;
            spinner.classList.remove('d-none');
            btnText.textContent = 'Verifying...';
            
            fetch('auth/recovery_verify_code.php', {
                method: 'POST',
                body: new FormData(this),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success state
                    document.querySelector('.verification-status i').classList.replace('bi-shield-lock', 'bi-shield-check');
                    document.querySelector('.verification-status i').style.color = '#0f5132';
                    
                    showAlert('Code verified successfully! You can now reset your password.', 'success');
                    
                    // Hide email verification form and show reset form
                    document.getElementById('emailVerificationForm').style.display = 'none';
                    document.getElementById('resetForm').style.display = 'block';
                } else {
                    // Show error
                    showAlert(data.message || 'Invalid or expired verification code.');
                    
                    // Reset form
                    codeInputs.forEach(input => input.value = '');
                    codeInputs[0].focus();
                    
                    // Reset button state
                    button.disabled = false;
                    spinner.classList.add('d-none');
                    btnText.textContent = 'Verify Code';
                }
            })
            .catch(() => {
                // Handle error
                button.disabled = false;
                spinner.classList.add('d-none');
                btnText.textContent = 'Verify Code';
                showAlert('Failed to verify code. Please try again.');
            });
        });

        document.getElementById('resetForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (newPassword !== confirmPassword) {
                document.getElementById('confirm_password').classList.add('is-invalid');
                showAlert('Passwords do not match');
                return;
            }

            if (newPassword.length < 8) {
                showAlert('Password must be at least 8 characters long');
                return;
            }

            // Send password reset request
            fetch('auth/reset_password.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    id: document.getElementById('verified_id').value,
                    password: newPassword
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    // Close the modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('recoveryModal'));
                    modal.hide();
                    // Reset forms
                    document.getElementById('verifyForm').reset();
                    document.getElementById('resetForm').reset();
                } else {
                    showAlert(data.message || 'Failed to reset password. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('An error occurred. Please try again.');
            });
        });

        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            if (this.value !== newPassword) {
                this.classList.add('is-invalid');
            } else {
                this.classList.remove('is-invalid');
            }
        });

        // Password strength indicator for recovery reset (improved)
        const pwInput = document.getElementById('new_password');
        const pwStrengthBar = document.getElementById('pw-strength-bar');
        const pwStrengthText = document.getElementById('pw-strength-text');
        const reqLength = document.getElementById('pw-length');
        const reqUpper = document.getElementById('pw-upper');
        const reqLower = document.getElementById('pw-lower');
        const reqNumber = document.getElementById('pw-number');
        const reqSpecial = document.getElementById('pw-special');

        function updateReq(el, passed) {
            el.className = passed ? 'passed' : '';
            el.querySelector('.pw-icon').textContent = passed ? '✔️' : '❌';
        }
        if (pwInput) {
            pwInput.addEventListener('input', function() {
                const val = pwInput.value;
                let score = 0;
                // Requirements
                const hasLength = val.length >= 8;
                const hasUpper = /[A-Z]/.test(val);
                const hasLower = /[a-z]/.test(val);
                const hasNumber = /[0-9]/.test(val);
                const hasSpecial = /[^A-Za-z0-9]/.test(val);
                // Update checklist with icons and color
                updateReq(reqLength, hasLength);
                updateReq(reqUpper, hasUpper);
                updateReq(reqLower, hasLower);
                updateReq(reqNumber, hasNumber);
                updateReq(reqSpecial, hasSpecial);
                // Scoring
                score += hasLength ? 1 : 0;
                score += hasUpper ? 1 : 0;
                score += hasLower ? 1 : 0;
                score += hasNumber ? 1 : 0;
                score += hasSpecial ? 1 : 0;
                // Strength bar and text
                let strength = '';
                let barClass = '';
                let barWidth = 0;
                if (score <= 2) {
                    strength = 'Weak';
                    barClass = 'bg-danger';
                    barWidth = 30;
                } else if (score === 3 || score === 4) {
                    strength = 'Medium';
                    barClass = 'bg-warning';
                    barWidth = 70;
                } else if (score === 5) {
                    strength = 'Strong';
                    barClass = 'bg-success';
                    barWidth = 100;
                }
                pwStrengthText.textContent = strength;
                pwStrengthText.className = 'fw-bold ' + (score <= 2 ? 'text-danger' : (score === 3 || score === 4 ? 'text-warning' : 'text-success'));
                pwStrengthBar.className = 'progress-bar ' + barClass;
                pwStrengthBar.style.width = barWidth + '%';
            });
        }

        // Password toggle functionality for recovery modal
        document.getElementById('toggleNewPassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('new_password');
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            // Toggle eye icon
            const eyeIcon = this.querySelector('svg');
            if (type === 'password') {
                // Show the "eye" icon (password is hidden)
                eyeIcon.innerHTML = '<path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/><path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>';
            } else {
                // Show the "eye-slash" icon (password is visible)
                eyeIcon.innerHTML = '<path d="M13.359 11.238C15.06 9.72 16 8 16 8s-3-5.5-8-5.5a7.028 7.028 0 0 0-2.79.588l.77.771A5.944 5.944 0 0 1 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.134 13.134 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755-.165.165-.337.328-.517.486l.708.709z"/><path d="M11.297 9.176a3.5 3.5 0 0 0-4.474-4.474l.823.823a2.5 2.5 0 0 1 2.829 2.829l.822.822zm-2.943 1.299.822.822a3.5 3.5 0 0 1-4.474-4.474l.823.823a2.5 2.5 0 0 0 2.829 2.829z"/><path d="M3.35 5.47c-.18.16-.353.322-.518.487A13.134 13.134 0 0 0 1.172 8l.195.288c.335.48.83 1.12 1.465 1.755C4.121 11.332 5.881 12.5 8 12.5c.716 0 1.39-.133 2.02-.36l.77.772A7.029 7.029 0 0 1 8 13.5C3 13.5 0 8 0 8s.939-1.721 2.641-3.238l.708.709zm10.296 8.884-12-12 .708-.708 12 12-.708.708z"/>';
            }
        });

        document.getElementById('toggleConfirmPassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('confirm_password');
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            // Toggle eye icon
            const eyeIcon = this.querySelector('svg');
            if (type === 'password') {
                // Show the "eye" icon (password is hidden)
                eyeIcon.innerHTML = '<path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/><path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>';
            } else {
                // Show the "eye-slash" icon (password is visible)
                eyeIcon.innerHTML = '<path d="M13.359 11.238C15.06 9.72 16 8 16 8s-3-5.5-8-5.5a7.028 7.028 0 0 0-2.79.588l.77.771A5.944 5.944 0 0 1 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.134 13.134 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755-.165.165-.337.328-.517.486l.708.709z"/><path d="M11.297 9.176a3.5 3.5 0 0 0-4.474-4.474l.823.823a2.5 2.5 0 0 1 2.829 2.829l.822.822zm-2.943 1.299.822.822a3.5 3.5 0 0 1-4.474-4.474l.823.823a2.5 2.5 0 0 0 2.829 2.829z"/><path d="M3.35 5.47c-.18.16-.353.322-.518.487A13.134 13.134 0 0 0 1.172 8l.195.288c.335.48.83 1.12 1.465 1.755C4.121 11.332 5.881 12.5 8 12.5c.716 0 1.39-.133 2.02-.36l.77.772A7.029 7.029 0 0 1 8 13.5C3 13.5 0 8 0 8s.939-1.721 2.641-3.238l.708.709zm10.296 8.884-12-12 .708-.708 12 12-.708.708z"/>';
            }
        });

        document.getElementById('showPasswordCheck').addEventListener('change', function() {
            const type = this.checked ? 'text' : 'password';
            document.getElementById('new_password').type = type;
            document.getElementById('confirm_password').type = type;
            
            // Update eye icons to match checkbox state
            const newPasswordToggle = document.getElementById('toggleNewPassword');
            const confirmPasswordToggle = document.getElementById('toggleConfirmPassword');
            
            if (newPasswordToggle) {
                const eyeIcon = newPasswordToggle.querySelector('svg');
                if (type === 'password') {
                    eyeIcon.innerHTML = '<path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/><path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>';
                } else {
                    eyeIcon.innerHTML = '<path d="M13.359 11.238C15.06 9.72 16 8 16 8s-3-5.5-8-5.5a7.028 7.028 0 0 0-2.79.588l.77.771A5.944 5.944 0 0 1 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.134 13.134 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755-.165.165-.337.328-.517.486l.708.709z"/><path d="M11.297 9.176a3.5 3.5 0 0 0-4.474-4.474l.823.823a2.5 2.5 0 0 1 2.829 2.829l.822.822zm-2.943 1.299.822.822a3.5 3.5 0 0 1-4.474-4.474l.823.823a2.5 2.5 0 0 0 2.829 2.829z"/><path d="M3.35 5.47c-.18.16-.353.322-.518.487A13.134 13.134 0 0 0 1.172 8l.195.288c.335.48.83 1.12 1.465 1.755C4.121 11.332 5.881 12.5 8 12.5c.716 0 1.39-.133 2.02-.36l.77.772A7.029 7.029 0 0 1 8 13.5C3 13.5 0 8 0 8s.939-1.721 2.641-3.238l.708.709zm10.296 8.884-12-12 .708-.708 12 12-.708.708z"/>';
                }
            }
            
            if (confirmPasswordToggle) {
                const eyeIcon = confirmPasswordToggle.querySelector('svg');
                if (type === 'password') {
                    eyeIcon.innerHTML = '<path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/><path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>';
                } else {
                    eyeIcon.innerHTML = '<path d="M13.359 11.238C15.06 9.72 16 8 16 8s-3-5.5-8-5.5a7.028 7.028 0 0 0-2.79.588l.77.771A5.944 5.944 0 0 1 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.134 13.134 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755-.165.165-.337.328-.517.486l.708.709z"/><path d="M11.297 9.176a3.5 3.5 0 0 0-4.474-4.474l.823.823a2.5 2.5 0 0 1 2.829 2.829l.822.822zm-2.943 1.299.822.822a3.5 3.5 0 0 1-4.474-4.474l.823.823a2.5 2.5 0 0 0 2.829 2.829z"/><path d="M3.35 5.47c-.18.16-.353.322-.518.487A13.134 13.134 0 0 0 1.172 8l.195.288c.335.48.83 1.12 1.465 1.755C4.121 11.332 5.881 12.5 8 12.5c.716 0 1.39-.133 2.02-.36l.77.772A7.029 7.029 0 0 1 8 13.5C3 13.5 0 8 0 8s.939-1.721 2.641-3.238l.708.709zm10.296 8.884-12-12 .708-.708 12 12-.708.708z"/>';
                }
            }
        });
    </script>
</body>
</html> 