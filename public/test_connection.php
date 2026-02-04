<?php
// test_connection.php

// Set a clear content type
header('Content-Type: text/plain');

echo "=========================================\n";
echo "PHP Outbound Connection Test\n";
echo "=========================================\n\n";

// --- Test 1: Check for cURL extension ---
if (function_exists('curl_version')) {
    $curl_version = curl_version();
    echo "[SUCCESS] cURL extension is installed.\n";
    echo "          Version: " . $curl_version['version'] . "\n";
    echo "          SSL Version: " . $curl_version['ssl_version'] . "\n\n";
} else {
    echo "[ERROR] cURL extension is NOT installed. This is a major problem.\n\n";
    // Stop the script if cURL is missing, as the next test will fail.
    exit;
}

// --- Test 2: Attempt a real outbound connection using cURL ---
$target_host = 'google.com';
$target_port = 443; // Standard HTTPS port

echo "Attempting to connect to: " . $target_host . " on port " . $target_port . "...\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://' . $target_host );
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // 10-second timeout
curl_setopt($ch, CURLOPT_HEADER, 1); // Include headers in the output
curl_setopt($ch, CURLOPT_NOBODY, 1); // We only want to check the connection, not download the body

$response = curl_exec($ch);

if (curl_errno($ch)) {
    $error_msg = curl_error($ch);
    echo "[FATAL ERROR] cURL connection failed.\n";
    echo "             Error Message: " . $error_msg . "\n\n";
    echo "CONCLUSION: Your server's firewall is likely blocking outbound connections.\n";
    echo "            Please contact your hosting support and show them this error message.\n";
} else {
    echo "[SUCCESS] cURL connection was successful!\n";
    echo "          Server responded with:\n";
    echo "-----------------------------------------\n";
    echo $response;
    echo "-----------------------------------------\n\n";
    echo "CONCLUSION: Your server CAN make outbound connections.\n";
    echo "            The problem is likely with the specific Pusher configuration or library.\n";
}

curl_close($ch);

echo "\n=========================================\n";
echo "Test Complete.\n";
echo "=========================================\n";

?>
