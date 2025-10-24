<?php
session_start();
require_once '../config/database.php';
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    exit('Not authorized');
}
$student_id = $_SESSION['user_id'];
$selected_teacher = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : null;
$teachers = $pdo->query("
    SELECT t.id, t.full_name,
        (
            SELECT MAX(created_at) FROM messages m
            WHERE (m.sender_id = t.id AND m.sender_type = 'teacher' AND m.receiver_id = $student_id AND m.receiver_type = 'student')
               OR (m.sender_id = $student_id AND m.sender_type = 'student' AND m.receiver_id = t.id AND m.receiver_type = 'teacher')
        ) as last_msg_time,
        (
            SELECT COUNT(*) FROM messages m
            WHERE m.sender_id = t.id AND m.sender_type = 'teacher' AND m.receiver_id = $student_id AND m.receiver_type = 'student' AND m.is_read = 0
        ) as unread_count
    FROM teachers t
    JOIN classes c ON t.id = c.teacher_id
    JOIN class_students cs ON c.id = cs.class_id
    WHERE cs.student_id = $student_id
    GROUP BY t.id
    ORDER BY (last_msg_time IS NULL), last_msg_time DESC, t.full_name ASC
")->fetchAll();
foreach ($teachers as $t): ?>
<a href="?teacher_id=<?php echo $t['id']; ?>" class="list-group-item list-group-item-action<?php if ($selected_teacher == $t['id']) echo ' active'; ?> d-flex justify-content-between align-items-center">
    <span><?php echo htmlspecialchars($t['full_name']); ?></span>
    <?php if ($t['unread_count'] > 0): ?><span class="unread-badge"><?php echo $t['unread_count']; ?></span><?php endif; ?>
</a>
<?php endforeach; ?> 
 
 
 
 
 
 
 