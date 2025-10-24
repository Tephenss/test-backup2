<?php
session_start();
require_once '../config/database.php';
require_once '../helpers/EmailVerification.php';

// Initialize error array
$errors = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validate inputs
    if (empty($username)) {
        $errors[] = "Username is required";
    } elseif (strlen($username) < 3 || strlen($username) > 50) {
        $errors[] = "Username must be between 3 and 50 characters";
    }

    if (empty($password)) {
        $errors[] = "Password is required";
    }

    // If no validation errors, proceed with login
    if (empty($errors)) {
        try {
            // Check each user type table for the username
            $user_found = false;
            $user_type = '';
            $user_data = null;

            // Check admin table
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->rowCount() > 0) {
                $user_data = $stmt->fetch();
                $user_type = 'admin';
                $user_found = true;
            }

            // Check teacher table if not found in admin
            if (!$user_found) {
                $stmt = $pdo->prepare("SELECT * FROM teachers WHERE username = ?");
                $stmt->execute([$username]);
                if ($stmt->rowCount() > 0) {
                    $user_data = $stmt->fetch();
                    $user_type = 'teacher';
                    $user_found = true;
                }
            }

            // Check student table if not found in admin or teacher
            if (!$user_found) {
                $stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = ?");
                $stmt->execute([$username]);
                if ($stmt->rowCount() > 0) {
                    $user_data = $stmt->fetch();
                    $user_type = 'student';
                    $user_found = true;
                }
            }

            if ($user_found && password_verify($password, $user_data['password'])) {
                // For students, check if section is assigned
                if ($user_type === 'student' && empty($user_data['section'])) {
                    $errors[] = "Please wait for section assignment.";
                    
                    // Log failed login attempt
                    try {
                        $log_stmt = $pdo->prepare("INSERT INTO login_logs (username, user_type, status, ip_address) VALUES (?, ?, 'failed', ?)");
                        $log_stmt->execute([$username, $user_type, $_SERVER['REMOTE_ADDR']]);
                    } catch(PDOException $e) {
                        error_log("Failed to log failed login: " . $e->getMessage());
                    }
                } else {
                    // Store user data temporarily
                    $_SESSION['temp_user_data'] = [
                        'id' => $user_data['id'],
                        'username' => $user_data['student_id'], // Use student_id as username
                        'user_type' => $user_type,
                        'full_name' => $user_data['first_name'] . ' ' . $user_data['last_name'],
                        'email' => $user_data['email']
                    ];

                    // Generate and send verification code
                    try {
                        $verification = new EmailVerification();
                        if ($verification->generateCode($user_data['id'], $user_type, $user_data['email'])) {
                            $_SESSION['pending_verification'] = true;
                            header("Location: verify.php");
                            exit();
                        } else {
                            $errorMessage = $verification->getLastError() ?: "Failed to send verification code. Please try again.";
                            $errors[] = $errorMessage;
                            error_log("Email verification failed for user {$user_data['id']}: $errorMessage");
                        }
                    } catch (Exception $e) {
                        error_log("EmailVerification exception: " . $e->getMessage());
                        $errors[] = "Email system error. Please try again later.";
                    }
                }
            } else {
                $errors[] = "Invalid username or password";
                
                // Log failed login attempt
                try {
                    $log_stmt = $pdo->prepare("INSERT INTO login_logs (username, user_type, status, ip_address) VALUES (?, ?, 'failed', ?)");
                    $log_stmt->execute([$username, $user_type, $_SERVER['REMOTE_ADDR']]);
                } catch(PDOException $e) {
                    error_log("Failed to log failed login: " . $e->getMessage());
                }
            }
        } catch(PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }

    // If there are errors, store them in session and redirect back
    if (!empty($errors)) {
        $_SESSION['login_errors'] = $errors;
        $_SESSION['old_username'] = $username;
        header("Location: ../index.php");
        exit();
    }
} else {
    // If not POST request, redirect to login page
    header("Location: ../index.php");
    exit();
}
?>