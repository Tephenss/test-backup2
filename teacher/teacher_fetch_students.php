<?php
session_start();
require_once '../config/database.php';
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'teacher') {
    exit('Not authorized');
}
$teacher_id = $_SESSION['user_id'];
$selected_student = isset($_GET['student_id']) ? (int)$_GET['student_id'] : null;
$students = $pdo->query("
    SELECT s.id, CONCAT(s.first_name, ' ', s.last_name) as full_name,
        (
            SELECT MAX(created_at) FROM messages m
            WHERE (m.sender_id = s.id AND m.sender_type = 'student' AND m.receiver_id = $teacher_id AND m.receiver_type = 'teacher')
               OR (m.sender_id = $teacher_id AND m.sender_type = 'teacher' AND m.receiver_id = s.id AND m.receiver_type = 'student')
        ) as last_msg_time,
        (
            SELECT COUNT(*) FROM messages m
            WHERE m.sender_id = s.id AND m.sender_type = 'student' AND m.receiver_id = $teacher_id AND m.receiver_type = 'teacher' AND m.is_read = 0
        ) as unread_count
    FROM students s
    JOIN class_students cs ON s.id = cs.student_id
    JOIN classes c ON cs.class_id = c.id
    WHERE c.teacher_id = $teacher_id
    GROUP BY s.id
    ORDER BY (last_msg_time IS NULL), last_msg_time DESC, full_name ASC
")->fetchAll();
foreach ($students as $s): ?>
<a href="?student_id=<?php echo $s['id']; ?>" class="list-group-item list-group-item-action<?php if ($selected_student == $s['id']) echo ' active'; ?> d-flex justify-content-between align-items-center">
    <span><?php echo htmlspecialchars($s['full_name']); ?></span>
    <?php if ($s['unread_count'] > 0): ?><span class="unread-badge"><?php echo $s['unread_count']; ?></span><?php endif; ?>
</a>
<?php endforeach; ?> 
 
 
 
 
 
 
 