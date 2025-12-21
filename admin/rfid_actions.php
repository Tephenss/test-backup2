<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../config/database.php';
require_once '../helpers/RfidHelper.php';
require_once '../helpers/BackupHooks.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

ensureRfidInfrastructure($pdo);

$action = $_REQUEST['action'] ?? '';
$backupHooks = new BackupHooks();

try {
    switch ($action) {
        case 'list_tags':
            // Skip sync when listing to prevent re-adding deleted tags
            // Sync will happen on manual refresh or after actions
            respondSuccess('RFID tags loaded.', buildState($pdo, true));
            break;

        case 'register_tag':
            ensurePost();
            $uid = sanitizeUid($_POST['uid'] ?? '');
            $label = trim($_POST['label'] ?? '');
            $source = trim($_POST['source'] ?? 'live_scanner');

            if (empty($uid)) {
                respondError('No UID received. Please scan the RFID card again.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM rfid_tags WHERE tag_uid = ? LIMIT 1");
            $stmt->execute([$uid]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $pdo->rollBack();
                
                // Get tag details for error message
                $tag = getRfidTagById($pdo, (int)$existing['id']);
                $statusText = $existing['status'] === 'assigned' ? 'assigned' : ($existing['status'] === 'disabled' ? 'disabled' : 'available');
                $studentInfo = '';
                if ($existing['student_id']) {
                    $studentStmt = $pdo->prepare("SELECT student_id, first_name, last_name FROM students WHERE id = ?");
                    $studentStmt->execute([$existing['student_id']]);
                    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
                    if ($student) {
                        $studentInfo = ' (Assigned to: ' . htmlspecialchars($student['student_id'] . ' - ' . $student['first_name'] . ' ' . $student['last_name']) . ')';
                    }
                }
                
                respondError('This RFID tag is already registered in the system.' . $studentInfo . ' Status: ' . ucfirst($statusText) . '.');
            } else {
                $insert = $pdo->prepare("
                    INSERT INTO rfid_tags (tag_uid, label, status, last_seen, last_source) 
                    VALUES (?, ?, 'available', NOW(), ?)
                ");
                $insert->execute([$uid, $label ?: null, $source ?: null]);
                $tagId = (int)$pdo->lastInsertId();
                $pdo->commit();

                $tag = getRfidTagById($pdo, $tagId);
                if ($tag) {
                    $backupHooks->backupRfidTagEvent($tag, 'insert');
                }

                // Skip sync when building state after registration to prevent duplicate inserts
                // The tag was just added, no need to sync from Firebase immediately
                respondSuccess('RFID tag registered successfully.', array_merge(buildState($pdo, true), [
                    'tag' => $tag,
                    'existing' => false
                ]));
            }
            break;

        case 'assign_tag':
            ensurePost();
            $tagId = isset($_POST['tag_id']) ? (int)$_POST['tag_id'] : 0;
            $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;

            if (!$tagId || !$studentId) {
                respondError('Please complete both RFID and Student before assigning.');
            }

            $tag = getRfidTagById($pdo, $tagId);
            if (!$tag) {
                respondError('Tag not found.');
            }
            if ($tag['status'] === 'disabled') {
                respondError('Cannot assign a disabled tag.');
            }

            $studentStmt = $pdo->prepare("
                SELECT id, student_id, first_name, middle_name, last_name, suffix_name 
                FROM students 
                WHERE id = ? AND is_deleted = 0
            ");
            $studentStmt->execute([$studentId]);
            $student = $studentStmt->fetch(PDO::FETCH_ASSOC);

            if (!$student) {
                respondError('Student not found or already deleted.');
            }

            $pdo->beginTransaction();

            try {
                // First, clear ALL tags currently assigned to this student (including the one we're about to assign)
                $pdo->prepare("UPDATE rfid_tags SET student_id = NULL, status = 'available', assigned_at = NULL WHERE student_id = ?")
                    ->execute([$studentId]);

                // Clear student's rfid_uid to ensure clean state
                $pdo->prepare("UPDATE students SET rfid_uid = NULL WHERE id = ?")
                    ->execute([$studentId]);

                // Detach tag from previous student if needed
                if (!empty($tag['student_id']) && $tag['student_id'] != $studentId) {
                    $pdo->prepare("UPDATE students SET rfid_uid = NULL WHERE id = ?")
                        ->execute([$tag['student_id']]);
                    
                    // Also clear any other tags assigned to the previous student
                    $pdo->prepare("UPDATE rfid_tags SET student_id = NULL, status = 'available', assigned_at = NULL WHERE student_id = ?")
                        ->execute([$tag['student_id']]);
                }

                // Now assign the new tag to the student
                $pdo->prepare("
                    UPDATE rfid_tags 
                    SET student_id = ?, status = 'assigned', assigned_at = NOW(), last_seen = NOW()
                    WHERE id = ?
                ")->execute([$studentId, $tagId]);

                // Set student's rfid_uid to the new tag
                $pdo->prepare("UPDATE students SET rfid_uid = ? WHERE id = ?")
                    ->execute([$tag['tag_uid'], $studentId]);

                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            // Final cleanup: Ensure only one tag is assigned to this student
            $duplicateCheck = $pdo->prepare("SELECT id FROM rfid_tags WHERE student_id = ? AND id != ?");
            $duplicateCheck->execute([$studentId, $tagId]);
            $duplicates = $duplicateCheck->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($duplicates)) {
                // Clear any remaining duplicates
                $pdo->beginTransaction();
                foreach ($duplicates as $dupId) {
                    $pdo->prepare("UPDATE rfid_tags SET student_id = NULL, status = 'available', assigned_at = NULL WHERE id = ?")
                        ->execute([$dupId]);
                }
                $pdo->commit();
            }

            $updatedTag = getRfidTagById($pdo, $tagId);
            if ($updatedTag) {
                $backupHooks->backupRfidTagEvent($updatedTag, 'assign');
                $backupHooks->backupStudentUpdate($studentId, ['rfid_uid' => $updatedTag['tag_uid']]);
            }

            respondSuccess('RFID tag assigned successfully.', array_merge(buildState($pdo, true), [
                'tag' => $updatedTag
            ]));
            break;

        case 'unassign_tag':
            ensurePost();
            $tagId = isset($_POST['tag_id']) ? (int)$_POST['tag_id'] : 0;
            if (!$tagId) {
                respondError('Tag ID is required.');
            }

            $tag = getRfidTagById($pdo, $tagId);
            if (!$tag) {
                respondError('Tag not found.');
            }

            if (!$tag['student_id']) {
                respondSuccess('Tag is already unassigned.', buildState($pdo));
            }

            $studentId = $tag['student_id'];
            
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE students SET rfid_uid = NULL WHERE id = ?")
                ->execute([$studentId]);
            $pdo->prepare("UPDATE rfid_tags SET student_id = NULL, status = 'available', assigned_at = NULL WHERE id = ?")
                ->execute([$tagId]);
            $pdo->commit();

            $updatedTag = getRfidTagById($pdo, $tagId);
            if ($updatedTag) {
                $backupHooks->backupRfidTagEvent($updatedTag, 'unassign');
                
                // Also backup student update to clear rfid_uid in Firebase
                // Explicitly set rfid_uid to empty string for Firebase
                $backupHooks->backupStudentUpdate($studentId, ['rfid_uid' => '']);
                error_log("Backed up student rfid_uid clearance to Firebase for student ID: {$studentId} (unassign)");
            }

            respondSuccess('RFID tag unassigned successfully.', buildState($pdo));
            break;

        case 'block_tag':
            ensurePost();
            $tagId = isset($_POST['tag_id']) ? (int)$_POST['tag_id'] : 0;
            if (!$tagId) {
                respondError('Tag ID is required.');
            }

            $tag = getRfidTagById($pdo, $tagId);
            if (!$tag) {
                respondError('Tag not found.');
            }

            // Get tag data before deletion for Firebase backup
            $tagDataForBackup = $tag;

            $pdo->beginTransaction();
            
            try {
                // Unassign student if assigned - clear both student's rfid_uid and ALL tags assigned to this student
                $studentIdToUpdate = null;
                if ($tag['student_id']) {
                    $studentIdToUpdate = $tag['student_id'];
                    
                    // Clear ALL tags assigned to this student (including the one being blocked)
                    $pdo->prepare("UPDATE rfid_tags SET student_id = NULL, status = 'available', assigned_at = NULL WHERE student_id = ?")
                        ->execute([$studentIdToUpdate]);
                    
                    // Clear student's rfid_uid
                    $pdo->prepare("UPDATE students SET rfid_uid = NULL WHERE id = ?")
                        ->execute([$studentIdToUpdate]);
                }
                
                // Delete the tag from rfid_tags table
                $deleteStmt = $pdo->prepare("DELETE FROM rfid_tags WHERE id = ?");
                $deleteStmt->execute([$tagId]);
                
                if ($deleteStmt->rowCount() === 0) {
                    $pdo->rollBack();
                    respondError('Failed to delete RFID tag. Tag may not exist.');
                }
                
                $pdo->commit();

                // Backup deletion to Firebase - must be done after successful deletion
                try {
                    $backupHooks->backupRfidTagEvent($tagDataForBackup, 'deletion');
                    
                    // Also backup student update to clear rfid_uid in Firebase
                    if ($studentIdToUpdate) {
                        // Explicitly set rfid_uid to empty string for Firebase (Firebase will treat empty string as cleared)
                        $backupHooks->backupStudentUpdate($studentIdToUpdate, ['rfid_uid' => '']);
                        error_log("Backed up student rfid_uid clearance to Firebase for student ID: {$studentIdToUpdate}");
                    }
                } catch (Exception $e) {
                    error_log("Firebase backup failed for tag deletion/student update: " . $e->getMessage());
                    // Don't fail the request if Firebase backup fails, but log it
                }

                // Skip sync when building state after deletion to prevent re-adding from Firebase
                respondSuccess('RFID tag blocked and removed successfully.', buildState($pdo, true));
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Error blocking RFID tag: " . $e->getMessage());
                respondError('Failed to block RFID tag: ' . $e->getMessage());
            }
            break;

        case 'search_students':
            $query = trim($_GET['query'] ?? '');
            if (strlen($query) < 2) {
                respondSuccess('Need at least 2 characters.', ['students' => []]);
            }

            $like = '%' . $query . '%';
            // Exclude students who already have an RFID assigned
            // Check both students.rfid_uid and rfid_tags.student_id
            $stmt = $pdo->prepare("
                SELECT s.id, s.student_id, s.first_name, s.middle_name, s.last_name, s.suffix_name, s.course, s.year_level 
                FROM students s
                LEFT JOIN rfid_tags rt ON s.id = rt.student_id AND rt.status = 'assigned'
                WHERE s.is_deleted = 0
                  AND (s.rfid_uid IS NULL OR s.rfid_uid = '')
                  AND rt.id IS NULL
                  AND (
                      s.student_id LIKE :q OR 
                      s.first_name LIKE :q OR 
                      s.last_name LIKE :q OR 
                      CONCAT(s.first_name, ' ', s.last_name) LIKE :q
                  )
                ORDER BY s.last_name ASC, s.first_name ASC
                LIMIT 10
            ");
            $stmt->execute([':q' => $like]);
            $students = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $students[] = [
                    'id' => (int)$row['id'],
                    'student_id' => $row['student_id'],
                    'name' => composeStudentName($row),
                    'course' => $row['course'],
                    'year_level' => $row['year_level']
                ];
            }
            respondSuccess('Students loaded.', ['students' => $students]);
            break;

        case 'get_student_rfid':
            $studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
            if (!$studentId) {
                respondError('Student ID is required.');
            }

            // First check if student has rfid_uid
            $studentStmt = $pdo->prepare("SELECT rfid_uid FROM students WHERE id = ? AND is_deleted = 0");
            $studentStmt->execute([$studentId]);
            $student = $studentStmt->fetch(PDO::FETCH_ASSOC);

            if (!$student) {
                respondError('Student not found.');
            }

            if (empty($student['rfid_uid'])) {
                respondSuccess('No RFID assigned.', ['rfid' => null]);
            }

            // Get RFID tag details
            $rfidStmt = $pdo->prepare("
                SELECT rt.* 
                FROM rfid_tags rt
                WHERE rt.tag_uid = ? AND rt.student_id = ?
                LIMIT 1
            ");
            $rfidStmt->execute([$student['rfid_uid'], $studentId]);
            $rfid = $rfidStmt->fetch(PDO::FETCH_ASSOC);

            if ($rfid) {
                respondSuccess('RFID information loaded.', ['rfid' => $rfid]);
            } else {
                respondSuccess('RFID tag not found in database.', ['rfid' => null]);
            }
            break;

        case 'check_tag_exists':
            $uid = sanitizeUid($_GET['uid'] ?? '');
            if (empty($uid)) {
                respondError('UID is required.');
            }

            $stmt = $pdo->prepare("
                SELECT rt.*, 
                       s.student_id as student_student_id,
                       s.first_name, s.last_name
                FROM rfid_tags rt
                LEFT JOIN students s ON rt.student_id = s.id
                WHERE rt.tag_uid = ? 
                LIMIT 1
            ");
            $stmt->execute([$uid]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $statusText = $existing['status'] === 'assigned' ? 'assigned' : ($existing['status'] === 'disabled' ? 'disabled' : 'available');
                $studentInfo = '';
                if ($existing['student_id'] && $existing['first_name']) {
                    $studentInfo = ' (Assigned to: ' . htmlspecialchars($existing['student_student_id'] . ' - ' . $existing['first_name'] . ' ' . $existing['last_name']) . ')';
                }
                respondSuccess('Tag exists.', [
                    'exists' => true,
                    'tag' => $existing,
                    'message' => 'This RFID tag is already registered in the system.' . $studentInfo . ' Status: ' . ucfirst($statusText) . '.'
                ]);
            } else {
                respondSuccess('Tag does not exist.', [
                    'exists' => false
                ]);
            }
            break;

        default:
            respondError('Unknown action supplied.', 400);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('RFID action error: ' . $e->getMessage());
    respondError('Something went wrong. ' . $e->getMessage(), 500);
}

function ensurePost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respondError('Invalid request method.', 405);
    }
}

function sanitizeUid(string $uid): string
{
    $clean = strtoupper(preg_replace('/[^A-F0-9]/i', '', $uid));
    return trim($clean);
}

function buildState(PDO $pdo, $skipSync = false): array
{
    return [
        'tags' => fetchRfidTags($pdo, $skipSync),
        'stats' => getRfidStats($pdo),
        'available_tags' => getAvailableRfidTags($pdo)
    ];
}

function respondSuccess(string $message, array $data = []): void
{
    http_response_code(200);
    echo json_encode(array_merge(['success' => true, 'message' => $message], $data));
    exit;
}

function respondError(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}


