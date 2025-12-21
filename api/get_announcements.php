<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

// Get user type from session or query parameter (for app access)
$user_type = $_GET['user_type'] ?? null;
if (!$user_type && isset($_SESSION['user_type'])) {
    $user_type = $_SESSION['user_type'];
}

// Determine target audience based on user type
$target_audience_filter = ['all']; // Everyone can see 'all' announcements
if ($user_type === 'student') {
    $target_audience_filter[] = 'students';
} elseif ($user_type === 'teacher') {
    $target_audience_filter[] = 'teachers';
}

try {
    $now = date('Y-m-d H:i:s');
    
    // Fetch active announcements that haven't expired
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
        LIMIT 10
    ");
    
    $params = array_merge($target_audience_filter, [$now]);
    $stmt->execute($params);
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format dates for JSON
    foreach ($announcements as &$ann) {
        $ann['created_at'] = date('Y-m-d H:i:s', strtotime($ann['created_at']));
        $ann['expires_at'] = $ann['expires_at'] ? date('Y-m-d H:i:s', strtotime($ann['expires_at'])) : null;
        $ann['content'] = nl2br(htmlspecialchars($ann['content']));
    }
    
    echo json_encode([
        'success' => true,
        'announcements' => $announcements
    ], JSON_UNESCAPED_UNICODE);
    
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching announcements: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

