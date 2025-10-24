<?php
session_start();
require_once '../config/database.php';
require_once '../helpers/EmailVerification.php';

// Check if it's a registration verification
if (isset($_SESSION['temp_student_data'])) {
    $userData = $_SESSION['temp_student_data'];
    $userId = $userData['student_id'];
    $userType = 'student';
    $email = $userData['email'];
} else {
    // If user is not in verification process, redirect to login
    if (!isset($_SESSION['pending_verification']) || !isset($_SESSION['temp_user_data'])) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid session']);
            exit();
        }
        header("Location: ../index.php");
        exit();
    }

    $userData = $_SESSION['temp_user_data'];
    $userId = $userData['id'];
    $userType = $userData['user_type'];
    $email = $userData['email'];
}

// Check cooldown in database
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count, MAX(created_at) as last_created FROM verification_codes WHERE user_id = ? AND user_type = ?");
    $stmt->execute([$userId, $userType]);
    $result = $stmt->fetch();

    if ($result['count'] > 1) { // Only check cooldown if there are multiple codes
        $lastCodeTime = strtotime($result['last_created']);
        $currentTime = time();
        $timeDiff = $currentTime - $lastCodeTime;

        if ($timeDiff < 60) { // 60 seconds cooldown
            $timeLeft = 60 - $timeDiff;
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false, 
                    'error' => "Please wait 60 seconds before requesting a new code",
                    'cooldown' => $timeLeft
                ]);
                exit();
            }
            $_SESSION['verification_errors'] = ["Please wait 60 seconds before requesting a new code"];
            header("Location: verify.php");
            exit();
        }
    }
} catch(PDOException $e) {
    error_log("Database error checking cooldown: " . $e->getMessage());
}

$verification = new EmailVerification();

// Generate and send new verification code
if ($verification->generateCode($userId, $userType, $email, true)) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit();
    }
    $_SESSION['verification_success'] = "A new verification code has been sent to your email.";
} else {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'error' => $verification->getLastError() ?: "Failed to send new verification code. Please try again."
        ]);
        exit();
    }
    $_SESSION['verification_errors'] = [$verification->getLastError() ?: "Failed to send new verification code. Please try again."];
}

// Redirect back to verification page for non-AJAX requests
header("Location: verify.php");
exit();
?>
