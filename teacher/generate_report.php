<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'teacher') {
    header("Location: ../index.php");
    exit();
}

// Get parameters (POST first, then GET as fallback)
$section = $_POST['section'] ?? $_GET['section'] ?? '';
$subject = $_POST['subject'] ?? $_GET['subject'] ?? '';
$semester = $_POST['semester'] ?? $_GET['semester'] ?? '';
$term = $_POST['term'] ?? $_GET['term'] ?? '';
$date_range = $_POST['date_range'] ?? $_GET['date_range'] ?? '';
$format = $_POST['format'] ?? $_GET['format'] ?? 'pdf';
$teacher_id = $_SESSION['user_id'] ?? '';
$custom_start = $_POST['custom_start'] ?? $_GET['custom_start'] ?? '';
$custom_end = $_POST['custom_end'] ?? $_GET['custom_end'] ?? '';

// Fetch current semester settings
try {
    $stmt = $pdo->query("SELECT * FROM semester_settings WHERE is_current = TRUE LIMIT 1");
    $current_semester = $stmt->fetch();
} catch(PDOException $e) {
    $current_semester = null;
}

// Fetch teacher name
$teacher_name = '';
if (!empty($teacher_id)) {
    $stmt = $pdo->prepare("SELECT full_name FROM teachers WHERE id = ?");
    $stmt->execute([$teacher_id]);
    $teacher_row = $stmt->fetch(PDO::FETCH_ASSOC);
    $teacher_name = $teacher_row ? $teacher_row['full_name'] : '';
}

// Fetch year level for the selected class/section/subject
$year_level = '';
$stmt = $pdo->prepare("
    SELECT s.year_level 
    FROM classes c
    JOIN subjects s ON c.subject_id = s.id
    WHERE TRIM(LOWER(c.section)) = TRIM(LOWER(?)) 
      AND TRIM(LOWER(s.subject_name)) = TRIM(LOWER(?)) 
      AND c.teacher_id = ?
    LIMIT 1
");
$stmt->execute([$section, $subject, $teacher_id]);
$year_level = $stmt->fetchColumn();

if (empty($section) || empty($subject) || empty($teacher_id)) {
    die("Missing parameters. Please select all required fields.");
}
if ($term === false || $term === null) {
    die("No class found for the selected section, subject, and teacher. Please check your class assignments.");
}

// Fetch attendance data
$query = "
    SELECT 
        s.student_id,
        CONCAT(s.last_name, ', ', s.first_name, ' ', LEFT(s.middle_name, 1)) as full_name,
        COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present_count,
        COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as absent_count,
        COUNT(CASE WHEN a.status = 'late' THEN 1 END) as late_count,
        COUNT(DISTINCT a.date) as total_classes
    FROM students s
    JOIN class_students cs ON s.id = cs.student_id
    JOIN classes c ON cs.class_id = c.id
    LEFT JOIN attendance a ON s.id = a.student_id 
        AND c.id = a.class_id
        AND a.date BETWEEN ? AND ?
    WHERE c.section = ? 
    AND c.subject_id IN (SELECT id FROM subjects WHERE subject_name = ?)
    AND c.teacher_id = ?
    GROUP BY s.student_id, s.last_name, s.first_name, s.middle_name
    ORDER BY s.last_name, s.first_name
";

$stmt = $pdo->prepare($query);
$stmt->execute([
    $current_semester['start_date'],
    $current_semester['end_date'],
    $section,
    $subject,
    $teacher_id
]);
$attendance_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set headers based on format
if ($format === 'pdf') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="attendance_report_' . date('Y-m-d') . '.pdf"');
} else if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="attendance_report_' . date('Y-m-d') . '.xls"');
}

$ajax = isset($_POST['ajax']) || isset($_GET['ajax']);

// Parse and display date range
function getDateRangeText($date_range) {
    if (!$date_range) return '';
    $today = date('Y-m-d');
    $firstOfMonth = date('Y-m-01');
    $lastOfMonth = date('Y-m-t');
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $weekEnd = date('Y-m-d', strtotime('sunday this week'));
    if ($date_range === 'today') {
        return 'Date Range: ' . date('F j, Y');
    } elseif ($date_range === 'this_week') {
        return 'Date Range: ' . date('F j, Y', strtotime($weekStart)) . ' – ' . date('F j, Y', strtotime($weekEnd));
    } elseif ($date_range === 'this_month') {
        return 'Date Range: ' . date('F j, Y', strtotime($firstOfMonth)) . ' – ' . date('F j, Y', strtotime($lastOfMonth));
    } elseif (strpos($date_range, ' to ') !== false) {
        list($start, $end) = explode(' to ', $date_range);
        return 'Date Range: ' . date('F j, Y', strtotime($start)) . ' – ' . date('F j, Y', strtotime($end));
    }
    return '';
}

if ($custom_start && $custom_end) {
    $date_range_text = 'Date Range: ' . date('F j, Y', strtotime($custom_start)) . ' – ' . date('F j, Y', strtotime($custom_end));
} else {
    $date_range_text = getDateRangeText(strtolower($date_range));
}

date_default_timezone_set('Asia/Manila');
$generated_on = date('F j, Y g:i A');

if ($ajax) {
    // Semester/Date Range block
    $semBlock = '';
    if ($current_semester) {
        $semBlock .= '<div style="margin-bottom:10px;">';
        $semBlock .= '<strong>Semester:</strong> ' . htmlspecialchars($current_semester['semester']) . ' | ';
        $semBlock .= '<strong>Date Range:</strong> ' . date('M d, Y', strtotime($current_semester['start_date'])) . ' - ' . date('M d, Y', strtotime($current_semester['end_date'])) . '';
        $semBlock .= '</div>';
    } else {
        $semBlock .= '<div style="margin-bottom:10px;color:#b00;">Current semester/term not set.</div>';
    }
    // Only output the report table and info for modal
    echo $semBlock;
    echo '<br><br>';
    echo '<div style="text-align:center;font-size:28px;font-weight:bold;margin-bottom:10px;">iAttendance Report</div>';
    echo '<div class="report-info-flex" style="display: flex; flex-direction: column; margin-bottom: 20px;">';
    echo '<div style="display: flex; justify-content: space-between; align-items: flex-start;">';
    echo '<div><strong>Year Level / Section:</strong> ' . htmlspecialchars($year_level) . ' - ' . htmlspecialchars($section) . '</div>';
    echo '<div><strong>Subject:</strong> ' . htmlspecialchars($subject) . '</div>';
    echo '</div>';
    echo '<div style="display: flex; justify-content: space-between; align-items: flex-start;">';
    echo '<div><strong>Teacher:</strong> ' . htmlspecialchars($teacher_name) . '</div>';
    echo '<div><strong>Generated on:</strong> ' . $generated_on . '</div>';
    echo '</div>';
    echo '</div>';
    echo '<table class="table table-bordered">';
    echo '<thead><tr>';
    echo '<th>Student ID</th><th>Student Name</th><th>Present</th><th>Absent</th><th>Late</th><th>Total Classes</th><th>Attendance Rate</th>';
    echo '</tr></thead><tbody>';
    foreach ($attendance_data as $student) {
        $attendance_rate = $student['total_classes'] > 0 
            ? round(($student['present_count'] / $student['total_classes']) * 100, 2) 
            : 0;
        // Format student ID as 2025-007 if needed
        $formatted_id = $student['student_id'];
        if (preg_match('/^\d{7}$/', $student['student_id'])) {
            $formatted_id = substr($student['student_id'], 0, 4) . '-' . substr($student['student_id'], 4);
        }
        echo '<tr>';
        echo '<td>' . htmlspecialchars($formatted_id) . '</td>';
        echo '<td>' . htmlspecialchars($student['full_name']) . '</td>';
        echo '<td>' . $student['present_count'] . '</td>';
        echo '<td>' . $student['absent_count'] . '</td>';
        echo '<td>' . $student['late_count'] . '</td>';
        echo '<td>' . $student['total_classes'] . '</td>';
        echo '<td class="attendance-rate">' . $attendance_rate . '%</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<div class="footer"><p>Generated by: iAttendance Management System</p></div>';
    exit();
}

// Accurate Excel export: output only a minimal HTML table
if ($format === 'excel' && !$ajax) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="attendance_report_' . date('Y-m-d') . '.xls"');
    echo "<table border='1'>";
    echo "<tr>
    <th>Student ID</th>
        <th>Student Name</th>
        <th>Present</th>
        <th>Absent</th>
        <th>Late</th>
        <th>Total Classes</th>
        <th>Attendance Rate</th>
    </tr>";
    foreach (
        $attendance_data as $student) {
        $attendance_rate = $student['total_classes'] > 0 
            ? round(($student['present_count'] / $student['total_classes']) * 100, 2) 
            : 0;
        // Format student ID as 2025-007 if needed
        $formatted_id = $student['student_id'];
        if (preg_match('/^\d{7}$/', $student['student_id'])) {
            $formatted_id = substr($student['student_id'], 0, 4) . '-' . substr($student['student_id'], 4);
        }
        echo "<tr>";
        echo "<td>" . htmlspecialchars($formatted_id) . "</td>";
        echo "<td>" . htmlspecialchars($student['full_name']) . "</td>";
        echo "<td>" . $student['present_count'] . "</td>";
        echo "<td>" . $student['absent_count'] . "</td>";
        echo "<td>" . $student['late_count'] . "</td>";
        echo "<td>" . $student['total_classes'] . "</td>";
        echo "<td>" . $attendance_rate . "%</td>";
        echo "</tr>";
    }
    echo "</table>";
    exit();
}

// Generate report content
?>
<!DOCTYPE html>
<html>
<head>
    <title>iAttendance Report</title>
    <style>
        body { 
            font-family: 'Nunito', Arial, sans-serif;
            margin: 0;
            background: #f8f9fb;
        }
        .header { 
            text-align: center; 
            margin-bottom: 30px;
            background: #f4f7ff;
            padding: 32px 10px 18px 10px;
            border-bottom: 1.5px solid #e0e6ed;
        }
        .sem-block {
            display: inline-block;
            background: #e8f0fe;
            color: #234;
            border-radius: 8px;
            padding: 10px 28px;
            font-size: 1.08em;
            font-weight: 600;
            margin-bottom: 18px;
            margin-top: 0;
            letter-spacing: 0.5px;
        }
        .report-title {
            font-size: 2.1em;
            font-weight: 800;
            margin-bottom: 10px;
            color: #2a2a2a;
            letter-spacing: 1px;
        }
        .report-info {
            margin: 0 auto 18px auto;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 18px 32px;
            font-size: 1.08em;
            color: #444;
        }
        .report-info span {
            min-width: 180px;
            display: inline-block;
        }
        table { 
            border-collapse: separate; 
            border-spacing: 0;
            width: 100%; 
            margin-top: 20px;
            background: #fff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(60,60,100,0.06);
        }
        th, td { 
            border: none;
            padding: 13px 12px; 
            text-align: left; 
        }
        th { 
            background-color: #f4f7ff;
            font-weight: 700;
            color: #3a4a7a;
            font-size: 1.05em;
            border-bottom: 2px solid #e0e6ed;
        }
        tr:nth-child(even) {
            background-color: #f8f9fb;
        }
        tr:nth-child(odd) {
            background-color: #fff;
        }
        .attendance-rate {
            font-weight: bold;
            color: #2a7a2a;
        }
        .footer {
            margin-top: 30px;
            text-align: right;
            font-style: italic;
            color: #888;
            font-size: 1em;
        }
        .generated-on {
            margin-top: 10px;
            text-align: right;
            color: #888;
            font-size: 0.98em;
        }
    </style>
</head>
<body>
    <div class="header">
        <?php if ($current_semester): ?>
            <div class="sem-block">
                <strong>Semester:</strong> <?php echo htmlspecialchars($current_semester['semester']); ?> |
                <strong>Date Range:</strong> <?php echo date('M d, Y', strtotime($current_semester['start_date'])); ?> - <?php echo date('M d, Y', strtotime($current_semester['end_date'])); ?>
            </div>
        <?php else: ?>
            <div class="sem-block" style="color:#b00;background:#ffeaea;">Current semester/term not set.</div>
        <?php endif; ?>
        <br><br>
        <div class="report-title">iAttendance Report</div>
        <div class="report-info">
            <span><strong>Year Level / Section:</strong> <?php echo htmlspecialchars($year_level) . ' - ' . htmlspecialchars($section); ?></span>
            <span><strong>Subject:</strong> <?php echo htmlspecialchars($subject); ?></span>
            <span><strong>Teacher:</strong> <?php echo htmlspecialchars($teacher_name); ?></span>
            <span><strong>Term:</strong> <?php echo htmlspecialchars($term); ?></span>
        </div>
        <div class="generated-on"><strong>Generated on:</strong> <?php echo $generated_on; ?></div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Student ID</th>
                <th>Student Name</th>
                <th>Present</th>
                <th>Absent</th>
                <th>Late</th>
                <th>Total Classes</th>
                <th>Attendance Rate</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($attendance_data as $student): ?>
                <?php
                    $attendance_rate = $student['total_classes'] > 0 
                        ? round(($student['present_count'] / $student['total_classes']) * 100, 2) 
                        : 0;
                    $formatted_id = $student['student_id'];
                    if (preg_match('/^\d{7}$/', $student['student_id'])) {
                        $formatted_id = substr($student['student_id'], 0, 4) . '-' . substr($student['student_id'], 4);
                    }
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($formatted_id); ?></td>
                    <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                    <td><?php echo $student['present_count']; ?></td>
                    <td><?php echo $student['absent_count']; ?></td>
                    <td><?php echo $student['late_count']; ?></td>
                    <td><?php echo $student['total_classes']; ?></td>
                    <td class="attendance-rate"><?php echo $attendance_rate . '%'; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="footer">
        <p>Generated by: iAttendance Management System</p>
    </div>
</body>
</html> 