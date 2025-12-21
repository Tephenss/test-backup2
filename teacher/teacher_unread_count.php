<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../config/database.php';
require_once '../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'teacher') {
    echo json_encode(['unread' => 0]);
    exit;
}

$teacher_id = $_SESSION['user_id'];

try {
    // Count unread messages for this teacher
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread
        FROM messages
        WHERE receiver_id = ? 
          AND receiver_type = 'teacher'
          AND is_read = 0
    ");
    $stmt->execute([$teacher_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'unread' => (int)($result['unread'] ?? 0)
    ]);
} catch (PDOException $e) {
    error_log("Error fetching unread count: " . $e->getMessage());
    echo json_encode(['unread' => 0]);
}
?>


