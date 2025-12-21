<?php
// Fetch announcements based on user type
$user_type = $_SESSION['user_type'] ?? 'student';
$target_audience_filter = ['all'];
if ($user_type === 'student') {
    $target_audience_filter[] = 'students';
} elseif ($user_type === 'teacher') {
    $target_audience_filter[] = 'teachers';
}

try {
    $now = date('Y-m-d H:i:s');
    $placeholders = implode(',', array_fill(0, count($target_audience_filter), '?'));
    $stmt = $pdo->prepare("
        SELECT a.*, 
               ad.full_name as created_by_name
        FROM announcements a
        LEFT JOIN admins ad ON a.created_by = ad.id
        WHERE a.is_active = 1
          AND a.target_audience IN ($placeholders)
          AND (a.expires_at IS NULL OR a.expires_at > ?)
        ORDER BY 
            CASE a.priority
                WHEN 'urgent' THEN 1
                WHEN 'high' THEN 2
                WHEN 'normal' THEN 3
                WHEN 'low' THEN 4
            END,
            a.created_at DESC
        LIMIT 5
    ");
    
    $params = array_merge($target_audience_filter, [$now]);
    $stmt->execute($params);
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $announcements = [];
    error_log("Error fetching announcements: " . $e->getMessage());
}
?>
<style>
.announcements-section {
    margin-bottom: 30px;
}
.announcement-card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: all 0.3s ease;
    margin-bottom: 15px;
    overflow: hidden;
    word-wrap: break-word;
    width: 100%;
}
.announcement-card .card-body,
.announcement-card .announcement-header,
.announcement-card .announcement-body,
.announcement-card .announcement-footer {
    overflow-wrap: break-word;
    word-wrap: break-word;
    max-width: 100%;
    width: 100%;
    box-sizing: border-box;
}
.announcement-card:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
    transform: translateY(-2px);
}
.announcement-priority-urgent {
    border-left: 4px solid #c62828;
}
.announcement-priority-high {
    border-left: 4px solid #f57c00;
}
.announcement-priority-normal {
    border-left: 4px solid #558b2f;
}
.announcement-priority-low {
    border-left: 4px solid #1976d2;
}
.announcement-header {
    padding: 18px 24px;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}
.announcement-title {
    font-size: 17px;
    font-weight: 600;
    color: #212121;
    margin: 0;
    flex: 1;
    line-height: 1.4;
    word-wrap: break-word;
    overflow-wrap: break-word;
    min-width: 0;
}
.announcement-badge {
    padding: 6px 12px;
    border-radius: 14px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    white-space: nowrap;
    flex-shrink: 0;
}
.badge-urgent { background: #ffebee; color: #c62828; }
.badge-high { background: #fff3e0; color: #f57c00; }
.badge-normal { background: #f1f8e9; color: #558b2f; }
.badge-low { background: #e3f2fd; color: #1976d2; }
.announcement-body {
    padding: 24px;
    min-height: 60px;
    width: 100%;
    box-sizing: border-box;
}
.announcement-content {
    color: #424242;
    line-height: 1.8;
    font-size: 15px;
    white-space: normal;
    word-wrap: break-word;
    word-break: break-word;
    overflow-wrap: break-word;
    max-width: 100%;
    display: block;
    text-align: left;
}
.announcement-content > div {
    word-wrap: break-word;
    overflow-wrap: break-word;
    max-width: 100%;
}
.announcement-footer {
    padding: 14px 24px;
    background: #f8f9fa;
    border-top: 1px solid #f0f0f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
    color: #757575;
    flex-wrap: wrap;
    gap: 8px;
}
.announcement-footer span {
    display: inline-flex;
    align-items: center;
    white-space: nowrap;
}
</style>

<div class="announcements-section">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">
            <i class="bi bi-megaphone-fill me-2" style="color: #667eea;"></i>Announcements
        </h5>
    </div>
    
    <?php if (empty($announcements)): ?>
        <div class="card announcement-card">
            <div class="card-body text-center py-5">
                <i class="bi bi-inbox" style="font-size: 48px; color: #ccc;"></i>
                <p class="text-muted mt-3 mb-0">No announcements at the moment</p>
                <small class="text-muted">Check back later for important updates</small>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($announcements as $ann): ?>
            <div class="card announcement-card announcement-priority-<?php echo $ann['priority']; ?>">
                <div class="announcement-header">
                    <h6 class="announcement-title"><?php echo htmlspecialchars($ann['title']); ?></h6>
                    <span class="announcement-badge badge-<?php echo $ann['priority']; ?>">
                        <?php echo ucfirst($ann['priority']); ?>
                    </span>
                </div>
                <div class="announcement-body">
                    <div class="announcement-content">
                        <?php 
                        // Get content
                        $content = trim($ann['content']);
                        
                        // Normalize line breaks
                        $content = str_replace(["\r\n", "\r"], "\n", $content);
                        
                        // Escape HTML
                        $content = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
                        
                        // Split by lines
                        $lines = explode("\n", $content);
                        
                        // Display each line properly
                        foreach ($lines as $index => $line) {
                            $line = trim($line);
                            if (empty($line) && $index > 0 && $index < count($lines) - 1) {
                                // Empty line = spacing
                                echo '<div style="height: 8px;"></div>';
                            } elseif (!empty($line)) {
                                // Non-empty line
                                echo '<div style="margin-bottom: 8px; line-height: 1.8;">' . $line . '</div>';
                            }
                        }
                        ?>
                    </div>
                </div>
                <div class="announcement-footer">
                    <span><i class="bi bi-person me-1"></i><?php echo htmlspecialchars($ann['created_by_name'] ?? 'Admin'); ?></span>
                    <span><i class="bi bi-calendar me-1"></i><?php echo date('M d, Y', strtotime($ann['created_at'])); ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

