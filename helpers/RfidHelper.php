<?php
/**
 * RFID Helper Functions
 * 
 * Provides helper functions for RFID tag management and infrastructure setup
 */

/**
 * Ensure RFID infrastructure exists (tables and columns)
 */
function ensureRfidInfrastructure(PDO $pdo) {
    ensureStudentRfidColumn($pdo);
    ensureRfidTagsTable($pdo);
}

/**
 * Ensure students table has rfid_uid column
 */
function ensureStudentRfidColumn(PDO $pdo) {
    try {
        // Check if column exists
        $stmt = $pdo->query("SHOW COLUMNS FROM students LIKE 'rfid_uid'");
        if ($stmt->rowCount() === 0) {
            // Add column
            $pdo->exec("ALTER TABLE students ADD COLUMN rfid_uid VARCHAR(50) NULL AFTER profile_picture");
            // Add unique index
            $pdo->exec("CREATE UNIQUE INDEX idx_students_rfid_uid ON students(rfid_uid)");
        }
    } catch (PDOException $e) {
        error_log("Error ensuring rfid_uid column: " . $e->getMessage());
    }
}

/**
 * Ensure rfid_tags table exists
 */
function ensureRfidTagsTable(PDO $pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS rfid_tags (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tag_uid VARCHAR(50) NOT NULL UNIQUE,
                label VARCHAR(255) NULL,
                status ENUM('available', 'assigned', 'disabled') DEFAULT 'available',
                student_id INT NULL,
                assigned_at TIMESTAMP NULL,
                last_seen TIMESTAMP NULL,
                last_source VARCHAR(50) NULL,
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL,
                INDEX idx_tag_uid (tag_uid),
                INDEX idx_student_id (student_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (PDOException $e) {
        error_log("Error ensuring rfid_tags table: " . $e->getMessage());
    }
}

/**
 * Sync RFID tags from Firebase to MySQL
 */
function syncRfidTagsFromFirebase(PDO $pdo): void {
    try {
        $firebaseConfig = require __DIR__ . '/../config/firebase.php';
        if (!$firebaseConfig['backup_enabled']) {
            return;
        }
        
        $url = rtrim($firebaseConfig['database_url'], '/') . '/attendance_system/rfid_tags.json';
        
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ],
            'http' => [
                'timeout' => 10
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            // Silently fail - Firebase might not be accessible
            return;
        }
        
        $firebaseData = json_decode($response, true);
        if (!$firebaseData || !is_array($firebaseData)) {
            return;
        }
        
        // Group by tag_uid and track all operations, including deletions
        $tagOperations = [];
        $tagDeletions = [];
        
        foreach ($firebaseData as $key => $record) {
            if (!isset($record['data']) || !is_array($record['data'])) {
                continue;
            }
            
            $tagData = $record['data'];
            $operation = $record['operation'] ?? 'insert';
            $tagUid = $tagData['tag_uid'] ?? null;
            
            if (empty($tagUid)) {
                continue;
            }
            
            $serverTime = $record['server_time'] ?? 0;
            
            // Track deletion operations separately
            if ($operation === 'deletion' || $operation === 'delete') {
                if (!isset($tagDeletions[$tagUid]) || $serverTime > ($tagDeletions[$tagUid]['server_time'] ?? 0)) {
                    $tagDeletions[$tagUid] = [
                        'tag_uid' => $tagUid,
                        'operation' => $operation,
                        'server_time' => $serverTime
                    ];
                }
            } else {
                // Track non-deletion operations
                if (!isset($tagOperations[$tagUid]) || $serverTime > ($tagOperations[$tagUid]['server_time'] ?? 0)) {
                    $tagOperations[$tagUid] = [
                        'tag_uid' => $tagUid,
                        'tag_data' => $tagData,
                        'operation' => $operation,
                        'server_time' => $serverTime
                    ];
                }
            }
        }
        
        // Process each unique tag
        foreach ($tagOperations as $tagUid => $assignment) {
            $tagData = $assignment['tag_data'];
            $operation = $assignment['operation'];
            $tagUid = $assignment['tag_uid'];
            $operationTime = $assignment['server_time'];
            
            // Check if there's a deletion operation that's more recent than this operation
            if (isset($tagDeletions[$tagUid])) {
                $deletionTime = $tagDeletions[$tagUid]['server_time'];
                // If deletion is more recent, skip this tag (don't re-add deleted tags)
                if ($deletionTime >= $operationTime) {
                    continue;
                }
            }
            
            // Check if tag exists in MySQL
            $stmt = $pdo->prepare("SELECT id, student_id, status FROM rfid_tags WHERE tag_uid = ?");
            $stmt->execute([$tagUid]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // If tag doesn't exist in MySQL but there's a deletion in Firebase, don't re-add it
            if (!$existing && isset($tagDeletions[$tagUid])) {
                // Tag was deleted, don't re-add it
                continue;
            }
            
            $studentId = isset($tagData['student_id']) && !empty($tagData['student_id']) ? (int)$tagData['student_id'] : null;
            
            if ($existing) {
                // Update existing tag if assignment changed
                if ($operation === 'assign' && $studentId) {
                    $pdo->beginTransaction();
                    
                    try {
                        // Clear ALL tags assigned to the new student first (including this one)
                        $pdo->prepare("UPDATE rfid_tags SET student_id = NULL, status = 'available', assigned_at = NULL WHERE student_id = ?")
                            ->execute([$studentId]);
                        
                        // Clear previous assignment
                        if ($existing['student_id'] && $existing['student_id'] != $studentId) {
                            $pdo->prepare("UPDATE students SET rfid_uid = NULL WHERE id = ?")
                                ->execute([$existing['student_id']]);
                            
                            // Clear any other tags assigned to the previous student
                            $pdo->prepare("UPDATE rfid_tags SET student_id = NULL, status = 'available', assigned_at = NULL WHERE student_id = ?")
                                ->execute([$existing['student_id']]);
                        }
                        
                        // Clear student's rfid_uid first to ensure clean state
                        $pdo->prepare("UPDATE students SET rfid_uid = NULL WHERE id = ?")
                            ->execute([$studentId]);
                        
                        // Update tag
                        $pdo->prepare("
                            UPDATE rfid_tags 
                            SET student_id = ?, status = 'assigned', assigned_at = NOW()
                            WHERE id = ?
                        ")->execute([$studentId, $existing['id']]);
                        
                        // Update student's rfid_uid
                        $pdo->prepare("UPDATE students SET rfid_uid = ? WHERE id = ?")
                            ->execute([$tagUid, $studentId]);
                        
                        $pdo->commit();
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log("Error syncing RFID assignment from Firebase: " . $e->getMessage());
                    }
                } elseif ($operation === 'unassign' && $existing['student_id']) {
                    // Handle unassign operation
                    $pdo->beginTransaction();
                    
                    try {
                        $pdo->prepare("UPDATE students SET rfid_uid = NULL WHERE id = ?")
                            ->execute([$existing['student_id']]);
                        
                        $pdo->prepare("
                            UPDATE rfid_tags 
                            SET student_id = NULL, status = 'available', assigned_at = NULL
                            WHERE id = ?
                        ")->execute([$existing['id']]);
                        
                        $pdo->commit();
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log("Error syncing RFID unassignment from Firebase: " . $e->getMessage());
                    }
                }
            } else {
                // Insert new tag from Firebase
                // Double-check tag doesn't exist (race condition protection)
                $doubleCheck = $pdo->prepare("SELECT id FROM rfid_tags WHERE tag_uid = ? LIMIT 1");
                $doubleCheck->execute([$tagUid]);
                if ($doubleCheck->fetch()) {
                    // Tag already exists (was added between check and insert), skip
                    continue;
                }
                
                $pdo->beginTransaction();
                
                try {
                    // If assigning, clear ALL tags assigned to this student first
                    if ($studentId && $operation === 'assign') {
                        // Clear all tags assigned to this student
                        $pdo->prepare("UPDATE rfid_tags SET student_id = NULL, status = 'available', assigned_at = NULL WHERE student_id = ?")
                            ->execute([$studentId]);
                        
                        // Clear student's rfid_uid
                        $pdo->prepare("UPDATE students SET rfid_uid = NULL WHERE id = ?")
                            ->execute([$studentId]);
                    }
                    
                    $insertStmt = $pdo->prepare("
                        INSERT INTO rfid_tags (tag_uid, label, status, student_id, assigned_at, last_seen, last_source, created_at)
                        VALUES (?, ?, ?, ?, ?, NOW(), 'firebase_sync', NOW())
                    ");
                    
                    $status = ($studentId && $operation === 'assign') ? 'assigned' : 'available';
                    $assignedAt = ($studentId && $operation === 'assign') ? date('Y-m-d H:i:s') : null;
                    
                    $insertStmt->execute([
                        $tagUid,
                        $tagData['label'] ?? null,
                        $status,
                        $studentId,
                        $assignedAt
                    ]);
                    
                    // Update student's rfid_uid if assigned
                    if ($studentId && $operation === 'assign') {
                        $pdo->prepare("UPDATE students SET rfid_uid = ? WHERE id = ?")
                            ->execute([$tagUid, $studentId]);
                    }
                    
                    $pdo->commit();
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    // Check if it's a duplicate key error (tag already exists)
                    if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Duplicate entry') !== false || strpos($e->getMessage(), 'UNIQUE constraint') !== false) {
                        // Tag already exists, skip silently (race condition handled)
                        error_log("RFID tag {$tagUid} already exists, skipping duplicate insert from Firebase sync.");
                    } else {
                        error_log("Error inserting RFID tag from Firebase: " . $e->getMessage());
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log("Error inserting RFID tag from Firebase: " . $e->getMessage());
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error syncing RFID tags from Firebase: " . $e->getMessage());
    }
}

/**
 * Fetch all RFID tags with student information
 */
function fetchRfidTags(PDO $pdo, $skipSync = false): array {
    try {
        // Sync from Firebase first (unless explicitly skipped)
        if (!$skipSync) {
            syncRfidTagsFromFirebase($pdo);
        }
        
        $stmt = $pdo->query("
            SELECT 
                t.*,
                s.id as student_id,
                s.student_id as student_student_id,
                s.first_name,
                s.middle_name,
                s.last_name,
                s.suffix_name,
                s.course,
                s.year_level,
                s.section
            FROM rfid_tags t
            LEFT JOIN students s ON t.student_id = s.id
            ORDER BY 
                CASE WHEN t.status = 'assigned' THEN 0 ELSE 1 END,
                t.created_at DESC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map('normalizeRfidTagRow', $rows);
    } catch (PDOException $e) {
        error_log("Error fetching RFID tags: " . $e->getMessage());
        return [];
    }
}

/**
 * Get RFID statistics
 */
function getRfidStats(PDO $pdo): array {
    try {
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) as assigned,
                SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available,
                SUM(CASE WHEN status = 'disabled' THEN 1 ELSE 0 END) as disabled
            FROM rfid_tags
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total' => 0,
            'assigned' => 0,
            'available' => 0,
            'disabled' => 0
        ];
    } catch (PDOException $e) {
        error_log("Error getting RFID stats: " . $e->getMessage());
        return [
            'total' => 0,
            'assigned' => 0,
            'available' => 0,
            'disabled' => 0
        ];
    }
}

/**
 * Get available RFID tags (not assigned, status = available)
 */
function getAvailableRfidTags(PDO $pdo): array {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM rfid_tags
            WHERE status = 'available' AND student_id IS NULL
            ORDER BY created_at DESC
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map('normalizeRfidTagRow', $rows);
    } catch (PDOException $e) {
        error_log("Error fetching available RFID tags: " . $e->getMessage());
        return [];
    }
}

/**
 * Get RFID tag by ID
 */
function getRfidTagById(PDO $pdo, int $tagId): ?array {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                t.*,
                s.id as student_id,
                s.student_id as student_student_id,
                s.first_name,
                s.middle_name,
                s.last_name,
                s.suffix_name,
                s.course,
                s.year_level,
                s.section
            FROM rfid_tags t
            LEFT JOIN students s ON t.student_id = s.id
            WHERE t.id = ?
        ");
        $stmt->execute([$tagId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? normalizeRfidTagRow($row) : null;
    } catch (PDOException $e) {
        error_log("Error fetching RFID tag by ID: " . $e->getMessage());
        return null;
    }
}

/**
 * Normalize RFID tag row data
 */
function normalizeRfidTagRow(array $row): array {
    $normalized = [
        'id' => (int)($row['id'] ?? 0),
        'tag_uid' => $row['tag_uid'] ?? '',
        'label' => $row['label'] ?? null,
        'status' => $row['status'] ?? 'available',
        'student_id' => $row['student_id'] ? (int)$row['student_id'] : null,
        'assigned_at' => $row['assigned_at'] ?? null,
        'last_seen' => $row['last_seen'] ?? null,
        'last_source' => $row['last_source'] ?? null,
        'notes' => $row['notes'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
    
    // Add student information if available
    if (!empty($row['first_name']) || !empty($row['last_name'])) {
        $normalized['student_name'] = composeStudentName($row);
        $normalized['student_student_id'] = $row['student_student_id'] ?? '';
        $normalized['student'] = [
            'id' => (int)$row['student_id'],
            'student_id' => $row['student_student_id'] ?? '',
            'name' => composeStudentName($row),
            'course' => $row['course'] ?? '',
            'year_level' => $row['year_level'] ?? '',
            'section' => $row['section'] ?? ''
        ];
    } else {
        $normalized['student_name'] = null;
        $normalized['student_student_id'] = null;
        $normalized['student'] = null;
    }
    
    return $normalized;
}

/**
 * Compose student full name
 */
function composeStudentName(array $student): string {
    $parts = [];
    if (!empty($student['last_name'])) {
        $parts[] = strtoupper($student['last_name']);
    }
    if (!empty($student['first_name'])) {
        $parts[] = ucwords(strtolower($student['first_name']));
    }
    if (!empty($student['middle_name'])) {
        $parts[] = strtoupper(substr($student['middle_name'], 0, 1)) . '.';
    }
    if (!empty($student['suffix_name'])) {
        $parts[] = $student['suffix_name'];
    }
    return implode(', ', $parts);
}
?>
