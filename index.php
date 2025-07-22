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

// Helper log function (if not already defined)
function writeLog($message, $type = 'INFO') {
    $logDir = __DIR__ . '/logs';
    $logFile = $logDir . '/error.log';

    if (!is_dir($logDir)) mkdir($logDir, 0777, true);

    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp][$type] $message" . PHP_EOL;
    file_put_contents($logFile, $entry, FILE_APPEND);
}

// Generate or load data by ID
function getImageAndCaptionById($id, $config) {
    $dataDir = __DIR__ . '/data';
    if (!is_dir($dataDir)) mkdir($dataDir, 0777, true);

    $jsonFile = "$dataDir/$id.json";
    $jpgFile = "$dataDir/$id.jpg";

    // If JSON + JPG exist, just read JSON and return
    if (file_exists($jsonFile) && file_exists($jpgFile)) {
        $json = file_get_contents($jsonFile);
        $data = json_decode($json, true);
        if ($data) {
            $data['image_path'] = $jpgFile;
            return $data;
        }
    }

    // Otherwise generate new image & caption

    // 1. Generate caption with OpenAI Chat Completion
    $caption = generateCaption($id, $config);

    // 2. Generate image with OpenAI Images API
    $imageUrl = generateImage($caption, $config);

    if (!$imageUrl) {
        writeLog("Failed to generate image for ID $id", 'ERROR');
        return false;
    }

    // 3. Download image to $jpgFile
    $imageData = @file_get_contents($imageUrl);
    if ($imageData === false) {
        writeLog("Failed to download image from $imageUrl for ID $id", 'ERROR');
        return false;
    }
    file_put_contents($jpgFile, $imageData);

    // 4. Save JSON with caption + image URL
    $data = [
        'id' => $id,
        'caption' => $caption,
        'image_url' => $imageUrl,
        'image_path' => $jpgFile,
        'created_at' => date('c')
    ];

    file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT));

    return $data;
}

// Generate caption for a prompt (here prompt = id for demo)
function generateCaption($prompt, $config) {
    $url = 'https://api.openai.com/v1/chat/completions';

    $data = [
        'model' => $config['openai']['caption_model'],
        'messages' => [
            ['role' => 'user', 'content' => "Write a catchy Instagram caption (max 20 words) about: $prompt"]
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

    if ($response === false) return "Enjoying my day!";

    $result = json_decode($response, true);
    return trim($result['choices'][0]['message']['content'] ?? "Enjoying my day!");
}

// Generate image and return the image URL
function generateImage($prompt, $config) {
    $url = 'https://api.openai.com/v1/images/generations';

    $data = [
        'model' => 'gpt-image-1',
        'prompt' => $prompt,
        'n' => 1,
        'size' => '1024x1024'
    ];

    $headers = [
        "Content-Type: application/json",
        "Authorization: Bearer {$config['openai']['api_key']}"
    ];

    $options = [
        'http' => [
            'header'  => $headers,
            'method'  => 'POST',
            'content' => json_encode($data),
            'ignore_errors' => true
        ]
    ];

    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        writeLog("Image generation request failed for prompt: $prompt", 'ERROR');
        return false;
    }

    $result = json_decode($response, true);
    if (!isset($result['data'][0]['url'])) {
        writeLog("Unexpected image generation response: $response", 'ERROR');
        return false;
    }

    return $result['data'][0]['url'];
}

// Main logic:
$config = [
    'openai' => [
        'api_key' => 'sk-xxxxx',          // your OpenAI API key
        'caption_model' => 'gpt-4.1'      // or gpt-3.5-turbo
    ]
];

// Check for ?id= query parameter
$id = $_GET['id'] ?? null;

if (!$id) {
    // Generate new unique ID, redirect
    $id = uniqid();
    header("Location: ?id=$id");
    exit;
}

// Load or generate data by ID
$data = getImageAndCaptionById($id, $config);

if (!$data) {
    http_response_code(500);
    echo "Failed to generate content. Please try again later.";
    exit;
}

// Now you can use $data['caption'] and $data['image_path'] to display on the page
?>

<!DOCTYPE html>
<html>
<head>
    <title>Generated Content #<?= htmlspecialchars($id) ?></title>
</head>
<body>
    <h1>Caption:</h1>
    <p><?= htmlspecialchars($data['caption']) ?></p>

    <h2>Image:</h2>
    <img src="<?= htmlspecialchars(str_replace(__DIR__, '', $data['image_path'])) ?>" alt="Generated Image" style="max-width:500px;" />
</body>
</html>