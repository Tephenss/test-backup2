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
            respondSuccess('RFID tags loaded.', buildState($pdo));
            break;

        case 'register_tag':
            ensurePost();
            $uid = sanitizeUid($_POST['uid'] ?? '');
            $label = trim($_POST['label'] ?? '');
            $source = trim($_POST['source'] ?? 'live_scanner');

            if (empty($uid)) {
                respondError('Walang nakuhang UID. Pakiscan muli ang RFID card.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM rfid_tags WHERE tag_uid = ? LIMIT 1");
            $stmt->execute([$uid]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $pdo->prepare("
                    UPDATE rfid_tags 
                    SET last_seen = NOW(), 
                        last_source = :source,
                        status = CASE WHEN status = 'disabled' THEN status ELSE status END
                    WHERE id = :id
                ")->execute([
                    ':source' => $source ?: null,
                    ':id' => $existing['id']
                ]);
                $pdo->commit();

                $tag = getRfidTagById($pdo, (int)$existing['id']);
                respondSuccess('RFID tag already exists in the system.', array_merge(buildState($pdo), [
                    'tag' => $tag,
                    'existing' => true
                ]));
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

                respondSuccess('RFID tag registered successfully.', array_merge(buildState($pdo), [
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
                respondError('Kumpletohin ang RFID at Student bago mag-assign.');
            }

            $tag = getRfidTagById($pdo, $tagId);
            if (!$tag) {
                respondError('Tag not found.');
            }
            if ($tag['status'] === 'disabled') {
                respondError('Hindi puwedeng i-assign ang disabled tag.');
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

            // Clear RFID reference from any tag currently assigned to this student
            $clearStmt = $pdo->prepare("SELECT id FROM rfid_tags WHERE student_id = ? AND id != ?");
            $clearStmt->execute([$studentId, $tagId]);
            while ($otherId = $clearStmt->fetchColumn()) {
                $pdo->prepare("UPDATE rfid_tags SET student_id = NULL, status = 'available', assigned_at = NULL WHERE id = ?")
                    ->execute([$otherId]);
            }

            // Detach tag from previous student if needed
            if (!empty($tag['student_id']) && $tag['student_id'] != $studentId) {
                $pdo->prepare("UPDATE students SET rfid_uid = NULL WHERE id = ?")
                    ->execute([$tag['student_id']]);
            }

            // Update tag + student record
            $pdo->prepare("
                UPDATE rfid_tags 
                SET student_id = ?, status = 'assigned', assigned_at = NOW(), last_seen = NOW()
                WHERE id = ?
            ")->execute([$studentId, $tagId]);

            $pdo->prepare("UPDATE students SET rfid_uid = ? WHERE id = ?")
                ->execute([$tag['tag_uid'], $studentId]);

            $pdo->commit();

            $updatedTag = getRfidTagById($pdo, $tagId);
            if ($updatedTag) {
                $backupHooks->backupRfidTagEvent($updatedTag, 'assign');
                $backupHooks->backupStudentUpdate($studentId, ['rfid_uid' => $updatedTag['tag_uid']]);
            }

            respondSuccess('RFID tag assigned successfully.', array_merge(buildState($pdo), [
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

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE students SET rfid_uid = NULL WHERE id = ?")
                ->execute([$tag['student_id']]);
            $pdo->prepare("UPDATE rfid_tags SET student_id = NULL, status = 'available', assigned_at = NULL WHERE id = ?")
                ->execute([$tagId]);
            $pdo->commit();

            $updatedTag = getRfidTagById($pdo, $tagId);
            if ($updatedTag) {
                $backupHooks->backupRfidTagEvent($updatedTag, 'unassign');
            }

            respondSuccess('RFID tag unassigned successfully.', buildState($pdo));
            break;

        case 'search_students':
            $query = trim($_GET['query'] ?? '');
            if (strlen($query) < 2) {
                respondSuccess('Need at least 2 characters.', ['students' => []]);
            }

            $like = '%' . $query . '%';
            $stmt = $pdo->prepare("
                SELECT id, student_id, first_name, middle_name, last_name, suffix_name, course, year_level 
                FROM students 
                WHERE is_deleted = 0
                  AND (
                      student_id LIKE :q OR 
                      first_name LIKE :q OR 
                      last_name LIKE :q OR 
                      CONCAT(first_name, ' ', last_name) LIKE :q
                  )
                ORDER BY last_name ASC, first_name ASC
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

function buildState(PDO $pdo): array
{
    return [
        'tags' => fetchRfidTags($pdo),
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


