<?php
require_once 'config/database.php';

// Function to ensure soft delete columns exist
function ensureSoftDeleteColumns($table) {
    global $pdo;
    try {
        // Check if is_deleted column exists
        $stmt = $pdo->query("SHOW COLUMNS FROM $table LIKE 'is_deleted'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN is_deleted TINYINT(1) DEFAULT 0");
            error_log("Added is_deleted column to $table");
        }

        // Check if deleted_at column exists
        $stmt = $pdo->query("SHOW COLUMNS FROM $table LIKE 'deleted_at'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN deleted_at DATETIME NULL");
            error_log("Added deleted_at column to $table");
        }
        return true;
    } catch(PDOException $e) {
        error_log("Error ensuring soft delete columns for $table: " . $e->getMessage());
        return false;
    }
}

// Function to soft delete a record
function softDelete($table, $id) {
    global $pdo;
    try {
        // First ensure the columns exist
        if (!ensureSoftDeleteColumns($table)) {
            error_log("Failed to ensure soft delete columns for $table");
            return false;
        }
        
        // First check if the record exists
        $checkStmt = $pdo->prepare("SELECT id FROM $table WHERE id = ?");
        $checkStmt->execute([$id]);
        if (!$checkStmt->fetch()) {
            error_log("Record not found in $table with id: $id");
            return false;
        }

        // Perform the soft delete
        $stmt = $pdo->prepare("UPDATE $table SET is_deleted = 1, deleted_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$id]);
        
        if ($result) {
            error_log("Successfully soft deleted record from $table with id: $id");
        } else {
            error_log("Failed to soft delete record from $table with id: $id");
        }
        
        return $result;
    } catch(PDOException $e) {
        error_log("Error in softDelete for $table (id: $id): " . $e->getMessage());
        return false;
    }
}

// Function to check if a record is deleted
function isDeleted($table, $id) {
    global $pdo;
    try {
        // First ensure the columns exist
        if (!ensureSoftDeleteColumns($table)) {
            error_log("Failed to ensure soft delete columns for $table");
            return false;
        }
        
        $stmt = $pdo->prepare("SELECT is_deleted FROM $table WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result && $result['is_deleted'] == 1;
    } catch(PDOException $e) {
        error_log("Error in isDeleted for $table (id: $id): " . $e->getMessage());
        return false;
    }
}

function restoreRecord($table, $id) {
    global $pdo;
    try {
        // First ensure the columns exist
        if (!ensureSoftDeleteColumns($table)) {
            error_log("Failed to ensure soft delete columns for $table");
            return false;
        }
        
        $stmt = $pdo->prepare("UPDATE $table SET is_deleted = 0, deleted_at = NULL WHERE id = ?");
        $result = $stmt->execute([$id]);
        
        if ($result) {
            error_log("Successfully restored record from $table with id: $id");
        } else {
            error_log("Failed to restore record from $table with id: $id");
        }
        
        return $result;
    } catch(PDOException $e) {
        error_log("Error in restoreRecord for $table (id: $id): " . $e->getMessage());
        return false;
    }
}

// ... rest of existing code ... 