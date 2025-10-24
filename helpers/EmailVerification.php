<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/email.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class EmailVerification {
    private $pdo;
    private $mailer;
    private $lastError;

    public function __construct() {
        global $pdo;
        $this->pdo = $pdo;
        
        // Check if constants are defined
        if (!defined('SMTP_HOST') || !defined('SMTP_USERNAME') || !defined('SMTP_PASSWORD')) {
            error_log("Email constants not defined");
            $this->lastError = "Email configuration not loaded properly.";
            throw new Exception("Email configuration not loaded properly.");
        }
        
        try {
            // Set timezone
            date_default_timezone_set('Asia/Manila');
            
            // Initialize PHPMailer
            $this->mailer = new PHPMailer(true);
            $this->mailer->isSMTP();
            $this->mailer->Host = SMTP_HOST;
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = SMTP_USERNAME;
            $this->mailer->Password = SMTP_PASSWORD;
            // Try SMTPS (port 465) if STARTTLS fails
            $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $this->mailer->Port = 465;
            $this->mailer->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            
            // Fix SSL certificate verification issues for Windows/XAMPP
            $this->mailer->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];
            
            // Enable debugging for better error tracking
            $this->mailer->SMTPDebug = 0; // Set to 2 for detailed debugging
            $this->mailer->Debugoutput = function($str, $level) {
                error_log("SMTP Debug: $str");
            };
            
            // Set timeout values
            $this->mailer->Timeout = 30;
            $this->mailer->SMTPKeepAlive = true;
            
            error_log("PHPMailer initialized successfully with host: " . SMTP_HOST);
        } catch (Exception $e) {
            error_log("PHPMailer initialization error: " . $e->getMessage());
            $this->lastError = "Email system initialization failed: " . $e->getMessage();
            throw $e;
        }
    }

    public function getLastError() {
        return $this->lastError;
    }

    public function generateCode($userId, $userType, $email, $isResend = false, $emailType = 'verification') {
        try {
            error_log("=== EmailVerification::generateCode START ===");
            error_log("Parameters: userId=$userId, userType=$userType, email=$email, isResend=" . ($isResend ? 'true' : 'false'));
            
            // Get user's name
            $table = match($userType) {
                'teacher' => 'teachers',
                'admin' => 'admins',
                default => 'students'
            };
            
            error_log("Using table: $table");
            
            // For students, use the new name fields
            if ($userType === 'student') {
                $stmt = $this->pdo->prepare("SELECT first_name, last_name FROM {$table} WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                $fullName = $user ? $user['first_name'] . ' ' . $user['last_name'] : 'User';
            } else {
                // For teachers and admins, use full_name
                $stmt = $this->pdo->prepare("SELECT full_name FROM {$table} WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                $fullName = $user ? $user['full_name'] : 'User';
            }
            
            error_log("Found user name: $fullName");

            // Generate a random 6-digit code
            $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            error_log("Generated verification code: $code");

            // Check if transaction is already active
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
            }

            try {
                // Delete any existing codes for this user - ensure user_id is treated as string
                $stmt = $this->pdo->prepare("DELETE FROM verification_codes WHERE CAST(user_id AS CHAR) = ? AND user_type = ?");
                $stmt->execute([(string)$userId, $userType]);

                // Insert new code with 30-minute expiration - ensure user_id is stored as string
                $stmt = $this->pdo->prepare("
                    INSERT INTO verification_codes 
                    (user_id, user_type, code, expires_at) 
                    VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))
                ");
                $stmt->execute([(string)$userId, $userType, $code]);
                
                // Verify the code was inserted
                $codeId = $this->pdo->lastInsertId();
                error_log("Inserted verification code with ID: $codeId");

                // Commit transaction
                if ($this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
                error_log("Saved verification code to database");

                // Send email
                if ($this->sendVerificationEmail($email, $code, $fullName, $isResend, $emailType)) {
                    error_log("Email sent successfully");
                    return true;
                } else {
                    error_log("Failed to send email, rolling back code generation");
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    $this->lastError = "Failed to send verification email. Please try again.";
                    return false;
                }
            } catch (Exception $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                error_log("Database error in generateCode: " . $e->getMessage());
                $this->lastError = "System error while generating code. Please try again.";
                return false;
            }
        } catch (Exception $e) {
            error_log("Error in generateCode: " . $e->getMessage());
            $this->lastError = "System error while generating code. Please try again.";
            return false;
        }
    }

    private function sendVerificationEmail($to, $code, $name, $isResend = false, $emailType = 'verification') {
        try {
            error_log("Attempting to send email to: $to (isResend: " . ($isResend ? 'true' : 'false') . ")");
            
            // Validate email address
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                error_log("Invalid email address: $to");
                $this->lastError = "Invalid email address format.";
                return false;
            }
            
            // Clear all recipients and attachments
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            
            // Set recipient
            $this->mailer->addAddress($to, $name);
            
            // Set email content
            $this->mailer->isHTML(true);
            
            // Different subject based on email type and resend status
            if ($emailType === 'recovery') {
                $subject = $isResend ? 
                    'New Account Recovery Code - iAttendance' : 
                    'Account Recovery Verification Code - iAttendance';
            } else {
                $subject = $isResend ? 
                    'New Verification Code - iAttendance' : 
                    'Your Verification Code - iAttendance';
            }
            
            $this->mailer->Subject = $subject;
            
            // Get current timestamp for display
            $currentTime = date('F j, Y \a\t g:i A');
            
            // Email body with enhanced indicators
            $resendIndicator = $isResend ? 
                '<div style="background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px; padding: 15px; margin: 20px 0; text-align: center;">
                    <h3 style="color: #856404; margin: 0;">RESEND CODE</h3>
                    <p style="color: #856404; margin: 5px 0 0 0; font-size: 14px;">This is a new verification code sent at your request</p>
                </div>' : '';
            
            // Different message based on email type
            if ($emailType === 'recovery') {
                $message = $isResend ? 
                    "Here is your new account recovery code for iAttendance:" :
                    "Your account recovery verification code for iAttendance is:";
            } else {
                $message = $isResend ? 
                    "Here is your new verification code for iAttendance:" :
                    "Your verification code for iAttendance is:";
            }
            
            $htmlBody = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #ffffff;'>
                    <div style='background: linear-gradient(135deg, #4CAF50, #45a049); padding: 20px; text-align: center; color: white;'>
                        <h1 style='margin: 0; font-size: 24px;'>iAttendance</h1>
                    </div>
                    
                    <div style='padding: 30px 20px;'>
                        <h2 style='color: #333; margin-bottom: 20px;'>Hello {$name},</h2>
                        
                        {$resendIndicator}
                        
                        <p style='color: #555; font-size: 16px; line-height: 1.5;'>{$message}</p>
                        
                        <div style='background: linear-gradient(135deg, #f8f9fa, #e9ecef); border: 2px dashed #4CAF50; padding: 25px; text-align: center; font-size: 36px; letter-spacing: 8px; margin: 25px 0; border-radius: 10px;'>
                            <strong style='color: #4CAF50; font-family: monospace;'>{$code}</strong>
                        </div>
                        
                        <div style='background-color: #e8f5e8; border-left: 4px solid #4CAF50; padding: 15px; margin: 20px 0;'>
                            <p style='margin: 0; color: #2e7d32; font-weight: bold;'>Code Expires in 30 minutes</p>
                            <p style='margin: 5px 0 0 0; color: #666; font-size: 14px;'>Sent on: {$currentTime}</p>
                        </div>
                        
                        <div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                            <p style='margin: 0; color: #666; font-size: 14px;'>
                                <strong>Security Notice:</strong> If you did not request this code, please ignore this email and consider changing your password.
                            </p>
                        </div>
                        
                        <hr style='border: none; border-top: 1px solid #eee; margin: 30px 0;'>
                        
                        <p style='font-size: 12px; color: #999; text-align: center; margin: 0;'>
                            This is an automated message from iAttendance.<br>
                            Please do not reply to this email.
                        </p>
                    </div>
                </div>
            ";
            
            $textBody = "iAttendance " . ($emailType === 'recovery' ? 'Account Recovery' : 'Verification') . "\n\n" .
                       "Hello {$name},\n\n" .
                       ($isResend ? "RESEND CODE - " : "") .
                       "{$message} {$code}\n\n" .
                       "Code Expires in 30 minutes\n" .
                       "Sent on: {$currentTime}\n\n" .
                       "Security Notice: If you did not request this code, please ignore this email.\n\n" .
                       "This is an automated message from iAttendance.\n" .
                       "Please do not reply to this email.";
            
            $this->mailer->Body = $htmlBody;
            $this->mailer->AltBody = $textBody;
            
            // Test SMTP connection before sending
            if (!$this->mailer->smtpConnect()) {
                error_log("SMTP connection failed");
                $this->lastError = "Unable to connect to email server. Please try again later.";
                return false;
            }
            
            // Send email
            $result = $this->mailer->send();
            if ($result) {
                error_log("Email sent successfully to: $to (isResend: " . ($isResend ? 'true' : 'false') . ")");
                return true;
            } else {
                error_log("Email sending failed: " . $this->mailer->ErrorInfo);
                $this->lastError = "Failed to send email: " . $this->mailer->ErrorInfo;
                return false;
            }
            
        } catch (Exception $e) {
            error_log("Failed to send verification email: " . $e->getMessage());
            error_log("Error trace: " . $e->getTraceAsString());
            $this->lastError = "Email sending failed: " . $e->getMessage();
            return false;
        }
    }

    public function verifyCode($userId, $userType, $code) {
        try {
            error_log("Verifying code for user: $userId, type: $userType, code: $code");
            
            // Trim the code and ensure it's 6 digits
            $code = trim($code);
            if (!preg_match('/^\d{6}$/', $code)) {
                error_log("Invalid code format");
                $this->lastError = "Invalid code format. Please enter a 6-digit number.";
                return false;
            }

            // Get the code details - ensure user_id is treated as string for consistency
            $stmt = $this->pdo->prepare("
                SELECT * FROM verification_codes 
                WHERE CAST(user_id AS CHAR) = ? 
                AND user_type = ? 
                AND code = ? 
                AND is_used = 0 
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmt->execute([(string)$userId, $userType, $code]);
            $verification = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$verification) {
                error_log("No verification code found for user_id=$userId, user_type=$userType, code=$code");
                
                // Debug: Check what codes exist for this user
                $debug_stmt = $this->pdo->prepare("SELECT * FROM verification_codes WHERE user_id = ? AND user_type = ? ORDER BY created_at DESC LIMIT 5");
                $debug_stmt->execute([$userId, $userType]);
                $debug_codes = $debug_stmt->fetchAll(PDO::FETCH_ASSOC);
                error_log("Available codes for user: " . json_encode($debug_codes));
                
                $this->lastError = "Invalid verification code. Please check and try again.";
                return false;
            }

            // Check if code is expired
            $expiresAt = strtotime($verification['expires_at']);
            if ($expiresAt < time()) {
                error_log("Code has expired");
                $this->lastError = "This verification code has expired. Please request a new one.";
                return false;
            }

            // Mark code as used
            $stmt = $this->pdo->prepare("UPDATE verification_codes SET is_used = 1 WHERE id = ?");
            $stmt->execute([$verification['id']]);
            
            error_log("Code verified successfully");
            return true;
            
        } catch (Exception $e) {
            error_log("Error in verifyCode: " . $e->getMessage());
            $this->lastError = "System error while verifying code. Please try again.";
            return false;
        }
    }

    public function sendEmail($to, $subject, $message) {
        try {
            error_log("Attempting to send email to: $to with subject: $subject");
            
            // Validate email address
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                error_log("Invalid email address: $to");
                $this->lastError = "Invalid email address format.";
                return false;
            }
            
            // Clear all recipients and attachments
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            
            // Set recipient
            $this->mailer->addAddress($to);
            
            // Set email content
            $this->mailer->isHTML(false);
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $message;
            
            // Test SMTP connection before sending
            if (!$this->mailer->smtpConnect()) {
                error_log("SMTP connection failed");
                $this->lastError = "Unable to connect to email server. Please try again later.";
                return false;
            }
            
            // Send email
            $result = $this->mailer->send();
            if ($result) {
                error_log("Email sent successfully to: $to");
                return true;
            } else {
                error_log("Email sending failed: " . $this->mailer->ErrorInfo);
                $this->lastError = "Failed to send email: " . $this->mailer->ErrorInfo;
                return false;
            }
            
        } catch (Exception $e) {
            error_log("Failed to send email: " . $e->getMessage());
            error_log("Error trace: " . $e->getTraceAsString());
            $this->lastError = "Email sending failed: " . $e->getMessage();
            return false;
        }
    }
}
