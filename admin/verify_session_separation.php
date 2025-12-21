<?php
/**
 * Verify that attendance records are properly separated by session
 * This script checks:
 * 1. If timetable_id column exists
 * 2. If unique constraint includes timetable_id
 * 3. If there are any records that might be mixing between sessions
 */

session_start();
require_once '../config/database.php';
require_once '../config.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    die("Unauthorized access");
}

try {
    echo "<!DOCTYPE html><html><head><title>Verify Session Separation</title></head><body>";
    echo "<h2>Verifying Session Separation in Attendance Table...</h2>";
    echo "<pre>";
    
    // Check if timetable_id column exists
    $checkColumn = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'timetable_id'");
    if ($checkColumn->rowCount() == 0) {
        echo "❌ Column 'timetable_id' does NOT exist!\n";
        echo "   Run: admin/add_timetable_id_to_attendance.php first\n";
    } else {
        echo "✓ Column 'timetable_id' exists.\n";
    }
    
    // Check unique constraints
    echo "\n=== Checking Unique Constraints ===\n";
    $indexes = $pdo->query("SHOW INDEXES FROM attendance")->fetchAll(PDO::FETCH_ASSOC);
    $hasSessionConstraint = false;
    $hasOldConstraint = false;
    
    foreach ($indexes as $index) {
        if ($index['Key_name'] === 'unique_attendance_session') {
            echo "✓ Found unique constraint: unique_attendance_session\n";
            echo "   Columns: " . $index['Column_name'] . "\n";
            $hasSessionConstraint = true;
        }
        if ($index['Key_name'] === 'unique_attendance' && strpos(json_encode($index), 'timetable_id') === false) {
            echo "⚠ Found old unique constraint: unique_attendance (without timetable_id)\n";
            echo "   This might cause conflicts. Consider dropping it.\n";
            $hasOldConstraint = true;
        }
    }
    
    if (!$hasSessionConstraint) {
        echo "❌ Unique constraint 'unique_attendance_session' NOT found!\n";
        echo "   You need: ALTER TABLE attendance ADD UNIQUE KEY unique_attendance_session (class_id, student_id, date, timetable_id);\n";
    }
    
    // Check for potential conflicts
    echo "\n=== Checking for Records That Might Mix ===\n";
    
    // Find records for same class, student, date but different timetable_id
    $conflicts = $pdo->query("
        SELECT class_id, student_id, date, GROUP_CONCAT(DISTINCT timetable_id ORDER BY timetable_id) as timetable_ids, COUNT(*) as count
        FROM attendance
        WHERE date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY class_id, student_id, date
        HAVING COUNT(DISTINCT timetable_id) > 1
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($conflicts)) {
        echo "✓ No conflicting records found (multiple sessions for same student/date).\n";
    } else {
        echo "⚠ Found " . count($conflicts) . " cases where same student has attendance in multiple sessions:\n";
        foreach ($conflicts as $conflict) {
            echo "   Class: {$conflict['class_id']}, Student: {$conflict['student_id']}, Date: {$conflict['date']}\n";
            echo "   Sessions: {$conflict['timetable_ids']} (This is CORRECT - each session should be separate)\n";
        }
    }
    
    // Check for NULL timetable_id when sessions exist
    $nullRecords = $pdo->query("
        SELECT COUNT(*) as count
        FROM attendance
        WHERE timetable_id IS NULL
        AND date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ")->fetchColumn();
    
    if ($nullRecords > 0) {
        echo "⚠ Found $nullRecords records with NULL timetable_id (old records without session).\n";
    } else {
        echo "✓ All recent records have timetable_id set.\n";
    }
    
    // Summary
    echo "\n=== Summary ===\n";
    if ($hasSessionConstraint && $checkColumn->rowCount() > 0) {
        echo "✓ Database schema is configured correctly for session separation.\n";
    } else {
        echo "❌ Database schema needs to be fixed.\n";
        echo "   Run: admin/add_timetable_id_to_attendance.php\n";
    }
    
    echo "</pre>";
    echo "<p><a href='manage_archive.php'>Back to Admin Panel</a></p>";
    echo "</body></html>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "</pre></body></html>";
}







