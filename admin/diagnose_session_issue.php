<?php
/**
 * Diagnose session separation issues
 * This script will check the actual database state and identify the problem
 */

session_start();
require_once '../config/database.php';
require_once '../config.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    die("Unauthorized access");
}

try {
    echo "<!DOCTYPE html><html><head><title>Diagnose Session Issue</title>";
    echo "<style>body{font-family:monospace;padding:20px;} .error{color:red;} .success{color:green;} .warning{color:orange;}</style>";
    echo "</head><body>";
    echo "<h2>🔍 Diagnosing Session Separation Issue...</h2>";
    echo "<pre>";
    
    // 1. Check if timetable_id column exists
    echo "\n=== 1. Checking timetable_id Column ===\n";
    $checkColumn = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'timetable_id'");
    if ($checkColumn->rowCount() == 0) {
        echo "<span class='error'>❌ Column 'timetable_id' does NOT exist!</span>\n";
        echo "   → Run: admin/add_timetable_id_to_attendance.php first\n";
    } else {
        echo "<span class='success'>✓ Column 'timetable_id' exists.</span>\n";
        $columnInfo = $checkColumn->fetch(PDO::FETCH_ASSOC);
        echo "   Type: {$columnInfo['Type']}, Null: {$columnInfo['Null']}\n";
    }
    
    // 2. Check unique constraints
    echo "\n=== 2. Checking Unique Constraints ===\n";
    $indexes = $pdo->query("SHOW INDEXES FROM attendance WHERE Key_name LIKE 'unique%'")->fetchAll(PDO::FETCH_ASSOC);
    
    $hasSessionConstraint = false;
    $hasOldConstraint = false;
    
    foreach ($indexes as $index) {
        if ($index['Key_name'] === 'unique_attendance_session') {
            echo "<span class='success'>✓ Found: unique_attendance_session</span>\n";
            echo "   Column: {$index['Column_name']}, Seq: {$index['Seq_in_index']}\n";
            $hasSessionConstraint = true;
        }
        if ($index['Key_name'] === 'unique_attendance') {
            echo "<span class='warning'>⚠ Found: unique_attendance (OLD - should be dropped)</span>\n";
            $hasOldConstraint = true;
        }
    }
    
    // Get all columns in unique_attendance_session
    if ($hasSessionConstraint) {
        $constraintCols = $pdo->query("
            SELECT Column_name 
            FROM information_schema.STATISTICS 
            WHERE table_schema = DATABASE() 
            AND table_name = 'attendance' 
            AND index_name = 'unique_attendance_session'
            ORDER BY seq_in_index
        ")->fetchAll(PDO::FETCH_COLUMN);
        
        echo "   Columns in constraint: " . implode(', ', $constraintCols) . "\n";
        
        if (!in_array('timetable_id', $constraintCols)) {
            echo "<span class='error'>❌ timetable_id is NOT in the unique constraint!</span>\n";
        } else {
            echo "<span class='success'>✓ timetable_id is in the unique constraint.</span>\n";
        }
    } else {
        echo "<span class='error'>❌ unique_attendance_session constraint NOT found!</span>\n";
    }
    
    // 3. Check for sample attendance records
    echo "\n=== 3. Checking Sample Attendance Records ===\n";
    $recentRecords = $pdo->query("
        SELECT id, class_id, student_id, date, timetable_id, status, created_at
        FROM attendance
        WHERE date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
        ORDER BY date DESC, created_at DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($recentRecords)) {
        echo "   No recent records found.\n";
    } else {
        echo "   Found " . count($recentRecords) . " recent records:\n\n";
        foreach ($recentRecords as $record) {
            echo "   ID: {$record['id']}, Class: {$record['class_id']}, Student: {$record['student_id']}\n";
            echo "   Date: {$record['date']}, Status: {$record['status']}\n";
            echo "   Timetable ID: " . ($record['timetable_id'] ?? 'NULL') . "\n";
            echo "   Created: {$record['created_at']}\n";
            echo "   ---\n";
        }
    }
    
    // 4. Check for records with same class/student/date but different timetable_id
    echo "\n=== 4. Checking for Potential Conflicts ===\n";
    $conflicts = $pdo->query("
        SELECT class_id, student_id, date, 
               GROUP_CONCAT(DISTINCT timetable_id ORDER BY timetable_id) as timetable_ids, 
               COUNT(*) as count,
               GROUP_CONCAT(DISTINCT status) as statuses
        FROM attendance
        WHERE date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY class_id, student_id, date
        HAVING COUNT(DISTINCT timetable_id) > 1
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($conflicts)) {
        echo "<span class='success'>✓ No conflicts found (this is good - each session has separate records).</span>\n";
    } else {
        echo "<span class='warning'>⚠ Found " . count($conflicts) . " cases with multiple sessions:</span>\n";
        foreach ($conflicts as $conflict) {
            echo "   Class: {$conflict['class_id']}, Student: {$conflict['student_id']}, Date: {$conflict['date']}\n";
            echo "   Sessions: {$conflict['timetable_ids']} (Statuses: {$conflict['statuses']})\n";
            echo "   → This is CORRECT if different sessions have different records\n";
        }
    }
    
    // 5. Check for NULL timetable_id records when sessions exist
    echo "\n=== 5. Checking for NULL timetable_id Records ===\n";
    $nullRecords = $pdo->query("
        SELECT COUNT(*) as count, 
               COUNT(DISTINCT CONCAT(class_id, '-', student_id, '-', date)) as unique_records
        FROM attendance
        WHERE timetable_id IS NULL
        AND date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ")->fetch();
    
    if ($nullRecords['count'] > 0) {
        echo "<span class='warning'>⚠ Found {$nullRecords['count']} records with NULL timetable_id</span>\n";
        echo "   ({$nullRecords['unique_records']} unique class-student-date combinations)\n";
        echo "   → These are old records without session information\n";
    } else {
        echo "<span class='success'>✓ All recent records have timetable_id set.</span>\n";
    }
    
    // 6. Check if a specific class has multiple sessions
    echo "\n=== 6. Checking for Classes with Multiple Sessions ===\n";
    $multiSessionClasses = $pdo->query("
        SELECT t.class_id, COUNT(*) as session_count, 
               GROUP_CONCAT(CONCAT(t.start_time, '-', t.end_time) ORDER BY t.start_time) as sessions
        FROM timetable t
        WHERE t.day_of_week = DAYNAME(CURDATE())
        GROUP BY t.class_id
        HAVING COUNT(*) > 1
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($multiSessionClasses)) {
        echo "   No classes found with multiple sessions today.\n";
        echo "   → Check if you've set up multiple sessions for the same class/day\n";
    } else {
        echo "   Found " . count($multiSessionClasses) . " classes with multiple sessions today:\n";
        foreach ($multiSessionClasses as $class) {
            echo "   Class ID: {$class['class_id']}, Sessions: {$class['session_count']}\n";
            echo "   Times: {$class['sessions']}\n";
        }
    }
    
    // Summary and Recommendations
    echo "\n=== 📋 SUMMARY & RECOMMENDATIONS ===\n";
    
    $issues = [];
    $fixes = [];
    
    if ($checkColumn->rowCount() == 0) {
        $issues[] = "timetable_id column missing";
        $fixes[] = "Run: admin/add_timetable_id_to_attendance.php";
    }
    
    if (!$hasSessionConstraint) {
        $issues[] = "unique_attendance_session constraint missing";
        $fixes[] = "Run: admin/add_timetable_id_to_attendance.php";
    }
    
    if ($hasOldConstraint) {
        $issues[] = "Old unique_attendance constraint still exists";
        $fixes[] = "Should be dropped by migration script";
    }
    
    if (empty($issues)) {
        echo "<span class='success'>✓ Database schema looks correct!</span>\n";
        echo "\nIf records are still mixing, the issue might be:\n";
        echo "1. Old records with NULL timetable_id being shown\n";
        echo "2. Code not filtering strictly by session\n";
        echo "3. Form not submitting session_id correctly\n";
    } else {
        echo "<span class='error'>❌ Found issues:</span>\n";
        foreach ($issues as $i => $issue) {
            echo ($i + 1) . ". $issue\n";
        }
        echo "\n<span class='warning'>🔧 Fixes needed:</span>\n";
        foreach ($fixes as $i => $fix) {
            echo ($i + 1) . ". $fix\n";
        }
    }
    
    echo "\n</pre>";
    echo "<p><a href='manage_archive.php'>← Back to Admin Panel</a></p>";
    echo "<p><a href='add_timetable_id_to_attendance.php' style='background:#28a745;color:white;padding:10px;text-decoration:none;border-radius:5px;'>🔧 Run Migration Script</a></p>";
    echo "</body></html>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    echo "</pre></body></html>";
}







