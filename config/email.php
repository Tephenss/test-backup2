<?php
// Prevent multiple inclusions - use if/else instead of return
if (!defined('EMAIL_CONFIG_LOADED')) {
    // Email Configuration
    define('SMTP_HOST', 'smtp.gmail.com');
    define('SMTP_PORT', 587);
    define('SMTP_USERNAME', 'iattendancemanagement@gmail.com'); // Replace with your Gmail address
    define('SMTP_PASSWORD', 'vlxhslqwkzrgwuhj'); // TODO: Replace this with your 16-character Gmail App Password from Google Account settings
    define('SMTP_FROM_EMAIL', 'iattendancemanagement@gmail.com'); // Replace with your Gmail address
    define('SMTP_FROM_NAME', 'iAttendance Management System');
    
    // Mark as loaded
    define('EMAIL_CONFIG_LOADED', true);
}
?> 