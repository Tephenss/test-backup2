<?php
require_once '../config/database.php';
require_once '../helpers/functions.php';

// Check if user is logged in and is admin
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// Handle promotion process
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'promote') {
        try {
            $pdo->beginTransaction();
            
            // First handle 4th year students - mark them as graduates (soft delete)
            $stmt = $pdo->prepare("
                UPDATE students 
                SET status = 'graduated',
                    is_deleted = 1,
                    deleted_at = CURRENT_TIMESTAMP
                WHERE year_level = 4 
                AND status = 'approved'
                AND is_deleted = 0
            ");
            $stmt->execute();
            $graduated_count = $stmt->rowCount();
            
            // Deactivate class enrollments for graduated students
            $stmt = $pdo->prepare("
                UPDATE class_students cs
                JOIN students s ON cs.student_id = s.id
                SET cs.status = 'inactive'
                WHERE s.status = 'graduated'
            ");
            $stmt->execute();
            
            // Get students eligible for promotion (not in 4th year)
            $stmt = $pdo->prepare("
                SELECT id, year_level, section, course 
                FROM students 
                WHERE year_level < 4 
                AND status = 'approved'
                AND is_deleted = 0
                ORDER BY year_level, section
            ");
            $stmt->execute();
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $promoted_count = 0;
            $errors = [];
            
            // Clear ALL existing schedules for a fresh start
            $stmt = $pdo->prepare("DELETE FROM timetable");
            $stmt->execute();
            
            foreach ($students as $student) {
                $new_year_level = $student['year_level'] + 1;
                
                // Get course_id for section assignment
                $stmt = $pdo->prepare("SELECT id FROM courses WHERE code = ? LIMIT 1");
                $stmt->execute([$student['course']]);
                $course_id = $stmt->fetchColumn();
                
                // Find available section in new year level
                $stmt = $pdo->prepare("
                    SELECT name 
                    FROM sections 
                    WHERE year_level = ? AND course_id = ? 
                    ORDER BY name ASC
                ");
                $stmt->execute([$new_year_level, $course_id]);
                $sections = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                $assigned_section = null;
                foreach ($sections as $section) {
                    // Check section capacity
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM students 
                        WHERE section = ? AND year_level = ? AND status = 'approved'
                    ");
                    $stmt->execute([$section, $new_year_level]);
                    if ($stmt->fetchColumn() < 5) {
                        $assigned_section = $section;
                        break;
                    }
                }
                
                // If no section available, create new one
                if (!$assigned_section) {
                    $sectionLetters = array_merge(['A','B','C','D','E'], range('F', 'Z'));
                    foreach ($sectionLetters as $letter) {
                        if (!in_array($letter, $sections)) {
                            // Create new section
                            $stmt = $pdo->prepare("
                                INSERT INTO sections (name, course_id, year_level) 
                                VALUES (?, ?, ?)
                            ");
                            $stmt->execute([$letter, $course_id, $new_year_level]);
                            $assigned_section = $letter;
                            break;
                        }
                    }
                }
                
                if ($assigned_section) {
                    // Update student's year level and section
                    $stmt = $pdo->prepare("
                        UPDATE students 
                        SET year_level = ?, section = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([$new_year_level, $assigned_section, $student['id']]);
                    
                    // Update class enrollments
                    // First, deactivate old enrollments
                    $stmt = $pdo->prepare("
                        UPDATE class_students cs
                        JOIN classes c ON cs.class_id = c.id
                        JOIN sections s ON c.section = s.name
                        SET cs.status = 'inactive'
                        WHERE cs.student_id = ? 
                        AND s.year_level = ?
                    ");
                    $stmt->execute([$student['id'], $student['year_level']]);
                    
                    // Then, enroll in new year level classes (only if not already enrolled)
                    $stmt = $pdo->prepare("
                        INSERT IGNORE INTO class_students (class_id, student_id, status)
                        SELECT c.id, ?, 'active'
                        FROM classes c
                        JOIN sections s ON c.section = s.name
                        WHERE s.year_level = ?
                        AND c.section = ?
                        AND c.status = 'active'
                        AND NOT EXISTS (
                            SELECT 1 FROM class_students cs 
                            WHERE cs.class_id = c.id AND cs.student_id = ?
                        )
                    ");
                    $stmt->execute([$student['id'], $new_year_level, $assigned_section, $student['id']]);
                    
                    $promoted_count++;
                } else {
                    $errors[] = "Could not find or create section for student ID: " . $student['id'];
                }
            }
            
            if (empty($errors)) {
                $pdo->commit();
                $_SESSION['success'] = "Successfully promoted $promoted_count students to their next year level. ";
                $_SESSION['success'] .= "All schedules have been cleared and need to be set up again. ";
                if ($graduated_count > 0) {
                    $_SESSION['success'] .= "$graduated_count 4th year students have been marked as graduates.";
                }
            } else {
                $pdo->rollBack();
                $_SESSION['error'] = "Errors occurred during promotion: " . implode(", ", $errors);
            }
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Error during promotion process: " . $e->getMessage();
        }
        
        header('Location: promote_students.php');
        exit();
    }
}

// Get promotion statistics
$stats = [];
try {
    // Get count of students by year level (only approved/active students)
    $stmt = $pdo->query("
        SELECT year_level, COUNT(*) as count 
        FROM students 
        WHERE status = 'approved'
        AND is_deleted = 0
        GROUP BY year_level 
        ORDER BY year_level
    ");
    $stats['by_year'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Get count of students eligible for promotion
    $stmt = $pdo->query("
        SELECT COUNT(*) 
        FROM students 
        WHERE year_level < 4 
        AND status = 'approved'
        AND is_deleted = 0
    ");
    $stats['eligible'] = $stmt->fetchColumn();
    
    // Get count of graduating students
    $stmt = $pdo->query("
        SELECT COUNT(*) 
        FROM students 
        WHERE year_level = 4 
        AND status = 'approved'
        AND is_deleted = 0
    ");
    $stats['graduating'] = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    $_SESSION['error'] = "Error fetching statistics: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Promote Students - iAttendance</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
</head>
<body class="admin-page">
    <?php include 'sidebar.php'; ?>
    
    <main class="page-content">
        <?php include 'topbar.php'; ?>
        
        <div class="container-fluid px-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="mb-0">Promote Students</h1>
            </div>
            
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php 
                    echo $_SESSION['success'];
                    unset($_SESSION['success']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php 
                    echo $_SESSION['error'];
                    unset($_SESSION['error']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <div class="row">
                <div class="col-md-8">
                    <div class="card mb-4">
                        <div class="card-body">
                            <h5 class="card-title">Current Student Distribution</h5>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Year Level</th>
                                            <th>Number of Students</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php for ($i = 1; $i <= 4; $i++): ?>
                                            <tr>
                                                <td><?php echo $i; ?><?php echo ($i == 1) ? 'st' : (($i == 2) ? 'nd' : (($i == 3) ? 'rd' : 'th')); ?> Year</td>
                                                <td><?php echo isset($stats['by_year'][$i]) ? $stats['by_year'][$i] : 0; ?></td>
                                            </tr>
                                        <?php endfor; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card mb-4">
                        <div class="card-body">
                            <h5 class="card-title">Promotion Actions</h5>
                            <p>Students eligible for promotion: <strong><?php echo $stats['eligible']; ?></strong></p>
                            <?php if ($stats['graduating'] > 0): ?>
                            <p>Graduating students (4th year): <strong class="text-success"><?php echo $stats['graduating']; ?></strong></p>
                            <?php endif; ?>
                            <form action="" method="POST" id="promoteForm">
                                <input type="hidden" name="action" value="promote">
                                <button type="button" class="btn btn-primary w-100" onclick="confirmPromotion()">
                                    <i class="bi bi-arrow-up-circle me-2"></i>
                                    Promote Eligible Students
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <!-- Confirmation Modal -->
    <div class="modal fade" id="confirmPromotionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Promotion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        This action will promote all eligible students to their next year level. This process:
                        <ul class="mb-0 mt-2">
                            <li>Will affect <?php echo $stats['eligible']; ?> students</li>
                            <li>Will update their year levels</li>
                            <li>Will assign them to new sections</li>
                            <li>Will update their class enrollments</li>
                            <li class="text-warning">Will clear ALL schedules for a fresh start</li>
                            <?php if ($stats['graduating'] > 0): ?>
                            <li class="text-success">Will mark <?php echo $stats['graduating']; ?> 4th year students as graduates</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <p class="mb-0">Are you sure you want to continue?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="document.getElementById('promoteForm').submit();">
                        <i class="bi bi-arrow-up-circle me-2"></i>
                        Proceed with Promotion
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function confirmPromotion() {
            new bootstrap.Modal(document.getElementById('confirmPromotionModal')).show();
        }
    </script>
</body>
</html> 