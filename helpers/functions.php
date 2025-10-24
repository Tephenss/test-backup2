<?php
/**
 * Common helper functions for the application
 */

/**
 * Sanitize input data
 * @param string $data The input data to sanitize
 * @return string The sanitized data
 */
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

/**
 * Generate a random string
 * @param int $length The length of the random string
 * @return string The generated random string
 */
function generate_random_string($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $random_string = '';
    for ($i = 0; $i < $length; $i++) {
        $random_string .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $random_string;
}

/**
 * Format date to a readable format
 * @param string $date The date to format
 * @param string $format The desired format (default: 'F j, Y')
 * @return string The formatted date
 */
function format_date($date, $format = 'F j, Y') {
    return date($format, strtotime($date));
}

/**
 * Check if a user is logged in
 * @return bool True if user is logged in, false otherwise
 */
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

/**
 * Check if a user is an admin
 * @return bool True if user is an admin, false otherwise
 */
function is_admin() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin';
}

/**
 * Check if a user is a teacher
 * @return bool True if user is a teacher, false otherwise
 */
function is_teacher() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'teacher';
}

/**
 * Check if a user is a student
 * @return bool True if user is a student, false otherwise
 */
function is_student() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student';
}

/**
 * Redirect to a specific URL
 * @param string $url The URL to redirect to
 */
function redirect($url) {
    header("Location: $url");
    exit();
}

/**
 * Set a flash message in the session
 * @param string $type The type of message (success, error, warning, info)
 * @param string $message The message to display
 */
function set_flash_message($type, $message) {
    $_SESSION['flash_message'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Get and clear the flash message from the session
 * @return array|null The flash message array or null if no message exists
 */
function get_flash_message() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $message;
    }
    return null;
}

/**
 * Display a flash message if one exists
 */
function display_flash_message() {
    $message = get_flash_message();
    if ($message) {
        $type = $message['type'];
        $text = $message['message'];
        echo "<div class='alert alert-{$type} alert-dismissible fade show' role='alert'>
                {$text}
                <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
              </div>";
    }
}

/**
 * Validate email format
 * @param string $email The email to validate
 * @return bool True if email is valid, false otherwise
 */
function is_valid_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Validate phone number format
 * @param string $phone The phone number to validate
 * @return bool True if phone number is valid, false otherwise
 */
function is_valid_phone($phone) {
    return preg_match('/^[0-9]{11}$/', $phone);
}

/**
 * Generate a student ID
 * @param string $year The year
 * @param string $course The course code
 * @param int $sequence The sequence number
 * @return string The generated student ID
 */
function generate_student_id($year, $course, $sequence) {
    return $year . $course . str_pad($sequence, 4, '0', STR_PAD_LEFT);
}

/**
 * Format time to 12-hour format
 * @param string $time The time to format
 * @return string The formatted time
 */
function format_time($time) {
    return date('h:i A', strtotime($time));
}

/**
 * Check if a time slot conflicts with existing schedules
 * @param PDO $pdo The PDO instance
 * @param int $teacher_id The teacher ID
 * @param string $day The day of the week
 * @param string $start_time The start time
 * @param string $end_time The end time
 * @param int $exclude_id The ID to exclude from the check (for updates)
 * @return bool True if there's a conflict, false otherwise
 */
function has_schedule_conflict($pdo, $teacher_id, $day, $start_time, $end_time, $exclude_id = null) {
    $sql = "SELECT COUNT(*) FROM schedules 
            WHERE teacher_id = ? AND day = ? 
            AND ((start_time <= ? AND end_time > ?) 
            OR (start_time < ? AND end_time >= ?) 
            OR (start_time >= ? AND end_time <= ?))";
    
    $params = [$teacher_id, $day, $end_time, $start_time, $end_time, $start_time, $start_time, $end_time];
    
    if ($exclude_id) {
        $sql .= " AND id != ?";
        $params[] = $exclude_id;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() > 0;
} 