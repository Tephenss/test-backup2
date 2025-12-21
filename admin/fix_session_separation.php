<?php
/**
 * Comprehensive fix for session separation issues
 * This script will:
 * 1. Check and fix database schema
 * 2. Verify unique constraints
 * 3. Clean up old records if needed
 * 4. Test the separation
 */

session_start();
require_once '../config/database.php';
require_once '../config.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    die("Unauthorized access");
}

try {
    echo "<!DOCTYPE html><html><head><title>Fix Session Separation</title>";
    echo "<style>body{font-family:monospace;padding:20px;} .error{color:red;font-weight:bold;} .success{color:green;font-weight:bold;} .warning{color:orange;} .info{color:blue;}</style>";
    echo "</head><body>";
    echo "<h2>🔧 Comprehensive Session Separation Fix</h2>";
    echo "<pre>";
    
    $fixes_applied = [];
    $errors = [];
    
    // 1. Check and add timetable_id column
    echo "\n=== STEP 1: Checking timetable_id Column ===\n";
    $checkColumn = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'timetable_id'");
    if ($checkColumn->rowCount() == 0) {
        echo "<span class='warning'>Column 'timetable_id' does NOT exist. Adding it...</span>\n";
        try {
            $pdo->exec("ALTER TABLE attendance ADD COLUMN timetable_id INT NULL AFTER class_id");
            echo "<span class='success'>✓ Added timetable_id column.</span>\n";
            $fixes_applied[] = "Added timetable_id column";
        } catch (Exception $e) {
            echo "<span class='error'>✗ Failed to add column: " . $e->getMessage() . "</span>\n";
            $errors[] = "Failed to add timetable_id column";
        }
    } else {
        echo "<span class='success'>✓ timetable_id column exists.</span>\n";
    }
    
    // 2. Drop old unique constraint
    echo "\n=== STEP 2: Removing Old Unique Constraint ===\n";
    $indexes = $pdo->query("SHOW INDEXES FROM attendance WHERE Key_name = 'unique_attendance'")->fetchAll();
    if (!empty($indexes)) {
        echo "<span class='warning'>Old unique constraint found. Dropping it...</span>\n";
        try {
            $pdo->exec("ALTER TABLE attendance DROP INDEX unique_attendance");
            echo "<span class='success'>✓ Dropped old unique constraint.</span>\n";
            $fixes_applied[] = "Dropped old unique_attendance constraint";
        } catch (Exception $e) {
            echo "<span class='warning'>⚠ Could not drop (may not exist or already dropped): " . $e->getMessage() . "</span>\n";
        }
    } else {
        echo "<span class='success'>✓ Old constraint already removed.</span>\n";
    }
    
    // 3. Add new unique constraint with timetable_id
    echo "\n=== STEP 3: Adding New Unique Constraint ===\n";
    $checkNewConstraint = $pdo->query("SHOW INDEXES FROM attendance WHERE Key_name = 'unique_attendance_session'")->fetchAll();
    if (empty($checkNewConstraint)) {
        echo "<span class='warning'>New unique constraint missing. Adding it...</span>\n";
        try {
            // Ensure timetable_id allows NULL
            $pdo->exec("ALTER TABLE attendance MODIFY COLUMN timetable_id INT NULL");
            
            // Add unique constraint
            $pdo->exec("ALTER TABLE attendance ADD UNIQUE KEY unique_attendance_session (class_id, student_id, date, timetable_id)");
            echo "<span class='success'>✓ Added unique constraint with timetable_id.</span>\n";
            echo "   This allows separate records per session.\n";
            $fixes_applied[] = "Added unique_attendance_session constraint";
        } catch (Exception $e) {
            echo "<span class='error'>✗ Failed to add constraint: " . $e->getMessage() . "</span>\n";
            $errors[] = "Failed to add unique constraint";
        }
    } else {
        echo "<span class='success'>✓ New unique constraint already exists.</span>\n";
        
        // Verify it includes timetable_id
        $constraintCols = $pdo->query("
            SELECT Column_name 
            FROM information_schema.STATISTICS 
            WHERE table_schema = DATABASE() 
            AND table_name = 'attendance' 
            AND index_name = 'unique_attendance_session'
            ORDER BY seq_in_index
        ")->fetchAll(PDO::FETCH_COLUMN);
        
        if (in_array('timetable_id', $constraintCols)) {
            echo "   Columns: " . implode(', ', $constraintCols) . "\n";
            echo "<span class='success'>✓ Constraint includes timetable_id (correct).</span>\n";
        } else {
            echo "<span class='error'>✗ Constraint does NOT include timetable_id!</span>\n";
            echo "   Columns found: " . implode(', ', $constraintCols) . "\n";
            $errors[] = "Constraint missing timetable_id";
        }
    }
    
    // 4. Add index for performance
    echo "\n=== STEP 4: Adding Index for Performance ===\n";
    $checkIndex = $pdo->query("SHOW INDEXES FROM attendance WHERE Key_name = 'idx_attendance_timetable'")->fetchAll();
    if (empty($checkIndex)) {
        try {
            $pdo->exec("CREATE INDEX idx_attendance_timetable ON attendance(timetable_id)");
            echo "<span class='success'>✓ Added index on timetable_id.</span>\n";
            $fixes_applied[] = "Added timetable_id index";
        } catch (Exception $e) {
            echo "<span class='warning'>⚠ Could not add index: " . $e->getMessage() . "</span>\n";
        }
    } else {
        echo "<span class='success'>✓ Index already exists.</span>\n";
    }
    
    // 5. Check for problematic records
    echo "\n=== STEP 5: Checking for Problematic Records ===\n";
    
    // Check for records with NULL timetable_id
    $nullCount = $pdo->query("
        SELECT COUNT(*) 
        FROM attendance 
        WHERE timetable_id IS NULL 
        AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ")->fetchColumn();
    
    if ($nullCount > 0) {
        echo "<span class='warning'>⚠ Found $nullCount records with NULL timetable_id (last 30 days).</span>\n";
        echo "   These are old records without session information.\n";
        echo "   They won't interfere with new session-based records.\n";
    } else {
        echo "<span class='success'>✓ No recent NULL records found.</span>\n";
    }
    
    // Check for duplicate records (same class/student/date without timetable_id separation)
    $duplicates = $pdo->query("
        SELECT class_id, student_id, date, COUNT(*) as count
        FROM attendance
        WHERE timetable_id IS NULL
        AND date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY class_id, student_id, date
        HAVING COUNT(*) > 1
    ")->fetchAll();
    
    if (!empty($duplicates)) {
        echo "<span class='warning'>⚠ Found " . count($duplicates) . " duplicate records (same class/student/date, NULL timetable_id).</span>\n";
        echo "   These need to be cleaned up.\n";
    } else {
        echo "<span class='success'>✓ No duplicate records found.</span>\n";
    }
    
    // 6. Test query to verify separation works
    echo "\n=== STEP 6: Testing Query Separation ===\n";
    $testQuery = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM attendance
        WHERE class_id = ? AND date = ? AND timetable_id = ?
    ");
    
    // Try with a dummy test (won't fail if no data)
    try {
        $testQuery->execute([999999, '2000-01-01', 999999]);
        echo "<span class='success'>✓ Query structure is correct.</span>\n";
    } catch (Exception $e) {
        echo "<span class='error'>✗ Query test failed: " . $e->getMessage() . "</span>\n";
        $errors[] = "Query test failed";
    }
    
    // Summary
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "<span class='info'>📋 SUMMARY</span>\n";
    echo str_repeat("=", 60) . "\n";
    
    if (!empty($fixes_applied)) {
        echo "\n<span class='success'>✅ Fixes Applied:</span>\n";
        foreach ($fixes_applied as $fix) {
            echo "  • $fix\n";
        }
    }
    
    if (!empty($errors)) {
        echo "\n<span class='error'>❌ Errors:</span>\n";
        foreach ($errors as $error) {
            echo "  • $error\n";
        }
    }
    
    if (empty($errors) && empty($fixes_applied)) {
        echo "\n<span class='success'>✓ Database schema is already correct!</span>\n";
    }
    
    // Final verification
    echo "\n=== FINAL VERIFICATION ===\n";
    $finalCheck = true;
    
    $checkCol = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'timetable_id'");
    if ($checkCol->rowCount() == 0) {
        echo "<span class='error'>✗ timetable_id column still missing!</span>\n";
        $finalCheck = false;
    }
    
    $checkConstraint = $pdo->query("SHOW INDEXES FROM attendance WHERE Key_name = 'unique_attendance_session'")->fetchAll();
    if (empty($checkConstraint)) {
        echo "<span class='error'>✗ unique_attendance_session constraint still missing!</span>\n";
        $finalCheck = false;
    } else {
        $cols = $pdo->query("
            SELECT Column_name 
            FROM information_schema.STATISTICS 
            WHERE table_schema = DATABASE() 
            AND table_name = 'attendance' 
            AND index_name = 'unique_attendance_session'
            ORDER BY seq_in_index
        ")->fetchAll(PDO::FETCH_COLUMN);
        
        if (!in_array('timetable_id', $cols)) {
            echo "<span class='error'>✗ Constraint does not include timetable_id!</span>\n";
            $finalCheck = false;
        }
    }
    
    if ($finalCheck) {
        echo "\n<span class='success'>✅ ALL CHECKS PASSED!</span>\n";
        echo "\n<span class='info'>Next steps:</span>\n";
        echo "1. Go to teacher/manage_attendance.php\n";
        echo "2. Select a class with multiple sessions\n";
        echo "3. Select a session from the dropdown\n";
        echo "4. Record attendance - it should only affect that session\n";
        echo "5. Switch to another session - records should be separate\n";
    } else {
        echo "\n<span class='error'>❌ Some issues remain. Please check the errors above.</span>\n";
    }
    
    echo "\n</pre>";
    echo "<p><a href='diagnose_session_issue.php' style='background:#007bff;color:white;padding:10px;text-decoration:none;border-radius:5px;margin-right:10px;'>🔍 Run Diagnostic</a>";
    echo "<a href='manage_archive.php' style='background:#6c757d;color:white;padding:10px;text-decoration:none;border-radius:5px;'>← Back to Admin Panel</a></p>";
    echo "</body></html>";
    
} catch (Exception $e) {
    echo "<span class='error'>FATAL ERROR: " . $e->getMessage() . "</span>\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    echo "</pre></body></html>";
}







