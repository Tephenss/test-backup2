<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    die("Unauthorized access");
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Cleanup Zero IDs</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
    </style>
</head>
<body>
    <h2>Cleaning up records with ID 0</h2>
    <hr>
<?php

try {
    $pdo->beginTransaction();
    
    $total_deleted = 0;
    
    // Check and delete from subject_assignments
    $stmt = $pdo->query("SELECT COUNT(*) FROM subject_assignments WHERE id = 0");
    $count_assignments = $stmt->fetchColumn();
    
    if ($count_assignments > 0) {
        $stmt = $pdo->prepare("DELETE FROM subject_assignments WHERE id = 0");
        $stmt->execute();
        $deleted_assignments = $stmt->rowCount();
        echo "<p class='success'>✓ Deleted $deleted_assignments record(s) from subject_assignments with ID 0</p>";
        $total_deleted += $deleted_assignments;
    } else {
        echo "<p class='info'>No records found in subject_assignments with ID 0</p>";
    }
    
    // Check and delete from classes
    $stmt = $pdo->query("SELECT COUNT(*) FROM classes WHERE id = 0");
    $count_classes = $stmt->fetchColumn();
    
    if ($count_classes > 0) {
        $stmt = $pdo->prepare("DELETE FROM classes WHERE id = 0");
        $stmt->execute();
        $deleted_classes = $stmt->rowCount();
        echo "<p class='success'>✓ Deleted $deleted_classes record(s) from classes with ID 0</p>";
        $total_deleted += $deleted_classes;
    } else {
        echo "<p class='info'>No records found in classes with ID 0</p>";
    }
    
    // Also check for NULL or invalid IDs in subject_assignments (where id is NULL or empty)
    $stmt = $pdo->query("SELECT COUNT(*) FROM subject_assignments WHERE id IS NULL OR id = ''");
    $count_null_assignments = $stmt->fetchColumn();
    
    if ($count_null_assignments > 0) {
        $stmt = $pdo->prepare("DELETE FROM subject_assignments WHERE id IS NULL OR id = ''");
        $stmt->execute();
        $deleted_null_assignments = $stmt->rowCount();
        echo "<p class='success'>✓ Deleted $deleted_null_assignments record(s) from subject_assignments with NULL/empty ID</p>";
        $total_deleted += $deleted_null_assignments;
    }
    
    // Also check for NULL or invalid IDs in classes
    $stmt = $pdo->query("SELECT COUNT(*) FROM classes WHERE id IS NULL OR id = ''");
    $count_null_classes = $stmt->fetchColumn();
    
    if ($count_null_classes > 0) {
        $stmt = $pdo->prepare("DELETE FROM classes WHERE id IS NULL OR id = ''");
        $stmt->execute();
        $deleted_null_classes = $stmt->rowCount();
        echo "<p class='success'>✓ Deleted $deleted_null_classes record(s) from classes with NULL/empty ID</p>";
        $total_deleted += $deleted_null_classes;
    }
    
    // Check and delete from subjects table (in case there are subjects with ID 0)
    $stmt = $pdo->query("SELECT COUNT(*) FROM subjects WHERE id = 0");
    $count_subjects = $stmt->fetchColumn();
    
    if ($count_subjects > 0) {
        $stmt = $pdo->prepare("DELETE FROM subjects WHERE id = 0");
        $stmt->execute();
        $deleted_subjects = $stmt->rowCount();
        echo "<p class='success'>✓ Deleted $deleted_subjects record(s) from subjects with ID 0</p>";
        $total_deleted += $deleted_subjects;
    } else {
        echo "<p class='info'>No records found in subjects with ID 0</p>";
    }
    
    $pdo->commit();
    echo "<hr>";
    echo "<p class='success'><strong>Cleanup completed successfully! Total records deleted: $total_deleted</strong></p>";
    echo "<p><a href='manage_subjects.php'>← Back to Manage Subjects</a></p>";
    
} catch(PDOException $e) {
    $pdo->rollBack();
    echo "<p class='error'><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
</body>
</html>

