<?php
session_start();
require_once '../config/database.php';
header('Content-Type: application/json');
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'teacher') {
    echo json_encode([]);
    exit();
}
$teacher_id = $_SESSION['user_id'];
$sql = "
    SELECT DISTINCT at.id, at.name
    FROM assessment_types at
    JOIN marks m ON m.assessment_type_id = at.id
    JOIN classes c ON m.class_id = c.id
    WHERE c.teacher_id = ?
    ORDER BY at.name
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$teacher_id]);
$types = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($types); 