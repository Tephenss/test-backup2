<?php
session_start();
require_once '../config/database.php';
require_once '../helpers/BackupHooks.php';

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

// Get marks data before update for Firebase backup
$getMarksStmt = $pdo->prepare("SELECT * FROM marks WHERE class_id = ? AND assessment_type_id = ? AND date = ?" . ($term ? " AND term = ?" : ""));
$getMarksParams = [$class_id, $assessment_type, $date];
if ($term) {
    $getMarksParams[] = $term;
}
$getMarksStmt->execute($getMarksParams);
$marksData = $getMarksStmt->fetchAll(PDO::FETCH_ASSOC);

// Update all marks for this class, assessment type, date, and term (if provided)
$query = "UPDATE marks SET custom_name = ? WHERE class_id = ? AND assessment_type_id = ? AND date = ?";
$params = [$custom_name, $class_id, $assessment_type, $date];
if ($term) {
    $query .= " AND term = ?";
    $params[] = $term;
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);

// Backup marks update to Firebase
if (!empty($marksData)) {
    try {
        $backupHooks = new BackupHooks();
        foreach ($marksData as $mark) {
            $backupData = array_merge($mark, [
                'custom_name' => $custom_name,
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => 'teacher'
            ]);
            $backupHooks->backupGenericRecord('marks', $backupData, 'update');
        }
    } catch (Exception $e) {
        error_log("Firebase backup failed for marks update: " . $e->getMessage());
    }
}

echo 'success'; 