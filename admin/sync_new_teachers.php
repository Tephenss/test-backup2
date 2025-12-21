<?php
/**
 * Sync New Teachers to Firebase
 * 
 * This script finds teachers that were created recently but may not be in Firebase
 * and backs them up to ensure the Android app can fetch them.
 */

session_start();
require_once '../config/database.php';
require_once '../config.php';
require_once '../helpers/BackupHooks.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    die("Unauthorized access");
}

try {
    echo "<!DOCTYPE html><html><head><title>Sync New Teachers</title></head><body>";
    echo "<h2>Syncing New Teachers to Firebase...</h2>";
    echo "<pre>";
    
    // Get all teachers
    $stmt = $pdo->query("SELECT * FROM teachers WHERE is_deleted = 0 ORDER BY id DESC");
    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($teachers) . " teachers in database.\n\n";
    
    $backupHooks = new BackupHooks();
    $syncedCount = 0;
    $errorCount = 0;
    
    foreach ($teachers as $teacher) {
        try {
            $teacherData = [
                'id' => $teacher['id'],
                'teacher_id' => $teacher['teacher_id'] ?? '',
                'full_name' => $teacher['full_name'],
                'sex' => $teacher['sex'] ?? '',
                'civil_status' => $teacher['civil_status'] ?? '',
                'birth_date' => $teacher['birth_date'] ?? '',
                'phone_number' => $teacher['phone_number'] ?? $teacher['phone'] ?? '',
                'course' => $teacher['course'] ?? 'BSIT',
                'email' => $teacher['email'] ?? '',
                'created_at' => $teacher['created_at'] ?? date('Y-m-d H:i:s')
            ];
            
            // Use update operation to ensure it creates/updates the record
            $result = $backupHooks->backupGenericRecord('teachers', $teacherData, 'insert');
            
            if ($result) {
                $syncedCount++;
                echo "✓ Synced teacher ID {$teacher['id']}: {$teacher['full_name']}\n";
            } else {
                $errorCount++;
                echo "✗ Failed to sync teacher ID {$teacher['id']}: {$teacher['full_name']}\n";
            }
        } catch (Exception $e) {
            $errorCount++;
            echo "✗ Error syncing teacher ID {$teacher['id']}: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Sync completed!\n";
    echo "  Successfully synced: $syncedCount\n";
    echo "  Errors: $errorCount\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    echo "</pre>";
    echo "<a href='manage_teachers.php' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>← Back to Manage Teachers</a>";
    echo "</body></html>";
    
} catch (Exception $e) {
    echo "<h2>Error</h2>";
    echo "<pre>Error: " . $e->getMessage() . "\n";
    echo "Please check your database connection and try again.</pre>";
    echo "<a href='manage_teachers.php'>← Back to Manage Teachers</a>";
}







