<?php
/**
 * Diagnostic script to test session filtering after reset
 */

session_start();
require_once '../config/database.php';
require_once '../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'teacher') {
    die("Unauthorized");
}

$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : null;
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : null;

echo "<h2>Session Filtering Diagnostic</h2>";
echo "<p>Class ID: " . htmlspecialchars($class_id) . "</p>";
echo "<p>Date: " . htmlspecialchars($date) . "</p>";
echo "<p>Session ID: " . htmlspecialchars($session_id) . "</p>";

if ($class_id && $date) {
    echo "<h3>MySQL Records:</h3>";
    
    // Check if timetable_id column exists
    $has_timetable_id_column = false;
    try {
        $check_column = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'timetable_id'");
        $has_timetable_id_column = ($check_column->rowCount() > 0);
    } catch (Exception $e) {
    }
    
    if ($has_timetable_id_column && $session_id) {
        $stmt = $pdo->prepare("SELECT * FROM attendance WHERE class_id = ? AND date = ? AND timetable_id = ?");
        $stmt->execute([$class_id, $date, $session_id]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM attendance WHERE class_id = ? AND date = ?");
        $stmt->execute([$class_id, $date]);
    }
    
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<p>Found " . count($records) . " record(s) in MySQL</p>";
    
    if (!empty($records)) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Student ID</th><th>Status</th><th>Session ID (timetable_id)</th><th>Date</th></tr>";
        foreach ($records as $record) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($record['id']) . "</td>";
            echo "<td>" . htmlspecialchars($record['student_id']) . "</td>";
            echo "<td>" . htmlspecialchars($record['status']) . "</td>";
            echo "<td>" . htmlspecialchars($record['timetable_id'] ?? 'NULL') . "</td>";
            echo "<td>" . htmlspecialchars($record['date']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Check for records from other sessions
    if ($has_timetable_id_column && $session_id) {
        echo "<h3>Records from OTHER sessions (should be 0 after reset):</h3>";
        $stmt = $pdo->prepare("SELECT * FROM attendance WHERE class_id = ? AND date = ? AND timetable_id != ? AND timetable_id IS NOT NULL");
        $stmt->execute([$class_id, $date, $session_id]);
        $otherRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<p>Found " . count($otherRecords) . " record(s) from other sessions</p>";
        
        if (!empty($otherRecords)) {
            echo "<table border='1' cellpadding='5'>";
            echo "<tr><th>ID</th><th>Student ID</th><th>Status</th><th>Session ID (timetable_id)</th><th>Date</th></tr>";
            foreach ($otherRecords as $record) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($record['id']) . "</td>";
                echo "<td>" . htmlspecialchars($record['student_id']) . "</td>";
                echo "<td>" . htmlspecialchars($record['status']) . "</td>";
                echo "<td>" . htmlspecialchars($record['timetable_id']) . "</td>";
                echo "<td>" . htmlspecialchars($record['date']) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    }
}

echo "<p><a href='manage_attendance.php'>Back to Manage Attendance</a></p>";
?>







