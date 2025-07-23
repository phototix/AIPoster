<?php
// whatsapp_sender.php
// Usage in crontab: php /path/to/whatsapp_sender.php ######

// Check if filename parameter is provided
if (!isset($argv[1])) {
    die("Error: Please provide a filename pattern (e.g., ######)\n");
}

$filenamePattern = $argv[1];
$directory = '/var/www/post.brandon.my/generated/';
$jsonFile = $directory . $filenamePattern . '.json';

// Check if file exists
if (!file_exists($jsonFile)) {
    die("Error: JSON file not found: $jsonFile\n");
}

// Read JSON file
$jsonContent = file_get_contents($jsonFile);
$data = json_decode($jsonContent, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    die("Error: Invalid JSON format in file: $jsonFile\n");
}

// Extract data
$sendMessage = $data['caption'] ?? '';
$imageFile = $data['image_file'] ?? '';
$imageURL = 'https://post.brandon.my/generated/' . $imageFile; // Adjust URL as needed

// WA API configuration
$apiBaseUrl = 'https://whatsapp-waha.brandon.my/api/';
$session = 'default';
$chatId = '120363421397770615@g.us'; // Replace with your actual chat ID

// Function to call WA API
function callWhatsAppAPI($url, $payload) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'accept: application/json',
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'status' => $httpCode,
        'response' => json_decode($response, true)
    ];
}

// Determine if we're sending text or image
if (!empty($imageFile) ){
    // Send image with caption
    $payload = [
        'chatId' => $chatId,
        'file' => [
            'mimetype' => 'image/jpeg',
            'filename' => $imageFile,
            'url' => $imageURL
        ],
        'reply_to' => null,
        'caption' => $sendMessage,
        'session' => $session
    ];
    
    $result = callWhatsAppAPI($apiBaseUrl . 'sendImage', $payload);
} else {
    // Send text only
    $payload = [
        'chatId' => $chatId,
        'reply_to' => null,
        'text' => $sendMessage,
        'linkPreview' => true,
        'linkPreviewHighQuality' => false,
        'session' => $session
    ];
    
    $result = callWhatsAppAPI($apiBaseUrl . 'sendText', $payload);
}

// Log result
if ($result['status'] === 200) {
    echo "Message sent successfully!\n";
    // Optionally delete or move the processed file
    // unlink($jsonFile);
} else {
    echo "Error sending message. Status: " . $result['status'] . "\n";
    if (isset($result['response']['message'])) {
        echo "Message: " . $result['response']['message'] . "\n";
    }
    print_r($result['response']);
}