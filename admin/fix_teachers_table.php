<?php
/**
 * Fix Teachers Table AUTO_INCREMENT
 * Run this once to fix the AUTO_INCREMENT issue on teachers table
 */

session_start();
require_once '../config/database.php';
require_once '../config.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    die("Unauthorized access");
}

try {
    echo "<!DOCTYPE html><html><head><title>Fix Teachers Table</title></head><body>";
    echo "<h2>Fixing Teachers Table AUTO_INCREMENT...</h2>";
    echo "<pre>";
    
    // Check current AUTO_INCREMENT status
    $checkStmt = $pdo->query("SHOW COLUMNS FROM teachers WHERE Field = 'id'");
    $columnInfo = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($columnInfo) {
        $hasAutoIncrement = (stripos($columnInfo['Extra'], 'auto_increment') !== false);
        
        echo "Current column definition:\n";
        echo "  Field: " . $columnInfo['Field'] . "\n";
        echo "  Type: " . $columnInfo['Type'] . "\n";
        echo "  Extra: " . $columnInfo['Extra'] . "\n\n";
        
        // Get the current max ID
        $maxIdStmt = $pdo->query("SELECT COALESCE(MAX(id), 0) as max_id FROM teachers");
        $maxId = $maxIdStmt->fetchColumn();
        $nextId = $maxId + 1;
        
        echo "Current max ID in table: $maxId\n";
        echo "Next ID should be: $nextId\n\n";
        
        if (!$hasAutoIncrement) {
            echo "⚠ AUTO_INCREMENT is NOT enabled. Fixing now...\n\n";
            
            // Add AUTO_INCREMENT to the id column
            $alterQuery = "ALTER TABLE teachers MODIFY id INT(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT = $nextId";
            $pdo->exec($alterQuery);
            
            echo "✓ Successfully enabled AUTO_INCREMENT on teachers.id\n";
            echo "✓ AUTO_INCREMENT will start at: $nextId\n\n";
        } else {
            echo "✓ AUTO_INCREMENT is already enabled.\n";
            echo "Updating AUTO_INCREMENT value to: $nextId\n\n";
            
            // Update AUTO_INCREMENT value to be safe
            $alterQuery = "ALTER TABLE teachers AUTO_INCREMENT = $nextId";
            $pdo->exec($alterQuery);
            
            echo "✓ AUTO_INCREMENT value updated to: $nextId\n\n";
        }
        
        // Verify the fix
        $verifyStmt = $pdo->query("SHOW COLUMNS FROM teachers WHERE Field = 'id'");
        $verifyInfo = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        
        echo "Verification after fix:\n";
        echo "  Field: " . $verifyInfo['Field'] . "\n";
        echo "  Type: " . $verifyInfo['Type'] . "\n";
        echo "  Extra: " . $verifyInfo['Extra'] . "\n\n";
        
        // Check for any records with id = 0
        $checkZeroStmt = $pdo->query("SELECT COUNT(*) as count FROM teachers WHERE id = 0");
        $zeroCount = $checkZeroStmt->fetchColumn();
        
        if ($zeroCount > 0) {
            echo "⚠ Warning: Found $zeroCount record(s) with id = 0. This may cause issues.\n";
            echo "   Consider updating these records manually.\n\n";
        }
        
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "✓ Fix completed successfully!\n";
        echo "  You can now add teachers normally.\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
    } else {
        echo "❌ ERROR: Could not find 'id' column in teachers table!\n";
    }
    
    echo "</pre>";
    echo "<a href='manage_teachers.php' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>← Back to Manage Teachers</a>";
    echo "</body></html>";
    
} catch (PDOException $e) {
    echo "<h2>Error</h2>";
    echo "<pre>Database error: " . $e->getMessage() . "\n";
    echo "Please check your database connection and try again.</pre>";
    echo "<a href='manage_teachers.php'>← Back to Manage Teachers</a>";
}







