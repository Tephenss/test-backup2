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
            
            // Generate key based on operation type
            if (in_array($operation, ['password_change', 'account_recovery', 'update', 'approve'])) {
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
                // For inserts and other operations, use unique key with timestamp
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
