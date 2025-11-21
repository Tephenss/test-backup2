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
 * Fetch all RFID tags with student information
 */
function fetchRfidTags(PDO $pdo): array {
    try {
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
            ORDER BY t.created_at DESC
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
        $normalized['student'] = [
            'id' => (int)$row['student_id'],
            'student_id' => $row['student_student_id'] ?? '',
            'name' => composeStudentName($row),
            'course' => $row['course'] ?? '',
            'year_level' => $row['year_level'] ?? '',
            'section' => $row['section'] ?? ''
        ];
    } else {
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
