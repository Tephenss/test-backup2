<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';
require_once '../helpers/EmailVerification.php';

// Handle only POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$username = $input['username'] ?? '';
$password = $input['password'] ?? '';

// Debug logging
error_log("Mobile Login Attempt: username='$username', password='" . substr($password, 0, 3) . "***'");

// Validate inputs
if (empty($username)) {
    echo json_encode(['success' => false, 'error' => 'Username is required']);
    exit();
}

if (empty($password)) {
    echo json_encode(['success' => false, 'error' => 'Password is required']);
    exit();
}

try {
    // Check each user type table for the username
    $user_found = false;
    $user_type = '';
    $user_data = null;
    $email = '';

    // Check admin table - search by username OR admin_id
    error_log("Checking admins table for username/admin_id: '$username'");
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? OR admin_id = ?");
    $stmt->execute([$username, $username]);
    if ($stmt->rowCount() > 0) {
        $user_data = $stmt->fetch();
        $user_type = 'admin';
        $user_found = true;
        $email = $user_data['email'] ?? $username . '@lagunauniversity.ph';
        error_log("Found in admins table: " . json_encode($user_data));
    } else {
        error_log("Not found in admins table");
    }

    // Check teacher table if not found in admin - search by teacher_id (like students)
    if (!$user_found) {
        error_log("Checking teachers table for teacher_id: '$username'");
        $stmt = $pdo->prepare("SELECT * FROM teachers WHERE teacher_id = ?");
        $stmt->execute([$username]);
        if ($stmt->rowCount() > 0) {
            $user_data = $stmt->fetch();
            $user_type = 'teacher';
            $user_found = true;
            $email = $user_data['email'] ?? $username . '@lagunauniversity.ph';
            error_log("Found in teachers table: " . json_encode($user_data));
        } else {
            error_log("Not found in teachers table");
        }
    }

    // Check student table if not found in admin or teacher
    if (!$user_found) {
        error_log("Checking students table for student_id: '$username'");
        $stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = ?");
        $stmt->execute([$username]);
        if ($stmt->rowCount() > 0) {
            $user_data = $stmt->fetch();
            $user_type = 'student';
            $user_found = true;
            $email = $user_data['email'] ?? $username . '@lagunauniversity.ph';
            error_log("Found in students table: " . json_encode($user_data));
        } else {
            error_log("Not found in students table");
        }
    }

    error_log("User found: " . ($user_found ? "YES" : "NO"));
    if ($user_found) {
        error_log("Stored password hash: " . $user_data['password']);
        error_log("Password verification result: " . (password_verify($password, $user_data['password']) ? "SUCCESS" : "FAILED"));
    }
    
    if ($user_found && password_verify($password, $user_data['password'])) {
        // For students, check if section is assigned
        if ($user_type === 'student' && empty($user_data['section'])) {
            echo json_encode([
                'success' => false, 
                'error' => 'Please wait for section assignment.'
            ]);
            exit();
        }

        // Store verification session data for mobile app
        session_start();
        $_SESSION['mobile_temp_user_data'] = [
            'id' => $user_data['id'] ?? ($user_type === 'student' ? $user_data['student_id'] : $user_data['username']),
            'username' => $user_data['student_id'] ?? $user_data['username'],
            'user_type' => $user_type,
            'full_name' => ($user_data['full_name'] ?? $user_data['first_name'] ?? '') . ' ' . ($user_data['last_name'] ?? ''),
            'email' => $email
        ];

        // Generate and send verification code
        try {
            $verification = new EmailVerification();
            // Use unique identifiers for each user type to avoid conflicts
            if ($user_type === 'admin') {
                $userId = $user_data['admin_id'] ?? $user_data['id'];
            } elseif ($user_type === 'teacher') {
                $userId = $user_data['teacher_id'] ?? $user_data['id'];
            } else { // student
                $userId = $user_data['student_id'] ?? $user_data['id'];
            }
            
            if ($verification->generateCode($userId, $user_type, $email, false)) { // Pass false for initial code
                echo json_encode([
                    'success' => true,
                    'message' => 'Verification code sent to email',
                    'user_id' => $userId,
                    'email' => $email,
                    'user_type' => $user_type,
                    'full_name' => $user_data['full_name'] ?? 'System Administrator',
                    'is_resend' => false
                ]);
                exit();
            } else {
                $errorMessage = $verification->getLastError() ?: "Failed to send verification code. Please try again.";
                echo json_encode(['success' => false, 'error' => $errorMessage]);
                exit();
            }
        } catch (Exception $e) {
            error_log("EmailVerification exception: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Email system error. Please try again later.']);
            exit();
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid username or password']);
        exit();
    }
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
    exit();
}
?>
