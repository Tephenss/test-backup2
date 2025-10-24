<?php
require_once __DIR__ . '/../config/database.php';

function getStudents($filters = []) {
    global $pdo;
    $params = [];
    $query = "SELECT s.*, s.year_level, DATE_FORMAT(s.created_at, '%M %d, %Y %h:%i %p') as created_at 
              FROM students s 
              LEFT JOIN class_students cs ON s.id = cs.student_id 
              LEFT JOIN classes c ON cs.class_id = c.id 
              WHERE s.deleted_at IS NULL";
    
    if (!empty($filters['section'])) {
        $query .= " AND s.section = ?";
        $params[] = $filters['section'];
    }
    if (!empty($filters['year_level'])) {
        $query .= " AND s.year_level = ?";
        $params[] = $filters['year_level'];
    }
    if (!empty($filters['class_id'])) {
        $query .= " AND c.id = ?";
        $params[] = $filters['class_id'];
    }
    if (!empty($filters['teacher_id'])) {
        $query .= " AND c.teacher_id = ?";
        $params[] = $filters['teacher_id'];
    }
    $query .= " GROUP BY s.id ORDER BY s.last_name, s.first_name";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
} 