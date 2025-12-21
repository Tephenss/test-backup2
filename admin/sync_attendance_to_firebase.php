<?php
/**
 * Sync attendance records from MySQL to Firebase
 * This ensures all attendance records with session IDs are properly synced to Firebase
 * with the correct key format including session ID
 */

session_start();
require_once '../config/database.php';
require_once '../config.php';
require_once '../helpers/FirebaseBackup.php';
require_once '../helpers/BackupHooks.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    die("Unauthorized access");
}

$backupHooks = new BackupHooks();
$results = [];
$errors = [];

try {
    echo "<!DOCTYPE html><html><head><title>Sync Attendance to Firebase</title>";
    echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'></head><body>";
    echo "<div class='container mt-5'>";
    echo "<h2>Syncing Attendance Records to Firebase</h2>";
    echo "<pre>";
    
    // Check if timetable_id column exists
    $checkColumnStmt = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'timetable_id'");
    $hasTimetableIdColumn = ($checkColumnStmt->rowCount() > 0);
    
    if (!$hasTimetableIdColumn) {
        echo "❌ 'timetable_id' column does not exist in attendance table.\n";
        echo "   Please run the migration script first.\n";
        echo "</pre><p><a href='manage_archive.php' class='btn btn-secondary'>Back to Admin Panel</a></p>";
        echo "</div></body></html>";
        exit;
    }
    
    // Fetch all attendance records from MySQL
    $stmt = $pdo->query("
        SELECT 
            a.id,
            a.class_id,
            a.timetable_id,
            a.student_id,
            a.date,
            a.status,
            a.recorded_by,
            a.created_at
        FROM attendance a
        ORDER BY a.date DESC, a.id DESC
    ");
    
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalRecords = count($records);
    
    echo "📦 Found {$totalRecords} attendance records in MySQL database.\n\n";
    
    $syncedCount = 0;
    $skippedCount = 0;
    $errorCount = 0;
    
    foreach ($records as $record) {
        try {
            $attendanceId = $record['id'];
            $classId = $record['class_id'];
            $timetableId = $record['timetable_id'];
            $studentId = $record['student_id'];
            $date = $record['date'];
            $status = $record['status'];
            $recordedBy = $record['recorded_by'];
            $createdAt = $record['created_at'] ?: date('Y-m-d H:i:s');
            
            // Prepare attendance data for Firebase backup
            $attendanceData = [
                'id' => $attendanceId,
                'class_id' => $classId,
                'student_id' => $studentId,
                'date' => $date,
                'status' => $status,
                'recorded_by' => $recordedBy,
                'created_at' => $createdAt
            ];
            
            // Include timetable_id if it exists
            if ($timetableId !== null && $timetableId !== '') {
                $attendanceData['timetable_id'] = $timetableId;
                echo "📝 Syncing attendance ID {$attendanceId} (Class: {$classId}, Student: {$studentId}, Date: {$date}, Session: {$timetableId})...\n";
            } else {
                echo "📝 Syncing attendance ID {$attendanceId} (Class: {$classId}, Student: {$studentId}, Date: {$date}, No Session)...\n";
            }
            
            // Use FirebaseBackup directly with 'update' operation to sync existing records
            // This will use the correct key format including session ID
            $firebaseBackup = new FirebaseBackup();
            $success = $firebaseBackup->backupRecord('attendance', $attendanceData, 'update');
            
            if ($success) {
                $syncedCount++;
                echo "   ✅ Successfully synced to Firebase\n";
            } else {
                $errorCount++;
                echo "   ❌ Failed to sync to Firebase\n";
                $errors[] = "Attendance ID {$attendanceId}: Failed to sync";
            }
            
        } catch (Exception $e) {
            $errorCount++;
            $skippedCount++;
            echo "   ❌ Error: " . $e->getMessage() . "\n";
            $errors[] = "Attendance ID {$attendanceId}: " . $e->getMessage();
        }
    }
    
    echo "\n=== Sync Complete ===\n";
    echo "✅ Successfully synced: {$syncedCount} records\n";
    if ($skippedCount > 0) {
        echo "⏭️ Skipped: {$skippedCount} records\n";
    }
    if ($errorCount > 0) {
        echo "❌ Errors: {$errorCount} records\n";
        echo "\nError Details:\n";
        foreach ($errors as $error) {
            echo "  - {$error}\n";
        }
    }
    
    echo "</pre>";
    
    if ($errorCount > 0) {
        echo "<p class='alert alert-warning'><strong>Some records failed to sync. Please check the error details above.</strong></p>";
    } else {
        echo "<p class='alert alert-success'><strong>All attendance records have been synced to Firebase successfully!</strong></p>";
    }
    
    echo "<p><a href='manage_archive.php' class='btn btn-secondary'>Back to Admin Panel</a></p>";
    echo "</div></body></html>";
    
} catch (Exception $e) {
    echo "<p class='alert alert-danger'><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><a href='manage_archive.php' class='btn btn-secondary'>Back to Admin Panel</a></p>";
    echo "</div></body></html>";
}

