<?php
require_once '../config/database.php';
require_once '../helpers/functions.php';

// Check if user is logged in and is admin
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $name = trim($_POST['name']);
                // Always use BSIT as the course
                $stmt = $pdo->prepare("SELECT id FROM courses WHERE code = 'BSIT' LIMIT 1");
                $stmt->execute();
                $course_id = $stmt->fetchColumn();
                $year_level = $_POST['year_level'];
                
                // Validate section name
                if (strlen($name) < 1) {
                    $_SESSION['error'] = "Section name must be at least 1 character long.";
                    break;
                }
                
                try {
                    // Check if section name already exists for the year level
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sections WHERE name = ? AND year_level = ?");
                    $stmt->execute([$name, $year_level]);
                    if ($stmt->fetchColumn() > 0) {
                        $_SESSION['error'] = "A section with this name already exists for the selected year level.";
                        break;
                    }
                    
                    $stmt = $pdo->prepare("INSERT INTO sections (name, course_id, year_level) VALUES (?, ?, ?)");
                    $stmt->execute([$name, $course_id, $year_level]);
                    $_SESSION['success'] = "Section added successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error'] = "Error adding section: " . $e->getMessage();
                }
                break;

            case 'edit':
                $id = $_POST['id'];
                $name = trim($_POST['name']);
                $course_id = $_POST['course_id'];
                $year_level = $_POST['year_level'];
                
                // Validate section name
                if (strlen($name) < 1) {
                    $_SESSION['error'] = "Section name must be at least 1 character long.";
                    break;
                }
                
                try {
                    // Check if section name already exists for the course and year level (excluding current section)
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sections WHERE name = ? AND course_id = ? AND year_level = ? AND id != ?");
                    $stmt->execute([$name, $course_id, $year_level, $id]);
                    if ($stmt->fetchColumn() > 0) {
                        $_SESSION['error'] = "A section with this name already exists for the selected course and year level.";
                        break;
                    }
                    
                    $stmt = $pdo->prepare("UPDATE sections SET name = ?, course_id = ?, year_level = ? WHERE id = ?");
                    $stmt->execute([$name, $course_id, $year_level, $id]);
                    $_SESSION['success'] = "Section updated successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error'] = "Error updating section: " . $e->getMessage();
                }
                break;

            case 'delete':
                $id = $_POST['id'];
                try {
                    // Get section name and course code for checking students
                    $stmt = $pdo->prepare("
                        SELECT s.name, c.code 
                        FROM sections s 
                        JOIN courses c ON s.course_id = c.id 
                        WHERE s.id = ?
                    ");
                    $stmt->execute([$id]);
                    $sectionData = $stmt->fetch();
                    
                    if (!$sectionData) {
                        $_SESSION['error'] = "Section not found.";
                        break;
                    }

                    // Check if section has students
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE section = ?");
                    $stmt->execute([$sectionData['name']]);
                    if ($stmt->fetchColumn() > 0) {
                        $_SESSION['error'] = "Cannot delete section: There are students assigned to this section.";
                        break;
                    }
                    
                    $stmt = $pdo->prepare("DELETE FROM sections WHERE id = ?");
                    $stmt->execute([$id]);
                    $_SESSION['success'] = "Section deleted successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error'] = "Error deleting section: " . $e->getMessage();
                }
                break;

            case 'assign_section_student':
                $student_id = $_POST['assign_section_student_id'];
                $section_id = $_POST['assign_section_section_id'];
                // Get section info
                $stmt = $pdo->prepare("SELECT year_level, course_id FROM sections WHERE id = ?");
                $stmt->execute([$section_id]);
                $sectionData = $stmt->fetch();
                if ($sectionData) {
                    $yearLevel = $sectionData['year_level'];
                    $courseId = $sectionData['course_id'];
                    // Find all sections for this year level and course
                    $stmtSections = $pdo->prepare("SELECT id, name FROM sections WHERE year_level = ? AND course_id = ? ORDER BY name ASC");
                    $stmtSections->execute([$yearLevel, $courseId]);
                    $sections = $stmtSections->fetchAll(PDO::FETCH_ASSOC);
                    $assignedSection = null;
                    foreach ($sections as $section) {
                    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM students WHERE section = ? AND year_level = ?");
                        $stmtCount->execute([$section['name'], $yearLevel]);
                    $studentCount = $stmtCount->fetchColumn();
                        if ($studentCount < 5) {
                            $assignedSection = $section['name'];
                            break;
                        }
                    }
                    if (!$assignedSection) {
                        // All sections full, create new section with next available letter
                        $sectionLetters = array_merge(['A','B','C','D','E'], range('F', 'Z'));
                        $existingNames = array_map(function($s) { return $s['name']; }, $sections);
                        foreach ($sectionLetters as $letter) {
                            if (!in_array($letter, $existingNames)) {
                                // Create new section
                                $stmtAdd = $pdo->prepare("INSERT INTO sections (name, course_id, year_level) VALUES (?, ?, ?)");
                                $stmtAdd->execute([$letter, $courseId, $yearLevel]);
                                $assignedSection = $letter;
                                break;
                            }
                        }
                    }
                    if ($assignedSection) {
                        $stmt = $pdo->prepare("UPDATE students SET section = ? WHERE id = ?");
                        $stmt->execute([$assignedSection, $student_id]);
                        $_SESSION['success'] = "Section assigned successfully!";
                        } else {
                            $_SESSION['error'] = "No available section letters.";
                    }
                } else {
                    $_SESSION['error'] = "Invalid section selected.";
                }
                header('Location: manage_sections.php');
                exit();
        }
        header('Location: manage_sections.php');
        exit();
    }
}

// Get filter parameters
$filter_course = isset($_GET['course']) ? $_GET['course'] : '';
$filter_year = isset($_GET['year']) ? $_GET['year'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build the query
$query = "SELECT s.*, c.name as course_name, c.code as course_code 
          FROM sections s 
          JOIN courses c ON s.course_id = c.id 
          WHERE 1=1";
$params = [];

if ($filter_course) {
    $query .= " AND s.course_id = ?";
    $params[] = $filter_course;
}

if ($filter_year) {
    $query .= " AND s.year_level = ?";
    $params[] = $filter_year;
}

if ($search) {
    $query .= " AND (s.name LIKE ? OR c.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$query .= " ORDER BY c.name, s.year_level, s.name";

// Fetch filtered sections
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$sections = $stmt->fetchAll();

// Fetch all courses for dropdown
$stmt = $pdo->query("SELECT * FROM courses ORDER BY name");
$courses = $stmt->fetchAll();

// Always show all year levels (1-4) even if there are no sections for a year
$year_levels = [1, 2, 3, 4];

// Fetch students who are approved but not yet assigned to a section, with course_id
try {
    $unassigned_students = $pdo->query("
        SELECT s.id, s.student_id, s.first_name, s.middle_name, s.last_name, s.course, s.year_level, c.id as course_id
        FROM students s
        LEFT JOIN courses c ON c.code = s.course
        WHERE s.status = 'approved'
        AND (s.section IS NULL OR s.section = '' OR s.section = '0')
        ORDER BY s.created_at ASC
    ")->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching unassigned students: " . $e->getMessage());
    $unassigned_students = [];
    $_SESSION['error'] = "Error fetching unassigned students. Please try again.";
}

// Fetch all sections for assignment dropdown
try {
    $all_sections = $pdo->query("SELECT id, name, course_id, year_level FROM sections ORDER BY course_id, year_level, name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching sections: " . $e->getMessage());
    $all_sections = [];
    $_SESSION['error'] = "Error fetching sections. Please try again.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Sections - iAttendance</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <style>
        .table-actions .btn { margin: 0 2px; }
        .modal-body label { margin-top: 10px; }
        .table td {
            border-bottom: 1px solid #dee2e6;
        }
        .table tbody tr:last-child td {
            border-bottom: none;
        }
        .table tbody tr:hover {
            background-color: #f1f3f5;
            cursor: pointer;
        }
        .card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,.05);
        }
        .tab-content.book-tab-content {
            min-height: 320px;
            position: relative;
        }
        .tab-pane.book-flip {
            transition: opacity 0.4s cubic-bezier(.4,2,.6,1), transform 0.4s cubic-bezier(.4,2,.6,1);
            position: absolute;
            width: 100%;
            left: 0;
            top: 0;
            z-index: 1;
            opacity: 0;
            transform: translateX(40px);
            pointer-events: none;
        }
        .tab-pane.book-flip.book-flip-flip-in {
            opacity: 1;
            transform: translateX(0);
            z-index: 2;
            pointer-events: auto;
        }
        .tab-pane.book-flip.book-flip-flip-out {
            opacity: 0;
            transform: translateX(-40px);
            z-index: 1;
            pointer-events: none;
        }
        .tab-pane.active:not(.book-flip) {
            position: relative;
            opacity: 1;
            transform: none;
            z-index: 2;
        }
        .nav-tabs.book-tabs .nav-link {
            border-radius: 1.5rem 1.5rem 0 0;
            margin: 0 2px;
            background: #e9ecef;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
            font-weight: 600;
            color: #495057;
            transition: background 0.2s, color 0.2s;
            border: none;
        }
        .nav-tabs.book-tabs .nav-link.active {
            background: #495057;
            color: #fff;
            box-shadow: 0 4px 12px rgba(73,80,87,0.12);
            border-bottom: 2px solid #495057;
        }
        .nav-tabs.book-tabs .nav-link:focus {
            outline: none;
            box-shadow: 0 0 0 2px #adb5bd;
        }
        .book-page-indicator {
            text-align: right;
            font-size: 1rem;
            color: #adb5bd;
            margin: 0.5rem 0 0.5rem 0;
            font-style: italic;
        }
        .btn-dark {
            background: #495057;
            color: #fff;
            border: none;
            border-radius: 0.5rem;
            font-weight: 600;
            transition: background 0.2s, color 0.2s;
        }
        .btn-dark:hover, .btn-dark:focus {
            background: #343a40;
            color: #fff;
        }
        /* Unified Modal Design for Admin */
        .modal-content {
            border-radius: 18px;
            box-shadow: 0 8px 32px rgba(44,62,80,0.13);
            border: none;
            background: #fff;
        }
        .modal-header {
            border-bottom: none;
            padding-bottom: 0.5rem;
            background: none;
        }
        .modal-title {
            font-size: 1.35rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #6c757d;
        }
        .modal-title i {
            color: #adb5bd;
            font-size: 1.4rem;
        }
        .modal-body {
            background: #f1f5f9;
            border-radius: 0 0 18px 18px;
            padding: 2rem 1.5rem 1.5rem 1.5rem;
        }
        .modal-footer {
            background: none;
            border-top: none;
            padding: 1.2rem 1.5rem 1.5rem 1.5rem;
            display: flex;
            gap: 0.7rem;
            justify-content: flex-end;
        }
        .btn-primary {
            font-weight: 700;
            padding: 0.55rem 2.2rem;
            border-radius: 1.5rem;
            font-size: 1.08rem;
            background: #6c757d;
            border: none;
            color: #fff;
            transition: background 0.18s, box-shadow 0.18s;
        }
        .btn-primary:hover {
            background: #495057;
            color: #fff;
        }
        .btn-secondary {
            font-weight: 600;
            padding: 0.55rem 1.5rem;
            border-radius: 1.5rem;
            font-size: 1.08rem;
            background: #e0e7ef !important;
            color: #444 !important;
            border: none;
            transition: background 0.18s, color 0.18s;
        }
        .btn-secondary:hover {
            background: #adb5bd !important;
            color: #222 !important;
        }
        .btn-danger {
            font-weight: 600;
            padding: 0.55rem 1.5rem;
            border-radius: 1.5rem;
            font-size: 1.08rem;
            background: #f87171;
            color: #fff;
            border: none;
            transition: background 0.18s, color 0.18s;
        }
        .btn-danger:hover {
            background: #dc2626;
            color: #fff;
        }
    </style>
</head>
<body class="admin-page">
    <!-- Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content -->
    <main class="page-content">
        <!-- Topbar -->
        <div class="topbar">
            <button class="toggle-sidebar">
                <i class="bi bi-list"></i>
            </button>

            <div class="user-info dropdown">
                <a href="#" class="user-dropdown-toggle" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="user-avatar">AD</div>
                    <span class="user-name">System Administrator</span>
                </a>
                <ul class="dropdown-menu user-dropdown" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i> Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="../auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>

        <div class="container-fluid px-4">
            <!-- Nav Bar for Section Management -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="mb-0">Manage Sections</h4>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i>
                    <?php 
                    echo $_SESSION['success'];
                    unset($_SESSION['success']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-circle me-2"></i>
                    <?php 
                    echo $_SESSION['error'];
                    unset($_SESSION['error']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Manage Sections Table (default visible) -->
            <div id="manageSectionsPanel">
            <div class="card">
                <div class="card-body p-0">
                    <!-- Book-style year level tabs -->
                    <ul class="nav nav-tabs nav-justified mb-3 book-tabs" id="yearTabs" role="tablist">
                        <?php foreach ($year_levels as $i => $year): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link<?php if ($i === 0) echo ' active'; ?>" id="year-tab-<?php echo $year; ?>" data-bs-toggle="tab" data-bs-target="#year-<?php echo $year; ?>" type="button" role="tab" aria-controls="year-<?php echo $year; ?>" aria-selected="<?php echo $i === 0 ? 'true' : 'false'; ?>">
                                <?php echo $year; ?><?php echo ($year == 1) ? 'st' : (($year == 2) ? 'nd' : (($year == 3) ? 'rd' : 'th')); ?> Year
                            </button>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="tab-content book-tab-content" id="yearTabsContent">
                        <?php foreach ($year_levels as $i => $year): ?>
                        <div class="tab-pane fade<?php if ($i === 0) echo ' show active'; ?>" id="year-<?php echo $year; ?>" role="tabpanel" aria-labelledby="year-tab-<?php echo $year; ?>">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr class="bg-light">
                                            <th style="padding: 15px 20px;">Section Name</th>
                                            <th style="padding: 15px 20px;">Course</th>
                                            <th style="padding: 15px 20px;">Total Students</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $hasSection = false; 
                                        foreach ($sections as $section): 
                                            if ($section['year_level'] == $year): 
                                                // Count students in this section
                                                $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM students WHERE section = ? AND year_level = ?");
                                                $stmtCount->execute([$section['name'], $section['year_level']]);
                                                $studentCount = $stmtCount->fetchColumn();
                                                if ($studentCount == 0) continue; // Skip if 0 students

                                                $hasSection = true; 
                                        ?>
                                            <tr>
                                                <td class="align-middle" style="padding: 15px 20px;"><?php echo htmlspecialchars($section['name']); ?></td>
                                                <td class="align-middle" style="padding: 15px 20px;">
                                                    <span class="badge bg-primary me-2" style="font-size: 12px; font-weight: 500;">
                                                        <?php echo htmlspecialchars($section['course_code']); ?>
                                                    </span>
                                                    <?php echo htmlspecialchars($section['course_name']); ?>
                                                </td>
                                                <td class="align-middle" style="padding: 15px 20px;">
                                                    <?php
                                                    $displayCount = min($studentCount, 5);
                                                    echo $displayCount . ' / 5';
                                                    ?>
                                                </td>
                                            </tr>
                                        <?php 
                                            endif; 
                                        endforeach; 
                                        if (!$hasSection): 
                                        ?>
                                            <tr>
                                                <td colspan="3" class="text-center py-4">
                                                    <i class="bi bi-inbox display-4 d-block mb-2 text-muted"></i>
                                                    <p class="text-muted">No sections found for this year level</p>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="d-flex justify-content-end align-items-end" style="min-height:2.5rem;">
                                <div class="book-page-indicator" style="position:relative; bottom:0; right:0; margin-bottom:0.5rem; margin-right:0.5rem;">
                                    Page <?php echo ($i+1); ?> of <?php echo count($year_levels); ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            </div>

            <!-- Filter Modal -->
            <div class="modal fade" id="filterModal" tabindex="-1" aria-labelledby="filterModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="filterModalLabel">
                                <i class="bi bi-funnel me-2"></i>
                                Filter Sections
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form method="GET">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label for="search" class="form-label">Select Section</label>
                                    <select class="form-select" id="search" name="search">
                                        <option value="">All Sections</option>
                                        <?php 
                                        $stmt = $pdo->query("SELECT DISTINCT name FROM sections ORDER BY name");
                                        $allSections = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                        foreach ($allSections as $sectionName): 
                                        ?>
                                            <option value="<?php echo htmlspecialchars($sectionName); ?>"
                                                    <?php echo $search === $sectionName ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($sectionName); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <hr>
                                <div class="mb-3">
                                    <label for="course" class="form-label">Course</label>
                                    <select class="form-select" id="course" name="course">
                                        <option value="">All Courses</option>
                                        <?php foreach ($courses as $course): ?>
                                            <option value="<?php echo $course['id']; ?>" 
                                                    <?php echo $filter_course == $course['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($course['code'] . ' - ' . $course['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="year" class="form-label">Year Level</label>
                                    <select class="form-select" id="year" name="year">
                                        <option value="">All Years</option>
                                        <?php for ($i = 1; $i <= 4; $i++): ?>
                                            <option value="<?php echo $i; ?>" 
                                                    <?php echo $filter_year == $i ? 'selected' : ''; ?>>
                                                <?php echo $i; ?>st Year
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <a href="manage_sections.php" class="btn btn-link text-decoration-none">Clear All</a>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-funnel me-1"></i>
                                    Apply Filters
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Add Section Modal -->
    <div class="modal fade" id="addSectionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle me-2"></i>
                        Add New Section
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="" method="POST" id="addSectionForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label for="section_name" class="form-label">Section Name</label>
                            <select class="form-select" id="section_name" name="name" required>
                                <option value="">Select Section</option>
                                <option value="A">A</option>
                                <option value="B">B</option>
                                <option value="C">C</option>
                                <option value="D">D</option>
                                <option value="E">E</option>
                            </select>
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
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-lg me-2"></i>
                            Add Section
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Section Modal -->
    <div class="modal fade" id="deleteSectionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Delete Section
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" id="delete_id">
                        <p>Are you sure you want to delete the section "<span id="delete_name" class="fw-bold"></span>"?</p>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-circle me-2"></i>
                            This action cannot be undone. All data associated with this section will be permanently deleted.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash me-2"></i>
                            Delete Section
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>
    <script>
        // Tab switching logic
        const manageSectionsPanel = document.getElementById('manageSectionsPanel');
        const assignSectionPanel = document.getElementById('assignSectionPanel');
        const showManageSectionsBtn = document.getElementById('showManageSectionsBtn');
        const showAssignSectionBtn = document.getElementById('showAssignSectionBtn');

        showManageSectionsBtn.classList.add('active');

        showManageSectionsBtn.addEventListener('click', function() {
            manageSectionsPanel.style.display = '';
            assignSectionPanel.style.display = 'none';
            showManageSectionsBtn.classList.add('active');
            showAssignSectionBtn.classList.remove('active');
        });

        showAssignSectionBtn.addEventListener('click', function() {
            manageSectionsPanel.style.display = 'none';
            assignSectionPanel.style.display = '';
            showAssignSectionBtn.classList.add('active');
            showManageSectionsBtn.classList.remove('active');
        });

        // Book-style page flip animation for year tabs (improved smoothness)
        const tabContent = document.getElementById('yearTabsContent');
        document.querySelectorAll('#yearTabs button[data-bs-toggle="tab"]').forEach(tabBtn => {
            tabBtn.addEventListener('show.bs.tab', function (e) {
                const prevPane = document.querySelector('.tab-pane.active');
                const nextPane = document.querySelector(this.getAttribute('data-bs-target'));
                if (prevPane && nextPane) {
                    prevPane.classList.add('book-flip', 'book-flip-flip-out');
                    nextPane.classList.add('book-flip', 'book-flip-flip-in');
                    setTimeout(() => {
                        prevPane.classList.remove('book-flip', 'book-flip-flip-out');
                        nextPane.classList.remove('book-flip', 'book-flip-flip-in');
                    }, 400);
                }
            });
        });

        // Handle delete modal
        document.getElementById('deleteSectionModal').addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const id = button.getAttribute('data-id');
            const name = button.getAttribute('data-name');

            document.getElementById('delete_id').value = id;
            document.getElementById('delete_name').textContent = name;
        });

        // Form validation and loading state
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                if (this.checkValidity()) {
                    this.classList.add('loading');
                    this.querySelector('button[type="submit"]').disabled = true;
                }
            });
        });
    </script>
</body>
</html> 