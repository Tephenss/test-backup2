<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM timetable WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $_SESSION['success_message'] = "Schedule deleted successfully.";
    } catch(PDOException $e) {
        $_SESSION['error_message'] = "Error deleting schedule: " . $e->getMessage();
    }
    header("Location: manage_timetable.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];

    // Validate inputs
    $teacher_id = $_POST['teacher_id'] ?? '';
    $section_id = $_POST['section_id'] ?? '';
    $course_id = $_POST['course_id'] ?? '';
    $day_of_week = $_POST['day_of_week'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $room = $_POST['room'] ?? '';

    if (empty($teacher_id)) {
        $errors[] = "Teacher is required.";
    }
    if (empty($section_id)) {
        $errors[] = "Section is required.";
    }
    if (empty($course_id)) {
        $errors[] = "Course is required.";
    }
    if (empty($day_of_week)) {
        $errors[] = "Day is required.";
    }
    if (empty($start_time)) {
        $errors[] = "Start time is required.";
    }
    if (empty($end_time)) {
        $errors[] = "End time is required.";
    }
    if (empty($room)) {
        $errors[] = "Room is required.";
    }

    // Check for time conflicts
    if (empty($errors)) {
        try {
            // Check for teacher, section, or room conflict
            $conflict_check = $pdo->prepare("
                SELECT COUNT(*) FROM timetable 
                WHERE day_of_week = ? 
                AND (
                    (teacher_id = ? OR section_id = ? OR room = ?)
                )
                AND (
                    (start_time < ? AND end_time > ?)
                )
                " . (isset($_POST['timetable_id']) ? "AND id != ?" : "")
            );
            $params = [
                $day_of_week,
                $teacher_id,
                $section_id,
                $room,
                $end_time, $start_time
            ];
            if (isset($_POST['timetable_id'])) {
                $params[] = $_POST['timetable_id'];
            }
            $conflict_check->execute($params);
            if ($conflict_check->fetchColumn() > 0) {
                $errors[] = "Schedule conflict detected! Teacher, section, or room already has a schedule that overlaps with this time.";
            }
        } catch(PDOException $e) {
            $errors[] = "Error checking schedule conflicts: " . $e->getMessage();
        }
    }

    if (empty($errors)) {
        try {
            if (isset($_POST['timetable_id'])) {
                // Update existing timetable
                $stmt = $pdo->prepare("
                    UPDATE timetable 
                    SET teacher_id = ?, section_id = ?, course_id = ?, 
                        day_of_week = ?, start_time = ?, end_time = ?, room = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $teacher_id, $section_id, $course_id,
                    $day_of_week, $start_time, $end_time, $room,
                    $_POST['timetable_id']
                ]);
                $_SESSION['success_message'] = "Schedule updated successfully.";
            } else {
                // Insert new timetable
                $stmt = $pdo->prepare("
                    INSERT INTO timetable 
                    (teacher_id, section_id, course_id, day_of_week, start_time, end_time, room)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $teacher_id, $section_id, $course_id,
                    $day_of_week, $start_time, $end_time, $room
                ]);
                $_SESSION['success_message'] = "Schedule added successfully.";
            }
        } catch(PDOException $e) {
            $_SESSION['error_message'] = "Error saving schedule: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = implode("<br>", $errors);
    }
}

header("Location: manage_timetable.php");
exit();
?> 