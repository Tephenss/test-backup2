<?php
require_once 'config/database.php';

// Change this to your desired new password
$new_password = 'admin123';

try {
    // Reset admin password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("UPDATE admins SET password = ? WHERE username = 'admin'");
    $result = $stmt->execute([$hashed_password]);
    
    if ($result) {
        echo "<h2>Password Reset Successful!</h2>";
        echo "<p>Username: <strong>admin</strong></p>";
        echo "<p>New Password: <strong>$new_password</strong></p>";
        echo "<p>You can now use these credentials in your mobile app.</p>";
    } else {
        echo "<h2>Password Reset Failed</h2>";
    }
    
} catch (PDOException $e) {
    echo "<h2>Database Error</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
}
?>