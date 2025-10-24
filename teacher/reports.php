<?php
session_start();
require_once '../config/database.php';
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'teacher') {
    header("Location: ../index.php");
    exit();
}

// Fetch current user info for avatar display
$stmt = $pdo->prepare("SELECT * FROM teachers WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch unique sections for this teacher
$sectionStmt = $pdo->prepare("SELECT DISTINCT section FROM classes WHERE teacher_id = ? ORDER BY section");
$sectionStmt->execute([$_SESSION['user_id']]);
$sections = $sectionStmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch unique subjects for this teacher
$subjectStmt = $pdo->prepare("SELECT DISTINCT s.subject_name FROM classes c JOIN subjects s ON c.subject_id = s.id WHERE c.teacher_id = ? ORDER BY s.subject_name");
$subjectStmt->execute([$_SESSION['user_id']]);
$subjects = $subjectStmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch unique semesters for this teacher
$semesterStmt = $pdo->prepare("SELECT DISTINCT c.semester FROM attendance a JOIN classes c ON a.class_id = c.id WHERE c.teacher_id = ? AND c.semester IS NOT NULL AND c.semester != '' ORDER BY c.semester");
$semesterStmt->execute([$_SESSION['user_id']]);
$semesters = $semesterStmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($semesters)) {
    $semesters = ['1st Semester', '2nd Semester'];
}

// Fetch unique terms for this teacher
$termStmt = $pdo->prepare("SELECT DISTINCT m.term FROM marks m JOIN classes c ON m.class_id = c.id WHERE c.teacher_id = ? AND m.term IS NOT NULL AND m.term != '' ORDER BY m.term");
$termStmt->execute([$_SESSION['user_id']]);
$terms = $termStmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($terms)) {
    $terms = ['Prelim', 'Midterm', 'Final'];
}

// Fetch assessment types for the performance report modal
$assessmentTypeStmt = $pdo->prepare("
    SELECT id, name FROM assessment_types ORDER BY name
");
$assessmentTypeStmt->execute();
$assessment_types = $assessmentTypeStmt->fetchAll(PDO::FETCH_ASSOC);

// Add cache control headers
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$shortName = '';
if (!empty($user['first_name']) && !empty($user['last_name'])) {
    $shortName = strtoupper(substr(trim($user['first_name']), 0, 1)) . '.' . ucfirst(strtolower(trim($user['last_name'])));
} else {
    $shortName = htmlspecialchars($user['full_name'] ?? '');
}

$stmt = $pdo->prepare("
    SELECT c.id,
           s.subject_code,
           s.year_level,
           c.section,
           CONCAT(s.subject_code, '-', s.year_level, c.section) as class_name,
           CONCAT(s.subject_code, ' - ', c.section, ' (', s.year_level, 'st Year)') as class_desc
    FROM classes c
    JOIN subjects s ON c.subject_id = s.id
    JOIN sections sec ON c.section = sec.name
    JOIN subject_assignments sa ON sa.teacher_id = c.teacher_id 
        AND sa.subject_id = c.subject_id 
        AND sa.section_id = sec.id
    WHERE c.teacher_id = ? AND c.status = 'active'
    ORDER BY s.subject_code, c.section
");
$stmt->execute([$_SESSION['user_id']]);
$available_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Teacher Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <link href="../assets/css/management.css" rel="stylesheet">
    <style>
        .report-card {
            transition: transform 0.2s;
        }
        .report-card:hover {
            transform: translateY(-5px);
        }
        .report-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
        }
        .user-avatar {
            width: 40px;
            height: 40px;
            background: #e9ecef;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: #495057;
            overflow: hidden;
        }
        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }
        .sidebar-unread-badge {
            background: #dc3545;
            color: #fff;
            border-radius: 50%;
            font-size: 0.85em;
            font-weight: bold;
            padding: 2px 8px;
            margin-left: 8px;
            box-shadow: 0 2px 8px rgba(220,53,69,0.15);
            display: inline-block;
            vertical-align: middle;
            animation: pulseUnread 1.2s infinite alternate;
            position: relative;
            top: -2px;
        }
        @keyframes pulseUnread {
            0% { box-shadow: 0 0 0 0 rgba(220,53,69,0.4); }
            100% { box-shadow: 0 0 0 8px rgba(220,53,69,0.0); }
        }
        .sidebar .nav-link.active,
        .sidebar .nav-link[aria-expanded="true"] {
            background: #7da6fa !important;
            color: #fff !important;
            border-radius: 10px;
            font-weight: 600;
        }
        .sidebar .nav-link,
        .sidebar .nav-link.d-flex {
            font-size: 1rem !important;
            font-weight: 500 !important;
        }
        .sidebar .collapse,
        .sidebar .collapse.show {
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            margin-top: 0;
            padding-top: 0;
            position: static !important;
            z-index: auto !important;
            padding-left: 0 !important;
        }
        .sidebar .collapse .nav-link {
            color: #fff;
            font-size: 1.08rem;
            border-radius: 8px;
            margin-bottom: 0.3rem;
            padding-left: 1.5rem !important;
            transition: background 0.2s, color 0.2s;
        }
        .sidebar .collapse .nav-link.active,
        .sidebar .collapse .nav-link:hover {
            background: rgba(255,255,255,0.12);
            color: #fff;
        }
        .sidebar .nav-link[aria-expanded="true"] .bi-chevron-down {
            transform: rotate(180deg);
            transition: transform 0.2s;
        }
        .sidebar .nav-link .bi-chevron-down {
            transition: transform 0.2s;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <a href="dashboard.php" class="sidebar-brand">
                <i class="bi bi-calendar2-check"></i>
                <span class="sidebar-brand-text">iAttendance</span>
            </a>
        </div>
        
        <hr class="sidebar-divider">
        
        <div class="sidebar-heading">Main</div>
        
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" href="dashboard.php">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="manage_students.php">
                    <i class="bi bi-people"></i>
                    <span>Students</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="manage_attendance.php">
                    <i class="bi bi-calendar-check"></i>
                    <span>Attendance</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="manage_timetable.php">
                    <i class="bi bi-clock"></i>
                    <span>Timetable</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="reports.php">
                    <i class="bi bi-bar-chart"></i>
                    <span>Reports</span>
                </a>
            </li>
            
        </ul>
        
        <hr class="sidebar-divider">
        
        <div class="sidebar-heading">Account</div>
        
                <ul class="navbar-nav">
                    <li class="nav-item">
                <a class="nav-link" href="profile.php">
                    <i class="bi bi-person"></i>
                    <span>Profile</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="../auth/logout.php">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
                    </li>
        </ul>
    </aside>

    <!-- Main Content -->
    <main class="page-content">
        <!-- Topbar -->
        <div class="topbar">
            <button class="toggle-sidebar">
                <i class="bi bi-list"></i>
            </button>
            
            <div class="user-info">
                <a href="#" class="user-dropdown-toggle" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="user-avatar">
<?php if (!empty($user['avatar']) && file_exists('../' . $user['avatar'])): ?>
    <img src="../<?php echo htmlspecialchars($user['avatar']); ?>" alt="Profile Avatar" class="avatar-image">
<?php else: ?>
    <?php echo isset($_SESSION['initials']) ? $_SESSION['initials'] : 'ME'; ?>
<?php endif; ?>
                    </div>
                    <span class="user-name ms-2" style="font-weight:600; font-size:1.1em; white-space:nowrap; overflow:visible; text-overflow:unset; max-width:none;">
                        <?php echo $shortName; ?>
                    </span>
                </a>
                <ul class="dropdown-menu user-dropdown" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person"></i> Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="../auth/logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                </ul>
            </div>
        </div>

        <div class="management-header animate-fadeIn">
            <h2>Reports</h2>
        </div>
        
        <div class="row justify-content-center animate-fadeIn delay-1">
            <!-- Attendance Report Card -->
            <div class="col-12 col-md-6 col-lg-5 mb-4">
                <div class="info-card h-100">
                    <div class="info-card-body text-center">
                        <div class="report-icon text-primary">
                            <i class="bi bi-calendar-check"></i>
                        </div>
                        <h5 class="card-title">Attendance Report</h5>
                        <p class="card-text">Generate detailed attendance reports for your classes.</p>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#attendanceReportModal">
                            <i class="bi bi-download me-2"></i>Generate Report
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Attendance Report Modal -->
    <div class="modal fade" id="attendanceReportModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Generate Attendance Report</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="reports.php">
                        <input type="hidden" name="report_type" value="attendance">
                        <div class="mb-3">
                            <label for="section" class="form-label">Select Section</label>
                            <select class="form-select" id="section" name="section" required>
                                <option value="" selected disabled>Select section...</option>
                                <?php foreach ($sections as $section): ?>
                                    <option value="<?php echo htmlspecialchars($section); ?>"><?php echo htmlspecialchars($section); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="subject" class="form-label">Select Subject</label>
                            <select class="form-select" id="subject" name="subject" required>
                                <option value="" selected disabled>Select subject...</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo htmlspecialchars($subject); ?>"><?php echo htmlspecialchars($subject); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="report_format" class="form-label">Report Format</label>
                            <select class="form-select" id="report_format" name="report_format">
                                <option value="pdf">PDF</option>
                            </select>
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-download me-2"></i>Generate</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Report Modal -->
    <div class="modal fade" id="reportResultModal" tabindex="-1" aria-labelledby="reportResultModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-xl">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="reportResultModalLabel">Generated Report</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body" id="report-modal-body">
            <!-- Report content will be loaded here -->
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            <button type="button" class="btn btn-primary" onclick="printReport()"><i class="bi bi-printer"></i> Print</button>
          </div>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle sidebar
        document.querySelector('.toggle-sidebar').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('toggled');
            document.querySelector('.page-content').classList.toggle('expanded');
        });

        // Auto-hide alerts after 5 seconds
        document.querySelectorAll('.alert').forEach(function(alert) {
            setTimeout(function() {
                alert.classList.add('fade-out');
                setTimeout(function() {
                    alert.remove();
                }, 150);
            }, 5000);
        });

        function printReport() {
            var printContents = document.getElementById('report-modal-body').innerHTML;
            var originalContents = document.body.innerHTML;
            document.body.innerHTML = printContents;
            window.print();
            document.body.innerHTML = originalContents;
            location.reload(); // To restore event listeners and modal
        }

        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form[action="reports.php"]');
            if (form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const formData = new FormData(form);
                    formData.append('ajax', '1');
                    fetch('generate_report.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.text())
                    .then(html => {
                        document.getElementById('report-modal-body').innerHTML = html;
                        var reportModal = new bootstrap.Modal(document.getElementById('reportResultModal'));
                        reportModal.show();
                    });
                });
            }
        });

        function pollSidebarUnreadBadge() {
            fetch('teacher_unread_count.php')
                .then(r => r.json())
                .then(data => {
                    const badge = document.getElementById('sidebar-unread-badge');
                    if (badge) {
                        if (data.unread > 0) {
                            badge.textContent = data.unread;
                            badge.style.display = 'inline-block';
                        } else {
                            badge.style.display = 'none';
                        }
                    }
                });
        }
        setInterval(pollSidebarUnreadBadge, 2000);
        pollSidebarUnreadBadge();
    </script>
</body>
</html> 