<?php
// Get admin data if not already set
if (!isset($admin)) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $admin = $stmt->fetch();
    } catch(PDOException $e) {
        error_log("Error fetching admin data: " . $e->getMessage());
        $admin = null;
    }
}
?>

<div class="topbar">
    <button class="toggle-sidebar">
        <i class="bi bi-list"></i>
    </button>

    <div class="user-info dropdown">
        <a href="#" class="user-dropdown-toggle" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <div class="user-avatar">AD</div>
            <span class="user-name"><?php echo htmlspecialchars($admin['full_name'] ?? 'System Administrator'); ?></span>
        </a>
        <ul class="dropdown-menu user-dropdown" aria-labelledby="userDropdown">
            <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i> Profile</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="../auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
        </ul>
    </div>
</div> 