<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Check if semester_settings table exists and create it if it doesn't
try {
    // First check if table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'semester_settings'");
    if ($stmt->rowCount() == 0) {
        // Table doesn't exist, create it
        $pdo->exec("CREATE TABLE `semester_settings` (
            `id` int(11) NOT NULL AUTO_INCREMENT,   
            `semester` varchar(50) NOT NULL,
            `start_date` date NOT NULL,
            `end_date` date NOT NULL,
            `prelim_start` date DEFAULT NULL,
            `prelim_end` date DEFAULT NULL,
            `midterm_start` date DEFAULT NULL,
            `midterm_end` date DEFAULT NULL,
            `final_start` date DEFAULT NULL,
            `final_end` date DEFAULT NULL,
            `is_current` tinyint(1) NOT NULL DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
        
        file_put_contents(__DIR__ . '/debug_table_created.txt', "Table created successfully");
    } else {
        // Table exists, check if it has all required columns
        $stmt = $pdo->query("SHOW COLUMNS FROM semester_settings");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $required_columns = [
            'id', 'semester', 'start_date', 'end_date', 
            'prelim_start', 'prelim_end', 
            'midterm_start', 'midterm_end', 
            'final_start', 'final_end', 
            'is_current', 'created_at'
        ];
        
        $missing_columns = array_diff($required_columns, $columns);
        if (!empty($missing_columns)) {
            // Add missing columns
            foreach ($missing_columns as $column) {
                switch ($column) {
                    case 'prelim_start':
                    case 'prelim_end':
                    case 'midterm_start':
                    case 'midterm_end':
                    case 'final_start':
                    case 'final_end':
                        $pdo->exec("ALTER TABLE semester_settings ADD COLUMN `$column` date DEFAULT NULL");
                        break;
                    case 'is_current':
                        $pdo->exec("ALTER TABLE semester_settings ADD COLUMN `$column` tinyint(1) NOT NULL DEFAULT 0");
                        break;
                    case 'created_at':
                        $pdo->exec("ALTER TABLE semester_settings ADD COLUMN `$column` timestamp NOT NULL DEFAULT current_timestamp()");
                        break;
                }
            }
            file_put_contents(__DIR__ . '/debug_table_altered.txt', "Added missing columns: " . implode(', ', $missing_columns));
        }
    }
} catch(PDOException $e) {
    file_put_contents(__DIR__ . '/debug_table_error.txt', $e->getMessage());
}

// --- ONE-TIME SYNC: Ensure all subject_assignments have a matching class record ---
try {
    $stmt = $pdo->query("SELECT sa.teacher_id, sa.subject_id, sa.section_id, s.subject_code, sec.name as section_name, sec.year_level FROM subject_assignments sa JOIN subjects s ON sa.subject_id = s.id JOIN sections sec ON sa.section_id = sec.id");
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($assignments as $a) {
        // Check if class already exists
        $stmtClass = $pdo->prepare("SELECT id FROM classes WHERE teacher_id = ? AND subject_id = ? AND section = ?");
        $stmtClass->execute([$a['teacher_id'], $a['subject_id'], $a['section_name']]);
        if (!$stmtClass->fetch()) {
            // Insert new class record
            $stmtInsert = $pdo->prepare("INSERT INTO classes (teacher_id, subject_id, section, status) VALUES (?, ?, ?, 'active')");
            $stmtInsert->execute([$a['teacher_id'], $a['subject_id'], $a['section_name']]);
        }
    }
} catch (PDOException $e) {
    error_log('SYNC ERROR: ' . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug: log all POST data for troubleshooting
    file_put_contents(__DIR__ . '/debug_semester_post.txt', print_r($_POST, true));
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'assign_subject':
                $teacher_id = $_POST['teacher_id'];
                $section_id = $_POST['section_id'];
                $start_date = $_POST['start_date'];
                $end_date = $_POST['end_date'];
                $semester = $_POST['semester'];
                $year_level = $_POST['year_level'];
                $academic_year = isset($_POST['academic_year']) ? $_POST['academic_year'] : date('Y') . '-' . (date('Y')+1);
                // Support multiple subject assignment
                $subject_ids = isset($_POST['subject_ids']) ? $_POST['subject_ids'] : (isset($_POST['subject_id']) ? [$_POST['subject_id']] : []);
                if (empty($subject_ids) || !$teacher_id || !$section_id || !$semester) {
                    $_SESSION['error_message'] = "Please select a teacher, section, semester, and at least one subject.";
                    break;
                }
                // --- LIMIT: Only 2 subjects per teacher per year level and section ---
                $stmtLimit = $pdo->prepare("SELECT COUNT(*) FROM subject_assignments sa JOIN sections sec ON sa.section_id = sec.id WHERE sa.teacher_id = ? AND sa.section_id = ? AND sec.year_level = ? AND sa.semester = ?");
                $stmtLimit->execute([$teacher_id, $section_id, $year_level, $semester]);
                $currentCount = $stmtLimit->fetchColumn();
                if ($currentCount >= 2) {
                    $_SESSION['error_message'] = "A teacher can only be assigned to 2 subjects per year level and section.";
                    break;
                }
                $success_count = 0;
                $error_count = 0;
                foreach ($subject_ids as $subject_id) {
                    if (!$subject_id) continue;
                    $stmtLimit->execute([$teacher_id, $section_id, $year_level, $semester]);
                    $currentCount = $stmtLimit->fetchColumn();
                    if ($currentCount >= 2) {
                        $_SESSION['error_message'] = "A teacher can only be assigned to 2 subjects per year level and section.";
                        break 2;
                    }
                    try {
                        // Check if assignment already exists
                        $stmt = $pdo->prepare("SELECT id FROM subject_assignments 
                            WHERE teacher_id = ? AND subject_id = ? AND section_id = ? 
                            AND semester = ?");
                        $stmt->execute([$teacher_id, $subject_id, $section_id, $semester]);
                        if (!$stmt->fetch()) {
                            $stmt = $pdo->prepare("INSERT INTO subject_assignments 
                                (teacher_id, subject_id, section_id, start_date, end_date, semester) 
                                VALUES (?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$teacher_id, $subject_id, $section_id, $start_date, $end_date, $semester]);
                            if ($stmt->errorCode() !== '00000') {
                                file_put_contents(__DIR__ . '/debug_sql_error.txt', print_r($stmt->errorInfo(), true));
                                $error_count++;
                                continue;
                            }
                            $success_count++;
                            // --- AUTO-CREATE CLASS RECORD IF NOT EXISTS ---
                            $stmtSection = $pdo->prepare("SELECT name, year_level FROM sections WHERE id = ?");
                            $stmtSection->execute([$section_id]);
                            $section_row = $stmtSection->fetch(PDO::FETCH_ASSOC);
                            $section_name = $section_row['name'];
                            $year_level_val = $section_row['year_level'];
                            $stmtClass = $pdo->prepare("SELECT id FROM classes WHERE teacher_id = ? AND section = ? AND subject_id = ? AND academic_year = ? AND semester = ?");
                            $stmtClass->execute([$teacher_id, $section_name, $subject_id, $academic_year, $semester]);
                            if (!$stmtClass->fetch()) {
                                $stmtInsert = $pdo->prepare("INSERT INTO classes (teacher_id, section, subject_id, academic_year, semester, year_level, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
                                $stmtInsert->execute([$teacher_id, $section_name, $subject_id, $academic_year, $semester, $year_level_val]);
                            }
                        } else {
                            $error_count++;
                        }
                    } catch(PDOException $e) {
                        $error_count++;
                        file_put_contents(__DIR__ . '/debug_assign_subject_error.txt', $e->getMessage());
                    }
                }
                if ($success_count > 0) {
                    $_SESSION['success_message'] = "Subject(s) successfully assigned to teacher.";
                } elseif ($error_count > 0) {
                    $_SESSION['error_message'] = "Some or all subjects could not be assigned (may already be assigned or an error occurred).";
                }
                break;

            case 'add_subject':
                $subject_code = $_POST['subject_code'];
                $subject_name = $_POST['subject_name'];
                $description = $_POST['description'];
                $units = $_POST['units'];
                $year_level = $_POST['year_level'];
                try {
                    $stmt = $pdo->prepare("INSERT INTO subjects (subject_code, subject_name, description, units, year_level) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$subject_code, $subject_name, $description, $units, $year_level]);
                    $_SESSION['success_message'] = "Subject added successfully.";
                } catch(PDOException $e) {
                    $_SESSION['error_message'] = "Error adding subject: " . $e->getMessage();
                }
                break;

            case 'remove_assignment':
                $assignment_id = $_POST['assignment_id'];
                try {
                    // Get assignment details before deleting
                    $stmt = $pdo->prepare("SELECT teacher_id, subject_id, section_id, semester FROM subject_assignments WHERE id = ?");
                    $stmt->execute([$assignment_id]);
                    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($assignment) {
                        // Get section name
                        $stmtSection = $pdo->prepare("SELECT name FROM sections WHERE id = ?");
                        $stmtSection->execute([$assignment['section_id']]);
                        $section_name = $stmtSection->fetchColumn();
                        // Delete class record(s) for this assignment
                        $stmtDeleteClass = $pdo->prepare("DELETE FROM classes WHERE teacher_id = ? AND subject_id = ? AND section = ? AND semester = ?");
                        $stmtDeleteClass->execute([
                            $assignment['teacher_id'],
                            $assignment['subject_id'],
                            $section_name,
                            $assignment['semester']
                        ]);
                    }
                    // Delete the assignment
                    $stmt = $pdo->prepare("DELETE FROM subject_assignments WHERE id = ?");
                    $stmt->execute([$assignment_id]);
                    $_SESSION['success_message'] = "Subject assignment removed successfully.";
                } catch(PDOException $e) {
                    $_SESSION['error_message'] = "Error removing assignment: " . $e->getMessage();
                }
                break;

            case 'delete_subject':
                $subject_id = $_POST['subject_id'];
                try {
                    // Check if subject is assigned to any active classes
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM classes c 
                        WHERE c.subject_id = ? AND c.status = 'active'
                    ");
                    $stmt->execute([$subject_id]);
                    $activeClasses = $stmt->fetchColumn();
                    
                    if ($activeClasses > 0) {
                        $_SESSION['error_message'] = "Cannot delete subject: It is currently assigned to active classes.";
                    } else {
                        // Use soft delete instead of hard delete
                        if (softDelete('subjects', $subject_id)) {
                            $_SESSION['success_message'] = "Subject has been moved to archive.";
                        } else {
                            $_SESSION['error_message'] = "Error archiving subject.";
                        }
                    }
                } catch(PDOException $e) {
                    $_SESSION['error_message'] = "Error: " . $e->getMessage();
                }
                header("Location: manage_subjects.php");
                exit();
                break;

            case 'delete_all_subjects':
                try {
                    // Get all subject IDs
                    $stmt = $pdo->query("SELECT id FROM subjects");
                    $subjectIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($subjectIds as $subject_id) {
                        // Get all class IDs for this subject
                        $stmt2 = $pdo->prepare("SELECT id FROM classes WHERE subject_id = ?");
                        $stmt2->execute([$subject_id]);
                        $classIds = $stmt2->fetchAll(PDO::FETCH_COLUMN);
                        if (!empty($classIds)) {
                            // Delete all timetable entries for these classes
                            $in = str_repeat('?,', count($classIds) - 1) . '?';
                            $stmt3 = $pdo->prepare("DELETE FROM timetable WHERE class_id IN ($in)");
                            $stmt3->execute($classIds);
                        }
                        // Delete all classes referencing this subject
                        $stmt2 = $pdo->prepare("DELETE FROM classes WHERE subject_id = ?");
                        $stmt2->execute([$subject_id]);
                    }
                    // Delete all subjects
                    $stmt = $pdo->prepare("DELETE FROM subjects");
                    $stmt->execute();
                    $_SESSION['success_message'] = "All subjects and their related data have been deleted.";
                } catch(PDOException $e) {
                    $_SESSION['error_message'] = "Error deleting all subjects: " . $e->getMessage();
                }
                header("Location: manage_subjects.php");
                exit();

            case 'set_semester':
                $semester = !empty($_POST['semester']) ? $_POST['semester'] : null;
                $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
                $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
                $prelim_start = !empty($_POST['prelim_start']) ? $_POST['prelim_start'] : null;
                $prelim_end = !empty($_POST['prelim_end']) ? $_POST['prelim_end'] : null;
                $midterm_start = !empty($_POST['midterm_start']) ? $_POST['midterm_start'] : null;
                $midterm_end = !empty($_POST['midterm_end']) ? $_POST['midterm_end'] : null;
                $final_start = !empty($_POST['final_start']) ? $_POST['final_start'] : null;
                $final_end = !empty($_POST['final_end']) ? $_POST['final_end'] : null;

                // Debug log the received values
                file_put_contents(__DIR__ . '/debug_semester_values.txt', print_r([
                    'semester' => $semester,
                    'start_date' => $start_date,
                    'end_date' => $end_date,
                    'prelim_start' => $prelim_start,
                    'prelim_end' => $prelim_end,
                    'midterm_start' => $midterm_start,
                    'midterm_end' => $midterm_end,
                    'final_start' => $final_start,
                    'final_end' => $final_end
                ], true));
                
                // Validate dates
                if ($start_date && $end_date) {
                    $start = strtotime($start_date);
                    $end = strtotime($end_date);
                    if ($end < $start) {
                        $_SESSION['error_message'] = "End date cannot be earlier than start date.";
                        header("Location: manage_subjects.php");
                        exit();
                    }
                }
                
                // Validate term dates are within semester range
                if ($start_date && $end_date) {
                    $semester_start = strtotime($start_date);
                    $semester_end = strtotime($end_date);
                    
                    $terms = [
                        'Prelim' => ['start' => $prelim_start, 'end' => $prelim_end],
                        'Midterm' => ['start' => $midterm_start, 'end' => $midterm_end],
                        'Final' => ['start' => $final_start, 'end' => $final_end]
                    ];
                    
                    foreach ($terms as $term => $dates) {
                        if ($dates['start'] && $dates['end']) {
                            $term_start = strtotime($dates['start']);
                            $term_end = strtotime($dates['end']);
                            
                            if ($term_start < $semester_start || $term_end > $semester_end) {
                                $_SESSION['error_message'] = "$term dates must be within the semester date range.";
                                header("Location: manage_subjects.php");
                                exit();
                            }
                            
                            if ($term_end < $term_start) {
                                $_SESSION['error_message'] = "$term end date cannot be earlier than start date.";
                                header("Location: manage_subjects.php");
                                exit();
                            }
                        }
                    }
                }

                try {
                    // First, set all existing records to not current
                    $stmt = $pdo->prepare("UPDATE semester_settings SET is_current = FALSE");
                    $stmt->execute();
                    
                    // Log the SQL query and parameters
                    $sql = "INSERT INTO semester_settings (semester, start_date, end_date, prelim_start, prelim_end, midterm_start, midterm_end, final_start, final_end, is_current) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE)";
                    $params = [$semester, $start_date, $end_date, $prelim_start, $prelim_end, $midterm_start, $midterm_end, $final_start, $final_end];
                    
                    file_put_contents(__DIR__ . '/debug_sql_query.txt', "SQL: " . $sql . "\nParams: " . print_r($params, true));

                    // Insert new semester settings with all values
                    $stmt = $pdo->prepare($sql);
                    $result = $stmt->execute($params);

                    // Log any SQL errors
                    if ($stmt->errorCode() !== '00000') {
                        file_put_contents(__DIR__ . '/debug_sql_error.txt', print_r([
                            'errorCode' => $stmt->errorCode(),
                            'errorInfo' => $stmt->errorInfo(),
                            'sql' => $sql,
                            'params' => $params
                        ], true));
                    }

                    // Log the result of the insert
                    file_put_contents(__DIR__ . '/debug_insert_result.txt', print_r([
                        'success' => $result,
                        'lastInsertId' => $pdo->lastInsertId(),
                        'errorCode' => $stmt->errorCode(),
                        'errorInfo' => $stmt->errorInfo(),
                        'rowCount' => $stmt->rowCount()
                    ], true));

                    if ($result) {
                    $_SESSION['success_message'] = "Semester settings updated successfully.";
                    } else {
                        $_SESSION['error_message'] = "Failed to update semester settings.";
                    }
                } catch(PDOException $e) {
                    $_SESSION['error_message'] = "Error setting semester: " . $e->getMessage();
                    file_put_contents(__DIR__ . '/debug_pdo_error.txt', print_r([
                        'message' => $e->getMessage(),
                        'code' => $e->getCode(),
                        'trace' => $e->getTraceAsString()
                    ], true));
                }
                break;

            case 'reset_assignments':
                try {
                    $stmt = $pdo->prepare("DELETE FROM subject_assignments");
                    $stmt->execute();
                    $_SESSION['success_message'] = "All subject assignments have been reset.";
                } catch(PDOException $e) {
                    $_SESSION['error_message'] = "Error resetting assignments: " . $e->getMessage();
                }
                header("Location: manage_subjects.php");
                exit();
        }
        
        header("Location: manage_subjects.php");
        exit();
    }
}

// Get all teachers
try {
    $stmt = $pdo->query("SELECT id, full_name FROM teachers ORDER BY full_name");
    $teachers = $stmt->fetchAll();
} catch(PDOException $e) {
    $teachers = [];
}

// Get all subjects with year level
try {
    $stmt = $pdo->query("SELECT id, subject_code, subject_name, description, units, year_level FROM subjects ORDER BY year_level, subject_code");
    $subjects = $stmt->fetchAll();
} catch(PDOException $e) {
    $subjects = [];
}

// Get all sections grouped by year level
try {
    $stmt = $pdo->query("SELECT id, name, year_level FROM sections ORDER BY year_level, name");
    $sections = $stmt->fetchAll();
    $sections_by_year = [];
    foreach ($sections as $section) {
        $sections_by_year[$section['year_level']][] = $section;
    }
} catch(PDOException $e) {
    $sections = [];
    $sections_by_year = [];
}

// Get current assignments with current semester settings
try {
    $stmt = $pdo->query("
        SELECT sa.id, sa.section_id, t.teacher_id AS formatted_teacher_id, t.full_name as teacher_name, s.subject_code, s.subject_name,
               sec.name as section_name, sec.year_level, sa.start_date, sa.end_date,
               COALESCE(sa.semester, ss.semester) as semester, 
               'Prelim' as term_period
        FROM subject_assignments sa
        JOIN teachers t ON sa.teacher_id = t.id
        JOIN subjects s ON sa.subject_id = s.id
        JOIN sections sec ON sa.section_id = sec.id
        LEFT JOIN semester_settings ss ON ss.is_current = TRUE
        ORDER BY sec.year_level, sa.semester, s.subject_code, sec.name
    ");
    $assignments = $stmt->fetchAll();
} catch(PDOException $e) {
    $assignments = [];
}

// Get current semester settings
try {
    $stmt = $pdo->query("SELECT * FROM semester_settings WHERE is_current = TRUE ORDER BY id DESC LIMIT 1");
    $current_semester = $stmt->fetch();
    
    // Debug log the current semester settings
    file_put_contents(__DIR__ . '/debug_current_semester.txt', print_r($current_semester, true));
} catch(PDOException $e) {
    $current_semester = null;
    file_put_contents(__DIR__ . '/debug_current_semester_error.txt', $e->getMessage());
}

// --- DEBUG: Print all subject assignments and classes for a specific teacher (change teacher_id as needed) ---
if (isset($_GET['debug_teacher'])) {
    $teacher_id = (int)$_GET['debug_teacher'];
    echo '<div style="background:#fffbe6;border:1px solid #ffe58f;padding:16px;margin:16px 0;">';
    echo '<h3>Debug: Subject Assignments for Teacher ID ' . $teacher_id . '</h3>';
    $stmt = $pdo->prepare("SELECT sa.*, s.subject_code, sec.name as section_name FROM subject_assignments sa JOIN subjects s ON sa.subject_id = s.id JOIN sections sec ON sa.section_id = sec.id WHERE sa.teacher_id = ?");
    $stmt->execute([$teacher_id]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo '<b>subject_assignments:</b><br><table border=1 cellpadding=4><tr><th>subject_id</th><th>subject_code</th><th>section_id</th><th>section_name</th><th>semester</th><th>term_period</th></tr>';
    foreach ($assignments as $a) {
        echo '<tr><td>' . $a['subject_id'] . '</td><td>' . htmlspecialchars($a['subject_code']) . '</td><td>' . $a['section_id'] . '</td><td>' . htmlspecialchars($a['section_name']) . '</td><td>' . htmlspecialchars($a['semester']) . '</td><td>' . htmlspecialchars($a['term_period']) . '</td></tr>';
    }
    echo '</table>';
    $stmt = $pdo->prepare("SELECT c.*, s.subject_code FROM classes c JOIN subjects s ON c.subject_id = s.id WHERE c.teacher_id = ?");
    $stmt->execute([$teacher_id]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo '<b>classes:</b><br><table border=1 cellpadding=4><tr><th>id</th><th>subject_id</th><th>subject_code</th><th>section</th><th>status</th></tr>';
    foreach ($classes as $c) {
        echo '<tr><td>' . $c['id'] . '</td><td>' . $c['subject_id'] . '</td><td>' . htmlspecialchars($c['subject_code']) . '</td><td>' . htmlspecialchars($c['section']) . '</td><td>' . htmlspecialchars($c['status']) . '</td></tr>';
    }
    echo '</table></div>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Subjects - iAttendance</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
</head>
<body class="admin-page">
    <?php include 'sidebar.php'; ?>

    <main class="page-content">
        <!-- Topbar -->
        <div class="topbar">
            <button class="toggle-sidebar">
                <i class="bi bi-list"></i>
            </button>
            <div class="user-info dropdown">
                <a href="#" class="user-dropdown-toggle" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="user-avatar">AD</div>
                    <span class="user-name"><?php echo htmlspecialchars($admin['full_name'] ?? 'Administrator'); ?></span>
                </a>
                <ul class="dropdown-menu user-dropdown" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i> Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="../auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>
        <div class="container-fluid">
            <h1 class="h3 mb-4">Manage Subjects</h1>

            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show" id="successAlert">
                    <?php 
                        echo $_SESSION['success_message'];
                        unset($_SESSION['success_message']);
                    ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" id="errorAlert">
                    <?php 
                        echo $_SESSION['error_message'];
                        unset($_SESSION['error_message']);
                    ?>
                </div>
            <?php endif; ?>

            <!-- Action Buttons -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#addSubjectModal">
                            <i class="bi bi-plus-circle me-2"></i>Add New Subject
                        </button>
                        <button type="button" class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#assignSubjectModal">
                            <i class="bi bi-person-plus me-2"></i>Assign Subject to Teacher
                        </button>
                        <button type="button" class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#allSubjectsModal">
                            <i class="bi bi-list-ul me-2"></i>All Subjects
                        </button>
                        <button type="button" class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#manageSemesterModal">
                            <i class="bi bi-calendar3 me-2"></i>Manage Semester
                        </button>
                    </div>
                </div>
            </div>

            <!-- Current Subject Assignments -->
                    <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Current Subject Assignments</h5>
                    <?php if ($current_semester): ?>
                    <div class="current-semester-info">
                        <span class="badge bg-secondary">
                            <?php echo htmlspecialchars($current_semester['semester']); ?>
                        </span>
                        <span class="badge bg-light text-dark border ms-2">
                            <?php echo date('M d, Y', strtotime($current_semester['start_date'])); ?> - 
                            <?php echo date('M d, Y', strtotime($current_semester['end_date'])); ?>
                        </span>
                        <div class="small text-muted mt-1">
                            Prelim: <?php echo date('M d', strtotime($current_semester['prelim_start'])); ?> - 
                            <?php echo date('M d', strtotime($current_semester['prelim_end'])); ?>
                            | Midterm: <?php echo date('M d', strtotime($current_semester['midterm_start'])); ?> - 
                            <?php echo date('M d', strtotime($current_semester['midterm_end'])); ?>
                            | Final: <?php echo date('M d', strtotime($current_semester['final_start'])); ?> - 
                            <?php echo date('M d', strtotime($current_semester['final_end'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                        </div>
                        <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Teacher ID</th>
                                    <th>Teacher</th>
                                    <th>Subject</th>
                                    <th>Section</th>
                                    <th>Year Level</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments as $assignment): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($assignment['formatted_teacher_id']); ?></td>
                                    <td><?php 
$fullName = trim($assignment['teacher_name'] ?? '');
$displayName = $fullName;
if ($fullName) {
    $parts = preg_split('/\s+/', $fullName);
    if (count($parts) >= 2) {
        $first = $parts[0];
        $last = $parts[count($parts) - 1];
        $middleParts = array_slice($parts, 1, -1);
        $middleDisplay = '';
        $middleInitial = '';
        if (count($middleParts) > 1) {
            $middleDisplay = implode(' ', array_slice($middleParts, 0, -1));
            $middleInitial = strtoupper($middleParts[count($middleParts)-1][0]) . '.';
        } elseif (count($middleParts) == 1) {
            $middleInitial = strtoupper($middleParts[0][0]) . '.';
        }
        $displayName = $last;
        if ($first) $displayName .= ', ' . $first;
        if ($middleDisplay) $displayName .= ' ' . $middleDisplay;
        if ($middleInitial) $displayName .= ' ' . $middleInitial;
    }
}
                                        echo htmlspecialchars($displayName);
                                    ?></td>
                                    <td><?php echo htmlspecialchars($assignment['subject_code'] . ' - ' . $assignment['subject_name']); ?></td>
                                    <td><?php echo htmlspecialchars($assignment['section_name']); ?></td>
                                    <td><?php echo htmlspecialchars($assignment['year_level']); ?></td>
                                    <td>
                                        <form action="manage_subjects.php" method="post" class="d-inline">
                                            <input type="hidden" name="action" value="remove_assignment">
                                            <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to remove this assignment?')">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                        </div>
                    </div>
                </div>

        <!-- Assign Subject Modal -->
        <div class="modal fade" id="assignSubjectModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-person-plus"></i> Assign Subject to Teacher</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                            <form action="manage_subjects.php" method="post">
                                <input type="hidden" name="action" value="assign_subject">
                                <div class="mb-3">
                                    <label for="assign_year_level" class="form-label">Year Level</label>
                                    <select class="form-select" id="assign_year_level" name="year_level" required>
                                        <option value="">Select Year Level</option>
                                        <?php foreach (array_keys($sections_by_year) as $year): ?>
                                            <option value="<?php echo $year; ?>"><?php echo $year; ?><?php echo ($year == 1 ? 'st' : ($year == 2 ? 'nd' : ($year == 3 ? 'rd' : 'th'))); ?> Year</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="assign_section_id" class="form-label">Section</label>
                                    <select class="form-select" id="assign_section_id" name="section_id" required>
                                        <option value="">Select Section</option>
                                        <!-- No options here, JS will populate -->
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Subject</label>
                                    <div id="assign_subject_buttons" class="d-flex flex-wrap justify-content-center gap-2 subject-btns-2row" style="max-width: 520px; margin: 0 auto;">
                                        <?php foreach ($subjects as $subject): ?>
                                            <span class="subject-btn-wrapper">
                                            <button type="button" class="btn btn-outline-primary subject-btn px-3 py-2" data-year="<?php echo $subject['year_level']; ?>" data-id="<?php echo $subject['id']; ?>" style="display:none; width:110px; white-space:nowrap; text-align:center; font-weight:600; font-size:1rem;">
                                                <?php echo htmlspecialchars($subject['subject_code']); ?>
                                            </button>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                    <div id="selected_subjects_inputs"></div>
                                </div>
                                <div class="mb-3">
                                    <label for="assign_teacher_id" class="form-label">Teacher</label>
                                    <select class="form-select" id="assign_teacher_id" name="teacher_id" required>
                                        <option value="">Select Teacher</option>
                                        <?php foreach ($teachers as $teacher): ?>
                                            <option value="<?php echo $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['full_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <input type="hidden" id="semester" name="semester">
                                <input type="hidden" id="term_period" name="term_period">
                                <input type="hidden" id="start_date" name="start_date">
                                <input type="hidden" id="end_date" name="end_date">
                                <div class="text-end">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-secondary">Assign Subject</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <style>
        /* Modal UI Enhancements */
        #assignSubjectModal .modal-content {
            border-radius: 18px;
            box-shadow: 0 8px 32px rgba(44,62,80,0.13);
            border: none;
            background: #fff;
        }
        #assignSubjectModal .modal-header {
            border-bottom: none;
            padding-bottom: 0.5rem;
        }
        #assignSubjectModal .modal-title {
            font-size: 1.45rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #6c757d;
        }
        #assignSubjectModal .modal-title i {
            color: #adb5bd;
            font-size: 1.5rem;
        }
        #assignSubjectModal .modal-body {
            background: #f1f5f9;
            border-radius: 0 0 18px 18px;
            padding: 2rem 1.5rem 1.5rem 1.5rem;
        }
        #assignSubjectModal .form-label {
            font-size: 1.08rem;
            font-weight: 600;
            color: #6c757d;
            margin-bottom: 0.25rem;
        }
        #assignSubjectModal .form-select, #assignSubjectModal input[type="text"], #assignSubjectModal input[type="number"] {
            border-radius: 0.7rem;
            padding: 0.6rem 1rem;
            font-size: 1.05rem;
            margin-bottom: 0.5rem;
            border: 1.5px solid #adb5bd;
            background: #fff;
            color: #1e293b;
            transition: border-color 0.18s, box-shadow 0.18s;
        }
        #assignSubjectModal .form-select:focus, #assignSubjectModal input:focus {
            border-color: #6c757d;
            box-shadow: 0 0 0 2px #adb5bd33;
            outline: none;
        }
        #assignSubjectModal .mb-3 {
            margin-bottom: 1.15rem !important;
            padding-bottom: 0.7rem;
            border-bottom: 1px solid #e3e8ef;
        }
        #assignSubjectModal .mb-3:last-child {
            border-bottom: none;
        }
        #assign_subject_buttons {
            background: #fff;
            border-radius: 1.2rem;
            box-shadow: 0 2px 8px rgba(44,62,80,0.06);
            padding: 1.1rem 0.5rem 0.7rem 0.5rem;
            margin-bottom: 0.5rem;
        }
        #assign_subject_buttons .subject-btn {
            border-radius: 2rem;
            font-size: 1rem;
            margin-bottom: 8px;
            transition: background-color 0.18s, color 0.18s, border-color 0.18s;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            padding-left: 18px !important;
            padding-right: 18px !important;
            min-width: 80px !important;
            max-width: 120px !important;
            white-space: nowrap;
            text-align: center;
            font-weight: 600;
            color: #6c757d !important;
            background: #e0e7ef !important;
            border: 1.5px solid #adb5bd !important;
        }
        #assign_subject_buttons .subject-btn.selected, #assign_subject_buttons .subject-btn.selected:focus {
            background: #6c757d !important;
            color: #fff !important;
            border-color: #6c757d !important;
        }
        #assign_subject_buttons .subject-btn:hover:not(.selected) {
            background: #adb5bd !important;
            color: #fff !important;
            border-color: #6c757d !important;
        }
        #assign_subject_buttons .subject-btn.disabled {
            pointer-events: none;
            opacity: 0.5;
            background: #e9ecef !important;
            color: #adb5bd !important;
            border-color: #adb5bd !important;
            cursor: not-allowed;
            position: relative;
            text-decoration: line-through;
        }
        #assign_subject_buttons .subject-btn.disabled::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.5);
            border-radius: 2rem;
            z-index: 1;
        }
        #assign_subject_buttons .subject-btn-wrapper[data-bs-toggle="tooltip"] {
            position: relative;
        }
        #assign_subject_buttons .subject-btn-wrapper[data-bs-toggle="tooltip"]::before {
            content: '!';
            position: absolute;
            top: -5px;
            right: -5px;
            width: 18px;
            height: 18px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: bold;
            z-index: 2;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        #assign_subject_buttons .subject-btn-wrapper[data-bs-toggle="tooltip"] .subject-btn {
            border: 2px solid #dc3545 !important;
        }
        @media (max-width: 600px) {
            #assignSubjectModal .modal-body {
                padding: 1.1rem 0.5rem 1rem 0.5rem;
            }
            #assign_subject_buttons {
                padding: 0.7rem 0.2rem 0.5rem 0.2rem;
            }
        }
        </style>

        <!-- All Subjects Modal -->
        <div class="modal fade" id="allSubjectsModal" tabindex="-1">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">All Subjects</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                    <div class="modal-body" style="padding: 10px 10px; max-height: 75vh; overflow-y: auto;">
                        <div class="row mb-3 align-items-center g-2" style="background: #f8f9fa; border-radius: 8px; padding: 12px 8px 8px 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.03); border: 1px solid #e3e6f0;">
                            <div class="col-auto">
                                <div class="input-group input-group-sm rounded-pill overflow-hidden filter-pill">
                                    <span class="input-group-text bg-white border-0 pe-1 filter-icon"><i class="bi bi-funnel-fill"></i></span>
                                    <label for="filterYearLevel" class="visually-hidden">Year Level</label>
                                    <select id="filterYearLevel" class="form-select border-0 bg-white" style="min-width: 110px;">
                                        <option value="">Year Level</option>
                                        <option value="1">1st Year</option>
                                        <option value="2">2nd Year</option>
                                        <option value="3">3rd Year</option>
                                        <option value="4">4th Year</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-auto">
                                <div class="input-group input-group-sm rounded-pill overflow-hidden filter-pill">
                                    <span class="input-group-text bg-white border-0 pe-1 filter-icon"><i class="bi bi-funnel-fill"></i></span>
                                    <label for="filterSemester" class="visually-hidden">Semester</label>
                                    <select id="filterSemester" class="form-select border-0 bg-white" style="min-width: 120px;">
                                        <option value="">Semester</option>
                                        <option value="1st Semester">1st Semester</option>
                                        <option value="2nd Semester">2nd Semester</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-auto ms-auto">
                                <button type="button" class="btn btn-danger btn-sm rounded-pill px-3" id="deleteAllSubjectsBtn"><i class="bi bi-trash"></i> Delete All</button>
                            </div>
                        </div>
                    <div class="table-responsive">
                        <form id="deleteAllSubjectsForm" action="manage_subjects.php" method="post" style="display:none;">
                            <input type="hidden" name="action" value="delete_all_subjects">
                        </form>
                            <table class="table table-striped table-sm align-middle" id="allSubjectsTable">
                            <thead>
                                <tr>
                                        <th>Subject Code</th>
                                        <th>Subject Name</th>
                                        <th>Description</th>
                                        <th>Units</th>
                                    <th>Year Level</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                    <?php foreach ($subjects as $subject): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($subject['subject_code']); ?></td>
                                        <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                        <td><?php echo htmlspecialchars($subject['description']); ?></td>
                                        <td><?php echo htmlspecialchars($subject['units']); ?></td>
                                        <td class="subject-year-level"><?php echo htmlspecialchars($subject['year_level']); ?>st Year</td>
                                        <td>
                                            <form action="manage_subjects.php" method="post" class="d-inline">
                                                <input type="hidden" name="action" value="delete_subject">
                                                <input type="hidden" name="subject_id" value="<?php echo $subject['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this subject?')">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Manage Semester Modal -->
        <div class="modal fade" id="manageSemesterModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-calendar3"></i> Manage Semester Settings</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form id="semesterForm" action="manage_subjects.php" method="post">
                            <input type="hidden" name="action" value="set_semester">
                            <div class="mb-3">
                                <label for="semester_setting" class="form-label">Semester</label>
                                <select class="form-select" id="semester_setting" name="semester" required>
                                    <option value="">Select Semester</option>
                                    <option value="1st Semester" <?php echo ($current_semester && $current_semester['semester'] == '1st Semester') ? 'selected' : ''; ?>>1st Semester</option>
                                    <option value="2nd Semester" <?php echo ($current_semester && $current_semester['semester'] == '2nd Semester') ? 'selected' : ''; ?>>2nd Semester</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="start_date_setting" class="form-label">Start Date</label>
                                <input type="date" class="form-control" id="start_date_setting" name="start_date" 
                                    value="<?php echo $current_semester ? $current_semester['start_date'] : ''; ?>"
                                    onchange="validateDates()">
                            </div>
                            <div class="mb-3">
                                <label for="end_date_setting" class="form-label">End Date</label>
                                <input type="date" class="form-control" id="end_date_setting" name="end_date" 
                                    value="<?php echo $current_semester ? $current_semester['end_date'] : ''; ?>"
                                    onchange="validateDates()">
                                <div class="invalid-feedback" id="date-error"></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Prelim Date Range</label>
                                <div class="input-group">
                                    <input type="date" class="form-control" name="prelim_start" required
                                        min="<?php echo $current_semester ? date('Y-m-d', strtotime($current_semester['start_date'])) : ''; ?>"
                                        max="<?php echo $current_semester ? date('Y-m-d', strtotime($current_semester['end_date'])) : ''; ?>"
                                        value="<?php echo $current_semester && $current_semester['prelim_start'] ? date('Y-m-d', strtotime($current_semester['prelim_start'])) : ''; ?>">
                                    <span class="input-group-text">to</span>
                                    <input type="date" class="form-control" name="prelim_end" required
                                        min="<?php echo $current_semester ? date('Y-m-d', strtotime($current_semester['start_date'])) : ''; ?>"
                                        max="<?php echo $current_semester ? date('Y-m-d', strtotime($current_semester['end_date'])) : ''; ?>"
                                        value="<?php echo $current_semester && $current_semester['prelim_end'] ? date('Y-m-d', strtotime($current_semester['prelim_end'])) : ''; ?>">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Midterm Date Range</label>
                                <div class="input-group">
                                    <input type="date" class="form-control" name="midterm_start" required
                                        min="<?php echo $current_semester ? date('Y-m-d', strtotime($current_semester['start_date'])) : ''; ?>"
                                        max="<?php echo $current_semester ? date('Y-m-d', strtotime($current_semester['end_date'])) : ''; ?>"
                                        value="<?php echo $current_semester && $current_semester['midterm_start'] ? date('Y-m-d', strtotime($current_semester['midterm_start'])) : ''; ?>">
                                    <span class="input-group-text">to</span>
                                    <input type="date" class="form-control" name="midterm_end" required
                                        min="<?php echo $current_semester ? date('Y-m-d', strtotime($current_semester['start_date'])) : ''; ?>"
                                        max="<?php echo $current_semester ? date('Y-m-d', strtotime($current_semester['end_date'])) : ''; ?>"
                                        value="<?php echo $current_semester && $current_semester['midterm_end'] ? date('Y-m-d', strtotime($current_semester['midterm_end'])) : ''; ?>">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Final Date Range</label>
                                <div class="input-group">
                                    <input type="date" class="form-control" name="final_start" required
                                        min="<?php echo $current_semester ? date('Y-m-d', strtotime($current_semester['start_date'])) : ''; ?>"
                                        max="<?php echo $current_semester ? date('Y-m-d', strtotime($current_semester['end_date'])) : ''; ?>"
                                        value="<?php echo $current_semester && $current_semester['final_start'] ? date('Y-m-d', strtotime($current_semester['final_start'])) : ''; ?>">
                                    <span class="input-group-text">to</span>
                                    <input type="date" class="form-control" name="final_end" required
                                        min="<?php echo $current_semester ? date('Y-m-d', strtotime($current_semester['start_date'])) : ''; ?>"
                                        max="<?php echo $current_semester ? date('Y-m-d', strtotime($current_semester['end_date'])) : ''; ?>"
                                        value="<?php echo $current_semester && $current_semester['final_end'] ? date('Y-m-d', strtotime($current_semester['final_end'])) : ''; ?>">
                                </div>
                            </div>
                            <!-- Add Preset Templates and Copy Previous Semester -->
                            <div class="mb-3 d-flex gap-2 align-items-center">
                                <label class="form-label mb-0">Preset:</label>
                                <select id="semesterTemplate" class="form-select form-select-sm" style="width:auto;">
                                    <option value="">Select Template</option>
                                    <option value="standard">Standard Semester (16 weeks)</option>
                                    <option value="summer">Short Summer Term (8 weeks)</option>
                                </select>
                                <!-- Removed Copy from Previous Semester and Reset to Default buttons -->
                            </div>
                            <div class="d-flex gap-3 justify-content-center align-items-center mt-4" id="manageSemesterBtnRow">
                                <button type="button" class="btn btn-danger" id="resetAssignmentsBtn">Reset</button>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-secondary" id="saveSemesterBtn">Save Settings</button>
                            </div>
                        </form>
                        <form id="resetAssignmentsForm" action="manage_subjects.php" method="post" style="display:none;">
                            <input type="hidden" name="action" value="reset_assignments">
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Subject Modal -->
        <div class="modal fade" id="addSubjectModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Add New Subject</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                            <form action="manage_subjects.php" method="post">
                                <input type="hidden" name="action" value="add_subject">
                            <div class="mb-3">
                                <label for="subject_code" class="form-label">Subject Code</label>
                                <input type="text" class="form-control" id="subject_code" name="subject_code" required>
                            </div>
                                <div class="mb-3">
                                    <label for="subject_name" class="form-label">Subject Name</label>
                                    <input type="text" class="form-control" id="subject_name" name="subject_name" required>
                                </div>
                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="units" class="form-label">Units</label>
                                <input type="number" class="form-control" id="units" name="units" min="1" max="6" required>
                                </div>
                                <div class="mb-3">
                                    <label for="year_level" class="form-label">Year Level</label>
                                    <select class="form-select" id="year_level" name="year_level" required>
                                        <option value="">Select Year Level</option>
                                    <option value="1">1st Year</option>
                                    <option value="2">2nd Year</option>
                                    <option value="3">3rd Year</option>
                                    <option value="4">4th Year</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="semester" class="form-label">Semester</label>
                                    <select class="form-select" id="semester" name="semester" required>
                                        <option value="">Select Semester</option>
                                        <option value="1st Semester">1st Semester</option>
                                        <option value="2nd Semester">2nd Semester</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="term_period" class="form-label">Term Period</label>
                                    <select class="form-select" id="term_period" name="term_period" required>
                                        <option value="">Select Term Period</option>
                                        <option value="Prelim">Prelim</option>
                                        <option value="Midterm">Midterm</option>
                                        <option value="Final">Final</option>
                                    </select>
                                </div>
                            <div class="text-end">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-secondary">Add Subject</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const templates = {};
        // Auto hide alerts after 3 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const successAlert = document.getElementById('successAlert');
            const errorAlert = document.getElementById('errorAlert');
            
            if (successAlert) {
                setTimeout(function() {
                    successAlert.classList.remove('show');
                    setTimeout(function() {
                        successAlert.remove();
                    }, 150);
                }, 3000);
            }
            
            if (errorAlert) {
                setTimeout(function() {
                    errorAlert.classList.remove('show');
                    setTimeout(function() {
                        errorAlert.remove();
                    }, 150);
                }, 3000);
            }
        });

        // Global variables for section management
        let yearLevelSelect, sectionSelect, sectionsByYear;
        
        // Update sections based on subject's year level
        document.addEventListener('DOMContentLoaded', function() {
            yearLevelSelect = document.getElementById('assign_year_level');
            const subjectButtonsDiv = document.getElementById('assign_subject_buttons');
            const selectedSubjectsInputsDiv = document.getElementById('selected_subjects_inputs');
            sectionSelect = document.getElementById('assign_section_id');
            const subjects = <?php echo json_encode($subjects); ?>;
            sectionsByYear = <?php echo json_encode($sections_by_year); ?>;
            const assignments = <?php echo json_encode($assignments); ?>;
            const currentSemester = <?php echo json_encode($current_semester ? $current_semester['semester'] : null); ?>;

            function filterSubjects() {
                const year = yearLevelSelect.value;
                const semester = currentSemester;
                const sectionId = sectionSelect.value;
                const sectionName = sectionSelect.options[sectionSelect.selectedIndex] ? sectionSelect.options[sectionSelect.selectedIndex].text : '';
                // Hide subject buttons container by default
                subjectButtonsDiv.style.display = 'none';
                if (year && sectionId) {
                    subjectButtonsDiv.style.display = '';
                    Array.from(subjectButtonsDiv.querySelectorAll('.subject-btn')).forEach(btn => {
                        const wrapper = btn.parentElement;
                        const btnYear = btn.getAttribute('data-year');
                        const btnSubjectId = btn.getAttribute('data-id');
                        const btnSubjectCode = btn.textContent.trim();
                        let assigned = false;
                        let enableBtn = false;
                        let assignedTeacher = '';
                        
                        // Show subject if year matches (semester is handled by current semester)
                        if (btnYear === year) {
                            btn.style.display = '';
                            
                            // Check if assigned and get teacher name
                            const assignment = assignments.find(a => {
                                return a.section_id == sectionId &&
                                       a.semester == semester &&
                                       a.subject_code == btnSubjectCode &&
                                       String(a.year_level) === String(year); // strict match year level
                            });
                            
                            assigned = !!assignment;
                            if (assigned) {
                                assignedTeacher = assignment.teacher_name || 'Already assigned';
                                console.log('Subject assigned:', btnSubjectCode, 'to:', assignedTeacher);
                            }
                            
                            enableBtn = !assigned;
                            if (!enableBtn) {
                                btn.disabled = true;
                                btn.classList.add('disabled');
                                btn.classList.remove('selected');
                                // Remove from selected subjects if it was selected
                                const selectedInput = document.querySelector(`input[name="subject_ids[]"][value="${btnSubjectId}"]`);
                                if (selectedInput) {
                                    selectedInput.remove();
                                }
                            } else {
                                btn.disabled = false;
                                btn.classList.remove('disabled');
                            }
                            
                            // Tooltip for assigned
                            if (assigned) {
                                wrapper.setAttribute('title', 'Assigned to: ' + assignedTeacher);
                                wrapper.setAttribute('data-bs-toggle', 'tooltip');
                            } else {
                                wrapper.removeAttribute('title');
                                wrapper.removeAttribute('data-bs-toggle');
                            }
                        } else {
                            btn.style.display = 'none';
                            btn.classList.remove('selected');
                            btn.classList.remove('disabled');
                            btn.disabled = false;
                            wrapper.removeAttribute('title');
                            wrapper.removeAttribute('data-bs-toggle');
                        }
                    });
                    
                    // Re-initialize Bootstrap tooltips for .subject-btn-wrapper
                    var tooltipTriggerList = [].slice.call(subjectButtonsDiv.querySelectorAll('.subject-btn-wrapper[data-bs-toggle="tooltip"]'));
                    tooltipTriggerList.forEach(function (tooltipTriggerEl) {
                        new bootstrap.Tooltip(tooltipTriggerEl);
                    });
                } else {
                    // Hide all subject buttons if not both year and section are selected
                    Array.from(subjectButtonsDiv.querySelectorAll('.subject-btn')).forEach(btn => {
                        const wrapper = btn.parentElement;
                        btn.style.display = 'none';
                        btn.classList.remove('selected');
                        btn.classList.remove('disabled');
                        btn.disabled = false;
                        wrapper.removeAttribute('title');
                        wrapper.removeAttribute('data-bs-toggle');
                    });
                }
                updateSelectedSubjectsInputs();
            }

            function updateSelectedSubjectsInputs() {
                // Remove all previous hidden inputs
                selectedSubjectsInputsDiv.innerHTML = '';
                // Add a hidden input for each selected subject
                Array.from(subjectButtonsDiv.querySelectorAll('.subject-btn.selected')).forEach(btn => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'subject_ids[]';
                    input.value = btn.getAttribute('data-id');
                    selectedSubjectsInputsDiv.appendChild(input);
                });
            }

            function updateSections() {
                const year = yearLevelSelect.value;
                const prevValue = sectionSelect.value; // Store previous selection
                sectionSelect.innerHTML = '<option value="">Select Section</option>';
                let found = false;
                
                if (year && sectionsByYear[year]) {
                    sectionsByYear[year].forEach(section => {
                        const option = document.createElement('option');
                        option.value = section.id;
                        option.textContent = section.name;
                        if (section.id == prevValue) {
                            option.selected = true;
                            found = true;
                        }
                        sectionSelect.appendChild(option);
                    });
                }
                
                if (!found) {
                    sectionSelect.selectedIndex = 0;
                }
            }

            // Only call updateSections and filterSubjects on year change
            if (yearLevelSelect && subjectButtonsDiv && sectionSelect) {
                if (yearLevelSelect) {
                    yearLevelSelect.addEventListener('change', function() {
                        updateSections();
                        filterSubjects();
                    });
                }
                if (sectionSelect) {
                    sectionSelect.addEventListener('change', filterSubjects);
                }
                if (subjectButtonsDiv) {
                    subjectButtonsDiv.addEventListener('click', function(e) {
                        if (
                            e.target.classList.contains('subject-btn') &&
                            !e.target.disabled &&
                            !e.target.classList.contains('disabled')
                        ) {
                            e.target.classList.toggle('selected');
                            updateSelectedSubjectsInputs();
                        }
                    });
                }
            }
            // Do NOT call updateSections() or filterSubjects() on page load
        });

        // Auto-fill semester details from current settings when assigning subject
        <?php if ($current_semester): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const assignModal = document.getElementById('assignSubjectModal');
            assignModal.addEventListener('show.bs.modal', function() {
                const semesterSelect = document.getElementById('semester');
                const termPeriodSelect = document.getElementById('term_period');
                const startDateInput = document.getElementById('start_date');
                const endDateInput = document.getElementById('end_date');

                semesterSelect.value = <?php echo json_encode($current_semester ? $current_semester['semester'] : ''); ?>;
                if (termPeriodSelect) termPeriodSelect.value = '';
                startDateInput.value = <?php echo json_encode($current_semester ? $current_semester['start_date'] : ''); ?>;
                endDateInput.value = <?php echo json_encode($current_semester ? $current_semester['end_date'] : ''); ?>;
                
                // Reset form fields when modal opens
                const yearLevelSelect = document.getElementById('assign_year_level');
                const sectionSelect = document.getElementById('assign_section_id');
                const teacherSelect = document.getElementById('assign_teacher_id');
                
                if (yearLevelSelect) {
                    yearLevelSelect.selectedIndex = 0;
                }
                if (sectionSelect) {
                    sectionSelect.innerHTML = '<option value="">Select Section</option>';
                }
                if (teacherSelect) teacherSelect.selectedIndex = 0;
                
                // Clear selected subjects
                const subjectButtons = document.querySelectorAll('#assign_subject_buttons .subject-btn');
                subjectButtons.forEach(btn => {
                    btn.classList.remove('selected');
                    btn.style.display = 'none';
                });
                
                // Clear selected subjects inputs
                const selectedSubjectsInputs = document.getElementById('selected_subjects_inputs');
                if (selectedSubjectsInputs) selectedSubjectsInputs.innerHTML = '';
            });
        });
        <?php endif; ?>

        // Date validation function
        function validateDates() {
            const startDate = document.getElementById('start_date_setting').value;
            const endDate = document.getElementById('end_date_setting').value;
            const saveButton = document.getElementById('saveSemesterBtn');
            const dateError = document.getElementById('date-error');
            
            if (startDate && endDate) {
                if (new Date(endDate) < new Date(startDate)) {
                    dateError.textContent = 'End date cannot be earlier than start date';
                    document.getElementById('end_date_setting').classList.add('is-invalid');
                    saveButton.disabled = true;
                } else {
                    dateError.textContent = '';
                    document.getElementById('end_date_setting').classList.remove('is-invalid');
                    saveButton.disabled = false;
                }
            }
        }

        // Add form submit validation
        document.getElementById('semesterForm').addEventListener('submit', function(e) {
            const startDate = document.getElementById('start_date_setting').value;
            const endDate = document.getElementById('end_date_setting').value;
            
            if (startDate && endDate && new Date(endDate) < new Date(startDate)) {
                e.preventDefault();
                document.getElementById('date-error').textContent = 'End date cannot be earlier than start date';
                document.getElementById('end_date_setting').classList.add('is-invalid');
            }
        });

        document.getElementById('resetAssignmentsBtn').onclick = function() {
            if (confirm('Are you sure you want to delete ALL subject assignments? This cannot be undone.')) {
                document.getElementById('resetAssignmentsForm').submit();
            }
        };

        document.getElementById('deleteAllSubjectsBtn').onclick = function() {
            if (confirm('Are you sure you want to delete ALL subjects and their related data? This cannot be undone.')) {
                document.getElementById('deleteAllSubjectsForm').submit();
            }
        };

        document.addEventListener('DOMContentLoaded', function() {
            const yearFilter = document.getElementById('filterYearLevel');
            const semesterFilter = document.getElementById('filterSemester');
            const table = document.getElementById('allSubjectsTable');
            function filterTable() {
                const year = yearFilter.value;
                const semester = semesterFilter.value;
                Array.from(table.tBodies[0].rows).forEach(row => {
                    const yearCell = row.querySelector('.subject-year-level')?.textContent || '';
                    const semesterCell = row.querySelector('.subject-semester')?.textContent || '';
                    let show = true;
                    if (year && !yearCell.startsWith(year)) show = false;
                    if (semester && semesterCell !== semester) show = false;
                    row.style.display = show ? '' : 'none';
                });
            }
            yearFilter.addEventListener('change', filterTable);
            semesterFilter.addEventListener('change', filterTable);
        });

        // Restore Manage Semester modal fields on close
        var manageSemesterModal = document.getElementById('manageSemesterModal');
        var semesterForm = document.getElementById('semesterForm');
        if (manageSemesterModal && semesterForm) {
            var originalValues = {};
            manageSemesterModal.addEventListener('show.bs.modal', function() {
                originalValues = {
                    semester: semesterForm.semester.value,
                    start_date: semesterForm.start_date.value,
                    end_date: semesterForm.end_date.value,
                    prelim_start: semesterForm.prelim_start.value,
                    prelim_end: semesterForm.prelim_end.value,
                    midterm_start: semesterForm.midterm_start.value,
                    midterm_end: semesterForm.midterm_end.value,
                    final_start: semesterForm.final_start.value,
                    final_end: semesterForm.final_end.value
                };
            });
            manageSemesterModal.addEventListener('hidden.bs.modal', function() {
                semesterForm.semester.value = originalValues.semester;
                semesterForm.start_date.value = originalValues.start_date;
                semesterForm.end_date.value = originalValues.end_date;
                semesterForm.prelim_start.value = originalValues.prelim_start;
                semesterForm.prelim_end.value = originalValues.prelim_end;
                semesterForm.midterm_start.value = originalValues.midterm_start;
                semesterForm.midterm_end.value = originalValues.midterm_end;
                semesterForm.final_start.value = originalValues.final_start;
                semesterForm.final_end.value = originalValues.final_end;
            });
        }

        function daysBetween(start, end) {
            return Math.round((new Date(end) - new Date(start)) / (1000 * 60 * 60 * 24)) + 1;
        }
        function autoCalculateTerms() {
            const start = document.getElementById('start_date_setting').value;
            const end = document.getElementById('end_date_setting').value;
            if (!start || !end) return;
            const startDate = new Date(start);
            const endDate = new Date(end);
            const totalDays = daysBetween(start, end);
            if (totalDays < 3) return;
            // Divide into 3 terms
            const prelimEnd = new Date(startDate.getTime() + Math.floor(totalDays/3) * 24*60*60*1000);
            const midtermEnd = new Date(startDate.getTime() + Math.floor(2*totalDays/3) * 24*60*60*1000);
            document.querySelector('input[name="prelim_start"]').value = formatDateLocal(startDate);
            document.querySelector('input[name="prelim_end"]').value = formatDateLocal(prelimEnd);
            document.querySelector('input[name="midterm_start"]').value = formatDateLocal(prelimEnd);
            document.querySelector('input[name="midterm_end"]').value = formatDateLocal(midtermEnd);
            document.querySelector('input[name="final_start"]').value = formatDateLocal(midtermEnd);
            document.querySelector('input[name="final_end"]').value = formatDateLocal(endDate);
            updateSemesterSummary();
            console.log('DEBUG: Start date input at end of autoCalculateTerms:', document.getElementById('start_date_setting').value);
        }
        function updateSemesterSummary() {
            document.getElementById('semesterSummary').innerHTML = '';
        }
        function validateTermRanges() {
            const start = document.getElementById('start_date_setting').value;
            const end = document.getElementById('end_date_setting').value;
            const prelimStart = document.querySelector('input[name="prelim_start"]').value;
            const prelimEnd = document.querySelector('input[name="prelim_end"]').value;
            const midtermStart = document.querySelector('input[name="midterm_start"]').value;
            const midtermEnd = document.querySelector('input[name="midterm_end"]').value;
            const finalStart = document.querySelector('input[name="final_start"]').value;
            const finalEnd = document.querySelector('input[name="final_end"]').value;
            let error = '';
            if (start && end) {
                if (prelimStart < start || prelimEnd > end || midtermStart < start || midtermEnd > end || finalStart < start || finalEnd > end) {
                    error = 'All term dates must be within the semester range.';
                } else if (!(prelimStart <= prelimEnd && midtermStart <= midtermEnd && finalStart <= finalEnd)) {
                    error = 'Each term start must be before its end.';
                } else if (!(prelimEnd <= midtermStart && midtermEnd <= finalStart)) {
                    error = 'Terms must not overlap.';
                }
            }
            document.getElementById('date-error').textContent = error;
            document.getElementById('saveSemesterBtn').disabled = !!error;
        }
        // --- SEMESTER DATE HELPERS ---
        function getFirstMonday(month, year) {
            let d = new Date(year, month, 1);
            while (d.getDay() !== 1) { // 1 = Monday
                d.setDate(d.getDate() + 1);
            }
            return d;
        }
        function getSecondMonday(month, year) {
            let firstMonday = getFirstMonday(month, year);
            let d = new Date(firstMonday);
            d.setDate(d.getDate() + 7);
            return d;
        }
        function getLastMonday(month, year) {
            let d = new Date(year, month + 1, 0); // last day of month
            while (d.getDay() !== 1) { // 1 = Monday
                d.setDate(d.getDate() - 1);
            }
            return d;
        }
        // --- DATE FORMATTER (LOCAL YYYY-MM-DD) ---
        function formatDateLocal(date) {
            return date.getFullYear() + '-' +
                String(date.getMonth() + 1).padStart(2, '0') + '-' +
                String(date.getDate()).padStart(2, '0');
        }
        // --- PRESET LOGIC ---
        templates.standard = function() {
            const sem = document.getElementById('semester_setting').value;
            let now = new Date();
            let year = now.getFullYear();
            let start, end;
            if (sem === '1st Semester') {
                let augFirstMonday = getFirstMonday(7, year); // August = 7
                let dec20 = new Date(year, 11, 20); // December 20
                start = formatDateLocal(augFirstMonday);
                end = formatDateLocal(dec20);
            } else if (sem === '2nd Semester') {
                let nextYear = year + 1;
                let janSecondMonday = getSecondMonday(0, nextYear); // January = 0
                let mayLastMonday = getLastMonday(4, nextYear); // May = 4
                start = formatDateLocal(janSecondMonday);
                end = formatDateLocal(mayLastMonday);
            } else {
                let today = now;
                start = formatDateLocal(today);
                let endDate = new Date(today.getTime() + 112*24*60*60*1000);
                end = formatDateLocal(endDate);
            }
            document.getElementById('start_date_setting').value = start;
                document.getElementById('end_date_setting').value = end;
                autoCalculateTerms();
            updateTermDateLimits();
        };
        templates.summer = function() {
            let now = new Date();
            let start = formatDateLocal(now);
            let endDate = new Date(now.getTime() + 56*24*60*60*1000); // 8 weeks
            let end = formatDateLocal(endDate);
            document.getElementById('start_date_setting').value = start;
            document.getElementById('end_date_setting').value = end;
            autoCalculateTerms();
            updateTermDateLimits();
        };
        // --- COPY PREVIOUS SEMESTER LOGIC ---
        const prevSemester = <?php echo json_encode($prev_semester ?? null); ?>;
        const copyPrevBtn = document.getElementById('copyPrevSemesterBtn');
        if (copyPrevBtn) {
            copyPrevBtn.addEventListener('click', function() {
                if (!prevSemester) {
                    alert('No previous semester found.');
                    return;
                }
                document.getElementById('semester_setting').value = prevSemester.semester;
                document.getElementById('start_date_setting').value = prevSemester.start_date;
                document.getElementById('end_date_setting').value = prevSemester.end_date;
                document.querySelector('input[name="prelim_start"]').value = prevSemester.prelim_start;
                document.querySelector('input[name="prelim_end"]').value = prevSemester.prelim_end;
                document.querySelector('input[name="midterm_start"]').value = prevSemester.midterm_start;
                document.querySelector('input[name="midterm_end"]').value = prevSemester.midterm_end;
                document.querySelector('input[name="final_start"]').value = prevSemester.final_start;
                document.querySelector('input[name="final_end"]').value = prevSemester.final_end;
                updateSemesterSummary();
                validateTermRanges();
                updateTermDateLimits();
            });
        }
        // --- AUTO CALCULATE TERMS ---
        function autoCalculateTerms() {
            const start = document.getElementById('start_date_setting').value;
            const end = document.getElementById('end_date_setting').value;
            if (!start || !end) return;
            const startDate = new Date(start);
            const endDate = new Date(end);
            const totalDays = daysBetween(start, end);
            if (totalDays < 3) return;
            // Divide into 3 terms
            const prelimEnd = new Date(startDate.getTime() + Math.floor(totalDays/3) * 24*60*60*1000);
            const midtermEnd = new Date(startDate.getTime() + Math.floor(2*totalDays/3) * 24*60*60*1000);
            document.querySelector('input[name="prelim_start"]').value = formatDateLocal(startDate);
            document.querySelector('input[name="prelim_end"]').value = formatDateLocal(prelimEnd);
            document.querySelector('input[name="midterm_start"]').value = formatDateLocal(prelimEnd);
            document.querySelector('input[name="midterm_end"]').value = formatDateLocal(midtermEnd);
            document.querySelector('input[name="final_start"]').value = formatDateLocal(midtermEnd);
            document.querySelector('input[name="final_end"]').value = formatDateLocal(endDate);
            updateSemesterSummary();
            updateTermDateLimits();
        }
        const semesterTemplate = document.getElementById('semesterTemplate');
        if (semesterTemplate) {
            semesterTemplate.addEventListener('change', function() {
                if (templates[this.value]) templates[this.value]();
                updateSemesterSummary();
                validateTermRanges();
            });
        }
        const semesterSetting = document.getElementById('semester_setting');
        if (semesterSetting) {
            semesterSetting.addEventListener('change', function() {
                const preset = document.getElementById('semesterTemplate').value;
                if (templates[preset]) templates[preset]();
                updateSemesterSummary();
                validateTermRanges();
            });
        }
        const resetTermDatesBtn = document.getElementById('resetTermDatesBtn');
        if (resetTermDatesBtn) {
            resetTermDatesBtn.addEventListener('click', function() {
                autoCalculateTerms();
                updateSemesterSummary();
                validateTermRanges();
            });
        }
        const startDateSetting = document.getElementById('start_date_setting');
        if (startDateSetting) {
            startDateSetting.addEventListener('change', updateTermDateLimits);
        }
        const endDateSetting = document.getElementById('end_date_setting');
        if (endDateSetting) {
            endDateSetting.addEventListener('change', updateTermDateLimits);
        }
        document.addEventListener('DOMContentLoaded', updateTermDateLimits);

        // --- DYNAMIC TERM DATE LIMITS ---
        function updateTermDateLimits() {
            const start = document.getElementById('start_date_setting').value;
            const end = document.getElementById('end_date_setting').value;
            const prelimStart = document.querySelector('input[name="prelim_start"]').value;
            const prelimEnd = document.querySelector('input[name="prelim_end"]').value;
            const midtermStart = document.querySelector('input[name="midterm_start"]').value;
            const midtermEnd = document.querySelector('input[name="midterm_end"]').value;
            const finalStart = document.querySelector('input[name="final_start"]').value;
            const finalEnd = document.querySelector('input[name="final_end"]').value;

            // Update min/max dates for all inputs
            const termInputs = [
                'prelim_start', 'prelim_end',
                'midterm_start', 'midterm_end',
                'final_start', 'final_end'
            ];
            termInputs.forEach(name => {
                const input = document.querySelector('input[name="'+name+'"]');
                if (input) {
                    input.min = start || '';
                    input.max = end || '';
                }
            });

            // Handle prelim inputs
            const prelimStartInput = document.querySelector('input[name="prelim_start"]');
            const prelimEndInput = document.querySelector('input[name="prelim_end"]');
            if (prelimStartInput && prelimEndInput) {
                prelimEndInput.min = prelimStart || '';
                if (prelimStart && prelimEnd && prelimEnd < prelimStart) {
                    prelimEndInput.value = prelimStart;
                }
            }

            // Handle midterm inputs
            const midtermStartInput = document.querySelector('input[name="midterm_start"]');
            const midtermEndInput = document.querySelector('input[name="midterm_end"]');
            if (midtermStartInput && midtermEndInput) {
                // If prelim is set, midterm start must be after prelim end
                if (prelimEnd) {
                    midtermStartInput.min = prelimEnd;
                    midtermStartInput.disabled = false;
                } else {
                    midtermStartInput.disabled = true;
                    midtermStartInput.value = '';
                    midtermEndInput.value = '';
                }
                midtermEndInput.min = midtermStart || '';
                if (midtermStart && midtermEnd && midtermEnd < midtermStart) {
                    midtermEndInput.value = midtermStart;
                }
            }

            // Handle final inputs
            const finalStartInput = document.querySelector('input[name="final_start"]');
            const finalEndInput = document.querySelector('input[name="final_end"]');
            if (finalStartInput && finalEndInput) {
                // If midterm is set, final start must be after midterm end
                if (midtermEnd) {
                    finalStartInput.min = midtermEnd;
                    finalStartInput.disabled = false;
                } else {
                    finalStartInput.disabled = true;
                    finalStartInput.value = '';
                    finalEndInput.value = '';
                    }
                finalEndInput.min = finalStart || '';
                if (finalStart && finalEnd && finalEnd < finalStart) {
                    finalEndInput.value = finalStart;
                }
            }

            // Update semester summary
            updateSemesterSummary();
            validateTermRanges();
        }

        // Add event listeners for term date changes
        document.querySelector('input[name="prelim_start"]').addEventListener('change', updateTermDateLimits);
        document.querySelector('input[name="prelim_end"]').addEventListener('change', updateTermDateLimits);
        document.querySelector('input[name="midterm_start"]').addEventListener('change', updateTermDateLimits);
        document.querySelector('input[name="midterm_end"]').addEventListener('change', updateTermDateLimits);
        document.querySelector('input[name="final_start"]').addEventListener('change', updateTermDateLimits);
        document.querySelector('input[name="final_end"]').addEventListener('change', updateTermDateLimits);

        // Initialize term date limits on page load
        document.addEventListener('DOMContentLoaded', updateTermDateLimits);
    </script>
    <style>
    #manageSemesterModal .modal-content {
        border-radius: 18px;
        box-shadow: 0 8px 32px rgba(44,62,80,0.13);
        border: none;
        background: #fff;
    }
    #manageSemesterModal .modal-header {
        border-bottom: none;
        padding-bottom: 0.5rem;
    }
    #manageSemesterModal .modal-title {
        font-size: 1.35rem;
        font-weight: 700;
        letter-spacing: 0.01em;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: #6c757d;
    }
    #manageSemesterModal .modal-title i {
        color: #adb5bd;
        font-size: 1.4rem;
    }
    #manageSemesterModal .modal-body {
        background: #f1f5f9;
        border-radius: 0 0 18px 18px;
        padding: 2rem 1.5rem 1.5rem 1.5rem;
    }
    #manageSemesterModal .form-label {
        font-size: 1.08rem;
        font-weight: 600;
        color: #6c757d;
        margin-bottom: 0.25rem;
    }
    #manageSemesterModal .form-select, #manageSemesterModal input[type="text"], #manageSemesterModal input[type="number"], #manageSemesterModal input[type="date"] {
        border-radius: 0.7rem;
        padding: 0.6rem 1rem;
        font-size: 1.05rem;
        margin-bottom: 0.5rem;
        border: 1.5px solid #adb5bd;
        background: #fff;
        color: #1e293b;
        transition: border-color 0.18s, box-shadow 0.18s;
    }
    #manageSemesterModal .form-select:focus, #manageSemesterModal input:focus {
        border-color: #6c757d;
        box-shadow: 0 0 0 2px #adb5bd33;
        outline: none;
    }
    #manageSemesterModal .mb-3 {
        margin-bottom: 1.15rem !important;
        padding-bottom: 0.7rem;
        border-bottom: 1px solid #e3e8ef;
    }
    #manageSemesterModal .mb-3:last-child {
        border-bottom: none;
    }
    #manageSemesterModal .text-end, #manageSemesterModal .d-flex.justify-content-between.align-items-center > div:last-child {
        margin-top: 1.5rem;
        display: flex;
        gap: 0.7rem;
        justify-content: flex-end;
    }
    #manageSemesterModal .btn-primary {
        font-weight: 700;
        padding: 0.55rem 2.2rem;
        border-radius: 1.5rem;
        font-size: 1.08rem;
        background: #6c757d;
        border: none;
        color: #fff;
        transition: background 0.18s, box-shadow 0.18s;
    }
    #manageSemesterModal .btn-primary:hover {
        background: #495057;
        color: #fff;
    }
    #manageSemesterModal .btn-secondary {
        font-weight: 600;
        padding: 0.55rem 1.5rem;
        border-radius: 1.5rem;
        font-size: 1.08rem;
        background: #e0e7ef !important;
        color: #444 !important;
        border: none;
        transition: background 0.18s, color 0.18s;
    }
    #manageSemesterModal .btn-secondary:hover {
        background: #adb5bd !important;
        color: #222 !important;
    }
    #manageSemesterModal .btn-danger {
        font-weight: 600;
        padding: 0.55rem 1.5rem;
        border-radius: 1.5rem;
        font-size: 1.08rem;
        background: #f87171;
        color: #fff;
        border: none;
        transition: background 0.18s, color 0.18s;
    }
    #manageSemesterModal .btn-danger:hover {
        background: #dc2626;
        color: #fff;
    }
    @media (max-width: 600px) {
        #manageSemesterModal .modal-body {
            padding: 1.1rem 0.5rem 1rem 0.5rem;
        }
    }
    #manageSemesterBtnRow .btn {
        min-width: 130px;
        font-size: 1.08rem;
        font-weight: 600;
        border-radius: 1.5rem;
        padding: 0.55rem 0;
    }
    /* Remove box styling from the 'to' separator in date range input groups */
    #manageSemesterModal .input-group-text {
        background: none !important;
        border: none !important;
        box-shadow: none !important;
        padding: 0 0.5rem !important;
        color: #6c757d;
        font-weight: 600;
        font-size: 1.08rem;
        vertical-align: middle;
    }
    </style>
    <style>
    #addSubjectModal .modal-content {
        border-radius: 18px;
        box-shadow: 0 8px 32px rgba(44,62,80,0.13);
        border: none;
        background: #fff;
    }
    #addSubjectModal .modal-header {
        border-bottom: none;
        padding-bottom: 0.5rem;
    }
    #addSubjectModal .modal-title {
        font-size: 1.35rem;
        font-weight: 700;
        letter-spacing: 0.01em;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: #6c757d;
    }
    #addSubjectModal .modal-title i {
        color: #adb5bd;
        font-size: 1.4rem;
    }
    #addSubjectModal .modal-body {
        background: #f1f5f9;
        border-radius: 0 0 18px 18px;
        padding: 2rem 1.5rem 1.5rem 1.5rem;
    }
    #addSubjectModal .form-label {
        font-size: 1.08rem;
        font-weight: 600;
        color: #6c757d;
        margin-bottom: 0.25rem;
    }
    #addSubjectModal .form-select, #addSubjectModal input[type="text"], #addSubjectModal input[type="number"], #addSubjectModal textarea {
        border-radius: 0.7rem;
        padding: 0.6rem 1rem;
        font-size: 1.05rem;
        margin-bottom: 0.5rem;
        border: 1.5px solid #adb5bd;
        background: #fff;
        color: #1e293b;
        transition: border-color 0.18s, box-shadow 0.18s;
    }
    #addSubjectModal .form-select:focus, #addSubjectModal input:focus, #addSubjectModal textarea:focus {
        border-color: #6c757d;
        box-shadow: 0 0 0 2px #adb5bd33;
        outline: none;
    }
    #addSubjectModal .mb-3 {
        margin-bottom: 1.15rem !important;
        padding-bottom: 0.7rem;
        border-bottom: 1px solid #e3e8ef;
    }
    #addSubjectModal .mb-3:last-child {
        border-bottom: none;
    }
    #addSubjectModal .text-end {
        margin-top: 1.5rem;
        display: flex;
        gap: 0.7rem;
        justify-content: flex-end;
    }
    #addSubjectModal .btn-primary {
        font-weight: 700;
        padding: 0.55rem 2.2rem;
        border-radius: 1.5rem;
        font-size: 1.08rem;
        background: #6c757d;
        border: none;
        color: #fff;
        transition: background 0.18s, box-shadow 0.18s;
    }
    #addSubjectModal .btn-primary:hover {
        background: #495057;
        color: #fff;
    }
    #addSubjectModal .btn.btn-secondary, 
    #addSubjectModal .btn.btn-secondary * {
        font-weight: 600;
        padding: 0.55rem 1.5rem;
        border-radius: 1.5rem;
        font-size: 1.08rem;
        background: #e0e7ef !important;
        color: #444 !important;
        border: none;
        transition: background 0.18s, color 0.18s;
    }
    #addSubjectModal .btn.btn-secondary:hover, 
    #addSubjectModal .btn.btn-secondary:hover * {
        background: #adb5bd !important;
        color: #222 !important;
    }
    @media (max-width: 600px) {
        #addSubjectModal .modal-body {
            padding: 1.1rem 0.5rem 1rem 0.5rem;
        }
    }
    </style>
    <style>
    #allSubjectsModal .modal-content {
        border-radius: 18px;
        box-shadow: 0 8px 32px rgba(44,62,80,0.13);
        border: none;
        background: #fff;
    }
    #allSubjectsModal .modal-header {
        border-bottom: none;
        padding-bottom: 0.5rem;
    }
    #allSubjectsModal .modal-title {
        font-size: 1.35rem;
        font-weight: 700;
        letter-spacing: 0.01em;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: #6c757d;
    }
    #allSubjectsModal .modal-title i {
        color: #adb5bd;
        font-size: 1.4rem;
    }
    #allSubjectsModal .modal-body {
        background: #f1f5f9;
        border-radius: 0 0 18px 18px;
        padding: 2rem 1.5rem 1.5rem 1.5rem;
    }
    #allSubjectsModal .form-label {
        font-size: 1.08rem;
        font-weight: 600;
        color: #6c757d;
        margin-bottom: 0.25rem;
    }
    #allSubjectsModal .form-select {
        border-radius: 0.7rem;
        padding: 0.5rem 1rem;
        font-size: 1.05rem;
        margin-bottom: 0.5rem;
        border: 1.5px solid #adb5bd;
        background: #fff;
        color: #1e293b;
        transition: border-color 0.18s, box-shadow 0.18s;
    }
    #allSubjectsModal .form-select:focus {
        border-color: #6c757d;
        box-shadow: 0 0 0 2px #adb5bd33;
        outline: none;
    }
    #allSubjectsModal .input-group-text {
        background: none !important;
        border: none !important;
        box-shadow: none !important;
        padding: 0 0.5rem !important;
        color: #6c757d;
        font-weight: 600;
        font-size: 1.08rem;
        vertical-align: middle;
    }
    #allSubjectsModal .table {
        background: #fff;
        border-radius: 1.2rem;
        overflow: hidden;
        margin-bottom: 0;
    }
    #allSubjectsModal th {
        background: #e0e7ef;
        color: #6c757d;
        font-weight: 700;
        font-size: 1.05rem;
        border: none;
    }
    #allSubjectsModal td {
        color: #1e293b;
        font-size: 1.01rem;
        vertical-align: middle;
        border-top: 1px solid #e3e8ef;
    }
    #allSubjectsModal .btn-danger {
        font-weight: 600;
        padding: 0.45rem 1.2rem;
        border-radius: 1.5rem;
        font-size: 1.01rem;
        background: #f87171;
        color: #fff;
        border: none;
        transition: background 0.18s, color 0.18s;
    }
    #allSubjectsModal .btn-danger:hover {
        background: #dc2626;
        color: #fff;
    }
    #allSubjectsModal .btn-info {
        font-weight: 600;
        padding: 0.45rem 1.2rem;
        border-radius: 1.5rem;
        font-size: 1.01rem;
        background: #38bdf8;
        color: #fff;
        border: none;
        transition: background 0.18s, color 0.18s;
    }
    #allSubjectsModal .btn-info:hover {
        background: #2563eb;
        color: #fff;
    }
    #allSubjectsModal .btn {
        font-family: 'Inter', Arial, sans-serif !important;
    }
    @media (max-width: 600px) {
        #allSubjectsModal .modal-body {
            padding: 1.1rem 0.5rem 1rem 0.5rem;
        }
    }
    </style>
    <style>
    /* All Subjects filter pill improvements */
    .filter-pill {
        background: #f8fafc;
        border: 1.5px solid #e0e7ef;
        box-shadow: 0 1px 4px rgba(44,62,80,0.04);
        transition: box-shadow 0.18s, border-color 0.18s;
        align-items: center;
        min-width: 180px;
        margin-right: 0.5rem;
    }
    .filter-pill:focus-within, .filter-pill:hover {
        border-color: #adb5bd;
        box-shadow: 0 2px 8px rgba(44,62,80,0.09);
    }
    .filter-icon {
        color: #6c757d !important;
        font-size: 1.1rem;
        display: flex;
        align-items: center;
    }
    .filter-pill select.form-select {
        background: transparent;
        color: #444;
        font-weight: 600;
        border: none;
        box-shadow: none;
        padding-left: 0.2rem;
    }
    .filter-pill select.form-select:focus {
        outline: none;
        box-shadow: none;
    }
    </style>
    <style>
    .subject-btn-wrapper { display: inline-block; }
    </style>
    <!-- Add Timeline/Summary -->
    <div id="semesterSummary" class="mb-3" style="font-size:1.05em; color:#444; background:#f8fafc; border-radius:8px; padding:10px 16px; margin-bottom:10px; border:1px solid #e3e8ef;"></div>
</body>
</html>
