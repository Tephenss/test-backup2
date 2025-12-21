<?php
session_start();
require_once '../config/database.php';
require_once '../config/firebase.php';

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Check if announcements table exists and create it if it doesn't
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'announcements'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("CREATE TABLE `announcements` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `title` varchar(255) NOT NULL,
            `content` text NOT NULL,
            `target_audience` enum('all','students','teachers') NOT NULL DEFAULT 'all',
            `priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
            `created_by` int(11) NOT NULL,
            `expires_at` datetime DEFAULT NULL,
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `created_by` (`created_by`),
            KEY `is_active` (`is_active`),
            KEY `expires_at` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
    }
} catch(PDOException $e) {
    error_log("Error creating announcements table: " . $e->getMessage());
}

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create' || $action === 'update') {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $target_audience = $_POST['target_audience'] ?? 'all'; // 'all', 'students', 'teachers'
        $priority = $_POST['priority'] ?? 'normal'; // 'low', 'normal', 'high', 'urgent'
        $expires_at = $_POST['expires_at'] ?? null;
        $announcement_id = $_POST['announcement_id'] ?? null;
        
        if (empty($title) || empty($content)) {
            $_SESSION['error'] = "Title and content are required.";
        } else {
            try {
                // Prepare announcement data
                $announcementData = [
                    'title' => $title,
                    'content' => $content,
                    'target_audience' => $target_audience,
                    'priority' => $priority,
                    'created_by' => $_SESSION['user_id'],
                    'created_at' => date('Y-m-d H:i:s'),
                    'expires_at' => $expires_at ?: null,
                    'is_active' => 1
                ];
                
                if ($action === 'create') {
                    // Save to MySQL
                    $stmt = $pdo->prepare("
                        INSERT INTO announcements (title, content, target_audience, priority, created_by, expires_at, is_active, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
                    ");
                    $stmt->execute([
                        $title, $content, $target_audience, $priority, $_SESSION['user_id'], $expires_at ?: null
                    ]);
                    $announcement_id = $pdo->lastInsertId();
                    
                    // Add ID to data
                    $announcementData['id'] = $announcement_id;
                    
                    // Backup to Firebase
                    require_once '../helpers/BackupHooks.php';
                    $backupHooks = new BackupHooks();
                    $backupHooks->backupGenericRecord('announcements', $announcementData, 'insert');
                    
                    $_SESSION['success'] = "Announcement created successfully!";
                } else {
                    // Update in MySQL
                    $stmt = $pdo->prepare("
                        UPDATE announcements 
                        SET title = ?, content = ?, target_audience = ?, priority = ?, expires_at = ?, updated_at = NOW()
                        WHERE id = ? AND created_by = ?
                    ");
                    $stmt->execute([
                        $title, $content, $target_audience, $priority, $expires_at ?: null, $announcement_id, $_SESSION['user_id']
                    ]);
                    
                    // Add ID to data
                    $announcementData['id'] = $announcement_id;
                    $announcementData['updated_at'] = date('Y-m-d H:i:s');
                    
                    // Backup to Firebase
                    require_once '../helpers/BackupHooks.php';
                    $backupHooks = new BackupHooks();
                    $backupHooks->backupGenericRecord('announcements', $announcementData, 'update');
                    
                    $_SESSION['success'] = "Announcement updated successfully!";
                }
            } catch(PDOException $e) {
                $_SESSION['error'] = "Error saving announcement: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $announcement_id = $_POST['announcement_id'] ?? null;
        if ($announcement_id) {
            try {
                // Delete from MySQL
                $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = ? AND created_by = ?");
                $stmt->execute([$announcement_id, $_SESSION['user_id']]);
                
                // Delete from Firebase
                require_once '../helpers/BackupHooks.php';
                $backupHooks = new BackupHooks();
                $backupHooks->backupGenericRecord('announcements', ['id' => $announcement_id], 'delete');
                
                $_SESSION['success'] = "Announcement deleted successfully!";
            } catch(PDOException $e) {
                $_SESSION['error'] = "Error deleting announcement: " . $e->getMessage();
            }
        }
    } elseif ($action === 'toggle_status') {
        $announcement_id = $_POST['announcement_id'] ?? null;
        $is_active = $_POST['is_active'] ?? 0;
        if ($announcement_id) {
            try {
                // Update MySQL
                $stmt = $pdo->prepare("UPDATE announcements SET is_active = ?, updated_at = NOW() WHERE id = ? AND created_by = ?");
                $stmt->execute([$is_active, $announcement_id, $_SESSION['user_id']]);
                
                // Fetch updated announcement data for Firebase backup
                $stmt = $pdo->prepare("SELECT * FROM announcements WHERE id = ?");
                $stmt->execute([$announcement_id]);
                $announcementData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($announcementData) {
                    // Ensure is_active is an integer for Firebase
                    $announcementData['is_active'] = (int)$announcementData['is_active'];
                    
                    // Backup to Firebase
                    require_once '../helpers/BackupHooks.php';
                    $backupHooks = new BackupHooks();
                    $backupHooks->backupGenericRecord('announcements', $announcementData, 'update');
                }
                
                $_SESSION['success'] = "Announcement status updated!";
            } catch(PDOException $e) {
                $_SESSION['error'] = "Error updating status: " . $e->getMessage();
            }
        }
    }
    
    header("Location: manage_announcements.php");
    exit();
}

// Fetch all announcements
try {
    $stmt = $pdo->prepare("
        SELECT a.*, 
               ad.full_name as created_by_name
        FROM announcements a
        LEFT JOIN admins ad ON a.created_by = ad.id
        ORDER BY a.created_at DESC
    ");
    $stmt->execute();
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $announcements = [];
    error_log("Error fetching announcements: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Announcements - iAttendance</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <style>
        .announcement-card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            margin-bottom: 20px;
            overflow: hidden;
        }
        .announcement-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }
        .announcement-header {
            padding: 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        .announcement-body {
            padding: 20px;
        }
        .priority-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .priority-low { background: #e3f2fd; color: #1976d2; }
        .priority-normal { background: #f1f8e9; color: #558b2f; }
        .priority-high { background: #fff3e0; color: #f57c00; }
        .priority-urgent { background: #ffebee; color: #c62828; }
        .target-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            background: #f5f5f5;
            color: #666;
        }
        .target-students { background: #e3f2fd; color: #1976d2; }
        .target-teachers { background: #f3e5f5; color: #7b1fa2; }
        .target-all { background: #e8f5e9; color: #2e7d32; }
        .btn-create {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 12px 30px;
            border-radius: 25px;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
            transition: all 0.3s ease;
        }
        .btn-create:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }
        .modal-content {
            border-radius: 16px;
            border: none;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .modal-header {
            border-bottom: 1px solid #e9ecef;
            padding: 20px;
        }
        .modal-body {
            padding: 30px;
        }
        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }
        .form-control, .form-select {
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            padding: 12px 15px;
            transition: all 0.3s ease;
        }
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        textarea.form-control {
            min-height: 150px;
            resize: vertical;
        }
        .content-preview {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
            white-space: pre-wrap;
            max-height: 300px;
            overflow-y: auto;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        .empty-state i {
            font-size: 64px;
            color: #ccc;
            margin-bottom: 20px;
        }
    </style>
</head>
<body class="admin-page">
    <?php include 'sidebar.php'; ?>
    
    <main class="page-content">
        <div class="topbar">
            <button class="toggle-sidebar">
                <i class="bi bi-list"></i>
            </button>
            <div class="user-info dropdown">
                <a href="#" class="user-dropdown-toggle" id="userDropdown" role="button" data-bs-toggle="dropdown">
                    <div class="user-avatar">AD</div>
                    <span class="user-name">System Administrator</span>
                </a>
                <ul class="dropdown-menu user-dropdown" aria-labelledby="userDropdown">
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="../auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>

        <div class="container-fluid py-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-1">Manage Announcements</h1>
                    <p class="text-muted mb-0">Create and manage announcements for students and teachers</p>
                </div>
                <button class="btn btn-primary btn-create" data-bs-toggle="modal" data-bs-target="#announcementModal" onclick="openCreateModal()">
                    <i class="bi bi-plus-circle me-2"></i>Create Announcement
                </button>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-circle-fill me-2"></i><?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (empty($announcements)): ?>
                <div class="empty-state">
                    <i class="bi bi-megaphone"></i>
                    <h4 class="mt-3">No announcements yet</h4>
                    <p class="text-muted">Create your first announcement to inform students and teachers.</p>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($announcements as $ann): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="card announcement-card">
                                <div class="announcement-header">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2"><?php echo htmlspecialchars($ann['title']); ?></h5>
                                        <div class="d-flex gap-2 flex-wrap">
                                            <span class="priority-badge priority-<?php echo $ann['priority']; ?>">
                                                <?php echo ucfirst($ann['priority']); ?>
                                            </span>
                                            <span class="target-badge target-<?php echo $ann['target_audience']; ?>">
                                                <?php 
                                                $targetLabels = ['all' => 'Everyone', 'students' => 'Students', 'teachers' => 'Teachers'];
                                                echo $targetLabels[$ann['target_audience']] ?? ucfirst($ann['target_audience']);
                                                ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-link text-muted" type="button" data-bs-toggle="dropdown">
                                            <i class="bi bi-three-dots-vertical"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="#" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($ann)); ?>)"><i class="bi bi-pencil me-2"></i>Edit</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="toggleStatus(<?php echo $ann['id']; ?>, <?php echo $ann['is_active'] ? 0 : 1; ?>)">
                                                <i class="bi bi-<?php echo $ann['is_active'] ? 'eye-slash' : 'eye'; ?> me-2"></i>
                                                <?php echo $ann['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                            </a></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><a class="dropdown-item text-danger" href="#" onclick="deleteAnnouncement(<?php echo $ann['id']; ?>)"><i class="bi bi-trash me-2"></i>Delete</a></li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="announcement-body">
                                    <p class="text-muted mb-3" style="display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;">
                                        <?php echo htmlspecialchars($ann['content']); ?>
                                    </p>
                                    <div class="d-flex justify-content-between align-items-center text-muted small">
                                        <span><i class="bi bi-person me-1"></i><?php echo htmlspecialchars($ann['created_by_name'] ?? 'Admin'); ?></span>
                                        <span><i class="bi bi-calendar me-1"></i><?php echo date('M d, Y', strtotime($ann['created_at'])); ?></span>
                                    </div>
                                    <?php if ($ann['expires_at']): ?>
                                        <div class="mt-2">
                                            <small class="text-muted"><i class="bi bi-clock me-1"></i>Expires: <?php echo date('M d, Y', strtotime($ann['expires_at'])); ?></small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Announcement Modal -->
    <div class="modal fade" id="announcementModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Create Announcement</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="announcementForm">
                    <input type="hidden" name="action" id="formAction" value="create">
                    <input type="hidden" name="announcement_id" id="announcementId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="title" class="form-label">Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="title" name="title" required maxlength="200">
                        </div>
                        <div class="mb-3">
                            <label for="content" class="form-label">Content <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="content" name="content" rows="6" required></textarea>
                            <small class="text-muted">You can use plain text or simple formatting.</small>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="target_audience" class="form-label">Target Audience</label>
                                <select class="form-select" id="target_audience" name="target_audience">
                                    <option value="all">Everyone</option>
                                    <option value="students">Students Only</option>
                                    <option value="teachers">Teachers Only</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="priority" class="form-label">Priority</label>
                                <select class="form-select" id="priority" name="priority">
                                    <option value="low">Low</option>
                                    <option value="normal" selected>Normal</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="expires_at" class="form-label">Expires At (Optional)</label>
                            <input type="datetime-local" class="form-control" id="expires_at" name="expires_at">
                            <small class="text-muted">Leave empty for no expiration.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Announcement</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openCreateModal() {
            document.getElementById('modalTitle').textContent = 'Create Announcement';
            document.getElementById('formAction').value = 'create';
            document.getElementById('announcementId').value = '';
            document.getElementById('announcementForm').reset();
        }

        function openEditModal(ann) {
            document.getElementById('modalTitle').textContent = 'Edit Announcement';
            document.getElementById('formAction').value = 'update';
            document.getElementById('announcementId').value = ann.id;
            document.getElementById('title').value = ann.title;
            document.getElementById('content').value = ann.content;
            document.getElementById('target_audience').value = ann.target_audience;
            document.getElementById('priority').value = ann.priority;
            if (ann.expires_at) {
                const date = new Date(ann.expires_at);
                const localDate = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
                document.getElementById('expires_at').value = localDate.toISOString().slice(0, 16);
            } else {
                document.getElementById('expires_at').value = '';
            }
            new bootstrap.Modal(document.getElementById('announcementModal')).show();
        }

        function deleteAnnouncement(id) {
            if (confirm('Are you sure you want to delete this announcement?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="announcement_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function toggleStatus(id, status) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="announcement_id" value="${id}">
                <input type="hidden" name="is_active" value="${status}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    </script>
</body>
</html>

