<?php
require_once '../config/database.php';
header('Content-Type: application/json');
try {
    $sections = $pdo->query("SELECT id, name FROM sections ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($sections);
} catch(PDOException $e) {
    echo json_encode([]);
} 