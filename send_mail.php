<?php
// Start output buffering to prevent PHP warnings/errors from contaminating the JSON output.
ob_start();

// Set headers to return JSON response
header('Content-Type: application/json');

// --- Configuration ---
// REPLACE THESE WITH YOUR ACTUAL MAILTRAP CREDENTIALS

// --- THIS IS THE FIX ---
// Updated the hostname to the new Mailtrap Sandbox address.
// This was the likely cause of the "AUTH LOGIN failed" error.
$smtp_host_full = 'sandbox.smtp.mailtrap.io'; // Using the correct NEW hostname
// --- END FIX ---

$smtp_port = 2525; // Port 2525 is still the most reliable for this connection method
$smtp_user = '20ecde392ffecd'; // <--- VERIFY THIS AGAIN
$smtp_pass = 'e405c40b657af8'; // <--- VERIFY THIS AGAIN
$sender_email = 'query@yourownshop.com'; 
$sender_name = 'YOUROWNSHOP Support';
// --- End Configuration ---

// Define a function to clean the buffer and output the final JSON
function sendJsonResponse($data) {
    ob_end_clean(); 
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['success' => false, 'message' => 'Invalid request method.']);
}

if (!isset($_POST['email']) || empty($_POST['email'])) {
    sendJsonResponse(['success' => false, 'message' => 'Email address is missing.']);
}

$recipient_email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);

if (!filter_var($recipient_email, FILTER_VALIDATE_EMAIL)) {
    sendJsonResponse(['success' => false, 'message' => 'Invalid email format provided.']);
}

// --- Email Content ---
$subject = "Query Confirmation from YOUROWNSHOP";
$message_body = "Dear Customer,\n\n";
$message_body .= "Thank you for your interest, your query has been noted by YOUROWNSHOP.\n";
$message_body .= "It will be reviewed, and a solution will be provided within 24hrs.\n\n";
$message_body .= "Sincerely,\n";
$message_body .= "The YOUROWNSHOP Team";

// --- Direct SMTP Sending Function (PHPMailer-style logic) ---
function sendDirectSMTP($host, $port, $user, $pass, $from_email, $from_name, $to_email, $subject, $body) {
    
    $socket = fsockopen($host, $port, $errno, $errstr, 10);
    
    if (!$socket) {
        return "Failed to connect to SMTP host ($host:$port): $errstr ($errno)";
    }

    stream_set_timeout($socket, 10);

    // --- THIS IS THE FIX ---
    // This function now correctly reads multi-line server responses (like the one after EHLO)
    // by checking the 4th character for a space ' ' (final line) or a dash '-' (more lines coming).
    $response = function($expected_code = null) use ($socket) {
        $data = '';
        $line = '';
        while (($line = fgets($socket, 512)) !== false) {
            $data .= $line; // Log all lines for debugging
            // If the 4th character is a space, this is the final line of the response.
            if (substr($line, 3, 1) == ' ') {
                break; // Stop reading
            }
        }
        
        // Check if the final line contains the expected code
        if ($expected_code && !strstr($line, $expected_code)) {
            return "SMTP response error: Expected $expected_code on final line, got '$line' (Full response: $data)";
        }
        return true;
    };
    // --- END FIX ---
    
    // Start communication
    if ($response('220') !== true) return "Server handshake failed."; 
    
    // HELLO (EHLO)
    fputs($socket, "EHLO localhost\r\n");
    // This will now correctly read all 250-xxx lines and wait for the final 250 xxx line
    if ($response('250') !== true) return "EHLO failed."; 
    
    // AUTH LOGIN
    // Now that the EHLO response is fully consumed, this command will get the correct response
    fputs($socket, "AUTH LOGIN\r\n");
    $auth_response = $response('334'); // Expect '334' (Send Username)
    if ($auth_response !== true) {
        return "AUTH LOGIN failed. " . (is_string($auth_response) ? $auth_response : "(Server did not request username)");
    }
    
    // Send Username (Base64 encoded)
    fputs($socket, base64_encode($user) . "\r\n");
    $user_response = $response('334'); // Expect '334' (Send Password)
    if ($user_response !== true) {
        return "Username failed. " . (is_string($user_response) ? $user_response : "(Server did not request password)");
    }
    
    // Send Password (Base64 encoded)
    fputs($socket, base64_encode($pass) . "\r\n");
    $pass_response = $response('235'); // Expect '235' (Auth Success)
    if ($pass_response !== true) {
        return "AUTH LOGIN failed. " . (is_string($pass_response) ? $pass_response : "(Check credentials for typos or trailing spaces.)");
    }
    
    // MAIL FROM
    fputs($socket, "MAIL FROM:<$from_email>\r\n");
    if ($response('250') !== true) return "MAIL FROM failed.";
    
    // RCPT TO
    fputs($socket, "RCPT TO:<$to_email>\r\n");
    if ($response('250') !== true) return "RCPT TO failed.";
    
    // DATA
    fputs($socket, "DATA\r\n");
    if ($response('354') !== true) return "DATA command failed.";

    // Headers
    fputs($socket, "To: $to_email\r\n");
    fputs($socket, "From: $from_name <$from_email>\r\n");
    fputs($socket, "Subject: $subject\r\n");
    fputs($socket, "MIME-Version: 1.0\r\n");
    fputs($socket, "Content-Type: text/plain; charset=UTF-8\r\n");
    fputs($socket, "\r\n"); // End of headers
    
    // Body
    fputs($socket, $body . "\r\n");
    fputs($socket, ".\r\n"); // End of data
    
    // Final response
    $success_response = $response('250');
    if ($success_response !== true) return "Delivery failure: " . $success_response;
    
    // QUIT
    fputs($socket, "QUIT\r\n");
    fclose($socket);
    
    return true; // Success!
}

// --- Execute Direct Send ---
$mail_result = sendDirectSMTP(
    $smtp_host_full, $smtp_port, $smtp_user, $smtp_pass, 
    $sender_email, $sender_name, $recipient_email, $subject, $message_body
);

if ($mail_result === true) {
    // Typo fix: 'success's' changed to 'success'
    sendJsonResponse(['success' => true, 'message' => 'Confirmation email successfully sent via Direct SMTP.']);
} else {
    sendJsonResponse(['success' => false, 'message' => "SMTP Error: $mail_result"]);
}

ob_end_clean();
?>