<?php
session_start();

// Store the user type before destroying session
$user_type = $_SESSION['user_type'] ?? '';

// Destroy the session
session_destroy();

// Set logout success message
session_start();
$_SESSION['logout_success'] = "You have been successfully logged out.";

// Redirect based on user type
if ($user_type === 'admin') {
    header("Location: ../index.php");
} else {
    header("Location: ../index.php");
}
exit();
?>