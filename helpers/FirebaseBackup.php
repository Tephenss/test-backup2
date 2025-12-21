<?php
/**
 * Firebase Database Backup Service
 * 
 * This class handles automatic backup of MySQL data to Firebase Realtime Database.
 * It provides methods to backup individual records and entire tables.
 */

require_once __DIR__ . '/../config/firebase.php';

class FirebaseBackup {
    private $config;
    private $accessToken;
    private $tokenExpiry;
    
    public function __construct() {
        $this->config = require __DIR__ . '/../config/firebase.php';
        $this->accessToken = null;
        $this->tokenExpiry = null;
    }
    
    /**
     * Get Firebase access token
     */
    private function getAccessToken() {
        // Check if token is still valid
        if ($this->accessToken && $this->tokenExpiry && time() < $this->tokenExpiry) {
            return $this->accessToken;
        }
        
        // Generate new token
        $jwt = $this->generateJWT();
        
        $postData = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->config['token_uri']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception('Failed to get Firebase access token. HTTP Code: ' . $httpCode . ' Response: ' . $response);
        }
        
        $data = json_decode($response, true);
        
        if (isset($data['access_token'])) {
            $this->accessToken = $data['access_token'];
            $this->tokenExpiry = time() + $data['expires_in'] - 60; // 60 seconds buffer
            return $this->accessToken;
        }
        
        throw new Exception('Failed to get Firebase access token: ' . $response);
    }
    
    /**
     * Generate JWT token for Firebase authentication
     */
    private function generateJWT() {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'RS256']);
        $now = time();
        $payload = json_encode([
            'iss' => $this->config['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.database',
            'aud' => $this->config['token_uri'],
            'exp' => $now + 3600,
            'iat' => $now
        ]);
        
        $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        
        $signature = '';
        $success = openssl_sign($base64Header . '.' . $base64Payload, $signature, $this->config['private_key'], 'SHA256');
        
        if (!$success) {
            throw new Exception('Failed to sign JWT');
        }
        
        $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        return $base64Header . '.' . $base64Payload . '.' . $base64Signature;
    }
    
    /**
     * Find existing record key for updates
     */
    private function findExistingRecord($table, $userId) {
        try {
            // For teachers, check the consistent key format first
            if ($table === 'teachers' && $userId) {
                $consistentKey = "teachers_{$userId}";
                $url = $this->config['database_url'] . 'attendance_system/' . $table . '/' . $consistentKey . '.json';
                
                $context = stream_context_create([
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false
                    ]
                ]);
                
                $response = file_get_contents($url, false, $context);
                if ($response !== false && $response !== 'null') {
                    $record = json_decode($response, true);
                    if ($record && isset($record['data']['id']) && $record['data']['id'] == $userId) {
                        return $consistentKey;
                    }
                }
            }
            
            // Fallback: search through all records
            $url = $this->config['database_url'] . 'attendance_system/' . $table . '.json';
            
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]);
            
            $response = file_get_contents($url, false, $context);
            
            if ($response !== false) {
                $data = json_decode($response, true);
                
                if ($data && is_array($data)) {
                    $latestRecord = null;
                    $latestTimestamp = 0;
                    
                    // Look for existing record with this user ID
                    foreach ($data as $key => $record) {
                        if (isset($record['data']['id']) && $record['data']['id'] == $userId) {
                            // Find the most recent record (highest timestamp)
                            $timestamp = $record['server_time'] ?? 0;
                            if ($timestamp > $latestTimestamp) {
                                $latestTimestamp = $timestamp;
                                $latestRecord = $key;
                            }
                        }
                    }
                    
                    return $latestRecord;
                }
            }
        } catch (Exception $e) {
            error_log("Error finding existing record: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Get Firebase path based on table and operation
     */
    private function getOperationPath($table, $operation) {
        // Use exact MySQL database structure
        return 'attendance_system/' . $table;
    }
    
    /**
     * Backup a single record to Firebase
     */
    public function backupRecord($table, $data, $operation = 'insert') {
        if (!$this->config['backup_enabled']) {
            return true;
        }
        
        try {
            // Handle deletions differently - actually delete from Firebase
            if ($operation === 'deletion' || $operation === 'delete') {
                return $this->deleteRecordFromFirebase($table, $data);
            }
            
            // Using public access - no token needed
            $timestamp = date('Y-m-d H:i:s');
            
            // Prepare backup data
            $backupData = [
                'table' => $table,
                'operation' => $operation,
                'data' => $data,
                'timestamp' => $timestamp,
                'server_time' => time()
            ];
            
            // Generate key based on table type
            // For attendance: use consistent key (class_id + student_id + date + timetable_id if exists) to allow updates
            // IMPORTANT: Include timetable_id (session ID) in key to ensure separate records per session
            if ($table === 'attendance') {
                $classId = $data['class_id'] ?? '';
                $studentId = $data['student_id'] ?? '';
                $date = isset($data['date']) ? str_replace('-', '', $data['date']) : date('Ymd');
                $timetableId = $data['timetable_id'] ?? null;
                
                // Consistent key: attendance_classId_studentId_date (without session) OR attendance_classId_studentId_date_sessionId (with session)
                // This ensures each session has its own independent attendance record
                if ($timetableId !== null && $timetableId !== '') {
                    $backupKey = "attendance_{$classId}_{$studentId}_{$date}_{$timetableId}";
                } else {
                    $backupKey = "attendance_{$classId}_{$studentId}_{$date}";
                }
            } elseif ($table === 'announcements') {
                // For announcements: use consistent key based on ID for updates/deletes
                $announcementId = $data['id'] ?? '';
                $backupKey = "announcements_{$announcementId}";
            } elseif ($table === 'teachers') {
                // For teachers: use consistent key based on ID for easier lookup
                $teacherId = $data['id'] ?? '';
                if ($teacherId) {
                    $backupKey = "teachers_{$teacherId}";
                } else {
                    // Fallback for inserts without ID yet
                    $existingKey = $this->findExistingRecord($table, $data['id'] ?? null);
                    if ($existingKey) {
                        $backupKey = $existingKey;
                    } else {
                        $backupKey = $table . '_' . uniqid() . '_' . time();
                    }
                }
            } elseif (in_array($operation, ['password_change', 'account_recovery', 'update', 'approve'])) {
                // For updates, try to find existing record first
                $existingKey = $this->findExistingRecord($table, $data['id'] ?? null);
                if ($existingKey) {
                    // Update existing record
                    $backupKey = $existingKey;
                } else {
                    // Create new record with consistent key
                    $backupKey = $table . '_' . ($data['id'] ?? uniqid());
                }
            } else {
                // For other inserts, use unique key with timestamp
                $backupKey = $table . '_' . ($data['id'] ?? uniqid()) . '_' . time();
            }
            
            // Organize by operation type instead of table
            $operationPath = $this->getOperationPath($table, $operation);
            $url = $this->config['database_url'] . $operationPath . '/' . $backupKey . '.json';
            
            $context = stream_context_create([
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
            
            $response = file_get_contents($url, false, $context);
            
            if ($response === false) {
                throw new Exception('Failed to backup record to Firebase');
            }
            
            $this->logBackupOperation('SUCCESS', $table, $operation, $backupKey);
            return true;
            
        } catch (Exception $e) {
            $this->logBackupOperation('ERROR', $table, $operation, $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete record from Firebase
     */
    private function deleteRecordFromFirebase($table, $data) {
        try {
            // Find all records with matching ID in the table
            $recordId = $data['id'] ?? null;
            $targetClassId = $data['class_id'] ?? null;
            $targetStudentId = $data['student_id'] ?? null;
            $targetDate = $data['date'] ?? null;
            $targetTimetableId = isset($data['timetable_id']) ? (string)$data['timetable_id'] : null; // Session ID
            $normalizedTargetDate = $targetDate ? $this->normalizeDateValue($targetDate) : null;
            
            // For attendance table: allow deletion without ID if we have class_id, student_id, date, and timetable_id
            if (!$recordId && $table === 'attendance' && $targetClassId && $targetStudentId && $normalizedTargetDate && $targetTimetableId) {
                error_log("Attempting to delete attendance from Firebase by session (no ID required): class={$targetClassId}, student={$targetStudentId}, date={$normalizedTargetDate}, session={$targetTimetableId}");
            } elseif (!$recordId) {
                error_log("No ID provided for Firebase deletion");
                return false;
            } else {
                error_log("Attempting to delete from Firebase: table={$table}, id={$recordId}");
            }
            
            // Build URL to query Firebase
            $url = $this->config['database_url'] . 'attendance_system/' . $table . '.json';
            
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]);
            
            $response = @file_get_contents($url, false, $context);
            
            if ($response === false) {
                error_log("Failed to fetch records from Firebase for deletion");
                return false;
            }
            
            $records = json_decode($response, true);
            
            if (!$records || !is_array($records)) {
                // No records to delete
                error_log("No records found in Firebase for table {$table}");
                return true;
            }
            
            error_log("Found " . count($records) . " records in Firebase for table {$table}");
            
            // Log first record structure for debugging
            if (!empty($records)) {
                $firstRecord = reset($records);
                error_log("Sample record structure: " . json_encode($firstRecord));
            }
            
            // Find and delete ONE matching record - STRICT MATCHING
            $deletedCount = 0;
            $foundMatch = false;
            
            error_log("Searching for record with ID: {$recordId} in table: {$table}");
            
            foreach ($records as $key => $record) {
                // Skip if we already found and deleted a match
                if ($foundMatch) {
                    break;
                }
                
                $shouldDelete = false;
                $matchReason = '';
                
                // For attendance records: ALWAYS check session ID, even when matching by ID
                // This prevents deleting records from other sessions
                if ($table === 'attendance') {
                    // For attendance, we MUST verify session ID before deleting
                    // Skip ID-only matching to prevent cross-session deletion
                    
                    // Check by key format first (most reliable for session-specific deletion)
                    if (strpos($key, 'attendance_') === 0) {
                        $keyParts = explode('_', $key);
                        if (count($keyParts) >= 5) {
                            // Has session ID in key format: attendance_{classId}_{studentId}_{date}_{sessionId}
                            $keyClassId = $keyParts[1] ?? '';
                            $keyStudentId = $keyParts[2] ?? '';
                            $keyDate = $keyParts[3] ?? '';
                            $keySessionId = $keyParts[4] ?? '';
                            
                            // Check if key matches all criteria INCLUDING session ID
                            $keyClassMatches = ($targetClassId && ($keyClassId == $targetClassId || (int)$keyClassId == (int)$targetClassId));
                            $keyStudentMatches = ($targetStudentId && ($keyStudentId == $targetStudentId || (int)$keyStudentId == (int)$targetStudentId));
                            $keyDateMatches = ($normalizedTargetDate && $keyDate == $normalizedTargetDate);
                            
                            // CRITICAL: Session ID must match if timetable_id is provided
                            $keySessionMatches = true;
                            if ($targetTimetableId !== null && $targetTimetableId !== '') {
                                $keySessionMatches = ($keySessionId == $targetTimetableId || (int)$keySessionId == (int)$targetTimetableId);
                            }
                            
                            // Also check if ID matches (for confirmation)
                            $keyIdMatches = false;
                            $recordData = isset($record['data']) && is_array($record['data']) ? $record['data'] : $record;
                            if (isset($recordData['id']) && $recordData['id'] == $recordId) {
                                $keyIdMatches = true;
                            }
                            
                            if ($keyClassMatches && $keyStudentMatches && $keyDateMatches && $keySessionMatches) {
                                // For attendance deletion, session match is the primary requirement
                                // ID match is optional but preferred for safety
                                $shouldDelete = true;
                                $matchReason = 'attendance_key_format_with_session';
                                error_log("Firebase deletion match found (key format with session): key={$key}, class_id={$keyClassId}, student_id={$keyStudentId}, date={$keyDate}, session_id={$keySessionId}, target_session={$targetTimetableId}, id_match=" . ($keyIdMatches ? 'yes' : 'no'));
                            } else {
                                // Log why it didn't match
                                error_log("Firebase deletion NO MATCH: key={$key}, class_match=" . ($keyClassMatches ? 'yes' : 'no') . 
                                          ", student_match=" . ($keyStudentMatches ? 'yes' : 'no') . 
                                          ", date_match=" . ($keyDateMatches ? 'yes' : 'no') . 
                                          ", session_match=" . ($keySessionMatches ? 'yes' : 'no') . 
                                          " (key_session={$keySessionId}, target_session={$targetTimetableId})");
                            }
                        }
                    }
                } else {
                    // For other tables: use ID matching (normal behavior)
                    // STRICT MATCHING: Only delete if ID matches exactly
                    // Priority 1: Check data.id (most reliable)
                    if (isset($record['data']['id']) && $record['data']['id'] == $recordId) {
                        $shouldDelete = true;
                        $matchReason = 'data.id';
                        error_log("Firebase deletion match found (data.id): key={$key}, id=" . $record['data']['id']);
                    }
                    // Priority 2: Check root id (fallback)
                    elseif (isset($record['id']) && $record['id'] == $recordId) {
                        $shouldDelete = true;
                        $matchReason = 'root.id';
                        error_log("Firebase deletion match found (root.id): key={$key}, id=" . $record['id']);
                    }
                    // Priority 3: Check key pattern (last resort, but still strict)
                    elseif (preg_match('/_' . preg_quote($recordId, '/') . '(_|$)/', $key)) {
                        $shouldDelete = true;
                        $matchReason = 'key.pattern';
                        error_log("Firebase deletion match found (key pattern): key={$key}");
                    }
                }
                
                // Additional check for attendance: verify by data content if key format check didn't work
                if (!$shouldDelete && $table === 'attendance' && $targetClassId && $targetStudentId && $normalizedTargetDate) {
                    $recordData = isset($record['data']) && is_array($record['data']) ? $record['data'] : $record;
                    $recordClassId = $recordData['class_id'] ?? $recordData['classId'] ?? null;
                    $recordStudentId = $recordData['student_id'] ?? $recordData['studentId'] ?? null;
                    $recordDate = isset($recordData['date']) ? $this->normalizeDateValue($recordData['date']) : null;
                    $recordTimetableId = isset($recordData['timetable_id']) ? (string)$recordData['timetable_id'] : null;
                    $recordDataId = isset($recordData['id']) ? $recordData['id'] : null;
                    
                    // STRICT matching: class_id, student_id, date, AND timetable_id (if provided) must ALL match
                    $classMatches = ($recordClassId == $targetClassId || (int)$recordClassId == (int)$targetClassId);
                    $studentMatches = ($recordStudentId == $targetStudentId || (int)$recordStudentId == (int)$targetStudentId);
                    $dateMatches = ($recordDate == $normalizedTargetDate);
                    $idMatches = ($recordId && $recordDataId && $recordDataId == $recordId);
                    
                    // CRITICAL: Session ID must match if timetable_id is provided
                    $sessionMatches = true;
                    if ($targetTimetableId !== null && $targetTimetableId !== '') {
                        $sessionMatches = ($recordTimetableId == $targetTimetableId || (int)$recordTimetableId == (int)$targetTimetableId);
                    } elseif ($recordTimetableId !== null && $recordTimetableId !== '') {
                        // Target has no session but record has session - don't match
                        $sessionMatches = false;
                    }
                    
                    if ($classMatches && $studentMatches && $dateMatches && $sessionMatches && $idMatches) {
                        $shouldDelete = true;
                        $matchReason = 'attendance_data_content_with_session';
                        error_log("Firebase deletion match found (data content): key={$key}, class_id={$recordClassId}, student_id={$recordStudentId}, date={$recordDate}, timetable_id={$recordTimetableId}, id={$recordDataId}");
                    }
                }
                
                if ($shouldDelete && !$foundMatch) {
                    // Delete this specific record
                    $deleteUrl = $this->config['database_url'] . 'attendance_system/' . $table . '/' . urlencode($key) . '.json';
                    
                    error_log("Attempting to delete Firebase record: key={$key}, reason={$matchReason}, ID={$recordId}");
                    
                    // Use cURL for DELETE request
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $deleteUrl);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                    
                    $deleteResponse = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    
                    error_log("DELETE response: HTTP {$httpCode}");
                    
                    if ($deleteResponse !== false && $httpCode >= 200 && $httpCode < 300) {
                        $deletedCount++;
                        $foundMatch = true; // Mark that we found and deleted a match
                        error_log("✓ Successfully deleted Firebase record: key={$key} (ID: {$recordId}, reason: {$matchReason})");
                        
                        // Only delete ONE record - break after first successful deletion
                        // This prevents multiple records from being deleted
                        break;
                    } else {
                        error_log("✗ Failed to delete Firebase record: key={$key}, HTTP: {$httpCode}");
                    }
                }
            }
            
            if ($deletedCount > 1) {
                error_log("WARNING: Multiple Firebase records deleted for {$table} ID {$recordId} (count: {$deletedCount})");
            } elseif ($deletedCount == 0) {
                error_log("WARNING: No Firebase record found to delete for {$table} ID {$recordId}");
            } else {
                error_log("✓ Successfully deleted {$deletedCount} record(s) from Firebase for {$table} ID {$recordId}");
            }
            
            return $deletedCount > 0;
            
        } catch (Exception $e) {
            error_log("Error deleting record from Firebase: " . $e->getMessage());
            return false;
        }
    }
    
    private function normalizeDateValue($dateString) {
        if (!$dateString) {
            return '';
        }
        $dateString = trim($dateString);
        if (preg_match('/^\d{8}$/', $dateString)) {
            return substr($dateString, 0, 4) . '-' . substr($dateString, 4, 2) . '-' . substr($dateString, 6, 2);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $dateString)) {
            return substr($dateString, 0, 10);
        }
        if (preg_match('/^\d{4}\/\d{2}\/\d{2}/', $dateString)) {
            return substr($dateString, 0, 4) . '-' . substr($dateString, 5, 2) . '-' . substr($dateString, 8, 2);
        }
        return substr($dateString, 0, 10);
    }
    
    /**
     * Backup entire table to Firebase
     */
    public function backupTable($table, $pdo) {
        if (!$this->config['backup_enabled'] || !in_array($table, $this->config['backup_tables'])) {
            return true;
        }
        
        try {
            $stmt = $pdo->query("SELECT * FROM $table");
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Using public access - no token needed
            $timestamp = date('Y-m-d H:i:s');
            
            $backupData = [
                'table' => $table,
                'operation' => 'full_backup',
                'records' => $records,
                'record_count' => count($records),
                'timestamp' => $timestamp,
                'server_time' => time()
            ];
            
            $backupKey = 'full_backup_' . $table . '_' . time();
            $url = $this->config['database_url'] . 'backups/' . $backupKey . '.json';
            
            $context = stream_context_create([
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
            
            $response = file_get_contents($url, false, $context);
            
            if ($response === false) {
                throw new Exception('Failed to backup table to Firebase');
            }
            
            $this->logBackupOperation('SUCCESS', $table, 'full_backup', $backupKey);
            return true;
            
        } catch (Exception $e) {
            $this->logBackupOperation('ERROR', $table, 'full_backup', $e->getMessage());
            return false;
        }
    }
    
    /**
     * Backup multiple records in batch
     */
    public function backupBatch($table, $records, $operation = 'batch_insert') {
        if (!$this->config['backup_enabled']) {
            return true;
        }
        
        try {
            // Using public access - no token needed
            $timestamp = date('Y-m-d H:i:s');
            
            $backupData = [
                'table' => $table,
                'operation' => $operation,
                'records' => $records,
                'record_count' => count($records),
                'timestamp' => $timestamp,
                'server_time' => time()
            ];
            
            $backupKey = 'batch_' . $table . '_' . time();
            $url = $this->config['database_url'] . 'backups/' . $backupKey . '.json';
            
            $context = stream_context_create([
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
            
            $response = file_get_contents($url, false, $context);
            
            if ($response === false) {
                throw new Exception('Failed to backup batch to Firebase');
            }
            
            $this->logBackupOperation('SUCCESS', $table, $operation, $backupKey);
            return true;
            
        } catch (Exception $e) {
            $this->logBackupOperation('ERROR', $table, $operation, $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log backup operations
     */
    private function logBackupOperation($status, $table, $operation, $details) {
        if (!$this->config['log_backup_operations']) {
            return;
        }
        
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'status' => $status,
            'table' => $table,
            'operation' => $operation,
            'details' => $details
        ];
        
        $logFile = __DIR__ . '/../' . $this->config['log_file'];
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Test Firebase connection
     */
    public function testConnection() {
        try {
            $testData = [
                'test' => true,
                'timestamp' => date('Y-m-d H:i:s'),
                'message' => 'Firebase connection test'
            ];
            
            $url = $this->config['database_url'] . 'test/connection.json';
            
            // Use cURL for better SSL handling
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($response === false || $httpCode >= 400) {
                throw new Exception('Failed to connect to Firebase. HTTP Code: ' . $httpCode);
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->logBackupOperation('ERROR', 'test', 'connection', $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get backup status for a table
     */
    public function getBackupStatus($table) {
        try {
            // Using public access - no token needed
            $url = $this->config['database_url'] . 'attendance_system/' . $table . '.json?orderBy="$key"&limitToLast=1';
            
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]);
            $response = file_get_contents($url, false, $context);
            $data = json_decode($response, true);
            
            if ($data) {
                $latestBackup = end($data);
                return [
                    'status' => 'success',
                    'last_backup' => $latestBackup['timestamp'] ?? 'Unknown',
                    'operation' => $latestBackup['operation'] ?? 'Unknown'
                ];
            }
            
            return [
                'status' => 'no_backup',
                'last_backup' => 'Never',
                'operation' => 'None'
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'last_backup' => 'Error',
                'operation' => $e->getMessage()
            ];
        }
    }
}
?>
