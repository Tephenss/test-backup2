<?php
/**
 * Sync Firebase Students with MySQL Database
 * 
 * This script:
 * 1. Removes students from Firebase that don't exist in MySQL (or are deleted)
 * 2. Adds students to Firebase that exist in MySQL but are missing in Firebase
 */

session_start();
require_once '../config/database.php';
require_once '../config.php';
require_once '../helpers/BackupHooks.php';
require_once '../helpers/FirebaseBackup.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    die("Unauthorized access");
}

try {
    echo "<!DOCTYPE html><html><head><title>Sync Firebase Students</title></head><body>";
    echo "<h2>Syncing Firebase Students with MySQL Database...</h2>";
    echo "<pre>";
    
    $firebaseConfig = require '../config/firebase.php';
    if (!$firebaseConfig['backup_enabled']) {
        echo "Firebase backup is disabled. Please enable it in config/firebase.php\n";
        exit;
    }
    
    $firebaseBaseUrl = rtrim($firebaseConfig['database_url'], '/');
    $firebaseBackup = new FirebaseBackup();
    $backupHooks = new BackupHooks();
    
    // Step 1: Get all active students from MySQL
    $mysqlStmt = $pdo->prepare("
        SELECT * FROM students 
        WHERE status NOT IN ('graduated', 'promoted', 'deleted')
        AND (is_deleted = 0 OR is_deleted IS NULL)
        ORDER BY id
    ");
    $mysqlStmt->execute();
    $mysqlStudents = $mysqlStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($mysqlStudents) . " active students in MySQL.\n\n";
    
    // Step 2: Get all students from Firebase
    $firebaseUrl = $firebaseBaseUrl . '/attendance_system/students.json';
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ],
        'http' => [
            'timeout' => 30
        ]
    ]);
    
    $firebaseResponse = @file_get_contents($firebaseUrl, false, $context);
    $firebaseStudents = [];
    
    if ($firebaseResponse !== false) {
        $firebaseData = json_decode($firebaseResponse, true);
        if (is_array($firebaseData)) {
            foreach ($firebaseData as $key => $record) {
                $data = null;
                if (isset($record['data']) && is_array($record['data'])) {
                    $data = $record['data'];
                } elseif (is_array($record)) {
                    $data = $record;
                }
                
                if ($data && isset($data['id'])) {
                    $firebaseStudents[$data['id']] = [
                        'key' => $key,
                        'data' => $data
                    ];
                }
            }
        }
    }
    
    echo "Found " . count($firebaseStudents) . " students in Firebase.\n\n";
    
    // Step 3: Create a map of MySQL student IDs
    $mysqlStudentIds = [];
    foreach ($mysqlStudents as $student) {
        $mysqlStudentIds[$student['id']] = $student;
    }
    
    // Step 4: Remove students from Firebase that don't exist in MySQL or are deleted
    echo "Step 1: Removing non-existent students from Firebase...\n";
    $removedCount = 0;
    foreach ($firebaseStudents as $firebaseId => $firebaseStudent) {
        $studentId = $firebaseStudent['data']['id'];
        
        // Check if student exists in MySQL and is active
        if (!isset($mysqlStudentIds[$studentId])) {
            // Student doesn't exist in MySQL - remove from Firebase
            $deleteUrl = $firebaseBaseUrl . '/attendance_system/students/' . $firebaseStudent['key'] . '.json';
            $deleteContext = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ],
                'http' => [
                    'method' => 'DELETE',
                    'timeout' => 10
                ]
            ]);
            
            @file_get_contents($deleteUrl, false, $deleteContext);
            $removedCount++;
            echo "  - Removed student ID {$studentId} (not found in MySQL)\n";
        } else {
            // Check if student is deleted/graduated/promoted in MySQL
            $mysqlStudent = $mysqlStudentIds[$studentId];
            if (in_array($mysqlStudent['status'], ['graduated', 'promoted', 'deleted']) || 
                ($mysqlStudent['is_deleted'] == 1)) {
                // Student is deleted/graduated/promoted - remove from Firebase
                $deleteUrl = $firebaseBaseUrl . '/attendance_system/students/' . $firebaseStudent['key'] . '.json';
                $deleteContext = stream_context_create([
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false
                    ],
                    'http' => [
                        'method' => 'DELETE',
                        'timeout' => 10
                    ]
                ]);
                
                @file_get_contents($deleteUrl, false, $deleteContext);
                $removedCount++;
                echo "  - Removed student ID {$studentId} (deleted/graduated/promoted in MySQL)\n";
            }
        }
    }
    
    echo "Removed {$removedCount} students from Firebase.\n\n";
    
    // Step 5: Add missing students to Firebase
    echo "Step 2: Adding missing students to Firebase...\n";
    $addedCount = 0;
    $errorCount = 0;
    
    foreach ($mysqlStudents as $student) {
        $studentId = $student['id'];
        
        // Check if student exists in Firebase
        $existsInFirebase = false;
        foreach ($firebaseStudents as $firebaseStudent) {
            if (isset($firebaseStudent['data']['id']) && $firebaseStudent['data']['id'] == $studentId) {
                $existsInFirebase = true;
                break;
            }
        }
        
        if (!$existsInFirebase) {
            // Student doesn't exist in Firebase - add it
            try {
                $backupHooks->backupStudentRegistration($student);
                $addedCount++;
                echo "  + Added student ID {$studentId} ({$student['first_name']} {$student['last_name']})\n";
            } catch (Exception $e) {
                $errorCount++;
                echo "  ✗ Error adding student ID {$studentId}: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "Added {$addedCount} students to Firebase.\n";
    if ($errorCount > 0) {
        echo "Encountered {$errorCount} errors.\n";
    }
    
    echo "\n=== Sync Complete ===\n";
    echo "MySQL Students: " . count($mysqlStudents) . "\n";
    echo "Firebase Students (before): " . count($firebaseStudents) . "\n";
    echo "Removed from Firebase: {$removedCount}\n";
    echo "Added to Firebase: {$addedCount}\n";
    
    echo "</pre>";
    echo "<p><a href='manage_students.php'>Back to Manage Students</a></p>";
    echo "</body></html>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "</pre></body></html>";
}

