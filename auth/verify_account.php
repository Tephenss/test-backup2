<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

// Get JSON data
$data = json_decode(file_get_contents('php://input'), true);
$id = $data['id'] ?? '';
$birthdate = $data['birthdate'] ?? '';
// Normalize yyyy/mm/dd -> yyyy-mm-dd for DB DATE() comparison
$birthdate = str_replace('/', '-', $birthdate);

$response = ['success' => false];

try {
    // First try teachers table
    $stmt = $pdo->prepare("SELECT * FROM teachers WHERE teacher_id = ? AND DATE(birth_date) = ?");
    $stmt->execute([$id, $birthdate]);
    $teacher = $stmt->fetch();

    if ($teacher) {
        $_SESSION['reset_user_type'] = 'teacher';
        $_SESSION['reset_user_id'] = $teacher['teacher_id'];
        $response['success'] = true;
        $response['user_data'] = [
            'type' => 'teacher',
            'id' => $teacher['teacher_id'],
            'db_id' => $teacher['id'],
            'email' => $teacher['email']
        ];
    } else {
        // Try students table
        $stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = ? AND DATE(birthdate) = ?");
        $stmt->execute([$id, $birthdate]);
        $student = $stmt->fetch();

        if ($student) {
            $_SESSION['reset_user_type'] = 'student';
            $_SESSION['reset_user_id'] = $student['student_id'];
            $response['success'] = true;
            $response['user_data'] = [
                'type' => 'student',
                'id' => $student['student_id'],
                'db_id' => $student['id'],
                'email' => $student['email']
            ];
        } else {
            // Try admins table
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE admin_id = ? AND DATE(birth_date) = ?");
            $stmt->execute([$id, $birthdate]);
            $admin = $stmt->fetch();

            if ($admin) {
                $_SESSION['reset_user_type'] = 'admin';
                $_SESSION['reset_user_id'] = $admin['admin_id'];
                $response['success'] = true;
                $response['user_data'] = [
                    'type' => 'admin',
                    'id' => $admin['admin_id'],
                    'db_id' => $admin['id'],
                    'email' => $admin['email']
                ];
            }
        }
    }

    if ($response['success']) {
        $response['message'] = 'Account verified successfully';
    } else {
        $response['message'] = 'Invalid ID or birthdate';
    }
    
} catch(PDOException $e) {
    $response['success'] = false;
    $response['message'] = "An error occurred while verifying your account";
    error_log("Database error in verify_account.php: " . $e->getMessage());
}

echo json_encode($response); 