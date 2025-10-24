<?php
session_start();
require_once '../config/database.php';
require_once '../helpers/EmailVerification.php';

header('Content-Type: application/json');

// Get JSON data
$data = json_decode(file_get_contents('php://input'), true);
$userData = $data['user_data'] ?? null;

$response = ['success' => false];

if (!$userData) {
    $response['message'] = 'User data not provided';
    echo json_encode($response);
    exit();
}

try {
    $verification = new EmailVerification();
    
    // Check if this is a resend request
    $isResend = isset($data['is_resend']) && $data['is_resend'] === true;
    
    // Generate and send verification code
    $code = $verification->generateCode($userData['db_id'], $userData['type'], $userData['email'], $isResend, 'recovery');
    
    if ($code) {
        $response['success'] = true;
        $response['email'] = $userData['email'];
        $response['message'] = $isResend ? 'New verification code sent successfully' : 'Verification code sent successfully';
    } else {
        $response['message'] = $verification->getLastError() ?: 'Failed to send verification code';
    }
    
} catch (Exception $e) {
    $response['message'] = 'An error occurred while sending verification code: ' . $e->getMessage();
    error_log("Error in recovery_send_code_direct.php: " . $e->getMessage());
}

echo json_encode($response);
?>
