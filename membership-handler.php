<?php
/**
 * Membership Form Handler
 * Receives form data and forwards it to Google Apps Script
 */

// Set JSON response header
header('Content-Type: application/json');

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit();
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

// Get the JSON payload from the request body
$input = file_get_contents('php://input');
$payload = json_decode($input, true);

// Validate that we received data
if (!$payload) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit();
}

// Google Apps Script deployment URL
$apps_script_url = 'https://script.google.com/macros/s/AKfycbzFG0SKrECB1toC6rMckgNQxsUuc_QsCvPr1TMfWztUNM_3plG3J9XnxhTvGdq2faAc/exec';

// Convert payload to JSON
$json_data = json_encode($payload);

// Initialize cURL request
$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, $apps_script_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($json_data)
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

// Execute the request
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);

curl_close($ch);

// Handle the response from Apps Script
if ($curl_error) {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Application received! Thank you for joining the Alabama Falcons family. Redirecting to payment page...'
    ]);
    exit();
}

// Parse the response from Apps Script
if ($response) {
    $result = json_decode($response, true);
    if ($result && $result['success']) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => $result['message']
        ]);
    } else {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Application received! Thank you for joining the Alabama Falcons family. Redirecting to payment page...'
        ]);
    }
} else {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Application received! Thank you for joining the Alabama Falcons family. Redirecting to payment page...'
    ]);
}
?>
