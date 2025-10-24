<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'teacher') {
    http_response_code(403);
    exit('Forbidden');
}

$class_id = $_POST['class_id'] ?? '';
$assessment_type = $_POST['assessment_type'] ?? '';
$date = $_POST['date'] ?? '';
$term = $_POST['term'] ?? '';

$students = [];
if ($class_id) {
    if (!empty($assessment_type) && !empty($date)) {
        $query = "
            SELECT 
                s.id,
                s.student_id as roll_number,
                CONCAT(s.first_name, ' ', s.last_name) as full_name,
                s.course,
                s.year_level,
                s.section,
                m.marks as existing_marks,
                m.total_marks as existing_total
            FROM students s
            JOIN class_students cs ON s.id = cs.student_id
            LEFT JOIN marks m ON s.id = m.student_id 
                AND m.class_id = ? 
                AND m.assessment_type_id = ? 
                AND m.date = ?" 
                . ($term ? " AND m.term = ?" : "") . "
            WHERE cs.class_id = ? AND cs.status = 'active'
            ORDER BY s.first_name, s.last_name
        ";
        $params = [$class_id, $assessment_type, $date];
        if ($term) {
            $params[] = $term;
        }
        $params[] = $class_id;
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $query = "
            SELECT 
                s.id,
                s.student_id as roll_number,
                CONCAT(s.first_name, ' ', s.last_name) as full_name,
                s.course,
                s.year_level,
                s.section
            FROM students s
            JOIN class_students cs ON s.id = cs.student_id
            WHERE cs.class_id = ? AND cs.status = 'active'
            ORDER BY s.first_name, s.last_name
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$class_id]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

ob_start();
if (!empty($students)) {
    foreach ($students as $student) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars(preg_replace('/^(\d{4})(\d+)$/', '$1-$2', $student['roll_number'])) . '</td>';
        echo '<td>' . htmlspecialchars($student['full_name']) . '</td>';
        echo '<td>' . htmlspecialchars($student['course']) . '</td>';
        echo '<td>' . htmlspecialchars($student['year_level'] . ' - ' . $student['section']) . '</td>';
        if (isset($student['existing_marks'])) {
            echo '<td><input type="number" class="form-control marks-input" name="marks[' . $student['id'] . '][score]" value="' . htmlspecialchars($student['existing_marks']) . '" min="0" max="100" step="0.01" data-student-name="' . htmlspecialchars($student['full_name']) . '" onchange="validateMarks(this)"></td>';
            echo '<td class="mark-status"><span class="badge bg-success">Recorded</span></td>';
        } else {
            echo '<td><input type="number" class="form-control marks-input" name="marks[' . $student['id'] . '][score]" value="" min="0" max="100" step="0.01" data-student-name="' . htmlspecialchars($student['full_name']) . '" onchange="validateMarks(this)"></td>';
            echo '<td class="mark-status"><span class="badge bg-warning text-dark">Pending</span></td>';
        }
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="6" class="text-center">No students found in this class.</td></tr>';
}
$html = ob_get_clean();
echo $html; 