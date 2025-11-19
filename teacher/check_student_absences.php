<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as teacher
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'teacher') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['student_id']) || !isset($_GET['class_id'])) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit();
}

$student_id = intval($_GET['student_id']);
$class_id = intval($_GET['class_id']);

try {
    // Count absences for this student in this class
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as absence_count
        FROM attendance
        WHERE student_id = ? 
        AND class_id = ? 
        AND status = 'absent'
    ");
    $stmt->execute([$student_id, $class_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $absence_count = intval($result['absence_count'] ?? 0);
    
    echo json_encode([
        'success' => true,
        'absence_count' => $absence_count,
        'can_drop' => $absence_count >= 5
    ]);
} catch (PDOException $e) {
    error_log("Error checking student absences: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>

