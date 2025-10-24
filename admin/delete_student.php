<?php
require_once '../config.php';
require_once '../config/database.php';
require_once '../includes/fetch_students.php';

// Check if user is logged in and is admin
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

// Check if ID is provided
if (!isset($_POST['id']) || empty($_POST['id'])) {
    echo json_encode(['success' => false, 'error' => 'Student ID is required']);
    exit;
}

$student_id = $_POST['id'];

try {
    // Check if transaction is already active
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
    }

    // First, delete related records (if any)
    // Delete from class_students
    $stmt = $pdo->prepare("DELETE FROM class_students WHERE student_id = ?");
    $stmt->execute([$student_id]);

    // Delete from attendance
    $stmt = $pdo->prepare("DELETE FROM attendance WHERE student_id = ?");
    $stmt->execute([$student_id]);

    // Finally, delete the student
    $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
    $stmt->execute([$student_id]);

    // Commit transaction
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?> 