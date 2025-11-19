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
    // First, get the section details to verify year_level
    $section_stmt = $pdo->prepare("SELECT name, year_level FROM sections WHERE id = ?");
    $section_stmt->execute([$section_id]);
    $section_info = $section_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$section_info) {
        echo json_encode([]);
        exit;
    }
    
    $section_name = $section_info['name'];
    $section_year_level = $section_info['year_level'];
    
    // Only fetch students that match both section name AND year_level
    $params = [$section_name, $section_year_level];
    $query = "SELECT s.id, s.student_id, s.first_name, s.middle_name, s.last_name, s.suffix_name, s.sex, s.civil_status, s.birthdate, s.place_of_birth, s.citizenship, s.address, s.phone_number, s.email, s.profile_picture, s.course, s.year_level, s.created_at 
              FROM students s 
              WHERE s.section = ? AND s.year_level = ? AND s.is_deleted = 0
              ORDER BY s.last_name ASC, s.first_name ASC, s.middle_name ASC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($students);
} catch (PDOException $e) {
    error_log("Error fetching students: " . $e->getMessage());
    echo json_encode([]);
} 