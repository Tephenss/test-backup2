<?php
// Database configuration
$host = 'localhost';
$dbname = 'attendance_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    // Set the PDO error mode to exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Set default fetch mode to associative array
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Set character set to utf8mb4
    $pdo->exec("SET NAMES utf8mb4");
} catch(PDOException $e) {
    // Log the error and show a user-friendly message
    error_log("Database Connection Error: " . $e->getMessage());
    die("Sorry, there was a problem connecting to the database. Please try again later.");
}
?> 