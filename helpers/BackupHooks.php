<?php
/**
 * Backup Hooks
 * 
 * This class provides methods to automatically backup data to Firebase
 * when database operations occur. It should be called after successful
 * database insertions, updates, and deletions.
 */

require_once __DIR__ . '/FirebaseBackup.php';

class BackupHooks {
    private $firebase;
    
    public function __construct() {
        $this->firebase = new FirebaseBackup();
    }
    
    /**
     * Backup student registration
     */
    public function backupStudentRegistration($studentData) {
        return $this->firebase->backupRecord('students', $studentData, 'insert');
    }
    
    /**
     * Backup teacher creation
     */
    public function backupTeacherCreation($teacherData) {
        return $this->firebase->backupRecord('teachers', $teacherData, 'insert');
    }
    
    /**
     * Backup student approval
     */
    public function backupStudentApproval($studentId, $updatedData) {
        // Get complete student data including student_id
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
            $stmt->execute([$studentId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($student) {
                // Merge updated data with complete student data
                $backupData = array_merge($student, $updatedData);
                return $this->firebase->backupRecord('students', $backupData, 'approve');
            }
        } catch (Exception $e) {
            error_log("Error getting student data for approval backup: " . $e->getMessage());
        }
        
        // Fallback to original method if database query fails
        $backupData = array_merge(['id' => $studentId], $updatedData);
        return $this->firebase->backupRecord('students', $backupData, 'approve');
    }
    
    /**
     * Backup attendance record
     */
    public function backupAttendanceRecord($attendanceData) {
        return $this->firebase->backupRecord('attendance', $attendanceData, 'insert');
    }
    
    /**
     * Backup grade/mark record
     */
    public function backupGradeRecord($gradeData) {
        return $this->firebase->backupRecord('marks', $gradeData, 'insert');
    }
    
    /**
     * Backup class enrollment
     */
    public function backupClassEnrollment($enrollmentData) {
        return $this->firebase->backupRecord('class_students', $enrollmentData, 'insert');
    }
    
    /**
     * Backup subject assignment
     */
    public function backupSubjectAssignment($assignmentData) {
        return $this->firebase->backupRecord('subject_assignments', $assignmentData, 'insert');
    }
    
    /**
     * Backup class creation
     */
    public function backupClassCreation($classData) {
        return $this->firebase->backupRecord('classes', $classData, 'insert');
    }
    
    /**
     * Backup section creation
     */
    public function backupSectionCreation($sectionData) {
        return $this->firebase->backupRecord('sections', $sectionData, 'insert');
    }
    
    /**
     * Backup subject creation
     */
    public function backupSubjectCreation($subjectData) {
        return $this->firebase->backupRecord('subjects', $subjectData, 'insert');
    }
    
    /**
     * Backup activity log
     */
    public function backupActivityLog($activityData) {
        return $this->firebase->backupRecord('activity_logs', $activityData, 'insert');
    }
    
    /**
     * Backup login log
     */
    public function backupLoginLog($loginData) {
        return $this->firebase->backupRecord('login_logs', $loginData, 'insert');
    }
    
    /**
     * Backup verification code
     */
    public function backupVerificationCode($verificationData) {
        return $this->firebase->backupRecord('verification_codes', $verificationData, 'insert');
    }
    
    /**
     * Backup timetable entry
     */
    public function backupTimetableEntry($timetableData) {
        return $this->firebase->backupRecord('timetable', $timetableData, 'insert');
    }
    
    /**
     * Backup multiple records (for batch operations)
     */
    public function backupBatchRecords($table, $records, $operation = 'batch') {
        return $this->firebase->backupBatch($table, $records, $operation);
    }
    
    /**
     * Backup student data update
     */
    public function backupStudentUpdate($studentId, $updatedData) {
        // Get complete student data including student_id
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
            $stmt->execute([$studentId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($student) {
                // Merge updated data with complete student data
                $backupData = array_merge($student, $updatedData);
                return $this->firebase->backupRecord('students', $backupData, 'update');
            }
        } catch (Exception $e) {
            error_log("Error getting student data for update backup: " . $e->getMessage());
        }
        
        // Fallback to original method if database query fails
        $backupData = array_merge(['id' => $studentId], $updatedData);
        return $this->firebase->backupRecord('students', $backupData, 'update');
    }
    
    /**
     * Backup teacher data update
     */
    public function backupTeacherUpdate($teacherId, $updatedData) {
        $backupData = array_merge(['id' => $teacherId], $updatedData);
        return $this->firebase->backupRecord('teachers', $backupData, 'update');
    }
    
    /**
     * Backup student deletion
     */
    public function backupStudentDeletion($studentId, $studentData) {
        $backupData = array_merge(['id' => $studentId], $studentData);
        return $this->firebase->backupRecord('students', $backupData, 'deletion');
    }
    
    /**
     * Backup password change for students
     */
    public function backupStudentPasswordChange($studentId, $updatedData) {
        // Get complete student data including student_id
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
            $stmt->execute([$studentId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($student) {
                // Merge updated data with complete student data
                $backupData = array_merge($student, $updatedData);
                return $this->firebase->backupRecord('students', $backupData, 'password_change');
            }
        } catch (Exception $e) {
            error_log("Error getting student data for password change backup: " . $e->getMessage());
        }
        
        // Fallback to original method if database query fails
        $backupData = array_merge(['id' => $studentId], $updatedData);
        return $this->firebase->backupRecord('students', $backupData, 'password_change');
    }
    
    /**
     * Backup password change for teachers
     */
    public function backupTeacherPasswordChange($teacherId, $updatedData) {
        // Get complete teacher data including teacher_id
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT * FROM teachers WHERE id = ?");
            $stmt->execute([$teacherId]);
            $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($teacher) {
                // Merge updated data with complete teacher data
                $backupData = array_merge($teacher, $updatedData);
                return $this->firebase->backupRecord('teachers', $backupData, 'password_change');
            }
        } catch (Exception $e) {
            error_log("Error getting teacher data for password change backup: " . $e->getMessage());
        }
        
        // Fallback to original method if database query fails
        $backupData = array_merge(['id' => $teacherId], $updatedData);
        return $this->firebase->backupRecord('teachers', $backupData, 'password_change');
    }
    
    /**
     * Backup password change for admins
     */
    public function backupAdminPasswordChange($adminId, $updatedData) {
        // Get complete admin data including admin_id
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
            $stmt->execute([$adminId]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($admin) {
                // Merge updated data with complete admin data
                $backupData = array_merge($admin, $updatedData);
                return $this->firebase->backupRecord('admins', $backupData, 'password_change');
            }
        } catch (Exception $e) {
            error_log("Error getting admin data for password change backup: " . $e->getMessage());
        }
        
        // Fallback to original method if database query fails
        $backupData = array_merge(['id' => $adminId], $updatedData);
        return $this->firebase->backupRecord('admins', $backupData, 'password_change');
    }
    
    /**
     * Backup account recovery (password reset)
     */
    public function backupAccountRecovery($userId, $userType, $updatedData) {
        global $pdo;
        try {
            $table = $userType . 's'; // students, teachers, admins
            $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // Merge updated data with complete user data
                $backupData = array_merge($user, $updatedData);
                return $this->firebase->backupRecord($table, $backupData, 'account_recovery');
            }
        } catch (Exception $e) {
            error_log("Error getting user data for account recovery backup: " . $e->getMessage());
        }
        
        // Fallback to original method if database query fails
        $backupData = array_merge(['id' => $userId], $updatedData);
        return $this->firebase->backupRecord($userType . 's', $backupData, 'account_recovery');
    }
    
    /**
     * Backup teacher deletion
     */
    public function backupTeacherDeletion($teacherId, $teacherData) {
        $backupData = array_merge(['id' => $teacherId], $teacherData);
        return $this->firebase->backupRecord('teachers', $backupData, 'deletion');
    }
    
    /**
     * Generic backup method for any table
     */
    public function backupGenericRecord($table, $data, $operation = 'insert') {
        return $this->firebase->backupRecord($table, $data, $operation);
    }
    
    /**
     * Test backup system
     */
    public function testBackupSystem() {
        $testData = [
            'id' => 999999,
            'test_field' => 'test_value',
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        return $this->firebase->backupRecord('test_backup', $testData, 'test');
    }
}
?>
