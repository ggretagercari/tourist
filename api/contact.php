<?php
/**
 * Contact Form API Endpoint
 * 
 * Handles contact form submissions and stores them in the database.
 * Includes validation, spam protection, and email notifications.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once '../config/database.php';

try {
    // Get and validate input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON data']);
        exit;
    }
    
    // Validate required fields
    $requiredFields = ['name', 'email', 'subject', 'message'];
    $errors = [];
    
    foreach ($requiredFields as $field) {
        if (empty($input[$field])) {
            $errors[] = ucfirst($field) . ' is required';
        }
    }
    
    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['error' => 'Validation failed', 'details' => $errors]);
        exit;
    }
    
    // Sanitize and validate input data
    $name = sanitizeInput($input['name']);
    $email = filter_var($input['email'], FILTER_VALIDATE_EMAIL);
    $phone = sanitizeInput($input['phone'] ?? '');
    $subject = sanitizeInput($input['subject']);
    $message = sanitizeInput($input['message']);
    $inquiry_type = sanitizeInput($input['inquiry_type'] ?? 'general');
    $country = sanitizeInput($input['country'] ?? '');
    $travel_dates = sanitizeInput($input['travel_dates'] ?? '');
    
    // Additional validation
    if (!$email) {
        http_response_code(400);
        echo json_encode(['error' => 'Valid email address is required']);
        exit;
    }
    
    if (strlen($name) < 2 || strlen($name) > 100) {
        http_response_code(400);
        echo json_encode(['error' => 'Name must be between 2 and 100 characters']);
        exit;
    }
    
    if (strlen($subject) < 5 || strlen($subject) > 200) {
        http_response_code(400);
        echo json_encode(['error' => 'Subject must be between 5 and 200 characters']);
        exit;
    }
    
    if (strlen($message) < 10 || strlen($message) > 2000) {
        http_response_code(400);
        echo json_encode(['error' => 'Message must be between 10 and 2000 characters']);
        exit;
    }
    
    // Basic spam protection
    if (isSpamContent($message) || isSpamContent($subject)) {
        http_response_code(429);
        echo json_encode(['error' => 'Message appears to be spam']);
        exit;
    }
    
    // Rate limiting check
    $clientIp = getClientIP();
    if (isRateLimited($clientIp)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many requests. Please try again later.']);
        exit;
    }
    
    $db = getDatabase();
    
    // Insert contact form submission
    $sql = "
        INSERT INTO contact_submissions (
            name, email, phone, subject, message, 
            inquiry_type, country, travel_dates, 
            ip_address, user_agent, status, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', NOW()
        )
    ";
    
    $params = [
        $name,
        $email,
        $phone,
        $subject,
        $message,
        $inquiry_type,
        $country,
        $travel_dates,
        $clientIp,
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ];
    
    $submissionId = dbExecute($sql, $params);
    
    if (!$submissionId) {
        throw new Exception('Failed to save contact submission');
    }
    
    // Send confirmation email to user
    $emailSent = sendConfirmationEmail($email, $name, $subject, $submissionId);
    
    // Send notification to admin
    sendAdminNotification($name, $email, $subject, $message, $inquiry_type, $submissionId);
    
    // Log the submission
    logContactSubmission($submissionId, $clientIp, $inquiry_type);
    
    // Prepare response
    $response = [
        'success' => true,
        'message' => 'Thank you for your message. We will get back to you within 24 hours.',
        'submission_id' => $submissionId,
        'email_sent' => $emailSent
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Contact form error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => 'Unable to process your request at this time. Please try again later.'
    ]);
}

/**
 * Sanitize input data
 */
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Get client IP address
 */
function getClientIP() {
    $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    
    foreach ($ipKeys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Check if content appears to be spam
 */
function isSpamContent($content) {
    $spamPatterns = [
        '/\b(viagra|cialis|casino|poker|loan|mortgage|pharmacy)\b/i',
        '/\b(click here|free money|guaranteed|limited time)\b/i',
        '/[^\w\s]{5,}/', // Too many special characters
        '/(http|https):\/\/[^\s]+[^\s]{3,}/', // Multiple URLs
    ];
    
    foreach ($spamPatterns as $pattern) {
        if (preg_match($pattern, $content)) {
            return true;
        }
    }
    
    // Check for excessive repetition
    $words = str_word_count($content, 1);
    $wordCounts = array_count_values(array_map('strtolower', $words));
    
    foreach ($wordCounts as $count) {
        if ($count > 5) { // Same word repeated more than 5 times
            return true;
        }
    }
    
    return false;
}

/**
 * Check rate limiting for IP address
 */
function isRateLimited($ip) {
    try {
        $db = getDatabase();
        
        // Check submissions in the last hour
        $sql = "
            SELECT COUNT(*) as submission_count 
            FROM contact_submissions 
            WHERE ip_address = ? 
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ";
        
        $result = dbQuerySingle($sql, [$ip]);
        
        // Allow max 5 submissions per hour per IP
        return $result && $result['submission_count'] >= 5;
        
    } catch (Exception $e) {
        error_log("Rate limiting check failed: " . $e->getMessage());
        return false; // Don't block on error
    }
}

/**
 * Send confirmation email to user
 */
function sendConfirmationEmail($email, $name, $subject, $submissionId) {
    try {
        $to = $email;
        $emailSubject = "Thank you for contacting Balkans Tourism - Ref: #" . $submissionId;
        
        $message = "
        <html>
        <head>
            <title>Thank you for your inquiry</title>
        </head>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #2563eb;'>Thank you for your inquiry!</h2>
                
                <p>Dear {$name},</p>
                
                <p>We have received your message regarding: <strong>{$subject}</strong></p>
                
                <p>Reference Number: <strong>#{$submissionId}</strong></p>
                
                <p>Our tourism experts will review your inquiry and respond within 24 hours. For urgent matters, please call our helpline at +383 44 123 456.</p>
                
                <div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                    <h3 style='margin-top: 0; color: #2563eb;'>What's Next?</h3>
                    <ul>
                        <li>Our team will review your inquiry</li>
                        <li>You'll receive a personalized response within 24 hours</li>
                        <li>For immediate assistance, call our 24/7 helpline</li>
                    </ul>
                </div>
                
                <p>Thank you for choosing Balkans Tourism for your travel needs!</p>
                
                <div style='border-top: 1px solid #eee; padding-top: 20px; margin-top: 30px;'>
                    <p style='margin: 0; color: #666; font-size: 14px;'>
                        Balkans Tourism Helpline<br>
                        Email: info@balkanstourism.com<br>
                        Phone: +383 44 123 456<br>
                        Emergency: 112 (Kosovo) | 112 (Albania)
                    </p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: Balkans Tourism <noreply@balkanstourism.com>" . "\r\n";
        $headers .= "Reply-To: info@balkanstourism.com" . "\r\n";
        
        return mail($to, $emailSubject, $message, $headers);
        
    } catch (Exception $e) {
        error_log("Failed to send confirmation email: " . $e->getMessage());
        return false;
    }
}

/**
 * Send notification to admin
 */
function sendAdminNotification($name, $email, $subject, $message, $inquiryType, $submissionId) {
    try {
        $adminEmail = getenv('ADMIN_EMAIL') ?: 'admin@balkanstourism.com';
        $emailSubject = "New Contact Form Submission - #{$submissionId}";
        
        $emailMessage = "
        <html>
        <head>
            <title>New Contact Form Submission</title>
        </head>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #dc2626;'>New Contact Form Submission</h2>
                
                <table style='width: 100%; border-collapse: collapse; margin-bottom: 20px;'>
                    <tr style='background-color: #f8f9fa;'>
                        <td style='padding: 10px; border: 1px solid #ddd; font-weight: bold;'>Submission ID:</td>
                        <td style='padding: 10px; border: 1px solid #ddd;'>#{$submissionId}</td>
                    </tr>
                    <tr>
                        <td style='padding: 10px; border: 1px solid #ddd; font-weight: bold;'>Name:</td>
                        <td style='padding: 10px; border: 1px solid #ddd;'>{$name}</td>
                    </tr>
                    <tr style='background-color: #f8f9fa;'>
                        <td style='padding: 10px; border: 1px solid #ddd; font-weight: bold;'>Email:</td>
                        <td style='padding: 10px; border: 1px solid #ddd;'>{$email}</td>
                    </tr>
                    <tr>
                        <td style='padding: 10px; border: 1px solid #ddd; font-weight: bold;'>Inquiry Type:</td>
                        <td style='padding: 10px; border: 1px solid #ddd;'>" . ucfirst($inquiryType) . "</td>
                    </tr>
                    <tr style='background-color: #f8f9fa;'>
                        <td style='padding: 10px; border: 1px solid #ddd; font-weight: bold;'>Subject:</td>
                        <td style='padding: 10px; border: 1px solid #ddd;'>{$subject}</td>
                    </tr>
                </table>
                
                <div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px;'>
                    <h3 style='margin-top: 0;'>Message:</h3>
                    <p style='white-space: pre-wrap;'>{$message}</p>
                </div>
                
                <div style='margin-top: 20px; padding: 15px; background-color: #fef3cd; border-radius: 5px;'>
                    <p style='margin: 0; font-weight: bold; color: #856404;'>
                        Please respond to this inquiry within 24 hours to maintain our service standards.
                    </p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: Balkans Tourism System <system@balkanstourism.com>" . "\r\n";
        $headers .= "Reply-To: {$email}" . "\r\n";
        
        return mail($adminEmail, $emailSubject, $emailMessage, $headers);
        
    } catch (Exception $e) {
        error_log("Failed to send admin notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Log contact submission for analytics
 */
function logContactSubmission($submissionId, $ip, $inquiryType) {
    try {
        $logEntry = date('Y-m-d H:i:s') . " - Contact submission #{$submissionId} from {$ip} (Type: {$inquiryType})\n";
        file_put_contents('contact_submissions.log', $logEntry, FILE_APPEND | LOCK_EX);
    } catch (Exception $e) {
        error_log("Failed to log contact submission: " . $e->getMessage());
    }
}
?>
