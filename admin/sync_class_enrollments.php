<?php
require_once '../config/database.php';
require_once '../helpers/BackupHooks.php';

echo "<h2>Syncing Class Enrollments</h2>";

// Get all active classes
$classes = $pdo->query("SELECT c.id, c.section, s.year_level FROM classes c JOIN sections s ON c.section = s.name WHERE c.status = 'active' AND c.academic_year != ''")->fetchAll(PDO::FETCH_ASSOC);
$total_synced = 0;
foreach ($classes as $class) {
    $class_id = $class['id'];
    $section = $class['section'];
    $year_level = $class['year_level'];
    // Find all students in this section and year_level
    $students = $pdo->prepare("SELECT id FROM students WHERE section = ? AND year_level = ?");
    $students->execute([$section, $year_level]);
    $student_ids = $students->fetchAll(PDO::FETCH_COLUMN);
    foreach ($student_ids as $student_id) {
        // Check if already enrolled
        $exists = $pdo->prepare("SELECT 1 FROM class_students WHERE class_id = ? AND student_id = ?");
        $exists->execute([$class_id, $student_id]);
        if (!$exists->fetch()) {
            // Enroll student
            $insert = $pdo->prepare("INSERT INTO class_students (class_id, student_id, status) VALUES (?, ?, 'active')");
            $insert->execute([$class_id, $student_id]);
            
            // Backup class enrollment to Firebase
            try {
                $backupHooks = new BackupHooks();
                $enrollmentData = [
                    'class_id' => $class_id,
                    'student_id' => $student_id,
                    'status' => 'active',
                    'enrolled_at' => date('Y-m-d H:i:s'),
                    'enrolled_by' => 'sync_class_enrollments'
                ];
                $backupHooks->backupClassEnrollment($enrollmentData);
            } catch (Exception $e) {
                error_log("Firebase backup failed for class enrollment: " . $e->getMessage());
            }
            
            echo "Enrolled student $student_id in class $class_id<br>";
            $total_synced++;
        }
    }
}
echo "<b>Sync complete. $total_synced enrollments added.</b>"; 