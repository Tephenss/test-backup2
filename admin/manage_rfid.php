<?php
session_start();
require_once '../config/database.php';
require_once '../config.php';
require_once '../helpers/RfidHelper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

ensureRfidInfrastructure($pdo);

// Skip sync on initial page load to prevent re-adding deleted tags
// Sync will happen on refresh button click or after actions
$rfidTags = fetchRfidTags($pdo, true);
$rfidStats = getRfidStats($pdo);
$availableTags = getAvailableRfidTags($pdo);
$firebaseConfig = require '../config/firebase.php';
$firebaseBaseUrl = rtrim($firebaseConfig['database_url'], '/');
$scannerPath = 'attendance_system/rfid_scans/latest';
$scannerUrl = $firebaseBaseUrl . '/' . $scannerPath . '.json';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage RFID - iAttendance</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dashboard.css" rel="stylesheet">
    <style>
        .stat-card {
            border-radius: 1rem;
            background: #fff;
            box-shadow: 0 0.5rem 1rem rgba(140,152,164,.1);
            padding: 1.5rem;
            height: 100%;
        }
        .stat-label {
            font-size: .85rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #6c757d;
            margin-bottom: .25rem;
        }
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #1a2b4b;
        }
        .live-status-pill {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: .25rem .85rem;
            border-radius: 999px;
            font-weight: 600;
        }
        .student-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            z-index: 20;
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: .5rem;
            max-height: 240px;
            overflow-y: auto;
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,.1);
        }
        .student-suggestions button {
            width: 100%;
            border: none;
            background: transparent;
            text-align: left;
            padding: .65rem .85rem;
        }
        .student-suggestions button:hover {
            background: #f8f9fa;
        }
        .rfid-table-status {
            min-width: 110px;
        }
        .rfid-table-actions button + button {
            margin-left: .4rem;
        }
    </style>
</head>
<body class="admin-page">
    <?php include 'sidebar.php'; ?>

    <main class="page-content" id="rfidManagerApp"
          data-action-url="rfid_actions.php"
          data-scanner-url="<?php echo htmlspecialchars($scannerUrl); ?>"
          data-firebase-node="<?php echo htmlspecialchars($scannerPath); ?>">

        <?php include 'topbar.php'; ?>

        <div class="container-fluid px-4 py-4">
            <div class="d-flex flex-wrap align-items-center justify-content-between mb-4">
                <div>
                    <h1 class="h3 mb-1">Manage RFID</h1>
                    <p class="text-muted mb-0">Register RFID cards via your Arduino + Firebase bridge and assign them to students.</p>
                </div>
                <div class="d-flex gap-2">
                    <a class="btn btn-outline-secondary" href="setup_firebase.php">
                        <i class="bi bi-cloud me-1"></i>
                        Firebase Setup
                    </a>
                    <button id="refreshTagsBtn" class="btn btn-primary">
                        <i class="bi bi-arrow-clockwise me-1"></i>
                        Refresh Data
                    </button>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-label">Total Tags</div>
                        <div class="stat-value" id="statTotalTags"><?php echo $rfidStats['total']; ?></div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-label">Assigned</div>
                        <div class="stat-value text-success" id="statAssigned"><?php echo $rfidStats['assigned']; ?></div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-label">Available</div>
                        <div class="stat-value text-primary" id="statAvailable"><?php echo $rfidStats['available']; ?></div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-label">Disabled</div>
                        <div class="stat-value text-danger" id="statDisabled"><?php echo $rfidStats['disabled']; ?></div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-lg-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white border-0 pb-0">
                            <h5 class="mb-1">Live RFID Registration</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <span class="text-muted">Scanner Status</span>
                                <span class="live-status-pill bg-light text-secondary" id="listeningStatus">
                                    <span class="status-dot bg-secondary rounded-circle" style="width:8px;height:8px;"></span>
                                    Idle
                                </span>
                            </div>
                            <div class="d-flex gap-2 mb-3">
                                <button class="btn btn-success flex-grow-1" id="startListeningBtn">
                                    <i class="bi bi-broadcast-pin me-1"></i>
                                    Start Listening
                                </button>
                                <button class="btn btn-outline-secondary" id="manualFetchBtn" title="Manual fetch from Firebase">
                                    <i class="bi bi-arrow-repeat"></i>
                                </button>
                                <button class="btn btn-outline-info" id="testFirebaseBtn" title="Test Firebase connection">
                                    <i class="bi bi-wifi"></i>
                                </button>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Captured UID</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-nfc"></i></span>
                                    <input type="text" class="form-control" id="capturedRfidInput" placeholder="Waiting for RFID scan..." readonly>
                                </div>
                                <div class="small text-muted mt-1" id="scanMeta">No scan yet. Click "Start Listening" and tap the RFID card.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Manual Entry (Optional)</label>
                                <input type="text" class="form-control" id="manualRfidInput" placeholder="Type the UID if the scanner is offline">
                                <small class="text-muted">You can also type the UID manually if the live scanner is not working.</small>
                            </div>

                            <div class="d-flex flex-column flex-sm-row gap-2">
                                <button class="btn btn-primary flex-grow-1" id="saveCapturedTag">
                                    <i class="bi bi-cloud-arrow-up me-1"></i>
                                    Register RFID
                                </button>
                                <button class="btn btn-outline-danger" id="clearCapturedBtn">
                                    <i class="bi bi-eraser me-1"></i>
                                    Clear
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white border-0 pb-0">
                            <h5 class="mb-1">Assign RFID to Student</h5>
                            <p class="text-muted small mb-0">Pick from available tags then search a student by ID or name.</p>
                        </div>
                        <div class="card-body">
                            <form id="assignRfidForm">
                                <div class="mb-3">
                                    <label class="form-label">Available RFID</label>
                                    <select class="form-select" id="assignableTagsSelect">
                                        <option value="">Select registered RFID</option>
                                        <?php foreach ($availableTags as $tag): ?>
                                            <option value="<?php echo $tag['id']; ?>">
                                                <?php echo htmlspecialchars($tag['tag_uid']); ?>
                                                <?php if (!empty($tag['label'])): ?>
                                                    (<?php echo htmlspecialchars($tag['label']); ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3 position-relative">
                                    <label class="form-label">Student</label>
                                    <input type="text" class="form-control" id="assignStudentSearch" placeholder="Search student ID or name" autocomplete="off">
                                    <div class="student-suggestions d-none" id="studentSuggestions"></div>
                                </div>

                                <div class="mb-3">
                                    <div class="alert alert-light border d-flex justify-content-between align-items-center" id="selectedStudentDisplay">
                                        <div>
                                            <strong>No student selected.</strong>
                                            <div class="small text-muted">Start typing above to search.</div>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="clearStudentSelection">
                                            Clear
                                        </button>
                                    </div>
                                </div>

                                <input type="hidden" id="selectedStudentId">
                                <button class="btn btn-primary w-100" type="submit">
                                    <i class="bi bi-person-check me-1"></i>
                                    Assign RFID
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div id="rfidAlert"></div>

            <div class="card shadow-sm">
                <div class="card-header bg-white border-0 d-flex flex-wrap justify-content-between align-items-center">
                    <h5 class="mb-0">Registered RFID Tags</h5>
                    <div class="text-muted small">Realtime data pulled from MySQL + Firebase sync.</div>
                </div>
                <div class="card-body border-bottom">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Search by Student Name or Student ID</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control" id="rfidSearchInput" placeholder="Type student name or ID to search...">
                                <button class="btn btn-outline-secondary" type="button" id="clearSearchBtn">
                                    <i class="bi bi-x"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="text-muted small">
                                <span id="searchResultCount"></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Tag UID</th>
                                <th>Student</th>
                                <th>Status</th>
                                <th>Last Seen</th>
                                <th>Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="rfidTagsTableBody">
                            <?php if (empty($rfidTags)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">No RFID tags registered yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rfidTags as $tag): ?>
                                    <tr data-tag-id="<?php echo $tag['id']; ?>">
                                        <td><?php echo $tag['id']; ?></td>
                                        <td>
                                            <span class="fw-semibold"><?php echo htmlspecialchars($tag['tag_uid']); ?></span>
                                        </td>
                                        <td>
                                            <?php if ($tag['student_id']): ?>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($tag['student_name'] ?? 'Student'); ?></div>
                                                <div class="small text-muted"><?php echo htmlspecialchars($tag['student_student_id'] ?? ''); ?></div>
                                            <?php else: ?>
                                                <span class="badge bg-light text-secondary">Unassigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="rfid-table-status">
                                            <?php if ($tag['status'] === 'assigned'): ?>
                                                <span class="badge bg-success">Assigned</span>
                                            <?php elseif ($tag['status'] === 'disabled'): ?>
                                                <span class="badge bg-danger">Disabled</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Available</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div><?php echo $tag['last_seen'] ? htmlspecialchars($tag['last_seen']) : '&mdash;'; ?></div>
                                            <div class="small text-muted"><?php echo htmlspecialchars($tag['last_source'] ?? ''); ?></div>
                                        </td>
                                        <td><?php echo htmlspecialchars($tag['created_at']); ?></td>
                                        <td class="text-end rfid-table-actions">
                                            <button class="btn btn-sm btn-outline-danger" data-action="block" data-tag-id="<?php echo $tag['id']; ?>">
                                                <i class="bi bi-ban me-1"></i>Block
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script>
        window.RfidManagerState = <?php echo json_encode([
            'tags' => $rfidTags,
            'stats' => $rfidStats,
            'available_tags' => $availableTags,
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/dashboard.js"></script>
    <script src="../assets/js/manage_rfid.js"></script>
</body>
</html>

