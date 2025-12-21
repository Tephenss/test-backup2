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
     * Backup RFID tag events
     */
    public function backupRfidTagEvent($rfidData, $operation = 'insert') {
        return $this->firebase->backupRecord('rfid_tags', $rfidData, $operation);
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
                
                // CRITICAL: Preserve profile_picture base64 string for Android app
                // When updating other fields (like rfid_uid), we must preserve the existing base64 string in Firebase
                // MySQL stores file paths, but Firebase needs base64 for the Android app
                
                // First, try to get existing base64 from Firebase (to preserve it)
                $existingFirebasePic = $this->getExistingFirebaseProfilePicture($studentId);
                
                // Check if profile_picture is being explicitly updated with base64 string
                if (isset($updatedData['profile_picture'])) {
                    if (strpos($updatedData['profile_picture'], 'data:image') === 0) {
                        // Explicitly updating with base64 string - use it
                        $backupData['profile_picture'] = $updatedData['profile_picture'];
                    } else {
                        // Explicitly updating but not base64 - use the new value (might be file path)
                        $backupData['profile_picture'] = $updatedData['profile_picture'];
                    }
                } elseif ($existingFirebasePic) {
                    // Not updating profile_picture, but Firebase has base64 - preserve it
                    $backupData['profile_picture'] = $existingFirebasePic;
                } elseif (!empty($backupData['profile_picture']) && strpos($backupData['profile_picture'], 'uploads/') === 0) {
                    // No existing Firebase base64, and MySQL has file path - convert to base64
                    $filePath = '../' . $backupData['profile_picture'];
                    if (file_exists($filePath)) {
                        try {
                            $imageData = file_get_contents($filePath);
                            if ($imageData !== false) {
                                $mimeType = mime_content_type($filePath) ?: 'image/png';
                                $base64String = base64_encode($imageData);
                                $backupData['profile_picture'] = 'data:' . $mimeType . ';base64,' . $base64String;
                            }
                        } catch (Exception $e) {
                            error_log("Error converting profile picture to base64: " . $e->getMessage());
                        }
                    }
                }
                
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
     * Get existing profile_picture base64 from Firebase to preserve it
     */
    private function getExistingFirebaseProfilePicture($studentId) {
        try {
            $firebaseConfig = require __DIR__ . '/../config/firebase.php';
            $firebaseUrl = rtrim($firebaseConfig['database_url'], '/');
            
            // Try to fetch all student records from Firebase and find matching ID
            $url = $firebaseUrl . '/attendance_system/students.json';
            $context = stream_context_create([
                'http' => [
                    'timeout' => 5
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]);
            $response = @file_get_contents($url, false, $context);
            
            if ($response !== false && $response !== 'null') {
                $data = json_decode($response, true);
                if ($data && is_array($data)) {
                    // Search through all student records
                    foreach ($data as $key => $record) {
                        // Handle different Firebase storage structures
                        $recordData = null;
                        if (isset($record['data']) && is_array($record['data'])) {
                            $recordData = $record['data'];
                        } elseif (is_array($record)) {
                            $recordData = $record;
                        }
                        
                        if ($recordData && isset($recordData['id']) && $recordData['id'] == $studentId) {
                            if (isset($recordData['profile_picture']) && !empty($recordData['profile_picture'])) {
                                $profilePic = $recordData['profile_picture'];
                                // Check if it's a base64 string (starts with 'data:image')
                                if (strpos($profilePic, 'data:image') === 0) {
                                    return $profilePic; // Return existing base64 string
                                }
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching existing Firebase profile picture for student {$studentId}: " . $e->getMessage());
        }
        
        return null; // Return null if not found
    }
    
    /**
     * Get existing avatar base64 from Firebase to preserve it (for teachers)
     */
    private function getExistingFirebaseTeacherAvatar($teacherId) {
        try {
            $firebaseConfig = require __DIR__ . '/../config/firebase.php';
            $firebaseUrl = rtrim($firebaseConfig['database_url'], '/');
            
            // Try to fetch all teacher records from Firebase and find matching ID
            $url = $firebaseUrl . '/attendance_system/teachers.json';
            $context = stream_context_create([
                'http' => [
                    'timeout' => 5
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]);
            $response = @file_get_contents($url, false, $context);
            
            if ($response !== false && $response !== 'null') {
                $data = json_decode($response, true);
                if ($data && is_array($data)) {
                    // Search through all teacher records
                    foreach ($data as $key => $record) {
                        // Handle different Firebase storage structures
                        $recordData = null;
                        if (isset($record['data']) && is_array($record['data'])) {
                            $recordData = $record['data'];
                        } elseif (is_array($record)) {
                            $recordData = $record;
                        }
                        
                        if ($recordData && isset($recordData['id']) && $recordData['id'] == $teacherId) {
                            // Check avatar field
                            if (isset($recordData['avatar']) && !empty($recordData['avatar'])) {
                                $avatar = $recordData['avatar'];
                                // Check if it's a base64 string (starts with 'data:image')
                                if (strpos($avatar, 'data:image') === 0) {
                                    return $avatar; // Return existing base64 string
                                }
                            }
                            // Also check profile_picture field
                            if (isset($recordData['profile_picture']) && !empty($recordData['profile_picture'])) {
                                $profilePic = $recordData['profile_picture'];
                                if (strpos($profilePic, 'data:image') === 0) {
                                    return $profilePic; // Return existing base64 string
                                }
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching existing Firebase avatar for teacher {$teacherId}: " . $e->getMessage());
        }
        
        return null; // Return null if not found
    }
    
    /**
     * Backup teacher data update
     */
    public function backupTeacherUpdate($teacherId, $updatedData) {
        // Get complete teacher data including teacher_id
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT * FROM teachers WHERE id = ?");
            $stmt->execute([$teacherId]);
            $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($teacher) {
                // Merge updated data with complete teacher data
                $backupData = array_merge($teacher, $updatedData);
                
                // CRITICAL: Preserve avatar/profile_picture base64 string for Android app
                // When updating other fields, we must preserve the existing base64 string in Firebase
                // MySQL stores file paths, but Firebase needs base64 for the Android app
                
                // First, try to get existing base64 from Firebase (to preserve it)
                $existingFirebasePic = $this->getExistingFirebaseTeacherAvatar($teacherId);
                
                // Check if avatar is being explicitly updated with base64 string
                if (isset($updatedData['avatar'])) {
                    if (strpos($updatedData['avatar'], 'data:image') === 0) {
                        // Explicitly updating with base64 string - use it
                        $backupData['avatar'] = $updatedData['avatar'];
                    } else {
                        // Explicitly updating but not base64 - use the new value (might be file path)
                        $backupData['avatar'] = $updatedData['avatar'];
                    }
                } elseif ($existingFirebasePic) {
                    // Not updating avatar, but Firebase has base64 - preserve it
                    $backupData['avatar'] = $existingFirebasePic;
                } elseif (!empty($backupData['avatar']) && (strpos($backupData['avatar'], 'uploads/') === 0 || strpos($backupData['avatar'], 'avatars/') !== false)) {
                    // No existing Firebase base64, and MySQL has file path - convert to base64
                    $filePath = '../' . $backupData['avatar'];
                    if (file_exists($filePath)) {
                        try {
                            $imageData = file_get_contents($filePath);
                            if ($imageData !== false) {
                                $mimeType = mime_content_type($filePath) ?: 'image/png';
                                $base64String = base64_encode($imageData);
                                $backupData['avatar'] = 'data:' . $mimeType . ';base64,' . $base64String;
                            }
                        } catch (Exception $e) {
                            error_log("Error converting teacher avatar to base64: " . $e->getMessage());
                        }
                    }
                }
                
                // Also handle profile_picture field (if used instead of avatar)
                if (isset($updatedData['profile_picture'])) {
                    if (strpos($updatedData['profile_picture'], 'data:image') === 0) {
                        $backupData['profile_picture'] = $updatedData['profile_picture'];
                    }
                } elseif (!empty($backupData['profile_picture']) && strpos($backupData['profile_picture'], 'data:image') !== 0) {
                    // Convert profile_picture file path to base64 if needed
                    $filePath = '../' . $backupData['profile_picture'];
                    if (file_exists($filePath)) {
                        try {
                            $imageData = file_get_contents($filePath);
                            if ($imageData !== false) {
                                $mimeType = mime_content_type($filePath) ?: 'image/png';
                                $base64String = base64_encode($imageData);
                                $backupData['profile_picture'] = 'data:' . $mimeType . ';base64,' . $base64String;
                            }
                        } catch (Exception $e) {
                            error_log("Error converting teacher profile_picture to base64: " . $e->getMessage());
                        }
                    }
                }
                
                return $this->firebase->backupRecord('teachers', $backupData, 'update');
            }
        } catch (Exception $e) {
            error_log("Error getting teacher data for update backup: " . $e->getMessage());
        }
        
        // Fallback to original method if database query fails
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
