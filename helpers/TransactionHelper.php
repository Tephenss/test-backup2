<?php
/**
 * Transaction Helper Class
 * Provides safe transaction management to prevent "There is no active transaction" errors
 */
class TransactionHelper {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Safely begin a transaction if one is not already active
     * @return bool True if transaction was started, false if already active
     */
    public function beginTransaction() {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            return true;
        }
        return false;
    }
    
    /**
     * Safely commit a transaction if one is active
     * @return bool True if transaction was committed, false if no active transaction
     */
    public function commit() {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
            return true;
        }
        return false;
    }
    
    /**
     * Safely rollback a transaction if one is active
     * @return bool True if transaction was rolled back, false if no active transaction
     */
    public function rollback() {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollback();
            return true;
        }
        return false;
    }
    
    /**
     * Check if a transaction is currently active
     * @return bool True if transaction is active
     */
    public function inTransaction() {
        return $this->pdo->inTransaction();
    }
    
    /**
     * Execute a callback within a transaction
     * Automatically handles commit/rollback based on callback result
     * @param callable $callback Function to execute within transaction
     * @return mixed Result of callback or false on error
     */
    public function executeInTransaction($callback) {
        $this->beginTransaction();
        
        try {
            $result = $callback();
            $this->commit();
            return $result;
        } catch (Exception $e) {
            $this->rollback();
            throw $e;
        }
    }
}
?>

