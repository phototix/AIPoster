<?php
// Error handling setup
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

// Custom error handler
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Exception handler
set_exception_handler(function($e) {
    error_log("Uncaught Exception: " . $e->getMessage());
    displayErrorPage("Something went wrong. Our team has been notified.<br>" . $e->getMessage());
});

// Display friendly error page
function displayErrorPage($message) {
    header('HTTP/1.1 500 Internal Server Error');
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Error</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; padding: 20px; }
            .error-container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 5px; }
            .error-heading { color: #d9534f; }
        </style>
    </head>
    <body>
        <div class="error-container">
            <h1 class="error-heading">Oops! Something went wrong</h1>
            <p><?= htmlspecialchars($message) ?></p>
            <p>Please try again later or contact support if the problem persists.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Ensure config directory is not web-accessible
define('CONFIG_DIR', __DIR__ . '/config/');
require_once CONFIG_DIR . 'config.php';
$config = include(CONFIG_DIR . 'config.php');

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable in production
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

// Secure session
session_start([
    'cookie_httponly' => true,
    'cookie_secure' => true,
    'cookie_samesite' => 'Strict'
]);

// Load routine data with validation
function loadRoutineData($filePath) {
    if (!file_exists($filePath)) {
        throw new Exception("Routine data file not found");
    }

    $data = json_decode(file_get_contents($filePath), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON data");
    }

    return $data['schedule'] ?? [];
}

try {
    $schedule = loadRoutineData($config['app']['routine_data']);
    $selectedActivity = $schedule[array_rand($schedule)];
} catch (Exception $e) {
    die("Error loading data: " . $e->getMessage());
}

// API Call with caching
function cachedApiCall($cacheKey, $callback) {
    global $config;
    
    $cacheFile = $config['app']['cache_dir'] . md5($cacheKey) . '.cache';
    
    // Return cached if exists and fresh (< 1 hour)
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 3600)) {
        return unserialize(file_get_contents($cacheFile));
    }
    
    $result = $callback();
    file_put_contents($cacheFile, serialize($result));
    return $result;
}

// Generate caption with OpenAI
function generateCaption($prompt) {
    global $config;
    
    return cachedApiCall("caption_" . md5($prompt), function() use ($prompt, $config) {
        $url = 'https://api.openai.com/v1/completions';
        $data = [
            'model' => $config['openai']['caption_model'],
            'prompt' => "Generate a 20-word Instagram caption about: $prompt",
            'max_tokens' => 50,
            'temperature' => 0.7
        ];
        
        $options = [
            'http' => [
                'header' => [
                    "Content-Type: application/json",
                    "Authorization: Bearer {$config['openai']['api_key']}"
                ],
                'method' => 'POST',
                'content' => json_encode($data)
            ]
        ];
        
        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);
        
        if ($response === false) {
            return "Enjoying my daily routine!";
        }
        
        $result = json_decode($response, true);
        return trim($result['choices'][0]['text'] ?? "Daily routine moment");
    });
}

// Generate image with DALL-E
function generateImage($prompt) {
    global $config;
    
    return cachedApiCall("image_" . md5($prompt), function() use ($prompt, $config) {
        $url = 'https://api.openai.com/v1/images/generations';
        $data = [
            'model' => $config['openai']['image_model'],
            'prompt' => $prompt,
            'n' => 1,
            'size' => '512x512'
        ];
        
        $options = [
            'http' => [
                'header' => [
                    "Content-Type: application/json",
                    "Authorization: Bearer {$config['openai']['api_key']}"
                ],
                'method' => 'POST',
                'content' => json_encode($data)
            ]
        ];
        
        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);
        
        if ($response === false) {
            return 'https://via.placeholder.com/512?text=Image+Not+Generated';
        }
        
        $result = json_decode($response, true);
        return $result['data'][0]['url'] ?? 'https://via.placeholder.com/512?text=Image+Not+Generated';
    });
}

// Generate content
$captionPrompt = "At {$selectedActivity['time']}, I'm {$selectedActivity['activity']} ({$selectedActivity['actions']})";
$caption = generateCaption($captionPrompt);
$imageUrl = generateImage($selectedActivity['activity']);

// HTML Output with security headers
header("Content-Security-Policy: default-src 'self'");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Daily Routine Post</title>
    <style>
        /* [Previous CSS styles remain exactly the same] */
    </style>
</head>
<body>
    <!-- [Previous HTML structure remains exactly the same] -->
</body>
</html>