<?php
session_start();
require_once '../config/database.php';
require_once '../helpers/FirebaseBackup.php';

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'test_connection':
                try {
                    $firebase = new FirebaseBackup();
                    if ($firebase->testConnection()) {
                        $message = "✅ Firebase connection successful!";
                    } else {
                        $error = "❌ Firebase connection failed. Check your configuration.";
                    }
                } catch (Exception $e) {
                    $error = "❌ Firebase connection error: " . $e->getMessage();
                }
                break;
        }
    }
}

$config = require '../config/firebase.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Firebase Database Backup - iAttendance</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
</head>
<body class="admin-page">
    <!-- Sidebar -->
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
                    <span class="user-name">System Administrator</span>
                </a>
                <ul class="dropdown-menu user-dropdown" aria-labelledby="userDropdown">
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="../auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <!-- Firebase Setup Section -->
        <div class="row animate-fadeIn">
            <div class="col-12">
                <h1 class="mb-4">Firebase Database Backup</h1>
            </div>
        </div>

        <!-- Configuration Status -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header bg-white border-0 pb-0">
                        <h5 class="mb-0">Configuration Status</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Backup Enabled:</strong> 
                                    <span class="badge bg-<?php echo $config['backup_enabled'] ? 'success' : 'danger'; ?>">
                                        <?php echo $config['backup_enabled'] ? 'Yes' : 'No'; ?>
                                    </span>
                                </p>
                                <p><strong>Project ID:</strong> <?php echo $config['project_id']; ?></p>
                                <p><strong>Database URL:</strong> <?php echo $config['database_url']; ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Tables to Backup:</strong> <?php echo count($config['backup_tables']); ?></p>
                                <p><strong>Logging:</strong> 
                                    <span class="badge bg-<?php echo $config['log_backup_operations'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $config['log_backup_operations'] ? 'Enabled' : 'Disabled'; ?>
                                    </span>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Connection Test -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header bg-white border-0 pb-0">
                        <h5 class="mb-0">Connection Test</h5>
                    </div>
                    <div class="card-body">
                        <p>Test your Firebase connection and verify data storage functionality.</p>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="test_connection">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-wifi me-2"></i>Test Connection & Data Storage
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>
</body>
</html>
