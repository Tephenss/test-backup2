<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'teacher') {
    header("Location: ../index.php");
    exit();
}

// Get parameters (POST first, then GET as fallback)
$class_id = $_POST['class_id'] ?? $_GET['class_id'] ?? '';
$format = $_POST['report_format'] ?? $_POST['format'] ?? $_GET['format'] ?? 'pdf';
$teacher_id = $_SESSION['user_id'] ?? '';

if (empty($class_id) || empty($teacher_id)) {
    die("Missing parameters. Please select a class.");
}

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

// Fetch class details for the selected class_id
$stmt = $pdo->prepare("
    SELECT 
        c.id as class_id,
        c.section,
        s.subject_code,
        s.subject_name,
        s.year_level,
        CONCAT(s.subject_code, ' - ', c.section, ' (', s.year_level, 'st Year)') as class_desc
    FROM classes c
    JOIN subjects s ON c.subject_id = s.id
    WHERE c.id = ? AND c.teacher_id = ? AND c.status = 'active'
    LIMIT 1
");
$stmt->execute([$class_id, $teacher_id]);
$class_info = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$class_info) {
    die("Class not found or you don't have permission to access this class.");
}

$section = $class_info['section'];
$subject = $class_info['subject_name'];
$year_level = $class_info['year_level'];
$subject_code = $class_info['subject_code'];

// Fetch attendance data - filter by specific class_id only (only students enrolled in this class)
// Start from class_students to ensure we ONLY get students enrolled in this specific class
// Also filter by student's section and year_level to match the class requirements
$query = "
    SELECT 
        s.student_id,
        CONCAT(s.last_name, ', ', s.first_name, ' ', LEFT(s.middle_name, 1)) as full_name,
        COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present_count,
        COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as absent_count,
        COUNT(CASE WHEN a.status = 'late' THEN 1 END) as late_count,
        COUNT(DISTINCT a.date) as total_classes
    FROM class_students cs
    INNER JOIN students s ON cs.student_id = s.id
    INNER JOIN classes c ON cs.class_id = c.id
    INNER JOIN subjects sub ON c.subject_id = sub.id
    LEFT JOIN attendance a ON s.id = a.student_id 
        AND a.class_id = cs.class_id
        AND a.date BETWEEN ? AND ?
    WHERE cs.class_id = ?
    AND cs.status = 'active'
    AND s.section = c.section
    AND s.year_level = sub.year_level
    AND s.is_deleted = 0
    AND s.status NOT IN ('graduated', 'promoted', 'dropped')
    GROUP BY s.student_id, s.last_name, s.first_name, s.middle_name
    ORDER BY s.last_name, s.first_name
";

// Determine date range for the query
$start_date = $current_semester ? $current_semester['start_date'] : date('Y-m-01'); // Default to start of current month
$end_date = $current_semester ? $current_semester['end_date'] : date('Y-m-t'); // Default to end of current month

$stmt = $pdo->prepare($query);
$stmt->execute([
    $start_date,
    $end_date,
    $class_id
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

// Date range text is not needed for attendance reports - using semester dates
$date_range_text = '';

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