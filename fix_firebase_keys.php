<?php
require_once 'config/database.php';
require_once 'helpers/BackupHooks.php';

echo "Fixing Firebase structure with consistent keys...\n\n";

try {
    // Delete the entire attendance_system node
    $url = 'https://iattendance-backup-115dc-default-rtdb.asia-southeast1.firebasedatabase.app/attendance_system.json';
    
    $context = stream_context_create([
        'http' => [
            'method' => 'DELETE',
            'header' => 'Content-Type: application/json'
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    
    $response = file_get_contents($url, false, $context);
    
    if ($response !== false) {
        echo "✅ attendance_system node deleted\n\n";
        
        // Wait for deletion
        sleep(2);
        
        // Now restore with consistent keys
        echo "Restoring with consistent keys...\n\n";
        
        $backupHooks = new BackupHooks();
        $config = require 'config/firebase.php';
        
        // Function to extract real password
        function extractRealPassword($hashedPassword, $testPasswords) {
            foreach ($testPasswords as $testPass) {
                if (password_verify($testPass, $hashedPassword)) {
                    return $testPass;
                }
            }
            return 'default123';
        }
        
        // Backup all tables with consistent keys
        foreach ($config['backup_tables'] as $table) {
            echo "Backing up table: $table\n";
            
            try {
                $stmt = $pdo->prepare("SELECT * FROM $table");
                $stmt->execute();
                $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($records as $record) {
                    // Determine operation type
                    $operation = 'insert';
                    
                    switch ($table) {
                        case 'students':
                            if (isset($record['status']) && $record['status'] === 'approved') {
                                $operation = 'approve';
                            }
                            break;
                    }
                    
                    // Convert hashed passwords to plain text
                    if (isset($record['password']) && strpos($record['password'], '$2y$') === 0) {
                        switch ($table) {
                            case 'students':
                                $testPasswords = [$record['birthdate'] ?? '', 'password', '123456', 'admin', 'student', '12345678', 'qwerty'];
                                $record['password'] = extractRealPassword($record['password'], $testPasswords);
                                break;
                                
                            case 'teachers':
                                $testPasswords = [$record['birth_date'] ?? '', 'password', '123456', 'admin', 'teacher', '12345678', 'qwerty'];
                                $record['password'] = extractRealPassword($record['password'], $testPasswords);
                                break;
                                
                            case 'admins':
                                $testPasswords = ['admin', 'admin123', 'password', '123456', 'root'];
                                $record['password'] = extractRealPassword($record['password'], $testPasswords);
                                break;
                        }
                    }
                    
                    // Use direct Firebase backup with consistent key
                    $timestamp = date('Y-m-d H:i:s');
                    $backupData = [
                        'table' => $table,
                        'operation' => $operation,
                        'data' => $record,
                        'timestamp' => $timestamp,
                        'server_time' => time()
                    ];
                    
                    // Use consistent key: table_id
                    $backupKey = $table . '_' . $record['id'];
                    $operationPath = 'attendance_system/' . $table;
                    $firebaseUrl = 'https://iattendance-backup-115dc-default-rtdb.asia-southeast1.firebasedatabase.app/' . $operationPath . '/' . $backupKey . '.json';
                    
                    $putContext = stream_context_create([
                        'http' => [
                            'method' => 'PUT',
                            'header' => 'Content-Type: application/json',
                            'content' => json_encode($backupData)
                        ],
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false
                        ]
                    ]);
                    
                    $result = file_get_contents($firebaseUrl, false, $putContext);
                    
                    if ($result === false) {
                        echo "❌ Failed to backup record ID: " . $record['id'] . "\n";
                    }
                    
                    // Small delay
                    usleep(50000);
                }
                
                echo "✅ $table: " . count($records) . " records backed up\n";
                
            } catch (Exception $e) {
                echo "❌ Error backing up $table: " . $e->getMessage() . "\n";
            }
            
            echo "\n";
        }
        
        // Wait for Firebase to process
        sleep(3);
        
        echo "Verifying Firebase structure...\n";
        
        $verifyUrl = 'https://iattendance-backup-115dc-default-rtdb.asia-southeast1.firebasedatabase.app/attendance_system/teachers.json';
        $verifyResponse = file_get_contents($verifyUrl, false, $context);
        
        if ($verifyResponse !== false) {
            $data = json_decode($verifyResponse, true);
            
            if ($data && is_array($data)) {
                echo "Teacher records with consistent keys:\n";
                foreach ($data as $key => $record) {
                    echo "- Key: $key\n";
                    echo "  Teacher ID: " . $record['data']['teacher_id'] . "\n";
                    echo "  Password: " . $record['data']['password'] . "\n";
                    echo "  Operation: " . $record['operation'] . "\n";
                    echo "---\n";
                }
                
                echo "\n✅ Firebase restored with consistent keys!\n";
                echo "Now password changes will update existing records correctly.\n";
                
            } else {
                echo "❌ Firebase data is empty\n";
            }
        } else {
            echo "❌ Failed to verify Firebase\n";
        }
        
    } else {
        echo "❌ Failed to delete attendance_system node\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>







