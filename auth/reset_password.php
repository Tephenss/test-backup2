<?php
session_start();
require_once '../config/database.php';
require_once '../helpers/BackupHooks.php';

header('Content-Type: application/json');

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['id']) || !isset($input['password'])) {
        throw new Exception('Missing required fields');
    }

    // Verify that this is the same user that was verified
    if (!isset($_SESSION['reset_user_type']) || !isset($_SESSION['reset_user_id']) || 
        $_SESSION['reset_user_id'] !== $input['id']) {
        throw new Exception('Invalid reset attempt. Please verify your account again.');
    }

    $id = $_SESSION['reset_user_id'];
    $user_type = $_SESSION['reset_user_type'];
    $password = $input['password'];

    // Validate password length
    if (strlen($password) < 8) {
        throw new Exception('Password must be at least 8 characters long');
    }

    // Hash the password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Update password based on user type
    $table = '';
    $id_field = '';
    switch ($user_type) {
        case 'student':
            $table = 'students';
            $id_field = 'student_id';
            break;
        case 'teacher':
            $table = 'teachers';
            $id_field = 'teacher_id';
            break;
        case 'admin':
            $table = 'admins';
            $id_field = 'admin_id';
            break;
        default:
            throw new Exception('Invalid user type');
    }

    // Update the password
    $stmt = $pdo->prepare("UPDATE {$table} SET password = ? WHERE {$id_field} = ?");
    if (!$stmt->execute([$hashedPassword, $id])) {
        throw new Exception('Failed to update password');
    }

    if ($stmt->rowCount() === 0) {
        throw new Exception('User not found');
    }
    
    // Get the user's database ID for Firebase backup
    $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE {$id_field} = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        // Backup account recovery to Firebase
        try {
            $backupHooks = new BackupHooks();
            $updatedData = [
                'password_reset_at' => date('Y-m-d H:i:s'),
                'reset_method' => 'account_recovery'
            ];
            $backupHooks->backupAccountRecovery($user['id'], $user_type, $updatedData);
        } catch (Exception $e) {
            error_log("Firebase backup failed for account recovery: " . $e->getMessage());
        }
    }

    // Log the password reset action
    $logStmt = $pdo->prepare("INSERT INTO activity_logs (user_id, user_type, action, details) VALUES (?, ?, 'Password Reset', 'Password was reset successfully')");
    $logStmt->execute([$id, $user_type]);

    // Clear the reset session variables
    unset($_SESSION['reset_user_type']);
    unset($_SESSION['reset_user_id']);

    echo json_encode([
        'success' => true,
        'message' => 'Password has been reset successfully'
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 