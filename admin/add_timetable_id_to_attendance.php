<?php
/**
 * Add timetable_id column to attendance table for multiple sessions support
 * This allows storing separate attendance records for different sessions on the same day
 */

session_start();
require_once '../config/database.php';
require_once '../config.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    die("Unauthorized access");
}

try {
    echo "<!DOCTYPE html><html><head><title>Add Timetable ID to Attendance</title></head><body>";
    echo "<h2>Adding Timetable ID Column to Attendance Table...</h2>";
    echo "<pre>";
    
    // Check if column already exists
    $checkStmt = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'timetable_id'");
    if ($checkStmt->rowCount() > 0) {
        echo "Column 'timetable_id' already exists. No changes needed.\n";
    } else {
        // Add timetable_id column (nullable for backward compatibility)
        $pdo->exec("ALTER TABLE attendance ADD COLUMN timetable_id INT NULL AFTER class_id");
        echo "✓ Added timetable_id column to attendance table.\n";
        
        // Add foreign key constraint
        try {
            $pdo->exec("ALTER TABLE attendance ADD CONSTRAINT fk_attendance_timetable FOREIGN KEY (timetable_id) REFERENCES timetable(id) ON DELETE SET NULL");
            echo "✓ Added foreign key constraint for timetable_id.\n";
        } catch (PDOException $e) {
            echo "⚠ Could not add foreign key constraint (may already exist): " . $e->getMessage() . "\n";
        }
        
        // Drop old unique constraint
        try {
            $pdo->exec("ALTER TABLE attendance DROP INDEX unique_attendance");
            echo "✓ Dropped old unique constraint.\n";
        } catch (PDOException $e) {
            echo "⚠ Could not drop unique constraint (may not exist): " . $e->getMessage() . "\n";
        }
        
        // Add new unique constraint that includes timetable_id
        // This allows multiple attendance records per day (one per session)
        // IMPORTANT: timetable_id must be included to allow separate records per session
        // Note: In MySQL, NULL values are considered distinct in unique constraints, 
        // so NULL timetable_id records won't conflict with non-NULL records
        try {
            // First, ensure timetable_id allows NULL
            $pdo->exec("ALTER TABLE attendance MODIFY COLUMN timetable_id INT NULL");
            
            // Add unique constraint with timetable_id
            // This allows: (class_id=1, student_id=1, date='2025-12-03', timetable_id=10) 
            // AND (class_id=1, student_id=1, date='2025-12-03', timetable_id=11)
            $pdo->exec("ALTER TABLE attendance ADD UNIQUE KEY unique_attendance_session (class_id, student_id, date, timetable_id)");
            echo "✓ Added new unique constraint with timetable_id.\n";
            echo "  This allows separate attendance records for each session on the same day.\n";
        } catch (PDOException $e) {
            echo "⚠ Could not add unique constraint: " . $e->getMessage() . "\n";
            echo "  You may need to manually add: ALTER TABLE attendance ADD UNIQUE KEY unique_attendance_session (class_id, student_id, date, timetable_id);\n";
        }
        
        // Add index for faster queries
        try {
            $pdo->exec("CREATE INDEX idx_attendance_timetable ON attendance(timetable_id)");
            echo "✓ Added index for timetable_id.\n";
        } catch (PDOException $e) {
            echo "⚠ Could not add index (may already exist): " . $e->getMessage() . "\n";
        }
        
        echo "\n=== Migration Complete ===\n";
        echo "The attendance table now supports multiple sessions per day.\n";
        echo "Each session can have its own attendance record.\n";
    }
    
    echo "</pre>";
    echo "<p><a href='manage_archive.php'>Back to Admin Panel</a></p>";
    echo "</body></html>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "</pre></body></html>";
}

