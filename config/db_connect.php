<?php
// Database configuration
$host = 'localhost';
$dbname = 'attendance_system';
$username = 'root';
$password = '';

try {
    // Create connection using mysqli
    $conn = new mysqli($host, $username, $password, $dbname);
    
    // Check connection
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    // Set character set to utf8mb4
    $conn->set_charset("utf8mb4");
    
} catch(Exception $e) {
    // Log the error and show a user-friendly message
    error_log("Database Connection Error: " . $e->getMessage());
    die("Sorry, there was a problem connecting to the database. Please try again later.");
}

// Return the connection
return $conn;
?> 
 
 
 
 
 