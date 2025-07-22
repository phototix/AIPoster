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
    displayErrorPage("Something went wrong. Our team has been notified. " . $e->getMessage());
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
ini_set('display_errors', 1); // Disable in production
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

// Secure session
session_start([
    'cookie_httponly' => true,
    'cookie_secure' => true,
    'cookie_samesite' => 'Strict'
]);

function writeLog($message, $type = 'INFO') {
    $logDir = __DIR__ . '/logs';
    $logFile = $logDir . '/error.log';

    if (!file_exists($logDir)) {
        mkdir($logDir, 0775, true); // Create logs directory if it doesn't exist
    }

    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp][$type] $message" . PHP_EOL;

    file_put_contents($logFile, $entry, FILE_APPEND);
}

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
    
    writeLog("load api");

    return $result;
}

// Generate caption with OpenAI
function generateCaption($prompt) {
    global $config;

    return cachedApiCall("caption_" . md5($prompt), function() use ($prompt, $config) {
        $url = 'https://api.openai.com/v1/chat/completions';

        $data = [
            'model' => $config['openai']['caption_model'], // e.g. "gpt-4.1" or "gpt-3.5-turbo"
            'messages' => [
                [
                    'role' => 'user',
                    'content' => "Write a short, catchy Instagram caption (max 20 words) about: $prompt"
                ]
            ],
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
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $error = error_get_last();
            return "API call failed: " . ($error['message'] ?? 'Unknown error');
        }

        $result = json_decode($response, true);
        writeLog($result);
        return trim($result['choices'][0]['message']['content'] ?? "Something inspiring!");
    });
}

// Generate image with DALL-E
function generateImage($prompt, $outputFile = 'output.png') {
    global $config;

    $url = 'https://api.openai.com/v1/images/generations';

    $data = [
        'model' => 'gpt-image-1',
        'prompt' => $prompt,
        'response_format' => 'b64_json'
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
    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        $error = error_get_last();
        return "Image generation failed: " . ($error['message'] ?? 'Unknown error');
    }

    $result = json_decode($response, true);

    if (!isset($result['data'][0]['b64_json'])) {
        return "Invalid response from OpenAI image API.";
    }

    $imageData = base64_decode($result['data'][0]['b64_json']);
    file_put_contents($outputFile, $imageData);
    
    writeLog($outputFile);

    return $outputFile; // path to the saved image
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
        .instagram-post {
            width: 500px;
            margin: 20px auto;
            border: 1px solid #dbdbdb;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: white;
            border-radius: 3px;
        }
        .post-header {
            display: flex;
            align-items: center;
            padding: 14px 16px;
            border-bottom: 1px solid #efefef;
        }
        .profile-pic {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            margin-right: 12px;
            background: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888);
        }
        .username {
            font-weight: 600;
            font-size: 14px;
        }
        .post-image {
            width: 100%;
            height: 500px;
            object-fit: cover;
        }
        .post-actions {
            padding: 8px 16px;
            display: flex;
            gap: 16px;
        }
        .action-icon {
            font-size: 24px;
            cursor: pointer;
        }
        .post-caption {
            padding: 0 16px 10px;
            font-size: 14px;
        }
        .caption-username {
            font-weight: 600;
            margin-right: 5px;
        }
        .post-time {
            padding: 0 16px 12px;
            color: #8e8e8e;
            font-size: 10px;
            text-transform: uppercase;
        }
        .activity-details {
            text-align: center;
            margin-top: 20px;
            padding: 15px;
            background: #fafafa;
            border-radius: 8px;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }
    </style>
</head>
<body>
    <div class="instagram-post">
        <div class="post-header">
            <div class="profile-pic">BC</div>
            <div class="username">brandon.chong</div>
        </div>
        
        <img src="<?php echo htmlspecialchars($imageUrl); ?>" alt="<?php echo htmlspecialchars($selectedActivity['activity']); ?>" class="post-image">
        
        <div class="post-actions">
            <span class="action-icon">❤️</span>
            <span class="action-icon">💬</span>
            <span class="action-icon">↪️</span>
            <span class="action-icon">🔖</span>
        </div>
        
        <div class="post-caption">
            <span class="caption-username">brandon.chong</span>
            <?php echo htmlspecialchars(trim($caption)); ?>
        </div>
        
        <div class="post-time">
            <?php echo date('F j, Y \a\t g:i A'); ?> • Daily Routine
        </div>
    </div>
    
    <div class="activity-details">
        <h3>📅 Activity Details</h3>
        <p><strong>⏰ Time:</strong> <?php echo htmlspecialchars($selectedActivity['time']); ?></p>
        <p><strong>🏃 Activity:</strong> <?php echo htmlspecialchars($selectedActivity['activity']); ?></p>
        <p><strong>🗂️ Type:</strong> <?php echo htmlspecialchars($selectedActivity['type']); ?></p>
        <p><strong>⚡ Action:</strong> <?php echo htmlspecialchars($selectedActivity['actions']); ?></p>
    </div>
</body>
</html>