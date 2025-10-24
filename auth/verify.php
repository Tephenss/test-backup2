<?php
session_start();
require_once '../config/database.php';
require_once '../helpers/EmailVerification.php';

// If user is not in verification process, redirect to login
if (!isset($_SESSION['pending_verification']) || !isset($_SESSION['temp_user_data'])) {
    header("Location: ../index.php");
    exit();
}

$errors = [];
$verification = new EmailVerification();

// Ensure we have access to the database connection
global $pdo;
if (!$pdo) {
    error_log("Database connection not available");
    $errors[] = "Database connection error occurred";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $code = trim($_POST['code'] ?? '');
    
    // Check if it's a registration verification
    if (isset($_SESSION['temp_student_data'])) {
        $userData = $_SESSION['temp_student_data'];
        
        if (empty($code)) {
            $errors[] = "Verification code is required";
        } else {
            error_log("Attempting to verify registration code: " . $code . " for student ID: " . $userData['student_id']);
            
            // Verify the code
            if ($verification->verifyCode($userData['student_id'], 'student', $code)) {
                error_log("Registration code verification successful");
                
                try {
                    // Insert new student
                    $stmt = $pdo->prepare("
                        INSERT INTO students (student_id, username, password, full_name, email, course, year_level, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $stmt->execute([
                        $userData['student_id'],
                        $userData['username'],
                        $userData['password'],
                        $userData['full_name'],
                        $userData['email'],
                        $userData['course'],
                        $userData['year_level']
                    ]);
                    
                    // Clean up session
                    unset($_SESSION['pending_verification']);
                    unset($_SESSION['temp_student_data']);
                    
                    // Set success message
                    $_SESSION['success_message'] = "Registration successful! Your Student ID is: " . $userData['student_id'] . ". Please wait for the admin to assign your section before logging in.";
                    
                    // Check if it's an AJAX request
                    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => true,
                            'message' => "Registration successful! Your Student ID is: " . $userData['student_id'] . ". Please wait for the admin to assign your section before logging in.",
                            'redirect' => "../student/register.php"
                        ]);
                        exit();
                    } else {
                        // Clear the form data from session
                        $_SESSION['form_data'] = [];
                        header("Location: ../student/register.php");
                        exit();
                    }
                } catch (PDOException $e) {
                    error_log("Failed to complete registration: " . $e->getMessage());
                    $errors[] = "Registration failed. Please try again.";
                }
            } else {
                error_log("Registration code verification failed");
                $errors[] = $verification->getLastError() ?: "Invalid or expired verification code. Please check your email for the correct code.";
            }
        }
    } else {
        // Existing login verification code
    $userData = $_SESSION['temp_user_data'];

    if (empty($code)) {
        $errors[] = "Verification code is required";
    } else {
        error_log("Attempting to verify code: " . $code . " for user ID: " . $userData['id']);
        
        // Verify the code
        if ($verification->verifyCode($userData['id'], $userData['user_type'], $code)) {
            error_log("Code verification successful");
            
            // Log successful login attempt
            try {
                // Ensure database connection is available
                if (!$pdo) {
                    throw new PDOException("Database connection not available");
                }
                
                $log_stmt = $pdo->prepare("INSERT INTO login_logs (user_id, user_type, status, ip_address) VALUES (?, ?, 'success', ?)");
                $log_stmt->execute([$userData['id'], $userData['user_type'], $_SERVER['REMOTE_ADDR']]);
            } catch(PDOException $e) {
                error_log("Failed to log successful login: " . $e->getMessage());
                // Continue with the login process even if logging fails
            }

            // Set session variables
            $_SESSION['user_id'] = $userData['id'];
            $_SESSION['username'] = $userData['username'];
            $_SESSION['user_type'] = $userData['user_type'];
            $_SESSION['full_name'] = $userData['full_name'];

            // Clean up session
            unset($_SESSION['pending_verification']);
            unset($_SESSION['temp_user_data']);

            // Set success message
            $_SESSION['success_message'] = "Welcome back, " . htmlspecialchars($userData['full_name']) . "! You have successfully logged in.";

            // Check if it's an AJAX request
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                // For AJAX requests, send JSON response
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'redirect' => "../{$userData['user_type']}/dashboard.php"
                ]);
                exit();
            } else {
                // For regular form submissions, redirect
            header("Location: ../{$userData['user_type']}/dashboard.php");
            exit();
            }
        } else {
            error_log("Code verification failed");
            $errors[] = $verification->getLastError() ?: "Invalid or expired verification code. Please check your email for the correct code.";
            
            // Check if it's an AJAX request
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                // For AJAX requests, send JSON response
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'errors' => $errors
                ]);
                exit();
                }
            }
        }
    }
}

// Store errors in session if any
if (!empty($errors)) {
    $_SESSION['verification_errors'] = $errors;
}

// If it's an AJAX request and we haven't handled it yet, send error response
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'errors' => $errors
    ]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - iAttendance</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/verification.css" rel="stylesheet">
        <style>
        .verification-code {
            letter-spacing: 0.5em;
            text-align: center;
            font-size: 1.5em;
            padding: 0.5em;
            border: 2px solid #ddd;
            border-radius: 8px;
            width: 100%;
            max-width: 200px;
            margin: 0 auto;
            display: block;
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
        }
        .verification-status {
            text-align: center;
            margin-bottom: 1rem;
        }
        .verification-status i {
            font-size: 3em;
            margin-bottom: 0.5rem;
            transition: color 0.3s ease;
        }
        .success-icon {
            color: #0f5132;
        }
        .pending-icon {
            color: #198754;
        }
        .btn-primary {
            background-color: #198754;
            border-color: #198754;
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            background-color: #0f5132;
            border-color: #0f5132;
        }
        .btn-primary:focus {
            box-shadow: 0 0 0 0.2rem rgba(25, 135, 84, 0.25);
        }
        .text-success {
            color: #198754 !important;
        }
        .card {
            border-color: #e3e6f0;
            transition: all 0.3s ease;
            border-radius: 15px;
            overflow: hidden;
        }
        .card:hover {
            border-color: #198754;
            box-shadow: 0 0 15px rgba(25, 135, 84, 0.1);
        }
        .card-header {
            background-color: #fff;
            border-bottom: 1px solid #e3e6f0;
            padding: 1.5rem 1.5rem;
        }
        
        .text-success {
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
                <div class="card">
                    <div class="card-header">
                <h4>Email Verification</h4>
                <p class="mb-0"><span class="text-success">iAttendance Management System</span></p>
                    </div>
                    <div class="card-body">
                        <?php if (isset($_SESSION['verification_errors'])): ?>
                            <div class="alert alert-danger">
                                <?php 
                                foreach ($_SESSION['verification_errors'] as $error) {
                                    echo htmlspecialchars($error) . "<br>";
                                }
                                unset($_SESSION['verification_errors']);
                                ?>
                            </div>
                        <?php endif; ?>

                <?php if (isset($_SESSION['verification_success'])): ?>
                            <div class="alert alert-success" style="background-color: #d1e7dd; color: #0f5132; border: 1px solid #badbcc;">
                                <div class="d-flex align-items-center">
                                    <div class="me-3">
                                        <i class="bi bi-check-circle-fill" style="color: #0f5132;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                        <?php 
                        echo htmlspecialchars($_SESSION['verification_success']);
                        unset($_SESSION['verification_success']);
                        ?>
                                    </div>
                                </div>
                    </div>
                <?php endif; ?>

                <div class="verification-status">
                    <i class="bi bi-shield-lock pending-icon"></i>
                </div>

                <div class="text-center mb-4">
                    <p class="mb-2">A verification code has been sent to your email address:</p>
                    <p class="text-success fw-bold mb-3"><?php 
                        if (isset($_SESSION['temp_student_data'])) {
                            echo htmlspecialchars($_SESSION['temp_student_data']['email']);
                        } else {
                            echo htmlspecialchars($_SESSION['temp_user_data']['email'] ?? '');
                        }
                    ?></p>
                    <p class="text-muted">Please enter the 6-digit code to complete your <?php echo isset($_SESSION['temp_student_data']) ? 'registration' : 'login'; ?>.</p>
                </div>
                
                <form method="POST" action="verify.php" id="verificationForm">
                    <div class="code-input-group">
                        <input type="text" class="code-input" maxlength="1" pattern="\d" required>
                        <input type="text" class="code-input" maxlength="1" pattern="\d" required>
                        <input type="text" class="code-input" maxlength="1" pattern="\d" required>
                        <input type="text" class="code-input" maxlength="1" pattern="\d" required>
                        <input type="text" class="code-input" maxlength="1" pattern="\d" required>
                        <input type="text" class="code-input" maxlength="1" pattern="\d" required>
                        <input type="hidden" name="code" id="codeInput">
                            </div>
                            <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" id="verifyButton">
                            <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                            <span class="btn-text">Verify Code</span>
                        </button>
                            </div>
                        </form>

                        <div class="text-center mt-4">
                    <p class="mb-2">Didn't receive the code?</p>
                    <a href="javascript:void(0)" class="resend-link" id="resendLink">Resend Code</a>
                    <div class="timer d-none" id="timerText">Resend available in <span id="timer">60</span>s</div>
                    <p class="text-muted mt-2">The code will expire in 30 minutes</p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Handle code input fields
        const codeInputs = document.querySelectorAll('.code-input');
        const codeInput = document.getElementById('codeInput');
        let cooldownTimer = null;
        
        // Get user ID from PHP session
        const userId = <?php 
            if (isset($_SESSION['temp_student_data'])) {
                echo json_encode($_SESSION['temp_student_data']['student_id']);
            } else {
                echo json_encode($_SESSION['temp_user_data']['id'] ?? '');
            }
        ?>;
        const userType = <?php 
            if (isset($_SESSION['temp_student_data'])) {
                echo json_encode('student');
            } else {
                echo json_encode($_SESSION['temp_user_data']['user_type'] ?? '');
            }
        ?>;
        const cooldownKey = `verification_cooldown_${userId}_${userType}`;

        // Initialize from localStorage
        document.addEventListener('DOMContentLoaded', () => {
            // Get stored cooldown for this specific user
            const cooldownEnd = localStorage.getItem(cooldownKey);
            
            if (cooldownEnd) {
                const timeLeft = Math.ceil((parseInt(cooldownEnd) - Date.now()) / 1000);
                if (timeLeft > 0) {
                    startCooldown(timeLeft);
                } else {
                    localStorage.removeItem(cooldownKey);
                }
            }
        });

        // Function to format time
        function formatTime(seconds) {
            return `${seconds}s`;
        }

        // Function to create and show alert
        function showAlert(message, type = 'danger') {
            // Remove existing alerts
            document.querySelectorAll('.alert').forEach(alert => alert.remove());
            
            // Create new alert
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} fade show`;
            
            // Custom styling based on type
            if (type === 'success') {
                alertDiv.style.backgroundColor = '#d1e7dd';
                alertDiv.style.color = '#0f5132';
                alertDiv.style.border = '1px solid #badbcc';
            }
            
            alertDiv.innerHTML = `
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        ${type === 'success' 
                            ? '<i class="bi bi-check-circle-fill" style="color: #0f5132;"></i>' 
                            : '<i class="bi bi-exclamation-circle-fill text-danger"></i>'}
                    </div>
                    <div class="flex-grow-1">${message}</div>
                </div>
            `;
            
            // Insert alert with smooth animation
            alertDiv.style.opacity = '0';
            alertDiv.style.transform = 'translateY(-20px)';
            alertDiv.style.transition = 'all 0.3s ease-in-out';
            document.querySelector('.card-body').insertBefore(alertDiv, document.querySelector('.verification-status'));
            
            // Trigger animation
            setTimeout(() => {
                alertDiv.style.opacity = '1';
                alertDiv.style.transform = 'translateY(0)';
            }, 10);
            
            // Auto dismiss after 5 seconds with fade out animation
            setTimeout(() => {
                alertDiv.style.opacity = '0';
                alertDiv.style.transform = 'translateY(-20px)';
                setTimeout(() => alertDiv.remove(), 300);
            }, 5000);
        }

        // Function to start cooldown timer
        function startCooldown(seconds) {
            const resendLink = document.getElementById('resendLink');
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
                    localStorage.removeItem(cooldownKey);
                }
            }, 1000);
        }

        // Function to update timer display
        function updateTimerDisplay(timeLeft) {
            const timerText = document.getElementById('timerText');
            timerText.textContent = `Resend available in ${formatTime(timeLeft)}`;
        }

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

        document.getElementById('resendLink').addEventListener('click', function(e) {
            e.preventDefault();
            
            if (this.classList.contains('disabled')) return;
            
            // Disable the resend link immediately
                this.classList.add('disabled');
                document.getElementById('timerText').classList.remove('d-none');
            
            // Send request to resend code first
            fetch('resend_code.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Start cooldown only after successful send
                    const cooldownTime = 60;
                    const cooldownEnd = Date.now() + (cooldownTime * 1000);
                    localStorage.setItem(cooldownKey, cooldownEnd);
                    startCooldown(cooldownTime);
                    
                    // Show success message with green styling
                    showAlert('A new verification code has been sent to your email.', 'success');
                } else {
                    // If there's an error, re-enable the resend link
                    this.classList.remove('disabled');
                    document.getElementById('timerText').classList.add('d-none');
                    showAlert(data.error || 'Failed to send verification code. Please try again.');
                }
            })
            .catch(error => {
                // If there's an error, re-enable the resend link
                this.classList.remove('disabled');
                document.getElementById('timerText').classList.add('d-none');
                showAlert('Failed to send verification code. Please try again.');
            });
        });

        // Handle form submission
        document.getElementById('verificationForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const button = document.getElementById('verifyButton');
            const spinner = button.querySelector('.spinner-border');
            const btnText = button.querySelector('.btn-text');
            
            // Show loading state
            button.disabled = true;
            spinner.classList.remove('d-none');
            btnText.textContent = 'Verifying...';
            
            fetch('verify.php', {
                method: 'POST',
                body: new FormData(this),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success state with green styling
                    document.querySelector('.verification-status i').classList.replace('bi-shield-lock', 'bi-shield-check');
                    document.querySelector('.verification-status i').classList.replace('pending-icon', 'success-icon');
                    document.querySelector('.success-icon').style.color = '#0f5132';
                    
                    // Clear verification-related data
                    localStorage.removeItem(cooldownKey);
                    
                    // Show success message
                    if (data.message) {
                        showAlert(data.message, 'success');
                    } else {
                    showAlert('Verification successful! Redirecting...', 'success');
                    }
                    
                    // Add success animation to the card
                    const card = document.querySelector('.card');
                    card.style.transition = 'all 0.3s ease-in-out';
                    card.style.borderColor = '#badbcc';
                    card.style.boxShadow = '0 0 15px rgba(15, 81, 50, 0.1)';
                    
                    // Redirect after animation
                    setTimeout(() => {
                        window.location.href = data.redirect;
                    }, 1000);
                } else {
                    // Show error
                    showAlert(data.errors.join('<br>'));
                    
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
                this.submit();
            });
        });

        // Add entrance animation
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelector('.container').classList.add('fade-in');
            document.querySelector('.card').classList.add('scale-in');
            codeInputs[0].focus();

            // Auto-dismiss any PHP-generated alerts after 5 seconds
            document.querySelectorAll('.alert').forEach(alert => {
                setTimeout(() => {
                    alert.classList.remove('show');
                    setTimeout(() => alert.remove(), 150);
                }, 5000);
            });
        });
    </script>
</body>
</html>
