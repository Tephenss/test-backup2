<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'teacher') {
    http_response_code(403);
    echo 'Unauthorized';
    exit();
}

$class_id = $_POST['class_id'] ?? null;
$assessment_type = $_POST['assessment_type'] ?? null;
$date = $_POST['date'] ?? null;
$term = $_POST['term'] ?? null;
$custom_name = $_POST['custom_name'] ?? null;

if (!$class_id || !$assessment_type || !$date || !$custom_name) {
    http_response_code(400);
    echo 'Missing required fields';
    exit();
}

// Update all marks for this class, assessment type, date, and term (if provided)
$query = "UPDATE marks SET custom_name = ? WHERE class_id = ? AND assessment_type_id = ? AND date = ?";
$params = [$custom_name, $class_id, $assessment_type, $date];
if ($term) {
    $query .= " AND term = ?";
    $params[] = $term;
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);

echo 'success'; 