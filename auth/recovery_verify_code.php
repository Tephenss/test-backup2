<?php
session_start();
require_once '../config/database.php';
require_once '../helpers/EmailVerification.php';

header('Content-Type: application/json');

$code = trim($_POST['code'] ?? '');

$response = ['success' => false];

if (empty($code)) {
    $response['message'] = 'Verification code is required';
    echo json_encode($response);
    exit();
}

try {
    $verification = new EmailVerification();
    
    // Get user data from session
    $userId = $_SESSION['reset_user_id'] ?? null;
    $userType = $_SESSION['reset_user_type'] ?? null;
    
    if (!$userId || !$userType) {
        $response['message'] = 'Session expired. Please verify your account again.';
        echo json_encode($response);
        exit();
    }
    
    // Get the database ID for the user
    $table = match($userType) {
        'teacher' => 'teachers',
        'admin' => 'admins',
        default => 'students'
    };
    
    $idField = match($userType) {
        'teacher' => 'teacher_id',
        'admin' => 'admin_id',
        default => 'student_id'
    };
    
    $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE {$idField} = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        $response['message'] = 'User not found. Please verify your account again.';
        echo json_encode($response);
        exit();
    }
    
    $dbId = $user['id'];
    
    // Verify the code using the database ID
    if ($verification->verifyCode($dbId, $userType, $code)) {
        $response['success'] = true;
        $response['message'] = 'Code verified successfully';
    } else {
        $response['message'] = $verification->getLastError() ?: 'Invalid or expired verification code';
    }
    
} catch (Exception $e) {
    $response['message'] = 'An error occurred while verifying code';
    error_log("Error in recovery_verify_code.php: " . $e->getMessage());
}

echo json_encode($response);
?>
