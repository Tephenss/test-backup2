<?php
require_once '../config/database.php';
header('Content-Type: application/json');

$section_id = isset($_GET['section_id']) ? $_GET['section_id'] : '';
$year_level = isset($_GET['year_level']) ? $_GET['year_level'] : '';

if (!$section_id) {
    echo json_encode([]);
    exit;
}

try {
    $params = [$section_id];
    $query = "SELECT s.id, s.student_id, s.first_name, s.middle_name, s.last_name, s.suffix_name, s.sex, s.civil_status, s.birthdate, s.place_of_birth, s.citizenship, s.address, s.phone_number, s.email, s.course, s.year_level, s.created_at FROM students s WHERE s.section = (SELECT name FROM sections WHERE id = ?) AND s.is_deleted = 0";
    if ($year_level !== '' && $year_level !== null) {
        $query .= " AND s.year_level = ?";
        $params[] = $year_level;
    }
    $query .= " ORDER BY s.last_name ASC, s.first_name ASC, s.middle_name ASC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug: Log the first student data to see structure
    if (!empty($students)) {
        error_log("First student data: " . print_r($students[0], true));
    }
    
    echo json_encode($students);
} catch (PDOException $e) {
    echo json_encode([]);
} 